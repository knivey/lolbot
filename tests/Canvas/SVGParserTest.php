<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\Color;
use draw\Group;
use draw\Path;
use draw\SVGDocument;
use draw\Shape;
use draw\SVGParser;
use draw\Transform;
use PHPUnit\Framework\TestCase;

class SVGParserTest extends TestCase
{
    public function test_parse_d_moveto_lineto(): void
    {
        $path = SVGParser::parseDString('M 10 20 L 30 40');
        $this->assertSame([30.0, 40.0], $path->getCurrentPoint());
        $subpaths = $path->flatten();
        $this->assertCount(1, $subpaths);
        $this->assertEqualsWithDelta(10.0, $subpaths[0]['vertices'][0][0], 0.001);
        $this->assertEqualsWithDelta(40.0, $subpaths[0]['vertices'][1][1], 0.001);
    }

    public function test_parse_d_relative_moveto_lineto(): void
    {
        $path = SVGParser::parseDString('M 10 20 l 5 10');
        $this->assertSame([15.0, 30.0], $path->getCurrentPoint());
    }

    public function test_parse_d_implicit_lineto_repeat(): void
    {
        $path = SVGParser::parseDString('M 0 0 L 10 10 20 20');
        $this->assertSame([20.0, 20.0], $path->getCurrentPoint());
    }

    public function test_parse_d_horizontal_vertical(): void
    {
        $path = SVGParser::parseDString('M 10 20 H 30 V 50');
        $this->assertSame([30.0, 50.0], $path->getCurrentPoint());
    }

    public function test_parse_d_closepath(): void
    {
        $path = SVGParser::parseDString('M 10 20 L 30 40 Z');
        $subpaths = $path->flatten();
        $this->assertTrue($subpaths[0]['closed']);
    }

    public function test_parse_d_cubic_bezier(): void
    {
        $path = SVGParser::parseDString('M 0 0 C 10 20 30 40 50 60');
        $this->assertSame([50.0, 60.0], $path->getCurrentPoint());
    }

    public function test_parse_d_smooth_cubic(): void
    {
        $path = SVGParser::parseDString('M 0 0 C 10 20 30 40 50 60 S 90 100 110 120');
        $this->assertSame([110.0, 120.0], $path->getCurrentPoint());
    }

    public function test_parse_d_quadratic_bezier(): void
    {
        $path = SVGParser::parseDString('M 0 0 Q 25 50 50 0');
        $this->assertSame([50.0, 0.0], $path->getCurrentPoint());
    }

    public function test_parse_d_smooth_quadratic(): void
    {
        $path = SVGParser::parseDString('M 0 0 Q 25 50 50 0 T 100 0');
        $this->assertSame([100.0, 0.0], $path->getCurrentPoint());
    }

    public function test_parse_d_arc(): void
    {
        $path = SVGParser::parseDString('M 0 0 A 25 25 0 0 1 50 50');
        $this->assertSame([50.0, 50.0], $path->getCurrentPoint());
    }

    public function test_parse_d_multiple_subpaths(): void
    {
        $path = SVGParser::parseDString('M 0 0 L 10 10 M 20 20 L 30 30');
        $subpaths = $path->flatten();
        $this->assertCount(2, $subpaths);
    }

    public function test_parse_d_comma_separated(): void
    {
        $path = SVGParser::parseDString('M 10,20 L 30,40');
        $this->assertSame([30.0, 40.0], $path->getCurrentPoint());
    }

    public function test_parse_d_negative_as_delimiter(): void
    {
        $path = SVGParser::parseDString('M 10-20L30-40');
        $this->assertSame([30.0, -40.0], $path->getCurrentPoint());
    }

    public function test_parse_d_arc_flags_no_separator(): void
    {
        $path = SVGParser::parseDString('M 0 0 A 25 25 0 01 50 50');
        $this->assertSame([50.0, 50.0], $path->getCurrentPoint());
    }

    public function test_parse_d_empty_string_returns_empty_path(): void
    {
        $path = SVGParser::parseDString('');
        $this->assertTrue($path->isEmpty());
    }

    public function test_parse_transform_translate(): void
    {
        $t = SVGParser::parseTransform('translate(10, 20)');
        $result = $t->apply(0.0, 0.0);
        $this->assertEqualsWithDelta(10.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(20.0, $result[1], 0.001);
    }

    public function test_parse_transform_translate_one_arg(): void
    {
        $t = SVGParser::parseTransform('translate(10)');
        $result = $t->apply(0.0, 0.0);
        $this->assertEqualsWithDelta(10.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(0.0, $result[1], 0.001);
    }

    public function test_parse_transform_scale(): void
    {
        $t = SVGParser::parseTransform('scale(2, 3)');
        $result = $t->apply(10.0, 10.0);
        $this->assertEqualsWithDelta(20.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(30.0, $result[1], 0.001);
    }

    public function test_parse_transform_scale_one_arg(): void
    {
        $t = SVGParser::parseTransform('scale(2)');
        $result = $t->apply(10.0, 10.0);
        $this->assertEqualsWithDelta(20.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(20.0, $result[1], 0.001);
    }

    public function test_parse_transform_rotate(): void
    {
        $t = SVGParser::parseTransform('rotate(90)');
        $result = $t->apply(1.0, 0.0);
        $this->assertEqualsWithDelta(0.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(1.0, $result[1], 0.001);
    }

    public function test_parse_transform_rotate_with_center(): void
    {
        $t = SVGParser::parseTransform('rotate(90, 50, 50)');
        $result = $t->apply(51.0, 50.0);
        $this->assertEqualsWithDelta(50.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(51.0, $result[1], 0.001);
    }

    public function test_parse_transform_skewX(): void
    {
        $t = SVGParser::parseTransform('skewX(45)');
        $result = $t->apply(1.0, 1.0);
        $this->assertEqualsWithDelta(2.0, $result[0], 0.01);
        $this->assertEqualsWithDelta(1.0, $result[1], 0.001);
    }

    public function test_parse_transform_skewY(): void
    {
        $t = SVGParser::parseTransform('skewY(45)');
        $result = $t->apply(1.0, 1.0);
        $this->assertEqualsWithDelta(1.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(2.0, $result[1], 0.01);
    }

    public function test_parse_transform_matrix(): void
    {
        $t = SVGParser::parseTransform('matrix(2, 0, 0, 3, 10, 20)');
        $result = $t->apply(5.0, 5.0);
        $this->assertEqualsWithDelta(20.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(35.0, $result[1], 0.001);
    }

    public function test_parse_transform_chained(): void
    {
        $t = SVGParser::parseTransform('translate(10, 0) scale(2)');
        $result = $t->apply(5.0, 5.0);
        $this->assertEqualsWithDelta(20.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(10.0, $result[1], 0.001);
    }

    public function test_parse_transform_empty_returns_identity(): void
    {
        $t = SVGParser::parseTransform('');
        $this->assertTrue(Transform::identity()->equals($t));
    }

    public function test_parse_string_rect(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="5" y="5" width="10" height="10" fill="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 20);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
        $this->assertNull($canvas->data[0][0]->fg);
    }

    public function test_parse_string_circle(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="5" fill="blue"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 20);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[10][10]->fg);
    }

    public function test_parse_string_ellipse(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><ellipse cx="15" cy="10" rx="10" ry="5" fill="green"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(30, 20);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[10][15]->fg);
    }

    public function test_parse_string_line(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><line x1="0" y1="5" x2="19" y2="5" stroke="white"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 10);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][10]->fg);
    }

    public function test_parse_string_polyline(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><polyline points="0,0 10,0 10,10" stroke="white"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 20);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[0][5]->fg);
    }

    public function test_parse_string_polygon(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><polygon points="5,0 10,10 0,10" fill="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_path_element(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path d="M 0 0 L 10 0 L 10 10 L 0 10 Z" fill="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_group(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><g fill="red"><rect x="0" y="0" width="5" height="5"/></g></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(10, 10);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[2][2]->fg);
    }

    public function test_parse_string_viewBox(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect x="0" y="0" width="50" height="50" fill="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $this->assertSame([0.0, 0.0, 100.0, 100.0], $doc->getViewBox());
        $canvas = Canvas::createBlank(40, 40);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[10][10]->fg);
    }

    public function test_parse_string_transform_attribute(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="0" y="0" width="5" height="5" fill="red" transform="translate(5,5)"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNull($canvas->data[0][0]->fg);
        $this->assertNotNull($canvas->data[7][7]->fg);
    }

    public function test_parse_string_unknown_element_ignored(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="0" y="0" width="10" height="10" fill="red"/><text>Hello</text></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_opacity(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="0" y="0" width="10" height="10" fill="red" opacity="0.5"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_fill_none(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="0" y="0" width="10" height="10" fill="none" stroke="white"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[0][5]->fg);
    }

    public function test_parse_string_linear_gradient(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g1" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#0000ff"/></linearGradient></defs><rect x="0" y="0" width="20" height="10" fill="url(#g1)"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 10);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
        $this->assertNotNull($canvas->data[5][15]->fg);
    }

    public function test_parse_string_radial_gradient(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><radialGradient id="rg" cx="10" cy="10" r="10"><stop offset="0" stop-color="white"/><stop offset="1" stop-color="black"/></radialGradient></defs><rect x="0" y="0" width="20" height="20" fill="url(#rg)"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 20);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[10][10]->fg);
    }

    public function test_parse_string_gradient_with_percentage_stops(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g2" x1="0%" y1="0%" x2="100%" y2="0%"><stop offset="0%" stop-color="red"/><stop offset="100%" stop-color="blue"/></linearGradient></defs><rect x="0" y="0" width="10" height="10" fill="url(#g2)"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(10, 10);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_missing_gradient_ref_no_fill(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="0" y="0" width="10" height="10" fill="url(#missing)"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(10, 10);
        $doc->render($canvas);
        $this->assertNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_logs_unknown_element(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $warnings = [];
            public function log($level, $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = $message;
                }
            }
        };
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><text x="0" y="0">hello</text></svg>';
        SVGParser::parseString($svg, $logger);
        $this->assertNotEmpty($logger->warnings);
    }

    public function test_parse_string_no_logger_no_error(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><text x="0" y="0">hello</text><rect x="0" y="0" width="5" height="5" fill="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(10, 10);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[2][2]->fg);
    }

    public function test_parse_string_gradient_spread_reflect(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g3" x1="0" y1="0" x2="5" y2="0" spreadMethod="reflect"><stop offset="0" stop-color="red"/><stop offset="1" stop-color="blue"/></linearGradient></defs><rect x="0" y="0" width="20" height="5" fill="url(#g3)"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 5);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[2][10]->fg);
    }

    public function test_integration_nested_groups_with_styles(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><g fill="red"><g transform="translate(5,5)"><rect x="0" y="0" width="5" height="5"/></g></g></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNull($canvas->data[2][2]->fg);
        $this->assertNotNull($canvas->data[7][7]->fg);
    }

    public function test_integration_viewbox_with_shapes(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><circle cx="100" cy="100" r="50" fill="blue"/><rect x="0" y="0" width="200" height="200" fill="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(40, 40);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[20][20]->fg);
    }

    public function test_integration_fill_and_stroke(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect x="2" y="2" width="16" height="6" fill="red" stroke="white" stroke-width="2"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 10);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
        $this->assertNotNull($canvas->data[2][5]->fg);
    }

    public function test_integration_readFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'svgtest_');
        file_put_contents($tmpFile, '<svg xmlns="http://www.w3.org/2000/svg"><rect x="0" y="0" width="10" height="10" fill="red"/></svg>');
        try {
            $doc = SVGParser::readFile($tmpFile);
            $canvas = Canvas::createBlank(10, 10);
            $doc->render($canvas);
            $this->assertNotNull($canvas->data[5][5]->fg);
        } finally {
            unlink($tmpFile);
        }
    }

    public function test_integration_malformed_xml_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SVGParser::parseString('<not valid xml');
    }

    public function test_integration_nonexistent_file_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SVGParser::readFile('/nonexistent/path/to/file.svg');
    }

    public function test_integration_complex_path(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path d="M 10 0 L 20 20 L 0 20 Z" fill="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(25, 25);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[10][10]->fg);
    }

    public function test_integration_evenodd_fill_rule(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path d="M 0 0 L 20 0 L 20 20 L 0 20 Z M 5 5 L 15 5 L 15 15 L 5 15 Z" fill="red" fill-rule="evenodd"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(25, 25);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[2][2]->fg);
        $this->assertNull($canvas->data[10][10]->fg);
    }

    public function test_parse_string_css_class_selector(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.red { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none" class="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_id_selector(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>#myRect { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none" id="myRect"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_type_selector(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>rect { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_universal_selector(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>* { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_multiple_classes(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.red { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none" class="bold red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_comma_separated_selectors(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.a, .b { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none" class="b"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_type_selector_case_insensitive(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>RECT { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_inline_style_overrides_class(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.red { fill: red; }</style><rect x="0" y="0" width="10" height="10" class="red" style="fill:none"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_overrides_presentation_attr(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.red { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none" class="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_id_overrides_class(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.blue { fill: blue; } #special { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none" class="blue" id="special"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_last_rule_wins_same_specificity(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.a { fill: none; } .b { fill: red; }</style><rect x="0" y="0" width="10" height="10" class="a b"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_class_overrides_type(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>rect { fill: none; } .red { fill: red; }</style><rect x="0" y="0" width="10" height="10" class="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_stroke_from_class(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.outlined { stroke: white; fill: none; }</style><rect x="0" y="0" width="10" height="10" class="outlined"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[0][5]->fg);
    }

    public function test_parse_string_css_opacity(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.semi { opacity: 0.5; }</style><rect x="0" y="0" width="10" height="10" fill="red" class="semi"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_no_matching_class_no_fill(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.red { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none" class="blue"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_display_none(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.hidden { display: none; }</style><rect x="0" y="0" width="10" height="10" fill="red" class="hidden"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_empty_style_block(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style></style><rect x="0" y="0" width="10" height="10" fill="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_unknown_property_ignored(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.red { fill: red; cursor: pointer; font-size: 14px; }</style><rect x="0" y="0" width="10" height="10" fill="none" class="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_comments_stripped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>/* comment */.red { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none" class="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_style_in_defs(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><style>.red { fill: red; }</style></defs><rect x="0" y="0" width="10" height="10" fill="none" class="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_does_not_log_style_element(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $warnings = [];
            public function log($level, $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = $message;
                }
            }
        };
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.red { fill: red; }</style><rect x="0" y="0" width="5" height="5" fill="red"/></svg>';
        SVGParser::parseString($svg, $logger);
        $this->assertEmpty($logger->warnings);
    }

    public function test_parse_string_css_malformed_rule_skipped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.red { fill: red; } { broken } .blue { fill: blue; }</style><rect x="0" y="0" width="10" height="10" fill="none" class="red"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_css_stop_color_on_gradient(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.sc1 { stop-color: red; } .sc2 { stop-color: blue; }</style><defs><linearGradient id="g1" x1="0" y1="0" x2="1" y2="0"><stop offset="0" class="sc1"/><stop offset="1" class="sc2"/></linearGradient></defs><rect x="0" y="0" width="20" height="10" fill="url(#g1)"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 10);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
        $this->assertNotNull($canvas->data[5][15]->fg);
    }

    public function test_parse_string_css_style_inside_group(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><g><style>.red { fill: red; }</style><rect x="0" y="0" width="10" height="10" fill="none" class="red"/></g></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_parse_string_fill_none_does_not_inherit_group_fill(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><g fill="red"><rect x="0" y="0" width="10" height="10" fill="none" stroke="white"/></g></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(15, 15);
        $doc->render($canvas);
        $this->assertNull($canvas->data[5][5]->fg);
        $this->assertNotNull($canvas->data[0][5]->fg);
    }

    public function test_linear_gradient_with_gradientTransform(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g1" x1="0" y1="0" x2="1" y2="0" gradientTransform="translate(0, 5)"><stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#0000ff"/></linearGradient></defs><rect x="0" y="0" width="20" height="10" fill="url(#g1)"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 10);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
        $this->assertNotNull($canvas->data[5][15]->fg);
    }

    public function test_radial_gradient_with_gradientTransform(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><radialGradient id="rg" cx="0" cy="0" r="1" gradientTransform="translate(10, 10) scale(10)"><stop offset="0" stop-color="white"/><stop offset="1" stop-color="black"/></radialGradient></defs><rect x="0" y="0" width="20" height="20" fill="url(#rg)"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 20);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[10][10]->fg);
    }

    public function test_gradient_with_userSpaceOnUse(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g1" x1="0" y1="5" x2="20" y2="5" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#0000ff"/></linearGradient></defs><rect x="0" y="0" width="20" height="10" fill="url(#g1)"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 10);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_gradient_with_viewBox_and_gradientTransform(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><defs><radialGradient id="rg" cx="0" cy="0" r="1" gradientTransform="translate(100, 100) scale(100)"><stop offset="0" stop-color="#ffcc00"/><stop offset="1" stop-color="#0066ff"/></radialGradient></defs><rect x="0" y="0" width="200" height="200" fill="url(#rg)"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 20);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[10][10]->fg);
        $this->assertNotNull($canvas->data[5][5]->fg);
    }

    public function test_gradient_default_gradientUnits_is_objectBoundingBox(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g1" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#0000ff"/></linearGradient></defs><rect x="0" y="0" width="20" height="10" fill="url(#g1)"/></svg>';
        $doc = SVGParser::parseString($svg);
        $canvas = Canvas::createBlank(20, 10);
        $doc->render($canvas);
        $this->assertNotNull($canvas->data[5][5]->fg);
        $this->assertNotNull($canvas->data[5][15]->fg);
    }
}
