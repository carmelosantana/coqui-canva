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
 * Create and poll design export jobs (PDF, PNG, JPG, PPTX, GIF, MP4).
 */
final readonly class ExportTool
{
    public function __construct(
        private CanvaClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'canva_export',
            description: 'Export Canva designs to various formats (PDF, PNG, JPG, PPTX, GIF, MP4). Creates an async job and polls for completion.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Export operation to perform.',
                    values: ['create', 'status'],
                    required: true,
                ),
                new StringParameter(
                    'design_id',
                    'Design ID to export (required for create).',
                    required: false,
                ),
                new EnumParameter(
                    'format',
                    'Export format (required for create).',
                    values: ['pdf', 'jpg', 'png', 'pptx', 'gif', 'mp4'],
                    required: false,
                ),
                new StringParameter(
                    'job_id',
                    'Export job ID (required for status).',
                    required: false,
                ),
                new StringParameter(
                    'pages',
                    'Comma-separated page numbers to export (optional for create, defaults to all pages).',
                    required: false,
                ),
                new NumberParameter(
                    'quality',
                    'Export quality 1-100 (optional for JPG, default 80). Not supported by all formats.',
                    required: false,
                ),
                new NumberParameter(
                    'width',
                    'Target width for raster exports (optional for PNG/JPG, maintains aspect ratio).',
                    required: false,
                ),
                new NumberParameter(
                    'height',
                    'Target height for raster exports (optional for PNG/JPG, maintains aspect ratio).',
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
            'create' => $this->createExport($args),
            'status' => $this->getStatus($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    /**
     * @param array<string, mixed> $args
     */
    private function createExport(array $args): ToolResult
    {
        $designId = trim((string) ($args['design_id'] ?? ''));
        if ($designId === '') {
            return ToolResult::error('The "design_id" parameter is required to create an export.');
        }

        $format = trim((string) ($args['format'] ?? ''));
        if ($format === '') {
            return ToolResult::error('The "format" parameter is required. Use: pdf, jpg, png, pptx, gif, mp4.');
        }

        $body = [
            'design_id' => $designId,
            'format' => [
                'type' => $format,
            ],
        ];

        // Add format-specific options
        $quality = (int) ($args['quality'] ?? 0);
        if ($quality > 0 && in_array($format, ['jpg', 'png'], true)) {
            $body['format']['quality'] = min($quality, 100);
        }

        $width = (int) ($args['width'] ?? 0);
        $height = (int) ($args['height'] ?? 0);
        if ($width > 0 || $height > 0) {
            $size = [];
            if ($width > 0) {
                $size['width'] = $width;
            }
            if ($height > 0) {
                $size['height'] = $height;
            }
            $body['format']['size'] = $size;
        }

        // Parse page selection
        $pages = trim((string) ($args['pages'] ?? ''));
        if ($pages !== '') {
            $pageNumbers = array_map('intval', explode(',', $pages));
            $body['pages'] = array_values(array_filter($pageNumbers, static fn(int $p): bool => $p > 0));
        }

        $createResult = $this->client->post('exports', $body);
        if (!$createResult->success) {
            return $createResult->toToolResult();
        }

        $jobId = $createResult->data['job']['id'] ?? null;
        if ($jobId === null) {
            return $createResult->toToolResultWith('Export job created (poll using canva_export status):');
        }

        // Poll for completion
        $result = $this->client->pollJob("exports/{$jobId}");

        return $result->toToolResultWith('Export completed:');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function getStatus(array $args): ToolResult
    {
        $jobId = trim((string) ($args['job_id'] ?? ''));
        if ($jobId === '') {
            return ToolResult::error('The "job_id" parameter is required to check export status.');
        }

        return $this->client->get("exports/{$jobId}")
            ->toToolResultWith('Export job status:');
    }
}
