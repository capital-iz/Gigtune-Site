<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GigTunePaymentWebhookService
{
    public function yocoHealth(): array
    {
        $secretSource = $this->yocoWebhookSecretSource();
        $lastEventTs = (int) $this->getOptionValue('gigtune_yoco_last_webhook_event_time');

        return [
            'ok' => true,
            'route' => 'yoco-webhook',
            'method' => 'GET',
            'version' => 'laravel-native',
            'server_time' => now()->format('Y-m-d H:i:s'),
            'configured' => $secretSource !== 'none',
            'secret_source' => $secretSource,
            'auto_settle_enabled' => true,
            'last_webhook_event_time' => $lastEventTs > 0 ? date('Y-m-d H:i:s', $lastEventTs) : '',
            'last_webhook_result' => $this->sanitizeKey((string) $this->getOptionValue('gigtune_yoco_last_webhook_result')),
        ];
    }

    public function handleYocoWebhook(string $rawBody, array $headers): array
    {
        $secretBytes = $this->yocoWebhookSecretBytes();
        if ($secretBytes === '') {
            $this->setYocoLastWebhookState('config_missing');
            return ['status' => 403, 'payload' => ['error' => 'Webhook not configured']];
        }
        if ($rawBody === '') {
            $this->setYocoLastWebhookState('parse_error');
            return ['status' => 400, 'payload' => ['error' => 'Empty payload']];
        }

        $id = trim((string) ($headers['webhook-id'] ?? ''));
        $ts = trim((string) ($headers['webhook-timestamp'] ?? ''));
        $sig = trim((string) ($headers['webhook-signature'] ?? ''));

        if (!$this->isWebhookTimestampFresh($ts, 180)) {
            $this->setYocoLastWebhookState('replay_invalid');
            return ['status' => 401, 'payload' => ['error' => 'Invalid signature']];
        }

        $expected = base64_encode(hash_hmac('sha256', $id . '.' . $ts . '.' . $rawBody, $secretBytes, true));
        if ($id === '' || $sig === '' || !$this->yocoSignatureMatchesV1($expected, $sig)) {
            $this->setYocoLastWebhookState('signature_invalid');
            return ['status' => 401, 'payload' => ['error' => 'Invalid signature']];
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $this->setYocoLastWebhookState('parse_error');
            return ['status' => 400, 'payload' => ['error' => 'Invalid payload']];
        }

        $eventType = $this->extractYocoEventType($payload);
        $eventId = $this->extractYocoEventId($payload);
        $checkoutId = $this->extractYocoCheckoutId($payload);
        $eventStatus = $this->extractYocoEventStatus($payload);
        $bookingId = $this->extractBookingIdFromYocoPayload($payload);

        if ($checkoutId === '' && $bookingId <= 0) {
            $this->setYocoLastWebhookState('ignored_missing_checkoutId', $eventType, '', $eventId);
            return ['status' => 200, 'payload' => ['ok' => true]];
        }

        if ($bookingId <= 0) {
            $ids = $this->bookingIdsByYocoCheckoutId($checkoutId, 3);
            if (count($ids) > 1) {
                $this->setYocoLastWebhookState('multiple_match', $eventType, $checkoutId, $eventId);
                return ['status' => 200, 'payload' => ['ok' => true]];
            }
            if (empty($ids)) {
                $this->setYocoLastWebhookState('no_match', $eventType, $checkoutId, $eventId);
                return ['status' => 200, 'payload' => ['ok' => true]];
            }
            $bookingId = (int) $ids[0];
        }
        if (!$this->isBookingPost($bookingId)) {
            $this->setYocoLastWebhookState('no_match', $eventType, $checkoutId, $eventId);
            return ['status' => 200, 'payload' => ['ok' => true]];
        }

        $eventKey = strtolower(trim($eventType));
        $statusKey = strtolower(trim($eventStatus));
        $marker = $this->yocoIdempotencyMarker($eventId, $checkoutId, $eventKey, $statusKey);
        if ($marker !== '') {
            $done = $this->getYocoDoneMarker($marker);
            if (($done['state'] ?? '') === 'done') {
                $this->setYocoLastWebhookState('duplicate_ignored', $eventType, $checkoutId, $eventId);
                return ['status' => 200, 'payload' => ['ok' => true]];
            }
            if (!$this->beginYocoWebhookProcessing($marker)) {
                $this->setYocoLastWebhookState('processing_lock', $eventType, $checkoutId, $eventId);
                return ['status' => 200, 'payload' => ['ok' => true]];
            }
        }

        $this->upsertPostMeta($bookingId, 'gigtune_yoco_last_webhook_at', (string) now()->timestamp);
        $this->upsertPostMeta($bookingId, 'gigtune_yoco_last_event_type', $eventType);
        if ($checkoutId !== '') {
            $this->upsertPostMeta($bookingId, 'gigtune_yoco_checkout_id', $checkoutId);
        }
        $this->upsertPostMeta($bookingId, 'gigtune_yoco_last_event_id', $eventId);

        $isSuccess = in_array($eventKey, ['checkout.succeeded', 'checkout_succeeded', 'payment.succeeded', 'payment_succeeded'], true)
            || (in_array($statusKey, ['succeeded', 'successful', 'paid', 'completed', 'complete'], true)
                && (str_contains($eventKey, 'checkout') || str_contains($eventKey, 'payment') || $eventKey === ''));
        $isFailed = in_array($eventKey, ['checkout.failed', 'checkout_failed', 'payment.failed', 'payment_failed', 'checkout.cancelled', 'checkout.canceled', 'checkout_cancelled', 'checkout_canceled'], true)
            || in_array($statusKey, ['failed', 'cancelled', 'canceled', 'declined', 'error'], true);

        if ($isSuccess) {
            $current = strtoupper($this->getPostMetaValue($bookingId, 'gigtune_payment_status'));
            $alreadyPaid = in_array($current, ['CONFIRMED_HELD_PENDING_COMPLETION', 'ESCROW_FUNDED', 'PAID_ESCROWED'], true);
            if (!$alreadyPaid) {
                $this->applyPaymentConfirmed($bookingId, 'yoco', 'YOCO webhook confirmed payment.');
            }
            $key = $alreadyPaid ? 'already_paid' : 'paid_applied';
            $this->upsertPostMeta($bookingId, 'gigtune_yoco_last_webhook_result', $key);
            $this->setYocoLastWebhookState($key, $eventType, $checkoutId, $eventId);
            if ($marker !== '') {
                $this->finishYocoWebhookProcessing($marker, $key);
            }
            return ['status' => 200, 'payload' => ['ok' => true]];
        }

        if (in_array($eventKey, ['refund.succeeded', 'refund_succeeded', 'checkout.refund.succeeded'], true)) {
            $this->applyYocoRefundResult($bookingId, $checkoutId, $eventType, 'SUCCEEDED', '');
            $this->upsertPostMeta($bookingId, 'gigtune_yoco_last_webhook_result', 'refund_succeeded');
            $this->setYocoLastWebhookState('refund_succeeded', $eventType, $checkoutId, $eventId);
            if ($marker !== '') {
                $this->finishYocoWebhookProcessing($marker, 'refund_succeeded');
            }
            return ['status' => 200, 'payload' => ['ok' => true]];
        }

        if (in_array($eventKey, ['refund.failed', 'refund_failed', 'checkout.refund.failed'], true)) {
            $reason = $this->sanitizeText((string) ($payload['data']['failureReason'] ?? ($payload['data']['reason'] ?? ($payload['message'] ?? 'Refund failed.'))));
            $this->applyYocoRefundResult($bookingId, $checkoutId, $eventType, 'FAILED', $reason);
            $this->upsertPostMeta($bookingId, 'gigtune_yoco_last_webhook_result', 'refund_failed');
            $this->setYocoLastWebhookState('refund_failed', $eventType, $checkoutId, $eventId);
            if ($marker !== '') {
                $this->finishYocoWebhookProcessing($marker, 'refund_failed');
            }
            return ['status' => 200, 'payload' => ['ok' => true]];
        }

        if ($isFailed) {
            $current = strtoupper($this->getPostMetaValue($bookingId, 'gigtune_payment_status'));
            if (!in_array($current, ['ESCROW_FUNDED', 'CONFIRMED_HELD_PENDING_COMPLETION', 'PAID_ESCROWED'], true) && $current !== 'FAILED') {
                $this->upsertPostMeta($bookingId, 'gigtune_payment_status', 'FAILED');
            }
            $this->upsertPostMeta($bookingId, 'gigtune_yoco_last_webhook_result', 'payment_failed');
            $this->setYocoLastWebhookState('payment_failed', $eventType, $checkoutId, $eventId);
            if ($marker !== '') {
                $this->finishYocoWebhookProcessing($marker, 'payment_failed');
            }
            return ['status' => 200, 'payload' => ['ok' => true]];
        }

        $this->upsertPostMeta($bookingId, 'gigtune_yoco_last_webhook_result', 'ignored_event');
        $this->setYocoLastWebhookState('ignored_event', $eventType, $checkoutId, $eventId);
        if ($marker !== '') {
            $this->finishYocoWebhookProcessing($marker, 'ignored_event');
        }
        return ['status' => 200, 'payload' => ['ok' => true]];
    }

    public function handlePaystackWebhook(string $rawBody, array $headers): array
    {
        $secret = trim((string) config('gigtune.payments.paystack.secret_key', ''));
        if ($secret === '') {
            $this->appendPaystackWebhookLog([
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'event' => '',
                'reference' => '',
                'signature_ok' => false,
                'outcome' => 'error: Paystack keys missing',
            ]);
            return ['status' => 400, 'payload' => ['error' => 'Paystack keys missing']];
        }

        $signature = trim((string) ($headers['x-paystack-signature'] ?? ''));
        $payload = json_decode($rawBody, true);
        $eventName = is_array($payload) ? $this->sanitizeText((string) ($payload['event'] ?? '')) : '';
        $reference = is_array($payload) ? $this->sanitizeText((string) (($payload['data']['reference'] ?? '') ?? '')) : '';

        $computed = hash_hmac('sha512', $rawBody, $secret);
        $signatureOk = $signature !== '' && hash_equals($computed, $signature);
        if (!$signatureOk) {
            $this->appendPaystackWebhookLog([
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'event' => $eventName,
                'reference' => $reference,
                'signature_ok' => false,
                'outcome' => 'error: invalid signature',
            ]);
            return ['status' => 400, 'payload' => ['error' => 'Invalid signature']];
        }

        if (!is_array($payload)) {
            $this->appendPaystackWebhookLog([
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'event' => $eventName,
                'reference' => $reference,
                'signature_ok' => true,
                'outcome' => 'error: invalid payload',
            ]);
            return ['status' => 400, 'payload' => ['error' => 'Invalid payload']];
        }

        $outcome = 'ok';
        $event = strtolower((string) ($payload['event'] ?? ''));
        $bookingId = $this->extractBookingIdFromPaystackPayload($payload);
        if ($bookingId > 0 && $this->isBookingPost($bookingId)) {
            if ($event === 'charge.success') {
                $this->applyPaymentConfirmed($bookingId, 'paystack', 'Paystack webhook confirmed payment.');
            } elseif (in_array($event, ['charge.failed', 'charge.reversed', 'charge.dispute.create'], true)) {
                $current = strtoupper($this->getPostMetaValue($bookingId, 'gigtune_payment_status'));
                if (!in_array($current, ['ESCROW_FUNDED', 'CONFIRMED_HELD_PENDING_COMPLETION', 'PAID_ESCROWED'], true)) {
                    $this->upsertPostMeta($bookingId, 'gigtune_payment_status', 'FAILED');
                }
            }
        } else {
            $outcome = 'ok: unmatched_booking';
        }

        $this->appendPaystackWebhookLog([
            'timestamp' => now()->format('Y-m-d H:i:s'),
            'event' => $eventName,
            'reference' => $reference,
            'signature_ok' => true,
            'outcome' => $outcome,
        ]);

        return ['status' => 200, 'payload' => ['ok' => true]];
    }

    private function yocoWebhookSecretSource(): string
    {
        $configured = trim((string) config('gigtune.payments.yoco.webhook_secret', ''));
        if ($configured !== '') {
            return 'env';
        }
        $optionSecret = trim((string) $this->getOptionValue('gigtune_yoco_webhook_secret'));
        return $optionSecret !== '' ? 'options' : 'none';
    }

    private function yocoWebhookSecret(): string
    {
        $configured = trim((string) config('gigtune.payments.yoco.webhook_secret', ''));
        return $configured !== '' ? $configured : trim((string) $this->getOptionValue('gigtune_yoco_webhook_secret'));
    }

    private function yocoWebhookSecretBytes(): string
    {
        $secret = $this->yocoWebhookSecret();
        if ($secret === '') {
            return '';
        }
        if (str_starts_with(strtolower($secret), 'whsec_')) {
            $secret = substr($secret, 6);
        }
        $decoded = base64_decode($secret, true);
        return $decoded === false ? '' : (string) $decoded;
    }

    private function extractYocoV1Signatures(string $signatureHeader): array
    {
        $signatureHeader = trim($signatureHeader);
        if ($signatureHeader === '') {
            return [];
        }

        $tokens = preg_split('/\s+/', $signatureHeader);
        if (!is_array($tokens)) {
            return [];
        }

        $signatures = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '' || !str_starts_with(strtolower($token), 'v1,')) {
                continue;
            }
            $sig = trim((string) substr($token, 3));
            if ($sig !== '') {
                $signatures[] = $sig;
            }
        }

        return array_values(array_unique($signatures));
    }

    private function yocoSignatureMatchesV1(string $expectedSignature, string $signatureHeader): bool
    {
        $expectedSignature = trim($expectedSignature);
        if ($expectedSignature === '') {
            return false;
        }

        foreach ($this->extractYocoV1Signatures($signatureHeader) as $candidate) {
            if (hash_equals($expectedSignature, (string) $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function isWebhookTimestampFresh(string $timestamp, int $maxSkewSeconds = 180): bool
    {
        $timestamp = trim($timestamp);
        if ($timestamp === '' || preg_match('/^\d+$/', $timestamp) !== 1) {
            return false;
        }

        $eventTs = (int) $timestamp;
        if ($eventTs <= 0) {
            return false;
        }

        return abs(time() - $eventTs) <= max(1, abs($maxSkewSeconds));
    }

    private function extractYocoEventType(array $payload): string
    {
        $candidates = [
            $payload['type'] ?? '',
            $payload['event'] ?? '',
            $payload['name'] ?? '',
            $payload['data']['type'] ?? '',
            $payload['data']['event'] ?? '',
        ];
        foreach ($candidates as $candidate) {
            $value = $this->sanitizeText((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function extractYocoEventStatus(array $payload): string
    {
        $candidates = [
            $payload['status'] ?? '',
            $payload['state'] ?? '',
            $payload['data']['status'] ?? '',
            $payload['data']['state'] ?? '',
            $payload['data']['checkout']['status'] ?? '',
            $payload['data']['checkout']['state'] ?? '',
            $payload['data']['payment']['status'] ?? '',
        ];
        foreach ($candidates as $candidate) {
            $value = $this->sanitizeText((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function extractYocoEventId(array $payload): string
    {
        $candidates = [
            $payload['eventId'] ?? '',
            $payload['event_id'] ?? '',
            $payload['id'] ?? '',
            $payload['data']['eventId'] ?? '',
            $payload['data']['event_id'] ?? '',
            $payload['data']['id'] ?? '',
        ];
        foreach ($candidates as $candidate) {
            $value = $this->sanitizeText((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function yocoPayloadRoot(array $payload): array
    {
        if (isset($payload['payload']) && is_array($payload['payload'])) {
            return $payload['payload'];
        }
        if (isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return [];
    }

    private function extractYocoCheckoutId(array $payload): string
    {
        $root = $this->yocoPayloadRoot($payload);
        $candidates = [
            $payload['metadata']['checkoutId'] ?? '',
            $payload['metadata']['checkout_id'] ?? '',
            $payload['data']['metadata']['checkoutId'] ?? '',
            $payload['data']['metadata']['checkout_id'] ?? '',
            $payload['data']['checkout']['metadata']['checkoutId'] ?? '',
            $payload['data']['checkout']['metadata']['checkout_id'] ?? '',
            $payload['checkoutId'] ?? '',
            $payload['checkout_id'] ?? '',
            $payload['data']['checkoutId'] ?? '',
            $payload['data']['checkout_id'] ?? '',
            $payload['data']['checkout']['id'] ?? '',
            $payload['data']['payment']['checkoutId'] ?? '',
            $root['checkoutId'] ?? '',
            $root['payment']['checkoutId'] ?? '',
            $root['payment']['checkout_id'] ?? '',
            $root['payment']['checkout']['id'] ?? '',
            $root['checkout']['id'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            $value = $this->sanitizeText((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function extractBookingIdFromYocoPayload(array $payload): int
    {
        $root = $this->yocoPayloadRoot($payload);
        $candidates = [
            $payload['metadata']['booking_id'] ?? 0,
            $payload['data']['metadata']['booking_id'] ?? 0,
            $payload['data']['checkout']['metadata']['booking_id'] ?? 0,
            $root['metadata']['booking_id'] ?? 0,
            $root['payment']['metadata']['booking_id'] ?? 0,
            $root['payment']['checkout']['metadata']['booking_id'] ?? 0,
            $root['checkout']['metadata']['booking_id'] ?? 0,
        ];

        foreach ($candidates as $candidate) {
            $bookingId = abs((int) $candidate);
            if ($bookingId > 0 && $this->isBookingPost($bookingId)) {
                return $bookingId;
            }
        }

        return 0;
    }

    private function extractBookingIdFromPaystackPayload(array $payload): int
    {
        $candidates = [
            $payload['data']['metadata']['booking_id'] ?? 0,
            $payload['data']['booking_id'] ?? 0,
        ];
        foreach ($candidates as $candidate) {
            $bookingId = abs((int) $candidate);
            if ($bookingId > 0 && $this->isBookingPost($bookingId)) {
                return $bookingId;
            }
        }

        $reference = $this->sanitizeText((string) ($payload['data']['reference'] ?? ''));
        if ($reference !== '') {
            $ids = $this->db()->table($this->postsTable() . ' as p')
                ->select('p.ID')
                ->where('p.post_type', 'gigtune_booking')
                ->whereExists(function ($query) use ($reference): void {
                    $query->selectRaw('1')
                        ->from($this->postmetaTable() . ' as pm')
                        ->whereColumn('pm.post_id', 'p.ID')
                        ->whereIn('pm.meta_key', ['gigtune_payment_reference_human', 'gigtune_payment_reference'])
                        ->where('pm.meta_value', $reference);
                })
                ->limit(1)
                ->pluck('p.ID')
                ->all();
            if (!empty($ids)) {
                return (int) $ids[0];
            }
        }

        return 0;
    }

    private function bookingIdsByYocoCheckoutId(string $checkoutId, int $limit = 5): array
    {
        $checkoutId = $this->sanitizeText($checkoutId);
        if ($checkoutId === '') {
            return [];
        }

        $rows = $this->db()->table($this->postsTable() . ' as p')
            ->select('p.ID')
            ->where('p.post_type', 'gigtune_booking')
            ->whereExists(function ($query) use ($checkoutId): void {
                $query->selectRaw('1')
                    ->from($this->postmetaTable() . ' as pm')
                    ->whereColumn('pm.post_id', 'p.ID')
                    ->where('pm.meta_key', 'gigtune_yoco_checkout_id')
                    ->where('pm.meta_value', $checkoutId);
            })
            ->orderByDesc('p.ID')
            ->limit(max(1, min($limit, 20)))
            ->pluck('p.ID')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return array_values(array_unique($rows));
    }

    private function yocoIdempotencyMarker(string $eventId, string $checkoutId, string $eventType, string $eventStatus): string
    {
        $eventId = strtolower(trim($eventId));
        $checkoutId = strtolower(trim($checkoutId));
        $eventType = strtolower(trim($eventType));
        $eventStatus = strtolower(trim($eventStatus));

        if ($eventId !== '') {
            return 'event:' . $eventId;
        }
        if ($checkoutId === '' || $eventType === '') {
            return '';
        }

        return 'combo:' . md5($checkoutId . '|' . $eventType . '|' . $eventStatus);
    }

    private function getYocoDoneMarker(string $marker): array
    {
        $value = $this->decodeMaybeSerialized((string) $this->getOptionValue('gigtune_yoco_wh_done_' . md5($marker)));
        return is_array($value) ? $value : [];
    }

    private function beginYocoWebhookProcessing(string $marker): bool
    {
        return Cache::add('gigtune_yoco_wh_lock_' . md5($marker), 1, now()->addSeconds(120));
    }

    private function finishYocoWebhookProcessing(string $marker, string $result): void
    {
        $payload = [
            'marker' => $marker,
            'state' => 'done',
            'result' => $this->sanitizeKey($result),
            'processed_at' => now()->timestamp,
        ];
        $this->upsertOptionValue('gigtune_yoco_wh_done_' . md5($marker), serialize($payload));
        Cache::forget('gigtune_yoco_wh_lock_' . md5($marker));
    }

    private function setYocoLastWebhookState(string $result, string $eventType = '', string $checkoutId = '', string $eventId = ''): void
    {
        $this->upsertOptionValue('gigtune_yoco_last_webhook_event_time', (string) now()->timestamp);
        $this->upsertOptionValue('gigtune_yoco_last_webhook_result', $this->sanitizeKey($result));
        $this->upsertOptionValue('gigtune_yoco_last_webhook_event_type', $this->sanitizeText($eventType));
        $this->upsertOptionValue('gigtune_yoco_last_webhook_checkout_id', $this->sanitizeText($checkoutId));
        $this->upsertOptionValue('gigtune_yoco_last_webhook_event_id', $this->sanitizeText($eventId));
    }

    private function applyYocoRefundResult(int $bookingId, string $checkoutId, string $eventType, string $status, string $reason = ''): void
    {
        $checkoutId = $this->sanitizeText($checkoutId);
        $status = strtoupper($this->sanitizeText($status));
        if ($checkoutId !== '') {
            $this->upsertPostMeta($bookingId, 'gigtune_refund_checkout_id', $checkoutId);
        }

        $requestedBy = $this->getPostMetaValue($bookingId, 'gigtune_refund_requested_by');
        $requestedAt = $this->getPostMetaValue($bookingId, 'gigtune_refund_requested_at');

        if ($status === 'SUCCEEDED') {
            $this->updateRefundMeta($bookingId, 'SUCCEEDED', $requestedBy, $requestedAt, $checkoutId, '');
            $this->setBookingRefundLock($bookingId, false);
            $this->upsertPostMeta($bookingId, 'gigtune_payment_refunded', '1');
            $this->upsertPostMeta($bookingId, 'gigtune_refund_processed_at', (string) now()->timestamp);
            $this->upsertPostMeta($bookingId, 'gigtune_payment_status', 'REFUNDED_PARTIAL');
            $this->upsertPostMeta($bookingId, 'gigtune_booking_status', 'CANCELLED_BY_CLIENT');
        } else {
            $this->updateRefundMeta($bookingId, 'FAILED', $requestedBy, $requestedAt, $checkoutId, $reason);
            $this->setBookingRefundLock($bookingId, false);
        }

        $this->upsertPostMeta($bookingId, 'gigtune_yoco_refund_last_event', $this->sanitizeText($eventType));
    }

    private function applyPaymentConfirmed(int $bookingId, string $paymentMethod, string $note): void
    {
        $this->upsertPostMeta($bookingId, 'gigtune_payment_method', $this->sanitizeKey($paymentMethod));
        $this->upsertPostMeta($bookingId, 'gigtune_yoco_confirmed_at', (string) now()->timestamp);
        $this->upsertPostMeta($bookingId, 'gigtune_payment_status', 'CONFIRMED_HELD_PENDING_COMPLETION');
        $bookingStatus = strtoupper($this->getPostMetaValue($bookingId, 'gigtune_booking_status'));
        if ($bookingStatus === 'ACCEPTED_PENDING_PAYMENT') {
            $this->upsertPostMeta($bookingId, 'gigtune_booking_status', 'PAID_ESCROWED');
        }
        $this->upsertPostMeta($bookingId, 'gigtune_payment_confirmed_at', (string) now()->timestamp);
        if (trim($note) !== '') {
            $this->upsertPostMeta($bookingId, 'gigtune_payment_last_note', trim($note));
        }
    }

    private function updateRefundMeta(int $bookingId, string $status, string $requestedBy = '', string $requestedAt = '', string $checkoutId = '', string $failureReason = ''): void
    {
        $status = strtoupper($this->sanitizeText($status));
        if (!in_array($status, ['REQUESTED', 'PENDING', 'SUCCEEDED', 'FAILED', 'REJECTED'], true)) {
            $status = 'REQUESTED';
        }
        $this->upsertPostMeta($bookingId, 'gigtune_refund_status', $status);
        if (trim($requestedBy) !== '') {
            $this->upsertPostMeta($bookingId, 'gigtune_refund_requested_by', $this->sanitizeKey($requestedBy));
        }
        if ((int) $requestedAt > 0) {
            $this->upsertPostMeta($bookingId, 'gigtune_refund_requested_at', (string) (int) $requestedAt);
        }
        if (trim($checkoutId) !== '') {
            $this->upsertPostMeta($bookingId, 'gigtune_refund_checkout_id', $this->sanitizeText($checkoutId));
        }
        if (trim($failureReason) !== '') {
            $this->upsertPostMeta($bookingId, 'gigtune_refund_failure_reason', $this->sanitizeText($failureReason));
        } else {
            $this->deletePostMeta($bookingId, 'gigtune_refund_failure_reason');
        }
    }

    private function setBookingRefundLock(int $bookingId, bool $lock): void
    {
        if ($lock) {
            $this->upsertPostMeta($bookingId, 'gigtune_booking_locked', 'refund_pending');
            return;
        }

        $value = trim($this->getPostMetaValue($bookingId, 'gigtune_booking_locked'));
        if ($value === 'refund_pending') {
            $this->deletePostMeta($bookingId, 'gigtune_booking_locked');
        }
    }

    private function appendPaystackWebhookLog(array $entry): void
    {
        $existing = $this->decodeMaybeSerialized((string) $this->getOptionValue('gigtune_paystack_webhook_log'));
        $log = is_array($existing) ? $existing : [];
        $log[] = $entry;
        if (count($log) > 25) {
            $log = array_slice($log, -25);
        }
        $this->upsertOptionValue('gigtune_paystack_webhook_log', serialize(array_values($log)));
    }

    private function getPostMetaValue(int $postId, string $metaKey): string
    {
        $value = $this->db()->table($this->postmetaTable())
            ->where('post_id', $postId)
            ->where('meta_key', $metaKey)
            ->orderByDesc('meta_id')
            ->value('meta_value');

        return is_string($value) ? $value : '';
    }

    private function upsertPostMeta(int $postId, string $metaKey, string $metaValue): void
    {
        $this->db()->table($this->postmetaTable())->updateOrInsert(
            [
                'post_id' => $postId,
                'meta_key' => $metaKey,
            ],
            [
                'meta_value' => $metaValue,
            ],
        );
    }

    private function deletePostMeta(int $postId, string $metaKey): void
    {
        $this->db()->table($this->postmetaTable())
            ->where('post_id', $postId)
            ->where('meta_key', $metaKey)
            ->delete();
    }

    private function getOptionValue(string $optionName): string
    {
        $value = $this->db()->table($this->optionsTable())
            ->where('option_name', $optionName)
            ->value('option_value');

        return is_string($value) ? $value : '';
    }

    private function upsertOptionValue(string $optionName, string $optionValue): void
    {
        try {
            $this->db()->table($this->optionsTable())->updateOrInsert(
                [
                    'option_name' => $optionName,
                ],
                [
                    'option_value' => $optionValue,
                    'autoload' => 'no',
                ],
            );
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // If concurrent requests race on insert, update the existing row.
            $this->db()->table($this->optionsTable())
                ->where('option_name', $optionName)
                ->update([
                    'option_value' => $optionValue,
                    'autoload' => 'no',
                ]);
        }
    }

    private function decodeMaybeSerialized(string $value): mixed
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

    private function isBookingPost(int $postId): bool
    {
        return $this->db()->table($this->postsTable())
            ->where('ID', $postId)
            ->where('post_type', 'gigtune_booking')
            ->exists();
    }

    private function sanitizeText(string $value): string
    {
        $value = trim($value);
        return preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? $value;
    }

    private function sanitizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_replace('/[^a-z0-9_\-]/', '', $value) ?? '';
    }

    private function postsTable(): string
    {
        return $this->tablePrefix() . 'posts';
    }

    private function postmetaTable(): string
    {
        return $this->tablePrefix() . 'postmeta';
    }

    private function optionsTable(): string
    {
        return $this->tablePrefix() . 'options';
    }

    private function tablePrefix(): string
    {
        return (string) config('gigtune.wordpress.table_prefix', 'wp_');
    }

    private function db(): ConnectionInterface
    {
        return DB::connection((string) config('gigtune.wordpress.database_connection', 'wordpress'));
    }
}
