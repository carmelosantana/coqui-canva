<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitCanva\Client;

use CarmeloSantana\CoquiToolkitCanva\Exception\CanvaAuthException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OAuth 2.0 PKCE authentication handler for the Canva Connect API.
 *
 * Manages the full OAuth lifecycle: authorization URL generation, code exchange,
 * token refresh, revocation, and secure file-based token storage.
 */
final class CanvaOAuth
{
    private const string AUTH_URL = 'https://www.canva.com/api/oauth/authorize';
    private const string TOKEN_URL = 'https://api.canva.com/rest/v1/oauth/token';
    private const string REVOKE_URL = 'https://api.canva.com/rest/v1/oauth/revoke';
    private const string TOKENS_FILE = '.canva-tokens.json';
    private const int CALLBACK_TIMEOUT = 120;
    private const int CALLBACK_PORT_MIN = 49152;
    private const int CALLBACK_PORT_MAX = 65535;
    private const int TOKEN_EXPIRY_BUFFER = 60;

    private const array DEFAULT_SCOPES = [
        'design:content:read',
        'design:content:write',
        'design:meta:read',
        'asset:read',
        'asset:write',
        'folder:read',
        'folder:write',
        'brandtemplate:meta:read',
        'brandtemplate:content:read',
        'comment:read',
        'comment:write',
        'profile:read',
    ];

    private string $resolvedClientId = '';
    private string $resolvedClientSecret = '';

    public function __construct(
        private readonly string $workspacePath,
        private readonly string $clientId = '',
        private readonly string $clientSecret = '',
        private readonly HttpClientInterface $httpClient = new \Symfony\Component\HttpClient\CurlHttpClient(),
    ) {}

    public static function fromEnv(string $workspacePath): self
    {
        return new self(
            workspacePath: $workspacePath,
            clientId: self::envString('CANVA_CLIENT_ID'),
            clientSecret: self::envString('CANVA_CLIENT_SECRET'),
        );
    }

    /**
     * Perform the full OAuth 2.0 authorization code flow with PKCE.
     *
     * Opens the user's browser, waits for the callback, exchanges the code
     * for tokens, and stores them to disk.
     *
     * @param list<string> $scopes
     * @return array{access_token: string, refresh_token?: string, expires_at?: int}
     */
    public function authorize(array $scopes = []): array
    {
        $clientId = $this->resolveClientId();
        $clientSecret = $this->resolveClientSecret();

        if ($clientId === '' || $clientSecret === '') {
            throw CanvaAuthException::configError(
                'CANVA_CLIENT_ID and CANVA_CLIENT_SECRET are required. '
                . 'Set them via: credentials(action: "set", key: "CANVA_CLIENT_ID", value: "...")',
            );
        }

        if ($scopes === []) {
            $scopes = self::DEFAULT_SCOPES;
        }

        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        $port = $this->findAvailablePort();
        $redirectUri = sprintf('http://127.0.0.1:%d/callback', $port);

        $state = bin2hex(random_bytes(16));
        $params = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
            'scope' => implode(' ', $scopes),
        ];

        $fullAuthUrl = self::AUTH_URL . '?' . http_build_query($params);

        $this->openBrowser($fullAuthUrl);

        $callbackData = $this->waitForCallback($port, $state);

        if (isset($callbackData['error'])) {
            throw CanvaAuthException::authorizationFailed(
                $callbackData['error'],
                $callbackData['error_description'] ?? '',
            );
        }

        $authCode = $callbackData['code'] ?? '';
        if ($authCode === '') {
            throw CanvaAuthException::authorizationFailed('no_code', 'No authorization code received');
        }

        $tokens = $this->exchangeCode($authCode, $redirectUri, $codeVerifier);
        $this->storeTokens($tokens);

        return $tokens;
    }

    /**
     * Get a valid access token, auto-refreshing if expired.
     */
    public function getAccessToken(): ?string
    {
        $tokens = $this->loadTokens();
        if ($tokens === null) {
            return null;
        }

        $expiresAt = $tokens['expires_at'] ?? 0;

        if ($expiresAt > 0 && $expiresAt < time() + self::TOKEN_EXPIRY_BUFFER) {
            $refreshToken = $tokens['refresh_token'] ?? null;
            if ($refreshToken === null) {
                return null;
            }

            try {
                $newTokens = $this->refreshToken($refreshToken);
                $this->storeTokens($newTokens);

                return $newTokens['access_token'];
            } catch (\Throwable) {
                return null;
            }
        }

        return $tokens['access_token'];
    }

    /**
     * Check whether stored tokens exist.
     */
    public function hasTokens(): bool
    {
        return $this->loadTokens() !== null;
    }

    /**
     * Get the current authentication status as a human-readable string.
     */
    public function getStatus(): string
    {
        $tokens = $this->loadTokens();
        if ($tokens === null) {
            return 'Not authenticated. Run canva_auth(action: "login") to connect your Canva account.';
        }

        $accessToken = $tokens['access_token'];
        $expiresAt = $tokens['expires_at'] ?? 0;
        if ($expiresAt > 0 && $expiresAt < time()) {
            $refreshToken = $tokens['refresh_token'] ?? null;
            if ($refreshToken !== null) {
                return 'Access token expired but can be refreshed automatically on next API call.';
            }

            return 'Access token expired. Run canva_auth(action: "login") to re-authenticate.';
        }

        $remaining = $expiresAt > 0 ? $expiresAt - time() : 0;
        $hours = intdiv($remaining, 3600);
        $minutes = intdiv($remaining % 3600, 60);

        return sprintf('Authenticated. Token expires in %dh %dm.', $hours, $minutes);
    }

    /**
     * Revoke the current access token and clear stored tokens.
     */
    public function logout(): void
    {
        $tokens = $this->loadTokens();
        if ($tokens !== null) {
            $accessToken = $tokens['access_token'];
            if ($accessToken !== '') {
                $this->revokeToken($accessToken);
            }
        }

        $this->clearTokens();
    }

    /**
     * Delete stored tokens from disk.
     */
    public function clearTokens(): void
    {
        $path = $this->tokensPath();
        if (file_exists($path)) {
            unlink($path);
        }
    }

    // -- PKCE ----------------------------------------------------------------

    private function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function generateCodeChallenge(string $verifier): string
    {
        $hash = hash('sha256', $verifier, true);

        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    // -- Token exchange ------------------------------------------------------

    /**
     * Exchange an authorization code for tokens.
     *
     * @return array{access_token: string, refresh_token?: string, expires_at?: int}
     */
    private function exchangeCode(string $code, string $redirectUri, string $codeVerifier): array
    {
        $clientId = $this->resolveClientId();
        $clientSecret = $this->resolveClientSecret();

        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $codeVerifier,
            ]),
            'timeout' => 30,
        ]);

        $data = $response->toArray(false);

        if (!isset($data['access_token'])) {
            $msg = $data['message'] ?? $data['error_description'] ?? 'Invalid token response';
            throw CanvaAuthException::tokenExchangeFailed((string) $msg);
        }

        return $this->normalizeTokenResponse($data);
    }

    /**
     * Refresh an access token.
     *
     * @return array{access_token: string, refresh_token?: string, expires_at?: int}
     */
    private function refreshToken(string $refreshToken): array
    {
        $clientId = $this->resolveClientId();
        $clientSecret = $this->resolveClientSecret();

        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]),
            'timeout' => 30,
        ]);

        $data = $response->toArray(false);

        if (!isset($data['access_token'])) {
            throw CanvaAuthException::tokenExchangeFailed('Refresh token exchange returned no access token');
        }

        return $this->normalizeTokenResponse($data);
    }

    /**
     * Revoke an access token.
     */
    private function revokeToken(string $accessToken): void
    {
        $clientId = $this->resolveClientId();
        $clientSecret = $this->resolveClientSecret();

        try {
            $this->httpClient->request('POST', self::REVOKE_URL, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => http_build_query([
                    'token' => $accessToken,
                ]),
                'timeout' => 10,
            ]);
        } catch (\Throwable) {
            // Best-effort revocation — don't fail the logout flow
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{access_token: string, refresh_token?: string, expires_at?: int}
     */
    private function normalizeTokenResponse(array $data): array
    {
        $result = [
            'access_token' => (string) $data['access_token'],
        ];

        if (isset($data['refresh_token'])) {
            $result['refresh_token'] = (string) $data['refresh_token'];
        }

        if (isset($data['expires_in'])) {
            $result['expires_at'] = time() + (int) $data['expires_in'];
        }

        return $result;
    }

    // -- Browser + callback --------------------------------------------------

    private function openBrowser(string $url): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Linux' => 'xdg-open',
            'Darwin' => 'open',
            'Windows' => 'start',
            default => null,
        };

        if ($command === null) {
            return;
        }

        $escapedUrl = escapeshellarg($url);
        exec("{$command} {$escapedUrl} > /dev/null 2>&1 &");
    }

    private function findAvailablePort(): int
    {
        for ($i = 0; $i < 100; $i++) {
            $port = random_int(self::CALLBACK_PORT_MIN, self::CALLBACK_PORT_MAX);
            $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr, STREAM_SERVER_BIND);

            if ($socket !== false) {
                fclose($socket);

                return $port;
            }
        }

        throw CanvaAuthException::configError('Could not find an available port for OAuth callback');
    }

    /**
     * @return array<string, string>
     */
    private function waitForCallback(int $port, string $expectedState): array
    {
        $server = @stream_socket_server(
            "tcp://127.0.0.1:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );

        if ($server === false) {
            throw CanvaAuthException::configError("Could not start callback server on port {$port}: {$errstr}");
        }

        stream_set_timeout($server, self::CALLBACK_TIMEOUT);

        $result = [];

        try {
            $client = @stream_socket_accept($server, self::CALLBACK_TIMEOUT);

            if ($client === false) {
                throw CanvaAuthException::authorizationFailed('timeout', 'No callback received within timeout');
            }

            $request = '';
            while (($line = fgets($client)) !== false) {
                $request .= $line;
                if (trim($line) === '') {
                    break;
                }
            }

            if (preg_match('/GET\s+\/callback\?([^\s]+)/', $request, $matches)) {
                parse_str($matches[1], $queryParams);
                /** @var array<string, string> $queryParams */
                $result = $queryParams;
            }

            $receivedState = $result['state'] ?? '';
            if ($receivedState !== $expectedState) {
                throw CanvaAuthException::authorizationFailed('state_mismatch', 'OAuth state parameter does not match');
            }

            $body = '<!DOCTYPE html><html><body><h1>Canva Authorization Complete</h1>'
                . '<p>You can close this tab and return to Coqui.</p>'
                . '<script>window.close();</script></body></html>';
            $response = "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n"
                . 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
            fwrite($client, $response);
            fclose($client);
        } finally {
            fclose($server);
        }

        return $result;
    }

    // -- Token storage -------------------------------------------------------

    /**
     * @param array{access_token: string, refresh_token?: string, expires_at?: int} $tokens
     */
    private function storeTokens(array $tokens): void
    {
        $path = $this->tokensPath();
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }

        $json = json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($path, $json . "\n");
        chmod($path, 0o600);
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_at?: int}|null
     */
    private function loadTokens(): ?array
    {
        $path = $this->tokensPath();

        if (!file_exists($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);
        if (!is_array($data) || !isset($data['access_token'])) {
            return null;
        }

        /** @var array{access_token: string, refresh_token?: string, expires_at?: int} $data */
        return $data;
    }

    private function tokensPath(): string
    {
        return $this->workspacePath . '/' . self::TOKENS_FILE;
    }

    // -- Credential resolution -----------------------------------------------

    private function resolveClientId(): string
    {
        if ($this->resolvedClientId !== '') {
            return $this->resolvedClientId;
        }

        if ($this->clientId !== '') {
            $this->resolvedClientId = $this->clientId;

            return $this->resolvedClientId;
        }

        $env = getenv('CANVA_CLIENT_ID');
        $this->resolvedClientId = is_string($env) && $env !== '' ? $env : '';

        return $this->resolvedClientId;
    }

    private function resolveClientSecret(): string
    {
        if ($this->resolvedClientSecret !== '') {
            return $this->resolvedClientSecret;
        }

        if ($this->clientSecret !== '') {
            $this->resolvedClientSecret = $this->clientSecret;

            return $this->resolvedClientSecret;
        }

        $env = getenv('CANVA_CLIENT_SECRET');
        $this->resolvedClientSecret = is_string($env) && $env !== '' ? $env : '';

        return $this->resolvedClientSecret;
    }

    private static function envString(string $name): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : '';
    }
}
