<?php
/**
 * Presales sidebar navigation partial.
 * Included automatically by main.php for all pages under /presales/.
 * Uses $_SERVER['PHP_SELF'] to determine the active nav item.
 */

use App\Core\Config;

// Map current filename → which top-level nav entry to highlight
$_navCurrent = basename($_SERVER['PHP_SELF']);
$_navMap = [
    'index.php'            => 'index.php',
    'sell.php'             => 'sell.php',
    'reservations.php'     => 'reservations.php',
    'seatplan_occupancy.php'=> 'seatplan_occupancy.php',
    'customers.php'        => 'customers.php',
    'vouchers_create.php'  => 'vouchers_create.php',
    'vouchers.php'         => 'vouchers.php',
    'box_office.php'       => 'box_office.php',
    'checkout_success.php' => 'sell.php',
    'documentation.php'    => 'documentation.php',
];
$_navActive = $_navMap[$_navCurrent] ?? '';

// Helper: render one <li> nav item
$_navItem = function (string $file, string $icon, string $label) use ($_navActive): string {
    $active = $_navActive === $file ? ' active' : '';
    return '<li class="nav-item">' . "\n"
        . '        <a class="nav-link' . $active . '" href="' . url('presales/' . $file) . '">' . "\n"
        . '            <i class="bi ' . $icon . '"></i> ' . $label . "\n"
        . '        </a>' . "\n"
        . '    </li>' . "\n";
};

$_vouchersActive = Config::get('voucher_active', false) && Config::get('presales_allow_vouchers', true);

echo $_navItem('index.php',             'bi-speedometer2',       'Dashboard');
echo $_navItem('sell.php',              'bi-cart-plus',          'Verkauf / Reservierung');
echo $_navItem('reservations.php',      'bi-ticket',             'Reservierungen');
echo $_navItem('seatplan_occupancy.php','bi-grid-3x3-gap-fill',  'Sitzplan-Belegung');
echo $_navItem('customers.php',         'bi-people',             'Kunden');

if ($_vouchersActive) {
    echo $_navItem('vouchers_create.php', 'bi-gift',      'Gutschein erstellen');
    echo $_navItem('vouchers.php',        'bi-list-ul',   'Gutscheinkiste');
}

echo $_navItem('box_office.php',     'bi-door-open', 'Abendkasse');
echo $_navItem('documentation.php',  'bi-book',      'Dokumentation');
