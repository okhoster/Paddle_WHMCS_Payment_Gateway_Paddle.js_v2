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
 * Customer and Address Management Service
 */
final class CustomerService
{
    private ApiClient $apiClient;

    public function __construct(ApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    /**
     * Get or create a Paddle customer and billing address for a given WHMCS client
     *
     * @param int $clientId WHMCS Client ID
     * @param array $clientDetails WHMCS Client Details array
     * @return array ['customer_id' => string, 'address_id' => ?string]
     */
    public function getOrCreateCustomer(int $clientId, array $clientDetails): array
    {
        Database::initSchema();
        $env = $this->apiClient->getEnvironment();

        // 1. Check local mapping table
        $mapping = Capsule::table(Database::TABLE_CUSTOMERS)
            ->where('client_id', $clientId)
            ->where('environment', $env)
            ->first();

        $customerId = null;
        $addressId = null;

        if ($mapping && !empty($mapping->paddle_customer_id)) {
            $customerId = $mapping->paddle_customer_id;
            $addressId = $mapping->paddle_address_id;
        }

        $email = trim((string)($clientDetails['email'] ?? ''));
        $firstName = trim((string)($clientDetails['firstname'] ?? ''));
        $lastName = trim((string)($clientDetails['lastname'] ?? ''));
        $fullName = trim($firstName . ' ' . $lastName);
        if (empty($fullName)) {
            $fullName = 'WHMCS Client #' . $clientId;
        }

        // 2. If no local customer ID, search Paddle by email
        if (empty($customerId) && !empty($email)) {
            try {
                $existing = $this->apiClient->findCustomerByEmail($email);
                if (!empty($existing['id'])) {
                    $customerId = $existing['id'];
                }
            } catch (\Throwable) {
                // If search fails, proceed to create
            }
        }

        // 3. If still no customer ID, create customer in Paddle
        if (empty($customerId)) {
            $createPayload = [
                'email' => $email,
                'name' => $fullName,
                'custom_data' => [
                    'whmcs_client_id' => (string)$clientId,
                ],
            ];

            $customerData = $this->apiClient->createCustomer($createPayload);
            $customerId = $customerData['id'] ?? null;

            if (empty($customerId)) {
                throw new \RuntimeException('Failed to obtain Paddle Customer ID after customer creation.');
            }
        }

        // 4. Create or update customer address if missing
        $countryCode = strtoupper(trim((string)($clientDetails['country'] ?? '')));
        // Ensure 2-character country code
        if (strlen($countryCode) === 2 && empty($addressId)) {
            try {
                $addressPayload = [
                    'country_code' => $countryCode,
                    'postal_code' => trim((string)($clientDetails['postcode'] ?? '')),
                    'city' => trim((string)($clientDetails['city'] ?? '')),
                    'first_line' => trim((string)($clientDetails['address1'] ?? '')),
                    'region' => trim((string)($clientDetails['state'] ?? '')),
                ];

                // Remove empty fields to allow Paddle validation
                $addressPayload = array_filter($addressPayload, fn($v) => !empty($v));
                if (!empty($addressPayload['country_code'])) {
                    $addressData = $this->apiClient->createCustomerAddress($customerId, $addressPayload);
                    $addressId = $addressData['id'] ?? null;
                }
            } catch (\Throwable $e) {
                // Address failure is non-fatal for customer creation
                Logger::logTransaction('Address creation non-fatal notice', ['error' => $e->getMessage()], 'Notice');
            }
        }

        // 5. Upsert mapping record in database
        Capsule::table(Database::TABLE_CUSTOMERS)->updateOrInsert(
            [
                'client_id' => $clientId,
                'environment' => $env,
            ],
            [
                'paddle_customer_id' => $customerId,
                'paddle_address_id' => $addressId,
                'updated_at' => Capsule::raw('CURRENT_TIMESTAMP'),
            ]
        );

        return [
            'customer_id' => $customerId,
            'address_id' => $addressId,
        ];
    }
}
