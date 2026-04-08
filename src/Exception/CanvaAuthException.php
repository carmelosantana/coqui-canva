<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitCanva\Exception;

/**
 * Thrown when OAuth authentication with Canva fails.
 */
final class CanvaAuthException extends \RuntimeException
{
    public static function configError(string $message): self
    {
        return new self('OAuth config error: ' . $message);
    }

    public static function authorizationFailed(string $error, string $description = ''): self
    {
        $msg = 'Authorization failed: ' . $error;
        if ($description !== '') {
            $msg .= ' — ' . $description;
        }

        return new self($msg);
    }

    public static function tokenExchangeFailed(string $reason): self
    {
        return new self('Token exchange failed: ' . $reason);
    }

    public static function tokenExpired(): self
    {
        return new self('Access token expired and could not be refreshed. Run canva_auth(action: "login") to re-authenticate.');
    }
}
