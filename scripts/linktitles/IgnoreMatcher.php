<?php
namespace scripts\linktitles;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManager;
use lolbot\entities\Bot;
use lolbot\entities\Network;
use scripts\linktitles\entities\hostignore;
use scripts\linktitles\entities\ignore;
use scripts\linktitles\entities\ignore_type;

/**
 * Shared matching for linktitles URL-regex and hostmask ignores. Both the
 * bot's enforcement (linktitles::urlIsIgnored) and the panel's tester call
 * these so the two can never drift apart.
 *
 * Scope: a row applies when it is global, scoped to the given network, or
 * scoped to the given bot.
 * //TODO channel scoping is unimplemented (entity has no Channel FK yet)
 */
class IgnoreMatcher
{
    /**
     * All URL-regex rows whose pattern matches $url at this scope.
     * @return list<ignore>
     */
    public static function findUrlMatches(EntityManager $em, ?Network $network, ?Bot $bot, string $url): array
    {
        $out = [];
        /** @var ignore[] $ignores */
        $ignores = $em->getRepository(ignore::class)->matching(self::scopeCriteria($network, $bot));
        foreach ($ignores as $ignore) {
            if (@preg_match($ignore->regex, $url) === 1) {
                $out[] = $ignore;
            }
        }
        return $out;
    }

    /**
     * All hostmask rows whose glob matches $fullhost at this scope.
     * @return list<hostignore>
     */
    public static function findHostMatches(EntityManager $em, ?Network $network, ?Bot $bot, string $fullhost): array
    {
        $out = [];
        /** @var hostignore[] $hostignores */
        $hostignores = $em->getRepository(hostignore::class)->matching(self::scopeCriteria($network, $bot));
        foreach ($hostignores as $hostignore) {
            $hostmask_re = \knivey\tools\globToRegex($hostignore->hostmask) . 'i';
            if (@preg_match($hostmask_re, $fullhost) === 1) {
                $out[] = $hostignore;
            }
        }
        return $out;
    }

    public static function isIgnored(EntityManager $em, ?Network $network, ?Bot $bot, string $fullhost, string $url): bool
    {
        return self::findUrlMatches($em, $network, $bot, $url) !== []
            || self::findHostMatches($em, $network, $bot, $fullhost) !== [];
    }

    /** True when the stored pattern compiles (legacy bad rows count as no match). */
    public static function patternIsValid(string $regex): bool
    {
        return @preg_match($regex, '') !== false;
    }

    /**
     * Rows visible at this scope: global, this network's, this bot's.
     * Explicitly grouped per type so e.g. another network's network-scoped
     * rows can never leak in through OR precedence.
     */
    private static function scopeCriteria(?Network $network, ?Bot $bot): Criteria
    {
        $expr = Criteria::expr();
        $ors = [$expr->eq('type', ignore_type::global)];
        if ($network !== null) {
            $ors[] = $expr->andX(
                $expr->eq('type', ignore_type::network),
                $expr->eq('network', $network),
            );
        }
        if ($bot !== null) {
            $ors[] = $expr->andX(
                $expr->eq('type', ignore_type::bot),
                $expr->eq('bot', $bot),
            );
        }
        return Criteria::create()->where(Criteria::expr()->orX(...$ors));
    }
}
