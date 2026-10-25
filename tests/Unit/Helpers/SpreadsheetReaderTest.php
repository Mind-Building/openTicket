<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\SpreadsheetReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Der Reader für den Sitzplan-Import liest .xlsx (ZIP + XML) und .csv ohne
 * externe Bibliothek. Wichtig ist vor allem, dass die Spaltenpositionen
 * erhalten bleiben – leere Zellen fehlen in der XLSX-XML komplett.
 */
class SpreadsheetReaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/spreadsheet_reader_test_' . uniqid('', true);
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    /**
     * Baut eine minimale .xlsx-Datei. Leere Werte werden – wie von Excel –
     * als fehlende <c>-Elemente geschrieben.
     */
    private function writeXlsx(array $rows, string $sheetTarget = 'worksheets/sheet1.xml'): string
    {
        $shared = [];
        $sheet = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $rowIndex => $row) {
            $sheet .= '<row r="' . ($rowIndex + 1) . '">';
            foreach ($row as $colIndex => $value) {
                if ($value === '') {
                    continue;
                }
                $stringIndex = array_search($value, $shared, true);
                if ($stringIndex === false) {
                    $shared[] = $value;
                    $stringIndex = count($shared) - 1;
                }
                $sheet .= '<c r="' . chr(65 + $colIndex) . ($rowIndex + 1) . '" t="s"><v>' . $stringIndex . '</v></c>';
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';

        $sharedStrings = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        foreach ($shared as $string) {
            $sharedStrings .= '<si><t>' . htmlspecialchars($string, ENT_XML1) . '</t></si>';
        }
        $sharedStrings .= '</sst>';

        $path = $this->tempDir . '/test.xlsx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString(
            'xl/workbook.xml',
            '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Tabelle1" sheetId="1" r:id="rId1"/></sheets></workbook>'
        );
        $zip->addFromString(
            'xl/_rels/workbook.xml.rels',
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
            . ' Target="' . $sheetTarget . '"/></Relationships>'
        );
        $zip->addFromString('xl/' . $sheetTarget, $sheet);
        $zip->addFromString('xl/sharedStrings.xml', $sharedStrings);
        $zip->close();

        return $path;
    }

    public function testReadsXlsxIncludingHeaderAndValues(): void
    {
        $path = $this->writeXlsx([
            ['Kat', 'Reihe', 'Platz', 'Reihe Stadthalle', 'Platz Stadthalle'],
            ['Kat. I.', '1', '13', '1', '1'],
            ['Kat. I.', '1', '14', '1', '2'],
        ]);

        $rows = SpreadsheetReader::read($path, 'test.xlsx');

        self::assertCount(3, $rows);
        self::assertSame(['Kat', 'Reihe', 'Platz', 'Reihe Stadthalle', 'Platz Stadthalle'], $rows[0]);
        self::assertSame(['Kat. I.', '1', '14', '1', '2'], $rows[2]);
    }

    public function testEmptyCellsKeepColumnPositions(): void
    {
        $path = $this->writeXlsx([
            ['A', 'B', 'C', 'D'],
            ['1', '', '3', '4'],
        ]);

        $rows = SpreadsheetReader::read($path, 'test.xlsx');

        self::assertSame(['1', '', '3', '4'], $rows[1]);
    }

    public function testResolvesSheetPathFromRelationships(): void
    {
        // Excel nummeriert Arbeitsblätter nicht zwingend als sheet1.xml
        $path = $this->writeXlsx([['Reihe', 'Platz'], ['2', '5']], 'worksheets/sheet42.xml');

        $rows = SpreadsheetReader::read($path, 'test.xlsx');

        self::assertSame(['2', '5'], $rows[1]);
    }

    public function testReadsSemicolonCsvWithBomAndUmlauts(): void
    {
        $path = $this->tempDir . '/test.csv';
        file_put_contents($path, "\xEF\xBB\xBFReihe;Platz;Größe\n1;13;groß\n");

        $rows = SpreadsheetReader::read($path, 'test.csv');

        self::assertSame(['Reihe', 'Platz', 'Größe'], $rows[0]);
        self::assertSame(['1', '13', 'groß'], $rows[1]);
    }

    public function testReadsCommaSeparatedCsv(): void
    {
        $path = $this->tempDir . '/test.csv';
        file_put_contents($path, "Reihe,Platz,Alt-Reihe,Alt-Platz\n1,13,1,1\n");

        $rows = SpreadsheetReader::read($path, 'test.csv');

        self::assertSame(['1', '13', '1', '1'], $rows[1]);
    }

    public function testRejectsLegacyXlsWithHelpfulMessage(): void
    {
        $path = $this->tempDir . '/old.xls';
        file_put_contents($path, 'irrelevant');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/\.xlsx/');

        SpreadsheetReader::read($path, 'old.xls');
    }
}
