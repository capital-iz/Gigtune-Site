<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GigTuneWebPushService;
use App\Services\WordPressUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GigTunePushController extends Controller
{
    public function __construct(
        private readonly GigTuneWebPushService $push,
        private readonly WordPressUserService $users,
    ) {
    }

    public function config(Request $request): JsonResponse
    {
        $user = $this->requestUser($request);
        if (!is_array($user)) {
            return $this->wpError('gigtune_forbidden', 'Authentication required.', 401);
        }

        return response()->json([
            'ok' => true,
            'enabled' => $this->push->enabled(),
            'configured' => $this->push->configured(),
            'public_key' => $this->push->vapidPublicKey(),
            'app_id' => trim((string) $request->query('app_id', 'gigtune-main')) ?: 'gigtune-main',
        ], 200);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $user = $this->requestUser($request);
        if (!is_array($user)) {
            return $this->wpError('gigtune_forbidden', 'Authentication required.', 401);
        }
        if (!$this->push->enabled()) {
            return $this->wpError('gigtune_push_disabled', 'Push notifications are disabled.', 400);
        }

        $subscription = $request->input('subscription');
        if (!is_array($subscription)) {
            return $this->wpError('gigtune_push_invalid_subscription', 'Invalid push subscription payload.', 422);
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return $this->wpError('gigtune_forbidden', 'Authentication required.', 401);
        }

        $appId = trim((string) $request->input('app_id', 'gigtune-main'));
        if ($appId === '') {
            $appId = 'gigtune-main';
        }

        try {
            $saved = $this->push->saveSubscription(
                $userId,
                $subscription,
                $appId,
                (string) $request->userAgent()
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->wpError('gigtune_push_invalid_subscription', $exception->getMessage(), 422);
        } catch (\Throwable $throwable) {
            return $this->wpError('gigtune_push_subscribe_failed', 'Unable to save push subscription.', 500);
        }

        return response()->json([
            'ok' => true,
            'saved' => [
                'endpoint' => (string) ($saved['endpoint'] ?? ''),
                'app_id' => (string) ($saved['app_id'] ?? $appId),
                'updated_at' => (int) ($saved['updated_at'] ?? now()->timestamp),
            ],
        ], 200);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $user = $this->requestUser($request);
        if (!is_array($user)) {
            return $this->wpError('gigtune_forbidden', 'Authentication required.', 401);
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return $this->wpError('gigtune_forbidden', 'Authentication required.', 401);
        }

        $endpoint = trim((string) $request->input('endpoint', ''));
        $appId = trim((string) $request->input('app_id', ''));

        try {
            if ($endpoint !== '') {
                $removed = $this->push->removeSubscription($userId, $endpoint) ? 1 : 0;
            } else {
                $removed = $this->push->removeAllSubscriptionsByUser($userId, $appId !== '' ? $appId : null);
            }
        } catch (\Throwable) {
            return $this->wpError('gigtune_push_unsubscribe_failed', 'Unable to remove push subscription.', 500);
        }

        return response()->json([
            'ok' => true,
            'removed' => $removed,
        ], 200);
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
