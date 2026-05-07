<?php
declare(strict_types=1);

namespace App\Models;

class TwoFactorModel
{
    private const DIGITS = 6;
    private const PERIOD = 30;
    private const WINDOW = 1;
    private const RECOVERY_COUNT = 10;

    public static function isEnabled(array $user): bool
    {
        return !empty($user['two_factor_enabled']) && trim((string)($user['two_factor_secret'] ?? '')) !== '';
    }

    public static function summary(array $user): array
    {
        $hashes = self::decodeRecoveryHashes((string)($user['two_factor_recovery_codes'] ?? '[]'));
        return [
            'enabled' => self::isEnabled($user),
            'method' => self::isEnabled($user) ? 'totp' : null,
            'confirmed_at' => trim((string)($user['two_factor_confirmed_at'] ?? '')) ?: null,
            'recovery_codes_remaining' => count($hashes),
        ];
    }

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function buildOtpAuthUri(array $user, string $secret): string
    {
        $issuer = trim(AP_NAME) !== '' ? AP_NAME : 'Starling';
        $label = sprintf('%s@%s', (string)($user['username'] ?? 'account'), AP_DOMAIN);
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label)
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1'
            . '&digits=' . self::DIGITS
            . '&period=' . self::PERIOD;
    }

    public static function encryptSecret(string $secret): string
    {
        if ($secret === '') return '';
        $key = self::encryptionKey();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false || $tag === '') {
            throw new \RuntimeException('Could not encrypt 2FA secret.');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decryptSecret(string $encoded): string
    {
        $encoded = trim($encoded);
        if ($encoded === '') return '';
        if (!str_starts_with($encoded, 'v1:')) return '';
        $raw = base64_decode(substr($encoded, 3), true);
        if ($raw === false || strlen($raw) < 29) return '';
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    public static function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(4)));
            $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
        }
        return $codes;
    }

    public static function hashRecoveryCodes(array $codes): string
    {
        $hashes = [];
        foreach ($codes as $code) {
            $normalized = self::normalizeRecoveryCode((string)$code);
            if ($normalized === '') continue;
            $hashes[] = password_hash($normalized, PASSWORD_BCRYPT);
        }
        return json_encode($hashes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    public static function verifyCode(array $user, string $code, bool $consume = true): bool
    {
        if (!self::isEnabled($user)) return false;
        $secret = self::decryptSecret((string)($user['two_factor_secret'] ?? ''));
        if ($secret === '') return false;
        return self::verifySecretCode($secret, $code, (int)($user['two_factor_last_used_step'] ?? 0), $consume, (string)($user['id'] ?? ''));
    }

    public static function verifySecretCode(string $secret, string $code, int $lastUsedStep = 0, bool $consume = false, string $userId = ''): bool
    {
        if ($secret === '') return false;
        $normalized = preg_replace('/\D+/', '', $code);
        if ($normalized === '' || strlen($normalized) !== self::DIGITS) return false;

        $currentStep = intdiv(time(), self::PERIOD);
        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $step = $currentStep + $offset;
            if ($step <= 0) continue;
            if ($consume && $lastUsedStep > 0 && $step <= $lastUsedStep) continue;
            if (hash_equals(self::totpAt($secret, $step), $normalized)) {
                if ($consume && $userId !== '') {
                    UserModel::update($userId, ['two_factor_last_used_step' => $step]);
                }
                return true;
            }
        }
        return false;
    }

    public static function consumeRecoveryCode(array $user, string $code): bool
    {
        if (!self::isEnabled($user)) return false;
        $normalized = self::normalizeRecoveryCode($code);
        if ($normalized === '') return false;

        $hashes = self::decodeRecoveryHashes((string)($user['two_factor_recovery_codes'] ?? '[]'));
        if (!$hashes) return false;

        $remaining = [];
        $matched = false;
        foreach ($hashes as $hash) {
            if (!$matched && password_verify($normalized, $hash)) {
                $matched = true;
                continue;
            }
            $remaining[] = $hash;
        }
        if (!$matched) return false;

        UserModel::update((string)$user['id'], [
            'two_factor_recovery_codes' => json_encode(array_values($remaining), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
        ]);
        return true;
    }

    public static function enableForUser(string $userId, string $secret, array $recoveryCodes): void
    {
        UserModel::update($userId, [
            'two_factor_enabled' => 1,
            'two_factor_secret' => self::encryptSecret($secret),
            'two_factor_confirmed_at' => now_iso(),
            'two_factor_recovery_codes' => self::hashRecoveryCodes($recoveryCodes),
            'two_factor_last_used_step' => 0,
        ]);
    }

    public static function disableForUser(string $userId): void
    {
        UserModel::update($userId, [
            'two_factor_enabled' => 0,
            'two_factor_secret' => '',
            'two_factor_confirmed_at' => '',
            'two_factor_recovery_codes' => '[]',
            'two_factor_last_used_step' => 0,
        ]);
    }

    public static function regenerateRecoveryCodes(string $userId): array
    {
        $codes = self::generateRecoveryCodes();
        UserModel::update($userId, [
            'two_factor_recovery_codes' => self::hashRecoveryCodes($codes),
        ]);
        return $codes;
    }

    public static function revokeOtherTokens(string $userId, ?string $keepToken = null): void
    {
        $sql = 'DELETE FROM oauth_tokens WHERE user_id=?';
        $params = [$userId];
        if ($keepToken !== null && $keepToken !== '') {
            $sql .= ' AND token<>?';
            $params[] = $keepToken;
        }
        DB::run($sql, $params);
    }

    private static function encryptionKey(): string
    {
        return hash('sha256', AP_SECURITY_SECRET . '|' . __CLASS__, true);
    }

    private static function decodeRecoveryHashes(string $json): array
    {
        $raw = json_decode($json, true);
        if (!is_array($raw)) return [];
        return array_values(array_filter(array_map(static fn($v) => is_string($v) ? $v : '', $raw), 'strlen'));
    }

    private static function normalizeRecoveryCode(string $code): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $code) ?? '');
        return strlen($normalized) === 8 ? $normalized : '';
    }

    private static function totpAt(string $secretBase32, int $step): string
    {
        $secret = self::base32Decode($secretBase32);
        if ($secret === '') return '';
        $counter = pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $counter, $secret, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $chunk = substr($hash, $offset, 4);
        $value = unpack('N', $chunk)[1] & 0x7FFFFFFF;
        $mod = 10 ** self::DIGITS;
        return str_pad((string)($value % $mod), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $raw): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($raw[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        $bitsLen = strlen($bits);
        for ($i = 0; $i < $bitsLen; $i += 5) {
            $chunk = substr($bits, $i, 5);
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $out .= $alphabet[bindec($chunk)];
        }
        return $out;
    }

    private static function base32Decode(string $encoded): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z2-7]+/', '', $encoded) ?? '');
        if ($clean === '') return '';
        $alphabet = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $bits = '';
        $len = strlen($clean);
        for ($i = 0; $i < $len; $i++) {
            if (!isset($alphabet[$clean[$i]])) return '';
            $bits .= str_pad(decbin($alphabet[$clean[$i]]), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        $bitsLen = strlen($bits);
        for ($i = 0; $i + 8 <= $bitsLen; $i += 8) {
            $out .= chr(bindec(substr($bits, $i, 8)));
        }
        return $out;
    }
}
