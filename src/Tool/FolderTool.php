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
 * Create, list, get, update, delete folders and move items between them.
 */
final readonly class FolderTool
{
    public function __construct(
        private CanvaClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'canva_folder',
            description: 'Manage Canva folders — create, list items, get, update, delete, or move items between folders.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Folder operation to perform.',
                    values: ['create', 'list_items', 'get', 'update', 'delete', 'move_item'],
                    required: true,
                ),
                new StringParameter(
                    'folder_id',
                    'Folder ID (required for list_items, get, update, delete, move_item target).',
                    required: false,
                ),
                new StringParameter(
                    'name',
                    'Folder name (required for create, optional for update).',
                    required: false,
                ),
                new StringParameter(
                    'parent_folder_id',
                    'Parent folder ID (optional for create — defaults to root).',
                    required: false,
                ),
                new StringParameter(
                    'item_id',
                    'ID of the item to move (required for move_item).',
                    required: false,
                ),
                new EnumParameter(
                    'item_type',
                    'Type of item to filter in list_items, or type of item being moved.',
                    values: ['design', 'folder', 'image', 'video'],
                    required: false,
                ),
                new StringParameter(
                    'sort_by',
                    'Sort order for list_items: "modified_descending", "modified_ascending", "title_descending", "title_ascending".',
                    required: false,
                ),
                new StringParameter(
                    'continuation',
                    'Continuation token for pagination (optional for list_items).',
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
            'create' => $this->createFolder($args),
            'list_items' => $this->listItems($args),
            'get' => $this->getFolder($args),
            'update' => $this->updateFolder($args),
            'delete' => $this->deleteFolder($args),
            'move_item' => $this->moveItem($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    /**
     * @param array<string, mixed> $args
     */
    private function createFolder(array $args): ToolResult
    {
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required to create a folder.');
        }

        $body = ['name' => $name];

        $parentId = trim((string) ($args['parent_folder_id'] ?? ''));
        if ($parentId !== '') {
            $body['parent_folder_id'] = $parentId;
        }

        return $this->client->post('folders', $body)
            ->toToolResultWith('Folder created:');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function listItems(array $args): ToolResult
    {
        $folderId = trim((string) ($args['folder_id'] ?? ''));
        if ($folderId === '') {
            return ToolResult::error('The "folder_id" parameter is required to list folder items.');
        }

        $query = [];

        $itemType = trim((string) ($args['item_type'] ?? ''));
        if ($itemType !== '') {
            $query['item_type'] = $itemType;
        }

        $sortBy = trim((string) ($args['sort_by'] ?? ''));
        if ($sortBy !== '') {
            $query['sort_by'] = $sortBy;
        }

        $continuation = trim((string) ($args['continuation'] ?? ''));
        if ($continuation !== '') {
            $query['continuation'] = $continuation;
        }

        return $this->client->paginate("folders/{$folderId}/items", $query, 'items')
            ->toToolResultWith('Folder items:');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function getFolder(array $args): ToolResult
    {
        $folderId = trim((string) ($args['folder_id'] ?? ''));
        if ($folderId === '') {
            return ToolResult::error('The "folder_id" parameter is required to get folder details.');
        }

        return $this->client->get("folders/{$folderId}")
            ->toToolResultWith('Folder details:');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function updateFolder(array $args): ToolResult
    {
        $folderId = trim((string) ($args['folder_id'] ?? ''));
        if ($folderId === '') {
            return ToolResult::error('The "folder_id" parameter is required to update a folder.');
        }

        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required to update a folder.');
        }

        return $this->client->patch("folders/{$folderId}", ['name' => $name])
            ->toToolResultWith('Folder updated:');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function deleteFolder(array $args): ToolResult
    {
        $folderId = trim((string) ($args['folder_id'] ?? ''));
        if ($folderId === '') {
            return ToolResult::error('The "folder_id" parameter is required to delete a folder.');
        }

        return $this->client->delete("folders/{$folderId}")
            ->toToolResultWith('Folder deleted.');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function moveItem(array $args): ToolResult
    {
        $folderId = trim((string) ($args['folder_id'] ?? ''));
        if ($folderId === '') {
            return ToolResult::error('The "folder_id" parameter is required as the target folder for move_item.');
        }

        $itemId = trim((string) ($args['item_id'] ?? ''));
        if ($itemId === '') {
            return ToolResult::error('The "item_id" parameter is required to move an item.');
        }

        $body = ['item_id' => $itemId];

        $itemType = trim((string) ($args['item_type'] ?? ''));
        if ($itemType !== '') {
            $body['item_type'] = $itemType;
        }

        return $this->client->post("folders/{$folderId}/move", $body)
            ->toToolResultWith('Item moved to folder.');
    }
}
