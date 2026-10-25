<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Models\PaymentRequest;
use App\Services\EmailService;

Auth::requireAdmin();

$model = new PaymentRequest();
$error   = '';
$success = '';

// ─── POST: Neue Zahlungsanforderung erstellen ────────────────────────────────
// CSRF-Schutz für alle POST-Aktionen dieser Seite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !\App\Core\Csrf::validatePost()) {
    http_response_code(403);
    die('Ungültige Anfrage: Sicherheits-Token fehlt oder ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_request'])) {
        try {
            $customerName  = trim($_POST['customer_name'] ?? '');
            $customerEmail = trim($_POST['customer_email'] ?? '');
            $amount        = str_replace(',', '.', trim($_POST['amount'] ?? ''));
            $description   = trim($_POST['description'] ?? '');
            $notes         = trim($_POST['notes'] ?? '');

            if (empty($customerName)) throw new \Exception('Name des Kunden ist Pflichtfeld.');
            if (empty($customerEmail) || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                throw new \Exception('Bitte eine gültige E-Mail-Adresse eingeben.');
            }
            if (!is_numeric($amount) || (float)$amount <= 0) {
                throw new \Exception('Bitte einen gültigen Betrag eingeben (z.B. 25.00).');
            }
            if (empty($description)) throw new \Exception('Beschreibung ist Pflichtfeld.');

            $token = PaymentRequest::generateToken();
            $id = $model->create([
                'token'          => $token,
                'customer_email' => $customerEmail,
                'customer_name'  => $customerName,
                'amount'         => (float)$amount,
                'description'    => $description,
                'created_by'     => Auth::id(),
                'notes'          => $notes ?: null,
            ]);

            // E-Mail sofort senden
            $paymentUrl = absoluteUrl('payment_request.php?token=' . $token);
            try {
                $emailService = new EmailService();
                $emailService->sendPaymentRequestEmail(
                    $customerEmail,
                    $customerName,
                    (float)$amount,
                    $description,
                    $paymentUrl
                );
                $success = 'Zahlungsanforderung erstellt und E-Mail an ' . htmlspecialchars($customerEmail) . ' gesendet.';
            } catch (\Exception $emailEx) {
                error_log('PaymentRequest email error: ' . $emailEx->getMessage());
                $success = 'Zahlungsanforderung erstellt. E-Mail konnte nicht gesendet werden – bitte Link manuell kopieren.';
            }

            if (function_exists('logAdminAction')) {
                logAdminAction((string)Auth::id(), 'payment_request_created', [
                    'id'     => $id,
                    'amount' => (float)$amount,
                    'email'  => substr(md5($customerEmail), 0, 8) . '***',
                ]);
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    } elseif (isset($_POST['cancel_request'])) {
        $id = (int)($_POST['request_id'] ?? 0);
        $req = $id ? $model->getById($id) : null;
        if ($req && $req['status'] === 'pending') {
            $model->cancel($id);
            $success = 'Zahlungsanforderung storniert.';
        } else {
            $error = 'Anforderung nicht gefunden oder bereits abgeschlossen.';
        }
    } elseif (isset($_POST['resend_email'])) {
        $id = (int)($_POST['request_id'] ?? 0);
        $req = $id ? $model->getById($id) : null;
        if ($req && $req['status'] === 'pending') {
            $paymentUrl = absoluteUrl('payment_request.php?token=' . $req['token']);
            try {
                $emailService = new EmailService();
                $emailService->sendPaymentRequestEmail(
                    $req['customer_email'],
                    $req['customer_name'],
                    (float)$req['amount'],
                    $req['description'],
                    $paymentUrl
                );
                $success = 'Zahlungslink erneut an ' . htmlspecialchars($req['customer_email']) . ' gesendet.';
            } catch (\Exception $e) {
                $error = 'E-Mail konnte nicht gesendet werden: ' . $e->getMessage();
            }
        } else {
            $error = 'Anforderung nicht gefunden oder bereits abgeschlossen.';
        }
    }
}

$requests = $model->getAll();

// ─── Layout ──────────────────────────────────────────────────────────────────
$pageTitle   = 'Zahlungsanforderungen';
$showSidebar = true;
$showHeader  = true;

// ─── Inhalt aufbauen ─────────────────────────────────────────────────────────
$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-send text-primary"></i> Zahlungsanforderungen</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
        <i class="bi bi-plus-circle"></i> Neue Anforderung
    </button>
</div>';

if ($error) {
    $content .= '<div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle"></i> ' . htmlspecialchars($error) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>';
}
if ($success) {
    $content .= '<div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle"></i> ' . htmlspecialchars($success) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>';
}

// ─── Erklärungsbox ────────────────────────────────────────────────────────────
$content .= '
<div class="alert alert-info mb-4">
    <h6 class="alert-heading"><i class="bi bi-info-circle"></i> Ablauf</h6>
    <ol class="mb-0 small">
        <li>Neue Anforderung erstellen → Kunde bekommt automatisch einen Bezahllink per E-Mail.</li>
        <li>Kunde zahlt per PayPal → Sie erhalten eine Benachrichtigungs-E-Mail.</li>
        <li>Sie weisen dem Kunden manuell Karten zu (über <strong>VIP-Tickets</strong>) und senden den Download-Link.</li>
    </ol>
</div>';

// ─── Tabelle ──────────────────────────────────────────────────────────────────
$content .= '
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Kunde</th>
                        <th>Betrag</th>
                        <th>Beschreibung</th>
                        <th>Status</th>
                        <th>Erstellt</th>
                        <th>Bezahlt</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>';

if (empty($requests)) {
    $content .= '<tr><td colspan="8" class="text-center text-muted py-4">Noch keine Zahlungsanforderungen vorhanden.</td></tr>';
} else {
    foreach ($requests as $req) {
        $statusBadge = match($req['status']) {
            'pending'   => '<span class="badge bg-warning text-dark">Ausstehend</span>',
            'paid'      => '<span class="badge bg-success">Bezahlt</span>',
            'cancelled' => '<span class="badge bg-secondary">Storniert</span>',
            default     => '<span class="badge bg-light text-dark">' . htmlspecialchars($req['status']) . '</span>',
        };

        $paymentUrl = absoluteUrl('payment_request.php?token=' . $req['token']);
        $paidAt     = $req['paid_at'] ? date('d.m.Y H:i', strtotime($req['paid_at'])) : '–';
        $createdAt  = date('d.m.Y H:i', strtotime($req['created_at']));

        $actionButtons = '';
        if ($req['status'] === 'pending') {
            $actionButtons .= '
                <button class="btn btn-sm btn-outline-secondary me-1" title="Bezahllink kopieren"
                    onclick="copyLink(\'' . htmlspecialchars($paymentUrl, ENT_QUOTES) . '\', this)">
                    <i class="bi bi-clipboard"></i>
                </button>
                <form method="POST" class="d-inline">
                    ' . \App\Core\Csrf::field() . '
                    <input type="hidden" name="request_id" value="' . $req['id'] . '">
                    <button type="submit" name="resend_email" class="btn btn-sm btn-outline-primary me-1" title="E-Mail erneut senden">
                        <i class="bi bi-envelope"></i>
                    </button>
                </form>
                <form method="POST" class="d-inline"
                    onsubmit="return confirm(\'Zahlungsanforderung stornieren?\')">
                    ' . \App\Core\Csrf::field() . '
                    <input type="hidden" name="request_id" value="' . $req['id'] . '">
                    <button type="submit" name="cancel_request" class="btn btn-sm btn-outline-danger" title="Stornieren">
                        <i class="bi bi-x-circle"></i>
                    </button>
                </form>';
        } elseif ($req['status'] === 'paid') {
            $actionButtons .= '
                <a href="' . url('admin/payment_request_assign.php?id=' . $req['id']) . '" class="btn btn-sm btn-success" title="Karten jetzt zuweisen">
                    <i class="bi bi-ticket-perforated"></i> Karten zuweisen
                </a>';
        }

        $content .= '
                <tr>
                    <td class="text-muted small">' . $req['id'] . '</td>
                    <td>
                        <div class="fw-semibold">' . htmlspecialchars($req['customer_name']) . '</div>
                        <div class="text-muted small">' . htmlspecialchars($req['customer_email']) . '</div>
                    </td>
                    <td class="fw-semibold">' . number_format((float)$req['amount'], 2, ',', '.') . ' €</td>
                    <td class="small">' . htmlspecialchars(mb_strimwidth($req['description'] ?? '', 0, 60, '…')) . '</td>
                    <td>' . $statusBadge . '</td>
                    <td class="small text-muted">' . $createdAt . '</td>
                    <td class="small text-muted">' . $paidAt . '</td>
                    <td>' . $actionButtons . '</td>
                </tr>';
    }
}

$content .= '
                </tbody>
            </table>
        </div>
    </div>
</div>';

// ─── Modal: Neue Anforderung ──────────────────────────────────────────────────
$content .= '
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                ' . \App\Core\Csrf::field() . '
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-send"></i> Neue Zahlungsanforderung</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name des Kunden <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="customer_name" required placeholder="Max Mustermann">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">E-Mail des Kunden <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="customer_email" required placeholder="kunde@example.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Betrag (€) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control" name="amount" required placeholder="25.00"
                                pattern="[0-9]+([,.][0-9]{1,2})?" title="Betrag z.B. 25.00 oder 25,00">
                            <span class="input-group-text">€</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Beschreibung <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="description" rows="3" required
                            placeholder="z.B. 2 Karten für Konzert am 15.03.2026 (Kategorie A)"></textarea>
                        <div class="form-text">Diese Beschreibung wird dem Kunden in der E-Mail und auf der Zahlungsseite angezeigt.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Interne Notiz (optional)</label>
                        <input type="text" class="form-control" name="notes" placeholder="Nur intern sichtbar">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" name="create_request" class="btn btn-primary">
                        <i class="bi bi-send"></i> Erstellen &amp; E-Mail senden
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>';

$additionalJs = '<script>
function copyLink(url, btn) {
    navigator.clipboard.writeText(url).then(function() {
        var icon = btn.querySelector("i");
        icon.className = "bi bi-clipboard-check";
        btn.title = "Kopiert!";
        setTimeout(function() {
            icon.className = "bi bi-clipboard";
            btn.title = "Bezahllink kopieren";
        }, 2000);
    }).catch(function() {
        prompt("Bezahllink (bitte manuell kopieren):", url);
    });
}
</script>';

require_once __DIR__ . '/../../app/views/layouts/main.php';
