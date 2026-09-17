<?php

namespace Tests\Canvas;

use draw\SVGParser;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

ini_set('memory_limit', '512M');

class SVGParserStructuralLimitsTest extends TestCase
{
    public function test_normal_svg_still_parses(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><g><path d="M0 0 L10 10" fill="red"/></g></svg>';
        $doc = SVGParser::parseString($svg);
        $this->assertNotNull($doc);
    }

    public function test_deep_nesting_throws(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat('<g>', 210) . str_repeat('</g>', 210) . '</svg>';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('svg nesting too deep');
        SVGParser::parseString($svg);
    }

    public function test_too_many_elements_throws(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat('<g/>', 100001) . '</svg>';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('svg too many elements');
        SVGParser::parseString($svg);
    }

    public function test_long_path_data_throws(): void
    {
        $d = 'M0 0' . str_repeat(' L1 1', 60000);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path d="' . $d . '" fill="red"/></svg>';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('svg path too long');
        SVGParser::parseString($svg);
    }
}
