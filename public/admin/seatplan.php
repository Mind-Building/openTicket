<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Models\Event;
use App\Models\SeatPlan;
use App\Models\SeatCategory;

Auth::requireAdmin();

$eventModel = new Event();
$seatPlanModel = new SeatPlan();
$categoryModel = new SeatCategory();

$eventId = (int)($_GET['event_id'] ?? 0);
$events = $eventModel->getAll();

$pageTitle = 'Sitzplan Editor';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Sitzplan Editor</h2>
    <button type="button" class="btn btn-outline-secondary" id="toggle-menu-btn" onclick="toggleMenu()">
        <i class="bi bi-list"></i> Menü
    </button>
</div>

<div class="row">
    <div class="col-md-12 col-lg-3" id="sidebar-menu" style="display: none;">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Veranstaltung wählen</h5>
            </div>
            <div class="card-body">
                <form method="GET" action="">
                    <div class="mb-3">
                        <label for="event_id" class="form-label">Veranstaltung</label>
                        <select class="form-select" id="event_id" name="event_id" onchange="this.form.submit()">
                            <option value="">-- Bitte wählen --</option>';

foreach ($events as $event) {
    $selected = $eventId === (int)$event['id'] ? 'selected' : '';
    $content .= '<option value="' . $event['id'] . '" ' . $selected . '>' . htmlspecialchars($event['name']) . '</option>';
}

$content .= '
                        </select>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="card mt-3">
            <div class="card-header">
                <h5 class="mb-0">Aktionen</h5>
            </div>
            <div class="card-body">
                <a href="' . url('admin/seatplan_categories.php?event_id=' . $eventId) . '" class="btn btn-sm btn-primary w-100 mb-2">
                    <i class="bi bi-plus-circle"></i> Kategorien verwalten
                </a>
                <a href="' . url('admin/seatplan_import.php?event_id=' . $eventId) . '" class="btn btn-sm btn-outline-dark w-100 mb-2">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Excel-Import (Alternativplätze)
                </a>
            </div>
        </div>
    </div>
    
    <div class="col-12">
';

if ($eventId > 0) {
    $event = $eventModel->getById($eventId);
    $categories = $categoryModel->getByEvent($eventId);
    $seatPlan = $seatPlanModel->getSeatPlanStructure($eventId);

    // Check for confirmed reservations (status >= 2, includes status 7 = box-office umgewandelt)
    $db = \App\Core\Database::getInstance();
    $soldStmt = $db->query(
        "SELECT COUNT(*) as cnt FROM reservations WHERE event_id = :eid AND status_id >= 2",
        [':eid' => $eventId]
    );
    $soldTicketCount = (int)($soldStmt->fetch()['cnt'] ?? 0);
    
    // Kategorien oben anzeigen
    if (!empty($categories)) {
        $content .= '
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">Kategorien</h5>
            </div>
            <div class="card-body">
                <div class="row g-2">';
        foreach ($categories as $cat) {
            $colorStyle = $cat['color'] ? 'border-color: ' . htmlspecialchars($cat['color']) . '; border-width: 3px;' : '';
            $content .= '
                    <div class="col-auto">
                        <div class="d-flex align-items-center p-2 border rounded" style="' . $colorStyle . '">
                            <strong>' . htmlspecialchars($cat['sign']) . '</strong>
                            <span class="ms-2">' . htmlspecialchars($cat['name']) . '</span>
                            <span class="ms-2">' . number_format($cat['price'], 2, ',', '.') . ' €</span>
                        </div>
                    </div>';
        }
        $content .= '
                </div>
            </div>
        </div>';
    }
    
    $content .= '
        <div id="feedback-container" style="position: fixed; top: 70px; right: 20px; z-index: 9999; max-width: 400px;"></div>';

    if ($soldTicketCount > 0) {
        $content .= '
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div>
                <strong>Achtung:</strong> Für diese Veranstaltung wurden bereits <strong>' . $soldTicketCount . ' Ticket(s)</strong> verkauft oder reserviert.
                Änderungen am Sitzplan können bestehende Buchungen beschädigen.
                <button type="button" class="btn btn-sm btn-danger ms-3" onclick="saveSeatPlanForce()">
                    <i class="bi bi-exclamation-triangle"></i> Trotzdem speichern
                </button>
            </div>
        </div>';
    }

    $content .= '
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Sitzplan: ' . htmlspecialchars($event['name']) . '</h5>
                <div>
                    <div class="btn-group me-2" role="group">
                        <input type="radio" class="btn-check" name="numberingMode" id="numberingPerRow" value="perRow" checked>
                        <label class="btn btn-sm btn-outline-secondary" for="numberingPerRow" title="Nummerierung pro Reihe">Pro Reihe</label>
                        <input type="radio" class="btn-check" name="numberingMode" id="numberingGlobal" value="global">
                        <label class="btn btn-sm btn-outline-secondary" for="numberingGlobal" title="Nummerierung global aufsteigend">Global</label>
                    </div>
                    <button type="button" class="btn btn-sm btn-info me-2" onclick="addStage()" title="Bühne hinzufügen">
                        <i class="bi bi-music-note-beamed"></i> Bühne
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary me-2" onclick="mirrorPlanVertically()" title="Sitzplan vertikal spiegeln: Reihenfolge aller Reihen (inkl. Bühne) umdrehen">
                        <i class="bi bi-symmetry-horizontal"></i> Spiegeln
                    </button>
                    <button type="button" class="btn btn-sm btn-success me-2" onclick="addRow()">
                        <i class="bi bi-plus-circle"></i> Reihe hinzufügen
                    </button>
                    <a href="' . url('admin/seatplan_import.php?event_id=' . $eventId) . '" class="btn btn-sm btn-outline-dark me-2" title="Sitze und Alternativplätze aus Excel/CSV importieren">
                        <i class="bi bi-file-earmark-spreadsheet"></i> Excel-Import
                    </a>
                    <button type="button" class="btn btn-sm btn-primary" onclick="saveSeatPlan()">
                        <i class="bi bi-save"></i> Speichern
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div id="seatplan-editor-container" style="width: 100%; overflow: auto; padding: 1rem;">
                    <div id="seatplan-editor" data-event-id="' . $eventId . '" style="min-width: max-content;">
                        <p class="text-muted">Sitzplan-Editor wird geladen...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>';
} else {
    $content .= '
    </div>
        <div class="card">
            <div class="card-body text-center text-muted">
                <i class="bi bi-calendar-event" style="font-size: 3rem;"></i>
                <p class="mt-3">Bitte wählen Sie eine Veranstaltung aus, um den Sitzplan zu bearbeiten.</p>
            </div>
        </div>';
}

$content .= '
</div>
';

$additionalCss = '
<style>
.seatplan-row {
    border: 1px solid #dee2e6;
    border-radius: 0.5rem;
    padding: 1rem;
    margin-bottom: 1rem;
    background: #fff;
    width: max-content;
    min-width: 100%;
}
/* Wide rows scroll horizontally; the row header (name + buttons) stays in the visible area
   so the row menu is reachable at any scroll position. 100cqw = visible width of the editor. */
#seatplan-editor-container {
    container-type: inline-size;
}
.seatplan-row-header {
    position: sticky;
    left: calc(2rem + 1px); /* container padding + row border + row padding: no jump at scroll 0 */
    width: calc(100cqw - 2rem - 2px);
    flex-wrap: wrap;
    gap: 0.5rem;
    z-index: 2;
    background: #fff;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #dee2e6;
}
.seatplan-elements {
    display: flex;
    flex-wrap: nowrap;
    gap: 0.5rem;
    min-height: 50px;
    padding: 0.5rem;
    background: #f8f9fa;
    border-radius: 0.25rem;
    overflow-x: visible;
    width: max-content;
    min-width: 100%;
}
.seat-element, .table-element, .distance-element {
    cursor: pointer;
    user-select: none;
    transition: all 0.2s;
    padding: 0.25rem 0.4rem;
    border-radius: 0.2rem;
    font-size: 0.75rem;
    min-width: 28px;
    text-align: center;
    white-space: nowrap;
    border: 3px solid transparent;
}
.seat-element:hover, .table-element:hover, .distance-element:hover {
    transform: scale(1.1);
    z-index: 10;
    position: relative;
}
.element-actions {
    display: none;
    position: absolute;
    bottom: -40px;
    left: 0; /* opens to the right; flipped to the left by JS near the right edge (see mouseover handler) */
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 0.25rem;
    padding: 0.25rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    z-index: 1000;
    white-space: nowrap;
}
.element-wrapper {
    position: relative;
    display: inline-block;
    margin-bottom: 50px; /* Space for actions when visible */
}
.element-wrapper:hover .element-actions {
    display: block;
}
/* Drag & drop reordering */
.element-wrapper[draggable="true"] {
    cursor: grab;
}
.element-wrapper.dragging {
    opacity: 0.4;
}
.element-wrapper.dragging .element-actions {
    display: none;
}
.element-wrapper.drop-before {
    box-shadow: -5px 0 0 -1px #0d6efd;
}
.element-wrapper.drop-after {
    box-shadow: 5px 0 0 -1px #0d6efd;
}
/* For tables, only show actions when hovering over the table element itself, not the seats */
.table-wrapper {
    position: relative;
    display: inline-block;
}
.table-element-wrapper {
    position: relative;
    display: inline-block;
}
/* Hide actions by default for tables */
.element-wrapper:has(.table-wrapper) .element-actions {
    display: none;
}
/* Show actions when hovering over table-element-wrapper (the table badge itself) */
.element-wrapper:has(.table-element-wrapper:hover) .element-actions {
    display: block !important;
}
.table-with-seats {
    position: relative;
    z-index: 1;
}
.table-element {
    position: relative;
    cursor: pointer;
}
</style>
';

$additionalJs = '
<script>
// Toggle menu visibility
function toggleMenu() {
    const menu = document.getElementById("sidebar-menu");
    const btn = document.getElementById("toggle-menu-btn");
    if (menu.style.display === "none") {
        menu.style.display = "block";
        btn.innerHTML = "<i class=\"bi bi-x\"></i> Menü schließen";
    } else {
        menu.style.display = "none";
        btn.innerHTML = "<i class=\"bi bi-list\"></i> Menü";
    }
}

// Feedback-Funktion für UI-Meldungen
function showFeedback(message, type = "info") {
    const container = document.getElementById("feedback-container");
    if (!container) return;
    
    const alertClass = {
        "success": "alert-success",
        "error": "alert-danger",
        "warning": "alert-warning",
        "info": "alert-info"
    }[type] || "alert-info";
    
    const alertId = "alert-" + Date.now();
    const alertHtml = 
        "<div id=\"" + alertId + "\" class=\"alert " + alertClass + " alert-dismissible fade show\" role=\"alert\">" +
        message +
        "<button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\" aria-label=\"Close\"></button>" +
        "</div>";
    
    container.insertAdjacentHTML("beforeend", alertHtml);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        const alertElement = document.getElementById(alertId);
        if (alertElement) {
            const bsAlert = new bootstrap.Alert(alertElement);
            bsAlert.close();
        }
    }, 5000);
}

let seatPlanData = ' . json_encode($seatPlan ?? []) . ';
let categories = ' . json_encode($categories ?? []) . ';
let eventId = ' . ($eventId ?? 0) . ';
let numberingMode = localStorage.getItem("seatplan_numbering_mode") || "perRow";

// Initialize editor
document.addEventListener("DOMContentLoaded", function() {
    if (eventId > 0) {
        // Set numbering mode
        document.getElementById(numberingMode === "global" ? "numberingGlobal" : "numberingPerRow").checked = true;
        
        // Listen for numbering mode changes
        document.querySelectorAll(\'input[name="numberingMode"]\').forEach(radio => {
            radio.addEventListener("change", function() {
                numberingMode = this.value;
                localStorage.setItem("seatplan_numbering_mode", numberingMode);
            });
        });
        
        renderSeatPlan();
    }
});

// The editor scrolls inside a box that ends at the bottom of the window, so its horizontal
// scrollbar is always visible - even while working on a row in the middle of a large plan.
function fitEditorHeight() {
    const container = document.getElementById("seatplan-editor-container");
    if (!container) return;
    const top = container.getBoundingClientRect().top + window.scrollY;
    container.style.maxHeight = Math.max(400, window.innerHeight - top - 16) + "px";
}
document.addEventListener("DOMContentLoaded", fitEditorHeight);
window.addEventListener("resize", fitEditorHeight);

// Hover menu of a seat/table: opens to the right by default, but flips to the left when it
// would be cut off at the right edge of the scrolling editor container (last seats of a row).
document.addEventListener("mouseover", function(e) {
    const wrapper = e.target.closest(".element-wrapper");
    if (!wrapper) return;
    const menu = wrapper.querySelector(".element-actions");
    const container = document.getElementById("seatplan-editor-container");
    if (!menu || !container) return;
    menu.style.left = "0";
    menu.style.right = "auto";
    const menuRect = menu.getBoundingClientRect();
    if (menuRect.width === 0) return; // not shown (e.g. table seats area)
    const bounds = container.getBoundingClientRect();
    if (menuRect.right > bounds.right && wrapper.getBoundingClientRect().right - menuRect.width >= bounds.left) {
        menu.style.left = "auto";
        menu.style.right = "0";
    }
});

// --- Reorder elements within a row via drag & drop -----------------------
// Dropping on the left/right half of an element inserts before/after it; dropping on the
// empty area of the row moves the element to the end. Numbers are not changed.
let dragSource = null; // { row, index }

function clearDropMarkers() {
    document.querySelectorAll(".drop-before, .drop-after").forEach(el => el.classList.remove("drop-before", "drop-after"));
}

function dropTarget(e) {
    if (!dragSource) return null;
    const wrapper = e.target.closest(".element-wrapper[data-index]");
    const rowEl = e.target.closest(".seatplan-elements[data-row]");
    if (!rowEl || parseInt(rowEl.dataset.row, 10) !== dragSource.row) return null; // only within the same row
    if (!wrapper) return { index: seatPlanData[dragSource.row].content.length, wrapper: null, after: true };
    const rect = wrapper.getBoundingClientRect();
    const after = e.clientX > rect.left + rect.width / 2;
    const index = parseInt(wrapper.dataset.index, 10) + (after ? 1 : 0);
    return { index, wrapper, after };
}

document.addEventListener("dragstart", function(e) {
    const wrapper = e.target.closest && e.target.closest(".element-wrapper[data-index]");
    if (!wrapper) return;
    dragSource = { row: parseInt(wrapper.dataset.row, 10), index: parseInt(wrapper.dataset.index, 10) };
    wrapper.classList.add("dragging");
    e.dataTransfer.effectAllowed = "move";
    e.dataTransfer.setData("text/plain", ""); // required by Firefox to start a drag
});

document.addEventListener("dragover", function(e) {
    const target = dropTarget(e);
    if (!target) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = "move";
    clearDropMarkers();
    if (target.wrapper) target.wrapper.classList.add(target.after ? "drop-after" : "drop-before");
});

document.addEventListener("drop", function(e) {
    const target = dropTarget(e);
    clearDropMarkers();
    if (!target) return;
    e.preventDefault();
    const content = seatPlanData[dragSource.row].content;
    let to = target.index;
    if (to > dragSource.index) to--; // the removed element shifts everything after it
    if (to !== dragSource.index) {
        const [element] = content.splice(dragSource.index, 1);
        content.splice(to, 0, element);
        renderSeatPlan();
        showFeedback("Element verschoben. Bitte den Sitzplan speichern.", "success");
    }
    dragSource = null;
});

document.addEventListener("dragend", function() {
    clearDropMarkers();
    document.querySelectorAll(".element-wrapper.dragging").forEach(el => el.classList.remove("dragging"));
    dragSource = null;
});

// Get next number for element type (with numbering mode)
function getNextNumber(elementType, rowIndex = null) {
    // Wheelchair seats share the seat numbering pool – both are seats.
    // Distance with consumes_number=1 reserves a seat number too.
    const seatLike = (elementType === "seat" || elementType === "wheelchair");
    function matches(element) {
        const t = element.type;
        if (seatLike) {
            if (t === "seat" || t === "wheelchair") return true;
            if (t === "distance" && element.consumes_number) return true;
            return false;
        }
        return t === elementType;
    }

    if (numberingMode === "global") {
        // Global numbering: find highest number across all rows
        let maxNumber = 0;
        seatPlanData.forEach(row => {
            row.content.forEach(element => {
                if (matches(element) && element.no && element.no > maxNumber) {
                    maxNumber = element.no;
                }
            });
        });
        return maxNumber + 1;
    } else {
        // Per-row numbering: find highest number in current row
        if (rowIndex === null || !seatPlanData[rowIndex]) return 1;
        const row = seatPlanData[rowIndex];
        let maxNumber = 0;
        row.content.forEach(element => {
            if (matches(element) && element.no && element.no > maxNumber) {
                maxNumber = element.no;
            }
        });
        return maxNumber + 1;
    }
}

// Seat numbers for "count" seat-like elements inserted at position insertAt of a row.
// Numbers continue from the numbered neighbour before the insert position (1 at the start of
// the row), in the direction of the row (descending for mirrored rows), as long as they fit into
// the gap before the next numbered element and are unused. Returns null when they do not fit.
function seatNumbersForInsert(rowIndex, insertAt, count) {
    const content = seatPlanData[rowIndex].content;
    const numberOf = el => {
        const holdsNumber = el.type === "seat" || el.type === "wheelchair" || (el.type === "distance" && el.consumes_number);
        const no = parseInt(el.no, 10);
        return holdsNumber && no > 0 ? no : null;
    };

    const rowNumbers = content.map(numberOf).filter(n => n !== null);
    const descending = rowNumbers.length > 1 && rowNumbers[0] > rowNumbers[rowNumbers.length - 1];

    let prev = null;
    for (let i = insertAt - 1; i >= 0 && prev === null; i--) prev = numberOf(content[i]);
    let next = null;
    for (let i = insertAt; i < content.length && next === null; i++) next = numberOf(content[i]);

    // Numbers already taken (whole plan in global numbering mode)
    const used = new Set();
    const rows = numberingMode === "global" ? seatPlanData : [seatPlanData[rowIndex]];
    rows.forEach(r => r.content.forEach(el => { const n = numberOf(el); if (n !== null) used.add(n); }));

    const numbers = [];
    for (let k = 1; k <= count; k++) {
        if (!descending) {
            numbers.push((prev === null ? 0 : prev) + k);
        } else if (prev !== null) {
            numbers.push(prev - k);
        } else if (next !== null) {
            numbers.push(next + count - k + 1); // start of a mirrored row: highest number first
        } else {
            return null;
        }
    }
    const fits = numbers.every(n => n >= 1 && !used.has(n)
        && (next === null || (descending ? n > next : n < next)));
    return fits ? numbers : null;
}

function addRow() {
    if (!eventId) {
        showFeedback("Bitte wählen Sie zuerst eine Veranstaltung aus.", "warning");
        return;
    }
    
    // Find highest row number and collect existing numbers
    let maxRowNumber = 0;
    let existingNumbers = [];
    seatPlanData.forEach(row => {
        if (row.no !== null && row.no !== undefined) {
            existingNumbers.push(row.no);
            if (row.no > maxRowNumber) {
                maxRowNumber = row.no;
            }
        }
    });
    
    // Find gaps in numbering (for suggestion)
    let suggestedNumber = maxRowNumber + 1;
    for (let i = 1; i <= maxRowNumber; i++) {
        if (!existingNumbers.includes(i)) {
            suggestedNumber = i;
            break;
        }
    }
    
    const rowNumberInput = prompt("Reihennummer eingeben:\n(Hinweis: Verfügbare Lücken werden automatisch erkannt)", suggestedNumber);
    if (!rowNumberInput || rowNumberInput.trim() === "") {
        return;
    }
    
    const rowNumber = parseInt(rowNumberInput.trim());
    if (isNaN(rowNumber) || rowNumber < 0) {
        showFeedback("Bitte geben Sie eine gültige Reihennummer ein (0 oder größer).", "error");
        return;
    }
    
    // Check if number already exists (except for row 0 which is stage)
    if (rowNumber !== 0 && existingNumbers.includes(rowNumber)) {
        if (!confirm("Reihe " + rowNumber + " existiert bereits. Möchten Sie sie ersetzen?")) {
            return;
        }
        // Remove existing row with this number
        const existingIndex = seatPlanData.findIndex(row => row.no === rowNumber);
        if (existingIndex !== -1) {
            seatPlanData.splice(existingIndex, 1);
        }
    }
    
    const newRow = {
        id: null,
        type: "row",
        no: rowNumber,
        category: null,
        content: []
    };
    
    insertRowSorted(newRow);
    renderSeatPlan();

    showFeedback("Reihe " + rowNumber + " wurde hinzugefügt.", "success");
}

function addStage() {
    if (!eventId) {
        showFeedback("Bitte wählen Sie zuerst eine Veranstaltung aus.", "warning");
        return;
    }
    
    // Create a special row for the stage
    const stageRow = {
        id: null,
        type: "row",
        no: 0, // Stage is always row 0
        category: null,
        content: [{
            id: null,
            type: "stage",
            no: 1
        }]
    };
    
    // Check if stage already exists
    const existingStage = seatPlanData.find(row => row.no === 0);
    if (existingStage) {
        if (!confirm("Bühne existiert bereits. Möchten Sie sie ersetzen?")) {
            return;
        }
        const index = seatPlanData.indexOf(existingStage);
        seatPlanData[index] = stageRow;
    } else {
        insertRowSorted(stageRow);
    }
    
    renderSeatPlan();
}

function copyRow(rowIndex) {
    if (rowIndex < 0 || rowIndex >= seatPlanData.length) {
        showFeedback("Ungültige Reihe zum Kopieren.", "error");
        return;
    }
    
    const sourceRow = seatPlanData[rowIndex];
    
    // Find highest row number
    let maxRowNo = 0;
    seatPlanData.forEach(row => {
        if (row.no > maxRowNo) {
            maxRowNo = row.no;
        }
    });
    
    // Create deep copy of the row
    const newRow = {
        id: null, // New row, no ID yet
        no: maxRowNo + 1,
        category: sourceRow.category || null,
        content: []
    };
    
    // Deep copy all elements
    sourceRow.content.forEach(element => {
        if (element.type === "seat" || element.type === "wheelchair") {
            // Copy seat
            newRow.content.push({
                type: element.type,
                id: null,
                no: element.no,
                category: element.category || null
            });
        } else if (element.type === "table") {
            // Copy table with all seats
            const newTable = {
                type: "table",
                id: null,
                no: element.no,
                category: element.category || null,
                seats: {
                    front: [],
                    back: [],
                    left: [],
                    right: []
                }
            };
            
            // Copy front seats
            if (element.seats && element.seats.front) {
                element.seats.front.forEach(seat => {
                    newTable.seats.front.push({
                        number: seat.number,
                        category: seat.category || null
                    });
                });
            }
            
            // Copy back seats
            if (element.seats && element.seats.back) {
                element.seats.back.forEach(seat => {
                    newTable.seats.back.push({
                        number: seat.number,
                        category: seat.category || null
                    });
                });
            }
            
            // Copy left seats
            if (element.seats && element.seats.left) {
                element.seats.left.forEach(seat => {
                    newTable.seats.left.push({
                        number: seat.number,
                        category: seat.category || null
                    });
                });
            }
            
            // Copy right seats
            if (element.seats && element.seats.right) {
                element.seats.right.forEach(seat => {
                    newTable.seats.right.push({
                        number: seat.number,
                        category: seat.category || null
                    });
                });
            }
            
            newRow.content.push(newTable);
        } else if (element.type === "distance") {
            // Copy distance
            newRow.content.push({
                type: "distance",
                id: null,
                no: element.no,
                consumes_number: element.consumes_number ? 1 : 0,
                stairs: element.stairs ? 1 : 0
            });
        } else if (element.type === "stage") {
            // Don\'t copy stage elements
            // Stages should be unique
        }
    });
    
    insertRowSorted(newRow);
    
    // Re-render
    renderSeatPlan();
    
    showFeedback("Reihe " + sourceRow.no + " wurde kopiert als Reihe " + newRow.no + ".", "success");
}

// True when the plan is mirrored vertically (row numbers run downwards in the array)
function isPlanMirrored() {
    const numbers = seatPlanData.map(r => r.no);
    for (let i = 1; i < numbers.length; i++) {
        if (numbers[i] !== numbers[i - 1]) return numbers[i] < numbers[i - 1];
    }
    return false;
}

// Insert a row at the position matching its number, keeping the current (normal or mirrored) direction
function insertRowSorted(newRow) {
    const mirrored = isPlanMirrored();
    let index = seatPlanData.findIndex(r => mirrored ? r.no < newRow.no : r.no > newRow.no);
    if (index === -1) index = seatPlanData.length;
    seatPlanData.splice(index, 0, newRow);
}

// Reverse the order of all rows (incl. stage): e.g. stage at the bottom instead of at the top
function mirrorPlanVertically() {
    if (seatPlanData.length < 2) {
        showFeedback("Zum Spiegeln werden mindestens zwei Reihen benötigt.", "warning");
        return;
    }
    seatPlanData.reverse();
    renderSeatPlan();
    showFeedback("Reihenfolge der Reihen wurde umgedreht. Bitte den Sitzplan speichern.", "success");
}

// Reverse the elements of one row (seat 1 on the other side); tables swap their left/right seats
function mirrorRow(rowIndex) {
    const row = seatPlanData[rowIndex];
    if (!row || !Array.isArray(row.content) || row.content.length < 2) {
        showFeedback("Die Reihe hat zu wenige Elemente zum Spiegeln.", "warning");
        return;
    }
    row.content.reverse();
    row.content.forEach(element => {
        if (element.type === "table" && element.seats) {
            const left = element.seats.left;
            const right = element.seats.right;
            delete element.seats.left;
            delete element.seats.right;
            if (right) element.seats.left = right;
            if (left) element.seats.right = left;
        }
    });
    renderSeatPlan();
    const rowName = row.row_name || ("Reihe " + row.no);
    showFeedback(rowName + " wurde gespiegelt. Bitte den Sitzplan speichern.", "success");
}

// Put the seats of a row in seat-number order. Seats (and wheelchair seats) are re-ordered
// within the slots they occupy, so distances and tables keep their place. Rows numbered
// mostly descending (mirrored) are sorted descending.
function sortRowSeats(rowIndex) {
    const row = seatPlanData[rowIndex];
    if (!row || !Array.isArray(row.content)) return;
    const isSeat = el => (el.type === "seat" || el.type === "wheelchair") && parseInt(el.no, 10) > 0;
    const slots = [];
    row.content.forEach((el, i) => { if (isSeat(el)) slots.push(i); });
    if (slots.length < 2) {
        showFeedback("Die Reihe hat zu wenige Plätze zum Sortieren.", "warning");
        return;
    }
    const seats = slots.map(i => row.content[i]);
    let up = 0, down = 0;
    for (let i = 1; i < seats.length; i++) {
        const diff = parseInt(seats[i].no, 10) - parseInt(seats[i - 1].no, 10);
        if (diff > 0) up++; else if (diff < 0) down++;
    }
    const descending = down > up;
    seats.sort((a, b) => descending ? b.no - a.no : a.no - b.no);
    slots.forEach((slot, k) => { row.content[slot] = seats[k]; });
    renderSeatPlan();
    showFeedback((row.row_name || ("Reihe " + row.no)) + " wurde nach Platznummer sortiert. Bitte den Sitzplan speichern.", "success");
}

function deleteRow(rowIndex) {
    if (confirm("Möchten Sie diese Reihe wirklich löschen?")) {
        seatPlanData.splice(rowIndex, 1);
        renderSeatPlan();
    }
}

function categorySelectHtml(id) {
    let html = "<select class=\"form-select form-select-sm\" id=\"" + id + "\"><option value=\"\">Keine Kategorie</option>";
    categories.forEach(cat => {
        html += "<option value=\"" + cat.id + "\">" + escHtml(cat.name) + " (" + escHtml(cat.sign) + ")</option>";
    });
    return html + "</select>";
}

function categoryLabel(categoryId) {
    const cat = categories.find(c => c.id == categoryId);
    if (!cat) return "<span class=\"text-muted\">Keine</span>";
    return "<span class=\"badge\" style=\"background-color: " + escHtml(cat.color || "#0d6efd") + "\">" + escHtml(cat.sign) + "</span> " + escHtml(cat.name);
}

// Set the category for a freely chosen subset of the row seats (applied locally, persisted on save)
function openRowCategoryDialog(rowIndex) {
    const row = seatPlanData[rowIndex];
    if (!row) {
        showFeedback("Ungültige Reihe.", "error");
        return;
    }
    if (categories.length === 0) {
        showFeedback("Bitte erstellen Sie zuerst mindestens eine Kategorie.", "warning");
        return;
    }

    const seats = collectRowSeats(rowIndex);
    if (seats.length === 0) {
        showFeedback("In dieser Reihe gibt es keine Plätze.", "warning");
        return;
    }

    const rowDisplayName = row.row_name || row.display_name || ("Reihe " + row.no);

    let tableRows = "";
    seats.forEach((seat, i) => {
        tableRows +=
            "<tr>" +
            "<td style=\"width:40px;\"><input type=\"checkbox\" class=\"form-check-input cat-seat-check\" data-index=\"" + i + "\" checked></td>" +
            "<td>" + escHtml(seat.label) + "</td>" +
            "<td>" + categoryLabel(seat.ref.category) + "</td>" +
            "</tr>";
    });

    let filterOptions = "<option value=\"\">Alle Kategorien</option><option value=\"none\">Ohne Kategorie</option>";
    categories.forEach(cat => {
        filterOptions += "<option value=\"" + cat.id + "\">" + escHtml(cat.name) + " (" + escHtml(cat.sign) + ")</option>";
    });

    const modal = document.createElement("div");
    modal.className = "modal fade show";
    modal.style.display = "block";
    modal.style.backgroundColor = "rgba(0,0,0,0.5)";
    modal.innerHTML =
        "<div class=\"modal-dialog modal-lg modal-dialog-scrollable\">" +
        "<div class=\"modal-content\">" +
        "<div class=\"modal-header\">" +
        "<h5 class=\"modal-title\">Kategorie setzen &ndash; " + escHtml(rowDisplayName) + "</h5>" +
        "<button type=\"button\" class=\"btn-close\" data-close></button>" +
        "</div>" +
        "<div class=\"modal-body\">" +
        "<div class=\"card mb-3\"><div class=\"card-body py-2\">" +
        "<div class=\"row g-2 align-items-end\">" +
        "<div class=\"col-auto\"><button type=\"button\" class=\"btn btn-sm btn-outline-secondary\" id=\"catSelAll\">Alle</button> " +
        "<button type=\"button\" class=\"btn btn-sm btn-outline-secondary\" id=\"catSelNone\">Keine</button></div>" +
        "<div class=\"col-auto\"><label class=\"form-label mb-0 small\">Nur Kategorie</label>" +
        "<select class=\"form-select form-select-sm\" id=\"catSelFilter\">" + filterOptions + "</select></div>" +
        "<div class=\"col-auto\"><label class=\"form-label mb-0 small\">Von Pos.</label>" +
        "<input type=\"number\" class=\"form-control form-control-sm\" id=\"catSelFrom\" min=\"1\" max=\"" + seats.length + "\" value=\"1\" style=\"width:80px;\"></div>" +
        "<div class=\"col-auto\"><label class=\"form-label mb-0 small\">Bis Pos.</label>" +
        "<input type=\"number\" class=\"form-control form-control-sm\" id=\"catSelTo\" min=\"1\" max=\"" + seats.length + "\" value=\"" + seats.length + "\" style=\"width:80px;\"></div>" +
        "<div class=\"col-auto\"><button type=\"button\" class=\"btn btn-sm btn-outline-primary\" id=\"catSelRange\">Bereich auswählen</button></div>" +
        "</div>" +
        "<div class=\"form-text\">Position = Reihenfolge in der Liste unten (Tischplätze eingeschlossen). Shift-Klick auf eine Checkbox markiert alle Plätze dazwischen.</div>" +
        "</div></div>" +
        "<table class=\"table table-sm table-bordered align-middle mb-0\">" +
        "<thead><tr><th></th><th>Platz</th><th>Aktuelle Kategorie</th></tr></thead>" +
        "<tbody>" + tableRows + "</tbody>" +
        "</table>" +
        "</div>" +
        "<div class=\"modal-footer\">" +
        "<div class=\"me-auto d-flex align-items-center gap-2\"><label class=\"small text-nowrap\" for=\"catSelTarget\">Neue Kategorie:</label>" + categorySelectHtml("catSelTarget") + "</div>" +
        "<button type=\"button\" class=\"btn btn-secondary\" data-close>Abbrechen</button>" +
        "<button type=\"button\" class=\"btn btn-primary\" id=\"catSelApply\">Übernehmen (<span id=\"catSelCount\"></span>)</button>" +
        "</div>" +
        "</div></div>";

    document.body.appendChild(modal);

    const checks = Array.from(modal.querySelectorAll(".cat-seat-check"));
    const countEl = modal.querySelector("#catSelCount");
    const updateCount = () => {
        countEl.textContent = checks.filter(c => c.checked).length + " Plätze";
    };
    updateCount();

    let lastClicked = null;
    checks.forEach((check, i) => {
        check.addEventListener("click", (e) => {
            if (e.shiftKey && lastClicked !== null) {
                const [a, b] = [Math.min(lastClicked, i), Math.max(lastClicked, i)];
                for (let k = a; k <= b; k++) checks[k].checked = check.checked;
            }
            lastClicked = i;
            updateCount();
        });
    });

    modal.querySelector("#catSelAll").addEventListener("click", () => {
        checks.forEach(c => c.checked = true);
        updateCount();
    });
    modal.querySelector("#catSelNone").addEventListener("click", () => {
        checks.forEach(c => c.checked = false);
        updateCount();
    });
    modal.querySelector("#catSelFilter").addEventListener("change", (e) => {
        const value = e.target.value;
        checks.forEach((c, i) => {
            const current = seats[i].ref.category;
            if (value === "") c.checked = true;
            else if (value === "none") c.checked = !current;
            else c.checked = current == value;
        });
        updateCount();
    });
    modal.querySelector("#catSelRange").addEventListener("click", () => {
        const from = parseInt(modal.querySelector("#catSelFrom").value, 10);
        const to = parseInt(modal.querySelector("#catSelTo").value, 10);
        if (isNaN(from) || isNaN(to) || from < 1 || to < from) {
            showFeedback("Bitte einen gültigen Bereich angeben.", "warning");
            return;
        }
        checks.forEach((c, i) => c.checked = (i + 1 >= from && i + 1 <= to));
        updateCount();
    });

    const close = () => modal.remove();
    modal.querySelectorAll("[data-close]").forEach(btn => btn.addEventListener("click", close));

    modal.querySelector("#catSelApply").addEventListener("click", () => {
        const selected = checks.filter(c => c.checked);
        if (selected.length === 0) {
            showFeedback("Bitte mindestens einen Platz auswählen.", "warning");
            return;
        }
        const targetValue = modal.querySelector("#catSelTarget").value;
        const categoryId = targetValue ? parseInt(targetValue, 10) : null;
        selected.forEach(c => {
            seats[parseInt(c.dataset.index, 10)].ref.category = categoryId;
        });
        // Whole row changed: keep the row-level category in sync
        if (selected.length === seats.length) {
            row.category = categoryId;
        }
        close();
        renderSeatPlan();
        showFeedback("Kategorie für " + selected.length + " Plätze gesetzt. Bitte den Sitzplan speichern.", "success");
    });
}

// Add one or more seats, tables, distances or wheelchair seats to the end of a row
function addElement(rowIndex, elementType) {
    const row = seatPlanData[rowIndex];
    if (!row) return;

    const titles = {
        seat: "Plätze hinzufügen",
        wheelchair: "Rollstuhlplätze hinzufügen",
        table: "Tische hinzufügen",
        distance: "Abstände hinzufügen"
    };
    if (!titles[elementType]) return;

    let extraFields = "";
    if (elementType === "seat" || elementType === "wheelchair") {
        extraFields =
            "<div class=\"mb-3\"><label class=\"form-label\">Kategorie" +
            (elementType === "wheelchair" ? " (Preis des Rollstuhlplatzes)" : "") + "</label>" +
            categorySelectHtml("addElCategory") + "</div>";
    } else if (elementType === "table") {
        extraFields =
            "<label class=\"form-label\">Plätze pro Tisch</label>" +
            "<div class=\"row g-2 mb-3\">" +
            ["front:Vorne", "left:Links", "right:Rechts", "back:Hinten"].map(pair => {
                const [area, label] = pair.split(":");
                return "<div class=\"col-3\"><label class=\"form-label small mb-0\">" + label + "</label>" +
                    "<input type=\"number\" class=\"form-control form-control-sm\" id=\"addElSeats_" + area + "\" min=\"0\" max=\"50\" value=\"" +
                    (area === "front" || area === "back" ? 2 : 0) + "\"></div>";
            }).join("") +
            "</div>" +
            "<div class=\"mb-3\"><label class=\"form-label\">Kategorie der Tischplätze</label>" + categorySelectHtml("addElCategory") + "</div>";
    } else if (elementType === "distance") {
        extraFields =
            "<div class=\"mb-3\"><label class=\"form-label d-block\">Art</label>" +
            "<div class=\"form-check form-check-inline\"><input class=\"form-check-input\" type=\"radio\" name=\"addElKind\" id=\"addElKindGap\" value=\"gap\" checked>" +
            "<label class=\"form-check-label\" for=\"addElKindGap\">Abstand</label></div>" +
            "<div class=\"form-check form-check-inline\"><input class=\"form-check-input\" type=\"radio\" name=\"addElKind\" id=\"addElKindStairs\" value=\"stairs\">" +
            "<label class=\"form-check-label\" for=\"addElKindStairs\">Treppe <span class=\"text-muted small\">(weiß mit schwarzem Rahmen)</span></label></div>" +
            "</div>" +
            "<div class=\"form-check mb-3\">" +
            "<input class=\"form-check-input\" type=\"checkbox\" id=\"addElConsumes\">" +
            "<label class=\"form-check-label\" for=\"addElConsumes\">Abstand schluckt eine Sitznummer " +
            "<span class=\"text-muted small\">(nächster Sitz bekommt eine höhere Nr.)</span></label>" +
            "</div>";
    }

    // Insert position: end of the row (default), start, or right after a chosen element
    let positionOptions = "<option value=\"" + row.content.length + "\" selected>Am Ende der Reihe</option>";
    if (row.content.length > 0) {
        positionOptions += "<option value=\"0\">Am Anfang der Reihe</option>";
    }
    row.content.forEach((el, i) => {
        if (i === row.content.length - 1) return; // same as "Am Ende"
        let label = "";
        if (el.type === "seat" && el.no) label = "Platz " + el.no;
        else if (el.type === "wheelchair") label = "Rollstuhlplatz " + el.no;
        else if (el.type === "table") label = "Tisch " + el.no;
        else if (el.type === "distance" || el.type === "spacer") label = "Abstand an Position " + (i + 1);
        if (label) positionOptions += "<option value=\"" + (i + 1) + "\">nach " + escHtml(label) + "</option>";
    });

    const modal = document.createElement("div");
    modal.className = "modal fade show";
    modal.style.display = "block";
    modal.style.backgroundColor = "rgba(0,0,0,0.5)";
    modal.innerHTML =
        "<div class=\"modal-dialog\"><div class=\"modal-content\">" +
        "<div class=\"modal-header\"><h5 class=\"modal-title\">" + titles[elementType] + "</h5>" +
        "<button type=\"button\" class=\"btn-close\" data-close></button></div>" +
        "<div class=\"modal-body\">" +
        "<div class=\"mb-3\"><label class=\"form-label\">Anzahl</label>" +
        "<input type=\"number\" class=\"form-control\" id=\"addElCount\" min=\"1\" max=\"200\" value=\"1\"></div>" +
        "<div class=\"mb-3\"><label class=\"form-label\">Position</label>" +
        "<select class=\"form-select\" id=\"addElPosition\">" + positionOptions + "</select></div>" +
        extraFields +
        "</div>" +
        "<div class=\"modal-footer\">" +
        "<button type=\"button\" class=\"btn btn-secondary\" data-close>Abbrechen</button>" +
        "<button type=\"button\" class=\"btn btn-primary\" id=\"addElOk\">Hinzufügen</button>" +
        "</div></div></div>";
    document.body.appendChild(modal);

    const countInput = modal.querySelector("#addElCount");
    countInput.focus();
    countInput.select();

    const close = () => modal.remove();
    modal.querySelectorAll("[data-close]").forEach(btn => btn.addEventListener("click", close));

    const apply = () => {
        const count = parseInt(countInput.value, 10);
        if (isNaN(count) || count < 1 || count > 200) {
            showFeedback("Bitte eine Anzahl zwischen 1 und 200 eingeben.", "warning");
            return;
        }
        const categoryInput = modal.querySelector("#addElCategory");
        const category = categoryInput && categoryInput.value ? parseInt(categoryInput.value, 10) : null;

        let insertAt = parseInt(modal.querySelector("#addElPosition").value, 10);
        if (isNaN(insertAt)) insertAt = row.content.length;
        const insert = element => row.content.splice(insertAt++, 0, element);

        // Seat numbers follow the insert position (e.g. 1-6 before a row starting at 7)
        const consumes = elementType === "distance" && modal.querySelector("#addElConsumes").checked;
        const needsSeatNumber = elementType === "seat" || elementType === "wheelchair" || consumes;
        const insertNumbers = needsSeatNumber ? seatNumbersForInsert(rowIndex, insertAt, count) : null;
        const insertedInside = insertAt < row.content.length;
        const seatNumber = n => insertNumbers ? insertNumbers[n] : getNextNumber("seat", rowIndex);

        for (let n = 0; n < count; n++) {
            if (elementType === "seat" || elementType === "wheelchair") {
                insert({
                    id: null,
                    type: elementType,
                    no: seatNumber(n),
                    category: category
                });
            } else if (elementType === "table") {
                const tableElement = {
                    id: null,
                    type: "table",
                    no: getNextNumber("table", rowIndex),
                    seats: {} // Object, not array, to hold seats by area
                };
                let seatNumber = 1; // Continuous numbering across all areas
                ["left", "right", "back", "front"].forEach(area => {
                    const perArea = parseInt(modal.querySelector("#addElSeats_" + area).value, 10) || 0;
                    for (let i = 0; i < perArea; i++) {
                        tableElement.seats[area] = tableElement.seats[area] || [];
                        tableElement.seats[area].push({ number: seatNumber++, category: category });
                    }
                });
                insert(tableElement);
            } else if (elementType === "distance") {
                const consumesFlag = modal.querySelector("#addElConsumes").checked ? 1 : 0;
                insert({
                    id: null,
                    type: "distance",
                    consumes_number: consumesFlag,
                    stairs: modal.querySelector("#addElKindStairs").checked ? 1 : 0,
                    no: consumesFlag ? seatNumber(n) : (row.content.length + 1)
                });
            }
        }

        close();
        renderSeatPlan();
        if (needsSeatNumber && insertedInside && !insertNumbers) {
            showFeedback(count + " " + (count === 1 ? "Element" : "Elemente") + " hinzugefügt. Die Nummern passten nicht in die Lücke und wurden ans Ende der Nummerierung gesetzt – bitte ggf. über Bearbeiten anpassen.", "warning");
        } else {
            showFeedback(count + " " + (count === 1 ? "Element" : "Elemente") + " hinzugefügt.", "success");
        }
    };

    modal.querySelector("#addElOk").addEventListener("click", apply);
    countInput.addEventListener("keydown", (e) => {
        if (e.key === "Enter") apply();
    });
}

function deleteElement(rowIndex, elementIndex) {
    if (confirm("Möchten Sie dieses Element wirklich löschen?")) {
        seatPlanData[rowIndex].content.splice(elementIndex, 1);
        renderSeatPlan();
    }
}

function addTableSeat(rowIndex, elementIndex, area) {
    const element = seatPlanData[rowIndex].content[elementIndex];
    if (element.type !== "table") return;
    
    element.seats = element.seats || {};
    element.seats[area] = element.seats[area] || [];
    
    // Calculate next number based on total seats at table (not per area)
    let totalSeats = 0;
    ["left", "right", "back", "front"].forEach(a => {
        if (element.seats[a] && element.seats[a].length > 0) {
            totalSeats += element.seats[a].length;
        }
    });
    const nextNumber = totalSeats + 1;
    
    // Show category selection dialog
    showCategoryDialog((categoryId) => {
        element.seats[area].push({
            number: nextNumber,
            category: categoryId
        });
        renderSeatPlan();
    });
}

function deleteTableSeat(rowIndex, elementIndex, area, seatNumber) {
    if (!confirm("Möchten Sie diesen Platz wirklich löschen?")) {
        return;
    }
    
    const element = seatPlanData[rowIndex].content[elementIndex];
    if (element.type !== "table" || !element.seats || !element.seats[area]) {
        return;
    }
    
    // Remove seat from array
    element.seats[area] = element.seats[area].filter(s => s.number != seatNumber);
    
    // Renumber remaining seats to be continuous (1, 2, 3, ...)
    let seatCounter = 1;
    ["left", "right", "back", "front"].forEach(a => {
        if (element.seats[a] && element.seats[a].length > 0) {
            element.seats[a].forEach(seat => {
                seat.number = seatCounter++;
            });
        }
    });
    
    // If area is empty, remove it
    if (element.seats[area].length === 0) {
        delete element.seats[area];
    }
    
    // If no seats left at all, remove seats object
    if (Object.keys(element.seats).length === 0) {
        delete element.seats;
    }
    
    renderSeatPlan();
}

function showCategoryDialog(callback, currentCategoryId = null) {
    // Create modal for category selection
    const modal = document.createElement("div");
    modal.className = "modal fade show";
    modal.style.display = "block";
    modal.style.backgroundColor = "rgba(0,0,0,0.5)";
    const categoryOptions = categories.map(cat => "<option value=\"" + cat.id + "\"" + (cat.id == currentCategoryId ? " selected" : "") + ">" + escHtml(cat.name) + " (" + escHtml(cat.sign) + ")</option>").join("");
    modal.innerHTML = "<div class=\"modal-dialog\"><div class=\"modal-content\"><div class=\"modal-header\"><h5 class=\"modal-title\">Kategorie wählen</h5><button type=\"button\" class=\"btn-close\" id=\"categorySelectClose\"></button></div><div class=\"modal-body\"><select id=\"categorySelect\" class=\"form-select\"><option value=\"\">Keine Kategorie</option>" + categoryOptions + "</select></div><div class=\"modal-footer\"><button type=\"button\" class=\"btn btn-secondary\" id=\"categorySelectCancel\">Abbrechen</button><button type=\"button\" class=\"btn btn-primary\" id=\"categorySelectOk\">OK</button></div></div></div>";
    document.body.appendChild(modal);
    
    // Attach event handlers
    document.getElementById("categorySelectClose").addEventListener("click", function() {
        modal.remove();
    });
    
    document.getElementById("categorySelectCancel").addEventListener("click", function() {
        modal.remove();
    });
    
    document.getElementById("categorySelectOk").addEventListener("click", function() {
        const select = document.getElementById("categorySelect");
        const categoryId = select.value ? parseInt(select.value) : null;
        modal.remove();
        callback(categoryId);
    });
}

// One dialog for seat number and category, prefilled with the current values
function editSeatDialog(rowIndex, element) {
    const label = element.type === "wheelchair" ? "Rollstuhlplatz" : "Platz";
    const modal = document.createElement("div");
    modal.className = "modal fade show";
    modal.style.display = "block";
    modal.style.backgroundColor = "rgba(0,0,0,0.5)";
    modal.innerHTML =
        "<div class=\"modal-dialog\"><div class=\"modal-content\">" +
        "<div class=\"modal-header\"><h5 class=\"modal-title\">" + label + " " + escHtml(element.no) + " bearbeiten</h5>" +
        "<button type=\"button\" class=\"btn-close\" data-close></button></div>" +
        "<div class=\"modal-body\">" +
        "<div class=\"mb-3\"><label class=\"form-label\">Platznummer</label>" +
        "<input type=\"number\" min=\"1\" class=\"form-control\" id=\"editSeatNo\" value=\"" + escHtml(element.no) + "\"></div>" +
        "<div class=\"mb-3\"><label class=\"form-label\">Kategorie</label>" + categorySelectHtml("editSeatCategory") + "</div>" +
        "</div>" +
        "<div class=\"modal-footer\">" +
        "<button type=\"button\" class=\"btn btn-secondary\" data-close>Abbrechen</button>" +
        "<button type=\"button\" class=\"btn btn-primary\" id=\"editSeatOk\">Übernehmen</button>" +
        "</div></div></div>";
    document.body.appendChild(modal);

    modal.querySelector("#editSeatCategory").value = element.category ? String(element.category) : "";
    const numberInput = modal.querySelector("#editSeatNo");
    numberInput.focus();
    numberInput.select();

    const close = () => modal.remove();
    modal.querySelectorAll("[data-close]").forEach(btn => btn.addEventListener("click", close));

    const apply = () => {
        const number = parseInt(numberInput.value, 10);
        if (isNaN(number) || number < 1) {
            showFeedback("Bitte eine gültige Platznummer eingeben.", "warning");
            return;
        }
        const duplicate = seatPlanData[rowIndex].content.some(el => el !== element
            && (el.type === "seat" || el.type === "wheelchair") && parseInt(el.no, 10) === number);
        if (duplicate && !confirm("Platz " + number + " gibt es in dieser Reihe bereits. Trotzdem verwenden?")) return;
        const categoryValue = modal.querySelector("#editSeatCategory").value;
        element.no = number;
        element.category = categoryValue ? parseInt(categoryValue, 10) : null;
        close();
        renderSeatPlan();
    };
    modal.querySelector("#editSeatOk").addEventListener("click", apply);
    numberInput.addEventListener("keydown", e => { if (e.key === "Enter") apply(); });
}

function editElement(rowIndex, elementIndex) {
    const element = seatPlanData[rowIndex].content[elementIndex];
    
    if (element.type === "seat" || element.type === "wheelchair") {
        editSeatDialog(rowIndex, element);
        return;
    } else if (element.type === "distance" || element.type === "spacer") {
        const toStairs = !element.stairs;
        if (!confirm(toStairs ? "Diesen Abstand als Treppe markieren?" : "Diese Treppe wieder als normalen Abstand darstellen?")) return;
        element.stairs = toStairs ? 1 : 0;
        renderSeatPlan();
        return;
    } else if (element.type === "table") {
        const newNumber = prompt("Neue Tischnummer:", element.no);
        if (newNumber && !isNaN(newNumber)) {
            element.no = parseInt(newNumber);
        }
        
        // Edit table seats - show dialog for managing seats
        if (element.seats) {
            let seatInfo = "Aktuelle Plätze:\n";
            ["left", "right", "back", "front"].forEach(area => {
                if (element.seats[area] && element.seats[area].length > 0) {
                    seatInfo += `${area}: ${element.seats[area].map(s => s.number).join(", ")}\n`;
                }
            });
            seatInfo += "\nAktion:\n1 = Platz bearbeiten\n2 = Platz löschen";
            const action = prompt(seatInfo, "1");
            
            if (action === "2") {
                const area = prompt("Bereich (left, right, back, front):", "left");
                if (["left", "right", "back", "front"].includes(area) && element.seats[area]) {
                    const seatNumber = prompt("Platznummer zum Löschen:", "1");
                    if (seatNumber && !isNaN(seatNumber)) {
                        element.seats[area] = element.seats[area].filter(s => s.number != parseInt(seatNumber));
                        if (element.seats[area].length === 0) {
                            delete element.seats[area];
                        }
                    }
                }
            } else if (action === "1") {
                const area = prompt("Bereich (left, right, back, front):", "left");
                if (["left", "right", "back", "front"].includes(area) && element.seats[area]) {
                    const seatNumber = prompt("Platznummer zum Bearbeiten:", "1");
                    if (seatNumber && !isNaN(seatNumber)) {
                        const seat = element.seats[area].find(s => s.number == parseInt(seatNumber));
                        if (seat) {
                            showCategoryDialog((categoryId) => {
                                seat.category = categoryId;
                                renderSeatPlan();
                            }, seat.category);
                        }
                    }
                }
            }
        }
    }
    
    renderSeatPlan();
}

function convertElementType(rowIndex, elementIndex) {
    const element = seatPlanData[rowIndex].content[elementIndex];
    if (element.type === "seat") {
        if (!confirm("Sitz Nr. " + element.no + " in Abstand umwandeln?")) return;
        element.type = "distance";
        delete element.category;
        element.stairs = 0;
    } else if (element.type === "distance" || element.type === "spacer") {
        const row = seatPlanData[rowIndex];
        // Only a number-consuming distance owns a real seat number; otherwise its "no" is
        // just a position (or missing after copyRow) and the seat would be skipped/unsaved.
        let suggested = element.consumes_number ? element.no : null;
        let neighbourCategory = null;
        for (let i = elementIndex - 1; i >= 0; i--) {
            const prev = row.content[i];
            if ((prev.type === "seat" || prev.type === "wheelchair") && prev.no) {
                if (suggested === null) suggested = parseInt(prev.no, 10) + 1;
                neighbourCategory = prev.category || null;
                break;
            }
        }
        if (suggested === null) suggested = 1;

        const input = prompt("Abstand in Sitz umwandeln.\nPlatznummer für den neuen Sitz:", suggested);
        if (input === null) return;
        const number = parseInt(input, 10);
        if (isNaN(number) || number < 1) {
            showFeedback("Bitte eine gültige Platznummer eingeben.", "error");
            return;
        }
        const duplicate = row.content.some((el, i) => i !== elementIndex
            && (el.type === "seat" || el.type === "wheelchair") && parseInt(el.no, 10) === number);
        if (duplicate && !confirm("Platz " + number + " gibt es in dieser Reihe bereits. Trotzdem verwenden?")) return;

        element.type = "seat";
        element.no = number;
        element.category = neighbourCategory;
        delete element.consumes_number;
        delete element.width;
    }
    renderSeatPlan();
    showFeedback("Element wurde umgewandelt.", "success");
}

function editRowName(rowIndex, rowId) {
    const row = seatPlanData[rowIndex];
    if (!row) {
        showFeedback("Ungültige Reihe.", "error");
        return;
    }
    
    const currentName = row.row_name || "";
    const newName = prompt("Reihenname eingeben (leer lassen für Standard \'Reihe X\'):", currentName);
    
    if (newName === null) {
        return; // User cancelled
    }
    
    // Update in data
    if (newName.trim() === "") {
        row.row_name = null;
    } else {
        row.row_name = newName.trim();
    }
    
    // Update display_name
    row.display_name = row.row_name || ("Reihe " + row.no);
    
    // Re-render
    renderSeatPlan();
    
    showFeedback("Reihenname wurde aktualisiert.", "success");
}

function saveSeatPlan(force = false) {
    if (!eventId) {
        showFeedback("Bitte wählen Sie zuerst eine Veranstaltung aus.", "warning");
        return;
    }

    if (categories.length === 0) {
        showFeedback("Bitte erstellen Sie zuerst mindestens eine Kategorie.", "warning");
        return;
    }

    // closest(): a click on the icon would otherwise put the spinner inside the <i>
    const saveButton = event.target.closest("button");
    const originalButtonHtml = saveButton.innerHTML;
    saveButton.disabled = true;
    saveButton.innerHTML = \'<span class="spinner-border spinner-border-sm"></span> Speichere...\';

    const data = {
        event_id: eventId,
        rows: seatPlanData,
        force: force
    };

    fetch(\'' . url('admin/seatplan_save.php') . '\', {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": "' . \App\Core\Csrf::token() . '"
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            showFeedback("Sitzplan erfolgreich gespeichert!", "success");
            // Reload page to get updated IDs after a short delay
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else if (result.sold_tickets) {
            showFeedback(result.error, "error");
        } else {
            showFeedback("Fehler beim Speichern: " + (result.error || "Unbekannter Fehler"), "error");
        }
    })
    .catch(error => {
        showFeedback("Fehler beim Speichern: " + error.message, "error");
    })
    .finally(() => {
        saveButton.disabled = false;
        saveButton.innerHTML = originalButtonHtml;
    });
}

function saveSeatPlanForce() {
    if (!confirm("Wirklich speichern? Bestehende Buchungen könnten ungültig werden!")) {
        return;
    }
    saveSeatPlan(true);
}

// --- Alternativbelegung (Ausweichspielstätte) ---------------------------

function escHtml(value) {
    return String(value === null || typeof value === "undefined" ? "" : value)
        .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;").replace(/\'/g, "&#39;");
}

function hasAlt(seat) {
    return !!(seat && ((seat.alt_row && String(seat.alt_row).trim() !== "") || (seat.alt_seat && String(seat.alt_seat).trim() !== "")));
}

// Compact marker shown on the seat badge, e.g. "1/13"
function altShort(seat) {
    if (!hasAlt(seat)) return "";
    const r = seat.alt_row ? String(seat.alt_row).trim() : "?";
    const s = seat.alt_seat ? String(seat.alt_seat).trim() : "?";
    return " <span style=\"font-size:0.7em; opacity:0.9;\">&#8594;" + escHtml(r + "/" + s) + "</span>";
}

function altTitle(seat) {
    if (!hasAlt(seat)) return "";
    const r = seat.alt_row ? String(seat.alt_row).trim() : "";
    const s = seat.alt_seat ? String(seat.alt_seat).trim() : "";
    const parts = [];
    if (r) parts.push(isNaN(r) ? r : "Reihe " + r);
    if (s) parts.push(isNaN(s) ? s : "Platz " + s);
    return "Alternativ: " + escHtml(parts.join(", "));
}

// Collect every bookable seat of a row (regular, wheelchair and table seats), sorted by the
// original seat number: regular/wheelchair seats first, then table seats by table and seat.
// The returned "ref" objects are live references into seatPlanData.
function collectRowSeats(rowIndex) {
    const row = seatPlanData[rowIndex];
    const seats = [];
    if (!row || !Array.isArray(row.content)) return seats;

    row.content.forEach((element, elementIndex) => {
        if (element.type === "seat" || element.type === "wheelchair") {
            if (element.no === null || element.no === "" || typeof element.no === "undefined") return;
            seats.push({
                label: (element.type === "wheelchair" ? "Rollstuhlplatz " : "Platz ") + element.no,
                sortKey: [0, parseInt(element.no, 10) || 0, 0],
                elementIndex: elementIndex,
                ref: element
            });
        } else if (element.type === "table" && element.seats) {
            ["front", "left", "right", "back"].forEach(area => {
                (element.seats[area] || []).forEach(seat => {
                    if (!seat.number) return;
                    seats.push({
                        label: "Tisch " + element.no + ", Platz " + seat.number,
                        sortKey: [1, parseInt(element.no, 10) || 0, parseInt(seat.number, 10) || 0],
                        elementIndex: elementIndex,
                        ref: seat
                    });
                });
            });
        }
    });

    seats.sort((a, b) => a.sortKey[0] - b.sortKey[0] || a.sortKey[1] - b.sortKey[1] || a.sortKey[2] - b.sortKey[2]);
    return seats;
}

function openAltDialog(rowIndex, focusElementIndex) {
    const row = seatPlanData[rowIndex];
    if (!row) {
        showFeedback("Ungültige Reihe.", "error");
        return;
    }

    const seats = collectRowSeats(rowIndex);
    if (seats.length === 0) {
        showFeedback("In dieser Reihe gibt es keine Plätze.", "warning");
        return;
    }

    const rowDisplayName = row.row_name || row.display_name || ("Reihe " + row.no);

    let tableRows = "";
    seats.forEach((seat, i) => {
        const highlight = (typeof focusElementIndex === "number" && seat.elementIndex === focusElementIndex)
            ? " table-warning" : "";
        tableRows +=
            "<tr class=\"" + highlight + "\">" +
            "<td class=\"align-middle\">" + escHtml(seat.label) + "</td>" +
            "<td><input type=\"text\" class=\"form-control form-control-sm alt-row-input\" data-index=\"" + i + "\" " +
                "value=\"" + escHtml(seat.ref.alt_row || "") + "\" placeholder=\"z. B. 1\"></td>" +
            "<td><input type=\"text\" class=\"form-control form-control-sm alt-seat-input\" data-index=\"" + i + "\" " +
                "value=\"" + escHtml(seat.ref.alt_seat || "") + "\" placeholder=\"z. B. 13\"></td>" +
            "</tr>";
    });

    const modal = document.createElement("div");
    modal.className = "modal fade show";
    modal.style.display = "block";
    modal.style.backgroundColor = "rgba(0,0,0,0.5)";
    modal.innerHTML =
        "<div class=\"modal-dialog modal-lg modal-dialog-scrollable\">" +
        "<div class=\"modal-content\">" +
        "<div class=\"modal-header\">" +
        "<h5 class=\"modal-title\">Alternativbelegung &ndash; " + escHtml(rowDisplayName) + "</h5>" +
        "<button type=\"button\" class=\"btn-close\" id=\"altDialogClose\"></button>" +
        "</div>" +
        "<div class=\"modal-body\">" +
        "<p class=\"text-muted small\">Zusätzliche Reihe/Platz-Nummer in der Ausweichspielstätte. " +
        "Leer lassen, wenn ein Platz keine Alternative hat.</p>" +
        "<div class=\"card mb-3\"><div class=\"card-body py-2\">" +
        "<div class=\"row g-2 align-items-end\">" +
        "<div class=\"col-auto\"><label class=\"form-label mb-0 small\">Alt-Reihe</label>" +
        "<input type=\"text\" class=\"form-control form-control-sm\" id=\"altFillRow\" style=\"width:110px;\"></div>" +
        "<div class=\"col-auto\"><label class=\"form-label mb-0 small\">Erster Alt-Platz</label>" +
        "<input type=\"number\" class=\"form-control form-control-sm\" id=\"altFillStart\" value=\"1\" style=\"width:110px;\"></div>" +
        "<div class=\"col-auto\"><label class=\"form-label mb-0 small\">Schrittweite</label>" +
        "<input type=\"number\" class=\"form-control form-control-sm\" id=\"altFillStep\" value=\"1\" style=\"width:100px;\"></div>" +
        "<div class=\"col-auto\"><button type=\"button\" class=\"btn btn-sm btn-outline-primary\" id=\"altFillApply\">" +
        "<i class=\"bi bi-magic\"></i> Fortlaufend ausfüllen</button></div>" +
        "<div class=\"col-auto\"><button type=\"button\" class=\"btn btn-sm btn-outline-secondary\" id=\"altFillClear\">" +
        "<i class=\"bi bi-eraser\"></i> Reihe leeren</button></div>" +
        "</div>" +
        "</div></div>" +
        "<table class=\"table table-sm table-bordered align-middle mb-0\">" +
        "<thead><tr><th style=\"width:40%;\">Originalplatz</th><th>Alt-Reihe</th><th>Alt-Platz</th></tr></thead>" +
        "<tbody>" + tableRows + "</tbody>" +
        "</table>" +
        "</div>" +
        "<div class=\"modal-footer\">" +
        "<button type=\"button\" class=\"btn btn-secondary\" id=\"altDialogCancel\">Abbrechen</button>" +
        "<button type=\"button\" class=\"btn btn-primary\" id=\"altDialogOk\">Übernehmen</button>" +
        "</div>" +
        "</div></div>";

    document.body.appendChild(modal);

    const rowInputs = modal.querySelectorAll(".alt-row-input");
    const seatInputs = modal.querySelectorAll(".alt-seat-input");

    modal.querySelector("#altFillApply").addEventListener("click", () => {
        const fillRow = modal.querySelector("#altFillRow").value.trim();
        const start = parseInt(modal.querySelector("#altFillStart").value, 10);
        const step = parseInt(modal.querySelector("#altFillStep").value, 10);
        if (isNaN(start) || isNaN(step)) {
            showFeedback("Bitte gültige Zahlen für Startplatz und Schrittweite eingeben.", "warning");
            return;
        }
        let current = start;
        for (let i = 0; i < seats.length; i++) {
            if (fillRow !== "") rowInputs[i].value = fillRow;
            seatInputs[i].value = current;
            current += step;
        }
    });

    modal.querySelector("#altFillClear").addEventListener("click", () => {
        for (let i = 0; i < seats.length; i++) {
            rowInputs[i].value = "";
            seatInputs[i].value = "";
        }
    });

    const close = () => modal.remove();
    modal.querySelector("#altDialogClose").addEventListener("click", close);
    modal.querySelector("#altDialogCancel").addEventListener("click", close);

    modal.querySelector("#altDialogOk").addEventListener("click", () => {
        seats.forEach((seat, i) => {
            const altRow = rowInputs[i].value.trim();
            const altSeat = seatInputs[i].value.trim();
            seat.ref.alt_row = altRow === "" ? null : altRow;
            seat.ref.alt_seat = altSeat === "" ? null : altSeat;
        });
        close();
        renderSeatPlan();
        showFeedback("Alternativbelegung übernommen. Bitte den Sitzplan speichern.", "success");
    });
}

function renderSeatPlan() {
    const editor = document.getElementById("seatplan-editor");
    if (!editor) return;
    
    // Ensure editor container has proper width
    const editorContainer = document.getElementById("seatplan-editor-container");
    if (editorContainer) {
        editorContainer.style.width = "100%";
    }
    
    if (seatPlanData.length === 0) {
        editor.innerHTML = \'<p class="text-muted text-center">Noch keine Reihen vorhanden. Klicken Sie auf "Reihe hinzufügen" um zu beginnen.</p>\';
        return;
    }
    
    // Array order is the display order (saved as display_order) - no re-sorting here,
    // otherwise a vertically mirrored plan would flip back on every render.

    let html = "";
    
    seatPlanData.forEach((row, rowIndex) => {
        const category = categories.find(c => c.id == row.category);
        const categoryColor = category ? (category.color || "#6c757d") : "#6c757d";
        const categorySign = category ? category.sign : "";
        
        html += `<div class="seatplan-row">`;
        html += `<div class="seatplan-row-header">`;
        html += `<div>`;
        const rowDisplayName = row.row_name || row.display_name || (row.no === 0 ? "Bühne" : "Reihe " + row.no);
        html += "<strong>" + rowDisplayName + "</strong>";
        if (categorySign) {
            html += `<span class="badge ms-2" style="background-color: ${categoryColor}">${categorySign}</span>`;
        }
        html += `</div>`;
        html += `<div>`;
        if (row.no !== 0) {
            html += `<button class="btn btn-sm btn-outline-secondary me-1" onclick="editRowName(${rowIndex}, ${row.id || 0})" title="Reihenname bearbeiten">`;
            html += `<i class="bi bi-pencil"></i> Name</button>`;
            html += `<button class="btn btn-sm btn-outline-primary me-1" onclick="addElement(${rowIndex}, \'seat\')" title="Platz hinzufügen">`;
            html += `<i class="bi bi-plus-circle"></i> Platz</button>`;
            html += `<button class="btn btn-sm btn-outline-warning me-1" onclick="addElement(${rowIndex}, \'table\')" title="Tisch hinzufügen">`;
            html += `<i class="bi bi-plus-circle"></i> Tisch</button>`;
            html += `<button class="btn btn-sm btn-outline-secondary me-1" onclick="addElement(${rowIndex}, \'distance\')" title="Abstand hinzufügen">`;
            html += `<i class="bi bi-plus-circle"></i> Abstand</button>`;
            html += `<button class="btn btn-sm btn-outline-info me-1" onclick="addElement(${rowIndex}, \'wheelchair\')" title="Rollstuhlplatz hinzufügen (nur über Vorverkauf buchbar, Begleitperson wird im Vorverkauf manuell gewählt)">`;
            html += `<i class="bi bi-person-wheelchair"></i> Rollstuhl</button>`;
            html += `<button class="btn btn-sm btn-outline-info me-1" onclick="openRowCategoryDialog(${rowIndex})" title="Kategorie für alle oder ausgewählte Plätze dieser Reihe setzen">`;
            html += `<i class="bi bi-tag"></i> Kategorie</button>`;
            html += `<button class="btn btn-sm btn-outline-dark me-1" onclick="openAltDialog(${rowIndex})" title="Alternativbelegung (Ausweichspielstätte) für alle Plätze dieser Reihe festlegen">`;
            html += `<i class="bi bi-signpost-split"></i> Alternativ</button>`;
        }
        if (row.no !== 0) {
            html += `<button class="btn btn-sm btn-outline-success me-1" onclick="copyRow(${rowIndex})" title="Reihe kopieren">`;
            html += `<i class="bi bi-files"></i> Kopieren</button>`;
            html += `<button class="btn btn-sm btn-outline-secondary me-1" onclick="mirrorRow(${rowIndex})" title="Reihe spiegeln: Reihenfolge der Plätze umdrehen">`;
            html += `<i class="bi bi-symmetry-vertical"></i> Spiegeln</button>`;
            html += `<button class="btn btn-sm btn-outline-secondary me-1" onclick="sortRowSeats(${rowIndex})" title="Plätze nach Nummer sortieren (Abstände und Tische bleiben an ihrer Stelle)">`;
            html += `<i class="bi bi-sort-numeric-down"></i> Sortieren</button>`;
        }
        html += `<button class="btn btn-sm btn-outline-danger" onclick="deleteRow(${rowIndex})" title="Reihe löschen">`;
        html += `<i class="bi bi-trash"></i></button>`;
        html += `</div>`;
        html += `</div>`;
        
        html += `<div class="seatplan-elements" data-row="${rowIndex}">`;
        
        if (row.content.length === 0) {
            html += `<span class="text-muted">Keine Elemente in dieser Reihe</span>`;
        } else {
            row.content.forEach((element, elementIndex) => {
                // Skip seats with null or invalid numbers
                if (element.type === "seat" && (!element.no || element.no === null || element.no === "")) {
                    return; // Skip this element (return from forEach callback)
                }
                
                // Draggable within its row (not the stage) to reorder seats, distances and tables
                if (row.no === 0) {
                    html += `<div class="element-wrapper">`;
                } else {
                    html += `<div class="element-wrapper" draggable="true" data-row="${rowIndex}" data-index="${elementIndex}" title="Ziehen zum Verschieben">`;
                }
                
                let elementHtml = "";
                let bgColor = "#6c757d";
                
                if (element.type === "seat") {
                    const elementCategory = categories.find(c => c.id == element.category);
                    bgColor = elementCategory ? (elementCategory.color || "#0d6efd") : "#0d6efd";
                    elementHtml = `<span class="badge seat-element" style="background-color: ${bgColor}; font-size: 0.9rem; padding: 0.5rem;" title="${altTitle(element)}">${element.no}${altShort(element)}</span>`;
                } else if (element.type === "wheelchair") {
                    const elementCategory = categories.find(c => c.id == element.category);
                    bgColor = elementCategory ? (elementCategory.color || "#0d6efd") : "#0d6efd";
                    // Distinct visual: white fill + thick dashed border in category color,
                    // wheelchair pictogram (same as customer/presales view) + seat number.
                    elementHtml = `<span class="badge seat-element wheelchair-edit-marker"
                        style="background-color: white; color: ${bgColor};
                               border: 3px dashed ${bgColor}; font-size: 0.9rem;
                               padding: 0.4rem 0.6rem; font-weight: 600;
                               box-shadow: 0 0 0 2px white inset, 0 0 4px rgba(0,0,0,0.15);"
                        title="Rollstuhlplatz – nur über Vorverkauf buchbar; Begleitperson wird vom Vorverkäufer manuell ausgewählt. ${altTitle(element)}">
                        <i class="bi bi-person-wheelchair"></i> ${element.no}${altShort(element)}
                    </span>`;
                } else if (element.type === "table") {
                    // Render table with seats around it
                    // Front (vorn) is towards the stage (top), Back (hinten) is away from stage (bottom)
                    // Structure: table-wrapper contains table-with-seats, and table-element-wrapper contains the table badge
                    html += `<div class="table-wrapper">`;
                    let tableHtml = `<div class="table-with-seats" style="display: inline-flex; flex-direction: column; align-items: center; gap: 0.25rem;">`;
                    
                    // Front seats (top - towards stage)
                    if (element.seats && element.seats.front && element.seats.front.length > 0) {
                        tableHtml += "<div style=\"display: flex; gap: 0.25rem;\">";
                        element.seats.front.forEach((seat, idx) => {
                            // Skip seats with null or invalid numbers
                            if (!seat.number || seat.number === null || seat.number === "") {
                                return;
                            }
                            const seatCategory = categories.find(c => c.id == seat.category);
                            const seatBgColor = seatCategory ? (seatCategory.color || "#0d6efd") : "#0d6efd";
                            tableHtml += "<span class=\"badge seat-element\" style=\"background-color: " + seatBgColor + "; font-size: 0.75rem; padding: 0.25rem 0.5rem; cursor: pointer;\" onclick=\"deleteTableSeat(" + rowIndex + ", " + elementIndex + ", \'front\', " + seat.number + ")\" title=\"Klicken zum Löschen. " + altTitle(seat) + "\">" + seat.number + altShort(seat) + " <i class=\"bi bi-x-circle\" style=\"font-size: 0.6em;\"></i></span>";
                        });
                        tableHtml += "</div>";
                    }
                    
                    // Table with left and right seats
                    tableHtml += "<div style=\"display: flex; align-items: center; gap: 0.25rem;\">";
                    // Left seats
                    if (element.seats && element.seats.left && element.seats.left.length > 0) {
                        tableHtml += "<div style=\"display: flex; flex-direction: column; gap: 0.25rem;\">";
                        element.seats.left.forEach((seat, idx) => {
                            // Skip seats with null or invalid numbers
                            if (!seat.number || seat.number === null || seat.number === "") {
                                return;
                            }
                            const seatCategory = categories.find(c => c.id == seat.category);
                            const seatBgColor = seatCategory ? (seatCategory.color || "#0d6efd") : "#0d6efd";
                            tableHtml += "<span class=\"badge seat-element\" style=\"background-color: " + seatBgColor + "; font-size: 0.75rem; padding: 0.25rem 0.5rem; cursor: pointer;\" onclick=\"deleteTableSeat(" + rowIndex + ", " + elementIndex + ", \'left\', " + seat.number + ")\" title=\"Klicken zum Löschen. " + altTitle(seat) + "\">" + seat.number + altShort(seat) + " <i class=\"bi bi-x-circle\" style=\"font-size: 0.6em;\"></i></span>";
                        });
                        tableHtml += "</div>";
                    }
                    // Table center - wrap in separate div for hover detection
                    tableHtml += "<div class=\"table-element-wrapper\"><span class=\"badge table-element bg-warning\" style=\"font-size: 0.9rem; padding: 0.5rem;\">Tisch " + element.no + "</span></div>";
                    // Right seats
                    if (element.seats && element.seats.right && element.seats.right.length > 0) {
                        tableHtml += "<div style=\"display: flex; flex-direction: column; gap: 0.25rem;\">";
                        element.seats.right.forEach((seat, idx) => {
                            // Skip seats with null or invalid numbers
                            if (!seat.number || seat.number === null || seat.number === "") {
                                return;
                            }
                            const seatCategory = categories.find(c => c.id == seat.category);
                            const seatBgColor = seatCategory ? (seatCategory.color || "#0d6efd") : "#0d6efd";
                            tableHtml += "<span class=\"badge seat-element\" style=\"background-color: " + seatBgColor + "; font-size: 0.75rem; padding: 0.25rem 0.5rem; cursor: pointer;\" onclick=\"deleteTableSeat(" + rowIndex + ", " + elementIndex + ", \'right\', " + seat.number + ")\" title=\"Klicken zum Löschen. " + altTitle(seat) + "\">" + seat.number + altShort(seat) + " <i class=\"bi bi-x-circle\" style=\"font-size: 0.6em;\"></i></span>";
                        });
                        tableHtml += "</div>";
                    }
                    tableHtml += "</div>";
                    
                    // Back seats (bottom - away from stage)
                    if (element.seats && element.seats.back && element.seats.back.length > 0) {
                        tableHtml += "<div style=\"display: flex; gap: 0.25rem;\">";
                        element.seats.back.forEach((seat, idx) => {
                            // Skip seats with null or invalid numbers
                            if (!seat.number || seat.number === null || seat.number === "") {
                                return;
                            }
                            const seatCategory = categories.find(c => c.id == seat.category);
                            const seatBgColor = seatCategory ? (seatCategory.color || "#0d6efd") : "#0d6efd";
                            tableHtml += "<span class=\"badge seat-element\" style=\"background-color: " + seatBgColor + "; font-size: 0.75rem; padding: 0.25rem 0.5rem; cursor: pointer;\" onclick=\"deleteTableSeat(" + rowIndex + ", " + elementIndex + ", \'back\', " + seat.number + ")\" title=\"Klicken zum Löschen. " + altTitle(seat) + "\">" + seat.number + altShort(seat) + " <i class=\"bi bi-x-circle\" style=\"font-size: 0.6em;\"></i></span>";
                        });
                        tableHtml += "</div>";
                    }
                    
                    tableHtml += `</div>`;
                    elementHtml = tableHtml;
                } else if (element.type === "distance" || element.type === "spacer") {
                    if (element.stairs) {
                        elementHtml = `<span class="badge distance-element" style="font-size: 0.9rem; padding: 0.5rem; background: #fff; color: #000; border: 2px solid #000;" title="Treppe${element.consumes_number ? " (schluckt Sitz-Nr. " + element.no + ")" : ""}"><i class="bi bi-bar-chart-steps"></i> Treppe${element.consumes_number ? " (" + element.no + ")" : ""}</span>`;
                    } else if (element.consumes_number) {
                        elementHtml = `<span class="badge distance-element bg-secondary" style="font-size: 0.9rem; padding: 0.5rem;" title="Abstand mit Nummerierung (schluckt Sitz-Nr. ${element.no})"><i class="bi bi-arrows-expand-vertical"></i> Abstand (${element.no})</span>`;
                    } else {
                        elementHtml = `<span class="badge distance-element bg-secondary" style="font-size: 0.9rem; padding: 0.5rem;" title="Reiner visueller Abstand">Abstand</span>`;
                    }
                } else if (element.type === "stage") {
                    elementHtml = `<div class="badge stage-element bg-dark text-white" style="font-size: 1rem; padding: 1rem; width: 100%; text-align: center; display: block;">
                        <i class="bi bi-music-note-beamed"></i> BÜHNE
                    </div>`;
                }
                
                html += elementHtml;
                
                if (row.no === 0) {
                    html += `</div>`;
                } else {
                    // For tables, place actions inside table-element-wrapper, for others in element-wrapper
                    if (element.type === "table") {
                        // Actions will be placed after closing table-wrapper
                        html += `</div>`; // Close table-wrapper
                        html += `<div class="element-actions">`;
                        // Table-specific actions: add seats on different sides
                        html += "<button class=\"btn btn-sm btn-outline-success me-1\" onclick=\"addTableSeat(" + rowIndex + ", " + elementIndex + ", \'left\')\" title=\"Platz links hinzufügen\">";
                        html += "<i class=\"bi bi-arrow-left\"></i> Links</button>";
                        html += "<button class=\"btn btn-sm btn-outline-success me-1\" onclick=\"addTableSeat(" + rowIndex + ", " + elementIndex + ", \'right\')\" title=\"Platz rechts hinzufügen\">";
                        html += "<i class=\"bi bi-arrow-right\"></i> Rechts</button>";
                        html += "<button class=\"btn btn-sm btn-outline-success me-1\" onclick=\"addTableSeat(" + rowIndex + ", " + elementIndex + ", \'front\')\" title=\"Platz vorne hinzufügen (zur Bühne)\">";
                        html += "<i class=\"bi bi-arrow-up\"></i> Vorne</button>";
                        html += "<button class=\"btn btn-sm btn-outline-success me-1\" onclick=\"addTableSeat(" + rowIndex + ", " + elementIndex + ", \'back\')\" title=\"Platz hinten hinzufügen (weg von Bühne)\">";
                        html += "<i class=\"bi bi-arrow-down\"></i> Hinten</button>";
                        html += "<hr class=\"my-1\">";
                    } else {
                        // For non-table elements, create element-actions div
                        html += `<div class="element-actions">`;
                        if (element.type === "seat" || element.type === "distance" || element.type === "spacer") {
                            html += `<button class="btn btn-sm btn-outline-warning me-1" onclick="convertElementType(${rowIndex}, ${elementIndex})" title="Typ umwandeln (Sitz ↔ Abstand)">`;
                            html += `<i class="bi bi-arrow-left-right"></i></button>`;
                        }
                    }
                    html += `<button class="btn btn-sm btn-outline-primary me-1" onclick="editElement(${rowIndex}, ${elementIndex})" title="Bearbeiten">`;
                    html += `<i class="bi bi-pencil"></i></button>`;
                    if (element.type === "seat" || element.type === "wheelchair" || element.type === "table") {
                        html += `<button class="btn btn-sm btn-outline-dark me-1" onclick="openAltDialog(${rowIndex}, ${elementIndex})" title="Alternativbelegung festlegen">`;
                        html += `<i class="bi bi-signpost-split"></i></button>`;
                    }
                    html += `<button class="btn btn-sm btn-outline-danger" onclick="deleteElement(${rowIndex}, ${elementIndex})" title="Löschen">`;
                    html += `<i class="bi bi-trash"></i></button>`;
                    html += `</div>`; // Close element-actions
                    html += `</div>`; // Close element-wrapper
                }
            });
        }
        
        html += `</div>`;
        html += `</div>`;
    });
    
    editor.innerHTML = html;
}
</script>
';

require __DIR__ . '/../../app/views/layouts/main.php';

