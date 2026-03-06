<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GigTuneWebPushService
{
    private const META_PREFIX = 'gigtune_push_subscription_';

    private string $connectionName;
    private string $tablePrefix;

    public function __construct()
    {
        $this->connectionName = (string) config('gigtune.wordpress.database_connection', 'wordpress');
        $this->tablePrefix = (string) config('gigtune.wordpress.table_prefix', 'wp_');
    }

    public function enabled(): bool
    {
        return (bool) config('gigtune.push.enabled', true);
    }

    public function vapidPublicKey(): string
    {
        return trim((string) config('gigtune.push.vapid_public_key', ''));
    }

    public function configured(): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        return $this->vapidSubject() !== ''
            && $this->vapidPublicKey() !== ''
            && $this->vapidPrivateKey() !== '';
    }

    /** @param array<string,mixed> $subscription */
    public function saveSubscription(int $userId, array $subscription, string $appId = 'gigtune-main', string $userAgent = ''): array
    {
        $userId = abs($userId);
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Invalid user.');
        }

        $payload = $this->normalizeSubscriptionPayload($subscription);
        $endpoint = (string) ($payload['endpoint'] ?? '');
        if ($endpoint === '') {
            throw new \InvalidArgumentException('Missing push subscription endpoint.');
        }

        $key = self::META_PREFIX . sha1($endpoint);
        $existing = $this->decodePayload($this->getUserMetaValue($userId, $key));
        $createdAt = (int) ($existing['created_at'] ?? 0);
        if ($createdAt <= 0) {
            $createdAt = now()->timestamp;
        }

        $record = [
            'endpoint' => $endpoint,
            'expiration_time' => (int) ($payload['expiration_time'] ?? 0),
            'content_encoding' => (string) ($payload['content_encoding'] ?? 'aesgcm'),
            'keys' => [
                'p256dh' => (string) (($payload['keys']['p256dh'] ?? '')),
                'auth' => (string) (($payload['keys']['auth'] ?? '')),
            ],
            'app_id' => trim($appId) !== '' ? trim($appId) : 'gigtune-main',
            'user_agent' => trim($userAgent),
            'created_at' => $createdAt,
            'updated_at' => now()->timestamp,
        ];

        $this->upsertUserMeta($userId, $key, json_encode($record, JSON_UNESCAPED_SLASHES));

        return $record;
    }

    public function removeSubscription(int $userId, string $endpoint): bool
    {
        $userId = abs($userId);
        $endpoint = trim($endpoint);
        if ($userId <= 0 || $endpoint === '') {
            return false;
        }

        $deleted = (int) $this->db()
            ->table($this->tablePrefix . 'usermeta')
            ->where('user_id', $userId)
            ->where('meta_key', self::META_PREFIX . sha1($endpoint))
            ->delete();

        return $deleted > 0;
    }

    public function removeAllSubscriptionsByUser(int $userId, ?string $appId = null): int
    {
        $subscriptions = $this->subscriptionsForUser($userId, $appId);
        $deleted = 0;
        foreach ($subscriptions as $subscription) {
            $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
            if ($endpoint === '') {
                continue;
            }
            if ($this->removeSubscription($userId, $endpoint)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** @return array<int,array<string,mixed>> */
    public function subscriptionsForUser(int $userId, ?string $appId = null): array
    {
        $userId = abs($userId);
        if ($userId <= 0) {
            return [];
        }

        $rows = $this->db()
            ->table($this->tablePrefix . 'usermeta')
            ->select(['meta_key', 'meta_value'])
            ->where('user_id', $userId)
            ->where('meta_key', 'like', self::META_PREFIX . '%')
            ->orderByDesc('umeta_id')
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $decoded = $this->decodePayload((string) ($row->meta_value ?? ''));
            $endpoint = trim((string) ($decoded['endpoint'] ?? ''));
            if ($endpoint === '') {
                continue;
            }
            if ($appId !== null && trim($appId) !== '') {
                $subAppId = trim((string) ($decoded['app_id'] ?? ''));
                if ($subAppId !== '' && $subAppId !== trim($appId)) {
                    continue;
                }
            }
            $items[] = $decoded;
        }

        return $items;
    }

    /** @param array<string,mixed> $extra */
    public function sendToUser(int $userId, string $title, string $body, string $url = '/notifications/', array $extra = []): array
    {
        $subscriptions = $this->subscriptionsForUser($userId);
        if ($subscriptions === []) {
            return ['sent' => 0, 'failed' => 0, 'removed' => 0];
        }

        if (!$this->configured()) {
            Log::warning('GigTune push skipped: VAPID not configured.', ['user_id' => $userId, 'count' => count($subscriptions)]);
            return ['sent' => 0, 'failed' => count($subscriptions), 'removed' => 0];
        }

        if (!class_exists('\\Minishlink\\WebPush\\WebPush') || !class_exists('\\Minishlink\\WebPush\\Subscription')) {
            Log::warning('GigTune push skipped: minishlink/web-push is not installed.', ['user_id' => $userId, 'count' => count($subscriptions)]);
            return ['sent' => 0, 'failed' => count($subscriptions), 'removed' => 0];
        }

        $payload = [
            'title' => trim($title) !== '' ? trim($title) : 'GigTune',
            'body' => trim($body) !== '' ? trim($body) : 'You have a new notification.',
            'url' => trim($url) !== '' ? trim($url) : '/notifications/',
            'tag' => trim((string) ($extra['tag'] ?? 'gigtune-live')),
            'icon' => '/wp-content/themes/gigtune-canon/assets/img/gigtune-app-icon-192.png',
            'badge' => '/wp-content/themes/gigtune-canon/assets/img/gigtune-app-icon-192.png',
            'meta' => $extra,
        ];

        $webPush = new \Minishlink\WebPush\WebPush([
            'VAPID' => [
                'subject' => $this->vapidSubject(),
                'publicKey' => $this->vapidPublicKey(),
                'privateKey' => $this->vapidPrivateKey(),
            ],
        ]);

        $sent = 0;
        $failed = 0;
        $removed = 0;

        foreach ($subscriptions as $subscription) {
            $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
            if ($endpoint === '') {
                continue;
            }

            try {
                $report = $webPush->sendOneNotification(
                    \Minishlink\WebPush\Subscription::create([
                        'endpoint' => $endpoint,
                        'publicKey' => (string) ($subscription['keys']['p256dh'] ?? ''),
                        'authToken' => (string) ($subscription['keys']['auth'] ?? ''),
                        'contentEncoding' => (string) ($subscription['content_encoding'] ?? 'aesgcm'),
                    ]),
                    json_encode($payload, JSON_UNESCAPED_SLASHES),
                    ['TTL' => 60]
                );

                if ($report->isSuccess()) {
                    $sent++;
                    continue;
                }

                $failed++;
                $reason = strtolower(trim((string) $report->getReason()));
                if ($this->shouldPruneSubscription($reason) && $this->removeSubscription($userId, $endpoint)) {
                    $removed++;
                }
            } catch (\Throwable $throwable) {
                $failed++;
                $reason = strtolower(trim($throwable->getMessage()));
                if ($this->shouldPruneSubscription($reason) && $this->removeSubscription($userId, $endpoint)) {
                    $removed++;
                }
                Log::warning('GigTune push send failed', [
                    'user_id' => $userId,
                    'endpoint' => $endpoint,
                    'error' => $throwable->getMessage(),
                ]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'removed' => $removed];
    }

    private function vapidSubject(): string
    {
        return trim((string) config('gigtune.push.vapid_subject', ''));
    }

    private function vapidPrivateKey(): string
    {
        return trim((string) config('gigtune.push.vapid_private_key', ''));
    }

    /** @param array<string,mixed> $subscription */
    private function normalizeSubscriptionPayload(array $subscription): array
    {
        $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
        $expiration = (int) ($subscription['expirationTime'] ?? $subscription['expiration_time'] ?? 0);
        $keysRaw = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];

        return [
            'endpoint' => $endpoint,
            'expiration_time' => $expiration,
            'content_encoding' => trim((string) ($subscription['contentEncoding'] ?? $subscription['content_encoding'] ?? 'aesgcm')),
            'keys' => [
                'p256dh' => trim((string) ($keysRaw['p256dh'] ?? '')),
                'auth' => trim((string) ($keysRaw['auth'] ?? '')),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function decodePayload(string $payload): array
    {
        $payload = trim($payload);
        if ($payload === '') {
            return [];
        }

        $json = json_decode($payload, true);
        if (is_array($json)) {
            return $json;
        }

        $decoded = @unserialize($payload, ['allowed_classes' => false]);
        return is_array($decoded) ? $decoded : [];
    }

    private function shouldPruneSubscription(string $reason): bool
    {
        if ($reason === '') {
            return false;
        }

        foreach (['404', '410', 'gone', 'expired', 'not found', 'unsubscribed'] as $needle) {
            if (str_contains($reason, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function getUserMetaValue(int $userId, string $metaKey): string
    {
        $value = $this->db()
            ->table($this->tablePrefix . 'usermeta')
            ->where('user_id', $userId)
            ->where('meta_key', $metaKey)
            ->orderByDesc('umeta_id')
            ->value('meta_value');

        return is_string($value) ? $value : '';
    }

    private function upsertUserMeta(int $userId, string $metaKey, string $metaValue): void
    {
        $updated = $this->db()
            ->table($this->tablePrefix . 'usermeta')
            ->where('user_id', $userId)
            ->where('meta_key', $metaKey)
            ->update(['meta_value' => $metaValue]);

        if ($updated > 0) {
            return;
        }

        $this->db()
            ->table($this->tablePrefix . 'usermeta')
            ->insert([
                'user_id' => $userId,
                'meta_key' => $metaKey,
                'meta_value' => $metaValue,
            ]);
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->connectionName);
    }
}
