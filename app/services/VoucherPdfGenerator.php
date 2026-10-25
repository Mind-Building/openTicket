<?php
/**
 * Voucher PDF Generator Service
 * Generates PDF vouchers from templates with dynamic content using TCPDF and FPDI
 */

namespace App\Services;

use App\Models\Event;
use App\Models\Voucher;
use App\Models\Customer;
use setasign\Fpdi\Tcpdf\Fpdi;

class VoucherPdfGenerator
{
    private Event $eventModel;
    private Voucher $voucherModel;
    private Customer $customerModel;

    public function __construct()
    {
        $this->eventModel = new Event();
        $this->voucherModel = new Voucher();
        $this->customerModel = new Customer();
    }

    /**
     * Generate PDF for multiple vouchers
     * @param array $voucherIds
     * @return string|null PDF content or null on error
     */
    public function generateVouchers(array $voucherIds): ?string
    {
        if (empty($voucherIds)) {
            throw new \RuntimeException("No voucher IDs provided");
        }

        // Get all vouchers
        $vouchers = [];
        $eventId = null;
        foreach ($voucherIds as $vid) {
            $voucher = $this->voucherModel->getById($vid);
            if (!$voucher) {
                throw new \RuntimeException("Voucher not found: $vid");
            }
            $vouchers[] = $voucher;
            if ($eventId === null) {
                $eventId = $voucher['event_id'];
            }
        }

        $event = $this->eventModel->getById($eventId);
        if (!$event || empty($event['voucher_template'])) {
            throw new \RuntimeException("Event not found or no voucher template configured");
        }

        // Load template config
        $config = [];
        if (!empty($event['voucher_template_config'])) {
            $config = json_decode($event['voucher_template_config'], true) ?: [];
        }

        // Generate PDF
        return $this->generatePdfWithPlaceholders($event, $vouchers, $config);
    }

    /**
     * Generate PDF with placeholders replaced
     */
    private function generatePdfWithPlaceholders(array $event, array $vouchers, array $config): string
    {
        $templatePath = __DIR__ . '/../../' . $event['voucher_template'];
        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Template file not found: $templatePath");
        }

        // Create FPDI instance (extends TCPDF)
        $pdf = new Fpdi();
        
        // Disable auto page break
        $pdf->SetAutoPageBreak(false);
        
        // Set document information
        $pdf->SetCreator('Ticket System');
        $pdf->SetAuthor('Ticket System');
        $pdf->SetTitle('Gutschein: ' . $event['name']);
        $pdf->SetSubject('Event Voucher');

        // Import template page
        $pageCount = $pdf->setSourceFile($templatePath);
        if ($pageCount === 0) {
            throw new \RuntimeException("Template PDF has no pages");
        }

        // Get customer data from first voucher
        $customer = null;
        if (!empty($vouchers[0]['customer_id'])) {
            $customer = $this->customerModel->getById($vouchers[0]['customer_id']);
        }

        // Generate one page per voucher
        foreach ($vouchers as $voucher) {
            // Import first page of template
            $templateId = $pdf->importPage(1);
            $size = $pdf->getTemplateSize($templateId);
            
            // Add page with template size
            $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
            $pdf->AddPage($orientation, [$size['width'], $size['height']]);
            
            // Use the imported page
            $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height'], true);
            
            // Get placeholder values for this voucher
            $placeholders = $this->getPlaceholderValues($event, $voucher, $customer);
            
            // Replace placeholders in PDF
            $this->replacePlaceholdersInPdf($pdf, $placeholders, $config);
        }

        // Return PDF as string
        return $pdf->Output('', 'S');
    }

    /**
     * Get placeholder values for voucher
     */
    private function getPlaceholderValues(array $event, array $voucher, ?array $customer): array
    {
        $values = [
            '{event_name}' => $event['name'] ?? '',
            '{voucher_code}' => $voucher['voucher_code'] ?? '',
            '{category_name}' => $voucher['category_name'] ?? '',
            '{quantity}' => $voucher['quantity'] ?? 1,
            '{order_date}' => isset($voucher['order_date']) ? date('d.m.Y', strtotime($voucher['order_date'])) : '',
        ];

        if ($customer) {
            $values['{customer_name}'] = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
            $values['{customer_first_name}'] = $customer['first_name'] ?? '';
            $values['{customer_last_name}'] = $customer['last_name'] ?? '';
            $values['{customer_email}'] = $customer['email'] ?? '';
            $values['{customer_street}'] = $customer['street'] ?? '';
            $values['{customer_house_number}'] = $customer['house_number'] ?? '';
            $values['{customer_postal_code}'] = $customer['postal_code'] ?? '';
            $values['{customer_city}'] = $customer['city'] ?? '';
        } else {
            $values['{customer_name}'] = '';
            $values['{customer_first_name}'] = '';
            $values['{customer_last_name}'] = '';
            $values['{customer_email}'] = '';
            $values['{customer_street}'] = '';
            $values['{customer_house_number}'] = '';
            $values['{customer_postal_code}'] = '';
            $values['{customer_city}'] = '';
        }

        return $values;
    }

    /**
     * Replace placeholders in PDF
     */
    private function replacePlaceholdersInPdf(Fpdi $pdf, array $placeholders, array $config): void
    {
        // Get text positions from config or use defaults
        $textPositions = $config['text_positions'] ?? [];
        
        foreach ($placeholders as $placeholder => $value) {
            // Find position for this placeholder (placeholder includes {})
            $position = $textPositions[$placeholder] ?? null;
            
            if ($position) {
                $x = $position['x'] ?? 0;
                $y = $position['y'] ?? 0;
                $fontSize = $position['font_size'] ?? 12;
                $fontFamily = strtolower($position['font_family'] ?? 'helvetica');
                $fontStyle = $position['font_style'] ?? '';
                $color = $position['color'] ?? [0, 0, 0];
                
                // Set font
                $pdf->SetFont($fontFamily, $fontStyle, $fontSize);
                $pdf->SetTextColor($color[0], $color[1], $color[2]);
                
                // Write text
                $pdf->SetXY($x, $y);
                $pdf->Write(0, $value);
            }
        }
    }
}

