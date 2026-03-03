<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'GigTune' }}</title>
    <link rel="stylesheet" href="/wp-content/themes/gigtune-canon/assets/css/tailwind.css">
    <link rel="stylesheet" href="/wp-content/themes/gigtune-canon/style.css">
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <header class="border-b border-white/10 bg-slate-900/90">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
            <a href="/" class="text-lg font-semibold text-white">GigTune</a>
            <div class="flex items-center gap-3 text-sm">
                <a href="/browse-artists" class="text-slate-200 hover:text-white">Browse Artists</a>
                <a href="/book-an-artist" class="text-slate-200 hover:text-white">Book Artist</a>
                <a href="/secret-admin-login-security" class="rounded-md border border-white/10 bg-white/10 px-2.5 py-1 text-slate-100 hover:bg-white/15">Admin</a>
            </div>
        </div>
    </header>

    <main class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        @yield('content')
    </main>
</body>
</html>
