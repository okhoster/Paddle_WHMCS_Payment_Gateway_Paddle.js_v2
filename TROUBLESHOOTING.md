# Paddle Billing Gateway Troubleshooting Guide

This guide covers common integration issues, diagnostic procedures, and resolutions for the WHMCS Paddle Billing Payment Gateway.

---

## 1. Webhook Signature Verification Failures (HTTP 401)

### Symptoms
In the Paddle Dashboard (**Developer Tools > Notifications > Notification history**), webhook attempts to your callback URL return `HTTP 401 Unauthorized` with the error `Invalid webhook signature`.

### Causes & Solutions

1. **Secret Key Mismatch**:
   - Ensure the secret key in your WHMCS gateway settings matches the specific Notification Destination in Paddle.
   - Note: The secret key begins with `pdl_ntfset_...`. Do not use your API Key (`pdl_live_...`) as the webhook secret!
2. **Server Time Drift (NTP Desynchronization)**:
   - Webhook signatures enforce a 300-second (5 minute) replay window.
   - If your server's clock is inaccurate by more than 5 minutes, signatures are rejected.
   - Solution: Synchronize your server clock using NTP:
     ```bash
     sudo chronyc makestep   # On Linux/systemd
     # Or check Windows Time Service if on Windows Server
     ```
3. **Reverse Proxy / Cloudflare Modifying Body**:
   - If Cloudflare or a Web Application Firewall (WAF) alters whitespace, buffers, or rewrites the raw POST body, the HMAC hash will not match.
   - Ensure `modules/gateways/callback/paddle.php` is bypassed by any payload optimization or transformation rules.

---

## 2. "Unable to Initialize Payment with Paddle"

### Symptoms
When a customer clicks "Pay Now with Paddle" on their invoice, an error banner appears stating: *"Unable to initialize payment with Paddle. Please try again or contact support."*

### Diagnostic Steps
1. In WHMCS Admin, go to **Billing > Gateway Log**.
2. Filter by gateway `paddle` and locate the latest error entry.

### Causes & Solutions

1. **Environment Mismatch**:
   - Error: `A Sandbox Paddle API Key cannot be used in Live environment.`
   - Ensure you did not paste a `pdl_sand_...` key into the Live credentials or vice versa.
2. **USD Currency Missing in WHMCS**:
   - Error: `Paddle Billing requires USD currency to be enabled in WHMCS.`
   - In WHMCS Admin, navigate to **System Settings > Payments > Currencies**. Ensure a currency with code `USD` exists and has an exchange rate defined.
3. **Firewall / Port 443 Blocked**:
   - Test connectivity from the Gateway Diagnostics panel (**Configuration > System Settings > Payment Gateways > Paddle Billing** -> click **Run Connection Test**).
   - If connection times out, verify your hosting firewall allows outgoing HTTPS requests to `api.paddle.com` and `sandbox-api.paddle.com`.
4. **Invalid or Missing Client-Side Token**:
   - Ensure the Client-Side Token field is populated. Sandbox tokens start with `test_` and Live tokens start with `live_`.

---

## 3. Checkout Modal Fails to Open or Closes Immediately

### Symptoms
Clicking the payment button does nothing, or the Paddle overlay briefly appears and closes with a domain warning.

### Causes & Solutions

1. **Domain Not Approved in Paddle Dashboard**:
   - Paddle.js requires all hostnames initiating overlay checkouts to be explicitly authorized.
   - Go to **Developer Tools > Authentication > Website approval** in your Paddle Dashboard.
   - Add your WHMCS domain (e.g. `billing.yourdomain.com`).
2. **Ad-Blocker or Script Interference**:
   - Some aggressive browser extensions block third-party payment scripts. Test in an Incognito / Private window with extensions disabled.

---

## 4. Customer Paid on Paddle, But WHMCS Invoice Stays Unpaid

### Symptoms
The customer completed payment on Paddle, but the WHMCS invoice status remains **Unpaid**.

### Causes & Solutions

1. **Webhook Destination Not Configured**:
   - Payments are confirmed exclusively via webhooks, never via the browser return URL.
   - Verify that your Webhook URL (`https://YOUR-DOMAIN.com/modules/gateways/callback/paddle.php`) is active in Paddle under **Developer Tools > Notifications**.
2. **Subscribed Events Missing**:
   - Ensure the Notification Destination is subscribed to `transaction.paid` and `transaction.completed`.
3. **Underpayment Detected (Amount Mismatch)**:
   - If an invoice was partially paid or an exchange rate mismatch occurred, check **Billing > Gateway Log**.
   - The module will log: `Underpayment detected on Invoice #XYZ. Expected USD: X, Paid USD: Y. Payment held for review.`
   - Administrators can review the discrepancy and credit the invoice manually.

---

## 5. Multi-Currency Accounting FAQ

**Question**: *My customer was billed €10.00 on their WHMCS invoice, but Paddle charged them $10.80 USD. Is this correct?*

**Answer**: **Yes, this is completely correct.**
- Paddle Billing processes transactions strictly in USD.
- The module calculates the exact USD equivalent ($10.80) using the exchange rate configured in your WHMCS installation.
- When the payment webhook is received, WHMCS's `addInvoicePayment()` credits the invoice with the full €10.00 EUR balance.
- The customer's invoice balance becomes €0.00 (Paid), and the transaction log preserves both the €10.00 EUR credit and the $10.80 USD Paddle collection for auditing.

---

## 6. Connection Errors & Seller ID FAQ

### Error: "Communication error connecting to Paddle: No URL set"
- **Cause**: An internal cURL option initialization issue previously prevented the target URL from attaching before execution.
- **Resolution**: This has been resolved in the current module update via `curl_setopt_array()`.

### "Is the connection failing because of a missing Paddle Seller ID?"
- **Role of Seller ID**: In **Paddle Billing (v2)**, backend API calls authenticate strictly using the Bearer API Key (`Authorization: Bearer pdl_...`). Paddle's REST API does not require a Seller ID header because the API Key is uniquely tied to your specific seller account.
- **Where to enter Seller ID**: You can now enter your numeric Seller ID under **Sandbox Seller ID** or **Live Seller ID** in the WHMCS Gateway Settings. This links your account in diagnostic logs and passes your account context to Paddle.js v2 checkout sessions.
- **Troubleshooting Connection Failures**:
  1. Ensure you enter the correct **API Key** starting with `pdl_sand_...` for Sandbox or `pdl_live_...` for Live.
  2. In your Paddle Dashboard under **Developer Tools > Authentication > API Keys**, ensure the key has permissions enabled for **Transactions**, **Subscriptions**, and **Customers**.
  3. If testing locally on Windows, the module automatically utilizes the Windows OS native root CA store (`CURLSSLOPT_NATIVE_CA`) to verify Paddle's SSL certificates without requiring manual `curl.cainfo` path configuration.

