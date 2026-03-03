<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GigTuneAssessmentController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'module' => 'assessment',
            'version' => '10.11.2',
            'server_time' => now()->format('Y-m-d H:i:s'),
        ], 200);
    }

    public function completeKeywords(Request $request): JsonResponse
    {
        $rawBody = file_get_contents('php://input');
        $payload = json_decode(is_string($rawBody) ? $rawBody : '', true);
        $keywords = is_array($payload) ? ($payload['keywords'] ?? null) : null;

        if (!is_array($payload) || !is_array($keywords)) {
            return response()->json([
                'ok' => false,
                'error' => 'Invalid input. keywords must be an array of strings.',
            ], 400);
        }

        $receivedCount = count($keywords);
        $cleaned = [];
        $emptyFiltered = 0;

        foreach ($keywords as $keyword) {
            if (!is_string($keyword)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'Invalid input. keywords must be an array of strings.',
                ], 400);
            }

            $value = trim($keyword);
            if ($value === '') {
                $emptyFiltered++;
                continue;
            }

            $cleaned[] = $value;
        }

        $unique = array_values(array_unique($cleaned));
        if (count($unique) > 500) {
            $unique = array_slice($unique, 0, 500);
        }

        return response()->json([
            'ok' => true,
            'received_count' => $receivedCount,
            'unique_count' => count($unique),
            'empty_filtered' => $emptyFiltered,
            'final_keywords' => $unique,
        ], 200);
    }
}
