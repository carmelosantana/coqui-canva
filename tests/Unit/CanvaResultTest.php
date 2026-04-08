<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\CoquiToolkitCanva\Client\CanvaResult;

test('successful result reports success', function () {
    $result = new CanvaResult(
        success: true,
        data: ['design' => ['id' => 'DAFxyz123']],
        statusCode: 200,
    );

    expect($result->success)->toBeTrue();
    expect($result->statusCode)->toBe(200);
    expect($result->data)->toHaveKey('design');
});

test('failed result reports failure', function () {
    $result = new CanvaResult(
        success: false,
        errorCode: 'invalid_token',
        errorMessage: 'The access token is invalid',
        statusCode: 401,
    );

    expect($result->success)->toBeFalse();
    expect($result->statusCode)->toBe(401);
    expect($result->errorCode)->toBe('invalid_token');
});

test('error factory creates error result', function () {
    $result = CanvaResult::error('Something went wrong', 'server_error', 500);

    expect($result->success)->toBeFalse();
    expect($result->errorMessage)->toBe('Something went wrong');
    expect($result->errorCode)->toBe('server_error');
    expect($result->statusCode)->toBe(500);
});

test('toToolResult returns success for successful result', function () {
    $result = new CanvaResult(
        success: true,
        data: ['id' => 'DAFxyz123', 'title' => 'My Design'],
        statusCode: 200,
    );

    $toolResult = $result->toToolResult();

    expect($toolResult->status)->toBe(ToolResultStatus::Success);
    expect($toolResult->content)->toContain('DAFxyz123');
    expect($toolResult->content)->toContain('My Design');
});

test('toToolResult returns error for failed result', function () {
    $result = new CanvaResult(
        success: false,
        errorMessage: 'Forbidden',
        statusCode: 403,
    );

    $toolResult = $result->toToolResult();

    expect($toolResult->status)->toBe(ToolResultStatus::Error);
    expect($toolResult->content)->toContain('Forbidden');
});

test('toToolResultWith prepends prefix for success', function () {
    $result = new CanvaResult(
        success: true,
        data: ['name' => 'test-design'],
        statusCode: 200,
    );

    $toolResult = $result->toToolResultWith('Design created:');

    expect($toolResult->status)->toBe(ToolResultStatus::Success);
    expect($toolResult->content)->toStartWith('Design created:');
    expect($toolResult->content)->toContain('test-design');
});

test('toToolResultWith returns error for failed result', function () {
    $result = new CanvaResult(
        success: false,
        errorMessage: 'Not found',
        statusCode: 404,
    );

    $toolResult = $result->toToolResultWith('Design details:');

    expect($toolResult->status)->toBe(ToolResultStatus::Error);
    expect($toolResult->content)->toContain('Not found');
    expect($toolResult->content)->not->toContain('Design details:');
});

test('toToolResult handles empty data as empty content', function () {
    $result = new CanvaResult(
        success: true,
        data: [],
        statusCode: 200,
    );

    $toolResult = $result->toToolResult();

    expect($toolResult->status)->toBe(ToolResultStatus::Success);
});

test('error format includes error code when present', function () {
    $result = new CanvaResult(
        success: false,
        errorCode: 'rate_limited',
        errorMessage: 'Too many requests',
        statusCode: 429,
    );

    $toolResult = $result->toToolResult();

    expect($toolResult->content)->toContain('rate_limited');
    expect($toolResult->content)->toContain('Too many requests');
    expect($toolResult->content)->toContain('429');
});

test('403 error includes Enterprise hint', function () {
    $result = new CanvaResult(
        success: false,
        errorMessage: 'Access denied',
        statusCode: 403,
    );

    $toolResult = $result->toToolResult();

    expect($toolResult->content)->toContain('Enterprise');
});
