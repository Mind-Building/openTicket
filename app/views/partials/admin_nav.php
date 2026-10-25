<?php
/**
 * Admin sidebar navigation partial.
 * Included automatically by main.php for all pages under /admin/.
 * Uses $_SERVER['PHP_SELF'] to determine the active nav item.
 */

// Map current filename → which top-level nav entry to highlight
$_navCurrent = basename($_SERVER['PHP_SELF']);
$_navMap = [
    'index.php'                  => 'index.php',
    'events.php'                 => 'events.php',
    'events_create.php'          => 'events.php',
    'events_edit.php'            => 'events.php',
    'events_dates.php'           => 'events.php',
    'events_ticket_template.php' => 'events.php',
    'events_presales_ticket_template.php' => 'events.php',
    'events_voucher_template.php'=> 'events.php',
    'seatplan.php'               => 'seatplan.php',
    'seatplan_categories.php'    => 'seatplan.php',
    'seatplan_import.php'        => 'seatplan.php',
    'seatplan_occupancy.php'     => 'seatplan_occupancy.php',
    'sell.php'                   => 'sell.php',
    'checkout_success.php'       => 'sell.php',
    'reservations.php'           => 'reservations.php',
    'box_office.php'             => 'box_office.php',
    'customers.php'              => 'customers.php',
    'customers_edit.php'         => 'customers.php',
    'customers_import.php'       => 'customers.php',
    'customer_emails.php'        => 'customer_emails.php',
    'vouchers.php'               => 'vouchers.php',
    'vouchers_create.php'        => 'vouchers.php',
    'vouchers_edit.php'          => 'vouchers.php',
    'vouchers_detail.php'        => 'vouchers.php',
    'vip_tickets.php'            => 'vip_tickets.php',
    'payment_requests.php'       => 'payment_requests.php',
    'payment_request_assign.php' => 'payment_requests.php',
    'users.php'                  => 'users.php',
    'users_create.php'           => 'users.php',
    'users_edit.php'             => 'users.php',
    'reports.php'                => 'reports.php',
    'reports_ticket_overview.php'=> 'reports.php',
    'reports_evaluations.php'    => 'reports.php',
    'reports_rebookings.php'     => 'reports.php',
    'logs.php'                   => 'logs.php',
    'email_failures.php'         => 'logs.php',
    'settings.php'               => 'settings.php',
    'database_update.php'        => 'settings.php',
    'documentation.php'          => 'documentation.php',
];
$_navActive = $_navMap[$_navCurrent] ?? '';

// Helper: render one <li> nav item
$_navItem = function (string $file, string $icon, string $label) use ($_navActive): string {
    $active = $_navActive === $file ? ' active' : '';
    return '<li class="nav-item">' . "\n"
        . '        <a class="nav-link' . $active . '" href="' . url('admin/' . $file) . '">' . "\n"
        . '            <i class="bi ' . $icon . '"></i> ' . $label . "\n"
        . '        </a>' . "\n"
        . '    </li>' . "\n";
};

echo $_navItem('index.php',            'bi-speedometer2',        'Dashboard');
echo $_navItem('events.php',           'bi-calendar-event',      'Veranstaltungen');
echo $_navItem('seatplan.php',         'bi-grid',                'Sitzplan Editor');
echo $_navItem('seatplan_occupancy.php','bi-grid-3x3-gap-fill',  'Sitzplan-Belegung');
echo $_navItem('sell.php',             'bi-cart-plus',           'Verkauf / Reservierung');
echo $_navItem('reservations.php',     'bi-ticket',              'Reservierungen');
echo $_navItem('box_office.php',       'bi-door-open',           'Abendkasse');
echo $_navItem('customers.php',        'bi-people',              'Kunden');
echo $_navItem('customer_emails.php',  'bi-envelope-paper',      'Kunden-Mails');
echo $_navItem('vouchers.php',         'bi-gift',                'Gutscheine');
echo $_navItem('vip_tickets.php',      'bi-star',                'VIP-Tickets');
echo $_navItem('payment_requests.php', 'bi-send',                'Zahlungsanforderungen');
echo $_navItem('users.php',            'bi-person-gear',         'Benutzer');
echo $_navItem('reports.php',          'bi-bar-chart',           'Auswertungen');
echo $_navItem('logs.php',             'bi-journal-text',        'System-Logs');
echo $_navItem('settings.php',         'bi-gear',                'Einstellungen');
echo $_navItem('documentation.php',    'bi-book',                'Dokumentation');
