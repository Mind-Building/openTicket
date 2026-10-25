<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Event;
use App\Models\SeatCategory;
use App\Models\SeatPlan;

Auth::requireAdmin();

$db = Database::getInstance();
$eventModel = new Event();
$categoryModel = new SeatCategory();
$seatPlanModel = new SeatPlan();

$eventId = (int)($_GET['event_id'] ?? 0);
$events = $eventModel->getAll();

// Get all statuses (excluding cart)
$statuses = [];
$stmt = $db->query("SELECT * FROM reservation_statuses WHERE id > 1 ORDER BY id ASC");
$statuses = $stmt->fetchAll();

$categories = [];
$eventDates = [];
$maxSeatsByCategory = [];
$reservationCounts = []; // [$date][$status_id][$category_id_or_0] = count

if ($eventId > 0) {
    $categories = $categoryModel->getByEvent($eventId);
    $eventDates = $eventModel->getDates($eventId);

    // Capacity per date: seat plan seat count if the event has a seat plan,
    // otherwise the configured max_seats (see Event::getCapacity).
    $selectedEvent   = $eventModel->getById($eventId);
    $capacityPerDate = $selectedEvent ? ($eventModel->getCapacity($selectedEvent) ?? 0) : 0;

    // Max seats per category – one grouped query for the whole event
    foreach ($categories as $category) {
        $maxSeatsByCategory[$category['id']] = 0;
    }
    $sql = "SELECT spe.category_id, COUNT(*) AS cnt
            FROM seat_plan_elements spe
            INNER JOIN seat_plan_rows spr ON spe.row_id = spr.id
            WHERE spr.event_id = :event_id
              AND spe.element_type IN ('seat', 'wheelchair')
            GROUP BY spe.category_id";
    $stmt = $db->query($sql, [':event_id' => $eventId]);
    foreach ($stmt->fetchAll() as $row) {
        $maxSeatsByCategory[(int)$row['category_id']] = (int)$row['cnt'];
    }

    // Events without a seat plan have no seat_plan_elements, so the query above
    // leaves every category at 0 — even though the event has a fixed capacity in
    // max_seats (which getCapacity() already uses for "Auslastung"). Assign that
    // capacity to the event's single configured category so "Max. Sitze" and the
    // "Frei" column line up with the utilisation figure instead of showing 0.
    if (array_sum($maxSeatsByCategory) === 0 && $capacityPerDate > 0) {
        $fixedCategoryId = (int)($selectedEvent['seat_category_id'] ?? 0);
        if ($fixedCategoryId > 0 && array_key_exists($fixedCategoryId, $maxSeatsByCategory)) {
            $maxSeatsByCategory[$fixedCategoryId] = $capacityPerDate;
        }
    }

    // All reservation counts in one grouped query – avoids hundreds of small COUNT(*) queries.
    // Reservations without a category land in bucket 0 (used as the "Fehler" column).
    $sql = "SELECT event_date, status_id, COALESCE(category_id, 0) AS category_id, COUNT(*) AS cnt
            FROM reservations
            WHERE event_id = :event_id
            GROUP BY event_date, status_id, category_id";
    $stmt = $db->query($sql, [':event_id' => $eventId]);
    foreach ($stmt->fetchAll() as $row) {
        $reservationCounts[$row['event_date']][(int)$row['status_id']][(int)$row['category_id']]
            = (int)$row['cnt'];
    }

    // Only keep statuses that actually occur for this event (in a displayed category),
    // so columns that would be all-zero are dropped entirely. The money/used/free
    // calculations read $reservationCounts directly, so they stay correct regardless.
    $statuses = array_values(array_filter($statuses, function ($status) use ($eventDates, $categories, $reservationCounts) {
        $sid = (int)$status['id'];
        foreach ($eventDates as $eventDate) {
            $date = $eventDate['date'];
            foreach ($categories as $category) {
                if (($reservationCounts[$date][$sid][$category['id']] ?? 0) > 0) {
                    return true;
                }
            }
        }
        return false;
    }));
}

// Voucher summary per category for this event
$voucherSummary = [];
if ($eventId > 0) {
    $sql = "SELECT v.category_id,
                   sc.name  AS category_name,
                   sc.sign  AS category_sign,
                   sc.price AS category_price,
                   COUNT(CASE WHEN v.status_id = 0 THEN 1 END) AS count_presales,
                   COUNT(CASE WHEN v.status_id = 2 AND v.purchase_intent = 'buy' THEN 1 END) AS count_paid,
                   COUNT(CASE WHEN v.status_id = 3 AND v.purchase_intent = 'buy' THEN 1 END) AS count_redeemed,
                   SUM(CASE WHEN v.status_id IN (2,3) AND v.purchase_intent = 'buy' THEN sc.price ELSE 0 END) AS revenue
            FROM vouchers v
            LEFT JOIN seat_categories sc ON v.category_id = sc.id
            WHERE v.event_id = :event_id
            GROUP BY v.category_id, sc.name, sc.sign, sc.price
            ORDER BY sc.name ASC";
    $stmt = $db->query($sql, [':event_id' => $eventId]);
    $voucherSummary = $stmt->fetchAll();
}

$pageTitle = 'Ticket-Übersicht';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Ticket-Übersicht</h2>
    <a href="' . url('admin/reports.php') . '" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Zurück
    </a>
</div>

<div class="row mb-4">
    <div class="col-md-4">
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
</div>';

if ($eventId > 0 && !empty($categories) && !empty($eventDates)) {
    // Show max seats per category
    $content .= '
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">Max. Sitze je Kategorie</h5>
    </div>
    <div class="card-body">
        <div class="row">';
    
    foreach ($categories as $category) {
        $maxSeats = $maxSeatsByCategory[$category['id']] ?? 0;
        $content .= '<div class="col-md-3 mb-2">
            <strong>' . htmlspecialchars($category['name']) . ' (' . htmlspecialchars($category['sign']) . '):</strong> ' . $maxSeats . ' Sitze
        </div>';
    }
    
    $content .= '
        </div>
    </div>
</div>';

    // Build detailed table
    $content .= '
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Detaillierte Übersicht</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-bordered table-sm" style="font-size: 0.85rem;">
                <thead>
                    <tr>
                        <th rowspan="2">Datum</th>';
    
    // Status headers
    foreach ($statuses as $status) {
        $content .= '<th colspan="' . count($categories) . '">' . htmlspecialchars(reservationStatusLabel((int)$status['id'])) . '</th>';
    }

    $content .= '
                        <th colspan="' . (count($categories) + 1) . '">Frei</th>
                        <th colspan="' . (count($categories) + 1) . '">Vergebene Tickets gesamt</th>
                        <th colspan="' . (count($categories) + 1) . '" title="Potentielle Einnahmen aus Reservierungen (noch nicht bezahlt)">Reserviert (potentielle Einnahmen)</th>
                        <th colspan="' . (count($categories) + 1) . '" title="Reale Einnahmen aus bezahlten Tickets (PayPal, Vorverkauf bezahlt, Barzahlung)">Bezahlt (reale Einnahmen)</th>
                        <th colspan="' . (count($categories) + 1) . '" title="Wert der VIP-Tickets – keine Einnahmen, gesponsert">VIP (gesponsert, keine Einnahmen)</th>
                        <th rowspan="2" class="align-middle text-center" title="Anteil vergebener Tickets an den maximal verfügbaren Sitzen">Auslastung</th>
                    </tr>
                    <tr>';
    
    // Category headers for each status
    foreach ($statuses as $status) {
        foreach ($categories as $category) {
            $content .= '<th>Kat. ' . htmlspecialchars($category['sign']) . '</th>';
        }
    }

    // Free seats headers
    foreach ($categories as $category) {
        $content .= '<th>Kat. ' . htmlspecialchars($category['sign']) . '</th>';
    }
    $content .= '<th>Summe</th>';

    // Used seats headers
    foreach ($categories as $category) {
        $content .= '<th>Kat. ' . htmlspecialchars($category['sign']) . '</th>';
    }
    $content .= '<th>Summe</th>';

    // Reserved revenue headers
    foreach ($categories as $category) {
        $content .= '<th>Kat. ' . htmlspecialchars($category['sign']) . '</th>';
    }
    $content .= '<th>Summe</th>';

    // Paid revenue headers
    foreach ($categories as $category) {
        $content .= '<th>Kat. ' . htmlspecialchars($category['sign']) . '</th>';
    }
    $content .= '<th>Summe</th>';

    // VIP value headers
    foreach ($categories as $category) {
        $content .= '<th>Kat. ' . htmlspecialchars($category['sign']) . '</th>';
    }
    $content .= '<th>Summe</th>';
    
    $content .= '
                    </tr>
                </thead>
                <tbody>';
    
    // Process each date
    $totals = [];
    foreach ($statuses as $status) {
        $totals[$status['id']] = [];
        foreach ($categories as $category) {
            $totals[$status['id']][$category['id']] = 0;
        }
    }
    $totals['free'] = [];
    $totals['used'] = [];
    $totals['reserved'] = [];
    $totals['paid']     = [];
    $totals['vip']      = [];
    foreach ($categories as $category) {
        $totals['free'][$category['id']]     = 0;
        $totals['used'][$category['id']]     = 0;
        $totals['reserved'][$category['id']] = 0;
        $totals['paid'][$category['id']]     = 0;
        $totals['vip'][$category['id']]      = 0;
    }
    
    foreach ($eventDates as $eventDate) {
        $date = $eventDate['date'];
        $content .= '<tr><td>' . date('d.m.y', strtotime($date)) . '</td>';
        
        // Count by status (all numbers read from the pre-loaded $reservationCounts array)
        foreach ($statuses as $status) {
            foreach ($categories as $category) {
                $count = $reservationCounts[$date][$status['id']][$category['id']] ?? 0;
                $totals[$status['id']][$category['id']] += $count;
                $content .= '<td>' . $count . '</td>';
            }
        }
        
        // Per-category "used" tally across all displayed statuses (id > 1).
        // In-cart (status 1) is intentionally excluded so transient cart items
        // don't reduce "Frei" or inflate "Vergeben".
        $usedByCategory = [];
        foreach ($categories as $category) {
            $usedSum = 0;
            foreach ($statuses as $status) {
                $usedSum += $reservationCounts[$date][$status['id']][$category['id']] ?? 0;
            }
            $usedByCategory[$category['id']] = $usedSum;
        }

        // Free seats
        $rowFreeSum = 0;
        foreach ($categories as $category) {
            $maxSeats = $maxSeatsByCategory[$category['id']] ?? 0;
            $freeSeats = max(0, $maxSeats - $usedByCategory[$category['id']]);
            $totals['free'][$category['id']] += $freeSeats;
            $rowFreeSum += $freeSeats;
            $content .= '<td>' . $freeSeats . '</td>';
        }
        $content .= '<td><strong>' . $rowFreeSum . '</strong></td>';

        // Used seats total
        $rowUsedSum = 0;
        foreach ($categories as $category) {
            $totals['used'][$category['id']] += $usedByCategory[$category['id']];
            $rowUsedSum += $usedByCategory[$category['id']];
            $content .= '<td>' . $usedByCategory[$category['id']] . '</td>';
        }
        $content .= '<td><strong>' . $rowUsedSum . '</strong></td>';

        // Money columns: split into reserved (potential), paid (real), vip (sponsored, no income)
        // Reservierungen (potentielle Einnahmen): status 3, 6
        // Bezahlt (reale Einnahmen): PayPal (2), Vorverkauf bezahlt (4), Barzahlung (5)
        // VIP (gesponsert, keine Einnahmen): status 11 – Wert wird nur informativ angezeigt
        // Ausgeschlossen: Gutschein-bezahlt (8 – Einnahmen bereits im Gutschein-Abschnitt erfasst)
        $moneyGroups = [
            'reserved' => [3, 6],
            'paid'     => [2, 4, 5],
            'vip'      => [11],
        ];
        $groupSums = ['reserved' => [], 'paid' => [], 'vip' => []];

        foreach ($moneyGroups as $groupKey => $groupStatusIds) {
            $daySum = 0;
            foreach ($categories as $category) {
                $categoryValue = 0;
                foreach ($groupStatusIds as $statusId) {
                    $count = $reservationCounts[$date][$statusId][$category['id']] ?? 0;
                    $categoryValue += $count * (float)$category['price'];
                }
                $totals[$groupKey][$category['id']] += $categoryValue;
                $daySum += $categoryValue;
                $groupSums[$groupKey][$category['id']] = $categoryValue;
            }
            $groupSums[$groupKey]['__day'] = $daySum;
        }

        foreach (['reserved', 'paid', 'vip'] as $groupKey) {
            foreach ($categories as $category) {
                $content .= '<td>' . number_format($groupSums[$groupKey][$category['id']], 2, ',', '.') . ' €</td>';
            }
            $content .= '<td><strong>' . number_format($groupSums[$groupKey]['__day'], 2, ',', '.') . ' €</strong></td>';
        }

        // Auslastung: vergebene Tickets / Kapazität (Sitzplan bzw. max_seats)
        $rowUsed = array_sum($usedByCategory);
        $rowUtil = $capacityPerDate > 0 ? ($rowUsed / $capacityPerDate * 100) : 0;
        $content .= '<td class="text-center fw-bold">' . number_format($rowUtil, 1, ',', '.') . ' %</td>';

        $content .= '</tr>';
    }
    
    // Totals row
    $content .= '
                </tbody>
                <tfoot>
                    <tr>
                        <td><strong>Summe</strong></td>';
    
    foreach ($statuses as $status) {
        foreach ($categories as $category) {
            $content .= '<td><strong>' . $totals[$status['id']][$category['id']] . '</strong></td>';
        }
    }

    $freeTotalSum = 0;
    foreach ($categories as $category) {
        $content .= '<td><strong>' . $totals['free'][$category['id']] . '</strong></td>';
        $freeTotalSum += $totals['free'][$category['id']];
    }
    $content .= '<td><strong>' . $freeTotalSum . '</strong></td>';

    $usedTotalSum = 0;
    foreach ($categories as $category) {
        $content .= '<td><strong>' . $totals['used'][$category['id']] . '</strong></td>';
        $usedTotalSum += $totals['used'][$category['id']];
    }
    $content .= '<td><strong>' . $usedTotalSum . '</strong></td>';

    foreach (['reserved', 'paid', 'vip'] as $groupKey) {
        $groupTotal = 0;
        foreach ($categories as $category) {
            $content .= '<td><strong>' . number_format($totals[$groupKey][$category['id']], 2, ',', '.') . ' €</strong></td>';
            $groupTotal += $totals[$groupKey][$category['id']];
        }
        $content .= '<td><strong>' . number_format($groupTotal, 2, ',', '.') . ' €</strong></td>';
    }

    // Gesamt-Auslastung: alle vergebenen Tickets / (Kapazität × Anzahl Termine)
    $totalUsedAll = 0;
    foreach ($categories as $category) {
        $totalUsedAll += $totals['used'][$category['id']];
    }
    $totalCapacity = $capacityPerDate * count($eventDates);
    $totalUtil     = $totalCapacity > 0 ? ($totalUsedAll / $totalCapacity * 100) : 0;
    $content .= '<td class="text-center fw-bold">' . number_format($totalUtil, 1, ',', '.') . ' %</td>';
    
    $content .= '
                    </tr>
                </tfoot>
            </table>
        </div>
        <small class="text-muted d-block mt-2">
            <strong>Reserviert:</strong> potentielle Einnahmen aus noch nicht bezahlten Reservierungen.
            <strong>Bezahlt:</strong> reale Einnahmen aus PayPal, Vorverkauf (bezahlt) und Barzahlung.
            <strong>VIP:</strong> Wert der gesponserten VIP-Tickets – keine tatsächlichen Einnahmen.
            Gutschein-bezahlte Tickets sind hier nicht enthalten (siehe Gutscheinverkäufe weiter unten).
        </small>
    </div>
</div>';
} else if ($eventId > 0) {
    $content .= '<div class="alert alert-info">Keine Daten für diese Veranstaltung gefunden. Bitte stellen Sie sicher, dass Kategorien und Termine vorhanden sind.</div>';
} else {
    $content .= '<div class="alert alert-info">Bitte wählen Sie eine Veranstaltung aus.</div>';
}

// Voucher section (shown whenever an event is selected, independent of ticket data)
if ($eventId > 0) {
    $content .= '
<div class="card mt-4">
    <div class="card-header">
        <h5 class="mb-0">Gutscheinverkäufe</h5>
    </div>
    <div class="card-body">';

    if (empty($voucherSummary)) {
        $content .= '<p class="text-muted mb-0">Keine Gutscheine für diese Veranstaltung vorhanden.</p>';
    } else {
        $totalPresales = 0;
        $totalPaid     = 0;
        $totalRedeemed = 0;
        $totalRevenue  = 0.0;

        $content .= '
        <div class="table-responsive">
            <table class="table table-striped table-bordered table-sm">
                <thead>
                    <tr>
                        <th>Kategorie</th>
                        <th>Einzelpreis</th>
                        <th title="Vom Vorverkauf ausgestellt (status=0)">Vorverkauf, nicht eingelöst</th>
                        <th title="Online gekauft, noch nicht eingelöst (status=2)">Bezahlt, nicht eingelöst</th>
                        <th title="Online gekauft und als Rabatt eingelöst (status=3)">Bezahlt, eingelöst</th>
                        <th>Gesamt verkauft</th>
                        <th>Einnahmen</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($voucherSummary as $row) {
            $sold    = (int)$row['count_paid'] + (int)$row['count_redeemed'];
            $revenue = (float)$row['revenue'];
            $totalPresales += (int)$row['count_presales'];
            $totalPaid     += (int)$row['count_paid'];
            $totalRedeemed += (int)$row['count_redeemed'];
            $totalRevenue  += $revenue;

            $content .= '<tr>
                <td>' . htmlspecialchars($row['category_name'] ?? '-') . ' (' . htmlspecialchars($row['category_sign'] ?? '') . ')</td>
                <td>' . number_format((float)$row['category_price'], 2, ',', '.') . ' €</td>
                <td>' . (int)$row['count_presales'] . '</td>
                <td>' . (int)$row['count_paid'] . '</td>
                <td>' . (int)$row['count_redeemed'] . '</td>
                <td><strong>' . $sold . '</strong></td>
                <td><strong>' . number_format($revenue, 2, ',', '.') . ' €</strong></td>
            </tr>';
        }

        $content .= '
                </tbody>
                <tfoot>
                    <tr>
                        <td><strong>Summe</strong></td>
                        <td></td>
                        <td><strong>' . $totalPresales . '</strong></td>
                        <td><strong>' . $totalPaid . '</strong></td>
                        <td><strong>' . $totalRedeemed . '</strong></td>
                        <td><strong>' . ($totalPaid + $totalRedeemed) . '</strong></td>
                        <td><strong>' . number_format($totalRevenue, 2, ',', '.') . ' €</strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <small class="text-muted">Einnahmen = nur online gekaufte Gutscheine (Bezahlt + Eingelöst) × Einzelpreis. Vorverkaufs-Gutscheine werden nicht als Einnahmen gezählt.</small>';
    }

    $content .= '
    </div>
</div>';
}

$content .= '
';

require __DIR__ . '/../../app/views/layouts/main.php';

