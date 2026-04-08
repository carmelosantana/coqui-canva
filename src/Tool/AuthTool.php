<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitCanva\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\CoquiToolkitCanva\Client\CanvaClient;

/**
 * OAuth authentication management for Canva.
 */
final readonly class AuthTool
{
    public function __construct(
        private CanvaClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'canva_auth',
            description: 'Manage Canva OAuth authentication — login, check status, or logout.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Authentication action to perform.',
                    values: ['login', 'status', 'logout'],
                    required: true,
                ),
            ],
            callback: fn(array $args) => $this->execute($args),
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private function execute(array $args): ToolResult
    {
        $action = trim((string) ($args['action'] ?? ''));

        return match ($action) {
            'login' => $this->login(),
            'status' => $this->status(),
            'logout' => $this->logout(),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function login(): ToolResult
    {
        $oauth = $this->client->oauth();

        if ($oauth->hasTokens()) {
            $token = $oauth->getAccessToken();
            if ($token !== null) {
                return ToolResult::success('Already authenticated with Canva. Use canva_auth(action: "status") to check details, or canva_auth(action: "logout") then login again to re-authenticate.');
            }
        }

        try {
            $tokens = $oauth->authorize();

            return ToolResult::success(
                'Successfully authenticated with Canva! Access token obtained.'
                . (isset($tokens['refresh_token']) ? ' Refresh token stored for automatic renewal.' : ''),
            );
        } catch (\Throwable $e) {
            return ToolResult::error('Canva authentication failed: ' . $e->getMessage());
        }
    }

    private function status(): ToolResult
    {
        return ToolResult::success($this->client->oauth()->getStatus());
    }

    private function logout(): ToolResult
    {
        try {
            $this->client->oauth()->logout();

            return ToolResult::success('Logged out from Canva. Tokens revoked and cleared.');
        } catch (\Throwable $e) {
            return ToolResult::error('Logout failed: ' . $e->getMessage());
        }
    }
}
