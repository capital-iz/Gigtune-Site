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
        $body = "You requested email verification for your GigTune account.\n\n"
            . "Verification link:\n{$verifyUrl}\n\n"
            . "This link expires in 48 hours.";

        return $this->sendToUser($userId, $subject, $body, false);
    }

    public function sendPasswordResetEmail(int $userId, string $token): bool
    {
        $resetUrl = rtrim((string) config('app.url', ''), '/') . '/reset-password/?token=' . rawurlencode($token);
        $subject = 'GigTune: Password reset';
        $body = "A password reset was requested for your GigTune account.\n\n"
            . "Reset link:\n{$resetUrl}\n\n"
            . "If you did not request this, you can ignore this message.";

        return $this->sendToUser($userId, $subject, $body, false);
    }

    public function sendKycStatusEmail(int $userId, string $status, string $message): bool
    {
        $subject = 'GigTune: KYC status update (' . strtoupper(trim($status)) . ')';
        return $this->sendToUser($userId, $subject, $message, true, 'security');
    }

    private function sendToUser(int $userId, string $subject, string $body, bool $respectPreferences, ?string $preferenceCategory = null): bool
    {
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
            Mail::raw($body, static function ($message) use ($email, $subject): void {
                $message->to($email)->subject($subject);
            });
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
                foreach ($decoded as $value) {
                    if ((bool) $value) {
                        return true;
                    }
                }
                return false;
            }
        }

        return false;
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
