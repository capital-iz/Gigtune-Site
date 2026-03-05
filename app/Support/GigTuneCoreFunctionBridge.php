<?php

namespace App\Support;

use App\Services\GigTuneShortcodeService;
use Illuminate\Support\Facades\DB;

class GigTuneCoreFunctionBridge
{
    /** @var array<int,string>|null */
    private static ?array $sourceFunctions = null;

    /** @var array<string,bool> */
    private static array $warnedFallback = [];

    public static function registerRuntimeFunctions(): void
    {
        foreach (self::sourceFunctionNames() as $functionName) {
            if (function_exists($functionName)) {
                continue;
            }
            $escapedName = str_replace("'", "\\'", $functionName);
            $code = 'function ' . $functionName . '(...$args){ return \\App\\Support\\GigTuneCoreFunctionBridge::dispatch(\'' . $escapedName . '\', $args); }';
            eval($code);
        }
    }

    /**
     * @return array<int,string>
     */
    public static function sourceFunctionNames(): array
    {
        if (is_array(self::$sourceFunctions)) {
            return self::$sourceFunctions;
        }

        $root = rtrim((string) config('gigtune.wordpress.root', ''), '\\/');
        $sourceFile = $root . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'gigtune-core' . DIRECTORY_SEPARATOR . 'gigtune-core.php';
        if (!is_file($sourceFile)) {
            self::$sourceFunctions = [];
            return self::$sourceFunctions;
        }

        $text = @file_get_contents($sourceFile);
        if (!is_string($text) || $text === '') {
            self::$sourceFunctions = [];
            return self::$sourceFunctions;
        }

        preg_match_all('/^\s*function\s+(gigtune_[a-zA-Z0-9_]+)\s*\(/m', $text, $matches);
        $names = array_map(static fn ($v): string => trim((string) $v), $matches[1] ?? []);
        $names = array_values(array_unique(array_filter($names, static fn ($v): bool => $v !== '')));
        sort($names);
        self::$sourceFunctions = $names;
        return self::$sourceFunctions;
    }

    /**
     * @param array<int,mixed> $args
     */
    public static function dispatch(string $functionName, array $args = []): mixed
    {
        $handler = self::explicitHandler($functionName);
        if ($handler !== null) {
            return $handler(...$args);
        }

        return self::defaultFallback($functionName);
    }

    /**
     * @return callable|null
     */
    private static function explicitHandler(string $functionName): ?callable
    {
        return match ($functionName) {
            'gigtune_get_required_policy_versions' => static fn (): array => [
                'terms' => (string) config('gigtune.policy.versions.terms', 'v1.1'),
                'aup' => (string) config('gigtune.policy.versions.aup', 'v1.1'),
                'privacy' => (string) config('gigtune.policy.versions.privacy', 'v1.1'),
                'refund' => (string) config('gigtune.policy.versions.refund', 'v1.1'),
            ],
            'gigtune_get_policy_document_links' => static fn (): array => [
                'terms' => (string) config('gigtune.policy.document_paths.terms', '/terms-and-conditions/'),
                'aup' => (string) config('gigtune.policy.document_paths.aup', '/acceptable-use-policy/'),
                'privacy' => (string) config('gigtune.policy.document_paths.privacy', '/privacy-policy/'),
                'refund' => (string) config('gigtune.policy.document_paths.refund', '/return-policy/'),
            ],
            'gigtune_get_policy_consent_url' => static fn (): string => (string) config('gigtune.policy.consent_path', '/policy-consent/'),
            'gigtune_get_kyc_page_url' => static fn (): string => '/kyc/',
            'gigtune_get_kyc_status_page_url' => static fn (): string => '/kyc-status/',
            'gigtune_get_identity_verification_label' => static fn (): string => 'Identity Verification (Know Your Customer Compliance)',
            'gigtune_get_identity_verification_short_label' => static fn (): string => 'Identity Verification',
            'gigtune_get_identity_verification_status_sentence' => static function (mixed $status = ''): string {
                $value = strtolower(trim((string) $status));
                return match ($value) {
                    'verified', 'approved' => 'Identity Verification approved.',
                    'rejected', 'declined' => 'Identity Verification declined.',
                    default => 'Identity Verification pending review.',
                };
            },
            'gigtune_get_identity_verification_privacy_url' => static fn (): string => (string) config('gigtune.policy.document_paths.privacy', '/privacy-policy/'),
            'gigtune_get_security_centre_page_url' => static fn (): string => '/security-centre/',
            'gigtune_get_notification_settings_page_url' => static fn (): string => '/notification-settings/',
            'gigtune_get_admin_maintenance_page_url' => static fn (): string => '/admin-maintenance/',
            'gigtune_get_verification_page_url' => static fn (): string => '/verify-email/',
            'gigtune_get_forgot_password_url' => static fn (): string => '/forgot-password/',
            'gigtune_get_reset_password_url' => static fn (): string => '/reset-password/',
            'gigtune_get_kyc_submission_post_type' => static fn (): string => 'gigtune_kyc_submission',
            'gigtune_get_kyc_submission_post_type_aliases' => static fn (): array => ['gigtune_kyc_submission', 'gigtune_kyc_submissi'],
            'gigtune_is_kyc_submission_post_type' => static function (mixed $postType = ''): bool {
                $value = trim((string) $postType);
                return in_array($value, ['gigtune_kyc_submission', 'gigtune_kyc_submissi'], true);
            },
            'gigtune_get_role_dashboard_url' => static function (mixed $userId = 0): string {
                $uid = (int) $userId;
                if ($uid <= 0) {
                    return '/';
                }
                $connection = (string) config('gigtune.wordpress.database_connection', 'wordpress');
                $prefix = (string) config('gigtune.wordpress.table_prefix', 'wpqx_');
                $userMetaTable = $prefix . 'usermeta';
                $caps = (string) DB::connection($connection)
                    ->table($userMetaTable)
                    ->where('user_id', $uid)
                    ->where('meta_key', 'like', '%capabilities')
                    ->orderByDesc('umeta_id')
                    ->value('meta_value');
                $caps = strtolower($caps);
                if (str_contains($caps, 'administrator')) {
                    return '/admin-dashboard/';
                }
                if (str_contains($caps, 'gigtune_artist') || str_contains($caps, 'artist')) {
                    return '/artist-dashboard/';
                }
                return '/client-dashboard/';
            },
            'gigtune_yoco_get_mode' => static fn (): string => strtolower(trim((string) config('gigtune.payments.yoco.mode', 'test'))) === 'live' ? 'live' : 'test',
            'gigtune_yoco_get_secret_key' => static fn (): string => strtolower(trim((string) config('gigtune.payments.yoco.mode', 'test'))) === 'live'
                ? trim((string) config('gigtune.payments.yoco.live_secret_key', ''))
                : trim((string) config('gigtune.payments.yoco.test_secret_key', '')),
            'gigtune_yoco_get_public_key' => static fn (): string => strtolower(trim((string) config('gigtune.payments.yoco.mode', 'test'))) === 'live'
                ? trim((string) config('gigtune.payments.yoco.live_public_key', ''))
                : trim((string) config('gigtune.payments.yoco.test_public_key', '')),
            'gigtune_yoco_get_success_url' => static fn (mixed $bookingId = 0): string => rtrim((string) config('app.url', ''), '/') . '/yoco-success/?booking_id=' . max(0, (int) $bookingId),
            'gigtune_yoco_get_cancel_url' => static fn (mixed $bookingId = 0): string => rtrim((string) config('app.url', ''), '/') . '/yoco-cancel/?booking_id=' . max(0, (int) $bookingId),
            'gigtune_core_get_artists' => static function (array $args = []): array {
                /** @var GigTuneShortcodeService $service */
                $service = app(GigTuneShortcodeService::class);
                return $service->getArtists($args);
            },
            'gigtune_core_get_artist' => static function (mixed $id = 0): array {
                /** @var GigTuneShortcodeService $service */
                $service = app(GigTuneShortcodeService::class);
                $artist = $service->getArtistById((int) $id);
                return is_array($artist) ? $artist : [];
            },
            'gigtune_core_get_filter_options' => static function (): array {
                /** @var GigTuneShortcodeService $service */
                $service = app(GigTuneShortcodeService::class);
                return $service->getFilterOptions();
            },
            default => null,
        };
    }

    private static function defaultFallback(string $functionName): mixed
    {
        if (!isset(self::$warnedFallback[$functionName])) {
            self::$warnedFallback[$functionName] = true;
            try {
                logger()->warning('GigTune core bridge fallback invoked', ['function' => $functionName]);
            } catch (\Throwable) {
                // No-op in environments without logger bootstrap.
            }
        }

        if (str_starts_with($functionName, 'gigtune_is_')) {
            return false;
        }
        if (str_starts_with($functionName, 'gigtune_render_')) {
            return '';
        }
        if (str_starts_with($functionName, 'gigtune_get_')) {
            if (str_contains($functionName, '_url') || str_ends_with($functionName, '_label') || str_contains($functionName, '_status_sentence')) {
                return '';
            }
            if (str_contains($functionName, '_ids') || str_ends_with($functionName, '_list') || str_ends_with($functionName, '_options') || str_ends_with($functionName, '_aliases')) {
                return [];
            }
            if (str_contains($functionName, '_count') || str_contains($functionName, '_amount') || str_contains($functionName, '_score') || str_contains($functionName, '_rate')) {
                return 0;
            }
            return null;
        }
        if (
            str_starts_with($functionName, 'gigtune_set_')
            || str_starts_with($functionName, 'gigtune_update_')
            || str_starts_with($functionName, 'gigtune_store_')
            || str_starts_with($functionName, 'gigtune_handle_')
            || str_starts_with($functionName, 'gigtune_append_')
        ) {
            return null;
        }
        return null;
    }
}

