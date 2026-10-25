<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Csrf;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for App\Core\Csrf.
 *
 * The token system protects every state-changing admin/presales POST. A
 * silent regression here would mean either:
 *   • tokens stop validating → every form blows up (very visible), or
 *   • tokens always validate → no protection at all (silent).
 *
 * The "silent" failure is the dangerous one. Tests below cover both
 * directions — valid tokens succeed, invalid/empty tokens fail, and
 * different sessions produce different tokens.
 *
 * Csrf is session-backed but holds no DB state, so we use a plain
 * TestCase and just clear $_SESSION between tests.
 */
class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        // Start from an empty session for every test. The Csrf class stores
        // its token in $_SESSION['csrf_token'] via Session::set().
        $_SESSION = [];
        $_POST    = [];
        $_GET     = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_GET     = [];
    }

    // ── generate() ─────────────────────────────────────────────────

    public function testGenerateProduces64CharacterHexToken(): void
    {
        // 32 random bytes encoded as hex = 64 characters.
        $token = Csrf::generate();
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }

    public function testGenerateProducesDifferentTokensEachCall(): void
    {
        $a = Csrf::generate();
        $b = Csrf::generate();
        $this->assertNotSame($a, $b, 'random_bytes(32) must not collide');
    }

    public function testGenerateStoresTokenInSession(): void
    {
        $token = Csrf::generate();
        $this->assertSame($token, $_SESSION['csrf_token'] ?? null);
    }

    // ── get() ──────────────────────────────────────────────────────

    public function testGetReturnsSameTokenAcrossCalls(): void
    {
        // get() is the idempotent accessor: repeated calls return the same
        // token until regenerate() is invoked. Otherwise the rendered form
        // and the user's submitted POST would disagree.
        $first  = Csrf::get();
        $second = Csrf::get();
        $third  = Csrf::get();
        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
    }

    public function testGetAutoGeneratesIfMissing(): void
    {
        $this->assertArrayNotHasKey('csrf_token', $_SESSION);
        $token = Csrf::get();
        $this->assertSame(64, strlen($token));
        $this->assertSame($token, $_SESSION['csrf_token']);
    }

    public function testGetReturnsExistingSessionTokenUnchanged(): void
    {
        $_SESSION['csrf_token'] = 'pre-existing-token-from-earlier-request';
        $this->assertSame('pre-existing-token-from-earlier-request', Csrf::get());
    }

    // ── validate() ────────────────────────────────────────────────

    public function testValidateAcceptsCurrentToken(): void
    {
        $token = Csrf::generate();
        $this->assertTrue(Csrf::validate($token));
    }

    public function testValidateRejectsEmptyToken(): void
    {
        Csrf::generate();
        $this->assertFalse(Csrf::validate(''));
    }

    public function testValidateRejectsWrongToken(): void
    {
        Csrf::generate();
        $this->assertFalse(Csrf::validate('not-the-current-token'));
    }

    public function testValidateFailsWhenNoSessionTokenExists(): void
    {
        // Even a syntactically valid token must fail if the session holds
        // nothing – this is the "session got wiped between render and submit"
        // path, and it must NOT silently allow the request through.
        $this->assertArrayNotHasKey('csrf_token', $_SESSION);
        $this->assertFalse(Csrf::validate(str_repeat('a', 64)));
    }

    public function testValidateRejectsTokenWithSingleCharacterDifference(): void
    {
        // Sanity-check that the comparison is whole-string, not prefix-based.
        // A length-equal but byte-different token must be rejected.
        $token   = Csrf::generate();
        $tampered = substr($token, 0, -1) . (str_ends_with($token, 'a') ? 'b' : 'a');
        $this->assertFalse(Csrf::validate($tampered));
    }

    // ── validatePost() / validateGet() ────────────────────────────

    public function testValidatePostReadsFromPostArray(): void
    {
        $token = Csrf::generate();

        $_POST = ['csrf_token' => $token];
        $this->assertTrue(Csrf::validatePost());

        $_POST = ['csrf_token' => 'wrong'];
        $this->assertFalse(Csrf::validatePost());

        $_POST = [];
        $this->assertFalse(Csrf::validatePost(), 'Missing csrf_token in POST → fail');
    }

    public function testValidateGetReadsFromGetArray(): void
    {
        $token = Csrf::generate();

        $_GET = ['csrf_token' => $token];
        $this->assertTrue(Csrf::validateGet());

        $_GET = ['csrf_token' => 'wrong'];
        $this->assertFalse(Csrf::validateGet());
    }

    public function testValidatePostIgnoresGetParameter(): void
    {
        $token = Csrf::generate();
        $_GET  = ['csrf_token' => $token];
        $_POST = []; // empty
        $this->assertFalse(Csrf::validatePost(), 'POST validator must not read from GET');
    }

    // ── regenerate() ───────────────────────────────────────────────

    public function testRegenerateReplacesTheToken(): void
    {
        $original = Csrf::generate();
        Csrf::regenerate();
        $next = Csrf::get();
        $this->assertNotSame($original, $next);
    }

    public function testRegenerateInvalidatesTheOldToken(): void
    {
        // After regeneration the previous token must no longer validate.
        // This is what makes a "one-time-use" workflow possible.
        $oldToken = Csrf::generate();
        Csrf::regenerate();
        $this->assertFalse(Csrf::validate($oldToken));
    }

    // ── field() ────────────────────────────────────────────────────

    public function testFieldRendersHiddenInputWithCurrentToken(): void
    {
        $token = Csrf::generate();
        $html  = Csrf::field();
        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('name="csrf_token"', $html);
        $this->assertStringContainsString('value="' . $token . '"', $html);
    }

    public function testFieldEscapesTokenForHtmlContext(): void
    {
        // Tokens are hex from random_bytes so they cannot contain HTML special
        // chars naturally. But if anyone ever stores a tampered token in the
        // session (e.g. by accident), the field() output must not produce
        // unescaped HTML.
        $_SESSION['csrf_token'] = 'a"b<c';
        $html = Csrf::field();
        $this->assertStringNotContainsString('a"b<c', $html, 'Raw special chars must not leak into HTML');
        $this->assertStringContainsString('a&quot;b&lt;c', $html);
    }
}
