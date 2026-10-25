<?php

declare(strict_types=1);

namespace Tests\Unit\Pdf;

use PHPUnit\Framework\TestCase;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Tests the FPDI + TCPDF library integration.
 *
 * These tests validate the exact API surface used in TicketPdfGenerator
 * and VoucherPdfGenerator. If a library update breaks any of these,
 * the generators will fail in production.
 */
class PdfGenerationTest extends TestCase
{
    private string $tempDir;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    private function createTempPdf(): string
    {
        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage('P', [210, 297]);
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->SetXY(10, 10);
        $pdf->Cell(0, 0, 'Template Page', 0, 0, 'L', false);

        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'phpunit_template_' . uniqid() . '.pdf';
        file_put_contents($path, $pdf->Output('', 'S'));
        $this->tempFiles[] = $path;
        return $path;
    }

    // --- Instantiation ---

    public function testFpdiCanBeInstantiated(): void
    {
        $pdf = new Fpdi();
        $this->assertInstanceOf(Fpdi::class, $pdf);
    }

    // --- Core TCPDF API (used in both generators) ---

    public function testSetAutoPageBreak(): void
    {
        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $this->expectNotToPerformAssertions();
    }

    public function testSetDocumentMetadata(): void
    {
        $pdf = new Fpdi();
        $pdf->SetCreator('Ticket System');
        $pdf->SetAuthor('Ticket System');
        $pdf->SetTitle('Test Ticket');
        $pdf->SetSubject('Event Ticket');
        $this->expectNotToPerformAssertions();
    }

    public function testAddPageWithExplicitSize(): void
    {
        $pdf = new Fpdi();
        $pdf->AddPage('P', [210, 297]);
        $this->assertSame(1, $pdf->getPage());
    }

    public function testAddPageLandscape(): void
    {
        $pdf = new Fpdi();
        $pdf->AddPage('L', [297, 210]);
        $this->assertSame(1, $pdf->getPage());
    }

    public function testSetFontAndWriteCell(): void
    {
        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage('P', [210, 297]);
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->SetXY(10, 20);
        $pdf->Cell(0, 0, 'Max Mustermann', 0, 0, 'L', false);
        $this->expectNotToPerformAssertions();
    }

    public function testSetFontBold(): void
    {
        $pdf = new Fpdi();
        $pdf->AddPage('P', [210, 297]);
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->SetXY(10, 10);
        $pdf->Cell(0, 0, 'Bold Text', 0, 0, 'L', false);
        $this->expectNotToPerformAssertions();
    }

    public function testSetTextColor(): void
    {
        $pdf = new Fpdi();
        $pdf->AddPage('P', [210, 297]);
        $pdf->SetTextColor(255, 0, 0);
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->SetXY(10, 10);
        $pdf->Cell(0, 0, 'Red Text', 0, 0, 'L', false);
        $this->expectNotToPerformAssertions();
    }

    public function testCellAlignments(): void
    {
        $pdf = new Fpdi();
        $pdf->AddPage('P', [210, 297]);
        $pdf->SetFont('Helvetica', '', 12);

        foreach (['L', 'C', 'R'] as $align) {
            $pdf->SetXY(10, 10);
            $pdf->Cell(0, 0, 'Aligned text', 0, 0, $align, false);
        }
        $this->expectNotToPerformAssertions();
    }

    public function testWriteMethod(): void
    {
        $pdf = new Fpdi();
        $pdf->AddPage('P', [210, 297]);
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->SetXY(10, 10);
        $pdf->Write(0, 'Voucher text');
        $this->expectNotToPerformAssertions();
    }

    // --- PDF output ---

    public function testOutputAsStringReturnsValidPdf(): void
    {
        $pdf = new Fpdi();
        $pdf->AddPage('P', [210, 297]);
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->SetXY(10, 10);
        $pdf->Cell(0, 0, 'Test', 0, 0, 'L', false);

        $output = $pdf->Output('', 'S');
        $this->assertStringStartsWith('%PDF-', $output);
    }

    public function testOutputToFile(): void
    {
        $pdf = new Fpdi();
        $pdf->AddPage('P', [210, 297]);

        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'phpunit_output_' . uniqid() . '.pdf';
        $this->tempFiles[] = $path;

        file_put_contents($path, $pdf->Output('', 'S'));
        $this->assertFileExists($path);
        $this->assertStringStartsWith('%PDF-', file_get_contents($path));
    }

    // --- FPDI template import (core feature of both generators) ---

    public function testSetSourceFileReturnsPageCount(): void
    {
        $templatePath = $this->createTempPdf();
        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($templatePath);
        $this->assertSame(1, $pageCount);
    }

    public function testImportPageReturnsTemplateId(): void
    {
        $templatePath = $this->createTempPdf();
        $pdf = new Fpdi();
        $pdf->setSourceFile($templatePath);
        $templateId = $pdf->importPage(1);
        $this->assertNotEmpty($templateId);
    }

    public function testGetTemplateSizeReturnsWidthAndHeight(): void
    {
        $templatePath = $this->createTempPdf();
        $pdf = new Fpdi();
        $pdf->setSourceFile($templatePath);
        $templateId = $pdf->importPage(1);
        $size = $pdf->getTemplateSize($templateId);

        $this->assertArrayHasKey('width', $size);
        $this->assertArrayHasKey('height', $size);
        $this->assertGreaterThan(0, $size['width']);
        $this->assertGreaterThan(0, $size['height']);
    }

    public function testUseTemplateRendersOnPage(): void
    {
        $templatePath = $this->createTempPdf();
        $pdf = new Fpdi();
        $pdf->setSourceFile($templatePath);
        $templateId = $pdf->importPage(1);
        $size = $pdf->getTemplateSize($templateId);

        $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
        $pdf->AddPage($orientation, [$size['width'], $size['height']]);
        $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height'], true);

        $output = $pdf->Output('', 'S');
        $this->assertStringStartsWith('%PDF-', $output);
    }

    public function testMultipleTicketsOnSeparatePages(): void
    {
        $templatePath = $this->createTempPdf();
        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pdf->SetFont('Helvetica', '', 12);

        $pageCount = $pdf->setSourceFile($templatePath);

        // Simulate generating 3 tickets (as outputMultipleTickets does)
        for ($ticket = 1; $ticket <= 3; $ticket++) {
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);
                $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height'], true);

                if ($pageNo === 1) {
                    $pdf->SetXY(10, 10);
                    $pdf->Cell(0, 0, 'Ticket ' . $ticket, 0, 0, 'L', false);
                }
            }
        }

        $this->assertSame(3, $pdf->getNumPages());
        $output = $pdf->Output('', 'S');
        $this->assertStringStartsWith('%PDF-', $output);
    }

    public function testOrientationDetectionPortrait(): void
    {
        // Portrait: height > width
        $size = ['width' => 210.0, 'height' => 297.0];
        $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
        $this->assertSame('P', $orientation);
    }

    public function testOrientationDetectionLandscape(): void
    {
        // Landscape: width > height
        $size = ['width' => 297.0, 'height' => 210.0];
        $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
        $this->assertSame('L', $orientation);
    }
}
