<?php
declare(strict_types=1);

namespace KidsPoint;

/** API 層で意図的に投げる例外。HTTP ステータスとエラーコードを持つ */
final class ApiError extends \RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
        public readonly array $extra = []
    ) {
        parent::__construct($message);
    }
}
