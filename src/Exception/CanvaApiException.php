<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitCanva\Exception;

/**
 * Thrown when the Canva Connect API returns an error response.
 */
final class CanvaApiException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 0,
    ) {
        parent::__construct($message, $statusCode);
    }

    public static function fromResponse(string $code, string $message, int $statusCode): self
    {
        return new self(
            errorCode: $code,
            message: sprintf('[%s] %s (HTTP %d)', $code, $message, $statusCode),
            statusCode: $statusCode,
        );
    }
}
