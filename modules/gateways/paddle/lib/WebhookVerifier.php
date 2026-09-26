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
 * Paddle Billing Webhook Signature Verifier
 */
final class WebhookVerifier
{
    /**
     * Standard timestamp tolerance in seconds (5 minutes) for strict replay defense
     */
    public const DEFAULT_TIMESTAMP_TOLERANCE_SECONDS = 300;

    /**
     * Verify the Paddle webhook signature from the raw request body and header
     *
     * @param string $rawBody Unmodified HTTP request body string
     * @param string|null $signatureHeader Value of the 'Paddle-Signature' HTTP header
     * @param string $webhookSecret Configured webhook endpoint secret (e.g. pdl_ntfset_...)
     * @param int $toleranceSec Maximum allowable difference between ts and server time
     * @return bool True if signature is valid and within timestamp tolerance
     * @throws \InvalidArgumentException If inputs are missing or malformed
     */
    public static function verify(
        string $rawBody,
        ?string $signatureHeader,
        string $webhookSecret,
        int $toleranceSec = self::DEFAULT_TIMESTAMP_TOLERANCE_SECONDS
    ): bool {
        if (empty($signatureHeader)) {
            return false;
        }

        $trimmedSecret = trim($webhookSecret);
        if (empty($trimmedSecret)) {
            return false;
        }

        // Parse ts and h1 components from header (format: "ts=1671552777;h1=hash1;h1=hash2")
        $parsed = self::parseSignatureHeader($signatureHeader);
        if ($parsed['timestamp'] === null || empty($parsed['hashes'])) {
            return false;
        }

        $timestamp = $parsed['timestamp'];

        // Validate timestamp against clock drift / replay window
        $currentTime = time();
        if (abs($currentTime - $timestamp) > $toleranceSec) {
            return false;
        }

        // Reconstruct signed payload string: ts + ":" + rawBody
        $signedPayload = (string)$timestamp . ':' . $rawBody;

        // Compute expected HMAC-SHA256 hash using the endpoint secret
        $expectedHash = hash_hmac('sha256', $signedPayload, $trimmedSecret);

        // Support secret rotation: Paddle may send multiple h1 hashes in the header
        foreach ($parsed['hashes'] as $candidateHash) {
            if (hash_equals($expectedHash, $candidateHash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse the Paddle-Signature header into timestamp and hash list
     *
     * @param string $header Header string (e.g. 'ts=1671552777;h1=abc...;h1=def...')
     * @return array ['timestamp' => ?int, 'hashes' => string[]]
     */
    public static function parseSignatureHeader(string $header): array
    {
        $timestamp = null;
        $hashes = [];

        $parts = explode(';', $header);
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            $keyValue = explode('=', $part, 2);
            if (count($keyValue) !== 2) {
                continue;
            }

            $key = trim($keyValue[0]);
            $val = trim($keyValue[1]);

            if ($key === 'ts' && is_numeric($val)) {
                $timestamp = (int)$val;
            } elseif ($key === 'h1' && !empty($val)) {
                $hashes[] = $val;
            }
        }

        return [
            'timestamp' => $timestamp,
            'hashes' => $hashes,
        ];
    }
}
