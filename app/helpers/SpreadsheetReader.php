<?php
/**
 * Spreadsheet Reader
 *
 * Liest Tabellen aus .xlsx- und .csv-Dateien ohne externe Abhängigkeit.
 * .xlsx ist ein ZIP-Archiv mit XML-Dateien; es werden nur die Zellwerte des
 * ersten Arbeitsblattes benötigt (Formeln werden über den zwischengespeicherten
 * Wert gelesen).
 */

namespace App\Helpers;

class SpreadsheetReader
{
    /** Maximale Anzahl gelesener Datenzeilen (Schutz gegen riesige Dateien) */
    public const MAX_ROWS = 50000;

    /**
     * Liest eine Datei und liefert ein Array von Zeilen (jede Zeile ein Array von Strings).
     *
     * @param string $path         Pfad zur hochgeladenen Datei
     * @param string $originalName Ursprünglicher Dateiname (für die Formaterkennung)
     * @throws \RuntimeException bei nicht lesbaren oder nicht unterstützten Dateien
     */
    public static function read(string $path, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return match ($extension) {
            'xlsx', 'xlsm' => self::readXlsx($path),
            'csv', 'txt'   => self::readCsv($path),
            'xls'          => throw new \RuntimeException(
                'Das alte Excel-Format (.xls) wird nicht unterstützt. '
                . 'Bitte die Datei in Excel als .xlsx oder als CSV speichern.'
            ),
            default        => throw new \RuntimeException(
                'Nicht unterstütztes Dateiformat: .' . $extension . ' (erlaubt: .xlsx, .csv)'
            ),
        };
    }

    // -----------------------------------------------------------------
    // CSV
    // -----------------------------------------------------------------

    private static function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Die Datei konnte nicht geöffnet werden.');
        }

        // BOM entfernen und Trennzeichen anhand der ersten Zeile bestimmen
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            return [];
        }
        $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);

        $delimiter = ';';
        $best = substr_count($firstLine, ';');
        foreach ([',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t")] as $candidate => $count) {
            if ($count > $best) {
                $best = $count;
                $delimiter = $candidate;
            }
        }

        rewind($handle);
        $rows = [];
        $skipBom = true;

        while (($data = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($skipBom && isset($data[0])) {
                $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', $data[0]);
                $skipBom = false;
            }
            $rows[] = array_map(static fn($value) => self::toUtf8((string)($value ?? '')), $data);

            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        fclose($handle);

        return $rows;
    }

    private static function toUtf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        return mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }

    // -----------------------------------------------------------------
    // XLSX
    // -----------------------------------------------------------------

    private static function readXlsx(string $path): array
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException(
                'Die PHP-Erweiterung "zip" ist nicht aktiv. Bitte die Datei als CSV speichern und erneut hochladen.'
            );
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Die Excel-Datei konnte nicht gelesen werden (kein gültiges .xlsx).');
        }

        try {
            $sharedStrings = self::readSharedStrings($zip);
            $sheetPath = self::resolveFirstSheetPath($zip);

            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false) {
                throw new \RuntimeException('Das Arbeitsblatt konnte in der Excel-Datei nicht gefunden werden.');
            }

            return self::parseSheet($sheetXml, $sharedStrings);
        } finally {
            $zip->close();
        }
    }

    private static function readSharedStrings(\ZipArchive $zip): array
    {
        $xmlData = $zip->getFromName('xl/sharedStrings.xml');
        if ($xmlData === false) {
            return [];
        }

        $xml = self::loadXml($xmlData);
        $strings = [];

        foreach ($xml->si ?? [] as $item) {
            // <si><t>Text</t></si> oder mit Formatierungen <si><r><t>..</t></r>...</si>
            $text = '';
            if (isset($item->t)) {
                $text = (string)$item->t;
            }
            foreach ($item->r ?? [] as $run) {
                $text .= (string)$run->t;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * Ermittelt den Pfad des ersten Arbeitsblattes über workbook.xml + rels.
     */
    private static function resolveFirstSheetPath(\ZipArchive $zip): string
    {
        $workbookData = $zip->getFromName('xl/workbook.xml');
        $relsData = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookData !== false && $relsData !== false) {
            $workbook = self::loadXml($workbookData);
            $rels = self::loadXml($relsData);

            $firstSheet = $workbook->sheets->sheet[0] ?? null;
            if ($firstSheet !== null) {
                $relId = (string)($firstSheet->attributes('r', true)->id ?? '');
                foreach ($rels->Relationship ?? [] as $relationship) {
                    if ((string)$relationship['Id'] === $relId) {
                        $target = ltrim((string)$relationship['Target'], '/');
                        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                    }
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private static function loadXml(string $data): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET verhindert externe Entities (XXE-Schutz)
        $xml = simplexml_load_string($data, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new \RuntimeException('Die Excel-Datei enthält ungültiges XML.');
        }

        return $xml;
    }

    private static function parseSheet(string $sheetXml, array $sharedStrings): array
    {
        $xml = self::loadXml($sheetXml);
        $rows = [];

        foreach ($xml->sheetData->row ?? [] as $rowNode) {
            $cells = [];
            $maxIndex = -1;

            foreach ($rowNode->c ?? [] as $cellNode) {
                $reference = (string)$cellNode['r'];
                $columnIndex = $reference !== '' ? self::columnIndex($reference) : $maxIndex + 1;
                $cells[$columnIndex] = self::cellValue($cellNode, $sharedStrings);
                if ($columnIndex > $maxIndex) {
                    $maxIndex = $columnIndex;
                }
            }

            // Lücken auffüllen, damit die Spaltenpositionen stimmen
            $row = [];
            for ($i = 0; $i <= $maxIndex; $i++) {
                $row[] = $cells[$i] ?? '';
            }
            $rows[] = $row;

            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        return $rows;
    }

    private static function cellValue(\SimpleXMLElement $cellNode, array $sharedStrings): string
    {
        $type = (string)$cellNode['t'];

        if ($type === 's') {
            $index = (int)$cellNode->v;
            return $sharedStrings[$index] ?? '';
        }

        if ($type === 'inlineStr') {
            $text = '';
            if (isset($cellNode->is->t)) {
                $text = (string)$cellNode->is->t;
            }
            foreach ($cellNode->is->r ?? [] as $run) {
                $text .= (string)$run->t;
            }
            return $text;
        }

        if ($type === 'b') {
            return ((string)$cellNode->v) === '1' ? '1' : '0';
        }

        return isset($cellNode->v) ? (string)$cellNode->v : '';
    }

    /**
     * Wandelt eine Zellreferenz ("B12", "AA3") in einen 0-basierten Spaltenindex um.
     */
    private static function columnIndex(string $reference): int
    {
        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $reference));
        $index = 0;

        for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return max(0, $index - 1);
    }
}
