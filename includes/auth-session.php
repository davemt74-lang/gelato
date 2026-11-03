<?php
declare(strict_types=1);

/** A password change invalidates every session, including PIN-authenticated sessions. */
function app_session_credential_version(array $user): string
{
    return hash('sha256', (string)$user['id'] . "\0" . (string)$user['password_hash']
        . "\0" . (string)($user['password_changed_at'] ?? '')
        . "\0" . (string)($user['membership_updated_at'] ?? ''));
}

function app_session_is_current(array $session, array $user, array $security, int $now): bool
{
    $started = (int)($session['authenticated_at'] ?? 0);
    $lastActivity = (int)($session['last_activity_at'] ?? 0);
    $legacyLifetime = max(900, (int)($security['session_lifetime_seconds'] ?? 28800));
    $absolute = max(900, (int)($security['session_absolute_lifetime_seconds'] ?? $legacyLifetime));
    $idle = max(900, (int)($security['session_idle_timeout_seconds'] ?? $legacyLifetime));
    $version = $session['credential_version'] ?? null;

    // Pre-hardening sessions must sign in again; never invent a credential version for them.
    return (int)($session['user_id'] ?? 0) === (int)$user['id']
        && (int)($session['organization_id'] ?? 0) === (int)$user['organization_id']
        && $started > 0 && $started <= $now
        && $lastActivity >= $started && $lastActivity <= $now
        && $now - $started < $absolute
        && $now - $lastActivity < $idle
        && is_string($version) && hash_equals(app_session_credential_version($user), $version);
}

function app_session_forget_user(): void
{
    // Pending actions, CSRF tokens and browser-scope identity must not survive lost authentication.
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/**
 * All sign-in paths establish the same credential and lifetime contract.
 * Password sign-ins pass the exact verified hash to reject a concurrent credential change.
 */
function app_session_start_user(PDO $pdo, int $organizationId, int $userId,
    string $authMethod = 'password', ?string $expectedPasswordHash = null): void
{
    $q = $pdo->prepare("SELECT u.id, u.password_hash, u.password_changed_at, (u.locked_until>NOW(6)) AS login_locked,
            om.organization_id, om.updated_at AS membership_updated_at
        FROM users u JOIN organization_memberships om ON om.user_id=u.id
        WHERE u.id=? AND om.organization_id=? AND om.status='active'
          AND u.status='active' AND u.archived_at IS NULL LIMIT 1");
    $q->execute([$userId, $organizationId]);
    $user = $q->fetch();
    if (!$user
        || !empty($user['login_locked'])
        || ($expectedPasswordHash !== null && !hash_equals((string)$user['password_hash'], $expectedPasswordHash))) {
        throw new InvalidArgumentException('Your sign-in could not be completed. Please sign in again.');
    }

    app_boot_session();
    $_SESSION = [];
    session_regenerate_id(true);
    $now = time();
    $_SESSION = [
        'user_id' => $userId,
        'organization_id' => $organizationId,
        'authenticated_at' => $now,
        'last_activity_at' => $now,
        'credential_version' => app_session_credential_version($user),
        'auth_method' => $authMethod,
        'browser_state_id' => bin2hex(random_bytes(16)),
    ];
}

/** Count failures in SQL so concurrent password attempts cannot lose increments. */
function app_auth_record_failure(PDO $pdo, int $userId): void
{
    $pdo->prepare("UPDATE users
        SET locked_until=IF(failed_login_count>=4, DATE_ADD(NOW(6), INTERVAL 15 MINUTE), locked_until),
            failed_login_count=IF(failed_login_count>=4, 0, failed_login_count+1)
        WHERE id=? AND (locked_until IS NULL OR locked_until<=NOW(6))")->execute([$userId]);
}

/**
 * Consume a reset token while locking the user first, then the token. Other tokens for
 * the same user follow the same lock order, and no password is written after a stale check.
 */
function app_password_reset_complete(PDO $pdo, string $token, string $passwordHash,
    ?callable $afterPasswordChange = null): array
{
    $unavailable = 'This password-reset link is invalid, expired, or has already been used.';
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
        throw new InvalidArgumentException($unavailable);
    }
    $tokenHash = hash('sha256', $token);
    $lookup = $pdo->prepare('SELECT user_id FROM password_reset_tokens WHERE token_hash=? LIMIT 1');
    $lookup->execute([$tokenHash]);
    $userId = (int)$lookup->fetchColumn();
    if ($userId < 1) throw new InvalidArgumentException($unavailable);

    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        $userLock = $pdo->prepare("SELECT u.id, om.organization_id FROM users u
            JOIN organization_memberships om ON om.user_id=u.id AND om.status='active'
            WHERE u.id=? AND u.status='active' AND u.archived_at IS NULL
            ORDER BY om.id LIMIT 1 FOR UPDATE");
        $userLock->execute([$userId]);
        $user = $userLock->fetch();
        if (!$user) throw new InvalidArgumentException($unavailable);

        $tokenLock = $pdo->prepare('SELECT id FROM password_reset_tokens
            WHERE token_hash=? AND user_id=? AND used_at IS NULL AND expires_at>NOW(6)
            LIMIT 1 FOR UPDATE');
        $tokenLock->execute([$tokenHash, $userId]);
        if (!$tokenLock->fetchColumn()) throw new InvalidArgumentException($unavailable);

        $pdo->prepare('UPDATE users SET password_hash=?, password_changed_at=NOW(6),
            failed_login_count=0, locked_until=NULL WHERE id=?')->execute([$passwordHash, $userId]);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW(6)
            WHERE user_id=? AND used_at IS NULL')->execute([$userId]);
        $identity = ['user_id' => $userId, 'organization_id' => (int)$user['organization_id']];
        if ($afterPasswordChange !== null) $afterPasswordChange($identity);
        app_audit($pdo, $identity['organization_id'], $userId,
            'authentication.password_reset_completed', 'user', (string)$userId);
        if ($owns) $pdo->commit();
        return $identity;
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
