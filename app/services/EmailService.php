<?php
/**
 * Email Service
 * Handles email sending
 */

namespace App\Services;

use App\Core\Auth;
use App\Core\Config;

class EmailService
{
    private string $smtpHost;
    private int $smtpPort;
    private string $smtpUser;
    private string $smtpPassword;
    private bool $smtpSecure; // true for TLS/SSL
    private string $fromEmail;
    private string $fromName;
    private bool $useSmtp;
    private ?string $lastError = null;

    public function __construct()
    {
        $this->useSmtp = Config::get('email_use_smtp', false);
        $this->smtpHost = Config::get('email_smtp_host', '');
        $this->smtpPort = (int)Config::get('email_smtp_port', 587);
        $this->smtpUser = Config::get('email_smtp_user', '');
        $this->smtpPassword = Config::get('email_smtp_password', '');
        $this->smtpSecure = Config::get('email_smtp_secure', true);
        $this->fromEmail = Config::get('sender_email', 'noreply@example.com');
        $this->fromName = Config::get('sender_name', 'Ticket System');
    }

    private function logEmail(string $type, string $toEmail, bool $success, array $details = []): void
    {
        $sanitizedEmail = substr(md5($toEmail), 0, 8);
        $sessionId = session_id() ?? 'N/A';
        $userId = $_SESSION['user_id'] ?? null;
        $customerId = $details['customer_id'] ?? null;

        $context = array_merge($details, [
            'type' => $type,
            'email_hash' => $sanitizedEmail,
            'success' => $success,
            'session_id' => substr($sessionId, 0, 32),
            'user_id' => $userId,
            'customer_id' => $customerId,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'N/A'
        ]);

        // Include the concrete transport error so failures are diagnosable
        // from the Monolog files without digging through the PHP error_log.
        if (!$success && $this->lastError !== null && !isset($context['error'])) {
            $context['error'] = $this->lastError;
        }

        $level = $success ? 'info' : 'warning';

        if (Auth::isPresales()) {
            LoggerService::logPresales('email_sent', $context, $level);
        } elseif (Auth::isAdmin()) {
            LoggerService::logAdmin('email_sent', $context, $level);
        } else {
            LoggerService::logCustomer('email_sent', $context, $level);
        }
    }

    /**
     * Send ticket email
     */
    public function sendTicketEmail(string $toEmail, string $toName, array $reservations, string $ticketLink, ?int $eventId = null): bool
    {
        if (!Config::get('email_enabled', true)) {
            return false;
        }
        
        // Check if this is a no-seatplan event
        $firstReservation = $reservations[0] ?? [];
        $isNoSeatPlan = strpos($firstReservation['seat_identifier'] ?? '', 'no_seatplan_') === 0;
        
        // Get event-specific email settings if event ID is provided
        $eventModel = null;
        $event = null;
        if ($eventId) {
            $eventModel = new \App\Models\Event();
            $event = $eventModel->getById($eventId);
        }
        
        // Get subject - check event-specific first, then global config, then default
        if ($event && !empty($event['email_ticket_subject'])) {
            $subject = $event['email_ticket_subject'];
        } else {
            $subject = Config::get('email_ticket_subject', 'Ihre Tickets');
        }
        
        // Get body template - check event-specific first, then global config, then default
        if ($event && !empty($event['email_ticket_body'])) {
            $bodyTemplate = $event['email_ticket_body'];
        } else {
            $bodyTemplate = Config::get('email_ticket_body') ?: $this->getDefaultTicketEmailBody($isNoSeatPlan);
        }
        
        // Collect voucher codes used
        $voucherCodes = [];
        foreach ($reservations as $reservation) {
            if (!empty($reservation['voucher_code'])) {
                $voucherCodes[] = $reservation['voucher_code'];
            }
        }
        $voucherCodesStr = !empty($voucherCodes) ? implode(', ', array_unique($voucherCodes)) : 'keine';
        
        // Get event time and category info from first reservation
        $eventTime = '';
        if (!empty($firstReservation['event_time'])) {
            $time = $firstReservation['event_time'];
            if (is_string($time) && strlen($time) >= 5) {
                $eventTime = substr($time, 0, 5) . ' Uhr'; // Format: HH:MM Uhr
            }
        }
        
        // Calculate total price (companions are free)
        $totalPrice = 0;
        foreach ($reservations as $reservation) {
            $totalPrice += $this->reservationPrice($reservation);
        }
        $priceStr = number_format($totalPrice, 2, ',', '.') . ' €';
        
        // Build ticket list (only for seat plan events)
        $ticketList = '';
        if (!$isNoSeatPlan) {
            $ticketList = '<ul>';
            foreach ($reservations as $reservation) {
                $seatLabel = $this->formatSeatIdentifier($reservation['seat_identifier'] ?? '');
                if ($seatLabel === '') {
                    continue;
                }
                $seatLine = htmlspecialchars($seatLabel);
                if (!empty($reservation['is_companion'])) {
                    $seatLine .= ' – <strong>Begleitperson kostenfrei</strong>';
                }
                $ticketList .= '<li>' . $seatLine . '</li>';
            }
            $ticketList .= '</ul>';
        }
        
        // Build clickable ticket link (inline-styled for email clients)
        $ticketLinkHtml = !empty($ticketLink)
            ? '<a href="' . htmlspecialchars($ticketLink) . '" style="display:inline-block;padding:12px 24px;background-color:#007bff;color:white;text-decoration:none;border-radius:4px;margin:20px 0;">Tickets herunterladen</a>'
            : '';

        // Build seatplan view link for customers
        $seatplanLink    = $this->buildSeatplanViewLink($reservations);
        $seatplanLinkHtml = !empty($seatplanLink)
            ? '<a href="' . htmlspecialchars($seatplanLink) . '" style="display:inline-block;padding:10px 20px;background-color:#6c757d;color:white;text-decoration:none;border-radius:4px;margin:10px 0;">Sitzplan ansehen</a>'
            : '';

        // Replace placeholders in subject and body
        $placeholders = [
            'customer_name'     => $toName,
            'ticket_link'       => $ticketLink,
            'ticket_link_html'  => $ticketLinkHtml,
            'seatplan_link'     => $seatplanLink,
            'seatplan_link_html' => $seatplanLinkHtml,
            'reservation_count' => count($reservations),
            'event_name'        => $firstReservation['event_name'] ?? '',
            'event_date'        => isset($firstReservation['event_date']) ? date('d.m.Y', strtotime($firstReservation['event_date'])) : '',
            'event_time'        => $eventTime,
            'category_name'     => $firstReservation['category_name'] ?? '',
            'price'             => $priceStr,
            'voucher_codes'     => $voucherCodesStr,
            'ticket_list'       => $ticketList,
            'seat_list'         => $ticketList,  // alias – consistent with reservation/pickup emails
        ];
        $subject = $this->replaceEmailPlaceholders($subject, $placeholders);
        $body    = $this->replaceEmailPlaceholders($bodyTemplate, $placeholders);

        $result = $this->sendEmail($toEmail, $toName, $subject, $body);
        $this->logEmail('ticket', $toEmail, $result, [
            'reservation_count' => count($reservations),
            'reservation_ids' => array_values(array_filter(array_map('intval', array_column($reservations, 'id')))),
            'event_id' => $eventId,
            'event_name' => $firstReservation['event_name'] ?? '',
            'event_date' => $firstReservation['event_date'] ?? '',
            'customer_id' => $firstReservation['customer_id'] ?? null,
        ]);
        return $result;
    }

    /**
     * Send reservation email
     *
     * @param string|null $replyTo  Optional Reply-To address
     * @param string|null $bcc      Optional BCC address (single recipient)
     */
    public function sendEmail(string $toEmail, string $toName, string $subject, string $body, bool $isHtml = true, ?string $replyTo = null, ?string $bcc = null): bool
    {
        $this->lastError = null;
        if ($this->useSmtp && !empty($this->smtpHost)) {
            return $this->sendViaSmtp($toEmail, $toName, $subject, $body, $isHtml, $replyTo, $bcc);
        }
        return $this->sendViaPhpMail($toEmail, $toName, $subject, $body, $isHtml, $replyTo, $bcc);
    }

    /**
     * Send a custom admin-composed email. Applies placeholder substitution
     * on subject + body and supports Reply-To / BCC.
     *
     * @param string      $toEmail       Recipient address
     * @param string      $toName        Recipient display name
     * @param string      $subject       Subject (placeholders allowed)
     * @param string      $bodyHtml      HTML body (placeholders allowed)
     * @param array       $placeholders  Values for {key} tokens
     * @param string|null $replyTo       Reply-To address (null = default)
     * @param string|null $bcc           BCC address (null = none)
     */
    public function sendCustomEmail(
        string $toEmail,
        string $toName,
        string $subject,
        string $bodyHtml,
        array  $placeholders = [],
        ?string $replyTo = null,
        ?string $bcc = null
    ): bool {
        if (!Config::get('email_enabled', true)) {
            $this->logEmail('custom', $toEmail, false, ['reason' => 'disabled']);
            return false;
        }

        $subject = $this->replaceEmailPlaceholders($subject, $placeholders);
        $body    = $this->replaceEmailPlaceholders($bodyHtml, $placeholders);

        $result = $this->sendEmail($toEmail, $toName, $subject, $body, true, $replyTo, $bcc);
        $this->logEmail('custom', $toEmail, $result, [
            'customer_id' => $placeholders['customer_id'] ?? null,
            'has_reply_to' => !empty($replyTo),
            'has_bcc'      => !empty($bcc),
        ]);
        return $result;
    }

    /**
     * Send reservation email (supports single reservation or array of reservations)
     */
    public function sendReservationEmail(string $toEmail, string $toName, array $reservations, string $reservationLink): bool
    {
        if (!Config::get('email_enabled', true)) {
            $this->logEmail('reservation', $toEmail, false, ['reason' => 'disabled']);
            return false;
        }

        // Support both single reservation array and array of reservations
        if (!isset($reservations[0]) || !is_array($reservations[0])) {
            $reservations = [$reservations];
        }
        $firstReservation = $reservations[0];

        $subject = Config::get('email_reservation_subject', 'Ihre Reservierung');
        $bodyTemplate = Config::get('email_reservation_body') ?: $this->getDefaultReservationEmailBody();

        $eventTime = '';
        if (!empty($firstReservation['event_time'])) {
            $time = $firstReservation['event_time'];
            if (is_string($time) && strlen($time) >= 5) {
                $eventTime = substr($time, 0, 5) . ' Uhr';
            }
        }

        $totalPrice = 0;
        foreach ($reservations as $res) {
            $totalPrice += $this->reservationPrice($res);
        }
        $priceStr = number_format($totalPrice, 2, ',', '.') . ' €';

        $seatList = $this->buildSeatList($reservations);

        $seatplanLink    = $this->buildSeatplanViewLink($reservations);
        $seatplanLinkHtml = !empty($seatplanLink)
            ? '<a href="' . htmlspecialchars($seatplanLink) . '" style="display:inline-block;padding:10px 20px;background-color:#6c757d;color:white;text-decoration:none;border-radius:4px;margin:10px 0;">Sitzplan ansehen</a>'
            : '';

        // Build reservation_link: link to the event page so customer can re-book if needed
        if (empty($reservationLink)) {
            $baseUrl = rtrim(ensurePublicInUrl(Config::get('ticket_url_start', absoluteUrl(''))), '/');
            $eventId = (int)($firstReservation['event_id'] ?? 0);
            $reservationLink = $eventId
                ? $baseUrl . '/event.php?id=' . $eventId
                : $baseUrl . '/';
        }

        $placeholders = [
            'customer_name'      => $toName,
            'event_name'         => $firstReservation['event_name'] ?? '',
            'event_date'         => isset($firstReservation['event_date']) ? date('d.m.Y', strtotime($firstReservation['event_date'])) : '',
            'event_time'         => $eventTime,
            'category_name'      => $firstReservation['category_name'] ?? '',
            'price'              => $priceStr,
            'seat_identifier'    => $this->formatSeatIdentifier($firstReservation['seat_identifier'] ?? ''),
            'seat_list'          => $seatList,
            'reservation_link'   => $reservationLink,
            'reservation_count'  => count($reservations),
            'seatplan_link'      => $seatplanLink,
            'seatplan_link_html' => $seatplanLinkHtml,
        ];
        $subject = $this->replaceEmailPlaceholders($subject, $placeholders);
        $body    = $this->replaceEmailPlaceholders($bodyTemplate, $placeholders);

        $result = $this->sendEmail($toEmail, $toName, $subject, $body);
        $this->logEmail('reservation', $toEmail, $result, [
            'event_name'        => $firstReservation['event_name'] ?? '',
            'event_date'        => $firstReservation['event_date'] ?? '',
            'reservation_count' => count($reservations),
            'reservation_ids'   => array_values(array_filter(array_map('intval', array_column($reservations, 'id')))),
            'customer_id'       => $firstReservation['customer_id'] ?? null,
        ]);
        return $result;
    }

    /**
     * Send pickup confirmation email (when reservation is marked as paid/collected)
     */
    public function sendPickupEmail(string $toEmail, string $toName, array $reservations): bool
    {
        if (!Config::get('email_enabled', true)) {
            $this->logEmail('pickup', $toEmail, false, ['reason' => 'disabled']);
            return false;
        }

        if (!isset($reservations[0]) || !is_array($reservations[0])) {
            $reservations = [$reservations];
        }
        $firstReservation = $reservations[0];

        $subject = Config::get('email_pickup_subject', 'Ihre Abholung bestätigt');
        $bodyTemplate = Config::get('email_pickup_body') ?: $this->getDefaultPickupEmailBody();

        $eventTime = '';
        if (!empty($firstReservation['event_time'])) {
            $time = $firstReservation['event_time'];
            if (is_string($time) && strlen($time) >= 5) {
                $eventTime = substr($time, 0, 5) . ' Uhr';
            }
        }

        $totalPrice = 0;
        foreach ($reservations as $res) {
            $totalPrice += $this->reservationPrice($res);
        }
        $priceStr = number_format($totalPrice, 2, ',', '.') . ' €';

        $seatList = $this->buildSeatList($reservations);

        $seatplanLink    = $this->buildSeatplanViewLink($reservations);
        $seatplanLinkHtml = !empty($seatplanLink)
            ? '<a href="' . htmlspecialchars($seatplanLink) . '" style="display:inline-block;padding:10px 20px;background-color:#6c757d;color:white;text-decoration:none;border-radius:4px;margin:10px 0;">Sitzplan ansehen</a>'
            : '';

        $placeholders = [
            'customer_name'      => $toName,
            'event_name'         => $firstReservation['event_name'] ?? '',
            'event_date'         => isset($firstReservation['event_date']) ? date('d.m.Y', strtotime($firstReservation['event_date'])) : '',
            'event_time'         => $eventTime,
            'category_name'      => $firstReservation['category_name'] ?? '',
            'price'              => $priceStr,
            'seat_list'          => $seatList,
            'reservation_count'  => count($reservations),
            'seatplan_link'      => $seatplanLink,
            'seatplan_link_html' => $seatplanLinkHtml,
        ];
        $subject = $this->replaceEmailPlaceholders($subject, $placeholders);
        $body    = $this->replaceEmailPlaceholders($bodyTemplate, $placeholders);

        $result = $this->sendEmail($toEmail, $toName, $subject, $body);
        $this->logEmail('pickup', $toEmail, $result, [
            'event_name'        => $firstReservation['event_name'] ?? '',
            'event_date'        => $firstReservation['event_date'] ?? '',
            'reservation_count' => count($reservations),
            'reservation_ids'   => array_values(array_filter(array_map('intval', array_column($reservations, 'id')))),
            'customer_id'       => $firstReservation['customer_id'] ?? null,
        ]);
        return $result;
    }

    /**
     * Send presales confirmation email (without download link)
     */
    public function sendPresalesEmail(string $toEmail, string $toName, array $reservations): bool
    {
        if (!Config::get('email_enabled', true)) {
            $this->logEmail('presales', $toEmail, false, ['reason' => 'disabled']);
            return false;
        }

        if (!isset($reservations[0]) || !is_array($reservations[0])) {
            $reservations = [$reservations];
        }
        $firstReservation = $reservations[0];

        $subject = Config::get('email_presales_subject', 'Ihre Tickets – Vorverkauf');
        $bodyTemplate = Config::get('email_presales_body') ?: $this->getDefaultPresalesEmailBody();

        $eventTime = '';
        if (!empty($firstReservation['event_time'])) {
            $time = $firstReservation['event_time'];
            if (is_string($time) && strlen($time) >= 5) {
                $eventTime = substr($time, 0, 5) . ' Uhr';
            }
        }

        $totalPrice = 0;
        foreach ($reservations as $res) {
            $totalPrice += $this->reservationPrice($res);
        }
        $priceStr = number_format($totalPrice, 2, ',', '.') . ' €';

        $seatList = $this->buildSeatList($reservations);

        $seatplanLink    = $this->buildSeatplanViewLink($reservations);
        $seatplanLinkHtml = !empty($seatplanLink)
            ? '<a href="' . htmlspecialchars($seatplanLink) . '" style="display:inline-block;padding:10px 20px;background-color:#6c757d;color:white;text-decoration:none;border-radius:4px;margin:10px 0;">Sitzplan ansehen</a>'
            : '';

        $placeholders = [
            'customer_name'      => $toName,
            'event_name'         => $firstReservation['event_name'] ?? '',
            'event_date'         => isset($firstReservation['event_date']) ? date('d.m.Y', strtotime($firstReservation['event_date'])) : '',
            'event_time'         => $eventTime,
            'category_name'      => $firstReservation['category_name'] ?? '',
            'price'              => $priceStr,
            'seat_list'          => $seatList,
            'reservation_count'  => count($reservations),
            'seatplan_link'      => $seatplanLink,
            'seatplan_link_html' => $seatplanLinkHtml,
        ];
        $subject = $this->replaceEmailPlaceholders($subject, $placeholders);
        $body    = $this->replaceEmailPlaceholders($bodyTemplate, $placeholders);

        $result = $this->sendEmail($toEmail, $toName, $subject, $body);
        $this->logEmail('presales', $toEmail, $result, [
            'event_name'        => $firstReservation['event_name'] ?? '',
            'event_date'        => $firstReservation['event_date'] ?? '',
            'reservation_count' => count($reservations),
            'reservation_ids'   => array_values(array_filter(array_map('intval', array_column($reservations, 'id')))),
            'customer_id'       => $firstReservation['customer_id'] ?? null,
        ]);
        return $result;
    }

    /**
     * Send VIP ticket email (with download link, no price)
     */
    public function sendVipEmail(string $toEmail, string $toName, array $reservations, string $ticketLink): bool
    {
        if (!Config::get('email_enabled', true)) {
            $this->logEmail('vip', $toEmail, false, ['reason' => 'disabled']);
            return false;
        }

        if (!isset($reservations[0]) || !is_array($reservations[0])) {
            $reservations = [$reservations];
        }
        $firstReservation = $reservations[0];

        $subject = Config::get('email_vip_subject', 'Ihre VIP-Tickets');
        $bodyTemplate = Config::get('email_vip_body') ?: $this->getDefaultVipEmailBody();

        $eventTime = '';
        if (!empty($firstReservation['event_time'])) {
            $time = $firstReservation['event_time'];
            if (is_string($time) && strlen($time) >= 5) {
                $eventTime = substr($time, 0, 5) . ' Uhr';
            }
        }

        $seatList = $this->buildSeatList($reservations);

        $ticketLinkHtml = !empty($ticketLink)
            ? '<a href="' . htmlspecialchars($ticketLink) . '" style="display:inline-block;padding:12px 24px;background-color:#007bff;color:white;text-decoration:none;border-radius:4px;margin:20px 0;">VIP-Tickets herunterladen</a>'
            : '';

        $seatplanLink    = $this->buildSeatplanViewLink($reservations);
        $seatplanLinkHtml = !empty($seatplanLink)
            ? '<a href="' . htmlspecialchars($seatplanLink) . '" style="display:inline-block;padding:10px 20px;background-color:#6c757d;color:white;text-decoration:none;border-radius:4px;margin:10px 0;">Sitzplan ansehen</a>'
            : '';

        $placeholders = [
            'customer_name'      => $toName,
            'event_name'         => $firstReservation['event_name'] ?? '',
            'event_date'         => isset($firstReservation['event_date']) ? date('d.m.Y', strtotime($firstReservation['event_date'])) : '',
            'event_time'         => $eventTime,
            'category_name'      => $firstReservation['category_name'] ?? '',
            'seat_list'          => $seatList,
            'reservation_count'  => count($reservations),
            'ticket_link'        => $ticketLink,
            'ticket_link_html'   => $ticketLinkHtml,
            'seatplan_link'      => $seatplanLink,
            'seatplan_link_html' => $seatplanLinkHtml,
        ];
        $subject = $this->replaceEmailPlaceholders($subject, $placeholders);
        $body    = $this->replaceEmailPlaceholders($bodyTemplate, $placeholders);

        $result = $this->sendEmail($toEmail, $toName, $subject, $body);
        $this->logEmail('vip', $toEmail, $result, [
            'event_name'        => $firstReservation['event_name'] ?? '',
            'event_date'        => $firstReservation['event_date'] ?? '',
            'reservation_count' => count($reservations),
            'reservation_ids'   => array_values(array_filter(array_map('intval', array_column($reservations, 'id')))),
            'customer_id'       => $firstReservation['customer_id'] ?? null,
        ]);
        return $result;
    }

    /**
     * Send voucher email
     */
    public function sendVoucherEmail(string $toEmail, string $toName, array $vouchers, ?string $voucherLink = null, bool $useTemplate = false): bool
    {
        if (!Config::get('email_enabled', true)) {
            $this->logEmail('voucher', $toEmail, false, ['reason' => 'disabled']);
            return false;
        }
        
        if (!isset($vouchers[0]) || !is_array($vouchers[0])) {
            $vouchers = [$vouchers];
        }
        
        $subject = Config::get('email_voucher_subject', count($vouchers) > 1 ? 'Ihre Gutscheine' : 'Ihr Gutschein');
        $bodyTemplate = Config::get('email_voucher_body') ?: $this->getDefaultVoucherEmailBody();
        
        $voucherListHtml = '';
        $voucherCodes = [];
        foreach ($vouchers as $voucher) {
            $voucherCodes[] = $voucher['voucher_code'] ?? '';
            $voucherListHtml .= '<div class="voucher-code">' . htmlspecialchars($voucher['voucher_code'] ?? '') . '</div>';
            if (!empty($voucher['event_name'])) {
                $voucherListHtml .= '<p>Veranstaltung: ' . htmlspecialchars($voucher['event_name']) . '</p>';
            }
            if (!empty($voucher['category_name'])) {
                $voucherListHtml .= '<p>Kategorie: ' . htmlspecialchars($voucher['category_name']) . '</p>';
            }
            if (!empty($voucher['category_price'])) {
                $qty = (int)($voucher['quantity'] ?? 1);
                $totalValue = (float)$voucher['category_price'] * $qty;
                $voucherListHtml .= '<p>Wert: ' . number_format($totalValue, 2, ',', '.') . ' €';
                if ($qty > 1) {
                    $voucherListHtml .= ' (' . $qty . ' × ' . number_format((float)$voucher['category_price'], 2, ',', '.') . ' €)';
                }
                $voucherListHtml .= '</p>';
            }
        }

        $ticketBaseUrl = rtrim(Config::get('ticket_url_start', ''), '/');

        // Sum up total quantity across all vouchers
        $totalQuantity = 0;
        foreach ($vouchers as $v) {
            $totalQuantity += (int)($v['quantity'] ?? 1);
        }

        $placeholders = [
            'customer_name' => $toName,
            'voucher_list'  => $voucherListHtml,
            'quantity'      => $totalQuantity,
            'voucher_codes' => implode(', ', $voucherCodes),
            'voucher_link'  => $voucherLink ?? '',
            'event_name'    => $vouchers[0]['event_name'] ?? '',
            'category_name' => $vouchers[0]['category_name'] ?? '',
            'ticket_url'    => $ticketBaseUrl,
        ];
        $subject = $this->replaceEmailPlaceholders($subject, $placeholders);
        $body    = $this->replaceEmailPlaceholders($bodyTemplate, $placeholders);

        $result = $this->sendEmail($toEmail, $toName, $subject, $body);
        $this->logEmail('voucher', $toEmail, $result, [
            'voucher_count' => count($vouchers),
            'voucher_codes' => $voucherCodes,
            'customer_id'   => $vouchers[0]['customer_id'] ?? null,
        ]);
        return $result;
    }

    /**
     * Send combined purchase email (tickets + vouchers)
     */
    public function sendCombinedPurchaseEmail(string $toEmail, string $toName, array $reservations = [], array $vouchers = [], ?string $ticketLink = null, ?string $voucherLink = null, bool $voucherUseTemplate = false, ?int $eventId = null): bool
    {
        if (!Config::get('email_enabled', true)) {
            $this->logEmail('combined', $toEmail, false, ['reason' => 'disabled']);
            return false;
        }
        
        $subject = Config::get('email_combined_purchase_subject', 'Ihre Bestellung');
        $bodyTemplate = Config::get('email_combined_purchase_body') ?: $this->getDefaultCombinedPurchaseEmailBody();
        
        $firstReservation = !empty($reservations) ? $reservations[0] : [];

        // Event time
        $eventTime = '';
        if (!empty($firstReservation['event_time'])) {
            $time = $firstReservation['event_time'];
            if (is_string($time) && strlen($time) >= 5) {
                $eventTime = substr($time, 0, 5) . ' Uhr';
            }
        }

        // Seat list
        $seatList = !empty($reservations) ? $this->buildSeatList($reservations) : '';

        // Total price (tickets + vouchers)
        $totalPrice = 0;
        foreach ($reservations as $res) {
            $totalPrice += $this->reservationPrice($res);
        }
        foreach ($vouchers as $v) {
            if (!empty($v['category_price'])) {
                $totalPrice += (float)$v['category_price'];
            }
        }
        $priceStr = number_format($totalPrice, 2, ',', '.') . ' €';

        // Ticket link HTML button
        $ticketLinkHtml = !empty($ticketLink)
            ? '<a href="' . htmlspecialchars($ticketLink) . '" style="display:inline-block;padding:12px 24px;background-color:#007bff;color:white;text-decoration:none;border-radius:4px;margin:20px 0;">Tickets herunterladen</a>'
            : '';

        // Voucher list HTML
        $voucherListHtml = '';
        $voucherCodes = [];
        foreach ($vouchers as $voucher) {
            $voucherListHtml .= '<p><strong>' . htmlspecialchars($voucher['voucher_code'] ?? '') . '</strong>';
            if (!empty($voucher['category_name'])) {
                $voucherListHtml .= '<br>Kategorie: ' . htmlspecialchars($voucher['category_name']);
            }
            if (!empty($voucher['category_price'])) {
                $voucherListHtml .= '<br>Wert: ' . number_format((float)$voucher['category_price'], 2, ',', '.') . ' €';
            }
            $voucherListHtml .= '</p>';
            $voucherCodes[] = $voucher['voucher_code'] ?? '';
        }

        // Pre-built ticket section block
        $eventName = $firstReservation['event_name'] ?? ($vouchers[0]['event_name'] ?? '');
        $eventDate = isset($firstReservation['event_date']) ? date('d.m.Y', strtotime($firstReservation['event_date'])) : '';

        $ticketSection = '';
        if (!empty($reservations) && $ticketLink) {
            $ticketSection = '<h3>Ihre Tickets</h3>';
            $ticketSection .= '<p>Sie haben ' . count($reservations) . ' Ticket(s) für "' . htmlspecialchars($eventName) . '" am ' . htmlspecialchars($eventDate) . '.</p>';
            $ticketSection .= '<p>' . $ticketLinkHtml . '</p>';
        }

        // Pre-built voucher section block
        $voucherSection = '';
        if (!empty($vouchers)) {
            $voucherSection = '<h3>Ihre Gutscheine</h3>';
            $voucherSection .= '<p>Sie haben ' . count($vouchers) . ' Gutschein(e):</p>';
            $voucherSection .= $voucherListHtml;
            if ($voucherUseTemplate && $voucherLink) {
                $voucherSection .= '<p><a href="' . htmlspecialchars($voucherLink) . '">Gutscheine anzeigen</a></p>';
            }
        }

        $ticketBaseUrl = rtrim(Config::get('ticket_url_start', ''), '/');
        $categoryName  = $firstReservation['category_name'] ?? ($vouchers[0]['category_name'] ?? '');

        $seatplanLink    = $this->buildSeatplanViewLink($reservations);
        $seatplanLinkHtml = !empty($seatplanLink)
            ? '<a href="' . htmlspecialchars($seatplanLink) . '" style="display:inline-block;padding:10px 20px;background-color:#6c757d;color:white;text-decoration:none;border-radius:4px;margin:10px 0;">Sitzplan ansehen</a>'
            : '';

        $placeholders = [
            'customer_name'      => $toName,
            'ticket_section'     => $ticketSection,
            'voucher_section'    => $voucherSection,
            'reservation_count'  => count($reservations),
            'event_name'         => $eventName,
            'event_date'         => $eventDate,
            'event_time'         => $eventTime,
            'category_name'      => $categoryName,
            'price'              => $priceStr,
            'seat_list'          => $seatList,
            'ticket_link'        => $ticketLink ?? '',
            'ticket_link_html'   => $ticketLinkHtml,
            'quantity'           => count($vouchers),
            'voucher_list'       => $voucherListHtml,
            'voucher_codes'      => implode(', ', $voucherCodes),
            'ticket_url'         => $ticketBaseUrl,
            'seatplan_link'      => $seatplanLink,
            'seatplan_link_html' => $seatplanLinkHtml,
        ];
        $subject = $this->replaceEmailPlaceholders($subject, $placeholders);
        $body    = $this->replaceEmailPlaceholders($bodyTemplate, $placeholders);

        $result = $this->sendEmail($toEmail, $toName, $subject, $body);
        $this->logEmail('combined', $toEmail, $result, [
            'reservation_count' => count($reservations),
            'voucher_count'     => count($vouchers),
            'customer_id'       => $firstReservation['customer_id'] ?? ($vouchers[0]['customer_id'] ?? null),
        ]);
        return $result;
    }

    /**
     * Sendet die zum Bestellstatus passende Bestaetigungs-/Ticket-E-Mail fuer
     * eine komplette Bestellgruppe (VIP, Ticket oder Reservierung).
     *
     * Gemeinsame Logik fuer "E-Mail erneut senden" (reservation_resend_email.php)
     * und den Re-Versand nach einer Umbuchung (rebook_seats.php).
     *
     * @param array $groupReservations Alle Reservierungen einer Bestellgruppe
     *                                 (z. B. aus Reservation::getGroupOf()).
     * @return array{sent:bool,type:?string,email:?string,reason:?string}
     */
    public function sendGroupConfirmationEmail(array $groupReservations): array
    {
        if (empty($groupReservations)) {
            return ['sent' => false, 'type' => null, 'email' => null, 'reason' => 'Reservierung nicht gefunden'];
        }

        $reservation = $groupReservations[0];

        if (empty($reservation['customer_id'])) {
            return ['sent' => false, 'type' => null, 'email' => null, 'reason' => 'Diese Reservierung hat keinen Kunden (Barverkauf)'];
        }

        $customerEmail = $reservation['email'] ?? '';
        if (empty($customerEmail)) {
            return ['sent' => false, 'type' => null, 'email' => null, 'reason' => 'Für diesen Kunden ist keine E-Mail-Adresse hinterlegt'];
        }

        if (!Config::get('email_enabled', true)) {
            return ['sent' => false, 'type' => null, 'email' => $customerEmail, 'reason' => 'E-Mail-Versand ist in den Einstellungen deaktiviert'];
        }

        $customerName = trim(($reservation['first_name'] ?? '') . ' ' . ($reservation['last_name'] ?? ''));
        $statusId     = (int)$reservation['status_id'];

        // Statuses that get a ticket email (paid) resp. a reservation confirmation
        $ticketStatuses      = [2, 4, 5, 8];
        $reservationStatuses = [3, 6];

        $allIds   = array_column($groupReservations, 'id');
        $idsParam = implode(',', $allIds);

        if ($statusId === 11) {
            $ok   = $this->sendVipEmail($customerEmail, $customerName ?: $customerEmail, $groupReservations, $this->buildTicketLink($allIds, $idsParam));
            $type = 'VIP-Ticket-E-Mail';
        } elseif (in_array($statusId, $ticketStatuses, true)) {
            $ok   = $this->sendTicketEmail($customerEmail, $customerName ?: $customerEmail, $groupReservations, $this->buildTicketLink($allIds, $idsParam), (int)($reservation['event_id'] ?? 0) ?: null);
            $type = 'Ticket-E-Mail';
        } elseif (in_array($statusId, $reservationStatuses, true)) {
            $ok   = $this->sendReservationEmail($customerEmail, $customerName ?: $customerEmail, $groupReservations, '');
            $type = 'Reservierungsbestätigung';
        } else {
            return ['sent' => false, 'type' => null, 'email' => $customerEmail, 'reason' => 'Für diesen Status ist kein E-Mail-Versand vorgesehen'];
        }

        return ['sent' => (bool)$ok, 'type' => $type, 'email' => $customerEmail, 'reason' => $ok ? null : 'E-Mail-Versand fehlgeschlagen'];
    }

    /**
     * Baut den signierten Ticket-Download-Link fuer eine Bestellgruppe.
     */
    private function buildTicketLink(array $allIds, string $idsParam): string
    {
        $baseUrl = Config::get('ticket_url_start', \absoluteUrl(''));
        $baseUrl = \ensurePublicInUrl($baseUrl);
        $token   = \generateTicketDownloadToken($allIds);
        return $baseUrl . '/api/ticket_download.php?reservation_ids=' . $idsParam . '&token=' . $token;
    }

    /**
     * Send email
     */
    private function sendViaSmtp(string $toEmail, string $toName, string $subject, string $body, bool $isHtml, ?string $replyTo = null, ?string $bcc = null): bool
    {
        // Use PHPMailer if available, otherwise use basic SMTP
        if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            return $this->sendViaPHPMailer($toEmail, $toName, $subject, $body, $isHtml, $replyTo, $bcc);
        }

        // Basic SMTP implementation using sockets
        $replyAddr = !empty($replyTo) ? $replyTo : $this->fromEmail;
        $headers = "From: {$this->fromName} <{$this->fromEmail}>\r\n";
        $headers .= "Reply-To: {$replyAddr}\r\n";
        if (!empty($bcc)) {
            $headers .= "Bcc: {$bcc}\r\n";
        }
        $headers .= "MIME-Version: 1.0\r\n";
        if ($isHtml) {
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        } else {
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        }

        // For now, fall back to PHP mail if SMTP is not fully configured
        $result = mail($toEmail, $subject, $body, $headers);
        if (!$result) {
            $this->lastError = 'mail()-Fallback fehlgeschlagen (PHPMailer nicht verfügbar)';
        }
        return $result;
    }

    /**
     * Send via PHP mail
     */
    private function sendViaPhpMail(string $toEmail, string $toName, string $subject, string $body, bool $isHtml, ?string $replyTo = null, ?string $bcc = null): bool
    {
        // Encode subject and From-name for UTF-8 (MIME base64)
        $encodedSubject  = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFromName = '=?UTF-8?B?' . base64_encode($this->fromName) . '?=';
        $replyAddr = !empty($replyTo) ? $replyTo : $this->fromEmail;
        $bccHeader = !empty($bcc) ? "Bcc: {$bcc}\r\n" : '';

        if ($isHtml) {
            // Encode body as base64 so HTML is reliably transmitted via mail()
            $encodedBody = chunk_split(base64_encode($body));
            $headers  = "From: {$encodedFromName} <{$this->fromEmail}>\r\n";
            $headers .= "Reply-To: {$replyAddr}\r\n";
            $headers .= $bccHeader;
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "Content-Transfer-Encoding: base64\r\n";
        } else {
            $encodedBody = $body;
            $headers  = "From: {$encodedFromName} <{$this->fromEmail}>\r\n";
            $headers .= "Reply-To: {$replyAddr}\r\n";
            $headers .= $bccHeader;
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $headers .= "Content-Transfer-Encoding: 8bit\r\n";
        }

        $result = mail($toEmail, $encodedSubject, $encodedBody, $headers);

        // Surface PHP-level mail() failures – e.g. "sendmail_path not set".
        if (!$result) {
            $lastError = error_get_last();
            if ($lastError && strpos($lastError['message'], 'mail') !== false) {
                $this->lastError = $lastError['message'];
                error_log("EmailService::sendViaPhpMail - PHP error: " . $lastError['message']);
            } else {
                $this->lastError = 'mail() fehlgeschlagen (kein PHP-Fehler verfügbar)';
            }
        }

        return $result;
    }

    /**
     * Send via PHPMailer (if available)
     */
    private function sendViaPHPMailer(string $toEmail, string $toName, string $subject, string $body, bool $isHtml, ?string $replyTo = null, ?string $bcc = null): bool
    {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $this->smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpUser;
            $mail->Password = $this->smtpPassword;
            if ($this->smtpSecure) {
                $mail->SMTPSecure = ($this->smtpPort === 465)
                    ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                    : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }
            $mail->Port = $this->smtpPort;
            $mail->CharSet  = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
            $mail->Encoding = \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64;

            // Only print the SMTP conversation in development. In production
            // it dumps every server response to error_log – fills disks and
            // leaks recipient addresses into log aggregators.
            if (($_ENV['APP_ENV'] ?? 'production') === 'development') {
                $mail->SMTPDebug = 2; // 0 = off, 1 = client, 2 = client + server
                $mail->Debugoutput = function ($str, $level) {
                    error_log("PHPMailer Debug ($level): $str");
                };
            }

            $mail->setFrom($this->fromEmail, $this->fromName);
            $mail->addAddress($toEmail, $toName);
            if (!empty($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($replyTo);
            }
            if (!empty($bcc) && filter_var($bcc, FILTER_VALIDATE_EMAIL)) {
                $mail->addBCC($bcc);
            }

            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body = $body;

            $result = $mail->send();
            if (!$result) {
                $this->lastError = $mail->ErrorInfo;
                error_log("EmailService::sendViaPHPMailer - Error: " . $mail->ErrorInfo);
            }
            return $result;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("EmailService::sendViaPHPMailer - Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Transport error of the most recent sendEmail() call (null on success).
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Build a secure customer seatplan view URL for a set of reservations.
     * Returns an empty string when the event has no seatplan or when the
     * necessary data (event_id, event_date) is missing.
     *
     * @param array $reservations  Array of reservation rows (same shape as getById() result)
     * @return string  Absolute URL or empty string
     */
    private function buildSeatplanViewLink(array $reservations): string
    {
        if (empty($reservations)) {
            return '';
        }

        $first = $reservations[0];

        // No seatplan events don't need a visual plan link
        if (str_starts_with($first['seat_identifier'] ?? '', 'no_seatplan_')) {
            return '';
        }

        $eventId   = (int)($first['event_id'] ?? 0);
        $eventDate = $first['event_date'] ?? '';

        if (!$eventId || !$eventDate) {
            return '';
        }

        $ids = array_column($reservations, 'id');
        $ids = array_filter(array_map('intval', $ids));

        if (empty($ids)) {
            return '';
        }

        try {
            $token = generateSeatplanViewToken(array_values($ids));
        } catch (\RuntimeException $e) {
            return '';
        }

        return \App\Helpers\UrlHelper::absoluteUrl(
            'seatplan_view.php'
            . '?event_id=' . $eventId
            . '&date=' . urlencode($eventDate)
            . '&reservation_ids=' . implode(',', $ids)
            . '&token=' . $token
        );
    }

    /**
     * Convert internal seat identifier to human-readable label.
     * seat_4_18  → "Reihe 4, Sitz 18"
     * no_seatplan_* → "" (hidden in seat lists)
     */
    private function formatSeatIdentifier(string $identifier): string
    {
        if (preg_match('/^seat_(\d+)_(\d+)$/', $identifier, $m)) {
            return 'Reihe ' . $m[1] . ', Sitz ' . $m[2];
        }
        if (preg_match('/^wheelchair_(\d+)_(\d+)$/', $identifier, $m)) {
            return 'Reihe ' . $m[1] . ', Sitz ' . $m[2] . ' (Rollstuhl)';
        }
        if (str_starts_with($identifier, 'no_seatplan_')) {
            return '';
        }
        return $identifier;
    }

    /**
     * Net price for a single reservation row, in EUR.
     * Companions (wheelchair accompaniment) are always free, so only the
     * wheelchair user — and every other seat — is charged.
     */
    private function reservationPrice(array $res): float
    {
        return \App\Helpers\TicketPrice::forReservation($res)['price'];
    }

    /**
     * Build a sorted HTML <ul> seat list from reservations.
     * Seats are sorted by row number then seat number (numeric, ascending).
     */
    private function buildSeatList(array $reservations): string
    {
        usort($reservations, function (array $a, array $b): int {
            $idA = $a['seat_identifier'] ?? '';
            $idB = $b['seat_identifier'] ?? '';
            preg_match('/(\d+)[_\-](\d+)$/', $idA, $mA);
            preg_match('/(\d+)[_\-](\d+)$/', $idB, $mB);
            $rowA  = isset($mA[1]) ? (int)$mA[1] : 0;
            $seatA = isset($mA[2]) ? (int)$mA[2] : 0;
            $rowB  = isset($mB[1]) ? (int)$mB[1] : 0;
            $seatB = isset($mB[2]) ? (int)$mB[2] : 0;
            return $rowA !== $rowB ? $rowA - $rowB : $seatA - $seatB;
        });

        $html = '<ul>';
        foreach ($reservations as $res) {
            $seatLabel = $this->formatSeatIdentifier($res['seat_identifier'] ?? '');
            if ($seatLabel === '') {
                continue;
            }
            $seatLine = htmlspecialchars($seatLabel);
            if (!empty($res['category_name'])) {
                $seatLine .= ' (' . htmlspecialchars($res['category_name']) . ')';
            }
            if (!empty($res['is_companion'])) {
                $seatLine .= ' – <strong>Begleitperson kostenfrei</strong>';
            }
            $html .= '<li>' . $seatLine . '</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    /**
     * Replace email placeholders.
     * Global placeholders (logo_html, company_name) are always injected.
     * If the template does not contain {logo_html}, the logo is injected
     * directly before the first <h1> inside any .header div.
     */
    private function replaceEmailPlaceholders(string $template, array $data): string
    {
        $logoHtml = $this->buildLogoHtml();
        $data += [
            'logo_html'    => $logoHtml,
            'company_name' => Config::get('company_name', ''),
        ];
        foreach ($data as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }

        // If the template had no {logo_html} placeholder but a logo is configured,
        // inject it automatically before the first heading inside the header block.
        if (!empty($logoHtml) && strpos($template, $logoHtml) === false) {
            $template = preg_replace(
                '#(<div[^>]*class=["\'][^"\']*header[^"\']*["\'][^>]*>)\s*(<h[1-6])#i',
                '$1' . $logoHtml . '$2',
                $template,
                1
            );
        }
        return $template;
    }

    /**
     * Build an <img> tag for the company logo (absolute URL), or empty string if no logo set.
     */
    private function buildLogoHtml(): string
    {
        $logoPath = Config::get('company_logo', '');
        if (empty($logoPath)) {
            return '';
        }
        // Use ticket_url_start (externally accessible URL configured by admin),
        // with /public/ ensured, so email clients can actually reach the image.
        // Fall back to absoluteUrl() only if ticket_url_start is not configured.
        $configuredBase = Config::get('ticket_url_start', '');
        if (!empty($configuredBase)) {
            $base = rtrim(ensurePublicInUrl($configuredBase), '/');
        } else {
            $base = rtrim(absoluteUrl(''), '/');
        }
        $logoUrl = $base . '/' . ltrim($logoPath, '/');
        return '<div style="text-align:right;margin-bottom:8px;"><img src="' . htmlspecialchars($logoUrl) . '" alt="Logo" style="max-height:60px;max-width:200px;object-fit:contain;"></div>';
    }

    /**
     * Get default ticket email body
     */
    private function getDefaultTicketEmailBody(bool $isNoSeatPlan = false): string
    {
        if ($isNoSeatPlan) {
            // Simplified email for events without seat plan
            return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #007bff; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background-color: #f9f9f9; }
        .button { display: inline-block; padding: 12px 24px; background-color: #007bff; color: white; text-decoration: none; border-radius: 4px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            {logo_html}
            <h1>Ihre Tickets</h1>
        </div>
        <div class="content">
            <p>Hallo {customer_name},</p>
            <p>vielen Dank für Ihre Bestellung!</p>
            <p>Sie haben {reservation_count} Ticket(s) für "{event_name}" am {event_date} bestellt.</p>
            <p>Sie können Ihre Tickets über den folgenden Link herunterladen:</p>
            <p style="text-align: center;">
                {ticket_link_html}
            </p>
            <p>Mit freundlichen Grüßen<br>Ihr Ticket-Team</p>
        </div>
        <div class="footer">
            <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht auf diese E-Mail.</p>
        </div>
    </div>
</body>
</html>';
        }
        
        // Full email with seat list for events with seat plan
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #007bff; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background-color: #f9f9f9; }
        .button { display: inline-block; padding: 12px 24px; background-color: #007bff; color: white; text-decoration: none; border-radius: 4px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            {logo_html}
            <h1>Ihre Tickets</h1>
        </div>
        <div class="content">
            <p>Hallo {customer_name},</p>
            <p>vielen Dank für Ihre Bestellung!</p>
            <p>Sie haben {reservation_count} Ticket(s) für "{event_name}" am {event_date} bestellt.</p>
            {ticket_list}
            <p>Sie können Ihre Tickets über den folgenden Link herunterladen:</p>
            <p style="text-align: center;">
                {ticket_link_html}
            </p>
            <p>Mit freundlichen Grüßen<br>Ihr Ticket-Team</p>
        </div>
        <div class="footer">
            <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht auf diese E-Mail.</p>
        </div>
    </div>
</body>
</html>';
    }

    /**
     * Get default reservation email body
     */
    private function getDefaultReservationEmailBody(): string
    {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #28a745; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background-color: #f9f9f9; }
        .button { display: inline-block; padding: 12px 24px; background-color: #28a745; color: white; text-decoration: none; border-radius: 4px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            {logo_html}
            <h1>Ihre Reservierung</h1>
        </div>
        <div class="content">
            <p>Hallo {customer_name},</p>
            <p>vielen Dank für Ihre Reservierung!</p>
            <p>Sie haben {reservation_count} Platz/Plätze für "{event_name}" am {event_date} um {event_time} reserviert.</p>
            {seat_list}
            <p>Mit freundlichen Grüßen<br>Ihr Ticket-Team</p>
        </div>
        <div class="footer">
            <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht auf diese E-Mail.</p>
        </div>
    </div>
</body>
</html>';
    }

    /**
     * Get default voucher email body
     */
    private function getDefaultVoucherEmailBody(): string
    {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #ffc107; color: #333; padding: 20px; text-align: center; }
        .content { padding: 20px; background-color: #f9f9f9; }
        .voucher-code { font-size: 24px; font-weight: bold; text-align: center; padding: 20px; background-color: #fff; border: 2px dashed #ffc107; margin: 20px 0; }
        .button { display: inline-block; padding: 12px 24px; background-color: #ffc107; color: #333; text-decoration: none; border-radius: 4px; margin: 20px 0; font-weight: bold; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            {logo_html}
            <h1>Ihr Gutschein</h1>
        </div>
        <div class="content">
            <p>Hallo {customer_name},</p>
            <p>vielen Dank für Ihren Gutscheinkauf!</p>
            <p>Sie haben {quantity} Gutschein(e) erhalten:</p>
            {voucher_list}
            <p>Mit freundlichen Grüßen<br>Ihr Ticket-Team</p>
        </div>
        <div class="footer">
            <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht auf diese E-Mail.</p>
        </div>
    </div>
</body>
</html>';
    }

    /**
     * Get default combined purchase email body
     */
    private function getDefaultCombinedPurchaseEmailBody(): string
    {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #007bff; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background-color: #f9f9f9; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            {logo_html}
            <h1>Ihre Bestellung</h1>
        </div>
        <div class="content">
            <p>Hallo {customer_name},</p>
            <p>vielen Dank für Ihre Bestellung!</p>
            {ticket_section}
            {voucher_section}
            <p>Mit freundlichen Grüßen<br>Ihr Ticket-Team</p>
        </div>
        <div class="footer">
            <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht auf diese E-Mail.</p>
        </div>
    </div>
</body>
</html>';
    }

    /**
     * Get default presales confirmation email body
     */
    private function getDefaultPresalesEmailBody(): string
    {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #198754; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background-color: #f9f9f9; }
        .seat-list { background-color: #fff; border: 1px solid #dee2e6; border-radius: 4px; padding: 12px 16px; margin: 12px 0; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            {logo_html}
            <h1>Ihre Tickets – Vorverkauf</h1>
        </div>
        <div class="content">
            <p>Hallo {customer_name},</p>
            <p>vielen Dank für Ihren Kartenkauf im Vorverkauf!</p>
            <p>Sie haben <strong>{reservation_count} Karte(n)</strong> für "<strong>{event_name}</strong>" am {event_date} um {event_time} erworben.</p>
            <div class="seat-list">{seat_list}</div>
            <p><strong>Gesamtpreis:</strong> {price}</p>
            <p>Ihre Karten wurden Ihnen direkt ausgehändigt.</p>
            <p>Wir freuen uns auf Ihren Besuch!</p>
            <p>Mit freundlichen Grüßen<br>Ihr Ticket-Team</p>
        </div>
        <div class="footer">
            <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht auf diese E-Mail.</p>
        </div>
    </div>
</body>
</html>';
    }

    /**
     * Get default VIP ticket email body
     */
    private function getDefaultVipEmailBody(): string
    {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #6f42c1; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background-color: #f9f9f9; }
        .seat-list { background-color: #fff; border: 1px solid #dee2e6; border-radius: 4px; padding: 12px 16px; margin: 12px 0; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            {logo_html}
            <h1>Ihre VIP-Tickets</h1>
        </div>
        <div class="content">
            <p>Hallo {customer_name},</p>
            <p>Sie erhalten <strong>{reservation_count} VIP-Karte(n)</strong> für "<strong>{event_name}</strong>" am {event_date} um {event_time}.</p>
            <div class="seat-list">{seat_list}</div>
            <p>Sie können Ihre Karten über folgenden Link herunterladen:</p>
            <p>{ticket_link_html}</p>
            <p>{seatplan_link_html}</p>
            <p>Wir freuen uns auf Ihren Besuch!</p>
            <p>Mit freundlichen Grüßen<br>Ihr Ticket-Team</p>
        </div>
        <div class="footer">
            <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht auf diese E-Mail.</p>
        </div>
    </div>
</body>
</html>';
    }

    /**
     * Zahlungsanforderung an Kunden senden (mit Bezahllink)
     */
    public function sendPaymentRequestEmail(
        string $toEmail,
        string $toName,
        float  $amount,
        string $description,
        string $paymentUrl
    ): bool {
        if (!Config::get('email_enabled', true)) {
            return false;
        }

        $subject      = Config::get('email_payment_request_subject', 'Ihre Zahlungsanforderung');
        $bodyTemplate = Config::get('email_payment_request_body') ?: $this->getDefaultPaymentRequestEmailBody();

        $amountFormatted = number_format($amount, 2, ',', '.') . ' €';

        $paymentLinkHtml = '<a href="' . htmlspecialchars($paymentUrl) . '" '
            . 'style="display:inline-block;padding:14px 28px;background-color:#007bff;color:white;'
            . 'text-decoration:none;border-radius:4px;font-size:16px;margin:20px 0;">Jetzt bezahlen</a>';

        $logoHtml = $this->buildLogoHtml();

        $placeholders = [
            'customer_name'    => $toName,
            'amount'           => $amountFormatted,
            'description'      => nl2br(htmlspecialchars($description)),
            'payment_link'     => $paymentUrl,
            'payment_link_html'=> $paymentLinkHtml,
            'logo_html'        => $logoHtml,
        ];

        $subject = $this->replaceEmailPlaceholders($subject, $placeholders);
        $body    = $this->replaceEmailPlaceholders($bodyTemplate, $placeholders);

        $result = $this->sendEmail($toEmail, $toName, $subject, $body);
        $this->logEmail('payment_request', $toEmail, $result, ['amount' => $amount]);
        return $result;
    }

    /**
     * Admin-Benachrichtigung nach eingegangener Zahlung
     */
    public function sendPaymentReceivedAdminEmail(
        string $adminEmail,
        string $customerName,
        string $customerEmail,
        float  $amount,
        string $description,
        int    $requestId
    ): bool {
        if (!Config::get('email_enabled', true)) {
            return false;
        }

        $subject = 'Zahlung eingegangen: ' . number_format($amount, 2, ',', '.') . ' € von ' . $customerName;

        $vipUrl = absoluteUrl('admin/vip_tickets.php');
        $listUrl = absoluteUrl('admin/payment_requests.php');

        $body = $this->getDefaultPaymentReceivedAdminEmailBody();

        $amountFormatted = number_format($amount, 2, ',', '.') . ' €';
        $vipLinkHtml = '<a href="' . htmlspecialchars($vipUrl) . '" '
            . 'style="display:inline-block;padding:12px 24px;background-color:#ffc107;color:#212529;'
            . 'text-decoration:none;border-radius:4px;margin:8px 4px;">Karten zuweisen (VIP-Tickets)</a>';
        $listLinkHtml = '<a href="' . htmlspecialchars($listUrl) . '" '
            . 'style="display:inline-block;padding:12px 24px;background-color:#6c757d;color:white;'
            . 'text-decoration:none;border-radius:4px;margin:8px 4px;">Alle Zahlungsanforderungen</a>';

        $logoHtml = $this->buildLogoHtml();

        $placeholders = [
            'customer_name'    => $customerName,
            'customer_email'   => $customerEmail,
            'amount'           => $amountFormatted,
            'description'      => nl2br(htmlspecialchars($description)),
            'request_id'       => (string)$requestId,
            'vip_link_html'    => $vipLinkHtml,
            'list_link_html'   => $listLinkHtml,
            'logo_html'        => $logoHtml,
        ];

        $body = $this->replaceEmailPlaceholders($body, $placeholders);

        $result = $this->sendEmail($adminEmail, 'Admin', $subject, $body);
        $this->logEmail('payment_received_admin', $adminEmail, $result, [
            'request_id'    => $requestId,
            'amount'        => $amount,
            'customer_name' => $customerName,
        ]);
        return $result;
    }

    /**
     * Standard-Template: Zahlungsanforderung an Kunden
     */
    private function getDefaultPaymentRequestEmailBody(): string
    {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #0d6efd; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background-color: #f9f9f9; }
        .amount-box { background: #fff; border: 2px solid #0d6efd; border-radius: 6px; padding: 16px 20px; margin: 16px 0; text-align: center; font-size: 28px; font-weight: bold; color: #0d6efd; }
        .description-box { background: #fff; border: 1px solid #dee2e6; border-radius: 4px; padding: 12px 16px; margin: 12px 0; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            {logo_html}
            <h1>Zahlungsanforderung</h1>
        </div>
        <div class="content">
            <p>Hallo {customer_name},</p>
            <p>Sie haben eine Zahlungsanforderung erhalten. Bitte zahlen Sie den folgenden Betrag:</p>
            <div class="amount-box">{amount}</div>
            <p><strong>Verwendungszweck:</strong></p>
            <div class="description-box">{description}</div>
            <p>Klicken Sie auf den folgenden Button, um die Zahlung sicher per PayPal abzuschließen:</p>
            <p style="text-align:center;">{payment_link_html}</p>
            <p>Oder kopieren Sie diesen Link in Ihren Browser:<br>
            <small style="color:#555;">{payment_link}</small></p>
            <p>Nach Eingang Ihrer Zahlung werden wir Ihnen die Eintrittskarten zukommen lassen.</p>
            <p>Mit freundlichen Grüßen<br>Ihr Ticket-Team</p>
        </div>
        <div class="footer">
            <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht auf diese E-Mail.</p>
        </div>
    </div>
</body>
</html>';
    }

    /**
     * Standard-Template: Admin-Benachrichtigung nach eingegangener Zahlung
     */
    private function getDefaultPaymentReceivedAdminEmailBody(): string
    {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #198754; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background-color: #f9f9f9; }
        .info-row { display: flex; gap: 8px; margin: 8px 0; }
        .info-label { font-weight: bold; min-width: 160px; }
        .amount-box { background: #d1e7dd; border: 1px solid #0f5132; border-radius: 4px; padding: 12px 16px; margin: 12px 0; font-size: 22px; font-weight: bold; color: #0f5132; }
        .action-box { background: #fff3cd; border: 1px solid #664d03; border-radius: 4px; padding: 16px; margin: 16px 0; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            {logo_html}
            <h1>&#10004; Zahlung eingegangen</h1>
        </div>
        <div class="content">
            <p>Eine Zahlung ist eingegangen:</p>
            <table style="width:100%;border-collapse:collapse;background:#fff;border:1px solid #dee2e6;border-radius:4px;">
                <tr><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;font-weight:bold;width:150px;">Anforderung #</td><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;">{request_id}</td></tr>
                <tr><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;font-weight:bold;">Kunde</td><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;">{customer_name}</td></tr>
                <tr><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;font-weight:bold;">E-Mail</td><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;">{customer_email}</td></tr>
                <tr><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;font-weight:bold;">Betrag</td><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;font-size:18px;font-weight:bold;color:#198754;">{amount}</td></tr>
                <tr><td style="padding:8px 12px;font-weight:bold;">Zweck</td><td style="padding:8px 12px;">{description}</td></tr>
            </table>

            <div class="action-box">
                <p style="margin:0 0 12px 0;font-weight:bold;">&#8594; Nächster Schritt: Karten zuweisen</p>
                <p style="margin:0 0 12px 0;">Weisen Sie dem Kunden jetzt die entsprechenden Karten zu und senden Sie ihm den Download-Link.</p>
                <p style="text-align:center;margin:0;">
                    {vip_link_html}
                    {list_link_html}
                </p>
            </div>
        </div>
        <div class="footer">
            <p>Diese E-Mail wurde automatisch generiert.</p>
        </div>
    </div>
</body>
</html>';
    }

    /**
     * Get default pickup confirmation email body
     */
    private function getDefaultPickupEmailBody(): string
    {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #0dcaf0; color: #000; padding: 20px; text-align: center; }
        .content { padding: 20px; background-color: #f9f9f9; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            {logo_html}
            <h1>Abholung bestätigt</h1>
        </div>
        <div class="content">
            <p>Hallo {customer_name},</p>
            <p>Ihre Tickets für "{event_name}" am {event_date} um {event_time} wurden erfolgreich abgeholt und bezahlt.</p>
            <p><strong>Anzahl Tickets:</strong> {reservation_count}</p>
            {seat_list}
            <p>Wir freuen uns auf Ihren Besuch!</p>
            <p>Mit freundlichen Grüßen<br>Ihr Ticket-Team</p>
        </div>
        <div class="footer">
            <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht auf diese E-Mail.</p>
        </div>
    </div>
</body>
</html>';
    }
}

