<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\FileUpload;
use App\Models\Event;

Auth::requireAdmin();

$eventModel = new Event();
$eventId = (int)($_GET['id'] ?? 0);
$event = $eventModel->getById($eventId);

if (!$event) {
    header('Location: ' . url('admin/events.php'));
    exit;
}

$error = '';
$success = '';

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_template'])) {
    // Validate CSRF token
    if (!Csrf::validatePost()) {
        $error = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } elseif (isset($_FILES['pdf_template']) && $_FILES['pdf_template']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploadDir = __DIR__ . '/../../storage/ticket_templates/';
        $customFileName = 'event_' . $eventId . '_' . time();
        
        $uploadResult = FileUpload::upload($_FILES['pdf_template'], $uploadDir, 'pdf', $customFileName);
        
        if ($uploadResult['success']) {
            // Delete old template if exists
            if (!empty($event['ticket_pdf_template'])) {
                $oldPath = __DIR__ . '/../../' . $event['ticket_pdf_template'];
                FileUpload::delete($oldPath);
            }
            
            // Save relative path
            $relativePath = 'storage/ticket_templates/' . $uploadResult['filename'];
            $eventModel->update($eventId, ['ticket_pdf_template' => $relativePath]);
            $event = $eventModel->getById($eventId);
            $success = 'PDF-Template erfolgreich hochgeladen!';
        } else {
            $error = $uploadResult['error'] ?? 'Fehler beim Hochladen der Datei.';
        }
    } else {
        $error = 'Keine Datei wurde hochgeladen.';
    }
}

// Handle template config save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    // Validate CSRF token
    if (!Csrf::validatePost()) {
        $error = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $config = [
            'placeholders' => []
        ];
        
        if (isset($_POST['placeholders']) && is_array($_POST['placeholders'])) {
            foreach ($_POST['placeholders'] as $placeholder) {
                if (!empty($placeholder['name']) && !empty($placeholder['x']) && !empty($placeholder['y'])) {
                    $config['placeholders'][] = [
                        'name' => $placeholder['name'],
                        'x' => (float)$placeholder['x'],
                        'y' => (float)$placeholder['y'],
                        'font_size' => isset($placeholder['font_size']) ? (int)$placeholder['font_size'] : 12,
                        'font_family' => $placeholder['font_family'] ?? 'Helvetica',
                        'align' => $placeholder['align'] ?? 'L',
                        'color_r' => isset($placeholder['color_r']) ? (int)$placeholder['color_r'] : 0,
                        'color_g' => isset($placeholder['color_g']) ? (int)$placeholder['color_g'] : 0,
                        'color_b' => isset($placeholder['color_b']) ? (int)$placeholder['color_b'] : 0,
                        'bold' => isset($placeholder['bold']) && $placeholder['bold'] == '1' ? 1 : 0,
                        // Size of the {ticket_code} QR code / barcode in mm
                        'width' => isset($placeholder['width']) ? (float)$placeholder['width'] : 25,
                        'height' => isset($placeholder['height']) ? (float)$placeholder['height'] : 15
                    ];
                }
            }
        }
        
        $eventModel->update($eventId, ['ticket_template_config' => json_encode($config)]);
        $event = $eventModel->getById($eventId);
        $success = 'Konfiguration erfolgreich gespeichert!';
    }
}

// Load existing config
$config = [];
if (!empty($event['ticket_template_config'])) {
    $config = json_decode($event['ticket_template_config'], true) ?: [];
}

// Prepare JavaScript for existing placeholders
$existingPlaceholdersJs = '';
if (!empty($config['placeholders'])) {
    $placeholdersJson = json_encode($config['placeholders'], JSON_HEX_APOS | JSON_HEX_QUOT);
    $existingPlaceholdersJs = "\nconst existingPlaceholders = " . $placeholdersJson . ";\nexistingPlaceholders.forEach(function(placeholder, index) {\n    placeholderIndex = index;\n    addPlaceholder(placeholder);\n});";
}

// Prepare preview URL for JavaScript (outside of string to avoid quote issues)
$previewUrl = url('api/ticket_preview.php');

$pageTitle = 'Ticket-Template: ' . htmlspecialchars($event['name']);
$showSidebar = true;
$showHeader = true;

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Ticket-Template verwalten</h2>
    <a href="' . url('admin/events_edit.php?id=' . $eventId) . '" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> Zurück
    </a>
</div>

' . ($success ? '<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle"></i> ' . htmlspecialchars($success) . '
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>' : '') . '

' . ($error ? '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>' : '') . '

<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">PDF-Template hochladen</h5>
            </div>
            <div class="card-body">
                ' . ($event['ticket_pdf_template'] ? '
                <div class="alert alert-info mb-3">
                    <i class="bi bi-info-circle"></i> Aktuelles Template: ' . htmlspecialchars(basename($event['ticket_pdf_template'])) . '
                    <br><small>Hochgeladen am: ' . date('d.m.Y H:i', filemtime(__DIR__ . '/../../' . $event['ticket_pdf_template'])) . '</small>
                </div>
                ' : '') . '
                
                <form method="POST" enctype="multipart/form-data">
                    ' . \App\Core\Csrf::field() . '
                    <div class="mb-3">
                        <label for="pdf_template" class="form-label">PDF-Datei auswählen</label>
                        <input type="file" class="form-control" id="pdf_template" name="pdf_template" 
                               accept=".pdf" required>
                        <small class="form-text text-muted">Nur PDF-Dateien werden akzeptiert.</small>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload"></i> Hochladen
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Verfügbare Platzhalter</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small">Verwenden Sie diese Platzhalter in Ihrer Konfiguration:</p>
                <ul class="list-unstyled">
                    <li><code>{event_name}</code> - Name der Veranstaltung</li>
                    <li><code>{event_date}</code> - Datum der Veranstaltung</li>
                    <li><code>{event_time}</code> - Uhrzeit der Veranstaltung</li>
                    <li><code>{seat_identifier}</code> - Sitzbezeichnung (z.B. "Reihe 1, Platz 5")</li>
                    <li><code>{customer_name}</code> - Name des Kunden</li>
                    <li><code>{customer_email}</code> - E-Mail des Kunden</li>
                    <li><code>{ticket_number}</code> - Eindeutige Ticketnummer</li>
                    <li><code>{category_name}</code> - Name der Kategorie</li>
                    <li><code>{price}</code> - Preis</li>
                    <li><code>{price_type}</code> - Preisart: Ermäßigt, FN-Card, Generalprobe oder Begleitperson (leer bei Normalpreis)</li>
                    <li><code>{fn_card_number}</code> - FN-Card-Nummer (nur bei FN-Card-Tickets, sonst leer)</li>
                    <li><code>{ticket_code}</code> - QR-Code oder Strichcode; Art und Inhalt unter „Veranstaltung bearbeiten“, Größe über Breite/Höhe</li>
                    <li><code>{ticket_code_notext}</code> - wie <code>{ticket_code}</code>, aber Strichcode ohne Text darunter</li>
                    <li><code>{alt_seat_identifier}</code> - Alternativplatz (z.B. "Reihe 2, Platz 7")</li>
                    <li><code>{alt_row}</code> - Alternativplatz: nur Reihe</li>
                    <li><code>{alt_seat}</code> - Alternativplatz: nur Platz</li>
                    <li><code>{alt_location}</code> - Alternativer Veranstaltungsort</li>
                    <li><code>{alt_full}</code> - Ort + Reihe + Platz der Alternative</li>
                </ul>
                <p class="text-muted small mb-0">
                    Die Alternativplatz-Platzhalter bleiben leer, wenn für den Sitz keine
                    Alternativbelegung im Sitzplan-Editor hinterlegt ist.
                </p>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Platzhalter-Konfiguration</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="configForm">
                    ' . \App\Core\Csrf::field() . '
                    <input type="hidden" name="save_config" value="1">
                    <div id="placeholders-container">
                        ' . (empty($config['placeholders']) ? '
                        <p class="text-muted">Noch keine Platzhalter konfiguriert. Klicken Sie auf "Platzhalter hinzufügen".</p>
                        ' : '') . '
                    </div>
                    <button type="button" class="btn btn-outline-primary" onclick="addPlaceholder()">
                        <i class="bi bi-plus-circle"></i> Platzhalter hinzufügen
                    </button>
                    <button type="submit" class="btn btn-primary ms-2">
                        <i class="bi bi-save"></i> Konfiguration speichern
                    </button>
                    <button type="button" class="btn btn-outline-info ms-2" onclick="updatePreview()">
                        <i class="bi bi-arrow-clockwise"></i> Vorschau aktualisieren
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Live-Vorschau</h5>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="updatePreview()" title="Vorschau aktualisieren">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>
            <div class="card-body p-0">
                ' . ($event['ticket_pdf_template'] ? '
                <div id="preview-container" style="position: relative; min-height: 400px; background: #f0f0f0;">
                    <iframe id="preview-iframe" src="' . url('api/ticket_preview.php?event_id=' . $eventId) . '" 
                            style="width: 100%; height: 600px; border: none;"></iframe>
                </div>
                ' : '
                <div class="p-4 text-center text-muted">
                    <i class="bi bi-file-pdf" style="font-size: 3rem;"></i>
                    <p class="mt-3">Bitte laden Sie zuerst ein PDF-Template hoch, um die Vorschau zu sehen.</p>
                </div>
                ') . '
            </div>
        </div>
    </div>
</div>

<script>
let placeholderIndex = ' . (empty($config['placeholders']) ? 0 : count($config['placeholders'])) . ';
let eventId = ' . $eventId . ';
let previewTimeout = null;

function updatePreview() {
    if (!eventId) return;
    
    // Collect current placeholder configuration
    const placeholders = [];
    const placeholderElements = document.querySelectorAll(\'[id^="placeholder-"]\');
    
    placeholderElements.forEach(element => {
        const name = element.querySelector(\'select[name*="[name]"]\')?.value;
        const x = element.querySelector(\'input[name*="[x]"]\')?.value;
        const y = element.querySelector(\'input[name*="[y]"]\')?.value;
        const fontSize = element.querySelector(\'input[name*="[font_size]"]\')?.value || 12;
        const fontFamily = element.querySelector(\'select[name*="[font_family]"]\')?.value || "Helvetica";
        const align = element.querySelector(\'select[name*="[align]"]\')?.value || "L";
        const colorR = element.querySelector(\'input[name*="[color_r]"]\')?.value || 0;
        const colorG = element.querySelector(\'input[name*="[color_g]"]\')?.value || 0;
        const colorB = element.querySelector(\'input[name*="[color_b]"]\')?.value || 0;
        const bold = element.querySelector(\'input[name*="[bold]"]\')?.checked || false;
        const codeWidth = element.querySelector(\'input[name*="[width]"]\')?.value || 25;
        const codeHeight = element.querySelector(\'input[name*="[height]"]\')?.value || 15;
        
        if (name && x && y) {
            placeholders.push({
                name: name,
                x: parseFloat(x),
                y: parseFloat(y),
                font_size: parseInt(fontSize),
                font_family: fontFamily,
                align: align,
                color_r: parseInt(colorR),
                color_g: parseInt(colorG),
                color_b: parseInt(colorB),
                bold: bold ? 1 : 0,
                width: parseFloat(codeWidth),
                height: parseFloat(codeHeight)
            });
        }
    });
    
    const config = {
        placeholders: placeholders
    };
    
    // Update iframe source
    const iframe = document.getElementById(\'preview-iframe\');
    if (iframe) {
        const configJson = encodeURIComponent(JSON.stringify(config));
        const previewUrl = \'' . $previewUrl . '\';
        iframe.src = previewUrl + \'?event_id=\' + eventId + \'&config=\' + configJson;
    }
}

function debouncePreview() {
    clearTimeout(previewTimeout);
    previewTimeout = setTimeout(updatePreview, 500);
}

function updateColorPreview(index) {
    const element = document.getElementById("placeholder-" + index);
    if (!element) return;
    
    const r = element.querySelector(\'input[name*="[color_r]"]\')?.value || 0;
    const g = element.querySelector(\'input[name*="[color_g]"]\')?.value || 0;
    const b = element.querySelector(\'input[name*="[color_b]"]\')?.value || 0;
    
    const preview = element.querySelector(\'.color-preview\');
    if (preview) {
        preview.style.backgroundColor = "rgb(" + r + ", " + g + ", " + b + ")";
    }
    const picker = element.querySelector(".color-picker");
    if (picker) {
        picker.value = rgbToHex(r, g, b);
    }
}

// Hex value (#rrggbb) for the colour picker from 0-255 values
function rgbToHex(r, g, b) {
    return "#" + [r, g, b].map(function(v) {
        const n = Math.max(0, Math.min(255, parseInt(v, 10) || 0));
        return n.toString(16).padStart(2, "0");
    }).join("");
}

// Colour picker -> RGB fields (those are what gets saved)
function pickColor(index, hex) {
    const element = document.getElementById("placeholder-" + index);
    if (!element) return;
    ["color_r", "color_g", "color_b"].forEach(function(key, i) {
        const input = element.querySelector("input[name$=" + JSON.stringify("[" + key + "]") + "]");
        if (input) input.value = parseInt(hex.substr(1 + i * 2, 2), 16);
    });
    debouncePreview();
}

// Show the size fields only for the QR code / barcode placeholder
function toggleCodeSize(select) {
    select.closest(".card-body").querySelectorAll(".code-size").forEach(function (el) {
        el.style.display = select.value.indexOf("ticket_code") === 0 ? "block" : "none";
    });
}

function addPlaceholder(data = null) {
    const container = document.getElementById("placeholders-container");
    const colorR = data ? (data.color_r || 0) : 0;
    const colorG = data ? (data.color_g || 0) : 0;
    const colorB = data ? (data.color_b || 0) : 0;
    const placeholderHtml = 
        "<div class=\"card mb-3\" id=\"placeholder-" + placeholderIndex + "\">" +
        "<div class=\"card-body\">" +
        "<div class=\"d-flex justify-content-between align-items-center mb-3\">" +
        "<h6 class=\"mb-0\">Platzhalter " + (placeholderIndex + 1) + "</h6>" +
        "<button type=\"button\" class=\"btn btn-sm btn-outline-danger\" onclick=\"removePlaceholder(" + placeholderIndex + ")\">" +
        "<i class=\"bi bi-trash\"></i>" +
        "</button>" +
        "</div>" +
        "<div class=\"row\">" +
        "<div class=\"col-md-6 mb-3\">" +
        "<label class=\"form-label\">Platzhalter-Name</label>" +
        "<select class=\"form-select\" name=\"placeholders[" + placeholderIndex + "][name]\" required onchange=\"toggleCodeSize(this); debouncePreview()\">" +
        "<option value=\"\">Bitte wählen...</option>" +
        "<option value=\"event_name\"" + (data && data.name === "event_name" ? " selected" : "") + ">Veranstaltungsname</option>" +
        "<option value=\"event_date\"" + (data && data.name === "event_date" ? " selected" : "") + ">Datum</option>" +
        "<option value=\"event_time\"" + (data && data.name === "event_time" ? " selected" : "") + ">Uhrzeit</option>" +
        "<option value=\"seat_identifier\"" + (data && data.name === "seat_identifier" ? " selected" : "") + ">Sitzbezeichnung</option>" +
        "<option value=\"customer_name\"" + (data && data.name === "customer_name" ? " selected" : "") + ">Kundenname</option>" +
        "<option value=\"customer_email\"" + (data && data.name === "customer_email" ? " selected" : "") + ">E-Mail</option>" +
        "<option value=\"ticket_number\"" + (data && data.name === "ticket_number" ? " selected" : "") + ">Ticketnummer</option>" +
        "<option value=\"category_name\"" + (data && data.name === "category_name" ? " selected" : "") + ">Kategorie</option>" +
        "<option value=\"price\"" + (data && data.name === "price" ? " selected" : "") + ">Preis</option>" +
        "<option value=\"price_type\"" + (data && data.name === "price_type" ? " selected" : "") + ">Preisart (Ermäßigt, FN-Card, Generalprobe, Begleitperson)</option>" +
        "<option value=\"fn_card_number\"" + (data && data.name === "fn_card_number" ? " selected" : "") + ">FN-Card-Nummer (nur bei FN-Card-Tickets)</option>" +
        "<option value=\"ticket_code\"" + (data && data.name === "ticket_code" ? " selected" : "") + ">QR-Code / Strichcode (Art + Inhalt in der Veranstaltung)</option>" +
        "<option value=\"ticket_code_notext\"" + (data && data.name === "ticket_code_notext" ? " selected" : "") + ">Strichcode ohne Text darunter (sonst wie QR-Code / Strichcode)</option>" +
        "<option value=\"alt_seat_identifier\"" + (data && data.name === "alt_seat_identifier" ? " selected" : "") + ">Alternativplatz (Reihe + Platz)</option>" +
        "<option value=\"alt_row\"" + (data && data.name === "alt_row" ? " selected" : "") + ">Alternativplatz: Reihe</option>" +
        "<option value=\"alt_seat\"" + (data && data.name === "alt_seat" ? " selected" : "") + ">Alternativplatz: Platz</option>" +
        "<option value=\"alt_location\"" + (data && data.name === "alt_location" ? " selected" : "") + ">Alternativer Veranstaltungsort</option>" +
        "<option value=\"alt_full\"" + (data && data.name === "alt_full" ? " selected" : "") + ">Alternativplatz komplett (Ort + Reihe + Platz)</option>" +
        "</select>" +
        "</div>" +
        "<div class=\"col-md-3 mb-3\">" +
        "<label class=\"form-label\">X-Position (mm)</label>" +
        "<input type=\"number\" step=\"0.1\" class=\"form-control\" name=\"placeholders[" + placeholderIndex + "][x]\" " +
        "value=\"" + (data ? data.x : "") + "\" required oninput=\"debouncePreview()\">" +
        "</div>" +
        "<div class=\"col-md-3 mb-3\">" +
        "<label class=\"form-label\">Y-Position (mm)</label>" +
        "<input type=\"number\" step=\"0.1\" class=\"form-control\" name=\"placeholders[" + placeholderIndex + "][y]\" " +
        "value=\"" + (data ? data.y : "") + "\" required oninput=\"debouncePreview()\">" +
        "</div>" +
        "<div class=\"col-md-3 mb-3 code-size\" style=\"display:" + (data && (data.name === "ticket_code" || data.name === "ticket_code_notext") ? "block" : "none") + "\">" +
        "<label class=\"form-label\">Breite (mm)</label>" +
        "<input type=\"number\" step=\"0.5\" min=\"5\" class=\"form-control\" name=\"placeholders[" + placeholderIndex + "][width]\" " +
        "value=\"" + (data && data.width ? data.width : 25) + "\" oninput=\"debouncePreview()\">" +
        "</div>" +
        "<div class=\"col-md-3 mb-3 code-size\" style=\"display:" + (data && (data.name === "ticket_code" || data.name === "ticket_code_notext") ? "block" : "none") + "\">" +
        "<label class=\"form-label\">Höhe (mm)</label>" +
        "<input type=\"number\" step=\"0.5\" min=\"5\" class=\"form-control\" name=\"placeholders[" + placeholderIndex + "][height]\" " +
        "value=\"" + (data && data.height ? data.height : 15) + "\" oninput=\"debouncePreview()\">" +
        "</div>" +
        "<div class=\"col-md-6 mb-3 code-size small text-muted align-self-center\" style=\"display:" + (data && (data.name === "ticket_code" || data.name === "ticket_code_notext") ? "block" : "none") + "\">" +
        "X/Y = linke obere Ecke. QR-Code ist quadratisch (nur Breite zählt); beim Strichcode gilt die Schriftgröße für den Text darunter." +
        "</div>" +
        "<div class=\"col-md-4 mb-3\">" +
        "<label class=\"form-label\">Schriftgröße</label>" +
        "<input type=\"number\" class=\"form-control\" name=\"placeholders[" + placeholderIndex + "][font_size]\" " +
        "value=\"" + (data ? data.font_size : 12) + "\" oninput=\"debouncePreview()\">" +
        "</div>" +
        "<div class=\"col-md-4 mb-3\">" +
        "<label class=\"form-label\">Schriftart</label>" +
        "<select class=\"form-select\" name=\"placeholders[" + placeholderIndex + "][font_family]\" onchange=\"debouncePreview()\">" +
        "<option value=\"Helvetica\"" + (data && data.font_family === "Helvetica" ? " selected" : "") + ">Helvetica</option>" +
        "<option value=\"Times\"" + (data && data.font_family === "Times" ? " selected" : "") + ">Times</option>" +
        "<option value=\"Courier\"" + (data && data.font_family === "Courier" ? " selected" : "") + ">Courier</option>" +
        "</select>" +
        "</div>" +
        "<div class=\"col-md-4 mb-3\">" +
        "<label class=\"form-label\">Ausrichtung</label>" +
        "<select class=\"form-select\" name=\"placeholders[" + placeholderIndex + "][align]\" onchange=\"debouncePreview()\">" +
        "<option value=\"L\"" + (data && data.align === "L" ? " selected" : "") + ">Links</option>" +
        "<option value=\"C\"" + (data && data.align === "C" ? " selected" : "") + ">Zentriert</option>" +
        "<option value=\"R\"" + (data && data.align === "R" ? " selected" : "") + ">Rechts</option>" +
        "</select>" +
        "</div>" +
        "<div class=\"col-md-12 mb-3\">" +
        "<div class=\"form-check\">" +
        "<input class=\"form-check-input\" type=\"checkbox\" name=\"placeholders[" + placeholderIndex + "][bold]\" id=\"bold_" + placeholderIndex + "\" value=\"1\" " +
        (data && (data.bold == 1 || data.bold == "1" || data.bold === true) ? "checked" : "") + " onchange=\"debouncePreview()\">" +
        "<label class=\"form-check-label\" for=\"bold_" + placeholderIndex + "\">Fett (Bold)</label>" +
        "</div>" +
        "</div>" +
        "<div class=\"col-md-12 mb-3\">" +
        "<label class=\"form-label\">Schriftfarbe (RGB)</label>" +
        "<div class=\"row g-2\">" +
        "<div class=\"col-4\">" +
        "<label class=\"form-label small\">Rot (0-255)</label>" +
        "<input type=\"number\" min=\"0\" max=\"255\" class=\"form-control\" " +
        "name=\"placeholders[" + placeholderIndex + "][color_r]\" " +
        "value=\"" + (data ? (data.color_r || 0) : 0) + "\" " +
        "oninput=\"updateColorPreview(" + placeholderIndex + "); debouncePreview();\">" +
        "</div>" +
        "<div class=\"col-4\">" +
        "<label class=\"form-label small\">Grün (0-255)</label>" +
        "<input type=\"number\" min=\"0\" max=\"255\" class=\"form-control\" " +
        "name=\"placeholders[" + placeholderIndex + "][color_g]\" " +
        "value=\"" + (data ? (data.color_g || 0) : 0) + "\" " +
        "oninput=\"updateColorPreview(" + placeholderIndex + "); debouncePreview();\">" +
        "</div>" +
        "<div class=\"col-4\">" +
        "<label class=\"form-label small\">Blau (0-255)</label>" +
        "<input type=\"number\" min=\"0\" max=\"255\" class=\"form-control\" " +
        "name=\"placeholders[" + placeholderIndex + "][color_b]\" " +
        "value=\"" + (data ? (data.color_b || 0) : 0) + "\" " +
        "oninput=\"updateColorPreview(" + placeholderIndex + "); debouncePreview();\">" +
        "</div>" +
        "</div>" +
        "<div class=\"mt-2 d-flex align-items-center gap-2\">" +
        "<input type=\"color\" class=\"form-control form-control-color color-picker\" title=\"Farbe wählen\" " +
        "value=\"" + rgbToHex(colorR, colorG, colorB) + "\" oninput=\"pickColor(" + placeholderIndex + ", this.value)\">" +
        "<small class=\"text-muted\">Farbe wählen oder RGB-Werte eingeben</small>" +
        "</div>" +
        "</div>" +
        "</div>" +
        "</div>" +
        "</div>";
    container.insertAdjacentHTML("beforeend", placeholderHtml);
    // Update color preview for the newly added placeholder
    updateColorPreview(placeholderIndex);
    placeholderIndex++;
}

function removePlaceholder(index) {
    const element = document.getElementById("placeholder-" + index);
    if (element) {
        element.remove();
        debouncePreview();
    }
}
</script>
';

// Append existing placeholders JavaScript outside of the string (in a separate script tag)
if (!empty($existingPlaceholdersJs)) {
    $content .= '<script>' . $existingPlaceholdersJs . '</script>';
}

require __DIR__ . '/../../app/views/layouts/main.php';

