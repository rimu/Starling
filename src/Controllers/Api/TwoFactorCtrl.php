<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\{TwoFactorModel, UserModel};

class TwoFactorCtrl
{
    private const SETUP_TTL = 600;

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('ap_2fa');
            session_set_cookie_params([
                'path' => '/',
                'secure' => is_https_request(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public function status(array $p): void
    {
        $user = require_auth();
        $this->startSession();
        $pending = $this->pendingSetupFor((string)$user['id']);
        json_out(array_merge(TwoFactorModel::summary($user), [
            'pending_setup' => $pending !== null,
        ]));
    }

    public function beginSetup(array $p): void
    {
        $user = require_auth();
        $d = req_body();
        $password = (string)($d['current_password'] ?? '');
        if ($password === '' || !password_verify($password, (string)($user['password'] ?? ''))) {
            err_out('Current password is incorrect.', 422);
        }
        if (TwoFactorModel::isEnabled($user) && !$this->verifyChallenge($user, $d)) {
            err_out('Invalid authenticator or recovery code.', 422);
        }

        $this->startSession();
        $secret = TwoFactorModel::generateSecret();
        $_SESSION['two_factor_setup'] = [
            'user_id' => (string)$user['id'],
            'secret' => $secret,
            'started_at' => time(),
        ];
        session_write_close();

        json_out([
            'enabled' => TwoFactorModel::isEnabled($user),
            'pending_setup' => true,
            'secret' => $secret,
            'otpauth_uri' => TwoFactorModel::buildOtpAuthUri($user, $secret),
        ]);
    }

    public function cancelSetup(array $p): void
    {
        $user = require_auth();
        $this->startSession();
        $pending = $this->pendingSetupFor((string)$user['id']);
        if ($pending) {
            unset($_SESSION['two_factor_setup']);
            session_write_close();
        }
        json_out(array_merge(TwoFactorModel::summary($user), ['pending_setup' => false]));
    }

    public function confirmSetup(array $p): void
    {
        $user = require_auth();
        $d = req_body();
        $this->startSession();
        $pending = $this->pendingSetupFor((string)$user['id']);
        if (!$pending) {
            err_out('No pending 2FA setup. Start again.', 422);
        }
        $code = (string)($d['code'] ?? '');
        if (!TwoFactorModel::verifySecretCode((string)$pending['secret'], $code)) {
            err_out('Invalid authenticator code.', 422);
        }

        $codes = TwoFactorModel::generateRecoveryCodes();
        TwoFactorModel::enableForUser((string)$user['id'], (string)$pending['secret'], $codes);
        unset($_SESSION['two_factor_setup']);
        session_write_close();

        $fresh = UserModel::byId((string)$user['id']) ?? $user;
        json_out(array_merge(TwoFactorModel::summary($fresh), [
            'recovery_codes' => $codes,
        ]));
    }

    public function disable(array $p): void
    {
        $user = require_auth();
        if (!TwoFactorModel::isEnabled($user)) {
            json_out(TwoFactorModel::summary($user));
        }

        $d = req_body();
        $password = (string)($d['current_password'] ?? '');
        if ($password === '' || !password_verify($password, (string)($user['password'] ?? ''))) {
            err_out('Current password is incorrect.', 422);
        }
        if (!$this->verifyChallenge($user, $d)) {
            err_out('Invalid authenticator or recovery code.', 422);
        }

        $ctx = auth_context();
        $keepToken = (string)(($ctx['token']['token'] ?? '') ?: '');
        TwoFactorModel::disableForUser((string)$user['id']);
        TwoFactorModel::revokeOtherTokens((string)$user['id'], $keepToken);
        json_out(TwoFactorModel::summary(UserModel::byId((string)$user['id']) ?? $user));
    }

    public function regenerateRecoveryCodes(array $p): void
    {
        $user = require_auth();
        if (!TwoFactorModel::isEnabled($user)) {
            err_out('Two-factor authentication is not enabled.', 422);
        }

        $d = req_body();
        $password = (string)($d['current_password'] ?? '');
        if ($password === '' || !password_verify($password, (string)($user['password'] ?? ''))) {
            err_out('Current password is incorrect.', 422);
        }
        if (!$this->verifyChallenge($user, $d)) {
            err_out('Invalid authenticator or recovery code.', 422);
        }

        $codes = TwoFactorModel::regenerateRecoveryCodes((string)$user['id']);
        $fresh = UserModel::byId((string)$user['id']) ?? $user;
        json_out(array_merge(TwoFactorModel::summary($fresh), [
            'recovery_codes' => $codes,
        ]));
    }

    private function verifyChallenge(array $user, array $d): bool
    {
        $code = (string)($d['code'] ?? '');
        if ($code !== '' && TwoFactorModel::verifyCode($user, $code, true)) {
            return true;
        }
        $recovery = (string)($d['recovery_code'] ?? '');
        return $recovery !== '' && TwoFactorModel::consumeRecoveryCode($user, $recovery);
    }

    private function pendingSetupFor(string $userId): ?array
    {
        $pending = $_SESSION['two_factor_setup'] ?? null;
        if (!is_array($pending)) return null;
        if (($pending['user_id'] ?? '') !== $userId) return null;
        $startedAt = (int)($pending['started_at'] ?? 0);
        if ($startedAt < (time() - self::SETUP_TTL)) {
            unset($_SESSION['two_factor_setup']);
            return null;
        }
        $secret = trim((string)($pending['secret'] ?? ''));
        return $secret === '' ? null : $pending;
    }
}
