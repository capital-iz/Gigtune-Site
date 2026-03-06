<?php

namespace App\Http\Controllers;

use App\Services\GigTuneMailService;
use App\Services\GigTuneSiteMaintenanceService;
use App\Services\WordPressUserService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminPortalController extends Controller
{
    private const DISPLAY_RESET_OPTIONS = [
        'payments' => 'gigtune_admin_display_reset_payments_at',
        'payouts' => 'gigtune_admin_display_reset_payouts_at',
        'bookings' => 'gigtune_admin_display_reset_bookings_at',
        'disputes' => 'gigtune_admin_display_reset_disputes_at',
        'refunds' => 'gigtune_admin_display_reset_refunds_at',
        'kyc' => 'gigtune_admin_display_reset_kyc_at',
        'reports' => 'gigtune_admin_display_reset_reports_at',
    ];

    private const DISPLAY_RESET_LABELS = [
        'payments' => 'Payments',
        'payouts' => 'Payouts',
        'bookings' => 'Bookings',
        'disputes' => 'Disputes',
        'refunds' => 'Refunds',
        'kyc' => 'KYC',
        'reports' => 'Reports',
    ];

    public function __construct(
        private readonly WordPressUserService $users,
        private readonly GigTuneMailService $mail,
        private readonly GigTuneSiteMaintenanceService $siteMaintenance,
    ) {
    }

    public function loginForm(Request $request): View|RedirectResponse
    {
        $existing = $request->session()->get('gigtune_auth_user_id');
        if ((int) $existing > 0) {
            $user = $this->users->getUserById((int) $existing);
            if (is_array($user) && (bool) ($user['is_admin'] ?? false)) {
                return redirect('/admin-dashboard');
            }
        }

        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $identifier = trim((string) $request->input('identifier', ''));
        $password = (string) $request->input('password', '');

        if ($identifier === '' || $password === '') {
            return back()->withErrors(['login' => 'Username/email and password are required.'])->withInput();
        }

        $user = $this->users->verifyCredentials($identifier, $password);
        if ($user === null || !((bool) ($user['is_admin'] ?? false))) {
            return back()->withErrors(['login' => 'Administrator credentials are invalid.'])->withInput();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('gigtune_auth_user_id', (int) $user['id']);
        $request->session()->put('gigtune_auth_logged_in_at', now()->toIso8601String());
        $request->session()->put('gigtune_auth_remember', true);

        return redirect('/admin-dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget([
            'gigtune_auth_user_id',
            'gigtune_auth_logged_in_at',
            'gigtune_auth_remember',
        ]);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/secret-admin-login-security');
    }

    public function dashboard(Request $request, ?string $tab = null): View
    {
        $requestedTab = $tab !== null && $tab !== ''
            ? $tab
            : (string) $request->query('tab', 'overview');
        $activeTab = $this->resolveDashboardTab($requestedTab);
        $currentUser = $request->attributes->get('gigtune_user');
        $currentUserId = (int) (is_array($currentUser) ? ($currentUser['id'] ?? 0) : 0);

        return view('admin.dashboard', [
            'currentUser' => is_array($currentUser) ? $currentUser : null,
            'metrics' => $this->loadMetrics(),
            'activeTab' => $activeTab,
            'tabData' => $this->loadDashboardTabData($activeTab, $request),
            'siteMaintenanceEnabled' => $this->siteMaintenance->isEnabled(),
            'adminNotifications' => $this->loadAdminNotifications($currentUserId, 12),
        ]);
    }

    public function users(Request $request): View
    {
        $viewData = $this->buildUsersViewData($request);
        return view('admin.users', $viewData);
    }

    public function createUser(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'login' => ['required', 'string', 'min:3', 'max:60'],
            'email' => ['required', 'email', 'max:100'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:250'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string'],
        ]);

        try {
            $created = $this->users->createUser($payload);
        } catch (\Throwable $throwable) {
            return back()->withErrors(['user_create' => $throwable->getMessage()])->withInput();
        }

        return redirect('/gts-admin-users')
            ->with('status', 'Created user #' . (int) $created['id'] . ' (' . $created['login'] . ').');
    }

    public function updateUserRoles(Request $request, int $userId): RedirectResponse
    {
        $payload = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string'],
        ]);

        try {
            $updated = $this->users->updateUserRoles($userId, $payload['roles']);
        } catch (\Throwable $throwable) {
            return back()->withErrors(['user_roles' => $throwable->getMessage()]);
        }

        return redirect('/gts-admin-users')
            ->with('status', 'Updated roles for ' . $updated['login'] . '.');
    }

    public function deleteUser(Request $request, int $userId): RedirectResponse
    {
        $user = $request->attributes->get('gigtune_user');
        $currentUserId = (int) (is_array($user) ? ($user['id'] ?? 0) : 0);
        if ($userId === $currentUserId) {
            return back()->withErrors(['user_delete' => 'You cannot delete your own account.']);
        }

        $blockers = $this->accountDeleteBlockers($userId);
        if (!empty($blockers)) {
            return back()->withErrors(['user_delete' => 'Deletion blocked: ' . implode(', ', $blockers) . '.']);
        }

        try {
            $this->cleanupUserLinkedProfiles($userId);
            $this->users->deleteUserHard($userId);
        } catch (\Throwable $throwable) {
            return back()->withErrors(['user_delete' => $throwable->getMessage()]);
        }

        return redirect('/gts-admin-users')->with('status', 'Deleted user #' . $userId . '.');
    }

    public function deleteUserFromDashboard(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'user_id' => ['required', 'string', 'max:255'],
        ]);

        $raw = trim((string) $payload['user_id']);
        $userId = abs((int) $raw);
        if ($userId <= 0 && preg_match('/\d+/', $raw, $matches) === 1) {
            $userId = (int) ($matches[0] ?? 0);
        }

        if ($userId <= 0 || !$this->wordpressDb()->table($this->tablePrefix() . 'users')->where('ID', $userId)->exists()) {
            return $this->redirectWithAdminFlash('overview', '', 'invalid_user');
        }

        $currentUserId = $this->currentAdminUserId($request);
        if ($userId === $currentUserId) {
            return $this->redirectWithAdminFlash('overview', '', 'cannot_delete_self');
        }

        $blockers = $this->accountDeleteBlockers($userId);
        if (!empty($blockers)) {
            return $this->redirectWithAdminFlash('overview', '', 'user_delete_blocked', implode(', ', $blockers));
        }

        try {
            $this->cleanupUserLinkedProfiles($userId);
            $this->users->deleteUserHard($userId);
        } catch (\Throwable $throwable) {
            return $this->redirectWithAdminFlash('overview', '', 'user_delete_failed');
        }

        return $this->redirectWithAdminFlash('overview', 'user_deleted');
    }

    public function maintenance(): View
    {
        return view('admin.maintenance', [
            'displayResetItems' => $this->displayResetItems(),
        ]);
    }

    public function toggleSiteMaintenance(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $payload['enabled'];
        try {
            $this->siteMaintenance->setEnabled($enabled);
        } catch (\Throwable) {
            return $this->redirectWithAdminFlash('overview', '', 'site_maintenance_toggle_failed');
        }

        return $this->redirectWithAdminFlash(
            'overview',
            $enabled ? 'site_maintenance_enabled' : 'site_maintenance_disabled'
        );
    }

    public function factoryReset(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'confirmation' => ['required', 'string'],
            'password' => ['required', 'string', 'min:1'],
        ]);

        if (trim((string) $payload['confirmation']) !== 'RESET GIGTUNE') {
            return back()->withErrors(['factory_reset' => 'Confirmation phrase must be exactly "RESET GIGTUNE".']);
        }

        $user = $request->attributes->get('gigtune_user');
        if (!is_array($user)) {
            return back()->withErrors(['factory_reset' => 'Authentication required.']);
        }

        $verified = $this->users->verifyCredentials((string) ($user['login'] ?? ''), (string) $payload['password']);
        if (!is_array($verified) || (int) ($verified['id'] ?? 0) !== (int) ($user['id'] ?? 0)) {
            return back()->withErrors(['factory_reset' => 'Admin password verification failed.']);
        }

        $stats = $this->performFactoryReset((int) $user['id']);

        return redirect('/admin/maintenance')->with(
            'status',
            'Factory reset completed: posts_deleted=' . $stats['posts_deleted'] .
            ', postmeta_deleted=' . $stats['postmeta_deleted'] .
            ', options_deleted=' . $stats['options_deleted'] .
            ', usermeta_deleted=' . $stats['usermeta_deleted']
        );
    }

    public function resetDisplayData(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'targets' => ['required', 'array', 'min:1'],
            'targets.*' => ['required', 'string'],
            'password' => ['required', 'string', 'min:1'],
        ]);

        $targets = $this->normalizeDisplayResetTargets((array) ($payload['targets'] ?? []));
        if ($targets === []) {
            return back()->withErrors(['display_reset' => 'Select at least one display section to reset.']);
        }

        $user = $request->attributes->get('gigtune_user');
        if (!is_array($user)) {
            return back()->withErrors(['display_reset' => 'Authentication required.']);
        }

        $verified = $this->users->verifyCredentials((string) ($user['login'] ?? ''), (string) $payload['password']);
        if (!is_array($verified) || (int) ($verified['id'] ?? 0) !== (int) ($user['id'] ?? 0)) {
            return back()->withErrors(['display_reset' => 'Admin password verification failed.']);
        }

        $resetAt = (string) now()->timestamp;
        $labels = [];
        foreach ($targets as $target) {
            $option = self::DISPLAY_RESET_OPTIONS[$target] ?? null;
            if (!is_string($option) || trim($option) === '') {
                continue;
            }
            $this->setOptionValue($option, $resetAt);
            $labels[] = self::DISPLAY_RESET_LABELS[$target] ?? ucfirst($target);
        }

        if ($labels === []) {
            return back()->withErrors(['display_reset' => 'No valid display sections were selected.']);
        }

        return redirect('/admin/maintenance')->with(
            'status',
            'Display data reset applied for: ' . implode(', ', $labels) . '. Existing records remain in the database.'
        );
    }

    public function reviewKyc(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'submission_id' => ['required', 'integer', 'min:1'],
            'target_user_id' => ['nullable', 'integer', 'min:1'],
            'decision' => ['required', 'string', 'max:50'],
            'decision_notes' => ['nullable', 'string', 'max:5000'],
            'review_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $actor = $request->attributes->get('gigtune_user');
        $actorId = (int) (is_array($actor) ? ($actor['id'] ?? 0) : 0);
        if ($actorId <= 0) {
            return back()->withErrors(['kyc_review' => 'Authentication required.']);
        }

        $submissionId = (int) $payload['submission_id'];
        $targetUserId = (int) ($payload['target_user_id'] ?? 0);
        $decision = trim(strtolower((string) $payload['decision']));
        $notes = trim((string) ($payload['decision_notes'] ?? ''));
        $reviewReason = trim((string) ($payload['review_reason'] ?? ''));

        try {
            $result = $this->applyKycReviewDecision(
                $submissionId,
                $targetUserId,
                $decision,
                $notes,
                $reviewReason,
                $actorId
            );
        } catch (\Throwable $throwable) {
            return back()->withErrors(['kyc_review' => $throwable->getMessage()]);
        }

        return redirect('/admin-dashboard/kyc')->with(
            'status',
            'KYC review saved: submission #' . $result['submission_id'] .
            ', user #' . $result['target_user_id'] .
            ', decision=' . $this->toSentenceCase($result['decision']) .
            ', status=' . $this->toSentenceCase($result['new_status']) . '.'
        );
    }

    public function purgeDeletedKycSubmission(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'submission_id' => ['required', 'integer', 'min:1'],
        ]);

        $actorId = $this->currentAdminUserId($request);
        if ($actorId <= 0) {
            return $this->redirectWithAdminFlash('kyc', '', 'insufficient_permissions');
        }

        $submissionId = (int) $payload['submission_id'];
        $post = $this->wordpressDb()
            ->table($this->tablePrefix() . 'posts')
            ->select(['ID', 'post_type'])
            ->where('ID', $submissionId)
            ->first();
        if ($post === null || !$this->isKycSubmissionPostType((string) ($post->post_type ?? ''))) {
            return $this->redirectWithAdminFlash('kyc', '', 'kyc_purge_invalid_submission');
        }

        $targetUserId = (int) $this->getLatestPostMetaValue($submissionId, 'gigtune_kyc_user_id');
        if ($targetUserId > 0 && $this->users->getUserById($targetUserId) !== null) {
            return $this->redirectWithAdminFlash('kyc', '', 'kyc_purge_not_deleted_user');
        }

        try {
            if (!$this->purgeKycSubmissionDocuments($submissionId)) {
                return $this->redirectWithAdminFlash('kyc', '', 'kyc_purge_failed');
            }
            $this->deletePostHard($submissionId);
        } catch (\Throwable) {
            return $this->redirectWithAdminFlash('kyc', '', 'kyc_purge_failed');
        }

        return $this->redirectWithAdminFlash('kyc', 'kyc_deleted_submission_purged');
    }

    public function kycDocument(Request $request, int $submission, string $doc, string $mode)
    {
        $submissionId = abs($submission);
        $docKey = preg_replace('/[^A-Za-z0-9_-]/', '', trim($doc)) ?? '';
        $mode = trim(strtolower($mode));
        if ($submissionId <= 0 || $docKey === '' || !in_array($mode, ['preview', 'download'], true)) {
            abort(404);
        }

        $post = $this->wordpressDb()
            ->table($this->tablePrefix() . 'posts')
            ->select(['ID', 'post_type'])
            ->where('ID', $submissionId)
            ->first();
        if ($post === null || !$this->isKycSubmissionPostType((string) ($post->post_type ?? ''))) {
            abort(404);
        }

        $documents = $this->decodeMetaArray($this->getLatestPostMetaValue($submissionId, 'gigtune_kyc_documents'));
        $document = $this->resolveKycDocumentByKey($documents, $docKey);
        if ($document === null) {
            abort(404);
        }

        $filePath = $this->resolveKycDocumentFilePath($document);
        if ($filePath === null || !is_file($filePath)) {
            abort(404);
        }

        $mime = trim((string) ($document['mime'] ?? ''));
        if ($mime === '') {
            $detected = @mime_content_type($filePath);
            $mime = is_string($detected) && trim($detected) !== '' ? trim($detected) : 'application/octet-stream';
        }
        $filename = trim((string) ($document['file_name'] ?? ''));
        if ($filename === '') {
            $filename = basename($filePath);
        }
        if ($filename === '') {
            $filename = $docKey;
        }

        if ($mode === 'download') {
            return response()->download($filePath, $filename, ['Content-Type' => $mime]);
        }

        return response()->file($filePath, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . addslashes($filename) . '"',
        ]);
    }

    public function reviewPayment(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'booking_id' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'string', 'in:confirm,reject'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $actorId = $this->currentAdminUserId($request);
        if ($actorId <= 0) {
            return $this->redirectWithAdminFlash('payments', '', 'insufficient_permissions');
        }

        $bookingId = (int) $payload['booking_id'];
        if (!$this->isPostType($bookingId, 'gigtune_booking')) {
            return $this->redirectWithAdminFlash('payments', '', 'invalid_booking');
        }

        $result = $this->applyPaymentReviewDecision(
            $bookingId,
            (string) $payload['decision'],
            trim((string) ($payload['note'] ?? '')),
            $actorId
        );

        if (!$result) {
            $error = ((string) $payload['decision']) === 'confirm' ? 'payment_confirm_failed' : 'payment_reject_failed';
            return $this->redirectWithAdminFlash('payments', '', $error);
        }

        $action = ((string) $payload['decision']) === 'confirm' ? 'payment_confirmed' : 'payment_rejected';
        return $this->redirectWithAdminFlash('payments', $action);
    }

    public function reviewPayout(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'booking_id' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'string', 'in:paid,failed'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $actorId = $this->currentAdminUserId($request);
        if ($actorId <= 0) {
            return $this->redirectWithAdminFlash('payouts', '', 'insufficient_permissions');
        }

        $bookingId = (int) $payload['booking_id'];
        if (!$this->isPostType($bookingId, 'gigtune_booking')) {
            return $this->redirectWithAdminFlash('payouts', '', 'invalid_booking');
        }

        $result = $this->applyPayoutReviewDecision(
            $bookingId,
            (string) $payload['decision'],
            trim((string) ($payload['reference'] ?? '')),
            trim((string) ($payload['note'] ?? '')),
            $actorId
        );

        if (!$result) {
            $error = ((string) $payload['decision']) === 'paid' ? 'payout_mark_paid_failed' : 'payout_mark_failed';
            return $this->redirectWithAdminFlash('payouts', '', $error);
        }

        $action = ((string) $payload['decision']) === 'paid' ? 'payout_marked_paid' : 'payout_marked_failed';
        return $this->redirectWithAdminFlash('payouts', $action);
    }

    public function requestBookingRefund(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'booking_id' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $actorId = $this->currentAdminUserId($request);
        if ($actorId <= 0) {
            return $this->redirectWithAdminFlash('bookings', '', 'insufficient_permissions');
        }

        $bookingId = (int) $payload['booking_id'];
        if (!$this->isPostType($bookingId, 'gigtune_booking')) {
            return $this->redirectWithAdminFlash('bookings', '', 'invalid_booking');
        }

        $result = $this->applyBookingRefundRequest($bookingId, trim((string) ($payload['note'] ?? '')), $actorId);
        if (!$result) {
            return $this->redirectWithAdminFlash('bookings', '', 'refund_request_failed');
        }

        return $this->redirectWithAdminFlash('bookings', 'refund_requested');
    }

    public function reviewDispute(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'dispute_id' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'string', 'in:resolve,reject'],
            'note' => ['nullable', 'string', 'max:5000'],
            'mark_booking_completed' => ['nullable', 'boolean'],
        ]);

        $actorId = $this->currentAdminUserId($request);
        if ($actorId <= 0) {
            return $this->redirectWithAdminFlash('disputes', '', 'insufficient_permissions');
        }

        $disputeId = (int) $payload['dispute_id'];
        if (!$this->isPostType($disputeId, 'gigtune_dispute')) {
            return $this->redirectWithAdminFlash('disputes', '', 'invalid_dispute');
        }

        $result = $this->applyDisputeDecision(
            $disputeId,
            (string) $payload['decision'],
            trim((string) ($payload['note'] ?? '')),
            $actorId,
            (bool) ($payload['mark_booking_completed'] ?? false)
        );

        if (!$result) {
            $error = ((string) $payload['decision']) === 'resolve' ? 'dispute_resolve_failed' : 'dispute_reject_failed';
            return $this->redirectWithAdminFlash('disputes', '', $error);
        }

        $action = ((string) $payload['decision']) === 'resolve' ? 'dispute_resolved' : 'dispute_rejected';
        return $this->redirectWithAdminFlash('disputes', $action);
    }

    public function reviewRefund(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'booking_id' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'string', 'in:pending,reject,completed'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $actorId = $this->currentAdminUserId($request);
        if ($actorId <= 0) {
            return $this->redirectWithAdminFlash('refunds', '', 'insufficient_permissions');
        }

        $bookingId = (int) $payload['booking_id'];
        if (!$this->isPostType($bookingId, 'gigtune_booking')) {
            return $this->redirectWithAdminFlash('refunds', '', 'invalid_booking');
        }

        $decision = (string) $payload['decision'];
        $result = $this->applyRefundReviewDecision($bookingId, $decision, trim((string) ($payload['note'] ?? '')), $actorId);
        if (!$result) {
            return $this->redirectWithAdminFlash('refunds', '', 'refund_review_failed');
        }

        $action = match ($decision) {
            'pending' => 'refund_approved',
            'reject' => 'refund_rejected',
            default => 'refund_completed',
        };

        return $this->redirectWithAdminFlash('refunds', $action);
    }

    public function applyComplianceOverride(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'email_verified' => ['nullable', 'string', 'in:0,1'],
            'policies_accepted' => ['nullable', 'string', 'in:0,1'],
            'kyc_status' => ['nullable', 'string', 'in:unsubmitted,pending,verified,rejected,locked'],
            'profile_visibility' => ['nullable', 'string', 'in:auto,force_visible,force_hidden'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $actorId = $this->currentAdminUserId($request);
        if ($actorId <= 0) {
            return $this->redirectWithAdminFlash('compliance', '', 'insufficient_permissions');
        }

        $targetUserId = (int) ($payload['user_id'] ?? 0);
        $targetExists = $this->wordpressDb()
            ->table($this->tablePrefix() . 'users')
            ->where('ID', $targetUserId)
            ->exists();
        if ($targetUserId <= 0 || !$targetExists) {
            return $this->redirectWithAdminFlash('compliance', '', 'invalid_user');
        }

        $note = trim((string) ($payload['note'] ?? ''));
        $changed = false;
        $audit = [
            'at' => now()->toDateTimeString(),
            'actor_user_id' => $actorId,
            'target_user_id' => $targetUserId,
            'note' => $note,
            'changes' => [],
        ];

        if (array_key_exists('email_verified', $payload)) {
            $emailVerified = (string) $payload['email_verified'] === '1';
            $this->upsertUserMeta($targetUserId, 'gigtune_email_verified', $emailVerified ? '1' : '0');
            $this->upsertUserMeta($targetUserId, 'gigtune_email_verification_required', $emailVerified ? '0' : '1');
            if ($emailVerified) {
                $this->upsertUserMeta($targetUserId, 'gigtune_email_verified_at', (string) now()->timestamp);
                $this->upsertUserMeta($targetUserId, 'gigtune_email_verification_token_hash', '');
                $this->upsertUserMeta($targetUserId, 'gigtune_email_verification_expires_at', '');
            } else {
                $this->deleteUserMeta($targetUserId, 'gigtune_email_verified_at');
            }
            $audit['changes']['email_verified'] = $emailVerified ? '1' : '0';
            $changed = true;
        }

        if (array_key_exists('policies_accepted', $payload)) {
            $acceptPolicies = (string) $payload['policies_accepted'] === '1';
            $requiredPolicies = $this->users->requiredPolicyVersions();
            if ($acceptPolicies) {
                $this->users->storePolicyAcceptance($targetUserId, array_keys($requiredPolicies));
            } else {
                $this->deleteUserMeta($targetUserId, 'gigtune_policy_acceptance');
                $this->deleteUserMeta($targetUserId, 'gigtune_terms_acceptance');
                $this->deleteUserMeta($targetUserId, 'gigtune_terms_accepted');
                $this->deleteUserMeta($targetUserId, 'gigtune_terms_version');
                $this->deleteUserMeta($targetUserId, 'gigtune_accept_terms');
                $this->deleteUserMeta($targetUserId, 'gigtune_accept_aup');
                $this->deleteUserMeta($targetUserId, 'gigtune_accept_privacy');
                $this->deleteUserMeta($targetUserId, 'gigtune_accept_refund');
            }
            $audit['changes']['policies_accepted'] = $acceptPolicies ? '1' : '0';
            $changed = true;
        }

        if (array_key_exists('kyc_status', $payload)) {
            $kycStatus = $this->normalizeKycUserStatus((string) $payload['kyc_status']);
            $this->upsertUserMeta($targetUserId, 'gigtune_kyc_status', $kycStatus);
            $this->upsertUserMeta($targetUserId, 'gigtune_kyc_updated_at', (string) now()->timestamp);
            if ($kycStatus === 'verified') {
                $this->upsertUserMeta($targetUserId, 'gigtune_kyc_verified_at', (string) now()->timestamp);
            }
            if ($kycStatus === 'locked') {
                $this->upsertUserMeta($targetUserId, 'gigtune_kyc_locked_at', (string) now()->timestamp);
            }
            if ($kycStatus === 'rejected') {
                $this->upsertUserMeta($targetUserId, 'gigtune_kyc_rejected_at', (string) now()->timestamp);
            }
            $audit['changes']['kyc_status'] = $kycStatus;
            $changed = true;
        }

        if (array_key_exists('profile_visibility', $payload)) {
            $visibility = trim((string) ($payload['profile_visibility'] ?? 'auto'));
            if (!in_array($visibility, ['auto', 'force_visible', 'force_hidden'], true)) {
                $visibility = 'auto';
            }
            $this->upsertUserMeta($targetUserId, 'gigtune_profile_visibility_override', $visibility);
            $audit['changes']['profile_visibility'] = $visibility;
            $changed = true;
        }

        if (!$changed) {
            return $this->redirectWithAdminFlash('compliance', '', 'no_changes_supplied');
        }

        $existingAudit = $this->decodeMaybeSerialized($this->getLatestUserMetaValue($targetUserId, 'gigtune_compliance_override_log'));
        if (!is_array($existingAudit)) {
            $existingAudit = [];
        }
        $existingAudit[] = $audit;
        if (count($existingAudit) > 30) {
            $existingAudit = array_slice($existingAudit, -30);
        }
        $this->upsertUserMeta($targetUserId, 'gigtune_compliance_override_log', serialize(array_values($existingAudit)));
        $this->upsertUserMeta($targetUserId, 'gigtune_compliance_override_last_at', (string) now()->timestamp);
        $this->upsertUserMeta($targetUserId, 'gigtune_compliance_override_last_by', (string) $actorId);

        return $this->redirectWithAdminFlash('compliance', 'compliance_override_saved');
    }

    private function currentAdminUserId(Request $request): int
    {
        $actor = $request->attributes->get('gigtune_user');
        return (int) (is_array($actor) ? ($actor['id'] ?? 0) : 0);
    }

    private function redirectWithAdminFlash(string $tab, string $action = '', string $error = '', string $blockers = ''): RedirectResponse
    {
        $tab = $this->resolveDashboardTab($tab);
        $args = ['tab' => $tab];
        if ($action !== '') {
            $args['admin_success'] = '1';
            $args['admin_action'] = strtolower(trim($action));
        }
        if ($error !== '') {
            $args['admin_error'] = strtolower(trim($error));
        }
        if ($blockers !== '') {
            $args['admin_blockers'] = $blockers;
        }

        return redirect('/admin-dashboard/' . $tab . '?' . http_build_query($args));
    }

    private function isPostType(int $postId, string|array $postType): bool
    {
        $postId = abs($postId);
        if ($postId <= 0) {
            return false;
        }

        $query = $this->wordpressDb()
            ->table($this->tablePrefix() . 'posts')
            ->where('ID', $postId);

        if (is_array($postType)) {
            $query->whereIn('post_type', $postType);
        } else {
            $query->where('post_type', $postType);
        }

        return $query->exists();
    }

    private function applyPaymentReviewDecision(int $bookingId, string $decision, string $note, int $adminUserId): bool
    {
        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['confirm', 'reject'], true)) {
            return false;
        }

        $bookingId = abs($bookingId);
        if (!$this->isPostType($bookingId, 'gigtune_booking')) {
            return false;
        }

        $status = $decision === 'confirm'
            ? 'CONFIRMED_HELD_PENDING_COMPLETION'
            : 'REJECTED_PAYMENT';
        $defaultNote = $decision === 'confirm'
            ? 'Payment confirmed by admin.'
            : 'Payment rejected by admin.';
        $note = $note !== '' ? $note : $defaultNote;

        $nowTs = (string) now()->timestamp;

        $this->upsertPostMeta($bookingId, 'gigtune_payment_status', $status);
        $this->upsertPostMeta($bookingId, 'gigtune_payment_last_note', $note);
        $this->upsertPostMeta($bookingId, 'gigtune_payment_last_reviewed_by', (string) $adminUserId);
        $this->upsertPostMeta($bookingId, 'gigtune_payment_last_reviewed_at', $nowTs);

        if ($decision === 'confirm') {
            $this->upsertPostMeta($bookingId, 'gigtune_payment_confirmed_at', $nowTs);
            $bookingStatus = strtoupper($this->getLatestPostMetaValue($bookingId, 'gigtune_booking_status'));
            if ($bookingStatus === 'ACCEPTED_PENDING_PAYMENT') {
                $this->upsertPostMeta($bookingId, 'gigtune_booking_status', 'PAID_ESCROWED');
            }
        }

        $paymentId = $this->findPostIdByTypeAndMeta('gt_payment', 'gigtune_payment_booking_id', (string) $bookingId);
        if ($paymentId > 0) {
            $this->upsertPostMeta($paymentId, 'gigtune_payment_status', $status);
            if ($decision === 'confirm') {
                $this->upsertPostMeta($paymentId, 'gigtune_payment_confirmed_at', $nowTs);
            }
            $this->upsertPostMeta($paymentId, 'gigtune_payment_admin_note', $note);
        }

        return true;
    }

    private function applyPayoutReviewDecision(int $bookingId, string $decision, string $reference, string $note, int $adminUserId): bool
    {
        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['paid', 'failed'], true)) {
            return false;
        }

        $bookingId = abs($bookingId);
        if (!$this->isPostType($bookingId, 'gigtune_booking')) {
            return false;
        }

        $payoutId = $this->ensurePayoutRecordForBooking($bookingId);
        if ($payoutId <= 0) {
            return false;
        }

        $status = $decision === 'paid' ? 'PAID' : 'FAILED';
        $defaultNote = $decision === 'paid'
            ? 'Manual payout marked paid by admin.'
            : 'Manual payout marked failed by admin.';
        $note = $note !== '' ? $note : $defaultNote;
        $nowTs = (string) now()->timestamp;

        $this->upsertPostMeta($bookingId, 'gigtune_payout_status', $status);
        $this->upsertPostMeta($bookingId, 'gigtune_payout_last_admin_id', (string) $adminUserId);
        $this->upsertPostMeta($bookingId, 'gigtune_payout_last_updated', $nowTs);
        $this->upsertPostMeta($payoutId, 'gigtune_payout_status', $status);
        if ($decision === 'paid') {
            $this->upsertPostMeta($bookingId, 'gigtune_payout_paid_at', $nowTs);
            $this->upsertPostMeta($payoutId, 'gigtune_payout_paid_at', $nowTs);
        } else {
            $this->upsertPostMeta($bookingId, 'gigtune_payout_failure_reason', $note);
            $this->upsertPostMeta($payoutId, 'gigtune_payout_failure_reason', $note);
        }
        if ($reference !== '') {
            $this->upsertPostMeta($bookingId, 'gigtune_payout_reference', $reference);
            $this->upsertPostMeta($payoutId, 'gigtune_payout_reference', $reference);
        }
        if ($note !== '') {
            $this->upsertPostMeta($bookingId, 'gigtune_payout_note', $note);
            $this->upsertPostMeta($payoutId, 'gigtune_payout_note', $note);
        }

        return true;
    }

    private function applyBookingRefundRequest(int $bookingId, string $note, int $adminUserId): bool
    {
        $bookingId = abs($bookingId);
        if (!$this->isPostType($bookingId, 'gigtune_booking')) {
            return false;
        }

        $requestedAt = (string) now()->timestamp;
        $checkoutId = trim($this->getLatestPostMetaValue($bookingId, 'gigtune_refund_checkout_id'));
        if ($checkoutId === '') {
            $checkoutId = trim($this->getLatestPostMetaValue($bookingId, 'gigtune_yoco_checkout_id'));
        }

        $this->upsertPostMeta($bookingId, 'gigtune_refund_status', 'REQUESTED');
        $this->upsertPostMeta($bookingId, 'gigtune_refund_requested_by', 'admin');
        $this->upsertPostMeta($bookingId, 'gigtune_refund_requested_at', $requestedAt);
        $this->upsertPostMeta($bookingId, 'gigtune_booking_locked', 'refund_pending');
        $this->upsertPostMeta($bookingId, 'gigtune_refund_admin_user_id', (string) $adminUserId);
        if ($checkoutId !== '') {
            $this->upsertPostMeta($bookingId, 'gigtune_refund_checkout_id', $checkoutId);
        }
        if ($note !== '') {
            $this->upsertPostMeta($bookingId, 'gigtune_refund_note', $note);
        }
        $this->deletePostMeta($bookingId, 'gigtune_refund_failure_reason');

        return true;
    }

    private function applyDisputeDecision(int $disputeId, string $decision, string $note, int $adminUserId, bool $markBookingCompleted): bool
    {
        $disputeId = abs($disputeId);
        if (!$this->isPostType($disputeId, 'gigtune_dispute')) {
            return false;
        }

        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['resolve', 'reject'], true)) {
            return false;
        }

        $bookingId = (int) $this->getLatestPostMetaValue($disputeId, 'gigtune_dispute_booking_id');
        if (!$this->isPostType($bookingId, 'gigtune_booking')) {
            return false;
        }

        $newStatus = $decision === 'resolve' ? 'RESOLVED' : 'REJECTED';
        $resolvedAt = (string) now()->timestamp;

        $this->upsertPostMeta($disputeId, 'gigtune_dispute_status', $newStatus);
        $this->upsertPostMeta($disputeId, 'gigtune_dispute_admin_note', $note);
        $this->upsertPostMeta($disputeId, 'gigtune_dispute_resolved_at', $resolvedAt);
        $this->upsertPostMeta($disputeId, 'gigtune_dispute_resolved_by_user_id', (string) $adminUserId);
        $this->upsertPostMeta($disputeId, 'status', $newStatus);
        $this->upsertPostMeta($disputeId, 'resolved_at', $resolvedAt);

        $this->upsertPostMeta($bookingId, 'gigtune_dispute_raised', '0');
        $this->upsertPostMeta($bookingId, 'gigtune_dispute_closed_at', $resolvedAt);
        $this->upsertPostMeta($bookingId, 'gigtune_dispute_resolution_status', $newStatus);
        if ($note !== '') {
            $this->upsertPostMeta($bookingId, 'gigtune_dispute_resolution_note', $note);
        }
        if ($decision === 'resolve' && $markBookingCompleted) {
            $this->upsertPostMeta($bookingId, 'gigtune_booking_status', 'COMPLETED_CONFIRMED');
        }

        $participantIds = $this->bookingParticipantUserIds($bookingId);
        $message = $decision === 'resolve'
            ? ('Dispute for booking #' . $bookingId . ' was resolved by admin.')
            : ('Dispute for booking #' . $bookingId . ' was rejected by admin.');
        if ($note !== '') {
            $message .= ' Note: ' . $note;
        }
        foreach ($participantIds as $participantId) {
            $this->createSystemNotification($participantId, $message, 'dispute', 'booking', $bookingId);
        }

        return true;
    }

    private function applyRefundReviewDecision(int $bookingId, string $decision, string $note, int $adminUserId): bool
    {
        $bookingId = abs($bookingId);
        if (!$this->isPostType($bookingId, 'gigtune_booking')) {
            return false;
        }

        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['pending', 'reject', 'completed'], true)) {
            return false;
        }

        $requestedBy = trim($this->getLatestPostMetaValue($bookingId, 'gigtune_refund_requested_by'));
        if ($requestedBy === '') {
            $requestedBy = 'admin';
        }
        $requestedAt = (int) $this->getLatestPostMetaValue($bookingId, 'gigtune_refund_requested_at');
        if ($requestedAt <= 0) {
            $requestedAt = now()->timestamp;
        }
        $checkoutId = trim($this->getLatestPostMetaValue($bookingId, 'gigtune_refund_checkout_id'));
        if ($checkoutId === '') {
            $checkoutId = trim($this->getLatestPostMetaValue($bookingId, 'gigtune_yoco_checkout_id'));
        }

        $this->upsertPostMeta($bookingId, 'gigtune_refund_requested_by', $requestedBy);
        $this->upsertPostMeta($bookingId, 'gigtune_refund_requested_at', (string) $requestedAt);
        $this->upsertPostMeta($bookingId, 'gigtune_refund_last_admin_user_id', (string) $adminUserId);
        if ($checkoutId !== '') {
            $this->upsertPostMeta($bookingId, 'gigtune_refund_checkout_id', $checkoutId);
        }

        if ($decision === 'pending') {
            $this->upsertPostMeta($bookingId, 'gigtune_refund_status', 'PENDING');
            $this->upsertPostMeta($bookingId, 'gigtune_booking_locked', 'refund_pending');
            $this->deletePostMeta($bookingId, 'gigtune_refund_failure_reason');
            if ($note !== '') {
                $this->upsertPostMeta($bookingId, 'gigtune_refund_note', $note);
            }
            foreach ($this->bookingParticipantUserIds($bookingId) as $participantId) {
                $msg = 'Refund review for booking #' . $bookingId . ' is pending admin processing.';
                if ($note !== '') {
                    $msg .= ' Note: ' . $note;
                }
                $this->createSystemNotification($participantId, $msg, 'refund', 'booking', $bookingId);
            }
            return true;
        }

        if ($decision === 'reject') {
            $failure = $note !== '' ? $note : 'Refund rejected by admin.';
            $this->upsertPostMeta($bookingId, 'gigtune_refund_status', 'REJECTED');
            $this->upsertPostMeta($bookingId, 'gigtune_refund_failure_reason', $failure);
            $this->clearRefundLock($bookingId);
            foreach ($this->bookingParticipantUserIds($bookingId) as $participantId) {
                $this->createSystemNotification($participantId, 'Refund request for booking #' . $bookingId . ' was rejected. Note: ' . $failure, 'refund', 'booking', $bookingId);
            }
            return true;
        }

        $processedAt = (string) now()->timestamp;
        $this->upsertPostMeta($bookingId, 'gigtune_refund_status', 'SUCCEEDED');
        $this->upsertPostMeta($bookingId, 'gigtune_payment_refunded', '1');
        $this->upsertPostMeta($bookingId, 'gigtune_refund_processed_at', $processedAt);
        $this->clearRefundLock($bookingId);
        $this->deletePostMeta($bookingId, 'gigtune_refund_failure_reason');
        if ($note !== '') {
            $this->upsertPostMeta($bookingId, 'gigtune_refund_note', $note);
        }

        $paymentStatus = 'REFUNDED_FULL';
        $refundCents = (int) $this->getLatestPostMetaValue($bookingId, 'gigtune_refund_amount_cents');
        $paymentId = $this->findPostIdByTypeAndMeta('gt_payment', 'gigtune_payment_booking_id', (string) $bookingId);
        if ($paymentId > 0) {
            $totalAmount = (float) $this->getLatestPostMetaValue($paymentId, 'gigtune_amount_gross');
            $totalCents = (int) round($totalAmount * 100);
            if ($refundCents > 0 && $totalCents > 0 && $refundCents < $totalCents) {
                $paymentStatus = 'REFUNDED_PARTIAL';
            }
            $this->upsertPostMeta($paymentId, 'gigtune_payment_status', $paymentStatus);
        }

        $this->upsertPostMeta($bookingId, 'gigtune_payment_status', $paymentStatus);
        $this->upsertPostMeta($bookingId, 'gigtune_booking_status', 'CANCELLED_BY_CLIENT');
        foreach ($this->bookingParticipantUserIds($bookingId) as $participantId) {
            $msg = 'Refund for booking #' . $bookingId . ' was completed (' . $paymentStatus . ').';
            if ($note !== '') {
                $msg .= ' Note: ' . $note;
            }
            $this->createSystemNotification($participantId, $msg, 'refund', 'booking', $bookingId);
        }

        return true;
    }

    /** @return array<int> */
    private function bookingParticipantUserIds(int $bookingId): array
    {
        $bookingId = abs($bookingId);
        if ($bookingId <= 0) {
            return [];
        }

        $ids = [];
        $clientUserId = (int) $this->getLatestPostMetaValue($bookingId, 'gigtune_booking_client_user_id');
        if ($clientUserId > 0) {
            $ids[$clientUserId] = $clientUserId;
        }

        $artistProfileId = (int) $this->getLatestPostMetaValue($bookingId, 'gigtune_booking_artist_profile_id');
        if ($artistProfileId > 0) {
            $artistUserId = (int) $this->getLatestPostMetaValue($artistProfileId, 'gigtune_user_id');
            if ($artistUserId <= 0) {
                $artistUserId = (int) $this->getLatestPostMetaValue($artistProfileId, 'gigtune_artist_user_id');
            }
            if ($artistUserId <= 0) {
                $artistUserId = (int) $this->wordpressDb()
                    ->table($this->tablePrefix() . 'posts')
                    ->where('ID', $artistProfileId)
                    ->value('post_author');
            }
            if ($artistUserId > 0) {
                $ids[$artistUserId] = $artistUserId;
            }
        }

        return array_values($ids);
    }

    private function createSystemNotification(
        int $targetUserId,
        string $message,
        string $type = 'system',
        string $objectType = '',
        int $objectId = 0
    ): void {
        $targetUserId = abs($targetUserId);
        if ($targetUserId <= 0) {
            return;
        }

        $message = trim($message);
        if ($message === '') {
            return;
        }

        $db = $this->wordpressDb();
        $posts = $this->tablePrefix() . 'posts';
        $nowLocal = now();
        $nowUtc = now('UTC');
        $title = function_exists('mb_substr')
            ? mb_substr($message, 0, 255)
            : substr($message, 0, 255);

        $notificationId = (int) $db->table($posts)->insertGetId([
            'post_author' => 0,
            'post_date' => $nowLocal->format('Y-m-d H:i:s'),
            'post_date_gmt' => $nowUtc->format('Y-m-d H:i:s'),
            'post_content' => '',
            'post_title' => $title,
            'post_excerpt' => '',
            'post_status' => 'publish',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_password' => '',
            'post_name' => '',
            'to_ping' => '',
            'pinged' => '',
            'post_modified' => $nowLocal->format('Y-m-d H:i:s'),
            'post_modified_gmt' => $nowUtc->format('Y-m-d H:i:s'),
            'post_content_filtered' => '',
            'post_parent' => 0,
            'guid' => '',
            'menu_order' => 0,
            'post_type' => 'gigtune_notification',
            'post_mime_type' => '',
            'comment_count' => 0,
        ]);
        if ($notificationId <= 0) {
            return;
        }

        $createdAt = (string) now()->timestamp;
        $meta = [
            'gigtune_notification_recipient_user_id' => (string) $targetUserId,
            'gigtune_notification_user_id' => (string) $targetUserId,
            'recipient_user_id' => (string) $targetUserId,
            'gigtune_notification_type' => $type !== '' ? $type : 'system',
            'notification_type' => $type !== '' ? $type : 'system',
            'gigtune_notification_object_type' => $objectType,
            'object_type' => $objectType,
            'gigtune_notification_object_id' => (string) max(0, $objectId),
            'object_id' => (string) max(0, $objectId),
            'gigtune_notification_title' => $message,
            'gigtune_notification_message' => $message,
            'message' => $message,
            'gigtune_notification_created_at' => $createdAt,
            'created_at' => $createdAt,
            'gigtune_notification_is_read' => '0',
            'gigtune_notification_read_at' => '0',
            'gigtune_notification_is_archived' => '0',
            'gigtune_notification_is_deleted' => '0',
            'is_read' => '0',
        ];
        foreach ($meta as $key => $value) {
            $this->upsertPostMeta($notificationId, $key, (string) $value);
        }
    }

    private function findPostIdByTypeAndMeta(string $postType, string $metaKey, string $metaValue): int
    {
        $postId = $this->wordpressDb()->table($this->tablePrefix() . 'posts as p')
            ->where('p.post_type', $postType)
            ->whereExists(function ($query) use ($metaKey, $metaValue): void {
                $query->selectRaw('1')
                    ->from($this->tablePrefix() . 'postmeta as pm')
                    ->whereColumn('pm.post_id', 'p.ID')
                    ->where('pm.meta_key', $metaKey)
                    ->where('pm.meta_value', $metaValue);
            })
            ->orderByDesc('p.ID')
            ->value('p.ID');

        return (int) $postId;
    }

    private function ensurePayoutRecordForBooking(int $bookingId): int
    {
        $bookingId = abs($bookingId);
        if ($bookingId <= 0) {
            return 0;
        }

        $existing = $this->findPostIdByTypeAndMeta('gt_payout', 'gigtune_payout_booking_id', (string) $bookingId);
        if ($existing > 0) {
            return $existing;
        }

        $nowLocal = now();
        $nowUtc = now('UTC');
        $payoutId = (int) $this->wordpressDb()
            ->table($this->tablePrefix() . 'posts')
            ->insertGetId([
                'post_author' => 0,
                'post_date' => $nowLocal->format('Y-m-d H:i:s'),
                'post_date_gmt' => $nowUtc->format('Y-m-d H:i:s'),
                'post_content' => '',
                'post_title' => 'Payout for booking #' . $bookingId,
                'post_excerpt' => '',
                'post_status' => 'publish',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
                'post_password' => '',
                'post_name' => '',
                'to_ping' => '',
                'pinged' => '',
                'post_modified' => $nowLocal->format('Y-m-d H:i:s'),
                'post_modified_gmt' => $nowUtc->format('Y-m-d H:i:s'),
                'post_content_filtered' => '',
                'post_parent' => 0,
                'guid' => '',
                'menu_order' => 0,
                'post_type' => 'gt_payout',
                'post_mime_type' => '',
                'comment_count' => 0,
            ]);

        if ($payoutId <= 0) {
            return 0;
        }

        $this->upsertPostMeta($payoutId, 'gigtune_payout_booking_id', (string) $bookingId);
        $this->upsertPostMeta($payoutId, 'gigtune_payout_status', 'PENDING_MANUAL');
        $this->upsertPostMeta($bookingId, 'gigtune_payout_status', 'PENDING_MANUAL');

        return $payoutId;
    }

    private function clearRefundLock(int $bookingId): void
    {
        $lock = trim($this->getLatestPostMetaValue($bookingId, 'gigtune_booking_locked'));
        if ($lock === 'refund_pending') {
            $this->deletePostMeta($bookingId, 'gigtune_booking_locked');
        }
    }

    private function deletePostMeta(int $postId, string $metaKey): void
    {
        $postId = abs($postId);
        if ($postId <= 0 || trim($metaKey) === '') {
            return;
        }

        $this->wordpressDb()
            ->table($this->tablePrefix() . 'postmeta')
            ->where('post_id', $postId)
            ->where('meta_key', $metaKey)
            ->delete();
    }

    private function accountDeleteBlockers(int $userId): array
    {
        $userId = abs($userId);
        if ($userId <= 0) {
            return [];
        }

        $activeStatuses = [
            'REQUESTED',
            'ACCEPTED_PENDING_PAYMENT',
            'PAID_ESCROWED',
            'COMPLETED_BY_ARTIST',
            'DISPUTE_OPEN',
        ];
        $pendingPayoutStatuses = ['PENDING_MANUAL', 'PENDING', 'INITIATED'];
        $artistProfileId = $this->resolveArtistProfileIdForUser($userId);
        $blockers = [];

        if ($this->bookingExistsForMetaPair(
            ['gigtune_booking_client_user_id' => (string) $userId],
            ['gigtune_booking_status' => $activeStatuses]
        )) {
            $blockers[] = 'Active bookings';
        }

        if ($artistProfileId > 0 && $this->bookingExistsForMetaPair(
            ['gigtune_booking_artist_profile_id' => (string) $artistProfileId],
            ['gigtune_booking_status' => $activeStatuses]
        )) {
            $blockers[] = 'Active bookings';
        }

        if ($artistProfileId > 0 && $this->bookingExistsForMetaPair(
            ['gigtune_booking_artist_profile_id' => (string) $artistProfileId],
            ['gigtune_payout_status' => $pendingPayoutStatuses]
        )) {
            $blockers[] = 'Pending payouts';
        }

        $hasClientDispute = $this->bookingExistsForMetaPair(
            ['gigtune_booking_client_user_id' => (string) $userId],
            ['gigtune_dispute_raised' => ['1']]
        ) || $this->bookingExistsForMetaPair(
            ['gigtune_booking_client_user_id' => (string) $userId],
            ['gigtune_booking_status' => ['DISPUTE_OPEN']]
        );

        $hasArtistDispute = false;
        if ($artistProfileId > 0) {
            $hasArtistDispute = $this->bookingExistsForMetaPair(
                ['gigtune_booking_artist_profile_id' => (string) $artistProfileId],
                ['gigtune_dispute_raised' => ['1']]
            ) || $this->bookingExistsForMetaPair(
                ['gigtune_booking_artist_profile_id' => (string) $artistProfileId],
                ['gigtune_booking_status' => ['DISPUTE_OPEN']]
            );
        }

        if ($hasClientDispute || $hasArtistDispute) {
            $blockers[] = 'Open disputes';
        }

        return array_values(array_unique($blockers));
    }

    private function bookingExistsForMetaPair(array $requiredMeta, array $statusMeta): bool
    {
        $db = $this->wordpressDb();
        $posts = $this->tablePrefix() . 'posts';
        $postmeta = $this->tablePrefix() . 'postmeta';

        $query = $db->table($posts . ' as p')
            ->where('p.post_type', 'gigtune_booking');

        foreach ($requiredMeta as $metaKey => $metaValue) {
            $query->whereExists(function ($sub) use ($postmeta, $metaKey, $metaValue): void {
                $sub->selectRaw('1')
                    ->from($postmeta . ' as pm')
                    ->whereColumn('pm.post_id', 'p.ID')
                    ->where('pm.meta_key', $metaKey)
                    ->where('pm.meta_value', (string) $metaValue);
            });
        }

        foreach ($statusMeta as $metaKey => $metaValues) {
            $values = [];
            foreach ((array) $metaValues as $value) {
                $normalized = trim((string) $value);
                if ($normalized !== '') {
                    $values[] = $normalized;
                }
            }
            if (empty($values)) {
                continue;
            }
            $query->whereExists(function ($sub) use ($postmeta, $metaKey, $values): void {
                $sub->selectRaw('1')
                    ->from($postmeta . ' as pm')
                    ->whereColumn('pm.post_id', 'p.ID')
                    ->where('pm.meta_key', $metaKey)
                    ->whereIn('pm.meta_value', $values);
            });
        }

        return $query->exists();
    }

    private function cleanupUserLinkedProfiles(int $userId): void
    {
        $userId = abs($userId);
        if ($userId <= 0) {
            return;
        }

        $artistProfileId = $this->resolveArtistProfileIdForUser($userId);
        if ($artistProfileId > 0 && $this->isPostType($artistProfileId, 'artist_profile')) {
            $this->deletePostHard($artistProfileId);
        }

        $clientProfileId = abs((int) $this->getLatestUserMetaValue($userId, 'gigtune_client_profile_id'));
        if ($clientProfileId > 0 && $this->isPostType($clientProfileId, 'gt_client_profile')) {
            $this->deletePostHard($clientProfileId);
        } else {
            $clientProfileId = (int) $this->findPostIdByTypeAndMeta('gt_client_profile', 'gigtune_client_user_id', (string) $userId);
            if ($clientProfileId > 0) {
                $this->deletePostHard($clientProfileId);
            }
        }
    }

    private function deletePostHard(int $postId): void
    {
        $postId = abs($postId);
        if ($postId <= 0) {
            return;
        }

        $db = $this->wordpressDb();
        $prefix = $this->tablePrefix();

        $db->table($prefix . 'term_relationships')
            ->where('object_id', $postId)
            ->delete();

        $db->table($prefix . 'postmeta')
            ->where('post_id', $postId)
            ->delete();

        $db->table($prefix . 'posts')
            ->where('ID', $postId)
            ->delete();
    }

    private function purgeKycSubmissionDocuments(int $submissionId): bool
    {
        $submissionId = abs($submissionId);
        if ($submissionId <= 0) {
            return false;
        }

        $documents = $this->decodeMetaArray($this->getLatestPostMetaValue($submissionId, 'gigtune_kyc_documents'));
        if (empty($documents)) {
            return true;
        }

        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }
            $candidatePaths = [
                (string) ($document['file_path'] ?? ''),
                (string) ($document['path'] ?? ''),
            ];
            foreach ($candidatePaths as $path) {
                $normalized = trim($path);
                if ($normalized === '' || preg_match('/^https?:\/\//i', $normalized) === 1) {
                    continue;
                }

                $absolute = $normalized;
                if (!str_starts_with($absolute, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:\\\\/', $absolute)) {
                    $absolute = base_path($absolute);
                }
                if (is_file($absolute)) {
                    @unlink($absolute);
                }

                $publicDiskPath = ltrim(str_replace('\\', '/', $normalized), '/');
                if (
                    $publicDiskPath !== ''
                    && \Illuminate\Support\Facades\Storage::disk('public')->exists($publicDiskPath)
                ) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($publicDiskPath);
                }
            }
        }

        return true;
    }

    private function resolveKycDocumentByKey(array $documents, string $docKey): ?array
    {
        $docKey = strtolower(trim($docKey));
        if ($docKey === '') {
            return null;
        }

        foreach ($documents as $key => $document) {
            $normalizedKey = strtolower((string) (preg_replace('/[^A-Za-z0-9_-]/', '', (string) $key) ?? ''));
            if ($normalizedKey === $docKey) {
                return $this->normalizeKycDocumentRecord($document, $docKey);
            }
        }

        if (ctype_digit($docKey)) {
            $index = (int) $docKey;
            $values = array_values($documents);
            if (isset($values[$index])) {
                return $this->normalizeKycDocumentRecord($values[$index], $docKey);
            }
        }

        return null;
    }

    private function normalizeKycDocumentRecord(mixed $document, string $fallbackKey): ?array
    {
        $fallbackKey = trim($fallbackKey);
        if (is_string($document)) {
            $path = trim($document);
            if ($path === '') {
                return null;
            }

            return [
                'file_name' => basename($path),
                'file_path' => $path,
                'path' => $path,
                'mime' => '',
            ];
        }

        if (!is_array($document)) {
            return null;
        }

        $filePath = trim((string) ($document['file_path'] ?? ''));
        $path = trim((string) ($document['path'] ?? ''));
        if ($filePath === '' && $path === '') {
            foreach (['storage_path', 'relative_path', 'uri', 'url'] as $alt) {
                $candidate = trim((string) ($document[$alt] ?? ''));
                if ($candidate !== '') {
                    $path = $candidate;
                    break;
                }
            }
        }

        if ($filePath === '' && $path === '') {
            return null;
        }

        $fileName = trim((string) ($document['file_name'] ?? ''));
        if ($fileName === '') {
            $base = $filePath !== '' ? $filePath : $path;
            $fileName = basename($base);
        }
        if ($fileName === '' && $fallbackKey !== '') {
            $fileName = $fallbackKey;
        }

        $document['file_name'] = $fileName;
        $document['file_path'] = $filePath;
        $document['path'] = $path;
        $document['mime'] = trim((string) ($document['mime'] ?? ''));

        return $document;
    }

    private function resolveKycDocumentFilePath(array $document): ?string
    {
        $candidates = [
            trim((string) ($document['file_path'] ?? '')),
            trim((string) ($document['path'] ?? '')),
            trim((string) ($document['storage_path'] ?? '')),
            trim((string) ($document['relative_path'] ?? '')),
            trim((string) ($document['url'] ?? '')),
            trim((string) ($document['uri'] ?? '')),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            foreach ($this->expandKycDocumentPathCandidates($candidate) as $pathCandidate) {
                if (is_file($pathCandidate)) {
                    return $pathCandidate;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function expandKycDocumentPathCandidates(string $candidate): array
    {
        $out = [];
        $candidate = trim($candidate);
        if ($candidate === '') {
            return $out;
        }

        if (preg_match('/^https?:\/\//i', $candidate) === 1) {
            $urlPath = (string) (parse_url($candidate, PHP_URL_PATH) ?? '');
            if ($urlPath !== '') {
                $candidate = $urlPath;
            }
        }

        $push = static function (array &$paths, string $value): void {
            $value = trim($value);
            if ($value === '' || in_array($value, $paths, true)) {
                return;
            }
            $paths[] = $value;
        };

        $push($out, $candidate);
        $normalized = str_replace('\\', '/', $candidate);

        $isAbsolute = str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $candidate) === 1;
        if (!$isAbsolute) {
            $push($out, base_path($candidate));
            $push($out, public_path($candidate));
            $push($out, storage_path('app/public/' . ltrim($normalized, '/')));
        }

        if (str_starts_with($normalized, '/storage/')) {
            $push($out, public_path(ltrim($normalized, '/')));
            $push($out, storage_path('app/public/' . ltrim(substr($normalized, strlen('/storage/')), '/')));
        }

        if (str_starts_with($normalized, '/wp-content/')) {
            $push($out, public_path(ltrim($normalized, '/')));
        }

        $wpContentPos = strpos($normalized, '/wp-content/');
        if ($wpContentPos !== false) {
            $suffix = ltrim(substr($normalized, $wpContentPos), '/');
            $push($out, public_path($suffix));
        }

        $publicHtmlPos = strpos($normalized, '/public_html/');
        if ($publicHtmlPos !== false) {
            $suffix = ltrim(substr($normalized, $publicHtmlPos + strlen('/public_html/')), '/');
            if ($suffix !== '') {
                $push($out, public_path($suffix));
                $push($out, base_path($suffix));
            }
        }

        return $out;
    }

    private function loadMetrics(): array
    {
        $db = $this->wordpressDb();
        $prefix = $this->tablePrefix();
        $posts = $prefix . 'posts';
        $postmeta = $prefix . 'postmeta';
        $users = $prefix . 'users';

        $pendingPayouts = (int) $db->table($posts . ' as p')
            ->where('p.post_type', 'gigtune_booking')
            ->whereExists(function ($query) use ($postmeta): void {
                $query->selectRaw('1')
                    ->from($postmeta . ' as pm')
                    ->whereColumn('pm.post_id', 'p.ID')
                    ->where('pm.meta_key', 'gigtune_payout_status')
                    ->where('pm.meta_value', 'PENDING_MANUAL');
            })
            ->count('p.ID');

        $pendingKyc = (int) $db->table($posts . ' as p')
            ->whereIn('p.post_type', ['gt_kyc_submission', 'gigtune_kyc_submission', 'gigtune_kyc_submissi'])
            ->where(function ($query) use ($postmeta): void {
                $query->whereExists(function ($sub) use ($postmeta): void {
                    $sub->selectRaw('1')
                        ->from($postmeta . ' as pm')
                        ->whereColumn('pm.post_id', 'p.ID')
                        ->where('pm.meta_key', 'gigtune_kyc_decision')
                        ->where('pm.meta_value', 'pending');
                })->orWhereNotExists(function ($sub) use ($postmeta): void {
                    $sub->selectRaw('1')
                        ->from($postmeta . ' as pm')
                        ->whereColumn('pm.post_id', 'p.ID')
                        ->where('pm.meta_key', 'gigtune_kyc_decision');
                });
            })
            ->count('p.ID');

        $bookingsTotal = (int) $db->table($posts)->where('post_type', 'gigtune_booking')->count('ID');

        return [
            'users_total' => (int) $db->table($users)->count('ID'),
            'bookings_total' => $bookingsTotal,
            'recent_bookings_scanned' => min($bookingsTotal, 50),
            'notifications_total' => (int) $db->table($posts)->where('post_type', 'gigtune_notification')->count('ID'),
            'kyc_total' => $pendingKyc,
            'pending_payouts' => $pendingPayouts,
            'psa_total' => (int) $db->table($posts)->where('post_type', 'gigtune_psa')->count('ID'),
            'open_disputes' => (int) $db->table($posts . ' as p')
                ->where('p.post_type', 'gigtune_booking')
                ->whereExists(function ($query) use ($postmeta): void {
                    $query->selectRaw('1')
                        ->from($postmeta . ' as pm')
                        ->whereColumn('pm.post_id', 'p.ID')
                        ->where('pm.meta_key', 'gigtune_dispute_raised')
                        ->where('pm.meta_value', '1');
                })
                ->count('p.ID'),
            'awaiting_payment_total' => (int) $db->table($posts . ' as p')
                ->where('p.post_type', 'gigtune_booking')
                ->whereExists(function ($query) use ($postmeta): void {
                    $query->selectRaw('1')
                        ->from($postmeta . ' as pm')
                        ->whereColumn('pm.post_id', 'p.ID')
                        ->where('pm.meta_key', 'gigtune_payment_status')
                        ->where('pm.meta_value', 'AWAITING_PAYMENT_CONFIRMATION');
                })
                ->count('p.ID'),
        ];
    }

    private function buildUsersViewData(Request $request): array
    {
        $currentUser = $request->attributes->get('gigtune_user');
        $users = $this->users->listUsers([
            'search' => $request->query('search'),
            'role' => $request->query('role'),
            'page' => $request->query('page', 1),
            'per_page' => 20,
        ]);

        return [
            'currentUser' => is_array($currentUser) ? $currentUser : null,
            'users' => $users,
            'metrics' => $this->loadMetrics(),
            'roleFilter' => trim((string) $request->query('role', '')),
            'search' => trim((string) $request->query('search', '')),
        ];
    }

    private function resolveDashboardTab(string $tab): string
    {
        $allowed = ['overview', 'users', 'compliance', 'payments', 'payouts', 'bookings', 'disputes', 'refunds', 'kyc', 'reports'];
        $normalized = trim(strtolower($tab));
        return in_array($normalized, $allowed, true) ? $normalized : 'overview';
    }

    private function loadAdminNotifications(int $adminUserId, int $limit = 12): array
    {
        $adminUserId = abs($adminUserId);
        if ($adminUserId <= 0) {
            return [];
        }

        $db = $this->wordpressDb();
        $prefix = $this->tablePrefix();
        $posts = $prefix . 'posts';
        $postmeta = $prefix . 'postmeta';

        $rows = $db->table($posts . ' as p')
            ->select(['p.ID', 'p.post_title', 'p.post_date'])
            ->where('p.post_type', 'gigtune_notification')
            ->whereExists(function ($query) use ($postmeta, $adminUserId): void {
                $query->selectRaw('1')
                    ->from($postmeta . ' as pm')
                    ->whereColumn('pm.post_id', 'p.ID')
                    ->whereIn('pm.meta_key', ['gigtune_notification_recipient_user_id', 'gigtune_notification_user_id', 'recipient_user_id'])
                    ->where('pm.meta_value', (string) $adminUserId);
            })
            ->orderByDesc('p.ID')
            ->limit(max(1, min(30, $limit)))
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->ID;
        }
        $meta = $this->postMetaMap($ids, [
            'gigtune_notification_message',
            'message',
            'gigtune_notification_object_type',
            'object_type',
            'gigtune_notification_object_id',
            'object_id',
            'gigtune_notification_is_read',
            'is_read',
            'gigtune_notification_created_at',
            'created_at',
        ]);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row->ID;
            $m = $meta[$id] ?? [];
            $message = trim((string) ($m['gigtune_notification_message'] ?? $m['message'] ?? $row->post_title ?? ''));
            $objectType = trim((string) ($m['gigtune_notification_object_type'] ?? $m['object_type'] ?? ''));
            $objectId = (int) ($m['gigtune_notification_object_id'] ?? $m['object_id'] ?? 0);
            $createdAt = (int) ($m['gigtune_notification_created_at'] ?? $m['created_at'] ?? 0);
            if ($createdAt <= 0) {
                $createdAt = strtotime((string) ($row->post_date ?? '')) ?: 0;
            }
            $isRead = in_array((string) ($m['gigtune_notification_is_read'] ?? $m['is_read'] ?? '0'), ['1', 'true', 'yes'], true);

            $openUrl = '/notifications/?notification_id=' . $id;
            if ($objectType === 'booking' && $objectId > 0) {
                $openUrl = '/messages/?booking_id=' . $objectId . '&notification_id=' . $id;
            } elseif ($objectType === 'psa' && $objectId > 0) {
                $openUrl = '/posts-page/?psa_id=' . $objectId . '&notification_id=' . $id;
            }

            $items[] = [
                'id' => $id,
                'message' => $message,
                'created_at' => $createdAt,
                'is_read' => $isRead,
                'open_url' => $openUrl,
            ];
        }

        return $items;
    }

    private function loadDashboardTabData(string $tab, Request $request): array
    {
        return match ($tab) {
            'users' => $this->loadAdminDashboardUsersTab($request),
            'compliance' => $this->loadAdminDashboardComplianceTab($request),
            'payments' => [
                'items' => $this->loadPostTypeRows(
                    'gigtune_booking',
                    [
                        'gigtune_payment_status',
                        'gigtune_payment_method',
                        'gigtune_payment_reference_human',
                        'gigtune_payment_reported_at',
                        'gigtune_payment_confirmed_at',
                        'gigtune_booking_status',
                        'gigtune_payout_status',
                    ],
                    100,
                    $this->withDisplayResetScope('payments', function ($query) use ($request): void {
                        $payment = trim((string) $request->query('payment', ''));
                        if ($payment !== '') {
                            $query->whereExists(function ($sub) use ($payment): void {
                                $sub->selectRaw('1')
                                    ->from($this->tablePrefix() . 'postmeta as pm')
                                    ->whereColumn('pm.post_id', 'p.ID')
                                    ->where('pm.meta_key', 'gigtune_payment_status')
                                    ->whereRaw('UPPER(pm.meta_value) = ?', [strtoupper($payment)]);
                            });
                            return;
                        }

                        $query->where(function ($sub): void {
                            $sub->whereExists(function ($q): void {
                                $q->selectRaw('1')
                                    ->from($this->tablePrefix() . 'postmeta as pm')
                                    ->whereColumn('pm.post_id', 'p.ID')
                                    ->where('pm.meta_key', 'gigtune_payment_status')
                                    ->where('pm.meta_value', 'AWAITING_PAYMENT_CONFIRMATION');
                            })->orWhere(function ($q): void {
                                $q->whereExists(function ($inner): void {
                                    $inner->selectRaw('1')
                                        ->from($this->tablePrefix() . 'postmeta as pm')
                                        ->whereColumn('pm.post_id', 'p.ID')
                                        ->where('pm.meta_key', 'gigtune_payment_reported_at')
                                        ->whereRaw('CAST(pm.meta_value AS UNSIGNED) > 0');
                                })->whereExists(function ($inner): void {
                                    $inner->selectRaw('1')
                                        ->from($this->tablePrefix() . 'postmeta as pm')
                                        ->whereColumn('pm.post_id', 'p.ID')
                                        ->where('pm.meta_key', 'gigtune_payment_status')
                                        ->whereRaw('UPPER(pm.meta_value) NOT IN (?, ?, ?, ?)', [
                                            'CONFIRMED_HELD_PENDING_COMPLETION',
                                            'ESCROW_FUNDED',
                                            'PAID_ESCROWED',
                                            'REJECTED_PAYMENT',
                                        ]);
                                });
                            });
                        });
                    })
                ),
            ],
            'payouts' => [
                'pending_items' => $this->loadPostTypeRows(
                    'gigtune_booking',
                    [
                        'gigtune_payout_booking_id',
                        'gigtune_payout_amount',
                        'gigtune_payout_status',
                        'gigtune_payout_required_at',
                        'gigtune_payout_paid_at',
                        'gigtune_payout_failure_reason',
                        'gigtune_booking_client_user_id',
                        'gigtune_booking_artist_profile_id',
                        'gigtune_payment_status',
                    ],
                    100,
                    $this->withDisplayResetScope('payouts', function ($query) use ($request): void {
                        $payout = trim((string) $request->query('payout', ''));
                        if ($payout !== '') {
                            $query->whereExists(function ($sub) use ($payout): void {
                                $sub->selectRaw('1')
                                    ->from($this->tablePrefix() . 'postmeta as pm')
                                    ->whereColumn('pm.post_id', 'p.ID')
                                    ->where('pm.meta_key', 'gigtune_payout_status')
                                    ->whereRaw('UPPER(pm.meta_value) = ?', [strtoupper($payout)]);
                            });
                            return;
                        }
                        $query->whereExists(function ($sub): void {
                            $sub->selectRaw('1')
                                ->from($this->tablePrefix() . 'postmeta as pm')
                                ->whereColumn('pm.post_id', 'p.ID')
                                ->where('pm.meta_key', 'gigtune_payout_status')
                                ->where('pm.meta_value', 'PENDING_MANUAL');
                        });
                    })
                ),
                'needs_review_items' => $this->loadPostTypeRows(
                    'gigtune_booking',
                    [
                        'gigtune_payout_status',
                        'gigtune_payout_failure_reason',
                        'gigtune_payment_status',
                        'gigtune_booking_status',
                    ],
                    40,
                    $this->withDisplayResetScope('payouts', function ($query): void {
                        $query->where(function ($sub): void {
                            $sub->whereExists(function ($q): void {
                                $q->selectRaw('1')
                                    ->from($this->tablePrefix() . 'postmeta as pm')
                                    ->whereColumn('pm.post_id', 'p.ID')
                                    ->where('pm.meta_key', 'gigtune_payout_status')
                                    ->whereRaw('UPPER(pm.meta_value) IN (?, ?, ?)', ['PENDING', 'FAILED', 'REVERSED']);
                            })->orWhere(function ($q): void {
                                $q->whereNotExists(function ($inner): void {
                                    $inner->selectRaw('1')
                                        ->from($this->tablePrefix() . 'postmeta as pm')
                                        ->whereColumn('pm.post_id', 'p.ID')
                                        ->where('pm.meta_key', 'gigtune_payout_status');
                                })->whereExists(function ($inner): void {
                                    $inner->selectRaw('1')
                                        ->from($this->tablePrefix() . 'postmeta as pm')
                                        ->whereColumn('pm.post_id', 'p.ID')
                                        ->where('pm.meta_key', 'gigtune_payment_status')
                                        ->where('pm.meta_value', 'CONFIRMED_HELD_PENDING_COMPLETION');
                                })->whereExists(function ($inner): void {
                                    $inner->selectRaw('1')
                                        ->from($this->tablePrefix() . 'postmeta as pm')
                                        ->whereColumn('pm.post_id', 'p.ID')
                                        ->where('pm.meta_key', 'gigtune_booking_status')
                                        ->where('pm.meta_value', 'COMPLETED_CONFIRMED');
                                });
                            });
                        });
                    })
                ),
            ],
            'bookings' => [
                'items' => $this->loadPostTypeRows(
                    'gigtune_booking',
                    [
                        'gigtune_booking_status',
                        'gigtune_booking_client_user_id',
                        'gigtune_booking_artist_profile_id',
                        'gigtune_booking_budget',
                        'gigtune_booking_event_date',
                        'gigtune_payment_status',
                        'gigtune_payout_status',
                        'gigtune_dispute_raised',
                        'gigtune_refund_status',
                        'gigtune_refund_checkout_id',
                        'gigtune_yoco_checkout_id',
                    ],
                    100,
                    $this->withDisplayResetScope('bookings', function ($query) use ($request): void {
                        $status = trim((string) $request->query('status', ''));
                        if ($status === '') {
                            return;
                        }
                        $query->whereExists(function ($sub) use ($status): void {
                            $sub->selectRaw('1')
                                ->from($this->tablePrefix() . 'postmeta as pm')
                                ->whereColumn('pm.post_id', 'p.ID')
                                ->where('pm.meta_key', 'gigtune_booking_status')
                                ->whereRaw('LOWER(pm.meta_value) = ?', [strtolower($status)]);
                        });
                    })
                ),
            ],
            'disputes' => [
                'items' => $this->loadPostTypeRows(
                    'gigtune_dispute',
                    [
                        'gigtune_dispute_booking_id',
                        'gigtune_dispute_status',
                        'gigtune_dispute_reason',
                        'gigtune_dispute_initiator_user_id',
                        'gigtune_dispute_initiator_role',
                        'gigtune_dispute_created_at',
                        'gigtune_dispute_admin_note',
                        'gigtune_dispute_resolved_at',
                    ],
                    100,
                    $this->withDisplayResetScope('disputes', function ($query) use ($request): void {
                        $status = trim((string) $request->query('status', ''));
                        if ($status === '') {
                            return;
                        }
                        $query->whereExists(function ($sub) use ($status): void {
                            $sub->selectRaw('1')
                                ->from($this->tablePrefix() . 'postmeta as pm')
                                ->whereColumn('pm.post_id', 'p.ID')
                                ->where('pm.meta_key', 'gigtune_dispute_status')
                                ->whereRaw('LOWER(pm.meta_value) = ?', [strtolower($status)]);
                        });
                    })
                ),
            ],
            'refunds' => [
                'items' => $this->loadPostTypeRows(
                    'gigtune_booking',
                    [
                        'gigtune_booking_client_user_id',
                        'gigtune_booking_artist_profile_id',
                        'gigtune_payment_status',
                        'gigtune_payout_status',
                        'gigtune_refund_status',
                        'gigtune_refund_checkout_id',
                        'gigtune_yoco_checkout_id',
                        'gigtune_refund_requested_by',
                        'gigtune_refund_requested_at',
                        'gigtune_refund_failure_reason',
                        'gigtune_booking_locked',
                    ],
                    100,
                    $this->withDisplayResetScope('refunds', function ($query): void {
                        $query->where(function ($sub): void {
                            $sub->whereExists(function ($q): void {
                                $q->selectRaw('1')
                                    ->from($this->tablePrefix() . 'postmeta as pm')
                                    ->whereColumn('pm.post_id', 'p.ID')
                                    ->where('pm.meta_key', 'gigtune_refund_status')
                                    ->whereRaw('UPPER(pm.meta_value) IN (?, ?, ?)', ['REQUESTED', 'PENDING', 'FAILED']);
                            })->orWhereExists(function ($q): void {
                                $q->selectRaw('1')
                                    ->from($this->tablePrefix() . 'postmeta as pm')
                                    ->whereColumn('pm.post_id', 'p.ID')
                                    ->where('pm.meta_key', 'gigtune_booking_locked')
                                    ->where('pm.meta_value', 'refund_pending');
                            });
                        });
                    })
                ),
            ],
            'kyc' => [
                'items' => $this->attachKycUserMeta($this->loadPostTypeRows(
                    ['gt_kyc_submission', 'gigtune_kyc_submission', 'gigtune_kyc_submissi'],
                    [
                        'gigtune_kyc_user_id',
                        'gigtune_kyc_role',
                        'gigtune_kyc_decision',
                        'gigtune_kyc_submitted_at',
                        'gigtune_kyc_reviewed_at',
                        'gigtune_kyc_reviewed_by',
                        'gigtune_kyc_review_reason',
                        'gigtune_kyc_decision_notes',
                        'gigtune_kyc_risk_score',
                        'gigtune_kyc_risk_flags',
                        'gigtune_kyc_documents',
                    ],
                    200,
                    $this->withDisplayResetScope('kyc', function ($query) use ($request): void {
                        $status = trim((string) $request->query('status', ''));
                        if ($status === '') {
                            return;
                        }
                        $query->whereExists(function ($sub) use ($status): void {
                            $sub->selectRaw('1')
                                ->from($this->tablePrefix() . 'postmeta as pm')
                                ->whereColumn('pm.post_id', 'p.ID')
                                ->where('pm.meta_key', 'gigtune_kyc_decision')
                                ->whereRaw('LOWER(pm.meta_value) = ?', [strtolower($status)]);
                        });
                    })
                )),
            ],
            'reports' => [
                'window_7' => $this->buildReportWindow(7, $this->getDisplayResetCutoffTimestamp('reports')),
                'window_30' => $this->buildReportWindow(30, $this->getDisplayResetCutoffTimestamp('reports')),
            ],
            default => [],
        };
    }

    private function normalizeDisplayResetTargets(array $targets): array
    {
        $allowed = array_keys(self::DISPLAY_RESET_OPTIONS);
        $normalized = [];
        foreach ($targets as $target) {
            $value = trim(strtolower((string) $target));
            if ($value === '' || !in_array($value, $allowed, true)) {
                continue;
            }
            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    private function displayResetItems(): array
    {
        $items = [];
        foreach (self::DISPLAY_RESET_OPTIONS as $key => $optionName) {
            $timestamp = $this->getDisplayResetCutoffTimestamp($key);
            $items[] = [
                'key' => $key,
                'label' => self::DISPLAY_RESET_LABELS[$key] ?? ucfirst($key),
                'option_name' => $optionName,
                'last_reset_at' => $timestamp > 0 ? now()->setTimestamp($timestamp)->format('Y-m-d H:i:s T') : null,
            ];
        }

        return $items;
    }

    private function withDisplayResetScope(string $target, ?callable $scope = null): \Closure
    {
        return function ($query) use ($target, $scope): void {
            $this->applyDisplayResetCutoff($query, $target);
            if ($scope !== null) {
                $scope($query);
            }
        };
    }

    private function applyDisplayResetCutoff($query, string $target): void
    {
        $cutoffTimestamp = $this->getDisplayResetCutoffTimestamp($target);
        if ($cutoffTimestamp <= 0) {
            return;
        }

        $query->where('p.post_date', '>=', now()->setTimestamp($cutoffTimestamp)->format('Y-m-d H:i:s'));
    }

    private function getDisplayResetCutoffTimestamp(string $target): int
    {
        $optionName = self::DISPLAY_RESET_OPTIONS[$target] ?? '';
        if ($optionName === '') {
            return 0;
        }

        $raw = trim($this->getOptionValue($optionName));
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return 0;
        }

        $timestamp = (int) $raw;
        return $timestamp > 0 ? $timestamp : 0;
    }

    private function loadAdminDashboardComplianceTab(Request $request): array
    {
        $scope = strtolower(trim((string) $request->query('user_scope', 'artists')));
        if (!in_array($scope, ['artists', 'clients'], true)) {
            $scope = 'artists';
        }

        $items = $this->loadAdminDashboardScopedUsers($scope);
        foreach ($items as &$item) {
            $userId = (int) ($item['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $item['profile_visibility_override'] = trim($this->getLatestUserMetaValue($userId, 'gigtune_profile_visibility_override'));
            if (!in_array($item['profile_visibility_override'], ['auto', 'force_visible', 'force_hidden'], true)) {
                $item['profile_visibility_override'] = 'auto';
            }
            $item['profile_visible_effective'] = $this->isProfileVisibleEffective($userId, $item['profile_visibility_override']);
        }
        unset($item);

        return [
            'user_scope' => $scope,
            'items' => $items,
        ];
    }

    private function loadAdminDashboardUsersTab(Request $request): array
    {
        $scope = strtolower(trim((string) $request->query('user_scope', 'artists')));
        if (!in_array($scope, ['artists', 'clients'], true)) {
            $scope = 'artists';
        }

        $userViewId = abs((int) $request->query('user_id', 0));
        $userViewSection = strtolower(trim((string) $request->query('section', '')));

        $payload = [
            'user_scope' => $scope,
            'user_view_id' => $userViewId,
            'user_view_section' => $userViewSection,
        ];

        if ($userViewId > 0) {
            $payload['user_detail'] = $this->loadAdminDashboardUserDetail($userViewId, $userViewSection);
            return $payload;
        }

        $payload['items'] = $this->loadAdminDashboardScopedUsers($scope);
        return $payload;
    }

    private function loadAdminDashboardScopedUsers(string $scope): array
    {
        $db = $this->wordpressDb();
        $users = $this->tablePrefix() . 'users';
        $usermeta = $this->tablePrefix() . 'usermeta';

        $queryBuilder = fn () => $db->table($users . ' as u')
            ->select(['u.ID', 'u.user_login', 'u.user_email', 'u.display_name', 'u.user_registered'])
            ->orderByDesc('u.user_registered')
            ->orderByDesc('u.ID')
            ->limit(80);

        if ($scope === 'artists') {
            $rows = $queryBuilder()
                ->whereExists(function ($query) use ($usermeta): void {
                    $query->selectRaw('1')
                        ->from($usermeta . ' as um')
                        ->whereColumn('um.user_id', 'u.ID')
                        ->where('um.meta_key', 'gigtune_artist_profile_id')
                        ->whereRaw('CAST(um.meta_value AS UNSIGNED) > 0');
                })
                ->get();
        } else {
            $rows = $queryBuilder()
                ->whereExists(function ($query) use ($usermeta): void {
                    $query->selectRaw('1')
                        ->from($usermeta . ' as um')
                        ->whereColumn('um.user_id', 'u.ID')
                        ->whereIn('um.meta_key', [$this->tablePrefix() . 'capabilities', 'capabilities'])
                        ->where('um.meta_value', 'like', '%gigtune_client%');
                })
                ->get();

            if ($rows->isEmpty()) {
                $rows = $queryBuilder()
                    ->whereExists(function ($query) use ($usermeta): void {
                        $query->selectRaw('1')
                            ->from($usermeta . ' as um')
                            ->whereColumn('um.user_id', 'u.ID')
                            ->where('um.meta_key', 'gigtune_client_profile_id')
                            ->whereRaw('CAST(um.meta_value AS UNSIGNED) > 0');
                    })
                    ->get();
            }
        }

        $items = [];
        foreach ($rows as $row) {
            $userId = (int) ($row->ID ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $roles = $this->getUserRolesById($userId);
            $compliance = $this->getUserComplianceSnapshot($userId);
            $complianceSummary = 'Compliance: ';
            $complianceSummary .= ($compliance['email_verified'] ? 'Email verified' : 'Email unverified');
            $complianceSummary .= ' | ';
            $complianceSummary .= ($compliance['policies_accepted'] ? 'Policies accepted' : 'Policies pending');
            $complianceSummary .= ' | ';
            $complianceSummary .= (string) ($compliance['kyc_label'] ?? 'Identity Verification unknown');

            $items[] = [
                'id' => $userId,
                'public_name' => $this->getUserPublicName($userId, (string) ($row->display_name ?? ''), (string) ($row->user_login ?? '')),
                'login' => (string) ($row->user_login ?? ''),
                'email' => (string) ($row->user_email ?? ''),
                'roles' => $roles,
                'compliance' => $compliance,
                'compliance_summary' => $complianceSummary,
                'view_url' => '/admin-dashboard/users?' . http_build_query([
                    'user_scope' => $scope,
                    'user_id' => $userId,
                ]),
            ];
        }

        return $items;
    }

    private function loadAdminDashboardUserDetail(int $userId, string $viewSection = ''): array
    {
        $row = $this->wordpressDb()
            ->table($this->tablePrefix() . 'users')
            ->select(['ID', 'user_login', 'user_email', 'display_name'])
            ->where('ID', $userId)
            ->first();

        if ($row === null) {
            return ['found' => false];
        }

        $roles = $this->getUserRolesById($userId);
        $artistProfile = $this->loadArtistProfileByUserId($userId);
        $clientProfile = $this->loadClientProfileByUserId($userId);
        $compliance = $this->getUserComplianceSnapshot($userId);

        $complianceNotes = [];
        if ($artistProfile !== null || in_array('gigtune_artist', $roles, true)) {
            $artistMissing = $this->getArtistMissingRequirementMessage($userId, $artistProfile, $compliance);
            if ($artistMissing !== '') {
                $complianceNotes[] = 'Artist readiness: ' . $artistMissing;
            }
        }
        if ($clientProfile !== null || in_array('gigtune_client', $roles, true)) {
            $clientMissing = $this->getClientMissingRequirementMessage($userId, $clientProfile, $compliance);
            if ($clientMissing !== '') {
                $complianceNotes[] = 'Client readiness: ' . $clientMissing;
            }
        }

        return [
            'found' => true,
            'id' => (int) ($row->ID ?? 0),
            'public_name' => $this->getUserPublicName((int) ($row->ID ?? 0), (string) ($row->display_name ?? ''), (string) ($row->user_login ?? '')),
            'login' => (string) ($row->user_login ?? ''),
            'email' => (string) ($row->user_email ?? ''),
            'roles' => $roles,
            'compliance' => $compliance,
            'compliance_notes' => $complianceNotes,
            'artist_profile' => $artistProfile,
            'client_profile' => $clientProfile,
            'view_section' => $viewSection,
        ];
    }

    private function loadArtistProfileByUserId(int $userId): ?array
    {
        $profileId = $this->resolveArtistProfileIdForUser($userId);
        if ($profileId <= 0) {
            return null;
        }

        $post = $this->wordpressDb()
            ->table($this->tablePrefix() . 'posts')
            ->select(['ID', 'post_type', 'post_status', 'post_title'])
            ->where('ID', $profileId)
            ->first();
        if ($post === null || (string) ($post->post_type ?? '') !== 'artist_profile' || (string) ($post->post_status ?? '') === 'trash') {
            return null;
        }

        $meta = $this->loadMetaMap([$profileId], [
            'gigtune_artist_price_min',
            'gigtune_artist_price_max',
            'gigtune_artist_base_area',
            'gigtune_artist_bank_account_name',
            'gigtune_artist_bank_account_number',
            'gigtune_artist_bank_code',
            'gigtune_artist_bank_name',
            'gigtune_artist_branch_code',
            'gigtune_artist_payout_preference',
            'gigtune_artist_availability_days',
            'gigtune_artist_travel_radius_km',
        ]);
        $entry = $meta[$profileId] ?? [];
        $branchCode = trim((string) ($entry['gigtune_artist_branch_code'] ?? ''));
        if ($branchCode === '') {
            $branchCode = trim((string) ($entry['gigtune_artist_bank_code'] ?? ''));
        }

        return [
            'id' => $profileId,
            'name' => (string) ($post->post_title ?? ''),
            'base_area' => (string) ($entry['gigtune_artist_base_area'] ?? ''),
            'price_min' => (string) ($entry['gigtune_artist_price_min'] ?? ''),
            'price_max' => (string) ($entry['gigtune_artist_price_max'] ?? ''),
            'skills' => $this->loadArtistSkillNames($profileId),
            'bank_name' => (string) ($entry['gigtune_artist_bank_name'] ?? ''),
            'bank_account_name' => (string) ($entry['gigtune_artist_bank_account_name'] ?? ''),
            'bank_account_number' => (string) ($entry['gigtune_artist_bank_account_number'] ?? ''),
            'branch_code' => $branchCode,
            'payout_preference' => (string) ($entry['gigtune_artist_payout_preference'] ?? ''),
            'availability_days' => $this->decodeMaybeSerialized((string) ($entry['gigtune_artist_availability_days'] ?? '')),
            'travel_radius_km' => (string) ($entry['gigtune_artist_travel_radius_km'] ?? ''),
        ];
    }

    private function loadClientProfileByUserId(int $userId): ?array
    {
        $profileId = abs((int) $this->getLatestUserMetaValue($userId, 'gigtune_client_profile_id'));
        if ($profileId <= 0 || !$this->isPostType($profileId, 'gt_client_profile')) {
            $profileId = (int) $this->findPostIdByTypeAndMeta('gt_client_profile', 'gigtune_client_user_id', (string) $userId);
        }
        if ($profileId <= 0) {
            return null;
        }

        $post = $this->wordpressDb()
            ->table($this->tablePrefix() . 'posts')
            ->select(['ID', 'post_type', 'post_title', 'post_content'])
            ->where('ID', $profileId)
            ->first();
        if ($post === null || (string) ($post->post_type ?? '') !== 'gt_client_profile') {
            return null;
        }

        $meta = $this->loadMetaMap([$profileId], [
            'gigtune_client_base_area',
            'gigtune_client_province',
            'gigtune_client_city',
            'gigtune_client_company',
            'gigtune_client_phone',
        ]);
        $entry = $meta[$profileId] ?? [];

        return [
            'id' => $profileId,
            'title' => (string) ($post->post_title ?? ''),
            'content' => (string) ($post->post_content ?? ''),
            'base_area' => (string) ($entry['gigtune_client_base_area'] ?? ''),
            'province' => (string) ($entry['gigtune_client_province'] ?? ''),
            'city' => (string) ($entry['gigtune_client_city'] ?? ''),
            'company' => (string) ($entry['gigtune_client_company'] ?? ''),
            'phone' => (string) ($entry['gigtune_client_phone'] ?? ''),
        ];
    }

    private function resolveArtistProfileIdForUser(int $userId): int
    {
        $profileId = abs((int) $this->getLatestUserMetaValue($userId, 'gigtune_artist_profile_id'));
        if ($profileId > 0 && $this->isPostType($profileId, 'artist_profile')) {
            return $profileId;
        }

        return (int) $this->findPostIdByTypeAndMeta('artist_profile', 'gigtune_user_id', (string) $userId);
    }

    private function loadArtistSkillNames(int $artistProfileId): array
    {
        if ($artistProfileId <= 0) {
            return [];
        }

        $prefix = $this->tablePrefix();
        $rows = $this->wordpressDb()
            ->table($prefix . 'term_relationships as tr')
            ->join($prefix . 'term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'tr.term_taxonomy_id')
            ->join($prefix . 'terms as t', 't.term_id', '=', 'tt.term_id')
            ->where('tr.object_id', $artistProfileId)
            ->whereIn('tt.taxonomy', ['performer_type', 'instrument_category', 'keyboard_parts', 'vocal_type', 'vocal_role'])
            ->orderBy('t.name')
            ->pluck('t.name')
            ->all();

        $clean = [];
        foreach ($rows as $row) {
            $name = trim((string) $row);
            if ($name !== '') {
                $clean[$name] = $name;
            }
        }

        return array_values($clean);
    }

    private function getUserRolesById(int $userId): array
    {
        $raw = $this->decodeMaybeSerialized($this->getLatestUserMetaValue($userId, $this->tablePrefix() . 'capabilities'));
        if (!is_array($raw)) {
            $raw = $this->decodeMaybeSerialized($this->getLatestUserMetaValue($userId, 'capabilities'));
        }
        if (!is_array($raw)) {
            return [];
        }

        $roles = [];
        foreach ($raw as $role => $enabled) {
            if (!is_string($role) || trim($role) === '') {
                continue;
            }
            if ((string) $enabled === '' || $enabled === false || $enabled === '0' || $enabled === 0) {
                continue;
            }
            $roles[] = trim($role);
        }

        return array_values(array_unique($roles));
    }

    private function getUserComplianceSnapshot(int $userId): array
    {
        if ($userId <= 0) {
            return [
                'email_verified' => false,
                'policies_accepted' => false,
                'kyc_status' => 'unsubmitted',
                'kyc_label' => $this->kycStatusLabel('unsubmitted'),
                'profile_visibility_override' => 'auto',
                'profile_visible_effective' => false,
                'all_verified' => false,
            ];
        }

        $emailVerified = $this->getLatestUserMetaValue($userId, 'gigtune_email_verified') === '1';
        try {
            $policyStatus = $this->users->getPolicyStatus($userId);
            $policiesAccepted = (bool) ($policyStatus['has_latest'] ?? false);
        } catch (\Throwable) {
            $policiesAccepted = false;
        }
        $kycStatus = $this->normalizeKycUserStatus($this->getLatestUserMetaValue($userId, 'gigtune_kyc_status'));
        $visibilityOverride = trim($this->getLatestUserMetaValue($userId, 'gigtune_profile_visibility_override'));
        if (!in_array($visibilityOverride, ['auto', 'force_visible', 'force_hidden'], true)) {
            $visibilityOverride = 'auto';
        }
        $profileVisible = $this->isProfileVisibleEffective($userId, $visibilityOverride);

        return [
            'email_verified' => $emailVerified,
            'policies_accepted' => $policiesAccepted,
            'kyc_status' => $kycStatus,
            'kyc_label' => $this->kycStatusLabel($kycStatus),
            'profile_visibility_override' => $visibilityOverride,
            'profile_visible_effective' => $profileVisible,
            'all_verified' => ($emailVerified && $policiesAccepted && $kycStatus === 'verified' && $profileVisible),
        ];
    }

    private function isProfileVisibleEffective(int $userId, string $visibilityOverride): bool
    {
        $visibilityOverride = trim($visibilityOverride);
        if ($visibilityOverride === 'force_visible') {
            return true;
        }
        if ($visibilityOverride === 'force_hidden') {
            return false;
        }

        $profileId = abs((int) $this->getLatestUserMetaValue($userId, 'gigtune_artist_profile_id'));
        if ($profileId <= 0) {
            $profileId = abs((int) $this->getLatestUserMetaValue($userId, 'gigtune_client_profile_id'));
        }
        if ($profileId <= 0) {
            return false;
        }

        $post = $this->wordpressDb()
            ->table($this->tablePrefix() . 'posts')
            ->select(['ID', 'post_status'])
            ->where('ID', $profileId)
            ->first();
        if ($post === null) {
            return false;
        }

        $status = strtolower(trim((string) ($post->post_status ?? '')));
        return in_array($status, ['publish', 'private'], true);
    }

    private function kycStatusLabel(string $status): string
    {
        $normalized = $this->normalizeKycUserStatus($status);
        $labels = [
            'unsubmitted' => 'Identity Verification not submitted',
            'pending' => 'Identity Verification pending review',
            'verified' => 'Identity Verification verified',
            'rejected' => 'Identity Verification rejected',
            'locked' => 'Identity Verification/security locked',
        ];

        return $labels[$normalized] ?? $labels['unsubmitted'];
    }

    private function getUserPublicName(int $userId, string $displayName = '', string $login = ''): string
    {
        $displayName = trim($displayName);
        if ($displayName !== '') {
            return $displayName;
        }

        $login = trim($login);
        if ($login !== '') {
            return $login;
        }

        return $userId > 0 ? ('User #' . $userId) : 'Unknown user';
    }

    private function getArtistMissingRequirementMessage(int $userId, ?array $artistProfile, array $compliance): string
    {
        if ($artistProfile === null) {
            return 'Create and link your artist profile.';
        }
        if (!($compliance['email_verified'] ?? false)) {
            return 'Verify your email before making your profile discoverable.';
        }
        if (!($compliance['policies_accepted'] ?? false)) {
            return 'Accept the latest policies to continue.';
        }

        $kycRequiredFor = $this->decodeMaybeSerialized($this->getLatestUserMetaValue($userId, 'gigtune_kyc_required_for'));
        if (is_array($kycRequiredFor) && in_array('artist_receive_requests', $kycRequiredFor, true) && ($compliance['kyc_status'] ?? '') !== 'verified') {
            return 'Identity Verification is required.';
        }

        if (trim((string) ($artistProfile['name'] ?? '')) === '') {
            return 'Add your profile name.';
        }
        if (trim((string) ($artistProfile['base_area'] ?? '')) === '') {
            return 'Set your base area.';
        }
        if (empty($artistProfile['skills']) || !is_array($artistProfile['skills'])) {
            return 'Select at least one performer type.';
        }
        if ((int) ($artistProfile['price_min'] ?? 0) <= 0) {
            return 'Set your minimum rate.';
        }
        if ((int) ($artistProfile['price_max'] ?? 0) <= 0) {
            return 'Set your maximum rate.';
        }
        if ((int) ($artistProfile['price_max'] ?? 0) < (int) ($artistProfile['price_min'] ?? 0)) {
            return 'Maximum rate must be greater than minimum rate.';
        }

        return '';
    }

    private function getClientMissingRequirementMessage(int $userId, ?array $clientProfile, array $compliance): string
    {
        if (!($compliance['policies_accepted'] ?? false)) {
            return 'Accept the latest policies to continue.';
        }
        if (!($compliance['email_verified'] ?? false)) {
            return 'Verify your email address before booking.';
        }

        $kycRequiredFor = $this->decodeMaybeSerialized($this->getLatestUserMetaValue($userId, 'gigtune_kyc_required_for'));
        if (is_array($kycRequiredFor) && in_array('client_requests', $kycRequiredFor, true) && ($compliance['kyc_status'] ?? '') !== 'verified') {
            return 'Identity Verification is required.';
        }

        if ($clientProfile === null) {
            return 'Complete your client profile before booking.';
        }
        if (trim((string) ($clientProfile['title'] ?? '')) === '') {
            return 'Add your display name in your client profile.';
        }
        if (trim((string) ($clientProfile['content'] ?? '')) === '') {
            return 'Add your about section in your client profile.';
        }
        if (trim((string) ($clientProfile['base_area'] ?? '')) === '') {
            return 'Add your base area in your client profile.';
        }
        if (trim((string) ($clientProfile['province'] ?? '')) === '') {
            return 'Add your province in your client profile.';
        }
        if (trim((string) ($clientProfile['city'] ?? '')) === '') {
            return 'Add your city in your client profile.';
        }
        $phone = preg_replace('/\D+/', '', (string) ($clientProfile['phone'] ?? '')) ?? '';
        if (strlen($phone) < 9) {
            return 'Add a valid phone number in your client profile.';
        }

        return '';
    }

    private function loadPostTypeRows(string|array $postTypes, array $metaKeys, int $limit = 50, ?callable $scope = null): array
    {
        $db = $this->wordpressDb();
        $posts = $this->tablePrefix() . 'posts';

        $query = $db->table($posts . ' as p')
            ->select([
                'p.ID',
                'p.post_title',
                'p.post_status',
                'p.post_type',
                'p.post_date',
            ]);

        if (is_array($postTypes)) {
            $query->whereIn('p.post_type', $postTypes);
        } else {
            $query->where('p.post_type', $postTypes);
        }

        if ($scope !== null) {
            $scope($query);
        }

        $rows = $query
            ->orderByDesc('p.post_date')
            ->orderByDesc('p.ID')
            ->limit(max(1, $limit))
            ->get();

        $ids = $rows->pluck('ID')->map(static fn ($id): int => (int) $id)->all();
        $metaMap = $this->loadMetaMap($ids, $metaKeys);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row->ID;
            $items[] = [
                'id' => $id,
                'title' => (string) ($row->post_title ?? ''),
                'status' => (string) ($row->post_status ?? ''),
                'type' => (string) ($row->post_type ?? ''),
                'date' => (string) ($row->post_date ?? ''),
                'meta' => $metaMap[$id] ?? [],
            ];
        }

        return $items;
    }

    private function loadMetaMap(array $postIds, array $metaKeys): array
    {
        if (empty($postIds) || empty($metaKeys)) {
            return [];
        }

        $postmeta = $this->tablePrefix() . 'postmeta';
        $rows = $this->wordpressDb()
            ->table($postmeta)
            ->select(['post_id', 'meta_key', 'meta_value', 'meta_id'])
            ->whereIn('post_id', $postIds)
            ->whereIn('meta_key', $metaKeys)
            ->orderByDesc('meta_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $postId = (int) $row->post_id;
            $metaKey = (string) $row->meta_key;
            if (!isset($map[$postId])) {
                $map[$postId] = [];
            }
            if (!array_key_exists($metaKey, $map[$postId])) {
                $map[$postId][$metaKey] = (string) $row->meta_value;
            }
        }

        return $map;
    }

    private function buildReportWindow(int $days, int $resetCutoffTimestamp = 0): array
    {
        $days = max(1, $days);
        $sincePoint = now()->subDays($days);
        if ($resetCutoffTimestamp > 0) {
            $resetPoint = now()->setTimestamp($resetCutoffTimestamp);
            if ($resetPoint->greaterThan($sincePoint)) {
                $sincePoint = $resetPoint;
            }
        }
        $since = $sincePoint->format('Y-m-d H:i:s');
        $db = $this->wordpressDb();
        $posts = $this->tablePrefix() . 'posts';
        $postmeta = $this->tablePrefix() . 'postmeta';

        $bookingsTotal = (int) $db->table($posts)
            ->where('post_type', 'gigtune_booking')
            ->where('post_date', '>=', $since)
            ->count('ID');

        $awaitingPayment = (int) $db->table($posts . ' as p')
            ->where('p.post_type', 'gigtune_booking')
            ->where('p.post_date', '>=', $since)
            ->whereExists(function ($query) use ($postmeta): void {
                $query->selectRaw('1')
                    ->from($postmeta . ' as pm')
                    ->whereColumn('pm.post_id', 'p.ID')
                    ->where('pm.meta_key', 'gigtune_payment_status')
                    ->where('pm.meta_value', 'AWAITING_PAYMENT_CONFIRMATION');
            })
            ->count('p.ID');

        $confirmedHeld = (int) $db->table($posts . ' as p')
            ->where('p.post_type', 'gigtune_booking')
            ->where('p.post_date', '>=', $since)
            ->whereExists(function ($query) use ($postmeta): void {
                $query->selectRaw('1')
                    ->from($postmeta . ' as pm')
                    ->whereColumn('pm.post_id', 'p.ID')
                    ->where('pm.meta_key', 'gigtune_payment_status')
                    ->whereIn('pm.meta_value', ['CONFIRMED_HELD_PENDING_COMPLETION', 'ESCROW_FUNDED', 'PAID_ESCROWED']);
            })
            ->count('p.ID');

        $payoutsPaid = (int) $db->table($posts . ' as p')
            ->where('p.post_type', 'gt_payout')
            ->where('p.post_date', '>=', $since)
            ->whereExists(function ($query) use ($postmeta): void {
                $query->selectRaw('1')
                    ->from($postmeta . ' as pm')
                    ->whereColumn('pm.post_id', 'p.ID')
                    ->where('pm.meta_key', 'gigtune_payout_status')
                    ->where('pm.meta_value', 'PAID');
            })
            ->count('p.ID');

        $payoutsPendingManual = (int) $db->table($posts . ' as p')
            ->where('p.post_type', 'gt_payout')
            ->where('p.post_date', '>=', $since)
            ->whereExists(function ($query) use ($postmeta): void {
                $query->selectRaw('1')
                    ->from($postmeta . ' as pm')
                    ->whereColumn('pm.post_id', 'p.ID')
                    ->where('pm.meta_key', 'gigtune_payout_status')
                    ->where('pm.meta_value', 'PENDING_MANUAL');
            })
            ->count('p.ID');

        $paymentRows = $this->loadPostTypeRows(
            'gt_payment',
            ['gigtune_amount_gross', 'gigtune_amount_fee', 'gigtune_amount_net'],
            500,
            function ($query) use ($since): void {
                $query->where('p.post_date', '>=', $since);
            }
        );

        $gross = 0.0;
        $fees = 0.0;
        $net = 0.0;
        foreach ($paymentRows as $item) {
            $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
            $gross += (float) ($meta['gigtune_amount_gross'] ?? 0);
            $fees += (float) ($meta['gigtune_amount_fee'] ?? 0);
            $net += (float) ($meta['gigtune_amount_net'] ?? 0);
        }

        return [
            'days' => $days,
            'total_bookings' => $bookingsTotal,
            'awaiting_payment_confirmation' => $awaitingPayment,
            'confirmed_held' => $confirmedHeld,
            'payouts_paid' => $payoutsPaid,
            'payouts_pending_manual' => $payoutsPendingManual,
            'gross' => $gross,
            'fees' => $fees,
            'net' => $net,
        ];
    }

    private function applyKycReviewDecision(
        int $submissionId,
        int $targetUserId,
        string $decision,
        string $notes,
        string $reviewReason,
        int $actorUserId
    ): array {
        $submissionId = abs($submissionId);
        $targetUserId = abs($targetUserId);
        $actorUserId = abs($actorUserId);
        if ($submissionId <= 0) {
            throw new \InvalidArgumentException('Invalid KYC submission.');
        }
        if ($actorUserId <= 0) {
            throw new \InvalidArgumentException('Invalid admin actor.');
        }

        $db = $this->wordpressDb();
        $posts = $this->tablePrefix() . 'posts';
        $users = $this->tablePrefix() . 'users';

        $submission = $db->table($posts)
            ->select(['ID', 'post_type'])
            ->where('ID', $submissionId)
            ->first();
        if ($submission === null || !$this->isKycSubmissionPostType((string) ($submission->post_type ?? ''))) {
            throw new \InvalidArgumentException('Invalid KYC submission.');
        }

        $submissionUserId = (int) $this->getLatestPostMetaValue($submissionId, 'gigtune_kyc_user_id');
        if ($targetUserId <= 0) {
            $targetUserId = $submissionUserId;
        }
        if ($targetUserId <= 0 || !$db->table($users)->where('ID', $targetUserId)->exists()) {
            throw new \InvalidArgumentException('Invalid KYC user.');
        }
        if ($submissionUserId > 0 && $submissionUserId !== $targetUserId) {
            throw new \InvalidArgumentException('KYC submission user mismatch.');
        }

        $allowed = ['approve', 'reject', 'needs_more_info', 'lock', 'pending'];
        if (!in_array($decision, $allowed, true)) {
            $decision = 'pending';
        }

        $notes = trim($notes);
        $reviewReason = trim($reviewReason);
        $timestamp = now()->format('Y-m-d H:i:s');

        return $db->transaction(function () use (
            $targetUserId,
            $submissionId,
            $decision,
            $notes,
            $reviewReason,
            $actorUserId,
            $timestamp
        ): array {
            $newStatus = 'pending';
            if ($decision === 'approve') {
                $newStatus = 'verified';
                $this->upsertUserMeta($targetUserId, 'gigtune_kyc_verified_at', $timestamp);
                $this->deleteUserMeta($targetUserId, 'gigtune_kyc_restart_pending');
                $this->deleteUserMeta($targetUserId, 'gigtune_kyc_locked_at');
                $this->deleteUserMeta($targetUserId, 'gigtune_kyc_rejected_at');
            } elseif ($decision === 'lock') {
                $newStatus = 'locked';
                $this->upsertUserMeta($targetUserId, 'gigtune_kyc_locked_at', $timestamp);
            } elseif (in_array($decision, ['reject', 'needs_more_info'], true)) {
                $newStatus = 'rejected';
                $this->upsertUserMeta($targetUserId, 'gigtune_kyc_rejected_at', $timestamp);
                $this->deleteUserMeta($targetUserId, 'gigtune_kyc_locked_at');
            } else {
                $this->deleteUserMeta($targetUserId, 'gigtune_kyc_locked_at');
            }

            $this->upsertPostMeta($submissionId, 'gigtune_kyc_reviewed_at', $timestamp);
            $this->upsertPostMeta($submissionId, 'gigtune_kyc_reviewed_by', (string) $actorUserId);
            $this->upsertPostMeta($submissionId, 'gigtune_kyc_decision', $decision);
            $this->upsertPostMeta($submissionId, 'gigtune_kyc_decision_notes', $notes);
            $this->upsertPostMeta($submissionId, 'gigtune_kyc_review_reason', $reviewReason);

            $statusNote = $notes !== '' ? $notes : $reviewReason;
            $previous = $this->normalizeKycUserStatus($this->getLatestUserMetaValue($targetUserId, 'gigtune_kyc_status'));
            $this->upsertUserMeta($targetUserId, 'gigtune_kyc_status', $newStatus);
            $this->upsertUserMeta($targetUserId, 'gigtune_kyc_updated_at', $timestamp);
            if ($statusNote !== '') {
                $this->upsertUserMeta($targetUserId, 'gigtune_kyc_note', $statusNote);
            }

            $audit = $this->decodeMaybeSerialized($this->getLatestUserMetaValue($targetUserId, 'gigtune_kyc_audit_log'));
            if (!is_array($audit)) {
                $audit = [];
            }
            $audit[] = [
                'timestamp' => $timestamp,
                'from' => $previous,
                'to' => $newStatus,
                'actor_user_id' => $actorUserId,
                'note' => $statusNote,
            ];
            if (count($audit) > 100) {
                $audit = array_slice($audit, -100);
            }
            $this->upsertUserMeta($targetUserId, 'gigtune_kyc_audit_log', serialize(array_values($audit)));
            $this->upsertUserMeta($targetUserId, 'gigtune_kyc_review_reason', $reviewReason);
            $this->upsertUserMeta($targetUserId, 'gigtune_kyc_latest_submission_id', (string) $submissionId);

            try {
                $this->createKycStatusNotification($targetUserId, $newStatus, $statusNote, $submissionId, $decision);
            } catch (\Throwable) {
                // Preserve review decision even if notification creation fails.
            }

            return [
                'submission_id' => $submissionId,
                'target_user_id' => $targetUserId,
                'decision' => $decision,
                'new_status' => $newStatus,
            ];
        });
    }

    private function isKycSubmissionPostType(string $postType): bool
    {
        return in_array(trim(strtolower($postType)), ['gt_kyc_submission', 'gigtune_kyc_submission', 'gigtune_kyc_submissi'], true);
    }

    private function attachKycUserMeta(array $items): array
    {
        $userIds = [];
        foreach ($items as $item) {
            $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
            $userId = (int) ($meta['gigtune_kyc_user_id'] ?? 0);
            if ($userId > 0) {
                $userIds[$userId] = $userId;
            }
        }
        if (empty($userIds)) {
            return $items;
        }

        $usersById = $this->wordpressDb()
            ->table($this->tablePrefix() . 'users')
            ->select(['ID', 'user_login', 'user_email', 'display_name'])
            ->whereIn('ID', array_values($userIds))
            ->get()
            ->keyBy('ID');

        $metaMap = $this->loadUserMetaMap(array_values($userIds), [
            'gigtune_kyc_status',
            'gigtune_kyc_updated_at',
            'gigtune_kyc_note',
            'gigtune_kyc_review_reason',
        ]);

        foreach ($items as $index => $item) {
            $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
            $userId = (int) ($meta['gigtune_kyc_user_id'] ?? 0);
            $userRow = $userId > 0 ? ($usersById->get($userId) ?? null) : null;
            $userExists = $userRow !== null;

            $decision = trim(strtolower((string) ($meta['gigtune_kyc_decision'] ?? '')));
            if ($decision === '') {
                $decision = 'pending';
            }

            $items[$index]['meta']['gigtune_kyc_decision'] = $decision;
            $items[$index]['user'] = [
                'exists' => $userExists,
                'id' => $userId,
                'login' => $userExists ? (string) ($userRow->user_login ?? '') : '',
                'email' => $userExists ? (string) ($userRow->user_email ?? '') : '',
                'public_name' => $userExists
                    ? $this->getUserPublicName(
                        $userId,
                        (string) ($userRow->display_name ?? ''),
                        (string) ($userRow->user_login ?? '')
                    )
                    : 'Unknown',
            ];
            $items[$index]['user_meta'] = $userId > 0 ? ($metaMap[$userId] ?? []) : [];
            $items[$index]['parsed'] = [
                'risk_score' => (int) ($meta['gigtune_kyc_risk_score'] ?? 0),
                'risk_flags' => $this->decodeMetaArray((string) ($meta['gigtune_kyc_risk_flags'] ?? '')),
                'documents' => $this->decodeMetaArray((string) ($meta['gigtune_kyc_documents'] ?? '')),
            ];
        }

        return $items;
    }

    private function decodeMetaArray(string $value): array
    {
        $decoded = $this->decodeMaybeSerialized($value);
        if (is_array($decoded)) {
            return $decoded;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }

        $json = json_decode($trimmed, true);
        return is_array($json) ? $json : [];
    }

    private function loadUserMetaMap(array $userIds, array $metaKeys): array
    {
        if (empty($userIds) || empty($metaKeys)) {
            return [];
        }

        $rows = $this->wordpressDb()
            ->table($this->tablePrefix() . 'usermeta')
            ->select(['user_id', 'meta_key', 'meta_value', 'umeta_id'])
            ->whereIn('user_id', $userIds)
            ->whereIn('meta_key', $metaKeys)
            ->orderByDesc('umeta_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            $metaKey = (string) $row->meta_key;
            if (!isset($map[$userId])) {
                $map[$userId] = [];
            }
            if (!array_key_exists($metaKey, $map[$userId])) {
                $map[$userId][$metaKey] = (string) $row->meta_value;
            }
        }

        return $map;
    }

    /**
     * @param array<int,int> $postIds
     * @param array<int,string> $metaKeys
     * @return array<int,array<string,mixed>>
     */
    private function postMetaMap(array $postIds, array $metaKeys): array
    {
        if ($postIds === [] || $metaKeys === []) {
            return [];
        }

        $rows = $this->wordpressDb()
            ->table($this->tablePrefix() . 'postmeta')
            ->select(['post_id', 'meta_key', 'meta_value', 'meta_id'])
            ->whereIn('post_id', $postIds)
            ->whereIn('meta_key', $metaKeys)
            ->orderByDesc('meta_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $postId = (int) $row->post_id;
            $metaKey = (string) $row->meta_key;
            if (!isset($map[$postId])) {
                $map[$postId] = [];
            }
            if (!array_key_exists($metaKey, $map[$postId])) {
                $map[$postId][$metaKey] = $this->decodeMaybeSerialized((string) $row->meta_value);
            }
        }

        return $map;
    }

    private function getLatestPostMetaValue(int $postId, string $metaKey): string
    {
        $value = $this->wordpressDb()
            ->table($this->tablePrefix() . 'postmeta')
            ->where('post_id', $postId)
            ->where('meta_key', $metaKey)
            ->orderByDesc('meta_id')
            ->value('meta_value');

        return is_string($value) ? $value : '';
    }

    private function getLatestUserMetaValue(int $userId, string $metaKey): string
    {
        $value = $this->wordpressDb()
            ->table($this->tablePrefix() . 'usermeta')
            ->where('user_id', $userId)
            ->where('meta_key', $metaKey)
            ->orderByDesc('umeta_id')
            ->value('meta_value');

        return is_string($value) ? $value : '';
    }

    private function upsertPostMeta(int $postId, string $metaKey, string $metaValue): void
    {
        $postId = abs($postId);
        if ($postId <= 0 || $metaKey === '') {
            return;
        }

        $db = $this->wordpressDb();
        $table = $this->tablePrefix() . 'postmeta';
        $updated = $db->table($table)
            ->where('post_id', $postId)
            ->where('meta_key', $metaKey)
            ->update(['meta_value' => $metaValue]);
        if ($updated > 0) {
            return;
        }

        $db->table($table)->insert([
            'post_id' => $postId,
            'meta_key' => $metaKey,
            'meta_value' => $metaValue,
        ]);
    }

    private function upsertUserMeta(int $userId, string $metaKey, string $metaValue): void
    {
        $userId = abs($userId);
        if ($userId <= 0 || $metaKey === '') {
            return;
        }

        $db = $this->wordpressDb();
        $table = $this->tablePrefix() . 'usermeta';
        $updated = $db->table($table)
            ->where('user_id', $userId)
            ->where('meta_key', $metaKey)
            ->update(['meta_value' => $metaValue]);
        if ($updated > 0) {
            return;
        }

        $db->table($table)->insert([
            'user_id' => $userId,
            'meta_key' => $metaKey,
            'meta_value' => $metaValue,
        ]);
    }

    private function deleteUserMeta(int $userId, string $metaKey): void
    {
        $userId = abs($userId);
        if ($userId <= 0 || $metaKey === '') {
            return;
        }

        $this->wordpressDb()
            ->table($this->tablePrefix() . 'usermeta')
            ->where('user_id', $userId)
            ->where('meta_key', $metaKey)
            ->delete();
    }

    private function decodeMaybeSerialized(string $value): mixed
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        if ($trimmed === 'N;' || preg_match('/^[aObisCd]:/', $trimmed) === 1) {
            $decoded = @unserialize($trimmed, ['allowed_classes' => false]);
            if ($decoded !== false || $trimmed === 'b:0;' || $trimmed === 'N;') {
                return $decoded;
            }
        }

        return $value;
    }

    private function normalizeKycUserStatus(string $status): string
    {
        $status = trim(strtolower($status));
        if (!in_array($status, ['unsubmitted', 'pending', 'verified', 'rejected', 'locked'], true)) {
            return 'unsubmitted';
        }

        return $status;
    }

    private function createKycStatusNotification(
        int $targetUserId,
        string $status,
        string $note,
        int $submissionId,
        string $decision
    ): void {
        $status = trim(strtolower($status));
        $decision = trim(strtolower($decision));
        $message = match ($status) {
            'verified' => 'Your Identity Verification (Know Your Customer Compliance) has been approved.',
            'rejected' => 'Your Identity Verification (Know Your Customer Compliance) was rejected. Please review the notes and resubmit.',
            'locked' => 'Your Identity Verification (Know Your Customer Compliance)/account is temporarily locked for security review.',
            'pending' => 'Your Identity Verification (Know Your Customer Compliance) submission is pending admin verification.',
            default => 'Your Identity Verification (Know Your Customer Compliance) status was updated.',
        };
        if ($note !== '') {
            $message .= ' Note: ' . $note;
        }

        $db = $this->wordpressDb();
        $posts = $this->tablePrefix() . 'posts';
        $nowLocal = now();
        $nowUtc = now('UTC');
        $title = function_exists('mb_substr')
            ? mb_substr($message, 0, 255)
            : substr($message, 0, 255);

        $notificationId = (int) $db->table($posts)->insertGetId([
            'post_author' => 0,
            'post_date' => $nowLocal->format('Y-m-d H:i:s'),
            'post_date_gmt' => $nowUtc->format('Y-m-d H:i:s'),
            'post_content' => '',
            'post_title' => $title,
            'post_excerpt' => '',
            'post_status' => 'publish',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_password' => '',
            'post_name' => '',
            'to_ping' => '',
            'pinged' => '',
            'post_modified' => $nowLocal->format('Y-m-d H:i:s'),
            'post_modified_gmt' => $nowUtc->format('Y-m-d H:i:s'),
            'post_content_filtered' => '',
            'post_parent' => 0,
            'guid' => '',
            'menu_order' => 0,
            'post_type' => 'gigtune_notification',
            'post_mime_type' => '',
            'comment_count' => 0,
        ]);
        if ($notificationId <= 0) {
            return;
        }

        $createdAt = (string) now()->timestamp;
        $meta = [
            'gigtune_notification_recipient_user_id' => (string) $targetUserId,
            'gigtune_notification_user_id' => (string) $targetUserId,
            'recipient_user_id' => (string) $targetUserId,
            'gigtune_notification_type' => 'system',
            'notification_type' => 'system',
            'gigtune_notification_object_type' => '',
            'object_type' => '',
            'gigtune_notification_object_id' => '0',
            'object_id' => '0',
            'gigtune_notification_title' => $message,
            'gigtune_notification_message' => $message,
            'message' => $message,
            'gigtune_notification_created_at' => $createdAt,
            'created_at' => $createdAt,
            'gigtune_notification_is_read' => '0',
            'gigtune_notification_read_at' => '0',
            'gigtune_notification_is_archived' => '0',
            'gigtune_notification_is_deleted' => '0',
            'is_read' => '0',
            'kyc_submission_id' => (string) $submissionId,
            'kyc_status' => $status,
            'kyc_decision' => $decision,
        ];
        foreach ($meta as $key => $value) {
            $this->upsertPostMeta($notificationId, $key, $value);
        }

        try {
            $this->mail->sendKycStatusEmail($targetUserId, $status, $message);
        } catch (\Throwable) {
            // Keep notification creation non-blocking for KYC review actions.
        }
    }

    private function toSentenceCase(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '-';
        }

        $normalized = preg_replace('/[_-]+/', ' ', $value) ?? $value;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = strtolower(trim($normalized));
        if ($normalized === '') {
            return '-';
        }
        $normalized = match ($normalized) {
            'paid escrowed' => 'paid - temporary holding',
            'escrow funded' => 'temporary holding funded',
            default => $normalized,
        };
        $normalized = preg_replace('/\bescrowed\b/i', 'temporary holding', $normalized) ?? $normalized;
        $normalized = preg_replace('/\bescrow\b/i', 'temporary holding', $normalized) ?? $normalized;
        $normalized = ucfirst($normalized);
        $normalized = preg_replace('/\bkyc\b/i', 'KYC', $normalized) ?? $normalized;
        $normalized = preg_replace('/\bpsa\b/i', 'PSA', $normalized) ?? $normalized;

        return $normalized;
    }

    private function performFactoryReset(int $adminUserId): array
    {
        $db = $this->wordpressDb();
        $prefix = $this->tablePrefix();
        $posts = $prefix . 'posts';
        $postmeta = $prefix . 'postmeta';
        $usermeta = $prefix . 'usermeta';
        $options = $prefix . 'options';

        $postTypes = [
            'artist_profile',
            'gigtune_booking',
            'gigtune_notification',
            'gigtune_message',
            'gigtune_dispute',
            'gt_payment',
            'gt_payout',
            'gigtune_psa',
            'gigtune_admin_log',
            'gt_client_profile',
            'gt_client_rating',
            'gt_kyc_submission',
            'gigtune_kyc_submission',
            'gigtune_kyc_submissi',
        ];

        return $db->transaction(function () use ($db, $posts, $postmeta, $usermeta, $options, $postTypes, $adminUserId): array {
            $postIds = $db->table($posts)
                ->whereIn('post_type', $postTypes)
                ->pluck('ID')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $postmetaDeleted = 0;
            if (!empty($postIds)) {
                $postmetaDeleted = (int) $db->table($postmeta)->whereIn('post_id', $postIds)->delete();
            }
            $postsDeleted = (int) $db->table($posts)->whereIn('post_type', $postTypes)->delete();

            $optionsDeleted = (int) $db->table($options)
                ->where('option_name', 'like', 'gigtune\_%')
                ->orWhere('option_name', 'like', '_transient\_gigtune\_%')
                ->orWhere('option_name', 'like', '_transient\_timeout\_gigtune\_%')
                ->delete();

            $usermetaDeleted = (int) $db->table($usermeta)
                ->where('meta_key', 'like', 'gigtune\_%')
                ->where('user_id', '<>', $adminUserId)
                ->delete();

            return [
                'posts_deleted' => $postsDeleted,
                'postmeta_deleted' => $postmetaDeleted,
                'options_deleted' => $optionsDeleted,
                'usermeta_deleted' => $usermetaDeleted,
            ];
        });
    }

    private function getOptionValue(string $optionName): string
    {
        $optionName = trim($optionName);
        if ($optionName === '') {
            return '';
        }

        $value = $this->wordpressDb()
            ->table($this->tablePrefix() . 'options')
            ->where('option_name', $optionName)
            ->value('option_value');

        return is_string($value) ? $value : '';
    }

    private function setOptionValue(string $optionName, string $optionValue): void
    {
        $optionName = trim($optionName);
        if ($optionName === '') {
            return;
        }

        $db = $this->wordpressDb();
        $table = $this->tablePrefix() . 'options';
        $updated = (int) $db->table($table)
            ->where('option_name', $optionName)
            ->update([
                'option_value' => $optionValue,
                'autoload' => 'no',
            ]);

        if ($updated > 0) {
            return;
        }

        $db->table($table)->insert([
            'option_name' => $optionName,
            'option_value' => $optionValue,
            'autoload' => 'no',
        ]);
    }

    private function tablePrefix(): string
    {
        return (string) config('gigtune.wordpress.table_prefix', 'wp_');
    }

    private function wordpressDb(): ConnectionInterface
    {
        $connection = (string) config('gigtune.wordpress.database_connection', 'wordpress');
        return DB::connection($connection);
    }
}
