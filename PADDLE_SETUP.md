# Paddle Billing Dashboard Setup Guide

This guide explains how to configure your **Paddle Billing Dashboard** (both Sandbox and Live) to integrate seamlessly with the WHMCS Payment Gateway module.

---

## 1. Environments Overview

Paddle provides two completely isolated environments:

| Environment | Dashboard URL | API Base URL | Key Prefix |
| :--- | :--- | :--- | :--- |
| **Sandbox (Test Mode)** | `https://sandbox-vendors.paddle.com` | `https://sandbox-api.paddle.com` | `pdl_sand_` |
| **Live (Production)** | `https://vendors.paddle.com` | `https://api.paddle.com` | `pdl_live_` |

> [!CAUTION]
> Sandbox and Live credentials are completely independent. Never attempt to use Sandbox credentials on Live or vice versa. The WHMCS module will strictly reject mixed environments to protect against test transactions in production.

---

## 2. Locating Your Paddle Seller ID (Vendor ID)

1. Log into your **Paddle Dashboard** ([Sandbox](https://sandbox-vendors.paddle.com) or [Live](https://vendors.paddle.com)).
2. Go to **Developer Tools > Authentication**.
3. At the top of the page, locate your **Seller ID** (or Vendor ID), e.g. `12345`.
4. Copy this numeric ID into your WHMCS gateway settings under **Sandbox Seller ID** or **Live Seller ID**.
   *(Note: While Paddle Billing REST API authenticates using your API Key, setting the Seller ID provides account validation, ties checkout sessions in Paddle.js, and appears in diagnostic logs).*

---

## 3. Generating Server-Side API Keys

1. Log into your **Paddle Dashboard** (Sandbox or Live).
2. Go to **Developer Tools > Authentication > API Keys**.
3. Click **New API key**.
4. Set a name for the key: `WHMCS Gateway Integration`.
5. Select the required permissions (following least-privilege principles):
   - **Transactions**: Read & Write
   - **Subscriptions**: Read & Write
   - **Customers**: Read & Write
   - **Prices**: Read
   - **Products**: Read
   - **Adjustments**: Read & Write (if refunds from WHMCS are required)
   - **Customer Portal**: Write (if self-service portal is enabled)
6. Click **Generate key**.
7. Copy the generated key immediately and paste it into WHMCS (**Sandbox API Key** or **Live API Key**).

---

## 3. Generating Client-Side Tokens (for Paddle.js)

Paddle.js v2 requires a public client-side token to securely initialize the checkout overlay:

1. In your Paddle Dashboard, go to **Developer Tools > Authentication > Client-side tokens**.
2. Click **Generate client-side token**.
3. Set a descriptive name: `WHMCS Checkout`.
4. Click **Generate token**.
   - Sandbox tokens start with `test_...`
   - Live tokens start with `live_...`
5. Copy this token into your WHMCS gateway settings (**Sandbox Client-Side Token** or **Live Client-Side Token**).

---

## 4. Setting up Products & Prices (Catalog)

For recurring products (e.g. web hosting, VPS, recurring licenses), create catalog items in Paddle:

1. Navigate to **Catalog > Products**.
2. Click **New Product**.
   - **Name**: e.g., `Standard Web Hosting`
   - **Tax Category**: Select the applicable tax category (e.g., `Standard`).
3. Save the product and note the **Product ID** (e.g. `pro_01h...`).
4. On the product details page, scroll down to **Prices** and click **New Price**:
   - **Description**: `Monthly Billing`
   - **Currency**: `USD`
   - **Unit Price**: e.g. `$10.00`
   - **Billing Type**: `Recurring`
   - **Billing Cycle**: `Every 1 month`
5. Click **Save Price** and copy the **Price ID** (e.g. `pri_01h...`).
6. Repeat for annual, quarterly, or other intervals as needed.

### Setting up a Fallback One-Time Product
For arbitrary one-time WHMCS invoices (domain registrations, custom work, late fees):
1. In Paddle, create a generic product named `WHMCS Custom Services`.
2. Copy its **Product ID** (starts with `pro_...`).
3. Paste this into the **One-Time Product ID** field in your WHMCS gateway settings. The module will dynamically attach custom line items, descriptions, and calculated USD amounts to this product for one-time transactions.

---

## 5. Setting up Webhook Notification Destinations

Webhooks are required for WHMCS to confirm payments and synchronize subscription status.

1. Go to **Developer Tools > Notifications > Notification settings**.
2. Click **New destination**.
3. Fill in:
   - **Destination type**: `Webhook`
   - **URL**: Your WHMCS callback URL (e.g. `https://your-domain.com/modules/gateways/callback/paddle.php`)
   - **Description**: `WHMCS Production Webhook`
4. Select all recommended event types:
   - `transaction.paid`
   - `transaction.completed`
   - `transaction.payment_failed`
   - `transaction.canceled`
   - `subscription.created`
   - `subscription.updated`
   - `subscription.activated`
   - `subscription.trialing`
   - `subscription.paused`
   - `subscription.resumed`
   - `subscription.canceled`
   - `adjustment.created`
   - `adjustment.updated`
   - `customer.created`
   - `customer.updated`
5. Click **Save destination**.
6. Paddle will display the **Secret key** (starts with `pdl_ntfset_...`).
7. Copy this secret key into WHMCS (**Sandbox Webhook Secret** or **Live Webhook Secret**).

---

## 6. Website / Domain Approval

To authorize Paddle.js overlay checkouts from your domain:

1. In the Paddle Dashboard, navigate to **Developer Tools > Authentication > Website approval**.
2. Click **Add domain**.
3. Enter your WHMCS domain (e.g., `billing.yourdomain.com`).
4. Follow the prompt to complete domain authorization.

---

## 7. Production Deployment Checklist

Before going live:
- [ ] Switched WHMCS Gateway Environment setting to `Live (Production)`.
- [ ] Populated Live API Key (`pdl_live_...`).
- [ ] Populated Live Client-Side Token (`live_...`).
- [ ] Configured Live Notification Destination and populated Live Webhook Secret (`pdl_ntfset_...`).
- [ ] Approved production domain in Paddle Dashboard.
- [ ] Executed **Run Connection Test** in WHMCS gateway diagnostics to verify live connectivity.
- [ ] Verified that USD is enabled in WHMCS with up-to-date conversion rates.
