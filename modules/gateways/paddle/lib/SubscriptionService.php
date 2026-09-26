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
 * Subscription Lifecycle, Synchronization, and Upgrade/Downgrade Management
 */
final class SubscriptionService
{
    private ApiClient $apiClient;

    public function __construct(ApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    /**
     * Record or update subscription details from a Paddle webhook or API object
     *
     * @param array $subscriptionData Paddle subscription object
     * @param int|null $serviceId WHMCS Service ID if known
     * @param int|null $invoiceId WHMCS Invoice ID if known
     * @return int Local record ID
     */
    public function syncSubscription(array $subscriptionData, ?int $serviceId = null, ?int $invoiceId = null): int
    {
        Database::initSchema();
        $subId = $subscriptionData['id'] ?? null;
        if (empty($subId)) {
            throw new \InvalidArgumentException('Missing subscription ID in subscription payload');
        }

        $env = $this->apiClient->getEnvironment();
        $customerId = $subscriptionData['customer_id'] ?? '';
        $status = strtolower((string)($subscriptionData['status'] ?? 'active'));
        $currency = $subscriptionData['currency_code'] ?? 'USD';

        $priceId = null;
        $billingInterval = null;
        if (!empty($subscriptionData['items'][0]['price'])) {
            $priceObj = $subscriptionData['items'][0]['price'];
            $priceId = $priceObj['id'] ?? null;
            if (!empty($priceObj['billing_cycle']['interval'])) {
                $billingInterval = $priceObj['billing_cycle']['frequency'] . ' ' . $priceObj['billing_cycle']['interval'];
            }
        }

        $nextBilledAt = null;
        if (!empty($subscriptionData['next_billed_at'])) {
            $nextBilledAt = date('Y-m-d H:i:s', strtotime((string)$subscriptionData['next_billed_at']));
        }

        // If serviceId not passed, attempt extraction from custom_data or existing record
        if (!$serviceId && !empty($subscriptionData['custom_data']['whmcs_service_id'])) {
            $serviceId = (int)$subscriptionData['custom_data']['whmcs_service_id'];
        }

        if (!$serviceId) {
            $existing = Capsule::table(Database::TABLE_SUBSCRIPTIONS)
                ->where('paddle_subscription_id', $subId)
                ->first();
            if ($existing) {
                $serviceId = $existing->service_id;
                $invoiceId = $invoiceId ?: $existing->invoice_id;
            }
        }

        // Resolve WHMCS client ID
        $clientId = 0;
        if ($serviceId) {
            $hosting = Capsule::table('tblhosting')->where('id', $serviceId)->first();
            if ($hosting) {
                $clientId = (int)$hosting->userid;
                // Update tblhosting.subscriptionid if not set
                if (empty($hosting->subscriptionid) || $hosting->subscriptionid !== $subId) {
                    Capsule::table('tblhosting')->where('id', $serviceId)->update(['subscriptionid' => $subId]);
                }
            }
        }

        if (!$clientId && !empty($customerId)) {
            $custMap = Capsule::table(Database::TABLE_CUSTOMERS)
                ->where('paddle_customer_id', $customerId)
                ->first();
            if ($custMap) {
                $clientId = (int)$custMap->client_id;
            }
        }

        $upsertData = [
            'service_id' => $serviceId ?: 0,
            'client_id' => $clientId,
            'invoice_id' => $invoiceId,
            'paddle_subscription_id' => $subId,
            'paddle_customer_id' => $customerId,
            'paddle_price_id' => $priceId,
            'status' => $status,
            'billing_interval' => $billingInterval,
            'currency_code' => $currency,
            'environment' => $env,
            'next_billed_at' => $nextBilledAt,
            'updated_at' => Capsule::raw('CURRENT_TIMESTAMP'),
        ];

        $existingSub = Capsule::table(Database::TABLE_SUBSCRIPTIONS)
            ->where('paddle_subscription_id', $subId)
            ->first();

        if ($existingSub) {
            Capsule::table(Database::TABLE_SUBSCRIPTIONS)
                ->where('paddle_subscription_id', $subId)
                ->update($upsertData);
            return (int)$existingSub->id;
        }

        $upsertData['created_at'] = Capsule::raw('CURRENT_TIMESTAMP');
        return (int)Capsule::table(Database::TABLE_SUBSCRIPTIONS)->insertGetId($upsertData);
    }

    /**
     * Cancel a Paddle subscription safely
     *
     * @param string $subscriptionId Paddle Subscription ID
     * @param string $effectiveFrom 'next_billing_period' or 'immediately'
     * @return array Cancellation API response
     * @throws \RuntimeException If cancellation fails
     */
    public function cancelSubscription(string $subscriptionId, string $effectiveFrom = 'next_billing_period'): array
    {
        $subscriptionId = trim($subscriptionId);
        if (empty($subscriptionId)) {
            throw new \InvalidArgumentException('No Subscription ID provided for cancellation');
        }

        // Fetch current subscription status to prevent canceling an already canceled subscription
        try {
            $current = $this->apiClient->getSubscription($subscriptionId);
            $currentStatus = strtolower((string)($current['status'] ?? ''));

            if ($currentStatus === 'canceled') {
                Logger::logTransaction(
                    'Cancel Subscription',
                    ['subscription_id' => $subscriptionId, 'notice' => 'Subscription already canceled on Paddle'],
                    'Success'
                );
                return ['status' => 'already_canceled', 'data' => $current];
            }
        } catch (\Throwable $e) {
            Logger::logTransaction(
                'Cancel Subscription Lookup Warning',
                ['subscription_id' => $subscriptionId, 'error' => $e->getMessage()],
                'Warning'
            );
        }

        $validModes = ['next_billing_period', 'immediately'];
        if (!in_array($effectiveFrom, $validModes, true)) {
            $effectiveFrom = 'next_billing_period';
        }

        $res = $this->apiClient->cancelSubscription($subscriptionId, $effectiveFrom);

        // Update local database record
        Capsule::table(Database::TABLE_SUBSCRIPTIONS)
            ->where('paddle_subscription_id', $subscriptionId)
            ->update([
                'status' => 'canceled',
                'updated_at' => Capsule::raw('CURRENT_TIMESTAMP'),
            ]);

        return $res;
    }

    /**
     * Upgrade or downgrade a Paddle subscription to a new Price ID
     *
     * @param string $subscriptionId Paddle Subscription ID
     * @param string $newPriceId New Paddle Price ID
     * @param string $prorationMode Paddle proration mode
     * @param int $quantity Item quantity (default 1)
     * @return array Resulting subscription data
     */
    public function updateSubscriptionPrice(
        string $subscriptionId,
        string $newPriceId,
        string $prorationMode = 'prorated_immediately',
        int $quantity = 1
    ): array {
        $allowedProrationModes = [
            'prorated_immediately',
            'prorated_next_billing_period',
            'full_immediately',
            'full_next_billing_period',
            'do_not_bill',
        ];

        if (!in_array($prorationMode, $allowedProrationModes, true)) {
            $prorationMode = 'prorated_immediately';
        }

        // Paddle requires the complete desired list of items
        $payload = [
            'items' => [
                [
                    'price_id' => $newPriceId,
                    'quantity' => max(1, $quantity),
                ],
            ],
            'proration_billing_mode' => $prorationMode,
        ];

        $updatedSub = $this->apiClient->updateSubscription($subscriptionId, $payload);
        $this->syncSubscription($updatedSub);

        return $updatedSub;
    }

    /**
     * Generate a Customer Portal session URL for a client's subscription
     *
     * @param int $serviceId WHMCS Service ID
     * @param string|null $subscriptionId Optional Paddle Subscription ID
     * @param string|null $customerId Optional Paddle Customer ID
     * @return string|null Customer Portal URL
     */
    public function getCustomerPortalUrl(int $serviceId, ?string $subscriptionId = null, ?string $customerId = null): ?string
    {
        Database::initSchema();

        // 1. If customerId is not directly provided, resolve it from subscription records or tblhosting
        if (empty($customerId)) {
            $sub = null;
            if ($serviceId > 0) {
                $sub = Capsule::table(Database::TABLE_SUBSCRIPTIONS)
                    ->where('service_id', $serviceId)
                    ->where('status', '!=', 'canceled')
                    ->first();
            }

            if (!$sub && !empty($subscriptionId)) {
                $sub = Capsule::table(Database::TABLE_SUBSCRIPTIONS)
                    ->where('paddle_subscription_id', $subscriptionId)
                    ->where('status', '!=', 'canceled')
                    ->first();
            }

            if (!$sub && $serviceId > 0) {
                $hostingSubId = Capsule::table('tblhosting')
                    ->where('id', $serviceId)
                    ->value('subscriptionid');
                if (!empty($hostingSubId)) {
                    $sub = Capsule::table(Database::TABLE_SUBSCRIPTIONS)
                        ->where('paddle_subscription_id', $hostingSubId)
                        ->where('status', '!=', 'canceled')
                        ->first();
                    if (!$subscriptionId) {
                        $subscriptionId = $hostingSubId;
                    }
                }
            }

            if ($sub && !empty($sub->paddle_customer_id)) {
                $customerId = $sub->paddle_customer_id;
                if (empty($subscriptionId)) {
                    $subscriptionId = $sub->paddle_subscription_id;
                }
            }
        }

        // 2. Fallback: Lookup customer ID from mod_paddle_customers via WHMCS client ID
        if (empty($customerId) && $serviceId > 0) {
            $clientId = (int)Capsule::table('tblhosting')->where('id', $serviceId)->value('userid');
            if ($clientId > 0) {
                $env = $this->apiClient->getEnvironment();
                $cust = Capsule::table(Database::TABLE_CUSTOMERS)
                    ->where('client_id', $clientId)
                    ->where('environment', $env)
                    ->first();
                if ($cust && !empty($cust->paddle_customer_id)) {
                    $customerId = $cust->paddle_customer_id;
                } else {
                    // Try to find or register customer on Paddle by email
                    try {
                        $client = Capsule::table('tblclients')->where('id', $clientId)->first();
                        if ($client && !empty($client->email)) {
                            $existing = $this->apiClient->findCustomerByEmail($client->email);
                            if (!empty($existing['id'])) {
                                $customerId = $existing['id'];
                            } else {
                                $created = $this->apiClient->createCustomer([
                                    'email' => $client->email,
                                    'name' => trim(($client->firstname ?? '') . ' ' . ($client->lastname ?? '')) ?: 'WHMCS Client #' . $clientId,
                                    'custom_data' => ['whmcs_client_id' => (string)$clientId],
                                ]);
                                $customerId = $created['id'] ?? null;
                            }

                            if (!empty($customerId)) {
                                Capsule::table(Database::TABLE_CUSTOMERS)->updateOrInsert(
                                    ['client_id' => $clientId, 'environment' => $env],
                                    ['paddle_customer_id' => $customerId, 'updated_at' => Capsule::raw('CURRENT_TIMESTAMP')]
                                );
                            }
                        }
                    } catch (\Throwable $e) {
                        Logger::logTransaction('Customer Resolution for Portal Notice', ['error' => $e->getMessage()], 'Notice');
                    }
                }
            }
        }

        if (empty($customerId)) {
            return null;
        }

        try {
            $subIds = !empty($subscriptionId) ? [$subscriptionId] : [];
            $session = null;

            // Attempt session creation with specific subscription deep-link
            if (!empty($subIds)) {
                try {
                    $session = $this->apiClient->createCustomerPortalSession($customerId, $subIds);
                } catch (\Throwable $e) {
                    // Deep-link subscription failed (e.g. invalid or legacy sub ID), retry general portal overview
                    $session = null;
                }
            }

            // Fallback: general account portal session with no subscription filter
            if (empty($session)) {
                $session = $this->apiClient->createCustomerPortalSession($customerId, []);
            }

            // Paddle Customer Portal URL is provided in data.urls.general.overview or specific subscription urls
            if (!empty($session['urls']['general']['overview'])) {
                return $session['urls']['general']['overview'];
            }
            if (!empty($session['urls']['subscriptions'][0]['cancel_subscription'])) {
                return $session['urls']['subscriptions'][0]['cancel_subscription'];
            }

            return null;
        } catch (\Throwable $e) {
            Logger::logTransaction('Customer Portal Session Creation Failed', ['customer_id' => $customerId, 'error' => $e->getMessage()], 'Error');
            return null;
        }
    }
}
