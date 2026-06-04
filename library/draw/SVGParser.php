<?php

namespace draw;

use Psr\Log\LoggerInterface;

class SVGParser
{
    public static function parseDString(string $d): Path
    {
        $path = new Path();
        $d = trim($d);
        if ($d === '') {
            return $path;
        }

        $tokens = self::tokenizeD($d);
        if (empty($tokens)) {
            return $path;
        }

        $pos = 0;
        $count = count($tokens);
        $lastCmd = '';

        while ($pos < $count) {
            $token = $tokens[$pos];
            if (strlen($token) === 1 && ctype_alpha($token)) {
                $cmd = $token;
                $pos++;
                $lastCmd = $cmd;
            } else {
                $cmd = $lastCmd;
            }

            if ($cmd === '') {
                break;
            }

            $upper = strtoupper((string)$cmd);
            $relative = $cmd !== $upper;

            switch ($upper) {
                case 'M':
                    $first = true;
                    while ($pos < $count && !ctype_alpha($tokens[$pos])) {
                        $coords = self::consumeCoordPair($tokens, $pos, $count);
                        if ($coords === null) {
                            break;
                        }
                        [$x, $y] = $coords;
                        if ($relative) {
                            $cp = $path->getCurrentPoint();
                            $x += $cp[0];
                            $y += $cp[1];
                        }
                        if ($first) {
                            $path->moveTo($x, $y);
                            $first = false;
                            $lastCmd = $relative ? 'l' : 'L';
                        } else {
                            $path->lineTo($x, $y);
                        }
                    }
                    break;

                case 'L':
                    while ($pos < $count && !ctype_alpha($tokens[$pos])) {
                        $coords = self::consumeCoordPair($tokens, $pos, $count);
                        if ($coords === null) {
                            break;
                        }
                        [$x, $y] = $coords;
                        if ($relative) {
                            $cp = $path->getCurrentPoint();
                            $x += $cp[0];
                            $y += $cp[1];
                        }
                        $path->lineTo($x, $y);
                    }
                    break;

                case 'H':
                    while ($pos < $count && !ctype_alpha($tokens[$pos])) {
                        $x = (float)$tokens[$pos++];
                        if ($relative) {
                            $x += $path->getCurrentPoint()[0];
                        }
                        $path->horizontalLineTo($x);
                    }
                    break;

                case 'V':
                    while ($pos < $count && !ctype_alpha($tokens[$pos])) {
                        $y = (float)$tokens[$pos++];
                        if ($relative) {
                            $y += $path->getCurrentPoint()[1];
                        }
                        $path->verticalLineTo($y);
                    }
                    break;

                case 'C':
                    while ($pos < $count && !ctype_alpha($tokens[$pos])) {
                        $nums = self::consumeNumbers($tokens, $pos, $count, 6);
                        if ($nums === null) {
                            break;
                        }
                        if ($relative) {
                            $cp = $path->getCurrentPoint();
                            for ($i = 0; $i < 6; $i += 2) {
                                $nums[$i] += $cp[0];
                                $nums[$i + 1] += $cp[1];
                            }
                        }
                        $path->cubicTo(
                            $nums[0], $nums[1],
                            $nums[2], $nums[3],
                            $nums[4], $nums[5]
                        );
                    }
                    break;

                case 'S':
                    while ($pos < $count && !ctype_alpha($tokens[$pos])) {
                        $nums = self::consumeNumbers($tokens, $pos, $count, 4);
                        if ($nums === null) {
                            break;
                        }
                        if ($relative) {
                            $cp = $path->getCurrentPoint();
                            $nums[0] += $cp[0];
                            $nums[1] += $cp[1];
                            $nums[2] += $cp[0];
                            $nums[3] += $cp[1];
                        }
                        $path->smoothCubicTo($nums[0], $nums[1], $nums[2], $nums[3]);
                    }
                    break;

                case 'Q':
                    while ($pos < $count && !ctype_alpha($tokens[$pos])) {
                        $nums = self::consumeNumbers($tokens, $pos, $count, 4);
                        if ($nums === null) {
                            break;
                        }
                        if ($relative) {
                            $cp = $path->getCurrentPoint();
                            $nums[0] += $cp[0];
                            $nums[1] += $cp[1];
                            $nums[2] += $cp[0];
                            $nums[3] += $cp[1];
                        }
                        $path->quadTo($nums[0], $nums[1], $nums[2], $nums[3]);
                    }
                    break;

                case 'T':
                    while ($pos < $count && !ctype_alpha($tokens[$pos])) {
                        $coords = self::consumeCoordPair($tokens, $pos, $count);
                        if ($coords === null) {
                            break;
                        }
                        [$x, $y] = $coords;
                        if ($relative) {
                            $cp = $path->getCurrentPoint();
                            $x += $cp[0];
                            $y += $cp[1];
                        }
                        $path->smoothQuadTo($x, $y);
                    }
                    break;

                case 'A':
                    while ($pos < $count && !ctype_alpha($tokens[$pos])) {
                        $nums = self::consumeNumbers($tokens, $pos, $count, 3);
                        if ($nums === null) {
                            break;
                        }
                        $largeArc = self::consumeFlag($tokens, $pos);
                        $sweep = self::consumeFlag($tokens, $pos);
                        $coords = self::consumeCoordPair($tokens, $pos, $count);
                        if ($coords === null) {
                            break;
                        }
                        [$x, $y] = $coords;
                        if ($relative) {
                            $cp = $path->getCurrentPoint();
                            $x += $cp[0];
                            $y += $cp[1];
                        }
                        $path->arcTo(
                            $nums[0], $nums[1], $nums[2],
                            $largeArc, $sweep,
                            $x, $y
                        );
                    }
                    break;

                case 'Z':
                    $path->closePath();
                    break;
            }
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    public static function tokenizeD(string $d): array
    {
        preg_match_all(
            '/[a-zA-Z]|[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/',
            $d,
            $matches
        );
        return $matches[0];
    }

    /**
     * @param list<string> $tokens
     * @return array{float, float}|null
     */
    private static function consumeCoordPair(array &$tokens, int &$pos, int $count): ?array
    {
        if ($pos + 1 >= $count) {
            return null;
        }
        if (ctype_alpha($tokens[$pos]) || ctype_alpha($tokens[$pos + 1])) {
            return null;
        }
        $x = (float)$tokens[$pos++];
        $y = (float)$tokens[$pos++];
        return [$x, $y];
    }

    /**
     * @param list<string> $tokens
     * @return list<float>|null
     */
    private static function consumeNumbers(array &$tokens, int &$pos, int $count, int $n): ?array
    {
        if ($pos + $n > $count) {
            return null;
        }
        for ($i = 0; $i < $n; $i++) {
            if (ctype_alpha($tokens[$pos + $i])) {
                return null;
            }
        }
        $result = [];
        for ($i = 0; $i < $n; $i++) {
            $result[] = (float)$tokens[$pos++];
        }
        return $result;
    }

    public static function parseTransform(string $transform): Transform
    {
        $transform = trim($transform);
        if ($transform === '') {
            return Transform::identity();
        }

        $result = Transform::identity();

        preg_match_all(
            '/([a-zA-Z]+)\s*\(([^)]*)\)/',
            $transform,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $func = strtolower($match[1]);
            $args = preg_split('/[\s,]+/', trim($match[2]));
            $args = array_map('floatval', array_filter($args ?: [], fn($v) => $v !== ''));

            $t = match ($func) {
                'translate' => Transform::translate(
                    $args[0],
                    $args[1] ?? 0.0
                ),
                'scale' => Transform::scale(
                    $args[0],
                    $args[1] ?? $args[0]
                ),
                'rotate' => Transform::rotate(
                    deg2rad($args[0]),
                    $args[1] ?? 0.0,
                    $args[2] ?? 0.0
                ),
                'skewx' => Transform::skewX(deg2rad($args[0])),
                'skewy' => Transform::skewY(deg2rad($args[0])),
                'matrix' => Transform::matrix(
                    $args[0], $args[1], $args[2],
                    $args[3], $args[4], $args[5]
                ),
                default => Transform::identity(),
            };

            $result = $result->multiply($t);
        }

        return $result;
    }

    /**
     * @param list<string> $tokens
     */
    private static function consumeFlag(array &$tokens, int &$pos): bool
    {
        if (!isset($tokens[$pos])) {
            return false;
        }
        $token = $tokens[$pos];
        if (ctype_alpha($token)) {
            return false;
        }

        $str = (string)$token;
        $first = $str[0];

        if (($first === '0' || $first === '1') && strlen($str) > 1) {
            $tokens[$pos] = substr($str, 1);
            return $first === '1';
        }

        $pos++;
        if ($first === '0' || $first === '1') {
            return $first === '1';
        }
        return (float)$str !== 0.0;
    }

    private const SVG_NS = 'http://www.w3.org/2000/svg';

    private const PRESENTATION_PROPS = [
        'fill', 'stroke', 'stroke-width', 'stroke-dasharray', 'stroke-dashoffset',
        'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-opacity',
        'opacity', 'fill-opacity', 'fill-rule', 'stop-color', 'display',
    ];

    public static function parseString(string $svg, ?LoggerInterface $logger = null): SVGDocument
    {
        $xml = @simplexml_load_string($svg);
        if ($xml === false) {
            throw new \InvalidArgumentException('Failed to parse SVG XML');
        }

        $styles = self::collectStyles($xml);
        $defs = [];
        self::collectAllDefs($xml, $defs, $styles, $logger);
        $root = self::parseSvgElement($xml, $defs, $styles, $logger, Transform::identity());

        $viewBox = null;
        $vb = (string)($xml['viewBox'] ?? '');
        if ($vb !== '') {
            $parts = preg_split('/[\s,]+/', trim($vb)) ?: [];
            if (count($parts) === 4) {
                $viewBox = [(float)$parts[0], (float)$parts[1], (float)$parts[2], (float)$parts[3]];
            }
        }

        $width = isset($xml['width']) ? (float)$xml['width'] : null;
        $height = isset($xml['height']) ? (float)$xml['height'] : null;

        return new SVGDocument($root, $viewBox, $width, $height, $logger);
    }

    public static function readFile(string $path, ?LoggerInterface $logger = null): SVGDocument
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \InvalidArgumentException("Failed to read SVG file: {$path}");
        }
        return self::parseString($contents, $logger);
    }

    private static function detectNamespace(\SimpleXMLElement $el): string
    {
        foreach ($el->getDocNamespaces(false, false) as $prefix => $uri) {
            if ($prefix === '' && $uri === self::SVG_NS) {
                return $uri;
            }
        }
        $namespaces = $el->getNamespaces(false);
        return $namespaces[''] ?? '';
    }

    private static function svgChildren(\SimpleXMLElement $el): array
    {
        $result = [];
        foreach ($el->children() as $child) {
            $result[] = $child;
        }
        if (!empty($result)) {
            return $result;
        }
        $ns = self::detectNamespace($el);
        if ($ns !== '') {
            foreach ($el->children($ns, false) as $child) {
                $result[] = $child;
            }
        }
        return $result;
    }

    private static function parseElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger, Transform $parentTransform): SceneNode
    {
        $name = $el->getName();
        return match ($name) {
            'svg' => self::parseSvgElement($el, $defs, $styles, $logger, $parentTransform),
            'g' => self::parseGroupElement($el, $defs, $styles, $logger, $parentTransform),
            'path' => self::parsePathElement($el, $defs, $styles, $logger, $parentTransform),
            'rect' => self::parseRectElement($el, $defs, $styles, $logger, $parentTransform),
            'circle' => self::parseCircleElement($el, $defs, $styles, $logger, $parentTransform),
            'ellipse' => self::parseEllipseElement($el, $defs, $styles, $logger, $parentTransform),
            'line' => self::parseLineElement($el, $defs, $styles, $logger, $parentTransform),
            'polyline' => self::parsePolylineElement($el, $defs, $styles, $logger, $parentTransform),
            'polygon' => self::parsePolygonElement($el, $defs, $styles, $logger, $parentTransform),
            'defs' => self::parseDefsElement($el, $defs, $styles, $logger),
            'linearGradient' => self::handleGradientElement($el, $defs, $styles, $logger),
            'radialGradient' => self::handleGradientElement($el, $defs, $styles, $logger),
            'style' => new Group(),
            default => (function () use ($name, $logger) {
                $logger?->warning("Unsupported SVG element: <{$name}>");
                return new Group();
            })(),
        };
    }

    private static function parseSvgElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger, Transform $parentTransform): Group
    {
        $group = new Group();
        foreach (self::svgChildren($el) as $child) {
            $group->addChild(self::parseElement($child, $defs, $styles, $logger, $parentTransform));
        }
        return $group;
    }

    private static function parseGroupElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger, Transform $parentTransform): Group
    {
        $fill = self::parsePaintAttr($el, 'fill', $defs, $styles, $logger);
        $stroke = self::parseStrokeAttr($el, $defs, $styles, $logger);
        $transform = self::parseOptionalTransform($el, $styles);
        $opacity = self::parseFloatAttr($el, 'opacity', $styles);
        $fillOpacity = self::parseFloatAttr($el, 'fill-opacity', $styles);
        $fillRule = self::parseFillRuleAttr($el, $styles);

        $childTransform = $parentTransform;
        if ($transform !== null) {
            $childTransform = $parentTransform->multiply($transform);
        }

        $group = new Group(
            fill: $fill,
            stroke: $stroke,
            transform: $transform,
            opacity: $opacity,
            fillOpacity: $fillOpacity,
            fillRule: $fillRule,
        );

        foreach (self::svgChildren($el) as $child) {
            $group->addChild(self::parseElement($child, $defs, $styles, $logger, $childTransform));
        }

        return $group;
    }

    private static function parsePathElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger, Transform $parentTransform): SceneNode
    {
        $d = (string)($el['d'] ?? '');
        $path = self::parseDString($d);
        return self::buildShape($path, $el, $defs, $styles, $logger, $parentTransform);
    }

    private static function parseRectElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger, Transform $parentTransform): SceneNode
    {
        $x = (float)($el['x'] ?? 0);
        $y = (float)($el['y'] ?? 0);
        $w = (float)($el['width'] ?? 0);
        $h = (float)($el['height'] ?? 0);
        $rx = (float)($el['rx'] ?? 0);
        $ry = (float)($el['ry'] ?? 0);

        $path = Path::rect($x, $y, $w, $h, $rx, $ry);
        return self::buildShape($path, $el, $defs, $styles, $logger, $parentTransform);
    }

    private static function parseCircleElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger, Transform $parentTransform): SceneNode
    {
        $cx = (float)($el['cx'] ?? 0);
        $cy = (float)($el['cy'] ?? 0);
        $r = (float)($el['r'] ?? 0);

        $path = Path::circle($cx, $cy, $r);
        return self::buildShape($path, $el, $defs, $styles, $logger, $parentTransform);
    }

    private static function parseEllipseElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger, Transform $parentTransform): SceneNode
    {
        $cx = (float)($el['cx'] ?? 0);
        $cy = (float)($el['cy'] ?? 0);
        $rx = (float)($el['rx'] ?? 0);
        $ry = (float)($el['ry'] ?? 0);

        $path = Path::ellipse($cx, $cy, $rx, $ry);
        return self::buildShape($path, $el, $defs, $styles, $logger, $parentTransform);
    }

    private static function parseLineElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger, Transform $parentTransform): SceneNode
    {
        $x1 = (float)($el['x1'] ?? 0);
        $y1 = (float)($el['y1'] ?? 0);
        $x2 = (float)($el['x2'] ?? 0);
        $y2 = (float)($el['y2'] ?? 0);

        $path = Path::line($x1, $y1, $x2, $y2);
        return self::buildShape($path, $el, $defs, $styles, $logger, $parentTransform);
    }

    private static function parsePolylineElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger, Transform $parentTransform): SceneNode
    {
        $pointsStr = (string)($el['points'] ?? '');
        $points = self::parsePointsAttr($pointsStr);

        if (count($points) < 2) {
            $path = new Path();
        } else {
            $path = Path::polyline($points);
        }
        return self::buildShape($path, $el, $defs, $styles, $logger, $parentTransform);
    }

    private static function parsePolygonElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger, Transform $parentTransform): SceneNode
    {
        $pointsStr = (string)($el['points'] ?? '');
        $points = self::parsePointsAttr($pointsStr);

        if (count($points) < 2) {
            $path = new Path();
        } else {
            $path = Path::polygon($points);
        }
        return self::buildShape($path, $el, $defs, $styles, $logger, $parentTransform);
    }

    private static function collectAllDefs(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): void
    {
        foreach (self::svgChildren($el) as $child) {
            $name = $child->getName();
            if ($name === 'defs') {
                self::parseDefsElement($child, $defs, $styles, $logger);
            } elseif ($name === 'linearGradient' || $name === 'radialGradient') {
                self::parseGradientElement($child, $defs, $styles, $logger);
            } else {
                self::collectAllDefs($child, $defs, $styles, $logger);
            }
        }
    }

    private static function parseDefsElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): Group
    {
        $group = new Group();
        foreach (self::svgChildren($el) as $child) {
            $name = $child->getName();
            if ($name === 'linearGradient' || $name === 'radialGradient') {
                self::parseGradientElement($child, $defs, $styles, $logger);
            } else {
                $id = (string)($child['id'] ?? '');
                if ($id !== '') {
                    $defs[$id] = $child;
                }
            }
        }
        return $group;
    }

    private static function parseGradientElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): void
    {
        $id = (string)($el['id'] ?? '');
        if ($id === '') {
            return;
        }

        $stops = self::parseGradientStops($el, $styles);
        if (count($stops) < 2) {
            return;
        }

        $spread = match (strtolower((string)($el['spreadMethod'] ?? 'pad'))) {
            'reflect' => SpreadMethod::Reflect,
            'repeat' => SpreadMethod::Repeat,
            default => SpreadMethod::Pad,
        };

        $gradientUnits = match (strtolower((string)($el['gradientUnits'] ?? 'objectBoundingBox'))) {
            'userspaceonuse' => GradientUnits::UserSpaceOnUse,
            default => GradientUnits::ObjectBoundingBox,
        };

        $gradientTransform = null;
        $gtStr = (string)($el['gradientTransform'] ?? '');
        if ($gtStr !== '') {
            $gradientTransform = self::parseTransform($gtStr);
        }

        $name = $el->getName();
        if ($name === 'linearGradient') {
            $x1 = self::parseGradientCoord($el, 'x1', 0.0);
            $y1 = self::parseGradientCoord($el, 'y1', 0.0);
            $x2 = self::parseGradientCoord($el, 'x2', 1.0);
            $y2 = self::parseGradientCoord($el, 'y2', 0.0);
            $defs[$id] = [
                'gradient' => new LinearGradient($x1, $y1, $x2, $y2, $stops, $spread),
                'units' => $gradientUnits,
                'transform' => $gradientTransform,
            ];
        } elseif ($name === 'radialGradient') {
            $cx = self::parseGradientCoord($el, 'cx', 0.5);
            $cy = self::parseGradientCoord($el, 'cy', 0.5);
            $r = self::parseGradientCoord($el, 'r', 0.5);
            $fx = self::parseOptionalGradientCoord($el, 'fx');
            $fy = self::parseOptionalGradientCoord($el, 'fy');
            $defs[$id] = [
                'gradient' => new RadialGradient($cx, $cy, $r, $stops, $fx, $fy, $spread),
                'units' => $gradientUnits,
                'transform' => $gradientTransform,
            ];
        }
    }

    private static function handleGradientElement(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): Group
    {
        self::parseGradientElement($el, $defs, $styles, $logger);
        return new Group();
    }

    private static function parseGradientStops(\SimpleXMLElement $el, array $styles): array
    {
        $stops = [];
        foreach (self::svgChildren($el) as $child) {
            if ($child->getName() !== 'stop') {
                continue;
            }
            $offsetStr = (string)($child['offset'] ?? '0');
            $offset = self::parsePercentageOrFloat($offsetStr);
            $colorStr = self::getEffectiveAttr($child, 'stop-color', $styles);
            if ($colorStr === '') {
                $colorStr = 'black';
            }
            $rgb = SvgColor::parse($colorStr);
            if ($rgb === null) {
                $rgb = [0, 0, 0];
            }
            $stops[] = new ColorStop($offset, $rgb[0], $rgb[1], $rgb[2]);
        }
        usort($stops, fn(ColorStop $a, ColorStop $b) => $a->offset <=> $b->offset);
        return $stops;
    }

    private static function parsePercentageOrFloat(string $val): float
    {
        $val = trim($val);
        if (str_ends_with($val, '%')) {
            return (float)substr($val, 0, -1) / 100.0;
        }
        return (float)$val;
    }

    private static function parseGradientCoord(\SimpleXMLElement $el, string $attr, float $default): float
    {
        $val = (string)($el[$attr] ?? '');
        if ($val === '') {
            return $default;
        }
        return self::parsePercentageOrFloat($val);
    }

    private static function parseOptionalGradientCoord(\SimpleXMLElement $el, string $attr): ?float
    {
        $val = (string)($el[$attr] ?? '');
        if ($val === '') {
            return null;
        }
        return self::parsePercentageOrFloat($val);
    }

    private static function buildShape(Path $path, \SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger, Transform $parentTransform): SceneNode
    {
        $display = self::getEffectiveAttr($el, 'display', $styles);
        if ($display === 'none') {
            return new Group();
        }

        $fill = self::resolveGradientPaint($el, 'fill', $defs, $styles, $path, $parentTransform, $logger);
        $stroke = self::parseStrokeAttr($el, $defs, $styles, $logger);
        $transform = self::parseOptionalTransform($el, $styles);
        $opacity = self::parseFloatAttr($el, 'opacity', $styles);
        $fillOpacity = self::parseFloatAttr($el, 'fill-opacity', $styles);
        $fillRule = self::parseFillRuleAttr($el, $styles);

        return new Shape(
            path: $path,
            fill: $fill,
            stroke: $stroke,
            transform: $transform,
            opacity: $opacity,
            fillOpacity: $fillOpacity,
            fillRule: $fillRule,
        );
    }

    private static function parsePaintAttr(\SimpleXMLElement $el, string $attr, array &$defs, array $styles, ?LoggerInterface $logger): ?Paint
    {
        $val = self::getEffectiveAttr($el, $attr, $styles);
        if ($val === 'none') {
            return new NoPaint();
        }
        if ($val === '') {
            if ($attr === 'fill') {
                $rgb = [0, 0, 0];
                $code = IrcPalette::nearestColor($rgb[0], $rgb[1], $rgb[2]);
                return new Color($code, null);
            }
            return null;
        }

        if (preg_match('/^url\(#(.+)\)$/', $val, $m)) {
            $id = $m[1];
            if (isset($defs[$id])) {
                $entry = $defs[$id];
                if ($entry instanceof Paint) {
                    return $entry;
                }
                return $entry['gradient'];
            }
            $logger?->warning("SVG reference not found: #{$id}");
            return new NoPaint();
        }

        $rgb = SvgColor::parse($val);
        if ($rgb === null) {
            return null;
        }

        $code = IrcPalette::nearestColor($rgb[0], $rgb[1], $rgb[2]);
        return new Color($code, null);
    }

    private static function resolveGradientPaint(
        \SimpleXMLElement $el,
        string $attr,
        array &$defs,
        array $styles,
        ?Path $path,
        Transform $parentTransform,
        ?LoggerInterface $logger,
    ): ?Paint {
        $val = self::getEffectiveAttr($el, $attr, $styles);
        if ($val === 'none') {
            return new NoPaint();
        }
        if ($val === '') {
            if ($attr === 'fill') {
                $rgb = [0, 0, 0];
                $code = IrcPalette::nearestColor($rgb[0], $rgb[1], $rgb[2]);
                return new Color($code, null);
            }
            return null;
        }

        if (preg_match('/^url\(#(.+)\)$/', $val, $m)) {
            $id = $m[1];
            if (!isset($defs[$id])) {
                $logger?->warning("SVG reference not found: #{$id}");
                return new NoPaint();
            }
            $entry = $defs[$id];
            if ($entry instanceof Paint) {
                return $entry;
            }
            $gradient = $entry['gradient'];
            $units = $entry['units'];
            $gradientTransform = $entry['transform'];

            if ($units === GradientUnits::ObjectBoundingBox && $path !== null) {
                $bbox = $path->getBBox();
                if ($bbox !== null && ($bbox['w'] > 0 || $bbox['h'] > 0)) {
                    $bboxTransform = Transform::translate($bbox['x'], $bbox['y'])
                        ->multiply(Transform::scale($bbox['w'], $bbox['h']));
                    $sampleTransform = $gradientTransform !== null
                        ? $bboxTransform->multiply($gradientTransform)
                        : $bboxTransform;
                    return $gradient->withSampleTransform($sampleTransform);
                }
            }

            if ($gradientTransform !== null) {
                $sampleTransform = $parentTransform->multiply($gradientTransform);
                return $gradient->withSampleTransform($sampleTransform);
            }
            if (!$parentTransform->equals(Transform::identity())) {
                return $gradient->withSampleTransform($parentTransform);
            }
            return $gradient;
        }

        $rgb = SvgColor::parse($val);
        if ($rgb === null) {
            return null;
        }
        $code = IrcPalette::nearestColor($rgb[0], $rgb[1], $rgb[2]);
        return new Color($code, null);
    }

    private static function parseStrokeAttr(\SimpleXMLElement $el, array &$defs, array $styles, ?LoggerInterface $logger): ?StrokeStyle
    {
        $strokeVal = self::getEffectiveAttr($el, 'stroke', $styles);
        if ($strokeVal === '' || $strokeVal === 'none') {
            return null;
        }

        $paint = null;
        if (preg_match('/^url\(#(.+)\)$/', $strokeVal, $m)) {
            $id = $m[1];
            if (isset($defs[$id])) {
                $entry = $defs[$id];
                $paint = ($entry instanceof Paint) ? $entry : $entry['gradient'];
            } else {
                $logger?->warning("SVG stroke reference not found: #{$id}");
                return null;
            }
        } else {
            $rgb = SvgColor::parse($strokeVal);
            if ($rgb === null) {
                return null;
            }
            $code = IrcPalette::nearestColor($rgb[0], $rgb[1], $rgb[2]);
            $paint = new Color($code, null);
        }

        $width = (float)(self::getEffectiveAttr($el, 'stroke-width', $styles) ?: '1.0');
        if ($width < 0) {
            $width = 1.0;
        }

        $dashArray = null;
        $dashStr = self::getEffectiveAttr($el, 'stroke-dasharray', $styles);
        if ($dashStr !== '' && $dashStr !== 'none') {
            $dashArray = array_map('floatval', preg_split('/[\s,]+/', trim($dashStr)));
            $dashArray = array_filter($dashArray, fn($v) => $v > 0);
            if (empty($dashArray)) {
                $dashArray = null;
            }
        }

        $lineCap = match (self::getEffectiveAttr($el, 'stroke-linecap', $styles) ?: 'butt') {
            'round' => LineCap::Round,
            'square' => LineCap::Square,
            default => LineCap::Butt,
        };

        $lineJoin = match (self::getEffectiveAttr($el, 'stroke-linejoin', $styles) ?: 'miter') {
            'round' => LineJoin::Round,
            'bevel' => LineJoin::Bevel,
            default => LineJoin::Miter,
        };

        $miterLimit = (float)(self::getEffectiveAttr($el, 'stroke-miterlimit', $styles) ?: '4.0');
        $strokeOpacity = self::parseFloatAttr($el, 'stroke-opacity', $styles) ?? 1.0;

        return new StrokeStyle(
            paint: $paint,
            width: $width,
            dashArray: $dashArray === null ? null : array_values($dashArray),
            dashOffset: (float)(self::getEffectiveAttr($el, 'stroke-dashoffset', $styles) ?: '0.0'),
            lineCap: $lineCap,
            lineJoin: $lineJoin,
            miterLimit: $miterLimit,
            opacity: $strokeOpacity,
        );
    }

    private static function parseOptionalTransform(\SimpleXMLElement $el, array $styles): ?Transform
    {
        $val = self::getEffectiveAttr($el, 'transform', $styles);
        if ($val === '') {
            return null;
        }
        return self::parseTransform($val);
    }

    private static function getEffectiveAttr(\SimpleXMLElement $el, string $attr, array $styles): string
    {
        $styleStr = (string)($el['style'] ?? '');
        if ($styleStr !== '') {
            $val = self::parseStyleProperty($styleStr, $attr);
            if ($val !== '') {
                return $val;
            }
        }

        if (!empty($styles)) {
            $tag = $el->getName();
            $class = (string)($el['class'] ?? '');
            $id = (string)($el['id'] ?? '');
            $cssProps = self::matchStyles($styles, $tag, $class, $id);
            if (isset($cssProps[$attr]) && $cssProps[$attr] !== '') {
                return $cssProps[$attr];
            }
        }

        return (string)($el[$attr] ?? '');
    }

    private static function parseStyleProperty(string $style, string $property): string
    {
        $declarations = preg_split('/\s*;\s*/', $style, -1, PREG_SPLIT_NO_EMPTY);
        $value = '';
        foreach ($declarations as $decl) {
            $parts = explode(':', $decl, 2);
            if (count($parts) === 2 && trim($parts[0]) === $property) {
                $value = trim($parts[1]);
            }
        }
        return $value;
    }

    private static function parseFloatAttr(\SimpleXMLElement $el, string $attr, array $styles): ?float
    {
        $val = self::getEffectiveAttr($el, $attr, $styles);
        if ($val === '') {
            return null;
        }
        return (float)$val;
    }

    private static function parseFillRuleAttr(\SimpleXMLElement $el, array $styles): ?FillRule
    {
        $val = self::getEffectiveAttr($el, 'fill-rule', $styles);
        if ($val === '') {
            return null;
        }
        return match ($val) {
            'evenodd' => FillRule::EvenOdd,
            'nonzero' => FillRule::NonZero,
            default => null,
        };
    }

    private static function collectStyles(\SimpleXMLElement $el): array
    {
        $styles = [];
        $name = $el->getName();
        if ($name === 'style') {
            $text = trim((string)$el);
            if ($text !== '') {
                $styles = self::parseStyleBlock($text);
            }
            return $styles;
        }
        foreach (self::svgChildren($el) as $child) {
            $styles = array_merge($styles, self::collectStyles($child));
        }
        return $styles;
    }

    private static function parseStyleBlock(string $css): array
    {
        $css = preg_replace('/\/\*.*?\*\//s', '', $css);
        if ($css === null || trim($css) === '') {
            return [];
        }

        $rules = [];
        preg_match_all('/([^{]+)\{([^}]*)\}/s', $css, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $selectorList = trim($match[1]);
            $declStr = trim($match[2]);

            if ($selectorList === '' || $declStr === '') {
                continue;
            }

            $props = [];
            $decls = preg_split('/\s*;\s*/', $declStr, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($decls as $decl) {
                $parts = explode(':', $decl, 2);
                if (count($parts) === 2) {
                    $prop = trim($parts[0]);
                    $val = trim($parts[1]);
                    if (in_array($prop, self::PRESENTATION_PROPS, true)) {
                        $props[$prop] = $val;
                    }
                }
            }

            if (empty($props)) {
                continue;
            }

            $selectors = preg_split('/\s*,\s*/', $selectorList);
            foreach ($selectors as $sel) {
                $sel = trim($sel);
                if ($sel === '') {
                    continue;
                }

                $specificity = match (true) {
                    str_starts_with($sel, '#') => 300,
                    str_starts_with($sel, '.') => 200,
                    $sel === '*' => 0,
                    default => 100,
                };

                $rules[] = [
                    'selector' => $sel,
                    'specificity' => $specificity,
                    'props' => $props,
                ];
            }
        }

        return $rules;
    }

    private static function matchStyles(array $styles, string $tag, string $class, string $id): array
    {
        $classes = preg_split('/\s+/', trim($class), -1, PREG_SPLIT_NO_EMPTY);
        $matched = [];

        foreach ($styles as $order => $rule) {
            $sel = $rule['selector'];
            $matches = false;

            if (str_starts_with($sel, '#')) {
                $matches = ($sel === '#' . $id);
            } elseif (str_starts_with($sel, '.')) {
                $matches = in_array(substr($sel, 1), $classes, true);
            } elseif ($sel === '*') {
                $matches = true;
            } else {
                $matches = (strtolower($sel) === strtolower($tag));
            }

            if ($matches) {
                $matched[] = ['props' => $rule['props'], 'specificity' => $rule['specificity'], 'order' => $order];
            }
        }

        usort($matched, fn($a, $b) => [$a['specificity'], $a['order']] <=> [$b['specificity'], $b['order']]);

        $props = [];
        foreach ($matched as $rule) {
            foreach ($rule['props'] as $prop => $val) {
                $props[$prop] = $val;
            }
        }

        return $props;
    }

    /**
     * @return array<int, array{float, float}>
     */
    private static function parsePointsAttr(string $s): array
    {
        $s = trim($s);
        if ($s === '') {
            return [];
        }
        $nums = preg_split('/[\s,]+/', $s);
        $points = [];
        for ($i = 0; $i + 1 < count($nums); $i += 2) {
            $points[] = [(float)$nums[$i], (float)$nums[$i + 1]];
        }
        return $points;
    }
}
