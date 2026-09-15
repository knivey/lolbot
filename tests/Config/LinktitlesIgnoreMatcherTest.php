<?php

namespace Tests\Config;

use lolbot\config\ConfigService;
use scripts\linktitles\entities\hostignore;
use scripts\linktitles\entities\ignore;
use scripts\linktitles\entities\ignore_type;
use scripts\linktitles\IgnoreMatcher;

require_once __DIR__ . '/../../vendor/autoload.php';

class LinktitlesIgnoreMatcherTest extends ConfigTestCase
{
    private ConfigService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ConfigService($this->em);
    }

    public function test_global_url_row_matches_any_scope(): void
    {
        $ig = new ignore(ignore_type::global);
        $ig->regex = '@example\.com@i';
        $this->em->persist($ig);
        $this->em->flush();

        $net = $this->svc->createNetwork('N');
        $bot = $this->svc->createBot($net, 'b1');

        $this->assertSame([$ig], IgnoreMatcher::findUrlMatches($this->em, null, null, 'https://example.com/x'));
        $this->assertSame([$ig], IgnoreMatcher::findUrlMatches($this->em, $net, $bot, 'https://example.com/x'));
        $this->assertSame([], IgnoreMatcher::findUrlMatches($this->em, $net, $bot, 'https://other.com/x'));
    }

    public function test_network_url_row_matches_only_own_network(): void
    {
        $netA = $this->svc->createNetwork('A');
        $netB = $this->svc->createNetwork('B');
        $ig = new ignore(ignore_type::network);
        $ig->regex = '@example\.com@i';
        $ig->network = $netA;
        $this->em->persist($ig);
        $this->em->flush();

        $this->assertSame([$ig], IgnoreMatcher::findUrlMatches($this->em, $netA, null, 'https://example.com/x'));
        $this->assertSame([], IgnoreMatcher::findUrlMatches($this->em, $netB, null, 'https://example.com/x'));
        $this->assertSame([], IgnoreMatcher::findUrlMatches($this->em, null, null, 'https://example.com/x'));
    }

    public function test_bot_url_row_matches_only_own_bot(): void
    {
        $net = $this->svc->createNetwork('N');
        $b1 = $this->svc->createBot($net, 'b1');
        $b2 = $this->svc->createBot($net, 'b2');
        $ig = new ignore(ignore_type::bot);
        $ig->regex = '@example\.com@i';
        $ig->bot = $b1;
        $this->em->persist($ig);
        $this->em->flush();

        $this->assertSame([$ig], IgnoreMatcher::findUrlMatches($this->em, $net, $b1, 'https://example.com/x'));
        $this->assertSame([], IgnoreMatcher::findUrlMatches($this->em, $net, $b2, 'https://example.com/x'));
    }

    public function test_host_rows_glob_and_scope(): void
    {
        $netA = $this->svc->createNetwork('A');
        $netB = $this->svc->createNetwork('B');
        $hi = new hostignore(ignore_type::network);
        $hi->hostmask = '*!*@*.bad.example';
        $hi->network = $netA;
        $this->em->persist($hi);
        $this->em->flush();

        $this->assertSame([$hi], IgnoreMatcher::findHostMatches($this->em, $netA, null, 'nick!user@host.bad.example'));
        $this->assertSame([], IgnoreMatcher::findHostMatches($this->em, $netB, null, 'nick!user@host.bad.example'));
        $this->assertSame([], IgnoreMatcher::findHostMatches($this->em, $netA, null, 'nick!user@host.good.example'));
    }

    public function test_is_ignored_via_url_or_host(): void
    {
        $net = $this->svc->createNetwork('N');
        $ig = new ignore(ignore_type::global);
        $ig->regex = '@spam\.example@i';
        $this->em->persist($ig);
        $hi = new hostignore(ignore_type::global);
        $hi->hostmask = '*!*@*.spammer';
        $this->em->persist($hi);
        $this->em->flush();

        $this->assertTrue(IgnoreMatcher::isIgnored($this->em, $net, null, 'nick!user@ok.host', 'https://spam.example/x'));
        $this->assertTrue(IgnoreMatcher::isIgnored($this->em, $net, null, 'nick!user@irc.spammer', 'https://fine.example/x'));
        $this->assertFalse(IgnoreMatcher::isIgnored($this->em, $net, null, 'nick!user@ok.host', 'https://fine.example/x'));
    }

    public function test_invalid_stored_regex_is_no_match_and_flagged(): void
    {
        $ig = new ignore(ignore_type::global);
        $ig->regex = '@unclosed[(@i';
        $this->em->persist($ig);
        $this->em->flush();

        $this->assertSame([], IgnoreMatcher::findUrlMatches($this->em, null, null, 'https://example.com/x'));
        $this->assertFalse(IgnoreMatcher::patternIsValid($ig->regex));
        $this->assertTrue(IgnoreMatcher::patternIsValid('@ok@i'));
    }
}
