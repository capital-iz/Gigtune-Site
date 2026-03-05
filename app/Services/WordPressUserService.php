<?php

namespace App\Services;

use App\Support\WordPressPasswordHasher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WordPressUserService
{
    private string $connectionName;
    private string $tablePrefix;

    public function __construct(
        private readonly WordPressPasswordHasher $passwordHasher,
    ) {
        $this->connectionName = (string) config('gigtune.wordpress.database_connection', 'wordpress');
        $this->tablePrefix = (string) config('gigtune.wordpress.table_prefix', 'wp_');
    }

    public function verifyCredentials(string $identifier, string $password): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '' || $password === '') {
            return null;
        }

        $record = $this->db()
            ->table($this->usersTable())
            ->where('user_login', $identifier)
            ->orWhere('user_email', $identifier)
            ->first();

        if ($record === null) {
            return null;
        }

        if (!$this->passwordHasher->check($password, (string) ($record->user_pass ?? ''))) {
            return null;
        }

        return $this->mapUser($record);
    }

    public function getUserById(int $userId): ?array
    {
        $userId = abs($userId);
        if ($userId <= 0) {
            return null;
        }

        $record = $this->db()
            ->table($this->usersTable())
            ->where('ID', $userId)
            ->first();

        if ($record === null) {
            return null;
        }

        return $this->mapUser($record);
    }

    public function getUserByIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $record = $this->db()
            ->table($this->usersTable())
            ->where('user_login', $identifier)
            ->orWhere('user_email', strtolower($identifier))
            ->first();

        if ($record === null) {
            return null;
        }

        return $this->mapUser($record);
    }

    public function listUsers(array $args = []): array
    {
        $perPage = max(1, min(100, (int) ($args['per_page'] ?? 20)));
        $page = max(1, (int) ($args['page'] ?? 1));
        $search = trim((string) ($args['search'] ?? ''));
        $role = $this->normalizeRoleName($args['role'] ?? '');

        $query = $this->db()
            ->table($this->usersTable() . ' as u')
            ->select([
                'u.ID',
                'u.user_login',
                'u.user_email',
                'u.display_name',
            ]);

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('u.user_login', 'like', '%' . $search . '%')
                    ->orWhere('u.user_email', 'like', '%' . $search . '%')
                    ->orWhere('u.display_name', 'like', '%' . $search . '%');
            });
        }

        if ($role !== '') {
            $metaKeys = [$this->tablePrefix . 'capabilities', 'capabilities'];
            $query->whereExists(function ($builder) use ($role, $metaKeys): void {
                $builder->selectRaw('1')
                    ->from($this->userMetaTable() . ' as um')
                    ->whereColumn('um.user_id', 'u.ID')
                    ->whereIn('um.meta_key', $metaKeys)
                    ->where('um.meta_value', 'like', '%' . $role . '%');
            });
        }

        $total = (int) (clone $query)->count('u.ID');
        $rows = (clone $query)
            ->orderByDesc('u.ID')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->mapUser($row);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    public function createUser(array $payload): array
    {
        $login = Str::of((string) ($payload['login'] ?? $payload['username'] ?? ''))
            ->trim()
            ->lower()
            ->replaceMatches('/[^a-z0-9._-]+/', '')
            ->value();
        $email = trim(strtolower((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');
        $displayName = trim((string) ($payload['display_name'] ?? $payload['name'] ?? $login));
        $roles = is_array($payload['roles'] ?? null) ? $payload['roles'] : [$payload['role'] ?? 'gigtune_client'];
        $normalizedRoles = $this->normalizeRoles($roles);

        if ($login === '' || strlen($login) < 3) {
            throw new \InvalidArgumentException('Username must be at least 3 characters.');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email address is required.');
        }
        if ($password === '' || strlen($password) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters.');
        }
        if (empty($normalizedRoles)) {
            throw new \InvalidArgumentException('At least one valid role is required.');
        }
        if ($displayName === '') {
            $displayName = $login;
        }

        $existing = $this->db()
            ->table($this->usersTable())
            ->where('user_login', $login)
            ->orWhere('user_email', $email)
            ->exists();
        if ($existing) {
            throw new \InvalidArgumentException('A user with this username or email already exists.');
        }

        $userId = $this->db()->transaction(function () use ($login, $email, $password, $displayName, $normalizedRoles): int {
            $now = now()->format('Y-m-d H:i:s');
            $userNicename = Str::slug($displayName) ?: $login;

            $userId = (int) $this->db()
                ->table($this->usersTable())
                ->insertGetId([
                    'user_login' => $login,
                    'user_pass' => $this->passwordHasher->hash($password),
                    'user_nicename' => mb_substr($userNicename, 0, 50),
                    'user_email' => $email,
                    'user_url' => '',
                    'user_registered' => $now,
                    'user_activation_key' => '',
                    'user_status' => 0,
                    'display_name' => $displayName,
                ]);

            $capabilities = [];
            foreach ($normalizedRoles as $role) {
                $capabilities[$role] = true;
            }

            $userLevel = (string) $this->rolesToUserLevel($normalizedRoles);
            $serialized = serialize($capabilities);
            $this->upsertUserMeta($userId, $this->tablePrefix . 'capabilities', $serialized);
            $this->upsertUserMeta($userId, $this->tablePrefix . 'user_level', $userLevel);
            $this->upsertUserMeta($userId, 'capabilities', $serialized);
            $this->upsertUserMeta($userId, 'user_level', $userLevel);

            return $userId;
        });

        $user = $this->getUserById($userId);
        if ($user === null) {
            throw new \RuntimeException('Failed to create user.');
        }

        return $user;
    }

    public function updateUserRoles(int $userId, array $roles): array
    {
        $userId = abs($userId);
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Invalid user ID.');
        }

        $user = $this->getUserById($userId);
        if ($user === null) {
            throw new \InvalidArgumentException('User not found.');
        }

        $normalizedRoles = $this->normalizeRoles($roles);
        if (empty($normalizedRoles)) {
            throw new \InvalidArgumentException('At least one valid role is required.');
        }

        $capabilities = [];
        foreach ($normalizedRoles as $role) {
            $capabilities[$role] = true;
        }
        $serialized = serialize($capabilities);
        $userLevel = (string) $this->rolesToUserLevel($normalizedRoles);

        $this->upsertUserMeta($userId, $this->tablePrefix . 'capabilities', $serialized);
        $this->upsertUserMeta($userId, $this->tablePrefix . 'user_level', $userLevel);
        $this->upsertUserMeta($userId, 'capabilities', $serialized);
        $this->upsertUserMeta($userId, 'user_level', $userLevel);

        $updated = $this->getUserById($userId);
        if ($updated === null) {
            throw new \RuntimeException('Failed to update user role.');
        }

        return $updated;
    }

    public function updateUserEmail(int $userId, string $email): array
    {
        $userId = abs($userId);
        $email = trim(strtolower($email));
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Invalid user ID.');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email address is required.');
        }

        $current = $this->getUserById($userId);
        if ($current === null) {
            throw new \InvalidArgumentException('User not found.');
        }
        if (strtolower((string) ($current['email'] ?? '')) === $email) {
            return $current;
        }

        $exists = $this->db()
            ->table($this->usersTable())
            ->where('user_email', $email)
            ->where('ID', '!=', $userId)
            ->exists();
        if ($exists) {
            throw new \InvalidArgumentException('That email is already in use.');
        }

        $this->db()
            ->table($this->usersTable())
            ->where('ID', $userId)
            ->update(['user_email' => $email]);

        $updated = $this->getUserById($userId);
        if ($updated === null) {
            throw new \RuntimeException('Failed to update user email.');
        }

        return $updated;
    }

    public function updateUserPassword(int $userId, string $password): void
    {
        $userId = abs($userId);
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Invalid user ID.');
        }
        if ($password === '' || strlen($password) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters.');
        }
        if ($this->getUserById($userId) === null) {
            throw new \InvalidArgumentException('User not found.');
        }

        $this->db()
            ->table($this->usersTable())
            ->where('ID', $userId)
            ->update([
                'user_pass' => $this->passwordHasher->hash($password),
                'user_activation_key' => '',
            ]);
    }

    public function deleteUserHard(int $userId): void
    {
        $userId = abs($userId);
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Invalid user ID.');
        }

        $user = $this->getUserById($userId);
        if ($user === null) {
            throw new \InvalidArgumentException('User not found.');
        }

        $this->db()->transaction(function () use ($userId): void {
            $this->db()
                ->table($this->userMetaTable())
                ->where('user_id', $userId)
                ->delete();

            $this->db()
                ->table($this->postsTable())
                ->where('post_author', $userId)
                ->update(['post_author' => 0]);

            $this->db()
                ->table($this->usersTable())
                ->where('ID', $userId)
                ->delete();
        });
    }

    public function requiredPolicyVersions(): array
    {
        $versions = (array) config('gigtune.policy.versions', []);
        $normalized = [];
        foreach ($versions as $policy => $version) {
            $policyKey = $this->normalizePolicyKey($policy);
            if ($policyKey === '') {
                continue;
            }
            $normalized[$policyKey] = (string) $version;
        }

        return $normalized;
    }

    public function getPolicyStatus(int $userId): array
    {
        $required = $this->requiredPolicyVersions();
        $accepted = $this->getPolicyAcceptance($userId);
        $missing = [];

        foreach ($required as $policy => $version) {
            $item = $accepted[$policy] ?? [];
            $acceptedVersion = trim((string) ($item['version'] ?? ''));
            if ($acceptedVersion !== (string) $version) {
                $missing[] = $policy;
            }
        }

        return [
            'required' => $required,
            'accepted' => $accepted,
            'missing' => $missing,
            'has_latest' => empty($missing),
            'consent_url' => $this->absoluteUrl((string) config('gigtune.policy.consent_path', '/policy-consent/')),
            'documents' => $this->policyDocumentLinks(),
        ];
    }

    public function storePolicyAcceptance(int $userId, array $acceptedKeys): array
    {
        $required = $this->requiredPolicyVersions();
        $allowed = array_fill_keys(array_keys($required), true);

        $cleanKeys = [];
        foreach ($acceptedKeys as $acceptedKey) {
            $policyKey = $this->normalizePolicyKey($acceptedKey);
            if ($policyKey !== '' && isset($allowed[$policyKey])) {
                $cleanKeys[] = $policyKey;
            }
        }
        $cleanKeys = array_values(array_unique($cleanKeys));

        $current = $this->getPolicyAcceptance($userId);
        $acceptedAt = now()->format('Y-m-d H:i:s');
        foreach ($cleanKeys as $policyKey) {
            $current[$policyKey] = [
                'version' => (string) $required[$policyKey],
                'accepted_at' => $acceptedAt,
            ];
        }

        $this->upsertUserMeta($userId, 'gigtune_policy_acceptance', serialize($current));
        if (isset($required['terms'])) {
            $this->upsertUserMeta($userId, 'gigtune_terms_version', (string) $required['terms']);
            $this->upsertUserMeta($userId, 'gigtune_terms_accepted', $acceptedAt);
        }
        if (in_array('terms', $cleanKeys, true)) {
            $this->upsertUserMeta($userId, 'gigtune_terms_acceptance', '1');
        }
        $legacyAcceptedMap = [
            'terms' => 'gigtune_accept_terms',
            'aup' => 'gigtune_accept_aup',
            'privacy' => 'gigtune_accept_privacy',
            'refund' => 'gigtune_accept_refund',
        ];
        foreach ($cleanKeys as $policyKey) {
            $legacyField = $legacyAcceptedMap[$policyKey] ?? '';
            if ($legacyField !== '') {
                $this->upsertUserMeta($userId, $legacyField, '1');
            }
        }

        return $current;
    }

    public function mapAcceptedPolicyInput(array $input): array
    {
        $required = array_keys($this->requiredPolicyVersions());
        $selected = [];

        if ($this->inputToBool($input['accept_all'] ?? false)) {
            return $required;
        }
        if ($this->inputToBool($input['gigtune_terms_acceptance'] ?? false)) {
            return $required;
        }

        $accepted = $input['accepted'] ?? null;
        if (is_array($accepted)) {
            foreach ($accepted as $item) {
                $key = $this->normalizePolicyKey($item);
                if ($key !== '') {
                    $selected[] = $key;
                }
            }
        }

        foreach ($required as $policyKey) {
            if ($this->inputToBool($input[$policyKey] ?? false)) {
                $selected[] = $policyKey;
            }
        }

        $legacyMap = [
            'terms' => 'gigtune_accept_terms',
            'aup' => 'gigtune_accept_aup',
            'privacy' => 'gigtune_accept_privacy',
            'refund' => 'gigtune_accept_refund',
        ];
        foreach ($legacyMap as $policyKey => $legacyField) {
            if ($this->inputToBool($input[$legacyField] ?? false)) {
                $selected[] = $policyKey;
            }
        }

        $selected = array_values(array_unique(array_filter($selected, static fn ($value): bool => is_string($value) && $value !== '')));

        return array_values(array_intersect($required, $selected));
    }

    private function mapUser(object $record): array
    {
        $userId = (int) ($record->ID ?? 0);
        $roles = $this->getUserRoles($userId);
        $isAdmin = $this->isAdminUser($roles);

        return [
            'id' => $userId,
            'login' => (string) ($record->user_login ?? ''),
            'email' => (string) ($record->user_email ?? ''),
            'display_name' => (string) ($record->display_name ?? ''),
            'roles' => $roles,
            'is_admin' => $isAdmin,
            'dashboard_url' => $this->dashboardUrl($roles, $isAdmin),
        ];
    }

    private function getUserRoles(int $userId): array
    {
        $raw = $this->getUserMeta($userId, $this->tablePrefix . 'capabilities');
        if (!is_array($raw)) {
            $raw = $this->getUserMeta($userId, 'capabilities');
        }

        if (!is_array($raw)) {
            return [];
        }

        $roles = [];
        foreach ($raw as $roleOrCapability => $enabled) {
            if (!$this->inputToBool($enabled)) {
                continue;
            }

            $name = trim((string) $roleOrCapability);
            if ($name !== '') {
                $roles[] = $name;
            }
        }

        return array_values(array_unique($roles));
    }

    private function isAdminUser(array $roles): bool
    {
        if (in_array('administrator', $roles, true)) {
            return true;
        }

        foreach (['manage_options', 'update_core', 'update_plugins', 'gigtune_manage_payments'] as $capability) {
            if (in_array($capability, $roles, true)) {
                return true;
            }
        }

        return false;
    }

    private function dashboardUrl(array $roles, bool $isAdmin): string
    {
        if ($isAdmin) {
            return $this->absoluteUrl('/admin-dashboard/');
        }

        if (in_array('gigtune_artist', $roles, true)) {
            return $this->absoluteUrl('/artist-dashboard/');
        }

        if (in_array('gigtune_client', $roles, true)) {
            return $this->absoluteUrl('/client-dashboard/');
        }

        return $this->absoluteUrl('/my-account/');
    }

    private function getPolicyAcceptance(int $userId): array
    {
        $raw = $this->getUserMeta($userId, 'gigtune_policy_acceptance');
        $normalized = [];
        if (is_array($raw)) {
            foreach ($raw as $policy => $value) {
                $policyKey = $this->normalizePolicyKey($policy);
                if ($policyKey === '' || !is_array($value)) {
                    continue;
                }

                $normalized[$policyKey] = [
                    'version' => trim((string) ($value['version'] ?? '')),
                    'accepted_at' => trim((string) ($value['accepted_at'] ?? '')),
                ];
            }
        }

        $required = $this->requiredPolicyVersions();
        $acceptedAt = trim((string) $this->getUserMeta($userId, 'gigtune_terms_accepted'));
        $termsVersion = trim((string) $this->getUserMeta($userId, 'gigtune_terms_version'));
        $legacyTermsAccepted = $this->inputToBool($this->getUserMeta($userId, 'gigtune_terms_acceptance'));

        if ($legacyTermsAccepted) {
            foreach ($required as $policyKey => $version) {
                if (!isset($normalized[$policyKey])) {
                    $normalized[$policyKey] = [
                        'version' => (string) $version,
                        'accepted_at' => $acceptedAt,
                    ];
                }
            }
        }

        $legacyAcceptedMap = [
            'terms' => 'gigtune_accept_terms',
            'aup' => 'gigtune_accept_aup',
            'privacy' => 'gigtune_accept_privacy',
            'refund' => 'gigtune_accept_refund',
        ];
        foreach ($legacyAcceptedMap as $policyKey => $legacyField) {
            if (!$this->inputToBool($this->getUserMeta($userId, $legacyField))) {
                continue;
            }
            if (isset($normalized[$policyKey])) {
                continue;
            }
            $version = trim((string) ($required[$policyKey] ?? ''));
            if ($policyKey === 'terms' && $termsVersion !== '') {
                $version = $termsVersion;
            }
            $normalized[$policyKey] = [
                'version' => $version,
                'accepted_at' => $acceptedAt,
            ];
        }

        if (isset($normalized['terms']) && trim((string) ($normalized['terms']['version'] ?? '')) === '' && $termsVersion !== '') {
            $normalized['terms']['version'] = $termsVersion;
        }

        return $normalized;
    }

    private function policyDocumentLinks(): array
    {
        $paths = (array) config('gigtune.policy.document_paths', []);
        $links = [];

        foreach ($paths as $policy => $path) {
            $policyKey = $this->normalizePolicyKey($policy);
            if ($policyKey === '') {
                continue;
            }
            $links[$policyKey] = $this->absoluteUrl((string) $path);
        }

        return $links;
    }

    private function absoluteUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return rtrim((string) config('app.url', ''), '/');
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $base = rtrim((string) config('app.url', ''), '/');
        $normalizedPath = '/' . ltrim($path, '/');

        if ($base === '') {
            return $normalizedPath;
        }

        return $base . $normalizedPath;
    }

    private function getUserMeta(int $userId, string $metaKey): mixed
    {
        $metaValue = $this->db()
            ->table($this->userMetaTable())
            ->where('user_id', $userId)
            ->where('meta_key', $metaKey)
            ->orderByDesc('umeta_id')
            ->value('meta_value');

        if (!is_string($metaValue)) {
            return null;
        }

        return $this->decodeMetaValue($metaValue);
    }

    private function upsertUserMeta(int $userId, string $metaKey, string $metaValue): void
    {
        $row = $this->db()
            ->table($this->userMetaTable())
            ->select('umeta_id')
            ->where('user_id', $userId)
            ->where('meta_key', $metaKey)
            ->orderByDesc('umeta_id')
            ->first();

        if ($row !== null && isset($row->umeta_id)) {
            $this->db()
                ->table($this->userMetaTable())
                ->where('umeta_id', (int) $row->umeta_id)
                ->update(['meta_value' => $metaValue]);

            return;
        }

        $this->db()
            ->table($this->userMetaTable())
            ->insert([
                'user_id' => $userId,
                'meta_key' => $metaKey,
                'meta_value' => $metaValue,
            ]);
    }

    private function decodeMetaValue(string $value): mixed
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if ($trimmed === 'N;' || preg_match('/^[aObisCd]:/', $trimmed) === 1) {
            $decoded = @unserialize($trimmed, ['allowed_classes' => false]);
            if ($decoded !== false || $trimmed === 'b:0;' || $trimmed === 'N;') {
                return $decoded;
            }
        }

        return $value;
    }

    private function normalizePolicyKey(mixed $key): string
    {
        $normalized = trim(strtolower((string) $key));
        if ($normalized === '') {
            return '';
        }

        return preg_replace('/[^a-z0-9_]/', '', $normalized) ?? '';
    }

    private function inputToBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizeRoles(array $roles): array
    {
        $allowed = [
            'administrator' => true,
            'gigtune_artist' => true,
            'gigtune_client' => true,
        ];

        $normalized = [];
        foreach ($roles as $role) {
            $name = $this->normalizeRoleName($role);
            if ($name !== '' && isset($allowed[$name])) {
                $normalized[] = $name;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeRoleName(mixed $role): string
    {
        $name = trim(strtolower((string) $role));
        return preg_replace('/[^a-z0-9_]/', '', $name) ?? '';
    }

    private function rolesToUserLevel(array $roles): int
    {
        if (in_array('administrator', $roles, true)) {
            return 10;
        }

        return 0;
    }

    private function postsTable(): string
    {
        return $this->tablePrefix . 'posts';
    }

    private function usersTable(): string
    {
        return $this->tablePrefix . 'users';
    }

    private function userMetaTable(): string
    {
        return $this->tablePrefix . 'usermeta';
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->connectionName);
    }
}
