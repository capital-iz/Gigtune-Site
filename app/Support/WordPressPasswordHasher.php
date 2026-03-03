<?php

namespace App\Support;

final class WordPressPasswordHasher
{
    public function hash(string $password): string
    {
        $preHashed = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));
        return '$wp' . password_hash($preHashed, PASSWORD_BCRYPT);
    }

    public function check(string $password, string $storedHash): bool
    {
        $storedHash = trim($storedHash);
        if ($storedHash === '') {
            return false;
        }

        // WordPress modern hash wrapper: "$wp" + bcrypt(pre-hashed password).
        if (str_starts_with($storedHash, '$wp')) {
            $innerHash = substr($storedHash, 3);
            if ($innerHash === '') {
                return false;
            }

            $preHashed = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));
            return password_verify($preHashed, $innerHash);
        }

        // Legacy phpass hashes still exist on older accounts.
        if (str_starts_with($storedHash, '$P$') || str_starts_with($storedHash, '$H$')) {
            return $this->verifyPhpass($password, $storedHash);
        }

        // Extremely old WordPress installs may still have MD5 hashes.
        if (strlen($storedHash) === 32 && ctype_xdigit($storedHash)) {
            return hash_equals(strtolower($storedHash), md5($password));
        }

        return password_verify($password, $storedHash);
    }

    private function verifyPhpass(string $password, string $storedHash): bool
    {
        $wpRoot = trim((string) config('gigtune.wordpress.root', ''));
        if ($wpRoot === '') {
            return false;
        }

        $phpassPath = rtrim($wpRoot, '\\/') . DIRECTORY_SEPARATOR . 'wp-includes' . DIRECTORY_SEPARATOR . 'class-phpass.php';
        if (!is_file($phpassPath)) {
            return false;
        }

        require_once $phpassPath;
        if (!class_exists('PasswordHash', false)) {
            return false;
        }

        $hasher = new \PasswordHash(8, true);
        return (bool) $hasher->CheckPassword($password, $storedHash);
    }
}
