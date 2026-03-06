<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class GigTuneMailService
{
    private string $connectionName;
    private string $tablePrefix;

    public function __construct(
        private readonly WordPressUserService $users,
    ) {
        $this->connectionName = (string) config('gigtune.wordpress.database_connection', 'wordpress');
        $this->tablePrefix = (string) config('gigtune.wordpress.table_prefix', 'wp_');
    }

    public function auditConfiguration(): array
    {
        $mailer = (string) config('mail.default', '');
        $fromAddress = trim((string) config('mail.from.address', ''));
        $fromName = trim((string) config('mail.from.name', ''));
        $host = trim((string) config('mail.mailers.smtp.host', ''));
        $port = (int) config('mail.mailers.smtp.port', 0);
        $username = trim((string) config('mail.mailers.smtp.username', ''));
        $password = trim((string) config('mail.mailers.smtp.password', ''));

        $warnings = [];
        if ($mailer === 'log') {
            $warnings[] = 'MAIL_MAILER is set to log; emails are recorded to logs only.';
        }
        if ($fromAddress === '' || in_array(strtolower($fromAddress), ['hello@example.com', 'example@example.com'], true)) {
            $warnings[] = 'MAIL_FROM_ADDRESS is still a placeholder.';
        }
        if ($mailer === 'smtp' && ($host === '' || $port <= 0 || $username === '' || $password === '')) {
            $warnings[] = 'SMTP mailer is active but SMTP credentials are incomplete.';
        }

        return [
            'mailer' => $mailer,
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'smtp_host' => $host,
            'smtp_port' => $port,
            'warnings' => $warnings,
        ];
    }

    public function sendVerificationEmail(int $userId, string $token): bool
    {
        $verifyUrl = rtrim((string) config('app.url', ''), '/') . '/verify-email/?token=' . rawurlencode($token);
        $subject = 'GigTune: Verify your email';
        $intro = 'Hi, please verify your email address to complete your GigTune account setup.';
        $footer = ['If you did not create this account, you can safely ignore this email.'];
        $html = $this->renderEmailTemplate('Verify your email', $intro, $verifyUrl, 'Verify email', $footer);
        $text = "Verify your email:\n{$verifyUrl}\n\nThis link expires in 48 hours.";

        return $this->sendToUser($userId, $subject, $text, false, null, $html);
    }

    public function sendPasswordResetEmail(int $userId, string $token): bool
    {
        $resetUrl = rtrim((string) config('app.url', ''), '/') . '/reset-password/?token=' . rawurlencode($token);
        $subject = 'GigTune: Password reset';
        $intro = 'A password reset was requested for your GigTune account.';
        $footer = ['If you did not request this, you can ignore this email.'];
        $html = $this->renderEmailTemplate('Reset your password', $intro, $resetUrl, 'Reset password', $footer);
        $text = "Reset your password:\n{$resetUrl}";

        return $this->sendToUser($userId, $subject, $text, false, null, $html);
    }

    public function sendKycStatusEmail(int $userId, string $status, string $message): bool
    {
        $statusLabel = strtoupper(trim($status));
        $subject = 'GigTune: KYC status update (' . ($statusLabel !== '' ? $statusLabel : 'UPDATED') . ')';
        $url = rtrim((string) config('app.url', ''), '/') . '/kyc-status/';
        $html = $this->renderEmailTemplate(
            'KYC status update',
            trim($message) !== '' ? trim($message) : 'Your KYC status was updated.',
            $url,
            'View KYC status',
            ['You can update email preferences in Notification Settings.']
        );
        $text = trim($message) !== '' ? trim($message) : 'Your KYC status was updated.';

        return $this->sendToUser($userId, $subject, $text, true, 'security', $html);
    }

    /** @param array<string,mixed> $context */
    public function sendNotificationEmail(int $recipientUserId, string $type, string $message, array $context = []): bool
    {
        $message = trim($message);
        if ($recipientUserId <= 0 || $message === '') {
            return false;
        }

        $category = $this->notificationTypeToCategory($type);
        $title = $message;
        $actionUrl = $this->notificationActionUrl($context);
        $subject = '[' . ucfirst($category) . '] ' . $title;
        $html = $this->renderEmailTemplate(
            $title,
            $message,
            $actionUrl,
            'View in GigTune',
            ['You can update email preferences in Notification Settings.']
        );
        $text = $message . ($actionUrl !== '' ? ("\n\n" . $actionUrl) : '');

        return $this->sendToUser($recipientUserId, $subject, $text, true, $category, $html);
    }

    private function sendToUser(
        int $userId,
        string $subject,
        string $body,
        bool $respectPreferences,
        ?string $preferenceCategory = null,
        ?string $htmlBody = null
    ): bool {
        $userId = abs($userId);
        if ($userId <= 0) {
            return false;
        }

        $user = $this->users->getUserById($userId);
        if (!is_array($user)) {
            return false;
        }

        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if ($respectPreferences && !$this->allowsEmailAlerts($userId, $preferenceCategory)) {
            return false;
        }

        try {
            if (is_string($htmlBody) && trim($htmlBody) !== '') {
                Mail::send([], [], static function ($message) use ($email, $subject, $htmlBody): void {
                    $message->to($email)->subject($subject);
                    $message->html($htmlBody);
                });
            } else {
                Mail::raw($body, static function ($message) use ($email, $subject): void {
                    $message->to($email)->subject($subject);
                });
            }
            return true;
        } catch (\Throwable $throwable) {
            Log::warning('GigTune mail send failed', [
                'user_id' => $userId,
                'email' => $email,
                'subject' => $subject,
                'error' => $throwable->getMessage(),
            ]);
            return false;
        }
    }

    private function allowsEmailAlerts(int $userId, ?string $category = null): bool
    {
        $category = strtolower(trim((string) $category));
        $raw = trim($this->latestUserMeta($userId, 'gigtune_notify_email'));
        if ($raw === '1') {
            return true;
        }
        if ($raw === '0') {
            return false;
        }

        $prefsRaw = trim($this->latestUserMeta($userId, 'gigtune_notification_email_preferences'));
        if ($prefsRaw !== '') {
            $decoded = json_decode($prefsRaw, true);
            if (!is_array($decoded)) {
                $decoded = @unserialize($prefsRaw, ['allowed_classes' => false]);
            }
            if (is_array($decoded)) {
                if ($category !== '' && array_key_exists($category, $decoded)) {
                    return (bool) $decoded[$category];
                }
                if (array_key_exists('email', $decoded)) {
                    return (bool) $decoded['email'];
                }
            }
        }

        $defaults = $this->defaultNotificationEmailPreferences();
        if ($category !== '' && array_key_exists($category, $defaults)) {
            return (bool) $defaults[$category];
        }
        return true;
    }

    /** @return array<string,bool> */
    private function defaultNotificationEmailPreferences(): array
    {
        return [
            'booking' => true,
            'psa' => true,
            'message' => true,
            'payment' => true,
            'dispute' => true,
            'security' => true,
        ];
    }

    private function notificationTypeToCategory(string $type): string
    {
        $type = strtolower(trim($type));
        if (in_array($type, ['security', 'booking_flagged', 'system'], true)) {
            return 'security';
        }
        if ($type === 'psa') {
            return 'psa';
        }
        if (in_array($type, ['payment', 'payout'], true)) {
            return 'payment';
        }
        if ($type === 'message') {
            return 'message';
        }
        if (in_array($type, ['dispute', 'refund'], true)) {
            return 'dispute';
        }
        return 'booking';
    }

    /** @param array<string,mixed> $context */
    private function notificationActionUrl(array $context): string
    {
        $explicit = trim((string) ($context['url'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $base = rtrim((string) config('app.url', ''), '/');
        if ($base === '') {
            return '';
        }

        $objectType = strtolower(trim((string) ($context['object_type'] ?? '')));
        $objectId = (int) ($context['object_id'] ?? 0);
        if ($objectType === 'booking' && $objectId > 0) {
            return $base . '/messages/?booking_id=' . $objectId;
        }
        if ($objectType === 'psa' && $objectId > 0) {
            return $base . '/posts-page/?psa_id=' . $objectId;
        }

        return $base . '/notifications/';
    }

    /** @param array<int,string> $footerLines */
    private function renderEmailTemplate(string $title, string $intro, string $ctaUrl = '', string $ctaLabel = '', array $footerLines = []): string
    {
        $titleEscaped = $this->escape($title);
        $introEscaped = $this->escape($intro);
        $ctaUrlEscaped = $this->escape($ctaUrl);
        $ctaLabelEscaped = $this->escape($ctaLabel);

        $footerHtml = '';
        foreach ($footerLines as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $footerHtml .= '<p style="margin:0 0 8px 0;color:#cbd5e1;font-size:14px;line-height:1.5;">' . $this->escape($line) . '</p>';
            }
        }

        $ctaHtml = '';
        if ($ctaUrlEscaped !== '' && $ctaLabelEscaped !== '') {
            $ctaHtml = '<p style="margin:18px 0 0 0;"><a href="' . $ctaUrlEscaped . '" style="display:inline-block;padding:12px 18px;border-radius:10px;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:600;">' . $ctaLabelEscaped . '</a></p>';
        }

        return '<!doctype html><html><body style="margin:0;padding:24px;background:#0f172a;font-family:Arial,sans-serif;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;margin:0 auto;background:#111827;border:1px solid rgba(255,255,255,0.1);border-radius:16px;">'
            . '<tr><td style="padding:24px;">'
            . '<p style="margin:0 0 12px 0;color:#93c5fd;font-size:12px;letter-spacing:.08em;text-transform:uppercase;">GIGTUNE</p>'
            . '<h1 style="margin:0 0 12px 0;color:#ffffff;font-size:24px;line-height:1.2;">' . $titleEscaped . '</h1>'
            . '<p style="margin:0;color:#cbd5e1;font-size:15px;line-height:1.6;">' . $introEscaped . '</p>'
            . $ctaHtml
            . '<div style="margin-top:20px;">' . $footerHtml . '</div>'
            . '</td></tr></table></body></html>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function latestUserMeta(int $userId, string $key): string
    {
        $value = $this->db()
            ->table($this->tablePrefix . 'usermeta')
            ->where('user_id', $userId)
            ->where('meta_key', $key)
            ->orderByDesc('umeta_id')
            ->value('meta_value');

        return is_string($value) ? $value : '';
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->connectionName);
    }
}
