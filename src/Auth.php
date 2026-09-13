<?php
declare(strict_types=1);

namespace KidsPoint;

/** セッション・CSRF・PIN/パスワードのロックアウト・保持トークンをまとめる */
final class Auth
{
    public const CHILD_MAX = 5;
    public const CHILD_WINDOW = 900;   // 15分
    public const CHILD_LOCK = 900;     // 15分
    public const PARENT_MAX = 5;
    public const PARENT_WINDOW = 900;
    public const PARENT_LOCK = 900;
    public const IP_MAX = 20;
    public const IP_WINDOW = 900;
    public const IP_LOCK = 900;

    public const REMEMBER_COOKIE = 'kp_remember';
    public const REMEMBER_DAYS = 30;
    // ローテーションで差し替えた直後の古いトークンを、この秒数だけ有効のまま残す
    // （同じ cookie を持つリクエストが同時に2本来たときの競合対策）
    public const REMEMBER_GRACE_SECONDS = 60;
    // login_failures のバケットを整理するとき、これより古い試行は無視してよいとみなす
    // （CHILD_WINDOW/PARENT_WINDOW/IP_WINDOW のうち最大のもの）
    public const PRUNE_WINDOW_SECONDS = 900;

    public static function bootstrapSession(): void
    {
        $path = getenv('KIDS_POINT_COOKIE_PATH') ?: '/kids-point/';
        $secure = getenv('KIDS_POINT_INSECURE_COOKIES') !== '1';
        session_name('KPSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $path,
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    }

    public static function csrfToken(): string
    {
        return $_SESSION['csrf'] ?? '';
    }

    public static function requireCsrf(): void
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $sent = null;
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'X-CSRF-Token') === 0) {
                $sent = $v;
                break;
            }
        }
        if ($sent === null) {
            $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        }
        $expected = self::csrfToken();
        if (!is_string($sent) || $expected === '' || !hash_equals($expected, $sent)) {
            throw new ApiError(403, 'csrf', 'CSRF トークンが不正です');
        }
    }

    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id'], $_SESSION['role']);
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            throw new ApiError(401, 'not_logged_in', 'ログインしてください');
        }
    }

    public static function requireParent(): void
    {
        self::requireLogin();
        if ($_SESSION['role'] !== 'parent') {
            throw new ApiError(403, 'forbidden', '親のみが行える操作です');
        }
    }

    public static function requireChild(): string
    {
        self::requireLogin();
        if ($_SESSION['role'] !== 'child') {
            throw new ApiError(403, 'forbidden', '子のみが行える操作です');
        }
        return (string) $_SESSION['user_id'];
    }

    public static function login(string $userId, string $role): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['role'] = $role;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    // ---- ロックアウト（データは JSON 側の login_failures に保持） ----

    public static function assertNotLocked(array $data, string $bucketKey, int $now): void
    {
        $bucket = $data['login_failures'][$bucketKey] ?? null;
        if ($bucket && !empty($bucket['locked_until']) && $bucket['locked_until'] > $now) {
            throw new ApiError(429, 'locked', 'しばらく時間をおいてから試してください', [
                'retry_after' => $bucket['locked_until'] - $now,
            ]);
        }
    }

    public static function recordFailure(array &$data, string $bucketKey, int $maxAttempts, int $windowSec, int $lockSec, int $now): void
    {
        $bucket = $data['login_failures'][$bucketKey] ?? ['attempts' => [], 'locked_until' => null];
        $attempts = array_values(array_filter($bucket['attempts'] ?? [], fn ($t) => $t > $now - $windowSec));
        $attempts[] = $now;
        if (count($attempts) >= $maxAttempts) {
            $bucket['locked_until'] = $now + $lockSec;
            $attempts = [];
        }
        $bucket['attempts'] = $attempts;
        $data['login_failures'][$bucketKey] = $bucket;
    }

    public static function resetBucket(array &$data, string $bucketKey): void
    {
        unset($data['login_failures'][$bucketKey]);
    }

    // ---- 30日保持トークン ----

    public static function issueRememberToken(array &$data, string $userId, int $now): string
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $data['remember_tokens'][$hash] = [
            'user_id' => $userId,
            'expires_at' => $now + self::REMEMBER_DAYS * 86400,
            'rotated_at' => null,
        ];
        return $token;
    }

    /**
     * 保持トークンを検証し、必要ならローテーションする。
     * 戻り値の status:
     *  - 'ok_rotate'    : 有効なので新しいトークンに差し替えた（$data を書き換え済み。呼び出し元は新cookieを設定する）
     *  - 'ok_no_rotate' : 直前にローテーション済みのトークンが猶予時間内にもう一度届いた
     *                     （ログインはさせるが、cookie は触らない＝設定も削除もしない）
     *  - 'not_found'    : トークンが見つからない、または期限切れ・猶予切れ（呼び出し元は cookie を消す）
     */
    public static function consumeRememberToken(array &$data, string $token, int $now): array
    {
        $hash = hash('sha256', $token);
        $entry = $data['remember_tokens'][$hash] ?? null;
        if (!$entry) {
            return ['status' => 'not_found'];
        }
        if (($entry['expires_at'] ?? 0) < $now) {
            unset($data['remember_tokens'][$hash]);
            return ['status' => 'not_found'];
        }
        $rotatedAt = $entry['rotated_at'] ?? null;
        if ($rotatedAt !== null) {
            // 既にローテーション済み。猶予時間内の重複リクエストとして扱う
            if ($now - $rotatedAt <= self::REMEMBER_GRACE_SECONDS) {
                return ['status' => 'ok_no_rotate', 'user_id' => $entry['user_id']];
            }
            // 猶予時間を過ぎた古いトークンの再利用は無効
            unset($data['remember_tokens'][$hash]);
            return ['status' => 'not_found'];
        }
        // 初めてこのトークンが使われた → ローテーションする（古い方は猶予時間だけ残す）
        $entry['rotated_at'] = $now;
        $data['remember_tokens'][$hash] = $entry;
        $newToken = self::issueRememberToken($data, $entry['user_id'], $now);
        return ['status' => 'ok_rotate', 'user_id' => $entry['user_id'], 'new_token' => $newToken];
    }

    public static function revokeAllRememberTokensFor(array &$data, string $userId): void
    {
        foreach ($data['remember_tokens'] as $hash => $entry) {
            if (($entry['user_id'] ?? null) === $userId) {
                unset($data['remember_tokens'][$hash]);
            }
        }
    }

    // ---- 掃除（際限なく増えるのを防ぐ） ----

    /**
     * 期限切れの login_failures バケットと remember_tokens を消す。
     * ログイン処理・保持トークンの検証など、書き込みを行うタイミングで呼ぶ。
     */
    public static function pruneExpired(array &$data, int $now): void
    {
        foreach ($data['login_failures'] as $key => $bucket) {
            $lockedUntil = $bucket['locked_until'] ?? null;
            if ($lockedUntil !== null && $lockedUntil > $now) {
                continue; // まだロック中
            }
            $attempts = array_values(array_filter(
                $bucket['attempts'] ?? [],
                fn ($t) => $t > $now - self::PRUNE_WINDOW_SECONDS
            ));
            if (empty($attempts)) {
                unset($data['login_failures'][$key]);
            } else {
                $data['login_failures'][$key] = ['attempts' => $attempts, 'locked_until' => null];
            }
        }
        foreach ($data['remember_tokens'] as $hash => $entry) {
            $expired = ($entry['expires_at'] ?? 0) < $now;
            $rotatedAt = $entry['rotated_at'] ?? null;
            $rotatedStale = $rotatedAt !== null && ($now - $rotatedAt) > self::REMEMBER_GRACE_SECONDS;
            if ($expired || $rotatedStale) {
                unset($data['remember_tokens'][$hash]);
            }
        }
    }

    public static function setRememberCookie(string $token): void
    {
        $path = getenv('KIDS_POINT_COOKIE_PATH') ?: '/kids-point/';
        $secure = getenv('KIDS_POINT_INSECURE_COOKIES') !== '1';
        setcookie(self::REMEMBER_COOKIE, $token, [
            'expires' => time() + self::REMEMBER_DAYS * 86400,
            'path' => $path,
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function clearRememberCookie(): void
    {
        $path = getenv('KIDS_POINT_COOKIE_PATH') ?: '/kids-point/';
        $secure = getenv('KIDS_POINT_INSECURE_COOKIES') !== '1';
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => $path,
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
