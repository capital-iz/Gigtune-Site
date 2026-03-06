<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f172a">
    <title>GigTune Admin Sign-in</title>
    <link rel="icon" type="image/png" sizes="512x512" href="/wp-content/themes/gigtune-canon/assets/img/admin-app-icon-512.png">
    <link rel="manifest" href="/admin-manifest.webmanifest?v=20260306b">
    <link rel="apple-touch-icon" href="/wp-content/themes/gigtune-canon/assets/img/admin-app-icon-192.png">
    <link rel="stylesheet" href="/wp-content/themes/gigtune-canon/assets/css/tailwind.css">
    <link rel="stylesheet" href="/wp-content/themes/gigtune-canon/style.css">
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <main class="min-h-[65vh] px-4 py-10 md:px-6 flex justify-center items-center">
        <div class="w-full max-w-md">
            <div class="rounded-2xl border border-white/10 bg-white/5 p-8">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-2xl font-bold text-white">Admin Sign-in</h3>
                    <span class="inline-flex items-center rounded-full border border-amber-400/40 bg-amber-500/15 px-4 py-1.5 text-xs font-semibold text-amber-200">
                        Administrator Security
                    </span>
                </div>
                <p class="mt-2 text-sm text-slate-300">This page is for GigTune Super administrators only.</p>

                @if ($errors->any())
                    <p class="mt-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-200">
                        {{ $errors->first() }}
                    </p>
                @endif

                <div class="mt-6">
                    <form method="post" action="/secret-admin-login-security" class="space-y-4">
                        @csrf
                        <input type="hidden" name="redirect_to" value="/admin-dashboard">

                        <div>
                            <label class="block text-sm font-semibold text-slate-200 mb-2" for="gigtune_admin_login_user">Email or Username</label>
                            <input id="gigtune_admin_login_user" type="text" name="identifier" required value="{{ old('identifier') }}"
                                class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white" />
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-200 mb-2" for="gigtune_admin_login_pass">Password</label>
                            <input id="gigtune_admin_login_pass" type="password" name="password" required
                                class="w-full rounded-xl bg-slate-950/50 border border-white/10 px-4 py-3 text-white" />
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <label class="inline-flex items-center gap-2 text-sm text-slate-300">
                                <input type="checkbox" checked disabled />
                                Secure session
                            </label>
                            <a class="text-sm text-blue-300 hover:text-blue-200" href="/">Back to site</a>
                        </div>

                        <button type="submit" class="w-full inline-flex items-center justify-center rounded-xl px-5 py-3 font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">
                            Sign In
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </main>
    <script>
        (function () {
            if (!('serviceWorker' in navigator)) return;
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/service-worker.js', { scope: '/' }).catch(function () { return null; });
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
            notificationsEnabled: false,
            userId: 0,
            isAdmin: true,
            pushEnabled: false,
            pollIntervalMs: 20000
        });
    </script>
    <script src="/wp-content/themes/gigtune-canon/assets/js/gigtune-live.js?v=20260306f"></script>
</body>
</html>
