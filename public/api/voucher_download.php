<?php
/**
 * API Endpoint to download generated voucher PDF
 * Secure token-based access
 */

// Suppress any output before PDF
if (ob_get_level()) {
    ob_clean();
} else {
    ob_start();
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Core\ApiGuard;
use App\Services\VoucherPdfGenerator;
use App\Models\Voucher;

Session::start();
ApiGuard::publicApi('pdf', 30, 60);

try {
    // Get parameters
    $token = $_GET['token'] ?? '';
    $voucherIds = isset($_GET['voucher_ids']) ? explode(',', $_GET['voucher_ids']) : [];
    
    // Convert voucher_ids to integers
    $voucherIds = array_filter(array_map('intval', $voucherIds));
    
    // Remove duplicates
    $voucherIds = array_unique($voucherIds);
    
    if (empty($voucherIds)) {
        throw new \Exception('Keine Gutschein-ID angegeben');
    }

    $voucherModel = new Voucher();
    $sessionId = session_id();
    
    // Verify access: Check if user is admin/presales OR if token matches
    $isAuthorized = false;
    
    // Check if user is logged in as admin or presales
    if (Auth::check() && in_array(Auth::role(), ['admin', 'presales'])) {
        $isAuthorized = true;
    } else {
        // Verify token for customer access
        // First, check if all vouchers are paid (status_id = 2)
        $allPaid = true;
        $vouchers = [];
        foreach ($voucherIds as $vid) {
            $voucher = $voucherModel->getById($vid);
            if (!$voucher) {
                $allPaid = false;
                break;
            }
            $vouchers[] = $voucher;
            if ((int)$voucher['status_id'] !== 2) { // Not paid
                $allPaid = false;
                break;
            }
        }

        if ($allPaid) {
            $tokenValid = false;

            // Primary check: token must match the exact set of requested IDs
            $expectedToken = generateVoucherDownloadToken(array_values($voucherIds));
            if (hash_equals($expectedToken, $token)) {
                $tokenValid = true;
            }

            // Fallback: voucher belongs to current session
            if (!$tokenValid) {
                foreach ($vouchers as $voucher) {
                    if ($voucher['session_id'] === $sessionId) {
                        $tokenValid = true;
                        break;
                    }
                }
            }

            if ($tokenValid) {
                $isAuthorized = true;
            } else {
                error_log("Voucher Download - Token validation failed for IDs: " . implode(', ', $voucherIds));
            }
        }
    }
    
    if (!$isAuthorized) {
        header('HTTP/1.1 403 Forbidden');
        header('Location: ' . url('index.php'));
        exit;
    }
    
    // Generate PDF for all vouchers
    $pdfGenerator = new VoucherPdfGenerator();
    
    // Generate combined PDF for all vouchers
    $pdfContent = $pdfGenerator->generateVouchers($voucherIds);
    
    if (!$pdfContent) {
        throw new \Exception('Fehler beim Generieren des Gutscheins');
    }
    
    // Output PDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="gutscheine_' . implode('_', $voucherIds) . '.pdf"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    echo $pdfContent;
    exit;
    
} catch (\Exception $e) {
    error_log("Voucher Download Error: " . $e->getMessage());
    error_log("Voucher Download Trace: " . $e->getTraceAsString());
    
    // Clear any output
    if (ob_get_level()) {
        ob_clean();
    }
    
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: text/html; charset=utf-8');
    $errorMessage = htmlspecialchars($e->getMessage());
    $baseUrl = url('');
    $bootstrapCss = url('libs/css/bootstrap.min.css');
    $bootstrapIcons = url('libs/css/bootstrap-icons.css');
    echo '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fehler - Ticket System</title>
    <link href="' . $bootstrapCss . '" rel="stylesheet">
    <link href="' . $bootstrapIcons . '" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="' . $baseUrl . '">
                <i class="bi bi-ticket-perforated"></i> Ticket System
            </a>
        </div>
    </nav>
    <div class="container mt-5 mb-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white text-center">
                        <h3 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Fehler beim Herunterladen des Gutscheins</h3>
                    </div>
                    <div class="card-body text-center">
                        <p class="lead">' . $errorMessage . '</p>
                        <a href="' . $baseUrl . '" class="btn btn-secondary">
                            <i class="bi bi-house-door"></i> Zur Startseite
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>';
    exit;
}

