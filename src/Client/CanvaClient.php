<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitCanva\Client;

use CarmeloSantana\CoquiToolkitCanva\Exception\CanvaApiException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Low-level HTTP client for the Canva Connect REST API v1.
 *
 * Handles authentication (via CanvaOAuth), request dispatch, response
 * parsing, continuation-token pagination, and rate-limit retry.
 */
final class CanvaClient
{
    private const string BASE_URL = 'https://api.canva.com/rest/v1';
    private const int TIMEOUT = 30;
    private const int MAX_PAGES = 10;
    private const int RATE_LIMIT_MAX_RETRIES = 1;

    public function __construct(
        private readonly CanvaOAuth $oauth,
        private readonly HttpClientInterface $httpClient = new \Symfony\Component\HttpClient\CurlHttpClient(),
    ) {}

    /**
     * Create a client from environment variables.
     */
    public static function fromEnv(string $workspacePath): self
    {
        $oauth = CanvaOAuth::fromEnv($workspacePath);

        return new self(oauth: $oauth);
    }

    public function oauth(): CanvaOAuth
    {
        return $this->oauth;
    }

    // -- HTTP verbs ----------------------------------------------------------

    /**
     * @param array<string, mixed> $query
     */
    public function get(string $endpoint, array $query = []): CanvaResult
    {
        return $this->request('GET', $endpoint, query: $query);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function post(string $endpoint, array $body = []): CanvaResult
    {
        return $this->request('POST', $endpoint, body: $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function put(string $endpoint, array $body = []): CanvaResult
    {
        return $this->request('PUT', $endpoint, body: $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function patch(string $endpoint, array $body = []): CanvaResult
    {
        return $this->request('PATCH', $endpoint, body: $body);
    }

    public function delete(string $endpoint): CanvaResult
    {
        return $this->request('DELETE', $endpoint);
    }

    /**
     * Upload binary data (for asset uploads).
     *
     * @param array<string, string> $headers
     */
    public function postBinary(string $endpoint, string $data, string $contentType, array $headers = []): CanvaResult
    {
        $token = $this->resolveToken();
        if ($token === null) {
            return $this->authRequiredResult();
        }

        $options = [
            'headers' => array_merge([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => $contentType,
            ], $headers),
            'body' => $data,
            'timeout' => 120,
        ];

        $url = self::BASE_URL . '/' . ltrim($endpoint, '/');

        try {
            $response = $this->httpClient->request('POST', $url, $options);

            return CanvaResult::fromResponse($response);
        } catch (HttpExceptionInterface $e) {
            return CanvaResult::fromErrorResponse($e);
        } catch (TransportExceptionInterface $e) {
            return CanvaResult::error('Transport error: ' . $e->getMessage());
        }
    }

    // -- Pagination ----------------------------------------------------------

    /**
     * Fetch all pages of a paginated endpoint using Canva's continuation token.
     *
     * @param array<string, mixed> $query
     * @param string $itemsKey The JSON key containing the array of items
     * @return CanvaResult Combined result with merged items
     */
    public function paginate(
        string $endpoint,
        array $query = [],
        string $itemsKey = 'items',
        int $maxPages = self::MAX_PAGES,
    ): CanvaResult {
        $allItems = [];

        for ($i = 0; $i < $maxPages; $i++) {
            $result = $this->get($endpoint, $query);

            if (!$result->success) {
                return $result;
            }

            $items = $result->data[$itemsKey] ?? [];
            if (is_array($items)) {
                $allItems = array_merge($allItems, $items);
            }

            $continuation = $result->data['continuation'] ?? null;
            if ($continuation === null || $continuation === '') {
                break;
            }

            $query['continuation'] = $continuation;
        }

        return new CanvaResult(
            success: true,
            data: [$itemsKey => $allItems, 'total_count' => count($allItems)],
            statusCode: 200,
        );
    }

    // -- Job polling ---------------------------------------------------------

    /**
     * Poll an async job endpoint until it completes or times out.
     *
     * @param int $timeoutSeconds Maximum time to wait for job completion
     * @param int $intervalSeconds Delay between polling requests
     */
    public function pollJob(
        string $endpoint,
        int $timeoutSeconds = 60,
        int $intervalSeconds = 3,
    ): CanvaResult {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $result = $this->get($endpoint);

            if (!$result->success) {
                return $result;
            }

            $status = $result->data['job']['status'] ?? $result->data['status'] ?? null;

            if ($status === 'success' || $status === 'completed') {
                return $result;
            }

            if ($status === 'failed') {
                $error = $result->data['job']['error'] ?? $result->data['error'] ?? [];
                $message = is_array($error) ? ($error['message'] ?? 'Job failed') : (string) $error;

                return CanvaResult::error("Job failed: {$message}");
            }

            sleep($intervalSeconds);
        }

        return CanvaResult::error("Job polling timed out after {$timeoutSeconds} seconds");
    }

    // -- Internal ------------------------------------------------------------

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function request(
        string $method,
        string $endpoint,
        array $query = [],
        array $body = [],
    ): CanvaResult {
        $token = $this->resolveToken();
        if ($token === null) {
            return $this->authRequiredResult();
        }

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'timeout' => self::TIMEOUT,
        ];

        if ($query !== []) {
            $options['query'] = $this->filterQuery($query);
        }

        if ($body !== [] && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $options['json'] = $body;
        }

        $url = self::BASE_URL . '/' . ltrim($endpoint, '/');

        return $this->executeWithRateLimitRetry($method, $url, $options);
    }

    /**
     * Execute a request with one retry on 429 rate limit.
     *
     * @param array<string, mixed> $options
     */
    private function executeWithRateLimitRetry(string $method, string $url, array $options): CanvaResult
    {
        for ($attempt = 0; $attempt <= self::RATE_LIMIT_MAX_RETRIES; $attempt++) {
            try {
                $response = $this->httpClient->request($method, $url, $options);
                $statusCode = $response->getStatusCode();

                if ($statusCode === 429 && $attempt < self::RATE_LIMIT_MAX_RETRIES) {
                    $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? '5';
                    $delay = min((int) $retryAfter, 30);
                    sleep(max($delay, 1));

                    continue;
                }

                return CanvaResult::fromResponse($response);
            } catch (HttpExceptionInterface $e) {
                $statusCode = $e->getResponse()->getStatusCode();

                if ($statusCode === 429 && $attempt < self::RATE_LIMIT_MAX_RETRIES) {
                    $retryAfter = $e->getResponse()->getHeaders(false)['retry-after'][0] ?? '5';
                    $delay = min((int) $retryAfter, 30);
                    sleep(max($delay, 1));

                    continue;
                }

                return CanvaResult::fromErrorResponse($e);
            } catch (TransportExceptionInterface $e) {
                return CanvaResult::error('Transport error: ' . $e->getMessage());
            }
        }

        return CanvaResult::error('Rate limit exceeded after retry');
    }

    /**
     * Remove null/empty-string values from query parameters.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function filterQuery(array $query): array
    {
        return array_filter($query, static fn(mixed $v): bool => $v !== null && $v !== '');
    }

    private function resolveToken(): ?string
    {
        return $this->oauth->getAccessToken();
    }

    private function authRequiredResult(): CanvaResult
    {
        return CanvaResult::error(
            'Not authenticated with Canva. Run canva_auth(action: "login") to connect your Canva account.',
        );
    }
}
