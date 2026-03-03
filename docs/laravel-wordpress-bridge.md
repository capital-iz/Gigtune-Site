# Laravel WordPress Bridge Runbook

## Purpose
Serve the existing WordPress/GigTune functionality through Laravel (`:8002`) while native Laravel modules are implemented incrementally.

## Config
Environment variables:
- `GIGTUNE_WORDPRESS_BRIDGE_ENABLED=true`
- `GIGTUNE_WORDPRESS_BASE_URL=http://127.0.0.1:8010`
- `GIGTUNE_WORDPRESS_ROOT="C:/Users/reama/Local Sites/gigtune/app/public"`
- `GIGTUNE_WORDPRESS_TIMEOUT=180`
- `GIGTUNE_WORDPRESS_EXECUTION_MODE=http|cgi` (`cgi` recommended default)
- `GIGTUNE_WORDPRESS_CGI_BINARY=php-cgi` (used when mode is `cgi`)

Config file:
- `config/gigtune.php`

## Start Order
1. Start MySQL for the Local site runtime (port `10018`) if not running.
2. Choose bridge execution mode:
   - `http` mode: run WordPress backend `php artisan gigtune:wp-serve --host=127.0.0.1 --port=8010`
   - `cgi` mode: no extra WordPress web server required; Laravel executes WordPress via `php-cgi`
3. Start Laravel frontend:
   - `php artisan serve --host=127.0.0.1 --port=8002`

## Health Checks
- Backend ping:
  - `php artisan gigtune:wp-ping` (HTTP mode only)
- Site check via Laravel:
  - `http://127.0.0.1:8002/`
- Core API check via Laravel:
  - `http://127.0.0.1:8002/wp-json/gigtune/v1/assessment/health`

## Implementation Notes
- Controller: `app/Http/Controllers/WordPressProxyController.php`
- Route catch-all: `routes/web.php`
- CSRF middleware is bypassed for proxy route so WordPress POST flows work unchanged.
- Request methods, cookies, form-data, multipart uploads, and headers are forwarded.
- Upstream URLs are rewritten from backend origin (`:8010`) to Laravel origin (`:8002`) in response headers/body.
- In `cgi` mode, Laravel executes WordPress directly with `php-cgi` from `GIGTUNE_WORDPRESS_ROOT`, so `:8010` is not required.
- First native module ported in Laravel (no proxy):
  - `GET /wp-json/gigtune/v1/assessment/health`
  - `POST /wp-json/gigtune/v1/assessment/keywords/complete`
- Phase 2 native auth/policy routes (WordPress DB-backed, no proxy):
  - `POST /wp-json/gigtune/v1/auth/login`
  - `GET /wp-json/gigtune/v1/auth/me` (requires Laravel session auth)
  - `POST /wp-json/gigtune/v1/auth/logout` (requires Laravel session auth)
  - `GET /wp-json/gigtune/v1/policy/status` (requires Laravel session auth)
  - `POST /wp-json/gigtune/v1/policy/accept` (requires Laravel session auth)
- Phase 3 native notifications routes (WordPress DB-backed, no proxy):
  - `GET /wp-json/gigtune/v1/notifications` (requires Laravel session auth)
  - `POST /wp-json/gigtune/v1/notifications/{id}/read`
  - `POST /wp-json/gigtune/v1/notifications/{id}/archive`
  - `POST /wp-json/gigtune/v1/notifications/{id}/unarchive`
  - `POST /wp-json/gigtune/v1/notifications/{id}/delete`
- Admin cutover routes:
  - `GET /secret-admin-login-security`
  - `GET /admin-dashboard` (Laravel-native)
  - `GET /gts-admin-users` (Laravel-native user management)
  - `GET /admin/maintenance` (Laravel-native)
- Admin bootstrap command:
  - `php artisan gigtune:admin-bootstrap <login> <email> <password> --name="Admin Name"`
- Live dump import command:
  - `php artisan gigtune:db-import <path-to-sql> --database=<db-name> --drop-existing`
- WordPress admin paths:
  - `/wp-admin/*` and `/wp-login.php` now redirect to `/secret-admin-login-security`
