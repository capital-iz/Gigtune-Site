<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'GigTune Admin' }}</title>
    <link rel="stylesheet" href="/wp-content/themes/gigtune-canon/assets/css/tailwind.css">
    <link rel="stylesheet" href="/wp-content/themes/gigtune-canon/style.css">
    <style>
        select option {
            background-color: #0f172a;
            color: #e2e8f0;
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <header class="sticky top-0 z-50 border-b border-white/10 bg-slate-900/90 backdrop-blur">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
            <div class="flex items-center gap-3">
                <a href="/admin-dashboard" class="text-lg font-semibold text-white">GigTune Admin</a>
                <a href="/gts-admin-users" class="rounded-md border border-white/10 bg-white/10 px-2.5 py-1 text-xs text-slate-100 hover:bg-white/15">Administrator</a>
            </div>
            <div class="flex items-center gap-3 text-sm">
                @if(isset($currentUser) && is_array($currentUser))
                    <span class="text-slate-300">{{ $currentUser['display_name'] ?: $currentUser['login'] }}</span>
                    <form method="post" action="/admin/logout">
                        @csrf
                        <button type="submit" class="rounded-lg border border-white/10 bg-white/10 px-3 py-1.5 text-white hover:bg-white/15">Sign Out</button>
                    </form>
                @endif
            </div>
        </div>
    </header>

    <main class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        @if (session('status'))
            <div class="mb-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                {{ session('status') }}
            </div>
        @endif
        @if ($errors->any())
            <div class="mb-4 rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
                @foreach ($errors->all() as $message)
                    <div>{{ $message }}</div>
                @endforeach
            </div>
        @endif
        @yield('content')
    </main>
</body>
</html>
