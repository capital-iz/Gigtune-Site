<?php

use App\Http\Controllers\Api\GigTuneAssessmentController;
use App\Http\Controllers\Api\GigTuneAuthController;
use App\Http\Controllers\Api\GigTuneCoreParityController;
use App\Http\Controllers\Api\GigTuneNotificationController;
use App\Http\Controllers\Api\GigTunePaymentWebhookController;
use App\Http\Controllers\Api\GigTunePolicyController;
use App\Http\Controllers\AdminPortalController;
use App\Http\Controllers\LegacyWordPressPathController;
use App\Http\Controllers\SitePageController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::prefix('wp-json/gigtune/v1')
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->group(function (): void {
        Route::prefix('assessment')->group(function (): void {
            Route::get('/health', [GigTuneAssessmentController::class, 'health']);
            Route::post('/keywords/complete', [GigTuneAssessmentController::class, 'completeKeywords']);
        });

        Route::prefix('auth')->group(function (): void {
            Route::post('/login', [GigTuneAuthController::class, 'login']);
            Route::middleware('gigtune.auth')->group(function (): void {
                Route::get('/me', [GigTuneAuthController::class, 'me']);
                Route::post('/logout', [GigTuneAuthController::class, 'logout']);
            });
        });

        Route::middleware('gigtune.auth')
            ->prefix('policy')
            ->group(function (): void {
                Route::get('/status', [GigTunePolicyController::class, 'status']);
                Route::post('/accept', [GigTunePolicyController::class, 'accept']);
            });

        Route::middleware('gigtune.auth')
            ->prefix('notifications')
            ->group(function (): void {
                Route::get('/', [GigTuneNotificationController::class, 'index']);
                Route::post('/{id}/read', [GigTuneNotificationController::class, 'read'])->whereNumber('id');
                Route::post('/{id}/archive', [GigTuneNotificationController::class, 'archive'])->whereNumber('id');
                Route::post('/{id}/unarchive', [GigTuneNotificationController::class, 'unarchive'])->whereNumber('id');
                Route::post('/{id}/delete', [GigTuneNotificationController::class, 'delete'])->whereNumber('id');
            });

        Route::get('/artists', [GigTuneCoreParityController::class, 'artists']);
        Route::get('/artists/{id}', [GigTuneCoreParityController::class, 'artist'])->whereNumber('id');
        Route::get('/filters', [GigTuneCoreParityController::class, 'filters']);
        Route::get('/artists/{id}/availability', [GigTuneCoreParityController::class, 'artistAvailability'])->whereNumber('id');

        Route::get('/bookings', [GigTuneCoreParityController::class, 'bookings']);
        Route::post('/bookings', [GigTuneCoreParityController::class, 'createBooking']);
        Route::get('/bookings/{id}', [GigTuneCoreParityController::class, 'booking'])->whereNumber('id');
        Route::post('/booking/payment-status', [GigTuneCoreParityController::class, 'bookingPaymentStatus']);
        Route::post('/bookings/{id}/costs', [GigTuneCoreParityController::class, 'bookingCosts'])->whereNumber('id');
        Route::post('/bookings/{id}/distance', [GigTuneCoreParityController::class, 'bookingDistance'])->whereNumber('id');
        Route::post('/bookings/{id}/accommodation', [GigTuneCoreParityController::class, 'bookingAccommodation'])->whereNumber('id');

        Route::match(['GET', 'POST'], '/client-ratings', [GigTuneCoreParityController::class, 'clientRatings']);
        Route::get('/clients/{id}/rating-summary', [GigTuneCoreParityController::class, 'clientRatingSummary'])->whereNumber('id');
        Route::get('/clients/{id}/ratings', [GigTuneCoreParityController::class, 'clientRatingsByClient'])->whereNumber('id');
        Route::get('/clients/{id}/profile', [GigTuneCoreParityController::class, 'clientProfile'])->whereNumber('id');
        Route::get('/clients/{id}/psas', [GigTuneCoreParityController::class, 'clientPsas'])->whereNumber('id');
        Route::get('/clients/me/profile', [GigTuneCoreParityController::class, 'clientMeProfile']);

        Route::match(['GET', 'POST'], '/psas', [GigTuneCoreParityController::class, 'psas']);
        Route::match(['GET', 'POST'], '/psas/{id}', [GigTuneCoreParityController::class, 'psa'])->whereNumber('id');
        Route::post('/psas/{id}/close', [GigTuneCoreParityController::class, 'psaClose'])->whereNumber('id');

        Route::get('/yoco/webhook', [GigTunePaymentWebhookController::class, 'yocoHealth']);
        Route::post('/yoco/webhook', [GigTunePaymentWebhookController::class, 'yocoWebhook']);
        Route::post('/paystack/webhook', [GigTunePaymentWebhookController::class, 'paystackWebhook']);
    });

Route::get('/secret-admin-login-security', [AdminPortalController::class, 'loginForm']);
Route::post('/secret-admin-login-security', [AdminPortalController::class, 'login']);

Route::prefix('admin')->group(function (): void {
    Route::get('/login', fn () => redirect('/secret-admin-login-security', 302));
    Route::post('/login', fn () => redirect('/secret-admin-login-security', 302));
    Route::post('/logout', [AdminPortalController::class, 'logout'])->middleware('gigtune.auth');

    Route::middleware(['gigtune.auth', 'gigtune.admin'])->group(function (): void {
        Route::get('/maintenance', [AdminPortalController::class, 'maintenance']);
        Route::post('/maintenance/factory-reset', [AdminPortalController::class, 'factoryReset']);
    });
});

Route::middleware(['gigtune.auth', 'gigtune.admin'])->group(function (): void {
    Route::get('/admin-dashboard', [AdminPortalController::class, 'dashboard']);
    Route::get('/admin-dashboard/{tab}', [AdminPortalController::class, 'dashboard'])
        ->whereIn('tab', ['overview', 'users', 'compliance', 'payments', 'payouts', 'bookings', 'disputes', 'refunds', 'kyc', 'reports']);
    Route::post('/admin-dashboard/compliance/apply', [AdminPortalController::class, 'applyComplianceOverride']);
    Route::post('/admin-dashboard/payments/review', [AdminPortalController::class, 'reviewPayment']);
    Route::post('/admin-dashboard/payouts/review', [AdminPortalController::class, 'reviewPayout']);
    Route::post('/admin-dashboard/bookings/request-refund', [AdminPortalController::class, 'requestBookingRefund']);
    Route::post('/admin-dashboard/disputes/review', [AdminPortalController::class, 'reviewDispute']);
    Route::post('/admin-dashboard/refunds/review', [AdminPortalController::class, 'reviewRefund']);
    Route::post('/admin-dashboard/kyc/review', [AdminPortalController::class, 'reviewKyc']);
    Route::post('/admin-dashboard/kyc/purge-deleted', [AdminPortalController::class, 'purgeDeletedKycSubmission']);
    Route::get('/admin-dashboard/kyc/documents/{submission}/{doc}/{mode}', [AdminPortalController::class, 'kycDocument'])
        ->whereNumber('submission')
        ->where('doc', '[A-Za-z0-9_-]+')
        ->whereIn('mode', ['preview', 'download']);
    Route::post('/admin-dashboard/users/hard-delete', [AdminPortalController::class, 'deleteUserFromDashboard']);

    Route::get('/gts-admin-users', [AdminPortalController::class, 'users']);
    Route::post('/gts-admin-users/users', [AdminPortalController::class, 'createUser']);
    Route::post('/gts-admin-users/users/{id}/roles', [AdminPortalController::class, 'updateUserRoles'])
        ->whereNumber('id');
    Route::post('/gts-admin-users/users/{id}/delete', [AdminPortalController::class, 'deleteUser'])
        ->whereNumber('id');

    // Compatibility aliases
    Route::post('/admin-dashboard/users', [AdminPortalController::class, 'createUser']);
    Route::post('/admin-dashboard/users/{id}/roles', [AdminPortalController::class, 'updateUserRoles'])->whereNumber('id');
    Route::post('/admin-dashboard/users/{id}/delete', [AdminPortalController::class, 'deleteUser'])->whereNumber('id');
});

Route::any('/wp-admin/{path?}', [LegacyWordPressPathController::class, 'wpAdmin'])->where('path', '.*');
Route::match(['GET', 'POST'], '/wp-login.php', [LegacyWordPressPathController::class, 'wpLogin'])
    ->withoutMiddleware([ValidateCsrfToken::class]);
Route::match(['GET', 'POST'], '/wp-login.php/{path}', function (string $path) {
    $normalized = '/' . ltrim(trim($path), '/');
    if ($normalized === '/' || str_starts_with($normalized, '//')) {
        return redirect('/sign-in/', 302);
    }
    return redirect($normalized, 302);
})->where('path', '.*')->withoutMiddleware([ValidateCsrfToken::class]);
Route::match(['GET', 'POST'], '/admin-ajax.php', [LegacyWordPressPathController::class, 'adminAjax'])
    ->withoutMiddleware([ValidateCsrfToken::class]);
Route::match(['GET', 'POST'], '/wp-admin/admin-ajax.php', [LegacyWordPressPathController::class, 'adminAjax'])
    ->withoutMiddleware([ValidateCsrfToken::class]);
Route::get('/favicon.ico', fn () => redirect('/wp-content/themes/gigtune-canon/assets/img/gigtune-logo-bp.png', 302));

Route::get('/', [SitePageController::class, 'home']);
Route::any('/{path?}', [SitePageController::class, 'page'])
    ->where('path', '.*')
    ->withoutMiddleware([ValidateCsrfToken::class]);
