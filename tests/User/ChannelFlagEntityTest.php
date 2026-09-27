<?php
// tests/User/ChannelFlagEntityTest.php
use library\user\ChannelFlagRepo;
use library\user\UserRepos;
use lolbot\entities\ChannelFlag;
use PHPUnit\Framework\TestCase;

class ChannelFlagEntityTest extends TestCase
{
    public function test_entity_shape(): void
    {
        $cf = new ChannelFlag();
        $cf->channel_id = 7;
        $cf->user_id = 9;
        $cf->flags = ['admin'];
        $cf->addedBy = 'bootstrap';
        $this->assertSame(['admin'], $cf->flags);
        $this->assertInstanceOf(\DateTimeImmutable::class, $cf->created);
    }

    public function test_userrepos_carries_channel_repo(): void
    {
        $users = $this->createStub(\library\user\UserRepo::class);
        $masks = $this->createStub(\library\user\UserHostmaskRepo::class);
        $chans = $this->createStub(ChannelFlagRepo::class);
        $repos = new UserRepos($users, $masks, $chans);
        $this->assertSame($chans, $repos->channelFlags);
    }
}
