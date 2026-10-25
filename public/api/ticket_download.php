<?php
/**
 * API Endpoint to download generated ticket PDF
 * Secure token-based access using session_id hash
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
use App\Services\TicketPdfGenerator;
use App\Services\LoggerService;
use App\Models\Reservation;

Session::start();
ApiGuard::publicApi('pdf', 30, 60);

try {
    // Get parameters - only accept reservation_ids (not id parameter for security)
    $token = $_GET['token'] ?? '';
    $reservationIds = isset($_GET['reservation_ids']) ? explode(',', $_GET['reservation_ids']) : [];
    
    // Convert reservation_ids to integers
    $reservationIds = array_filter(array_map('intval', $reservationIds));
    
    // Remove duplicates
    $reservationIds = array_unique($reservationIds);
    
    if (empty($reservationIds)) {
        throw new \Exception('Keine Reservierungs-ID angegeben', 400);
    }

    $reservationModel = new Reservation();
    $sessionId = session_id();
    
    // Verify access: Check if user is admin/presales OR if token matches
    $isAuthorized = false;
    
    // Check if user is logged in as admin or presales
    if (Auth::check() && in_array(Auth::role(), ['admin', 'presales'])) {
        $isAuthorized = true;
    } else {
        // Verify token for customer access
        // First, check if all reservations are paid (status_id = 2)
        $allPaid = true;
        $reservations = [];
        $failedId = null;
        $failedReason = null;
        foreach ($reservationIds as $rid) {
            $reservation = $reservationModel->getById($rid);
            if (!$reservation) {
                $allPaid = false;
                $failedId = $rid;
                $failedReason = 'nicht gefunden (gelöscht/storniert?)';
                break;
            }
            $reservations[] = $reservation;
            // Accept all statuses that represent a completed purchase.
            // Must mirror $printableStatuses in api/order_details.php.
            // 7 = für die Abendkasse gedruckt (Reprint erlaubt).
            $paidStatuses = [2, 4, 5, 7, 8, 9, 10, 11];
            if (!in_array((int)$reservation['status_id'], $paidStatuses, true)) {
                $allPaid = false;
                $failedId = $rid;
                $failedReason = 'status_id=' . (int)$reservation['status_id'] . ' (kein Bezahlt-Status)';
                break;
            }
        }
        
        if ($allPaid) {
            $tokenValid = false;

            // Primary check: token must match the exact set of requested IDs
            $expectedToken = generateTicketDownloadToken(array_values($reservationIds));
            if (hash_equals($expectedToken, $token)) {
                $tokenValid = true;
            }

            // Fallback: reservation belongs to current session (no token needed)
            if (!$tokenValid) {
                foreach ($reservations as $reservation) {
                    if ($reservation['session_id'] === $sessionId) {
                        $tokenValid = true;
                        break;
                    }
                }
            }

            if ($tokenValid) {
                $isAuthorized = true;
            } else {
                $firstRes = $reservations[0] ?? [];
                $logger = LoggerService::getCustomerLogger();
                $logger->warning('Ticket Download: Token-Validierung fehlgeschlagen', [
                    'reservation_ids'  => implode(',', $reservationIds),
                    'customer_id'      => $firstRes['customer_id'] ?? null,
                    'customer_name'    => trim(($firstRes['first_name'] ?? '') . ' ' . ($firstRes['last_name'] ?? '')) ?: null,
                    'token_provided'   => substr($token, 0, 8) . '...',
                    'token_expected'   => substr($expectedToken, 0, 8) . '...',
                    'secret_hash'      => substr(md5($_ENV['APP_SECRET'] ?? ''), 0, 6),
                    'ip'               => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);
            }
        } else {
            // Diagnose: War der Link jemals legitim von uns erzeugt? Token auch dann
            // prüfen, wenn die Reservierung nicht mehr existiert (gelöscht/storniert).
            $expectedToken = generateTicketDownloadToken(array_values($reservationIds));
            $tokenValid    = $token !== '' && hash_equals($expectedToken, $token);

            $logger = LoggerService::getCustomerLogger();
            $logger->warning('Ticket Download: Nicht alle Reservierungen bezahlt', [
                'reservation_ids' => implode(',', $reservationIds),
                'failed_id'       => $failedId,
                'failed_reason'   => $failedReason,
                'token_valid'     => $tokenValid,
                'token_provided'  => $token === '' ? '(leer)' : substr($token, 0, 8) . '...',
                'secret_hash'     => substr(md5($_ENV['APP_SECRET'] ?? ''), 0, 6),
                'ip'              => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'referer'         => $_SERVER['HTTP_REFERER'] ?? null,
            ]);
        }
    }
    
    if (!$isAuthorized) {
        throw new \Exception('Zugriff verweigert', 403);
    }

    // Log ticket download / (re-)print
    $logRole = (Auth::check() && in_array(Auth::role(), ['admin', 'presales'], true)) ? Auth::role() : 'customer';
    LoggerService::logTicketDownload(
        $logRole,
        Auth::check() ? (string)Auth::id() : null,
        Auth::username(),
        array_values($reservationIds)
    );

    $generator = new TicketPdfGenerator();

    // Presales always prints through the presales template (which encodes its own
    // print mode per event). Admins may explicitly request the presales template
    // via ?scope=presales (otherwise the customer/A4 template is used). Customers
    // always get the customer template.
    if (Auth::check() && Auth::role() === 'presales') {
        $scope = 'presales';
    } elseif (Auth::check() && Auth::role() === 'admin' && ($_GET['scope'] ?? '') === 'presales') {
        $scope = 'presales';
    } else {
        $scope = 'customer';
    }

    // Generate filename
    if (count($reservationIds) === 1) {
        $filename = 'ticket_' . $reservationIds[0] . '.pdf';
        $generator->outputTicket($reservationIds[0], $filename, $scope);
    } else {
        $filename = 'tickets_' . implode('_', $reservationIds) . '.pdf';
        $generator->outputMultipleTickets($reservationIds, $filename, $scope);
    }
    
    exit;
} catch (\Throwable $e) {
    if (ob_get_level()) {
        ob_clean();
    }

    try {
        $logger = LoggerService::getCustomerLogger();
        $logger->error('Ticket Download fehlgeschlagen: ' . $e->getMessage(), [
            'reservation_ids' => implode(',', $reservationIds ?? []),
            'exception'       => get_class($e),
            'file'            => $e->getFile() . ':' . $e->getLine(),
            'ip'              => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
    } catch (\Throwable $logErr) {
        error_log('Ticket Download Logging-Fehler: ' . $logErr->getMessage());
    }

    // Exception-Code als HTTP-Status verwenden, wenn es ein gültiger ist
    // (403 Zugriff verweigert, 400 fehlende ID) – sonst 500.
    $httpCode = (int)$e->getCode();
    http_response_code($httpCode >= 400 && $httpCode < 600 ? $httpCode : 500);

    // If headers not sent, show error page
    if (!headers_sent()) {
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
                        <h3 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Fehler beim Generieren des Tickets</h3>
                    </div>
                    <div class="card-body text-center">
                        <p class="lead">' . $errorMessage . '</p>
                        <a href="javascript:history.back()" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Zurück
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>';
    } else {
        echo 'Error: ' . htmlspecialchars($e->getMessage());
    }
    exit;
}

