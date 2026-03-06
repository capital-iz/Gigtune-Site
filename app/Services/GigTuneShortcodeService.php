<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GigTuneShortcodeService
{
    public function __construct(
        private readonly WordPressNotificationService $notifications,
        private readonly WordPressUserService $users,
        private readonly GigTuneMailService $mail,
    ) {
    }

    /** @var array<string, string> */
    private array $handlers = [
        'gigtune_role_nav' => 'roleNav',
        'gigtune_artist_dashboard' => 'artistDashboard',
        'gigtune_client_dashboard' => 'clientDashboard',
        'gigtune_artist_feed' => 'artistFeed',
        'gigtune_open_client_posts' => 'artistFeed',
        'gigtune_artist_directory' => 'artistDirectory',
        'gigtune_featured_artists_simple' => 'featuredArtists',
        'gigtune_client_directory' => 'clientDirectory',
        'gigtune_artist_home_snapshot' => 'artistSnapshot',
        'gigtune_client_home_snapshot' => 'clientSnapshot',
        'gigtune_client_psa_applicants_panel' => 'clientApplicants',
        'gigtune_client_profile_panel' => 'clientProfile',
        'gigtune_client_profile_edit' => 'clientProfileEdit',
        'gigtune_public_client_profile' => 'publicClientProfile',
        'gigtune_public_artist_profile' => 'publicArtistProfile',
        'gigtune_artist_profile_edit' => 'artistProfileEdit',
        'gigtune_artist_availability' => 'artistAvailability',
        'gigtune_artist_availability_summary' => 'artistAvailabilitySummary',
        'gigtune_book_artist' => 'bookArtist',
        'gigtune_booking_messages' => 'messages',
        'gigtune_notifications' => 'notifications',
        'gigtune_notifications_archive' => 'notificationsArchive',
        'gigtune_notifications_bell' => 'notificationsBell',
        'gigtune_notification_settings' => 'notificationSettings',
        'gigtune_account_portal' => 'accountPortal',
        'gigtune_kyc_form' => 'kycForm',
        'gigtune_kyc_status' => 'kycStatus',
        'gigtune_security_centre' => 'securityCentre',
        'gigtune_policy_consent' => 'policyConsent',
        'gigtune_register' => 'register',
        'gigtune_admin_login' => 'adminLogin',
        'gigtune_admin_dashboard' => 'adminDashboard',
        'gigtune_admin_maintenance' => 'adminMaintenance',
        'gigtune_sign_out' => 'signOut',
        'gigtune_verify_email' => 'verifyEmail',
        'gigtune_forgot_password' => 'forgotPassword',
        'gigtune_reset_password' => 'resetPassword',
        'gigtune_yoco_success_return' => 'yocoSuccess',
        'gigtune_yoco_cancel_return' => 'yocoCancel',
        'gigtune_yoco_return' => 'yocoSuccess',
        'woocommerce_my_account' => 'accountPortal',
        'woocommerce_cart' => 'wooCart',
        'woocommerce_checkout' => 'wooCheckout',
    ];

    public function has(string $tag): bool
    {
        return isset($this->handlers[strtolower(trim($tag))]);
    }

    public function render(string $content, ?array $user = null, array $ctx = []): string
    {
        $content = str_replace(['<!-- wp:shortcode -->', '<!-- /wp:shortcode -->'], '', $content);

        return preg_replace_callback('/\[([a-zA-Z0-9_]+)([^\]]*)\]/', function (array $m) use ($user, $ctx): string {
            $tag = strtolower(trim((string) ($m[1] ?? '')));
            if ($tag === '' || !isset($this->handlers[$tag])) {
                return '';
            }
            $method = $this->handlers[$tag];
            $atts = $this->atts((string) ($m[2] ?? ''));
            return $this->{$method}($atts, $user, $ctx);
        }, $content) ?? $content;
    }

    public function getArtistById(int $id): ?array
    {
        $items = $this->getArtists(['per_page' => 500, 'paged' => 1, 'include_incomplete' => true])['items'];
        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                return $item;
            }
        }
        return null;
    }

    public function isArtistAvailableForEvent(int $artistProfileId, string $eventDate): bool
    {
        $artistProfileId = abs($artistProfileId);
        $eventDate = trim($eventDate);
        if ($artistProfileId <= 0 || $eventDate === '') {
            return false;
        }

        $daysRaw = (string) $this->getLatestPostMeta($artistProfileId, 'gigtune_artist_availability_days');
        $days = $this->normalizeAvailabilityDays($this->days($daysRaw));
        if ($days === []) {
            return false;
        }

        $timezoneName = (string) config('app.timezone', 'UTC');
        try {
            $timezone = new \DateTimeZone($timezoneName !== '' ? $timezoneName : 'UTC');
        } catch (\Throwable) {
            $timezone = new \DateTimeZone('UTC');
        }

        $dt = null;
        foreach (['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $candidate = \DateTimeImmutable::createFromFormat($format, $eventDate, $timezone);
            if ($candidate instanceof \DateTimeImmutable) {
                $dt = $candidate;
                break;
            }
        }
        if (!($dt instanceof \DateTimeImmutable)) {
            $fallbackTs = strtotime($eventDate);
            if ($fallbackTs !== false) {
                $dt = (new \DateTimeImmutable('@' . $fallbackTs))->setTimezone($timezone);
            }
        }
        if (!($dt instanceof \DateTimeImmutable)) {
            return false;
        }

        $eventTimestamp = $dt->getTimestamp();
        $slotsRaw = (string) $this->getLatestPostMeta($artistProfileId, 'gigtune_artist_availability_slots');
        $slotsValue = $this->maybe($slotsRaw);
        if (is_array($slotsValue) && $slotsValue !== []) {
            $hasValidSlotWindow = false;
            foreach ($slotsValue as $slot) {
                if (!is_array($slot)) {
                    continue;
                }
                $startTs = abs((int) ($slot['start'] ?? 0));
                $endTs = abs((int) ($slot['end'] ?? 0));
                if ($startTs <= 0 || $endTs <= 0) {
                    continue;
                }
                $hasValidSlotWindow = true;
                if ($eventTimestamp >= $startTs && $eventTimestamp <= $endTs) {
                    return true;
                }
            }
            if ($hasValidSlotWindow) {
                return false;
            }
        }

        $weekdayMap = [
            'Mon' => 'mon',
            'Tue' => 'tue',
            'Wed' => 'wed',
            'Thu' => 'thu',
            'Fri' => 'fri',
            'Sat' => 'sat',
            'Sun' => 'sun',
        ];
        $weekday = (string) ($weekdayMap[$dt->format('D')] ?? '');
        if ($weekday === '' || !in_array($weekday, $days, true)) {
            return false;
        }

        if (strpos($eventDate, 'T') === false && preg_match('/\d{2}:\d{2}/', $eventDate) !== 1) {
            return true;
        }

        $startTime = trim((string) $this->getLatestPostMeta($artistProfileId, 'gigtune_artist_availability_start_time'));
        $endTime = trim((string) $this->getLatestPostMeta($artistProfileId, 'gigtune_artist_availability_end_time'));
        if (preg_match('/^\d{2}:\d{2}$/', $startTime) !== 1 || preg_match('/^\d{2}:\d{2}$/', $endTime) !== 1) {
            return true;
        }

        $eventMinutes = ((int) $dt->format('H') * 60) + (int) $dt->format('i');
        [$startH, $startM] = array_map('intval', explode(':', $startTime));
        [$endH, $endM] = array_map('intval', explode(':', $endTime));
        $startMinutes = ($startH * 60) + $startM;
        $endMinutes = ($endH * 60) + $endM;

        if ($endMinutes >= $startMinutes) {
            return $eventMinutes >= $startMinutes && $eventMinutes <= $endMinutes;
        }

        return $eventMinutes >= $startMinutes || $eventMinutes <= $endMinutes;
    }

    /** @return array<string,mixed> */
    public function getArtists(array $args = []): array
    {
        $includeIncomplete = (bool) ($args['include_incomplete'] ?? false);
        $rows = $this->db()->table($this->posts())
            ->where('post_type', 'artist_profile')
            ->where('post_status', 'publish')
            ->orderByDesc('ID')
            ->get(['ID', 'post_title', 'post_name', 'post_content']);

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->ID;
        }

        $meta = $this->postMetaMap($ids, [
            'gigtune_artist_price_min', 'gigtune_artist_price_max', 'gigtune_artist_base_area',
            'gigtune_artist_available_now', 'gigtune_demo_videos', 'gigtune_performance_rating_avg',
            'gigtune_reliability_rating_avg', 'gigtune_artist_photo_id', 'gigtune_artist_banner_id',
            'gigtune_artist_availability_days', 'gigtune_artist_availability_start_time', 'gigtune_artist_availability_end_time',
            'gigtune_artist_visibility_mode',
            'gigtune_artist_travel_radius_km', 'gigtune_artist_travel_rate_per_km', 'gigtune_artist_travel_free_km',
            'gigtune_artist_travel_min_fee', 'gigtune_artist_travel_roundtrip',
            'gigtune_artist_bank_account_name', 'gigtune_artist_bank_account_number', 'gigtune_artist_bank_name',
            'gigtune_artist_branch_code', 'gigtune_artist_bank_code', 'gigtune_artist_user_id', 'gigtune_user_id',
            'gigtune_artist_accom_preference', 'gigtune_artist_accom_fee_flat',
            'gigtune_artist_accom_required_if_end_after', 'gigtune_artist_accom_required_km',
            'gigtune_artist_accom_recommended_if_end_after', 'gigtune_artist_accom_recommended_km',
            'gigtune_artist_availability_slots',
        ]);
        $terms = $this->termMap($ids);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row->ID;
            $m = $meta[$id] ?? [];
            $photoId = (int) ($m['gigtune_artist_photo_id'] ?? 0);
            $bannerId = (int) ($m['gigtune_artist_banner_id'] ?? 0);
            $items[] = [
                'id' => $id,
                'title' => (string) $row->post_title,
                'slug' => (string) $row->post_name,
                'content' => (string) $row->post_content,
                'pricing' => ['min' => (float) ($m['gigtune_artist_price_min'] ?? 0), 'max' => (float) ($m['gigtune_artist_price_max'] ?? 0)],
                'availability' => [
                    'base_area' => (string) ($m['gigtune_artist_base_area'] ?? ''),
                    'available_now' => $this->bool($m['gigtune_artist_available_now'] ?? ''),
                    'days' => $this->days($m['gigtune_artist_availability_days'] ?? ''),
                    'start_time' => (string) ($m['gigtune_artist_availability_start_time'] ?? ''),
                    'end_time' => (string) ($m['gigtune_artist_availability_end_time'] ?? ''),
                ],
                'ratings' => [
                    'performance_avg' => (float) ($m['gigtune_performance_rating_avg'] ?? 0),
                    'reliability_avg' => (float) ($m['gigtune_reliability_rating_avg'] ?? 0),
                ],
                'terms' => $terms[$id] ?? [],
                'demo_videos' => $this->demoVideos($m['gigtune_demo_videos'] ?? ''),
                'photo' => ['url' => $this->attachmentUrl($photoId)],
                'banner' => ['url' => $this->attachmentUrl($bannerId)],
                'meta' => $m,
            ];
        }

        if (!$includeIncomplete && $items !== []) {
            $userIds = [];
            foreach ($items as $item) {
                $metaItem = is_array($item['meta'] ?? null) ? $item['meta'] : [];
                $userId = (int) ($metaItem['gigtune_user_id'] ?? $metaItem['gigtune_artist_user_id'] ?? 0);
                if ($userId > 0) {
                    $userIds[$userId] = $userId;
                }
            }

            $requiredPolicies = $this->users->requiredPolicyVersions();
            $userRows = [];
            if ($userIds !== []) {
                $rowsUser = $this->db()->table($this->usersTable())
                    ->whereIn('ID', array_values($userIds))
                    ->get(['ID', 'display_name']);
                foreach ($rowsUser as $rowUser) {
                    $uid = (int) ($rowUser->ID ?? 0);
                    if ($uid > 0) {
                        $userRows[$uid] = ['display_name' => (string) ($rowUser->display_name ?? '')];
                    }
                }
            }
            $userMeta = $this->userMetaLatestMap(array_values($userIds), [
                'gigtune_email_verification_required',
                'gigtune_email_verified',
                'first_name',
                'last_name',
                'gigtune_policy_acceptance',
                'gigtune_kyc_status',
                'gigtune_kyc_required_for',
                'gigtune_profile_visibility_override',
            ]);

            $items = array_values(array_filter($items, function (array $item) use ($userRows, $userMeta, $requiredPolicies): bool {
                return $this->artistMeetsBookableRequirements($item, $userRows, $userMeta, $requiredPolicies);
            }));
        }

        $slug = trim((string) ($args['artist_slug'] ?? ''));
        if ($slug !== '') {
            $items = array_values(array_filter($items, fn ($i) => (string) ($i['slug'] ?? '') === $slug));
        }

        $search = mb_strtolower(trim((string) ($args['search'] ?? '')));
        if ($search !== '') {
            $items = array_values(array_filter($items, function ($i) use ($search): bool {
                $hay = mb_strtolower(((string) ($i['title'] ?? '')) . ' ' . ((string) ($i['content'] ?? '')) . ' ' . ((string) ($i['availability']['base_area'] ?? '')));
                if (str_contains($hay, $search)) {
                    return true;
                }
                foreach (($i['terms'] ?? []) as $tx) {
                    foreach (($tx ?? []) as $t) {
                        if (str_contains(mb_strtolower((string) ($t['name'] ?? '')), $search)) {
                            return true;
                        }
                    }
                }
                return false;
            }));
        }

        $taxQuery = $args['tax_query'] ?? null;
        if (is_array($taxQuery) && $taxQuery !== []) {
            $items = array_values(array_filter($items, function (array $item) use ($taxQuery): bool {
                foreach ($taxQuery as $condition) {
                    if (!is_array($condition)) {
                        continue;
                    }
                    $taxonomy = trim((string) ($condition['taxonomy'] ?? ''));
                    $terms = $condition['terms'] ?? [];
                    if ($taxonomy === '' || !is_array($terms) || $terms === []) {
                        continue;
                    }
                    $existing = [];
                    foreach (($item['terms'][$taxonomy] ?? []) as $term) {
                        $existing[] = (string) ($term['slug'] ?? '');
                    }
                    $ok = false;
                    foreach ($terms as $needle) {
                        if (in_array((string) $needle, $existing, true)) {
                            $ok = true;
                            break;
                        }
                    }
                    if (!$ok) {
                        return false;
                    }
                }
                return true;
            }));
        }

        $metaQuery = $args['meta_query'] ?? null;
        if (is_array($metaQuery) && $metaQuery !== []) {
            $items = array_values(array_filter($items, function (array $item) use ($metaQuery): bool {
                $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
                return $this->metaQueryMatch($meta, $metaQuery);
            }));
        }

        $per = max(1, min(200, (int) ($args['per_page'] ?? 20)));
        $page = max(1, (int) ($args['paged'] ?? 1));
        $total = count($items);

        return [
            'items' => array_values(array_slice($items, ($page - 1) * $per, $per)),
            'total' => $total,
            'per_page' => $per,
            'paged' => $page,
        ];
    }

    /** @return array<string, array<int,array{slug:string,name:string}>> */
    public function getFilterOptions(): array
    {
        $tx = ['performer_type', 'instrument_category', 'keyboard_parts', 'vocal_type', 'vocal_role'];
        $rows = $this->db()->table($this->tt() . ' as tt')
            ->join($this->terms() . ' as t', 't.term_id', '=', 'tt.term_id')
            ->whereIn('tt.taxonomy', $tx)
            ->orderBy('t.name')
            ->get(['tt.taxonomy', 't.slug', 't.name']);
        $out = array_fill_keys($tx, []);
        foreach ($rows as $row) {
            $taxonomy = (string) $row->taxonomy;
            $slug = (string) $row->slug;
            if ($taxonomy !== '' && $slug !== '') {
                $out[$taxonomy][$slug] = ['slug' => $slug, 'name' => (string) $row->name];
            }
        }

        $fallbacks = $this->taxonomyDefaultOptions();
        foreach ($fallbacks as $taxonomy => $terms) {
            if (!isset($out[$taxonomy]) || !is_array($out[$taxonomy])) {
                $out[$taxonomy] = [];
            }
            foreach ($terms as $term) {
                $slug = trim((string) ($term['slug'] ?? ''));
                if ($slug === '' || isset($out[$taxonomy][$slug])) {
                    continue;
                }
                $out[$taxonomy][$slug] = [
                    'slug' => $slug,
                    'name' => trim((string) ($term['name'] ?? $slug)),
                ];
            }
        }

        foreach ($out as $k => $v) {
            $out[$k] = array_values($v);
        }
        return $out;
    }

    /** @return array<string,array<int,array{slug:string,name:string}>> */
    private function taxonomyDefaultOptions(): array
    {
        return [
            'performer_type' => [
                ['slug' => 'instrumentalist', 'name' => 'Instrumentalist'],
                ['slug' => 'vocalist', 'name' => 'Vocalist'],
            ],
            'keyboard_parts' => [
                ['slug' => 'durban-style', 'name' => 'Durban Style'],
            ],
        ];
    }

    /** @param array<string,bool> $preferences */
    public function saveNotificationEmailPreferences(int $userId, array $preferences): void
    {
        $this->storeNotificationEmailPreferences($userId, $preferences);
    }

    private function roleNav(array $a, ?array $u): string { return is_array($u) ? '<div class="mb-4 flex flex-wrap gap-2"><a class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-200" href="/my-account-page/">Account</a><a class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-200" href="/messages/">Messages</a><a class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-200" href="/notifications/">Notifications</a></div>' : ''; }
    private function artistDashboard(array $a, ?array $u, array $ctx = []): string { return $this->dashboardShell($u, true, $ctx); }
    private function clientDashboard(array $a, ?array $u, array $ctx = []): string { return $this->dashboardShell($u, false, $ctx); }
    private function artistFeed(array $a, ?array $u = null, array $ctx = []): string { return $this->renderPsaFeed($a, $u, $ctx); }
    private function artistDirectory(array $a): string { return $this->artistCards(max(1, min(60, (int) ($a['limit'] ?? 18)))); }
    private function featuredArtists(array $a): string { return $this->artistCards(max(1, min(12, (int) ($a['limit'] ?? 6)))); }
    private function clientDirectory(array $a): string
    {
        $limit = max(1, min(50, (int) ($a['limit'] ?? 12)));
        $rows = $this->db()->table($this->posts())
            ->where('post_type', 'gt_client_profile')
            ->where('post_status', 'publish')
            ->orderByDesc('ID')
            ->limit($limit)
            ->get(['ID', 'post_title']);

        if ($rows->isEmpty()) {
            return '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">No clients available.</div>';
        }

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->ID;
        }
        $meta = $this->postMetaMap($ids, [
            'gigtune_client_company',
            'gigtune_client_base_area',
            'gigtune_client_photo_id',
        ]);

        $html = '<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">';
        foreach ($rows as $row) {
            $id = (int) $row->ID;
            $m = $meta[$id] ?? [];
            $photo = $this->attachmentUrl((int) ($m['gigtune_client_photo_id'] ?? 0));
            $html .= '<article class="rounded-xl border border-white/10 bg-white/5 p-5">';
            if ($photo !== '') {
                $html .= '<img src="' . e($photo) . '" alt="' . e((string) $row->post_title) . '" class="h-20 w-20 rounded-xl object-cover">';
            }
            $html .= '<h3 class="mt-3 text-lg font-semibold text-white">' . e((string) $row->post_title) . '</h3>';
            $html .= '<p class="mt-1 text-sm text-slate-300">' . e((string) ($m['gigtune_client_company'] ?? '')) . '</p>';
            $html .= '<p class="text-xs text-slate-400">' . e((string) ($m['gigtune_client_base_area'] ?? 'South Africa')) . '</p>';
            $html .= '<a class="mt-3 inline-flex rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white" href="/client-profile/?client_profile_id=' . $id . '">View profile</a>';
            $html .= '</article>';
        }
        return $html . '</div>';
    }
    private function artistSnapshot(array $a, ?array $u): string { return $this->snapshot($u, true); }
    private function clientSnapshot(array $a, ?array $u): string { return $this->snapshot($u, false); }
    private function clientApplicants(array $a, ?array $u): string
    {
        if (!is_array($u)) {
            return '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">Sign in required.</div>';
        }
        $uid = (int) ($u['id'] ?? 0);
        $rows = $this->db()->table($this->posts() . ' as p')
            ->where('p.post_type', 'gigtune_psa')
            ->where('p.post_status', 'publish')
            ->whereExists(function ($q) use ($uid): void {
                $q->selectRaw('1')
                    ->from($this->pm() . ' as pm')
                    ->whereColumn('pm.post_id', 'p.ID')
                    ->whereIn('pm.meta_key', ['gigtune_psa_client_user_id', 'gigtune_client_user_id'])
                    ->where('pm.meta_value', (string) $uid);
            })
            ->orderByDesc('p.ID')
            ->limit(max(1, min(12, (int) ($a['limit'] ?? 6))))
            ->get(['p.ID', 'p.post_title']);

        if ($rows->isEmpty()) {
            return '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">No open posts found.</div>';
        }

        $html = '<div class="grid gap-3 md:grid-cols-2">';
        foreach ($rows as $row) {
            $html .= '<article class="rounded-xl border border-white/10 bg-white/5 p-4"><div class="text-xs text-slate-400">Open post #' . (int) $row->ID . '</div><h4 class="mt-1 text-sm font-semibold text-white">' . e((string) $row->post_title) . '</h4><a class="mt-3 inline-flex rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white" href="/book-an-artist/?psa_id=' . (int) $row->ID . '">Open post</a></article>';
        }
        return $html . '</div>';
    }

    private function clientProfile(array $a, ?array $u): string
    {
        if (!is_array($u)) {
            return '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">Sign in required.</div>';
        }
        $uid = (int) ($u['id'] ?? 0);
        $profileId = $this->latestUserMetaInt($uid, 'gigtune_client_profile_id');
        if ($profileId <= 0) {
            return '<div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">No linked client profile found.</div>';
        }
        $meta = $this->postMetaMap([$profileId], ['gigtune_client_company', 'gigtune_client_phone', 'gigtune_client_base_area'])[$profileId] ?? [];
        return '<div class="rounded-xl border border-white/10 bg-white/5 p-4"><h3 class="text-sm font-semibold text-white">Client profile</h3><p class="mt-2 text-sm text-slate-300">Company: ' . e((string) ($meta['gigtune_client_company'] ?? '')) . '</p><p class="text-sm text-slate-300">Phone: ' . e((string) ($meta['gigtune_client_phone'] ?? '')) . '</p><p class="text-sm text-slate-300">Base area: ' . e((string) ($meta['gigtune_client_base_area'] ?? '')) . '</p><a class="mt-3 inline-flex rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white" href="/client-profile-edit/">Edit profile</a></div>';
    }

    private function clientProfileEdit(array $a, ?array $u, array $ctx = []): string
    {
        if (!is_array($u)) {
            return '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">Sign in required.</div>';
        }

        $uid = (int) ($u['id'] ?? 0);
        if ($uid <= 0) {
            return '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">Sign in required.</div>';
        }

        $roles = is_array($u['roles'] ?? null) ? $u['roles'] : [];
        if (!in_array('gigtune_client', $roles, true) && !((bool) ($u['is_admin'] ?? false))) {
            return '<div class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">Client accounts only.</div>';
        }

        $request = $this->req($ctx);
        $profileId = $this->latestUserMetaInt($uid, 'gigtune_client_profile_id');
        if ($profileId <= 0) {
            $profileId = (int) $this->db()->table($this->posts())->insertGetId([
                'post_author' => $uid,
                'post_date' => now()->format('Y-m-d H:i:s'),
                'post_date_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                'post_content' => '',
                'post_title' => (string) ($u['display_name'] ?? $u['login'] ?? ('Client ' . $uid)),
                'post_status' => 'publish',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
                'post_name' => 'client-profile-' . $uid,
                'post_modified' => now()->format('Y-m-d H:i:s'),
                'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                'post_type' => 'gt_client_profile',
            ]);
            if ($profileId > 0) {
                $this->upsertUserMeta($uid, 'gigtune_client_profile_id', (string) $profileId);
                $this->upsertPostMeta($profileId, 'gigtune_client_user_id', (string) $uid);
            }
        }

        if ($profileId <= 0) {
            return '<div class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">Client profile not found.</div>';
        }

        $post = $this->db()->table($this->posts())
            ->where('ID', $profileId)
            ->where('post_type', 'gt_client_profile')
            ->first(['ID', 'post_title', 'post_content']);
        if ($post === null) {
            return '<div class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">Client profile unavailable.</div>';
        }

        $statusMessage = '';
        $errorMessage = '';
        $skipClientProfileSave = false;
        if ($request !== null && strtoupper((string) $request->method()) === 'POST') {
            $removeMedia = trim((string) $request->input('gigtune_client_media_remove', ''));
            if (in_array($removeMedia, ['photo', 'banner'], true)) {
                $skipClientProfileSave = true;
                if ($removeMedia === 'photo') {
                    $existingPhotoId = (int) $this->getLatestPostMeta($profileId, 'gigtune_client_photo_id');
                    if ($existingPhotoId > 0) {
                        $this->deleteAttachment($existingPhotoId);
                    }
                    $this->upsertPostMeta($profileId, 'gigtune_client_photo_id', '0');
                    $statusMessage = 'Profile photo removed.';
                } else {
                    $existingBannerId = (int) $this->getLatestPostMeta($profileId, 'gigtune_client_banner_id');
                    if ($existingBannerId > 0) {
                        $this->deleteAttachment($existingBannerId);
                    }
                    $this->upsertPostMeta($profileId, 'gigtune_client_banner_id', '0');
                    $statusMessage = 'Profile banner removed.';
                }
            } elseif (in_array(trim((string) $request->input('gigtune_client_media_apply', '')), ['photo', 'banner'], true)) {
                $skipClientProfileSave = true;
                $applyMedia = trim((string) $request->input('gigtune_client_media_apply', ''));
                if ($applyMedia === 'photo') {
                    $photoFile = $request->file('gt_profile_photo_file');
                    if (!($photoFile instanceof UploadedFile)) {
                        $photoFile = $request->file('gigtune_client_photo');
                    }
                    if (!($photoFile instanceof UploadedFile)) {
                        $photoFile = $request->file('gigtune_profile_photo');
                    }
                    if (!($photoFile instanceof UploadedFile)) {
                        $photoFile = $request->file('profile_photo');
                    }
                    $photoId = $this->saveUploadedAttachment($photoFile, $uid, $profileId, 'Client Profile Photo');
                    if ($photoId > 0) {
                        $oldPhotoId = (int) $this->getLatestPostMeta($profileId, 'gigtune_client_photo_id');
                        if ($oldPhotoId > 0 && $oldPhotoId !== $photoId) {
                            $this->deleteAttachment($oldPhotoId);
                        }
                        $this->upsertPostMeta($profileId, 'gigtune_client_photo_id', (string) $photoId);
                        $statusMessage = 'Profile photo updated.';
                    } else {
                        $errorMessage = 'Please select a valid profile photo first.';
                    }
                } else {
                    $bannerFile = $request->file('gt_profile_banner_file');
                    if (!($bannerFile instanceof UploadedFile)) {
                        $bannerFile = $request->file('gigtune_client_banner');
                    }
                    if (!($bannerFile instanceof UploadedFile)) {
                        $bannerFile = $request->file('gigtune_profile_banner');
                    }
                    if (!($bannerFile instanceof UploadedFile)) {
                        $bannerFile = $request->file('profile_banner');
                    }
                    $bannerId = $this->saveUploadedAttachment($bannerFile, $uid, $profileId, 'Client Profile Banner');
                    if ($bannerId > 0) {
                        $oldBannerId = (int) $this->getLatestPostMeta($profileId, 'gigtune_client_banner_id');
                        if ($oldBannerId > 0 && $oldBannerId !== $bannerId) {
                            $this->deleteAttachment($oldBannerId);
                        }
                        $this->upsertPostMeta($profileId, 'gigtune_client_banner_id', (string) $bannerId);
                        $statusMessage = 'Profile banner updated.';
                    } else {
                        $errorMessage = 'Please select a valid profile banner first.';
                    }
                }
            } elseif ((string) $request->input('gigtune_client_account_submit', '') === '1') {
                $skipClientProfileSave = true;
                $newEmail = strtolower(trim((string) $request->input('gigtune_account_email', '')));
                $newPassword = (string) $request->input('gigtune_account_password', '');
                $confirmPassword = (string) $request->input('gigtune_account_password_confirm', '');
                try {
                    if ($newEmail !== '' && $newEmail !== strtolower(trim((string) ($u['email'] ?? '')))) {
                        $this->users->updateUserEmail($uid, $newEmail);
                        $token = bin2hex(random_bytes(16));
                        $this->upsertUserMeta($uid, 'gigtune_email_verified', '0');
                        $this->upsertUserMeta($uid, 'gigtune_email_verification_required', '1');
                        $this->upsertUserMeta($uid, 'gigtune_email_verification_token_hash', hash('sha256', $token));
                        $this->upsertUserMeta($uid, 'gigtune_email_verification_expires_at', (string) (time() + 172800));
                        $this->upsertUserMeta($uid, 'gigtune_email_verification_sent_at', now()->format('Y-m-d H:i:s'));
                        $mailSent = $this->mail->sendVerificationEmail($uid, $token);
                        $this->upsertUserMeta($uid, 'gigtune_email_verification_delivery', $mailSent ? 'sent' : 'failed');
                        $statusMessage = $mailSent
                            ? 'Account email updated. Verification email sent to the new address.'
                            : 'Account email updated. Verification email could not be sent right now.';
                    }
                    if ($newPassword !== '' || $confirmPassword !== '') {
                        if ($newPassword === '' || $newPassword !== $confirmPassword) {
                            throw new \InvalidArgumentException('Password confirmation does not match.');
                        }
                        $this->users->updateUserPassword($uid, $newPassword);
                        $statusMessage = $statusMessage === '' ? 'Password updated successfully.' : ($statusMessage . ' Password updated successfully.');
                    }
                    if ($statusMessage === '') {
                        $errorMessage = 'No account changes detected.';
                    }
                } catch (\Throwable $throwable) {
                    $errorMessage = trim($throwable->getMessage()) !== '' ? trim($throwable->getMessage()) : 'Unable to update account details.';
                }
            }
        }
        if (
            $request !== null
            && strtoupper((string) $request->method()) === 'POST'
            && !$skipClientProfileSave
            && (string) $request->input('gigtune_client_profile_submit', '') === '1'
        ) {
            $title = trim((string) $request->input('gigtune_client_title', ''));
            $bio = trim((string) $request->input('gigtune_client_bio', ''));
            $baseArea = trim((string) $request->input('gigtune_client_base_area', ''));
            $province = trim((string) $request->input('gigtune_client_province', ''));
            $city = trim((string) $request->input('gigtune_client_city', ''));
            $streetAddress = trim((string) $request->input('gigtune_client_address_street', ''));
            $suburb = trim((string) $request->input('gigtune_client_address_suburb', ''));
            $postalCode = trim((string) $request->input('gigtune_client_address_postal_code', ''));
            $country = trim((string) $request->input('gigtune_client_address_country', 'South Africa'));
            if ($country === '') {
                $country = 'South Africa';
            }
            $company = trim((string) $request->input('gigtune_client_company', ''));
            $phone = trim((string) $request->input('gigtune_client_phone', ''));
            $bankAccountName = trim((string) $request->input('gigtune_client_bank_account_name', ''));
            $bankAccountNumber = preg_replace('/\s+/', '', (string) $request->input('gigtune_client_bank_account_number', '')) ?? '';
            $bankName = trim((string) $request->input('gigtune_client_bank_name', ''));
            $branchCode = trim((string) $request->input('gigtune_client_branch_code', ''));
            if ($branchCode === '') {
                $branchCode = trim((string) $request->input('gigtune_client_bank_code', ''));
            }

            $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
            if (
                $title === '' ||
                $bio === '' ||
                $baseArea === '' ||
                $company === '' ||
                strlen($phoneDigits) < 9 ||
                $streetAddress === '' ||
                $city === '' ||
                $province === '' ||
                $postalCode === ''
            ) {
                $errorMessage = 'Please complete all required profile fields.';
            } else {
                $this->db()->table($this->posts())
                    ->where('ID', $profileId)
                    ->update([
                        'post_title' => $title,
                        'post_content' => $bio,
                        'post_modified' => now()->format('Y-m-d H:i:s'),
                        'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                    ]);

                $this->upsertPostMeta($profileId, 'gigtune_client_user_id', (string) $uid);
                $this->upsertPostMeta($profileId, 'gigtune_client_base_area', $baseArea);
                $this->upsertPostMeta($profileId, 'gigtune_client_province', $province);
                $this->upsertPostMeta($profileId, 'gigtune_client_city', $city);
                $this->upsertPostMeta($profileId, 'gigtune_client_address_street', $streetAddress);
                $this->upsertPostMeta($profileId, 'gigtune_client_address_suburb', $suburb);
                $this->upsertPostMeta($profileId, 'gigtune_client_address_city', $city);
                $this->upsertPostMeta($profileId, 'gigtune_client_address_postal_code', $postalCode);
                $this->upsertPostMeta($profileId, 'gigtune_client_address_country', $country);
                $this->upsertPostMeta($profileId, 'gigtune_client_company', $company);
                $this->upsertPostMeta($profileId, 'gigtune_client_phone', $phone);
                $this->upsertPostMeta($profileId, 'gigtune_client_bank_account_name', $bankAccountName);
                $this->upsertPostMeta($profileId, 'gigtune_client_bank_account_number', $bankAccountNumber);
                $this->upsertPostMeta($profileId, 'gigtune_client_bank_name', $bankName);
                $this->upsertPostMeta($profileId, 'gigtune_client_branch_code', $branchCode);
                $this->upsertPostMeta($profileId, 'gigtune_client_bank_code', $branchCode);

                $photoFile = $request->file('gt_profile_photo_file');
                if (!($photoFile instanceof UploadedFile)) {
                    $photoFile = $request->file('gigtune_client_photo');
                }
                if (!($photoFile instanceof UploadedFile)) {
                    $photoFile = $request->file('gigtune_profile_photo');
                }
                if (!($photoFile instanceof UploadedFile)) {
                    $photoFile = $request->file('profile_photo');
                }
                $photoId = $this->saveUploadedAttachment($photoFile, $uid, $profileId, 'Client Profile Photo');
                if ($photoId > 0) {
                    $oldPhotoId = (int) $this->getLatestPostMeta($profileId, 'gigtune_client_photo_id');
                    if ($oldPhotoId > 0 && $oldPhotoId !== $photoId) {
                        $this->deleteAttachment($oldPhotoId);
                    }
                    $this->upsertPostMeta($profileId, 'gigtune_client_photo_id', (string) $photoId);
                }

                $bannerFile = $request->file('gt_profile_banner_file');
                if (!($bannerFile instanceof UploadedFile)) {
                    $bannerFile = $request->file('gigtune_client_banner');
                }
                if (!($bannerFile instanceof UploadedFile)) {
                    $bannerFile = $request->file('gigtune_profile_banner');
                }
                if (!($bannerFile instanceof UploadedFile)) {
                    $bannerFile = $request->file('profile_banner');
                }
                $bannerId = $this->saveUploadedAttachment($bannerFile, $uid, $profileId, 'Client Profile Banner');
                if ($bannerId > 0) {
                    $oldBannerId = (int) $this->getLatestPostMeta($profileId, 'gigtune_client_banner_id');
                    if ($oldBannerId > 0 && $oldBannerId !== $bannerId) {
                        $this->deleteAttachment($oldBannerId);
                    }
                    $this->upsertPostMeta($profileId, 'gigtune_client_banner_id', (string) $bannerId);
                }

                $statusMessage = 'Profile saved.';
            }

            $post = $this->db()->table($this->posts())
                ->where('ID', $profileId)
                ->where('post_type', 'gt_client_profile')
                ->first(['ID', 'post_title', 'post_content']);
            if ($post === null) {
                return '<div class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">Client profile unavailable.</div>';
            }
        }

        $meta = $this->postMetaMap([$profileId], [
            'gigtune_client_base_area',
            'gigtune_client_province',
            'gigtune_client_city',
            'gigtune_client_address_street',
            'gigtune_client_address_suburb',
            'gigtune_client_address_city',
            'gigtune_client_address_postal_code',
            'gigtune_client_address_country',
            'gigtune_client_company',
            'gigtune_client_phone',
            'gigtune_client_bank_account_name',
            'gigtune_client_bank_account_number',
            'gigtune_client_bank_name',
            'gigtune_client_branch_code',
            'gigtune_client_bank_code',
            'gigtune_client_photo_id',
            'gigtune_client_banner_id',
        ])[$profileId] ?? [];
        $branchCode = (string) ($meta['gigtune_client_branch_code'] ?? '');
        if ($branchCode === '') {
            $branchCode = (string) ($meta['gigtune_client_bank_code'] ?? '');
        }
        $photoUrl = $this->attachmentUrl((int) ($meta['gigtune_client_photo_id'] ?? 0));
        $bannerUrl = $this->attachmentUrl((int) ($meta['gigtune_client_banner_id'] ?? 0));
        $clientPhotoHidden = $photoUrl === '' ? ' hidden' : '';
        $clientPhotoPlaceholderHidden = $photoUrl !== '' ? ' hidden' : '';
        $clientBannerHidden = $bannerUrl === '' ? ' hidden' : '';
        $clientBannerPlaceholderHidden = $bannerUrl !== '' ? ' hidden' : '';

        $html = '<form method="post" enctype="multipart/form-data" class="space-y-6 rounded-2xl border border-white/10 bg-white/5 p-6">';
        if ($statusMessage !== '') {
            $html .= '<div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-200">' . e($statusMessage) . '</div>';
        }
        if ($errorMessage !== '') {
            $html .= '<div class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-sm text-rose-200">' . e($errorMessage) . '</div>';
        }

        $html .= '<input type="hidden" name="gigtune_client_profile_submit" value="1">';
        $html .= '<div class="grid gap-4 md:grid-cols-2">';
        $html .= '<div class="rounded-xl border border-white/10 bg-black/20 p-4">';
        $html .= '<p class="mb-3 text-sm font-semibold text-white">Profile photo</p>';
        $html .= '<img id="gt-client-photo-preview" src="' . e($photoUrl) . '" alt="Client profile photo" class="h-24 w-24 rounded-full object-cover border border-white/10' . $clientPhotoHidden . '">';
        $html .= '<div id="gt-client-photo-placeholder" class="h-24 w-24 rounded-full border border-white/10 bg-slate-900/70 text-xs text-slate-400 flex items-center justify-center' . $clientPhotoPlaceholderHidden . '">No photo</div>';
        $html .= '<div class="mt-3 space-y-2">';
        $html .= '<input id="gt-client-photo-input" type="file" name="gt_profile_photo_file" accept="image/*" data-gt-preview-id="gt-client-photo-preview" data-gt-placeholder-id="gt-client-photo-placeholder" data-crop-ratio="1" class="block w-full text-sm text-slate-200">';
        $html .= '<div class="flex flex-wrap gap-2">';
        $html .= '<button type="submit" name="gigtune_client_media_apply" value="photo" class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-500">Apply photo</button>';
        $html .= '<button type="submit" name="gigtune_client_media_remove" value="photo" class="inline-flex items-center justify-center rounded-lg bg-white/10 px-3 py-2 text-xs font-semibold text-white hover:bg-white/15">Remove photo</button>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="rounded-xl border border-white/10 bg-black/20 p-4">';
        $html .= '<p class="mb-3 text-sm font-semibold text-white">Profile banner</p>';
        $html .= '<img id="gt-client-banner-preview" src="' . e($bannerUrl) . '" alt="Client profile banner" class="h-24 w-full rounded-xl object-cover border border-white/10' . $clientBannerHidden . '">';
        $html .= '<div id="gt-client-banner-placeholder" class="h-24 w-full rounded-xl border border-white/10 bg-slate-900/70 text-xs text-slate-400 flex items-center justify-center' . $clientBannerPlaceholderHidden . '">No banner image</div>';
        $html .= '<div class="mt-3 space-y-2">';
        $html .= '<input id="gt-client-banner-input" type="file" name="gt_profile_banner_file" accept="image/*" data-gt-preview-id="gt-client-banner-preview" data-gt-placeholder-id="gt-client-banner-placeholder" data-crop-ratio="3" class="block w-full text-sm text-slate-200">';
        $html .= '<div class="flex flex-wrap gap-2">';
        $html .= '<button type="submit" name="gigtune_client_media_apply" value="banner" class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-500">Apply banner</button>';
        $html .= '<button type="submit" name="gigtune_client_media_remove" value="banner" class="inline-flex items-center justify-center rounded-lg bg-white/10 px-3 py-2 text-xs font-semibold text-white hover:bg-white/15">Remove banner</button>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="grid gap-4 md:grid-cols-2">';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Display name *</label><input required name="gigtune_client_title" value="' . e((string) ($post->post_title ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Company *</label><input required name="gigtune_client_company" value="' . e((string) ($meta['gigtune_client_company'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Phone *</label><input required name="gigtune_client_phone" value="' . e((string) ($meta['gigtune_client_phone'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Base area *</label><input required name="gigtune_client_base_area" value="' . e((string) ($meta['gigtune_client_base_area'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Province</label><select required name="gigtune_client_province" class="gigtune-site-select w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"><option value="">Select province</option>';
        foreach ($this->saProvinces() as $provinceOption) {
            $selectedProvince = (string) ($meta['gigtune_client_province'] ?? '') === $provinceOption ? ' selected' : '';
            $html .= '<option value="' . e($provinceOption) . '"' . $selectedProvince . '>' . e($provinceOption) . '</option>';
        }
        $html .= '</select></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Town/City</label><input required name="gigtune_client_city" value="' . e((string) (($meta['gigtune_client_address_city'] ?? '') !== '' ? ($meta['gigtune_client_address_city'] ?? '') : ($meta['gigtune_client_city'] ?? ''))) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Street address</label><input required name="gigtune_client_address_street" value="' . e((string) ($meta['gigtune_client_address_street'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Suburb</label><input name="gigtune_client_address_suburb" value="' . e((string) ($meta['gigtune_client_address_suburb'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Postcode</label><input required name="gigtune_client_address_postal_code" value="' . e((string) ($meta['gigtune_client_address_postal_code'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Country</label><input required name="gigtune_client_address_country" value="' . e((string) (($meta['gigtune_client_address_country'] ?? '') !== '' ? ($meta['gigtune_client_address_country'] ?? '') : 'South Africa')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div class="md:col-span-2"><label class="mb-1 block text-sm text-slate-300">Bio *</label><textarea required name="gigtune_client_bio" rows="4" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white">' . e((string) ($post->post_content ?? '')) . '</textarea></div>';
        $html .= '</div>';

        $html .= '<div class="grid gap-4 md:grid-cols-2">';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Bank account name</label><input name="gigtune_client_bank_account_name" value="' . e((string) ($meta['gigtune_client_bank_account_name'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Bank account number</label><input name="gigtune_client_bank_account_number" value="' . e((string) ($meta['gigtune_client_bank_account_number'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Bank name</label><input name="gigtune_client_bank_name" value="' . e((string) ($meta['gigtune_client_bank_name'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Branch code</label><input name="gigtune_client_branch_code" value="' . e($branchCode) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '</div>';

        $html .= '<div class="rounded-xl border border-white/10 bg-black/20 p-4">';
        $html .= '<p class="mb-3 text-sm font-semibold text-white">Account security</p>';
        $html .= '<div class="grid gap-4 md:grid-cols-2">';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Email address</label><input type="email" name="gigtune_account_email" value="' . e((string) ($u['email'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div class="md:col-span-2"><p class="text-xs text-slate-400">Changing email requires re-verification. Leave password fields blank if not changing password.</p></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">New password</label><input type="password" name="gigtune_account_password" autocomplete="new-password" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Confirm new password</label><input type="password" name="gigtune_account_password_confirm" autocomplete="new-password" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '</div>';
        $html .= '<div class="mt-3"><button type="submit" name="gigtune_client_account_submit" value="1" class="inline-flex items-center justify-center rounded-lg bg-white/10 px-4 py-2 text-sm font-semibold text-white hover:bg-white/15">Save account settings</button></div>';
        $html .= '</div>';

        $html .= '<div class="flex flex-wrap gap-2">';
        $html .= '<button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Save profile</button>';
        $html .= '<a class="rounded-md border border-slate-600 px-4 py-2 text-sm font-semibold text-slate-200" href="/client-profile/?client_profile_id=' . (int) $profileId . '">Preview profile</a>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= $this->renderProfileMediaEnhancementsScript();

        return $html;
    }

    private function publicClientProfile(array $a, ?array $u, array $ctx = []): string
    {
        $request = $this->req($ctx);
        $profileId = (int) ($a['client_profile_id'] ?? 0);
        if ($profileId <= 0) {
            $profileId = (int) ($request?->query('client_profile_id', 0) ?? 0);
        }
        if ($profileId <= 0 && is_array($u)) {
            $profileId = $this->latestUserMetaInt((int) ($u['id'] ?? 0), 'gigtune_client_profile_id');
        }
        if ($profileId <= 0) {
            $profileId = (int) $this->db()->table($this->posts())
                ->where('post_type', 'gt_client_profile')
                ->where('post_status', 'publish')
                ->orderByDesc('ID')
                ->value('ID');
        }
        if ($profileId <= 0) {
            return '<div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">Client profile unavailable.</div>';
        }
        $post = $this->db()->table($this->posts())->where('ID', $profileId)->first(['post_title', 'post_content']);
        if ($post === null) {
            return '<div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">Client profile unavailable.</div>';
        }
        $meta = $this->postMetaMap([$profileId], [
            'gigtune_client_user_id',
            'gigtune_client_company',
            'gigtune_client_organisation',
            'gigtune_client_base_area',
            'gigtune_client_photo_id',
            'gigtune_client_banner_id',
        ])[$profileId] ?? [];
        $clientUserId = (int) ($meta['gigtune_client_user_id'] ?? 0);
        $photo = $this->attachmentUrl((int) ($meta['gigtune_client_photo_id'] ?? 0));
        $banner = $this->attachmentUrl((int) ($meta['gigtune_client_banner_id'] ?? 0));
        $company = trim((string) ($meta['gigtune_client_company'] ?? $meta['gigtune_client_organisation'] ?? ''));
        $baseArea = trim((string) ($meta['gigtune_client_base_area'] ?? ''));

        $ratingsAvg = 0.0;
        $ratingsCount = 0;
        if ($clientUserId > 0) {
            $ratingRows = $this->db()->table($this->posts() . ' as p')
                ->where('p.post_type', 'gt_client_rating')
                ->where('p.post_status', 'publish')
                ->whereExists(function ($q) use ($clientUserId): void {
                    $q->selectRaw('1')
                        ->from($this->pm() . ' as pm')
                        ->whereColumn('pm.post_id', 'p.ID')
                        ->where('pm.meta_key', 'gigtune_client_rating_client_user_id')
                        ->where('pm.meta_value', (string) $clientUserId);
                })
                ->pluck('p.ID')
                ->all();
            if (is_array($ratingRows) && $ratingRows !== []) {
                $ratingIds = array_map(static fn ($value): int => (int) $value, $ratingRows);
                $ratingsMeta = $this->postMetaMap($ratingIds, ['gigtune_client_rating_overall_avg']);
                $sum = 0.0;
                $count = 0;
                foreach ($ratingIds as $ratingId) {
                    $avg = (float) (($ratingsMeta[$ratingId]['gigtune_client_rating_overall_avg'] ?? 0));
                    if ($avg > 0) {
                        $sum += $avg;
                        $count++;
                    }
                }
                if ($count > 0) {
                    $ratingsAvg = $sum / $count;
                    $ratingsCount = $count;
                }
            }
        }

        $html = '<div class="space-y-6">';
        $html .= '<div class="overflow-hidden rounded-2xl border border-white/10 bg-white/5">';
        if ($banner !== '') {
            $html .= '<div class="h-48 w-full border-b border-white/10 bg-slate-900/70"><img src="' . e($banner) . '" alt="' . e((string) $post->post_title) . ' banner" class="h-full w-full object-cover"></div>';
        } else {
            $html .= '<div class="h-48 w-full border-b border-white/10 bg-gradient-to-r from-slate-900 to-slate-800"></div>';
        }
        $html .= '<div class="flex flex-col gap-4 px-6 pb-6 -mt-14 sm:flex-row sm:items-end sm:justify-between">';
        $html .= '<div class="flex items-end gap-4">';
        $html .= '<div class="h-28 w-28 shrink-0 overflow-hidden rounded-full border-4 border-slate-900 bg-slate-900">';
        if ($photo !== '') {
            $html .= '<img src="' . e($photo) . '" alt="' . e((string) $post->post_title) . '" class="h-full w-full object-cover">';
        } else {
            $html .= '<div class="flex h-full w-full items-center justify-center text-xs text-slate-400">No photo</div>';
        }
        $html .= '</div>';
        $html .= '<div class="pt-10">';
        $html .= '<h2 class="text-2xl font-bold text-white">' . e((string) $post->post_title) . '</h2>';
        if ($company !== '') {
            $html .= '<p class="mt-1 text-sm text-slate-300">' . e($company) . '</p>';
        }
        if ($baseArea !== '') {
            $html .= '<p class="text-xs text-slate-400">' . e($baseArea) . '</p>';
        }
        $html .= '</div></div>';
        $html .= '<div class="rounded-xl border border-white/10 bg-black/20 p-3 text-sm">';
        if ($ratingsCount > 0) {
            $html .= '<div class="text-slate-300">Client rating</div>';
            $html .= '<div class="mt-1 font-semibold text-white">&#9733; ' . e(number_format($ratingsAvg, 1)) . ' <span class="font-normal text-slate-400">(' . e((string) $ratingsCount) . ')</span></div>';
        } else {
            $html .= '<div class="text-slate-300">No ratings yet.</div>';
        }
        $html .= '</div></div></div>';
        $html .= '<div class="rounded-2xl border border-white/10 bg-white/5 p-6">';
        $html .= '<h3 class="text-lg font-semibold text-white">About</h3>';
        $html .= '<div class="mt-3 text-sm text-slate-200">' . nl2br(e(trim((string) $post->post_content))) . '</div>';
        $html .= '</div>';
        return $html . '</div>';
    }
    private function publicArtistProfile(array $a, ?array $u = null, array $ctx = []): string
    {
        $request = $this->req($ctx);
        $profileId = (int) ($a['id'] ?? 0);
        if ($profileId <= 0) {
            $profileId = (int) ($a['artist_id'] ?? 0);
        }
        if ($profileId <= 0) {
            $profileId = (int) ($request?->query('id', 0) ?? 0);
        }
        if ($profileId <= 0) {
            $profileId = (int) ($request?->query('artist_id', 0) ?? 0);
        }

        $artistSlug = trim((string) ($a['artist_slug'] ?? ''));
        if ($artistSlug === '') {
            $artistSlug = trim((string) ($request?->query('artist_slug', '') ?? ''));
        }

        if ($profileId <= 0 && $artistSlug !== '') {
            $profileId = (int) $this->db()->table($this->posts())
                ->where('post_type', 'artist_profile')
                ->where('post_status', 'publish')
                ->where('post_name', $artistSlug)
                ->orderByDesc('ID')
                ->value('ID');
        }

        if ($profileId <= 0) {
            $profileId = (int) $this->db()->table($this->posts())
                ->where('post_type', 'artist_profile')
                ->where('post_status', 'publish')
                ->orderByDesc('ID')
                ->value('ID');
        }

        if ($profileId <= 0) {
            return '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 text-slate-200">No artist profiles found.</div>';
        }

        $post = $this->db()->table($this->posts())
            ->where('ID', $profileId)
            ->where('post_type', 'artist_profile')
            ->first(['ID', 'post_title', 'post_content']);

        if ($post === null) {
            return '<div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-6 text-rose-200">Artist profile not found.</div>';
        }

        $meta = $this->postMetaMap([(int) $post->ID], [
            'gigtune_artist_photo_id',
            'gigtune_artist_base_area',
            'gigtune_artist_price_min',
            'gigtune_artist_price_max',
            'gigtune_artist_available_now',
            'gigtune_user_id',
            'gigtune_performance_rating_avg',
            'gigtune_performance_rating_count',
            'gigtune_demo_videos',
        ])[(int) $post->ID] ?? [];

        $photoId = (int) ($meta['gigtune_artist_photo_id'] ?? 0);
        $photo = $this->attachmentUrl($photoId);
        $baseArea = trim((string) ($meta['gigtune_artist_base_area'] ?? ''));
        $priceMin = (int) ($meta['gigtune_artist_price_min'] ?? 0);
        $priceMax = (int) ($meta['gigtune_artist_price_max'] ?? 0);
        $availableNow = $this->bool($meta['gigtune_artist_available_now'] ?? '');
        $ratingAvg = (float) ($meta['gigtune_performance_rating_avg'] ?? 0);
        $ratingCount = (int) ($meta['gigtune_performance_rating_count'] ?? 0);
        $demoVideos = $this->demoVideos((string) ($meta['gigtune_demo_videos'] ?? ''));

        $priceLabel = '';
        if ($priceMin > 0 && $priceMax > 0) {
            $priceLabel = 'ZAR ' . number_format($priceMin) . ' - ' . number_format($priceMax);
        } elseif ($priceMin > 0) {
            $priceLabel = 'From ZAR ' . number_format($priceMin);
        } elseif ($priceMax > 0) {
            $priceLabel = 'Up to ZAR ' . number_format($priceMax);
        }

        $editUrl = '';
        if (is_array($u)) {
            $userId = (int) ($u['id'] ?? 0);
            $myProfileId = $this->latestUserMetaInt($userId, 'gigtune_artist_profile_id');
            if ($myProfileId > 0 && $myProfileId === (int) $post->ID) {
                $editUrl = '/artist-profile-edit/';
            }
        }

        $html = '<div class="space-y-6">';
        $html .= '<div class="rounded-2xl border border-white/10 bg-white/5 p-6">';
        $html .= '<div class="flex flex-col gap-5 sm:flex-row sm:items-center">';
        $html .= '<div class="flex h-28 w-28 items-center justify-center rounded-full border border-white/10 bg-black/30">';
        if ($photo !== '') {
            $html .= '<img src="' . e($photo) . '" alt="' . e((string) $post->post_title) . '" class="h-28 w-28 rounded-full object-cover">';
        } else {
            $html .= '<span class="text-xs text-slate-400">No photo</span>';
        }
        $html .= '</div>';
        $html .= '<div class="flex-1">';
        $html .= '<h2 class="text-2xl font-bold text-white">' . e((string) $post->post_title) . '</h2>';
        if ($baseArea !== '') {
            $html .= '<p class="mt-1 text-slate-300">Base area: ' . e($baseArea) . '</p>';
        }
        if ($priceLabel !== '') {
            $html .= '<p class="mt-1 text-slate-300">Pricing: ' . e($priceLabel) . '</p>';
        }
        $html .= '<p class="mt-1 text-slate-300">Availability: ' . ($availableNow ? 'Available now' : 'Check availability') . '</p>';
        $html .= '<div class="mt-3 rounded-xl border border-white/10 bg-black/20 p-3 text-sm">';
        if ($ratingCount > 0) {
            $html .= '<div class="text-slate-300">Overall rating</div>';
            $html .= '<div class="mt-1 font-semibold text-white">&#9733; ' . e(number_format($ratingAvg, 1)) . ' <span class="font-normal text-slate-400">(' . e((string) $ratingCount) . ')</span></div>';
        } else {
            $html .= '<div class="text-slate-300">No ratings yet.</div>';
        }
        $html .= '</div></div>';
        $html .= '<div>';
        $html .= '<a class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 font-semibold text-white hover:bg-indigo-500" href="' . e($this->bookArtistFormUrl((int) $post->ID)) . '">Book this artist</a>';
        if ($editUrl !== '') {
            $html .= '<a class="mt-3 inline-flex text-sm text-blue-300 hover:text-blue-200" href="' . e($editUrl) . '">Edit Public Profile</a>';
        }
        $html .= '</div></div></div>';
        $html .= '<div class="rounded-2xl border border-white/10 bg-white/5 p-6">';
        $html .= '<h3 class="text-lg font-semibold text-white">About</h3>';
        $html .= '<p class="mt-3 text-slate-300">' . e(trim((string) $post->post_content)) . '</p>';
        $html .= '</div>';
        if ($demoVideos !== []) {
            $html .= '<div class="rounded-2xl border border-white/10 bg-white/5 p-6">';
            $html .= '<h3 class="text-lg font-semibold text-white">Demo videos</h3>';
            $html .= '<div class="mt-3 grid gap-4 md:grid-cols-2">';
            foreach ($demoVideos as $video) {
                $videoUrl = trim((string) ($video['url'] ?? ''));
                $html .= '<div class="rounded-xl border border-white/10 bg-black/20 p-3 space-y-2">';
                $html .= $this->renderDemoPreview($video);
                if ($videoUrl !== '') {
                    $html .= '<a href="' . e($videoUrl) . '" target="_blank" rel="noopener" class="text-xs text-slate-300 hover:text-white underline break-all">' . e($videoUrl) . '</a>';
                }
                $html .= '</div>';
            }
            $html .= '</div></div>';
        }
        $html .= '</div>';

        return $html;
    }
    private function artistProfileEdit(array $a, ?array $u, array $ctx = []): string
    {
        if (!is_array($u)) {
            return '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">Sign in required.</div>';
        }
        $request = $this->req($ctx);
        $uid = (int) ($u['id'] ?? 0);
        $profileId = $this->latestUserMetaInt($uid, 'gigtune_artist_profile_id');
        if ($profileId <= 0) {
            return '<div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">No linked artist profile found.</div>';
        }

        $post = $this->db()->table($this->posts())
            ->where('ID', $profileId)
            ->where('post_type', 'artist_profile')
            ->first(['ID', 'post_title', 'post_content']);
        if ($post === null) {
            return '<div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">Artist profile unavailable.</div>';
        }

        $statusMessage = '';
        $errorMessage = '';
        $skipArtistProfileSave = false;
        if ($request !== null && strtoupper((string) $request->method()) === 'POST') {
            $removeMedia = trim((string) $request->input('gigtune_artist_media_remove', ''));
            if (in_array($removeMedia, ['photo', 'banner'], true)) {
                $skipArtistProfileSave = true;
                if ($removeMedia === 'photo') {
                    $existingPhotoId = (int) $this->getLatestPostMeta($profileId, 'gigtune_artist_photo_id');
                    if ($existingPhotoId > 0) {
                        $this->deleteAttachment($existingPhotoId);
                    }
                    $this->upsertPostMeta($profileId, 'gigtune_artist_photo_id', '0');
                    $statusMessage = 'Profile photo removed.';
                } else {
                    $existingBannerId = (int) $this->getLatestPostMeta($profileId, 'gigtune_artist_banner_id');
                    if ($existingBannerId > 0) {
                        $this->deleteAttachment($existingBannerId);
                    }
                    $this->upsertPostMeta($profileId, 'gigtune_artist_banner_id', '0');
                    $statusMessage = 'Profile banner removed.';
                }
            } elseif (in_array(trim((string) $request->input('gigtune_artist_media_apply', '')), ['photo', 'banner'], true)) {
                $skipArtistProfileSave = true;
                $applyMedia = trim((string) $request->input('gigtune_artist_media_apply', ''));
                if ($applyMedia === 'photo') {
                    $artistPhotoFile = $request->file('gt_profile_photo_file');
                    if (!($artistPhotoFile instanceof UploadedFile)) {
                        $artistPhotoFile = $request->file('gigtune_artist_photo');
                    }
                    if (!($artistPhotoFile instanceof UploadedFile)) {
                        $artistPhotoFile = $request->file('gigtune_profile_photo');
                    }
                    if (!($artistPhotoFile instanceof UploadedFile)) {
                        $artistPhotoFile = $request->file('profile_photo');
                    }
                    $photoId = $this->saveUploadedAttachment($artistPhotoFile, $uid, $profileId, 'Artist Profile Photo');
                    if ($photoId > 0) {
                        $oldPhotoId = (int) $this->getLatestPostMeta($profileId, 'gigtune_artist_photo_id');
                        if ($oldPhotoId > 0 && $oldPhotoId !== $photoId) {
                            $this->deleteAttachment($oldPhotoId);
                        }
                        $this->upsertPostMeta($profileId, 'gigtune_artist_photo_id', (string) $photoId);
                        $statusMessage = 'Profile photo updated.';
                    } else {
                        $errorMessage = 'Please select a valid profile photo first.';
                    }
                } else {
                    $artistBannerFile = $request->file('gt_profile_banner_file');
                    if (!($artistBannerFile instanceof UploadedFile)) {
                        $artistBannerFile = $request->file('gigtune_artist_banner');
                    }
                    if (!($artistBannerFile instanceof UploadedFile)) {
                        $artistBannerFile = $request->file('gigtune_profile_banner');
                    }
                    if (!($artistBannerFile instanceof UploadedFile)) {
                        $artistBannerFile = $request->file('profile_banner');
                    }
                    $bannerId = $this->saveUploadedAttachment($artistBannerFile, $uid, $profileId, 'Artist Profile Banner');
                    if ($bannerId > 0) {
                        $oldBannerId = (int) $this->getLatestPostMeta($profileId, 'gigtune_artist_banner_id');
                        if ($oldBannerId > 0 && $oldBannerId !== $bannerId) {
                            $this->deleteAttachment($oldBannerId);
                        }
                        $this->upsertPostMeta($profileId, 'gigtune_artist_banner_id', (string) $bannerId);
                        $statusMessage = 'Profile banner updated.';
                    } else {
                        $errorMessage = 'Please select a valid profile banner first.';
                    }
                }
            } elseif ((string) $request->input('gigtune_artist_account_submit', '') === '1') {
                $skipArtistProfileSave = true;
                $newEmail = strtolower(trim((string) $request->input('gigtune_account_email', '')));
                $newPassword = (string) $request->input('gigtune_account_password', '');
                $confirmPassword = (string) $request->input('gigtune_account_password_confirm', '');
                try {
                    if ($newEmail !== '' && $newEmail !== strtolower(trim((string) ($u['email'] ?? '')))) {
                        $this->users->updateUserEmail($uid, $newEmail);
                        $token = bin2hex(random_bytes(16));
                        $this->upsertUserMeta($uid, 'gigtune_email_verified', '0');
                        $this->upsertUserMeta($uid, 'gigtune_email_verification_required', '1');
                        $this->upsertUserMeta($uid, 'gigtune_email_verification_token_hash', hash('sha256', $token));
                        $this->upsertUserMeta($uid, 'gigtune_email_verification_expires_at', (string) (time() + 172800));
                        $this->upsertUserMeta($uid, 'gigtune_email_verification_sent_at', now()->format('Y-m-d H:i:s'));
                        $mailSent = $this->mail->sendVerificationEmail($uid, $token);
                        $this->upsertUserMeta($uid, 'gigtune_email_verification_delivery', $mailSent ? 'sent' : 'failed');
                        $statusMessage = $mailSent
                            ? 'Account email updated. Verification email sent to the new address.'
                            : 'Account email updated. Verification email could not be sent right now.';
                    }
                    if ($newPassword !== '' || $confirmPassword !== '') {
                        if ($newPassword === '' || $newPassword !== $confirmPassword) {
                            throw new \InvalidArgumentException('Password confirmation does not match.');
                        }
                        $this->users->updateUserPassword($uid, $newPassword);
                        $statusMessage = $statusMessage === '' ? 'Password updated successfully.' : ($statusMessage . ' Password updated successfully.');
                    }
                    if ($statusMessage === '') {
                        $errorMessage = 'No account changes detected.';
                    }
                } catch (\Throwable $throwable) {
                    $errorMessage = trim($throwable->getMessage()) !== '' ? trim($throwable->getMessage()) : 'Unable to update account details.';
                }
            }
        }
        if (
            $request !== null
            && strtoupper((string) $request->method()) === 'POST'
            && !$skipArtistProfileSave
            && (string) $request->input('gigtune_profile_submit', '') === '1'
        ) {
            $profileName = trim((string) $request->input('profile_name', ''));
            $profileBio = trim((string) $request->input('profile_bio', ''));
            $baseArea = trim((string) $request->input('gigtune_artist_base_area', ''));
            $addressStreet = trim((string) $request->input('gigtune_artist_address_street', ''));
            $addressSuburb = trim((string) $request->input('gigtune_artist_address_suburb', ''));
            $addressCity = trim((string) $request->input('gigtune_artist_address_city', ''));
            $addressProvince = trim((string) $request->input('gigtune_artist_address_province', ''));
            $addressPostalCode = trim((string) $request->input('gigtune_artist_address_postal_code', ''));
            $addressCountry = trim((string) $request->input('gigtune_artist_address_country', 'South Africa'));
            if ($addressCountry === '') {
                $addressCountry = 'South Africa';
            }
            $priceMin = max(0, (int) $request->input('gigtune_artist_price_min', 0));
            $priceMax = max(0, (int) $request->input('gigtune_artist_price_max', 0));
            $travelRadius = max(0, (int) $request->input('gigtune_artist_travel_radius_km', 0));
            $availableNow = (string) $request->input('gigtune_artist_available_now', '') === '1' ? '1' : '0';
            $visibilityMode = strtolower(trim((string) $request->input('gigtune_artist_visibility_mode', 'approx')));
            if (!in_array($visibilityMode, ['approx', 'hidden'], true)) {
                $visibilityMode = 'approx';
            }

            $travelRatePerKm = trim((string) $request->input('gigtune_artist_travel_rate_per_km', ''));
            $travelFreeKm = trim((string) $request->input('gigtune_artist_travel_free_km', ''));
            $travelMinFee = trim((string) $request->input('gigtune_artist_travel_min_fee', ''));
            $travelRoundtrip = (string) $request->input('gigtune_artist_travel_roundtrip', '') === '1' ? '1' : '0';

            $accomPreference = strtolower(trim((string) $request->input('gigtune_artist_accom_preference', 'none')));
            if (!in_array($accomPreference, ['none', 'required', 'recommended'], true)) {
                $accomPreference = 'none';
            }
            $accomFeeFlat = trim((string) $request->input('gigtune_artist_accom_fee_flat', ''));
            $accomRequiredIfEndAfter = trim((string) $request->input('gigtune_artist_accom_required_if_end_after', ''));
            $accomRequiredKm = trim((string) $request->input('gigtune_artist_accom_required_km', ''));
            $accomRecommendedIfEndAfter = trim((string) $request->input('gigtune_artist_accom_recommended_if_end_after', ''));
            $accomRecommendedKm = trim((string) $request->input('gigtune_artist_accom_recommended_km', ''));

            $bankAccountName = trim((string) $request->input('gigtune_artist_bank_account_name', ''));
            $bankAccountNumber = preg_replace('/\s+/', '', (string) $request->input('gigtune_artist_bank_account_number', '')) ?? '';
            $bankName = trim((string) $request->input('gigtune_artist_bank_name', ''));
            $branchCode = trim((string) $request->input('gigtune_artist_branch_code', ''));
            $bankCode = trim((string) $request->input('gigtune_artist_bank_code', ''));
            if ($branchCode === '' && $bankCode !== '') {
                $branchCode = $bankCode;
            }

            $allowedDays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
            $selectedDays = $request->input('gigtune_artist_availability_days', []);
            $days = [];
            if (is_array($selectedDays)) {
                foreach ($selectedDays as $day) {
                    $day = strtolower(trim((string) $day));
                    if (in_array($day, $allowedDays, true)) {
                        $days[] = $day;
                    }
                }
            }
            $days = array_values(array_unique($days));
            $startTime = trim((string) $request->input('gigtune_artist_availability_start_time', ''));
            $endTime = trim((string) $request->input('gigtune_artist_availability_end_time', ''));
            $slots = trim((string) $request->input('gigtune_artist_availability_slots', ''));

            $taxonomySelections = [];
            foreach (['performer_type', 'instrument_category', 'keyboard_parts', 'vocal_type', 'vocal_role'] as $taxonomy) {
                $rawTerms = $request->input($taxonomy, []);
                $slugs = [];
                if (is_array($rawTerms)) {
                    foreach ($rawTerms as $slug) {
                        $slug = strtolower(trim((string) $slug));
                        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? '';
                        $slug = trim($slug, '-');
                        if ($slug !== '') {
                            $slugs[$slug] = $slug;
                        }
                    }
                }
                $taxonomySelections[$taxonomy] = array_values($slugs);
            }

            if (
                $profileName === '' ||
                $profileBio === '' ||
                $baseArea === '' ||
                $addressStreet === '' ||
                $addressCity === '' ||
                $addressProvince === '' ||
                $addressPostalCode === '' ||
                $priceMin <= 0 ||
                $priceMax <= 0 ||
                $startTime === '' ||
                $endTime === '' ||
                count($taxonomySelections['performer_type'] ?? []) < 1
            ) {
                $errorMessage = 'Please complete all required profile fields.';
            } else {
                if ($priceMax > 0 && $priceMin > $priceMax) {
                    $tmp = $priceMin;
                    $priceMin = $priceMax;
                    $priceMax = $tmp;
                }

                $this->db()->table($this->posts())
                    ->where('ID', $profileId)
                    ->update([
                        'post_title' => $profileName,
                        'post_content' => $profileBio,
                        'post_modified' => now()->format('Y-m-d H:i:s'),
                        'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                    ]);

                $this->upsertPostMeta($profileId, 'gigtune_artist_user_id', (string) $uid);
                $this->upsertPostMeta($profileId, 'gigtune_artist_available_now', $availableNow);
                $this->upsertPostMeta($profileId, 'gigtune_artist_visibility_mode', $visibilityMode);
                $this->upsertPostMeta($profileId, 'gigtune_artist_base_area', $baseArea);
                $this->upsertPostMeta($profileId, 'gigtune_artist_address_street', $addressStreet);
                $this->upsertPostMeta($profileId, 'gigtune_artist_address_suburb', $addressSuburb);
                $this->upsertPostMeta($profileId, 'gigtune_artist_address_city', $addressCity);
                $this->upsertPostMeta($profileId, 'gigtune_artist_address_province', $addressProvince);
                $this->upsertPostMeta($profileId, 'gigtune_artist_address_postal_code', $addressPostalCode);
                $this->upsertPostMeta($profileId, 'gigtune_artist_address_country', $addressCountry);
                $this->upsertPostMeta($profileId, 'gigtune_artist_travel_radius_km', (string) $travelRadius);
                $this->upsertPostMeta($profileId, 'gigtune_artist_price_min', (string) $priceMin);
                $this->upsertPostMeta($profileId, 'gigtune_artist_price_max', (string) $priceMax);
                $this->upsertPostMeta($profileId, 'gigtune_artist_availability_days', serialize($days));
                $this->upsertPostMeta($profileId, 'gigtune_artist_availability_start_time', $startTime);
                $this->upsertPostMeta($profileId, 'gigtune_artist_availability_end_time', $endTime);
                $this->upsertPostMeta($profileId, 'gigtune_artist_availability_slots', $slots);

                $this->upsertPostMeta($profileId, 'gigtune_artist_travel_rate_per_km', $travelRatePerKm);
                $this->upsertPostMeta($profileId, 'gigtune_artist_travel_free_km', $travelFreeKm);
                $this->upsertPostMeta($profileId, 'gigtune_artist_travel_min_fee', $travelMinFee);
                $this->upsertPostMeta($profileId, 'gigtune_artist_travel_roundtrip', $travelRoundtrip);

                $this->upsertPostMeta($profileId, 'gigtune_artist_accom_preference', $accomPreference);
                $this->upsertPostMeta($profileId, 'gigtune_artist_accom_fee_flat', $accomFeeFlat);
                $this->upsertPostMeta($profileId, 'gigtune_artist_accom_required_if_end_after', $accomRequiredIfEndAfter);
                $this->upsertPostMeta($profileId, 'gigtune_artist_accom_required_km', $accomRequiredKm);
                $this->upsertPostMeta($profileId, 'gigtune_artist_accom_recommended_if_end_after', $accomRecommendedIfEndAfter);
                $this->upsertPostMeta($profileId, 'gigtune_artist_accom_recommended_km', $accomRecommendedKm);

                $this->upsertPostMeta($profileId, 'gigtune_artist_bank_account_name', $bankAccountName);
                $this->upsertPostMeta($profileId, 'gigtune_artist_bank_account_number', $bankAccountNumber);
                $this->upsertPostMeta($profileId, 'gigtune_artist_bank_name', $bankName);
                $this->upsertPostMeta($profileId, 'gigtune_artist_branch_code', $branchCode);
                $this->upsertPostMeta($profileId, 'gigtune_artist_bank_code', $branchCode);

                foreach ($taxonomySelections as $taxonomy => $slugs) {
                    $this->syncPostTermsBySlugs($profileId, $taxonomy, $slugs);
                }

                $artistPhotoFile = $request->file('gt_profile_photo_file');
                if (!($artistPhotoFile instanceof UploadedFile)) {
                    $artistPhotoFile = $request->file('gigtune_artist_photo');
                }
                if (!($artistPhotoFile instanceof UploadedFile)) {
                    $artistPhotoFile = $request->file('gigtune_profile_photo');
                }
                if (!($artistPhotoFile instanceof UploadedFile)) {
                    $artistPhotoFile = $request->file('profile_photo');
                }
                $photoId = $this->saveUploadedAttachment($artistPhotoFile, $uid, $profileId, 'Artist Profile Photo');
                if ($photoId > 0) {
                    $oldPhotoId = (int) $this->getLatestPostMeta($profileId, 'gigtune_artist_photo_id');
                    if ($oldPhotoId > 0 && $oldPhotoId !== $photoId) {
                        $this->deleteAttachment($oldPhotoId);
                    }
                    $this->upsertPostMeta($profileId, 'gigtune_artist_photo_id', (string) $photoId);
                }
                $artistBannerFile = $request->file('gt_profile_banner_file');
                if (!($artistBannerFile instanceof UploadedFile)) {
                    $artistBannerFile = $request->file('gigtune_artist_banner');
                }
                if (!($artistBannerFile instanceof UploadedFile)) {
                    $artistBannerFile = $request->file('gigtune_profile_banner');
                }
                if (!($artistBannerFile instanceof UploadedFile)) {
                    $artistBannerFile = $request->file('profile_banner');
                }
                $bannerId = $this->saveUploadedAttachment($artistBannerFile, $uid, $profileId, 'Artist Profile Banner');
                if ($bannerId > 0) {
                    $oldBannerId = (int) $this->getLatestPostMeta($profileId, 'gigtune_artist_banner_id');
                    if ($oldBannerId > 0 && $oldBannerId !== $bannerId) {
                        $this->deleteAttachment($oldBannerId);
                    }
                    $this->upsertPostMeta($profileId, 'gigtune_artist_banner_id', (string) $bannerId);
                }

                $demoRaw = $this->maybe($this->getLatestPostMeta($profileId, 'gigtune_demo_videos'));
                $demoItems = is_array($demoRaw) ? $demoRaw : [];
                $normalizedDemoItems = [];
                foreach ($demoItems as $item) {
                    if (is_int($item) || ctype_digit((string) $item)) {
                        $id = (int) $item;
                        if ($id > 0) {
                            $normalizedDemoItems[] = $id;
                        }
                        continue;
                    }
                    $url = trim((string) $item);
                    if ($url !== '') {
                        $normalizedDemoItems[] = $url;
                    }
                }
                $demoItems = array_values(array_unique($normalizedDemoItems, SORT_REGULAR));

                $removeDemoValues = $request->input('gigtune_remove_demo', []);
                if (is_array($removeDemoValues) && $removeDemoValues !== []) {
                    $removeSet = [];
                    foreach ($removeDemoValues as $value) {
                        $value = trim((string) $value);
                        if ($value !== '') {
                            $removeSet[$value] = true;
                        }
                    }
                    $kept = [];
                    foreach ($demoItems as $item) {
                        $rawValue = (string) $item;
                        if (isset($removeSet[$rawValue])) {
                            if (is_int($item) || ctype_digit($rawValue)) {
                                $this->deleteAttachment((int) $rawValue);
                            }
                            continue;
                        }
                        $kept[] = $item;
                    }
                    $demoItems = $kept;
                }

                $demoUrl = trim((string) $request->input('gigtune_demo_url', ''));
                if ($demoUrl !== '') {
                    $demoItems[] = $demoUrl;
                }

                $demoFiles = $request->file('gigtune_demo_videos');
                if (is_array($demoFiles)) {
                    foreach ($demoFiles as $demoFile) {
                        $demoAttachmentId = $this->saveUploadedAttachment($demoFile, $uid, $profileId, 'Artist Demo Video');
                        if ($demoAttachmentId > 0) {
                            $demoItems[] = $demoAttachmentId;
                        }
                    }
                } elseif ($demoFiles instanceof UploadedFile) {
                    $demoAttachmentId = $this->saveUploadedAttachment($demoFiles, $uid, $profileId, 'Artist Demo Video');
                    if ($demoAttachmentId > 0) {
                        $demoItems[] = $demoAttachmentId;
                    }
                }

                $dedupedDemoItems = [];
                foreach ($demoItems as $item) {
                    $key = is_int($item) || ctype_digit((string) $item) ? ('id:' . (int) $item) : ('url:' . trim((string) $item));
                    if (!isset($dedupedDemoItems[$key])) {
                        $dedupedDemoItems[$key] = is_int($item) || ctype_digit((string) $item) ? (int) $item : trim((string) $item);
                    }
                }
                $this->upsertPostMeta($profileId, 'gigtune_demo_videos', serialize(array_values($dedupedDemoItems)));

                $statusMessage = 'Profile updated successfully.';
            }

            $post = $this->db()->table($this->posts())
                ->where('ID', $profileId)
                ->where('post_type', 'artist_profile')
                ->first(['ID', 'post_title', 'post_content']);
            if ($post === null) {
                return '<div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">Artist profile unavailable.</div>';
            }
        }

        $meta = $this->postMetaMap([$profileId], [
            'gigtune_artist_available_now',
            'gigtune_artist_visibility_mode',
            'gigtune_artist_base_area',
            'gigtune_artist_address_street',
            'gigtune_artist_address_suburb',
            'gigtune_artist_address_city',
            'gigtune_artist_address_province',
            'gigtune_artist_address_postal_code',
            'gigtune_artist_address_country',
            'gigtune_artist_travel_radius_km',
            'gigtune_artist_price_min',
            'gigtune_artist_price_max',
            'gigtune_artist_availability_days',
            'gigtune_artist_availability_start_time',
            'gigtune_artist_availability_end_time',
            'gigtune_artist_availability_slots',
            'gigtune_artist_travel_rate_per_km',
            'gigtune_artist_travel_free_km',
            'gigtune_artist_travel_min_fee',
            'gigtune_artist_travel_roundtrip',
            'gigtune_artist_accom_preference',
            'gigtune_artist_accom_fee_flat',
            'gigtune_artist_accom_required_if_end_after',
            'gigtune_artist_accom_required_km',
            'gigtune_artist_accom_recommended_if_end_after',
            'gigtune_artist_accom_recommended_km',
            'gigtune_artist_bank_account_name',
            'gigtune_artist_bank_account_number',
            'gigtune_artist_bank_name',
            'gigtune_artist_branch_code',
            'gigtune_artist_bank_code',
            'gigtune_artist_photo_id',
            'gigtune_artist_banner_id',
            'gigtune_demo_videos',
        ])[$profileId] ?? [];

        $days = $this->days((string) ($meta['gigtune_artist_availability_days'] ?? ''));
        $daySet = array_fill_keys($days, true);
        $visibilityMode = (string) ($meta['gigtune_artist_visibility_mode'] ?? 'approx');
        $accomPreference = (string) ($meta['gigtune_artist_accom_preference'] ?? 'none');
        $branchCode = (string) ($meta['gigtune_artist_branch_code'] ?? '');
        if ($branchCode === '') {
            $branchCode = (string) ($meta['gigtune_artist_bank_code'] ?? '');
        }
        $photoUrl = $this->attachmentUrl((int) ($meta['gigtune_artist_photo_id'] ?? 0));
        $bannerUrl = $this->attachmentUrl((int) ($meta['gigtune_artist_banner_id'] ?? 0));
        $artistPhotoHidden = $photoUrl === '' ? ' hidden' : '';
        $artistPhotoPlaceholderHidden = $photoUrl !== '' ? ' hidden' : '';
        $artistBannerHidden = $bannerUrl === '' ? ' hidden' : '';
        $artistBannerPlaceholderHidden = $bannerUrl !== '' ? ' hidden' : '';
        $demoVideos = $this->demoVideos((string) ($meta['gigtune_demo_videos'] ?? ''));
        $selectedTaxonomies = $this->postTermSlugs($profileId, ['performer_type', 'instrument_category', 'keyboard_parts', 'vocal_type', 'vocal_role']);
        $taxonomyOptions = $this->getFilterOptions();
        foreach ($selectedTaxonomies as $taxonomy => $selectedSlugs) {
            if (!is_array($selectedSlugs) || $selectedSlugs === []) {
                continue;
            }
            if (!isset($taxonomyOptions[$taxonomy]) || !is_array($taxonomyOptions[$taxonomy])) {
                $taxonomyOptions[$taxonomy] = [];
            }
            $existing = [];
            foreach ($taxonomyOptions[$taxonomy] as $term) {
                $slug = trim((string) ($term['slug'] ?? ''));
                if ($slug !== '') {
                    $existing[$slug] = true;
                }
            }
            foreach ($selectedSlugs as $slug) {
                $slug = trim((string) $slug);
                if ($slug === '' || isset($existing[$slug])) {
                    continue;
                }
                $taxonomyOptions[$taxonomy][] = ['slug' => $slug, 'name' => ucwords(str_replace(['-', '_'], ' ', $slug))];
                $existing[$slug] = true;
            }
        }

        $html = '<form method="post" enctype="multipart/form-data" class="space-y-6 rounded-2xl border border-white/10 bg-white/5 p-6">';
        if ($statusMessage !== '') {
            $html .= '<div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-200">' . e($statusMessage) . '</div>';
        }
        if ($errorMessage !== '') {
            $html .= '<div class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-sm text-rose-200">' . e($errorMessage) . '</div>';
        }

        $html .= '<input type="hidden" name="gigtune_profile_submit" value="1">';
        $html .= '<div class="grid gap-4 md:grid-cols-2">';
        $html .= '<div class="rounded-xl border border-white/10 bg-black/20 p-4">';
        $html .= '<p class="mb-3 text-sm font-semibold text-white">Profile photo</p>';
        $html .= '<img id="gt-artist-photo-preview" src="' . e($photoUrl) . '" alt="Artist profile photo" class="h-24 w-24 rounded-full object-cover border border-white/10' . $artistPhotoHidden . '">';
        $html .= '<div id="gt-artist-photo-placeholder" class="h-24 w-24 rounded-full border border-white/10 bg-slate-900/70 text-xs text-slate-400 flex items-center justify-center' . $artistPhotoPlaceholderHidden . '">No photo</div>';
        $html .= '<div class="mt-3 space-y-2">';
        $html .= '<input id="gt-artist-photo-input" type="file" name="gt_profile_photo_file" accept="image/*" data-gt-preview-id="gt-artist-photo-preview" data-gt-placeholder-id="gt-artist-photo-placeholder" data-crop-ratio="1" class="block w-full text-sm text-slate-200">';
        $html .= '<div class="flex flex-wrap gap-2">';
        $html .= '<button type="submit" name="gigtune_artist_media_apply" value="photo" class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-500">Apply photo</button>';
        $html .= '<button type="submit" name="gigtune_artist_media_remove" value="photo" class="inline-flex items-center justify-center rounded-lg bg-white/10 px-3 py-2 text-xs font-semibold text-white hover:bg-white/15">Remove photo</button>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="rounded-xl border border-white/10 bg-black/20 p-4">';
        $html .= '<p class="mb-3 text-sm font-semibold text-white">Profile banner</p>';
        $html .= '<img id="gt-artist-banner-preview" src="' . e($bannerUrl) . '" alt="Artist profile banner" class="h-24 w-full rounded-xl object-cover border border-white/10' . $artistBannerHidden . '">';
        $html .= '<div id="gt-artist-banner-placeholder" class="h-24 w-full rounded-xl border border-white/10 bg-slate-900/70 text-xs text-slate-400 flex items-center justify-center' . $artistBannerPlaceholderHidden . '">No banner image</div>';
        $html .= '<div class="mt-3 space-y-2">';
        $html .= '<input id="gt-artist-banner-input" type="file" name="gt_profile_banner_file" accept="image/*" data-gt-preview-id="gt-artist-banner-preview" data-gt-placeholder-id="gt-artist-banner-placeholder" data-crop-ratio="3" class="block w-full text-sm text-slate-200">';
        $html .= '<div class="flex flex-wrap gap-2">';
        $html .= '<button type="submit" name="gigtune_artist_media_apply" value="banner" class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-500">Apply banner</button>';
        $html .= '<button type="submit" name="gigtune_artist_media_remove" value="banner" class="inline-flex items-center justify-center rounded-lg bg-white/10 px-3 py-2 text-xs font-semibold text-white hover:bg-white/15">Remove banner</button>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<div class="grid gap-4 md:grid-cols-2">';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Profile name *</label><input required name="profile_name" value="' . e((string) ($post->post_title ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Base area *</label><input required name="gigtune_artist_base_area" value="' . e((string) ($meta['gigtune_artist_base_area'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div class="md:col-span-2"><label class="mb-1 block text-sm text-slate-300">Bio *</label><textarea required name="profile_bio" rows="5" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white">' . e((string) ($post->post_content ?? '')) . '</textarea></div>';
        $html .= '</div>';

        $html .= '<div class="grid gap-4 md:grid-cols-3">';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Street address *</label><input required name="gigtune_artist_address_street" value="' . e((string) ($meta['gigtune_artist_address_street'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Suburb</label><input name="gigtune_artist_address_suburb" value="' . e((string) ($meta['gigtune_artist_address_suburb'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Town/City *</label><input required name="gigtune_artist_address_city" value="' . e((string) ($meta['gigtune_artist_address_city'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Province *</label><select required name="gigtune_artist_address_province" class="gigtune-site-select w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"><option value="">Select province</option>';
        foreach ($this->saProvinces() as $province) {
            $selectedProvince = (string) ($meta['gigtune_artist_address_province'] ?? '') === $province ? ' selected' : '';
            $html .= '<option value="' . e($province) . '"' . $selectedProvince . '>' . e($province) . '</option>';
        }
        $html .= '</select></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Postcode *</label><input required name="gigtune_artist_address_postal_code" value="' . e((string) ($meta['gigtune_artist_address_postal_code'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Country *</label><input required name="gigtune_artist_address_country" value="' . e((string) (($meta['gigtune_artist_address_country'] ?? '') !== '' ? ($meta['gigtune_artist_address_country'] ?? '') : 'South Africa')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '</div>';

        $html .= '<div class="rounded-xl border border-white/10 bg-black/20 p-4"><p class="mb-2 text-sm font-semibold text-white">Categories</p><div class="grid gap-4 md:grid-cols-2">';
        foreach (['performer_type' => 'Performer Type', 'instrument_category' => 'Instrument Category', 'keyboard_parts' => 'Keyboard Parts', 'vocal_type' => 'Vocal Type', 'vocal_role' => 'Vocal Role'] as $taxonomyKey => $taxonomyLabel) {
            $terms = is_array($taxonomyOptions[$taxonomyKey] ?? null) ? $taxonomyOptions[$taxonomyKey] : [];
            $selectedSlugs = is_array($selectedTaxonomies[$taxonomyKey] ?? null) ? $selectedTaxonomies[$taxonomyKey] : [];
            $html .= '<div><p class="mb-2 text-sm text-slate-200">' . e($taxonomyLabel) . ($taxonomyKey === 'performer_type' ? ' <span class="text-xs text-rose-300">Required</span>' : '') . '</p>';
            if ($terms === []) {
                $html .= '<p class="text-xs text-slate-400">No terms available.</p>';
            } else {
                $html .= '<div class="grid grid-cols-2 gap-2">';
                foreach ($terms as $term) {
                    $slug = trim((string) ($term['slug'] ?? ''));
                    $name = trim((string) ($term['name'] ?? $slug));
                    if ($slug === '') {
                        continue;
                    }
                    $checked = in_array($slug, $selectedSlugs, true) ? ' checked' : '';
                    $html .= '<label class="inline-flex items-center gap-2 text-sm text-slate-300"><input type="checkbox" name="' . e($taxonomyKey) . '[]" value="' . e($slug) . '"' . $checked . '> ' . e($name) . '</label>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';
        }
        $html .= '</div></div>';

        $html .= '<div class="grid gap-4 md:grid-cols-3">';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Travel radius (km) *</label><input required type="number" min="0" name="gigtune_artist_travel_radius_km" value="' . e((string) ($meta['gigtune_artist_travel_radius_km'] ?? '0')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Price min (ZAR) *</label><input required type="number" min="0" name="gigtune_artist_price_min" value="' . e((string) ($meta['gigtune_artist_price_min'] ?? '0')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Price max (ZAR) *</label><input required type="number" min="0" name="gigtune_artist_price_max" value="' . e((string) ($meta['gigtune_artist_price_max'] ?? '0')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '</div>';

        $html .= '<div class="grid gap-4 md:grid-cols-3">';
        $html .= '<label class="inline-flex items-center gap-2 text-sm text-slate-200"><input type="checkbox" name="gigtune_artist_available_now" value="1"' . (($meta['gigtune_artist_available_now'] ?? '') === '1' ? ' checked' : '') . '> Available now</label>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Visibility</label><select name="gigtune_artist_visibility_mode" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"><option value="approx"' . ($visibilityMode === 'approx' ? ' selected' : '') . '>Show approximate area</option><option value="hidden"' . ($visibilityMode === 'hidden' ? ' selected' : '') . '>Hide location</option></select></div>';
        $html .= '<label class="inline-flex items-center gap-2 text-sm text-slate-200"><input type="checkbox" name="gigtune_artist_travel_roundtrip" value="1"' . (($meta['gigtune_artist_travel_roundtrip'] ?? '') === '1' ? ' checked' : '') . '> Travel roundtrip required</label>';
        $html .= '</div>';

        $html .= '<div><p class="mb-2 text-sm font-semibold text-slate-200">Availability days *</p><div class="flex flex-wrap gap-3">';
        foreach (['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'] as $dayValue => $dayLabel) {
            $checked = isset($daySet[$dayValue]) ? ' checked' : '';
            $html .= '<label class="inline-flex items-center gap-2 text-sm text-slate-300"><input type="checkbox" name="gigtune_artist_availability_days[]" value="' . e($dayValue) . '"' . $checked . '> ' . e($dayLabel) . '</label>';
        }
        $html .= '</div></div>';

        $html .= '<div class="grid gap-4 md:grid-cols-2">';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Start time *</label><input required type="time" name="gigtune_artist_availability_start_time" value="' . e((string) ($meta['gigtune_artist_availability_start_time'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">End time *</label><input required type="time" name="gigtune_artist_availability_end_time" value="' . e((string) ($meta['gigtune_artist_availability_end_time'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '</div>';

        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Availability slots (raw)</label><textarea name="gigtune_artist_availability_slots" rows="3" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white">' . e((string) ($meta['gigtune_artist_availability_slots'] ?? '')) . '</textarea></div>';

        $html .= '<div class="grid gap-4 md:grid-cols-3">';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Travel rate / km</label><input type="number" min="0" step="0.01" name="gigtune_artist_travel_rate_per_km" value="' . e((string) ($meta['gigtune_artist_travel_rate_per_km'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Travel free km</label><input type="number" min="0" step="1" name="gigtune_artist_travel_free_km" value="' . e((string) ($meta['gigtune_artist_travel_free_km'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Travel min fee</label><input type="number" min="0" step="0.01" name="gigtune_artist_travel_min_fee" value="' . e((string) ($meta['gigtune_artist_travel_min_fee'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '</div>';

        $html .= '<div class="grid gap-4 md:grid-cols-3">';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Accommodation preference</label><select name="gigtune_artist_accom_preference" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"><option value="none"' . ($accomPreference === 'none' ? ' selected' : '') . '>None</option><option value="recommended"' . ($accomPreference === 'recommended' ? ' selected' : '') . '>Recommended</option><option value="required"' . ($accomPreference === 'required' ? ' selected' : '') . '>Required</option></select></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Accommodation flat fee</label><input type="number" min="0" step="0.01" name="gigtune_artist_accom_fee_flat" value="' . e((string) ($meta['gigtune_artist_accom_fee_flat'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Accommodation required if end after</label><input type="time" name="gigtune_artist_accom_required_if_end_after" value="' . e((string) ($meta['gigtune_artist_accom_required_if_end_after'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Accommodation required km</label><input type="number" min="0" step="1" name="gigtune_artist_accom_required_km" value="' . e((string) ($meta['gigtune_artist_accom_required_km'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Accommodation recommended if end after</label><input type="time" name="gigtune_artist_accom_recommended_if_end_after" value="' . e((string) ($meta['gigtune_artist_accom_recommended_if_end_after'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Accommodation recommended km</label><input type="number" min="0" step="1" name="gigtune_artist_accom_recommended_km" value="' . e((string) ($meta['gigtune_artist_accom_recommended_km'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '</div>';

        $html .= '<div class="grid gap-4 md:grid-cols-2">';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Bank account name</label><input name="gigtune_artist_bank_account_name" value="' . e((string) ($meta['gigtune_artist_bank_account_name'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Bank account number</label><input name="gigtune_artist_bank_account_number" value="' . e((string) ($meta['gigtune_artist_bank_account_number'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Bank name</label><input name="gigtune_artist_bank_name" value="' . e((string) ($meta['gigtune_artist_bank_name'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Branch code</label><input name="gigtune_artist_branch_code" value="' . e($branchCode) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '</div>';

        $html .= '<div class="rounded-2xl border border-white/10 bg-white/5 p-6">';
        $html .= '<h2 class="text-lg font-semibold text-white">Demo videos</h2>';
        $html .= '<p class="text-sm text-slate-400 mt-1">You can upload demo videos or add links below.</p>';
        $html .= '<p class="text-xs text-slate-300 mt-2">Optional: add up to 5 demo videos. Duration must be 30-120 seconds.</p>';
        if ($demoVideos !== []) {
            $html .= '<div class="mt-4 space-y-3">';
            foreach ($demoVideos as $video) {
                $videoUrl = trim((string) ($video['url'] ?? ''));
                $videoRaw = trim((string) ($video['raw'] ?? $videoUrl));
                $videoLabel = trim((string) ($video['label'] ?? $videoUrl));
                if ($videoRaw === '') {
                    continue;
                }
                $html .= '<div class="rounded-xl border border-white/10 bg-black/20 p-4 space-y-3">';
                $html .= $this->renderDemoPreview($video);
                if ($videoUrl !== '') {
                    $html .= '<a href="' . e($videoUrl) . '" target="_blank" rel="noopener" class="text-sm text-slate-300 break-all hover:text-white underline">' . e($videoLabel !== '' ? $videoLabel : $videoUrl) . '</a>';
                } else {
                    $html .= '<div class="text-sm text-slate-300 break-all">' . e($videoLabel !== '' ? $videoLabel : $videoRaw) . '</div>';
                }
                $html .= '<label class="inline-flex items-center gap-2 text-xs text-slate-300"><input type="checkbox" name="gigtune_remove_demo[]" value="' . e($videoRaw) . '"> Remove</label>';
                $html .= '</div>';
            }
            $html .= '</div>';
        }
        $html .= '<div class="rounded-xl border border-white/10 bg-black/20 p-4 mt-4 space-y-3">';
        $html .= '<div><label class="block text-sm font-semibold text-slate-200 mb-2">Upload demo videos</label><input type="file" name="gigtune_demo_videos[]" multiple accept="video/*" class="block w-full text-sm text-slate-200"></div>';
        $html .= '<div><label class="block text-sm font-semibold text-slate-200 mb-2">Or add demo link</label><input type="url" name="gigtune_demo_url" placeholder="https://..." class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<div class="rounded-xl border border-white/10 bg-black/20 p-4">';
        $html .= '<p class="mb-3 text-sm font-semibold text-white">Account security</p>';
        $html .= '<div class="grid gap-4 md:grid-cols-2">';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Email address</label><input type="email" name="gigtune_account_email" value="' . e((string) ($u['email'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div class="md:col-span-2"><p class="text-xs text-slate-400">Changing email requires re-verification. Leave password fields blank if not changing password.</p></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">New password</label><input type="password" name="gigtune_account_password" autocomplete="new-password" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Confirm new password</label><input type="password" name="gigtune_account_password_confirm" autocomplete="new-password" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '</div>';
        $html .= '<div class="mt-3"><button type="submit" name="gigtune_artist_account_submit" value="1" class="inline-flex items-center justify-center rounded-lg bg-white/10 px-4 py-2 text-sm font-semibold text-white hover:bg-white/15">Save account settings</button></div>';
        $html .= '</div>';

        $html .= '<div class="flex flex-wrap gap-2"><button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Save profile</button><a class="rounded-md border border-slate-600 px-4 py-2 text-sm font-semibold text-slate-200" href="/artist-profile/?artist_id=' . (int) $profileId . '">Preview profile</a></div>';
        $html .= '</form>';
        $html .= $this->renderProfileMediaEnhancementsScript();
        return $html;
    }

    private function artistAvailability(array $a, ?array $u): string
    {
        if (!is_array($u)) {
            return '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">Sign in required.</div>';
        }
        $profileId = $this->latestUserMetaInt((int) ($u['id'] ?? 0), 'gigtune_artist_profile_id');
        if ($profileId <= 0) {
            return '<div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">No artist profile found.</div>';
        }
        return $this->artistAvailabilitySummary(['artist_id' => (string) $profileId], $u) . '<div class="mt-3"><a class="inline-flex rounded-md border border-slate-600 px-3 py-1.5 text-xs font-semibold text-slate-200" href="/artist-profile-edit/">Open profile editor</a></div>';
    }

    private function artistAvailabilitySummary(array $a, ?array $u = null, array $ctx = []): string
    {
        $request = $this->req($ctx);
        $heading = trim((string) ($a['heading'] ?? 'Artist availability'));
        if ($heading === '') {
            $heading = 'Artist availability';
        }
        $artistId = (int) ($a['artist_id'] ?? 0);
        if ($artistId <= 0) {
            $artistId = (int) ($request?->query('artist_id', 0) ?? 0);
        }
        if ($artistId <= 0) {
            $slug = trim((string) ($request?->query('artist_slug', '') ?? ''));
            if ($slug !== '') {
                $artist = $this->getArtists(['artist_slug' => $slug, 'per_page' => 1, 'paged' => 1, 'include_incomplete' => true])['items'][0] ?? null;
                $artistId = is_array($artist) ? (int) ($artist['id'] ?? 0) : 0;
            }
        }
        if ($artistId <= 0 && is_array($u)) {
            $artistId = $this->latestUserMetaInt((int) ($u['id'] ?? 0), 'gigtune_artist_profile_id');
        }
        $artist = $artistId > 0 ? $this->getArtistById($artistId) : null;
        if (!is_array($artist)) {
            return '<div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">Availability unavailable.</div>';
        }
        $days = is_array($artist['availability']['days'] ?? null) ? $artist['availability']['days'] : [];
        $dayText = $days === [] ? 'Not specified' : implode(', ', array_map(static fn ($d) => ucfirst((string) $d), $days));
        $start = trim((string) ($artist['availability']['start_time'] ?? ''));
        $end = trim((string) ($artist['availability']['end_time'] ?? ''));
        $hours = ($start !== '' || $end !== '') ? trim($start . ' - ' . $end, ' -') : 'Not specified';
        return '<div class="rounded-xl border border-white/10 bg-white/5 p-4"><h3 class="text-sm font-semibold text-white">' . e($heading) . '</h3><p class="mt-2 text-sm text-slate-300">Days: ' . e($dayText) . '</p><p class="text-sm text-slate-300">Hours: ' . e($hours) . '</p><p class="text-sm text-slate-300">Base area: ' . e((string) ($artist['availability']['base_area'] ?? 'South Africa')) . '</p></div>';
    }

    private function bookArtist(array $a, ?array $u, array $ctx = []): string
    {
        $request = $this->req($ctx);
        $artistId = (int) ($a['artist_id'] ?? 0);
        if ($artistId <= 0) {
            $artistId = (int) ($request?->query('artist_id', 0) ?? 0);
        }
        $sourcePsaId = (int) ($a['psa_id'] ?? 0);
        if ($sourcePsaId <= 0) {
            $sourcePsaId = (int) ($request?->query('psa_id', $request?->query('post_ref', 0)) ?? 0);
        }

        $success = (string) ($request?->query('booking_success', '') ?? '');
        $bookingId = (int) ($request?->query('booking_id', 0) ?? 0);
        $error = (string) ($request?->query('booking_error', '') ?? '');
        $errorMessage = trim((string) ($request?->query('booking_error_msg', '') ?? ''));
        $firstErrorField = '';
        $errorList = [];

        $values = [
            'event_date' => '',
            'event_address_street' => '',
            'event_address_suburb' => '',
            'event_address_city' => '',
            'event_address_province' => '',
            'event_address_postal_code' => '',
            'event_address_country' => 'South Africa',
            'budget' => '',
            'travel_amount' => '',
            'requires_accommodation' => '0',
            'client_offers_accommodation' => '0',
            'accommodation_note' => '',
            'notes' => '',
        ];

        $isPost = $request !== null && strtoupper((string) $request->method()) === 'POST';
        $isSubmit = $isPost && ((string) $request->input('gigtune_action', '') === 'book_artist' || (string) $request->input('gigtune_book_artist_submit', '') === '1');

        if ($isSubmit) {
            $artistIdInput = (int) $request->input('artist_id', $request->input('gigtune_booking_artist_profile_id', $artistId));
            $sourcePsaId = (int) $request->input('gigtune_source_psa_id', $sourcePsaId);
            if ($artistId <= 0) {
                $artistId = $artistIdInput;
            }

            $values['event_date'] = trim((string) $request->input('event_date', $request->input('gigtune_booking_event_date', '')));
            $values['event_address_street'] = trim((string) $request->input('event_address_street', $request->input('gigtune_booking_address_street', '')));
            $values['event_address_suburb'] = trim((string) $request->input('event_address_suburb', $request->input('gigtune_booking_address_suburb', '')));
            $values['event_address_city'] = trim((string) $request->input('event_address_city', $request->input('gigtune_booking_address_city', '')));
            $values['event_address_province'] = trim((string) $request->input('event_address_province', $request->input('gigtune_booking_address_province', '')));
            $values['event_address_postal_code'] = trim((string) $request->input('event_address_postal_code', $request->input('gigtune_booking_address_postal_code', '')));
            $values['event_address_country'] = trim((string) $request->input('event_address_country', $request->input('gigtune_booking_address_country', 'South Africa')));
            if ($values['event_address_country'] === '') {
                $values['event_address_country'] = 'South Africa';
            }
            $values['budget'] = trim((string) $request->input('budget', $request->input('gigtune_booking_budget', '')));
            $values['travel_amount'] = trim((string) $request->input('travel_amount', $request->input('gigtune_booking_travel_amount', '')));
            $values['requires_accommodation'] = (string) ($request->input('requires_accommodation', $request->input('gigtune_booking_requires_accommodation', '')) === '1' ? '1' : '0');
            $values['client_offers_accommodation'] = (string) ($request->input('client_offers_accommodation', $request->input('gigtune_booking_client_offers_accommodation', '')) === '1' ? '1' : '0');
            $values['accommodation_note'] = trim((string) $request->input('accommodation_note', $request->input('gigtune_booking_accommodation_note', '')));
            $values['notes'] = trim((string) $request->input('notes', $request->input('gigtune_booking_notes', '')));

            if (!is_array($u)) {
                $error = '1';
                $errorMessage = 'Please sign in to book an artist.';
                $errorList = [$errorMessage];
            } else {
                $userId = (int) ($u['id'] ?? 0);
                $roles = is_array($u['roles'] ?? null) ? array_map(static fn ($r): string => strtolower((string) $r), $u['roles']) : [];
                if (!in_array('gigtune_client', $roles, true)) {
                    $error = '1';
                    $errorMessage = 'Only client accounts can create booking requests.';
                    $errorList = [$errorMessage];
                } else {
                    $clientRequirements = $this->missingRequirements($userId, 'client_can_book');
                    if ($clientRequirements !== []) {
                        $error = '1';
                        $errorMessage = $this->firstMissingRequirementMessage($clientRequirements, 'Please complete your account before creating bookings.');
                        $errorList = [$errorMessage];
                    } else {
                        $clientRequestRequirements = $this->missingRequirements($userId, 'client_can_request');
                        if ($clientRequestRequirements !== []) {
                            $error = '1';
                            $errorMessage = $this->firstMissingRequirementMessage($clientRequestRequirements, 'Identity Verification (Know Your Customer Compliance) is required before creating bookings.');
                            $errorList = [$errorMessage];
                        } else {
                            $fieldErrors = [];
                            if ($artistIdInput <= 0) {
                                $fieldErrors['artist_id'] = 'Artist not selected.';
                            }
                            if ($values['event_date'] === '') {
                                $fieldErrors['event_date'] = 'Event date is required.';
                            }
                            if ($values['event_address_street'] === '' || $values['event_address_city'] === '' || $values['event_address_province'] === '' || $values['event_address_postal_code'] === '') {
                                $fieldErrors['event_location'] = 'Complete address fields are required.';
                            }
                            if ($values['event_address_postal_code'] !== '' && preg_match('/^\d{4}$/', $values['event_address_postal_code']) !== 1) {
                                $fieldErrors['address_postal_code'] = 'Postal code must be a 4-digit number.';
                            }

                            if ($fieldErrors !== []) {
                                $error = '1';
                                $errorMessage = (string) reset($fieldErrors);
                                $errorList = array_values($fieldErrors);
                                $first = (string) array_key_first($fieldErrors);
                                $firstErrorField = match ($first) {
                                    'event_date' => 'gigtune-booking-event-date',
                                    'event_location' => 'event_address_street',
                                    'address_postal_code' => 'event_address_postal_code',
                                    default => '',
                                };
                            } else {
                                $artist = $this->getArtistById($artistIdInput);
                                if (!is_array($artist)) {
                                    $error = '1';
                                    $errorMessage = 'Artist not found.';
                                    $errorList = [$errorMessage];
                                } else {
                                    $artistMeta = is_array($artist['meta'] ?? null) ? $artist['meta'] : [];
                                    $artistOwnerId = (int) ($artistMeta['gigtune_user_id'] ?? $artistMeta['gigtune_artist_user_id'] ?? 0);
                                    if ($artistOwnerId <= 0) {
                                        $artistOwnerId = (int) $this->db()->table($this->posts())->where('ID', $artistIdInput)->value('post_author');
                                    }
                                    if ($artistOwnerId > 0) {
                                        $artistRequirements = $this->missingRequirements($artistOwnerId, 'artist_can_receive_requests');
                                        if ($artistRequirements !== []) {
                                            $error = '1';
                                            $errorMessage = $this->firstMissingRequirementMessage($artistRequirements, 'The selected artist cannot receive booking requests right now.');
                                            $errorList = [$errorMessage];
                                        }
                                    }
                                    if ($error === '' && !$this->isArtistAvailableForEvent($artistIdInput, $values['event_date'])) {
                                        $error = '1';
                                        $errorMessage = 'Artist is unavailable for the selected date.';
                                        $errorList = [$errorMessage];
                                        $firstErrorField = 'gigtune-booking-event-date';
                                    }
                                    if ($error === '') {
                                        $artistPricing = is_array($artist['pricing'] ?? null) ? $artist['pricing'] : [];
                                        $artistMinimum = max(
                                            0,
                                            (int) round((float) ($artistPricing['min'] ?? ($artistMeta['gigtune_artist_price_min'] ?? 0)))
                                        );
                                        $submittedBudget = max(0, (int) $values['budget']);
                                        if ($artistMinimum > 0 && $submittedBudget < $artistMinimum) {
                                            $error = '1';
                                            $errorMessage = 'Booking budget must be at least the artist minimum price (ZAR ' . number_format($artistMinimum) . ').';
                                            $errorList = [$errorMessage];
                                            $firstErrorField = 'budget';
                                        }
                                    }
                                }
                            }

                            if ($error === '') {
                                $now = now();
                                $nowUtc = now('UTC');
                                $title = 'Booking Request - ' . ((string) ($artist['title'] ?? ('Artist #' . $artistIdInput))) . ' - ' . $now->format('Y-m-d H:i');
                                $bookingId = (int) $this->db()->table($this->posts())->insertGetId([
                                    'post_author' => (int) ($u['id'] ?? 0),
                                    'post_date' => $now->format('Y-m-d H:i:s'),
                                    'post_date_gmt' => $nowUtc->format('Y-m-d H:i:s'),
                                    'post_content' => $values['notes'],
                                    'post_title' => $title,
                                    'post_status' => 'publish',
                                    'comment_status' => 'closed',
                                    'ping_status' => 'closed',
                                    'post_name' => 'booking-request-' . $artistIdInput . '-' . $now->format('YmdHis') . '-' . random_int(100, 999),
                                    'post_modified' => $now->format('Y-m-d H:i:s'),
                                    'post_modified_gmt' => $nowUtc->format('Y-m-d H:i:s'),
                                    'post_type' => 'gigtune_booking',
                                ]);

                                $locationText = implode(', ', array_values(array_filter([
                                    $values['event_address_street'],
                                    $values['event_address_suburb'],
                                    $values['event_address_city'],
                                    $values['event_address_province'],
                                    $values['event_address_postal_code'],
                                    $values['event_address_country'],
                                ], static fn ($v): bool => trim((string) $v) !== '')));

                                $requestedTs = $now->timestamp;
                                $expiryTs = $now->copy()->addHours(72)->timestamp;
                                $budgetInt = max(0, (int) $values['budget']);

                                $this->upsertPostMeta($bookingId, 'gigtune_booking_id', (string) $bookingId);
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_artist_profile_id', (string) $artistIdInput);
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_client_user_id', (string) (int) ($u['id'] ?? 0));
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_status', 'REQUESTED');
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_requested_at', $now->format('Y-m-d H:i:s'));
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_request_expires_at', (string) $expiryTs);
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_event_date', $values['event_date']);
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_location_text', $locationText);
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_address_street', $values['event_address_street']);
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_address_suburb', $values['event_address_suburb']);
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_address_city', $values['event_address_city']);
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_address_province', $values['event_address_province']);
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_address_postal_code', $values['event_address_postal_code']);
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_address_country', $values['event_address_country']);
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_notes', $values['notes']);
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_requires_accommodation', $values['requires_accommodation']);
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_client_offers_accommodation', $values['client_offers_accommodation']);
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_accommodation_note', $values['accommodation_note']);
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_travel_amount', $values['travel_amount']);
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_created_at', (string) $requestedTs);
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_quote_accepted', '0');
                                $this->upsertPostMeta($bookingId, 'gigtune_payment_status', 'UNPAID');
                                $this->upsertPostMeta($bookingId, 'gigtune_payout_status', 'PENDING_MANUAL');
                                $this->upsertPostMeta($bookingId, 'gigtune_escrow_status', 'UNFUNDED');
                                $this->upsertPostMeta($bookingId, 'gigtune_escrow_amount', '0');
                                $this->upsertPostMeta($bookingId, 'gigtune_dispute_raised', '0');
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_currency', 'ZAR');
                                if ($sourcePsaId > 0) {
                                    $this->upsertPostMeta($bookingId, 'gigtune_booking_source_psa_id', (string) $sourcePsaId);
                                }
                                if ($budgetInt > 0) {
                                    $this->upsertPostMeta($bookingId, 'gigtune_booking_budget', (string) $budgetInt);
                                    $this->upsertPostMeta($bookingId, 'gigtune_booking_quote_amount', (string) $budgetInt);
                                    $this->upsertPostMeta($bookingId, 'gigtune_booking_quote_snapshot', serialize(['amount' => $budgetInt, 'currency' => 'ZAR']));
                                }
                                $eventTs = strtotime($values['event_date']);
                                if ($eventTs !== false && $eventTs > 0) {
                                    $this->upsertPostMeta($bookingId, 'gigtune_booking_start_time', date('H:i', $eventTs));
                                    $this->upsertPostMeta($bookingId, 'gigtune_booking_end_time', date('H:i', $eventTs));
                                }
                                $this->upsertPostMeta($bookingId, 'gigtune_booking_state_log', serialize([
                                    ['event' => 'requested', 'at' => $requestedTs, 'actor_user_id' => (int) ($u['id'] ?? 0)],
                                ]));

                                $artistMeta = is_array($artist['meta'] ?? null) ? $artist['meta'] : [];
                                $artistOwnerId = (int) ($artistMeta['gigtune_user_id'] ?? $artistMeta['gigtune_artist_user_id'] ?? 0);
                                if ($artistOwnerId <= 0) {
                                    $artistOwnerId = (int) $this->db()->table($this->posts())->where('ID', $artistIdInput)->value('post_author');
                                }
                                $artistName = trim((string) ($artist['title'] ?? 'Artist'));
                                if ($artistOwnerId > 0) {
                                    $this->createNotification(
                                        $artistOwnerId,
                                        'booking',
                                        'New booking request #' . $bookingId . ' from client user #' . (int) ($u['id'] ?? 0) . ' for ' . ($artistName !== '' ? $artistName : ('artist #' . $artistIdInput)) . '.',
                                        ['object_type' => 'booking', 'object_id' => $bookingId, 'artist_profile_id' => $artistIdInput]
                                    );
                                }
                                $this->createNotification(
                                    (int) ($u['id'] ?? 0),
                                    'booking',
                                    'Booking request #' . $bookingId . ' submitted successfully. The artist has been notified.',
                                    ['object_type' => 'booking', 'object_id' => $bookingId, 'artist_profile_id' => $artistIdInput]
                                );
                                $sourceIsPsa = $sourcePsaId > 0 && $this->db()->table($this->posts())
                                    ->where('ID', $sourcePsaId)
                                    ->where('post_type', 'gigtune_psa')
                                    ->exists();
                                if ($sourceIsPsa) {
                                    $psaClientUserId = (int) $this->getLatestPostMeta($sourcePsaId, 'gigtune_psa_client_user_id');
                                    if ($psaClientUserId <= 0 || $psaClientUserId === (int) ($u['id'] ?? 0)) {
                                        $this->upsertPostMeta($sourcePsaId, 'gigtune_psa_status', 'closed');
                                        $this->upsertPostMeta($sourcePsaId, 'gigtune_psa_closed_at', (string) $requestedTs);
                                        $this->upsertPostMeta($sourcePsaId, 'gigtune_psa_closed_by_booking_id', (string) $bookingId);
                                        if ($psaClientUserId > 0) {
                                            $this->createNotification(
                                                $psaClientUserId,
                                                'booking',
                                                'Client post #' . $sourcePsaId . ' was automatically closed after booking #' . $bookingId . ' was created.',
                                                ['object_type' => 'psa', 'object_id' => $sourcePsaId]
                                            );
                                        }
                                    }
                                }

                                $success = '1';
                                $error = '';
                                $errorMessage = '';
                                $errorList = [];
                                $firstErrorField = '';
                                $artistId = $artistIdInput;
                                $values = [
                                    'event_date' => '',
                                    'event_address_street' => '',
                                    'event_address_suburb' => '',
                                    'event_address_city' => '',
                                    'event_address_province' => '',
                                    'event_address_postal_code' => '',
                                    'event_address_country' => 'South Africa',
                                    'budget' => '',
                                    'travel_amount' => '',
                                    'requires_accommodation' => '0',
                                    'client_offers_accommodation' => '0',
                                    'accommodation_note' => '',
                                    'notes' => '',
                                ];
                            }
                        }
                    }
                }
            }
        }

        $html = '';
        if ($success === '1' && $bookingId > 0) {
            $html .= '<div class="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-4 mb-6"><p class="text-emerald-200 font-semibold">Booking request sent.</p><p class="text-emerald-200/80 text-sm mt-1">Booking ID: ' . e((string) $bookingId) . '</p></div>';
        }
        if ($error === '1') {
            $html .= '<div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4 mb-6"><p class="text-rose-200 font-semibold">Booking request failed.</p>';
            if ($errorMessage !== '') {
                $html .= '<p class="text-rose-200/80 text-sm mt-1">' . e($errorMessage) . '</p>';
            }
            if (count($errorList) > 1) {
                $html .= '<ul class="mt-2 text-rose-200/90 text-sm list-disc pl-5">';
                foreach ($errorList as $err) {
                    $err = trim((string) $err);
                    if ($err !== '') {
                        $html .= '<li>' . e($err) . '</li>';
                    }
                }
                $html .= '</ul>';
            }
            $html .= '</div>';
        }

        if ($artistId <= 0) {
            $artists = $this->getArtists(['per_page' => 24, 'paged' => 1])['items'];
            $html .= '<div class="rounded-2xl border border-white/10 bg-white/5 p-6">';
            $html .= '<h2 class="text-xl md:text-2xl font-bold text-white mb-2">Choose an artist</h2>';
            $html .= '<p class="text-slate-300/80 mb-6">Select an artist below to start your booking request.</p>';
            if ($artists === []) {
                return $html . '<p class="text-slate-300/80">No artists found yet.</p></div>';
            }
            $html .= '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">';
            foreach ($artists as $artist) {
                $id = (int) ($artist['id'] ?? 0);
                $name = trim((string) ($artist['title'] ?? ''));
                if ($name === '') {
                    $name = 'Artist #' . $id;
                }
                $html .= '<div class="rounded-2xl border border-white/10 bg-slate-900/40 p-5"><div class="flex items-start justify-between gap-4"><div><p class="text-white font-semibold text-lg">' . e($name) . '</p><p class="text-slate-300/70 text-sm mt-1">Artist ID: ' . e((string) $id) . '</p></div><a class="inline-flex items-center justify-center rounded-xl bg-indigo-600 hover:bg-indigo-500 px-4 py-2 text-white font-semibold text-sm" href="' . e($this->bookArtistFormUrl($id)) . '">Book</a></div></div>';
            }
            return $html . '</div></div>';
        }

        $artist = $this->getArtistById($artistId);
        if (!is_array($artist)) {
            return $html . '<div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4"><p class="text-rose-200 font-semibold">Artist not found.</p></div>';
        }

        $artistName = trim((string) ($artist['title'] ?? ''));
        if ($artistName === '') {
            $artistName = 'Artist #' . $artistId;
        }

        $html .= '<div class="rounded-2xl border border-white/10 bg-white/5 p-6">';
        $html .= '<div class="mb-6"><h2 class="text-xl md:text-2xl font-bold text-white">Booking request</h2><p class="text-slate-300/80 mt-1">You are booking: <span class="text-white font-semibold">' . e($artistName) . '</span> <span class="text-slate-300/70 text-sm">(ID: ' . e((string) $artistId) . ')</span></p></div>';
        $html .= '<div class="mb-6">' . $this->artistAvailabilitySummary(['artist_id' => (string) $artistId, 'heading' => 'Artist availability'], $u, $ctx) . '</div>';

        if (!is_array($u)) {
            return $html . '<div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4"><p class="text-amber-200 font-semibold">Please log in to submit a booking request.</p></div></div>';
        }
        $roles = is_array($u['roles'] ?? null) ? array_map(static fn ($r): string => strtolower((string) $r), $u['roles']) : [];
        if (!in_array('gigtune_client', $roles, true)) {
            return $html . '<div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4"><p class="text-amber-200 font-semibold">Only client accounts can create booking requests.</p></div></div>';
        }

        $provinces = $this->saProvinces();
        $html .= '<form method="post" action="' . e($this->bookArtistFormUrl($artistId)) . '" class="space-y-4">';
        $html .= '<input type="hidden" name="gigtune_book_artist_submit" value="1"><input type="hidden" name="gigtune_action" value="book_artist"><input type="hidden" name="artist_id" value="' . e((string) $artistId) . '"><input type="hidden" name="gigtune_source_psa_id" value="' . e((string) max(0, $sourcePsaId)) . '">';
        $html .= '<style>.gigtune-site-select,.gigtune-site-select option{background-color:#020617;color:#e2e8f0;}</style>';
        $html .= '<div><label class="block text-sm font-semibold text-slate-200 mb-2">Event date &amp; time <span class="text-xs text-rose-300">Required</span></label><div data-role="gigtune-datetime-wrap" class="rounded-xl bg-slate-950/30 border border-white/10 p-1 cursor-pointer"><input id="gigtune-booking-event-date" data-role="gigtune-datetime-input" type="datetime-local" name="event_date" required value="' . e($values['event_date']) . '" class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div></div>';
        $html .= '<div class="space-y-3"><label class="block text-sm font-semibold text-slate-200 mb-1">Event location / address <span class="text-xs text-rose-300">Required</span></label><div class="grid gap-3 md:grid-cols-2">';
        $html .= '<div><label class="block text-xs text-slate-300 mb-1">Street address <span class="text-rose-300">Required</span></label><input id="event_address_street" type="text" name="event_address_street" required value="' . e($values['event_address_street']) . '" class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="block text-xs text-slate-300 mb-1">Suburb</label><input id="event_address_suburb" type="text" name="event_address_suburb" value="' . e($values['event_address_suburb']) . '" class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="block text-xs text-slate-300 mb-1">City / Town <span class="text-rose-300">Required</span></label><input id="event_address_city" type="text" name="event_address_city" required value="' . e($values['event_address_city']) . '" class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="block text-xs text-slate-300 mb-1">Province <span class="text-rose-300">Required</span></label><select id="event_address_province" name="event_address_province" required class="gigtune-site-select w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"><option value="">Select province</option>';
        foreach ($provinces as $province) {
            $selected = $values['event_address_province'] === $province ? ' selected' : '';
            $html .= '<option value="' . e($province) . '"' . $selected . '>' . e($province) . '</option>';
        }
        $html .= '</select></div>';
        $html .= '<div><label class="block text-xs text-slate-300 mb-1">Postal code <span class="text-rose-300">Required</span></label><input id="event_address_postal_code" type="text" name="event_address_postal_code" required value="' . e($values['event_address_postal_code']) . '" class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="block text-xs text-slate-300 mb-1">Country <span class="text-rose-300">Required</span></label><select id="event_address_country" name="event_address_country" required class="gigtune-site-select w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"><option value="South Africa">South Africa</option></select></div>';
        $html .= '</div></div>';
        $html .= '<div><label class="block text-sm font-semibold text-slate-200 mb-2">Budget (ZAR)</label><input id="budget" type="number" min="0" step="1" name="budget" value="' . e($values['budget']) . '" placeholder="e.g. 2500" class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="block text-sm font-semibold text-slate-200 mb-2">Travel amount</label><input id="travel_amount" type="number" min="0" step="0.01" name="travel_amount" value="' . e($values['travel_amount']) . '" class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div>';
        $html .= '<div class="grid gap-3 md:grid-cols-2">';
        $html .= '<label class="inline-flex items-center gap-2 text-sm text-slate-200"><input type="checkbox" name="requires_accommodation" value="1"' . ($values['requires_accommodation'] === '1' ? ' checked' : '') . '> Requires accommodation</label>';
        $html .= '<label class="inline-flex items-center gap-2 text-sm text-slate-200"><input type="checkbox" name="client_offers_accommodation" value="1"' . ($values['client_offers_accommodation'] === '1' ? ' checked' : '') . '> Client offers accommodation</label>';
        $html .= '</div>';
        $html .= '<div><label class="block text-sm font-semibold text-slate-200 mb-2">Accommodation note</label><textarea id="accommodation_note" name="accommodation_note" rows="2" class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white">' . e($values['accommodation_note']) . '</textarea></div>';
        $html .= '<div><label class="block text-sm font-semibold text-slate-200 mb-2">Notes</label><textarea id="notes" name="notes" rows="5" placeholder="Tell the artist what you need..." class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white">' . e($values['notes']) . '</textarea></div>';
        $html .= '<div class="pt-2"><button type="submit" class="inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500 transition shadow">Send booking request</button></div>';
        $html .= '</form>';

        $firstErrorJson = json_encode($firstErrorField, JSON_UNESCAPED_SLASHES);
        if (!is_string($firstErrorJson)) {
            $firstErrorJson = '""';
        }
        $html .= '<script>(function(){var wrap=document.querySelector("[data-role=\\"gigtune-datetime-wrap\\"]");var input=document.querySelector("[data-role=\\"gigtune-datetime-input\\"]");if(!input){return;}var openPicker=function(){if(typeof input.showPicker==="function"){try{input.showPicker();}catch(e){}}};if(wrap){wrap.addEventListener("click",function(e){if(e.target!==input){input.focus();}openPicker();});}input.addEventListener("focus",openPicker);var firstError=' . $firstErrorJson . ';if(firstError){var t=document.getElementById(firstError)||document.querySelector("[name=\""+firstError+"\"]");if(t){try{t.scrollIntoView({behavior:"smooth",block:"center"});}catch(e){}try{t.focus();}catch(e2){}}}})();</script>';

        return $html . '</div>';
    }
    private function messages(array $a, ?array $u, array $ctx = []): string
    {
        if (!is_array($u)) {
            return '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">Sign in required.</div>';
        }

        $uid = (int) ($u['id'] ?? 0);
        $isAdmin = (bool) ($u['is_admin'] ?? false);
        $request = $this->req($ctx);
        $bookingId = (int) ($request?->query('booking_id', 0) ?? 0);

        if ($request !== null) {
            $markReadId = abs((int) $request->query('notification_id', 0));
            if ($markReadId > 0) {
                try {
                    $this->notifications->markRead($markReadId, $uid, $isAdmin);
                } catch (\Throwable) {
                    // Non-blocking read marker.
                }
            }
        }

        if ($bookingId > 0) {
            $bookingPost = $this->db()->table($this->posts())
                ->where('ID', $bookingId)
                ->where('post_type', 'gigtune_booking')
                ->first(['ID', 'post_title', 'post_content', 'post_date']);
            if ($bookingPost === null) {
                return '<div class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">Booking not found.</div>';
            }

            $bookingMeta = $this->postMetaMap([$bookingId], [
                'gigtune_booking_status',
                'gigtune_payment_status',
                'gigtune_payout_status',
                'gigtune_refund_status',
                'gigtune_dispute_raised',
                'gigtune_booking_artist_profile_id',
                'gigtune_booking_client_user_id',
                'gigtune_booking_event_date',
                'gigtune_booking_location_text',
                'gigtune_booking_requested_at',
                'gigtune_booking_request_expires_at',
                'gigtune_booking_responded_at',
                'gigtune_booking_reject_reason',
                'gigtune_booking_locked',
                'gigtune_booking_budget',
                'gigtune_booking_quote_amount',
                'gigtune_booking_requires_accommodation',
                'gigtune_booking_client_offers_accommodation',
                'gigtune_payment_accommodation_fee',
                'gigtune_payment_method',
                'gigtune_payment_reported_at',
                'gigtune_payment_window_expires_at',
                'gigtune_payment_reference_human',
                'gigtune_client_rating_submitted',
                'gigtune_artist_rating_submitted',
                'gigtune_booking_completed_by_artist_at',
                'gigtune_booking_completed_confirmed_at',
            ])[$bookingId] ?? [];

            $clientUserId = (int) ($bookingMeta['gigtune_booking_client_user_id'] ?? 0);
            $artistProfileId = (int) ($bookingMeta['gigtune_booking_artist_profile_id'] ?? 0);
            $artistOwnerId = 0;
            if ($artistProfileId > 0) {
                $artistProfileMeta = $this->postMetaMap([$artistProfileId], ['gigtune_artist_user_id', 'gigtune_user_id'])[$artistProfileId] ?? [];
                $artistOwnerId = (int) ($artistProfileMeta['gigtune_user_id'] ?? $artistProfileMeta['gigtune_artist_user_id'] ?? 0);
                if ($artistOwnerId <= 0) {
                    $artistOwnerId = (int) $this->db()->table($this->posts())->where('ID', $artistProfileId)->value('post_author');
                }
            }

            $isClientOwner = $clientUserId > 0 && $uid === $clientUserId;
            $isArtistOwner = $artistOwnerId > 0 && $uid === $artistOwnerId;
            if (!$isAdmin && !$isClientOwner && !$isArtistOwner) {
                return '<div class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">Access denied for this booking thread.</div>';
            }

            $statusMessage = '';
            $errorMessage = '';
            $redirectToYoco = '';
            if (
                $request !== null
                && strtoupper((string) $request->method()) === 'POST'
                && (string) $request->input('gigtune_booking_action_submit', '') === '1'
            ) {
                $nonce = trim((string) $request->input('gigtune_booking_action_nonce', ''));
                $action = trim((string) $request->input('gigtune_booking_action', ''));
                $currentStatus = strtoupper(trim((string) ($bookingMeta['gigtune_booking_status'] ?? '')));
                $paymentStatus = strtoupper(trim((string) ($bookingMeta['gigtune_payment_status'] ?? '')));
                $paymentWindowExpiresAt = (int) ($bookingMeta['gigtune_payment_window_expires_at'] ?? 0);
                $paymentWindowExpired = $paymentWindowExpiresAt > 0 && $paymentWindowExpiresAt < time();
                $nowTs = (string) now()->timestamp;
                $nowMysql = now()->format('Y-m-d H:i:s');
                $paymentWindowHours = 24;
                $rejectReason = trim((string) $request->input('gigtune_booking_reject_reason', ''));
                $disputeSubject = trim((string) $request->input('gigtune_dispute_subject', ''));
                $disputeBody = trim((string) $request->input('gigtune_dispute_text', ''));
                $threadMessage = trim((string) $request->input('gigtune_booking_thread_message', ''));
                $threadSubject = trim((string) $request->input('gigtune_booking_thread_subject', ''));
                $ratePunctuality = abs((int) $request->input('gigtune_rate_punctuality', 0));
                $ratePerformanceQuality = abs((int) $request->input('gigtune_rate_performance_quality', 0));
                $rateCharacter = abs((int) $request->input('gigtune_rate_character', 0));
                $rateCompletionSpeed = abs((int) $request->input('gigtune_rate_completion_speed', 0));
                $rateProfessionalism = abs((int) $request->input('gigtune_rate_professionalism', 0));
                $rateWorkingConditions = abs((int) $request->input('gigtune_rate_working_conditions', 0));
                $rateClientCharacter = abs((int) $request->input('gigtune_rate_client_character', 0));
                $disputeWindowDeadlineTs = $this->bookingDisputeDeadlineTimestamp((string) ($bookingMeta['gigtune_booking_event_date'] ?? ''));
                $disputeWindowClosed = $disputeWindowDeadlineTs > 0 && time() > $disputeWindowDeadlineTs;

                if (!$this->verifyWpNonce($nonce, 'gigtune_booking_action')) {
                    $errorMessage = 'Security check failed.';
                } elseif ($action === 'accept') {
                    if (!$isArtistOwner && !$isAdmin) {
                        $errorMessage = 'Only the assigned artist can accept this request.';
                    } elseif ($currentStatus !== 'REQUESTED') {
                        $errorMessage = 'Booking request can only be accepted while pending.';
                    } else {
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_status', 'ACCEPTED_PENDING_PAYMENT');
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_accepted_at', $nowTs);
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_responded_at', $nowMysql);
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_reject_reason', '');
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_last_actor_user_id', (string) $uid);
                        $this->upsertPostMeta($bookingId, 'gigtune_payment_status', 'UNPAID');
                        $this->upsertPostMeta($bookingId, 'gigtune_payment_window_expires_at', (string) (time() + ($paymentWindowHours * 3600)));
                        $this->upsertPostMeta($bookingId, 'gigtune_escrow_status', 'UNFUNDED');
                        if ($clientUserId > 0) {
                            $this->createNotification($clientUserId, 'booking', 'Booking #' . $bookingId . ' was accepted by the artist and is awaiting payment.', ['object_type' => 'booking', 'object_id' => $bookingId]);
                        }
                        $statusMessage = 'Booking accepted.';
                    }
                } elseif ($action === 'decline') {
                    if (!$isArtistOwner && !$isAdmin) {
                        $errorMessage = 'Only the assigned artist can decline this request.';
                    } elseif ($currentStatus !== 'REQUESTED') {
                        $errorMessage = 'Booking request can only be declined while pending.';
                    } else {
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_status', 'REJECTED');
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_declined_at', $nowTs);
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_responded_at', $nowMysql);
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_reject_reason', $rejectReason);
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_last_actor_user_id', (string) $uid);
                        $this->upsertPostMeta($bookingId, 'gigtune_payment_status', 'UNPAID');
                        $this->upsertPostMeta($bookingId, 'gigtune_escrow_status', 'UNFUNDED');
                        if ($clientUserId > 0) {
                            $this->createNotification($clientUserId, 'booking', 'Booking #' . $bookingId . ' was declined by the artist.', ['object_type' => 'booking', 'object_id' => $bookingId]);
                        }
                        $statusMessage = 'Booking declined.';
                    }
                } elseif ($action === 'pay_yoco') {
                    if (!$isClientOwner && !$isAdmin) {
                        $errorMessage = 'Only the requesting client can initiate card checkout.';
                    } elseif ($currentStatus !== 'ACCEPTED_PENDING_PAYMENT') {
                        $errorMessage = 'Booking is not in a payable state.';
                    } elseif ($paymentWindowExpired) {
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_status', 'PAYMENT_TIMEOUT');
                        $this->upsertPostMeta($bookingId, 'gigtune_payment_status', 'FAILED');
                        $this->upsertPostMeta($bookingId, 'gigtune_payment_last_note', 'Payment window expired.');
                        $errorMessage = 'Payment window expired. Please contact support.';
                    } elseif (in_array($paymentStatus, ['AWAITING_PAYMENT_CONFIRMATION', 'CONFIRMED_HELD_PENDING_COMPLETION', 'PAID_ESCROWED', 'ESCROW_FUNDED'], true)) {
                        $errorMessage = 'Payment has already been submitted or confirmed.';
                    } else {
                        $referenceHuman = $this->bookingPaymentReferenceHuman($bookingId, $clientUserId);
                        $amountCents = $this->bookingYocoAmountCents($bookingId);
                        if ($amountCents <= 0) {
                            $safeError = 'Invalid amount.';
                            $this->upsertPostMeta($bookingId, 'gigtune_yoco_last_error', $safeError);
                            $errorMessage = $safeError;
                        } else {
                            $checkout = $this->createYocoCheckout($bookingId, $amountCents);
                            if (isset($checkout['error'])) {
                                $safeError = trim((string) $checkout['error']);
                                if ($safeError === '') {
                                    $safeError = 'Card checkout is currently unavailable.';
                                }
                                $this->upsertPostMeta($bookingId, 'gigtune_yoco_last_error', $safeError);
                                $errorMessage = $safeError;
                            } else {
                                $checkoutId = trim((string) ($checkout['checkout_id'] ?? ''));
                                $redirectUrl = trim((string) ($checkout['redirect_url'] ?? ''));
                                if ($checkoutId === '' || $redirectUrl === '') {
                                    $this->upsertPostMeta($bookingId, 'gigtune_yoco_last_error', 'Invalid checkout response.');
                                    $errorMessage = 'Card checkout is currently unavailable.';
                                } else {
                                    $this->upsertPostMeta($bookingId, 'gigtune_payment_method', 'yoco');
                                    $this->upsertPostMeta($bookingId, 'gigtune_payment_reference_human', $referenceHuman);
                                    $this->upsertPostMeta($bookingId, 'gigtune_yoco_checkout_id', $checkoutId);
                                    $this->upsertPostMeta($bookingId, 'gigtune_yoco_redirect_url', $redirectUrl);
                                    $this->upsertPostMeta($bookingId, 'gigtune_yoco_last_error', '');
                                    $redirectToYoco = $redirectUrl;
                                    $statusMessage = 'Redirecting to secure card checkout.';
                                }
                            }
                        }
                    }
                } elseif ($action === 'payment_confirm') {
                    if (!$isAdmin) {
                        $errorMessage = 'Admin permissions required.';
                    } elseif (!in_array($paymentStatus, ['AWAITING_PAYMENT_CONFIRMATION', 'UNPAID', 'REJECTED_PAYMENT'], true)) {
                        $errorMessage = 'Payment is not in a reviewable state.';
                    } else {
                        $this->upsertPostMeta($bookingId, 'gigtune_payment_status', 'CONFIRMED_HELD_PENDING_COMPLETION');
                        $this->upsertPostMeta($bookingId, 'gigtune_payment_confirmed_at', $nowTs);
                        $this->upsertPostMeta($bookingId, 'gigtune_escrow_status', 'FUNDED');
                        if ($currentStatus === 'ACCEPTED_PENDING_PAYMENT') {
                            $this->upsertPostMeta($bookingId, 'gigtune_booking_status', 'PAID_ESCROWED');
                        }
                        if ($clientUserId > 0) {
                            $this->createNotification($clientUserId, 'payment', 'Payment confirmed for booking #' . $bookingId . '.', ['object_type' => 'booking', 'object_id' => $bookingId]);
                        }
                        if ($artistOwnerId > 0) {
                            $this->createNotification($artistOwnerId, 'payment', 'Payment confirmed for booking #' . $bookingId . '. You may proceed with performance.', ['object_type' => 'booking', 'object_id' => $bookingId]);
                        }
                        $statusMessage = 'Payment confirmed.';
                    }
                } elseif ($action === 'payment_reject') {
                    if (!$isAdmin) {
                        $errorMessage = 'Admin permissions required.';
                    } elseif (!in_array($paymentStatus, ['AWAITING_PAYMENT_CONFIRMATION', 'UNPAID', 'CONFIRMED_HELD_PENDING_COMPLETION'], true)) {
                        $errorMessage = 'Payment is not in a rejectable state.';
                    } else {
                        $this->upsertPostMeta($bookingId, 'gigtune_payment_status', 'REJECTED_PAYMENT');
                        $this->upsertPostMeta($bookingId, 'gigtune_payment_last_reviewed_at', $nowTs);
                        $this->upsertPostMeta($bookingId, 'gigtune_payment_last_reviewed_by', (string) $uid);
                        if ($clientUserId > 0) {
                            $this->createNotification($clientUserId, 'payment', 'Payment report for booking #' . $bookingId . ' was rejected. Please re-submit or contact support.', ['object_type' => 'booking', 'object_id' => $bookingId]);
                        }
                        $statusMessage = 'Payment rejected.';
                    }
                } elseif ($action === 'mark_completed') {
                    if (!$isArtistOwner && !$isAdmin) {
                        $errorMessage = 'Only the assigned artist can mark this booking completed.';
                    } elseif ($currentStatus !== 'PAID_ESCROWED') {
                        $errorMessage = 'Booking can only be marked completed after payment confirmation.';
                    } elseif (!in_array($paymentStatus, ['CONFIRMED_HELD_PENDING_COMPLETION', 'ESCROW_FUNDED', 'PAID_ESCROWED'], true)) {
                        $errorMessage = 'Payment must be confirmed before completion.';
                    } else {
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_status', 'COMPLETED_BY_ARTIST');
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_completed_by_artist_at', $nowTs);
                        if ($clientUserId > 0) {
                            $this->createNotification($clientUserId, 'booking', 'Artist marked booking #' . $bookingId . ' as completed. Please confirm completion.', ['object_type' => 'booking', 'object_id' => $bookingId]);
                        }
                        $statusMessage = 'Booking marked completed. Awaiting client confirmation.';
                    }
                } elseif ($action === 'confirm_completion') {
                    if (!$isClientOwner && !$isAdmin) {
                        $errorMessage = 'Only the requesting client can confirm completion.';
                    } elseif ($currentStatus !== 'COMPLETED_BY_ARTIST') {
                        $errorMessage = 'Completion can only be confirmed after the artist marks completed.';
                    } else {
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_status', 'COMPLETED_CONFIRMED');
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_completed_confirmed_at', $nowTs);
                        if (strtoupper(trim((string) ($bookingMeta['gigtune_payout_status'] ?? ''))) === '') {
                            $this->upsertPostMeta($bookingId, 'gigtune_payout_status', 'PENDING');
                        }
                        if ($artistOwnerId > 0) {
                            $this->createNotification($artistOwnerId, 'booking', 'Client confirmed completion for booking #' . $bookingId . '.', ['object_type' => 'booking', 'object_id' => $bookingId]);
                        }
                        foreach ($this->adminUserIds() as $adminUserId) {
                            $this->createNotification($adminUserId, 'payout', 'Booking #' . $bookingId . ' completed and ready for payout review.', ['object_type' => 'booking', 'object_id' => $bookingId]);
                        }
                        $statusMessage = 'Completion confirmed.';
                    }
                } elseif ($action === 'cancel') {
                    if (!$isClientOwner && !$isAdmin) {
                        $errorMessage = 'Only the requesting client can cancel this booking.';
                    } elseif (!in_array($currentStatus, ['REQUESTED', 'ACCEPTED_PENDING_PAYMENT', 'PAID_ESCROWED', 'AWAITING_PAYMENT_CONFIRMATION'], true)) {
                        $errorMessage = 'Booking cannot be cancelled in its current state.';
                    } else {
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_status', 'CANCELLED_BY_CLIENT');
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_cancelled_at', $nowTs);
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_last_actor_user_id', (string) $uid);
                        if (in_array($paymentStatus, ['PAID_ESCROWED', 'PAID', 'AWAITING_PAYMENT_CONFIRMATION', 'CONFIRMED_HELD_PENDING_COMPLETION', 'REFUNDED_PARTIAL', 'REFUNDED_FULL'], true)) {
                            $this->upsertPostMeta($bookingId, 'gigtune_refund_status', 'REQUESTED');
                            $this->upsertPostMeta($bookingId, 'gigtune_booking_locked', 'refund_pending');
                            $this->upsertPostMeta($bookingId, 'gigtune_refund_requested_by', 'client');
                            $this->upsertPostMeta($bookingId, 'gigtune_refund_requested_at', $nowTs);
                        }
                        if ($artistOwnerId > 0) {
                            $this->createNotification($artistOwnerId, 'booking', 'Booking #' . $bookingId . ' was cancelled by the client.', ['object_type' => 'booking', 'object_id' => $bookingId]);
                        }
                        $statusMessage = 'Booking cancelled.';
                    }
                } elseif ($action === 'cancel_artist') {
                    if (!$isArtistOwner && !$isAdmin) {
                        $errorMessage = 'Only the assigned artist can cancel this booking.';
                    } elseif (!in_array($currentStatus, ['REQUESTED', 'ACCEPTED_PENDING_PAYMENT', 'PAID_ESCROWED', 'AWAITING_PAYMENT_CONFIRMATION'], true)) {
                        $errorMessage = 'Booking cannot be cancelled in its current state.';
                    } else {
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_status', 'CANCELLED_BY_ARTIST');
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_cancelled_at', $nowTs);
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_last_actor_user_id', (string) $uid);
                        if (in_array($paymentStatus, ['PAID_ESCROWED', 'PAID', 'AWAITING_PAYMENT_CONFIRMATION', 'CONFIRMED_HELD_PENDING_COMPLETION', 'REFUNDED_PARTIAL', 'REFUNDED_FULL'], true)) {
                            $this->upsertPostMeta($bookingId, 'gigtune_refund_status', 'REQUESTED');
                            $this->upsertPostMeta($bookingId, 'gigtune_booking_locked', 'refund_pending');
                            $this->upsertPostMeta($bookingId, 'gigtune_refund_requested_by', 'artist');
                            $this->upsertPostMeta($bookingId, 'gigtune_refund_requested_at', $nowTs);
                        }
                        if ($clientUserId > 0) {
                            $this->createNotification($clientUserId, 'booking', 'Booking #' . $bookingId . ' was cancelled by the artist.', ['object_type' => 'booking', 'object_id' => $bookingId]);
                        }
                        $statusMessage = 'Booking cancelled.';
                    }
                } elseif ($action === 'raise_dispute') {
                    if (!$isClientOwner && !$isArtistOwner && !$isAdmin) {
                        $errorMessage = 'Only booking participants can raise a dispute.';
                    } elseif ((string) ($bookingMeta['gigtune_dispute_raised'] ?? '') === '1') {
                        $errorMessage = 'A dispute is already open for this booking.';
                    } elseif ($disputeWindowClosed) {
                        $errorMessage = 'Dispute window has closed for this booking.';
                    } elseif ($disputeSubject === '' || $disputeBody === '') {
                        $errorMessage = 'Dispute subject and details are required.';
                    } else {
                        $ts = now();
                        $this->upsertPostMeta($bookingId, 'gigtune_dispute_raised', '1');
                        $this->upsertPostMeta($bookingId, 'gigtune_dispute_raised_at', (string) $ts->timestamp);
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_status', 'DISPUTE_OPEN');
                        $this->upsertPostMeta($bookingId, 'gigtune_dispute_opened_at', (string) $ts->timestamp);

                        $disputeId = (int) $this->db()->table($this->posts())->insertGetId([
                            'post_author' => $uid,
                            'post_date' => $ts->format('Y-m-d H:i:s'),
                            'post_date_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                            'post_content' => $disputeBody,
                            'post_title' => $disputeSubject,
                            'post_status' => 'publish',
                            'comment_status' => 'closed',
                            'ping_status' => 'closed',
                            'post_name' => 'gigtune-dispute-' . $bookingId . '-' . $ts->format('YmdHis'),
                            'post_modified' => $ts->format('Y-m-d H:i:s'),
                            'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                            'post_type' => 'gigtune_dispute',
                        ]);
                        if ($disputeId > 0) {
                            $initiatorRole = $isClientOwner ? 'client' : ($isArtistOwner ? 'artist' : 'admin');
                            $this->upsertPostMeta($disputeId, 'gigtune_dispute_booking_id', (string) $bookingId);
                            $this->upsertPostMeta($disputeId, 'gigtune_dispute_status', 'OPEN');
                            $this->upsertPostMeta($disputeId, 'gigtune_dispute_initiator_user_id', (string) $uid);
                            $this->upsertPostMeta($disputeId, 'gigtune_dispute_initiator_role', $initiatorRole);
                            $this->upsertPostMeta($disputeId, 'gigtune_dispute_created_at', (string) $ts->timestamp);
                            $this->upsertPostMeta($disputeId, 'gigtune_dispute_subject', $disputeSubject);
                            $this->upsertPostMeta($disputeId, 'gigtune_dispute_text', $disputeBody);
                        }

                        if ($clientUserId > 0 && $clientUserId !== $uid) {
                            $this->createNotification($clientUserId, 'dispute', 'A dispute was opened for booking #' . $bookingId . ': ' . $disputeSubject, ['object_type' => 'booking', 'object_id' => $bookingId]);
                        }
                        if ($artistOwnerId > 0 && $artistOwnerId !== $uid) {
                            $this->createNotification($artistOwnerId, 'dispute', 'A dispute was opened for booking #' . $bookingId . ': ' . $disputeSubject, ['object_type' => 'booking', 'object_id' => $bookingId]);
                        }
                        foreach ($this->adminUserIds() as $adminUserId) {
                            if ($adminUserId <= 0) {
                                continue;
                            }
                            $this->createNotification($adminUserId, 'dispute', 'New dispute on booking #' . $bookingId . ': ' . $disputeSubject, ['object_type' => 'booking', 'object_id' => $bookingId]);
                        }
                        $statusMessage = 'Dispute opened.';
                    }
                } elseif ($action === 'request_refund') {
                    if (!$isClientOwner && !$isAdmin) {
                        $errorMessage = 'Only the requesting client can request a refund.';
                    } elseif (in_array($currentStatus, ['COMPLETED_BY_ARTIST', 'COMPLETED_CONFIRMED'], true)) {
                        $errorMessage = 'Refund requests are unavailable after completion.';
                    } elseif (!in_array($paymentStatus, ['PAID_ESCROWED', 'PAID', 'AWAITING_PAYMENT_CONFIRMATION', 'CONFIRMED_HELD_PENDING_COMPLETION', 'REFUNDED_PARTIAL'], true)) {
                        $errorMessage = 'Refund requests require a paid or held booking.';
                    } else {
                        $this->upsertPostMeta($bookingId, 'gigtune_refund_status', 'REQUESTED');
                        $this->upsertPostMeta($bookingId, 'gigtune_refund_requested_by', $isAdmin ? 'admin' : 'client');
                        $this->upsertPostMeta($bookingId, 'gigtune_refund_requested_at', $nowTs);
                        $this->upsertPostMeta($bookingId, 'gigtune_booking_locked', 'refund_pending');
                        foreach ($this->adminUserIds() as $adminUserId) {
                            $this->createNotification($adminUserId, 'refund', 'Refund request submitted for booking #' . $bookingId . '.', ['object_type' => 'booking', 'object_id' => $bookingId]);
                        }
                        $statusMessage = 'Refund request submitted.';
                    }
                } elseif ($action === 'rate_artist') {
                    if (!$isClientOwner && !$isAdmin) {
                        $errorMessage = 'Only the booking client can rate the artist.';
                    } elseif ($currentStatus !== 'COMPLETED_CONFIRMED') {
                        $errorMessage = 'Rating is available after completion is confirmed.';
                    } elseif ((string) ($bookingMeta['gigtune_dispute_raised'] ?? '') === '1' || $currentStatus === 'DISPUTE_OPEN') {
                        $errorMessage = 'Rating is unavailable while a dispute is open.';
                    } elseif ((string) ($bookingMeta['gigtune_client_rating_submitted'] ?? '') === '1') {
                        $errorMessage = 'You already rated this artist.';
                    } elseif (
                        $ratePunctuality < 1 || $ratePunctuality > 5
                        || $ratePerformanceQuality < 1 || $ratePerformanceQuality > 5
                        || $rateCharacter < 1 || $rateCharacter > 5
                    ) {
                        $errorMessage = 'Rating values must be between 1 and 5.';
                    } elseif ($artistProfileId <= 0) {
                        $errorMessage = 'Artist profile is missing.';
                    } else {
                        $payload = [
                            'punctuality' => $ratePunctuality,
                            'performance_quality' => $ratePerformanceQuality,
                            'character' => $rateCharacter,
                            'submitted_at' => time(),
                        ];
                        $this->upsertPostMeta($bookingId, 'gigtune_client_rating_payload', serialize($payload));
                        $this->upsertPostMeta($bookingId, 'gigtune_client_rating_submitted', '1');

                        $this->updateProfileRatingAverage($artistProfileId, 'gigtune_artist_rating_punctuality_avg', 'gigtune_artist_rating_punctuality_count', $ratePunctuality);
                        $this->updateProfileRatingAverage($artistProfileId, 'gigtune_artist_rating_performance_quality_avg', 'gigtune_artist_rating_performance_quality_count', $ratePerformanceQuality);
                        $this->updateProfileRatingAverage($artistProfileId, 'gigtune_artist_rating_character_avg', 'gigtune_artist_rating_character_count', $rateCharacter);
                        $summary = $this->artistRatingSummary($artistProfileId);
                        $this->upsertPostMeta($artistProfileId, 'gigtune_performance_rating_avg', (string) $summary['rating_avg']);
                        $this->upsertPostMeta($artistProfileId, 'gigtune_performance_rating_count', (string) $summary['rating_count']);
                        $this->upsertPostMeta($artistProfileId, 'gigtune_reliability_rating_avg', (string) $summary['rating_avg']);
                        $this->upsertPostMeta($artistProfileId, 'gigtune_reliability_rating_count', (string) $summary['rating_count']);

                        if ($artistOwnerId > 0) {
                            $this->createNotification($artistOwnerId, 'booking', 'Client submitted a rating for booking #' . $bookingId . '.', ['object_type' => 'booking', 'object_id' => $bookingId]);
                        }
                        $statusMessage = 'Artist rating submitted.';
                    }
                } elseif ($action === 'rate_client') {
                    if (!$isArtistOwner && !$isAdmin) {
                        $errorMessage = 'Only the assigned artist can rate the client.';
                    } elseif ($currentStatus !== 'COMPLETED_CONFIRMED') {
                        $errorMessage = 'Rating is available after completion is confirmed.';
                    } elseif ((string) ($bookingMeta['gigtune_dispute_raised'] ?? '') === '1' || $currentStatus === 'DISPUTE_OPEN') {
                        $errorMessage = 'Rating is unavailable while a dispute is open.';
                    } elseif ((string) ($bookingMeta['gigtune_artist_rating_submitted'] ?? '') === '1') {
                        $errorMessage = 'You already rated this client.';
                    } elseif (
                        $rateCompletionSpeed < 1 || $rateCompletionSpeed > 5
                        || $rateProfessionalism < 1 || $rateProfessionalism > 5
                        || $rateWorkingConditions < 1 || $rateWorkingConditions > 5
                        || $rateClientCharacter < 1 || $rateClientCharacter > 5
                    ) {
                        $errorMessage = 'Rating values must be between 1 and 5.';
                    } elseif ($clientUserId <= 0) {
                        $errorMessage = 'Client account is missing.';
                    } else {
                        $artistCompletedAt = (int) ($bookingMeta['gigtune_booking_completed_by_artist_at'] ?? 0);
                        $clientConfirmedAt = (int) ($bookingMeta['gigtune_booking_completed_confirmed_at'] ?? 0);
                        $completionSpeedHours = 0.0;
                        if ($artistCompletedAt > 0 && $clientConfirmedAt >= $artistCompletedAt) {
                            $completionSpeedHours = round(($clientConfirmedAt - $artistCompletedAt) / 3600, 2);
                        }

                        $overallAvg = round((($rateCompletionSpeed + $rateProfessionalism + $rateWorkingConditions + $rateClientCharacter) / 4), 2);
                        $ratingId = (int) $this->db()->table($this->posts())->insertGetId([
                            'post_author' => $uid,
                            'post_date' => $nowMysql,
                            'post_date_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                            'post_content' => '',
                            'post_title' => 'Client Rating - Booking #' . $bookingId . ' - ' . now()->format('Y-m-d H:i'),
                            'post_status' => 'publish',
                            'comment_status' => 'closed',
                            'ping_status' => 'closed',
                            'post_name' => 'client-rating-' . $bookingId . '-' . now()->timestamp . '-' . random_int(100, 999),
                            'post_modified' => $nowMysql,
                            'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                            'post_type' => 'gt_client_rating',
                        ]);
                        if ($ratingId <= 0) {
                            $errorMessage = 'Unable to save client rating.';
                        } else {
                            $this->upsertPostMeta($ratingId, 'gigtune_client_rating_booking_id', (string) $bookingId);
                            $this->upsertPostMeta($ratingId, 'gigtune_client_rating_client_user_id', (string) $clientUserId);
                            $this->upsertPostMeta($ratingId, 'gigtune_client_rating_artist_user_id', (string) $uid);
                            $this->upsertPostMeta($ratingId, 'gigtune_client_rating_booking_completion_speed', (string) $rateCompletionSpeed);
                            $this->upsertPostMeta($ratingId, 'gigtune_client_rating_professionalism', (string) $rateProfessionalism);
                            $this->upsertPostMeta($ratingId, 'gigtune_client_rating_working_conditions', (string) $rateWorkingConditions);
                            $this->upsertPostMeta($ratingId, 'gigtune_client_rating_character', (string) $rateClientCharacter);
                            $this->upsertPostMeta($ratingId, 'gigtune_client_rating_overall_avg', (string) $overallAvg);
                            $this->upsertPostMeta($ratingId, 'gigtune_client_rating_completion_speed_hours', (string) $completionSpeedHours);
                            $this->upsertPostMeta($ratingId, 'gigtune_client_rating_created_at', $nowTs);
                            $this->upsertPostMeta($bookingId, 'gigtune_artist_rating_submitted', '1');
                            $this->createNotification($clientUserId, 'booking', 'Artist submitted a rating for booking #' . $bookingId . '.', ['object_type' => 'booking', 'object_id' => $bookingId]);
                            $statusMessage = 'Client rating submitted.';
                        }
                    }
                } elseif ($action === 'send_message') {
                    if (!$isClientOwner && !$isArtistOwner && !$isAdmin) {
                        $errorMessage = 'Only booking participants can send messages.';
                    } elseif ($threadMessage === '') {
                        $errorMessage = 'Message cannot be empty.';
                    } else {
                        $messageSubject = $threadSubject !== '' ? $threadSubject : ('Booking #' . $bookingId . ' message');
                        $messageId = (int) $this->db()->table($this->posts())->insertGetId([
                            'post_author' => $uid,
                            'post_date' => $nowMysql,
                            'post_date_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                            'post_content' => $threadMessage,
                            'post_title' => $messageSubject,
                            'post_status' => 'publish',
                            'comment_status' => 'closed',
                            'ping_status' => 'closed',
                            'post_name' => 'gigtune-message-' . $bookingId . '-' . now()->format('YmdHis') . '-' . random_int(100, 999),
                            'post_modified' => $nowMysql,
                            'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                            'post_type' => 'gigtune_message',
                        ]);
                        if ($messageId <= 0) {
                            $errorMessage = 'Unable to send message.';
                        } else {
                            $this->upsertPostMeta($messageId, 'gigtune_message_booking_id', (string) $bookingId);
                            $this->upsertPostMeta($messageId, 'gigtune_message_sender_user_id', (string) $uid);
                            $recipientIds = [];
                            if ($isAdmin) {
                                if ($clientUserId > 0 && $clientUserId !== $uid) {
                                    $recipientIds[] = $clientUserId;
                                }
                                if ($artistOwnerId > 0 && $artistOwnerId !== $uid) {
                                    $recipientIds[] = $artistOwnerId;
                                }
                            } elseif ($isClientOwner && $artistOwnerId > 0) {
                                $recipientIds[] = $artistOwnerId;
                            } elseif ($isArtistOwner && $clientUserId > 0) {
                                $recipientIds[] = $clientUserId;
                            }
                            foreach (array_values(array_unique($recipientIds)) as $recipientId) {
                                $this->upsertPostMeta($messageId, 'gigtune_message_recipient_user_id', (string) $recipientId);
                                $this->createNotification($recipientId, 'message', 'New message on booking #' . $bookingId . '.', ['object_type' => 'booking', 'object_id' => $bookingId]);
                            }
                            if (!$isAdmin) {
                                foreach ($this->adminUserIds() as $adminUserId) {
                                    if ($adminUserId > 0 && $adminUserId !== $uid) {
                                        $this->createNotification($adminUserId, 'message', 'New participant message on booking #' . $bookingId . '.', ['object_type' => 'booking', 'object_id' => $bookingId]);
                                    }
                                }
                            }
                            $statusMessage = 'Message sent.';
                        }
                    }
                }

                $bookingMeta = $this->postMetaMap([$bookingId], [
                    'gigtune_booking_status',
                    'gigtune_payment_status',
                    'gigtune_payout_status',
                    'gigtune_refund_status',
                    'gigtune_dispute_raised',
                    'gigtune_booking_artist_profile_id',
                    'gigtune_booking_event_date',
                    'gigtune_booking_location_text',
                    'gigtune_booking_budget',
                    'gigtune_booking_quote_amount',
                    'gigtune_booking_requires_accommodation',
                    'gigtune_booking_client_offers_accommodation',
                    'gigtune_payment_accommodation_fee',
                    'gigtune_booking_responded_at',
                    'gigtune_booking_reject_reason',
                    'gigtune_payment_method',
                    'gigtune_payment_reported_at',
                    'gigtune_payment_window_expires_at',
                    'gigtune_payment_reference_human',
                    'gigtune_client_rating_submitted',
                    'gigtune_artist_rating_submitted',
                    'gigtune_booking_completed_by_artist_at',
                    'gigtune_booking_completed_confirmed_at',
                ])[$bookingId] ?? [];
            }

            $status = $this->toSentenceCase((string) ($bookingMeta['gigtune_booking_status'] ?? ''));
            $payment = $this->toSentenceCase((string) ($bookingMeta['gigtune_payment_status'] ?? ''));
            $payout = $this->toSentenceCase((string) ($bookingMeta['gigtune_payout_status'] ?? ''));
            $refund = $this->toSentenceCase((string) ($bookingMeta['gigtune_refund_status'] ?? ''));
            $dispute = ((string) ($bookingMeta['gigtune_dispute_raised'] ?? '') === '1') ? 'Open' : 'None';
            $eventDate = trim((string) ($bookingMeta['gigtune_booking_event_date'] ?? ''));
            $location = trim((string) ($bookingMeta['gigtune_booking_location_text'] ?? ''));
            $quoteAmount = (int) ($bookingMeta['gigtune_booking_quote_amount'] ?? $bookingMeta['gigtune_booking_budget'] ?? 0);
            $respondedAt = trim((string) ($bookingMeta['gigtune_booking_responded_at'] ?? ''));
            $rejectReason = trim((string) ($bookingMeta['gigtune_booking_reject_reason'] ?? ''));
            $paymentMethod = strtoupper(trim((string) ($bookingMeta['gigtune_payment_method'] ?? '')));
            $paymentReportedAtTs = (int) ($bookingMeta['gigtune_payment_reported_at'] ?? 0);
            $paymentWindowExpiresAtTs = (int) ($bookingMeta['gigtune_payment_window_expires_at'] ?? 0);
            $paymentWindowExpired = $paymentWindowExpiresAtTs > 0 && $paymentWindowExpiresAtTs < time();
            $paymentReferenceHuman = trim((string) ($bookingMeta['gigtune_payment_reference_human'] ?? ''));
            if ($paymentReferenceHuman === '') {
                $paymentReferenceHuman = $this->bookingPaymentReferenceHuman($bookingId, $clientUserId);
            }
            $accommodationFeeAmount = $this->bookingAccommodationFeeAmount($bookingMeta);
            $checkoutTotalCents = $this->bookingYocoAmountCents($bookingId);
            $checkoutTotal = $checkoutTotalCents > 0 ? ($checkoutTotalCents / 100) : 0.0;
            $disputeWindowDeadlineTs = $this->bookingDisputeDeadlineTimestamp($eventDate);
            $disputeWindowClosed = $disputeWindowDeadlineTs > 0 && time() > $disputeWindowDeadlineTs;

            $currentStatusRaw = strtoupper(trim((string) ($bookingMeta['gigtune_booking_status'] ?? '')));
            $paymentStatusRaw = strtoupper(trim((string) ($bookingMeta['gigtune_payment_status'] ?? '')));
            $yocoError = trim((string) ($request?->query('yoco_error', '') ?? ''));
            $paymentResult = strtolower(trim((string) ($request?->query('payment_result', '') ?? '')));

            $html = '<div class="space-y-4">';
            if ($statusMessage !== '') {
                $html .= '<div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">' . e($statusMessage) . '</div>';
            }
            if ($errorMessage !== '') {
                $html .= '<div class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">' . e($errorMessage) . '</div>';
            }
            if ($paymentResult === 'success') {
                $html .= '<div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">Payment completed and submitted for confirmation.</div>';
            } elseif (in_array($paymentResult, ['cancelled', 'failed'], true)) {
                $html .= '<div class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">Payment was not completed. You can try card checkout again.</div>';
            }
            if ($redirectToYoco !== '') {
                $redirectJson = json_encode($redirectToYoco, JSON_UNESCAPED_SLASHES);
                if (!is_string($redirectJson)) {
                    $redirectJson = '"/"';
                }
                $html .= '<script>(function(){var u=' . $redirectJson . ';if(u){window.location.assign(u);}})();</script>';
                $html .= '<div class="rounded-xl border border-sky-500/30 bg-sky-500/10 px-4 py-3 text-sm text-sky-100">Redirecting to secure card checkout. If you are not redirected, <a class="underline" href="' . e($redirectToYoco) . '">continue to payment</a>.</div>';
                return $html . '</div>';
            }

            $html .= '<div class="rounded-xl border border-white/10 bg-white/5 p-4">';
            $html .= '<div class="flex flex-wrap items-center justify-between gap-3">';
            $html .= '<h3 class="text-base font-semibold text-white">Booking #' . $bookingId . '</h3>';
            $html .= '<a href="/messages/" class="text-xs text-slate-300 hover:text-white underline">Back to all messages</a>';
            $html .= '</div>';
            $html .= '<div class="mt-2 grid gap-2 text-sm text-slate-300 md:grid-cols-2">';
            $html .= '<p>Status: <span class="text-slate-100">' . e($status !== '' ? $status : '-') . '</span></p>';
            $html .= '<p>Payment: <span class="text-slate-100">' . e($payment !== '' ? $payment : '-') . '</span></p>';
            $html .= '<p>Payout: <span class="text-slate-100">' . e($payout !== '' ? $payout : '-') . '</span></p>';
            $html .= '<p>Refund: <span class="text-slate-100">' . e($refund !== '' ? $refund : '-') . '</span></p>';
            $html .= '<p>Dispute: <span class="text-slate-100">' . e($dispute) . '</span></p>';
            $html .= '<p>Event date: <span class="text-slate-100">' . e($eventDate !== '' ? $eventDate : '-') . '</span></p>';
            $html .= '<p class="md:col-span-2">Location: <span class="text-slate-100">' . e($location !== '' ? $location : '-') . '</span></p>';
            if ($quoteAmount > 0) {
                $html .= '<p>Quote: <span class="text-slate-100">ZAR ' . e(number_format($quoteAmount)) . '</span></p>';
            }
            $html .= '<p>Accommodation fee: <span class="text-slate-100">ZAR ' . e(number_format($accommodationFeeAmount, 2)) . '</span></p>';
            if ($paymentMethod !== '') {
                $html .= '<p>Method: <span class="text-slate-100">' . e($paymentMethod) . '</span></p>';
            }
            if ($paymentReportedAtTs > 0) {
                $html .= '<p>Reported at: <span class="text-slate-100">' . e(date('M j, Y H:i', $paymentReportedAtTs)) . '</span></p>';
            }
            if ($paymentWindowExpiresAtTs > 0) {
                $html .= '<p>Payment deadline: <span class="text-slate-100">' . e(date('M j, Y H:i', $paymentWindowExpiresAtTs)) . '</span></p>';
            }
            if ($respondedAt !== '') {
                $html .= '<p>Responded at: <span class="text-slate-100">' . e($respondedAt) . '</span></p>';
            }
            if ($rejectReason !== '') {
                $html .= '<p class="md:col-span-2">Reject reason: <span class="text-slate-100">' . e($rejectReason) . '</span></p>';
            }
            $html .= '</div>';

            $nonce = $this->createWpNonce('gigtune_booking_action');
            $html .= '<div class="mt-4 flex flex-wrap gap-2">';
            if (($isArtistOwner || $isAdmin) && $currentStatusRaw === 'REQUESTED') {
                $html .= '<form method="post" class="w-full space-y-2">';
                $html .= '<input type="hidden" name="gigtune_booking_action_submit" value="1"><input type="hidden" name="gigtune_booking_action_nonce" value="' . e($nonce) . '"><input type="hidden" name="gigtune_booking_action" value="accept">';
                $html .= '<label class="block text-xs text-slate-300">Reject reason (optional)</label>';
                $html .= '<textarea name="gigtune_booking_reject_reason" rows="2" class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-3 py-2 text-xs text-white"></textarea>';
                $html .= '<div class="flex flex-wrap gap-2">';
                $html .= '<button type="submit" onclick="this.form.gigtune_booking_action.value=\'accept\'" class="inline-flex items-center justify-center rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-500">Accept request</button>';
                $html .= '<button type="submit" onclick="this.form.gigtune_booking_action.value=\'decline\'" class="inline-flex items-center justify-center rounded-lg bg-rose-600 px-3 py-2 text-xs font-semibold text-white hover:bg-rose-500">Decline request</button>';
                $html .= '</div></form>';
            }
            if (($isClientOwner || $isAdmin) && $currentStatusRaw === 'ACCEPTED_PENDING_PAYMENT') {
                $html .= '<div class="w-full rounded-xl border border-white/10 bg-black/20 p-4 space-y-3">';
                $html .= '<p class="text-sm text-slate-200 font-semibold">Pay by card (YOCO)</p>';
                if ($checkoutTotal > 0) {
                    $html .= '<p class="text-xs text-slate-300">Total: <span class="text-slate-100">ZAR ' . e(number_format($checkoutTotal, 2)) . '</span> <span class="text-slate-400">(includes 15% service fee)</span></p>';
                }
                $html .= '<p class="text-xs text-slate-300">Reference: <span class="text-slate-100 font-mono">' . e($paymentReferenceHuman) . '</span></p>';
                if ($paymentWindowExpiresAtTs > 0) {
                    $html .= '<p class="text-xs text-slate-300">Payment deadline: <span class="text-slate-100">' . e(date('M j, Y H:i', $paymentWindowExpiresAtTs)) . '</span></p>';
                }
                if ($paymentWindowExpired) {
                    $html .= '<p class="text-xs text-rose-200">Payment window has expired.</p>';
                } elseif ($yocoError === '1') {
                    $html .= '<div class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-3">';
                    $html .= '<p class="text-rose-200 text-xs font-semibold">Card checkout failed. Please try again.</p>';
                    if ($isAdmin) {
                        $yocoLastError = trim((string) ($bookingMeta['gigtune_yoco_last_error'] ?? $this->getLatestPostMeta($bookingId, 'gigtune_yoco_last_error')));
                        if ($yocoLastError !== '') {
                            $html .= '<p class="text-slate-300 text-xs mt-2">Admin debug: ' . e($yocoLastError) . '</p>';
                        }
                    }
                    $html .= '</div>';
                } elseif (in_array($paymentStatusRaw, ['AWAITING_PAYMENT_CONFIRMATION', 'CONFIRMED_HELD_PENDING_COMPLETION', 'PAID_ESCROWED'], true)) {
                    $html .= '<p class="text-xs text-blue-200">Payment already reported: ' . e($this->toSentenceCase($paymentStatusRaw)) . '.</p>';
                } else {
                    $html .= '<form method="post" class="space-y-2">';
                    $html .= '<input type="hidden" name="gigtune_booking_action_submit" value="1"><input type="hidden" name="gigtune_booking_action_nonce" value="' . e($nonce) . '"><input type="hidden" name="gigtune_booking_action" value="pay_yoco">';
                    $html .= '<button type="submit" class="inline-flex items-center justify-center rounded-xl px-5 py-2.5 text-sm font-semibold text-white shadow bg-gradient-to-r from-blue-600 via-sky-500 to-amber-400 hover:from-blue-500 hover:via-sky-400 hover:to-amber-300">Pay with Card (YOCO)</button>';
                    $html .= '<p class="text-xs text-slate-300">Secure checkout hosted by YOCO.</p>';
                    $html .= '</form>';
                }
                $html .= '</div>';
            }
            if ($isAdmin && $paymentStatusRaw === 'AWAITING_PAYMENT_CONFIRMATION') {
                $html .= '<form method="post" class="inline-flex">';
                $html .= '<input type="hidden" name="gigtune_booking_action_submit" value="1"><input type="hidden" name="gigtune_booking_action_nonce" value="' . e($nonce) . '"><input type="hidden" name="gigtune_booking_action" value="payment_confirm">';
                $html .= '<button type="submit" class="inline-flex items-center justify-center rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-500">Confirm payment</button></form>';
                $html .= '<form method="post" class="inline-flex">';
                $html .= '<input type="hidden" name="gigtune_booking_action_submit" value="1"><input type="hidden" name="gigtune_booking_action_nonce" value="' . e($nonce) . '"><input type="hidden" name="gigtune_booking_action" value="payment_reject">';
                $html .= '<button type="submit" class="inline-flex items-center justify-center rounded-lg bg-rose-600 px-3 py-2 text-xs font-semibold text-white hover:bg-rose-500">Reject payment</button></form>';
            }
            if (($isArtistOwner || $isAdmin) && $currentStatusRaw === 'PAID_ESCROWED') {
                $html .= '<form method="post" class="inline-flex">';
                $html .= '<input type="hidden" name="gigtune_booking_action_submit" value="1"><input type="hidden" name="gigtune_booking_action_nonce" value="' . e($nonce) . '"><input type="hidden" name="gigtune_booking_action" value="mark_completed">';
                $html .= '<button type="submit" class="inline-flex items-center justify-center rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-500">Mark completed</button></form>';
            }
            if (($isClientOwner || $isAdmin) && $currentStatusRaw === 'COMPLETED_BY_ARTIST') {
                $html .= '<form method="post" class="inline-flex">';
                $html .= '<input type="hidden" name="gigtune_booking_action_submit" value="1"><input type="hidden" name="gigtune_booking_action_nonce" value="' . e($nonce) . '"><input type="hidden" name="gigtune_booking_action" value="confirm_completion">';
                $html .= '<button type="submit" class="inline-flex items-center justify-center rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-500">Confirm completion</button></form>';
            }
            if (($isClientOwner || $isAdmin) && in_array($currentStatusRaw, ['REQUESTED', 'ACCEPTED_PENDING_PAYMENT', 'PAID_ESCROWED', 'AWAITING_PAYMENT_CONFIRMATION'], true)) {
                $html .= '<form method="post" class="inline-flex">';
                $html .= '<input type="hidden" name="gigtune_booking_action_submit" value="1"><input type="hidden" name="gigtune_booking_action_nonce" value="' . e($nonce) . '"><input type="hidden" name="gigtune_booking_action" value="cancel">';
                $html .= '<button type="submit" class="inline-flex items-center justify-center rounded-lg bg-white/10 px-3 py-2 text-xs font-semibold text-white hover:bg-white/15">Cancel booking</button></form>';
            }
            if (($isArtistOwner || $isAdmin) && in_array($currentStatusRaw, ['REQUESTED', 'ACCEPTED_PENDING_PAYMENT', 'PAID_ESCROWED', 'AWAITING_PAYMENT_CONFIRMATION'], true)) {
                $html .= '<form method="post" class="inline-flex">';
                $html .= '<input type="hidden" name="gigtune_booking_action_submit" value="1"><input type="hidden" name="gigtune_booking_action_nonce" value="' . e($nonce) . '"><input type="hidden" name="gigtune_booking_action" value="cancel_artist">';
                $html .= '<button type="submit" class="inline-flex items-center justify-center rounded-lg bg-white/10 px-3 py-2 text-xs font-semibold text-white hover:bg-white/15">Cancel as artist</button></form>';
            }
            if (($isClientOwner || $isArtistOwner || $isAdmin) && !$disputeWindowClosed && $currentStatusRaw !== 'DISPUTE_OPEN' && !in_array($currentStatusRaw, ['CANCELLED_BY_CLIENT', 'CANCELLED_BY_ARTIST'], true)) {
                $html .= '<button type="button" data-gigtune-open-dispute="1" class="inline-flex items-center justify-center rounded-lg bg-amber-600 px-3 py-2 text-xs font-semibold text-white hover:bg-amber-500">Open dispute</button>';
            }
            if (($isClientOwner || $isAdmin) && !in_array($currentStatusRaw, ['COMPLETED_BY_ARTIST', 'COMPLETED_CONFIRMED'], true) && in_array($paymentStatusRaw, ['PAID_ESCROWED', 'PAID', 'CONFIRMED_HELD_PENDING_COMPLETION', 'REFUNDED_PARTIAL'], true)) {
                $html .= '<form method="post" class="inline-flex">';
                $html .= '<input type="hidden" name="gigtune_booking_action_submit" value="1"><input type="hidden" name="gigtune_booking_action_nonce" value="' . e($nonce) . '"><input type="hidden" name="gigtune_booking_action" value="request_refund">';
                $html .= '<button type="submit" class="inline-flex items-center justify-center rounded-lg bg-white/10 px-3 py-2 text-xs font-semibold text-white hover:bg-white/15">Request refund</button></form>';
            }
            $html .= '</div></div>';
            if (($isClientOwner || $isArtistOwner || $isAdmin) && !$disputeWindowClosed && $currentStatusRaw !== 'DISPUTE_OPEN' && !in_array($currentStatusRaw, ['CANCELLED_BY_CLIENT', 'CANCELLED_BY_ARTIST'], true)) {
                $html .= '<div data-gigtune-dispute-form="1" class="hidden rounded-xl border border-amber-500/30 bg-amber-500/10 p-4">';
                $html .= '<form method="post" class="space-y-3">';
                $html .= '<input type="hidden" name="gigtune_booking_action_submit" value="1"><input type="hidden" name="gigtune_booking_action_nonce" value="' . e($nonce) . '"><input type="hidden" name="gigtune_booking_action" value="raise_dispute">';
                $html .= '<div><label class="mb-1 block text-xs font-semibold text-amber-100">Subject</label><input type="text" name="gigtune_dispute_subject" required class="w-full rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-sm text-white" placeholder="Brief dispute subject"></div>';
                $html .= '<div><label class="mb-1 block text-xs font-semibold text-amber-100">Dispute details</label><textarea name="gigtune_dispute_text" rows="4" required class="w-full rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-sm text-white" placeholder="Describe the issue for admin review"></textarea></div>';
                $html .= '<div class="flex flex-wrap gap-2"><button type="submit" class="inline-flex items-center justify-center rounded-lg bg-amber-600 px-3 py-2 text-xs font-semibold text-white hover:bg-amber-500">Submit dispute</button><button type="button" data-gigtune-close-dispute="1" class="inline-flex items-center justify-center rounded-lg bg-white/10 px-3 py-2 text-xs font-semibold text-white hover:bg-white/15">Cancel</button></div>';
                $html .= '</form></div>';
                $html .= '<script>(function(){var openBtn=document.querySelector("[data-gigtune-open-dispute=\\"1\\"]");var closeBtn=document.querySelector("[data-gigtune-close-dispute=\\"1\\"]");var form=document.querySelector("[data-gigtune-dispute-form=\\"1\\"]");if(!openBtn||!form){return;}openBtn.addEventListener("click",function(){form.classList.remove("hidden");openBtn.classList.add("hidden");var s=form.querySelector("input[name=\'gigtune_dispute_subject\']");if(s){try{s.focus();}catch(e){}}});if(closeBtn){closeBtn.addEventListener("click",function(){form.classList.add("hidden");openBtn.classList.remove("hidden");});}})();</script>';
            } elseif ($disputeWindowClosed) {
                $html .= '<div class="rounded-xl border border-slate-600/40 bg-slate-900/40 p-3 text-xs text-slate-300">Dispute window expired for this booking.</div>';
            }

            $clientRated = (string) ($bookingMeta['gigtune_client_rating_submitted'] ?? '') === '1';
            $artistRated = (string) ($bookingMeta['gigtune_artist_rating_submitted'] ?? '') === '1';
            $hasOpenDispute = ((string) ($bookingMeta['gigtune_dispute_raised'] ?? '') === '1') || $currentStatusRaw === 'DISPUTE_OPEN';
            if (in_array($currentStatusRaw, ['COMPLETED_CONFIRMED', 'DISPUTE_OPEN'], true)) {
                $html .= '<div class="rounded-xl border border-white/10 bg-white/5 p-4">';
                $html .= '<div class="text-sm font-semibold text-white">Post-booking ratings</div>';

                if ($isClientOwner || $isAdmin) {
                    if ($clientRated) {
                        $html .= '<p class="mt-2 text-xs text-slate-300">Thanks - artist rating submitted.</p>';
                    } elseif ($hasOpenDispute) {
                        $html .= '<p class="mt-2 text-xs text-slate-300">Rating available once dispute is resolved.</p>';
                    } elseif ($currentStatusRaw !== 'COMPLETED_CONFIRMED') {
                        $html .= '<p class="mt-2 text-xs text-slate-300">Rating becomes available after booking completion is confirmed.</p>';
                    } else {
                        $html .= '<form method="post" class="mt-3 space-y-3">';
                        $html .= '<input type="hidden" name="gigtune_booking_action_submit" value="1"><input type="hidden" name="gigtune_booking_action_nonce" value="' . e($nonce) . '"><input type="hidden" name="gigtune_booking_action" value="rate_artist">';
                        $html .= '<div class="grid gap-3 md:grid-cols-3">';
                        $html .= '<div><label class="mb-1 block text-xs font-semibold text-slate-200">Punctuality (1-5)</label><input type="number" min="1" max="5" name="gigtune_rate_punctuality" required class="w-full rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-sm text-white"></div>';
                        $html .= '<div><label class="mb-1 block text-xs font-semibold text-slate-200">Performance quality (1-5)</label><input type="number" min="1" max="5" name="gigtune_rate_performance_quality" required class="w-full rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-sm text-white"></div>';
                        $html .= '<div><label class="mb-1 block text-xs font-semibold text-slate-200">Character (1-5)</label><input type="number" min="1" max="5" name="gigtune_rate_character" required class="w-full rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-sm text-white"></div>';
                        $html .= '</div>';
                        $html .= '<button type="submit" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-500">Submit artist rating</button>';
                        $html .= '</form>';
                    }
                }

                if ($isArtistOwner || $isAdmin) {
                    if ($artistRated) {
                        $html .= '<p class="mt-3 text-xs text-slate-300">Thanks - client rating submitted.</p>';
                    } elseif ($hasOpenDispute) {
                        $html .= '<p class="mt-3 text-xs text-slate-300">Rating available once dispute is resolved.</p>';
                    } elseif ($currentStatusRaw !== 'COMPLETED_CONFIRMED') {
                        $html .= '<p class="mt-3 text-xs text-slate-300">Rating becomes available after booking completion is confirmed.</p>';
                    } else {
                        $html .= '<form method="post" class="mt-3 space-y-3">';
                        $html .= '<input type="hidden" name="gigtune_booking_action_submit" value="1"><input type="hidden" name="gigtune_booking_action_nonce" value="' . e($nonce) . '"><input type="hidden" name="gigtune_booking_action" value="rate_client">';
                        $html .= '<div class="grid gap-3 md:grid-cols-2">';
                        $html .= '<div><label class="mb-1 block text-xs font-semibold text-slate-200">Booking completion speed (1-5)</label><input type="number" min="1" max="5" name="gigtune_rate_completion_speed" required class="w-full rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-sm text-white"></div>';
                        $html .= '<div><label class="mb-1 block text-xs font-semibold text-slate-200">Professionalism (1-5)</label><input type="number" min="1" max="5" name="gigtune_rate_professionalism" required class="w-full rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-sm text-white"></div>';
                        $html .= '<div><label class="mb-1 block text-xs font-semibold text-slate-200">Working conditions (1-5)</label><input type="number" min="1" max="5" name="gigtune_rate_working_conditions" required class="w-full rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-sm text-white"></div>';
                        $html .= '<div><label class="mb-1 block text-xs font-semibold text-slate-200">Character (1-5)</label><input type="number" min="1" max="5" name="gigtune_rate_client_character" required class="w-full rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-sm text-white"></div>';
                        $html .= '</div>';
                        $html .= '<button type="submit" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-500">Submit client rating</button>';
                        $html .= '</form>';
                    }
                }

                $html .= '</div>';
            }

            $html .= '<div class="rounded-xl border border-white/10 bg-white/5 p-4">';
            $html .= '<div class="text-sm font-semibold text-white">Conversation</div>';
            $html .= '<form method="post" class="mt-3 space-y-2">';
            $html .= '<input type="hidden" name="gigtune_booking_action_submit" value="1"><input type="hidden" name="gigtune_booking_action_nonce" value="' . e($nonce) . '"><input type="hidden" name="gigtune_booking_action" value="send_message">';
            $html .= '<input type="text" name="gigtune_booking_thread_subject" placeholder="Subject (optional)" class="w-full rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-sm text-white">';
            $html .= '<textarea name="gigtune_booking_thread_message" rows="3" required placeholder="Write your message..." class="w-full rounded-lg border border-white/10 bg-slate-950/60 px-3 py-2 text-sm text-white"></textarea>';
            $html .= '<button type="submit" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-500">Send message</button>';
            $html .= '</form></div>';

            $rows = $this->db()->table($this->posts() . ' as p')
                ->where('p.post_type', 'gigtune_message')
                ->where('p.post_status', 'publish')
                ->whereExists(function ($q) use ($bookingId): void {
                    $q->selectRaw('1')
                        ->from($this->pm() . ' as pm')
                        ->whereColumn('pm.post_id', 'p.ID')
                        ->where('pm.meta_key', 'gigtune_message_booking_id')
                        ->where('pm.meta_value', (string) $bookingId);
                })
                ->orderByDesc('p.ID')
                ->limit(50)
                ->get(['p.ID', 'p.post_title', 'p.post_content', 'p.post_date']);

            if ($rows->isEmpty()) {
                $html .= '<div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-slate-300">No booking conversation messages yet.</div>';
                return $html . '</div>';
            }

            $messageIds = [];
            foreach ($rows as $row) {
                $messageIds[] = (int) ($row->ID ?? 0);
            }
            $messageMeta = $this->postMetaMap($messageIds, ['gigtune_message_sender_user_id']);
            $html .= '<div class="space-y-3">';
            foreach ($rows as $row) {
                $messageId = (int) ($row->ID ?? 0);
                $senderId = (int) ($messageMeta[$messageId]['gigtune_message_sender_user_id'] ?? 0);
                $senderLabel = 'Unknown';
                if ($senderId === $clientUserId && $clientUserId > 0) {
                    $senderLabel = 'Client';
                } elseif ($senderId === $artistOwnerId && $artistOwnerId > 0) {
                    $senderLabel = 'Artist';
                } elseif ($senderId > 0 && in_array($senderId, $this->adminUserIds(), true)) {
                    $senderLabel = 'Admin';
                }
                $html .= '<article class="rounded-xl border border-white/10 bg-white/5 p-4"><div class="text-xs text-slate-400">' . e((string) $row->post_date) . ' <span class="text-slate-500">|</span> ' . e($senderLabel) . '</div><div class="mt-1 text-sm font-semibold text-white">' . e((string) $row->post_title) . '</div><p class="mt-1 text-sm text-slate-300">' . e((string) $row->post_content) . '</p></article>';
            }
            $html .= '</div>';
            return $html . '</div>';
        }

        $rows = $this->db()->table($this->posts() . ' as p')
            ->where('p.post_type', 'gigtune_message')
            ->where('p.post_status', 'publish')
            ->whereExists(function ($q) use ($uid): void {
                $q->selectRaw('1')
                    ->from($this->pm() . ' as pm')
                    ->whereColumn('pm.post_id', 'p.ID')
                    ->whereIn('pm.meta_key', ['gigtune_message_sender_user_id', 'gigtune_message_recipient_user_id'])
                    ->where('pm.meta_value', (string) $uid);
            })
            ->orderByDesc('p.ID')
            ->limit(30)
            ->get(['p.ID', 'p.post_title', 'p.post_content', 'p.post_date']);

        if ($rows->isEmpty()) {
            return '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">No messages found.</div>';
        }
        $html = '<div class="space-y-3">';
        foreach ($rows as $row) {
            $html .= '<article class="rounded-xl border border-white/10 bg-white/5 p-4"><div class="text-xs text-slate-400">' . e((string) $row->post_date) . '</div><div class="mt-1 text-sm font-semibold text-white">' . e((string) $row->post_title) . '</div><p class="mt-1 text-sm text-slate-300">' . e((string) $row->post_content) . '</p></article>';
        }
        return $html . '</div>';
    }
    private function notifications(array $a, ?array $u, array $ctx = []): string { return $this->notificationsList($u, false, $ctx); }
    private function notificationsArchive(array $a, ?array $u, array $ctx = []): string { return $this->notificationsList($u, true, $ctx); }
    private function notificationsBell(array $a, ?array $u): string
    {
        $id = (int) ($u['id'] ?? 0);
        if ($id <= 0) {
            return '';
        }
        $count = (int) ($this->notifications->list($id, $id, (bool) ($u['is_admin'] ?? false), [
            'per_page' => 1,
            'page' => 1,
            'only_unread' => '1',
            'include_archived' => '0',
        ])['total'] ?? 0);
        $label = $count > 0 ? ('Notifications (' . $count . ')') : 'Notifications';
        return '<span class="text-sm text-slate-200 hover:text-white">' . e($label) . '</span>';
    }
    private function notificationSettings(array $a, ?array $u, array $ctx = []): string
    {
        if (!is_array($u)) {
            return '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 text-slate-200">Please sign in to manage notification settings.</div>';
        }

        $uid = (int) ($u['id'] ?? 0);
        $request = $this->req($ctx);
        $saved = $request !== null && sanitize_key((string) $request->query('notification_settings_saved', '')) === '1';
        $errorFlag = $request !== null && sanitize_key((string) $request->query('notification_settings_error', '')) === '1';
        $isAdminNoticeUser = (bool) ($u['is_admin'] ?? false);

        // Fallback inline handling when this shortcode is rendered in a non-redirect flow.
        if ($request !== null && strtoupper((string) $request->method()) === 'POST' && (string) $request->input('gigtune_notification_settings_submit', '') === '1') {
            $submittedPreferences = [];
            foreach ($this->notificationEmailCategories() as $category) {
                $fieldName = 'gigtune_notify_' . $category;
                $submittedPreferences[$category] = (string) $request->input($fieldName, '') === '1';
            }
            $this->storeNotificationEmailPreferences($uid, $submittedPreferences);
            $saved = true;
            $errorFlag = false;
        }

        $preferences = $this->loadNotificationEmailPreferences($uid);
        $labels = [
            'booking' => 'Booking updates',
            'psa' => 'Open post applications (PSA)',
            'message' => 'New messages',
            'payment' => 'Payments and payouts',
            'dispute' => 'Disputes and refunds',
            'security' => 'Security alerts (recommended)',
        ];

        $html = '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8">';
        $html .= '<h2 class="text-2xl font-bold text-white">Notification settings</h2>';
        $html .= '<p class="mt-2 text-sm text-slate-300">Choose which notification categories should also send an email to your account.</p>';

        if ($isAdminNoticeUser) {
            $html .= '<div class="mt-4 rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-100/90">'
                . 'Email deliverability requires DNS alignment for <code class="px-1 py-0.5 rounded bg-black/30">gigtune.africa</code>: '
                . 'SPF + DKIM + DMARC should be configured. '
                . 'Recommended SPF baseline: <code class="px-1 py-0.5 rounded bg-black/30">v=spf1 mx a ~all</code>'
                . '</div>';
        }

        if ($saved) {
            $html .= '<div class="mt-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-200">Notification settings updated.</div>';
        } elseif ($errorFlag) {
            $html .= '<div class="mt-4 rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-sm text-rose-200">Security check failed. Please try again.</div>';
        }

        $html .= '<form id="gigtune-notification-settings-form" method="post" class="mt-5 space-y-4">';
        $html .= '<input type="hidden" name="gigtune_notification_settings_nonce" value="' . e($this->createWpNonce('gigtune_notification_settings_action')) . '">';
        $html .= '<input type="hidden" name="gigtune_notification_settings_submit" value="1">';
        $html .= '<div class="space-y-3">';
        foreach ($this->notificationEmailCategories() as $category) {
            $fieldName = 'gigtune_notify_' . $category;
            $checked = !empty($preferences[$category]) ? ' checked="checked"' : '';
            $html .= '<label class="flex items-start gap-3 rounded-xl border border-white/10 bg-black/20 px-4 py-3 text-sm text-slate-200">';
            $html .= '<input id="' . e($fieldName) . '" type="checkbox" name="' . e($fieldName) . '" value="1" class="mt-0.5 h-4 w-4 accent-blue-500"' . $checked . '>';
            $html .= '<span>' . e((string) ($labels[$category] ?? ucfirst($category))) . '</span>';
            $html .= '</label>';
        }
        $html .= '</div>';
        $html .= '<div class="flex flex-wrap items-center gap-3">';
        $html .= '<button type="submit" class="inline-flex items-center justify-center rounded-xl px-6 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Save settings</button>';
        $html .= '<button type="submit" name="gigtune_notification_settings_cancel" value="1" class="inline-flex items-center justify-center rounded-xl px-6 py-3 text-sm font-semibold text-white bg-white/10 hover:bg-white/15">Cancel</button>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= '</div>';

        return $html;
    }

    private function accountPortal(array $a, ?array $u, array $ctx = []): string
    {
        if (!is_array($u)) {
            return '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 text-slate-200">Please sign in to manage your account.</div>';
        }
        $request = $this->req($ctx);
        $path = strtolower(trim((string) ($request?->path() ?? ''), '/'));
        if ($path === 'client-profile-edit') {
            return $this->clientProfileEdit($a, $u, $ctx);
        }
        $roles = is_array($u['roles'] ?? null) ? $u['roles'] : [];
        $html = '<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">';
        $html .= '<a class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm font-semibold text-white" href="/messages/">Messages</a>';
        $html .= '<a class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm font-semibold text-white" href="/notifications/">Notifications</a>';
        $html .= '<a class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm font-semibold text-white" href="/kyc-status/">KYC Status</a>';
        $html .= '<a class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm font-semibold text-white" href="/notification-settings/">Notification Settings</a>';
        if (in_array('gigtune_artist', $roles, true)) {
            $html .= '<a class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm font-semibold text-white" href="/artist-profile-edit/">Artist Profile</a>';
        }
        if (in_array('gigtune_client', $roles, true)) {
            $html .= '<a class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm font-semibold text-white" href="/client-profile-edit/">Client Profile</a>';
        }
        if ((bool) ($u['is_admin'] ?? false)) {
            $html .= '<a class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm font-semibold text-white" href="/admin-dashboard/">Admin Dashboard</a>';
        }
        $html .= '</div>';
        return $html . '<div class="mt-6 rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">Signed in as <span class="font-semibold text-white">' . e((string) ($u['display_name'] ?? $u['email'] ?? 'User')) . '</span>.</div>';
    }

    private function kycForm(array $a, ?array $u, array $ctx = []): string
    {
        if (!is_array($u)) {
            return '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 text-slate-200">Please sign in to submit Identity Verification (Know Your Customer Compliance).</div>';
        }
        $request = $this->req($ctx);
        $saved = false;
        $error = '';

        if ($request !== null && strtoupper((string) $request->method()) === 'POST' && (string) $request->input('gigtune_kyc_submit', '') === '1') {
            $uid = (int) ($u['id'] ?? 0);
            $legalName = trim((string) $request->input('gigtune_kyc_legal_name', ''));
            $idNumber = trim((string) $request->input('gigtune_kyc_id_number', ''));
            $dob = trim((string) $request->input('gigtune_kyc_dob', ''));
            $country = trim((string) $request->input('gigtune_kyc_country', 'South Africa'));
            $mobile = trim((string) $request->input('gigtune_kyc_mobile', ''));
            $addressStreet = trim((string) $request->input('gigtune_kyc_address_street', ''));
            $addressSuburb = trim((string) $request->input('gigtune_kyc_address_suburb', ''));
            $addressCity = trim((string) $request->input('gigtune_kyc_address_city', ''));
            $addressProvince = trim((string) $request->input('gigtune_kyc_address_province', ''));
            $addressPostalCode = trim((string) $request->input('gigtune_kyc_address_postal_code', ''));
            $legacyAddress = trim((string) $request->input('gigtune_kyc_address', ''));
            $address = trim(implode(', ', array_values(array_filter([
                $addressStreet,
                $addressSuburb,
                $addressCity,
                $addressProvince,
                $addressPostalCode,
                $country,
            ], static fn ($value): bool => trim((string) $value) !== ''))));
            if ($address === '' && $legacyAddress !== '') {
                $address = $legacyAddress;
            }
            $idDoc = $request->file('gigtune_kyc_id_document');
            $selfieDoc = $request->file('gigtune_kyc_selfie');
            $proofDoc = $request->file('gigtune_kyc_proof_of_address');

            if (
                $legalName === '' ||
                $idNumber === '' ||
                $country === '' ||
                $mobile === '' ||
                $addressStreet === '' ||
                $addressCity === '' ||
                $addressProvince === '' ||
                $addressPostalCode === '' ||
                ($addressPostalCode !== '' && preg_match('/^\d{4}$/', $addressPostalCode) !== 1) ||
                $address === '' ||
                $idDoc === null ||
                !$idDoc->isValid()
            ) {
                $error = 'Please complete all required fields and upload an ID document.';
            } else {
                $submissionId = (int) $this->db()->table($this->posts())->insertGetId([
                    'post_author' => $uid,
                    'post_date' => now()->format('Y-m-d H:i:s'),
                    'post_date_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                    'post_content' => '',
                    'post_title' => 'Identity Verification submission user ' . $uid . ' ' . now()->format('YmdHis'),
                    'post_status' => 'private',
                    'comment_status' => 'closed',
                    'ping_status' => 'closed',
                    'post_name' => 'identity-verification-submission-' . $uid . '-' . now()->format('YmdHis'),
                    'post_modified' => now()->format('Y-m-d H:i:s'),
                    'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                    'post_type' => 'gt_kyc_submission',
                ]);

                $docs = [];
                $idPath = $idDoc->store('gigtune/kyc', 'public');
                if (is_string($idPath) && $idPath !== '') {
                    $idAbsolute = storage_path('app/public/' . ltrim(str_replace('\\', '/', $idPath), '/'));
                    $docs['id_document'] = [
                        'file_name' => basename($idPath),
                        'file_path' => $idAbsolute,
                        'path' => $idPath,
                        'mime' => (string) ($idDoc->getMimeType() ?? ''),
                        'size' => (int) ($idDoc->getSize() ?? 0),
                    ];
                }
                if ($selfieDoc !== null && $selfieDoc->isValid()) {
                    $selfiePath = $selfieDoc->store('gigtune/kyc', 'public');
                    if (is_string($selfiePath) && $selfiePath !== '') {
                        $selfieAbsolute = storage_path('app/public/' . ltrim(str_replace('\\', '/', $selfiePath), '/'));
                        $docs['selfie'] = [
                            'file_name' => basename($selfiePath),
                            'file_path' => $selfieAbsolute,
                            'path' => $selfiePath,
                            'mime' => (string) ($selfieDoc->getMimeType() ?? ''),
                            'size' => (int) ($selfieDoc->getSize() ?? 0),
                        ];
                    }
                }
                if ($proofDoc !== null && $proofDoc->isValid()) {
                    $proofPath = $proofDoc->store('gigtune/kyc', 'public');
                    if (is_string($proofPath) && $proofPath !== '') {
                        $proofAbsolute = storage_path('app/public/' . ltrim(str_replace('\\', '/', $proofPath), '/'));
                        $docs['proof_of_address'] = [
                            'file_name' => basename($proofPath),
                            'file_path' => $proofAbsolute,
                            'path' => $proofPath,
                            'mime' => (string) ($proofDoc->getMimeType() ?? ''),
                            'size' => (int) ($proofDoc->getSize() ?? 0),
                        ];
                    }
                }

                $mask = strlen($idNumber) > 4 ? str_repeat('*', max(0, strlen($idNumber) - 4)) . substr($idNumber, -4) : $idNumber;
                $idHash = hash('sha256', strtolower((string) preg_replace('/\s+/', '', $idNumber)));
                $submittedAt = now()->format('Y-m-d H:i:s');
                $documentAccessLog = [[
                    'event' => 'submitted',
                    'at' => now()->timestamp,
                    'actor_user_id' => $uid,
                    'files' => array_keys($docs),
                ]];

                $this->upsertPostMeta($submissionId, 'gigtune_kyc_user_id', (string) $uid);
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_legal_name', $legalName);
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_id_number_masked', $mask);
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_id_number_hash', $idHash);
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_date_of_birth', $dob);
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_country', $country);
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_mobile', $mobile);
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_address', $address);
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_address_street', $addressStreet);
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_address_suburb', $addressSuburb);
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_address_city', $addressCity);
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_address_province', $addressProvince);
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_address_postal_code', $addressPostalCode);
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_address_country', $country);
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_submitted_at', $submittedAt);
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_decision', 'pending');
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_documents', json_encode($docs, JSON_UNESCAPED_SLASHES));
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_submission_id', (string) $submissionId);
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_document_access_log', json_encode($documentAccessLog, JSON_UNESCAPED_SLASHES));
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_risk_flags', json_encode([], JSON_UNESCAPED_SLASHES));
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_risk_metrics', json_encode([], JSON_UNESCAPED_SLASHES));
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_risk_score', '0');
                $this->upsertPostMeta($submissionId, 'gigtune_kyc_restart_requested_at', '');
                $this->upsertUserMeta($uid, 'gigtune_kyc_status', 'pending');
                $this->upsertUserMeta($uid, 'gigtune_kyc_latest_submission_id', (string) $submissionId);
                $this->upsertUserMeta($uid, 'gigtune_kyc_submission_id', (string) $submissionId);
                $saved = true;
            }
        }

        $html = '<div class="rounded-2xl border border-white/10 bg-white/5 p-6"><h2 class="text-2xl font-bold text-white">Identity Verification (Know Your Customer Compliance)</h2><p class="mt-2 text-sm text-slate-300">Submit required compliance details for secure platform access.</p>';
        if ($saved) {
            $html .= '<div class="mt-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-200">Submission received. Review your status on the KYC status page.</div>';
        } elseif ($error !== '') {
            $html .= '<div class="mt-4 rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-sm text-rose-200">' . e($error) . '</div>';
        }
        $html .= '<form method="post" enctype="multipart/form-data" class="mt-5 grid gap-4 md:grid-cols-2">';
        $html .= '<input type="hidden" name="gigtune_kyc_submit" value="1">';
        $html .= '<div class="md:col-span-2"><label class="mb-1 block text-sm text-slate-300">Legal full name</label><input name="gigtune_kyc_legal_name" required class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">ID/Passport number</label><input name="gigtune_kyc_id_number" required class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Date of birth</label><input type="date" name="gigtune_kyc_dob" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Country</label><input name="gigtune_kyc_country" value="South Africa" required class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Mobile</label><input name="gigtune_kyc_mobile" required class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Street address</label><input name="gigtune_kyc_address_street" required class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Suburb</label><input name="gigtune_kyc_address_suburb" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Town/City</label><input name="gigtune_kyc_address_city" required class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Province</label><select name="gigtune_kyc_address_province" required class="gigtune-site-select w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"><option value="">Select province</option>';
        foreach ($this->saProvinces() as $province) {
            $html .= '<option value="' . e($province) . '">' . e($province) . '</option>';
        }
        $html .= '</select></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Postcode</label><input name="gigtune_kyc_address_postal_code" required pattern="\\d{4}" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div class="md:col-span-2"><label class="mb-1 block text-sm text-slate-300">Address summary</label><textarea name="gigtune_kyc_address" rows="2" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white" placeholder="Optional legacy address format"></textarea></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">ID document *</label><input type="file" name="gigtune_kyc_id_document" accept="application/pdf,image/jpeg,image/png" required class="block w-full text-sm text-slate-200"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Selfie (optional)</label><input type="file" name="gigtune_kyc_selfie" accept="image/jpeg,image/png" class="block w-full text-sm text-slate-200"></div>';
        $html .= '<div class="md:col-span-2"><label class="mb-1 block text-sm text-slate-300">Proof of address (optional)</label><input type="file" name="gigtune_kyc_proof_of_address" accept="application/pdf,image/jpeg,image/png" class="block w-full text-sm text-slate-200"></div>';
        $html .= '<div class="md:col-span-2"><button type="submit" class="inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Submit Identity Verification</button></div>';
        $html .= '</form>';
        return $html . '</div>';
    }

    private function kycStatus(array $a, ?array $u): string
    {
        if (!is_array($u)) {
            return '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 text-slate-200">Please sign in to view Identity Verification (Know Your Customer Compliance) status.</div>';
        }
        $uid = (int) ($u['id'] ?? 0);
        $status = strtolower(trim($this->latestUserMeta($uid, 'gigtune_kyc_status')));
        if ($status === '') {
            $status = 'unsubmitted';
        }
        $labels = ['unsubmitted' => 'Not submitted', 'pending' => 'Pending review', 'verified' => 'Verified', 'rejected' => 'Rejected', 'locked' => 'Security locked'];
        $statusLabel = $labels[$status] ?? ucfirst($status);
        return '<div class="rounded-2xl border border-white/10 bg-white/5 p-6"><h2 class="text-2xl font-bold text-white">Identity Verification (Know Your Customer Compliance) status</h2><p class="mt-2 text-sm text-slate-300">Current status: <span class="font-semibold text-slate-100">' . e($statusLabel) . '</span></p><a href="/kyc/" class="mt-4 inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Open KYC submission page</a></div>';
    }

    private function securityCentre(array $a, ?array $u): string
    {
        if (!is_array($u)) {
            return '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 text-slate-200">Please sign in to view security settings.</div>';
        }
        $uid = (int) ($u['id'] ?? 0);
        $policy = $this->users->getPolicyStatus($uid);
        $kyc = $this->latestUserMeta($uid, 'gigtune_kyc_status');
        $emailVerified = $this->latestUserMeta($uid, 'gigtune_email_verified') === '1';
        return '<div class="rounded-2xl border border-white/10 bg-white/5 p-6"><h2 class="text-2xl font-bold text-white">Security centre</h2><div class="mt-4 grid gap-3 md:grid-cols-2 text-sm text-slate-200"><div>Email verification: <span class="text-slate-100">' . ($emailVerified ? 'Verified' : 'Pending') . '</span></div><div>Policy acceptance: <span class="text-slate-100">' . ((bool) ($policy['has_latest'] ?? false) ? 'Up to date' : 'Action required') . '</span></div><div>Identity Verification (Know Your Customer Compliance): <span class="text-slate-100">' . e($kyc !== '' ? ucfirst($kyc) : 'Unsubmitted') . '</span></div></div><div class="mt-4 flex flex-wrap gap-2"><a href="/kyc-status/" class="inline-flex items-center rounded-lg bg-white/10 px-3 py-2 text-xs text-white hover:bg-white/15">Open KYC status</a><a href="/policy-consent/" class="inline-flex items-center rounded-lg bg-white/10 px-3 py-2 text-xs text-white hover:bg-white/15">Review policies</a></div></div>';
    }

    private function policyConsent(array $a, ?array $u, array $ctx = []): string
    {
        if (!is_array($u)) {
            return '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 text-slate-200"><p>Please sign in to continue.</p><a href="/sign-in/" class="mt-4 inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Sign in</a></div>';
        }
        $uid = (int) ($u['id'] ?? 0);
        $request = $this->req($ctx);
        $submitError = '';
        if ($request !== null && strtoupper((string) $request->method()) === 'POST' && (string) $request->input('gigtune_policy_consent_submit', '') === '1') {
            $accepted = $this->users->mapAcceptedPolicyInput($request->all());
            $required = array_keys($this->users->requiredPolicyVersions());
            $missing = array_values(array_diff($required, $accepted));
            if ($missing === []) {
                // Keep parity with legacy plugin behavior: submit implies all required policies.
                $this->users->storePolicyAcceptance($uid, $required);
            } else {
                $submitError = 'Please accept all required policies before continuing.';
            }
        }
        $policy = $this->users->getPolicyStatus($uid);
        if ((bool) ($policy['has_latest'] ?? false)) {
            return '<div class="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-6 text-emerald-200"><p class="text-lg font-semibold">Policies accepted</p><p class="mt-2 text-sm text-emerald-100/90">Your policy acceptance is up to date.</p><a href="' . e((string) ($u['dashboard_url'] ?? '/')) . '" class="mt-4 inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Continue</a></div>';
        }
        $required = is_array($policy['required'] ?? null) ? $policy['required'] : [];
        $documents = is_array($policy['documents'] ?? null) ? $policy['documents'] : [];
        $html = '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8"><h2 class="text-2xl font-bold text-white">Policy acceptance required</h2><p class="mt-2 text-sm text-slate-300">Please accept the latest policies to continue using your dashboard.</p><form method="post" class="mt-5 space-y-4"><input type="hidden" name="gigtune_policy_consent_submit" value="1">';
        if ($submitError !== '') {
            $html .= '<div class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">' . e($submitError) . '</div>';
        }
        foreach ($required as $key => $version) {
            $url = (string) ($documents[$key] ?? '/');
            $html .= '<label class="flex items-start gap-3 rounded-xl border border-white/10 bg-black/20 p-4 text-sm text-slate-200"><input type="checkbox" name="' . e((string) $key) . '" value="1" class="mt-0.5 h-4 w-4 rounded border-white/20 bg-transparent text-blue-500 focus:ring-blue-400" required><span>I agree to policy <a class="text-blue-300 hover:text-blue-200 underline" href="' . e($url) . '" target="_blank" rel="noopener">' . e((string) $key) . ' (' . e((string) $version) . ')</a>.</span></label>';
        }
        $html .= '<button type="submit" class="inline-flex w-full items-center justify-center rounded-xl px-6 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Accept and continue</button></form></div>';
        return $html;
    }
    private function register(array $a): string
    {
        return '<a href="/join/" class="inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Open registration</a>';
    }

    private function adminLogin(array $a): string { return '<a href="/secret-admin-login-security" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Open Admin Login</a>'; }
    private function adminDashboard(array $a, ?array $u = null): string
    {
        if (!is_array($u)) {
            return '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 text-slate-200">Please sign in to view your dashboard.<div class="mt-4"><a href="/secret-admin-login-security" class="inline-flex items-center justify-center rounded-xl px-6 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500 shadow-sm min-w-[140px]">Admin sign in</a></div></div>';
        }
        if (!(bool) ($u['is_admin'] ?? false)) {
            return '<div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-6 text-rose-200">Access denied.</div>';
        }

        return '<div class="rounded-2xl border border-white/10 bg-white/5 p-6">'
            . '<h3 class="text-lg font-semibold text-white">Admin Dashboard</h3>'
            . '<p class="mt-2 text-sm text-slate-300">Open the full control center for users, bookings, payments, payouts, disputes, refunds, KYC, and reports.</p>'
            . '<div class="mt-4 flex flex-wrap gap-2">'
            . '<a href="/admin-dashboard/" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Open Dashboard</a>'
            . '<a href="/gts-admin-users" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white bg-white/10 hover:bg-white/15">Open Users</a>'
            . '</div>'
            . '</div>';
    }

    private function adminMaintenance(array $a, ?array $u = null): string
    {
        if (!is_array($u) || !(bool) ($u['is_admin'] ?? false)) {
            return '<div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-6 text-rose-200">Access denied.</div>';
        }

        return '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8">'
            . '<h2 class="text-2xl font-bold text-white">Admin Maintenance</h2>'
            . '<p class="mt-2 text-sm text-slate-300">Factory reset permanently removes GigTune platform data while keeping WordPress-style users and core settings intact.</p>'
            . '<div class="mt-5 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-200 space-y-2">'
            . '<p class="font-semibold">Warning</p>'
            . '<p>This action deletes bookings, notifications, messages, disputes, payouts, KYC submissions, and related GigTune metadata.</p>'
            . '</div>'
            . '<div class="mt-5 flex flex-wrap gap-2">'
            . '<a href="/admin/maintenance" class="inline-flex items-center justify-center rounded-xl px-6 py-3 text-sm font-semibold text-white bg-white/10 hover:bg-white/15">Open maintenance controls</a>'
            . '<a href="/admin-dashboard" class="inline-flex items-center justify-center rounded-xl px-6 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Back to dashboard</a>'
            . '</div>'
            . '</div>';
    }

    private function signOut(array $a): string { return '<a href="/sign-out/?redirect_to=/" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white">Sign Out</a>'; }
    private function verifyEmail(array $a, ?array $u = null, array $ctx = []): string
    {
        $request = $this->req($ctx);
        $statusHtml = '';
        $verifiedSuccess = false;

        $token = trim((string) ($request?->query('token', $request?->input('token', '')) ?? ''));
        $targetUserId = (int) ($request?->query('user_id', $request?->input('user_id', 0)) ?? 0);
        if ($token !== '') {
            $tokenHash = hash('sha256', $token);
            if ($targetUserId <= 0) {
                $targetUserId = $this->findUserIdByMetaTokenHash('gigtune_email_verification_token_hash', $tokenHash);
            }

            if ($targetUserId <= 0) {
                $statusHtml = '<div class="mt-4 rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">Invalid verification link.</div>';
            } else {
                $storedHash = trim($this->latestUserMeta($targetUserId, 'gigtune_email_verification_token_hash'));
                $expiresAt = (int) $this->latestUserMeta($targetUserId, 'gigtune_email_verification_expires_at');
                if ($storedHash === '' || $expiresAt <= 0) {
                    $statusHtml = '<div class="mt-4 rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">Verification token is missing or expired.</div>';
                } elseif ($expiresAt < time()) {
                    $statusHtml = '<div class="mt-4 rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">Verification link has expired.</div>';
                } elseif (!hash_equals($storedHash, $tokenHash)) {
                    $statusHtml = '<div class="mt-4 rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">Verification token is invalid.</div>';
                } else {
                    $this->upsertUserMeta($targetUserId, 'gigtune_email_verified', '1');
                    $this->upsertUserMeta($targetUserId, 'gigtune_email_verification_required', '0');
                    $this->upsertUserMeta($targetUserId, 'gigtune_email_verified_at', (string) now()->timestamp);
                    $this->upsertUserMeta($targetUserId, 'gigtune_email_verification_token_hash', '');
                    $this->upsertUserMeta($targetUserId, 'gigtune_email_verification_expires_at', '');
                    $this->upsertUserMeta($targetUserId, 'gigtune_email_verification_delivery', 'confirmed');
                    $statusHtml = '<div class="mt-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-200">Email verified successfully. You can continue using GigTune.</div>';
                    $verifiedSuccess = true;
                }
            }
        }

        $verifyError = strtolower(trim((string) ($request?->query('verify_error', '') ?? '')));
        if ($verifyError !== '' && $statusHtml === '') {
            $message = match ($verifyError) {
                'invalid_nonce' => 'Security check failed. Please try again.',
                'send_failed' => 'Unable to send verification email right now. Please try again.',
                'login_required' => 'Please sign in to resend verification email.',
                'rate_limited' => 'Too many attempts, try again later.',
                default => 'Unable to process verification request.',
            };
            $statusHtml = '<div class="mt-4 rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">' . e($message) . '</div>';
        } elseif ($statusHtml === '' && strtolower(trim((string) ($request?->query('verify_sent', '') ?? ''))) === '1') {
            $statusHtml = '<div class="mt-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-200">Verification email sent. Check your inbox.</div>';
        }

        $resent = false;
        $deliveryFailed = false;
        if ($request !== null && strtoupper((string) $request->method()) === 'POST' && (string) $request->input('gigtune_resend_verification_submit', '') === '1' && is_array($u)) {
            $uid = (int) ($u['id'] ?? 0);
            if ($uid > 0) {
                $token = bin2hex(random_bytes(16));
                $this->upsertUserMeta($uid, 'gigtune_email_verification_token_hash', hash('sha256', $token));
                $this->upsertUserMeta($uid, 'gigtune_email_verification_expires_at', (string) (time() + 172800));
                $this->upsertUserMeta($uid, 'gigtune_email_verification_sent_at', now()->format('Y-m-d H:i:s'));
                $this->upsertUserMeta($uid, 'gigtune_email_verification_required', '1');
                $mailSent = $this->mail->sendVerificationEmail($uid, $token);
                $this->upsertUserMeta($uid, 'gigtune_email_verification_delivery', $mailSent ? 'sent' : 'failed');
                $resent = true;
                $deliveryFailed = !$mailSent;
            }
        }

        if (!is_array($u)) {
            $html = '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8"><h2 class="text-2xl font-bold text-white">Verify your email</h2><p class="mt-2 text-sm text-slate-300">Email verification is required before booking creation and payment initiation.</p>';
            if ($statusHtml !== '') {
                $html .= $statusHtml;
            }
            if ($verifiedSuccess) {
                $html .= '<a href="/sign-in/" class="mt-4 inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Continue</a>';
            } else {
                $html .= '<a href="/sign-in/" class="mt-4 inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Sign in</a>';
            }
            return $html . '</div>';
        }

        $uid = (int) ($u['id'] ?? 0);
        $isVerified = $this->latestUserMeta($uid, 'gigtune_email_verified') === '1';
        $html = '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8"><h2 class="text-2xl font-bold text-white">Verify your email</h2><p class="mt-2 text-sm text-slate-300">Email verification is required before booking creation and payment initiation.</p>';
        if ($statusHtml !== '') {
            $html .= $statusHtml;
        }
        if ($verifiedSuccess) {
            $html .= '<a href="' . e((string) ($u['dashboard_url'] ?? '/')) . '" class="mt-4 inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Continue</a>';
            return $html . '</div>';
        }
        if ($isVerified) {
            $html .= '<div class="mt-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-200">Your email is already verified.</div>';
            $html .= '<a href="' . e((string) ($u['dashboard_url'] ?? '/')) . '" class="mt-4 inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Open dashboard</a>';
            return $html . '</div>';
        }
        if ($resent) {
            if ($deliveryFailed) {
                $html .= '<div class="mt-4 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-100">Verification token was generated, but email delivery failed. Check mail configuration/logs.</div>';
            } else {
                $html .= '<div class="mt-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-200">Verification email sent. Check your inbox.</div>';
            }
        }
        $html .= '<form method="post" class="mt-4 space-y-3"><input type="hidden" name="gigtune_resend_verification_submit" value="1"><button type="submit" class="inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Resend verification email</button></form>';
        return $html . '</div>';
    }

    private function forgotPassword(array $a, ?array $u = null, array $ctx = []): string
    {
        $request = $this->req($ctx);
        $sent = false;
        $deliveryFailed = false;
        if ($request !== null && strtoupper((string) $request->method()) === 'POST' && (string) $request->input('gigtune_forgot_password_submit', '') === '1') {
            $identifier = trim((string) $request->input('gigtune_forgot_identifier', ''));
            $target = $identifier !== '' ? $this->users->getUserByIdentifier($identifier) : null;
            if (is_array($target)) {
                $uid = (int) ($target['id'] ?? 0);
                if ($uid > 0) {
                    $token = bin2hex(random_bytes(24));
                    $this->upsertUserMeta($uid, 'gigtune_password_reset_token_hash', hash('sha256', $token));
                    $this->upsertUserMeta($uid, 'gigtune_password_reset_expires_at', (string) (time() + 7200));
                    $this->upsertUserMeta($uid, 'gigtune_password_reset_requested_at', now()->format('Y-m-d H:i:s'));
                    $mailSent = $this->mail->sendPasswordResetEmail($uid, $token);
                    $this->upsertUserMeta($uid, 'gigtune_password_reset_delivery', $mailSent ? 'sent' : 'failed');
                    $deliveryFailed = !$mailSent;
                }
            }
            $sent = true;
        }
        $html = '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8"><h2 class="text-2xl font-bold text-white">Forgot password</h2><p class="mt-2 text-sm text-slate-300">Enter your email or username and a reset flow token will be generated.</p>';
        if ($sent) {
            if ($deliveryFailed) {
                $html .= '<div class="mt-4 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-100">If an account exists, a reset token was generated but email delivery failed. Check mail configuration/logs.</div>';
            } else {
                $html .= '<div class="mt-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-200">If an account exists for that email or username, a reset link has been sent.</div>';
            }
        }
        $html .= '<form method="post" class="mt-4 space-y-4"><input type="hidden" name="gigtune_forgot_password_submit" value="1"><div><label class="mb-2 block text-sm font-semibold text-slate-200" for="gigtune_forgot_identifier">Email or username</label><input id="gigtune_forgot_identifier" type="text" name="gigtune_forgot_identifier" required class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div><button type="submit" class="inline-flex w-full items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Send reset link</button></form></div>';
        return $html;
    }

    private function resetPassword(array $a, ?array $u = null, array $ctx = []): string
    {
        $request = $this->req($ctx);
        $token = trim((string) ($request?->query('token', $request?->input('token', '')) ?? ''));
        $targetUserId = (int) ($request?->query('user_id', $request?->input('user_id', 0)) ?? 0);
        $statusHtml = '';
        $done = false;

        $resolveByToken = static function (string $rawToken): string {
            return hash('sha256', $rawToken);
        };

        if ($token !== '' && $targetUserId <= 0) {
            $targetUserId = $this->findUserIdByMetaTokenHash('gigtune_password_reset_token_hash', $resolveByToken($token));
        }

        $tokenValid = false;
        if ($token !== '' && $targetUserId > 0) {
            $storedHash = trim($this->latestUserMeta($targetUserId, 'gigtune_password_reset_token_hash'));
            $expiresAt = (int) $this->latestUserMeta($targetUserId, 'gigtune_password_reset_expires_at');
            if ($storedHash !== '' && $expiresAt > 0 && $expiresAt >= time() && hash_equals($storedHash, $resolveByToken($token))) {
                $tokenValid = true;
            }
        }

        if ($request !== null && strtoupper((string) $request->method()) === 'POST' && (string) $request->input('gigtune_reset_password_submit', '') === '1') {
            $password = (string) $request->input('gigtune_reset_password', '');
            $confirm = (string) $request->input('gigtune_reset_password_confirm', '');
            if (!$tokenValid) {
                $statusHtml = '<div class="mt-4 rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">Password reset link is invalid or expired.</div>';
            } elseif ($password === '' || $confirm === '') {
                $statusHtml = '<div class="mt-4 rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">Please enter your new password in both fields.</div>';
            } elseif ($password !== $confirm) {
                $statusHtml = '<div class="mt-4 rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">Passwords do not match.</div>';
            } elseif (strlen($password) < 8) {
                $statusHtml = '<div class="mt-4 rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">Password must be at least 8 characters.</div>';
            } else {
                try {
                    $this->users->updateUserPassword($targetUserId, $password);
                    $this->upsertUserMeta($targetUserId, 'gigtune_password_reset_token_hash', '');
                    $this->upsertUserMeta($targetUserId, 'gigtune_password_reset_expires_at', '');
                    $this->upsertUserMeta($targetUserId, 'gigtune_password_reset_completed_at', now()->format('Y-m-d H:i:s'));
                    $done = true;
                } catch (\Throwable) {
                    $statusHtml = '<div class="mt-4 rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">Unable to reset password right now. Please try again.</div>';
                }
            }
        }

        $html = '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8"><h2 class="text-2xl font-bold text-white">Reset password</h2><p class="mt-2 text-sm text-slate-300">Set a new password using your reset token.</p>';
        if ($statusHtml !== '') {
            $html .= $statusHtml;
        }
        if ($token === '' || $targetUserId <= 0 || (!$tokenValid && !$done)) {
            return $html . '<div class="mt-4 rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">Password reset link is invalid or incomplete.</div></div>';
        }
        if ($done) {
            $html .= '<div class="mt-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-200">Password reset complete. You can sign in now.</div>';
            $html .= '<a href="/sign-in/" class="mt-4 inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Sign in</a>';
            return $html . '</div>';
        }
        $html .= '<form method="post" class="mt-4 space-y-4"><input type="hidden" name="gigtune_reset_password_submit" value="1"><input type="hidden" name="token" value="' . e($token) . '"><input type="hidden" name="user_id" value="' . e((string) $targetUserId) . '"><div><label class="mb-2 block text-sm font-semibold text-slate-200" for="gigtune_reset_password">New password</label><input id="gigtune_reset_password" type="password" name="gigtune_reset_password" required class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div><div><label class="mb-2 block text-sm font-semibold text-slate-200" for="gigtune_reset_password_confirm">Confirm password</label><input id="gigtune_reset_password_confirm" type="password" name="gigtune_reset_password_confirm" required class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div><button type="submit" class="inline-flex w-full items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Reset password</button></form></div>';
        return $html;
    }
    private function yocoSuccess(array $a): string
    {
        $bookingId = (int) (request()->query('booking_id', $a['booking_id'] ?? 0));
        if ($bookingId <= 0) {
            return '<div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">Payment completed successfully.</div>';
        }

        $bookingExists = $this->db()->table($this->posts())
            ->where('ID', $bookingId)
            ->where('post_type', 'gigtune_booking')
            ->exists();
        if (!$bookingExists) {
            return '<div class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">Booking not found for payment callback.</div>';
        }

        $meta = $this->postMetaMap([$bookingId], [
            'gigtune_booking_status',
            'gigtune_booking_client_user_id',
            'gigtune_booking_artist_profile_id',
            'gigtune_payment_status',
        ])[$bookingId] ?? [];
        $nowTs = (string) now()->timestamp;
        $bookingStatus = strtoupper(trim((string) ($meta['gigtune_booking_status'] ?? '')));
        $paymentStatus = strtoupper(trim((string) ($meta['gigtune_payment_status'] ?? '')));
        if (!in_array($paymentStatus, ['CONFIRMED_HELD_PENDING_COMPLETION', 'PAID_ESCROWED', 'ESCROW_FUNDED'], true)) {
            $this->upsertPostMeta($bookingId, 'gigtune_payment_method', 'yoco');
            $this->upsertPostMeta($bookingId, 'gigtune_payment_status', 'AWAITING_PAYMENT_CONFIRMATION');
            $this->upsertPostMeta($bookingId, 'gigtune_payment_reported_at', $nowTs);
            $this->upsertPostMeta($bookingId, 'gigtune_payment_last_note', 'Card payment completed. Awaiting admin confirmation.');
            if ($bookingStatus === 'ACCEPTED_PENDING_PAYMENT') {
                $this->upsertPostMeta($bookingId, 'gigtune_booking_status', 'AWAITING_PAYMENT_CONFIRMATION');
            }
        }

        $clientUserId = (int) ($meta['gigtune_booking_client_user_id'] ?? 0);
        $artistProfileId = (int) ($meta['gigtune_booking_artist_profile_id'] ?? 0);
        $artistOwnerId = 0;
        if ($artistProfileId > 0) {
            $artistProfileMeta = $this->postMetaMap([$artistProfileId], ['gigtune_artist_user_id', 'gigtune_user_id'])[$artistProfileId] ?? [];
            $artistOwnerId = (int) ($artistProfileMeta['gigtune_user_id'] ?? $artistProfileMeta['gigtune_artist_user_id'] ?? 0);
            if ($artistOwnerId <= 0) {
                $artistOwnerId = (int) $this->db()->table($this->posts())->where('ID', $artistProfileId)->value('post_author');
            }
        }
        if ($clientUserId > 0) {
            $this->createNotification($clientUserId, 'payment', 'Card payment submitted for booking #' . $bookingId . '. Awaiting confirmation.', ['object_type' => 'booking', 'object_id' => $bookingId]);
        }
        if ($artistOwnerId > 0) {
            $this->createNotification($artistOwnerId, 'payment', 'Client payment submitted for booking #' . $bookingId . '. Awaiting confirmation.', ['object_type' => 'booking', 'object_id' => $bookingId]);
        }
        foreach ($this->adminUserIds() as $adminUserId) {
            $this->createNotification($adminUserId, 'payment', 'Card payment submitted for booking #' . $bookingId . '. Review payment queue.', ['object_type' => 'booking', 'object_id' => $bookingId]);
        }

        return $this->redirectInline('/messages/?booking_id=' . $bookingId . '&payment_result=success');
    }

    private function yocoCancel(array $a): string
    {
        $bookingId = (int) (request()->query('booking_id', $a['booking_id'] ?? 0));
        if ($bookingId <= 0) {
            return '<div class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">Payment canceled.</div>';
        }

        $bookingExists = $this->db()->table($this->posts())
            ->where('ID', $bookingId)
            ->where('post_type', 'gigtune_booking')
            ->exists();
        if ($bookingExists) {
            $this->upsertPostMeta($bookingId, 'gigtune_payment_status', 'FAILED');
            $this->upsertPostMeta($bookingId, 'gigtune_payment_last_note', 'Payment cancelled by user.');
        }

        return $this->redirectInline('/messages/?booking_id=' . $bookingId . '&payment_result=cancelled');
    }
    private function wooCart(array $a): string { return '<div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">Cart handled through booking flow.</div>'; }
    private function wooCheckout(array $a): string { return '<div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">Checkout handled via Yoco/Paystack.</div>'; }

    private function dashboardShell(?array $u, bool $artist, array $ctx = []): string
    {
        if (!is_array($u)) {
            return '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">Sign in required.</div>';
        }

        $title = $artist ? 'Artist Dashboard' : 'Client Dashboard';
        $primaryCta = $artist
            ? '<a href="/artist-profile-edit/" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Edit Profile</a>'
            : '<a href="/book-an-artist/" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Book an Artist</a>';
        $secondaryCta = $artist
            ? '<a href="/artist-availability/" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white bg-white/10 hover:bg-white/15">Manage Availability</a>'
            : '<a href="/client-profile-edit/" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white bg-white/10 hover:bg-white/15">Edit Profile</a>';
        $tertiaryCta = $artist
            ? ''
            : '<a href="/browse-artists/" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white bg-white/10 hover:bg-white/15">Browse Artists</a>';

        $uid = (int) ($u['id'] ?? 0);
        $heroProfileUrl = '';
        $heroBannerUrl = '';
        $heroSubtitle = $artist ? 'Artist account' : 'Client account';
        $heroRatingDisplay = '0';
        if ($artist) {
            $artistProfileId = $this->latestUserMetaInt($uid, 'gigtune_artist_profile_id');
            if ($artistProfileId > 0) {
                $artistMeta = $this->postMetaMap([$artistProfileId], [
                    'gigtune_artist_photo_id',
                    'gigtune_artist_banner_id',
                    'gigtune_artist_base_area',
                    'gigtune_performance_rating_avg',
                ])[$artistProfileId] ?? [];
                $heroProfileUrl = $this->attachmentUrl((int) ($artistMeta['gigtune_artist_photo_id'] ?? 0));
                $heroBannerUrl = $this->attachmentUrl((int) ($artistMeta['gigtune_artist_banner_id'] ?? 0));
                $heroRating = (float) ($artistMeta['gigtune_performance_rating_avg'] ?? 0);
                if ($heroRating > 0) {
                    $heroRatingDisplay = number_format($heroRating, 1);
                }
                $baseArea = trim((string) ($artistMeta['gigtune_artist_base_area'] ?? ''));
                if ($baseArea !== '') {
                    $heroSubtitle = 'Base area: ' . $baseArea;
                }
            }
        } else {
            $clientProfileId = $this->latestUserMetaInt($uid, 'gigtune_client_profile_id');
            if ($clientProfileId > 0) {
                $clientMeta = $this->postMetaMap([$clientProfileId], [
                    'gigtune_client_photo_id',
                    'gigtune_client_banner_id',
                    'gigtune_client_company',
                    'gigtune_client_base_area',
                    'gigtune_client_user_id',
                ])[$clientProfileId] ?? [];
                $heroProfileUrl = $this->attachmentUrl((int) ($clientMeta['gigtune_client_photo_id'] ?? 0));
                $heroBannerUrl = $this->attachmentUrl((int) ($clientMeta['gigtune_client_banner_id'] ?? 0));
                $company = trim((string) ($clientMeta['gigtune_client_company'] ?? ''));
                $baseArea = trim((string) ($clientMeta['gigtune_client_base_area'] ?? ''));
                $ratingClientUserId = (int) ($clientMeta['gigtune_client_user_id'] ?? $uid);
                if ($ratingClientUserId > 0) {
                    $ratingRows = $this->db()->table($this->posts() . ' as p')
                        ->where('p.post_type', 'gt_client_rating')
                        ->where('p.post_status', 'publish')
                        ->whereExists(function ($q) use ($ratingClientUserId): void {
                            $q->selectRaw('1')
                                ->from($this->pm() . ' as pm')
                                ->whereColumn('pm.post_id', 'p.ID')
                                ->where('pm.meta_key', 'gigtune_client_rating_client_user_id')
                                ->where('pm.meta_value', (string) $ratingClientUserId);
                        })
                        ->pluck('p.ID')
                        ->all();
                    if (is_array($ratingRows) && $ratingRows !== []) {
                        $ratingIds = array_map(static fn ($value): int => (int) $value, $ratingRows);
                        $ratingsMeta = $this->postMetaMap($ratingIds, ['gigtune_client_rating_overall_avg']);
                        $sum = 0.0;
                        $count = 0;
                        foreach ($ratingIds as $ratingId) {
                            $avg = (float) ($ratingsMeta[$ratingId]['gigtune_client_rating_overall_avg'] ?? 0);
                            if ($avg > 0) {
                                $sum += $avg;
                                $count++;
                            }
                        }
                        if ($count > 0) {
                            $heroRatingDisplay = number_format($sum / $count, 1);
                        }
                    }
                }
                if ($company !== '' && $baseArea !== '') {
                    $heroSubtitle = $company . ' - ' . $baseArea;
                } elseif ($company !== '') {
                    $heroSubtitle = $company;
                } elseif ($baseArea !== '') {
                    $heroSubtitle = 'Base area: ' . $baseArea;
                }
            }
        }
        $policy = $this->users->getPolicyStatus($uid);
        $userMeta = $this->userMetaLatestMap([$uid], [
            'gigtune_email_verification_required',
            'gigtune_email_verified',
        ])[$uid] ?? [];
        $kycStatus = strtolower(trim($this->latestUserMeta($uid, 'gigtune_kyc_status')));
        if ($kycStatus === '') {
            $kycStatus = 'unsubmitted';
        }
        $kycLabel = [
            'unsubmitted' => 'Not submitted',
            'pending' => 'Pending review',
            'verified' => 'Verified',
            'rejected' => 'Rejected',
            'locked' => 'Security locked',
        ][$kycStatus] ?? ucfirst($kycStatus);
        $emailVerified = $this->userEmailVerified($userMeta);
        $requirementsContext = $artist ? 'artist_bookable' : 'client_can_book';
        $missingRequirements = $this->missingRequirements($uid, $requirementsContext);

        $html = '<div class="grid gap-6 lg:grid-cols-3">';
        $html .= '<div class="lg:col-span-2 space-y-6">';
        if ($missingRequirements !== []) {
            $requirementsTitle = $artist
                ? 'Complete these steps to make your artist profile discoverable'
                : 'Complete these steps to unlock booking actions';
            $html .= $this->renderMissingRequirementsPanel($requirementsTitle, $missingRequirements);
        }
        $html .= '<div class="bg-slate-800 rounded-2xl border border-slate-700 overflow-hidden">';
        $html .= '<div class="h-56 md:h-64 bg-slate-700 relative"' . ($heroBannerUrl !== '' ? (' style="background-image:url(' . e($heroBannerUrl) . ');background-size:cover;background-position:center;"') : '') . '>';
        $html .= '<div class="w-full h-full bg-gradient-to-t from-slate-900 to-transparent absolute bottom-0 z-10"></div>';
        $html .= '<div class="absolute inset-0 flex items-center justify-center text-slate-500"><svg class="w-16 h-16 opacity-10" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path><path d="M22 4 12 14.01l-3-3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg></div>';
        if ($heroProfileUrl !== '') {
            $html .= '<div class="absolute inset-0 flex items-center justify-center z-20"><img src="' . e($heroProfileUrl) . '" alt="' . e((string) ($u['display_name'] ?? $u['login'] ?? $title)) . '" class="w-28 h-28 md:w-32 md:h-32 rounded-full object-cover border border-slate-600 shadow-lg"></div>';
        } else {
            $html .= '<div class="absolute inset-0 flex items-center justify-center z-20"><div class="w-28 h-28 md:w-32 md:h-32 rounded-full border border-slate-600 bg-slate-900/80 flex items-center justify-center text-slate-300 text-xs">No photo</div></div>';
        }
        $html .= '<div class="absolute top-4 right-4 z-20"><span class="bg-slate-900/80 backdrop-blur text-white text-xs font-bold px-2 py-1 rounded flex items-center gap-1"><span class="w-3 h-3 text-yellow-400"><svg class="w-3 h-3 text-yellow-400 fill-yellow-400" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2.5l2.95 6 6.62.96-4.79 4.67 1.13 6.6L12 17.9l-5.91 3.11 1.13-6.6L2.43 9.46l6.62-.96L12 2.5Z"></path></svg></span>' . e($heroRatingDisplay) . '</span></div>';
        $html .= '</div>';
        $html .= '<div class="p-6 md:p-8">';
        $html .= '<div class="flex flex-col md:flex-row md:items-start md:justify-between gap-6">';
        $html .= '<div class="min-w-0">';
        $html .= '<div class="flex items-center gap-3 mb-2"><h1 class="text-2xl md:text-3xl lg:text-4xl font-bold text-white">' . e((string) ($u['display_name'] ?? $u['login'] ?? $title)) . '</h1><span class="text-blue-400 w-5 h-5 flex-shrink-0"><svg class="w-5 h-5 text-blue-400" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path><path d="M22 4 12 14.01l-3-3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg></span></div>';
        $html .= '<p class="text-slate-400 text-sm md:text-base">' . e($artist ? 'Artist' : 'Client') . ' <span class="text-slate-600">&bull;</span> ' . e($heroSubtitle) . '</p>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="rounded-2xl border border-white/10 bg-white/5 p-6">';
        $html .= '<h3 class="text-lg font-semibold text-white">' . e($title) . '</h3>';
        $html .= '<p class="mt-2 text-sm text-slate-300">Manage bookings, messages, notifications, and account compliance.</p>';
        $html .= '<div class="mt-4 flex flex-wrap gap-3">' . $primaryCta . $secondaryCta . $tertiaryCta . '<a href="/notifications/" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white bg-white/10 hover:bg-white/15">Notifications</a></div>';
        $html .= '</div>';
        $html .= '<div class="rounded-2xl border border-white/10 bg-white/5 p-6">';
        $html .= '<h3 class="text-lg font-semibold text-white">Snapshot</h3>';
        $html .= '<div class="mt-4">' . $this->snapshot($u, $artist) . '</div>';
        $html .= '</div>';
        $html .= '<div class="rounded-2xl border border-white/10 bg-white/5 p-6">';
        $html .= '<h3 class="text-lg font-semibold text-white">Recent Bookings</h3>';
        $html .= '<div class="mt-4">' . $this->bookingsTable($u, $artist, $ctx) . '</div>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<div class="space-y-6">';
        $html .= '<div class="rounded-2xl border border-white/10 bg-white/5 p-6">';
        $html .= '<h3 class="text-lg font-semibold text-white">Legal &amp; Compliance</h3>';
        $html .= '<div class="mt-3 space-y-2 text-sm text-slate-300">';
        $html .= '<p>Email verification: <span class="text-slate-100">' . ($emailVerified ? 'Verified' : 'Pending') . '</span></p>';
        $html .= '<p>Policies: <span class="text-slate-100">' . ((bool) ($policy['has_latest'] ?? false) ? 'Accepted' : 'Action required') . '</span></p>';
        $html .= '<p>Identity Verification (Know Your Customer Compliance): <span class="text-slate-100">' . e($kycLabel) . '</span></p>';
        $html .= '</div>';
        $html .= '<div class="mt-4 flex flex-wrap gap-2">';
        $html .= '<a href="/kyc-status/" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-xs font-semibold text-white bg-white/10 hover:bg-white/15">KYC status</a>';
        $html .= '<a href="/policy-consent/" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-xs font-semibold text-white bg-white/10 hover:bg-white/15">Policy acceptance</a>';
        $html .= '<a href="/notification-settings/" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-xs font-semibold text-white bg-white/10 hover:bg-white/15">Notification settings</a>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private function bookingsTable(?array $u, bool $artist, array $ctx = []): string
    {
        if (!is_array($u)) {
            return '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">Sign in required.</div>';
        }

        $uid = (int) ($u['id'] ?? 0);
        if ($uid <= 0) {
            return '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">Sign in required.</div>';
        }
        $request = $this->req($ctx);
        $archiveMetaKey = $artist ? 'gigtune_booking_is_archived_artist' : 'gigtune_booking_is_archived_client';
        $statusMessage = '';
        $errorMessage = '';
        $viewerArtistProfileId = 0;
        if ($artist) {
            $viewerArtistProfileId = $this->latestUserMetaInt($uid, 'gigtune_artist_profile_id');
            if ($viewerArtistProfileId <= 0) {
                return '<div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">No linked artist profile found.</div>';
            }
        }

        if (
            $request !== null
            && strtoupper((string) $request->method()) === 'POST'
            && (string) $request->input('gigtune_booking_archive_submit', '') === '1'
        ) {
            $nonce = trim((string) $request->input('gigtune_booking_archive_nonce', ''));
            $action = trim((string) $request->input('gigtune_booking_archive_action', ''));
            $targetBookingId = abs((int) $request->input('gigtune_booking_archive_id', 0));
            if (!$this->verifyWpNonce($nonce, 'gigtune_booking_archive_action')) {
                $errorMessage = 'Security check failed.';
            } elseif ($targetBookingId <= 0 || !in_array($action, ['archive', 'restore'], true)) {
                $errorMessage = 'Invalid booking archive request.';
            } else {
                $targetBooking = $this->db()->table($this->posts())
                    ->where('ID', $targetBookingId)
                    ->where('post_type', 'gigtune_booking')
                    ->first(['ID']);
                if ($targetBooking === null) {
                    $errorMessage = 'Booking not found.';
                } else {
                    $targetMeta = $this->postMetaMap([$targetBookingId], [
                        'gigtune_booking_client_user_id',
                        'gigtune_booking_artist_profile_id',
                        'gigtune_booking_status',
                        'gigtune_booking_event_date',
                        'gigtune_payment_status',
                        'gigtune_payout_status',
                        'gigtune_refund_status',
                    ])[$targetBookingId] ?? [];

                    $ownsBooking = false;
                    if ($artist) {
                        $targetArtistProfileId = (int) ($targetMeta['gigtune_booking_artist_profile_id'] ?? 0);
                        $ownsBooking = $viewerArtistProfileId > 0 && $viewerArtistProfileId === $targetArtistProfileId;
                    } else {
                        $targetClientUserId = (int) ($targetMeta['gigtune_booking_client_user_id'] ?? 0);
                        $ownsBooking = $uid > 0 && $uid === $targetClientUserId;
                    }

                    if (!$ownsBooking) {
                        $errorMessage = 'You do not have permission to archive this booking.';
                    } elseif ($action === 'archive' && !$this->bookingCanBeArchived($targetMeta)) {
                        $errorMessage = 'Only past or closed bookings can be archived.';
                    } else {
                        $this->upsertPostMeta($targetBookingId, $archiveMetaKey, $action === 'archive' ? '1' : '0');
                        $statusMessage = $action === 'archive'
                            ? ('Booking #' . $targetBookingId . ' archived.')
                            : ('Booking #' . $targetBookingId . ' restored.');
                    }
                }
            }
        }

        $query = $this->db()->table($this->posts() . ' as p')
            ->where('p.post_type', 'gigtune_booking')
            ->orderByDesc('p.ID');

        if ($artist) {
            $query->whereExists(function ($q) use ($viewerArtistProfileId): void {
                $q->selectRaw('1')
                    ->from($this->pm() . ' as pm')
                    ->whereColumn('pm.post_id', 'p.ID')
                    ->where('pm.meta_key', 'gigtune_booking_artist_profile_id')
                    ->where('pm.meta_value', (string) $viewerArtistProfileId);
            });
        } else {
            $query->whereExists(function ($q) use ($uid): void {
                $q->selectRaw('1')
                    ->from($this->pm() . ' as pm')
                    ->whereColumn('pm.post_id', 'p.ID')
                    ->where('pm.meta_key', 'gigtune_booking_client_user_id')
                    ->where('pm.meta_value', (string) $uid);
            });
        }

        $rows = $query->limit(80)->get(['p.ID', 'p.post_title', 'p.post_date']);
        if ($rows->isEmpty() && $statusMessage === '' && $errorMessage === '') {
            return '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">No bookings yet.</div>';
        }

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->ID;
        }
        $metaMap = $this->postMetaMap($ids, [
            'gigtune_booking_status',
            'gigtune_payment_status',
            'gigtune_payout_status',
            'gigtune_booking_event_date',
            'gigtune_booking_client_user_id',
            'gigtune_booking_artist_profile_id',
            'gigtune_refund_status',
            $archiveMetaKey,
        ]);

        $activeRows = [];
        $archivedRows = [];
        foreach ($rows as $row) {
            $id = (int) $row->ID;
            $meta = $metaMap[$id] ?? [];
            $isArchived = trim((string) ($meta[$archiveMetaKey] ?? '')) === '1';
            if ($isArchived) {
                $archivedRows[] = $row;
            } else {
                $activeRows[] = $row;
            }
        }

        $nonce = $this->createWpNonce('gigtune_booking_archive_action');
        $out = '<div class="space-y-4">';
        if ($statusMessage !== '') {
            $out .= '<div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-200">' . e($statusMessage) . '</div>';
        }
        if ($errorMessage !== '') {
            $out .= '<div class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-sm text-rose-200">' . e($errorMessage) . '</div>';
        }

        if ($activeRows === []) {
            $out .= '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">No active bookings.</div>';
        } else {
            $out .= '<div class="space-y-3">';
        }
        foreach (array_slice($activeRows, 0, 20) as $row) {
            $id = (int) $row->ID;
            $meta = $metaMap[$id] ?? [];
            $status = $this->toSentenceCase((string) ($meta['gigtune_booking_status'] ?? ''));
            $payment = $this->toSentenceCase((string) ($meta['gigtune_payment_status'] ?? ''));
            $payout = $this->toSentenceCase((string) ($meta['gigtune_payout_status'] ?? ''));
            $refund = $this->toSentenceCase((string) ($meta['gigtune_refund_status'] ?? ''));
            $eventDate = trim((string) ($meta['gigtune_booking_event_date'] ?? ''));
            $partyId = $artist
                ? (int) ($meta['gigtune_booking_client_user_id'] ?? 0)
                : (int) ($meta['gigtune_booking_artist_profile_id'] ?? 0);
            $partyLink = $artist
                ? '/client-profile/?client_user_id=' . $partyId
                : '/artist-profile/?artist_id=' . $partyId;
            $allowArchive = $this->bookingCanBeArchived($meta);

            $out .= '<article class="rounded-xl border border-white/10 bg-black/20 p-4">';
            $out .= '<div class="flex flex-wrap items-center justify-between gap-3">';
            $out .= '<div class="text-sm font-semibold text-white">Booking #' . $id . '</div>';
            $out .= '<a href="/messages/?booking_id=' . $id . '" class="inline-flex items-center justify-center rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold text-white hover:bg-white/15">View Booking</a>';
            $out .= '</div>';
            $out .= '<div class="mt-2 text-xs text-slate-300">Status: ' . e($status !== '' ? $status : '-') . ' | Payment: ' . e($payment !== '' ? $payment : '-') . ' | Payout: ' . e($payout !== '' ? $payout : '-') . ' | Refund: ' . e($refund !== '' ? $refund : '-') . '</div>';
            $out .= '<div class="mt-1 text-xs text-slate-300">Event: ' . e($eventDate !== '' ? $eventDate : '-') . '</div>';
            $out .= '<div class="mt-3 flex flex-wrap gap-2">';
            if ($partyId > 0) {
                $out .= '<a href="' . e($partyLink) . '" class="inline-flex items-center rounded-lg bg-white/10 px-3 py-2 text-xs text-white hover:bg-white/15">' . ($artist ? 'View Client Profile' : 'View Artist Profile') . '</a>';
            }
            if ($allowArchive) {
                $out .= '<form method="post" class="inline-flex">'
                    . '<input type="hidden" name="gigtune_booking_archive_submit" value="1">'
                    . '<input type="hidden" name="gigtune_booking_archive_nonce" value="' . e($nonce) . '">'
                    . '<input type="hidden" name="gigtune_booking_archive_action" value="archive">'
                    . '<input type="hidden" name="gigtune_booking_archive_id" value="' . $id . '">'
                    . '<button type="submit" class="inline-flex items-center rounded-lg bg-white/10 px-3 py-2 text-xs text-white hover:bg-white/15">Archive</button>'
                    . '</form>';
            }
            $out .= '</div>';
            $out .= '</article>';
        }
        if ($activeRows !== []) {
            $out .= '</div>';
        }

        if ($archivedRows !== []) {
            $out .= '<div class="pt-3"><div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Archived bookings</div><div class="space-y-3">';
            foreach (array_slice($archivedRows, 0, 20) as $row) {
                $id = (int) $row->ID;
                $meta = $metaMap[$id] ?? [];
                $status = $this->toSentenceCase((string) ($meta['gigtune_booking_status'] ?? ''));
                $eventDate = trim((string) ($meta['gigtune_booking_event_date'] ?? ''));
                $out .= '<article class="rounded-xl border border-white/10 bg-black/20 p-4">';
                $out .= '<div class="flex flex-wrap items-center justify-between gap-3">';
                $out .= '<div class="text-sm font-semibold text-white">Booking #' . $id . ' <span class="text-slate-400">Archived</span></div>';
                $out .= '<div class="flex flex-wrap gap-2">';
                $out .= '<a href="/messages/?booking_id=' . $id . '" class="inline-flex items-center justify-center rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold text-white hover:bg-white/15">View Booking</a>';
                $out .= '<form method="post" class="inline-flex">'
                    . '<input type="hidden" name="gigtune_booking_archive_submit" value="1">'
                    . '<input type="hidden" name="gigtune_booking_archive_nonce" value="' . e($nonce) . '">'
                    . '<input type="hidden" name="gigtune_booking_archive_action" value="restore">'
                    . '<input type="hidden" name="gigtune_booking_archive_id" value="' . $id . '">'
                    . '<button type="submit" class="inline-flex items-center rounded-lg bg-white/10 px-3 py-1.5 text-xs text-white hover:bg-white/15">Restore</button>'
                    . '</form>';
                $out .= '</div></div>';
                $out .= '<div class="mt-2 text-xs text-slate-300">Status: ' . e($status !== '' ? $status : '-') . ' | Event: ' . e($eventDate !== '' ? $eventDate : '-') . '</div>';
                $out .= '</article>';
            }
            $out .= '</div></div>';
        }

        return $out . '</div>';
    }

    private function snapshot(?array $u, bool $artist): string
    {
        if (!is_array($u)) {
            return '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">Sign in required.</div>';
        }

        $uid = (int) ($u['id'] ?? 0);
        $bookingsQuery = $this->db()->table($this->posts() . ' as p')
            ->where('p.post_type', 'gigtune_booking');

        if ($artist) {
            $profileId = $this->latestUserMetaInt($uid, 'gigtune_artist_profile_id');
            if ($profileId > 0) {
                $bookingsQuery->whereExists(function ($q) use ($profileId): void {
                    $q->selectRaw('1')
                        ->from($this->pm() . ' as pm')
                        ->whereColumn('pm.post_id', 'p.ID')
                        ->where('pm.meta_key', 'gigtune_booking_artist_profile_id')
                        ->where('pm.meta_value', (string) $profileId);
                });
            } else {
                $bookingsQuery->whereRaw('1 = 0');
            }
        } else {
            $bookingsQuery->whereExists(function ($q) use ($uid): void {
                $q->selectRaw('1')
                    ->from($this->pm() . ' as pm')
                    ->whereColumn('pm.post_id', 'p.ID')
                    ->where('pm.meta_key', 'gigtune_booking_client_user_id')
                    ->where('pm.meta_value', (string) $uid);
            });
        }

        $bookings = (int) $bookingsQuery->count('p.ID');
        $notifications = (int) ($this->notifications->list($uid, $uid, (bool) ($u['is_admin'] ?? false), ['per_page' => 1, 'page' => 1, 'archived' => '0'])['total'] ?? 0);
        $kycStatus = $this->toSentenceCase((string) $this->latestUserMeta($uid, 'gigtune_kyc_status'));
        if ($kycStatus === '') {
            $kycStatus = 'Not submitted';
        }
        return '<div class="grid gap-3 sm:grid-cols-3"><div class="rounded-xl border border-white/10 bg-white/5 p-4"><div class="text-xs text-slate-400">Bookings</div><div class="mt-1 text-2xl font-bold text-white">' . $bookings . '</div></div><div class="rounded-xl border border-white/10 bg-white/5 p-4"><div class="text-xs text-slate-400">Notifications</div><div class="mt-1 text-2xl font-bold text-white">' . $notifications . '</div></div><div class="rounded-xl border border-white/10 bg-white/5 p-4"><div class="text-xs text-slate-400">KYC</div><div class="mt-1 text-sm font-semibold text-white">' . e($kycStatus) . '</div></div></div>';
    }

    private function metaQueryMatch(array $meta, array $query): bool
    {
        $relation = strtolower((string) ($query['relation'] ?? 'and'));
        $checks = [];
        foreach ($query as $key => $value) {
            if ($key === 'relation') {
                continue;
            }
            if (!is_array($value)) {
                continue;
            }
            if (isset($value['relation'])) {
                $checks[] = $this->metaQueryMatch($meta, $value);
                continue;
            }
            $mKey = (string) ($value['key'] ?? '');
            $cmp = strtoupper((string) ($value['compare'] ?? '='));
            $expected = (string) ($value['value'] ?? '');
            $actual = (string) ($meta[$mKey] ?? '');
            $checks[] = match ($cmp) {
                'LIKE' => str_contains(mb_strtolower($actual), mb_strtolower($expected)),
                '>=' => (float) $actual >= (float) $expected,
                '<=' => (float) $actual <= (float) $expected,
                default => $actual === $expected,
            };
        }
        if ($checks === []) {
            return true;
        }
        if ($relation === 'or') {
            foreach ($checks as $check) {
                if ($check) {
                    return true;
                }
            }
            return false;
        }
        foreach ($checks as $check) {
            if (!$check) {
                return false;
            }
        }
        return true;
    }

    private function notificationsList(?array $u, bool $archived, array $ctx = []): string
    {
        if (!is_array($u)) {
            $message = $archived
                ? 'Please sign in to view archived notifications.'
                : 'Please sign in to view notifications.';
            return '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 text-slate-200">' . e($message) . '</div>';
        }
        $id = (int) ($u['id'] ?? 0);
        $request = $this->req($ctx);
        $defaultRedirectTarget = $archived ? '/notifications-archive/' : '/notifications/';
        $redirectTarget = $defaultRedirectTarget;
        if ($request !== null) {
            $referer = trim((string) $request->headers->get('referer', ''));
            if ($referer !== '') {
                $parts = parse_url($referer);
                if (is_array($parts)) {
                    $path = trim((string) ($parts['path'] ?? ''));
                    $pathBase = rtrim($path, '/');
                    $isAllowedPath = in_array($pathBase, ['/notifications', '/notifications-archive'], true);
                    if ($path !== '' && str_starts_with($path, '/') && $isAllowedPath) {
                        $query = isset($parts['query']) && trim((string) $parts['query']) !== ''
                            ? ('?' . (string) $parts['query'])
                            : '';
                        $redirectTarget = $path . $query;
                    }
                }
            }
            if ($redirectTarget === $defaultRedirectTarget) {
                $uri = trim((string) $request->getRequestUri());
                if ($uri !== '' && str_starts_with($uri, '/')) {
                    $uriParts = parse_url($uri);
                    if (is_array($uriParts)) {
                        $uriPath = trim((string) ($uriParts['path'] ?? ''));
                        $uriPathBase = rtrim($uriPath, '/');
                        $isAllowedPath = in_array($uriPathBase, ['/notifications', '/notifications-archive'], true);
                        if ($isAllowedPath) {
                            $uriQuery = isset($uriParts['query']) && trim((string) $uriParts['query']) !== ''
                                ? ('?' . (string) $uriParts['query'])
                                : '';
                            $redirectTarget = $uriPath . $uriQuery;
                        }
                    }
                }
            }
        }
        $heading = $archived ? 'Archived notifications' : 'Notifications';
        if ($request !== null && !$archived) {
            $markReadId = abs((int) $request->query('notification_id', 0));
            if ($markReadId > 0) {
                try {
                    $this->notifications->markRead($markReadId, $id, (bool) ($u['is_admin'] ?? false));
                } catch (\Throwable) {
                    // Ignore mark-read failures.
                }
            }
        }

        // Fallback inline handling when this shortcode is rendered in a non-redirect flow.
        if ($request !== null && strtoupper((string) $request->method()) === 'POST') {
            if (!$archived && (string) $request->input('gigtune_mark_notifications_read', '') === '1') {
                $nonce = (string) $request->input('gigtune_notifications_nonce', '');
                if ($this->verifyWpNonce($nonce, 'gigtune_mark_notifications_read')) {
                    $this->notifications->markAllRead($id, $id, (bool) ($u['is_admin'] ?? false));
                }
            }
            if ($archived && (string) $request->input('gigtune_restore_notification_submit', '') === '1') {
                $nonce = (string) $request->input('gigtune_restore_notification_nonce', '');
                $notificationId = abs((int) $request->input('gigtune_restore_notification_id', 0));
                if ($notificationId > 0 && $this->verifyWpNonce($nonce, 'gigtune_restore_notification')) {
                    $this->notifications->restore($notificationId, $id, (bool) ($u['is_admin'] ?? false));
                }
            }
        }

        $res = $this->notifications->list($id, $id, (bool) ($u['is_admin'] ?? false), [
            'only_archived' => $archived ? '1' : '0',
            'per_page' => 50,
            'page' => 1,
            'order_by' => 'post_date',
        ]);
        $items = is_array($res['items'] ?? null) ? $res['items'] : [];

        $out = '<div class="rounded-2xl border border-white/10 bg-white/5 p-6">';
        $out .= '<div class="flex items-center justify-between gap-4">';
        $out .= '<h2 class="text-lg font-semibold text-white">' . e($heading) . '</h2>';
        $out .= '<div class="flex items-center gap-3">';
        if (!$archived) {
            $out .= '<form method="post">';
            $out .= '<input type="hidden" name="gigtune_notifications_nonce" value="' . e($this->createWpNonce('gigtune_mark_notifications_read')) . '">';
            $out .= '<input type="hidden" name="gigtune_notifications_redirect" value="' . e($redirectTarget) . '">';
            $out .= '<button type="submit" name="gigtune_mark_notifications_read" value="1" class="text-xs text-slate-200/80 hover:text-white underline">Mark all read</button>';
            $out .= '</form>';
            $out .= '<a href="/notification-settings/" class="text-xs text-slate-200/80 hover:text-white underline">Email settings</a>';
            $out .= '<a href="/notifications-archive/" class="text-xs text-slate-200/80 hover:text-white underline">View archive</a>';
        } else {
            $out .= '<a href="/notifications/" class="text-xs text-slate-200/80 hover:text-white underline">Back to notifications</a>';
        }
        $out .= '</div></div>';

        if ($items === []) {
            $out .= '<p class="mt-4 text-sm text-slate-300">No notifications yet.</p>';
            return $out . '</div>';
        }

        $out .= '<div class="mt-4 space-y-3">';
        foreach ($items as $item) {
            $notificationId = (int) ($item['id'] ?? 0);
            $message = trim((string) ($item['message'] ?? ''));
            if ($message === '') {
                $message = (string) ($item['title'] ?? 'Notification');
            }

            $createdAt = (int) ($item['created_at'] ?? 0);
            $isRead = (bool) ($item['is_read'] ?? false);
            $objectType = trim((string) ($item['object_type'] ?? ''));
            $objectId = (int) ($item['object_id'] ?? 0);
            if ($objectId <= 0 && preg_match('/booking\s*#(\d+)/i', $message, $matches) === 1) {
                $objectId = (int) ($matches[1] ?? 0);
                if ($objectType === '') {
                    $objectType = 'booking';
                }
            }

            $targetUrl = '';
            if ($objectType === 'booking' && $objectId > 0) {
                $targetUrl = '/messages/?booking_id=' . $objectId;
                if ($notificationId > 0) {
                    $targetUrl .= '&notification_id=' . $notificationId;
                }
            } elseif ($objectType === 'psa' && $objectId > 0) {
                $targetUrl = $this->psaWorkflowUrl($objectId, $id);
                if ($notificationId > 0) {
                    $targetUrl .= (str_contains($targetUrl, '?') ? '&' : '?') . 'notification_id=' . $notificationId;
                }
            }

            $out .= '<div class="rounded-xl border border-white/10 bg-black/20 p-4">';
            $out .= '<div class="flex items-start justify-between gap-3"><div>';
            $out .= '<div class="text-sm text-white' . ($isRead ? '' : ' font-semibold') . '">' . e($message) . '</div>';
            if ($createdAt > 0) {
                $out .= '<div class="text-xs text-slate-400 mt-1">' . e(date_i18n('M j, Y H:i', $createdAt)) . '</div>';
            }
            $out .= '</div>';
            if ($targetUrl !== '') {
                $out .= '<a href="' . e($targetUrl) . '" class="inline-flex items-center justify-center rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold text-white hover:bg-white/15">Open</a>';
            }
            $out .= '</div>';

            if ($archived && $notificationId > 0) {
                $out .= '<form method="post" class="mt-2">';
                $out .= '<input type="hidden" name="gigtune_restore_notification_nonce" value="' . e($this->createWpNonce('gigtune_restore_notification')) . '">';
                $out .= '<input type="hidden" name="gigtune_notifications_redirect" value="' . e($redirectTarget) . '">';
                $out .= '<input type="hidden" name="gigtune_restore_notification_id" value="' . $notificationId . '">';
                $out .= '<button type="submit" name="gigtune_restore_notification_submit" value="1" class="text-xs text-slate-200/80 hover:text-white underline">Restore</button>';
                $out .= '</form>';
            }

            $out .= '</div>';
        }
        return $out . '</div></div>';
    }

    private function artistCards(int $limit): string
    {
        $items = $this->getArtists(['per_page' => $limit, 'paged' => 1])['items'];
        if ($items === []) {
            return '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">No artists available.</div>';
        }
        $html = '<div id="gt-artist-grid" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">';
        foreach ($items as $artist) {
            $name = (string) ($artist['title'] ?? 'Artist');
            $id = (int) ($artist['id'] ?? 0);
            $slug = trim((string) ($artist['slug'] ?? ''));
            $loc = trim((string) ($artist['availability']['base_area'] ?? 'South Africa'));
            $rating = number_format((float) ($artist['ratings']['performance_avg'] ?? 0), 1);
            $profile = $slug !== '' ? '/artist-profile/?artist_slug=' . rawurlencode($slug) : '/artist-profile/?artist_id=' . $id;
            $html .= '<article class="gt-artist-card rounded-xl border border-white/10 bg-white/5 p-5"><h3 class="text-white font-semibold text-lg">' . e($name) . '</h3><p class="mt-1 text-sm text-slate-400">' . e($loc) . '</p><p class="mt-1 text-sm text-slate-300">Rating: ' . e($rating) . '</p><div class="mt-3 flex gap-2"><a class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white" href="' . e($profile) . '">View Profile</a><a class="rounded-md border border-slate-600 px-3 py-1.5 text-xs font-semibold text-slate-200" href="' . e($this->bookArtistFormUrl((int) $id)) . '">Book</a></div></article>';
        }
        return $html . '</div>';
    }

    private function psaCards(int $limit): string
    {
        $rows = $this->db()->table($this->posts())->where('post_type', 'gigtune_psa')->where('post_status', 'publish')->orderByDesc('ID')->limit($limit)->get(['ID', 'post_title']);
        if ($rows->isEmpty()) {
            return '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">No open posts right now.</div>';
        }
        $out = '<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">';
        foreach ($rows as $row) {
            $out .= '<article class="rounded-xl border border-white/10 bg-white/5 p-4"><h3 class="text-base font-semibold text-white">' . e((string) $row->post_title) . '</h3><a class="mt-3 inline-flex rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white" href="/book-an-artist/?psa_id=' . (int) $row->ID . '">Apply</a></article>';
        }
        return $out . '</div>';
    }

    private function renderPsaFeed(array $a, ?array $u, array $ctx = []): string
    {
        $request = $this->req($ctx);
        $uid = (int) ($u['id'] ?? 0);
        $roles = is_array($u['roles'] ?? null) ? $u['roles'] : [];
        $isAdmin = (bool) ($u['is_admin'] ?? false);
        $isClient = $uid > 0 && (in_array('gigtune_client', $roles, true) || $isAdmin);
        $isArtist = $uid > 0 && in_array('gigtune_artist', $roles, true);
        $artistProfileId = $isArtist ? $this->latestUserMetaInt($uid, 'gigtune_artist_profile_id') : 0;

        $clientRequirements = $isClient && !$isAdmin ? $this->missingRequirements($uid, 'client_can_request') : [];
        $artistRequirements = $isArtist ? $this->missingRequirements($uid, 'artist_can_receive_requests') : [];
        $canClientPost = $isClient && ($isAdmin || $clientRequirements === []);
        $canArtistApply = $isArtist && $artistRequirements === [];
        $applicationsViewPsaId = max(0, (int) ($request?->query('psa_id', 0) ?? 0));
        $applicationsViewEnabled = $isClient && strtolower(trim((string) ($request?->query('view', '') ?? ''))) === 'applications' && $applicationsViewPsaId > 0;

        $statusMessage = '';
        $errorMessage = '';

        if ($request !== null && strtoupper((string) $request->method()) === 'POST') {
            if ((string) $request->input('gigtune_psa_submit', '') === '1') {
                $nonce = trim((string) $request->input('gigtune_psa_nonce', ''));
                if (!$this->verifyWpNonce($nonce, 'gigtune_psa_action')) {
                    $errorMessage = 'Security check failed.';
                } elseif (!$canClientPost) {
                    $errorMessage = 'Complete your account requirements before posting requests.';
                } else {
                    $title = trim((string) $request->input('gigtune_psa_title', ''));
                    $description = trim((string) $request->input('gigtune_psa_description', ''));
                    $startDate = trim((string) $request->input('gigtune_psa_start_date', ''));
                    $endDate = trim((string) $request->input('gigtune_psa_end_date', ''));
                    $durationWeeks = max(0, (int) $request->input('gigtune_psa_duration_weeks', 0));
                    $budgetMin = max(0, (int) $request->input('gigtune_psa_budget_min', 0));
                    $budgetMax = max(0, (int) $request->input('gigtune_psa_budget_max', 0));
                    $locationProvince = trim((string) $request->input('gigtune_psa_location_province', ''));
                    $locationCity = trim((string) $request->input('gigtune_psa_location_city', ''));
                    $locationStreet = trim((string) $request->input('gigtune_psa_location_street', ''));
                    $locationSuburb = trim((string) $request->input('gigtune_psa_location_suburb', ''));
                    $locationPostalCode = trim((string) $request->input('gigtune_psa_location_postal_code', ''));
                    $locationCountry = trim((string) $request->input('gigtune_psa_location_country', 'South Africa'));
                    if ($locationCountry === '') {
                        $locationCountry = 'South Africa';
                    }
                    $locationText = implode(', ', array_values(array_filter([
                        $locationStreet,
                        $locationSuburb,
                        $locationCity,
                        $locationProvince,
                        $locationPostalCode,
                        $locationCountry,
                    ], static fn ($value): bool => trim((string) $value) !== '')));

                    if ($title === '' || $startDate === '') {
                        $errorMessage = 'Title and start date are required.';
                    } elseif ($locationStreet === '' || $locationCity === '' || $locationProvince === '' || $locationPostalCode === '' || $locationCountry === '') {
                        $errorMessage = 'Complete address fields are required.';
                    } elseif ($locationPostalCode !== '' && preg_match('/^\d{4}$/', $locationPostalCode) !== 1) {
                        $errorMessage = 'Postal code must be a 4-digit number.';
                    } elseif ($endDate !== '' && strtotime($endDate) !== false && strtotime($startDate) !== false && strtotime($endDate) < strtotime($startDate)) {
                        $errorMessage = 'End date cannot be before start date.';
                    } else {
                        if ($budgetMax > 0 && $budgetMin > $budgetMax) {
                            $tmp = $budgetMin;
                            $budgetMin = $budgetMax;
                            $budgetMax = $tmp;
                        }

                        $psaId = (int) $this->db()->table($this->posts())->insertGetId([
                            'post_author' => $uid,
                            'post_date' => now()->format('Y-m-d H:i:s'),
                            'post_date_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                            'post_content' => $description,
                            'post_title' => $title,
                            'post_status' => 'publish',
                            'comment_status' => 'closed',
                            'ping_status' => 'closed',
                            'post_name' => 'psa-' . time() . '-' . $uid,
                            'post_modified' => now()->format('Y-m-d H:i:s'),
                            'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                            'post_type' => 'gigtune_psa',
                        ]);

                        if ($psaId > 0) {
                            $this->upsertPostMeta($psaId, 'gigtune_psa_status', 'open');
                            $this->upsertPostMeta($psaId, 'gigtune_psa_client_user_id', (string) $uid);
                            $this->upsertPostMeta($psaId, 'gigtune_psa_created_at', (string) now()->timestamp);
                            $this->upsertPostMeta($psaId, 'gigtune_psa_start_date', $startDate);
                            $this->upsertPostMeta($psaId, 'gigtune_psa_end_date', $endDate);
                            $this->upsertPostMeta($psaId, 'gigtune_psa_duration_weeks', (string) $durationWeeks);
                            $this->upsertPostMeta($psaId, 'gigtune_psa_budget_min', (string) $budgetMin);
                            $this->upsertPostMeta($psaId, 'gigtune_psa_budget_max', (string) $budgetMax);
                            $this->upsertPostMeta($psaId, 'gigtune_psa_location_province', $locationProvince);
                            $this->upsertPostMeta($psaId, 'gigtune_psa_location_city', $locationCity);
                            $this->upsertPostMeta($psaId, 'gigtune_psa_location_street', $locationStreet);
                            $this->upsertPostMeta($psaId, 'gigtune_psa_location_suburb', $locationSuburb);
                            $this->upsertPostMeta($psaId, 'gigtune_psa_location_postal_code', $locationPostalCode);
                            $this->upsertPostMeta($psaId, 'gigtune_psa_location_country', $locationCountry);
                            $this->upsertPostMeta($psaId, 'gigtune_psa_location_text', $locationText);
                            $this->upsertPostMeta($psaId, 'gigtune_post_applications', serialize([]));

                            $selectedTerms = $request->input('gigtune_psa_terms', []);
                            $taxonomies = array_keys($this->getFilterOptions());
                            foreach ($taxonomies as $taxonomy) {
                                $slugs = [];
                                $raw = is_array($selectedTerms) ? ($selectedTerms[$taxonomy] ?? []) : [];
                                if (is_array($raw)) {
                                    foreach ($raw as $slug) {
                                        $slug = trim((string) $slug);
                                        if ($slug !== '') {
                                            $slugs[] = $slug;
                                        }
                                    }
                                }
                                $this->syncPostTermsBySlugs($psaId, $taxonomy, $slugs);
                            }

                            $statusMessage = 'Post updated successfully.';
                        } else {
                            $errorMessage = 'Unable to create post.';
                        }
                    }
                }
            } elseif ((string) $request->input('gigtune_apply_post_submit', '') === '1') {
                $nonce = trim((string) $request->input('gigtune_psa_apply_nonce', ''));
                $psaId = (int) $request->input('gigtune_psa_id', 0);
                if (!$this->verifyWpNonce($nonce, 'gigtune_psa_apply_action')) {
                    $errorMessage = 'Security check failed.';
                } elseif (!$canArtistApply) {
                    $errorMessage = 'Complete your account requirements before applying.';
                } elseif ($psaId <= 0 || $artistProfileId <= 0) {
                    $errorMessage = 'Invalid post.';
                } else {
                    $status = strtolower(trim($this->getLatestPostMeta($psaId, 'gigtune_psa_status')));
                    if ($status === '') {
                        $status = 'open';
                    }
                    if ($status !== 'open') {
                        $errorMessage = 'Post is closed.';
                    } else {
                        $applications = $this->psaApplications($psaId);
                        if ($this->psaArtistHasApplied($applications, $artistProfileId)) {
                            $statusMessage = 'You already applied to this post.';
                        } else {
                            $applications[] = [
                                'application_id' => 'APP-' . $psaId . '-' . strtoupper(substr(hash('sha256', $artistProfileId . '|' . now()->timestamp . '|' . random_int(1000, 9999)), 0, 8)),
                                'artist_id' => $artistProfileId,
                                'applied_at' => now()->timestamp,
                                'status' => 'APPLIED',
                            ];
                            $this->savePsaApplications($psaId, $applications);
                            $postTitle = trim((string) $this->db()->table($this->posts())->where('ID', $psaId)->value('post_title'));
                            $clientUserId = (int) $this->getLatestPostMeta($psaId, 'gigtune_psa_client_user_id');
                            if ($clientUserId > 0) {
                                $this->createNotification(
                                    $clientUserId,
                                    'booking',
                                    $this->profileNameById($artistProfileId) . ' applied to your open post "' . ($postTitle !== '' ? $postTitle : ('Post #' . $psaId)) . '". Review applicants and book directly from your dashboard.',
                                    ['object_type' => 'psa', 'object_id' => $psaId, 'artist_profile_id' => $artistProfileId]
                                );
                            }
                            $this->createNotification(
                                $uid,
                                'booking',
                                'Application submitted for "' . ($postTitle !== '' ? $postTitle : ('Post #' . $psaId)) . '". The client has been notified and can book you from applicants.',
                                ['object_type' => 'psa', 'object_id' => $psaId, 'artist_profile_id' => $artistProfileId]
                            );
                            $statusMessage = 'Application submitted.';
                        }
                    }
                }
            } elseif ((string) $request->input('gigtune_psa_close_submit', '') === '1') {
                $nonce = trim((string) $request->input('gigtune_psa_close_nonce', ''));
                $psaId = (int) $request->input('gigtune_psa_id', 0);
                if (!$this->verifyWpNonce($nonce, 'gigtune_psa_close_action')) {
                    $errorMessage = 'Security check failed.';
                } elseif (!$isClient || $psaId <= 0) {
                    $errorMessage = 'Invalid post.';
                } else {
                    $ownerId = (int) $this->getLatestPostMeta($psaId, 'gigtune_psa_client_user_id');
                    if (!$isAdmin && $ownerId !== $uid) {
                        $errorMessage = 'Not allowed.';
                    } else {
                        $this->upsertPostMeta($psaId, 'gigtune_psa_status', 'closed');
                        $statusMessage = 'Post updated successfully.';
                    }
                }
            } elseif ((string) $request->input('gigtune_psa_delete_submit', '') === '1') {
                $nonce = trim((string) $request->input('gigtune_psa_delete_nonce', ''));
                $psaId = (int) $request->input('gigtune_psa_id', 0);
                if (!$this->verifyWpNonce($nonce, 'gigtune_psa_delete_action') || $psaId <= 0 || !$isClient) {
                    $errorMessage = 'Invalid post action.';
                } else {
                    $ownerId = (int) $this->getLatestPostMeta($psaId, 'gigtune_psa_client_user_id');
                    if (!$isAdmin && $ownerId !== $uid) {
                        $errorMessage = 'Not allowed.';
                    } else {
                        $this->db()->table($this->posts())
                            ->where('ID', $psaId)
                            ->where('post_type', 'gigtune_psa')
                            ->update([
                                'post_status' => 'trash',
                                'post_modified' => now()->format('Y-m-d H:i:s'),
                                'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                            ]);
                        $this->upsertPostMeta($psaId, 'gigtune_psa_deleted_at', (string) now()->timestamp);
                        $statusMessage = 'Post deleted.';
                    }
                }
            } elseif ((string) $request->input('gigtune_psa_admin_hide_submit', '') === '1') {
                $nonce = trim((string) $request->input('gigtune_psa_admin_hide_nonce', ''));
                $psaId = (int) $request->input('gigtune_psa_id', 0);
                if (!$isAdmin || !$this->verifyWpNonce($nonce, 'gigtune_psa_admin_hide_action') || $psaId <= 0) {
                    $errorMessage = 'Invalid admin action.';
                } else {
                    $this->db()->table($this->posts())
                        ->where('ID', $psaId)
                        ->where('post_type', 'gigtune_psa')
                        ->update([
                            'post_status' => 'private',
                            'post_modified' => now()->format('Y-m-d H:i:s'),
                            'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                        ]);
                    $this->upsertPostMeta($psaId, 'gigtune_psa_hidden_by_admin', '1');
                    $this->upsertPostMeta($psaId, 'gigtune_psa_hidden_at', (string) now()->timestamp);
                    $statusMessage = 'Post hidden from public view.';
                }
            } elseif ((string) $request->input('gigtune_psa_admin_delete_submit', '') === '1') {
                $nonce = trim((string) $request->input('gigtune_psa_admin_delete_nonce', ''));
                $psaId = (int) $request->input('gigtune_psa_id', 0);
                if (!$isAdmin || !$this->verifyWpNonce($nonce, 'gigtune_psa_admin_delete_action') || $psaId <= 0) {
                    $errorMessage = 'Invalid admin action.';
                } else {
                    $this->db()->table($this->posts())
                        ->where('ID', $psaId)
                        ->where('post_type', 'gigtune_psa')
                        ->update([
                            'post_status' => 'trash',
                            'post_modified' => now()->format('Y-m-d H:i:s'),
                            'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                        ]);
                    $this->upsertPostMeta($psaId, 'gigtune_psa_deleted_by_admin', '1');
                    $this->upsertPostMeta($psaId, 'gigtune_psa_deleted_at', (string) now()->timestamp);
                    $statusMessage = 'Post deleted.';
                }
            }
        }
        if ($request !== null) {
            $markReadId = abs((int) $request->query('notification_id', 0));
            if ($markReadId > 0) {
                try {
                    $this->notifications->markRead($markReadId, $id, (bool) ($u['is_admin'] ?? false));
                } catch (\Throwable) {
                    // Ignore read update errors and continue rendering the page.
                }
            }
        }

        $html = '<div class="space-y-6">';
        if ($statusMessage !== '') {
            $html .= '<div class="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-4"><p class="text-emerald-200 font-semibold">' . e($statusMessage) . '</p></div>';
        }
        if ($errorMessage !== '') {
            $html .= '<div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4"><p class="text-rose-200 font-semibold">Post update failed.</p><p class="text-rose-200/80 text-sm mt-1">' . e($errorMessage) . '</p></div>';
        }
        if ($isClient && !$canClientPost) {
            $first = reset($clientRequirements);
            $msg = is_array($first) ? (string) ($first['message'] ?? 'Complete your account requirements before posting requests.') : 'Complete your account requirements before posting requests.';
            $html .= '<div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4"><p class="text-amber-200 font-semibold">' . e($msg) . '</p></div>';
        }
        if ($isArtist && !$canArtistApply) {
            $first = reset($artistRequirements);
            $msg = is_array($first) ? (string) ($first['message'] ?? 'Complete your account requirements before applying.') : 'Complete your account requirements before applying.';
            $html .= '<div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4"><p class="text-amber-200 font-semibold">' . e($msg) . '</p></div>';
        }

        if ($isClient && !$applicationsViewEnabled) {
            $taxOptions = $this->getFilterOptions();
            $provinces = $this->saProvinces();
            $html .= '<div class="rounded-2xl border border-white/10 bg-white/5 p-6">';
            $html .= '<h2 class="text-lg font-semibold text-white">Create a client post</h2>';
            $html .= '<p class="text-sm text-slate-300 mt-2">Share what kind of artist you are looking for.</p>';
            if (!$canClientPost) {
                $html .= '<p class="mt-4 text-sm text-amber-200">Complete verification, policies, and KYC before publishing requests.</p>';
            } else {
                $html .= '<form method="post" class="mt-6 space-y-4">';
                $html .= '<input type="hidden" name="gigtune_psa_submit" value="1">';
                $html .= '<input type="hidden" name="gigtune_psa_nonce" value="' . e($this->createWpNonce('gigtune_psa_action')) . '">';
                $html .= '<div><label class="block text-sm font-semibold text-slate-200 mb-2">Title</label><input type="text" name="gigtune_psa_title" required class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div>';
                $html .= '<div><label class="block text-sm font-semibold text-slate-200 mb-2">Description</label><textarea name="gigtune_psa_description" rows="4" class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></textarea></div>';
                $html .= '<div class="grid gap-4 md:grid-cols-3">';
                $html .= '<div><label class="block text-sm font-semibold text-slate-200 mb-2">Start date</label><input type="date" name="gigtune_psa_start_date" required class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div>';
                $html .= '<div><label class="block text-sm font-semibold text-slate-200 mb-2">End date</label><input type="date" name="gigtune_psa_end_date" class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div>';
                $html .= '</div>';
                $html .= '<div class="grid gap-4 md:grid-cols-2">';
                $html .= '<div><label class="block text-sm font-semibold text-slate-200 mb-2">Budget min (ZAR)</label><input type="number" min="0" name="gigtune_psa_budget_min" class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div>';
                $html .= '<div><label class="block text-sm font-semibold text-slate-200 mb-2">Budget max (ZAR)</label><input type="number" min="0" name="gigtune_psa_budget_max" class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div>';
                $html .= '</div>';
                $html .= '<div class="grid gap-4 md:grid-cols-3">';
                $html .= '<div><label class="block text-sm font-semibold text-slate-200 mb-2">Street address</label><input type="text" name="gigtune_psa_location_street" required class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div>';
                $html .= '<div><label class="block text-sm font-semibold text-slate-200 mb-2">Suburb</label><input type="text" name="gigtune_psa_location_suburb" class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div>';
                $html .= '<div><label class="block text-sm font-semibold text-slate-200 mb-2">Town/City</label><input type="text" name="gigtune_psa_location_city" required class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div>';
                $html .= '</div>';
                $html .= '<div class="grid gap-4 md:grid-cols-3">';
                $html .= '<div><label class="block text-sm font-semibold text-slate-200 mb-2">Province</label><select name="gigtune_psa_location_province" required class="gigtune-site-select w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"><option value="">Select province</option>';
                foreach ($provinces as $province) {
                    $html .= '<option value="' . e($province) . '">' . e($province) . '</option>';
                }
                $html .= '</select></div>';
                $html .= '<div><label class="block text-sm font-semibold text-slate-200 mb-2">Postcode</label><input type="text" name="gigtune_psa_location_postal_code" required pattern="\\d{4}" class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div>';
                $html .= '<div><label class="block text-sm font-semibold text-slate-200 mb-2">Country</label><input type="text" name="gigtune_psa_location_country" value="South Africa" required class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div>';
                $html .= '</div>';
                foreach ($taxOptions as $taxonomy => $terms) {
                    if (!is_array($terms) || $terms === []) {
                        continue;
                    }
                    $html .= '<div><label class="block text-sm font-semibold text-slate-200 mb-2">' . e(ucwords(str_replace('_', ' ', $taxonomy))) . '</label><div class="flex flex-wrap gap-2">';
                    foreach ($terms as $term) {
                        $slug = (string) ($term['slug'] ?? '');
                        $name = (string) ($term['name'] ?? $slug);
                        if ($slug === '') {
                            continue;
                        }
                        $html .= '<label class="inline-flex items-center gap-2 text-xs text-slate-300"><input type="checkbox" name="gigtune_psa_terms[' . e($taxonomy) . '][]" value="' . e($slug) . '">' . e($name) . '</label>';
                    }
                    $html .= '</div></div>';
                }
                $html .= '<button type="submit" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 hover:bg-indigo-500 px-5 py-3 text-white font-semibold">Publish post</button>';
                $html .= '</form>';
            }
            $html .= '</div>';
        }

        if ($applicationsViewEnabled) {
            $html .= $this->renderClientPsaApplicationsPanel($uid, $applicationsViewPsaId, $isAdmin);
            return $html . '</div>';
        }

        $posts = $this->fetchPsaFeedPosts($isClient ? $uid : null, $isClient ? null : 'open');
        $html .= '<div class="rounded-2xl border border-white/10 bg-white/5 p-6">';
        $html .= '<h2 class="text-lg font-semibold text-white">' . e($isClient ? 'Your client posts' : 'Open client posts') . '</h2>';
        $html .= '<div class="mt-4 space-y-4">';
        if ($posts === []) {
            $html .= '<p class="text-sm text-slate-300">No posts found.</p>';
        } else {
            foreach ($posts as $post) {
                $psaId = (int) ($post['id'] ?? 0);
                $status = strtolower(trim((string) ($post['status'] ?? 'open')));
                $applications = $this->psaApplications($psaId);
                $alreadyApplied = $isArtist && $artistProfileId > 0 && $this->psaArtistHasApplied($applications, $artistProfileId);

                $html .= '<div class="rounded-xl border border-white/10 bg-black/20 p-4">';
                $html .= '<div class="text-white font-semibold">' . e((string) ($post['title'] ?? 'Client post')) . '</div>';
                if (trim((string) ($post['content'] ?? '')) !== '') {
                    $html .= '<div class="text-sm text-slate-300 mt-2">' . e((string) $post['content']) . '</div>';
                }
                $html .= '<div class="text-xs text-slate-400 mt-2">Status: <span class="text-slate-200">' . e($this->toSentenceCase($status)) . '</span></div>';
                $budgetMin = (int) ($post['budget_min'] ?? 0);
                $budgetMax = (int) ($post['budget_max'] ?? 0);
                if ($budgetMin > 0 || $budgetMax > 0) {
                    $html .= '<div class="text-xs text-slate-400">Budget: <span class="text-slate-200">ZAR ' . e(number_format($budgetMin)) . ($budgetMax > 0 ? (' - ' . e(number_format($budgetMax))) : '') . '</span></div>';
                }

                if ($isClient) {
                    $appCount = count($applications);
                    $applicationUrl = '/posts-page/?view=applications&psa_id=' . $psaId;
                    $html .= '<div class="mt-3 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs text-slate-300">Applications: <span class="text-slate-100 font-semibold">' . e((string) $appCount) . '</span> <a href="' . e($applicationUrl) . '" class="ml-2 text-blue-300 hover:text-blue-200 underline">Open applications</a></div>';
                }

                if ($isClient && $status === 'open') {
                    $html .= '<form method="post" class="mt-3"><input type="hidden" name="gigtune_psa_id" value="' . $psaId . '"><input type="hidden" name="gigtune_psa_close_nonce" value="' . e($this->createWpNonce('gigtune_psa_close_action')) . '"><button type="submit" name="gigtune_psa_close_submit" value="1" class="text-xs text-rose-300 hover:text-rose-200">Close post</button></form>';
                }
                if ($isClient) {
                    $html .= '<form method="post" class="mt-2"><input type="hidden" name="gigtune_psa_id" value="' . $psaId . '"><input type="hidden" name="gigtune_psa_delete_nonce" value="' . e($this->createWpNonce('gigtune_psa_delete_action')) . '"><button type="submit" name="gigtune_psa_delete_submit" value="1" class="text-xs text-rose-300 hover:text-rose-200">Delete post</button></form>';
                }
                if ($canArtistApply && $status === 'open') {
                    $html .= '<form method="post" class="mt-3"><input type="hidden" name="gigtune_psa_id" value="' . $psaId . '"><input type="hidden" name="gigtune_psa_apply_nonce" value="' . e($this->createWpNonce('gigtune_psa_apply_action')) . '">';
                    if ($alreadyApplied) {
                        $html .= '<button type="submit" disabled class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-xs font-semibold text-slate-300 bg-white/10 opacity-70 cursor-not-allowed">Already applied</button>';
                    } else {
                        $html .= '<button type="submit" name="gigtune_apply_post_submit" value="1" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-500">Apply to this Post</button>';
                    }
                    $html .= '</form>';
                }
                if ($isAdmin) {
                    $html .= '<div class="mt-3 flex flex-wrap gap-2">';
                    $html .= '<form method="post"><input type="hidden" name="gigtune_psa_id" value="' . $psaId . '"><input type="hidden" name="gigtune_psa_admin_hide_nonce" value="' . e($this->createWpNonce('gigtune_psa_admin_hide_action')) . '"><button type="submit" name="gigtune_psa_admin_hide_submit" value="1" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-xs font-semibold text-white bg-white/10 hover:bg-white/15">Hide post</button></form>';
                    $html .= '<form method="post"><input type="hidden" name="gigtune_psa_id" value="' . $psaId . '"><input type="hidden" name="gigtune_psa_admin_delete_nonce" value="' . e($this->createWpNonce('gigtune_psa_admin_delete_action')) . '"><button type="submit" name="gigtune_psa_admin_delete_submit" value="1" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-xs font-semibold text-white bg-rose-600/90 hover:bg-rose-500">Delete post</button></form>';
                    $html .= '</div>';
                }
                $html .= '</div>';
            }
        }
        $html .= '</div></div>';
        return $html . '</div>';
    }

    private function renderClientPsaApplicationsPanel(int $clientUserId, int $psaId, bool $isAdmin): string
    {
        $psaId = abs($psaId);
        if ($psaId <= 0) {
            return '<div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4"><p class="text-rose-200 font-semibold">Invalid post ID.</p></div>';
        }

        $post = $this->db()->table($this->posts())
            ->where('ID', $psaId)
            ->where('post_type', 'gigtune_psa')
            ->where('post_status', 'publish')
            ->first(['ID', 'post_title', 'post_content']);
        if ($post === null) {
            return '<div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4"><p class="text-rose-200 font-semibold">Client post not found.</p></div>';
        }

        $ownerId = (int) $this->getLatestPostMeta($psaId, 'gigtune_psa_client_user_id');
        if (!$isAdmin && $ownerId > 0 && $ownerId !== $clientUserId) {
            return '<div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4"><p class="text-rose-200 font-semibold">Access denied for post applications.</p></div>';
        }

        $applications = $this->psaApplications($psaId);
        $html = '<div class="rounded-2xl border border-white/10 bg-white/5 p-6">';
        $html .= '<div class="flex flex-wrap items-center justify-between gap-3">';
        $html .= '<h2 class="text-lg font-semibold text-white">Post applications</h2>';
        $html .= '<a href="/posts-page/" class="text-xs text-slate-300 hover:text-white underline">Back to client posts</a>';
        $html .= '</div>';
        $html .= '<p class="mt-2 text-sm text-slate-300">Post #' . e((string) $psaId) . ': <span class="text-slate-100">' . e((string) ($post->post_title ?? 'Client post')) . '</span></p>';

        if ($applications === []) {
            $html .= '<p class="mt-4 text-sm text-slate-300">No applications yet.</p>';
            return $html . '</div>';
        }

        $html .= '<div class="mt-4 space-y-3">';
        foreach ($applications as $application) {
            $artistId = (int) ($application['artist_id'] ?? 0);
            if ($artistId <= 0) {
                continue;
            }
            $artistPost = $this->db()->table($this->posts())->where('ID', $artistId)->where('post_type', 'artist_profile')->first(['post_title']);
            $artistName = $artistPost !== null ? trim((string) ($artistPost->post_title ?? '')) : '';
            if ($artistName === '') {
                $artistName = 'Artist #' . $artistId;
            }
            $applicationId = trim((string) ($application['application_id'] ?? ''));
            if ($applicationId === '') {
                $applicationId = 'APP-' . $psaId . '-' . strtoupper(substr(hash('sha256', $artistId . '|' . (string) ($application['applied_at'] ?? 0)), 0, 8));
            }
            $appliedAt = (int) ($application['applied_at'] ?? 0);
            $bookUrl = '/book-an-artist/?artist_id=' . $artistId . '&post_ref=' . $psaId . '&psa_id=' . $psaId . '&application_id=' . rawurlencode($applicationId);
            $profileUrl = '/artist-profile/?artist_id=' . $artistId;
            $html .= '<div class="rounded-xl border border-white/10 bg-black/20 p-4">';
            $html .= '<div class="flex flex-wrap items-start justify-between gap-3">';
            $html .= '<div><div class="text-xs text-slate-400">Application ID: <span class="text-slate-200 font-mono">' . e($applicationId) . '</span></div><div class="text-sm font-semibold text-white mt-1">' . e($artistName) . '</div>';
            if ($appliedAt > 0) {
                $html .= '<div class="text-xs text-slate-400 mt-1">Applied: ' . e(date('M j, Y H:i', $appliedAt)) . '</div>';
            }
            $html .= '</div>';
            $html .= '<div class="flex flex-wrap gap-2"><a href="' . e($profileUrl) . '" class="inline-flex items-center justify-center rounded-lg px-3 py-1.5 text-xs font-semibold text-white bg-white/10 hover:bg-white/15">View profile</a><a href="' . e($bookUrl) . '" class="inline-flex items-center justify-center rounded-lg px-3 py-1.5 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-500">Book this artist</a></div>';
            $html .= '</div></div>';
        }
        $html .= '</div>';

        return $html . '</div>';
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchPsaFeedPosts(?int $clientUserId = null, ?string $status = null): array
    {
        $query = $this->db()->table($this->posts())
            ->where('post_type', 'gigtune_psa')
            ->where('post_status', 'publish')
            ->orderByDesc('ID');

        if (($clientUserId ?? 0) > 0) {
            $query->whereExists(function ($q) use ($clientUserId): void {
                $q->selectRaw('1')
                    ->from($this->pm() . ' as pm')
                    ->whereColumn('pm.post_id', $this->posts() . '.ID')
                    ->where('pm.meta_key', 'gigtune_psa_client_user_id')
                    ->where('pm.meta_value', (string) $clientUserId);
            });
        }

        $rows = $query->limit(120)->get(['ID', 'post_title', 'post_content']);
        if ($rows->isEmpty()) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->ID;
        }
        $meta = $this->postMetaMap($ids, [
            'gigtune_psa_status',
            'gigtune_psa_budget_min',
            'gigtune_psa_budget_max',
            'gigtune_psa_location_text',
            'gigtune_psa_location_street',
            'gigtune_psa_location_suburb',
            'gigtune_psa_location_province',
            'gigtune_psa_location_city',
            'gigtune_psa_location_postal_code',
            'gigtune_psa_location_country',
        ]);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row->ID;
            $m = $meta[$id] ?? [];
            $rowStatus = strtolower(trim((string) ($m['gigtune_psa_status'] ?? 'open')));
            if ($status !== null && $status !== '' && $rowStatus !== strtolower($status)) {
                continue;
            }
            $items[] = [
                'id' => $id,
                'title' => (string) ($row->post_title ?? ''),
                'content' => (string) ($row->post_content ?? ''),
                'status' => $rowStatus === '' ? 'open' : $rowStatus,
                'budget_min' => (int) ($m['gigtune_psa_budget_min'] ?? 0),
                'budget_max' => (int) ($m['gigtune_psa_budget_max'] ?? 0),
                'location_text' => trim((string) ($m['gigtune_psa_location_text'] ?? '')) !== ''
                    ? trim((string) ($m['gigtune_psa_location_text'] ?? ''))
                    : implode(', ', array_values(array_filter([
                        trim((string) ($m['gigtune_psa_location_street'] ?? '')),
                        trim((string) ($m['gigtune_psa_location_suburb'] ?? '')),
                        trim((string) ($m['gigtune_psa_location_city'] ?? '')),
                        trim((string) ($m['gigtune_psa_location_province'] ?? '')),
                        trim((string) ($m['gigtune_psa_location_postal_code'] ?? '')),
                        trim((string) ($m['gigtune_psa_location_country'] ?? '')),
                    ], static fn ($value): bool => $value !== ''))),
                'location_province' => trim((string) ($m['gigtune_psa_location_province'] ?? '')),
                'location_city' => trim((string) ($m['gigtune_psa_location_city'] ?? '')),
            ];
        }
        return $items;
    }

    /** @return array<int,array{application_id:string,artist_id:int,applied_at:int,status:string}> */
    private function psaApplications(int $psaId): array
    {
        $raw = $this->maybe($this->getLatestPostMeta($psaId, 'gigtune_post_applications'));
        if (!is_array($raw) || $raw === []) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $artistId = (int) ($row['artist_id'] ?? 0);
            if ($artistId <= 0 || isset($out[$artistId])) {
                continue;
            }
            $status = strtoupper(trim((string) ($row['status'] ?? 'APPLIED')));
            if ($status === '') {
                $status = 'APPLIED';
            }
            $applicationId = trim((string) ($row['application_id'] ?? ''));
            if ($applicationId === '') {
                $applicationId = 'APP-' . $psaId . '-' . strtoupper(substr(hash('sha256', $artistId . '|' . (string) ($row['applied_at'] ?? 0)), 0, 8));
            }
            $out[$artistId] = [
                'application_id' => $applicationId,
                'artist_id' => $artistId,
                'applied_at' => max(0, (int) ($row['applied_at'] ?? 0)),
                'status' => $status,
            ];
        }
        return array_values($out);
    }

    /** @param array<int,array{application_id?:string,artist_id:int,applied_at:int,status:string}> $applications */
    private function savePsaApplications(int $psaId, array $applications): void
    {
        $clean = [];
        foreach ($applications as $row) {
            if (!is_array($row)) {
                continue;
            }
            $artistId = (int) ($row['artist_id'] ?? 0);
            if ($artistId <= 0 || isset($clean[$artistId])) {
                continue;
            }
            $applicationId = trim((string) ($row['application_id'] ?? ''));
            if ($applicationId === '') {
                $applicationId = 'APP-' . $psaId . '-' . strtoupper(substr(hash('sha256', $artistId . '|' . (string) ($row['applied_at'] ?? now()->timestamp)), 0, 8));
            }
            $clean[$artistId] = [
                'application_id' => $applicationId,
                'artist_id' => $artistId,
                'applied_at' => max(0, (int) ($row['applied_at'] ?? now()->timestamp)),
                'status' => strtoupper(trim((string) ($row['status'] ?? 'APPLIED'))) ?: 'APPLIED',
            ];
        }
        $this->upsertPostMeta($psaId, 'gigtune_post_applications', serialize(array_values($clean)));
    }

    /** @param array<int,array{artist_id:int,applied_at:int,status:string}> $applications */
    private function psaArtistHasApplied(array $applications, int $artistProfileId): bool
    {
        foreach ($applications as $row) {
            if ((int) ($row['artist_id'] ?? 0) === $artistProfileId) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,string> */
    private function atts(string $raw): array
    {
        $out = [];
        preg_match_all("/([a-zA-Z0-9_:-]+)\\s*=\\s*(\\\"([^\\\"]*)\\\"|'([^']*)'|([^\\s\\\"']+))/", $raw, $m, PREG_SET_ORDER);
        foreach ($m as $v) {
            $k = strtolower((string) ($v[1] ?? ''));
            $out[$k] = (string) (($v[3] ?? $v[4] ?? $v[5] ?? ''));
        }
        return $out;
    }

    private function attachmentUrl(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        $file = $this->getLatestPostMeta($id, '_wp_attached_file');
        return $file === '' ? '' : (rtrim((string) config('app.url', ''), '/') . '/wp-content/uploads/' . ltrim(str_replace('\\', '/', $file), '/'));
    }

    /** @return array<int,string> */
    private function days(string $raw): array
    {
        $v = $this->maybe($raw);
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $d) {
            $d = trim(strtolower((string) $d));
            if ($d !== '') {
                $out[] = $d;
            }
        }
        return array_values(array_unique($out));
    }

    /** @param array<int,string> $days */
    private function normalizeAvailabilityDays(array $days): array
    {
        $map = [
            'monday' => 'mon', 'mon' => 'mon',
            'tuesday' => 'tue', 'tue' => 'tue', 'tues' => 'tue',
            'wednesday' => 'wed', 'wed' => 'wed',
            'thursday' => 'thu', 'thu' => 'thu', 'thurs' => 'thu',
            'friday' => 'fri', 'fri' => 'fri',
            'saturday' => 'sat', 'sat' => 'sat',
            'sunday' => 'sun', 'sun' => 'sun',
        ];

        $normalized = [];
        foreach ($days as $day) {
            $key = strtolower(trim((string) $day));
            $canonical = (string) ($map[$key] ?? '');
            if ($canonical !== '') {
                $normalized[] = $canonical;
            }
        }

        return array_values(array_unique($normalized));
    }

    /** @return array<int,array{title:string,url:string,type:string,raw:string,label:string}> */
    private function demoVideos(string $raw): array
    {
        $v = $this->maybe($raw);
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $i) {
            if (is_int($i) || ctype_digit((string) $i)) {
                $id = (int) $i;
                $u = $this->attachmentUrl((int) $i);
                if ($u !== '') {
                    $out[] = ['title' => 'Demo Video', 'url' => $u, 'type' => 'upload', 'raw' => (string) $id, 'label' => $u];
                }
            } elseif (is_string($i) && trim($i) !== '') {
                $url = trim($i);
                $out[] = ['title' => 'Demo Video', 'url' => $url, 'type' => 'link', 'raw' => $url, 'label' => $url];
            }
        }
        return $out;
    }

    /** @param array{title?:string,url?:string,type?:string,raw?:string,label?:string} $video */
    private function renderDemoPreview(array $video): string
    {
        $url = trim((string) ($video['url'] ?? ''));
        if ($url === '') {
            return '<div class="rounded-lg border border-white/10 bg-slate-900/60 px-3 py-2 text-xs text-slate-400">Preview unavailable</div>';
        }

        $type = strtolower(trim((string) ($video['type'] ?? '')));
        $ext = strtolower((string) pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        $isDirectVideo = $type === 'upload' || in_array($ext, ['mp4', 'webm', 'mov', 'm4v'], true);
        if ($isDirectVideo) {
            return '<video controls preload="metadata" class="w-full rounded-lg border border-white/10 bg-black"><source src="' . e($url) . '"></video>';
        }

        $embedUrl = $this->normalizeDemoEmbedUrl($url);
        if ($embedUrl !== '') {
            return '<div class="aspect-video w-full overflow-hidden rounded-lg border border-white/10 bg-black"><iframe src="' . e($embedUrl) . '" class="h-full w-full border-0" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe></div>';
        }

        return '<div class="rounded-lg border border-white/10 bg-slate-900/60 px-3 py-2 text-xs text-slate-300">External demo link preview unavailable for this provider.</div>';
    }

    private function normalizeDemoEmbedUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#(?:youtube\.com/watch\?v=|youtu\.be/)([A-Za-z0-9_-]{6,})#i', $url, $m) === 1) {
            return 'https://www.youtube.com/embed/' . $m[1];
        }
        if (preg_match('#youtube\.com/embed/([A-Za-z0-9_-]{6,})#i', $url, $m) === 1) {
            return 'https://www.youtube.com/embed/' . $m[1];
        }
        if (preg_match('#vimeo\.com/(?:video/)?([0-9]{6,})#i', $url, $m) === 1) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }
        if (preg_match('#tiktok\.com/.*/video/([0-9]+)#i', $url, $m) === 1) {
            return 'https://www.tiktok.com/embed/v2/' . $m[1];
        }

        return '';
    }

    private function renderProfileMediaEnhancementsScript(): string
    {
        return <<<'HTML'
<script>
(function () {
  if (window.__gtProfileMediaEnhancerBound) {
    if (window.__gtProfileMediaEnhancerBind) { window.__gtProfileMediaEnhancerBind(); }
    return;
  }
  window.__gtProfileMediaEnhancerBound = true;

  var cropState = {
    modal: null,
    cropper: null,
    input: null,
    file: null,
    previewId: '',
    placeholderId: '',
    aspectRatio: 1
  };

  function ensureCropperAssets() {
    if (!document.querySelector('link[data-gt-cropper-css]')) {
      var css = document.createElement('link');
      css.rel = 'stylesheet';
      css.href = 'https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css';
      css.setAttribute('data-gt-cropper-css', '1');
      document.head.appendChild(css);
    }
    if (!window.Cropper && !document.querySelector('script[data-gt-cropper-js]')) {
      var js = document.createElement('script');
      js.src = 'https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js';
      js.defer = true;
      js.setAttribute('data-gt-cropper-js', '1');
      document.head.appendChild(js);
    }
  }

  function byId(id) {
    if (!id) { return null; }
    return document.getElementById(id);
  }

  function updatePreview(previewId, placeholderId, src) {
    var preview = byId(previewId);
    var placeholder = byId(placeholderId);
    if (preview) {
      preview.src = src || '';
      if (src) {
        preview.classList.remove('hidden');
      } else {
        preview.classList.add('hidden');
      }
    }
    if (placeholder) {
      if (src) {
        placeholder.classList.add('hidden');
      } else {
        placeholder.classList.remove('hidden');
      }
    }
  }

  function setInputFile(input, file) {
    if (!input || !file) { return; }
    var dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
  }

  function ensureModal() {
    if (cropState.modal) { return cropState.modal; }
    var wrapper = document.createElement('div');
    wrapper.id = 'gt-media-cropper-modal';
    wrapper.className = 'fixed inset-0 z-[120] hidden items-center justify-center bg-slate-950/80 p-4';
    wrapper.innerHTML = ''
      + '<div class="w-full max-w-3xl rounded-2xl border border-white/10 bg-slate-900 p-4 shadow-2xl">'
      + '  <div class="mb-3 flex items-center justify-between">'
      + '    <h3 class="text-sm font-semibold text-white">Crop image</h3>'
      + '    <button type="button" data-role="gt-crop-close" class="rounded-lg bg-white/10 px-3 py-1 text-xs font-semibold text-white hover:bg-white/15">Cancel</button>'
      + '  </div>'
      + '  <div class="h-[58vh] overflow-hidden rounded-xl border border-white/10 bg-black">'
      + '    <img data-role="gt-crop-image" src="" alt="Crop preview" class="block max-h-full w-full object-contain">'
      + '  </div>'
      + '  <div class="mt-3 flex justify-end gap-2">'
      + '    <button type="button" data-role="gt-crop-apply" class="rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white hover:bg-blue-500">Apply crop</button>'
      + '  </div>'
      + '</div>';
    document.body.appendChild(wrapper);
    cropState.modal = wrapper;

    var closeBtn = wrapper.querySelector('[data-role="gt-crop-close"]');
    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        if (cropState.input) {
          cropState.input.value = '';
        }
        closeModal();
      });
    }

    var applyBtn = wrapper.querySelector('[data-role="gt-crop-apply"]');
    if (applyBtn) {
      applyBtn.addEventListener('click', function () {
        applyCrop();
      });
    }

    return wrapper;
  }

  function closeModal() {
    if (cropState.cropper) {
      cropState.cropper.destroy();
      cropState.cropper = null;
    }
    if (cropState.modal) {
      cropState.modal.classList.add('hidden');
      cropState.modal.classList.remove('flex');
    }
    cropState.input = null;
    cropState.file = null;
    cropState.previewId = '';
    cropState.placeholderId = '';
    cropState.aspectRatio = 1;
  }

  function openCropper(input, file) {
    var modal = ensureModal();
    var img = modal.querySelector('[data-role="gt-crop-image"]');
    if (!img) {
      updatePreview(input.dataset.gtPreviewId || '', input.dataset.gtPlaceholderId || '', URL.createObjectURL(file));
      return;
    }

    cropState.input = input;
    cropState.file = file;
    cropState.previewId = input.dataset.gtPreviewId || '';
    cropState.placeholderId = input.dataset.gtPlaceholderId || '';
    cropState.aspectRatio = parseFloat(input.dataset.cropRatio || '1');
    if (!isFinite(cropState.aspectRatio) || cropState.aspectRatio <= 0) {
      cropState.aspectRatio = 1;
    }

    var objectUrl = URL.createObjectURL(file);
    img.src = objectUrl;
    modal.classList.remove('hidden');
    modal.classList.add('flex');

    img.onload = function () {
      if (!window.Cropper) {
        updatePreview(cropState.previewId, cropState.placeholderId, objectUrl);
        return;
      }
      if (cropState.cropper) {
        cropState.cropper.destroy();
      }
      cropState.cropper = new Cropper(img, {
        aspectRatio: cropState.aspectRatio,
        viewMode: 1,
        autoCropArea: 1,
        background: false,
        responsive: true
      });
    };
  }

  function applyCrop() {
    if (!cropState.input || !cropState.file) {
      closeModal();
      return;
    }
    if (!cropState.cropper) {
      var rawUrl = URL.createObjectURL(cropState.file);
      updatePreview(cropState.previewId, cropState.placeholderId, rawUrl);
      closeModal();
      return;
    }

    var mime = cropState.file.type === 'image/png' ? 'image/png' : 'image/jpeg';
    var canvas = cropState.cropper.getCroppedCanvas({
      maxWidth: 2400,
      maxHeight: 2400,
      fillColor: '#0f172a'
    });
    if (!canvas) {
      closeModal();
      return;
    }

    canvas.toBlob(function (blob) {
      if (!blob || !cropState.input) {
        closeModal();
        return;
      }
      var name = (cropState.file.name || 'image').replace(/\.[^.]+$/, '');
      var ext = mime === 'image/png' ? 'png' : 'jpg';
      var croppedFile = new File([blob], name + '-crop.' + ext, { type: mime });
      setInputFile(cropState.input, croppedFile);
      var previewUrl = URL.createObjectURL(croppedFile);
      updatePreview(cropState.previewId, cropState.placeholderId, previewUrl);
      closeModal();
    }, mime, 0.95);
  }

  function bindInput(input) {
    if (!input || input.dataset.gtBound === '1') { return; }
    input.dataset.gtBound = '1';
    input.addEventListener('change', function () {
      var file = input.files && input.files[0] ? input.files[0] : null;
      if (!file) { return; }
      ensureCropperAssets();
      openCropper(input, file);
    });
  }

  function bindAll() {
    var inputs = document.querySelectorAll('input[type="file"][data-gt-preview-id]');
    for (var i = 0; i < inputs.length; i++) {
      bindInput(inputs[i]);
    }
  }

  window.__gtProfileMediaEnhancerBind = bindAll;
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindAll);
  } else {
    bindAll();
  }
})();
</script>
HTML;
    }

    private function bool(mixed $v): bool
    {
        $n = strtolower(trim((string) $v));
        return in_array($n, ['1', 'true', 'yes', 'on'], true);
    }

    private function maybe(string $v): mixed
    {
        $t = trim($v);
        if ($t === '') {
            return '';
        }
        if ($t === 'N;' || preg_match('/^[aObisCd]:/', $t) === 1) {
            $d = @unserialize($t, ['allowed_classes' => false]);
            if ($d !== false || $t === 'b:0;' || $t === 'N;') {
                return $d;
            }
        }
        return $v;
    }

    private function toSentenceCase(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/[_-]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        $value = ucfirst($value);
        $value = preg_replace('/\bkyc\b/i', 'KYC', $value) ?? $value;
        $value = preg_replace('/\bpsa\b/i', 'PSA', $value) ?? $value;
        return $value;
    }

    /** @return array<string,array<int,string>> */
    private function postTermSlugs(int $postId, array $taxonomies): array
    {
        if ($postId <= 0 || $taxonomies === []) {
            return [];
        }

        $cleanTaxonomies = [];
        foreach ($taxonomies as $taxonomy) {
            $taxonomy = trim((string) $taxonomy);
            if ($taxonomy !== '') {
                $cleanTaxonomies[] = $taxonomy;
            }
        }
        if ($cleanTaxonomies === []) {
            return [];
        }

        $rows = $this->db()->table($this->tr() . ' as tr')
            ->join($this->tt() . ' as tt', 'tt.term_taxonomy_id', '=', 'tr.term_taxonomy_id')
            ->join($this->terms() . ' as t', 't.term_id', '=', 'tt.term_id')
            ->where('tr.object_id', $postId)
            ->whereIn('tt.taxonomy', $cleanTaxonomies)
            ->get(['tt.taxonomy', 't.slug']);

        $out = [];
        foreach ($rows as $row) {
            $taxonomy = trim((string) ($row->taxonomy ?? ''));
            $slug = trim((string) ($row->slug ?? ''));
            if ($taxonomy === '' || $slug === '') {
                continue;
            }
            if (!isset($out[$taxonomy])) {
                $out[$taxonomy] = [];
            }
            if (!in_array($slug, $out[$taxonomy], true)) {
                $out[$taxonomy][] = $slug;
            }
        }

        return $out;
    }

    /** @param array<int,string> $slugs */
    private function syncPostTermsBySlugs(int $postId, string $taxonomy, array $slugs): void
    {
        $postId = abs($postId);
        $taxonomy = trim($taxonomy);
        if ($postId <= 0 || $taxonomy === '') {
            return;
        }

        $cleanSlugs = [];
        foreach ($slugs as $slug) {
            $slug = strtolower(trim((string) $slug));
            $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? '';
            $slug = trim($slug, '-');
            if ($slug !== '') {
                $cleanSlugs[$slug] = $slug;
            }
        }
        $cleanSlugs = array_values($cleanSlugs);

        $existing = $this->db()->table($this->tr() . ' as tr')
            ->join($this->tt() . ' as tt', 'tt.term_taxonomy_id', '=', 'tr.term_taxonomy_id')
            ->where('tr.object_id', $postId)
            ->where('tt.taxonomy', $taxonomy)
            ->pluck('tr.term_taxonomy_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        $existingSet = array_fill_keys($existing, true);
        $desiredIds = [];
        if ($cleanSlugs !== []) {
            $rows = $this->db()->table($this->tt() . ' as tt')
                ->join($this->terms() . ' as t', 't.term_id', '=', 'tt.term_id')
                ->where('tt.taxonomy', $taxonomy)
                ->whereIn('t.slug', $cleanSlugs)
                ->get(['tt.term_taxonomy_id', 't.slug']);
            $existingBySlug = [];
            foreach ($rows as $row) {
                $id = (int) ($row->term_taxonomy_id ?? 0);
                $slug = strtolower(trim((string) ($row->slug ?? '')));
                if ($id > 0) {
                    $desiredIds[$id] = $id;
                    if ($slug !== '') {
                        $existingBySlug[$slug] = $id;
                    }
                }
            }

            foreach ($cleanSlugs as $slug) {
                if (isset($existingBySlug[$slug])) {
                    continue;
                }
                $createdId = $this->ensureTaxonomyTermBySlug($taxonomy, $slug);
                if ($createdId > 0) {
                    $desiredIds[$createdId] = $createdId;
                }
            }
        }
        $desiredIds = array_values($desiredIds);
        $desiredSet = array_fill_keys($desiredIds, true);

        $toDelete = [];
        foreach ($existing as $id) {
            if (!isset($desiredSet[$id])) {
                $toDelete[] = $id;
            }
        }
        if ($toDelete !== []) {
            $this->db()->table($this->tr())
                ->where('object_id', $postId)
                ->whereIn('term_taxonomy_id', $toDelete)
                ->delete();
        }

        foreach ($desiredIds as $termTaxonomyId) {
            if (isset($existingSet[$termTaxonomyId])) {
                continue;
            }
            $this->db()->table($this->tr())->insert([
                'object_id' => $postId,
                'term_taxonomy_id' => $termTaxonomyId,
                'term_order' => 0,
            ]);
        }

        $touchIds = array_values(array_unique(array_merge($existing, $desiredIds)));
        foreach ($touchIds as $termTaxonomyId) {
            $count = (int) $this->db()->table($this->tr())
                ->where('term_taxonomy_id', $termTaxonomyId)
                ->count('object_id');
            $this->db()->table($this->tt())
                ->where('term_taxonomy_id', $termTaxonomyId)
                ->update(['count' => $count]);
        }
    }

    private function ensureTaxonomyTermBySlug(string $taxonomy, string $slug): int
    {
        $taxonomy = trim($taxonomy);
        $slug = strtolower(trim($slug));
        if ($taxonomy === '' || $slug === '') {
            return 0;
        }

        $existing = $this->db()->table($this->tt() . ' as tt')
            ->join($this->terms() . ' as t', 't.term_id', '=', 'tt.term_id')
            ->where('tt.taxonomy', $taxonomy)
            ->where('t.slug', $slug)
            ->first(['tt.term_taxonomy_id']);
        if ($existing !== null) {
            return (int) ($existing->term_taxonomy_id ?? 0);
        }

        $termId = (int) $this->db()->table($this->terms())
            ->where('slug', $slug)
            ->value('term_id');
        if ($termId <= 0) {
            $termId = (int) $this->db()->table($this->terms())->insertGetId([
                'name' => $this->taxonomyNameFromSlug($slug),
                'slug' => $slug,
                'term_group' => 0,
            ]);
        }
        if ($termId <= 0) {
            return 0;
        }

        $taxonomyId = (int) $this->db()->table($this->tt())
            ->where('term_id', $termId)
            ->where('taxonomy', $taxonomy)
            ->value('term_taxonomy_id');
        if ($taxonomyId > 0) {
            return $taxonomyId;
        }

        return (int) $this->db()->table($this->tt())->insertGetId([
            'term_id' => $termId,
            'taxonomy' => $taxonomy,
            'description' => '',
            'parent' => 0,
            'count' => 0,
        ]);
    }

    private function taxonomyNameFromSlug(string $slug): string
    {
        $label = trim((string) preg_replace('/[-_]+/', ' ', $slug));
        if ($label === '') {
            return $slug;
        }
        return ucwords($label);
    }

    private function saveUploadedAttachment(mixed $file, int $authorId, int $parentPostId, string $titlePrefix): int
    {
        if (!($file instanceof UploadedFile) || !$file->isValid()) {
            return 0;
        }

        $uploadsRoot = public_path('wp-content/uploads');
        $subdir = date('Y/m');
        $targetDir = $uploadsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subdir);
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return 0;
        }

        $originalName = (string) $file->getClientOriginalName();
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', $base), '-');
        if ($base === '') {
            $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', $titlePrefix), '-'));
        }
        if ($base === '') {
            $base = 'upload';
        }

        $extension = strtolower(trim((string) $file->getClientOriginalExtension()));
        if ($extension === '') {
            $guessed = (string) $file->guessExtension();
            $extension = strtolower(trim($guessed));
        }
        if ($extension === '') {
            $extension = 'bin';
        }

        $filename = $base . '-' . date('YmdHis') . '-' . random_int(1000, 9999) . '.' . $extension;
        $moved = $file->move($targetDir, $filename);
        $absolutePath = $moved->getPathname();
        if (!is_file($absolutePath)) {
            return 0;
        }

        $relativePath = $subdir . '/' . $filename;
        $mime = (string) ($file->getClientMimeType() ?: $file->getMimeType() ?: 'application/octet-stream');
        $now = now();
        $nowUtc = now('UTC');
        $postTitle = trim($titlePrefix . ' ' . pathinfo($filename, PATHINFO_FILENAME));

        $attachmentId = (int) $this->db()->table($this->posts())->insertGetId([
            'post_author' => max(0, $authorId),
            'post_date' => $now->format('Y-m-d H:i:s'),
            'post_date_gmt' => $nowUtc->format('Y-m-d H:i:s'),
            'post_content' => '',
            'post_title' => $postTitle !== '' ? $postTitle : 'Attachment',
            'post_excerpt' => '',
            'post_status' => 'inherit',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_password' => '',
            'post_name' => trim((string) preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($postTitle)), '-'),
            'to_ping' => '',
            'pinged' => '',
            'post_modified' => $now->format('Y-m-d H:i:s'),
            'post_modified_gmt' => $nowUtc->format('Y-m-d H:i:s'),
            'post_content_filtered' => '',
            'post_parent' => max(0, $parentPostId),
            'guid' => rtrim((string) config('app.url', ''), '/') . '/wp-content/uploads/' . $relativePath,
            'menu_order' => 0,
            'post_type' => 'attachment',
            'post_mime_type' => $mime,
            'comment_count' => 0,
        ]);
        if ($attachmentId <= 0) {
            @unlink($absolutePath);
            return 0;
        }

        $this->upsertPostMeta($attachmentId, '_wp_attached_file', str_replace('\\', '/', $relativePath));
        $this->upsertPostMeta($attachmentId, '_wp_attachment_metadata', '');
        return $attachmentId;
    }

    private function deleteAttachment(int $attachmentId): void
    {
        $attachmentId = abs($attachmentId);
        if ($attachmentId <= 0) {
            return;
        }

        $relative = $this->getLatestPostMeta($attachmentId, '_wp_attached_file');
        if ($relative !== '') {
            $fullPath = public_path('wp-content/uploads/' . ltrim(str_replace('\\', '/', $relative), '/'));
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }

        $this->db()->table($this->pm())->where('post_id', $attachmentId)->delete();
        $this->db()->table($this->tr())->where('object_id', $attachmentId)->delete();
        $this->db()->table($this->posts())->where('ID', $attachmentId)->where('post_type', 'attachment')->delete();
    }

    private function getLatestPostMeta(int $postId, string $key): string
    {
        $v = $this->db()->table($this->pm())->where('post_id', $postId)->where('meta_key', $key)->orderByDesc('meta_id')->value('meta_value');
        return is_string($v) ? $v : '';
    }

    /** @param array<int> $ids @param array<int,string> $keys @return array<int,array<string,string>> */
    private function postMetaMap(array $ids, array $keys): array
    {
        if ($ids === [] || $keys === []) {
            return [];
        }
        $rows = $this->db()->table($this->pm())->whereIn('post_id', $ids)->whereIn('meta_key', $keys)->orderByDesc('meta_id')->get(['post_id', 'meta_key', 'meta_value']);
        $map = [];
        foreach ($rows as $r) {
            $p = (int) $r->post_id;
            $k = (string) $r->meta_key;
            if (!isset($map[$p])) {
                $map[$p] = [];
            }
            if (!isset($map[$p][$k])) {
                $map[$p][$k] = (string) $r->meta_value;
            }
        }
        return $map;
    }

    /** @param array<int> $ids @return array<int,array<string,array<int,array{slug:string,name:string}>>> */
    private function termMap(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->db()->table($this->tr() . ' as tr')->join($this->tt() . ' as tt', 'tt.term_taxonomy_id', '=', 'tr.term_taxonomy_id')->join($this->terms() . ' as t', 't.term_id', '=', 'tt.term_id')->whereIn('tr.object_id', $ids)->get(['tr.object_id', 'tt.taxonomy', 't.slug', 't.name']);
        $map = [];
        foreach ($rows as $r) {
            $oid = (int) $r->object_id;
            $tx = (string) $r->taxonomy;
            if (!isset($map[$oid])) {
                $map[$oid] = [];
            }
            if (!isset($map[$oid][$tx])) {
                $map[$oid][$tx] = [];
            }
            $map[$oid][$tx][] = ['slug' => (string) $r->slug, 'name' => (string) $r->name];
        }
        return $map;
    }

    private function artistMeetsBookableRequirements(array $artist, array $userRows, array $userMetaMap, array $requiredPolicies): bool
    {
        $meta = is_array($artist['meta'] ?? null) ? $artist['meta'] : [];
        $userId = (int) ($meta['gigtune_user_id'] ?? $meta['gigtune_artist_user_id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }
        if (!isset($userRows[$userId])) {
            return false;
        }

        $userMeta = is_array($userMetaMap[$userId] ?? null) ? $userMetaMap[$userId] : [];
        if (!$this->userEmailVerified($userMeta)) {
            return false;
        }

        $firstName = trim((string) ($userMeta['first_name'] ?? ''));
        $lastName = trim((string) ($userMeta['last_name'] ?? ''));
        $fullName = trim($firstName . ' ' . $lastName);
        $displayName = trim((string) ($userRows[$userId]['display_name'] ?? ''));
        if (!$this->isValidFullName($fullName) && !$this->isValidFullName($displayName)) {
            return false;
        }

        if (!$this->userHasLatestPolicyAcceptance($userMeta, $requiredPolicies)) {
            return false;
        }

        $visibilityOverride = strtolower(trim((string) ($userMeta['gigtune_profile_visibility_override'] ?? 'auto')));
        if ($visibilityOverride === 'force_hidden') {
            return false;
        }
        if ($visibilityOverride === 'force_visible') {
            return true;
        }

        $kycRequiredFor = $this->userKycRequiredFor($userMeta);
        if (in_array('artist_receive_requests', $kycRequiredFor, true) && $this->userKycStatus($userMeta) !== 'verified') {
            return false;
        }

        $profileName = trim((string) ($artist['title'] ?? ''));
        $profileBio = trim((string) ($artist['content'] ?? ''));
        $baseArea = trim((string) ($artist['availability']['base_area'] ?? ''));
        $travelRadius = (int) ($meta['gigtune_artist_travel_radius_km'] ?? 0);
        $priceMin = (int) ($meta['gigtune_artist_price_min'] ?? 0);
        $priceMax = (int) ($meta['gigtune_artist_price_max'] ?? 0);
        $days = is_array($artist['availability']['days'] ?? null) ? $artist['availability']['days'] : [];
        $startTime = trim((string) ($artist['availability']['start_time'] ?? ''));
        $endTime = trim((string) ($artist['availability']['end_time'] ?? ''));
        $performerTerms = is_array($artist['terms']['performer_type'] ?? null) ? $artist['terms']['performer_type'] : [];

        if ($profileName === '' || $profileBio === '' || $baseArea === '') {
            return false;
        }
        if ($travelRadius <= 0) {
            return false;
        }
        if ($priceMin < 0 || $priceMax <= 0 || $priceMin > $priceMax) {
            return false;
        }
        if ($days === []) {
            return false;
        }
        if (preg_match('/^\d{2}:\d{2}$/', $startTime) !== 1 || preg_match('/^\d{2}:\d{2}$/', $endTime) !== 1) {
            return false;
        }
        if (count($performerTerms) < 1) {
            return false;
        }

        return true;
    }

    /** @return array<int,array<string,string>> */
    private function userMetaLatestMap(array $userIds, array $keys): array
    {
        if ($userIds === [] || $keys === []) {
            return [];
        }
        $rows = $this->db()->table($this->um())
            ->whereIn('user_id', $userIds)
            ->whereIn('meta_key', $keys)
            ->orderByDesc('umeta_id')
            ->get(['user_id', 'meta_key', 'meta_value']);

        $map = [];
        foreach ($rows as $row) {
            $uid = (int) ($row->user_id ?? 0);
            $key = (string) ($row->meta_key ?? '');
            if ($uid <= 0 || $key === '') {
                continue;
            }
            if (!isset($map[$uid])) {
                $map[$uid] = [];
            }
            if (!isset($map[$uid][$key])) {
                $map[$uid][$key] = (string) ($row->meta_value ?? '');
            }
        }
        return $map;
    }

    private function userEmailVerified(array $userMeta): bool
    {
        $required = trim((string) ($userMeta['gigtune_email_verification_required'] ?? ''));
        if ($required !== '1') {
            return true;
        }
        return trim((string) ($userMeta['gigtune_email_verified'] ?? '')) === '1';
    }

    private function userHasLatestPolicyAcceptance(array $userMeta, array $requiredPolicies): bool
    {
        if ($requiredPolicies === []) {
            return true;
        }
        $raw = (string) ($userMeta['gigtune_policy_acceptance'] ?? '');
        $decoded = $this->maybe($raw);
        if (!is_array($decoded)) {
            return false;
        }
        foreach ($requiredPolicies as $policyKey => $requiredVersion) {
            $policyKey = trim((string) $policyKey);
            if ($policyKey === '') {
                continue;
            }
            $item = $decoded[$policyKey] ?? null;
            if (!is_array($item)) {
                return false;
            }
            $acceptedVersion = trim((string) ($item['version'] ?? ''));
            if ($acceptedVersion !== (string) $requiredVersion) {
                return false;
            }
        }
        return true;
    }

    /** @return array<int,string> */
    private function userKycRequiredFor(array $userMeta): array
    {
        $raw = $this->maybe((string) ($userMeta['gigtune_kyc_required_for'] ?? ''));
        $allowed = ['client_requests', 'artist_receive_requests', 'payouts'];
        if (!is_array($raw) || $raw === []) {
            return $allowed;
        }
        $out = [];
        foreach ($raw as $entry) {
            $key = strtolower(trim((string) $entry));
            if (in_array($key, $allowed, true)) {
                $out[] = $key;
            }
        }
        $out = array_values(array_unique($out));
        return $out === [] ? $allowed : $out;
    }

    private function userKycStatus(array $userMeta): string
    {
        $status = strtolower(trim((string) ($userMeta['gigtune_kyc_status'] ?? '')));
        if (!in_array($status, ['unsubmitted', 'pending', 'verified', 'rejected', 'locked'], true)) {
            return 'unsubmitted';
        }
        return $status;
    }

    private function isValidFullName(string $fullName): bool
    {
        $fullName = trim($fullName);
        if ($fullName === '' || filter_var($fullName, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $parts = preg_split('/\s+/', $fullName);
        if (!is_array($parts)) {
            return false;
        }
        $parts = array_values(array_filter($parts, static fn ($part): bool => trim((string) $part) !== ''));
        return count($parts) >= 2;
    }

    /** @return array<string,array{message:string,url:string}> */
    private function missingRequirements(int $userId, string $context): array
    {
        $userId = abs($userId);
        $context = strtolower(trim($context));
        $missing = [];

        if ($userId <= 0) {
            $missing['sign_in'] = [
                'message' => 'Please sign in to continue.',
                'url' => '/sign-in/',
            ];
            return $missing;
        }

        $userRow = $this->db()->table($this->usersTable())
            ->where('ID', $userId)
            ->first(['ID', 'display_name']);
        if ($userRow === null) {
            $missing['account'] = [
                'message' => 'User account could not be loaded.',
                'url' => '/',
            ];
            return $missing;
        }

        $userMeta = $this->userMetaLatestMap([$userId], [
            'gigtune_email_verification_required',
            'gigtune_email_verified',
            'first_name',
            'last_name',
            'gigtune_policy_acceptance',
            'gigtune_kyc_status',
            'gigtune_kyc_required_for',
        ])[$userId] ?? [];

        $firstName = trim((string) ($userMeta['first_name'] ?? ''));
        $lastName = trim((string) ($userMeta['last_name'] ?? ''));
        $fullName = trim($firstName . ' ' . $lastName);
        $displayName = trim((string) ($userRow->display_name ?? ''));
        $hasValidName = $this->isValidFullName($fullName) || $this->isValidFullName($displayName);
        $hasLatestPolicies = $this->userHasLatestPolicyAcceptance($userMeta, $this->users->requiredPolicyVersions());
        $emailVerified = $this->userEmailVerified($userMeta);
        $kycRequiredFor = $this->userKycRequiredFor($userMeta);
        $kycStatus = $this->userKycStatus($userMeta);

        if (in_array($context, ['client_can_book', 'client_can_request'], true)) {
            if (!$hasValidName) {
                $missing['full_name'] = [
                    'message' => 'Complete your account name (name and surname).',
                    'url' => '/my-account-page/',
                ];
            }
            if (!$hasLatestPolicies) {
                $missing['policy_acceptance'] = [
                    'message' => 'Accept the latest policies to continue.',
                    'url' => '/policy-consent/',
                ];
            }
            if (!$emailVerified) {
                $missing['email_verified'] = [
                    'message' => 'Verify your email address before booking.',
                    'url' => '/verify-email/',
                ];
            }
            if (in_array('client_requests', $kycRequiredFor, true) && $kycStatus !== 'verified') {
                $missing['kyc_status'] = [
                    'message' => 'Identity Verification (Know Your Customer Compliance) is required before booking.',
                    'url' => '/kyc-status/',
                ];
            }

            $clientProfileId = $this->latestUserMetaInt($userId, 'gigtune_client_profile_id');
            if ($clientProfileId <= 0) {
                $missing['client_profile'] = [
                    'message' => 'Complete your client profile before booking.',
                    'url' => '/my-account-page/',
                ];
            } else {
                $profile = $this->db()->table($this->posts())
                    ->where('ID', $clientProfileId)
                    ->where('post_type', 'gt_client_profile')
                    ->first(['post_title', 'post_content']);
                if ($profile === null) {
                    $missing['client_profile'] = [
                        'message' => 'Complete your client profile before booking.',
                        'url' => '/my-account-page/',
                    ];
                } else {
                    $meta = $this->postMetaMap([$clientProfileId], [
                        'gigtune_client_company',
                        'gigtune_client_phone',
                        'gigtune_client_base_area',
                        'gigtune_client_province',
                        'gigtune_client_address_street',
                        'gigtune_client_address_suburb',
                        'gigtune_client_address_city',
                        'gigtune_client_address_postal_code',
                        'gigtune_client_address_country',
                    ])[$clientProfileId] ?? [];
                    $company = trim((string) ($meta['gigtune_client_company'] ?? ''));
                    $phoneDigits = preg_replace('/\D+/', '', (string) ($meta['gigtune_client_phone'] ?? '')) ?? '';
                    $baseArea = trim((string) ($meta['gigtune_client_base_area'] ?? ''));
                    $province = trim((string) ($meta['gigtune_client_province'] ?? ''));
                    $addressStreet = trim((string) ($meta['gigtune_client_address_street'] ?? ''));
                    $addressSuburb = trim((string) ($meta['gigtune_client_address_suburb'] ?? ''));
                    $addressCity = trim((string) ($meta['gigtune_client_address_city'] ?? ''));
                    $addressPostalCode = trim((string) ($meta['gigtune_client_address_postal_code'] ?? ''));
                    $addressCountry = trim((string) ($meta['gigtune_client_address_country'] ?? ''));
                    $title = trim((string) ($profile->post_title ?? ''));
                    $bio = trim((string) ($profile->post_content ?? ''));

                    if ($title === '') {
                        $missing['client_title'] = [
                            'message' => 'Add your display name in your client profile.',
                            'url' => '/my-account-page/',
                        ];
                    }
                    if ($bio === '') {
                        $missing['client_bio'] = [
                            'message' => 'Add your about section in your client profile.',
                            'url' => '/my-account-page/',
                        ];
                    }
                    if ($company === '') {
                        $missing['client_company'] = [
                            'message' => 'Add your company name in your client profile.',
                            'url' => '/my-account-page/',
                        ];
                    }
                    if ($baseArea === '') {
                        $missing['client_base_area'] = [
                            'message' => 'Add your base area in your client profile.',
                            'url' => '/my-account-page/',
                        ];
                    }
                    if (strlen($phoneDigits) < 9) {
                        $missing['client_phone'] = [
                            'message' => 'Add a valid phone number in your client profile.',
                            'url' => '/my-account-page/',
                        ];
                    }
                    if ($province === '') {
                        $missing['client_province'] = [
                            'message' => 'Add your province in your client profile.',
                            'url' => '/my-account-page/',
                        ];
                    }
                    if ($addressStreet === '' || $addressCity === '' || $addressPostalCode === '' || $addressCountry === '') {
                        $missing['client_address'] = [
                            'message' => 'Complete your client profile address (street, city, postcode, country).',
                            'url' => '/my-account-page/',
                        ];
                    } elseif (preg_match('/^\d{4}$/', $addressPostalCode) !== 1) {
                        $missing['client_postal_code'] = [
                            'message' => 'Client profile postcode must be a 4-digit number.',
                            'url' => '/my-account-page/',
                        ];
                    } elseif ($addressSuburb === '') {
                        $missing['client_suburb'] = [
                            'message' => 'Add your suburb in your client profile.',
                            'url' => '/my-account-page/',
                        ];
                    }
                }
            }

            return $missing;
        }

        if (!in_array($context, ['artist_bookable', 'artist_payout_ready', 'artist_can_receive_requests'], true)) {
            return $missing;
        }

        $profileId = $this->latestUserMetaInt($userId, 'gigtune_artist_profile_id');
        if ($profileId <= 0) {
            $missing['profile_link'] = [
                'message' => 'Create and link your artist profile.',
                'url' => '/artist-profile-edit/',
            ];
            return $missing;
        }

        if (!$emailVerified) {
            $missing['email_verified'] = [
                'message' => 'Verify your email before making your profile discoverable.',
                'url' => '/verify-email/',
            ];
        }
        if (!$hasValidName) {
            $missing['full_name'] = [
                'message' => 'Complete your account name (name and surname).',
                'url' => '/artist-profile-edit/',
            ];
        }
        if (!$hasLatestPolicies) {
            $missing['policy_acceptance'] = [
                'message' => 'Accept the latest policies to continue.',
                'url' => '/policy-consent/',
            ];
        }

        $requiresArtistKyc = in_array('artist_receive_requests', $kycRequiredFor, true);
        $requiresPayoutKyc = $context === 'artist_payout_ready' && in_array('payouts', $kycRequiredFor, true);
        if (($requiresArtistKyc || $requiresPayoutKyc) && $kycStatus !== 'verified') {
            $missing['kyc_status'] = [
                'message' => 'Identity Verification (Know Your Customer Compliance) is required.',
                'url' => '/kyc-status/',
            ];
        }

        $profile = $this->db()->table($this->posts())
            ->where('ID', $profileId)
            ->where('post_type', 'artist_profile')
            ->first(['post_title', 'post_content']);
        if ($profile === null) {
            $missing['profile_link'] = [
                'message' => 'Create and link your artist profile.',
                'url' => '/artist-profile-edit/',
            ];
            return $missing;
        }

        $meta = $this->postMetaMap([$profileId], [
            'gigtune_artist_base_area',
            'gigtune_artist_travel_radius_km',
            'gigtune_artist_price_min',
            'gigtune_artist_price_max',
            'gigtune_artist_availability_days',
            'gigtune_artist_availability_start_time',
            'gigtune_artist_availability_end_time',
            'gigtune_artist_bank_account_name',
            'gigtune_artist_bank_account_number',
            'gigtune_artist_bank_name',
            'gigtune_artist_branch_code',
            'gigtune_artist_bank_code',
            'gigtune_artist_address_street',
            'gigtune_artist_address_suburb',
            'gigtune_artist_address_city',
            'gigtune_artist_address_province',
            'gigtune_artist_address_postal_code',
            'gigtune_artist_address_country',
        ])[$profileId] ?? [];
        $terms = $this->termMap([$profileId]);
        $performerCount = count($terms[$profileId]['performer_type'] ?? []);

        $profileName = trim((string) ($profile->post_title ?? ''));
        $profileBio = trim((string) ($profile->post_content ?? ''));
        $baseArea = trim((string) ($meta['gigtune_artist_base_area'] ?? ''));
        $travelRadius = (int) ($meta['gigtune_artist_travel_radius_km'] ?? 0);
        $priceMin = (int) ($meta['gigtune_artist_price_min'] ?? 0);
        $priceMax = (int) ($meta['gigtune_artist_price_max'] ?? 0);
        $days = $this->days((string) ($meta['gigtune_artist_availability_days'] ?? ''));
        $startTime = trim((string) ($meta['gigtune_artist_availability_start_time'] ?? ''));
        $endTime = trim((string) ($meta['gigtune_artist_availability_end_time'] ?? ''));
        $addressStreet = trim((string) ($meta['gigtune_artist_address_street'] ?? ''));
        $addressSuburb = trim((string) ($meta['gigtune_artist_address_suburb'] ?? ''));
        $addressCity = trim((string) ($meta['gigtune_artist_address_city'] ?? ''));
        $addressProvince = trim((string) ($meta['gigtune_artist_address_province'] ?? ''));
        $addressPostalCode = trim((string) ($meta['gigtune_artist_address_postal_code'] ?? ''));
        $addressCountry = trim((string) ($meta['gigtune_artist_address_country'] ?? ''));

        if ($profileName === '') {
            $missing['profile_name'] = ['message' => 'Add your profile name.', 'url' => '/artist-profile-edit/'];
        }
        if ($profileBio === '') {
            $missing['profile_bio'] = ['message' => 'Add your profile bio.', 'url' => '/artist-profile-edit/'];
        }
        if ($performerCount < 1) {
            $missing['performer_type'] = ['message' => 'Select at least one performer type.', 'url' => '/artist-profile-edit/'];
        }
        if ($baseArea === '') {
            $missing['base_area'] = ['message' => 'Set your base area.', 'url' => '/artist-profile-edit/'];
        }
        if ($travelRadius <= 0) {
            $missing['travel_radius'] = ['message' => 'Set a valid travel radius.', 'url' => '/artist-profile-edit/'];
        }
        if ($priceMin < 0 || $priceMax <= 0 || $priceMin > $priceMax) {
            $missing['pricing'] = ['message' => 'Set a valid minimum and maximum price range.', 'url' => '/artist-profile-edit/'];
        }
        if ($days === []) {
            $missing['availability_days'] = ['message' => 'Select at least one availability day.', 'url' => '/artist-profile-edit/'];
        }
        if (preg_match('/^\d{2}:\d{2}$/', $startTime) !== 1 || preg_match('/^\d{2}:\d{2}$/', $endTime) !== 1) {
            $missing['availability_time'] = ['message' => 'Set valid availability start and end times.', 'url' => '/artist-profile-edit/'];
        }
        if ($addressStreet === '' || $addressCity === '' || $addressProvince === '' || $addressPostalCode === '' || $addressCountry === '') {
            $missing['address'] = ['message' => 'Complete your profile address (street, city, province, postcode, country).', 'url' => '/artist-profile-edit/'];
        } elseif (preg_match('/^\d{4}$/', $addressPostalCode) !== 1) {
            $missing['address_postal_code'] = ['message' => 'Profile postcode must be a 4-digit number.', 'url' => '/artist-profile-edit/'];
        } elseif ($addressSuburb === '') {
            $missing['address_suburb'] = ['message' => 'Add your suburb in your profile address.', 'url' => '/artist-profile-edit/'];
        }

        if ($context === 'artist_payout_ready') {
            $bankAccountName = trim((string) ($meta['gigtune_artist_bank_account_name'] ?? ''));
            $bankAccountNumber = preg_replace('/\s+/', '', (string) ($meta['gigtune_artist_bank_account_number'] ?? '')) ?? '';
            $bankName = trim((string) ($meta['gigtune_artist_bank_name'] ?? ''));
            $branchCode = trim((string) ($meta['gigtune_artist_branch_code'] ?? ''));
            if ($branchCode === '') {
                $branchCode = trim((string) ($meta['gigtune_artist_bank_code'] ?? ''));
            }

            if ($bankAccountName === '' || $bankAccountNumber === '' || $bankName === '' || $branchCode === '') {
                $missing['bank_fields'] = ['message' => 'Complete payout bank details.', 'url' => '/artist-profile-edit/'];
            } elseif (preg_match('/^\d{6,20}$/', $bankAccountNumber) !== 1) {
                $missing['bank_account_number'] = ['message' => 'Bank account number must contain only digits.', 'url' => '/artist-profile-edit/'];
            }
        }

        return $missing;
    }

    /** @param array<string,array{message:string,url:string}> $requirements */
    private function renderMissingRequirementsPanel(string $title, array $requirements): string
    {
        if ($requirements === []) {
            return '';
        }

        $html = '<div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-6">';
        $html .= '<h3 class="text-lg font-semibold text-amber-100">' . e($title) . '</h3>';
        $html .= '<p class="mt-2 text-sm text-amber-100/90">Complete the items below to unlock all actions.</p>';
        $html .= '<ul class="mt-3 space-y-2 text-sm text-amber-50">';
        foreach ($requirements as $item) {
            $message = trim((string) ($item['message'] ?? 'Requirement missing.'));
            $url = trim((string) ($item['url'] ?? ''));
            if ($message === '') {
                $message = 'Requirement missing.';
            }
            $html .= '<li class="flex items-start gap-2"><span aria-hidden="true">&bull;</span>';
            if ($url !== '') {
                $html .= '<a href="' . e($url) . '" class="underline decoration-amber-200/70 hover:text-white">' . e($message) . '</a>';
            } else {
                $html .= '<span>' . e($message) . '</span>';
            }
            $html .= '</li>';
        }
        $html .= '</ul></div>';

        return $html;
    }

    /** @param array<string,array{message:string,url:string}> $requirements */
    private function firstMissingRequirementMessage(array $requirements, string $fallback): string
    {
        foreach ($requirements as $item) {
            $message = trim((string) ($item['message'] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }
        return $fallback;
    }

    private function bookArtistFormUrl(int $artistId = 0): string
    {
        $slug = $this->db()->table($this->posts())
            ->where('post_type', 'page')
            ->where('post_status', 'publish')
            ->whereIn('post_name', ['book-artist-form', 'book-an-artist'])
            ->orderByRaw("CASE WHEN post_name = 'book-artist-form' THEN 0 ELSE 1 END")
            ->orderByDesc('ID')
            ->value('post_name');

        $path = '/' . (is_string($slug) && $slug !== '' ? $slug : 'book-an-artist') . '/';
        if ($artistId > 0) {
            return $path . '?artist_id=' . $artistId;
        }
        return $path;
    }

    /** @return array<int,string> */
    private function saProvinces(): array
    {
        return [
            'Eastern Cape',
            'Free State',
            'Gauteng',
            'KwaZulu-Natal',
            'Limpopo',
            'Mpumalanga',
            'Northern Cape',
            'North West',
            'Western Cape',
        ];
    }

    /** @return array<int,string> */
    private function notificationEmailCategories(): array
    {
        return ['booking', 'psa', 'message', 'payment', 'dispute', 'security'];
    }

    /** @return array<string,bool> */
    private function defaultNotificationEmailPreferences(): array
    {
        return [
            'booking' => true,
            'psa' => true,
            'message' => true,
            'payment' => true,
            'dispute' => true,
            'security' => true,
        ];
    }

    /** @return array<string,bool> */
    private function loadNotificationEmailPreferences(int $userId): array
    {
        $defaults = $this->defaultNotificationEmailPreferences();
        if ($userId <= 0) {
            return $defaults;
        }

        $raw = trim($this->latestUserMeta($userId, 'gigtune_notification_email_preferences'));
        if ($raw === '') {
            return $defaults;
        }

        $decoded = $this->maybe($raw);
        if (!is_array($decoded)) {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $decoded = $json;
            } else {
                return $defaults;
            }
        }

        $preferences = $defaults;
        foreach ($this->notificationEmailCategories() as $category) {
            if (array_key_exists($category, $decoded)) {
                $preferences[$category] = $this->bool($decoded[$category] ?? false);
            }
        }
        return $preferences;
    }

    /** @param array<string,bool> $preferences */
    private function storeNotificationEmailPreferences(int $userId, array $preferences): void
    {
        if ($userId <= 0) {
            return;
        }

        $normalized = $this->defaultNotificationEmailPreferences();
        foreach ($this->notificationEmailCategories() as $category) {
            if (array_key_exists($category, $preferences)) {
                $normalized[$category] = $this->bool($preferences[$category]);
            }
        }

        $serialized = serialize($normalized);
        $this->upsertUserMeta($userId, 'gigtune_notification_email_preferences', $serialized);
        $this->upsertUserMeta($userId, 'gigtune_notification_email_preferences_updated_at', now()->format('Y-m-d H:i:s'));

        // Backward compatibility for legacy Laravel checks that still read this flag.
        $emailEnabled = in_array(true, $normalized, true);
        $this->upsertUserMeta($userId, 'gigtune_notify_email', $emailEnabled ? '1' : '0');
    }

    /** @param array<string,mixed> $context */
    private function createNotification(int $recipientUserId, string $type, string $message, array $context = []): void
    {
        $recipientUserId = abs($recipientUserId);
        if ($recipientUserId <= 0 || trim($message) === '') {
            return;
        }

        $now = now();
        $nowUtc = now('UTC');
        $postId = (int) $this->db()->table($this->posts())->insertGetId([
            'post_author' => 0,
            'post_date' => $now->format('Y-m-d H:i:s'),
            'post_date_gmt' => $nowUtc->format('Y-m-d H:i:s'),
            'post_content' => '',
            'post_title' => $message,
            'post_excerpt' => '',
            'post_status' => 'publish',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_password' => '',
            'post_name' => 'gigtune-notification-' . $now->format('YmdHis') . '-' . random_int(100, 999),
            'to_ping' => '',
            'pinged' => '',
            'post_modified' => $now->format('Y-m-d H:i:s'),
            'post_modified_gmt' => $nowUtc->format('Y-m-d H:i:s'),
            'post_content_filtered' => '',
            'post_parent' => 0,
            'guid' => '',
            'menu_order' => 0,
            'post_type' => 'gigtune_notification',
            'post_mime_type' => '',
            'comment_count' => 0,
        ]);
        if ($postId <= 0) {
            return;
        }

        $this->upsertPostMeta($postId, 'gigtune_notification_user_id', (string) $recipientUserId);
        $this->upsertPostMeta($postId, 'gigtune_notification_recipient_user_id', (string) $recipientUserId);
        $this->upsertPostMeta($postId, 'recipient_user_id', (string) $recipientUserId);
        $this->upsertPostMeta($postId, 'gigtune_notification_type', trim($type) !== '' ? $type : 'security');
        $this->upsertPostMeta($postId, 'notification_type', trim($type) !== '' ? $type : 'security');
        $this->upsertPostMeta($postId, 'gigtune_notification_message', $message);
        $this->upsertPostMeta($postId, 'message', $message);
        $this->upsertPostMeta($postId, 'gigtune_notification_created_at', (string) $now->timestamp);
        $this->upsertPostMeta($postId, 'created_at', (string) $now->timestamp);
        $this->upsertPostMeta($postId, 'gigtune_notification_is_read', '0');
        $this->upsertPostMeta($postId, 'gigtune_notification_is_archived', '0');
        $this->upsertPostMeta($postId, 'gigtune_notification_is_deleted', '0');

        $objectType = trim((string) ($context['object_type'] ?? ''));
        if ($objectType !== '') {
            $this->upsertPostMeta($postId, 'gigtune_notification_object_type', $objectType);
            $this->upsertPostMeta($postId, 'object_type', $objectType);
        }
        $objectId = (int) ($context['object_id'] ?? 0);
        if ($objectId > 0) {
            $this->upsertPostMeta($postId, 'gigtune_notification_object_id', (string) $objectId);
            $this->upsertPostMeta($postId, 'object_id', (string) $objectId);
        }
        $artistProfileId = (int) ($context['artist_profile_id'] ?? 0);
        if ($artistProfileId > 0) {
            $this->upsertPostMeta($postId, 'artist_profile_id', (string) $artistProfileId);
        }

        try {
            $this->mail->sendNotificationEmail(
                $recipientUserId,
                trim($type) !== '' ? $type : 'security',
                $message,
                [
                    'object_type' => $objectType,
                    'object_id' => $objectId,
                ]
            );
        } catch (\Throwable) {
            // Keep notification persistence non-blocking if mail transport fails.
        }
    }

    private function bookingDisputeWindowDays(): int
    {
        return 7;
    }

    private function bookingDisputeDeadlineTimestamp(string $eventDate): int
    {
        $eventDate = trim($eventDate);
        if ($eventDate === '') {
            return 0;
        }

        $timezoneName = (string) config('app.timezone', 'Africa/Johannesburg');
        try {
            $timezone = new \DateTimeZone($timezoneName !== '' ? $timezoneName : 'Africa/Johannesburg');
        } catch (\Throwable) {
            $timezone = new \DateTimeZone('Africa/Johannesburg');
        }

        $eventTs = 0;
        foreach (['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $candidate = \DateTimeImmutable::createFromFormat($format, $eventDate, $timezone);
            if ($candidate instanceof \DateTimeImmutable) {
                $eventTs = $candidate->getTimestamp();
                break;
            }
        }
        if ($eventTs <= 0) {
            $fallback = strtotime($eventDate);
            if ($fallback !== false) {
                $eventTs = (int) $fallback;
            }
        }
        if ($eventTs <= 0) {
            return 0;
        }

        return $eventTs + ($this->bookingDisputeWindowDays() * 86400);
    }

    /** @param array<string,string> $bookingMeta */
    private function bookingCanBeArchived(array $bookingMeta): bool
    {
        $statusRaw = strtoupper(trim((string) ($bookingMeta['gigtune_booking_status'] ?? '')));
        $paymentRaw = strtoupper(trim((string) ($bookingMeta['gigtune_payment_status'] ?? '')));
        $payoutRaw = strtoupper(trim((string) ($bookingMeta['gigtune_payout_status'] ?? '')));
        $refundRaw = strtoupper(trim((string) ($bookingMeta['gigtune_refund_status'] ?? '')));

        $closedStatuses = [
            'REJECTED',
            'PAYMENT_TIMEOUT',
            'COMPLETED_BY_ARTIST',
            'COMPLETED_CONFIRMED',
            'CANCELLED_BY_CLIENT',
            'CANCELLED_BY_ARTIST',
            'REFUNDED',
            'REFUNDED_FULL',
        ];
        if (in_array($statusRaw, $closedStatuses, true)) {
            return true;
        }
        if (in_array($paymentRaw, ['FAILED', 'REFUNDED', 'REFUNDED_FULL'], true)) {
            return true;
        }
        if (in_array($refundRaw, ['SUCCEEDED', 'REJECTED'], true)) {
            return true;
        }
        if (in_array($payoutRaw, ['PAID', 'FAILED'], true)) {
            return true;
        }

        $eventDate = trim((string) ($bookingMeta['gigtune_booking_event_date'] ?? ''));
        if ($eventDate === '') {
            return false;
        }
        $timezoneName = (string) config('app.timezone', 'Africa/Johannesburg');
        try {
            $timezone = new \DateTimeZone($timezoneName !== '' ? $timezoneName : 'Africa/Johannesburg');
        } catch (\Throwable) {
            $timezone = new \DateTimeZone('Africa/Johannesburg');
        }

        $eventTs = 0;
        foreach (['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $candidate = \DateTimeImmutable::createFromFormat($format, $eventDate, $timezone);
            if ($candidate instanceof \DateTimeImmutable) {
                $eventTs = $candidate->getTimestamp();
                break;
            }
        }
        if ($eventTs <= 0) {
            $fallback = strtotime($eventDate);
            if ($fallback !== false) {
                $eventTs = (int) $fallback;
            }
        }

        return $eventTs > 0 && $eventTs <= time();
    }

    /** @param array<string,string> $bookingMeta */
    private function bookingAccommodationFeeAmount(array $bookingMeta): float
    {
        $explicit = max(0.0, (float) ($bookingMeta['gigtune_payment_accommodation_fee'] ?? 0));
        if ($explicit > 0) {
            return $explicit;
        }

        $requiresAccommodation = (string) ($bookingMeta['gigtune_booking_requires_accommodation'] ?? '') === '1';
        $clientOffersAccommodation = (string) ($bookingMeta['gigtune_booking_client_offers_accommodation'] ?? '') === '1';
        if (!$requiresAccommodation || $clientOffersAccommodation) {
            return 0.0;
        }

        $artistProfileId = (int) ($bookingMeta['gigtune_booking_artist_profile_id'] ?? 0);
        if ($artistProfileId <= 0) {
            return 0.0;
        }

        $artistMeta = $this->postMetaMap([$artistProfileId], ['gigtune_artist_accom_fee_flat'])[$artistProfileId] ?? [];
        return max(0.0, (float) ($artistMeta['gigtune_artist_accom_fee_flat'] ?? 0));
    }

    private function updateProfileRatingAverage(int $profileId, string $avgKey, string $countKey, int $score): void
    {
        $profileId = abs($profileId);
        $score = max(1, min(5, $score));
        if ($profileId <= 0 || $avgKey === '' || $countKey === '') {
            return;
        }

        $currentAvg = max(0.0, (float) $this->getLatestPostMeta($profileId, $avgKey));
        $currentCount = max(0, (int) $this->getLatestPostMeta($profileId, $countKey));
        $newCount = $currentCount + 1;
        $newAvg = $newCount > 0 ? round((($currentAvg * $currentCount) + $score) / $newCount, 2) : (float) $score;

        $this->upsertPostMeta($profileId, $avgKey, (string) $newAvg);
        $this->upsertPostMeta($profileId, $countKey, (string) $newCount);
    }

    /** @return array{rating_avg:float,rating_count:int} */
    private function artistRatingSummary(int $profileId): array
    {
        $profileId = abs($profileId);
        if ($profileId <= 0) {
            return ['rating_avg' => 0.0, 'rating_count' => 0];
        }

        $meta = $this->postMetaMap([$profileId], [
            'gigtune_artist_rating_punctuality_avg',
            'gigtune_artist_rating_punctuality_count',
            'gigtune_artist_rating_performance_quality_avg',
            'gigtune_artist_rating_performance_quality_count',
            'gigtune_artist_rating_character_avg',
            'gigtune_artist_rating_character_count',
        ])[$profileId] ?? [];

        $sum = 0.0;
        $count = 0;
        $pairs = [
            ['gigtune_artist_rating_punctuality_avg', 'gigtune_artist_rating_punctuality_count'],
            ['gigtune_artist_rating_performance_quality_avg', 'gigtune_artist_rating_performance_quality_count'],
            ['gigtune_artist_rating_character_avg', 'gigtune_artist_rating_character_count'],
        ];
        foreach ($pairs as [$avgKey, $countKey]) {
            $avg = max(0.0, (float) ($meta[$avgKey] ?? 0));
            $c = max(0, (int) ($meta[$countKey] ?? 0));
            if ($c > 0) {
                $sum += ($avg * $c);
                $count += $c;
            }
        }

        if ($count <= 0) {
            return ['rating_avg' => 0.0, 'rating_count' => 0];
        }

        return [
            'rating_avg' => round($sum / $count, 2),
            'rating_count' => $count,
        ];
    }

    private function profileNameById(int $profileId): string
    {
        $profileId = abs($profileId);
        if ($profileId <= 0) {
            return 'Artist';
        }
        $title = trim((string) $this->db()->table($this->posts())->where('ID', $profileId)->where('post_type', 'artist_profile')->value('post_title'));
        return $title !== '' ? $title : ('Artist #' . $profileId);
    }

    /** @return array<int> */
    private function adminUserIds(): array
    {
        $rows = $this->db()->table($this->um())
            ->where('meta_key', 'like', '%capabilities')
            ->where('meta_value', 'like', '%administrator%')
            ->pluck('user_id')
            ->all();
        $ids = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $id = (int) $row;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
        return array_values($ids);
    }

    private function bookingPaymentReferenceHuman(int $bookingId, int $clientUserId): string
    {
        $bookingId = abs($bookingId);
        if ($bookingId <= 0) {
            return '';
        }
        $existing = trim($this->getLatestPostMeta($bookingId, 'gigtune_payment_reference_human'));
        if ($existing !== '') {
            return $existing;
        }
        $clientCode = 'GT-C-' . str_pad((string) max(0, $clientUserId), 6, '0', STR_PAD_LEFT);
        $reference = 'GT-B-' . $bookingId . ' | ' . $clientCode;
        $this->upsertPostMeta($bookingId, 'gigtune_payment_reference_human', $reference);
        return $reference;
    }

    private function bookingYocoAmountCents(int $bookingId): int
    {
        $bookingId = abs($bookingId);
        if ($bookingId <= 0) {
            return 0;
        }

        $meta = $this->postMetaMap([$bookingId], [
            'gigtune_booking_budget',
            'gigtune_booking_quote_amount',
            'gigtune_booking_travel_amount',
            'gigtune_booking_requires_accommodation',
            'gigtune_booking_client_offers_accommodation',
            'gigtune_booking_artist_profile_id',
            'gigtune_booking_cost_breakdown',
            'gigtune_payment_accommodation_fee',
        ])[$bookingId] ?? [];

        $breakdownRaw = trim((string) ($meta['gigtune_booking_cost_breakdown'] ?? ''));
        $decodedBreakdown = $breakdownRaw !== '' ? $this->maybe($breakdownRaw) : null;
        if (is_array($decodedBreakdown)) {
            $existingTotal = (float) ($decodedBreakdown['total_amount'] ?? 0);
            if ($existingTotal > 0) {
                return max(0, (int) round($existingTotal * 100));
            }
        }

        $budget = (float) ($meta['gigtune_booking_budget'] ?? 0);
        if ($budget <= 0) {
            $budget = (float) ($meta['gigtune_booking_quote_amount'] ?? 0);
        }
        if ($budget <= 0) {
            return 0;
        }

        $travelFee = max(0.0, (float) ($meta['gigtune_booking_travel_amount'] ?? 0));
        $accommodationFee = $this->bookingAccommodationFeeAmount($meta);

        $serviceFeeRate = 0.15;
        $serviceFee = round(($budget + $travelFee + $accommodationFee) * $serviceFeeRate, 2);
        $total = round($budget + $travelFee + $accommodationFee + $serviceFee, 2);
        if ($total <= 0) {
            return max(0, (int) round($budget * 100));
        }

        return max(0, (int) round($total * 100));
    }

    /** @return array{checkout_id?: string, redirect_url?: string, error?: string} */
    private function createYocoCheckout(int $bookingId, int $amountCents): array
    {
        $bookingId = abs($bookingId);
        $amountCents = max(0, $amountCents);
        if ($bookingId <= 0 || $amountCents <= 0) {
            return ['error' => 'Invalid payment request.'];
        }
        if ($amountCents < 200) {
            return ['error' => 'Minimum card payment is R2.00. Current total is R' . number_format($amountCents / 100, 2) . '.'];
        }

        $secretKey = $this->yocoSecretKey();
        if ($secretKey === '') {
            return ['error' => 'Card checkout is currently unavailable.'];
        }

        $payload = [
            'amount' => $amountCents,
            'currency' => 'ZAR',
            'successUrl' => $this->yocoSuccessUrl($bookingId),
            'cancelUrl' => $this->yocoCancelUrl($bookingId),
            'metadata' => [
                'booking_id' => (string) $bookingId,
                'environment' => 'gigtune_' . $this->yocoMode(),
            ],
        ];

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $secretKey,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://payments.yoco.com/api/checkouts', $payload);
        } catch (\Throwable) {
            return ['error' => 'Card checkout is currently unavailable.'];
        }

        $body = $response->json();
        if (!$response->successful()) {
            $code = $response->status();
            $prefix = $code > 0 ? ('HTTP ' . $code . ': ') : '';
            $apiMessage = '';
            if (is_array($body)) {
                $apiMessage = trim((string) ($body['message'] ?? $body['error'] ?? $body['description'] ?? ''));
                if ($apiMessage === '' && isset($body['errors']) && is_array($body['errors']) && isset($body['errors'][0]) && is_array($body['errors'][0])) {
                    $apiMessage = trim((string) ($body['errors'][0]['message'] ?? $body['errors'][0]['description'] ?? ''));
                }
            }
            if ($apiMessage === '') {
                $rawBody = trim((string) $response->body());
                if ($rawBody !== '') {
                    $apiMessage = substr($rawBody, 0, 240);
                }
            }

            logger()->warning('YOCO checkout create failed', [
                'status' => $code,
                'response' => is_array($body) ? $body : substr((string) $response->body(), 0, 400),
                'booking_id' => $bookingId,
                'amount_cents' => $amountCents,
                'mode' => $this->yocoMode(),
            ]);

            return ['error' => $prefix . ($apiMessage !== '' ? $apiMessage : 'Card checkout is currently unavailable.')];
        }

        if (!is_array($body)) {
            return ['error' => 'Invalid checkout response.'];
        }

        $checkoutId = trim((string) ($body['id'] ?? ''));
        $redirectUrl = trim((string) ($body['redirectUrl'] ?? $body['redirect_url'] ?? ''));
        if ($redirectUrl !== '' && !filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
            $redirectUrl = '';
        }

        if ($checkoutId === '' || $redirectUrl === '') {
            return ['error' => 'Invalid checkout response.'];
        }

        return [
            'checkout_id' => $checkoutId,
            'redirect_url' => $redirectUrl,
        ];
    }

    private function yocoMode(): string
    {
        $mode = strtolower(trim((string) config('gigtune.payments.yoco.mode', 'test')));
        return $mode === 'live' ? 'live' : 'test';
    }

    private function yocoSecretKey(): string
    {
        if ($this->yocoMode() === 'live') {
            return trim((string) config('gigtune.payments.yoco.live_secret_key', ''));
        }
        return trim((string) config('gigtune.payments.yoco.test_secret_key', ''));
    }

    private function yocoSuccessUrl(int $bookingId): string
    {
        return $this->yocoBaseUrl() . '/yoco-success/?booking_id=' . max(0, $bookingId);
    }

    private function yocoCancelUrl(int $bookingId): string
    {
        return $this->yocoBaseUrl() . '/yoco-cancel/?booking_id=' . max(0, $bookingId);
    }

    private function yocoBaseUrl(): string
    {
        $appUrl = trim((string) config('app.url', ''));
        if ($appUrl !== '') {
            return rtrim($appUrl, '/');
        }

        try {
            $request = request();
            if ($request !== null) {
                return rtrim((string) $request->getSchemeAndHttpHost(), '/');
            }
        } catch (\Throwable) {
            // Fallback below.
        }

        return rtrim((string) url('/'), '/');
    }

    private function redirectInline(string $path): string
    {
        $target = trim($path);
        if ($target === '') {
            $target = '/';
        }
        if (!str_starts_with($target, '/')) {
            $target = '/' . ltrim($target, '/');
        }
        $encoded = json_encode($target, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            $encoded = '"/"';
        }

        return '<script>(function(){var u=' . $encoded . ';if(u){window.location.replace(u);}})();</script>'
            . '<div class="rounded-xl border border-sky-500/30 bg-sky-500/10 px-4 py-3 text-sm text-sky-100">Redirecting... If not redirected, <a class="underline" href="' . e($target) . '">continue</a>.</div>';
    }

    private function createWpNonce(string $action): string
    {
        $seed = csrf_token() . '|' . $action . '|' . (string) config('app.key', '');
        return hash('sha256', $seed);
    }

    private function verifyWpNonce(string $nonce, string $action): bool
    {
        return hash_equals($this->createWpNonce($action), trim($nonce));
    }

    private function psaWorkflowUrl(int $psaId, int $viewerUserId): string
    {
        $psaId = abs($psaId);
        if ($psaId <= 0) {
            return '/open-posts/';
        }

        $ownerUserId = (int) $this->getLatestPostMeta($psaId, 'gigtune_psa_client_user_id');
        if ($viewerUserId > 0 && $ownerUserId > 0 && $viewerUserId === $ownerUserId) {
            return '/posts-page/?view=applications&psa_id=' . $psaId;
        }

        return '/open-posts/?psa_id=' . $psaId;
    }

    private function req(array $ctx): ?\Illuminate\Http\Request
    {
        $request = $ctx['request'] ?? null;
        return $request instanceof \Illuminate\Http\Request ? $request : null;
    }

    private function findUserIdByMetaTokenHash(string $metaKey, string $tokenHash): int
    {
        $metaKey = trim($metaKey);
        $tokenHash = trim($tokenHash);
        if ($metaKey === '' || $tokenHash === '') {
            return 0;
        }

        $userId = (int) $this->db()->table($this->um())
            ->where('meta_key', $metaKey)
            ->where('meta_value', $tokenHash)
            ->orderByDesc('umeta_id')
            ->value('user_id');

        return $userId > 0 ? $userId : 0;
    }

    private function latestUserMeta(int $userId, string $key): string
    {
        if ($userId <= 0 || $key === '') {
            return '';
        }
        $value = $this->db()->table($this->um())
            ->where('user_id', $userId)
            ->where('meta_key', $key)
            ->orderByDesc('umeta_id')
            ->value('meta_value');
        return is_string($value) ? $value : '';
    }

    private function latestUserMetaInt(int $userId, string $key): int
    {
        return (int) $this->latestUserMeta($userId, $key);
    }

    private function upsertPostMeta(int $postId, string $key, string $value): void
    {
        $row = $this->db()->table($this->pm())
            ->select('meta_id')
            ->where('post_id', $postId)
            ->where('meta_key', $key)
            ->orderByDesc('meta_id')
            ->first();

        if ($row !== null && isset($row->meta_id)) {
            $this->db()->table($this->pm())
                ->where('meta_id', (int) $row->meta_id)
                ->update(['meta_value' => $value]);
            return;
        }

        $this->db()->table($this->pm())->insert([
            'post_id' => $postId,
            'meta_key' => $key,
            'meta_value' => $value,
        ]);
    }

    private function upsertUserMeta(int $userId, string $key, string $value): void
    {
        $row = $this->db()->table($this->um())
            ->select('umeta_id')
            ->where('user_id', $userId)
            ->where('meta_key', $key)
            ->orderByDesc('umeta_id')
            ->first();

        if ($row !== null && isset($row->umeta_id)) {
            $this->db()->table($this->um())
                ->where('umeta_id', (int) $row->umeta_id)
                ->update(['meta_value' => $value]);
            return;
        }

        $this->db()->table($this->um())->insert([
            'user_id' => $userId,
            'meta_key' => $key,
            'meta_value' => $value,
        ]);
    }

    private function db(): ConnectionInterface { return DB::connection((string) config('gigtune.wordpress.database_connection', 'wordpress')); }
    private function pfx(): string { return (string) config('gigtune.wordpress.table_prefix', 'wp_'); }
    private function usersTable(): string { return $this->pfx() . 'users'; }
    private function posts(): string { return $this->pfx() . 'posts'; }
    private function pm(): string { return $this->pfx() . 'postmeta'; }
    private function um(): string { return $this->pfx() . 'usermeta'; }
    private function terms(): string { return $this->pfx() . 'terms'; }
    private function tt(): string { return $this->pfx() . 'term_taxonomy'; }
    private function tr(): string { return $this->pfx() . 'term_relationships'; }
}
