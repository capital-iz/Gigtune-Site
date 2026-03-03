@extends('layouts.site', ['title' => $title ?? 'GigTune'])

@section('content')
    <article class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-10">
        <h1 class="text-3xl font-bold text-white">{{ $title }}</h1>
        <div class="prose prose-invert mt-6 max-w-none text-slate-200">
            {!! $contentHtml !!}
        </div>
    </article>
@endsection
