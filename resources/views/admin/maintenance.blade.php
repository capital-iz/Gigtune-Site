@extends('layouts.admin', ['title' => 'Admin Maintenance', 'currentUser' => request()->attributes->get('gigtune_user')])

@section('content')
    <section class="rounded-xl border border-white/10 bg-white/5 p-6">
        <h1 class="text-2xl font-bold text-white">Admin Maintenance</h1>
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

