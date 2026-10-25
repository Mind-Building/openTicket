<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Voucher;
use Tests\Support\DatabaseTestCase;

/**
 * Integration tests for the Voucher model.
 *
 * Heavy focus on the code generator (fix #3, security-critical): every new
 * voucher is bargain-equivalent to cash, so the codes must be unguessable
 * and the algorithm must keep its contract:
 *   • 12 characters from Crockford-Base32 (no I, L, O, U),
 *   • cryptographically random source (random_int → CSPRNG),
 *   • no collision in any practical run,
 *   • old md5-style codes (already in the DB before the refactor) keep
 *     working under getByCode() because lookup is a plain string match.
 */
class VoucherTest extends DatabaseTestCase
{
    private Voucher $model;

    /** Crockford-Base32 alphabet used by Voucher::generateVoucherCode(). */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new Voucher();
    }

    // ── generateVoucherCode (via create) ──────────────────────────

    public function testCreateProducesExactly12CharacterCode(): void
    {
        $category = $this->createSeatCategory();
        $id = $this->model->create([
            'session_id'  => 'sess_test',
            'event_id'    => $category['event_id'],
            'category_id' => $category['id'],
        ]);

        $voucher = $this->model->getById($id);
        $this->assertSame(12, strlen((string)$voucher['voucher_code']));
    }

    public function testCreateProducesCodeFromCrockfordAlphabetOnly(): void
    {
        $category = $this->createSeatCategory();
        // Generate several codes to make the alphabet check meaningful;
        // a single 12-char code might miss a stray illegal character.
        for ($i = 0; $i < 10; $i++) {
            $id = $this->model->create([
                'session_id'  => 'sess_test_' . $i,
                'event_id'    => $category['event_id'],
                'category_id' => $category['id'],
            ]);
            $code = $this->model->getById($id)['voucher_code'];
            $allowed = self::ALPHABET;
            $this->assertSame(
                strlen($code),
                strspn($code, $allowed),
                "Code '{$code}' contains characters outside the Crockford-Base32 alphabet ({$allowed})"
            );
        }
    }

    public function testCreateNeverProducesAmbiguousCharacters(): void
    {
        $category = $this->createSeatCategory();
        for ($i = 0; $i < 20; $i++) {
            $id = $this->model->create([
                'session_id'  => 'sess_amb_' . $i,
                'event_id'    => $category['event_id'],
                'category_id' => $category['id'],
            ]);
            $code = $this->model->getById($id)['voucher_code'];
            // The whole point of Crockford-Base32 is dropping these four.
            $this->assertStringNotContainsString('I', $code);
            $this->assertStringNotContainsString('L', $code);
            $this->assertStringNotContainsString('O', $code);
            $this->assertStringNotContainsString('U', $code);
        }
    }

    public function testGeneratedCodesAreAllUniqueAcrossManyCreations(): void
    {
        // 200 codes drawn from a 32^12 ≈ 1.15e18 space – the chance of any
        // collision is astronomically low. A failure here would mean either
        // the generator regressed to a low-entropy source (e.g. mt_rand),
        // or the loop is reusing a buffer somewhere.
        $category = $this->createSeatCategory();
        $codes = [];
        for ($i = 0; $i < 200; $i++) {
            $id = $this->model->create([
                'session_id'  => 'sess_uniq_' . $i,
                'event_id'    => $category['event_id'],
                'category_id' => $category['id'],
            ]);
            $codes[] = $this->model->getById($id)['voucher_code'];
        }
        $this->assertCount(200, array_unique($codes), 'Voucher codes must not collide');
    }

    public function testCreatePersistsAccompanyingFields(): void
    {
        $category = $this->createSeatCategory();
        $id = $this->model->create([
            'session_id'      => 'sess_full',
            'event_id'        => $category['event_id'],
            'category_id'     => $category['id'],
            'quantity'        => 3,
            'purchase_intent' => 'redeem',
            'status_id'       => 2,
        ]);
        $voucher = $this->model->getById($id);
        $this->assertSame(3,        (int)$voucher['quantity']);
        $this->assertSame('redeem', $voucher['purchase_intent']);
        $this->assertSame(2,        (int)$voucher['status_id']);
        // Hash is derived from code + time; just sanity-check it's set.
        $this->assertNotEmpty($voucher['hash']);
    }

    // ── getByCode – lookup must work for old AND new codes ─────────

    public function testGetByCodeReturnsNullForUnknownCode(): void
    {
        $this->assertNull($this->model->getByCode('NOTHING-LIKE-THIS'));
    }

    public function testGetByCodeFindsLegacyMd5StyleCodes(): void
    {
        // This is the core "old codes stay valid" guarantee: lookup is a
        // straight string match, so a hex-style legacy code generated by the
        // pre-refactor md5/uniqid scheme keeps working unchanged.
        $legacy = $this->createVoucher([
            'voucher_code' => 'A1B2C3D4E5F6', // 12-char hex – the old shape
        ]);
        $found = $this->model->getByCode($legacy['voucher_code']);
        $this->assertNotNull($found);
        $this->assertSame($legacy['id'], (int)$found['id']);
    }

    public function testGetByCodeFindsNewCrockfordStyleCodes(): void
    {
        $category = $this->createSeatCategory();
        $id = $this->model->create([
            'session_id'  => 'sess_lookup',
            'event_id'    => $category['event_id'],
            'category_id' => $category['id'],
        ]);
        $code = $this->model->getById($id)['voucher_code'];

        $found = $this->model->getByCode($code);
        $this->assertNotNull($found);
        $this->assertSame($id, (int)$found['id']);
    }

    // ── update / delete ───────────────────────────────────────────

    public function testUpdateChangesAllowedFields(): void
    {
        $v = $this->createVoucher([
            'status_id'  => 0,
            'is_reduced' => 0,
        ]);

        $this->model->update($v['id'], [
            'status_id'  => 2,
            'is_reduced' => 1,
        ]);

        $after = $this->model->getById($v['id']);
        $this->assertSame(2, (int)$after['status_id']);
        $this->assertSame(1, (int)$after['is_reduced']);
    }

    public function testUpdateDoesNotAcceptQuantity(): void
    {
        // Voucher::update()'s whitelist deliberately omits quantity – a voucher's
        // count is fixed at creation time. Passing it must be a no-op.
        $v = $this->createVoucher(['quantity' => 1]);

        $this->model->update($v['id'], ['quantity' => 5]);

        $after = $this->model->getById($v['id']);
        $this->assertSame(1, (int)$after['quantity']);
    }

    public function testUpdateDoesNotTouchVoucherCode(): void
    {
        // The voucher_code is the customer-visible identifier; changing it
        // would invalidate any printed/emailed copies. The update() method
        // must NOT accept voucher_code in its payload.
        $v = $this->createVoucher();
        $originalCode = $v['voucher_code'];

        $this->model->update($v['id'], [
            'voucher_code' => 'TRY_TO_OVERWRITE',
            'status_id'    => 2,
        ]);

        $after = $this->model->getById($v['id']);
        $this->assertSame($originalCode, $after['voucher_code']);
        $this->assertSame(2, (int)$after['status_id']);
    }

    public function testDeleteRemovesTheRow(): void
    {
        $v = $this->createVoucher();
        $this->model->delete($v['id']);
        $this->assertNull($this->model->getById($v['id']));
        $this->assertNull($this->model->getByCode($v['voucher_code']));
    }

    // ── getBySession ──────────────────────────────────────────────

    public function testGetBySessionReturnsOnlyCartVouchers(): void
    {
        // getBySession() filters to status_id = 1 (in cart), matching the
        // Reservation::getBySession contract – it is the cart view, not a
        // "all vouchers of this session" lookup.
        $cat = $this->createSeatCategory();

        // status 1 (cart, buy) – must appear
        $this->createVoucher([
            'session_id'      => 'sess_filter',
            'event_id'        => $cat['event_id'],
            'category_id'     => $cat['id'],
            'purchase_intent' => 'buy',
            'status_id'       => 1,
            'voucher_code'    => 'CART_BUY',
        ]);
        // status 1 (cart, redeem) – must appear when no filter, hidden when buy filter
        $this->createVoucher([
            'session_id'      => 'sess_filter',
            'event_id'        => $cat['event_id'],
            'category_id'     => $cat['id'],
            'purchase_intent' => 'redeem',
            'status_id'       => 1,
            'voucher_code'    => 'CART_REDEEM',
        ]);
        // status 2 (paid) – must never appear regardless of intent filter
        $this->createVoucher([
            'session_id'      => 'sess_filter',
            'event_id'        => $cat['event_id'],
            'category_id'     => $cat['id'],
            'purchase_intent' => 'buy',
            'status_id'       => 2,
            'voucher_code'    => 'PAID_BUY',
        ]);

        $buy    = $this->model->getBySession('sess_filter', 'buy');
        $redeem = $this->model->getBySession('sess_filter', 'redeem');
        $all    = $this->model->getBySession('sess_filter');

        $this->assertCount(1, $buy);
        $this->assertSame('CART_BUY', $buy[0]['voucher_code']);

        $this->assertCount(1, $redeem);
        $this->assertSame('CART_REDEEM', $redeem[0]['voucher_code']);

        $this->assertCount(2, $all, 'Both cart rows show up, the paid row is excluded');
    }
}
