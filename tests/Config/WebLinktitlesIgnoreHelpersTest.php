<?php
namespace Tests\Config;

use lolbot\config\ConfigService;
use scripts\linktitles\entities\ignore;
use scripts\linktitles\entities\ignore_type;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Pure helpers for the linktitles ignores panel (function-surface tests:
 * the section file is require_once'd directly, WebAuthTest pattern).
 */
class WebLinktitlesIgnoreHelpersTest extends ConfigTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../web/sections/linktitles.php';
    }

    protected function tearDown(): void
    {
        unset($_POST['type'], $_POST['network'], $_POST['bot']);
        parent::tearDown();
    }

    public function test_match_rows_flattens_url_and_host_rows(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b1');
        $ig = $svc->addLinktitlesIgnore('example\.com', ignore_type::global);
        $bi = $svc->addLinktitlesIgnore('foo', ignore_type::bot, null, $bot);
        $hi = $svc->addLinktitlesHostignore('*!*@*.bad', ignore_type::network, $net);

        $rows = web_lt_match_rows([$ig, $bi, $hi]);
        $this->assertSame([
            ['id' => $ig->id, 'pattern' => $ig->regex, 'scope' => 'global', 'invalid' => false],
            ['id' => $bi->id, 'pattern' => $bi->regex, 'scope' => 'bot: ' . $bot->name, 'invalid' => false],
            ['id' => $hi->id, 'pattern' => '*!*@*.bad', 'scope' => 'network: N', 'invalid' => false],
        ], $rows);
    }

    public function test_match_rows_flags_invalid_pattern(): void
    {
        $ig = new ignore(ignore_type::global);
        $ig->regex = '@unclosed[(@i';
        $this->em->persist($ig);
        $this->em->flush();

        $rows = web_lt_match_rows([$ig]);
        $this->assertTrue($rows[0]['invalid']);
    }

    public function test_scope_from_post_network(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $_POST['type'] = 'network';
        $_POST['network'] = (string)$net->id;
        [$type, $resolvedNet, $bot] = web_lt_ignore_scope_from_post(['svc' => $svc]);
        $this->assertSame(ignore_type::network, $type);
        $this->assertSame($net, $resolvedNet);
        $this->assertNull($bot);

        $_POST['type'] = 'global';
        [$type, $resolvedNet, $bot] = web_lt_ignore_scope_from_post(['svc' => $svc]);
        $this->assertSame(ignore_type::global, $type);
        $this->assertNull($resolvedNet);
        $this->assertNull($bot);
    }

    public function test_scope_from_post_bot(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b1');
        $_POST['type'] = 'bot';
        $_POST['bot'] = (string)$bot->id;
        [$type, $resolvedNet, $resolvedBot] = web_lt_ignore_scope_from_post(['svc' => $svc]);
        $this->assertSame(ignore_type::bot, $type);
        $this->assertNull($resolvedNet);
        $this->assertSame($bot, $resolvedBot);
    }

    public function test_scope_from_post_missing_network_throws(): void
    {
        $_POST['type'] = 'network';
        $_POST['network'] = '999';
        $this->expectException(\InvalidArgumentException::class);
        web_lt_ignore_scope_from_post(['svc' => new ConfigService($this->em)]);
    }

    public function test_scope_from_post_bad_type_throws(): void
    {
        $_POST['type'] = 'bogus';
        $this->expectException(\ValueError::class);
        web_lt_ignore_scope_from_post(['svc' => new ConfigService($this->em)]);
    }

    public function test_test_scope_optional_and_bot_implies_network(): void
    {
        $svc = new ConfigService($this->em);
        $net = $svc->createNetwork('N');
        $bot = $svc->createBot($net, 'b1');

        [$n, $b] = web_lt_test_scope_from_post(['svc' => $svc]);
        $this->assertNull($n);
        $this->assertNull($b);

        $_POST['network'] = (string)$net->id;
        [$n, $b] = web_lt_test_scope_from_post(['svc' => $svc]);
        $this->assertSame($net, $n);
        $this->assertNull($b);
        unset($_POST['network']);

        $_POST['bot'] = (string)$bot->id;
        [$n, $b] = web_lt_test_scope_from_post(['svc' => $svc]);
        $this->assertSame($bot, $b);
        $this->assertSame($net, $n);
    }
}
