<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitCanva\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\CoquiToolkitCanva\Client\CanvaClient;

/**
 * Get the authenticated Canva user's profile.
 */
final readonly class UserTool
{
    public function __construct(
        private CanvaClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'canva_user',
            description: 'Get the authenticated Canva user\'s profile information.',
            parameters: [
                new EnumParameter(
                    'action',
                    'User operation to perform.',
                    values: ['profile'],
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
            'profile' => $this->getProfile(),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function getProfile(): ToolResult
    {
        return $this->client->get('users/me')
            ->toToolResultWith('Canva user profile:');
    }
}
