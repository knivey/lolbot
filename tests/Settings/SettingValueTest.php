<?php
// tests/Settings/SettingValueTest.php — SettingValue::coerce()/display()
// input coercion per the settings-registry contract: garbage refuses the
// whole operation (nothing persists), bool forms are case-insensitive,
// ints go through FILTER_VALIDATE_INT, enums strict-match enum_of.
use library\settings\Setting;
use library\settings\SettingValue;
use PHPUnit\Framework\TestCase;

class SettingValueTest extends TestCase
{
    public function test_bool_forms(): void
    {
        $s = new Setting('b', type: 'bool');
        foreach (['true','on','1','yes','TRUE'] as $in) $this->assertTrue(SettingValue::coerce($s, $in), $in);
        foreach (['false','off','0','no'] as $in) $this->assertFalse(SettingValue::coerce($s, $in), $in);
    }

    public function test_bool_garbage_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SettingValue::coerce(new Setting('b', type: 'bool'), 'maybe');
    }

    public function test_int_and_garbage(): void
    {
        $this->assertSame(7, SettingValue::coerce(new Setting('i', type: 'int'), '7'));
        $this->assertSame(-3, SettingValue::coerce(new Setting('i', type: 'int'), '-3'));
        $this->expectException(InvalidArgumentException::class);
        SettingValue::coerce(new Setting('i', type: 'int'), '12a');
    }

    public function test_enum_membership(): void
    {
        $s = new Setting('e', type: 'enum', enum_of: ['metric', 'imperial']);
        $this->assertSame('metric', SettingValue::coerce($s, 'metric'));
        $this->expectException(InvalidArgumentException::class);
        SettingValue::coerce($s, 'kelvin');
    }

    public function test_string_passthrough_and_display(): void
    {
        $this->assertSame('Seattle, WA', SettingValue::coerce(new Setting('s'), 'Seattle, WA'));
        $this->assertSame('on', SettingValue::display(true));
        $this->assertSame('off', SettingValue::display(false));
        $this->assertSame('7', SettingValue::display(7));
    }
}
