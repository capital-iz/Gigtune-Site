<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GigTuneShortcodeService;
use App\Services\WordPressUserService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GigTuneCoreParityController extends Controller
{
    public function __construct(
        private readonly GigTuneShortcodeService $shortcodes,
        private readonly WordPressUserService $users,
    ) {
    }

    public function artists(Request $request): JsonResponse
    {
        $args = [
            'per_page' => (int) $request->query('per_page', 20),
            'paged' => (int) $request->query('paged', 1),
            'search' => (string) $request->query('search', $request->query('q', '')),
            'artist_slug' => (string) $request->query('artist_slug', ''),
        ];

        return response()->json($this->shortcodes->getArtists($args));
    }

    public function artist(int $id): JsonResponse
    {
        $artist = $this->shortcodes->getArtistById($id);
        if (!is_array($artist)) {
            return response()->json(['code' => 'gigtune_not_found', 'message' => 'Artist not found.'], 404);
        }

        return response()->json($artist);
    }

    public function filters(): JsonResponse
    {
        return response()->json($this->shortcodes->getFilterOptions());
    }

    public function artistAvailability(int $id): JsonResponse
    {
        $artist = $this->shortcodes->getArtistById($id);
        if (!is_array($artist)) {
            return response()->json(['code' => 'gigtune_not_found', 'message' => 'Artist not found.'], 404);
        }

        return response()->json([
            'id' => (int) ($artist['id'] ?? 0),
            'availability' => $artist['availability'] ?? [],
        ]);
    }

    public function bookings(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        $rows = $this->db()->table($this->posts() . ' as p')
            ->where('p.post_type', 'gigtune_booking')
            ->orderByDesc('p.ID')
            ->limit(100)
            ->get(['p.ID', 'p.post_title', 'p.post_date']);

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->ID;
        }

        $meta = $this->postMetaMap($ids, [
            'gigtune_booking_status',
            'gigtune_booking_event_date',
            'gigtune_booking_end_time',
            'gigtune_booking_budget',
            'gigtune_booking_client_user_id',
            'gigtune_booking_artist_profile_id',
            'gigtune_payment_status',
            'gigtune_payout_status',
            'gigtune_dispute_raised',
        ]);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row->ID;
            $m = $meta[$id] ?? [];

            if (is_array($user) && !$this->isAdmin($user)) {
                $uid = (int) ($user['id'] ?? 0);
                $clientId = (int) ($m['gigtune_booking_client_user_id'] ?? 0);
                if ($uid > 0 && $clientId > 0 && $uid !== $clientId) {
                    continue;
                }
            }

            $items[] = [
                'id' => $id,
                'title' => (string) $row->post_title,
                'date' => (string) $row->post_date,
                'status' => (string) ($m['gigtune_booking_status'] ?? ''),
                'event_date' => (string) ($m['gigtune_booking_event_date'] ?? ''),
                'budget' => (string) ($m['gigtune_booking_budget'] ?? ''),
                'client_user_id' => (int) ($m['gigtune_booking_client_user_id'] ?? 0),
                'artist_profile_id' => (int) ($m['gigtune_booking_artist_profile_id'] ?? 0),
                'payment_status' => (string) ($m['gigtune_payment_status'] ?? ''),
                'payout_status' => (string) ($m['gigtune_payout_status'] ?? ''),
                'dispute_raised' => (string) ($m['gigtune_dispute_raised'] ?? ''),
            ];
        }

        return response()->json(['items' => array_values($items), 'total' => count($items)]);
    }

    public function createBooking(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (!is_array($user)) {
            return response()->json(['code' => 'gigtune_forbidden', 'message' => 'Authentication required.'], 401);
        }

        $payload = $request->validate([
            'artist_profile_id' => ['required', 'integer', 'min:1'],
            'event_date' => ['required', 'string', 'max:50'],
            'end_time' => ['nullable', 'string', 'max:20'],
            'budget' => ['nullable', 'string', 'max:50'],
            'psa_id' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $artistProfileId = (int) $payload['artist_profile_id'];
        $artist = $this->shortcodes->getArtistById($artistProfileId);
        if (!is_array($artist)) {
            return response()->json(['code' => 'gigtune_not_found', 'message' => 'Artist not found.'], 404);
        }

        $eventDate = trim((string) ($payload['event_date'] ?? ''));
        if (!$this->shortcodes->isArtistAvailableForEvent($artistProfileId, $eventDate)) {
            return response()->json([
                'code' => 'gigtune_artist_unavailable',
                'message' => 'Artist is unavailable for the selected date.',
            ], 422);
        }
        $artistPricing = is_array($artist['pricing'] ?? null) ? $artist['pricing'] : [];
        $artistMinimum = max(0, (int) round((float) ($artistPricing['min'] ?? 0)));
        $budgetRaw = trim((string) ($payload['budget'] ?? ''));
        $submittedBudget = (float) ($budgetRaw !== '' ? $budgetRaw : '0');
        if ($artistMinimum > 0 && $submittedBudget <= 0) {
            $submittedBudget = (float) $artistMinimum;
            $payload['budget'] = (string) $artistMinimum;
        }
        if ($artistMinimum > 0 && $submittedBudget < $artistMinimum) {
            return response()->json([
                'code' => 'gigtune_budget_too_low',
                'message' => 'Booking budget must be at least the artist minimum price.',
                'minimum' => $artistMinimum,
            ], 422);
        }

        $id = (int) $this->db()->table($this->posts())->insertGetId([
            'post_author' => (int) ($user['id'] ?? 0),
            'post_date' => now()->format('Y-m-d H:i:s'),
            'post_date_gmt' => now('UTC')->format('Y-m-d H:i:s'),
            'post_content' => '',
            'post_title' => 'Booking Request ' . now()->format('YmdHis'),
            'post_status' => 'publish',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_name' => 'booking-' . now()->timestamp . '-' . mt_rand(100, 999),
            'post_modified' => now()->format('Y-m-d H:i:s'),
            'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
            'post_type' => 'gigtune_booking',
        ]);

        $this->upsertPostMeta($id, 'gigtune_booking_client_user_id', (string) (int) ($user['id'] ?? 0));
        $this->upsertPostMeta($id, 'gigtune_booking_artist_profile_id', (string) $artistProfileId);
        $this->upsertPostMeta($id, 'gigtune_booking_status', 'REQUESTED');
        $this->upsertPostMeta($id, 'gigtune_booking_event_date', $eventDate);
        $this->upsertPostMeta($id, 'gigtune_booking_end_time', (string) ($payload['end_time'] ?? ''));
        $this->upsertPostMeta($id, 'gigtune_booking_budget', (string) ($payload['budget'] ?? ''));
        $this->upsertPostMeta($id, 'gigtune_booking_notes', (string) ($payload['notes'] ?? ''));
        $sourcePsaId = (int) ($payload['psa_id'] ?? 0);
        if ($sourcePsaId > 0) {
            $this->upsertPostMeta($id, 'gigtune_booking_source_psa_id', (string) $sourcePsaId);
            $psaExists = $this->db()->table($this->posts())
                ->where('ID', $sourcePsaId)
                ->where('post_type', 'gigtune_psa')
                ->exists();
            if ($psaExists) {
                $this->upsertPostMeta($sourcePsaId, 'gigtune_psa_status', 'closed');
                $this->upsertPostMeta($sourcePsaId, 'gigtune_psa_closed_at', (string) now()->timestamp);
                $this->upsertPostMeta($sourcePsaId, 'gigtune_psa_closed_by_booking_id', (string) $id);
            }
        }

        return response()->json(['id' => $id, 'status' => 'REQUESTED'], 201);
    }

    public function booking(int $id): JsonResponse
    {
        $row = $this->db()->table($this->posts())
            ->where('ID', $id)
            ->where('post_type', 'gigtune_booking')
            ->first(['ID', 'post_title', 'post_date']);

        if ($row === null) {
            return response()->json(['code' => 'gigtune_not_found', 'message' => 'Booking not found.'], 404);
        }

        $meta = $this->postMetaMap([$id], [
            'gigtune_booking_status',
            'gigtune_booking_event_date',
            'gigtune_booking_end_time',
            'gigtune_booking_budget',
            'gigtune_booking_notes',
            'gigtune_booking_client_user_id',
            'gigtune_booking_artist_profile_id',
            'gigtune_payment_status',
            'gigtune_payout_status',
            'gigtune_dispute_raised',
        ])[$id] ?? [];

        return response()->json([
            'id' => (int) $row->ID,
            'title' => (string) $row->post_title,
            'date' => (string) $row->post_date,
            'meta' => $meta,
        ]);
    }

    public function bookingPaymentStatus(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'booking_id' => ['required', 'integer', 'min:1'],
            'payment_status' => ['required', 'string', 'max:80'],
        ]);

        $this->upsertPostMeta((int) $payload['booking_id'], 'gigtune_payment_status', (string) $payload['payment_status']);
        return response()->json(['ok' => true]);
    }

    public function bookingCosts(Request $request, int $id): JsonResponse
    {
        $payload = $request->validate([
            'gross' => ['nullable', 'numeric'],
            'fee' => ['nullable', 'numeric'],
            'net' => ['nullable', 'numeric'],
        ]);

        $this->upsertPostMeta($id, 'gigtune_amount_gross', (string) ($payload['gross'] ?? '0'));
        $this->upsertPostMeta($id, 'gigtune_amount_fee', (string) ($payload['fee'] ?? '0'));
        $this->upsertPostMeta($id, 'gigtune_amount_net', (string) ($payload['net'] ?? '0'));
        return response()->json(['ok' => true]);
    }

    public function bookingDistance(Request $request, int $id): JsonResponse
    {
        $distance = (string) $request->input('distance_km', '0');
        $this->upsertPostMeta($id, 'gigtune_booking_distance_km', $distance);
        return response()->json(['ok' => true]);
    }

    public function bookingAccommodation(Request $request, int $id): JsonResponse
    {
        $status = (string) $request->input('accommodation_status', '');
        $this->upsertPostMeta($id, 'gigtune_booking_accommodation_status', $status);
        return response()->json(['ok' => true]);
    }

    public function clientRatings(Request $request): JsonResponse
    {
        if (strtoupper($request->method()) === 'POST') {
            return $this->createClientRating($request);
        }

        $items = $this->loadClientRatings();
        return response()->json(['items' => $items, 'total' => count($items)]);
    }

    public function clientRatingSummary(int $id): JsonResponse
    {
        $items = array_values(array_filter($this->loadClientRatings(), static fn (array $row): bool => (int) ($row['client_user_id'] ?? 0) === $id));
        $count = count($items);
        $avg = 0.0;
        foreach ($items as $item) {
            $avg += (float) ($item['overall_avg'] ?? 0);
        }
        if ($count > 0) {
            $avg /= $count;
        }

        return response()->json(['client_user_id' => $id, 'ratings_count' => $count, 'overall_avg' => round($avg, 2)]);
    }

    public function clientRatingsByClient(int $id): JsonResponse
    {
        $items = array_values(array_filter($this->loadClientRatings(), static fn (array $row): bool => (int) ($row['client_user_id'] ?? 0) === $id));
        return response()->json(['items' => $items, 'total' => count($items)]);
    }

    public function clientProfile(int $id): JsonResponse
    {
        $row = $this->db()->table($this->posts() . ' as p')
            ->where('p.post_type', 'gt_client_profile')
            ->where('p.post_status', 'publish')
            ->whereExists(function ($query) use ($id): void {
                $query->selectRaw('1')
                    ->from($this->postMeta() . ' as pm')
                    ->whereColumn('pm.post_id', 'p.ID')
                    ->where('pm.meta_key', 'gigtune_client_user_id')
                    ->where('pm.meta_value', (string) $id);
            })
            ->orderByDesc('p.ID')
            ->first(['p.ID', 'p.post_title']);

        if ($row === null) {
            return response()->json(['code' => 'gigtune_not_found', 'message' => 'Client profile not found.'], 404);
        }

        $meta = $this->postMetaMap([(int) $row->ID], [
            'gigtune_client_base_area', 'gigtune_client_city', 'gigtune_client_province',
            'gigtune_client_company', 'gigtune_client_phone', 'gigtune_client_verified',
        ])[(int) $row->ID] ?? [];

        return response()->json(['id' => (int) $row->ID, 'title' => (string) $row->post_title, 'meta' => $meta]);
    }

    public function clientPsas(int $id): JsonResponse
    {
        $rows = $this->db()->table($this->posts() . ' as p')
            ->where('p.post_type', 'gigtune_psa')
            ->where('p.post_status', 'publish')
            ->whereExists(function ($query) use ($id): void {
                $query->selectRaw('1')
                    ->from($this->postMeta() . ' as pm')
                    ->whereColumn('pm.post_id', 'p.ID')
                    ->where('pm.meta_key', 'gigtune_psa_client_user_id')
                    ->where('pm.meta_value', (string) $id);
            })
            ->orderByDesc('p.ID')
            ->get(['p.ID', 'p.post_title']);

        return response()->json(['items' => $rows, 'total' => $rows->count()]);
    }

    public function clientMeProfile(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (!is_array($user)) {
            return response()->json(['code' => 'gigtune_forbidden', 'message' => 'Authentication required.'], 401);
        }

        return $this->clientProfile((int) ($user['id'] ?? 0));
    }

    public function psas(Request $request): JsonResponse
    {
        if (strtoupper($request->method()) === 'POST') {
            return $this->createPsa($request);
        }

        $rows = $this->db()->table($this->posts())
            ->where('post_type', 'gigtune_psa')
            ->where('post_status', 'publish')
            ->orderByDesc('ID')
            ->limit(100)
            ->get(['ID', 'post_title', 'post_date']);

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->ID;
        }
        $meta = $this->postMetaMap($ids, [
            'gigtune_psa_budget_min', 'gigtune_psa_budget_max', 'gigtune_psa_location_text',
            'gigtune_psa_status', 'gigtune_psa_client_user_id', 'gigtune_psa_start_date', 'gigtune_psa_end_date',
            'gigtune_psa_deleted_at', 'gigtune_psa_deleted_by_admin',
        ]);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row->ID;
            $itemMeta = $meta[$id] ?? [];
            $status = strtolower(trim((string) ($itemMeta['gigtune_psa_status'] ?? 'open')));
            $deletedAt = (int) ($itemMeta['gigtune_psa_deleted_at'] ?? 0);
            $deletedByAdmin = (string) ($itemMeta['gigtune_psa_deleted_by_admin'] ?? '') === '1';
            if ($deletedAt > 0 || $deletedByAdmin || in_array($status, ['deleted', 'trash', 'removed'], true)) {
                continue;
            }
            $items[] = [
                'id' => $id,
                'title' => (string) $row->post_title,
                'date' => (string) $row->post_date,
                'meta' => $itemMeta,
            ];
        }

        return response()->json(['items' => $items, 'total' => count($items)]);
    }

    public function psa(Request $request, int $id): JsonResponse
    {
        if (strtoupper($request->method()) === 'POST') {
            return $this->updatePsa($request, $id);
        }

        $row = $this->db()->table($this->posts())
            ->where('ID', $id)
            ->where('post_type', 'gigtune_psa')
            ->first(['ID', 'post_title', 'post_date']);

        if ($row === null) {
            return response()->json(['code' => 'gigtune_not_found', 'message' => 'PSA not found.'], 404);
        }

        $meta = $this->postMetaMap([$id], [
            'gigtune_psa_budget_min', 'gigtune_psa_budget_max', 'gigtune_psa_location_text',
            'gigtune_psa_status', 'gigtune_psa_client_user_id', 'gigtune_psa_start_date', 'gigtune_psa_end_date',
            'gigtune_psa_deleted_at', 'gigtune_psa_deleted_by_admin',
        ])[$id] ?? [];
        $status = strtolower(trim((string) ($meta['gigtune_psa_status'] ?? 'open')));
        $deletedAt = (int) ($meta['gigtune_psa_deleted_at'] ?? 0);
        $deletedByAdmin = (string) ($meta['gigtune_psa_deleted_by_admin'] ?? '') === '1';
        if ($deletedAt > 0 || $deletedByAdmin || in_array($status, ['deleted', 'trash', 'removed'], true)) {
            return response()->json(['code' => 'gigtune_not_found', 'message' => 'PSA not found.'], 404);
        }

        return response()->json(['id' => (int) $row->ID, 'title' => (string) $row->post_title, 'date' => (string) $row->post_date, 'meta' => $meta]);
    }

    public function psaClose(int $id): JsonResponse
    {
        $this->upsertPostMeta($id, 'gigtune_psa_status', 'closed');
        return response()->json(['ok' => true, 'id' => $id, 'status' => 'closed']);
    }

    private function createClientRating(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'booking_id' => ['required', 'integer', 'min:1'],
            'client_user_id' => ['required', 'integer', 'min:1'],
            'artist_user_id' => ['nullable', 'integer', 'min:1'],
            'overall_avg' => ['required', 'numeric'],
        ]);

        $id = (int) $this->db()->table($this->posts())->insertGetId([
            'post_author' => (int) ($payload['artist_user_id'] ?? 0),
            'post_date' => now()->format('Y-m-d H:i:s'),
            'post_date_gmt' => now('UTC')->format('Y-m-d H:i:s'),
            'post_content' => '',
            'post_title' => 'Client Rating ' . now()->format('YmdHis'),
            'post_status' => 'publish',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_name' => 'client-rating-' . now()->timestamp . '-' . mt_rand(100, 999),
            'post_modified' => now()->format('Y-m-d H:i:s'),
            'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
            'post_type' => 'gt_client_rating',
        ]);

        $this->upsertPostMeta($id, 'gigtune_client_rating_booking_id', (string) (int) $payload['booking_id']);
        $this->upsertPostMeta($id, 'gigtune_client_rating_client_user_id', (string) (int) $payload['client_user_id']);
        $this->upsertPostMeta($id, 'gigtune_client_rating_artist_user_id', (string) (int) ($payload['artist_user_id'] ?? 0));
        $this->upsertPostMeta($id, 'gigtune_client_rating_overall_avg', (string) (float) $payload['overall_avg']);
        $this->upsertPostMeta($id, 'gigtune_client_rating_created_at', (string) now()->timestamp);

        return response()->json(['id' => $id], 201);
    }

    /** @return array<int,array<string,mixed>> */
    private function loadClientRatings(): array
    {
        $rows = $this->db()->table($this->posts())
            ->where('post_type', 'gt_client_rating')
            ->where('post_status', 'publish')
            ->orderByDesc('ID')
            ->get(['ID']);

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->ID;
        }

        $meta = $this->postMetaMap($ids, [
            'gigtune_client_rating_booking_id',
            'gigtune_client_rating_client_user_id',
            'gigtune_client_rating_artist_user_id',
            'gigtune_client_rating_overall_avg',
            'gigtune_client_rating_created_at',
        ]);

        $items = [];
        foreach ($ids as $id) {
            $m = $meta[$id] ?? [];
            $items[] = [
                'id' => $id,
                'booking_id' => (int) ($m['gigtune_client_rating_booking_id'] ?? 0),
                'client_user_id' => (int) ($m['gigtune_client_rating_client_user_id'] ?? 0),
                'artist_user_id' => (int) ($m['gigtune_client_rating_artist_user_id'] ?? 0),
                'overall_avg' => (float) ($m['gigtune_client_rating_overall_avg'] ?? 0),
                'created_at' => (string) ($m['gigtune_client_rating_created_at'] ?? ''),
            ];
        }

        return $items;
    }

    private function createPsa(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (!is_array($user)) {
            return response()->json(['code' => 'gigtune_forbidden', 'message' => 'Authentication required.'], 401);
        }

        $payload = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'location_text' => ['nullable', 'string', 'max:250'],
            'budget_min' => ['nullable', 'numeric'],
            'budget_max' => ['nullable', 'numeric'],
            'start_date' => ['nullable', 'string', 'max:30'],
            'end_date' => ['nullable', 'string', 'max:30'],
        ]);

        $id = (int) $this->db()->table($this->posts())->insertGetId([
            'post_author' => (int) ($user['id'] ?? 0),
            'post_date' => now()->format('Y-m-d H:i:s'),
            'post_date_gmt' => now('UTC')->format('Y-m-d H:i:s'),
            'post_content' => '',
            'post_title' => (string) $payload['title'],
            'post_status' => 'publish',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_name' => 'psa-' . now()->timestamp . '-' . mt_rand(100, 999),
            'post_modified' => now()->format('Y-m-d H:i:s'),
            'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
            'post_type' => 'gigtune_psa',
        ]);

        $this->upsertPostMeta($id, 'gigtune_psa_client_user_id', (string) (int) ($user['id'] ?? 0));
        $this->upsertPostMeta($id, 'gigtune_psa_location_text', (string) ($payload['location_text'] ?? ''));
        $this->upsertPostMeta($id, 'gigtune_psa_budget_min', (string) ($payload['budget_min'] ?? 0));
        $this->upsertPostMeta($id, 'gigtune_psa_budget_max', (string) ($payload['budget_max'] ?? 0));
        $this->upsertPostMeta($id, 'gigtune_psa_start_date', (string) ($payload['start_date'] ?? ''));
        $this->upsertPostMeta($id, 'gigtune_psa_end_date', (string) ($payload['end_date'] ?? ''));
        $this->upsertPostMeta($id, 'gigtune_psa_status', 'open');

        return response()->json(['id' => $id], 201);
    }

    private function updatePsa(Request $request, int $id): JsonResponse
    {
        $payload = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'location_text' => ['nullable', 'string', 'max:250'],
            'budget_min' => ['nullable', 'numeric'],
            'budget_max' => ['nullable', 'numeric'],
        ]);

        if (isset($payload['status'])) {
            $this->upsertPostMeta($id, 'gigtune_psa_status', (string) $payload['status']);
        }
        if (isset($payload['location_text'])) {
            $this->upsertPostMeta($id, 'gigtune_psa_location_text', (string) $payload['location_text']);
        }
        if (isset($payload['budget_min'])) {
            $this->upsertPostMeta($id, 'gigtune_psa_budget_min', (string) $payload['budget_min']);
        }
        if (isset($payload['budget_max'])) {
            $this->upsertPostMeta($id, 'gigtune_psa_budget_max', (string) $payload['budget_max']);
        }

        return response()->json(['ok' => true, 'id' => $id]);
    }

    /** @return array<string,mixed>|null */
    private function authUser(Request $request): ?array
    {
        $u = $request->attributes->get('gigtune_user');
        if (is_array($u)) {
            return $u;
        }

        $sessionUserId = (int) $request->session()->get('gigtune_auth_user_id', 0);
        if ($sessionUserId <= 0) {
            return null;
        }

        $user = $this->users->getUserById($sessionUserId);
        return is_array($user) ? $user : null;
    }

    /** @param array<string,mixed> $user */
    private function isAdmin(array $user): bool
    {
        return (bool) ($user['is_admin'] ?? false);
    }

    /** @param array<int> $ids @param array<int,string> $keys @return array<int,array<string,string>> */
    private function postMetaMap(array $ids, array $keys): array
    {
        if ($ids === [] || $keys === []) {
            return [];
        }

        $rows = $this->db()->table($this->postMeta())
            ->whereIn('post_id', $ids)
            ->whereIn('meta_key', $keys)
            ->orderByDesc('meta_id')
            ->get(['post_id', 'meta_key', 'meta_value']);

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

    private function upsertPostMeta(int $postId, string $metaKey, string $metaValue): void
    {
        $row = $this->db()->table($this->postMeta())
            ->select('meta_id')
            ->where('post_id', $postId)
            ->where('meta_key', $metaKey)
            ->orderByDesc('meta_id')
            ->first();

        if ($row !== null && isset($row->meta_id)) {
            $this->db()->table($this->postMeta())
                ->where('meta_id', (int) $row->meta_id)
                ->update(['meta_value' => $metaValue]);
            return;
        }

        $this->db()->table($this->postMeta())->insert([
            'post_id' => $postId,
            'meta_key' => $metaKey,
            'meta_value' => $metaValue,
        ]);
    }

    private function db(): ConnectionInterface
    {
        return DB::connection((string) config('gigtune.wordpress.database_connection', 'wordpress'));
    }

    private function prefix(): string
    {
        return (string) config('gigtune.wordpress.table_prefix', 'wp_');
    }

    private function posts(): string
    {
        return $this->prefix() . 'posts';
    }

    private function postMeta(): string
    {
        return $this->prefix() . 'postmeta';
    }
}
