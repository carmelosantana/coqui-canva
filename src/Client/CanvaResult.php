<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitCanva\Client;

use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Typed result value object for Canva Connect API responses.
 *
 * Wraps the Canva JSON response and provides helpers for converting
 * to ToolResult for the agent.
 */
final readonly class CanvaResult
{
    /**
     * @param array<string, mixed> $data The response body as an associative array
     */
    public function __construct(
        public bool $success,
        public array $data = [],
        public string $errorCode = '',
        public string $errorMessage = '',
        public int $statusCode = 200,
    ) {}

    /**
     * Create a result from a Symfony HTTP response.
     */
    public static function fromResponse(ResponseInterface $response): self
    {
        $statusCode = $response->getStatusCode();
        $data = $response->toArray(false);

        if ($statusCode >= 400) {
            return new self(
                success: false,
                errorCode: (string) ($data['code'] ?? ''),
                errorMessage: (string) ($data['message'] ?? 'Unknown error'),
                statusCode: $statusCode,
            );
        }

        return new self(
            success: true,
            data: $data,
            statusCode: $statusCode,
        );
    }

    /**
     * Create an error result from a Canva error response.
     */
    public static function error(string $message, string $code = '', int $statusCode = 0): self
    {
        return new self(
            success: false,
            errorCode: $code,
            errorMessage: $message,
            statusCode: $statusCode,
        );
    }

    /**
     * Create an error result from a Symfony HTTP exception.
     */
    public static function fromErrorResponse(HttpExceptionInterface $e): self
    {
        $statusCode = $e->getResponse()->getStatusCode();

        try {
            $data = $e->getResponse()->toArray(false);
            $code = (string) ($data['code'] ?? '');
            $message = (string) ($data['message'] ?? $e->getMessage());
        } catch (\Throwable) {
            $code = '';
            $message = $e->getMessage();
        }

        return new self(
            success: false,
            errorCode: $code,
            errorMessage: $message,
            statusCode: $statusCode,
        );
    }

    /**
     * Convert the result to a ToolResult for the agent.
     */
    public function toToolResult(): ToolResult
    {
        if ($this->success) {
            return ToolResult::success($this->formatData());
        }

        return ToolResult::error($this->formatError());
    }

    /**
     * Convert the result to a ToolResult with a custom success prefix.
     */
    public function toToolResultWith(string $successPrefix): ToolResult
    {
        if ($this->success) {
            $formatted = $this->formatData();
            $output = $successPrefix;
            if ($formatted !== '' && $formatted !== '[]' && $formatted !== '{}') {
                $output .= "\n\n" . $formatted;
            }

            return ToolResult::success($output);
        }

        return ToolResult::error($this->formatError());
    }

    private function formatData(): string
    {
        if ($this->data === []) {
            return '';
        }

        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : '';
    }

    private function formatError(): string
    {
        $msg = $this->errorMessage !== '' ? $this->errorMessage : 'Unknown error';

        if ($this->errorCode !== '') {
            $msg .= " (code: {$this->errorCode})";
        }

        if ($this->statusCode > 0) {
            $msg .= " [HTTP {$this->statusCode}]";
        }

        if ($this->statusCode === 403) {
            $msg .= "\n\nThis may require a Canva Enterprise subscription or additional scopes on your integration.";
        }

        return $msg;
    }
}
