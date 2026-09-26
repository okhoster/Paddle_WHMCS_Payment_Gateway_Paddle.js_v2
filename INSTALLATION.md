# Paddle Billing Gateway Installation & Configuration Guide

This guide walks you through installing, configuring, and verifying the Paddle Billing Payment Gateway in WHMCS (versions 8.10.1 and 10.13.0 on PHP 8.1/8.3).

---

## Pre-Installation Checklist

- [ ] WHMCS 8.10.1 or 10.13.0 installed and functioning.
- [ ] PHP 8.1, 8.2, or 8.3 installed with extensions: `curl`, `json`, `openssl`, `mbstring`, `bcmath`, `pdo`.
- [ ] SSL certificate (HTTPS) active on your WHMCS domain.
- [ ] Active Paddle Billing account ([Paddle Sandbox](https://sandbox-vendors.paddle.com) or [Paddle Live](https://vendors.paddle.com)).
- [ ] **USD Currency enabled in WHMCS** under **Setup / Configuration > System Settings > Payments > Currencies**.

---

## Step 1: Upload Module Files

Upload the module folders directly to your WHMCS root directory:

```text
your-whmcs-root/
├── modules/
│   └── gateways/
│       ├── paddle.php
│       ├── callback/
│       │   └── paddle.php
│       └── paddle/
│           ├── lib/
│           └── templates/
└── includes/
    └── hooks/
        └── paddle_gateway_hooks.php
```

> [!NOTE]
> No Composer commands or SSH root access are required. The module is fully standalone and compatible with shared hosting, cPanel, Plesk, Docker, and enterprise cloud hosting.

---

## Step 2: Configure USD Currency in WHMCS

The Paddle Billing platform processes transactions in **USD**.

1. In WHMCS Admin, go to **System Settings > Payments > Currencies** (or **Setup > Payments > Currencies** in 8.10).
2. Check if **USD** is present in the currency list:
   - If USD is your default currency, no changes are needed.
   - If USD is not your default currency, ensure USD is added with code `USD` and a valid base conversion rate (or enable automatic daily exchange rate updates).
3. Any invoice issued to customers in non-USD currencies (EUR, GBP, PKR, INR, CAD, etc.) will automatically be converted to USD at checkout based on these rates, preserving the original invoice amount and currency in WHMCS.

---

## Step 3: Activate the Payment Gateway

1. In WHMCS Admin, navigate to **Configuration > System Settings > Payment Gateways**.
2. Click on the **All Payment Gateways** tab.
3. Locate **Paddle Billing** and click it to activate.
4. You will be redirected to the **Manage Existing Gateways** tab with Paddle Billing selected.

---

## Step 4: Configure Gateway Credentials

In the gateway configuration screen, complete the following fields:

| Configuration Field | Description | Example / Recommended |
| :--- | :--- | :--- |
| **Show on Order Form** | Check to enable on checkout order forms | Checked |
| **Visible Name** | Customer-facing gateway title | `Credit Card / PayPal (via Paddle)` |
| **Environment** | Operating environment | `Sandbox (Test Mode)` for initial testing |
| **Sandbox API Key** | Paddle Sandbox server API key | `pdl_sand_apikey_...` |
| **Sandbox Client-Side Token** | Public token for Paddle.js v2 in Sandbox | `test_...` |
| **Sandbox Webhook Secret** | Secret key from Paddle notification destination | `pdl_ntfset_...` |
| **Live API Key** | Paddle Production server API key | `pdl_live_apikey_...` |
| **Live Client-Side Token** | Public token for Paddle.js v2 in Live | `live_...` |
| **Live Webhook Secret** | Secret key from Live notification destination | `pdl_ntfset_...` |
| **One-Time Product ID** | Fallback Paddle Product ID for one-time invoices | `pro_...` (create one in Paddle catalog) |
| **Checkout Mode** | Display mode for Paddle.js v2 | `Overlay Modal (Recommended)` |
| **Subscription Cancellation** | Terminate Paddle subscription when service terminates | Checked (`Yes`) |
| **Cancellation Timing** | When cancellation takes effect | `End of Billing Period (Recommended)` |
| **Upgrade Proration Mode** | Proration method for package upgrades | `Prorated Immediately` |
| **Enable Customer Portal** | Allow client area self-service portal link | Checked (`Yes`) |
| **Webhook Logging Level** | Detail level for gateway transaction logs | `Normal (Recommended)` |
| **API Logging Level** | Detail level for API requests | `Errors Only (Recommended)` |

Click **Save Changes**. The module will automatically run its database table migrations upon saving.

---

## Step 5: Configure Paddle Notification Destination (Webhook)

1. Note the **Webhook Notification Destination URL** displayed in the diagnostic box at the top of the gateway settings:
   `https://YOUR-DOMAIN.COM/modules/gateways/callback/paddle.php`
2. Log into your [Paddle Dashboard](https://vendors.paddle.com) (or Sandbox Dashboard).
3. Navigate to **Developer Tools > Notifications > Notification settings**.
4. Click **New destination**.
5. Fill in the destination details:
   - **Destination type**: `Webhook`
   - **URL**: Paste the exact URL copied from step 1.
   - **Description**: `WHMCS Billing Gateway`
6. Under **Events to subscribe**, select the recommended event types:
   - **Transactions**:
     - `transaction.paid`
     - `transaction.completed`
     - `transaction.payment_failed`
     - `transaction.canceled`
   - **Subscriptions**:
     - `subscription.created`
     - `subscription.updated`
     - `subscription.activated`
     - `subscription.trialing`
     - `subscription.paused`
     - `subscription.resumed`
     - `subscription.canceled`
   - **Adjustments**:
     - `adjustment.created`
     - `adjustment.updated`
   - **Customers**:
     - `customer.created`
     - `customer.updated`
7. Click **Save destination**.
8. Copy the generated **Secret key** (starts with `pdl_ntfset_...`) and paste it into the **Sandbox Webhook Secret** (or **Live Webhook Secret**) field in your WHMCS gateway settings.
9. Click **Save Changes** in WHMCS.

---

## Step 6: Approve Your Domain in Paddle

Paddle.js requires your website domain to be approved before opening overlay checkouts:

1. In your Paddle Dashboard, go to **Developer Tools > Authentication > Website approval**.
2. Click **Add domain**.
3. Enter your domain (e.g. `billing.yourdomain.com`).
4. Complete the verification as instructed by Paddle.

---

## Step 7: Products & Subscriptions Synchronization

The module offers fully automatic creation and synchronization of Products, Prices, and Subscriptions between WHMCS and Paddle:

### Method A: Fully Automatic On-Demand Creation (Default & Recommended)
- You do **not** need to manually create products or prices in Paddle.
- When **Automatic Product & Price Creation** is enabled (default: `Yes`), whenever a customer places an order or pays an invoice for any WHMCS package, the module dynamically creates:
  1. The matching **Paddle Product** (`pro_...`).
  2. The matching **Paddle Price** (`pri_...`) configured with the exact recurrence interval (Monthly, Quarterly, Annually, etc.).
- When the customer completes checkout, Paddle automatically creates the active **Subscription** (`sub_...`), which WHMCS stores in `mod_paddle_subscriptions` and `tblhosting.subscriptionid`.

### Method B: One-Click Bulk Synchronization
- If you prefer to populate your entire Paddle Catalog in advance:
  1. In WHMCS Admin, open the **Paddle Billing** gateway settings.
  2. In the Diagnostics panel, locate the **Auto-Sync WHMCS Products** card.
  3. Click **Sync All Products to Paddle**.
  4. The gateway will scan all active WHMCS packages and prices in `tblpricing` and create them automatically in Paddle.

### Method C: Manual Custom Fields Override (Optional)
- If you have pre-existing Paddle Price IDs you wish to explicitly map:
  1. Go to **System Settings > Products/Services**.
  2. Add an Admin-Only Custom Field: `paddle_price_monthly`, `paddle_price_annually`, or `paddle_price_id`.
  3. Enter your pre-existing Price ID (`pri_...`).


---

## Step 8: Test & Verify

1. In the WHMCS gateway settings, click **Run Connection Test** under the Diagnostics panel.
2. Ensure you receive the green success notice:
   `Successfully connected to Paddle Billing sandbox API!`
3. Generate a test invoice in WHMCS.
4. Open the invoice in the client area and click **Pay Now with Paddle**.
5. Use Paddle's [test payment details](https://developer.paddle.com/build/checkout/build-overlay-checkout#test-cards) to complete checkout.
6. Verify that:
   - The invoice displays the "Payment Received! We are verifying..." notice.
   - The webhook callback receives `transaction.paid`.
   - The WHMCS invoice is marked **Paid** and payment is logged under **Billing > Gateway Log**.

---

## Rollback Instructions

If you need to temporarily roll back or disable the gateway:
1. In WHMCS Admin, go to **System Settings > Payment Gateways > Manage Existing Gateways**.
2. Uncheck **Show on Order Form** for Paddle Billing, or click **Deactivate**.
3. Deactivating the gateway will not remove existing invoice payments or client records.
