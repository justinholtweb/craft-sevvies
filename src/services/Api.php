<?php

namespace justinholtweb\sevvies\services;

use Craft;
use craft\helpers\App;
use craft\helpers\Json;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use justinholtweb\sevvies\errors\ApiException;
use justinholtweb\sevvies\Plugin;
use yii\base\Component;

/**
 * The only place an HTTP request reaches sevDesk.
 *
 * Every call is logged, every failure is classified transient or permanent, and
 * the transport itself is swappable so the rest of the plugin can be exercised
 * without a live account.
 */
class Api extends Component
{
    /**
     * @var callable|null Overrides the HTTP transport. Signature:
     *   fn(string $method, string $url, array $headers, ?string $body): array{0:int,1:string}
     * Returns [statusCode, responseBody].
     */
    public $transport = null;

    /**
     * A configured token is the whole prerequisite.
     */
    public function isConfigured(): bool
    {
        return $this->token() !== '';
    }

    public function token(): string
    {
        return trim((string)App::parseEnv((string)Plugin::getInstance()->getSettings()->apiToken));
    }

    public function baseUrl(): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $url = trim((string)App::parseEnv((string)$settings->apiBaseUrl));

        return $url !== '' ? rtrim($url, '/') : $settings->apiBaseUrl();
    }

    /**
     * GET, returning the `objects` payload.
     */
    public function get(string $path, array $query = [], ?int $orderId = null): mixed
    {
        return $this->objects($this->request('GET', $path, $query, null, $orderId));
    }

    /**
     * POST a JSON body, returning the `objects` payload.
     */
    public function post(string $path, array $body, ?int $orderId = null): mixed
    {
        return $this->objects($this->request('POST', $path, [], $body, $orderId));
    }

    /**
     * PUT a JSON body, returning the `objects` payload.
     */
    public function put(string $path, array $body, ?int $orderId = null): mixed
    {
        return $this->objects($this->request('PUT', $path, [], $body, $orderId));
    }

    public function delete(string $path, ?int $orderId = null): mixed
    {
        return $this->objects($this->request('DELETE', $path, [], null, $orderId));
    }

    /**
     * Perform a request and return the decoded response.
     *
     * @return array The decoded JSON body, or ['objects' => raw] when the
     *               response was not JSON (sevDesk returns raw PDF bytes on
     *               some endpoints).
     * @throws ApiException
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, ?int $orderId = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $token = $this->token();

        if ($token === '') {
            throw new ApiException(Craft::t('sevvies', 'No sevDesk API token is configured.'), null, null, $path);
        }

        $url = $this->baseUrl() . '/' . ltrim($path, '/');

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $encoded = $body === null ? null : Json::encode($body);

        $headers = [
            // sevDesk takes the bare token, not `Bearer <token>`.
            'Authorization' => $token,
            'Accept' => 'application/json',
            'User-Agent' => 'Sevvies for Craft Commerce (justinholt.com)',
        ];

        if ($encoded !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $started = microtime(true);
        $statusCode = null;
        $responseBody = '';
        $error = null;

        try {
            [$statusCode, $responseBody] = $this->send($method, $url, $headers, $encoded);
        } catch (ConnectException $e) {
            $error = $e->getMessage();
        } catch (RequestException $e) {
            $response = $e->getResponse();

            if ($response !== null) {
                $statusCode = $response->getStatusCode();
                $responseBody = (string)$response->getBody();
            } else {
                $error = $e->getMessage();
            }
        }

        $durationMs = (int)round((microtime(true) - $started) * 1000);
        $success = $error === null && $statusCode !== null && $statusCode >= 200 && $statusCode < 300;

        Plugin::getInstance()->log->record([
            'orderId' => $orderId,
            'type' => 'request',
            'method' => $method,
            'endpoint' => $this->redactUrl($path),
            'statusCode' => $statusCode,
            'success' => $success,
            'durationMs' => $durationMs,
            'message' => $success ? null : ($error ?? $this->errorMessage($responseBody, $statusCode)),
            'requestBody' => $settings->logBodies ? $encoded : null,
            'responseBody' => $settings->logBodies ? $this->truncate($responseBody) : null,
        ]);

        if ($error !== null) {
            throw new ApiException(
                Craft::t('sevvies', 'Could not reach sevDesk: {message}', ['message' => $error]),
                null,
                null,
                $path,
            );
        }

        if (!$success) {
            throw new ApiException($this->errorMessage($responseBody, $statusCode), $statusCode, $responseBody, $path);
        }

        $decoded = Json::decodeIfJson($responseBody);

        return is_array($decoded) ? $decoded : ['objects' => $responseBody];
    }

    /**
     * Raw bytes, for PDF downloads.
     *
     * @throws ApiException
     */
    public function download(string $path, array $query = [], ?int $orderId = null): string
    {
        $response = $this->request('GET', $path, $query, null, $orderId);
        $objects = $response['objects'] ?? null;

        // getPdf answers a JSON envelope with base64 content; some accounts
        // answer raw bytes instead.
        if (is_array($objects) && isset($objects['content'])) {
            $content = base64_decode((string)$objects['content'], true);

            if ($content !== false) {
                return $content;
            }
        }

        if (is_string($objects)) {
            return $objects;
        }

        throw new ApiException(Craft::t('sevvies', 'sevDesk returned no document content.'), null, null, $path);
    }

    /**
     * Check the token and report which bookkeeping system the account is on.
     *
     * @return array{ok:bool,message:string,version:?string}
     */
    public function check(): array
    {
        if (!$this->isConfigured()) {
            return [
                'ok' => false,
                'message' => Craft::t('sevvies', 'No API token configured.'),
                'version' => null,
            ];
        }

        try {
            $version = Plugin::getInstance()->meta->bookkeepingVersion(true);
        } catch (ApiException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'version' => null];
        }

        return [
            'ok' => true,
            'message' => Craft::t('sevvies', 'Connected. sevDesk bookkeeping system {version}.', [
                'version' => $version,
            ]),
            'version' => $version,
        ];
    }

    /**
     * @return array{0:int,1:string}
     */
    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        if (is_callable($this->transport)) {
            return ($this->transport)($method, $url, $headers, $body);
        }

        $client = Craft::createGuzzleClient([
            'timeout' => Plugin::getInstance()->getSettings()->timeout,
            'http_errors' => false,
        ]);

        $options = ['headers' => $headers];

        if ($body !== null) {
            $options['body'] = $body;
        }

        $response = $client->request($method, $url, $options);

        return [$response->getStatusCode(), (string)$response->getBody()];
    }

    /**
     * sevDesk answers `{"objects": …}`; unwrap it but never lose a body that
     * does not follow the convention.
     */
    private function objects(array $response): mixed
    {
        return array_key_exists('objects', $response) ? $response['objects'] : $response;
    }

    /**
     * Turn a sevDesk error body into something a merchant can act on.
     */
    private function errorMessage(string $body, ?int $statusCode): string
    {
        $decoded = Json::decodeIfJson($body);
        $detail = null;

        if (is_array($decoded)) {
            $detail = $decoded['error']['message']
                ?? $decoded['message']
                ?? (is_string($decoded['error'] ?? null) ? $decoded['error'] : null);
        }

        if ($detail === null && $body !== '') {
            $detail = $this->truncate($body, 400);
        }

        $prefix = match ($statusCode) {
            401 => Craft::t('sevvies', 'sevDesk rejected the API token.'),
            403 => Craft::t('sevvies', 'The sevDesk token is not allowed to do that.'),
            429 => Craft::t('sevvies', 'sevDesk is rate limiting the connection.'),
            default => Craft::t('sevvies', 'sevDesk returned {code}.', ['code' => $statusCode ?? '?']),
        };

        return $detail ? $prefix . ' ' . $detail : $prefix;
    }

    private function redactUrl(string $path): string
    {
        return substr(ltrim($path, '/'), 0, 255);
    }

    private function truncate(?string $value, int $length = 60000): ?string
    {
        if ($value === null) {
            return null;
        }

        return strlen($value) > $length ? substr($value, 0, $length) . '…' : $value;
    }
}
