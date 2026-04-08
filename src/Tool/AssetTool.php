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
 * Upload, list, get, update, and delete Canva assets.
 */
final readonly class AssetTool
{
    public function __construct(
        private CanvaClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'canva_asset',
            description: 'Manage Canva assets — upload files, list, get, update metadata, or delete.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Asset operation to perform.',
                    values: ['upload', 'get', 'list', 'update', 'delete', 'upload_status'],
                    required: true,
                ),
                new StringParameter(
                    'asset_id',
                    'Asset ID (required for get, update, delete).',
                    required: false,
                ),
                new StringParameter(
                    'file_path',
                    'Absolute path to the file to upload (required for upload).',
                    required: false,
                ),
                new StringParameter(
                    'name',
                    'Display name for the asset (optional for upload, update).',
                    required: false,
                ),
                new StringParameter(
                    'tags',
                    'Comma-separated tags for the asset (optional for update).',
                    required: false,
                ),
                new StringParameter(
                    'job_id',
                    'Upload job ID (required for upload_status).',
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
            'upload' => $this->uploadAsset($args),
            'get' => $this->getAsset($args),
            'list' => $this->listAssets($args),
            'update' => $this->updateAsset($args),
            'delete' => $this->deleteAsset($args),
            'upload_status' => $this->getUploadStatus($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    /**
     * @param array<string, mixed> $args
     */
    private function uploadAsset(array $args): ToolResult
    {
        $filePath = trim((string) ($args['file_path'] ?? ''));
        if ($filePath === '') {
            return ToolResult::error('The "file_path" parameter is required to upload an asset.');
        }

        if (!file_exists($filePath)) {
            return ToolResult::error("File not found: {$filePath}");
        }

        if (!is_readable($filePath)) {
            return ToolResult::error("File is not readable: {$filePath}");
        }

        $data = file_get_contents($filePath);
        if ($data === false) {
            return ToolResult::error("Failed to read file: {$filePath}");
        }

        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            $name = basename($filePath);
        }

        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        $nameBase64 = base64_encode($name);

        $headers = [
            'Asset-Upload-Metadata' => json_encode([
                'name_base64' => $nameBase64,
            ], JSON_THROW_ON_ERROR),
        ];

        $createResult = $this->client->postBinary('asset-uploads', $data, $mimeType, $headers);
        if (!$createResult->success) {
            return $createResult->toToolResult();
        }

        $jobId = $createResult->data['job']['id'] ?? null;
        if ($jobId === null) {
            return $createResult->toToolResultWith('Asset upload started:');
        }

        // Poll for completion
        $result = $this->client->pollJob("asset-uploads/{$jobId}");

        return $result->toToolResultWith('Asset uploaded:');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function getAsset(array $args): ToolResult
    {
        $assetId = trim((string) ($args['asset_id'] ?? ''));
        if ($assetId === '') {
            return ToolResult::error('The "asset_id" parameter is required to get asset details.');
        }

        return $this->client->get("assets/{$assetId}")
            ->toToolResultWith('Asset details:');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function listAssets(array $args): ToolResult
    {
        $query = [];

        $continuation = trim((string) ($args['continuation'] ?? ''));
        if ($continuation !== '') {
            $query['continuation'] = $continuation;
        }

        return $this->client->paginate('assets', $query, 'items')
            ->toToolResultWith('Assets:');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function updateAsset(array $args): ToolResult
    {
        $assetId = trim((string) ($args['asset_id'] ?? ''));
        if ($assetId === '') {
            return ToolResult::error('The "asset_id" parameter is required to update an asset.');
        }

        $body = [];

        $name = trim((string) ($args['name'] ?? ''));
        if ($name !== '') {
            $body['name'] = $name;
        }

        $tags = trim((string) ($args['tags'] ?? ''));
        if ($tags !== '') {
            $body['tags'] = array_map('trim', explode(',', $tags));
        }

        if ($body === []) {
            return ToolResult::error('At least one of "name" or "tags" is required to update an asset.');
        }

        return $this->client->patch("assets/{$assetId}", $body)
            ->toToolResultWith('Asset updated:');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function deleteAsset(array $args): ToolResult
    {
        $assetId = trim((string) ($args['asset_id'] ?? ''));
        if ($assetId === '') {
            return ToolResult::error('The "asset_id" parameter is required to delete an asset.');
        }

        return $this->client->delete("assets/{$assetId}")
            ->toToolResultWith('Asset deleted.');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function getUploadStatus(array $args): ToolResult
    {
        $jobId = trim((string) ($args['job_id'] ?? ''));
        if ($jobId === '') {
            return ToolResult::error('The "job_id" parameter is required to check upload status.');
        }

        return $this->client->get("asset-uploads/{$jobId}")
            ->toToolResultWith('Upload job status:');
    }
}
