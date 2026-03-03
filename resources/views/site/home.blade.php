@extends('layouts.site', ['title' => 'GigTune'])

@section('content')
    <section class="relative overflow-hidden rounded-3xl border border-white/10 bg-slate-900/60 p-8 md:p-14">
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(59,130,246,.25),transparent_55%)]"></div>
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_bottom_left,rgba(168,85,247,.2),transparent_55%)]"></div>
        <div class="relative z-10 max-w-3xl">
            <span class="inline-flex rounded-full border border-blue-400/30 bg-blue-500/10 px-3 py-1 text-xs font-semibold text-blue-200">The New Standard for Live Bookings</span>
            <h1 class="mt-5 text-4xl font-bold leading-tight text-white md:text-6xl">
                Book reliable talent.<br>
                <span class="bg-gradient-to-r from-blue-400 to-purple-500 bg-clip-text text-transparent">Without the guesswork.</span>
            </h1>
            <p class="mt-4 text-base text-slate-300 md:text-lg">
                GigTune matches you with verified artists and protects payments in temporary holding until performance completion.
            </p>
            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <a href="/browse-artists" class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-blue-600 to-purple-600 px-6 py-3 text-sm font-semibold text-white hover:from-blue-500 hover:to-purple-500">Find an Artist</a>
                <a href="/join" class="inline-flex items-center justify-center rounded-xl border border-white/15 bg-white/10 px-6 py-3 text-sm font-semibold text-white hover:bg-white/15">Join as Pro</a>
            </div>
        </div>
    </section>

    <section class="mt-8 grid gap-4 md:grid-cols-4">
        <div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">100% verified artists</div>
        <div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">Secure temporary holding</div>
        <div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">Manual payout controls</div>
        <div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">Dispute and refund controls</div>
    </section>
@endsection
