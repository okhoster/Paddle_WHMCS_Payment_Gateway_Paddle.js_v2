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

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * Currency Conversion and Precision Monetary Arithmetic Service
 */
final class CurrencyService
{
    public const TARGET_CURRENCY = 'USD';

    /**
     * Convert an invoice amount from its native currency to USD using WHMCS rates
     *
     * @param float|string $amount Source invoice amount (e.g. 10.00)
     * @param string|int $sourceCurrency Currency code (e.g. "EUR", "PKR") or currency ID
     * @return array [
     *     'usd_amount' => float (e.g. 10.80),
     *     'usd_cents' => string (e.g. "1080"),
     *     'original_amount' => float (e.g. 10.00),
     *     'original_currency' => string (e.g. "EUR"),
     *     'exchange_rate' => float,
     *     'audit' => array
     * ]
     * @throws \InvalidArgumentException If amount is zero, negative, or invalid
     * @throws \RuntimeException If USD currency is not configured in WHMCS
     */
    public static function convertToUsd(float|string $amount, string|int $sourceCurrency): array
    {
        $floatAmount = (float)$amount;

        if ($floatAmount <= 0.0) {
            throw new \InvalidArgumentException('Invoice payment amount must be greater than zero. Received: ' . $amount);
        }

        // Resolve source currency code and ID
        $sourceCurrencyData = self::resolveCurrency($sourceCurrency);
        $sourceCode = strtoupper($sourceCurrencyData->code);

        // If source currency is already USD, return directly with no conversion drift
        if ($sourceCode === self::TARGET_CURRENCY) {
            $formattedUsd = round($floatAmount, 2);
            $cents = (string)intval(round($formattedUsd * 100));

            return [
                'usd_amount' => $formattedUsd,
                'usd_cents' => $cents,
                'original_amount' => $formattedUsd,
                'original_currency' => self::TARGET_CURRENCY,
                'exchange_rate' => 1.0,
                'audit' => [
                    'source_currency' => self::TARGET_CURRENCY,
                    'target_currency' => self::TARGET_CURRENCY,
                    'conversion_needed' => false,
                    'rate' => 1.0,
                    'raw_amount' => $floatAmount,
                    'converted_usd' => $formattedUsd,
                    'paddle_cents' => $cents,
                ],
            ];
        }

        // Look up USD target currency in WHMCS
        $usdCurrencyData = self::resolveCurrency(self::TARGET_CURRENCY);
        if (!$usdCurrencyData) {
            throw new \RuntimeException(
                'Paddle Billing requires USD currency to be enabled in WHMCS. ' .
                'Please configure USD in WHMCS under Setup/Settings > Payments > Currencies.'
            );
        }

        $calculatedUsd = null;
        $conversionMethod = 'none';

        // Method 1: WHMCS Official Billing\Currency class
        if (class_exists('\WHMCS\Billing\Currency')) {
            try {
                $fromModel = \WHMCS\Billing\Currency::find($sourceCurrencyData->id);
                $toModel = \WHMCS\Billing\Currency::find($usdCurrencyData->id);

                if ($fromModel && $toModel) {
                    if (method_exists('\WHMCS\Billing\Currency', 'convertBetween')) {
                        $calculatedUsd = \WHMCS\Billing\Currency::convertBetween($fromModel, $floatAmount, $toModel);
                        $conversionMethod = '\WHMCS\Billing\Currency::convertBetween';
                    } elseif (method_exists($fromModel, 'convertTo')) {
                        $calculatedUsd = $fromModel->convertTo($floatAmount, $toModel);
                        $conversionMethod = '$fromModel->convertTo';
                    }
                }
            } catch (\Throwable) {
                // Fallback to Method 2 or 3
            }
        }

        // Method 2: WHMCS global helper convertCurrency()
        if ($calculatedUsd === null && function_exists('convertCurrency')) {
            try {
                $calculatedUsd = \convertCurrency($floatAmount, $sourceCurrencyData->id, $usdCurrencyData->id);
                $conversionMethod = 'convertCurrency() helper';
            } catch (\Throwable) {
                // Fallback to Method 3
            }
        }

        // Method 3: Deterministic WHMCS tblcurrencies formula
        if ($calculatedUsd === null) {
            $sourceRate = (float)$sourceCurrencyData->rate;
            $usdRate = (float)$usdCurrencyData->rate;

            if ($sourceRate <= 0.0) {
                throw new \RuntimeException("Invalid conversion rate ({$sourceRate}) for currency {$sourceCode} in WHMCS.");
            }
            if ($usdRate <= 0.0) {
                throw new \RuntimeException("Invalid conversion rate ({$usdRate}) for currency USD in WHMCS.");
            }

            // WHMCS Base Formula: Base Currency Amount = Foreign Amount / Foreign Rate
            // USD Amount = Base Currency Amount * USD Rate = (Foreign Amount / Foreign Rate) * USD Rate
            if (extension_loaded('bcmath')) {
                $amountStr = number_format($floatAmount, 4, '.', '');
                $sourceRateStr = number_format($sourceRate, 6, '.', '');
                $usdRateStr = number_format($usdRate, 6, '.', '');

                $baseAmount = bcdiv($amountStr, $sourceRateStr, 8);
                $usdAmountStr = bcmul($baseAmount, $usdRateStr, 8);
                $calculatedUsd = (float)$usdAmountStr;
            } else {
                $baseAmount = $floatAmount / $sourceRate;
                $calculatedUsd = $baseAmount * $usdRate;
            }
            $conversionMethod = 'WHMCS tblcurrencies base rate formula';
        }

        $roundedUsd = round((float)$calculatedUsd, 2, PHP_ROUND_HALF_UP);
        if ($roundedUsd <= 0.0) {
            throw new \RuntimeException(
                "Converted USD amount ({$roundedUsd}) is zero or negative from {$sourceCode} {$floatAmount}."
            );
        }

        $paddleCents = (string)intval(round($roundedUsd * 100));
        $effectiveRate = ($floatAmount > 0) ? round($roundedUsd / $floatAmount, 6) : 1.0;

        $audit = [
            'method' => $conversionMethod,
            'source_currency' => $sourceCode,
            'source_amount' => $floatAmount,
            'target_currency' => self::TARGET_CURRENCY,
            'effective_rate' => $effectiveRate,
            'converted_usd' => $roundedUsd,
            'paddle_cents' => $paddleCents,
            'timestamp' => date('c'),
        ];

        return [
            'usd_amount' => $roundedUsd,
            'usd_cents' => $paddleCents,
            'original_amount' => round($floatAmount, 2),
            'original_currency' => $sourceCode,
            'exchange_rate' => $effectiveRate,
            'audit' => $audit,
        ];
    }

    /**
     * Convert an amount from USD to a target currency using WHMCS exchange rates
     *
     * @param float|string $usdAmount Amount in USD
     * @param string|int $targetCurrency Target currency code (e.g. "EUR", "PKR") or currency ID
     * @return array [
     *     'target_amount' => float,
     *     'target_currency' => string,
     *     'exchange_rate' => float
     * ]
     * @throws \InvalidArgumentException If amount is negative or invalid
     */
    public static function convertFromUsd(float|string $usdAmount, string|int $targetCurrency): array
    {
        $floatUsd = (float)$usdAmount;
        if ($floatUsd < 0.0) {
            throw new \InvalidArgumentException('USD amount must be non-negative. Received: ' . $usdAmount);
        }

        $targetCurrencyData = self::resolveCurrency($targetCurrency);
        $targetCode = strtoupper($targetCurrencyData->code ?? 'USD');

        if ($targetCode === self::TARGET_CURRENCY || $floatUsd === 0.0) {
            return [
                'target_amount' => round($floatUsd, 2),
                'target_currency' => self::TARGET_CURRENCY,
                'exchange_rate' => 1.0,
            ];
        }

        $usdCurrencyData = self::resolveCurrency(self::TARGET_CURRENCY);
        $calculatedTarget = null;

        // Method 1: WHMCS Official Billing\Currency class
        if (class_exists('\WHMCS\Billing\Currency')) {
            try {
                $fromModel = \WHMCS\Billing\Currency::find($usdCurrencyData->id ?? 1);
                $toModel = \WHMCS\Billing\Currency::find($targetCurrencyData->id ?? 1);

                if ($fromModel && $toModel && method_exists('\WHMCS\Billing\Currency', 'convertBetween')) {
                    $calculatedTarget = \WHMCS\Billing\Currency::convertBetween($fromModel, $floatUsd, $toModel);
                }
            } catch (\Throwable) {
                // Fallback below
            }
        }

        // Method 2: WHMCS global helper convertCurrency()
        if ($calculatedTarget === null && function_exists('convertCurrency') && !empty($usdCurrencyData->id) && !empty($targetCurrencyData->id)) {
            try {
                $calculatedTarget = \convertCurrency($floatUsd, $usdCurrencyData->id, $targetCurrencyData->id);
            } catch (\Throwable) {
                // Fallback below
            }
        }

        // Method 3: Deterministic formula: Target Amount = (USD Amount / USD Rate) * Target Rate
        if ($calculatedTarget === null) {
            $sourceRate = (float)($usdCurrencyData->rate ?? 1.0);
            $targetRate = (float)($targetCurrencyData->rate ?? 1.0);

            if ($sourceRate <= 0.0) {
                $sourceRate = 1.0;
            }
            if ($targetRate <= 0.0) {
                $targetRate = 1.0;
            }

            if (extension_loaded('bcmath')) {
                $usdStr = number_format($floatUsd, 4, '.', '');
                $sourceRateStr = number_format($sourceRate, 6, '.', '');
                $targetRateStr = number_format($targetRate, 6, '.', '');

                $baseAmount = bcdiv($usdStr, $sourceRateStr, 8);
                $targetAmountStr = bcmul($baseAmount, $targetRateStr, 8);
                $calculatedTarget = (float)$targetAmountStr;
            } else {
                $baseAmount = $floatUsd / $sourceRate;
                $calculatedTarget = $baseAmount * $targetRate;
            }
        }

        $rounded = round((float)$calculatedTarget, 2, PHP_ROUND_HALF_UP);
        $effectiveRate = ($floatUsd > 0) ? round($rounded / $floatUsd, 6) : 1.0;

        return [
            'target_amount' => $rounded,
            'target_currency' => $targetCode,
            'exchange_rate' => $effectiveRate,
        ];
    }

    /**
     * Resolve currency record by ID or Code
     *
     * @param string|int $currency Identifier (e.g. 'EUR' or 1)
     * @return object|null Database row with id, code, rate, default
     */
    public static function resolveCurrency(string|int $currency): ?object
    {
        if (!class_exists('\WHMCS\Database\Capsule')) {
            return (object)[
                'id' => 1,
                'code' => is_numeric($currency) ? 'USD' : (string)$currency,
                'rate' => 1.000000,
                'default' => 1,
            ];
        }

        if (is_numeric($currency)) {
            return Capsule::table('tblcurrencies')->where('id', (int)$currency)->first();
        }

        return Capsule::table('tblcurrencies')->where('code', strtoupper((string)$currency))->first();
    }

    /**
     * Convert Paddle integer cents string (e.g. "1080") to standard float (e.g. 10.80)
     *
     * @param string|int $cents Amount in cents
     * @return float Amount in dollars
     */
    public static function centsToDollars(string|int $cents): float
    {
        return round(((int)$cents) / 100, 2);
    }

    /**
     * Convert dollars to integer cents string for Paddle API (e.g. 10.80 -> "1080")
     *
     * @param float|string $dollars Amount in dollars
     * @return string Integer cents string
     */
    public static function dollarsToCents(float|string $dollars): string
    {
        return (string)intval(round(((float)$dollars) * 100));
    }
}
