<?php
namespace Tests\Config;

use lolbot\config\SettingBool;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../../vendor/autoload.php';

class SettingBoolTest extends \PHPUnit\Framework\TestCase
{
    /** @return list<array{0: string, 1: bool}> */
    public static function acceptedValues(): array
    {
        return [
            ['true', true], ['1', true], ['on', true], ['yes', true], ['y', true], ['TRUE', true],
            ['false', false], ['0', false], ['off', false], ['no', false], ['n', false], ['', false],
        ];
    }

    #[DataProvider('acceptedValues')]
    public function test_accepts_boolean_forms(string $raw, bool $expected): void
    {
        $this->assertSame($expected, SettingBool::parse($raw));
    }

    public function test_rejects_garbage_with_honest_message(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be a boolean value (true/false/1/0/on/off/yes/no)');
        SettingBool::parse('garbage');
    }

    public function test_prefixes_message_with_setting_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('disabled must be a boolean value (true/false/1/0/on/off/yes/no)');
        SettingBool::parse('garbage', 'disabled');
    }
}
