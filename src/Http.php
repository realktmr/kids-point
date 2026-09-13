<?php
declare(strict_types=1);

namespace KidsPoint;

/** リクエスト／レスポンスまわりの小さな共通処理 */
final class Http
{
    public static function jsonResponse(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** POST の JSON 本文を連想配列で返す（不正なら空配列） */
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function requireString(array $body, string $key, int $maxLen = 200): string
    {
        $v = $body[$key] ?? null;
        if (!is_string($v) || $v === '' || mb_strlen($v) > $maxLen) {
            throw new ApiError(400, 'invalid_input', "{$key} が不正です");
        }
        return $v;
    }

    public static function optionalString(array $body, string $key, int $maxLen = 500): string
    {
        $v = $body[$key] ?? '';
        if (!is_string($v) || mb_strlen($v) > $maxLen) {
            throw new ApiError(400, 'invalid_input', "{$key} が不正です");
        }
        return $v;
    }

    private const MAX_ABS_INT = 1_000_000;

    public static function requireInt(array $body, string $key): int
    {
        $v = $body[$key] ?? null;
        if ($v === null) {
            throw new ApiError(400, 'invalid_input', "{$key} が不正です");
        }
        return self::parseIntStrict($v, $key);
    }

    /** 未指定・空文字なら null、それ以外は厳密な整数として検証する（例: items_update の points） */
    public static function optionalInt(array $body, string $key): ?int
    {
        $v = $body[$key] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        return self::parseIntStrict($v, $key);
    }

    /** JSON数値の int のみ、または "-?\d+" 形式の文字列のみを受け付ける（"1e3" や "10.7" は拒否） */
    private static function parseIntStrict(mixed $v, string $key): int
    {
        if (is_int($v)) {
            $n = $v;
        } elseif (is_string($v) && preg_match('/^-?\d+$/', $v)) {
            $n = (int) $v;
        } else {
            throw new ApiError(400, 'invalid_input', "{$key} が不正です");
        }
        if (abs($n) > self::MAX_ABS_INT) {
            throw new ApiError(400, 'invalid_input', "{$key} が大きすぎます");
        }
        return $n;
    }
}
