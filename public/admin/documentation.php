<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;

Auth::requireAdmin();

$manualsDir = __DIR__ . '/../../docs/manuals/';

$documents = [
    [
        'file'        => 'ADMIN_HANDBUCH.pdf',
        'title'       => 'Admin-Handbuch',
        'description' => 'Funktionen des Admin-Bereichs: Veranstaltungen, Sitzplan, Reservierungen, Kunden, Gutscheine, Berichte, Einstellungen.',
        'icon'        => 'bi-shield-lock',
    ],
    [
        'file'        => 'VORVERKAUF_HANDBUCH.pdf',
        'title'       => 'Vorverkauf-Handbuch',
        'description' => 'Funktionen des Vorverkauf-Bereichs: Verkauf, Reservierungen, Kasse, Kunden, Gutscheine.',
        'icon'        => 'bi-cart-check',
    ],
];

$cards = '';
foreach ($documents as $doc) {
    $path = $manualsDir . $doc['file'];
    $available = is_readable($path);
    $size = $available ? round(filesize($path) / 1024) . ' KB' : '–';

    $viewUrl = url('api/doc_download.php?file=' . urlencode($doc['file']) . '&inline=1');
    $dlUrl   = url('api/doc_download.php?file=' . urlencode($doc['file']));

    $actions = $available
        ? '
            <a href="' . $viewUrl . '" target="_blank" rel="noopener" class="btn btn-primary btn-sm">
                <i class="bi bi-eye"></i> Anzeigen
            </a>
            <a href="' . $dlUrl . '" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-download"></i> Download
            </a>'
        : '<span class="badge bg-secondary">Datei fehlt</span>';

    $cards .= '
    <div class="col-md-6 mb-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                    <i class="bi ' . htmlspecialchars($doc['icon']) . ' fs-1 text-primary"></i>
                    <div class="flex-grow-1">
                        <h5 class="card-title mb-1">' . htmlspecialchars($doc['title']) . '</h5>
                        <div class="text-muted small mb-2">' . htmlspecialchars($doc['file']) . ' · ' . $size . '</div>
                        <p class="card-text small">' . htmlspecialchars($doc['description']) . '</p>
                        <div class="d-flex gap-2 flex-wrap">
                            ' . $actions . '
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>';
}

$pageTitle   = 'Dokumentation';
$showSidebar = true;
$showHeader  = true;

$content = '
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><i class="bi bi-book"></i> Dokumentation</h2>
</div>

<p class="text-muted">Handbücher zum Ticket-System als PDF.</p>

<div class="row">
    ' . $cards . '
</div>
';

require __DIR__ . '/../../app/views/layouts/main.php';
