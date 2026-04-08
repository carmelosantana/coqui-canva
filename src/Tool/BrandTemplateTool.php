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
 * List, get, and inspect brand template datasets (Enterprise feature).
 */
final readonly class BrandTemplateTool
{
    public function __construct(
        private CanvaClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'canva_brand_template',
            description: 'Manage Canva brand templates — list available templates, get details, or retrieve the autofillable dataset. Requires Canva Enterprise.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Brand template operation to perform.',
                    values: ['list', 'get', 'dataset'],
                    required: true,
                ),
                new StringParameter(
                    'template_id',
                    'Brand template ID (required for get, dataset).',
                    required: false,
                ),
                new StringParameter(
                    'query',
                    'Search term to filter templates (optional for list).',
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
            'list' => $this->listTemplates($args),
            'get' => $this->getTemplate($args),
            'dataset' => $this->getDataset($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    /**
     * @param array<string, mixed> $args
     */
    private function listTemplates(array $args): ToolResult
    {
        $query = [];

        $search = trim((string) ($args['query'] ?? ''));
        if ($search !== '') {
            $query['query'] = $search;
        }

        $continuation = trim((string) ($args['continuation'] ?? ''));
        if ($continuation !== '') {
            $query['continuation'] = $continuation;
        }

        return $this->client->paginate('brand-templates', $query, 'items')
            ->toToolResultWith('Brand templates:');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function getTemplate(array $args): ToolResult
    {
        $templateId = trim((string) ($args['template_id'] ?? ''));
        if ($templateId === '') {
            return ToolResult::error('The "template_id" parameter is required to get template details.');
        }

        return $this->client->get("brand-templates/{$templateId}")
            ->toToolResultWith('Brand template details:');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function getDataset(array $args): ToolResult
    {
        $templateId = trim((string) ($args['template_id'] ?? ''));
        if ($templateId === '') {
            return ToolResult::error('The "template_id" parameter is required to get the template dataset.');
        }

        return $this->client->get("brand-templates/{$templateId}/dataset")
            ->toToolResultWith('Brand template dataset (autofillable fields):');
    }
}
