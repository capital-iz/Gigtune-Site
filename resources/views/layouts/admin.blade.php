<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f172a">
    <title>{{ $title ?? 'GigTune Admin' }}</title>
    <link rel="icon" type="image/png" sizes="512x512" href="/wp-content/themes/gigtune-canon/assets/img/admin-app-icon-512.png">
    <link rel="manifest" href="/admin-manifest.webmanifest?v=20260306a">
    <link rel="apple-touch-icon" href="/wp-content/themes/gigtune-canon/assets/img/admin-app-icon-192.png">
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
                <a href="/admin-dashboard" class="rounded-md border border-white/10 bg-white/10 px-2.5 py-1 text-xs text-slate-100 hover:bg-white/15 inline-flex items-center gap-2">
                    <span class="gt-live-notification-label">Notifications</span>
                </a>
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
    @php
        $liveUser = is_array($currentUser ?? null) ? $currentUser : null;
        $liveUserId = (int) ($liveUser['id'] ?? 0);
    @endphp
    <script>
        (function () {
            if (!('serviceWorker' in navigator)) return;
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/service-worker.js', { scope: '/admin-dashboard/' }).catch(function () { return null; });
            });
        })();
    </script>
    <script>
        window.GigTuneLiveConfig = Object.assign({}, window.GigTuneLiveConfig || {}, {
            appId: 'gigtune-admin',
            appName: 'GigTune Admin',
            installEnabled: true,
            installPromptLabel: 'Install GigTune Admin App',
            alertsToggleLabel: 'Enable Admin Alerts',
            notificationsEnabled: {{ $liveUserId > 0 ? 'true' : 'false' }},
            userId: {{ $liveUserId }},
            isAdmin: true,
            pushEnabled: {{ $liveUserId > 0 ? 'true' : 'false' }},
            pushConfigEndpoint: '/wp-json/gigtune/v1/push/config',
            pushSubscribeEndpoint: '/wp-json/gigtune/v1/push/subscribe',
            pushUnsubscribeEndpoint: '/wp-json/gigtune/v1/push/unsubscribe',
            pollEndpoint: '/wp-json/gigtune/v1/notifications?per_page=12&page=1&only_unread=1&include_archived=0',
            pollIntervalMs: 20000
        });
    </script>
    <script src="/wp-content/themes/gigtune-canon/assets/js/gigtune-live.js?v=20260306e"></script>
</body>
</html>
