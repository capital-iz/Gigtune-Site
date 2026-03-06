@extends('layouts.admin', ['title' => 'Administrator Users', 'currentUser' => $currentUser ?? null])

@section('content')
    <div class="grid gap-4 md:grid-cols-5">
        <div class="rounded-xl border border-white/10 bg-white/5 p-4">
            <div class="text-xs uppercase tracking-wide text-slate-400">Users</div>
            <div class="mt-2 text-2xl font-semibold text-white">{{ $metrics['users_total'] }}</div>
        </div>
        <div class="rounded-xl border border-white/10 bg-white/5 p-4">
            <div class="text-xs uppercase tracking-wide text-slate-400">Bookings</div>
            <div class="mt-2 text-2xl font-semibold text-white">{{ $metrics['bookings_total'] }}</div>
        </div>
        <div class="rounded-xl border border-white/10 bg-white/5 p-4">
            <div class="text-xs uppercase tracking-wide text-slate-400">Notifications</div>
            <div class="mt-2 text-2xl font-semibold text-white">{{ $metrics['notifications_total'] }}</div>
        </div>
        <div class="rounded-xl border border-white/10 bg-white/5 p-4">
            <div class="text-xs uppercase tracking-wide text-slate-400">KYC Submissions</div>
            <div class="mt-2 text-2xl font-semibold text-white">{{ $metrics['kyc_total'] }}</div>
        </div>
        <div class="rounded-xl border border-white/10 bg-white/5 p-4">
            <div class="text-xs uppercase tracking-wide text-slate-400">Pending Payouts</div>
            <div class="mt-2 text-2xl font-semibold text-white">{{ $metrics['pending_payouts'] }}</div>
        </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-white/10 bg-white/5 p-5">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-white">Create User</h2>
                <a href="/admin-dashboard" class="rounded-lg border border-white/10 bg-white/10 px-3 py-1.5 text-xs text-white hover:bg-white/15">Back to Dashboard</a>
            </div>
            <p class="mt-1 text-sm text-slate-300">Create admin, artist, or client accounts without WordPress admin.</p>
            <form class="mt-4 space-y-3" method="post" action="/gts-admin-users/users">
                @csrf
                <div>
                    <label class="mb-1 block text-sm text-slate-200">Username</label>
                    <input name="login" required class="w-full rounded-lg border border-white/10 bg-slate-950/50 px-3 py-2 text-white" value="{{ old('login') }}">
                </div>
                <div>
                    <label class="mb-1 block text-sm text-slate-200">Email</label>
                    <input name="email" type="email" required class="w-full rounded-lg border border-white/10 bg-slate-950/50 px-3 py-2 text-white" value="{{ old('email') }}">
                </div>
                <div>
                    <label class="mb-1 block text-sm text-slate-200">Display Name</label>
                    <input name="display_name" class="w-full rounded-lg border border-white/10 bg-slate-950/50 px-3 py-2 text-white" value="{{ old('display_name') }}">
                </div>
                <div>
                    <label class="mb-1 block text-sm text-slate-200">Password</label>
                    <input name="password" type="password" required class="w-full rounded-lg border border-white/10 bg-slate-950/50 px-3 py-2 text-white">
                </div>
                <div>
                    <div class="mb-1 block text-sm text-slate-200">Roles</div>
                    @foreach (['administrator' => 'Administrator', 'gigtune_artist' => 'Artist', 'gigtune_client' => 'Client'] as $roleKey => $roleLabel)
                        <label class="mb-1 flex items-center gap-2 text-sm text-slate-200">
                            <input type="checkbox" name="roles[]" value="{{ $roleKey }}" @checked(in_array($roleKey, old('roles', ['gigtune_client']), true))>
                            <span>{{ $roleLabel }}</span>
                        </label>
                    @endforeach
                </div>
                <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-gradient-to-r from-blue-600 to-purple-600 px-4 py-2 text-sm font-semibold text-white hover:from-blue-500 hover:to-purple-500">
                    Create User
                </button>
            </form>
        </section>

        <section class="rounded-xl border border-white/10 bg-white/5 p-5">
            <h2 class="text-lg font-semibold text-white">Quick Links</h2>
            <p class="mt-1 text-sm text-slate-300">Use administrator routes instead of wp-admin.</p>
            <div class="mt-3 flex flex-col gap-2 text-sm">
                <a href="/admin-dashboard" class="text-blue-300 hover:text-blue-200">Admin Dashboard</a>
                <a href="/admin-dashboard/payments" class="text-blue-300 hover:text-blue-200">Payments Queue</a>
                <a href="/admin-dashboard/payouts" class="text-blue-300 hover:text-blue-200">Payouts Queue</a>
                <a href="/admin-dashboard/kyc" class="text-blue-300 hover:text-blue-200">Identity Verification Queue</a>
                <a href="/admin/maintenance" class="text-blue-300 hover:text-blue-200">Admin Maintenance</a>
            </div>
        </section>
    </div>

    <section class="mt-6 rounded-xl border border-white/10 bg-white/5 p-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-white">User Management</h2>
                <p class="mt-1 text-sm text-slate-300">Manage roles and remove accounts from the Administrator panel.</p>
            </div>
            <form class="flex flex-wrap items-end gap-2" method="get" action="/gts-admin-users">
                <div>
                    <label class="mb-1 block text-xs uppercase tracking-wide text-slate-400">Search</label>
                    <input name="search" value="{{ $search }}" class="rounded-lg border border-white/10 bg-slate-950/50 px-3 py-2 text-sm text-white">
                </div>
                <div>
                    <label class="mb-1 block text-xs uppercase tracking-wide text-slate-400">Role</label>
                    <select name="role" class="rounded-lg border border-white/10 bg-slate-950/50 px-3 py-2 text-sm text-white">
                        <option value="">All</option>
                        <option value="administrator" @selected($roleFilter === 'administrator')>Administrator</option>
                        <option value="gigtune_artist" @selected($roleFilter === 'gigtune_artist')>Artist</option>
                        <option value="gigtune_client" @selected($roleFilter === 'gigtune_client')>Client</option>
                    </select>
                </div>
                <button type="submit" class="rounded-lg border border-white/10 bg-white/10 px-3 py-2 text-sm text-white hover:bg-white/15">Filter</button>
            </form>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="text-slate-300">
                    <tr>
                        <th class="px-3 py-2">ID</th>
                        <th class="px-3 py-2">Username</th>
                        <th class="px-3 py-2">Email</th>
                        <th class="px-3 py-2">Roles</th>
                        <th class="px-3 py-2">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users['items'] as $entry)
                        <tr class="border-t border-white/10">
                            <td class="px-3 py-2 text-slate-300">{{ $entry['id'] }}</td>
                            <td class="px-3 py-2 text-white">{{ $entry['login'] }}</td>
                            <td class="px-3 py-2 text-slate-200">{{ $entry['email'] }}</td>
                            <td class="px-3 py-2 text-slate-200">{{ implode(', ', $entry['roles']) ?: 'none' }}</td>
                            <td class="px-3 py-2">
                                <form class="mb-2 flex flex-wrap items-center gap-2" method="post" action="/gts-admin-users/users/{{ $entry['id'] }}/roles">
                                    @csrf
                                    @foreach (['administrator', 'gigtune_artist', 'gigtune_client'] as $roleName)
                                        <label class="flex items-center gap-1 text-xs text-slate-200">
                                            <input type="checkbox" name="roles[]" value="{{ $roleName }}" @checked(in_array($roleName, $entry['roles'], true))>
                                            <span>{{ $roleName }}</span>
                                        </label>
                                    @endforeach
                                    <button type="submit" class="rounded-md border border-white/10 bg-white/10 px-2 py-1 text-xs text-white hover:bg-white/15">Save Roles</button>
                                </form>
                                <form method="post" action="/gts-admin-users/users/{{ $entry['id'] }}/delete" onsubmit="return confirm('Delete this user?');">
                                    @csrf
                                    <button type="submit" class="rounded-md border border-rose-500/30 bg-rose-500/10 px-2 py-1 text-xs text-rose-200 hover:bg-rose-500/20">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    @if (empty($users['items']))
                        <tr>
                            <td colspan="5" class="px-3 py-6 text-center text-slate-400">No users found.</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>

        @php
            $page = (int) $users['page'];
            $totalPages = (int) $users['total_pages'];
            $query = array_filter(['search' => $search, 'role' => $roleFilter]);
            $prevQuery = http_build_query(array_merge($query, ['page' => max(1, $page - 1)]));
            $nextQuery = http_build_query(array_merge($query, ['page' => min($totalPages > 0 ? $totalPages : 1, $page + 1)]));
        @endphp
        <div class="mt-4 flex items-center justify-between text-sm text-slate-300">
            <div>Page {{ $page }} of {{ max(1, $totalPages) }} ({{ $users['total'] }} users)</div>
            <div class="flex items-center gap-2">
                <a href="/gts-admin-users?{{ $prevQuery }}" class="rounded-md border border-white/10 bg-white/10 px-3 py-1 hover:bg-white/15">Prev</a>
                <a href="/gts-admin-users?{{ $nextQuery }}" class="rounded-md border border-white/10 bg-white/10 px-3 py-1 hover:bg-white/15">Next</a>
            </div>
        </div>
    </section>
@endsection
