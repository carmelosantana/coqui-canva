<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitCanva\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\CoquiToolkitCanva\Client\CanvaClient;

/**
 * Create, list, get, and delete Canva designs.
 */
final readonly class DesignTool
{
    public function __construct(
        private CanvaClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'canva_design',
            description: 'Manage Canva designs — create new designs (from presets or custom dimensions), list, get details, or delete.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Design operation to perform.',
                    values: ['create', 'list', 'get', 'delete'],
                    required: true,
                ),
                new StringParameter(
                    'design_id',
                    'Design ID (required for get, delete).',
                    required: false,
                ),
                new EnumParameter(
                    'design_type',
                    'Preset design type for create. Use "custom" for custom dimensions.',
                    values: ['doc', 'whiteboard', 'presentation', 'custom'],
                    required: false,
                ),
                new StringParameter(
                    'title',
                    'Design title (optional for create).',
                    required: false,
                ),
                new NumberParameter(
                    'width',
                    'Width in pixels (required when design_type is "custom").',
                    required: false,
                ),
                new NumberParameter(
                    'height',
                    'Height in pixels (required when design_type is "custom").',
                    required: false,
                ),
                new StringParameter(
                    'asset_id',
                    'Asset ID to use as the initial content of the design (optional for create).',
                    required: false,
                ),
                new StringParameter(
                    'query',
                    'Search term to filter designs (optional for list).',
                    required: false,
                ),
                new StringParameter(
                    'sort_by',
                    'Sort order for list: "relevance", "modified_descending", "modified_ascending", "title_descending", "title_ascending".',
                    required: false,
                ),
                new StringParameter(
                    'continuation',
                    'Continuation token for pagination (optional for list).',
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
            'create' => $this->createDesign($args),
            'list' => $this->listDesigns($args),
            'get' => $this->getDesign($args),
            'delete' => $this->deleteDesign($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    /**
     * @param array<string, mixed> $args
     */
    private function createDesign(array $args): ToolResult
    {
        $designType = trim((string) ($args['design_type'] ?? ''));
        if ($designType === '') {
            return ToolResult::error('The "design_type" parameter is required for create. Use "doc", "whiteboard", "presentation", or "custom".');
        }

        $body = [];

        if ($designType === 'custom') {
            $width = (int) ($args['width'] ?? 0);
            $height = (int) ($args['height'] ?? 0);
            if ($width <= 0 || $height <= 0) {
                return ToolResult::error('Both "width" and "height" are required when design_type is "custom".');
            }
            $body['design_type'] = [
                'type' => 'custom',
                'width' => $width,
                'height' => $height,
            ];
        } else {
            $body['design_type'] = [
                'type' => 'preset',
                'name' => $designType,
            ];
        }

        $title = trim((string) ($args['title'] ?? ''));
        if ($title !== '') {
            $body['title'] = $title;
        }

        $assetId = trim((string) ($args['asset_id'] ?? ''));
        if ($assetId !== '') {
            $body['asset_id'] = $assetId;
        }

        return $this->client->post('designs', $body)
            ->toToolResultWith('Design created:');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function listDesigns(array $args): ToolResult
    {
        $query = [];

        $search = trim((string) ($args['query'] ?? ''));
        if ($search !== '') {
            $query['query'] = $search;
        }

        $sortBy = trim((string) ($args['sort_by'] ?? ''));
        if ($sortBy !== '') {
            $query['sort_by'] = $sortBy;
        }

        $continuation = trim((string) ($args['continuation'] ?? ''));
        if ($continuation !== '') {
            $query['continuation'] = $continuation;
        }

        return $this->client->paginate('designs', $query, 'items')
            ->toToolResultWith('Designs:');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function getDesign(array $args): ToolResult
    {
        $designId = trim((string) ($args['design_id'] ?? ''));
        if ($designId === '') {
            return ToolResult::error('The "design_id" parameter is required to get design details.');
        }

        return $this->client->get("designs/{$designId}")
            ->toToolResultWith('Design details:');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function deleteDesign(array $args): ToolResult
    {
        $designId = trim((string) ($args['design_id'] ?? ''));
        if ($designId === '') {
            return ToolResult::error('The "design_id" parameter is required to delete a design.');
        }

        return $this->client->delete("designs/{$designId}")
            ->toToolResultWith('Design deleted.');
    }
}
