<?php
use lolbot\entities\ChannelSetting;
use lolbot\entities\UserSetting;
use PHPUnit\Framework\TestCase;

class EntityTest extends TestCase
{
    public function test_channel_setting_defaults(): void
    {
        $cs = new ChannelSetting();
        $cs->network_id = 1;
        $cs->settingKey = 'enabled';
        $cs->value = false;
        $this->assertNull($cs->channel_id);
        $this->assertNull($cs->updated);
    }

    public function test_user_setting_defaults(): void
    {
        $us = new UserSetting();
        $us->user_id = 5;
        $us->settingKey = 'weather.location';
        $us->value = 'Seattle';
        $this->assertNull($us->updated);
    }
}
