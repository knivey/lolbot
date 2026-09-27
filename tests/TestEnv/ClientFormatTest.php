<?php
// tests/TestEnv/ClientFormatTest.php
use library\testenv\ClientFormat;
use PHPUnit\Framework\TestCase;

class ClientFormatTest extends TestCase
{
    public function test_formats_a_plain_privmsg(): void
    {
        $line = ClientFormat::formatLine(['from' => 'DevBot1', 'target' => '#testchan', 'text' => 'hello']);
        $this->assertMatchesRegularExpression('/^\[\d\d:\d\d\] <DevBot1> hello$/', $line);
    }

    public function test_collapses_ctcp(): void
    {
        $line = ClientFormat::formatLine(['from' => 'x', 'target' => '#c', 'text' => "\x01ACTION waves\x01"]);
        $this->assertStringNotContainsString("\x01", $line);
        $this->assertStringContainsString('[CTCP', $line);
    }

    public function test_strips_color_codes_and_control_chars(): void
    {
        $line = ClientFormat::formatLine(['from' => 'x', 'target' => '#c', 'text' => "\x034red\x03 plain\x02bold\x0f"]);
        $this->assertStringNotContainsString("\x03", $line);
        $this->assertStringContainsString('plain', $line);
        $line2 = ClientFormat::formatLine(['from' => 'x', 'target' => '#c', 'text' => "a\nb\rc"]);
        $this->assertStringNotContainsString("\n", $line2);
    }
}
