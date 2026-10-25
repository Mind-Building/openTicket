<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Event;

Auth::requireAdmin();

$db = Database::getInstance();
$eventModel = new Event();

$eventId = (int)($_GET['event_id'] ?? 0);
$events = $eventModel->getAll();

// Get all statuses
$statuses = [];
$stmt = $db->query("SELECT * FROM reservation_statuses WHERE id > 1 ORDER BY id ASC");
$statuses = $stmt->fetchAll();

// Get dates and counts
$lineChartDatesSum = [];
$lineChartDataSum = [];
$lineChartDataByStatus = [];

if ($eventId > 0) {
    // One grouped query covers both per-day totals and per-day-per-status breakdowns.
    $sql = "SELECT DATE_FORMAT(updated_at, '%Y-%m-%d') AS lchange,
                   status_id,
                   COUNT(id) AS anzahl
            FROM reservations
            WHERE event_id = :event_id AND status_id > 1
            GROUP BY lchange, status_id
            ORDER BY lchange ASC";
    $stmt = $db->query($sql, [':event_id' => $eventId]);

    $countsByDateAndStatus = []; // [$date][$status_id] = count
    foreach ($stmt->fetchAll() as $row) {
        $countsByDateAndStatus[$row['lchange']][(int)$row['status_id']] = (int)$row['anzahl'];
    }

    // Distinct dates (already ascending thanks to ORDER BY in the query)
    $lineChartDatesSum = array_keys($countsByDateAndStatus);

    // Per-day total (sum of all statuses on that date)
    foreach ($lineChartDatesSum as $date) {
        $lineChartDataSum[] = array_sum($countsByDateAndStatus[$date]);
    }

    // Per-status series, indexed parallel to $lineChartDatesSum
    foreach ($statuses as $status) {
        $sid = (int)$status['id'];
        $lineChartDataByStatus[$sid] = [];
        foreach ($lineChartDatesSum as $date) {
            $lineChartDataByStatus[$sid][] = $countsByDateAndStatus[$date][$sid] ?? 0;
        }
    }

    // Filter statuses to only those with data
    $activeStatuses = array_filter($statuses, function($status) use ($lineChartDataByStatus) {
        return isset($lineChartDataByStatus[$status['id']]) && array_sum($lineChartDataByStatus[$status['id']]) > 0;
    });
}

// Voucher activity data (only sold vouchers: purchase_intent='buy', status >= 2)
$voucherDatesSum = [];
$voucherDataSum  = [];
$voucherDataByStatus = [];
$voucherStatusLabels = [
    2 => 'Bezahlt, nicht eingelöst',
    3 => 'Bezahlt, eingelöst',
];

if ($eventId > 0) {
    // One grouped query gives us totals AND per-status breakdown in a single round-trip.
    $sql = "SELECT DATE_FORMAT(updated_at, '%Y-%m-%d') AS lchange,
                   status_id,
                   COUNT(id) AS anzahl
            FROM vouchers
            WHERE event_id = :event_id AND status_id >= 2 AND purchase_intent = 'buy'
            GROUP BY lchange, status_id
            ORDER BY lchange ASC";
    $stmt = $db->query($sql, [':event_id' => $eventId]);

    $voucherCountsByDateAndStatus = []; // [$date][$status_id] = count
    foreach ($stmt->fetchAll() as $row) {
        $voucherCountsByDateAndStatus[$row['lchange']][(int)$row['status_id']] = (int)$row['anzahl'];
    }

    $voucherDatesSum = array_keys($voucherCountsByDateAndStatus);
    foreach ($voucherDatesSum as $date) {
        // Sum of ALL statuses on that date (matches original behaviour: query
        // filter is status_id >= 2, so totals include any higher status too,
        // not just 2 and 3).
        $voucherDataSum[] = array_sum($voucherCountsByDateAndStatus[$date]);
    }

    foreach ([2, 3] as $statusId) {
        $voucherDataByStatus[$statusId] = [];
        foreach ($voucherDatesSum as $date) {
            $voucherDataByStatus[$statusId][] = $voucherCountsByDateAndStatus[$date][$statusId] ?? 0;
        }
    }

    // Filter voucher statuses to only those with data
    $activeVoucherStatusIds = array_filter([2, 3], function($statusId) use ($voucherDataByStatus) {
        return isset($voucherDataByStatus[$statusId]) && array_sum($voucherDataByStatus[$statusId]) > 0;
    });
}

$pageTitle = 'Evaluierungen';
$showSidebar = true;
$showHeader = true;

$content = '
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Evaluierungen</h2>
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

if ($eventId > 0 && !empty($lineChartDatesSum)) {
    $content .= '
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Grafische Darstellung</h5>
        <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-primary active" data-zoom="day" data-chart="reservations">Tage</button>
            <button type="button" class="btn btn-outline-primary" data-zoom="week" data-chart="reservations">Wochen</button>
            <button type="button" class="btn btn-outline-primary" data-zoom="month" data-chart="reservations">Monate</button>
        </div>
    </div>
    <div class="card-body">
        <canvas id="chartCanvas" height="100"></canvas>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">Statistik nach Status</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-bordered">
                <thead>
                    <tr>
                        <th>Summe</th>';
    
    foreach ($activeStatuses as $status) {
        $content .= '<th>' . htmlspecialchars(reservationStatusLabel((int)$status['id'])) . '</th>';
    }
    
    $content .= '
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td valign="top">
                            <table class="table table-sm table-bordered mb-0">
                                <thead>
                                    <tr>
                                        <th>Datum</th>
                                        <th>Anzahl</th>
                                    </tr>
                                </thead>
                                <tbody>';
    
    $sumTotal = 0;
    foreach ($lineChartDatesSum as $idx => $date) {
        $count = $lineChartDataSum[$idx];
        $sumTotal += $count;
        $content .= '<tr><td>' . htmlspecialchars($date) . '</td><td>' . $count . '</td></tr>';
    }
    
    $content .= '
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td><strong>Summe</strong></td>
                                        <td><strong>' . $sumTotal . '</strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </td>';
    
    foreach ($activeStatuses as $status) {
        $statusId = $status['id'];
        $content .= '<td valign="top">
                            <table class="table table-sm table-bordered mb-0">
                                <thead>
                                    <tr>
                                        <th>Datum</th>
                                        <th>Anzahl</th>
                                    </tr>
                                </thead>
                                <tbody>';

        $sumStatus = 0;
        if (isset($lineChartDataByStatus[$statusId])) {
            foreach ($lineChartDatesSum as $idx => $date) {
                $count = $lineChartDataByStatus[$statusId][$idx] ?? 0;
                if ($count > 0) {
                    $sumStatus += $count;
                    $content .= '<tr><td>' . htmlspecialchars($date) . '</td><td>' . $count . '</td></tr>';
                }
            }
        }

        $content .= '
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td><strong>Summe</strong></td>
                                        <td><strong>' . $sumStatus . '</strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </td>';
    }
    
    $content .= '
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <th>Summe</th>';
    
    foreach ($activeStatuses as $status) {
        $statusId = $status['id'];
        $sumStatus = isset($lineChartDataByStatus[$statusId]) ? array_sum($lineChartDataByStatus[$statusId]) : 0;
        $content .= '<th>' . $sumStatus . '</th>';
    }
    
    $content .= '
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

';

    // Voucher statistics card
    if (!empty($voucherDatesSum)) {
        $content .= '
<div class="card mb-4 mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Gutscheinverkäufe – Grafische Darstellung</h5>
        <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-primary active" data-zoom="day" data-chart="vouchers">Tage</button>
            <button type="button" class="btn btn-outline-primary" data-zoom="week" data-chart="vouchers">Wochen</button>
            <button type="button" class="btn btn-outline-primary" data-zoom="month" data-chart="vouchers">Monate</button>
        </div>
    </div>
    <div class="card-body">
        <canvas id="voucherChartCanvas" height="100"></canvas>
    </div>
</div>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">Gutscheinverkäufe – Statistik nach Status</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-bordered">
                <thead>
                    <tr>
                        <th>Summe</th>';
        foreach ($activeVoucherStatusIds as $statusId) {
            $content .= '<th>' . htmlspecialchars($voucherStatusLabels[$statusId]) . '</th>';
        }
        $content .= '
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td valign="top">
                            <table class="table table-sm table-bordered mb-0">
                                <thead><tr><th>Datum</th><th>Anzahl</th></tr></thead>
                                <tbody>';
        $voucherSumTotal = 0;
        foreach ($voucherDatesSum as $idx => $date) {
            $cnt = $voucherDataSum[$idx];
            $voucherSumTotal += $cnt;
            $content .= '<tr><td>' . htmlspecialchars($date) . '</td><td>' . $cnt . '</td></tr>';
        }
        $content .= '
                                </tbody>
                                <tfoot><tr><td><strong>Summe</strong></td><td><strong>' . $voucherSumTotal . '</strong></td></tr></tfoot>
                            </table>
                        </td>';
        foreach ($activeVoucherStatusIds as $statusId) {
            $content .= '<td valign="top">
                            <table class="table table-sm table-bordered mb-0">
                                <thead><tr><th>Datum</th><th>Anzahl</th></tr></thead>
                                <tbody>';
            $sumStatus = 0;
            foreach ($voucherDatesSum as $idx => $date) {
                $cnt = $voucherDataByStatus[$statusId][$idx] ?? 0;
                if ($cnt > 0) {
                    $sumStatus += $cnt;
                    $content .= '<tr><td>' . htmlspecialchars($date) . '</td><td>' . $cnt . '</td></tr>';
                }
            }
            $content .= '
                                </tbody>
                                <tfoot><tr><td><strong>Summe</strong></td><td><strong>' . $sumStatus . '</strong></td></tr></tfoot>
                            </table>
                        </td>';
        }
        $content .= '
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <th>Summe</th>';
        foreach ($activeVoucherStatusIds as $statusId) {
            $content .= '<th>' . array_sum($voucherDataByStatus[$statusId]) . '</th>';
        }
        $content .= '
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>';
    }

    // Prepare chart data (only active statuses)
    $chartLabels = json_encode($lineChartDatesSum);
    $chartDatasets = [];
    $statusColors = [
        'rgba(54,162,235,', 'rgba(255,99,132,', 'rgba(75,192,192,',
        'rgba(255,206,86,', 'rgba(153,102,255,', 'rgba(255,159,64,',
        'rgba(199,199,199,', 'rgba(83,102,255,', 'rgba(255,99,255,'
    ];
    $colorIdx = 0;
    foreach ($activeStatuses as $status) {
        $statusId = $status['id'];
        $data = $lineChartDataByStatus[$statusId] ?? [];
        $color = $statusColors[$colorIdx % count($statusColors)];
        $chartDatasets[] = [
            'label' => reservationStatusLabel((int)$status['id']),
            'data' => $data,
            'borderColor' => $color . '1)',
            'backgroundColor' => $color . '0.2)',
            'fill' => false
        ];
        $colorIdx++;
    }
    $chartDatasetsJson = json_encode($chartDatasets);

    // Prepare voucher chart data (only active statuses)
    $voucherChartLabels = json_encode($voucherDatesSum);
    $voucherChartDatasets = [];
    $voucherColors = ['2' => ['80,200,120', '80,200,120'], '3' => ['255,140,0', '255,140,0']];
    foreach ($activeVoucherStatusIds as $statusId) {
        $rgb = $voucherColors[$statusId];
        $voucherChartDatasets[] = [
            'label' => $voucherStatusLabels[$statusId],
            'data' => $voucherDataByStatus[$statusId],
            'borderColor' => 'rgba(' . $rgb[0] . ',1)',
            'backgroundColor' => 'rgba(' . $rgb[1] . ',0.2)',
            'fill' => false,
        ];
    }
    $voucherChartDatasetsJson = json_encode($voucherChartDatasets);

    $additionalJs = '
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    var rawLabels = ' . $chartLabels . ';
    var rawDatasets = ' . $chartDatasetsJson . ';
    var voucherRawLabels = ' . $voucherChartLabels . ';
    var voucherRawDatasets = ' . $voucherChartDatasetsJson . ';

    function getWeekKey(dateStr) {
        // ISO 8601 week: weeks start Monday, week 1 contains the first Thursday.
        var d = new Date(dateStr);
        var target = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
        var dayNum = target.getUTCDay() || 7; // Sun=0 -> 7
        target.setUTCDate(target.getUTCDate() + 4 - dayNum);
        var yearStart = new Date(Date.UTC(target.getUTCFullYear(), 0, 1));
        var weekNum = Math.ceil(((target - yearStart) / 86400000 + 1) / 7);
        return target.getUTCFullYear() + "-KW" + String(weekNum).padStart(2, "0");
    }

    function getMonthKey(dateStr) {
        return dateStr.substring(0, 7);
    }

    function aggregateData(labels, datasets, mode) {
        if (mode === "day") return { labels: labels, datasets: datasets };

        var keyFn = mode === "week" ? getWeekKey : getMonthKey;
        var keyMap = {};
        var orderedKeys = [];

        labels.forEach(function(dateStr, i) {
            var key = keyFn(dateStr);
            if (!(key in keyMap)) {
                keyMap[key] = [];
                orderedKeys.push(key);
            }
            keyMap[key].push(i);
        });

        var newDatasets = datasets.map(function(ds) {
            var newData = orderedKeys.map(function(key) {
                return keyMap[key].reduce(function(sum, idx) { return sum + (ds.data[idx] || 0); }, 0);
            });
            return Object.assign({}, ds, { data: newData });
        });

        return { labels: orderedKeys, datasets: newDatasets };
    }

    function createChart(canvasId, labels, datasets) {
        var ctx = document.getElementById(canvasId).getContext("2d");
        return new Chart(ctx, {
            type: "line",
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { position: "top" } },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    }

    var reservationsChart = createChart("chartCanvas", rawLabels, rawDatasets);

    var voucherChart = null;
    var voucherCanvas = document.getElementById("voucherChartCanvas");
    if (voucherCanvas) {
        voucherChart = createChart("voucherChartCanvas", voucherRawLabels, voucherRawDatasets);
    }

    document.querySelectorAll("[data-zoom]").forEach(function(btn) {
        btn.addEventListener("click", function() {
            var mode = this.getAttribute("data-zoom");
            var chartType = this.getAttribute("data-chart");

            this.closest(".btn-group").querySelectorAll(".btn").forEach(function(b) { b.classList.remove("active"); });
            this.classList.add("active");

            if (chartType === "reservations") {
                var agg = aggregateData(rawLabels, rawDatasets, mode);
                reservationsChart.data.labels = agg.labels;
                reservationsChart.data.datasets = agg.datasets;
                reservationsChart.update();
            } else if (chartType === "vouchers" && voucherChart) {
                var agg = aggregateData(voucherRawLabels, voucherRawDatasets, mode);
                voucherChart.data.labels = agg.labels;
                voucherChart.data.datasets = agg.datasets;
                voucherChart.update();
            }
        });
    });
});
</script>';
} else if ($eventId > 0) {
    $content .= '<div class="alert alert-info">Keine Daten für diese Veranstaltung gefunden.</div>';
} else {
    $content .= '<div class="alert alert-info">Bitte wählen Sie eine Veranstaltung aus.</div>';
}

$content .= '
';

require __DIR__ . '/../../app/views/layouts/main.php';

