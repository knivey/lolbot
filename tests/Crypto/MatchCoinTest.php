<?php

namespace Tests\Crypto;

use PHPUnit\Framework\TestCase;
use scripts\crypto\coinSearchResult;
use scripts\crypto\crypto;

class MatchCoinTest extends TestCase
{
    private static function mkCoin(string $id, string $name, string $symbol): coinSearchResult
    {
        $c = new coinSearchResult();
        $c->id = $id;
        $c->name = $name;
        $c->api_symbol = $id;
        $c->symbol = $symbol;
        $c->market_cap_rank = null;
        return $c;
    }

    public function test_exact_id_match(): void
    {
        $results = [
            self::mkCoin('ethereum', 'Ethereum', 'ETH'),
            self::mkCoin('bitcoin', 'Bitcoin', 'BTC'),
        ];
        $match = crypto::matchCoin('bitcoin', $results);
        $this->assertNotNull($match);
        $this->assertSame('bitcoin', $match->id);
    }

    public function test_exact_symbol_match_case_insensitive(): void
    {
        $results = [
            self::mkCoin('ethereum', 'Ethereum', 'ETH'),
            self::mkCoin('bitcoin', 'Bitcoin', 'BTC'),
        ];
        $match = crypto::matchCoin('BTC', $results);
        $this->assertNotNull($match);
        $this->assertSame('bitcoin', $match->id);
    }

    public function test_exact_match_preferred_over_ranked_first(): void
    {
        // First (ranked) result does NOT exact-match; second does.
        $results = [
            self::mkCoin('bitcoin-cash', 'Bitcoin Cash', 'BCH'),
            self::mkCoin('bitcoin', 'Bitcoin', 'BTC'),
        ];
        $match = crypto::matchCoin('bitcoin', $results);
        $this->assertNotNull($match);
        $this->assertSame('bitcoin', $match->id);
    }

    public function test_ranked_fallback_returns_first_when_no_exact(): void
    {
        $results = [
            self::mkCoin('bitcoin', 'Bitcoin', 'BTC'),
            self::mkCoin('bitcoin-cash', 'Bitcoin Cash', 'BCH'),
        ];
        $match = crypto::matchCoin('bit', $results);
        $this->assertNotNull($match);
        $this->assertSame('bitcoin', $match->id);
    }

    public function test_empty_results_returns_null(): void
    {
        $this->assertNull(crypto::matchCoin('anything', []));
    }
}
