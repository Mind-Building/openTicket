<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Auth;
use Tests\Support\DatabaseTestCase;

/**
 * Integration tests for Auth::login().
 *
 * These exercise the real DB + session stack: insert a user via the
 * DatabaseTestCase factory, then call Auth::login() and observe its
 * effects. The whole test runs in a transaction that is rolled back in
 * tearDown(), so the inserted user is gone before the next test starts.
 *
 * Covers the two hardening changes from the security pass:
 *   • password_verify() runs against a dummy hash when the user is missing,
 *     so the response is timing-equivalent for known vs. unknown usernames,
 *   • a failed login for an existing user keeps the resulting record (no
 *     side effects on the user row itself).
 */
class AuthLoginTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // PHP sessions need to be reset between tests, otherwise login state
        // leaks. tests/bootstrap.php doesn't start a session – we manage it
        // explicitly here so Auth::login()'s Session::regenerate() works.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
        } else {
            // headers_sent() is always true under CLI, so session_start would
            // emit a warning. Just initialise $_SESSION directly – Session::set
            // operates on $_SESSION, which is what login uses.
            $_SESSION = [];
        }
    }

    public function testLoginSucceedsForValidCredentials(): void
    {
        $user = $this->createUser([
            'username'      => 'auth_test_admin',
            'password_hash' => password_hash('correct horse battery staple', PASSWORD_DEFAULT),
            'role'          => 'admin',
        ]);

        $result = Auth::login('auth_test_admin', 'correct horse battery staple');

        $this->assertTrue($result);
        $this->assertSame($user['id'], $_SESSION['user_id'] ?? null);
        $this->assertSame('admin', $_SESSION['user_role'] ?? null);
    }

    public function testLoginFailsForWrongPassword(): void
    {
        $this->createUser([
            'username'      => 'auth_test_wrongpw',
            'password_hash' => password_hash('the real password', PASSWORD_DEFAULT),
        ]);

        $result = Auth::login('auth_test_wrongpw', 'something else');

        $this->assertFalse($result);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testLoginFailsForUnknownUsername(): void
    {
        $result = Auth::login('user_that_does_not_exist_zzzz', 'any password');
        $this->assertFalse($result);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testLoginFailsForInactiveUser(): void
    {
        // The WHERE clause in Auth::login filters AND active = 1, so a
        // deactivated user must not be able to log in even with the right pw.
        $this->createUser([
            'username'      => 'auth_test_inactive',
            'password_hash' => password_hash('right password', PASSWORD_DEFAULT),
            'active'        => 0,
        ]);

        $result = Auth::login('auth_test_inactive', 'right password');
        $this->assertFalse($result);
    }

    public function testTimingForKnownVsUnknownUserIsSimilar(): void
    {
        // The #4 hardening always runs password_verify against a dummy hash
        // when the username doesn't exist, so an attacker can't enumerate
        // valid usernames by measuring response time.
        $this->createUser([
            'username'      => 'auth_test_timing',
            'password_hash' => password_hash('any pw', PASSWORD_DEFAULT),
        ]);

        $time = function (string $u, string $p): float {
            $t = microtime(true);
            Auth::login($u, $p);
            return (microtime(true) - $t) * 1000.0;
        };

        // Warmup so JIT / autoloader cold-start doesn't skew the first sample.
        $time('auth_test_timing', 'wrong1');
        $time('does_not_exist_warmup', 'wrong1');

        $known    = $time('auth_test_timing',       'wrong2');
        $unknown  = $time('does_not_exist_zzzz_zzz', 'wrong3');

        // The runs should be within an order of magnitude. Allow a generous
        // 50% relative tolerance to keep this test robust on shared CI nodes;
        // the pre-fix gap was orders of magnitude (~50ms vs ~0ms).
        $diff     = abs($known - $unknown);
        $smaller  = min($known, $unknown);
        $this->assertLessThan(
            $smaller * 0.5,
            $diff,
            "Timing between known ({$known}ms) and unknown ({$unknown}ms) user differs too much – timing oracle regression?"
        );
    }

    public function testFailedLoginLeavesUserRowUntouched(): void
    {
        $user = $this->createUser([
            'username'      => 'auth_test_idempotent',
            'password_hash' => password_hash('right', PASSWORD_DEFAULT),
        ]);

        $before = $this->pdo
            ->query("SELECT * FROM users WHERE id = {$user['id']}")
            ->fetch();

        Auth::login('auth_test_idempotent', 'wrong');
        Auth::login('auth_test_idempotent', 'wrong');
        Auth::login('auth_test_idempotent', 'wrong');

        $after = $this->pdo
            ->query("SELECT * FROM users WHERE id = {$user['id']}")
            ->fetch();

        // last_login should not have been updated for failed attempts.
        $this->assertSame($before['last_login'], $after['last_login']);
        $this->assertSame($before['password_hash'], $after['password_hash']);
    }
}
