<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

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
        foreach ($out as $k => $v) {
            $out[$k] = array_values($v);
        }
        return $out;
    }

    /** @param array<string,bool> $preferences */
    public function saveNotificationEmailPreferences(int $userId, array $preferences): void
    {
        $this->storeNotificationEmailPreferences($userId, $preferences);
    }

    private function roleNav(array $a, ?array $u): string { return is_array($u) ? '<div class="mb-4 flex flex-wrap gap-2"><a class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-200" href="/my-account-page/">Account</a><a class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-200" href="/messages/">Messages</a><a class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-200" href="/notifications/">Notifications</a></div>' : ''; }
    private function artistDashboard(array $a, ?array $u): string { return $this->dashboardShell($u, true); }
    private function clientDashboard(array $a, ?array $u): string { return $this->dashboardShell($u, false); }
    private function artistFeed(array $a): string { return $this->psaCards(max(1, min(30, (int) ($a['limit'] ?? 12)))); }
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
        $meta = $this->postMetaMap([$profileId], ['gigtune_client_company', 'gigtune_client_base_area', 'gigtune_client_photo_id'])[$profileId] ?? [];
        $photo = $this->attachmentUrl((int) ($meta['gigtune_client_photo_id'] ?? 0));
        $html = '<section class="rounded-2xl border border-white/10 bg-white/5 p-6">';
        if ($photo !== '') {
            $html .= '<img src="' . e($photo) . '" alt="' . e((string) $post->post_title) . '" class="h-24 w-24 rounded-xl object-cover">';
        }
        $html .= '<h2 class="mt-3 text-2xl font-bold text-white">' . e((string) $post->post_title) . '</h2>';
        $html .= '<p class="mt-1 text-sm text-slate-300">' . e((string) ($meta['gigtune_client_company'] ?? '')) . '</p>';
        $html .= '<p class="text-xs text-slate-400">' . e((string) ($meta['gigtune_client_base_area'] ?? '')) . '</p>';
        $html .= '<div class="mt-4 text-sm text-slate-200">' . nl2br(e(trim((string) $post->post_content))) . '</div>';
        return $html . '</section>';
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
        ])[(int) $post->ID] ?? [];

        $photoId = (int) ($meta['gigtune_artist_photo_id'] ?? 0);
        $photo = $this->attachmentUrl($photoId);
        $baseArea = trim((string) ($meta['gigtune_artist_base_area'] ?? ''));
        $priceMin = (int) ($meta['gigtune_artist_price_min'] ?? 0);
        $priceMax = (int) ($meta['gigtune_artist_price_max'] ?? 0);
        $availableNow = $this->bool($meta['gigtune_artist_available_now'] ?? '');
        $ratingAvg = (float) ($meta['gigtune_performance_rating_avg'] ?? 0);
        $ratingCount = (int) ($meta['gigtune_performance_rating_count'] ?? 0);

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
        if ($request !== null && strtoupper((string) $request->method()) === 'POST' && (string) $request->input('gigtune_profile_submit', '') === '1') {
            $profileName = trim((string) $request->input('profile_name', ''));
            $profileBio = trim((string) $request->input('profile_bio', ''));
            $baseArea = trim((string) $request->input('gigtune_artist_base_area', ''));
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

            if ($profileName === '' || $profileBio === '' || $baseArea === '' || $priceMin <= 0 || $priceMax <= 0 || $startTime === '' || $endTime === '') {
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
        ])[$profileId] ?? [];

        $days = $this->days((string) ($meta['gigtune_artist_availability_days'] ?? ''));
        $daySet = array_fill_keys($days, true);
        $visibilityMode = (string) ($meta['gigtune_artist_visibility_mode'] ?? 'approx');
        $accomPreference = (string) ($meta['gigtune_artist_accom_preference'] ?? 'none');
        $branchCode = (string) ($meta['gigtune_artist_branch_code'] ?? '');
        if ($branchCode === '') {
            $branchCode = (string) ($meta['gigtune_artist_bank_code'] ?? '');
        }

        $html = '<form method="post" class="space-y-6 rounded-2xl border border-white/10 bg-white/5 p-6">';
        if ($statusMessage !== '') {
            $html .= '<div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-200">' . e($statusMessage) . '</div>';
        }
        if ($errorMessage !== '') {
            $html .= '<div class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-sm text-rose-200">' . e($errorMessage) . '</div>';
        }

        $html .= '<input type="hidden" name="gigtune_profile_submit" value="1">';
        $html .= '<div class="grid gap-4 md:grid-cols-2">';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Profile name *</label><input required name="profile_name" value="' . e((string) ($post->post_title ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div><label class="mb-1 block text-sm text-slate-300">Base area *</label><input required name="gigtune_artist_base_area" value="' . e((string) ($meta['gigtune_artist_base_area'] ?? '')) . '" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div>';
        $html .= '<div class="md:col-span-2"><label class="mb-1 block text-sm text-slate-300">Bio *</label><textarea required name="profile_bio" rows="5" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white">' . e((string) ($post->post_content ?? '')) . '</textarea></div>';
        $html .= '</div>';

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

        $html .= '<div class="flex flex-wrap gap-2"><button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Save profile</button><a class="rounded-md border border-slate-600 px-4 py-2 text-sm font-semibold text-slate-200" href="/artist-profile/?artist_id=' . (int) $profileId . '">Preview profile</a></div>';
        $html .= '</form>';
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
                $roles = is_array($u['roles'] ?? null) ? array_map(static fn ($r): string => strtolower((string) $r), $u['roles']) : [];
                if (!in_array('gigtune_client', $roles, true)) {
                    $error = '1';
                    $errorMessage = 'Only client accounts can create booking requests.';
                    $errorList = [$errorMessage];
                } elseif ($this->latestUserMeta((int) ($u['id'] ?? 0), 'gigtune_email_verified') !== '1') {
                    $error = '1';
                    $errorMessage = 'Verify your email address before booking.';
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
            $artists = $this->getArtists(['per_page' => 24, 'paged' => 1, 'include_incomplete' => true])['items'];
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
        $html .= '<input type="hidden" name="gigtune_book_artist_submit" value="1"><input type="hidden" name="gigtune_action" value="book_artist"><input type="hidden" name="artist_id" value="' . e((string) $artistId) . '">';
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
    private function messages(array $a, ?array $u): string
    {
        if (!is_array($u)) {
            return '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">Sign in required.</div>';
        }
        $uid = (int) ($u['id'] ?? 0);
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

    private function accountPortal(array $a, ?array $u): string
    {
        if (!is_array($u)) {
            return '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 text-slate-200">Please sign in to manage your account.</div>';
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
            $address = trim((string) $request->input('gigtune_kyc_address', ''));
            $idDoc = $request->file('gigtune_kyc_id_document');
            $selfieDoc = $request->file('gigtune_kyc_selfie');
            $proofDoc = $request->file('gigtune_kyc_proof_of_address');

            if ($legalName === '' || $idNumber === '' || $country === '' || $mobile === '' || $address === '' || $idDoc === null || !$idDoc->isValid()) {
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
                    $docs['id_document'] = $idPath;
                }
                if ($selfieDoc !== null && $selfieDoc->isValid()) {
                    $selfiePath = $selfieDoc->store('gigtune/kyc', 'public');
                    if (is_string($selfiePath) && $selfiePath !== '') {
                        $docs['selfie'] = $selfiePath;
                    }
                }
                if ($proofDoc !== null && $proofDoc->isValid()) {
                    $proofPath = $proofDoc->store('gigtune/kyc', 'public');
                    if (is_string($proofPath) && $proofPath !== '') {
                        $docs['proof_of_address'] = $proofPath;
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
        $html .= '<form method="post" enctype="multipart/form-data" class="mt-5 grid gap-4 md:grid-cols-2"><input type="hidden" name="gigtune_kyc_submit" value="1"><div class="md:col-span-2"><label class="mb-1 block text-sm text-slate-300">Legal full name</label><input name="gigtune_kyc_legal_name" required class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div><div><label class="mb-1 block text-sm text-slate-300">ID/Passport number</label><input name="gigtune_kyc_id_number" required class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div><div><label class="mb-1 block text-sm text-slate-300">Date of birth</label><input type="date" name="gigtune_kyc_dob" class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div><div><label class="mb-1 block text-sm text-slate-300">Country</label><input name="gigtune_kyc_country" value="South Africa" required class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div><div><label class="mb-1 block text-sm text-slate-300">Mobile</label><input name="gigtune_kyc_mobile" required class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></div><div class="md:col-span-2"><label class="mb-1 block text-sm text-slate-300">Address</label><textarea name="gigtune_kyc_address" rows="3" required class="w-full rounded-xl border border-white/10 bg-slate-950/50 px-4 py-3 text-white"></textarea></div><div><label class="mb-1 block text-sm text-slate-300">ID document *</label><input type="file" name="gigtune_kyc_id_document" accept="application/pdf,image/jpeg,image/png" required class="block w-full text-sm text-slate-200"></div><div><label class="mb-1 block text-sm text-slate-300">Selfie (optional)</label><input type="file" name="gigtune_kyc_selfie" accept="image/jpeg,image/png" class="block w-full text-sm text-slate-200"></div><div class="md:col-span-2"><label class="mb-1 block text-sm text-slate-300">Proof of address (optional)</label><input type="file" name="gigtune_kyc_proof_of_address" accept="application/pdf,image/jpeg,image/png" class="block w-full text-sm text-slate-200"></div><div class="md:col-span-2"><button type="submit" class="inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Submit Identity Verification</button></div></form>';
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
        if ($request !== null && strtoupper((string) $request->method()) === 'POST' && (string) $request->input('gigtune_policy_consent_submit', '') === '1') {
            $accepted = $this->users->mapAcceptedPolicyInput($request->all());
            $this->users->storePolicyAcceptance($uid, $accepted);
        }
        $policy = $this->users->getPolicyStatus($uid);
        if ((bool) ($policy['has_latest'] ?? false)) {
            return '<div class="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-6 text-emerald-200"><p class="text-lg font-semibold">Policies accepted</p><p class="mt-2 text-sm text-emerald-100/90">Your policy acceptance is up to date.</p><a href="' . e((string) ($u['dashboard_url'] ?? '/')) . '" class="mt-4 inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Continue</a></div>';
        }
        $required = is_array($policy['required'] ?? null) ? $policy['required'] : [];
        $documents = is_array($policy['documents'] ?? null) ? $policy['documents'] : [];
        $html = '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8"><h2 class="text-2xl font-bold text-white">Policy acceptance required</h2><p class="mt-2 text-sm text-slate-300">Please accept the latest policies to continue using your dashboard.</p><form method="post" class="mt-5 space-y-4"><input type="hidden" name="gigtune_policy_consent_submit" value="1">';
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

    private function signOut(array $a): string { return '<a href="/wp-login.php?action=logout&redirect_to=/" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white">Sign Out</a>'; }
    private function verifyEmail(array $a, ?array $u = null, array $ctx = []): string
    {
        $request = $this->req($ctx);
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
            return '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8"><h2 class="text-2xl font-bold text-white">Verify your email</h2><p class="mt-2 text-sm text-slate-300">Email verification is required before booking creation and payment initiation.</p><a href="/sign-in/" class="mt-4 inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Sign in</a></div>';
        }

        $uid = (int) ($u['id'] ?? 0);
        $isVerified = $this->latestUserMeta($uid, 'gigtune_email_verified') === '1';
        $html = '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8"><h2 class="text-2xl font-bold text-white">Verify your email</h2><p class="mt-2 text-sm text-slate-300">Email verification is required before booking creation and payment initiation.</p>';
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
        $done = false;
        if ($request !== null && strtoupper((string) $request->method()) === 'POST' && (string) $request->input('gigtune_reset_password_submit', '') === '1') {
            $done = true;
        }
        $html = '<div class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8"><h2 class="text-2xl font-bold text-white">Reset password</h2><p class="mt-2 text-sm text-slate-300">Set a new password using your reset token.</p>';
        if ($done) {
            $html .= '<div class="mt-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-200">Password reset complete. You can sign in now.</div>';
        }
        $html .= '<form method="post" class="mt-4 space-y-4"><input type="hidden" name="gigtune_reset_password_submit" value="1"><div><label class="mb-2 block text-sm font-semibold text-slate-200" for="gigtune_reset_password">New password</label><input id="gigtune_reset_password" type="password" name="gigtune_reset_password" required class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div><div><label class="mb-2 block text-sm font-semibold text-slate-200" for="gigtune_reset_password_confirm">Confirm password</label><input id="gigtune_reset_password_confirm" type="password" name="gigtune_reset_password_confirm" required class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white"></div><button type="submit" class="inline-flex w-full items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Reset password</button></form></div>';
        return $html;
    }
    private function yocoSuccess(array $a): string { return '<div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">Payment completed successfully.</div>'; }
    private function yocoCancel(array $a): string { return '<div class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">Payment canceled.</div>'; }
    private function wooCart(array $a): string { return '<div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">Cart handled through booking flow.</div>'; }
    private function wooCheckout(array $a): string { return '<div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">Checkout handled via Yoco/Paystack.</div>'; }

    private function dashboardShell(?array $u, bool $artist): string
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
            : '<a href="/browse-artists/" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white bg-white/10 hover:bg-white/15">Browse Artists</a>';

        $uid = (int) ($u['id'] ?? 0);
        $policy = $this->users->getPolicyStatus($uid);
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
        $emailVerified = $this->latestUserMeta($uid, 'gigtune_email_verified') === '1';

        $html = '<div class="grid gap-6 lg:grid-cols-3">';
        $html .= '<div class="lg:col-span-2 space-y-6">';
        $html .= '<div class="rounded-2xl border border-white/10 bg-white/5 p-6">';
        $html .= '<h3 class="text-lg font-semibold text-white">' . e($title) . '</h3>';
        $html .= '<p class="mt-2 text-sm text-slate-300">Manage bookings, messages, notifications, and account compliance.</p>';
        $html .= '<div class="mt-4 flex flex-wrap gap-3">' . $primaryCta . $secondaryCta . '<a href="/notifications/" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white bg-white/10 hover:bg-white/15">Notifications</a></div>';
        $html .= '</div>';
        $html .= '<div class="rounded-2xl border border-white/10 bg-white/5 p-6">';
        $html .= '<h3 class="text-lg font-semibold text-white">Snapshot</h3>';
        $html .= '<div class="mt-4">' . $this->snapshot($u, $artist) . '</div>';
        $html .= '</div>';
        $html .= '<div class="rounded-2xl border border-white/10 bg-white/5 p-6">';
        $html .= '<h3 class="text-lg font-semibold text-white">Recent Bookings</h3>';
        $html .= '<div class="mt-4">' . $this->bookingsTable($u, $artist) . '</div>';
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

    private function bookingsTable(?array $u, bool $artist): string
    {
        if (!is_array($u)) {
            return '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">Sign in required.</div>';
        }

        $uid = (int) ($u['id'] ?? 0);
        if ($uid <= 0) {
            return '<div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">Sign in required.</div>';
        }

        $query = $this->db()->table($this->posts() . ' as p')
            ->where('p.post_type', 'gigtune_booking')
            ->orderByDesc('p.ID');

        if ($artist) {
            $profileId = $this->latestUserMetaInt($uid, 'gigtune_artist_profile_id');
            if ($profileId <= 0) {
                return '<div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">No linked artist profile found.</div>';
            }
            $query->whereExists(function ($q) use ($profileId): void {
                $q->selectRaw('1')
                    ->from($this->pm() . ' as pm')
                    ->whereColumn('pm.post_id', 'p.ID')
                    ->where('pm.meta_key', 'gigtune_booking_artist_profile_id')
                    ->where('pm.meta_value', (string) $profileId);
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

        $rows = $query->limit(20)->get(['p.ID', 'p.post_title', 'p.post_date']);
        if ($rows->isEmpty()) {
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
        ]);

        $out = '<div class="space-y-3">';
        foreach ($rows as $row) {
            $id = (int) $row->ID;
            $meta = $metaMap[$id] ?? [];
            $status = $this->toSentenceCase((string) ($meta['gigtune_booking_status'] ?? ''));
            $payment = $this->toSentenceCase((string) ($meta['gigtune_payment_status'] ?? ''));
            $payout = $this->toSentenceCase((string) ($meta['gigtune_payout_status'] ?? ''));
            $eventDate = trim((string) ($meta['gigtune_booking_event_date'] ?? ''));
            $partyId = $artist
                ? (int) ($meta['gigtune_booking_client_user_id'] ?? 0)
                : (int) ($meta['gigtune_booking_artist_profile_id'] ?? 0);
            $partyLink = $artist
                ? '/client-profile/?client_user_id=' . $partyId
                : '/artist-profile/?artist_id=' . $partyId;

            $out .= '<article class="rounded-xl border border-white/10 bg-black/20 p-4">';
            $out .= '<div class="flex flex-wrap items-center justify-between gap-3">';
            $out .= '<div class="text-sm font-semibold text-white">Booking #' . $id . '</div>';
            $out .= '<a href="/messages/?booking_id=' . $id . '" class="text-sm text-blue-300 hover:text-blue-200">View</a>';
            $out .= '</div>';
            $out .= '<div class="mt-2 text-xs text-slate-300">Status: ' . e($status !== '' ? $status : '-') . ' | Payment: ' . e($payment !== '' ? $payment : '-') . ' | Payout: ' . e($payout !== '' ? $payout : '-') . '</div>';
            $out .= '<div class="mt-1 text-xs text-slate-300">Event: ' . e($eventDate !== '' ? $eventDate : '-') . '</div>';
            if ($partyId > 0) {
                $out .= '<div class="mt-3"><a href="' . e($partyLink) . '" class="inline-flex items-center rounded-lg bg-white/10 px-3 py-2 text-xs text-white hover:bg-white/15">' . ($artist ? 'View Client Profile' : 'View Artist Profile') . '</a></div>';
            }
            $out .= '</article>';
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
                $out .= '<a href="' . e($targetUrl) . '" class="text-xs text-blue-300 hover:text-blue-200">View</a>';
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

    /** @return array<int,array{title:string,url:string,type:string}> */
    private function demoVideos(string $raw): array
    {
        $v = $this->maybe($raw);
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $i) {
            if (is_int($i) || ctype_digit((string) $i)) {
                $u = $this->attachmentUrl((int) $i);
                if ($u !== '') {
                    $out[] = ['title' => 'Demo Video', 'url' => $u, 'type' => 'upload'];
                }
            } elseif (is_string($i) && trim($i) !== '') {
                $out[] = ['title' => 'Demo Video', 'url' => trim($i), 'type' => 'link'];
            }
        }
        return $out;
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
            return '/posts-page/?psa_id=' . $psaId;
        }

        return '/open-posts/?psa_id=' . $psaId;
    }

    private function req(array $ctx): ?\Illuminate\Http\Request
    {
        $request = $ctx['request'] ?? null;
        return $request instanceof \Illuminate\Http\Request ? $request : null;
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
