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
 * Create autofill jobs from brand templates (Enterprise feature).
 */
final readonly class AutofillTool
{
    public function __construct(
        private CanvaClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'canva_autofill',
            description: 'Create designs by autofilling brand templates with data. Creates an async job and polls for completion. Requires Canva Enterprise.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Autofill operation to perform.',
                    values: ['create', 'status'],
                    required: true,
                ),
                new StringParameter(
                    'template_id',
                    'Brand template ID to autofill (required for create).',
                    required: false,
                ),
                new StringParameter(
                    'data',
                    'JSON string of field name → value pairs to fill in the template (required for create). Use canva_brand_template(action: "dataset") to discover available fields.',
                    required: false,
                ),
                new StringParameter(
                    'title',
                    'Title for the resulting design (optional for create).',
                    required: false,
                ),
                new StringParameter(
                    'job_id',
                    'Autofill job ID (required for status).',
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
            'create' => $this->createAutofill($args),
            'status' => $this->getStatus($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    /**
     * @param array<string, mixed> $args
     */
    private function createAutofill(array $args): ToolResult
    {
        $templateId = trim((string) ($args['template_id'] ?? ''));
        if ($templateId === '') {
            return ToolResult::error('The "template_id" parameter is required. Use canva_brand_template(action: "list") to find templates.');
        }

        $dataJson = trim((string) ($args['data'] ?? ''));
        if ($dataJson === '') {
            return ToolResult::error('The "data" parameter is required. Provide a JSON string of field → value pairs. Use canva_brand_template(action: "dataset", template_id: "...") to discover available fields.');
        }

        $data = json_decode($dataJson, true);
        if (!is_array($data)) {
            return ToolResult::error('The "data" parameter must be a valid JSON object of field → value pairs.');
        }

        $body = [
            'brand_template_id' => $templateId,
            'data' => $this->normalizeAutofillData($data),
        ];

        $title = trim((string) ($args['title'] ?? ''));
        if ($title !== '') {
            $body['title'] = $title;
        }

        $createResult = $this->client->post('autofills', $body);
        if (!$createResult->success) {
            return $createResult->toToolResult();
        }

        $jobId = $createResult->data['job']['id'] ?? null;
        if ($jobId === null) {
            return $createResult->toToolResultWith('Autofill job created:');
        }

        // Poll for completion
        $result = $this->client->pollJob("autofills/{$jobId}");

        return $result->toToolResultWith('Autofill completed:');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function getStatus(array $args): ToolResult
    {
        $jobId = trim((string) ($args['job_id'] ?? ''));
        if ($jobId === '') {
            return ToolResult::error('The "job_id" parameter is required to check autofill status.');
        }

        return $this->client->get("autofills/{$jobId}")
            ->toToolResultWith('Autofill job status:');
    }

    /**
     * Convert simple key-value pairs to Canva's autofill data format.
     *
     * Canva expects: {"field_name": {"type": "text", "text": "value"}}
     * We accept: {"field_name": "value"} and normalize it.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeAutofillData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $normalized[$key] = [
                    'type' => 'text',
                    'text' => $value,
                ];
            } elseif (is_array($value) && isset($value['type'])) {
                // Already in Canva format
                $normalized[$key] = $value;
            } else {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
