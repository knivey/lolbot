<?php
namespace lolbot\config;

use Doctrine\ORM\EntityManager;
use lolbot\entities\Channel;
use lolbot\entities\Network;
use scripts\linktitles\entities\linktitles_setting;

/**
 * Resolves effective per-scope settings for a running bot.
 *
 * linktitles_setting resolution follows the existing linktitles.php convention:
 * a channel-scoped row (if present) wins; otherwise the network-scoped row;
 * otherwise null (caller applies code defaults).
 */
class SettingsResolver
{
    public function __construct(private EntityManager $em) {}

    public function getLinktitlesSetting(Network $network, ?Channel $channel): ?linktitles_setting
    {
        $repo = $this->em->getRepository(linktitles_setting::class);
        if ($channel !== null) {
            $s = $repo->findOneBy(['channel' => $channel]);
            if ($s !== null) {
                return $s;
            }
        }
        return $repo->findOneBy(['network' => $network, 'channel' => null]);
    }

    public function linktitlesEnabled(Network $network, ?Channel $channel): bool
    {
        $setting = $this->getLinktitlesSetting($network, $channel);
        return $setting !== null && $setting->enabled;
    }

    public function urlLogChan(Network $network, ?Channel $channel): ?string
    {
        return $this->getLinktitlesSetting($network, $channel)?->url_log_chan;
    }
}
