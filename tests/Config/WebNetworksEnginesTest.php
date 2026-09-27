<?php
namespace Tests\Config;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../web/sections/networks.php';

/**
 * auth_engines form-field parsing for web_networks_update: the field is
 * comma-separated engine names, and anything that filters down to no
 * names (empty field, lone commas, commas with spaces) means auto-detect
 * (null) — NOT a pinned empty chain, which would silently disable every
 * engine for the network.
 */
class WebNetworksEnginesTest extends \PHPUnit\Framework\TestCase
{
    public function test_empty_field_parses_to_null_auto_detect(): void
    {
        $this->assertNull(\web_networks_parse_auth_engines(''));
    }

    public function test_comma_only_field_parses_to_null_auto_detect(): void
    {
        $this->assertNull(\web_networks_parse_auth_engines(','));
        $this->assertNull(\web_networks_parse_auth_engines(' , , '));
    }

    public function test_names_are_trimmed_and_empty_entries_dropped(): void
    {
        $this->assertSame(
            ['account-tag', 'hostmask'],
            \web_networks_parse_auth_engines(' account-tag , ,hostmask, '),
        );
    }

    public function test_single_name_parses_to_one_element_list(): void
    {
        $this->assertSame(['manual'], \web_networks_parse_auth_engines('manual'));
    }
}
