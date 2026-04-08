<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\CoquiToolkitCanva\CanvaToolkit;
use CarmeloSantana\CoquiToolkitCanva\Client\CanvaClient;
use CarmeloSantana\CoquiToolkitCanva\Client\CanvaOAuth;

function createTestToolkit(): CanvaToolkit
{
    $oauth = new CanvaOAuth(
        workspacePath: sys_get_temp_dir(),
        clientId: 'test-client-id',
        clientSecret: 'test-client-secret',
    );
    $client = new CanvaClient(oauth: $oauth);

    return new CanvaToolkit(client: $client);
}

test('toolkit implements ToolkitInterface', function () {
    $toolkit = createTestToolkit();

    expect($toolkit)->toBeInstanceOf(ToolkitInterface::class);
});

test('tools returns all 9 tools', function () {
    $toolkit = createTestToolkit();

    expect($toolkit->tools())->toHaveCount(9);
});

test('each tool implements ToolInterface', function () {
    $toolkit = createTestToolkit();

    foreach ($toolkit->tools() as $tool) {
        expect($tool)->toBeInstanceOf(ToolInterface::class);
    }
});

test('tool names are unique', function () {
    $toolkit = createTestToolkit();
    $names = array_map(fn(ToolInterface $t) => $t->name(), $toolkit->tools());

    expect($names)->toHaveCount(count(array_unique($names)));
});

test('all tool names start with canva_', function () {
    $toolkit = createTestToolkit();

    foreach ($toolkit->tools() as $tool) {
        expect($tool->name())->toStartWith('canva_');
    }
});

test('expected tool names are registered', function () {
    $toolkit = createTestToolkit();
    $names = array_map(fn(ToolInterface $t) => $t->name(), $toolkit->tools());

    expect($names)->toContain('canva_auth');
    expect($names)->toContain('canva_design');
    expect($names)->toContain('canva_export');
    expect($names)->toContain('canva_asset');
    expect($names)->toContain('canva_folder');
    expect($names)->toContain('canva_comment');
    expect($names)->toContain('canva_user');
    expect($names)->toContain('canva_brand_template');
    expect($names)->toContain('canva_autofill');
});

test('each tool produces a valid function schema', function () {
    $toolkit = createTestToolkit();

    foreach ($toolkit->tools() as $tool) {
        $schema = $tool->toFunctionSchema();

        expect($schema)
            ->toBeArray()
            ->toHaveKeys(['type', 'function']);

        expect($schema['type'])->toBe('function');
        expect($schema['function'])->toBeArray()->toHaveKeys(['name', 'description', 'parameters']);
        expect($schema['function']['name'])->toBeString()->not->toBeEmpty();
        expect($schema['function']['description'])->toBeString()->not->toBeEmpty();
        expect($schema['function']['parameters'])->toBeArray();
    }
});

test('guidelines contain XML tags', function () {
    $toolkit = createTestToolkit();
    $guidelines = $toolkit->guidelines();

    expect($guidelines)->toContain('<CANVA-GUIDELINES>');
    expect($guidelines)->toContain('</CANVA-GUIDELINES>');
});

test('guidelines mention all tool names', function () {
    $toolkit = createTestToolkit();
    $guidelines = $toolkit->guidelines();

    expect($guidelines)->toContain('canva_auth');
    expect($guidelines)->toContain('canva_design');
    expect($guidelines)->toContain('canva_export');
    expect($guidelines)->toContain('canva_asset');
    expect($guidelines)->toContain('canva_folder');
    expect($guidelines)->toContain('canva_comment');
    expect($guidelines)->toContain('canva_user');
    expect($guidelines)->toContain('canva_brand_template');
    expect($guidelines)->toContain('canva_autofill');
});

test('guidelines contain workflow documentation', function () {
    $toolkit = createTestToolkit();
    $guidelines = $toolkit->guidelines();

    expect($guidelines)->toContain('Authentication Flow');
    expect($guidelines)->toContain('Common Workflows');
    expect($guidelines)->toContain('Rate limits');
});
