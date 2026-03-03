<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WordPressNotificationService
{
    private string $connectionName;
    private string $tablePrefix;

    public function __construct()
    {
        $this->connectionName = (string) config('gigtune.wordpress.database_connection', 'wordpress');
        $this->tablePrefix = (string) config('gigtune.wordpress.table_prefix', 'wp_');
    }

    public function list(int $targetUserId, int $actorUserId, bool $actorIsAdmin, array $args = []): array
    {
        $targetUserId = abs($targetUserId);
        $actorUserId = abs($actorUserId);
        if ($targetUserId <= 0 || $actorUserId <= 0) {
            throw new NotificationServiceException('gigtune_invalid_user', 'Invalid user for notifications.', 422);
        }

        if (!$actorIsAdmin && $targetUserId !== $actorUserId) {
            throw new NotificationServiceException('gigtune_invalid_user', 'Invalid user for notifications.', 422);
        }

        $perPage = max(1, (int) ($args['per_page'] ?? 20));
        $page = max(1, (int) ($args['page'] ?? 1));
        $onlyUnread = $this->parseBool($args['only_unread'] ?? false);
        $onlyArchived = $this->parseBool($args['only_archived'] ?? ($args['archived'] ?? false));
        $includeArchived = $this->parseBool($args['include_archived'] ?? false);
        $includeDeleted = $this->parseBool($args['include_deleted'] ?? false);
        $type = trim((string) ($args['type'] ?? ''));
        $orderBy = strtolower(trim((string) ($args['order_by'] ?? 'created_at')));

        $baseQuery = $this->db()
            ->table($this->postsTable() . ' as p')
            ->where('p.post_type', 'gigtune_notification')
            ->where('p.post_status', 'publish')
            ->whereExists(function ($query) use ($targetUserId): void {
                $query->selectRaw('1')
                    ->from($this->postmetaTable() . ' as pm_owner')
                    ->whereColumn('pm_owner.post_id', 'p.ID')
                    ->whereIn('pm_owner.meta_key', [
                        'gigtune_notification_recipient_user_id',
                        'gigtune_notification_user_id',
                        'recipient_user_id',
                    ])
                    ->where('pm_owner.meta_value', (string) $targetUserId);
            });

        if ($type !== '') {
            $baseQuery->whereExists(function ($query) use ($type): void {
                $query->selectRaw('1')
                    ->from($this->postmetaTable() . ' as pm_type')
                    ->whereColumn('pm_type.post_id', 'p.ID')
                    ->whereIn('pm_type.meta_key', [
                        'gigtune_notification_type',
                        'notification_type',
                    ])
                    ->where('pm_type.meta_value', $type);
            });
        }

        if ($onlyUnread) {
            $baseQuery->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from($this->postmetaTable() . ' as pm_read')
                    ->whereColumn('pm_read.post_id', 'p.ID')
                    ->where('pm_read.meta_key', 'gigtune_notification_is_read')
                    ->where('pm_read.meta_value', '1');
            });
        }

        if ($onlyArchived) {
            $baseQuery->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from($this->postmetaTable() . ' as pm_archived')
                    ->whereColumn('pm_archived.post_id', 'p.ID')
                    ->where('pm_archived.meta_key', 'gigtune_notification_is_archived')
                    ->where('pm_archived.meta_value', '1');
            });
        } elseif (!$includeArchived) {
            $baseQuery->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from($this->postmetaTable() . ' as pm_archived')
                    ->whereColumn('pm_archived.post_id', 'p.ID')
                    ->where('pm_archived.meta_key', 'gigtune_notification_is_archived')
                    ->where('pm_archived.meta_value', '1');
            });
        }

        if (!$includeDeleted) {
            $baseQuery->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from($this->postmetaTable() . ' as pm_deleted')
                    ->whereColumn('pm_deleted.post_id', 'p.ID')
                    ->where('pm_deleted.meta_key', 'gigtune_notification_is_deleted')
                    ->where('pm_deleted.meta_value', '1');
            });
        }

        $total = (clone $baseQuery)->count('p.ID');
        $offset = ($page - 1) * $perPage;

        $idQuery = (clone $baseQuery);
        if (in_array($orderBy, ['post_date', 'date', 'post_date_desc', 'date_desc'], true)) {
            $idQuery->orderByDesc('p.post_date')->orderByDesc('p.ID');
        } else {
            $idQuery->orderByRaw(
                'CAST(COALESCE((SELECT pm_created.meta_value FROM ' . $this->postmetaTable() . ' pm_created WHERE pm_created.post_id = p.ID AND pm_created.meta_key = ? ORDER BY pm_created.meta_id DESC LIMIT 1), \'0\') AS UNSIGNED) DESC',
                ['created_at']
            )->orderByDesc('p.ID');
        }

        $ids = $idQuery
            ->offset($offset)
            ->limit($perPage)
            ->pluck('p.ID')
            ->map(static fn ($id): int => (int) $id)
            ->values();

        $items = $this->formatNotifications($ids);

        return [
            'items' => $items,
            'total' => (int) $total,
            'total_pages' => (int) ceil(((int) $total) / $perPage),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public function unreadCount(int $userId): int
    {
        $userId = abs($userId);
        if ($userId <= 0) {
            return 0;
        }

        return (int) $this->db()
            ->table($this->postsTable() . ' as p')
            ->where('p.post_type', 'gigtune_notification')
            ->where('p.post_status', 'publish')
            ->whereExists(function ($query) use ($userId): void {
                $query->selectRaw('1')
                    ->from($this->postmetaTable() . ' as pm_owner')
                    ->whereColumn('pm_owner.post_id', 'p.ID')
                    ->where('pm_owner.meta_key', 'gigtune_notification_user_id')
                    ->where('pm_owner.meta_value', (string) $userId);
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from($this->postmetaTable() . ' as pm_read')
                    ->whereColumn('pm_read.post_id', 'p.ID')
                    ->where('pm_read.meta_key', 'gigtune_notification_is_read')
                    ->where('pm_read.meta_value', '1');
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from($this->postmetaTable() . ' as pm_archived')
                    ->whereColumn('pm_archived.post_id', 'p.ID')
                    ->where('pm_archived.meta_key', 'gigtune_notification_is_archived')
                    ->where('pm_archived.meta_value', '1');
            })
            ->count('p.ID');
    }

    public function markRead(int $notificationId, int $actorUserId, bool $actorIsAdmin): array
    {
        $this->assertCanMutate($notificationId, $actorUserId, $actorIsAdmin);

        $readAt = now()->timestamp;
        $this->upsertMeta($notificationId, 'gigtune_notification_is_read', '1');
        $this->upsertMeta($notificationId, 'gigtune_notification_read_at', (string) $readAt);
        $this->upsertMeta($notificationId, 'is_read', '1');

        return [
            'id' => $notificationId,
            'is_read' => true,
            'read_at' => $this->metaToInt($this->getMetaValue($notificationId, 'gigtune_notification_read_at')),
        ];
    }

    public function markAllRead(int $targetUserId, int $actorUserId, bool $actorIsAdmin): array
    {
        $targetUserId = abs($targetUserId);
        $actorUserId = abs($actorUserId);
        if ($targetUserId <= 0 || $actorUserId <= 0) {
            throw new NotificationServiceException('gigtune_invalid_user', 'Invalid user for notifications.', 422);
        }
        if (!$actorIsAdmin && $targetUserId !== $actorUserId) {
            throw new NotificationServiceException('gigtune_invalid_user', 'Invalid user for notifications.', 422);
        }

        $notificationIds = $this->db()
            ->table($this->postsTable() . ' as p')
            ->where('p.post_type', 'gigtune_notification')
            ->where('p.post_status', 'publish')
            ->whereExists(function ($query) use ($targetUserId): void {
                $query->selectRaw('1')
                    ->from($this->postmetaTable() . ' as pm_owner')
                    ->whereColumn('pm_owner.post_id', 'p.ID')
                    ->whereIn('pm_owner.meta_key', [
                        'gigtune_notification_recipient_user_id',
                        'gigtune_notification_user_id',
                        'recipient_user_id',
                    ])
                    ->where('pm_owner.meta_value', (string) $targetUserId);
            })
            ->orderByDesc('p.ID')
            ->pluck('p.ID')
            ->map(static fn ($id): int => (int) $id)
            ->values();

        $updated = 0;
        $readAt = now()->timestamp;
        foreach ($notificationIds as $notificationId) {
            $id = (int) $notificationId;
            if ($id <= 0) {
                continue;
            }

            if (!$actorIsAdmin && $this->resolveOwnerId($id) !== $actorUserId) {
                continue;
            }

            $isRead = $this->metaToBool($this->getMetaValue($id, 'is_read'))
                || $this->metaToBool($this->getMetaValue($id, 'gigtune_notification_is_read'));

            if ($isRead) {
                $this->upsertMeta($id, 'gigtune_notification_is_archived', '1');
                $updated++;
                continue;
            }

            $this->upsertMeta($id, 'is_read', '1');
            $this->upsertMeta($id, 'gigtune_notification_is_read', '1');
            $this->upsertMeta($id, 'gigtune_notification_read_at', (string) $readAt);
            $this->upsertMeta($id, 'gigtune_notification_is_archived', '1');
            $updated++;
        }

        return [
            'updated' => $updated,
            'user_id' => $targetUserId,
        ];
    }

    public function archive(int $notificationId, int $actorUserId, bool $actorIsAdmin): array
    {
        $this->assertCanMutate($notificationId, $actorUserId, $actorIsAdmin);
        $this->upsertMeta($notificationId, 'gigtune_notification_is_archived', '1');
        return $this->formatNotification($notificationId);
    }

    public function unarchive(int $notificationId, int $actorUserId, bool $actorIsAdmin): array
    {
        $this->assertCanMutate($notificationId, $actorUserId, $actorIsAdmin);
        $this->upsertMeta($notificationId, 'gigtune_notification_is_archived', '0');
        return $this->formatNotification($notificationId);
    }

    public function restore(int $notificationId, int $actorUserId, bool $actorIsAdmin): array
    {
        return $this->unarchive($notificationId, $actorUserId, $actorIsAdmin);
    }

    public function delete(int $notificationId, int $actorUserId, bool $actorIsAdmin): array
    {
        $this->assertCanMutate($notificationId, $actorUserId, $actorIsAdmin);
        $this->upsertMeta($notificationId, 'gigtune_notification_is_deleted', '1');
        return $this->formatNotification($notificationId);
    }

    private function assertCanMutate(int $notificationId, int $actorUserId, bool $actorIsAdmin): void
    {
        $notificationId = abs($notificationId);
        $actorUserId = abs($actorUserId);
        if ($notificationId <= 0 || $actorUserId <= 0) {
            throw new NotificationServiceException('gigtune_invalid_notification', 'Invalid notification ID.', 422);
        }

        if (!$this->notificationExists($notificationId)) {
            throw new NotificationServiceException('gigtune_notification_not_found', 'Notification not found.', 404);
        }

        if ($actorIsAdmin) {
            return;
        }

        $ownerId = $this->resolveOwnerId($notificationId);
        if ($ownerId !== $actorUserId) {
            throw new NotificationServiceException('gigtune_forbidden', 'Not allowed.', 403);
        }
    }

    private function notificationExists(int $notificationId): bool
    {
        return $this->db()
            ->table($this->postsTable())
            ->where('ID', $notificationId)
            ->where('post_type', 'gigtune_notification')
            ->exists();
    }

    private function resolveOwnerId(int $notificationId): int
    {
        $owner = $this->metaToInt($this->getMetaValue($notificationId, 'gigtune_notification_recipient_user_id'));
        if ($owner > 0) {
            return $owner;
        }

        $owner = $this->metaToInt($this->getMetaValue($notificationId, 'gigtune_notification_user_id'));
        if ($owner > 0) {
            return $owner;
        }

        return $this->metaToInt($this->getMetaValue($notificationId, 'recipient_user_id'));
    }

    private function formatNotifications(Collection $notificationIds): array
    {
        if ($notificationIds->isEmpty()) {
            return [];
        }

        $ids = $notificationIds->values()->all();
        $metaMap = $this->loadMetaMap($ids, [
            'gigtune_notification_type',
            'notification_type',
            'gigtune_notification_object_type',
            'object_type',
            'gigtune_notification_object_id',
            'object_id',
            'gigtune_notification_booking_id',
            'gigtune_notification_title',
            'gigtune_notification_message',
            'message',
            'gigtune_notification_created_at',
            'created_at',
            'gigtune_notification_read_at',
            'gigtune_notification_is_read',
            'gigtune_notification_is_archived',
            'gigtune_notification_is_deleted',
        ]);
        $postMap = $this->loadPostMap($ids);

        $items = [];
        foreach ($ids as $notificationId) {
            $items[] = $this->buildNotificationItem(
                (int) $notificationId,
                $metaMap[(int) $notificationId] ?? [],
                $postMap[(int) $notificationId] ?? []
            );
        }

        return $items;
    }

    private function formatNotification(int $notificationId): array
    {
        $items = $this->formatNotifications(collect([$notificationId]));
        return $items[0] ?? [];
    }

    private function buildNotificationItem(int $notificationId, array $meta, array $post = []): array
    {
        $postTitle = trim((string) ($post['post_title'] ?? ''));
        $postDate = trim((string) ($post['post_date'] ?? ''));
        $type = $this->firstMetaValue($meta, ['gigtune_notification_type', 'notification_type']);
        $objectType = $this->firstMetaValue($meta, ['gigtune_notification_object_type', 'object_type']);
        $objectId = $this->metaToInt($this->firstMetaValue($meta, ['gigtune_notification_object_id', 'object_id', 'gigtune_notification_booking_id']));
        $title = $this->firstMetaValue($meta, ['gigtune_notification_title']);
        $message = $this->firstMetaValue($meta, ['gigtune_notification_message', 'message']);
        if ($message === '' && $postTitle !== '') {
            $message = $postTitle;
        }
        if ($title === '') {
            $title = $message !== '' ? $message : $postTitle;
        }
        $createdAt = $this->metaToInt($this->firstMetaValue($meta, ['gigtune_notification_created_at', 'created_at']));
        if ($createdAt <= 0 && $postDate !== '') {
            $createdAt = max(0, (int) strtotime($postDate));
        }

        return [
            'id' => $notificationId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'is_read' => $this->metaToBool($meta['gigtune_notification_is_read'] ?? ''),
            'read_at' => $this->metaToInt($meta['gigtune_notification_read_at'] ?? ''),
            'is_archived' => $this->metaToBool($meta['gigtune_notification_is_archived'] ?? ''),
            'is_deleted' => $this->metaToBool($meta['gigtune_notification_is_deleted'] ?? ''),
            'created_at' => $createdAt,
        ];
    }

    private function loadPostMap(array $postIds): array
    {
        if (empty($postIds)) {
            return [];
        }

        $rows = $this->db()
            ->table($this->postsTable())
            ->select('ID', 'post_title', 'post_date')
            ->whereIn('ID', $postIds)
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $id = (int) ($row->ID ?? 0);
            if ($id <= 0) {
                continue;
            }
            $map[$id] = [
                'post_title' => (string) ($row->post_title ?? ''),
                'post_date' => (string) ($row->post_date ?? ''),
            ];
        }

        return $map;
    }

    private function loadMetaMap(array $postIds, array $metaKeys): array
    {
        if (empty($postIds) || empty($metaKeys)) {
            return [];
        }

        $rows = $this->db()
            ->table($this->postmetaTable())
            ->select('post_id', 'meta_key', 'meta_value', 'meta_id')
            ->whereIn('post_id', $postIds)
            ->whereIn('meta_key', $metaKeys)
            ->orderByDesc('meta_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $postId = (int) $row->post_id;
            $metaKey = (string) $row->meta_key;
            if (!isset($map[$postId])) {
                $map[$postId] = [];
            }
            if (!array_key_exists($metaKey, $map[$postId])) {
                $map[$postId][$metaKey] = (string) $row->meta_value;
            }
        }

        return $map;
    }

    private function getMetaValue(int $postId, string $metaKey): string
    {
        $value = $this->db()
            ->table($this->postmetaTable())
            ->where('post_id', $postId)
            ->where('meta_key', $metaKey)
            ->orderByDesc('meta_id')
            ->value('meta_value');

        return is_string($value) ? $value : '';
    }

    private function upsertMeta(int $postId, string $metaKey, string $metaValue): void
    {
        $updated = $this->db()
            ->table($this->postmetaTable())
            ->where('post_id', $postId)
            ->where('meta_key', $metaKey)
            ->update(['meta_value' => $metaValue]);

        if ($updated > 0) {
            return;
        }

        $this->db()
            ->table($this->postmetaTable())
            ->insert([
                'post_id' => $postId,
                'meta_key' => $metaKey,
                'meta_value' => $metaValue,
            ]);
    }

    private function firstMetaValue(array $meta, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($meta[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function metaToBool(mixed $value): bool
    {
        return trim((string) $value) === '1';
    }

    private function metaToInt(mixed $value): int
    {
        return (int) trim((string) $value);
    }

    private function parseBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (bool) ((int) $value);
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private function postsTable(): string
    {
        return $this->tablePrefix . 'posts';
    }

    private function postmetaTable(): string
    {
        return $this->tablePrefix . 'postmeta';
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->connectionName);
    }
}
