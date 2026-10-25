<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Session;
use App\Core\Config;

Session::start();

// Prevent caching - user should not see cached timeout page
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Clear reservation timer
Session::clearReservationTimeout();

$pageTitle = 'Warenkorb abgelaufen';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Ticket System</title>
    <link href="<?= url('libs/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= url('libs/css/bootstrap-icons.css') ?>" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="<?= url('') ?>">
                <i class="bi bi-ticket-perforated"></i> Ticket System
            </a>
        </div>
    </nav>
    <?php $companyLogo = Config::get('company_logo', ''); if ($companyLogo): ?>
    <div style="position: fixed; top: 70px; right: 50px; z-index: 1000; pointer-events: none;">
        <img src="<?= url($companyLogo) ?>" alt="Logo" style="height: 100px; max-width: 250px; object-fit: contain;">
    </div>
    <?php endif; ?>

    <div class="container mt-5 mb-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card border-warning">
                    <div class="card-header bg-warning text-dark">
                        <h4 class="mb-0"><i class="bi bi-hourglass-bottom"></i> Reservierungszeit abgelaufen</h4>
                    </div>
                    <div class="card-body text-center">
                        <div class="mb-4">
                            <i class="bi bi-clock-history text-warning" style="font-size: 4rem;"></i>
                        </div>
                        <h5>Ihr Warenkorb wurde geleert</h5>
                        <p class="text-muted">
                            Die Reservierungszeit von 15 Minuten ist abgelaufen. 
                            Ihre ausgewählten Plätze wurden freigegeben und stehen nun wieder anderen Kunden zur Verfügung.
                        </p>
                        <hr>
                        <p class="mb-4">
                            Bitte wählen Sie Ihre Plätze erneut aus, wenn Sie noch Tickets kaufen möchten.
                        </p>
                        <a href="<?= url('') ?>" class="btn btn-primary btn-lg">
                            <i class="bi bi-arrow-left"></i> Zurück zur Startseite
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= url('libs/js/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>

