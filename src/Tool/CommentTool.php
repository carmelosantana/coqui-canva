<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitCanva\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\CoquiToolkitCanva\Client\CanvaClient;

/**
 * Create comment threads, list threads, create replies, and get thread details.
 */
final readonly class CommentTool
{
    public function __construct(
        private CanvaClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'canva_comment',
            description: 'Manage Canva design comments — create threads, list threads on a design, reply to threads, or get thread details.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Comment operation to perform.',
                    values: ['create_thread', 'list_threads', 'create_reply', 'get_thread'],
                    required: true,
                ),
                new StringParameter(
                    'design_id',
                    'Design ID (required for create_thread, list_threads).',
                    required: false,
                ),
                new StringParameter(
                    'thread_id',
                    'Comment thread ID (required for create_reply, get_thread).',
                    required: false,
                ),
                new StringParameter(
                    'message',
                    'Comment message text (required for create_thread, create_reply).',
                    required: false,
                ),
                new StringParameter(
                    'continuation',
                    'Continuation token for pagination (optional for list_threads).',
                    required: false,
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
            'create_thread' => $this->createThread($args),
            'list_threads' => $this->listThreads($args),
            'create_reply' => $this->createReply($args),
            'get_thread' => $this->getThread($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    /**
     * @param array<string, mixed> $args
     */
    private function createThread(array $args): ToolResult
    {
        $designId = trim((string) ($args['design_id'] ?? ''));
        if ($designId === '') {
            return ToolResult::error('The "design_id" parameter is required to create a comment thread.');
        }

        $message = trim((string) ($args['message'] ?? ''));
        if ($message === '') {
            return ToolResult::error('The "message" parameter is required to create a comment thread.');
        }

        return $this->client->post("designs/{$designId}/comments", [
            'message' => $message,
        ])->toToolResultWith('Comment thread created:');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function listThreads(array $args): ToolResult
    {
        $designId = trim((string) ($args['design_id'] ?? ''));
        if ($designId === '') {
            return ToolResult::error('The "design_id" parameter is required to list comment threads.');
        }

        $query = [];

        $continuation = trim((string) ($args['continuation'] ?? ''));
        if ($continuation !== '') {
            $query['continuation'] = $continuation;
        }

        return $this->client->paginate("designs/{$designId}/comments", $query, 'items')
            ->toToolResultWith('Comment threads:');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function createReply(array $args): ToolResult
    {
        $threadId = trim((string) ($args['thread_id'] ?? ''));
        if ($threadId === '') {
            return ToolResult::error('The "thread_id" parameter is required to create a reply.');
        }

        $message = trim((string) ($args['message'] ?? ''));
        if ($message === '') {
            return ToolResult::error('The "message" parameter is required to create a reply.');
        }

        return $this->client->post("comments/{$threadId}/replies", [
            'message' => $message,
        ])->toToolResultWith('Reply created:');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function getThread(array $args): ToolResult
    {
        $threadId = trim((string) ($args['thread_id'] ?? ''));
        if ($threadId === '') {
            return ToolResult::error('The "thread_id" parameter is required to get thread details.');
        }

        return $this->client->get("comments/{$threadId}")
            ->toToolResultWith('Comment thread:');
    }
}
