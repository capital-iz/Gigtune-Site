<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WordPressUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GigTunePolicyController extends Controller
{
    public function __construct(
        private readonly WordPressUserService $users,
    ) {
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->attributes->get('gigtune_user');
        if (!is_array($user)) {
            return response()->json([
                'ok' => false,
                'error' => 'Authentication required.',
            ], 401);
        }

        return response()->json([
            'ok' => true,
            'user_id' => (int) $user['id'],
            'policy' => $this->users->getPolicyStatus((int) $user['id']),
        ], 200);
    }

    public function accept(Request $request): JsonResponse
    {
        $user = $request->attributes->get('gigtune_user');
        if (!is_array($user)) {
            return response()->json([
                'ok' => false,
                'error' => 'Authentication required.',
            ], 401);
        }

        $payload = $this->requestPayload($request);
        $required = array_keys($this->users->requiredPolicyVersions());
        $accepted = $this->users->mapAcceptedPolicyInput($payload);
        $missing = array_values(array_diff($required, $accepted));

        if (!empty($missing)) {
            return response()->json([
                'ok' => false,
                'error' => 'All required policies must be accepted.',
                'missing' => $missing,
            ], 422);
        }

        $this->users->storePolicyAcceptance((int) $user['id'], $accepted);

        return response()->json([
            'ok' => true,
            'user_id' => (int) $user['id'],
            'policy' => $this->users->getPolicyStatus((int) $user['id']),
        ], 200);
    }

    private function requestPayload(Request $request): array
    {
        $payload = $request->all();
        if (!empty($payload)) {
            return is_array($payload) ? $payload : [];
        }

        $raw = (string) $request->getContent();
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
