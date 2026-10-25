<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\SeatPlan;
use App\Services\SeatPlanImporter;
use Tests\Support\DatabaseTestCase;

/**
 * Import der Alternativbelegung (Ausweichspielstätte) aus Excel/CSV.
 */
class SeatPlanImporterTest extends DatabaseTestCase
{
    private SeatPlan $seatPlan;
    private SeatPlanImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seatPlan = new SeatPlan();
        $this->importer = new SeatPlanImporter();
    }

    /**
     * Legt eine Reihe mit fortlaufend nummerierten Sitzen an.
     */
    private function createRowWithSeats(int $eventId, int $rowNumber, int $seatCount, ?int $categoryId = null): int
    {
        $rowId = $this->seatPlan->saveRow($eventId, $rowNumber, null, $rowNumber);
        for ($i = 1; $i <= $seatCount; $i++) {
            $this->seatPlan->addElement($rowId, [
                'element_type'   => 'seat',
                'element_number' => $i,
                'position'       => $i - 1,
                'category_id'    => $categoryId,
            ]);
        }
        return $rowId;
    }

    public function testGuessMappingPicksOriginalAndAlternativeColumns(): void
    {
        $headers = ['Kat', 'Euro', 'erm.', 'Reihe', 'Platz', 'Datum', 'Beginn', 'Premiere', 'Reihe Stadthalle', 'Platz Stadthalle'];

        $mapping = SeatPlanImporter::guessMapping($headers);

        self::assertSame(3, $mapping['row']);
        self::assertSame(4, $mapping['seat']);
        self::assertSame(8, $mapping['alt_row']);
        self::assertSame(9, $mapping['alt_seat']);
        self::assertSame(0, $mapping['category']);
        self::assertNull($mapping['table']);
    }

    public function testNormalizeCategoryTokenStripsPrefixAndPunctuation(): void
    {
        self::assertSame('i', SeatPlanImporter::normalizeCategoryToken('Kat. I.'));
        self::assertSame('a', SeatPlanImporter::normalizeCategoryToken('Kategorie A'));
        self::assertSame('ii', SeatPlanImporter::normalizeCategoryToken(' II '));
        self::assertSame('', SeatPlanImporter::normalizeCategoryToken(null));
    }

    public function testImportWritesAlternativeSeatsForExistingSeats(): void
    {
        $event = $this->createEvent();
        $this->createRowWithSeats((int)$event['id'], 1, 3);

        // Spalten: Reihe | Platz | Alt-Reihe | Alt-Platz (ohne "Reihe"/"Platz" im Text)
        $rows = [
            ['Reihe', 'Platz', 'Reihe alt.', 'Platz alt.'],
            ['1', '1', '5', '11'],
            ['1', '2', '5', '12'],
        ];

        $result = $this->importer->import((int)$event['id'], $rows, [
            'row' => 0, 'seat' => 1, 'alt_row' => 2, 'alt_seat' => 3,
        ]);

        self::assertSame(2, $result['updated']);
        self::assertSame(0, $result['created']);
        self::assertSame([], $result['not_found']);

        $alternative = $this->seatPlan->getAlternativeSeat((int)$event['id'], 'seat_1_2');
        self::assertSame(['row' => '5', 'seat' => '12'], $alternative);
        self::assertSame('Reihe 5, Platz 12', SeatPlan::formatAlternativeSeat('5', '12'));

        // Sitz ohne Zeile in der Datei bleibt ohne Alternative
        self::assertNull($this->seatPlan->getAlternativeSeat((int)$event['id'], 'seat_1_3'));
    }

    public function testImportToleratesLabelPrefixesAndDeduplicatesRepeatedRows(): void
    {
        $event = $this->createEvent();
        $this->createRowWithSeats((int)$event['id'], 1, 2);

        // Derselbe Platz je Vorstellung – identische Angaben, daher nur ein Eintrag
        $rows = [
            ['Reihe', 'Platz', 'Datum', 'Reihe alt.', 'Platz alt.'],
            ['Reihe 1', 'Platz 1', '25. Juli 2019', 'Reihe 2', 'Platz 7'],
            ['Reihe 1', 'Platz 1', '26. Juli 2019', 'Reihe 2', 'Platz 7'],
            ['Reihe 1', 'Platz 1', '27. Juli 2019', 'Reihe 3', 'Platz 9'],
        ];

        $result = $this->importer->import((int)$event['id'], $rows, [
            'row' => 0, 'seat' => 1, 'alt_row' => 3, 'alt_seat' => 4,
        ]);

        self::assertSame(1, $result['total_entries']);
        self::assertSame(1, $result['updated']);
        // Die dritte Zeile widerspricht den ersten beiden
        self::assertCount(1, $result['conflicts']);

        self::assertSame(
            ['row' => '2', 'seat' => '7'],
            $this->seatPlan->getAlternativeSeat((int)$event['id'], 'seat_1_1')
        );
    }

    public function testImportReportsSeatsMissingFromThePlan(): void
    {
        $event = $this->createEvent();
        $this->createRowWithSeats((int)$event['id'], 1, 1);

        $rows = [
            ['Reihe', 'Platz', 'Reihe alt.', 'Platz alt.'],
            ['1', '1', '5', '1'],
            ['9', '4', '5', '2'],
        ];

        $result = $this->importer->import((int)$event['id'], $rows, [
            'row' => 0, 'seat' => 1, 'alt_row' => 2, 'alt_seat' => 3,
        ]);

        self::assertSame(1, $result['updated']);
        self::assertSame(['Reihe 9, Platz 4'], $result['not_found']);
    }

    public function testImportCreatesMissingSeatsWhenRequested(): void
    {
        $event = $this->createEvent();
        $this->createRowWithSeats((int)$event['id'], 1, 1);

        $rows = [
            ['Reihe', 'Platz', 'Reihe alt.', 'Platz alt.'],
            ['2', '1', '7', '1'],
            ['2', '2', '7', '2'],
        ];

        $result = $this->importer->import((int)$event['id'], $rows, [
            'row' => 0, 'seat' => 1, 'alt_row' => 2, 'alt_seat' => 3,
        ], ['create_missing' => true]);

        self::assertSame(2, $result['created']);
        self::assertSame([], $result['not_found']);
        self::assertSame(
            ['row' => '7', 'seat' => '2'],
            $this->seatPlan->getAlternativeSeat((int)$event['id'], 'seat_2_2')
        );
    }

    public function testCreatedSeatsAreSortedInBySeatNumber(): void
    {
        $event = $this->createEvent();
        // Row 1 already has seats 2 and 4; the file lists the missing seats out of order
        $rowId = $this->seatPlan->saveRow((int)$event['id'], 1, null, 1);
        foreach ([2, 4] as $position => $number) {
            $this->seatPlan->addElement($rowId, ['element_type' => 'seat', 'element_number' => $number, 'position' => $position]);
        }

        $rows = [
            ['Reihe', 'Platz', 'Reihe alt.', 'Platz alt.'],
            ['1', '5', '7', '5'],
            ['1', '1', '7', '1'],
            ['1', '3', '7', '3'],
        ];

        $result = $this->importer->import((int)$event['id'], $rows, [
            'row' => 0, 'seat' => 1, 'alt_row' => 2, 'alt_seat' => 3,
        ], ['create_missing' => true]);

        self::assertSame(3, $result['created']);
        $order = array_map(
            fn($element) => (int)$element['element_number'],
            $this->seatPlan->getRowWithElements($rowId)['elements']
        );
        self::assertSame([1, 2, 3, 4, 5], $order);
    }

    public function testImportAssignsCategoryBySign(): void
    {
        $event = $this->createEvent();
        $category = $this->createSeatCategory(['event_id' => $event['id'], 'name' => 'Parkett', 'sign' => 'I']);
        $this->createRowWithSeats((int)$event['id'], 1, 1);

        $rows = [
            ['Kat', 'Reihe', 'Platz', 'Reihe alt.', 'Platz alt.'],
            ['Kat. I.', '1', '1', '4', '4'],
            ['Kat. X.', '1', '1', '4', '4'],
        ];

        $result = $this->importer->import((int)$event['id'], $rows, [
            'category' => 0, 'row' => 1, 'seat' => 2, 'alt_row' => 3, 'alt_seat' => 4,
        ]);

        self::assertSame(1, $result['categories_set']);

        $seatIndex = $this->seatPlan->getSeatIndex((int)$event['id']);
        self::assertSame((int)$category['id'], (int)$seatIndex['1|1']['category_id']);

        // Die unbekannte Kategorie der doppelten Zeile wird gemeldet
        self::assertArrayHasKey('x', $result['unknown_categories']);
    }

    public function testClearExistingRemovesPreviousAlternatives(): void
    {
        $event = $this->createEvent();
        $this->createRowWithSeats((int)$event['id'], 1, 2);

        $header = ['Reihe', 'Platz', 'Reihe alt.', 'Platz alt.'];
        $mapping = ['row' => 0, 'seat' => 1, 'alt_row' => 2, 'alt_seat' => 3];

        $this->importer->import((int)$event['id'], [$header, ['1', '1', '5', '1'], ['1', '2', '5', '2']], $mapping);
        self::assertNotNull($this->seatPlan->getAlternativeSeat((int)$event['id'], 'seat_1_2'));

        // Zweiter Import nur für Platz 1 – Platz 2 muss geleert werden
        $result = $this->importer->import(
            (int)$event['id'],
            [$header, ['1', '1', '6', '1']],
            $mapping,
            ['clear_existing' => true]
        );

        self::assertSame(2, $result['cleared']);
        self::assertSame(
            ['row' => '6', 'seat' => '1'],
            $this->seatPlan->getAlternativeSeat((int)$event['id'], 'seat_1_1')
        );
        self::assertNull($this->seatPlan->getAlternativeSeat((int)$event['id'], 'seat_1_2'));
    }

    public function testImportRequiresSeatColumn(): void
    {
        $event = $this->createEvent();

        $this->expectException(\InvalidArgumentException::class);
        $this->importer->import((int)$event['id'], [['Reihe']], ['row' => 0, 'alt_row' => 1]);
    }
}
