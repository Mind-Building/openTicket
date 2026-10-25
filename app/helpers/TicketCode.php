<?php
/**
 * Ticket Code
 * QR code / barcode printed on tickets ({ticket_code} in the ticket template).
 * Type and content pattern are set per event (events.ticket_code_type / ticket_code_content).
 */

namespace App\Helpers;

final class TicketCode
{
    public const TYPE_QR = 'qrcode';
    public const TYPE_CODE128 = 'code128';

    public const DEFAULT_CONTENT = '{ticket_number}';

    /** @return array<string, string> type => label */
    public static function types(): array
    {
        return [
            self::TYPE_QR => 'QR-Code',
            self::TYPE_CODE128 => 'Strichcode (Code 128)',
        ];
    }

    /** @return array<string, string> token => description, for the event settings */
    public static function tokens(): array
    {
        return [
            '{ticket_number}' => 'Ticketnummer, z. B. T-00001234',
            '{reservation_id}' => 'Reservierungs-ID, z. B. 1234',
            '{event_id}' => 'ID der Veranstaltung',
            '{event_date}' => 'Termin, z. B. 2026-10-02',
            '{row}' => 'Reihe (bei Tischplätzen die Tischnummer)',
            '{seat}' => 'Platznummer',
            '{seat_identifier}' => 'Interne Platz-ID, z. B. seat_5_12',
            '{check}' => 'Prüfcode (8 Zeichen, nur mit dem App-Secret erzeugbar – erschwert Fälschungen)',
        ];
    }

    /**
     * Code content for one reservation from the event's pattern (empty pattern = DEFAULT_CONTENT).
     * Code 128 only encodes ASCII, so other characters are transliterated or dropped.
     */
    public static function content(string $pattern, array $reservation, string $type = self::TYPE_QR): string
    {
        $pattern = trim($pattern) !== '' ? $pattern : self::DEFAULT_CONTENT;
        $reservationId = (int)($reservation['id'] ?? 0);
        [$row, $seat] = self::rowAndSeat((string)($reservation['seat_identifier'] ?? ''));

        $content = strtr($pattern, [
            '{ticket_number}' => 'T-' . str_pad((string)$reservationId, 8, '0', STR_PAD_LEFT),
            '{reservation_id}' => (string)$reservationId,
            '{event_id}' => (string)(int)($reservation['event_id'] ?? 0),
            '{event_date}' => (string)($reservation['event_date'] ?? ''),
            '{row}' => $row,
            '{seat}' => $seat,
            '{seat_identifier}' => (string)($reservation['seat_identifier'] ?? ''),
            '{check}' => self::checkCode($reservationId),
        ]);

        if ($type === self::TYPE_CODE128) {
            $content = strtr($content, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
            $content = preg_replace('/[^\x20-\x7E]/', '', $content) ?? '';
        }

        return $content;
    }

    /**
     * Short HMAC of the reservation id; lets an entry scanner verify a ticket without a lookup table.
     */
    public static function checkCode(int $reservationId): string
    {
        $secret = $_ENV['APP_SECRET'] ?? '';
        if ($secret === '') {
            return '';
        }
        return strtoupper(substr(hash('sha256', 'ticketcode:' . $reservationId . ':' . $secret), 0, 8));
    }

    /** @return array{0: string, 1: string} row (or table) and seat from a seat identifier */
    private static function rowAndSeat(string $seatIdentifier): array
    {
        if (preg_match('/^(?:seat|wheelchair|tableseat)_(\d+)_(\d+)$/', $seatIdentifier, $m)) {
            return [$m[1], $m[2]];
        }
        return ['', ''];
    }
}
