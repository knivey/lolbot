<?php

namespace scripts\crypto;

use scripts\script_base;

class crypto extends script_base
{
    /**
     * Pick the best coin from CoinGecko search results.
     *
     * @param string $query
     * @param list<coinSearchResult> $results
     * @return coinSearchResult|null
     */
    public static function matchCoin(string $query, array $results): ?coinSearchResult
    {
        $q = strtolower($query);
        foreach ($results as $r) {
            if (strtolower($r->id) === $q || strtolower($r->symbol) === $q) {
                return $r;
            }
        }
        return $results[0] ?? null;
    }
}
