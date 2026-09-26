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

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

use WHMCS\Module\Gateway\Paddle\Security;
use WHMCS\Module\Gateway\Paddle\Compatibility;

/**
 * Variables expected:
 * @var array $config
 */

$webhookUrl = Compatibility::getWebhookCallbackUrl();
$csrfToken = Security::generateCsrfToken('paddle_admin');
$currentEnv = Compatibility::normalizeEnvironment($config['environment'] ?? 'sandbox');
$isLive = ($currentEnv === 'live');
?>
<div class="paddle-admin-panel" style="margin-top: 15px; margin-bottom: 25px; padding: 20px; background: #ffffff; border: 1px solid #dcdfe6; border-radius: 6px;">
    <h3 style="margin-top: 0; color: #2e384d; border-bottom: 2px solid #eef1f6; padding-bottom: 10px;">
        <i class="fas fa-satellite-dish"></i> Paddle Billing Gateway Diagnostics & Tools
    </h3>

    <!-- Dynamic Webhook Callback URL Box -->
    <div style="background: #f4f6fa; border-left: 4px solid #3b82f6; padding: 12px 16px; margin-bottom: 20px; border-radius: 0 4px 4px 0;">
        <strong><i class="fas fa-link"></i> Webhook Notification Destination URL:</strong>
        <div style="margin-top: 6px; font-family: monospace; font-size: 13px; color: #1e40af; word-break: break-all;">
            <input type="text" readonly value="<?= Security::escapeHtml($webhookUrl) ?>" style="width: 100%; max-width: 650px; padding: 6px 10px; font-family: monospace; background: #fff; border: 1px solid #cbd5e1; border-radius: 4px;" onclick="this.select();" />
        </div>
        <small style="color: #64748b; display: block; margin-top: 6px;">
            Copy this URL into your Paddle Dashboard under <strong>Developer Tools &gt; Notifications &gt; Destinations</strong>.
        </small>
    </div>

    <!-- Approved Checkout Domains Notice -->
    <div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 16px; margin-bottom: 20px; border-radius: 0 4px 4px 0;">
        <strong><i class="fas fa-shield-alt"></i> Checkout Domain Approval (Required by Paddle):</strong>
        <p style="margin: 6px 0 0 0; color: #92400e; font-size: 13px;">
            To use Paddle.js Overlay/Inline checkout, you must add your WHMCS domain (<strong><?= Security::escapeHtml(parse_url($webhookUrl, PHP_URL_HOST)) ?></strong>) to your approved website domains in the Paddle Dashboard under <strong>Developer Tools &gt; Authentication &gt; Website approval</strong>.
        </p>
    </div>

    <div style="display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 20px;">
        <!-- Test API Connection Tool -->
        <div style="flex: 1; min-width: 280px; padding: 15px; border: 1px solid #e2e8f0; border-radius: 6px; background: #fafafa;">
            <h4 style="margin-top: 0; color: #334155; font-size: 15px;">
                <i class="fas fa-plug"></i> Test API Connection
            </h4>
            <p style="font-size: 12px; color: #64748b;">
                Verify server-to-server connectivity and authentication with the configured <strong id="paddle-test-env-name"><?= $isLive ? 'LIVE' : 'SANDBOX' ?></strong> environment.
            </p>
            <button type="button" class="btn btn-default btn-sm" id="btn-paddle-test-connection" style="padding: 6px 14px;">
                <i class="fas fa-sync-alt"></i> Run Connection Test
            </button>
            <div id="paddle-connection-result" style="margin-top: 10px; font-size: 12px; display: none;"></div>
        </div>

        <!-- Validate Paddle Price / Product ID Tool -->
        <div style="flex: 1; min-width: 280px; padding: 15px; border: 1px solid #e2e8f0; border-radius: 6px; background: #fafafa;">
            <h4 style="margin-top: 0; color: #334155; font-size: 15px;">
                <i class="fas fa-tag"></i> Validate Price or Product ID
            </h4>
            <p style="font-size: 12px; color: #64748b;">
                Check if a Price ID (pri_...) or Product ID (pro_...) exists and is active on Paddle.
            </p>
            <div style="display: flex; gap: 6px;">
                <input type="text" id="paddle-price-input" placeholder="pri_01h... or pro_01m..." style="padding: 5px 10px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 4px; flex: 1;" />
                <button type="button" class="btn btn-default btn-sm" id="btn-paddle-validate-price">
                    Validate
                </button>
            </div>
            <div id="paddle-price-result" style="margin-top: 10px; font-size: 12px; display: none;"></div>
        </div>

        <!-- Auto-Sync Catalog Tool -->
        <div style="flex: 1; min-width: 280px; padding: 15px; border: 1px solid #e2e8f0; border-radius: 6px; background: #fafafa;">
            <h4 style="margin-top: 0; color: #334155; font-size: 15px;">
                <i class="fas fa-sync"></i> Auto-Sync WHMCS Products
            </h4>
            <p style="font-size: 12px; color: #64748b;">
                Batch create matching Paddle Products and recurring Prices for all active WHMCS packages.
            </p>
            <button type="button" class="btn btn-default btn-sm" id="btn-paddle-sync-products" style="padding: 6px 14px;">
                <i class="fas fa-cloud-upload-alt"></i> Sync All Products to Paddle
            </button>
            <div id="paddle-sync-result" style="margin-top: 10px; font-size: 12px; display: none;"></div>
        </div>
    </div>


    <!-- Diagnostic Information Table -->
    <table class="form" width="100%" border="0" cellspacing="2" cellpadding="3" style="font-size: 12px;">
        <tr>
            <td class="fieldlabel" width="25%">Active Environment:</td>
            <td class="fieldarea">
                <span id="paddle-active-env-badge" class="label <?= $isLive ? 'label-success' : 'label-warning' ?>" style="font-weight: bold; padding: 3px 8px; border-radius: 3px;">
                    <?= $isLive ? 'LIVE (Production)' : 'SANDBOX (Test Mode)' ?>
                </span>
            </td>
        </tr>
        <tr>
            <td class="fieldlabel">Paddle Seller ID:</td>
            <td class="fieldarea" id="paddle-active-seller-id">
                <?php
                $activeSellerId = $isLive ? ($config['liveSellerId'] ?? '') : ($config['sandboxSellerId'] ?? '');
                if (!empty($activeSellerId) && is_numeric($activeSellerId)): ?>
                    <strong style="color: #1e40af;"><i class="fas fa-id-badge"></i> <?= Security::escapeHtml($activeSellerId) ?></strong>
                <?php else: ?>
                    <span style="color: #64748b;">Not configured (Optional - enter numeric Seller ID in settings below)</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td class="fieldlabel">Module Version:</td>
            <td class="fieldarea">v<?= Security::escapeHtml(Compatibility::MODULE_VERSION) ?></td>
        </tr>
        <tr>
            <td class="fieldlabel">WHMCS / PHP Compatibility:</td>
            <td class="fieldarea">
                WHMCS v<?= Security::escapeHtml(Compatibility::getWhmcsVersion()) ?> | PHP v<?= Security::escapeHtml(Compatibility::getPhpVersion()) ?>
            </td>
        </tr>
    </table>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        const csrfToken = <?= json_encode($csrfToken) ?>;
        const currentUrl = window.location.href;

        // Dynamic environment and seller ID synchronization
        function updateEnvironmentUi() {
            const rawEnv = $('select[name*="environment"]').val() || '';
            const isLive = rawEnv.toLowerCase().indexOf('live') !== -1;
            const envName = isLive ? 'LIVE' : 'SANDBOX';
            const envFull = isLive ? 'LIVE (Production)' : 'SANDBOX (Test Mode)';

            $('#paddle-active-env-badge')
                .removeClass('label-success label-warning')
                .addClass(isLive ? 'label-success' : 'label-warning')
                .text(envFull);

            $('#paddle-test-env-name').text(envName);

            const liveSeller = ($('input[name*="liveSellerId"]').val() || '').trim();
            const sandSeller = ($('input[name*="sandboxSellerId"]').val() || '').trim();
            const seller = isLive ? liveSeller : sandSeller;

            if (seller && /^\d+$/.test(seller)) {
                $('#paddle-active-seller-id').html('<strong style="color: #1e40af;"><i class="fas fa-id-badge"></i> ' + $('<div>').text(seller).html() + '</strong>');
            } else {
                $('#paddle-active-seller-id').html('<span style="color: #64748b;">Not configured (Optional - enter numeric Seller ID in settings below)</span>');
            }
        }

        // Live update as user selects environment or edits Seller ID
        $(document).on('change', 'select[name*="environment"]', updateEnvironmentUi);
        $(document).on('input keyup change', 'input[name*="SellerId"]', updateEnvironmentUi);

        // Run once on load to ensure UI reflects current dropdown selection
        updateEnvironmentUi();

        // Helper to extract current form credentials
        function getLiveCredentials() {
            const rawEnv = $('select[name*="environment"]').val() || '';
            const isLive = rawEnv.toLowerCase().indexOf('live') !== -1;
            const targetEnv = isLive ? 'live' : 'sandbox';

            const typedApiKey = isLive
                ? ($('input[name*="liveApiKey"]').val() || '').trim()
                : ($('input[name*="sandboxApiKey"]').val() || '').trim();
            const typedSellerId = isLive
                ? ($('input[name*="liveSellerId"]').val() || '').trim()
                : ($('input[name*="sandboxSellerId"]').val() || '').trim();

            return {
                environment: targetEnv,
                apiKey: typedApiKey,
                sellerId: typedSellerId
            };
        }

        // Test Connection Handler
        $('#btn-paddle-test-connection').on('click', function(e) {
            e.preventDefault();
            const $btn = $(this);
            const $res = $('#paddle-connection-result');

            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Testing...');
            $res.hide().removeClass('alert alert-success alert-danger');

            const creds = getLiveCredentials();

            $.ajax({
                url: currentUrl,
                type: 'POST',
                data: Object.assign({
                    paddle_admin_action: 'test_connection',
                    paddle_csrf_token: csrfToken
                }, creds),
                dataType: 'json'
            }).done(function(data) {
                if (data && data.status === 'success') {
                    $res.addClass('alert alert-success').html('<i class="fas fa-check-circle"></i> ' + data.message).show();
                } else {
                    $res.addClass('alert alert-danger').html('<i class="fas fa-times-circle"></i> ' + (data ? data.message : 'Unknown error')).show();
                }
            }).fail(function(xhr) {
                $res.addClass('alert alert-danger').html('<i class="fas fa-exclamation-triangle"></i> Request failed (HTTP ' + xhr.status + ')').show();
            }).always(function() {
                $btn.prop('disabled', false).html('<i class="fas fa-sync-alt"></i> Run Connection Test');
            });
        });

        // Validate Price / Product ID Handler
        $('#btn-paddle-validate-price').on('click', function(e) {
            e.preventDefault();
            const $btn = $(this);
            const $res = $('#paddle-price-result');
            const priceId = $('#paddle-price-input').val().trim();

            if (!priceId) {
                alert('Please enter a Price ID (pri_...) or Product ID (pro_...)');
                return;
            }

            $btn.prop('disabled', true).text('Validating...');
            $res.hide().removeClass('alert alert-success alert-danger');

            const creds = getLiveCredentials();

            $.ajax({
                url: currentUrl,
                type: 'POST',
                data: Object.assign({
                    paddle_admin_action: 'validate_price',
                    paddle_csrf_token: csrfToken,
                    price_id: priceId
                }, creds),
                dataType: 'json'
            }).done(function(data) {
                if (data && data.status === 'success') {
                    let html = '<strong><i class="fas fa-check-circle"></i> ' + data.message + '</strong><br>';
                    if (data.details) {
                        if (data.details.amount) html += 'Amount: ' + data.details.amount + ' ' + (data.details.currency || 'USD') + '<br>';
                        if (data.details.billing_type) html += 'Billing: ' + data.details.billing_type + '<br>';
                        if (data.details.tax_category) html += 'Tax Category: ' + data.details.tax_category + '<br>';
                        html += 'Status: ' + data.details.status;
                    }
                    $res.addClass('alert alert-success').html(html).show();
                } else {
                    $res.addClass('alert alert-danger').html('<i class="fas fa-times-circle"></i> ' + (data ? data.message : 'Validation failed')).show();
                }
            }).fail(function(xhr) {
                $res.addClass('alert alert-danger').html('<i class="fas fa-exclamation-triangle"></i> Validation failed (HTTP ' + xhr.status + ')').show();
            }).always(function() {
                $btn.prop('disabled', false).text('Validate');
            });
        });

        // Batch Sync Products Handler
        $('#btn-paddle-sync-products').on('click', function(e) {
            e.preventDefault();
            if (!confirm('This will automatically create matching Products and Prices on Paddle for all active WHMCS products. Proceed?')) {
                return;
            }
            const $btn = $(this);
            const $res = $('#paddle-sync-result');

            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Syncing with Paddle...');
            $res.hide().removeClass('alert alert-success alert-danger');

            const creds = getLiveCredentials();

            $.ajax({
                url: currentUrl,
                type: 'POST',
                data: Object.assign({
                    paddle_admin_action: 'sync_products',
                    paddle_csrf_token: csrfToken
                }, creds),
                dataType: 'json'
            }).done(function(data) {
                if (data && data.status === 'success') {
                    $res.addClass('alert alert-success').html('<i class="fas fa-check-circle"></i> ' + data.message).show();
                } else {
                    $res.addClass('alert alert-danger').html('<i class="fas fa-times-circle"></i> ' + (data ? data.message : 'Sync failed')).show();
                }
            }).fail(function(xhr) {
                $res.addClass('alert alert-danger').html('<i class="fas fa-exclamation-triangle"></i> Sync request failed (HTTP ' + xhr.status + ')').show();
            }).always(function() {
                $btn.prop('disabled', false).html('<i class="fas fa-cloud-upload-alt"></i> Sync All Products to Paddle');
            });
        });
    });
    </script>
</div>

