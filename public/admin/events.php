<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\Event;

Auth::requireAdmin();

$eventModel = new Event();
$events = $eventModel->getAll();

$pageTitle = 'Veranstaltungen';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Veranstaltungen</h2>
    <a href="' . url('admin/events_create.php') . '" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Neue Veranstaltung
    </a>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Tickets</th>
                        <th>Gutscheine</th>
                        <th>Sitzplan</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>';

if (empty($events)) {
    $content .= '
                    <tr>
                        <td colspan="7" class="text-center text-muted">
                            <i class="bi bi-inbox"></i> Noch keine Veranstaltungen vorhanden
                        </td>
                    </tr>';
} else {
    foreach ($events as $event) {
        $statusBadge = $event['active']
            ? '<span class="badge bg-success">Aktiv</span>'
            : '<span class="badge bg-secondary">Inaktiv</span>';

        // Vorüber: alle Termine liegen in der Vergangenheit – im Kundenbereich gesperrt
        if (!empty($event['is_finished'])) {
            $statusBadge .= ' <span class="badge bg-dark" title="Alle Termine liegen in der Vergangenheit. '
                . 'Im Kundenbereich sind Ticket- und Gutscheinkauf gesperrt.">Vorüber</span>';
        }

        $ticketsBadge = $event['sell_tickets'] 
            ? '<span class="badge bg-primary">Ja</span>' 
            : '<span class="badge bg-secondary">Nein</span>';
        
        $vouchersBadge = $event['sell_vouchers'] 
            ? '<span class="badge bg-info">Ja</span>' 
            : '<span class="badge bg-secondary">Nein</span>';
        
        $seatplanBadge = $event['use_seat_plan'] 
            ? '<span class="badge bg-warning">Ja</span>' 
            : '<span class="badge bg-secondary">Nein</span>';
        
        $content .= '
                    <tr>
                        <td>' . htmlspecialchars($event['id']) . '</td>
                        <td><strong>' . htmlspecialchars($event['name']) . '</strong></td>
                        <td>' . $statusBadge . '</td>
                        <td>' . $ticketsBadge . '</td>
                        <td>' . $vouchersBadge . '</td>
                        <td>' . $seatplanBadge . '</td>
                        <td>
                            <a href="' . url('admin/events_edit.php?id=' . $event['id']) . '" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i> Bearbeiten
                            </a>
                            <a href="' . url('admin/events_dates.php?id=' . $event['id']) . '" class="btn btn-sm btn-outline-info">
                                <i class="bi bi-calendar"></i> Termine
                            </a>
                            <a href="' . url('admin/box_office.php?event_id=' . $event['id']) . '" class="btn btn-sm btn-outline-warning">
                                <i class="bi bi-printer"></i> Abendkasse
                            </a>
                            <a href="' . url('event.php?id=' . $event['id']) . '" target="_blank" class="btn btn-sm btn-outline-secondary" title="Buchung aus Kundensicht testen – funktioniert für Admins auch bei inaktiven Veranstaltungen">
                                <i class="bi bi-eye"></i> Als Kunde testen
                            </a>
                        </td>
                    </tr>';
    }
}

$content .= '
                </tbody>
            </table>
        </div>
    </div>
</div>
';

require __DIR__ . '/../../app/views/layouts/main.php';

