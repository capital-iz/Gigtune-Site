@extends('layouts.admin', ['title' => 'Admin Maintenance', 'currentUser' => request()->attributes->get('gigtune_user')])

@section('content')
    @php
        $displayResetItems = is_array($displayResetItems ?? null) ? $displayResetItems : [];
    @endphp
    <section class="mt-6 rounded-xl border border-white/10 bg-white/5 p-6">
        <h1 class="text-2xl font-bold text-white">Admin Maintenance</h1>
        <p class="mt-2 text-sm text-slate-300">
            Reset tab display data without deleting records. This only moves each selected admin tab to a fresh baseline.
        </p>
        <div class="mt-4 rounded-lg border border-sky-500/30 bg-sky-500/10 p-4 text-sm text-sky-200">
            This is non-destructive. Historical records stay in the database and can still be queried manually.
        </div>

        <form class="mt-5 max-w-3xl space-y-4" method="post" action="/admin/maintenance/reset-display-data">
            @csrf
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($displayResetItems as $item)
                    <label class="flex items-start gap-3 rounded-lg border border-white/10 bg-slate-950/40 px-3 py-2 text-sm text-slate-100">
                        <input type="checkbox" name="targets[]" value="{{ $item['key'] ?? '' }}" class="mt-1 h-4 w-4 rounded border-white/20 bg-slate-900 text-sky-500 focus:ring-sky-500">
                        <span>
                            <span class="block font-medium text-white">{{ $item['label'] ?? '-' }}</span>
                            <span class="block text-xs text-slate-400">
                                Last reset:
                                {{ ($item['last_reset_at'] ?? null) ? $item['last_reset_at'] : 'Never' }}
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
            <div>
                <label class="mb-1 block text-sm text-slate-200">Admin password</label>
                <input name="password" type="password" required
                    class="w-full rounded-lg border border-white/10 bg-slate-950/50 px-3 py-2 text-white">
            </div>
            <button type="submit"
                class="inline-flex items-center justify-center rounded-lg bg-gradient-to-r from-sky-600 to-blue-600 px-4 py-2 text-sm font-semibold text-white hover:from-sky-500 hover:to-blue-500">
                Reset Selected Display Tabs
            </button>
        </form>
    </section>

    <section class="rounded-xl border border-white/10 bg-white/5 p-6">
        <h2 class="text-2xl font-bold text-white">Factory Reset</h2>
        <p class="mt-2 text-sm text-slate-300">
            Factory reset removes GigTune operational records from the WordPress-shaped data tables while keeping admin login access.
        </p>
        <div class="mt-4 rounded-lg border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-200">
            This is destructive. Type <strong>RESET GIGTUNE</strong> and confirm your admin password.
        </div>

        <form class="mt-5 max-w-xl space-y-4" method="post" action="/admin/maintenance/factory-reset">
            @csrf
            <div>
                <label class="mb-1 block text-sm text-slate-200">Confirmation phrase</label>
                <input name="confirmation" required placeholder="RESET GIGTUNE"
                    class="w-full rounded-lg border border-white/10 bg-slate-950/50 px-3 py-2 text-white">
            </div>
            <div>
                <label class="mb-1 block text-sm text-slate-200">Admin password</label>
                <input name="password" type="password" required
                    class="w-full rounded-lg border border-white/10 bg-slate-950/50 px-3 py-2 text-white">
            </div>
            <button type="submit"
                class="inline-flex items-center justify-center rounded-lg bg-gradient-to-r from-rose-600 to-red-600 px-4 py-2 text-sm font-semibold text-white hover:from-rose-500 hover:to-red-500">
                Run Factory Reset
            </button>
        </form>
    </section>
@endsection

