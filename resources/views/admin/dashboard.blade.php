@extends('layouts.admin', ['title' => 'Admin Dashboard', 'currentUser' => $currentUser ?? null])

@section('content')
    @php
        $adminNotifications = is_array($adminNotifications ?? null) ? $adminNotifications : [];
        $sentenceCase = function ($value): string {
            $value = trim((string) $value);
            if ($value === '') {
                return '-';
            }
            $normalized = preg_replace('/[_-]+/', ' ', $value) ?? $value;
            $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
            $normalized = strtolower(trim($normalized));
            if ($normalized === '') {
                return '-';
            }
            $normalized = match ($normalized) {
                'paid escrowed' => 'paid - temporary holding',
                'escrow funded' => 'temporary holding funded',
                default => $normalized,
            };
            $normalized = preg_replace('/\bescrowed\b/i', 'temporary holding', $normalized) ?? $normalized;
            $normalized = preg_replace('/\bescrow\b/i', 'temporary holding', $normalized) ?? $normalized;
            $normalized = ucfirst($normalized);
            $normalized = preg_replace('/\bkyc\b/i', 'KYC', $normalized) ?? $normalized;
            $normalized = preg_replace('/\bpsa\b/i', 'PSA', $normalized) ?? $normalized;

            return $normalized;
        };
    @endphp
    <div class="gigtune-admin-dashboard grid gap-6 lg:grid-cols-3">
        <style>
            .gigtune-admin-dashboard select.gigtune-admin-select,
            .gigtune-admin-dashboard select.gigtune-admin-select option {
                background-color: #020617;
                color: #e2e8f0;
            }
        </style>
        <div class="lg:col-span-2 space-y-6">
            <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
                <div class="flex flex-wrap items-center gap-3">
                    <h3 class="text-xl font-semibold text-white">Admin Dashboard</h3>
                    <span class="inline-flex items-center rounded-full border border-amber-400/40 bg-amber-500/15 px-4 py-1.5 text-xs font-semibold text-amber-200">
                        Administrator
                    </span>
                </div>
                <p class="mt-2 text-sm text-slate-300">
                    Operations center for bookings, payments, payouts, disputes, Identity Verification (Know Your Customer Compliance), and reporting.
                </p>
                <p class="mt-3 text-xs text-amber-200/90">Admin-only area. Actions here affect live bookings and payouts.</p>

                <div class="mt-5 grid gap-3 sm:grid-cols-3">
                    <a href="/admin-dashboard/payments" class="rounded-xl border border-white/10 bg-black/20 p-4 hover:bg-black/30 transition">
                        <div class="text-sm font-semibold text-white">Payments Queue</div>
                        <div class="mt-1 text-xs text-slate-300">Awaiting review: {{ $metrics['awaiting_payment_total'] }}</div>
                    </a>
                    <a href="/admin-dashboard/payouts" class="rounded-xl border border-white/10 bg-black/20 p-4 hover:bg-black/30 transition">
                        <div class="text-sm font-semibold text-white">Payouts Queue</div>
                        <div class="mt-1 text-xs text-slate-300">Pending manual: {{ $metrics['pending_payouts'] }}</div>
                    </a>
                    <a href="/admin-dashboard/kyc" class="rounded-xl border border-white/10 bg-black/20 p-4 hover:bg-black/30 transition">
                        <div class="text-sm font-semibold text-white">Identity Verification Queue</div>
                        <div class="mt-1 text-xs text-slate-300">Pending verification: {{ $metrics['kyc_total'] }}</div>
                    </a>
                </div>

                @php
                    $tabs = ['overview', 'users', 'compliance', 'payments', 'payouts', 'bookings', 'disputes', 'refunds', 'kyc', 'reports'];
                @endphp
                <div class="mt-5 flex flex-wrap gap-2">
                    @foreach ($tabs as $tab)
                        <a href="/admin-dashboard/{{ $tab }}"
                            class="inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold {{ $activeTab === $tab ? 'text-white bg-gradient-to-r from-blue-600 to-purple-600' : 'text-white bg-white/10 hover:bg-white/15' }}">
                            {{ ucfirst($tab) }}
                        </a>
                    @endforeach
                </div>
            </div>

            @php
                $adminSuccess = (string) request()->query('admin_success', '');
                $adminAction = (string) request()->query('admin_action', '');
                $adminError = (string) request()->query('admin_error', '');
                $adminBlockers = (string) request()->query('admin_blockers', '');
            @endphp
            @if ($adminSuccess === '1')
                <div class="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-4">
                    <p class="text-emerald-200 font-semibold">Action completed: {{ $sentenceCase($adminAction) }}</p>
                </div>
            @elseif ($adminError !== '')
                <div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4">
                    <p class="text-rose-200 font-semibold">Action failed: {{ $sentenceCase($adminError) }}</p>
                    @if ($adminBlockers !== '')
                        <p class="text-rose-200/90 text-xs mt-1">Blocked by: {{ $adminBlockers }}</p>
                    @endif
                </div>
            @endif

            @if ($activeTab === 'overview')
                <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
                    <h4 class="text-base font-semibold text-white">Overview</h4>
                    @php
                        $overviewActiveBookings = is_array($tabData['active_booking_items'] ?? null) ? $tabData['active_booking_items'] : [];
                        $overviewArchivedBookings = is_array($tabData['archived_booking_items'] ?? null) ? $tabData['archived_booking_items'] : [];
                        $overviewArchivedCount = (int) ($tabData['archived_booking_count'] ?? count($overviewArchivedBookings));
                    @endphp
                    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-xl border border-white/10 bg-black/20 p-4">
                            <div class="text-xs text-slate-300">Awaiting payment confirmation</div>
                            <div class="mt-1 text-xl font-bold text-white">{{ $metrics['awaiting_payment_total'] }}</div>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-black/20 p-4">
                            <div class="text-xs text-slate-300">Pending manual payouts</div>
                            <div class="mt-1 text-xl font-bold text-white">{{ $metrics['pending_payouts'] }}</div>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-black/20 p-4">
                            <div class="text-xs text-slate-300">Open disputes</div>
                            <div class="mt-1 text-xl font-bold text-white">{{ $metrics['open_disputes'] }}</div>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-black/20 p-4">
                            <div class="text-xs text-slate-300">Recent bookings scanned</div>
                            <div class="mt-1 text-xl font-bold text-white">{{ $metrics['recent_bookings_scanned'] ?? 0 }}</div>
                        </div>
                    </div>

                    <div class="mt-6 grid gap-3 md:grid-cols-2">
                        <div class="rounded-xl border border-white/10 bg-black/20 p-4 md:col-span-2">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-sm font-semibold text-white">Admin notifications</div>
                                <a href="/notifications/" class="text-xs text-blue-300 hover:text-blue-200">Open notifications</a>
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-3 text-xs">
                                <form method="post" action="/admin-dashboard/notifications/mark-all-read" class="inline-flex">
                                    @csrf
                                    <button type="submit" class="text-slate-200/90 hover:text-white underline">Mark all read</button>
                                </form>
                                <a href="/notification-settings/" class="text-slate-200/90 hover:text-white underline">Email settings</a>
                                <a href="/notifications-archive/" class="text-slate-200/90 hover:text-white underline">View archive</a>
                            </div>
                            @if (!empty($adminNotifications))
                                <div class="mt-3 space-y-2">
                                    @foreach ($adminNotifications as $notification)
                                        <div class="rounded-lg border border-white/10 bg-slate-950/40 px-3 py-2">
                                            <div class="flex flex-wrap items-start justify-between gap-3">
                                                <div class="text-xs {{ !empty($notification['is_read']) ? 'text-slate-300' : 'text-white font-semibold' }}">
                                                    {{ $notification['message'] ?? 'Notification' }}
                                                    @if (!empty($notification['created_at']))
                                                        <div class="mt-1 text-[11px] text-slate-400">
                                                            {{ date('M j, Y H:i', (int) $notification['created_at']) }}
                                                        </div>
                                                    @endif
                                                </div>
                                                <a href="{{ $notification['open_url'] ?? '/notifications/' }}" class="inline-flex items-center justify-center rounded-md bg-white/10 px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-white/15">
                                                    Open
                                                </a>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <p class="mt-2 text-xs text-slate-300">No notifications yet.</p>
                            @endif
                        </div>
                        <div class="rounded-xl border border-white/10 bg-black/20 p-4 md:col-span-2">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-sm font-semibold text-white">Recent bookings</div>
                                <a href="/admin-dashboard/bookings" class="text-xs text-blue-300 hover:text-blue-200">Open Bookings</a>
                            </div>
                            @if (empty($overviewActiveBookings))
                                <p class="mt-3 text-sm text-slate-300">No active bookings found.</p>
                            @else
                                <div class="mt-3 space-y-2">
                                    @foreach ($overviewActiveBookings as $row)
                                        @php
                                            $bookingStatus = $sentenceCase($row['meta']['gigtune_booking_status'] ?? '-');
                                        @endphp
                                        <article class="rounded-lg border border-white/10 bg-slate-950/40 p-3">
                                            <div class="flex flex-wrap items-start justify-between gap-2">
                                                <div class="text-sm text-slate-200">
                                                    <div class="font-semibold text-white">Booking #{{ $row['id'] }} - {{ $bookingStatus }}</div>
                                                    <div class="mt-1 text-xs text-slate-300">Payment: {{ $sentenceCase($row['meta']['gigtune_payment_status'] ?? '-') }} | Payout: {{ $sentenceCase($row['meta']['gigtune_payout_status'] ?? '-') }}</div>
                                                </div>
                                                <div class="flex flex-wrap gap-2">
                                                    <a class="inline-flex items-center justify-center rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold text-white hover:bg-white/15" href="/messages/?booking_id={{ $row['id'] }}">View Booking</a>
                                                    @if (!empty($row['can_archive']))
                                                        <form method="post" action="/admin-dashboard/bookings/archive">
                                                            @csrf
                                                            <input type="hidden" name="booking_id" value="{{ $row['id'] }}">
                                                            <input type="hidden" name="return_tab" value="overview">
                                                            <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold text-white hover:bg-white/15">Archive</button>
                                                        </form>
                                                    @endif
                                                </div>
                                            </div>
                                        </article>
                                    @endforeach
                                </div>
                            @endif
                            <details class="mt-3 rounded-lg border border-white/10 bg-slate-950/40 p-3" @if ($overviewArchivedCount > 0) open @endif>
                                <summary class="cursor-pointer select-none text-xs font-semibold text-white">Archived bookings ({{ $overviewArchivedCount }})</summary>
                                @if (empty($overviewArchivedBookings))
                                    <p class="mt-2 text-xs text-slate-300">No archived bookings.</p>
                                @else
                                    <div class="mt-2 space-y-2">
                                        @foreach ($overviewArchivedBookings as $row)
                                            @php
                                                $bookingStatus = $sentenceCase($row['meta']['gigtune_booking_status'] ?? '-');
                                            @endphp
                                            <article class="rounded-lg border border-white/10 bg-black/20 p-3">
                                                <div class="flex flex-wrap items-start justify-between gap-2">
                                                    <div class="text-sm text-slate-200">
                                                        <div class="font-semibold text-white">Booking #{{ $row['id'] }} - {{ $bookingStatus }} <span class="text-slate-400">Archived</span></div>
                                                        <div class="mt-1 text-xs text-slate-300">Event: {{ $row['meta']['gigtune_booking_event_date'] ?? '-' }}</div>
                                                    </div>
                                                    <div class="flex flex-wrap gap-2">
                                                        <a class="inline-flex items-center justify-center rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold text-white hover:bg-white/15" href="/messages/?booking_id={{ $row['id'] }}">View Booking</a>
                                                        <form method="post" action="/admin-dashboard/bookings/restore">
                                                            @csrf
                                                            <input type="hidden" name="booking_id" value="{{ $row['id'] }}">
                                                            <input type="hidden" name="return_tab" value="overview">
                                                            <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold text-white hover:bg-white/15">Restore</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </article>
                                        @endforeach
                                    </div>
                                @endif
                            </details>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-black/20 p-4">
                            <div class="text-sm font-semibold text-white">Users</div>
                            <div class="mt-1 text-xs text-slate-300">Total users: {{ $metrics['users_total'] }}</div>
                            <a href="/admin-dashboard/users" class="mt-2 inline-flex text-sm text-blue-300 hover:text-blue-200">Open Users</a>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-black/20 p-4">
                            <div class="text-sm font-semibold text-white">Client PSAs</div>
                            <div class="mt-1 text-xs text-slate-300">Published PSAs: {{ $metrics['psa_total'] }}</div>
                            <a href="/browse-psa/" class="mt-2 inline-flex text-sm text-blue-300 hover:text-blue-200">Open Client PSAs</a>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-black/20 p-4">
                            <div class="text-sm font-semibold text-white">Client Profiles</div>
                            <div class="mt-1 text-xs text-slate-300">Client profiles are stored on user accounts and linked user meta.</div>
                            <a href="/admin-dashboard/users?user_scope=clients" class="mt-2 inline-flex text-sm text-blue-300 hover:text-blue-200">Open Users</a>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-black/20 p-4">
                            <div class="text-sm font-semibold text-white">Client Ratings</div>
                            <div class="mt-1 text-xs text-slate-300">Ratings are tracked via bookings and rating meta fields.</div>
                            <a href="/admin-dashboard/bookings" class="mt-2 inline-flex text-sm text-blue-300 hover:text-blue-200">Review via Bookings</a>
                        </div>
                        <div class="rounded-xl border border-white/10 bg-black/20 p-4 md:col-span-2">
                            <div class="text-sm font-semibold text-white">Delete user (hard delete)</div>
                            <div class="mt-1 text-xs text-slate-300">Allowed only when no active bookings, pending payouts, or open disputes exist.</div>
                            <form method="post" action="/admin-dashboard/users/hard-delete" class="mt-3 grid gap-2 sm:grid-cols-4">
                                @csrf
                                <input type="text" name="user_id" placeholder="User ID" class="rounded-lg bg-slate-950/50 border border-white/10 px-3 py-2 text-white text-sm sm:col-span-2" required>
                                <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold text-white bg-white/10 hover:bg-white/15 sm:col-span-2">Delete user</button>
                            </form>
                        </div>
                    </div>
                </div>
            @endif

            @if ($activeTab === 'users')
                <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
                    @php
                        $userScope = $tabData['user_scope'] ?? 'artists';
                        $userViewId = (int) ($tabData['user_view_id'] ?? 0);
                        $userDetail = $tabData['user_detail'] ?? null;
                    @endphp
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h4 class="text-base font-semibold text-white">Users</h4>
                        <div class="flex flex-wrap gap-2">
                            <a href="/admin-dashboard/users?user_scope=artists"
                                class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-xs font-semibold {{ $userScope === 'artists' ? 'text-white bg-gradient-to-r from-blue-600 to-purple-600' : 'text-white bg-white/10 hover:bg-white/15' }}">
                                Artists
                            </a>
                            <a href="/admin-dashboard/users?user_scope=clients"
                                class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-xs font-semibold {{ $userScope === 'clients' ? 'text-white bg-gradient-to-r from-blue-600 to-purple-600' : 'text-white bg-white/10 hover:bg-white/15' }}">
                                Clients
                            </a>
                        </div>
                    </div>

                    @if ($userViewId > 0)
                        @if (!is_array($userDetail) || !($userDetail['found'] ?? false))
                            <p class="mt-3 text-sm text-rose-200">User not found.</p>
                        @else
                            <div class="mt-4 rounded-xl border border-white/10 bg-black/20 p-4 space-y-2 text-sm text-slate-200">
                                <div class="text-white font-semibold">{{ $userDetail['public_name'] ?? 'Unknown user' }} (User #{{ $userDetail['id'] }})</div>
                                <div>Username: <span class="text-slate-100">{{ $userDetail['login'] ?? '-' }}</span></div>
                                <div>Email: <span class="text-slate-100">{{ $userDetail['email'] ?? '-' }}</span></div>
                                <div>Roles: <span class="text-slate-100">{{ !empty($userDetail['roles']) ? implode(', ', (array) $userDetail['roles']) : 'none' }}</span></div>
                                <div class="pt-2">
                                    <a href="/gts-admin-users?search={{ urlencode((string) ($userDetail['login'] ?? '')) }}" class="text-blue-300 hover:text-blue-200">Open in admin users</a>
                                </div>
                            </div>

                            <div class="mt-4 rounded-xl border border-white/10 bg-black/20 p-4 space-y-2 text-sm text-slate-200">
                                <div class="text-white font-semibold">Legal compliance</div>
                                <div>Email verified: <span class="text-slate-100">{{ !empty($userDetail['compliance']['email_verified']) ? 'Yes' : 'No' }}</span></div>
                                <div>Policies accepted: <span class="text-slate-100">{{ !empty($userDetail['compliance']['policies_accepted']) ? 'Yes' : 'No' }}</span></div>
                                <div>Identity Verification (Know Your Customer Compliance) status: <span class="text-slate-100">{{ $userDetail['compliance']['kyc_label'] ?? 'Unknown' }}</span></div>
                                <div>Overall compliance:
                                    <span class="{{ !empty($userDetail['compliance']['all_verified']) ? 'text-emerald-200' : 'text-amber-200' }}">
                                        {{ !empty($userDetail['compliance']['all_verified']) ? 'Compliant' : 'Action required' }}
                                    </span>
                                </div>
                                @if (!empty($userDetail['compliance_notes']))
                                    <div class="pt-1 rounded-lg border border-amber-500/20 bg-amber-500/10 p-2 text-xs text-amber-100/90 space-y-1">
                                        @foreach (($userDetail['compliance_notes'] ?? []) as $note)
                                            <div>{{ $note }}</div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            @if (!empty($userDetail['artist_profile']))
                                @php
                                    $artistProfile = $userDetail['artist_profile'];
                                @endphp
                                <div class="mt-4 rounded-xl border border-white/10 bg-black/20 p-4 space-y-2 text-sm text-slate-200">
                                    <div class="text-white font-semibold">Artist profile</div>
                                    <div>Profile ID: <span class="text-slate-100">{{ $artistProfile['id'] }}</span></div>
                                    <div>Name: <span class="text-slate-100">{{ $artistProfile['name'] !== '' ? $artistProfile['name'] : 'N/A' }}</span></div>
                                    <div>Area: <span class="text-slate-100">{{ $artistProfile['base_area'] !== '' ? $artistProfile['base_area'] : 'N/A' }}</span></div>
                                    <div>Pricing:
                                        <span class="text-slate-100">
                                            @if (($artistProfile['price_min'] ?? '') !== '' || ($artistProfile['price_max'] ?? '') !== '')
                                                ZAR {{ $artistProfile['price_min'] !== '' ? $artistProfile['price_min'] : '0' }} - {{ $artistProfile['price_max'] !== '' ? $artistProfile['price_max'] : '0' }}
                                            @else
                                                N/A
                                            @endif
                                        </span>
                                    </div>
                                    <div>Skills: <span class="text-slate-100">{{ !empty($artistProfile['skills']) ? implode(', ', $artistProfile['skills']) : 'N/A' }}</span></div>
                                    <div class="pt-2">
                                        <a href="/artist-profile/?artist_id={{ $artistProfile['id'] }}" class="text-blue-300 hover:text-blue-200">Open artist profile</a>
                                    </div>
                                </div>

                                <div id="gigtune-admin-user-payout" class="mt-4 rounded-xl border border-white/10 bg-black/20 p-4 space-y-2 text-sm text-slate-200 {{ ($userDetail['view_section'] ?? '') === 'payout' ? 'ring-2 ring-blue-400/50' : '' }}">
                                    <div class="text-white font-semibold">Payout details (admin only)</div>
                                    <div>Bank name: <span class="text-slate-100">{{ $artistProfile['bank_name'] !== '' ? $artistProfile['bank_name'] : 'N/A' }}</span></div>
                                    <div>Account holder: <span class="text-slate-100">{{ $artistProfile['bank_account_name'] !== '' ? $artistProfile['bank_account_name'] : 'N/A' }}</span></div>
                                    <div>Account number: <span class="text-slate-100">{{ $artistProfile['bank_account_number'] !== '' ? $artistProfile['bank_account_number'] : 'N/A' }}</span></div>
                                    <div>Branch code: <span class="text-slate-100">{{ $artistProfile['branch_code'] !== '' ? $artistProfile['branch_code'] : 'N/A' }}</span></div>
                                    <div>Payout preference: <span class="text-slate-100">{{ $artistProfile['payout_preference'] !== '' ? $artistProfile['payout_preference'] : 'N/A' }}</span></div>
                                </div>
                            @endif

                            <div class="mt-4 rounded-xl border border-white/10 bg-black/20 p-4 space-y-2 text-sm text-slate-200">
                                <div class="text-white font-semibold">Client profile</div>
                                @if (empty($userDetail['client_profile']))
                                    <div>Client profile not found.</div>
                                @else
                                    @php
                                        $clientProfile = $userDetail['client_profile'];
                                        $location = trim(($clientProfile['base_area'] ?? '') . ' ' . ($clientProfile['city'] ?? '') . ' ' . ($clientProfile['province'] ?? ''));
                                    @endphp
                                    <div>Display name: <span class="text-slate-100">{{ ($clientProfile['title'] ?? '') !== '' ? $clientProfile['title'] : ($userDetail['public_name'] ?? 'N/A') }}</span></div>
                                    <div>Phone: <span class="text-slate-100">{{ ($clientProfile['phone'] ?? '') !== '' ? $clientProfile['phone'] : 'N/A' }}</span></div>
                                    <div>Location: <span class="text-slate-100">{{ $location !== '' ? $location : 'N/A' }}</span></div>
                                    <div>Company: <span class="text-slate-100">{{ ($clientProfile['company'] ?? '') !== '' ? $clientProfile['company'] : 'N/A' }}</span></div>
                                    <div>Bio: <span class="text-slate-100">{{ $clientProfile['content'] ?? '' }}</span></div>
                                @endif
                            </div>
                        @endif
                    @else
                        @php
                            $usersList = is_array($tabData['items'] ?? null) ? $tabData['items'] : [];
                        @endphp
                        <details class="mt-4 rounded-xl border border-white/10 bg-black/20 p-4">
                            <summary class="cursor-pointer select-none text-sm font-semibold text-white">Users list ({{ count($usersList) }})</summary>
                            @if (empty($usersList))
                                <p class="mt-3 text-sm text-slate-300">No users found for this filter.</p>
                            @else
                                <div class="mt-3 space-y-2">
                                    @foreach ($usersList as $item)
                                        <div class="rounded-lg border border-white/10 bg-black/20 p-3 text-sm text-slate-200">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <div>
                                                    <div class="font-semibold text-white">{{ $item['public_name'] }} (User #{{ $item['id'] }})</div>
                                                    <div class="text-xs text-slate-300 mt-1">Username: {{ $item['login'] }} | Email: {{ $item['email'] }}</div>
                                                    <div class="text-xs mt-1 {{ !empty($item['compliance']['all_verified']) ? 'text-emerald-200' : 'text-amber-200' }}">{{ $item['compliance_summary'] }}</div>
                                                </div>
                                                <div class="flex gap-3 text-sm">
                                                    <a class="text-blue-300 hover:text-blue-200" href="{{ $item['view_url'] }}">View</a>
                                                    <a class="text-blue-300 hover:text-blue-200" href="/gts-admin-users?search={{ urlencode((string) $item['login']) }}">Admin users</a>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </details>
                    @endif
                </div>
            @endif

            @if ($activeTab === 'compliance')
                @php
                    $complianceScope = $tabData['user_scope'] ?? 'artists';
                @endphp
                <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h4 class="text-base font-semibold text-white">Compliance Overrides</h4>
                            <p class="mt-1 text-xs text-slate-300">Admin emergency controls for KYC, policy acceptance, email verification, and profile visibility.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a href="/admin-dashboard/compliance?user_scope=artists"
                                class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-xs font-semibold {{ $complianceScope === 'artists' ? 'text-white bg-gradient-to-r from-blue-600 to-purple-600' : 'text-white bg-white/10 hover:bg-white/15' }}">
                                Artists
                            </a>
                            <a href="/admin-dashboard/compliance?user_scope=clients"
                                class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-xs font-semibold {{ $complianceScope === 'clients' ? 'text-white bg-gradient-to-r from-blue-600 to-purple-600' : 'text-white bg-white/10 hover:bg-white/15' }}">
                                Clients
                            </a>
                        </div>
                    </div>

                    @php
                        $complianceItems = is_array($tabData['items'] ?? null) ? $tabData['items'] : [];
                    @endphp
                    <details class="mt-4 rounded-xl border border-white/10 bg-black/20 p-4">
                        <summary class="cursor-pointer select-none text-sm font-semibold text-white">Compliance users ({{ count($complianceItems) }})</summary>
                        @if (empty($complianceItems))
                            <p class="mt-3 text-sm text-slate-300">No users found for this scope.</p>
                        @else
                            <div class="mt-3 space-y-3">
                                @foreach ($complianceItems as $item)
                                @php
                                    $compliance = is_array($item['compliance'] ?? null) ? $item['compliance'] : [];
                                    $override = (string) ($item['profile_visibility_override'] ?? 'auto');
                                    $visibleNow = (bool) ($item['profile_visible_effective'] ?? false);
                                @endphp
                                <div class="rounded-xl border border-white/10 bg-black/20 p-4">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <div class="text-sm font-semibold text-white">{{ $item['public_name'] ?? ('User #' . ($item['id'] ?? '')) }} (User #{{ $item['id'] }})</div>
                                            <div class="mt-1 text-xs text-slate-300">Username: {{ $item['login'] ?? '-' }} | Email: {{ $item['email'] ?? '-' }}</div>
                                            <div class="mt-1 text-xs text-slate-300">Current: Email {{ !empty($compliance['email_verified']) ? 'verified' : 'unverified' }}, Policies {{ !empty($compliance['policies_accepted']) ? 'accepted' : 'pending' }}, KYC {{ $sentenceCase((string) ($compliance['kyc_status'] ?? 'unsubmitted')) }}, Profile {{ $visibleNow ? 'visible' : 'hidden' }}</div>
                                        </div>
                                    </div>
                                    <form method="post" action="/admin-dashboard/compliance/apply" class="mt-3 grid gap-3 md:grid-cols-5">
                                        @csrf
                                        <input type="hidden" name="user_id" value="{{ $item['id'] }}">
                                        <div>
                                            <label class="mb-1 block text-xs text-slate-300">Email verification</label>
                                            <select name="email_verified" class="gigtune-admin-select w-full rounded-lg bg-slate-950/50 border border-white/10 px-3 py-2 text-white text-sm">
                                                <option value="1" @selected(!empty($compliance['email_verified']))>Verified</option>
                                                <option value="0" @selected(empty($compliance['email_verified']))>Unverified</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-xs text-slate-300">Policy acceptance</label>
                                            <select name="policies_accepted" class="gigtune-admin-select w-full rounded-lg bg-slate-950/50 border border-white/10 px-3 py-2 text-white text-sm">
                                                <option value="1" @selected(!empty($compliance['policies_accepted']))>Accepted</option>
                                                <option value="0" @selected(empty($compliance['policies_accepted']))>Pending</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-xs text-slate-300">KYC status</label>
                                            <select name="kyc_status" class="gigtune-admin-select w-full rounded-lg bg-slate-950/50 border border-white/10 px-3 py-2 text-white text-sm">
                                                @foreach (['unsubmitted', 'pending', 'verified', 'rejected', 'locked'] as $kycStatus)
                                                    <option value="{{ $kycStatus }}" @selected(($compliance['kyc_status'] ?? 'unsubmitted') === $kycStatus)>{{ $sentenceCase($kycStatus) }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-xs text-slate-300">Profile visibility</label>
                                            <select name="profile_visibility" class="gigtune-admin-select w-full rounded-lg bg-slate-950/50 border border-white/10 px-3 py-2 text-white text-sm">
                                                <option value="auto" @selected($override === 'auto')>Auto</option>
                                                <option value="force_visible" @selected($override === 'force_visible')>Force visible</option>
                                                <option value="force_hidden" @selected($override === 'force_hidden')>Force hidden</option>
                                            </select>
                                        </div>
                                        <div class="md:col-span-1">
                                            <label class="mb-1 block text-xs text-slate-300">Admin note</label>
                                            <input type="text" name="note" value="" placeholder="Optional reason" class="w-full rounded-lg bg-slate-950/50 border border-white/10 px-3 py-2 text-white text-sm">
                                        </div>
                                        <div class="md:col-span-5">
                                            <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Apply override</button>
                                        </div>
                                    </form>
                                </div>
                                @endforeach
                            </div>
                        @endif
                    </details>
                </div>
            @endif

            @if ($activeTab === 'payments')
                <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
                    <h4 class="text-base font-semibold text-white">Payments Awaiting Confirmation</h4>
                    @php
                        $paymentItems = is_array($tabData['items'] ?? null) ? $tabData['items'] : [];
                    @endphp
                    <details class="mt-4 rounded-xl border border-white/10 bg-black/20 p-4">
                        <summary class="cursor-pointer select-none text-sm font-semibold text-white">Awaiting payment confirmation ({{ count($paymentItems) }})</summary>
                        @if (empty($paymentItems))
                            <p class="mt-3 text-sm text-slate-300">No bookings are awaiting payment confirmation.</p>
                        @else
                            <div class="mt-3 space-y-3">
                                @foreach ($paymentItems as $row)
                                @php
                                    $status = strtoupper((string) ($row['meta']['gigtune_payment_status'] ?? ''));
                                    $methodRaw = trim((string) ($row['meta']['gigtune_payment_method'] ?? ''));
                                    $methodNormalized = strtolower(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $methodRaw)) ?? $methodRaw);
                                    if ($methodNormalized === '') {
                                        $method = 'N/A';
                                    } elseif (str_contains($methodNormalized, 'yoco') || str_contains($methodNormalized, 'card')) {
                                        $method = 'Card Payment (yoco)';
                                    } elseif (str_contains($methodNormalized, 'manual')) {
                                        $method = 'Manual';
                                    } else {
                                        $method = ucfirst($methodNormalized);
                                    }
                                    $reference = (string) ($row['meta']['gigtune_payment_reference_human'] ?? '');
                                    $reportedRaw = (int) ($row['meta']['gigtune_payment_reported_at'] ?? 0);
                                    $reported = $reportedRaw > 0 ? date('Y-m-d H:i:s', $reportedRaw) : 'N/A';
                                    $bookingStatus = $sentenceCase($row['meta']['gigtune_booking_status'] ?? '-');
                                @endphp
                                <div class="rounded-xl border border-white/10 bg-black/20 p-4">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <div class="text-sm text-slate-200">
                                            <div class="font-semibold text-white">Booking #{{ $row['id'] }} - {{ $bookingStatus }}</div>
                                            <div class="mt-1 text-xs text-slate-300">Method: {{ $method }} | Status: {{ $sentenceCase($status) }}</div>
                                            <div class="mt-1 text-xs text-slate-300">Reference: <span class="font-mono">{{ $reference !== '' ? $reference : '-' }}</span> | Reported: {{ $reported }}</div>
                                        </div>
                                        <div class="flex flex-wrap gap-3 text-sm">
                                            <a class="text-blue-300 hover:text-blue-200" href="/messages/?booking_id={{ $row['id'] }}">Messages</a>
                                        </div>
                                    </div>
                                    <div class="mt-3 grid gap-2 md:grid-cols-2">
                                        <form method="post" action="/admin-dashboard/payments/review" class="flex gap-2">
                                            @csrf
                                            <input type="hidden" name="booking_id" value="{{ $row['id'] }}">
                                            <input type="hidden" name="decision" value="confirm">
                                            <input type="text" name="note" value="" placeholder="Optional note" class="w-full rounded-lg bg-slate-950/50 border border-white/10 px-3 py-2 text-white text-sm">
                                            <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Confirm</button>
                                        </form>
                                        <form method="post" action="/admin-dashboard/payments/review" class="flex gap-2">
                                            @csrf
                                            <input type="hidden" name="booking_id" value="{{ $row['id'] }}">
                                            <input type="hidden" name="decision" value="reject">
                                            <input type="text" name="note" value="" placeholder="Reason / note" class="w-full rounded-lg bg-slate-950/50 border border-white/10 px-3 py-2 text-white text-sm">
                                            <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold text-white bg-white/10 hover:bg-white/15">Reject</button>
                                        </form>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                        @endif
                    </details>
                </div>
            @endif

            @if ($activeTab === 'payouts')
                <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
                    <h4 class="text-base font-semibold text-white">Payouts Pending Manual Processing</h4>
                    @php
                        $pendingPayoutItems = is_array($tabData['pending_items'] ?? null) ? $tabData['pending_items'] : [];
                        $needsReviewItems = is_array($tabData['needs_review_items'] ?? null) ? $tabData['needs_review_items'] : [];
                    @endphp
                    <details class="mt-4 rounded-xl border border-white/10 bg-black/20 p-4">
                        <summary class="cursor-pointer select-none text-sm font-semibold text-white">Pending manual payouts ({{ count($pendingPayoutItems) }})</summary>
                        @if (empty($pendingPayoutItems))
                            <p class="mt-3 text-sm text-slate-300">No manual payouts pending.</p>
                        @else
                            <div class="mt-3 space-y-3">
                                @foreach ($pendingPayoutItems as $row)
                                @php
                                    $bookingStatus = $sentenceCase($row['meta']['gigtune_booking_status'] ?? '-');
                                @endphp
                                <div class="rounded-xl border border-white/10 bg-black/20 p-4">
                                    <div class="text-sm text-slate-200">
                                        <div class="font-semibold text-white">Booking #{{ $row['id'] }} - {{ $bookingStatus }}</div>
                                        <div class="mt-1 text-xs text-slate-300">Payout: {{ $sentenceCase($row['meta']['gigtune_payout_status'] ?? '-') }} | Payment: {{ $sentenceCase($row['meta']['gigtune_payment_status'] ?? '-') }}</div>
                                    </div>
                                    <form method="post" action="/admin-dashboard/payouts/review" class="mt-3 space-y-2">
                                        @csrf
                                        <input type="hidden" name="booking_id" value="{{ $row['id'] }}">
                                        <input type="text" name="reference" value="" placeholder="Payout reference" class="w-full rounded-lg bg-slate-950/50 border border-white/10 px-3 py-2 text-white text-sm">
                                        <textarea name="note" rows="2" placeholder="Payout note" class="w-full rounded-lg bg-slate-950/50 border border-white/10 px-3 py-2 text-white text-sm"></textarea>
                                        <div class="flex flex-wrap gap-2">
                                            <button type="submit" name="decision" value="paid" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Mark payout processed</button>
                                            <button type="submit" name="decision" value="failed" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold text-white bg-white/10 hover:bg-white/15">Mark payout failed</button>
                                        </div>
                                    </form>
                                </div>
                                @endforeach
                            </div>
                        @endif
                    </details>

                    <details class="mt-4 rounded-xl border border-white/10 bg-black/20 p-4">
                        <summary class="cursor-pointer select-none text-sm font-semibold text-white">Needs review ({{ count($needsReviewItems) }})</summary>
                        @if (empty($needsReviewItems))
                            <p class="mt-3 text-sm text-slate-300">No unusual payout states detected.</p>
                        @else
                            <div class="mt-3 space-y-2">
                                @foreach ($needsReviewItems as $row)
                                    <div class="rounded-lg border border-white/10 bg-black/20 p-3 text-sm text-slate-200">
                                        Booking #{{ $row['id'] }} | Payout: {{ $sentenceCase($row['meta']['gigtune_payout_status'] ?? '-') }}
                                        @if (!empty($row['meta']['gigtune_payout_failure_reason']))
                                            | Reason: {{ $row['meta']['gigtune_payout_failure_reason'] }}
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </details>
                </div>
            @endif

            @if ($activeTab === 'bookings')
                <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
                    <h4 class="text-base font-semibold text-white">Bookings</h4>
                    @php
                        $activeBookingItems = is_array($tabData['active_items'] ?? null) ? $tabData['active_items'] : [];
                        $archivedBookingItems = is_array($tabData['archived_items'] ?? null) ? $tabData['archived_items'] : [];
                        $archivedBookingCount = (int) ($tabData['archived_count'] ?? count($archivedBookingItems));
                    @endphp
                    @if (empty($activeBookingItems))
                        <p class="mt-3 text-sm text-slate-300">No active bookings found.</p>
                    @else
                        <div class="mt-4 space-y-3">
                            @foreach ($activeBookingItems as $row)
                                @php
                                    $bookingStatus = $sentenceCase($row['meta']['gigtune_booking_status'] ?? '-');
                                @endphp
                                <div class="rounded-xl border border-white/10 bg-black/20 p-4">
                                    <div class="text-sm text-slate-200">
                                        <div class="font-semibold text-white">Booking #{{ $row['id'] }} - {{ $bookingStatus }}</div>
                                        <div class="mt-1 text-xs text-slate-300">Payment: {{ $sentenceCase($row['meta']['gigtune_payment_status'] ?? '-') }} | Payout: {{ $sentenceCase($row['meta']['gigtune_payout_status'] ?? '-') }}</div>
                                        <div class="mt-1 text-xs text-slate-300">Client: {{ $row['meta']['gigtune_booking_client_user_id'] ?? '-' }} | Artist profile: {{ $row['meta']['gigtune_booking_artist_profile_id'] ?? '-' }} | Event: {{ $row['meta']['gigtune_booking_event_date'] ?? '-' }}</div>
                                    </div>
                                    <form method="post" action="/admin-dashboard/bookings/request-refund" class="mt-3 flex gap-2">
                                        @csrf
                                        <input type="hidden" name="booking_id" value="{{ $row['id'] }}">
                                        <input type="text" name="note" value="" placeholder="Refund note (optional)" class="w-full rounded-lg bg-slate-950/50 border border-white/10 px-3 py-2 text-white text-sm">
                                        <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold text-white bg-white/10 hover:bg-white/15">Refund (YOCO)</button>
                                    </form>
                                    @if (!empty($row['can_archive']))
                                        <form method="post" action="/admin-dashboard/bookings/archive" class="mt-2">
                                            @csrf
                                            <input type="hidden" name="booking_id" value="{{ $row['id'] }}">
                                            <input type="hidden" name="return_tab" value="bookings">
                                            <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold text-white hover:bg-white/15">Archive</button>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <details class="mt-4 rounded-xl border border-white/10 bg-black/20 p-4" @if ($archivedBookingCount > 0) open @endif>
                        <summary class="cursor-pointer select-none text-sm font-semibold text-white">Archived bookings ({{ $archivedBookingCount }})</summary>
                        @if (empty($archivedBookingItems))
                            <p class="mt-3 text-sm text-slate-300">No archived bookings.</p>
                        @else
                            <div class="mt-3 space-y-3">
                                @foreach ($archivedBookingItems as $row)
                                    @php
                                        $bookingStatus = $sentenceCase($row['meta']['gigtune_booking_status'] ?? '-');
                                    @endphp
                                    <div class="rounded-xl border border-white/10 bg-slate-950/40 p-4">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div class="text-sm text-slate-200">
                                                <div class="font-semibold text-white">Booking #{{ $row['id'] }} - {{ $bookingStatus }} <span class="text-slate-400">Archived</span></div>
                                                <div class="mt-1 text-xs text-slate-300">Payment: {{ $sentenceCase($row['meta']['gigtune_payment_status'] ?? '-') }} | Payout: {{ $sentenceCase($row['meta']['gigtune_payout_status'] ?? '-') }}</div>
                                                <div class="mt-1 text-xs text-slate-300">Event: {{ $row['meta']['gigtune_booking_event_date'] ?? '-' }}</div>
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                <a class="inline-flex items-center justify-center rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold text-white hover:bg-white/15" href="/messages/?booking_id={{ $row['id'] }}">View Booking</a>
                                                <form method="post" action="/admin-dashboard/bookings/restore">
                                                    @csrf
                                                    <input type="hidden" name="booking_id" value="{{ $row['id'] }}">
                                                    <input type="hidden" name="return_tab" value="bookings">
                                                    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold text-white hover:bg-white/15">Restore</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </details>
                </div>
            @endif

            @if ($activeTab === 'disputes')
                <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
                    <h4 class="text-base font-semibold text-white">Disputes</h4>
                    @php
                        $disputeItems = is_array($tabData['items'] ?? null) ? $tabData['items'] : [];
                    @endphp
                    <details class="mt-4 rounded-xl border border-white/10 bg-black/20 p-4">
                        <summary class="cursor-pointer select-none text-sm font-semibold text-white">Disputes ({{ count($disputeItems) }})</summary>
                        @if (empty($disputeItems))
                            <p class="mt-3 text-sm text-slate-300">No disputes found.</p>
                        @else
                            <div class="mt-3 space-y-3">
                                @foreach ($disputeItems as $row)
                                <div class="rounded-xl border border-white/10 bg-black/20 p-4">
                                    <div class="text-sm text-slate-200">
                                        <div class="font-semibold text-white">Dispute #{{ $row['id'] }}</div>
                                        <div class="mt-1 text-xs text-slate-300">Booking: {{ $row['meta']['gigtune_dispute_booking_id'] ?? '-' }} | Status: {{ $sentenceCase($row['meta']['gigtune_dispute_status'] ?? '-') }}</div>
                                        <div class="mt-1 text-xs text-slate-300">Reason: {{ $row['meta']['gigtune_dispute_reason'] ?? '-' }}</div>
                                    </div>
                                    <form method="post" action="/admin-dashboard/disputes/review" class="mt-3 space-y-2">
                                        @csrf
                                        <input type="hidden" name="dispute_id" value="{{ $row['id'] }}">
                                        <textarea name="note" rows="2" placeholder="Admin note" class="w-full rounded-lg bg-slate-950/50 border border-white/10 px-3 py-2 text-white text-sm"></textarea>
                                        <label class="inline-flex items-center gap-2 text-xs text-slate-300">
                                            <input type="checkbox" name="mark_booking_completed" value="1">
                                            Resolve and mark booking completed
                                        </label>
                                        <div class="flex flex-wrap gap-2">
                                            <button type="submit" name="decision" value="resolve" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Resolve dispute</button>
                                            <button type="submit" name="decision" value="reject" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold text-white bg-white/10 hover:bg-white/15">Reject dispute</button>
                                        </div>
                                    </form>
                                </div>
                                @endforeach
                            </div>
                        @endif
                    </details>
                </div>
            @endif

            @if ($activeTab === 'refunds')
                <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
                    <h4 class="text-base font-semibold text-white">Refunds Queue</h4>
                    @php
                        $refundItems = is_array($tabData['items'] ?? null) ? $tabData['items'] : [];
                    @endphp
                    <details class="mt-4 rounded-xl border border-white/10 bg-black/20 p-4">
                        <summary class="cursor-pointer select-none text-sm font-semibold text-white">Refund requests ({{ count($refundItems) }})</summary>
                        @if (empty($refundItems))
                            <p class="mt-3 text-sm text-slate-300">No refunds are currently pending review.</p>
                        @else
                            <div class="mt-3 space-y-3">
                                @foreach ($refundItems as $row)
                                @php
                                    $checkoutId = (string) ($row['meta']['gigtune_refund_checkout_id'] ?? '');
                                    if ($checkoutId === '') {
                                        $checkoutId = (string) ($row['meta']['gigtune_yoco_checkout_id'] ?? '');
                                    }
                                    $requestedAt = (int) ($row['meta']['gigtune_refund_requested_at'] ?? 0);
                                    $bookingStatus = $sentenceCase($row['meta']['gigtune_booking_status'] ?? '-');
                                @endphp
                                <div class="rounded-xl border border-white/10 bg-black/20 p-4">
                                    <div class="text-sm text-slate-200">
                                        <div class="font-semibold text-white">Booking #{{ $row['id'] }} - {{ $bookingStatus }}</div>
                                        <div class="mt-1 text-xs text-slate-300">Payment: {{ $sentenceCase($row['meta']['gigtune_payment_status'] ?? '-') }} | Refund: {{ $sentenceCase($row['meta']['gigtune_refund_status'] ?? '-') }}</div>
                                        <div class="mt-1 text-xs text-slate-300">Requested by: {{ $row['meta']['gigtune_refund_requested_by'] ?? '-' }} | Requested at: {{ $requestedAt > 0 ? date('Y-m-d H:i:s', $requestedAt) : 'N/A' }}</div>
                                        <div class="mt-1 text-xs text-slate-300">Checkout ID: <span class="break-all">{{ $checkoutId !== '' ? $checkoutId : 'N/A' }}</span></div>
                                        @if (!empty($row['meta']['gigtune_refund_failure_reason']))
                                            <div class="mt-1 text-xs text-rose-200">Failure reason: {{ $row['meta']['gigtune_refund_failure_reason'] }}</div>
                                        @endif
                                    </div>
                                    <form method="post" action="/admin-dashboard/refunds/review" class="mt-3 space-y-2">
                                        @csrf
                                        <input type="hidden" name="booking_id" value="{{ $row['id'] }}">
                                        <textarea name="note" rows="2" placeholder="Admin note (optional)" class="w-full rounded-lg bg-slate-950/50 border border-white/10 px-3 py-2 text-white text-sm"></textarea>
                                        <div class="flex flex-wrap gap-2">
                                            <button type="submit" name="decision" value="pending" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold text-white bg-white/10 hover:bg-white/15">Mark refund approved</button>
                                            <button type="submit" name="decision" value="reject" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold text-white bg-white/10 hover:bg-white/15">Mark refund rejected</button>
                                            <button type="submit" name="decision" value="completed" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Mark refund completed</button>
                                        </div>
                                    </form>
                                </div>
                                @endforeach
                            </div>
                        @endif
                    </details>
                </div>
            @endif

            @if ($activeTab === 'kyc')
                @php
                    $kycItems = is_array($tabData['items'] ?? null) ? $tabData['items'] : [];
                    $kycGroups = [
                        'pending' => [],
                        'approved' => [],
                        'rejected' => [],
                        'locked' => [],
                        'deleted' => [],
                    ];
                    foreach ($kycItems as $submission) {
                        $meta = is_array($submission['meta'] ?? null) ? $submission['meta'] : [];
                        $decision = trim(strtolower((string) ($meta['gigtune_kyc_decision'] ?? '')));
                        $user = is_array($submission['user'] ?? null) ? $submission['user'] : [];
                        $userExists = (bool) ($user['exists'] ?? false);
                        if (!$userExists) {
                            $kycGroups['deleted'][] = $submission;
                            continue;
                        }
                        if ($decision === '' || $decision === 'pending') {
                            $kycGroups['pending'][] = $submission;
                        } elseif ($decision === 'approve') {
                            $kycGroups['approved'][] = $submission;
                        } elseif (in_array($decision, ['reject', 'needs_more_info'], true)) {
                            $kycGroups['rejected'][] = $submission;
                        } elseif ($decision === 'lock') {
                            $kycGroups['locked'][] = $submission;
                        } else {
                            $kycGroups['pending'][] = $submission;
                        }
                    }
                    $kycGroupLabels = [
                        'pending' => 'Verification queue',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected / needs more info',
                        'locked' => 'Locked',
                        'deleted' => 'Deleted accounts',
                    ];
                    $kycGroupEmptyLabels = [
                        'pending' => 'No Identity Verification submissions are currently pending verification.',
                        'approved' => 'No approved Identity Verification submissions yet.',
                        'rejected' => 'No rejected Identity Verification submissions yet.',
                        'locked' => 'No locked Identity Verification submissions.',
                        'deleted' => 'No orphaned submissions from deleted accounts.',
                    ];
                    $kycStatusLabels = [
                        'unsubmitted' => 'Identity Verification not submitted',
                        'pending' => 'Identity Verification pending review',
                        'verified' => 'Identity Verification verified',
                        'rejected' => 'Identity Verification rejected',
                        'locked' => 'Identity Verification/security locked',
                    ];
                @endphp
                <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
                    <h4 class="text-base font-semibold text-white">Identity Verification (Know Your Customer Compliance) &amp; History</h4>
                    <p class="mt-2 text-sm text-slate-300">Review identity documents, approve/reject/lock submissions, and reopen prior records any time. Approved and rejected submissions remain fully accessible to admins.</p>
                    <div class="mt-4 space-y-4">
                        @foreach ($kycGroupLabels as $groupKey => $groupLabel)
                            @php
                                $groupItems = is_array($kycGroups[$groupKey] ?? null) ? $kycGroups[$groupKey] : [];
                                $groupCount = count($groupItems);
                                $groupOpen = $groupKey === 'pending';
                            @endphp
                            <details class="rounded-xl border border-white/10 bg-black/20 p-4" @if ($groupOpen) open @endif>
                                <summary class="flex cursor-pointer select-none items-center justify-between gap-3 text-sm font-semibold text-white">
                                    <span>{{ $groupLabel }}</span>
                                    <span class="inline-flex items-center rounded-full border border-white/10 bg-white/10 px-2.5 py-1 text-xs text-slate-200">{{ $groupCount }}</span>
                                </summary>
                                @if (empty($groupItems))
                                    <p class="mt-3 text-sm text-slate-300">{{ $kycGroupEmptyLabels[$groupKey] ?? 'No submissions.' }}</p>
                                @else
                                    <div class="mt-3 space-y-3">
                                        @foreach ($groupItems as $submission)
                                            @php
                                                $submissionId = (int) ($submission['id'] ?? 0);
                                                $meta = is_array($submission['meta'] ?? null) ? $submission['meta'] : [];
                                                $parsed = is_array($submission['parsed'] ?? null) ? $submission['parsed'] : [];
                                                $user = is_array($submission['user'] ?? null) ? $submission['user'] : [];
                                                $targetUserId = (int) ($meta['gigtune_kyc_user_id'] ?? 0);
                                                $userExists = (bool) ($user['exists'] ?? false);
                                                $decision = trim((string) ($meta['gigtune_kyc_decision'] ?? ''));
                                                if ($decision === '') {
                                                    $decision = 'pending';
                                                }
                                                $statusRaw = trim(strtolower((string) (($submission['user_meta']['gigtune_kyc_status'] ?? ''))));
                                                if (!array_key_exists($statusRaw, $kycStatusLabels)) {
                                                    $statusRaw = 'unsubmitted';
                                                }
                                                $statusLabel = $kycStatusLabels[$statusRaw];
                                                $riskScore = (int) ($parsed['risk_score'] ?? 0);
                                                $riskFlags = is_array($parsed['risk_flags'] ?? null) ? $parsed['risk_flags'] : [];
                                                $documents = is_array($parsed['documents'] ?? null) ? $parsed['documents'] : [];
                                                $viewArgs = ['submission_id' => $submissionId];
                                                if ($targetUserId > 0) {
                                                    $viewArgs['user_id'] = $targetUserId;
                                                }
                                                $submissionViewUrl = '/kyc-status/?' . http_build_query($viewArgs);
                                            @endphp
                                            <div class="rounded-xl border border-white/10 bg-black/20 p-4">
                                                <div class="flex flex-wrap items-center justify-between gap-2">
                                                    <div class="font-semibold text-white">Submission #{{ $submissionId }}</div>
                                                    <div class="flex items-center gap-3 text-xs text-slate-300">
                                                        <span>Decision: <span class="text-slate-100">{{ $sentenceCase($decision) }}</span></span>
                                                        <a class="text-blue-300 hover:text-blue-200 underline" href="{{ $submissionViewUrl }}">View ID</a>
                                                    </div>
                                                </div>
                                                <div class="mt-2 text-xs text-slate-300">
                                                    User: <span class="text-slate-100">{{ $user['public_name'] ?? 'Unknown' }}</span>
                                                    @if ($userExists)
                                                        (ID {{ $targetUserId }} | {{ (string) ($user['email'] ?? '-') }})
                                                    @endif
                                                </div>
                                                <div class="mt-1 text-xs text-slate-300">
                                                    Role: <span class="text-slate-100">{{ (string) ($meta['gigtune_kyc_role'] ?? '') !== '' ? $sentenceCase((string) $meta['gigtune_kyc_role']) : 'N/A' }}</span> |
                                                    Submitted: <span class="text-slate-100">{{ (string) ($meta['gigtune_kyc_submitted_at'] ?? '') !== '' ? (string) $meta['gigtune_kyc_submitted_at'] : ($submission['date'] ?? 'N/A') }}</span> |
                                                    Current status: <span class="text-slate-100">{{ $statusLabel }}</span>
                                                </div>
                                                <div class="mt-1 text-xs text-slate-300">
                                                    Risk score: <span class="text-slate-100">{{ $riskScore }}</span> |
                                                    Risk flags: <span class="text-slate-100">{{ !empty($riskFlags) ? implode(', ', $riskFlags) : 'none' }}</span>
                                                </div>
                                                <details class="mt-3 rounded-lg border border-white/10 bg-black/30 p-3">
                                                    <summary class="cursor-pointer select-none text-xs font-semibold uppercase tracking-wide text-slate-200">Expand full view</summary>
                                                    <div class="mt-3 space-y-3">
                                                        @if (!empty($documents))
                                                            <div class="mt-2 space-y-2 text-xs">
                                                                @foreach ($documents as $docKey => $document)
                                                                    @php
                                                                        $docLabel = ucwords(str_replace('_', ' ', trim((string) $docKey)));
                                                                        $docRouteKey = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $docKey) ?? '';
                                                                        $previewHref = '';
                                                                        $downloadHref = '';
                                                                        $previewMime = strtolower(trim((string) ($document['mime'] ?? '')));
                                                                        if ($docRouteKey !== '') {
                                                                            $previewHref = '/admin-dashboard/kyc/documents/' . $submissionId . '/' . $docRouteKey . '/preview';
                                                                            $downloadHref = '/admin-dashboard/kyc/documents/' . $submissionId . '/' . $docRouteKey . '/download';
                                                                        }
                                                                    @endphp
                                                                    <div class="flex flex-wrap items-center gap-2">
                                                                        <span class="text-slate-300 min-w-[140px]">{{ $docLabel !== '' ? $docLabel : 'Document' }}</span>
                                                                        @if ($previewHref !== '' && $downloadHref !== '')
                                                                            <button type="button"
                                                                                data-gt-kyc-preview="1"
                                                                                data-preview-url="{{ $previewHref }}"
                                                                                data-preview-mime="{{ $previewMime }}"
                                                                                class="inline-flex items-center rounded-lg bg-white/10 px-3 py-2 text-white hover:bg-white/15">
                                                                                Preview
                                                                            </button>
                                                                            <a href="{{ $downloadHref }}" target="_blank" rel="noopener" class="inline-flex items-center rounded-lg bg-white/10 px-3 py-2 text-white hover:bg-white/15">Download</a>
                                                                        @endif
                                                                    </div>
                                                                @endforeach
                                                            </div>
                                                        @endif

                                                        @if ($userExists)
                                                            <form method="post" action="/admin-dashboard/kyc/review" class="space-y-2">
                                                                @csrf
                                                                <input type="hidden" name="submission_id" value="{{ $submissionId }}">
                                                                <input type="hidden" name="target_user_id" value="{{ $targetUserId }}">
                                                                <div>
                                                                    <label class="block text-sm text-slate-300 mb-1" for="gigtune_admin_kyc_decision_{{ $submissionId }}">Decision</label>
                                                                    <select id="gigtune_admin_kyc_decision_{{ $submissionId }}" name="decision" class="gigtune-admin-select w-full rounded-lg bg-slate-950/50 border border-white/10 px-3 py-2 text-white text-sm">
                                                                        <option value="pending" @selected($decision === 'pending')>Pending</option>
                                                                        <option value="approve" @selected($decision === 'approve')>Approve</option>
                                                                        <option value="reject" @selected($decision === 'reject')>Reject</option>
                                                                        <option value="needs_more_info" @selected($decision === 'needs_more_info')>Needs more info</option>
                                                                        <option value="lock" @selected($decision === 'lock')>Lock account</option>
                                                                    </select>
                                                                </div>
                                                                <textarea name="review_reason" rows="2" placeholder="Review reason (admin)" class="w-full rounded-lg bg-slate-950/50 border border-white/10 px-3 py-2 text-white text-sm">{{ (string) ($meta['gigtune_kyc_review_reason'] ?? '') }}</textarea>
                                                                <textarea name="decision_notes" rows="2" placeholder="Decision notes (shown in notification)" class="w-full rounded-lg bg-slate-950/50 border border-white/10 px-3 py-2 text-white text-sm">{{ (string) ($meta['gigtune_kyc_decision_notes'] ?? '') }}</textarea>
                                                                <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">Save Identity Verification decision</button>
                                                            </form>
                                                        @else
                                                            <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-100">
                                                                User account no longer exists. Documents remain visible for audit in this deleted-accounts queue.
                                                            </div>
                                                            <form method="post" action="/admin-dashboard/kyc/purge-deleted" class="space-y-2">
                                                                @csrf
                                                                <input type="hidden" name="submission_id" value="{{ $submissionId }}">
                                                                <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold text-white bg-rose-600/90 hover:bg-rose-500">
                                                                    Remove from site
                                                                </button>
                                                            </form>
                                                        @endif
                                                    </div>
                                                </details>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </details>
                        @endforeach
                    </div>
                </div>

                <div id="gt-kyc-preview-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/80 p-4">
                    <div class="w-full max-w-6xl rounded-2xl border border-white/10 bg-slate-900 shadow-2xl">
                        <div class="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-3">
                            <h4 class="text-sm font-semibold text-white">Identity Verification document preview</h4>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" data-gt-kyc-preview-fit="1" class="inline-flex items-center rounded-lg bg-white/10 px-2.5 py-2 text-xs text-white hover:bg-white/15">Fit</button>
                                <button type="button" data-gt-kyc-preview-zoom-out="1" class="inline-flex items-center rounded-lg bg-white/10 px-2.5 py-2 text-xs text-white hover:bg-white/15">-</button>
                                <button type="button" data-gt-kyc-preview-zoom-in="1" class="inline-flex items-center rounded-lg bg-white/10 px-2.5 py-2 text-xs text-white hover:bg-white/15">+</button>
                                <button type="button" data-gt-kyc-preview-rotate-left="1" class="inline-flex items-center rounded-lg bg-white/10 px-2.5 py-2 text-xs text-white hover:bg-white/15">&#8634;</button>
                                <button type="button" data-gt-kyc-preview-rotate-right="1" class="inline-flex items-center rounded-lg bg-white/10 px-2.5 py-2 text-xs text-white hover:bg-white/15">&#8635;</button>
                                <button type="button" data-gt-kyc-preview-fullscreen="1" class="inline-flex items-center rounded-lg bg-white/10 px-2.5 py-2 text-xs text-white hover:bg-white/15" title="Fullscreen">
                                    <span aria-hidden="true">&#9974;</span>
                                </button>
                                <button type="button" data-gt-kyc-preview-close="1" class="inline-flex items-center rounded-lg bg-white/10 px-3 py-2 text-xs text-white hover:bg-white/15">Close</button>
                            </div>
                        </div>
                        <div id="gt-kyc-preview-stage" class="relative h-[75vh] w-full overflow-auto rounded-b-2xl bg-black flex items-center justify-center">
                            <iframe id="gt-kyc-preview-frame" title="Identity Verification document preview" class="hidden h-full w-full border-0 bg-black"></iframe>
                            <img id="gt-kyc-preview-image" alt="Identity Verification document preview image" class="hidden max-h-full max-w-full object-contain origin-center transition-transform duration-150 select-none" />
                        </div>
                    </div>
                </div>
                <script>
                    (function () {
                        var modal = document.getElementById('gt-kyc-preview-modal');
                        var stage = document.getElementById('gt-kyc-preview-stage');
                        var frame = document.getElementById('gt-kyc-preview-frame');
                        var image = document.getElementById('gt-kyc-preview-image');
                        if (!modal || !stage || !frame || !image) return;

                        var state = { mode: '', scale: 1, rotation: 0, tx: 0, ty: 0, dragging: false, dragPointerId: null, dragStartX: 0, dragStartY: 0, dragOriginX: 0, dragOriginY: 0 };

                        function applyTransform() {
                            if (state.mode !== 'image') return;
                            image.style.transform = 'translate(' + state.tx + 'px, ' + state.ty + 'px) scale(' + state.scale + ') rotate(' + state.rotation + 'deg)';
                        }

                        function fit() {
                            state.scale = 1;
                            state.rotation = 0;
                            state.tx = 0;
                            state.ty = 0;
                            applyTransform();
                            if (state.mode === 'pdf') {
                                var src = frame.getAttribute('src') || '';
                                if (src !== '') {
                                    frame.setAttribute('src', src.replace(/#.*$/, '') + '#zoom=page-fit');
                                }
                            }
                        }

                        function show(url, mime) {
                            var safeUrl = String(url || '').trim();
                            if (!safeUrl) return;
                            state.mode = (String(mime || '').toLowerCase().indexOf('pdf') !== -1) ? 'pdf' : 'image';
                            state.scale = 1;
                            state.rotation = 0;
                            if (state.mode === 'pdf') {
                                image.classList.add('hidden');
                                image.removeAttribute('src');
                                frame.classList.remove('hidden');
                                frame.setAttribute('src', safeUrl + '#zoom=page-fit');
                            } else {
                                frame.classList.add('hidden');
                                frame.removeAttribute('src');
                                image.classList.remove('hidden');
                                image.setAttribute('src', safeUrl);
                                image.style.cursor = 'grab';
                                applyTransform();
                            }
                            modal.classList.remove('hidden');
                            modal.classList.add('flex');
                        }

                        function closeModal() {
                            modal.classList.add('hidden');
                            modal.classList.remove('flex');
                            frame.classList.add('hidden');
                            frame.removeAttribute('src');
                            image.classList.add('hidden');
                            image.removeAttribute('src');
                            image.style.transform = '';
                            image.style.cursor = '';
                            state.mode = '';
                        }

                        document.querySelectorAll('[data-gt-kyc-preview="1"]').forEach(function (button) {
                            button.addEventListener('click', function () {
                                show(button.getAttribute('data-preview-url') || '', button.getAttribute('data-preview-mime') || '');
                            });
                        });

                        modal.querySelectorAll('[data-gt-kyc-preview-close="1"]').forEach(function (button) {
                            button.addEventListener('click', closeModal);
                        });
                        modal.querySelectorAll('[data-gt-kyc-preview-fit="1"]').forEach(function (button) {
                            button.addEventListener('click', fit);
                        });
                        modal.querySelectorAll('[data-gt-kyc-preview-zoom-in="1"]').forEach(function (button) {
                            button.addEventListener('click', function () {
                                if (state.mode !== 'image') return;
                                state.scale = Math.min(4, state.scale + 0.2);
                                applyTransform();
                            });
                        });
                        modal.querySelectorAll('[data-gt-kyc-preview-zoom-out="1"]').forEach(function (button) {
                            button.addEventListener('click', function () {
                                if (state.mode !== 'image') return;
                                state.scale = Math.max(0.4, state.scale - 0.2);
                                applyTransform();
                            });
                        });
                        modal.querySelectorAll('[data-gt-kyc-preview-rotate-left="1"]').forEach(function (button) {
                            button.addEventListener('click', function () {
                                if (state.mode !== 'image') return;
                                state.rotation -= 90;
                                applyTransform();
                            });
                        });
                        modal.querySelectorAll('[data-gt-kyc-preview-rotate-right="1"]').forEach(function (button) {
                            button.addEventListener('click', function () {
                                if (state.mode !== 'image') return;
                                state.rotation += 90;
                                applyTransform();
                            });
                        });
                        modal.querySelectorAll('[data-gt-kyc-preview-fullscreen="1"]').forEach(function (button) {
                            button.addEventListener('click', function () {
                                if (document.fullscreenElement) {
                                    document.exitFullscreen().catch(function () {});
                                } else if (stage.requestFullscreen) {
                                    stage.requestFullscreen().catch(function () {});
                                }
                            });
                        });
                        stage.addEventListener('wheel', function (event) {
                            if (state.mode !== 'image') return;
                            event.preventDefault();
                            var delta = event.deltaY > 0 ? -0.1 : 0.1;
                            state.scale = Math.max(0.4, Math.min(6, state.scale + delta));
                            applyTransform();
                        }, { passive: false });
                        stage.addEventListener('pointerdown', function (event) {
                            if (state.mode !== 'image') return;
                            state.dragging = true;
                            state.dragPointerId = event.pointerId;
                            state.dragStartX = event.clientX;
                            state.dragStartY = event.clientY;
                            state.dragOriginX = state.tx;
                            state.dragOriginY = state.ty;
                            image.style.cursor = 'grabbing';
                            if (stage.setPointerCapture) {
                                try { stage.setPointerCapture(event.pointerId); } catch (e) {}
                            }
                        });
                        stage.addEventListener('pointermove', function (event) {
                            if (!state.dragging || state.mode !== 'image' || state.dragPointerId !== event.pointerId) return;
                            state.tx = state.dragOriginX + (event.clientX - state.dragStartX);
                            state.ty = state.dragOriginY + (event.clientY - state.dragStartY);
                            applyTransform();
                        });
                        function stopDrag(event) {
                            if (!state.dragging) return;
                            if (event && state.dragPointerId !== null && event.pointerId !== state.dragPointerId) return;
                            state.dragging = false;
                            state.dragPointerId = null;
                            if (state.mode === 'image') {
                                image.style.cursor = 'grab';
                            }
                        }
                        stage.addEventListener('pointerup', stopDrag);
                        stage.addEventListener('pointercancel', stopDrag);
                        modal.addEventListener('click', function (event) {
                            if (event.target === modal) closeModal();
                        });
                        document.addEventListener('keydown', function (event) {
                            if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
                                closeModal();
                            }
                        });
                    })();
                </script>
            @endif

            @if ($activeTab === 'reports')
                <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
                    <h4 class="text-base font-semibold text-white">Reports</h4>
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        @foreach (['window_7', 'window_30'] as $key)
                            @php $row = $tabData[$key] ?? []; @endphp
                            <div class="rounded-xl border border-white/10 bg-black/20 p-4 text-sm text-slate-200 space-y-1">
                                <div class="font-semibold text-white">Last {{ $row['days'] ?? '-' }} days</div>
                                <div>Total bookings: {{ $row['total_bookings'] ?? 0 }}</div>
                                <div>Awaiting payment confirmation: {{ $row['awaiting_payment_confirmation'] ?? 0 }}</div>
                                <div>Confirmed held: {{ $row['confirmed_held'] ?? 0 }}</div>
                                <div>Payouts paid: {{ $row['payouts_paid'] ?? 0 }}</div>
                                <div>Payouts pending manual: {{ $row['payouts_pending_manual'] ?? 0 }}</div>
                                <div>Gross: ZAR {{ number_format((float) ($row['gross'] ?? 0), 2) }}</div>
                                <div>Service fees: ZAR {{ number_format((float) ($row['fees'] ?? 0), 2) }}</div>
                                <div>Net payout to artists: ZAR {{ number_format((float) ($row['net'] ?? 0), 2) }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
                <h3 class="text-lg font-semibold text-white">Admin Operations</h3>
                <div class="mt-3 flex flex-col gap-2 text-sm">
                    <a href="/gts-admin-users" class="text-blue-300 hover:text-blue-200">Open Users</a>
                    <a href="/admin/maintenance" class="text-blue-300 hover:text-blue-200">Open Admin Maintenance</a>
                </div>
                <div class="mt-5 rounded-xl border border-white/10 bg-black/20 p-4">
                    <div class="flex items-center justify-between gap-2">
                        <div class="text-sm font-semibold text-white">Site Maintenance Mode</div>
                        <span class="inline-flex items-center rounded-full border px-2 py-1 text-xs font-semibold {{ !empty($siteMaintenanceEnabled) ? 'border-amber-400/40 bg-amber-500/15 text-amber-200' : 'border-emerald-400/40 bg-emerald-500/15 text-emerald-200' }}">
                            {{ !empty($siteMaintenanceEnabled) ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>
                    <p class="mt-2 text-xs text-slate-300">
                        When enabled, all non-admin pages are replaced by a maintenance message.
                    </p>
                    <form method="post" action="/admin-dashboard/site-maintenance" class="mt-3">
                        @csrf
                        <input type="hidden" name="enabled" value="{{ !empty($siteMaintenanceEnabled) ? '0' : '1' }}">
                        <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold text-white {{ !empty($siteMaintenanceEnabled) ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-rose-600 hover:bg-rose-500' }}">
                            {{ !empty($siteMaintenanceEnabled) ? 'Disable Maintenance Mode' : 'Enable Maintenance Mode' }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
