<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitCanva\Client\CanvaOAuth;

test('PKCE code verifier has correct length', function () {
    $oauth = new CanvaOAuth(
        workspacePath: sys_get_temp_dir(),
        clientId: 'test-id',
        clientSecret: 'test-secret',
    );

    // Use reflection to test private method
    $ref = new ReflectionMethod($oauth, 'generateCodeVerifier');
    $verifier = $ref->invoke($oauth);

    expect($verifier)->toBeString();
    expect(strlen($verifier))->toBeGreaterThanOrEqual(43);
    expect(strlen($verifier))->toBeLessThanOrEqual(128);
    // Must be URL-safe base64
    expect($verifier)->toMatch('/^[A-Za-z0-9_-]+$/');
});

test('PKCE code challenge is derived from verifier', function () {
    $oauth = new CanvaOAuth(
        workspacePath: sys_get_temp_dir(),
        clientId: 'test-id',
        clientSecret: 'test-secret',
    );

    $verifierRef = new ReflectionMethod($oauth, 'generateCodeVerifier');
    $challengeRef = new ReflectionMethod($oauth, 'generateCodeChallenge');

    $verifier = $verifierRef->invoke($oauth);
    $challenge = $challengeRef->invoke($oauth, $verifier);

    expect($challenge)->toBeString();
    expect($challenge)->not->toBe($verifier);
    // Must be URL-safe base64
    expect($challenge)->toMatch('/^[A-Za-z0-9_-]+$/');
});

test('same verifier produces same challenge', function () {
    $oauth = new CanvaOAuth(
        workspacePath: sys_get_temp_dir(),
        clientId: 'test-id',
        clientSecret: 'test-secret',
    );

    $challengeRef = new ReflectionMethod($oauth, 'generateCodeChallenge');

    $challenge1 = $challengeRef->invoke($oauth, 'test-verifier-123');
    $challenge2 = $challengeRef->invoke($oauth, 'test-verifier-123');

    expect($challenge1)->toBe($challenge2);
});

test('different verifiers produce different challenges', function () {
    $oauth = new CanvaOAuth(
        workspacePath: sys_get_temp_dir(),
        clientId: 'test-id',
        clientSecret: 'test-secret',
    );

    $challengeRef = new ReflectionMethod($oauth, 'generateCodeChallenge');

    $challenge1 = $challengeRef->invoke($oauth, 'verifier-aaa');
    $challenge2 = $challengeRef->invoke($oauth, 'verifier-bbb');

    expect($challenge1)->not->toBe($challenge2);
});

test('token storage and loading roundtrip', function () {
    $tmpDir = sys_get_temp_dir() . '/canva-test-' . uniqid();
    mkdir($tmpDir, 0o700, true);

    $oauth = new CanvaOAuth(
        workspacePath: $tmpDir,
        clientId: 'test-id',
        clientSecret: 'test-secret',
    );

    $storeRef = new ReflectionMethod($oauth, 'storeTokens');
    $loadRef = new ReflectionMethod($oauth, 'loadTokens');

    $tokens = [
        'access_token' => 'test-access-token',
        'refresh_token' => 'test-refresh-token',
        'expires_at' => time() + 3600,
    ];

    $storeRef->invoke($oauth, $tokens);

    $loaded = $loadRef->invoke($oauth);

    expect($loaded)->not->toBeNull();
    expect($loaded['access_token'])->toBe('test-access-token');
    expect($loaded['refresh_token'])->toBe('test-refresh-token');

    // Verify file permissions
    $path = $tmpDir . '/.canva-tokens.json';
    expect(file_exists($path))->toBeTrue();
    $perms = fileperms($path) & 0o777;
    expect($perms)->toBe(0o600);

    // Cleanup
    unlink($path);
    rmdir($tmpDir);
});

test('loadTokens returns null when no file exists', function () {
    $oauth = new CanvaOAuth(
        workspacePath: sys_get_temp_dir() . '/nonexistent-' . uniqid(),
        clientId: 'test-id',
        clientSecret: 'test-secret',
    );

    $loadRef = new ReflectionMethod($oauth, 'loadTokens');
    $result = $loadRef->invoke($oauth);

    expect($result)->toBeNull();
});

test('hasTokens returns false when not authenticated', function () {
    $oauth = new CanvaOAuth(
        workspacePath: sys_get_temp_dir() . '/no-tokens-' . uniqid(),
        clientId: 'test-id',
        clientSecret: 'test-secret',
    );

    expect($oauth->hasTokens())->toBeFalse();
});

test('getAccessToken returns null when not authenticated', function () {
    $oauth = new CanvaOAuth(
        workspacePath: sys_get_temp_dir() . '/no-tokens-' . uniqid(),
        clientId: 'test-id',
        clientSecret: 'test-secret',
    );

    expect($oauth->getAccessToken())->toBeNull();
});

test('getAccessToken returns valid token from storage', function () {
    $tmpDir = sys_get_temp_dir() . '/canva-test-' . uniqid();
    mkdir($tmpDir, 0o700, true);

    $oauth = new CanvaOAuth(
        workspacePath: $tmpDir,
        clientId: 'test-id',
        clientSecret: 'test-secret',
    );

    // Store a non-expired token
    $storeRef = new ReflectionMethod($oauth, 'storeTokens');
    $storeRef->invoke($oauth, [
        'access_token' => 'valid-token',
        'expires_at' => time() + 3600,
    ]);

    expect($oauth->getAccessToken())->toBe('valid-token');

    // Cleanup
    unlink($tmpDir . '/.canva-tokens.json');
    rmdir($tmpDir);
});

test('clearTokens removes stored tokens', function () {
    $tmpDir = sys_get_temp_dir() . '/canva-test-' . uniqid();
    mkdir($tmpDir, 0o700, true);

    $oauth = new CanvaOAuth(
        workspacePath: $tmpDir,
        clientId: 'test-id',
        clientSecret: 'test-secret',
    );

    // Store tokens
    $storeRef = new ReflectionMethod($oauth, 'storeTokens');
    $storeRef->invoke($oauth, [
        'access_token' => 'to-be-cleared',
        'expires_at' => time() + 3600,
    ]);

    expect($oauth->hasTokens())->toBeTrue();

    $oauth->clearTokens();

    expect($oauth->hasTokens())->toBeFalse();

    // Cleanup
    rmdir($tmpDir);
});

test('getStatus returns not authenticated when no tokens', function () {
    $oauth = new CanvaOAuth(
        workspacePath: sys_get_temp_dir() . '/no-tokens-' . uniqid(),
        clientId: 'test-id',
        clientSecret: 'test-secret',
    );

    $status = $oauth->getStatus();

    expect($status)->toContain('Not authenticated');
    expect($status)->toContain('canva_auth');
});

test('getStatus returns authenticated with valid token', function () {
    $tmpDir = sys_get_temp_dir() . '/canva-test-' . uniqid();
    mkdir($tmpDir, 0o700, true);

    $oauth = new CanvaOAuth(
        workspacePath: $tmpDir,
        clientId: 'test-id',
        clientSecret: 'test-secret',
    );

    $storeRef = new ReflectionMethod($oauth, 'storeTokens');
    $storeRef->invoke($oauth, [
        'access_token' => 'valid-token',
        'expires_at' => time() + 7200,
    ]);

    $status = $oauth->getStatus();

    expect($status)->toContain('Authenticated');

    // Cleanup
    unlink($tmpDir . '/.canva-tokens.json');
    rmdir($tmpDir);
});

test('credential resolution uses constructor params', function () {
    $oauth = new CanvaOAuth(
        workspacePath: sys_get_temp_dir(),
        clientId: 'my-client-id',
        clientSecret: 'my-client-secret',
    );

    $resolveId = new ReflectionMethod($oauth, 'resolveClientId');
    $resolveSecret = new ReflectionMethod($oauth, 'resolveClientSecret');

    expect($resolveId->invoke($oauth))->toBe('my-client-id');
    expect($resolveSecret->invoke($oauth))->toBe('my-client-secret');
});
