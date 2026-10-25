<?php
/**
 * Ticket PDF Generator Service
 * Generates PDF tickets from templates with dynamic content using TCPDF and FPDI
 */

namespace App\Services;

use App\Helpers\TicketCode;
use App\Helpers\TicketPrice;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\Customer;
use App\Models\SeatPlan;
use setasign\Fpdi\Tcpdf\Fpdi;

class TicketPdfGenerator
{
    private Event $eventModel;
    private Reservation $reservationModel;
    private Customer $customerModel;
    private SeatPlan $seatPlanModel;

    public function __construct()
    {
        $this->eventModel = new Event();
        $this->reservationModel = new Reservation();
        $this->customerModel = new Customer();
        $this->seatPlanModel = new SeatPlan();
    }

    /**
     * Resolve rendering parameters for the given scope.
     *
     * Customer scope always uses mode = 'template' (legacy behaviour).
     *
     * Presales scope honours the per-event print_mode stored in the presales
     * config JSON: 'template' (PDF background + placeholders), 'overlay'
     * (placeholders on template-sized blank page), or 'blank' (placeholders
     * on a blank page of configured width/height). If no presales config is
     * saved at all, the whole setup falls back to the customer template.
     *
     * @return array{mode:string, template_path:string, config:array, page_width:float, page_height:float}
     */
    private function resolveRenderConfig(array $event, string $scope): array
    {
        $presalesConfigJson = $event['presales_ticket_template_config'] ?? '';
        $customerConfigJson = $event['ticket_template_config'] ?? '';
        $presalesPdf = $event['presales_ticket_pdf_template'] ?? '';
        $customerPdf = $event['ticket_pdf_template'] ?? '';

        $usePresales = ($scope === 'presales' && !empty($presalesConfigJson));

        if ($usePresales) {
            $config = json_decode($presalesConfigJson, true) ?: [];
            $mode = $config['print_mode'] ?? 'template';
            if (!in_array($mode, ['template', 'overlay', 'blank'], true)) {
                $mode = 'template';
            }
            $templatePath = $presalesPdf ?: $customerPdf;
        } else {
            $config = !empty($customerConfigJson) ? (json_decode($customerConfigJson, true) ?: []) : [];
            $mode = 'template';
            $templatePath = $customerPdf;
        }

        return [
            'mode'        => $mode,
            'template_path' => $templatePath,
            'config'      => $config,
            'page_width'  => isset($config['page_width']) ? (float)$config['page_width'] : 0.0,
            'page_height' => isset($config['page_height']) ? (float)$config['page_height'] : 0.0,
        ];
    }

    /**
     * Render all configured placeholders onto the current PDF page.
     */
    private function renderPlaceholders(Fpdi $pdf, array $config, array $placeholders): void
    {
        if (empty($config['placeholders']) || !is_array($config['placeholders'])) {
            return;
        }
        foreach ($config['placeholders'] as $placeholderConfig) {
            if (empty($placeholderConfig['name']) || !isset($placeholderConfig['x']) || !isset($placeholderConfig['y'])) {
                continue;
            }
            $name = $placeholderConfig['name'];
            // ticket_code_notext prints the same code as ticket_code, only without text under a barcode
            $value = $placeholders[$name === 'ticket_code_notext' ? 'ticket_code' : $name] ?? '';
            if ($value === '' || $value === null) {
                continue;
            }
            if ($name === 'ticket_code' || $name === 'ticket_code_notext') {
                // ticket_code_notext: same code without the human-readable text under a barcode
                $this->renderTicketCode($pdf, (string)($placeholders['ticket_code_type'] ?? ''), (string)$value, $placeholderConfig, $name === 'ticket_code');
                continue;
            }

            $fontFamily = $placeholderConfig['font_family'] ?? 'Helvetica';
            $fontSize = isset($placeholderConfig['font_size']) ? (int)$placeholderConfig['font_size'] : 12;
            $fontStyle = (!empty($placeholderConfig['bold']) && $placeholderConfig['bold']) ? 'B' : '';
            $pdf->SetFont($fontFamily, $fontStyle, $fontSize);

            $x = (float)$placeholderConfig['x'];
            $y = (float)$placeholderConfig['y'];
            $align = $placeholderConfig['align'] ?? 'L';

            $r = isset($placeholderConfig['color_r']) ? (int)$placeholderConfig['color_r'] : 0;
            $g = isset($placeholderConfig['color_g']) ? (int)$placeholderConfig['color_g'] : 0;
            $b = isset($placeholderConfig['color_b']) ? (int)$placeholderConfig['color_b'] : 0;
            $pdf->SetTextColor($r, $g, $b);

            $pdf->SetXY($x, $y);
            $pdf->Cell(0, 0, $value, 0, 0, $align, false);
        }
    }

    /**
     * Draw the {ticket_code} placeholder as QR code or Code 128 barcode.
     * x/y = top left corner; width/height in mm (a QR code is square: width only).
     * Font size/colour apply to the human-readable text under the barcode.
     */
    private function renderTicketCode(Fpdi $pdf, string $type, string $content, array $config, bool $showText = true): void
    {
        $x = (float)$config['x'];
        $y = (float)$config['y'];
        $width = max(5.0, (float)($config['width'] ?? 25));
        $height = max(5.0, (float)($config['height'] ?? 15));
        $color = [(int)($config['color_r'] ?? 0), (int)($config['color_g'] ?? 0), (int)($config['color_b'] ?? 0)];

        if ($type === TicketCode::TYPE_QR) {
            $pdf->write2DBarcode($content, 'QRCODE,M', $x, $y, $width, $width, ['border' => false, 'padding' => 0, 'fgcolor' => $color, 'bgcolor' => false], 'N');
        } elseif ($type === TicketCode::TYPE_CODE128) {
            $pdf->write1DBarcode($content, 'C128', $x, $y, $width, $height, null, [
                'border' => false, 'padding' => 0, 'fgcolor' => $color, 'bgcolor' => false,
                'text' => $showText, 'font' => 'helvetica', 'fontsize' => (int)($config['font_size'] ?? 8), 'stretchtext' => 0,
                'stretch' => true, 'fitwidth' => false,
            ], 'N');
        }
    }

    /**
     * Add a blank page sized from the configured page_width/page_height.
     * Falls back to A4 if dimensions are missing.
     */
    private function addBlankPage(Fpdi $pdf, float $pageWidth, float $pageHeight): void
    {
        if ($pageWidth <= 0) {
            $pageWidth = 210.0;
        }
        if ($pageHeight <= 0) {
            $pageHeight = 297.0;
        }
        $orientation = ($pageWidth > $pageHeight) ? 'L' : 'P';
        $pdf->AddPage($orientation, [$pageWidth, $pageHeight]);
    }

    /**
     * Generate PDF for a reservation, saved to storage/generated_tickets/.
     *
     * @return string|null relative path of the generated PDF
     */
    public function generateTicket(int $reservationId, string $scope = 'customer'): ?string
    {
        $reservation = $this->reservationModel->getById($reservationId);
        if (!$reservation) {
            throw new \RuntimeException("Reservation not found: $reservationId");
        }

        $event = $this->eventModel->getById($reservation['event_id']);
        if (!$event) {
            throw new \RuntimeException("Event not found or no template configured");
        }

        $render = $this->resolveRenderConfig($event, $scope);

        $customer = null;
        if ($reservation['customer_id']) {
            $customer = $this->customerModel->getById($reservation['customer_id']);
        }

        return $this->generatePdfWithPlaceholders($event, $reservation, $customer, $render);
    }

    /**
     * Build PDF file for a single reservation and save it.
     */
    private function generatePdfWithPlaceholders(array $event, array $reservation, ?array $customer, array $render): string
    {
        $placeholders = $this->getPlaceholderValues($event, $reservation, $customer);

        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Ticket System');
        $pdf->SetAuthor('Ticket System');
        $pdf->SetTitle('Ticket: ' . $event['name']);
        $pdf->SetSubject('Event Ticket');

        if ($render['mode'] === 'blank') {
            $this->addBlankPage($pdf, $render['page_width'], $render['page_height']);
        } else {
            if (empty($render['template_path'])) {
                throw new \RuntimeException("Event not found or no template configured");
            }
            $templatePath = __DIR__ . '/../../' . $render['template_path'];
            if (!file_exists($templatePath)) {
                throw new \RuntimeException("Template file not found: $templatePath");
            }

            $pageCount = $pdf->setSourceFile($templatePath);
            if ($pageCount === 0) {
                throw new \RuntimeException("Template PDF has no pages");
            }
            $templateId = $pdf->importPage(1);
            $size = $pdf->getTemplateSize($templateId);
            $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
            $pdf->AddPage($orientation, [$size['width'], $size['height']]);
            if ($render['mode'] === 'template') {
                $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height'], true);
            }
        }

        $this->renderPlaceholders($pdf, $render['config'], $placeholders);

        $outputDir = __DIR__ . '/../../storage/generated_tickets/';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $outputFileName = 'ticket_' . $reservation['id'] . '_' . time() . '.pdf';
        $outputPath = $outputDir . $outputFileName;
        $pdf->Output('F', $outputPath);

        return 'storage/generated_tickets/' . $outputFileName;
    }

    /**
     * Generate PDF and output directly to browser for multiple reservations.
     */
    public function outputMultipleTickets(array $reservationIds, string $filename = 'tickets.pdf', string $scope = 'customer'): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        if (headers_sent($file, $line)) {
            throw new \RuntimeException("Headers already sent in $file on line $line");
        }
        if (empty($reservationIds)) {
            throw new \RuntimeException("No reservation IDs provided");
        }

        $firstReservation = $this->reservationModel->getById($reservationIds[0]);
        if (!$firstReservation) {
            throw new \RuntimeException("Reservation not found: " . $reservationIds[0]);
        }

        $event = $this->eventModel->getById($firstReservation['event_id']);
        if (!$event) {
            throw new \RuntimeException("Event not found or no template configured");
        }

        $render = $this->resolveRenderConfig($event, $scope);

        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Ticket System');
        $pdf->SetAuthor('Ticket System');
        $pdf->SetTitle('Tickets: ' . $event['name']);
        $pdf->SetSubject('Event Tickets');

        $pageCount = 1;
        if ($render['mode'] !== 'blank') {
            if (empty($render['template_path'])) {
                throw new \RuntimeException("Event not found or no template configured");
            }
            $templatePath = __DIR__ . '/../../' . $render['template_path'];
            if (!file_exists($templatePath)) {
                throw new \RuntimeException("Template file not found: $templatePath");
            }
            $pageCount = $pdf->setSourceFile($templatePath);
            if ($pageCount === 0) {
                throw new \RuntimeException("Template PDF has no pages");
            }
        }

        foreach ($reservationIds as $reservationId) {
            $reservation = $this->reservationModel->getById($reservationId);
            if (!$reservation) {
                continue;
            }

            $customer = null;
            if ($reservation['customer_id']) {
                $customer = $this->customerModel->getById($reservation['customer_id']);
            }

            $placeholders = $this->getPlaceholderValues($event, $reservation, $customer);

            if ($render['mode'] === 'blank') {
                $this->addBlankPage($pdf, $render['page_width'], $render['page_height']);
                $this->renderPlaceholders($pdf, $render['config'], $placeholders);
            } else {
                for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                    $templateId = $pdf->importPage($pageNo);
                    $size = $pdf->getTemplateSize($templateId);
                    $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
                    $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                    if ($render['mode'] === 'template') {
                        $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height'], true);
                    }
                    if ($pageNo === 1) {
                        $this->renderPlaceholders($pdf, $render['config'], $placeholders);
                    }
                }
            }
        }

        $cleanFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        if (empty($cleanFilename)) {
            $cleanFilename = 'tickets.pdf';
        }
        if (strtolower(substr($cleanFilename, -4)) !== '.pdf') {
            $cleanFilename .= '.pdf';
        }
        $cleanFilename = strtolower($cleanFilename);

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $cleanFilename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        header('Content-Transfer-Encoding: binary');

        $pdf->Output();
    }

    /**
     * Generate a single ticket and stream it to the browser.
     */
    public function outputTicket(int $reservationId, string $filename = 'ticket.pdf', string $scope = 'customer'): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        if (headers_sent($file, $line)) {
            throw new \RuntimeException("Headers already sent in $file on line $line");
        }

        $reservation = $this->reservationModel->getById($reservationId);
        if (!$reservation) {
            throw new \RuntimeException("Reservation not found: $reservationId");
        }

        $event = $this->eventModel->getById($reservation['event_id']);
        if (!$event) {
            throw new \RuntimeException("Event not found or no template configured");
        }

        $render = $this->resolveRenderConfig($event, $scope);

        $customer = null;
        if ($reservation['customer_id']) {
            $customer = $this->customerModel->getById($reservation['customer_id']);
        }

        $placeholders = $this->getPlaceholderValues($event, $reservation, $customer);

        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Ticket System');
        $pdf->SetAuthor('Ticket System');
        $pdf->SetTitle('Ticket: ' . $event['name']);
        $pdf->SetSubject('Event Ticket');

        if ($render['mode'] === 'blank') {
            $this->addBlankPage($pdf, $render['page_width'], $render['page_height']);
            $this->renderPlaceholders($pdf, $render['config'], $placeholders);
        } else {
            if (empty($render['template_path'])) {
                throw new \RuntimeException("Event not found or no template configured");
            }
            $templatePath = __DIR__ . '/../../' . $render['template_path'];
            if (!file_exists($templatePath)) {
                throw new \RuntimeException("Template file not found: $templatePath");
            }
            $pageCount = $pdf->setSourceFile($templatePath);
            if ($pageCount === 0) {
                throw new \RuntimeException("Template PDF has no pages");
            }
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);
                $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                if ($render['mode'] === 'template') {
                    $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height'], true);
                }
                if ($pageNo === 1) {
                    $this->renderPlaceholders($pdf, $render['config'], $placeholders);
                }
            }
        }

        $cleanFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        if (empty($cleanFilename)) {
            $cleanFilename = 'ticket.pdf';
        }
        if (strtolower(substr($cleanFilename, -4)) !== '.pdf') {
            $cleanFilename .= '.pdf';
        }
        $cleanFilename = strtolower($cleanFilename);

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $cleanFilename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        header('Content-Transfer-Encoding: binary');

        $pdf->Output();
    }

    /**
     * Generate a preview PDF with demo data.
     *
     * The editor passes a live $config from the URL which may include
     * print_mode / page_width / page_height — those override the stored
     * event config so the admin sees their in-progress edits.
     */
    public function outputPreview(int $eventId, array $config = [], string $scope = 'customer'): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }

        $event = $this->eventModel->getById($eventId);
        if (!$event) {
            throw new \RuntimeException("Event not found or no template configured");
        }

        $mode = $config['print_mode'] ?? 'template';
        if (!in_array($mode, ['template', 'overlay', 'blank'], true)) {
            $mode = 'template';
        }
        $pageWidth = isset($config['page_width']) ? (float)$config['page_width'] : 0.0;
        $pageHeight = isset($config['page_height']) ? (float)$config['page_height'] : 0.0;

        // Resolve template PDF (only needed for template/overlay modes)
        $templateRelative = '';
        if ($mode !== 'blank') {
            if ($scope === 'presales' && !empty($event['presales_ticket_pdf_template'])) {
                $templateRelative = $event['presales_ticket_pdf_template'];
            } else {
                $templateRelative = $event['ticket_pdf_template'] ?? '';
            }
            if (empty($templateRelative)) {
                throw new \RuntimeException("Event not found or no template configured");
            }
        }

        $altLocation = trim((string)($event['alt_location_name'] ?? ''));

        $previewShow = $this->previewShowDate($event);
        $previewPrice = $this->previewCategoryPrice($event, $previewShow['date']);
        $placeholders = [
            'event_name'          => $event['name'] ?? 'Beispiel Veranstaltung',
            'event_date'          => date('d.m.Y', strtotime($previewShow['date'])),
            'event_time'          => date('H:i', strtotime($previewShow['time'])) . ' Uhr',
            'seat_identifier'     => 'Reihe 5, Platz 12',
            'customer_name'       => 'Max Mustermann',
            'customer_email'      => 'max.mustermann@example.com',
            'ticket_number'       => 'T-00001234',
            'category_name'       => $previewPrice['category_name'],
            'price'               => $previewPrice['price'],
            'price_type'          => $previewPrice['price_type'],
            'fn_card_number'      => '12345678',
            // Preview shows the event's code type, or a QR code so the placeholder is visible while positioning
            'ticket_code_type'    => ($event['ticket_code_type'] ?? '') ?: TicketCode::TYPE_QR,
            'ticket_code'         => TicketCode::content(
                (string)($event['ticket_code_content'] ?? ''),
                ['id' => 1234, 'event_id' => $eventId, 'event_date' => $previewShow['date'], 'seat_identifier' => 'seat_5_12'],
                ($event['ticket_code_type'] ?? '') ?: TicketCode::TYPE_QR
            ),
            'alt_seat_identifier' => 'Reihe 2, Platz 7',
            'alt_row'             => 'Reihe 2',
            'alt_seat'            => 'Platz 7',
            'alt_location'        => $altLocation !== '' ? $altLocation : 'Ausweichspielstätte',
            'alt_full'            => ($altLocation !== '' ? $altLocation : 'Ausweichspielstätte') . ': Reihe 2, Platz 7',
        ];

        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Ticket System');
        $pdf->SetAuthor('Ticket System');
        $pdf->SetTitle('Ticket Preview: ' . $event['name']);
        $pdf->SetSubject('Event Ticket Preview');

        if ($mode === 'blank') {
            $this->addBlankPage($pdf, $pageWidth, $pageHeight);
            $this->renderPlaceholders($pdf, $config, $placeholders);
        } else {
            $templatePath = __DIR__ . '/../../' . $templateRelative;
            if (!file_exists($templatePath)) {
                throw new \RuntimeException("Template file not found: $templatePath");
            }
            $pageCount = $pdf->setSourceFile($templatePath);
            if ($pageCount === 0) {
                throw new \RuntimeException("Template PDF has no pages");
            }
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);
                $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                if ($mode === 'template') {
                    $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height'], true);
                }
                if ($pageNo === 1) {
                    $this->renderPlaceholders($pdf, $config, $placeholders);
                }
            }
        }

        while (ob_get_level()) {
            ob_end_clean();
        }
        $pdf->Output();
        exit;
    }

    /**
     * Show date/time for the template preview: the next upcoming date of the event, otherwise
     * its most recent one; without any dates today. Time as on the real ticket: start time of
     * the date, else of the event, else the general start time from the settings.
     *
     * @return array{date: string, time: string} Y-m-d and H:i:s
     */
    private function previewShowDate(array $event): array
    {
        $dates = $this->eventModel->getDates((int)$event['id']);
        $today = date('Y-m-d');
        $show = null;
        foreach ($dates as $date) {
            if ($date['date'] >= $today) {
                $show = $date;
                break;
            }
        }
        $show = $show ?? ($dates ? end($dates) : null);

        $time = $show['show_start_time'] ?? null
            ?: ($event['show_start_time'] ?? null)
            ?: \App\Core\Config::get('show_start_time', '20:30');

        return ['date' => $show['date'] ?? $today, 'time' => (string)$time];
    }

    /**
     * Category and price for the template preview, taken from the event: the first seat plan
     * category (or the category of an event without seat plan). If it has a reduced price, the
     * preview shows that one so {price_type} is visible too. Same price rule as real tickets.
     *
     * @return array{category_name: string, price: string, price_type: string}
     */
    private function previewCategoryPrice(array $event, string $showDate): array
    {
        $categoryModel = new \App\Models\SeatCategory();
        $categories = $categoryModel->getByEvent((int)$event['id']);
        $category = $categories[0] ?? null;
        if ($category === null && !empty($event['seat_category_id'])) {
            $category = $categoryModel->getById((int)$event['seat_category_id']);
        }
        if ($category === null) {
            return ['category_name' => 'Kategorie A', 'price' => '25,00 €', 'price_type' => 'Ermäßigt'];
        }

        $ticketPrice = TicketPrice::forReservation([
            'category_id' => $category['id'],
            'event_id' => $event['id'],
            'event_date' => $showDate,
            'is_reduced' => (float)($category['reduced_price'] ?? 0) > 0 ? 1 : 0,
        ]);

        return [
            'category_name' => (string)$category['name'],
            'price' => number_format($ticketPrice['price'], 2, ',', '.') . ' €',
            'price_type' => TicketPrice::typeLabel($ticketPrice['type']),
        ];
    }

    /**
     * Build the map of placeholder values for a concrete reservation.
     */
    private function getPlaceholderValues(array $event, array $reservation, ?array $customer): array
    {
        $seatDisplayName = $this->seatPlanModel->getSeatDisplayName(
            $event['id'],
            $reservation['seat_identifier']
        );

        $categoryName = '';
        if (!empty($reservation['category_id'])) {
            $categoryName = $reservation['category_name'] ?? '';
        }

        $showStartTime = '20:30:00';
        if (!empty($reservation['event_date'])) {
            $eventDate = $this->eventModel->getDateByEventAndDate($event['id'], $reservation['event_date']);
            if ($eventDate && !empty($eventDate['show_start_time'])) {
                $showStartTime = $eventDate['show_start_time'];
            } elseif (!empty($event['show_start_time'])) {
                $showStartTime = $event['show_start_time'];
            }
        } elseif (!empty($event['show_start_time'])) {
            $showStartTime = $event['show_start_time'];
        }

        $timeFormatted = date('H:i', strtotime($showStartTime)) . ' Uhr';

        $codeType = (string)($event['ticket_code_type'] ?? '');
        $ticketPrice = TicketPrice::forReservation($reservation);
        $priceType = TicketPrice::typeLabel($ticketPrice['type']);
        if (!empty($reservation['is_companion'])) {
            // Wheelchair companion ticket – always free; (B) = Begleitperson
            $priceFormatted = '0 € (B)';
        } elseif ((int)($reservation['status_id'] ?? 0) === 11) {
            $priceFormatted = 'VIP';
            $priceType = '';
        } else {
            $priceFormatted = number_format($ticketPrice['price'], 2, ',', '.') . ' €';
        }

        // Alternativbelegung (Ausweichspielstätte), sofern für diesen Sitz hinterlegt
        $alternative = $this->seatPlanModel->getAlternativeSeat(
            (int)$event['id'],
            $reservation['seat_identifier']
        );
        $altLocation = trim((string)($event['alt_location_name'] ?? ''));
        $altIdentifier = $alternative
            ? SeatPlan::formatAlternativeSeat($alternative['row'], $alternative['seat'])
            : '';
        $altFull = '';
        if ($altIdentifier !== '') {
            $altFull = $altLocation !== '' ? $altLocation . ': ' . $altIdentifier : $altIdentifier;
        }

        return [
            'event_name'          => $event['name'] ?? '',
            'event_date'          => date('d.m.Y', strtotime($reservation['event_date'])),
            'event_time'          => $timeFormatted,
            'seat_identifier'     => $seatDisplayName,
            'customer_name'       => $customer ? trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')) : ($reservation['customer_id'] ? '' : 'Barverkauf'),
            'customer_email'      => $customer ? ($customer['email'] ?? '') : '',
            'ticket_number'       => 'T-' . str_pad($reservation['id'], 8, '0', STR_PAD_LEFT),
            'category_name'       => $categoryName,
            'price'               => $priceFormatted,
            'price_type'          => $priceType,
            'fn_card_number'      => !empty($reservation['is_fn_card']) ? (string)($reservation['fn_card_number'] ?? '') : '',
            'ticket_code'         => $codeType !== '' ? TicketCode::content((string)($event['ticket_code_content'] ?? ''), $reservation, $codeType) : '',
            'ticket_code_type'    => $codeType,
            'alt_seat_identifier' => $altIdentifier,
            'alt_row'             => $alternative ? SeatPlan::formatAlternativeSeat($alternative['row'], null) : '',
            'alt_seat'            => $alternative ? SeatPlan::formatAlternativeSeat(null, $alternative['seat']) : '',
            'alt_location'        => $altIdentifier !== '' ? $altLocation : '',
            'alt_full'            => $altFull,
        ];
    }
}
