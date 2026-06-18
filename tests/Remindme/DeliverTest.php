<?php

namespace Tests\Remindme;

use PHPUnit\Framework\TestCase;
use scripts\remindme\entities\reminder;
use scripts\remindme\remindme;

class DeliverTest extends TestCase
{
    private bool $emWasSet = false;

    protected function setUp(): void
    {
        $this->emWasSet = isset($GLOBALS['entityManager']);
    }

    protected function tearDown(): void
    {
        if (!$this->emWasSet) {
            unset($GLOBALS['entityManager']);
        }
    }

    private function makeReminder(bool $sent = false): reminder
    {
        $r = new reminder();
        $r->nick = 'knivey';
        $r->chan = '#deviate';
        $r->msg = 'lol';
        $r->at = time() + 60;
        $r->sent = $sent;
        return $r;
    }

    public function test_deliver_sends_and_marks_sent(): void
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');
        $GLOBALS['entityManager'] = $em;

        $r = $this->makeReminder(sent: false);

        $bot = $this->createMock(\Irc\Client::class);
        $bot->expects($this->once())
            ->method('pm')
            ->with('#deviate', '[REMINDER: knivey] lol');

        remindme::deliver($bot, $r);

        $this->assertTrue($r->sent);
    }

    public function test_deliver_skips_already_sent(): void
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');
        $GLOBALS['entityManager'] = $em;

        $r = $this->makeReminder(sent: true);

        $bot = $this->createMock(\Irc\Client::class);
        $bot->expects($this->never())->method('pm');

        remindme::deliver($bot, $r);

        $this->assertTrue($r->sent);
    }

    /**
     * Reproduces the init()/in() startup race: the same reminder entity
     * gets scheduled twice (once by the command handler, once by init()
     * loading from DB). Without the guard, deliver() would send twice.
     */
    public function test_deliver_only_sends_once_when_called_twice(): void
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $GLOBALS['entityManager'] = $em;

        $r = $this->makeReminder(sent: false);

        $bot = $this->createMock(\Irc\Client::class);
        $bot->expects($this->once())->method('pm');

        remindme::deliver($bot, $r);
        remindme::deliver($bot, $r);

        $this->assertTrue($r->sent);
    }
}
