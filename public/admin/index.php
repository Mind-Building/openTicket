<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\Customer;
use App\Models\Voucher;

Auth::requireAdmin();

$eventModel = new Event();
$reservationModel = new Reservation();
$customerModel = new Customer();
$voucherModel = new Voucher();

$eventCount = count($eventModel->getAll());
$reservationCount = count($reservationModel->getAll());
$customerCount = $customerModel->count();
$voucherCount = $voucherModel->count();

$pageTitle = 'Admin Dashboard';
$showSidebar = true;
$showHeader = true;

// After a code update the database may still lack migrations - point the admin to the update page
$pendingMigrations = [];
try {
    $pendingMigrations = (new \App\Services\DatabaseMigrator())->pending();
} catch (\Throwable $e) {
    // dashboard must not fail because of this check
}
$migrationNotice = $pendingMigrations
    ? '<div class="alert alert-warning d-flex justify-content-between align-items-center">'
        . '<span><i class="bi bi-database-exclamation"></i> Die Datenbank ist nicht auf dem aktuellen Stand: '
        . count($pendingMigrations) . ' Änderung(en) ausstehend.</span>'
        . '<a href="' . url('admin/database_update.php') . '" class="btn btn-sm btn-warning">Datenbank-Update öffnen</a></div>'
    : '';

$content = $migrationNotice . '
<div class="row">
    <div class="col-md-3 mb-4">
        <div class="card text-white bg-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Veranstaltungen</h6>
                        <h2 class="mb-0">' . $eventCount . '</h2>
                    </div>
                    <i class="bi bi-calendar-event" style="font-size: 2rem; opacity: 0.5;"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="card text-white bg-success">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Reservierungen</h6>
                        <h2 class="mb-0">' . $reservationCount . '</h2>
                    </div>
                    <i class="bi bi-ticket" style="font-size: 2rem; opacity: 0.5;"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="card text-white bg-info">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Kunden</h6>
                        <h2 class="mb-0">' . $customerCount . '</h2>
                    </div>
                    <i class="bi bi-people" style="font-size: 2rem; opacity: 0.5;"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="card text-white bg-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Gutscheine</h6>
                        <h2 class="mb-0">' . $voucherCount . '</h2>
                    </div>
                    <i class="bi bi-gift" style="font-size: 2rem; opacity: 0.5;"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h5 class="card-title">Willkommen im Admin-Bereich</h5>
        <p class="card-text">Hier können Sie Veranstaltungen verwalten, Sitzpläne erstellen und alle Reservierungen einsehen.</p>
    </div>
</div>
';

require __DIR__ . '/../../app/views/layouts/main.php';

