<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;

Auth::requireAdmin();

$pageTitle = 'Auswertungen';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="row">
    <div class="col-md-12">
        <h2>Auswertungen</h2>
        <p class="text-muted">Wählen Sie eine Auswertung aus:</p>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title">
                    <i class="bi bi-ticket-perforated"></i> Ticket-Übersicht
                </h5>
                <p class="card-text">
                    Detaillierte Übersicht über alle Tickets nach Datum, Status und Kategorie. 
                    Zeigt belegte und freie Plätze sowie Einnahmen.
                </p>
                <a href="' . url('admin/reports_ticket_overview.php') . '" class="btn btn-primary">
                    <i class="bi bi-arrow-right"></i> Zur Ticket-Übersicht
                </a>
            </div>
        </div>
    </div>
    
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title">
                    <i class="bi bi-graph-up"></i> Evaluierungen
                </h5>
                <p class="card-text">
                    Statistische Auswertung der Reservierungen nach Status und Datum.
                    Inklusive grafischer Darstellung als Liniendiagramm.
                </p>
                <a href="' . url('admin/reports_evaluations.php') . '" class="btn btn-primary">
                    <i class="bi bi-arrow-right"></i> Zu den Evaluierungen
                </a>
            </div>
        </div>
    </div>

    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title">
                    <i class="bi bi-arrow-left-right"></i> Umbuchungen
                </h5>
                <p class="card-text">
                    Übersicht aller umgebuchten Karten je Veranstaltung (Sitz-/Datumswechsel)
                    mit Vorher/Nachher, Kunde und Bearbeiter. Hilft, alte Ausdrucke zu erkennen.
                </p>
                <a href="' . url('admin/reports_rebookings.php') . '" class="btn btn-primary">
                    <i class="bi bi-arrow-right"></i> Zu den Umbuchungen
                </a>
            </div>
        </div>
    </div>
</div>
';

require __DIR__ . '/../../app/views/layouts/main.php';

