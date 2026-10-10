<?php

namespace Tests\Mal;

use PHPUnit\Framework\TestCase;
use scripts\mal\mal;

require_once __DIR__ . '/../../library/async_get_contents.php';

class SearchFetchMsgTest extends TestCase
{
    public function test_404_search_means_no_results(): void
    {
        // MAL answers HTTP 404 with the normal search page when a query
        // matches nothing, so the empty-table path never sees a 200; the
        // bot must say "no results found" instead of dumping the page
        $e = new \async_get_exception(
            "<!DOCTYPE html><html><head><title>Search Anime - MyAnimeList.net</title></head></html>",
            404
        );
        $this->assertSame('no results found', mal::searchFetchMsg($e));
    }

    public function test_other_http_status_keeps_raw_error(): void
    {
        $e = new \async_get_exception('upstream exploded', 503);
        $this->assertSame('Error (503): upstream exploded', mal::searchFetchMsg($e));
    }

    public function test_transport_failure_keeps_raw_error(): void
    {
        $e = new \async_get_exception('cURL error 28: timeout was reached', 0);
        $this->assertSame('Error (0): cURL error 28: timeout was reached', mal::searchFetchMsg($e));
    }
}
