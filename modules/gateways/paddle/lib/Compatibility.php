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
 * WHMCS and PHP Compatibility Abstraction Layer
 */
final class Compatibility
{
    /**
     * Module Version
     */
    public const MODULE_VERSION = '1.0.0';

    /**
     * Get the detected WHMCS version
     *
     * @return string Normalized WHMCS version string (e.g. "8.10.1")
     */
    public static function getWhmcsVersion(): string
    {
        if (class_exists('\WHMCS\Config\ApplicationConfig')) {
            try {
                $version = \WHMCS\Config\ApplicationConfig::getVersion();
                if ($version instanceof \WHMCS\Version\SemanticVersion) {
                    return $version->getCasual();
                }
                if (is_string($version)) {
                    return $version;
                }
            } catch (\Throwable) {
                // Fallback below
            }
        }

        global $CONFIG;
        if (!empty($CONFIG['Version'])) {
            return (string)$CONFIG['Version'];
        }

        return '8.10.1'; // Conservative default fallback
    }

    /**
     * Get the detected PHP version
     *
     * @return string PHP version string
     */
    public static function getPhpVersion(): string
    {
        return PHP_VERSION;
    }

    /**
     * Check if the runtime environment meets all compatibility requirements
     *
     * @return array [bool $isCompatible, array $messages]
     */
    public static function checkEnvironmentCompatibility(): array
    {
        $messages = [];
        $compatible = true;

        // Check PHP Version (Requires >= 8.1 and <= 8.3.x)
        if (PHP_VERSION_ID < 80100) {
            $compatible = false;
            $messages[] = 'PHP 8.1 or higher is required. Detected PHP version: ' . PHP_VERSION;
        } elseif (PHP_VERSION_ID >= 80400) {
            $messages[] = 'Notice: Running on PHP 8.4+. Module is strictly designed for PHP 8.1 - 8.3 compatibility.';
        }

        // Check Required PHP Extensions
        $requiredExtensions = ['curl', 'json', 'openssl', 'mbstring', 'bcmath', 'pdo'];
        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                $compatible = false;
                $messages[] = 'Missing required PHP extension: ' . $ext;
            }
        }

        // Check WHMCS Capsule DB interaction
        if (!class_exists('\WHMCS\Database\Capsule')) {
            $compatible = false;
            $messages[] = 'WHMCS Capsule database abstraction is not available.';
        }

        return [$compatible, $messages];
    }

    /**
     * Get WHMCS System Base URL dynamically with trailing slash
     *
     * @return string Normalized System URL
     */
    public static function getSystemUrl(): string
    {
        if (class_exists('\WHMCS\Config\Setting')) {
            try {
                $url = \WHMCS\Config\Setting::getValue('SystemURL');
                if (!empty($url)) {
                    return rtrim($url, '/') . '/';
                }
            } catch (\Throwable) {
                // Fallback below
            }
        }

        global $CONFIG;
        if (!empty($CONFIG['SystemURL'])) {
            return rtrim((string)$CONFIG['SystemURL'], '/') . '/';
        }

        // Server detection fallback
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $protocol . $host . '/';
    }

    /**
     * Get the full absolute Webhook Callback URL for this installation
     *
     * @return string Webhook endpoint URL
     */
    public static function getWebhookCallbackUrl(): string
    {
        return self::getSystemUrl() . 'modules/gateways/callback/paddle.php';
    }

    /**
     * Check if current session has an authenticated WHMCS administrator
     *
     * @return bool True if logged-in admin
     */
    public static function isAdminAuthenticated(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        // WHMCS stores admin ID in $_SESSION['adminid']
        return !empty($_SESSION['adminid']) && is_numeric($_SESSION['adminid']);
    }

    /**
     * Normalize environment string to 'live' or 'sandbox'
     *
     * @param string|null $env Environment string (e.g. 'live', 'Live (Production)', 'sandbox')
     * @return string Normalized environment: 'live' or 'sandbox'
     */
    public static function normalizeEnvironment(?string $env): string
    {
        if (empty($env)) {
            return 'sandbox';
        }
        $lower = strtolower(trim((string)$env));
        if ($lower === 'live' || str_starts_with($lower, 'live') || str_contains($lower, 'prod')) {
            return 'live';
        }
        return 'sandbox';
    }

    /**
     * Resolve, decrypt, and self-heal gateway credentials
     *
     * @param array $rawConfig Raw key/value pairs from database or WHMCS
     * @return array Fully decrypted and normalized configuration
     */
    public static function resolveCredentials(array $rawConfig): array
    {
        $config = $rawConfig;

        // Decrypt any WHMCS-encrypted password fields (only if not already plain text)
        if (function_exists('decrypt')) {
            foreach ($config as $k => $v) {
                if (is_string($v) && !empty($v)) {
                    $isAlreadyPlaintext = str_starts_with($v, 'pdl_') || str_starts_with($v, 'test_') || str_starts_with($v, 'live_');
                    if (!$isAlreadyPlaintext) {
                        try {
                            $dec = decrypt($v);
                            if (!empty($dec) && is_string($dec) && $dec !== $v) {
                                $config[$k] = $dec;
                            }
                        } catch (\Throwable) {
                            // Keep original value
                        }
                    }
                }
            }
        }

        // Normalize active environment
        $config['environment'] = self::normalizeEnvironment($config['environment'] ?? 'sandbox');

        // Self-heal: If an API key was placed or shifted into sellerId, auto-detect & heal
        $sandKey = trim((string)($config['sandboxApiKey'] ?? ''));
        $sandSeller = trim((string)($config['sandboxSellerId'] ?? ''));
        $isSandKeyValid = str_starts_with($sandKey, 'pdl_sand_') || str_starts_with($sandKey, 'pdl_sdbx_');
        $isSandSellerAKey = str_starts_with($sandSeller, 'pdl_sand_') || str_starts_with($sandSeller, 'pdl_sdbx_');
        if (!$isSandKeyValid && $isSandSellerAKey) {
            $config['sandboxApiKey'] = $sandSeller;
            $config['sandboxSellerId'] = '';
        }

        $liveKey = trim((string)($config['liveApiKey'] ?? ''));
        $liveSeller = trim((string)($config['liveSellerId'] ?? ''));
        if (!str_starts_with($liveKey, 'pdl_live_') && str_starts_with($liveSeller, 'pdl_live_')) {
            $config['liveApiKey'] = $liveSeller;
            $config['liveSellerId'] = '';
        }

        // Self-heal: If an API Key and Webhook Secret were swapped in Sandbox
        $sandSec = trim((string)($config['sandboxWebhookSecret'] ?? ''));
        $sandKey = trim((string)($config['sandboxApiKey'] ?? ''));
        $isSandSecAKey = str_starts_with($sandSec, 'pdl_sand_') || str_starts_with($sandSec, 'pdl_sdbx_');
        $isSandKeyASec = str_starts_with($sandKey, 'pdl_ntfset_');
        if ($isSandSecAKey && $isSandKeyASec) {
            $config['sandboxApiKey'] = $sandSec;
            $config['sandboxWebhookSecret'] = $sandKey;
        }

        // Self-heal: If an API Key and Webhook Secret were swapped in Live
        $liveSec = trim((string)($config['liveWebhookSecret'] ?? ''));
        $liveKey = trim((string)($config['liveApiKey'] ?? ''));
        $isLiveSecAKey = str_starts_with($liveSec, 'pdl_live_');
        $isLiveKeyASec = str_starts_with($liveKey, 'pdl_ntfset_');
        if ($isLiveSecAKey && $isLiveKeyASec) {
            $config['liveApiKey'] = $liveSec;
            $config['liveWebhookSecret'] = $liveKey;
        }

        // Clean up seller IDs: Numeric only, never raw encrypted ciphertexts or API keys
        if (!empty($config['sandboxSellerId']) && (!is_numeric($config['sandboxSellerId']) || strlen($config['sandboxSellerId']) > 20)) {
            $config['sandboxSellerId'] = '';
        }
        if (!empty($config['liveSellerId']) && (!is_numeric($config['liveSellerId']) || strlen($config['liveSellerId']) > 20)) {
            $config['liveSellerId'] = '';
        }

        return $config;
    }

    /**
     * Safely retrieve gateway configuration parameters from tblpaymentgateways
     * without triggering WHMCS's fatal "Gateway Module Not Activated" error.
     * Decrypts all encrypted password fields and normalizes settings.
     *
     * @param string $gateway Gateway module name (default 'paddle')
     * @return array Associative array of setting => value
     */
    public static function getGatewayConfig(string $gateway = 'paddle'): array
    {
        // 1. If gateway is activated, WHMCS's native getGatewayVariables safely loads and decrypts
        if (function_exists('getGatewayVariables') && self::isGatewayActive($gateway)) {
            try {
                $vars = getGatewayVariables($gateway);
                if (!empty($vars) && is_array($vars)) {
                    return self::resolveCredentials($vars);
                }
            } catch (\Throwable) {
                // Fall through to direct DB read
            }
        }

        // 2. Direct database query via Capsule with decryption fallback
        $config = [];
        if (class_exists('\WHMCS\Database\Capsule')) {
            try {
                $rows = \WHMCS\Database\Capsule::table('tblpaymentgateways')
                    ->where('gateway', $gateway)
                    ->get();

                foreach ($rows as $row) {
                    $config[$row->setting] = (string)$row->value;
                }
            } catch (\Throwable) {
                // Table may not exist during early install
            }
        }

        return self::resolveCredentials($config);
    }

    /**
     * Check if the gateway is currently marked active in WHMCS
     *
     * @param string $gateway Gateway module name (default 'paddle')
     * @return bool True if active
     */
    public static function isGatewayActive(string $gateway = 'paddle'): bool
    {
        if (class_exists('\WHMCS\Database\Capsule')) {
            try {
                $rows = \WHMCS\Database\Capsule::table('tblpaymentgateways')
                    ->where('gateway', $gateway)
                    ->get();

                if ($rows->isEmpty()) {
                    return false;
                }

                // In WHMCS, deactivating a gateway sets setting 'type' => 'InActive'
                foreach ($rows as $row) {
                    if ($row->setting === 'type' && strtolower((string)$row->value) === 'inactive') {
                        return false;
                    }
                }

                return true;
            } catch (\Throwable) {
                return false;
            }
        }
        return false;
    }
}

