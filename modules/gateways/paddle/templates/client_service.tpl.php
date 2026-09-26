<?php
if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

use WHMCS\Module\Gateway\Paddle\Security;

/**
 * Variables expected:
 * @var int         $serviceId
 * @var string|null $subscriptionId
 * @var string|null $customerId
 * @var string|null $status
 * @var string|null $interval
 * @var string|null $nextBilledAt
 * @var string|null $portalUrl
 * @var string|null $portalActionUrl
 */

$portalLink = !empty($portalActionUrl) 
    ? $portalActionUrl 
    : (!empty($portalUrl) ? $portalUrl : "clientarea.php?action=productdetails&id={$serviceId}&paddle_action=customer_portal");

$portalError = $_SESSION['paddle_portal_error'] ?? null;
if (!empty($portalError)) {
    unset($_SESSION['paddle_portal_error']);
}
?>
<div class="panel panel-default card mb-3 paddle-service-info-panel shadow-sm">
    <div class="panel-heading card-header bg-light d-flex justify-content-between align-items-center py-2 px-3">
        <h5 class="panel-title card-title m-0 font-weight-bold" style="font-size: 15px;">
            <i class="fas fa-credit-card me-1 text-primary"></i> Paddle Billing Subscription
        </h5>
        <span class="badge badge-<?= ($status === 'active') ? 'success' : 'secondary' ?> bg-<?= ($status === 'active') ? 'success' : 'secondary' ?> px-2 py-1">
            <?= Security::escapeHtml(strtoupper($status ?? 'ACTIVE')) ?>
        </span>
    </div>
    <div class="panel-body card-body p-3">
        <?php if (!empty($portalError)): ?>
            <div class="alert alert-warning alert-dismissible fade show p-2 mb-3 small" role="alert">
                <i class="fas fa-exclamation-circle me-1"></i>
                <?= Security::escapeHtml($portalError) ?>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-sm-6 col-12 mb-2">
                <span class="text-muted small d-block">Subscription Status</span>
                <strong class="text-capitalize"><?= Security::escapeHtml($status ?? 'Active') ?></strong>
            </div>
            <?php if (!empty($subscriptionId)): ?>
            <div class="col-sm-6 col-12 mb-2">
                <span class="text-muted small d-block">Paddle Subscription ID</span>
                <code><?= Security::escapeHtml($subscriptionId) ?></code>
            </div>
            <?php endif; ?>
            <?php if (!empty($interval)): ?>
            <div class="col-sm-6 col-12 mb-2">
                <span class="text-muted small d-block">Billing Interval</span>
                <span><?= Security::escapeHtml(ucwords($interval)) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($nextBilledAt)): ?>
            <div class="col-sm-6 col-12 mb-2">
                <span class="text-muted small d-block">Next Renewal Date</span>
                <span><?= Security::escapeHtml($nextBilledAt) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <hr class="my-2">
        <div class="d-flex justify-content-end align-items-center pt-2">
            <a href="<?= Security::escapeHtml($portalLink) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-sm px-3" style="background-color: #2e384d; border-color: #2e384d; color: #fff;">
                <i class="fas fa-external-link-alt me-1"></i> Manage in Paddle Customer Portal
            </a>
        </div>
    </div>
</div>
