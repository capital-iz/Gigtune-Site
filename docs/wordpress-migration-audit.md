# GigTune WordPress Migration Audit

Date: 2026-03-02

## Source Scope Read
- WordPress root: `C:\Users\reama\Local Sites\gigtune\app\public`
- File count read from root: `8164`
- Upload files present: `497`
- Primary code scanned:
  - `wp-content/plugins/gigtune-core/gigtune-core.php`
  - `wp-content/plugins/gigtune-core/includes/assessment.php`
  - `wp-content/themes/gigtune-canon/*` (source templates/assets)
  - `app/sql/local.sql` (current local dataset)

## Active Runtime (from local.sql)
- Active theme: `gigtune-canon`
- Active plugins:
  - `gigtune-core/gigtune-core.php`
  - `headers-security-advanced-hsts-wp/headers-security-advanced-hsts-wp.php`
  - `html-sitemap/html-sitemap.php`
  - `indexnow/indexnow-url-submission.php`
  - `litespeed-cache/litespeed-cache.php`

## Core Feature Surface Identified

### Marketplace Roles and Capabilities
- Roles: `gigtune_artist`, `gigtune_client`, admin-enhanced capabilities
- Capability logic includes `gigtune_manage_payments`
- Admin dashboard and maintenance flows are custom frontend/app flows (not only WP admin)

### Custom Post Types
- `artist_profile`
- `gigtune_notification`
- `gigtune_message`
- `gigtune_booking`
- `gt_payment`
- `gt_payout`
- `gt_client_rating`
- `gt_client_profile`
- `gigtune_psa`
- `gigtune_admin_log`
- `gt_kyc_submission`
- `gigtune_dispute`

### Taxonomies
- `performer_type`
- `instrument_category`
- `keyboard_parts`
- `vocal_type`
- `vocal_role`

### Payment and Financial Flows
- Paystack integration (config + webhook handling)
- Yoco integration (checkout, webhook verification, idempotency, refund lifecycle)
- Booking payment statuses, escrow/holding-style flow, payout admin handling, refund review paths

### Compliance and Security Flows
- Policy consent/version gating (`terms`, `aup`, `privacy`, `refund`)
- KYC submission + document storage + review decision system
- Security centre data and login tracking
- Rate limiting for booking, disputes, messages, payments, registration, password flows

### Messaging, Notifications, Disputes
- Booking-linked messages
- Notification feed/bell/archive/settings
- Dispute creation and admin resolution paths

## Theme/Page Surface (gigtune-canon)
- Custom page templates include (non-exhaustive):  
  `about-us`, `acceptable-use-policy`, `admin-maintenance`, `artist-dashboard`, `artist-profile`, `book-an-artist`, `browse-artists`, `client-dashboard`, `kyc`, `kyc-status`, `messages`, `notifications`, `notification-settings`, `my-account-page`, `public-profile`, `register`, `security-centre`, `terms-and-conditions`, etc.
- PWA/service worker and manifest are theme-managed.
- Front-page composes plugin shortcodes into role-aware dashboard layout.

### Registered GigTune Shortcodes (core)
- `gigtune_admin_dashboard`
- `gigtune_admin_maintenance`
- `gigtune_admin_login`
- `gigtune_sign_out`
- `gigtune_verify_email`
- `gigtune_forgot_password`
- `gigtune_reset_password`
- `gigtune_policy_consent`
- `gigtune_kyc_form`
- `gigtune_kyc_status`
- `gigtune_security_centre`
- `gigtune_client_dashboard`
- `gigtune_artist_dashboard`
- `gigtune_book_artist`
- `gigtune_booking_messages`
- `gigtune_notifications`
- `gigtune_notifications_archive`
- `gigtune_notification_settings`
- `gigtune_notifications_bell`
- `gigtune_artist_directory`
- `gigtune_client_directory`
- `gigtune_public_artist_profile`
- `gigtune_public_client_profile`
- `gigtune_account_portal`
- plus additional snapshot/feed/profile/edit/admin return shortcodes

### REST API Surface (gigtune/v1)
- Artist directory/profile/filter/availability endpoints
- Booking CRUD + payment status/cost/distance/accommodation endpoints
- Notifications endpoints (read/archive/unarchive/delete)
- Client profile/ratings endpoints
- PSA endpoints (create/update/close/list/detail)
- Webhooks:
  - `/gigtune/v1/paystack/webhook`
  - `/gigtune/v1/yoco/webhook`
- Assessment module:
  - `/gigtune/v1/assessment/health`
  - `/gigtune/v1/assessment/keywords/complete`

## Migration Risk Hotspots
- `gigtune-core.php` is large and stateful (~27k lines): heavily WP-hook driven.
- Payment + webhook security logic must be preserved exactly.
- KYC document privacy and admin review workflows are compliance-critical.
- Existing role/capability semantics drive access control across dashboards.
- Some config/secrets are hardcoded in WP config and should be moved to Laravel env before production cutover.

## Current Status in Laravel
- Laravel now serves all routes through a WordPress bridge proxy for immediate functional continuity while native module migration proceeds.
- Native Laravel parity has started for the `assessment` module routes:
  - `GET /wp-json/gigtune/v1/assessment/health`
  - `POST /wp-json/gigtune/v1/assessment/keywords/complete`
- Native Laravel parity now also includes WordPress DB-backed auth + policy routes:
  - `POST /wp-json/gigtune/v1/auth/login`
  - `GET /wp-json/gigtune/v1/auth/me`
  - `POST /wp-json/gigtune/v1/auth/logout`
  - `GET /wp-json/gigtune/v1/policy/status`
  - `POST /wp-json/gigtune/v1/policy/accept`
- Phase 3 native migration started for notifications:
  - `GET /wp-json/gigtune/v1/notifications`
  - `POST /wp-json/gigtune/v1/notifications/{id}/read`
  - `POST /wp-json/gigtune/v1/notifications/{id}/archive`
  - `POST /wp-json/gigtune/v1/notifications/{id}/unarchive`
  - `POST /wp-json/gigtune/v1/notifications/{id}/delete`
- Phase 4 admin cutover:
  - Laravel admin gateway routes:
    - `GET /secret-admin-login-security`
    - `GET /admin-dashboard`
    - `GET /gts-admin-users`
    - `GET /admin/maintenance`
  - Laravel admin account operations (no wp-admin required):
    - create/list/update-role/delete user from `/admin-dashboard`
    - bootstrap first admin via `php artisan gigtune:admin-bootstrap ...`
  - WordPress admin path replacement:
    - `/wp-admin/*` redirected to `/secret-admin-login-security`
    - `/wp-login.php` redirected to `/secret-admin-login-security` (logout action mapped to Laravel session logout)
  - Live SQL import utility:
    - `php artisan gigtune:db-import <dump.sql> --database=<name> --drop-existing`
- Bridge execution upgrade:
  - Added `cgi` execution mode so Laravel can execute WordPress directly via `php-cgi` from the local WordPress root.
  - Validated key pages and APIs on `:8002` with `:8010` offline.
- Bridge status verified:
  - `GET /` through Laravel: `200`
  - `GET /client-dashboard/` through Laravel: `200`
  - `GET /wp-json/gigtune/v1/assessment/health` through Laravel: `200`
