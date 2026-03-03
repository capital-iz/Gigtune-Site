<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GigTunePaymentWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GigTunePaymentWebhookController extends Controller
{
    public function __construct(
        private readonly GigTunePaymentWebhookService $service,
    ) {
    }

    public function yocoHealth(): JsonResponse
    {
        return response()->json($this->service->yocoHealth(), 200);
    }

    public function yocoWebhook(Request $request): JsonResponse
    {
        $result = $this->service->handleYocoWebhook((string) $request->getContent(), $this->headers($request));
        return response()->json($result['payload'] ?? ['ok' => false], (int) ($result['status'] ?? 500));
    }

    public function paystackWebhook(Request $request): JsonResponse
    {
        $result = $this->service->handlePaystackWebhook((string) $request->getContent(), $this->headers($request));
        return response()->json($result['payload'] ?? ['ok' => false], (int) ($result['status'] ?? 500));
    }

    private function headers(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $key = strtolower((string) $name);
            if (!is_array($values) || empty($values)) {
                $headers[$key] = '';
                continue;
            }
            $headers[$key] = (string) $values[0];
        }

        return $headers;
    }
}
