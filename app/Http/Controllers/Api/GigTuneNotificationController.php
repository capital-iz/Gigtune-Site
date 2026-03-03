<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NotificationServiceException;
use App\Services\WordPressNotificationService;
use App\Services\WordPressUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GigTuneNotificationController extends Controller
{
    public function __construct(
        private readonly WordPressNotificationService $notifications,
        private readonly WordPressUserService $users,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->requestUser($request);
        if (!is_array($user)) {
            return $this->wpError('gigtune_forbidden', 'Authentication required.', 401);
        }

        $actorUserId = (int) ($user['id'] ?? 0);
        $actorIsAdmin = (bool) ($user['is_admin'] ?? false);

        $targetUserId = $actorUserId;
        if ($actorIsAdmin && $request->filled('user_id')) {
            $targetUserId = abs((int) $request->query('user_id'));
        }

        try {
            $payload = $this->notifications->list($targetUserId, $actorUserId, $actorIsAdmin, [
                'per_page' => $request->query('per_page'),
                'page' => $request->query('page'),
                'only_unread' => $request->query('only_unread'),
                'include_archived' => $request->query('include_archived'),
                'include_deleted' => $request->query('include_deleted'),
                'type' => $request->query('type'),
            ]);
        } catch (NotificationServiceException $exception) {
            return $this->wpError($exception->errorCode, $exception->getMessage(), $exception->statusCode);
        }

        return response()->json($payload, 200);
    }

    public function read(Request $request, int $id): JsonResponse
    {
        return $this->mutate($request, $id, 'read');
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        return $this->mutate($request, $id, 'archive');
    }

    public function unarchive(Request $request, int $id): JsonResponse
    {
        return $this->mutate($request, $id, 'unarchive');
    }

    public function delete(Request $request, int $id): JsonResponse
    {
        return $this->mutate($request, $id, 'delete');
    }

    private function mutate(Request $request, int $id, string $action): JsonResponse
    {
        $user = $this->requestUser($request);
        if (!is_array($user)) {
            return $this->wpError('gigtune_forbidden', 'Authentication required.', 401);
        }

        $actorUserId = (int) ($user['id'] ?? 0);
        $actorIsAdmin = (bool) ($user['is_admin'] ?? false);

        try {
            $payload = match ($action) {
                'read' => $this->notifications->markRead($id, $actorUserId, $actorIsAdmin),
                'archive' => $this->notifications->archive($id, $actorUserId, $actorIsAdmin),
                'unarchive' => $this->notifications->unarchive($id, $actorUserId, $actorIsAdmin),
                'delete' => $this->notifications->delete($id, $actorUserId, $actorIsAdmin),
                default => throw new NotificationServiceException('gigtune_invalid_action', 'Invalid action.', 400),
            };
        } catch (NotificationServiceException $exception) {
            return $this->wpError($exception->errorCode, $exception->getMessage(), $exception->statusCode);
        }

        return response()->json($payload, 200);
    }

    private function requestUser(Request $request): ?array
    {
        $user = $request->attributes->get('gigtune_user');
        if (is_array($user)) {
            return $user;
        }

        $sessionUserId = (int) $request->session()->get('gigtune_auth_user_id', 0);
        if ($sessionUserId <= 0) {
            return null;
        }

        $resolved = $this->users->getUserById($sessionUserId);
        return is_array($resolved) ? $resolved : null;
    }

    private function wpError(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'data' => [
                'status' => $status,
            ],
        ], $status);
    }
}
