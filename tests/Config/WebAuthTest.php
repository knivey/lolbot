<?php
namespace Tests\Config;

require_once __DIR__ . '/../../vendor/autoload.php';

/** Auth helpers are tested via the function surface (session state in-process). */
class WebAuthTest extends ConfigTestCase
{
    private function bootAuth(array $config): void
    {
        $GLOBALS['config'] = $config;
        $GLOBALS['entityManager'] = $this->em; // web_app() builds ConfigService from the global EM.
        @session_start();
        $_SESSION = [];
        require_once __DIR__ . '/../../web/app.php';
        require_once __DIR__ . '/../../web/auth.php';
    }

    public function test_attempt_login_fails_when_no_key_configured(): void
    {
        // No control_key configured → login must be impossible (no open mode; an empty
        // submitted key must NOT satisfy hash_equals('', '')).
        $this->bootAuth([]);
        $this->assertFalse(web_attempt_login(''));
        $this->assertFalse(web_attempt_login('anything'));
        $this->assertFalse($_SESSION['authed'] ?? false);
    }

    public function test_attempt_login_succeeds_with_correct_key(): void
    {
        $this->bootAuth(['control_key' => 'sekret']);
        $this->assertTrue(web_attempt_login('sekret'));
        $this->assertTrue($_SESSION['authed'] ?? false);
    }

    public function test_attempt_login_rejects_wrong_key(): void
    {
        $this->bootAuth(['control_key' => 'sekret']);
        $this->assertFalse(web_attempt_login('nope'));
        $this->assertFalse($_SESSION['authed'] ?? false);
    }

    public function test_csrf_token_roundtrip(): void
    {
        $this->bootAuth(['control_key' => 'sekret']);
        $tok = web_csrf_token();
        $_POST['_csrf'] = $tok;
        web_verify_csrf(); // no exception
        $this->assertTrue(true);
    }

    public function test_csrf_rejects_mismatch(): void
    {
        $this->bootAuth(['control_key' => 'sekret']);
        $_POST['_csrf'] = 'wrong';
        $this->expectException(\RuntimeException::class);
        web_verify_csrf();
    }
}
