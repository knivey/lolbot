<?php

namespace library\user;

use Doctrine\ORM\EntityManager;
use lolbot\entities\ChannelFlag;

final class DoctrineChannelFlagRepo implements ChannelFlagRepo
{
    public function __construct(private EntityManager $em)
    {
    }

    public function findForChannelUser(int $channelId, int $userId): ?object
    {
        return $this->em->getRepository(ChannelFlag::class)->findOneBy([
            'channel_id' => $channelId,
            'user_id' => $userId,
        ]);
    }
}
