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
 * Audit and Gateway Logging Service
 */
final class Logger
{
    private const GATEWAY_NAME = 'paddle';

    /**
     * Log transaction activity into WHMCS Gateway Log
     *
     * @param string $action Action description
     * @param mixed $rawData Request/response payload or diagnostic data
     * @param string $status Result status (e.g. 'Success', 'Failed', 'Received')
     */
    public static function logTransaction(string $action, mixed $rawData, string $status): void
    {
        $sanitizedData = Security::redactSensitiveData($rawData);

        if (is_array($sanitizedData) || is_object($sanitizedData)) {
            $formattedData = [
                'action' => $action,
                'timestamp' => date('Y-m-d H:i:s'),
                'details' => $sanitizedData,
            ];
        } else {
            $formattedData = "Action: {$action}\nTimestamp: " . date('Y-m-d H:i:s') . "\nDetails: {$sanitizedData}";
        }

        if (function_exists('logTransaction')) {
            \logTransaction(self::GATEWAY_NAME, $formattedData, $status);
        }
    }

    /**
     * Log an API request and response safely
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $url Target endpoint URL
     * @param mixed $requestPayload Request body or query
     * @param int $responseCode HTTP status code
     * @param mixed $responsePayload Response body or array
     * @param float $durationSec Request duration in seconds
     * @param string $configuredLogLevel Logging level ('off', 'errors', 'debug')
     */
    public static function logApi(
        string $method,
        string $url,
        mixed $requestPayload,
        int $responseCode,
        mixed $responsePayload,
        float $durationSec,
        string $configuredLogLevel = 'errors'
    ): void {
        if ($configuredLogLevel === 'off') {
            return;
        }

        $isError = ($responseCode < 200 || $responseCode >= 300);

        if ($configuredLogLevel === 'errors' && !$isError) {
            return;
        }

        $logEntry = [
            'type' => 'PADDLE_API_REQUEST',
            'method' => $method,
            'url' => $url,
            'request' => Security::redactSensitiveData($requestPayload),
            'http_code' => $responseCode,
            'response' => Security::redactSensitiveData($responsePayload),
            'duration' => round($durationSec * 1000, 2) . 'ms',
        ];

        $status = $isError ? 'API Error (' . $responseCode . ')' : 'API Success';
        self::logTransaction("Paddle API: {$method} " . parse_url($url, PHP_URL_PATH), $logEntry, $status);
    }

    /**
     * Log a webhook processing event safely
     *
     * @param string $eventId Paddle Event ID (e.g. evt_...)
     * @param string $eventType Paddle Event Type (e.g. transaction.paid)
     * @param string $status Processing result
     * @param string $message Descriptive log message
     * @param mixed $context Additional context data
     * @param string $configuredLogLevel Logging level ('off', 'errors', 'normal', 'debug')
     */
    public static function logWebhook(
        string $eventId,
        string $eventType,
        string $status,
        string $message,
        mixed $context = [],
        string $configuredLogLevel = 'normal'
    ): void {
        if ($configuredLogLevel === 'off') {
            return;
        }

        $isError = (strcasecmp($status, 'error') === 0 || strcasecmp($status, 'failed') === 0);

        if ($configuredLogLevel === 'errors' && !$isError) {
            return;
        }

        if ($configuredLogLevel === 'normal' && !$isError && $status === 'Ignored') {
            return;
        }

        $logEntry = [
            'type' => 'PADDLE_WEBHOOK_EVENT',
            'event_id' => $eventId,
            'event_type' => $eventType,
            'status' => $status,
            'message' => $message,
            'context' => Security::redactSensitiveData($context),
        ];

        self::logTransaction("Paddle Webhook [{$eventType}]: {$eventId}", $logEntry, $status);
    }
}
