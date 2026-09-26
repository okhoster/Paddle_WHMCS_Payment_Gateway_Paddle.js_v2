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
 * Security and Data Sanitization Utility
 */
final class Security
{
    /**
     * Allowed Paddle API base domains to prevent SSRF
     */
    private const ALLOWED_API_DOMAINS = [
        'api.paddle.com',
        'sandbox-api.paddle.com',
    ];

    /**
     * Maximum payload size allowed for webhooks (1MB)
     */
    public const MAX_WEBHOOK_PAYLOAD_BYTES = 1048576;

    /**
     * Validate an API URL against the SSRF allowlist
     *
     * @param string $url Full URL
     * @return bool True if host is in the allowlist
     */
    public static function isAllowedApiUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }

        $host = strtolower($parts['host']);
        return in_array($host, self::ALLOWED_API_DOMAINS, true);
    }

    /**
     * Mask sensitive credentials and tokens for safe logging and UI display
     *
     * @param string|null $secret Secret string to mask
     * @param int $keepPrefix Number of characters to keep at beginning
     * @param int $keepSuffix Number of characters to keep at end
     * @return string Masked string
     */
    public static function maskSecret(?string $secret, int $keepPrefix = 6, int $keepSuffix = 4): string
    {
        if (empty($secret)) {
            return '[NOT_CONFIGURED]';
        }

        $len = strlen($secret);
        if ($len <= ($keepPrefix + $keepSuffix)) {
            return str_repeat('*', $len);
        }

        $prefix = substr($secret, 0, $keepPrefix);
        $suffix = substr($secret, -$keepSuffix);
        return $prefix . str_repeat('*', min(12, $len - $keepPrefix - $keepSuffix)) . $suffix;
    }

    /**
     * Redact secrets from arbitrary data structures (arrays or strings)
     *
     * @param mixed $data Data to sanitize
     * @return mixed Sanitized copy
     */
    public static function redactSensitiveData(mixed $data): mixed
    {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                $lowerKey = strtolower((string)$key);
                if (
                    str_contains($lowerKey, 'secret') ||
                    str_contains($lowerKey, 'apikey') ||
                    str_contains($lowerKey, 'api_key') ||
                    str_contains($lowerKey, 'token') ||
                    str_contains($lowerKey, 'password') ||
                    str_contains($lowerKey, 'authorization') ||
                    str_contains($lowerKey, 'card') ||
                    str_contains($lowerKey, 'cvv') ||
                    str_contains($lowerKey, 'auth')
                ) {
                    $sanitized[$key] = is_string($value) ? self::maskSecret($value) : '[REDACTED]';
                } else {
                    $sanitized[$key] = self::redactSensitiveData($value);
                }
            }
            return $sanitized;
        }

        if (is_string($data)) {
            // Redact Authorization headers if present in raw string
            $data = preg_replace('/Bearer\s+([A-Za-z0-9_\-\.]+)/i', 'Bearer [REDACTED]', $data);
            // Redact Paddle keys like pdl_live_... or pdl_sand_...
            $data = preg_replace('/(pdl_(?:live|sand)_[a-zA-Z0-9_\-]+)/', '[REDACTED_PADDLE_KEY]', $data);
            // Redact webhook secrets like pdl_ntfset_...
            $data = preg_replace('/(pdl_ntfset_[a-zA-Z0-9_\-]+)/', '[REDACTED_WEBHOOK_SECRET]', $data);
            return $data;
        }

        return $data;
    }

    /**
     * Safely parse JSON string with depth and error checking
     *
     * @param string $json JSON string
     * @param int $maxDepth Maximum allowed recursion depth
     * @return array Decoded array
     * @throws \RuntimeException If JSON is invalid or exceeds depth
     */
    public static function safeJsonDecode(string $json, int $maxDepth = 32): array
    {
        if (empty(trim($json))) {
            throw new \RuntimeException('Empty JSON payload provided');
        }

        $decoded = json_decode($json, true, $maxDepth, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('JSON decoding error: ' . json_last_error_msg());
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON structure: expected object or array');
        }

        return $decoded;
    }

    /**
     * Safely encode data to JSON with consistent options
     *
     * @param mixed $data Data to encode
     * @return string JSON string
     * @throws \RuntimeException If encoding fails
     */
    public static function safeJsonEncode(mixed $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('JSON encoding error: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * Escape output for safe HTML rendering to prevent XSS
     *
     * @param mixed $value Value to escape
     * @return string Sanitized HTML
     */
    public static function escapeHtml(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Generate or verify CSRF token for administrative actions
     *
     * @param string $action Action identifier
     * @return string Generated CSRF token
     */
    public static function generateCsrfToken(string $action): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $salt = defined('EMPTY_PASSWORD_HASH') ? EMPTY_PASSWORD_HASH : 'paddle_whmcs_csrf_salt';
        $token = hash_hmac('sha256', session_id() . '|' . $action, $salt);
        $_SESSION['paddle_csrf_' . $action] = $token;
        return $token;
    }

    /**
     * Validate CSRF token for an action
     *
     * @param string $action Action identifier
     * @param string|null $providedToken Token sent by client
     * @return bool True if valid
     */
    public static function validateCsrfToken(string $action, ?string $providedToken): bool
    {
        if (empty($providedToken)) {
            return false;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $sessionKey = 'paddle_csrf_' . $action;
        if (empty($_SESSION[$sessionKey])) {
            return false;
        }

        $expected = (string)$_SESSION[$sessionKey];
        return hash_equals($expected, $providedToken);
    }
}
