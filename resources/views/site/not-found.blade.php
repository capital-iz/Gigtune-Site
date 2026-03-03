@extends('layouts.site', ['title' => 'Page Not Found'])

@section('content')
    <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-6 text-amber-100">
        <h1 class="text-2xl font-semibold">Page not available</h1>
        <p class="mt-3 text-sm">The route <code>{{ $path }}</code> has not been mapped to a native Laravel page yet.</p>
        <p class="mt-2 text-sm">Core admin and API endpoints remain active.</p>
    </div>
@endsection
