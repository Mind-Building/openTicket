<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Models\PaymentRequest;

// Token aus URL lesen und Anforderung laden
$token = trim($_GET['token'] ?? '');
$req   = null;

if (!empty($token)) {
    $model = new PaymentRequest();
    $req   = $model->getByToken($token);
}

// PayPal-Konfiguration
$paypalActive   = Config::get('paypal_active', false);
$paypalMode     = Config::get('paypal_mode', 'sandbox');
$paypalClientId = $paypalMode === 'live'
    ? ($_ENV['PAYPAL_LIVE_CLIENT_ID']    ?? Config::get('paypal_live_client_id', ''))
    : ($_ENV['PAYPAL_SANDBOX_CLIENT_ID'] ?? Config::get('paypal_sandbox_client_id', ''));

$companyName = Config::get('company_name', 'Ticket System');

$pageTitle = 'Zahlung';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> – <?= htmlspecialchars($companyName) ?></title>
    <link href="<?= url('libs/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= url('libs/css/bootstrap-icons.css') ?>" rel="stylesheet">
    <?php if ($req && $req['status'] === 'pending' && $paypalActive && !empty($paypalClientId)): ?>
        <script src="https://www.paypal.com/sdk/js?client-id=<?= htmlspecialchars($paypalClientId) ?>&currency=EUR&locale=de_DE"></script>
    <?php endif; ?>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-primary">
    <div class="container">
        <span class="navbar-brand">
            <i class="bi bi-ticket-perforated"></i> <?= htmlspecialchars($companyName) ?>
        </span>
    </div>
</nav>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">

<?php if (empty($token) || !$req): ?>
    <!-- Ungültiger Link -->
    <div class="card border-danger">
        <div class="card-body text-center py-5">
            <i class="bi bi-exclamation-circle text-danger" style="font-size:3rem;"></i>
            <h4 class="mt-3">Ungültiger Link</h4>
            <p class="text-muted">Dieser Zahlungslink ist nicht gültig oder wurde bereits verwendet.</p>
        </div>
    </div>

<?php elseif ($req['status'] === 'paid'): ?>
    <!-- Bereits bezahlt -->
    <div class="card border-success">
        <div class="card-body text-center py-5">
            <i class="bi bi-check-circle-fill text-success" style="font-size:3rem;"></i>
            <h4 class="mt-3">Zahlung eingegangen</h4>
            <p class="text-muted">Diese Zahlung wurde bereits erfolgreich abgeschlossen.<br>
            Sie erhalten Ihre Eintrittskarten per E-Mail.</p>
        </div>
    </div>

<?php elseif ($req['status'] === 'cancelled'): ?>
    <!-- Storniert -->
    <div class="card border-secondary">
        <div class="card-body text-center py-5">
            <i class="bi bi-x-circle text-secondary" style="font-size:3rem;"></i>
            <h4 class="mt-3">Zahlungsanforderung storniert</h4>
            <p class="text-muted">Diese Zahlungsanforderung wurde storniert. Bitte wenden Sie sich an den Veranstalter.</p>
        </div>
    </div>

<?php else: /* status === 'pending' */ ?>
    <!-- Zahlungsformular -->
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-credit-card"></i> Zahlung vornehmen</h5>
        </div>
        <div class="card-body">

            <div class="mb-4">
                <p class="text-muted small mb-1">Empfänger</p>
                <p class="fw-semibold mb-0"><?= htmlspecialchars($req['customer_name']) ?></p>
            </div>

            <div class="mb-4">
                <p class="text-muted small mb-1">Verwendungszweck</p>
                <p class="mb-0"><?= nl2br(htmlspecialchars($req['description'])) ?></p>
            </div>

            <div class="mb-4">
                <p class="text-muted small mb-1">Betrag</p>
                <p class="fs-3 fw-bold text-primary mb-0">
                    <?= number_format((float)$req['amount'], 2, ',', '.') ?> €
                </p>
            </div>

            <hr>

            <?php if (!$paypalActive || empty($paypalClientId)): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    Online-Zahlung ist aktuell nicht verfügbar. Bitte kontaktieren Sie den Veranstalter.
                </div>
            <?php else: ?>
                <p class="text-muted small mb-3">Bitte zahlen Sie den Betrag sicher per PayPal:</p>
                <div id="paypal-button-container"></div>
                <div id="paypal-error" class="alert alert-danger mt-3" style="display:none;"></div>
            <?php endif; ?>

        </div>
    </div>

<?php endif; ?>

        </div>
    </div>
</div>

<script src="<?= url('libs/js/bootstrap.bundle.min.js') ?>"></script>

<?php if ($req && $req['status'] === 'pending' && $paypalActive && !empty($paypalClientId)): ?>
<script>
const requestToken = <?= json_encode($token) ?>;
const createOrderUrl = <?= json_encode(url('api/payment_request_create_order.php')) ?>;
const captureUrl     = <?= json_encode(url('api/payment_request_capture.php')) ?>;
const successUrl     = <?= json_encode(absoluteUrl('payment_request.php?token=' . $token . '&paid=1')) ?>;

paypal.Buttons({
    style: {
        layout: 'vertical',
        color:  'gold',
        shape:  'rect',
        label:  'pay'
    },

    createOrder: function(data, actions) {
        document.getElementById('paypal-error').style.display = 'none';
        return fetch(createOrderUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ token: requestToken })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.orderID) {
                return data.orderID;
            }
            throw new Error(data.error || 'Fehler beim Starten der Zahlung');
        });
    },

    onApprove: function(data, actions) {
        return fetch(captureUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ token: requestToken, orderID: data.orderID })
        })
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                window.location.href = successUrl;
            } else {
                throw new Error(result.error || 'Zahlung konnte nicht abgeschlossen werden');
            }
        });
    },

    onError: function(err) {
        var errEl = document.getElementById('paypal-error');
        errEl.textContent = 'Zahlung fehlgeschlagen. Bitte versuchen Sie es erneut oder kontaktieren Sie den Veranstalter.';
        errEl.style.display = 'block';
        console.error('PayPal error:', err);
    },

    onCancel: function() {
        /* Nutzer hat Popup geschlossen – kein Fehler anzeigen */
    }
}).render('#paypal-button-container');
</script>
<?php endif; ?>

</body>
</html>
