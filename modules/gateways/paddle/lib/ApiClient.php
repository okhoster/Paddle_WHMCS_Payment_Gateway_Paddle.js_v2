<?php
/**
 * Paddle Billing Payment Gateway for WHMCS
 *
 * @category   PaymentGateway
 * @package    WHMCS
 * @author     OKHOSTER
 * @copyright  2026 OKHOSTER
 * @license    GPL-3.0 license
 * @link       https://okhoster.com/
 */

namespace WHMCS\Module\Gateway\Paddle;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * Custom Exception for Paddle API Errors
 */
class PaddleApiException extends \Exception
{
    protected ?string $errorCode;
    protected ?string $errorType;
    protected ?array $errorDetails;
    protected int $httpStatusCode;

    public function __construct(
        string $message,
        int $httpStatusCode = 0,
        ?string $errorCode = null,
        ?string $errorType = null,
        ?array $errorDetails = null,
        ?\Throwable $previous = null
    ) {
        $this->httpStatusCode = $httpStatusCode;
        $this->errorCode = $errorCode;
        $this->errorType = $errorType;
        $this->errorDetails = $errorDetails;
        parent::__construct($message, $httpStatusCode, $previous);
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getErrorType(): ?string
    {
        return $this->errorType;
    }

    public function getErrorDetails(): ?array
    {
        return $this->errorDetails;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}

/**
 * Official Paddle Billing REST API Client
 */
final class ApiClient
{
    public const ENV_SANDBOX = 'sandbox';
    public const ENV_LIVE = 'live';

    private const API_URL_SANDBOX = 'https://sandbox-api.paddle.com';
    private const API_URL_LIVE = 'https://api.paddle.com';

    private const API_VERSION = '1';
    private const TIMEOUT_CONNECT = 10;
    private const TIMEOUT_READ = 30;

    private string $environment;
    private string $apiKey;
    private string $baseUrl;
    private string $logLevel;

    private string $sellerId;

    /**
     * Constructor
     *
     * @param string $environment 'sandbox' or 'live'
     * @param string $apiKey Paddle API Key
     * @param string $logLevel API logging level ('off', 'errors', 'debug')
     * @param string $sellerId Optional Paddle Seller ID / Vendor ID
     * @throws \InvalidArgumentException If environment or key is invalid
     */
    public function __construct(string $environment, string $apiKey, string $logLevel = 'errors', string $sellerId = '')
    {
        $this->environment = (strtolower($environment) === self::ENV_LIVE) ? self::ENV_LIVE : self::ENV_SANDBOX;
        $this->baseUrl = ($this->environment === self::ENV_LIVE) ? self::API_URL_LIVE : self::API_URL_SANDBOX;
        $this->apiKey = trim($apiKey);
        $this->logLevel = $logLevel;
        $this->sellerId = trim($sellerId);

        if (empty($this->apiKey)) {
            throw new \InvalidArgumentException('Paddle API key cannot be empty for environment: ' . $this->environment);
        }

        // Validate that sandbox key is not accidentally used for live or vice versa
        $this->validateKeyEnvironment();
    }

    public function getSellerId(): string
    {
        return $this->sellerId;
    }

    /**
     * Verify that the API key matches the configured environment
     */
    private function validateKeyEnvironment(): void
    {
        // Paddle live keys start with pdl_live_ and sandbox keys with pdl_sdbx_ or pdl_sand_
        $isSandboxKey = str_starts_with($this->apiKey, 'pdl_sand_') || str_starts_with($this->apiKey, 'pdl_sdbx_');
        $isLiveKey = str_starts_with($this->apiKey, 'pdl_live_');

        if ($this->environment === self::ENV_LIVE && $isSandboxKey) {
            throw new \InvalidArgumentException('A Sandbox Paddle API Key cannot be used in Live environment.');
        }
        if ($this->environment === self::ENV_SANDBOX && $isLiveKey) {
            throw new \InvalidArgumentException('A Live Paddle API Key cannot be used in Sandbox environment.');
        }
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Execute an authenticated HTTP request to Paddle Billing API
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint path (e.g. '/transactions')
     * @param array|null $payload Request data (sent as JSON body for POST/PATCH or query for GET)
     * @return array Decoded response 'data'
     * @throws PaddleApiException On API failure
     */
    public function request(string $method, string $endpoint, ?array $payload = null): array
    {
        $method = strtoupper($method);
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        // SSRF protection
        if (!Security::isAllowedApiUrl($url)) {
            throw new \RuntimeException('Security violation: Request destination is outside allowed Paddle API endpoints: ' . $url);
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Paddle-Version: ' . self::API_VERSION,
            'Accept: application/json',
            'User-Agent: WHMCS-PaddleBilling/' . Compatibility::MODULE_VERSION . ' (PHP ' . PHP_VERSION . '; WHMCS ' . Compatibility::getWhmcsVersion() . ')',
        ];

        $ch = curl_init();

        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_CONNECT,
            CURLOPT_TIMEOUT => self::TIMEOUT_READ,
            // Enforce strict TLS certificate verification
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        // On Windows environments where curl.cainfo / openssl.cafile may not be set in php.ini,
        // use the native OS root certificate store to verify Paddle's SSL certificates securely.
        if (defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NATIVE_CA')) {
            $curlOptions[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }

        if ($method === 'GET' && !empty($payload)) {
            $queryString = http_build_query($payload);
            $url .= (str_contains($url, '?') ? '&' : '?') . $queryString;
        } elseif (in_array($method, ['POST', 'PATCH', 'PUT'], true)) {
            $headers[] = 'Content-Type: application/json';
            $jsonBody = !empty($payload) ? Security::safeJsonEncode($payload) : '{}';
            $curlOptions[CURLOPT_POSTFIELDS] = $jsonBody;
        }

        $curlOptions[CURLOPT_URL] = $url;
        $curlOptions[CURLOPT_HTTPHEADER] = $headers;

        curl_setopt_array($ch, $curlOptions);

        $startTime = microtime(true);
        $responseBody = curl_exec($ch);

        $duration = microtime(true) - $startTime;

        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrno !== 0) {
            Logger::logApi($method, $url, $payload, 0, ['curl_error' => $curlError], $duration, $this->logLevel);
            throw new PaddleApiException('Communication error connecting to Paddle: ' . $curlError, 0);
        }

        try {
            $decoded = Security::safeJsonDecode((string)$responseBody);
        } catch (\Throwable $e) {
            Logger::logApi($method, $url, $payload, $httpCode, ['raw_body' => $responseBody], $duration, $this->logLevel);
            throw new PaddleApiException('Invalid JSON received from Paddle API (HTTP ' . $httpCode . '): ' . $e->getMessage(), $httpCode, null, null, null, $e);
        }

        Logger::logApi($method, $url, $payload, $httpCode, $decoded, $duration, $this->logLevel);

        if ($httpCode < 200 || $httpCode >= 300) {
            $err = $decoded['error'] ?? [];
            $code = $err['code'] ?? 'unknown_error';
            $type = $err['type'] ?? 'api_error';
            $detail = $err['detail'] ?? 'Paddle API responded with HTTP status ' . $httpCode;
            $details = $err['errors'] ?? null;

            throw new PaddleApiException($detail, $httpCode, $code, $type, $details);
        }

        return $decoded['data'] ?? $decoded;
    }

    // -------------------------------------------------------------------------
    // TRANSACTIONS
    // -------------------------------------------------------------------------

    public function createTransaction(array $payload): array
    {
        return $this->request('POST', '/transactions', $payload);
    }

    public function getTransaction(string $transactionId): array
    {
        return $this->request('GET', '/transactions/' . urlencode($transactionId));
    }

    // -------------------------------------------------------------------------
    // CUSTOMERS & ADDRESSES
    // -------------------------------------------------------------------------

    public function createCustomer(array $payload): array
    {
        return $this->request('POST', '/customers', $payload);
    }

    public function getCustomer(string $customerId): array
    {
        return $this->request('GET', '/customers/' . urlencode($customerId));
    }

    public function findCustomerByEmail(string $email): ?array
    {
        $res = $this->request('GET', '/customers', ['search' => $email, 'per_page' => 1]);
        if (!empty($res) && is_array($res) && isset($res[0])) {
            return $res[0];
        }
        return null;
    }

    public function createCustomerAddress(string $customerId, array $payload): array
    {
        return $this->request('POST', '/customers/' . urlencode($customerId) . '/addresses', $payload);
    }

    // -------------------------------------------------------------------------
    // SUBSCRIPTIONS
    // -------------------------------------------------------------------------

    public function getSubscription(string $subscriptionId): array
    {
        return $this->request('GET', '/subscriptions/' . urlencode($subscriptionId));
    }

    public function updateSubscription(string $subscriptionId, array $payload): array
    {
        return $this->request('PATCH', '/subscriptions/' . urlencode($subscriptionId), $payload);
    }

    public function cancelSubscription(string $subscriptionId, string $effectiveFrom = 'next_billing_period'): array
    {
        $payload = ['effective_from' => $effectiveFrom];
        return $this->request('POST', '/subscriptions/' . urlencode($subscriptionId) . '/cancel', $payload);
    }

    public function pauseSubscription(string $subscriptionId, string $effectiveFrom = 'next_billing_period'): array
    {
        $payload = ['effective_from' => $effectiveFrom];
        return $this->request('POST', '/subscriptions/' . urlencode($subscriptionId) . '/pause', $payload);
    }

    public function resumeSubscription(string $subscriptionId, string $effectiveFrom = 'immediately'): array
    {
        $payload = ['effective_from' => $effectiveFrom];
        return $this->request('POST', '/subscriptions/' . urlencode($subscriptionId) . '/resume', $payload);
    }

    // -------------------------------------------------------------------------
    // CUSTOMER PORTAL
    // -------------------------------------------------------------------------

    public function createCustomerPortalSession(string $customerId, array $subscriptionIds = []): array
    {
        $payload = [];
        if (!empty($subscriptionIds)) {
            $payload['subscription_ids'] = array_values($subscriptionIds);
        }
        $endpoint = '/customers/' . rawurlencode($customerId) . '/portal-sessions';
        return $this->request('POST', $endpoint, $payload);
    }

    // -------------------------------------------------------------------------
    // ADJUSTMENTS (REFUNDS & CREDITS)
    // -------------------------------------------------------------------------

    public function createAdjustment(array $payload): array
    {
        return $this->request('POST', '/adjustments', $payload);
    }

    // -------------------------------------------------------------------------
    // CATALOG: PRICES & PRODUCTS
    // -------------------------------------------------------------------------

    public function createProduct(array $payload): array
    {
        return $this->request('POST', '/products', $payload);
    }

    public function updateProduct(string $productId, array $payload): array
    {
        return $this->request('PATCH', '/products/' . urlencode($productId), $payload);
    }

    public function createPrice(array $payload): array
    {
        return $this->request('POST', '/prices', $payload);
    }

    public function getPrice(string $priceId): array
    {
        return $this->request('GET', '/prices/' . urlencode($priceId));
    }

    public function getProduct(string $productId): array
    {
        return $this->request('GET', '/products/' . urlencode($productId));
    }


    // -------------------------------------------------------------------------
    // DIAGNOSTICS & SYSTEM
    // -------------------------------------------------------------------------

    public function getEventTypes(): array
    {
        return $this->request('GET', '/event-types');
    }

    public function testConnection(): array
    {
        // Safe read-only request to verify authentication
        return $this->request('GET', '/event-types', ['per_page' => 1]);
    }
}
