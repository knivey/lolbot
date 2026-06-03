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

    public static function parseString(string $svg, ?LoggerInterface $logger = null): SVGDocument
    {
        $xml = @simplexml_load_string($svg);
        if ($xml === false) {
            throw new \InvalidArgumentException('Failed to parse SVG XML');
        }

        $defs = [];
        $root = self::parseSvgElement($xml, $defs, $logger);

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

    private static function parseElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): SceneNode
    {
        $name = $el->getName();
        return match ($name) {
            'svg' => self::parseSvgElement($el, $defs, $logger),
            'g' => self::parseGroupElement($el, $defs, $logger),
            'path' => self::parsePathElement($el, $defs, $logger),
            'rect' => self::parseRectElement($el, $defs, $logger),
            'circle' => self::parseCircleElement($el, $defs, $logger),
            'ellipse' => self::parseEllipseElement($el, $defs, $logger),
            'line' => self::parseLineElement($el, $defs, $logger),
            'polyline' => self::parsePolylineElement($el, $defs, $logger),
            'polygon' => self::parsePolygonElement($el, $defs, $logger),
            'defs' => self::parseDefsElement($el, $defs, $logger),
            default => new Group(),
        };
    }

    private static function parseSvgElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Group
    {
        $group = new Group();
        foreach ($el->children() as $child) {
            $group->addChild(self::parseElement($child, $defs, $logger));
        }
        return $group;
    }

    private static function parseGroupElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Group
    {
        $fill = self::parsePaintAttr($el, 'fill', $defs, $logger);
        $stroke = self::parseStrokeAttr($el, $defs, $logger);
        $transform = self::parseOptionalTransform($el);
        $opacity = self::parseFloatAttr($el, 'opacity');
        $fillOpacity = self::parseFloatAttr($el, 'fill-opacity');
        $fillRule = self::parseFillRuleAttr($el);

        $group = new Group(
            fill: $fill,
            stroke: $stroke,
            transform: $transform,
            opacity: $opacity,
            fillOpacity: $fillOpacity,
            fillRule: $fillRule,
        );

        foreach ($el->children() as $child) {
            $group->addChild(self::parseElement($child, $defs, $logger));
        }

        return $group;
    }

    private static function parsePathElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Shape
    {
        $d = (string)($el['d'] ?? '');
        $path = self::parseDString($d);
        return self::buildShape($path, $el, $defs, $logger);
    }

    private static function parseRectElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Shape
    {
        $x = (float)($el['x'] ?? 0);
        $y = (float)($el['y'] ?? 0);
        $w = (float)($el['width'] ?? 0);
        $h = (float)($el['height'] ?? 0);
        $rx = (float)($el['rx'] ?? 0);
        $ry = (float)($el['ry'] ?? 0);

        $path = Path::rect($x, $y, $w, $h, $rx, $ry);
        return self::buildShape($path, $el, $defs, $logger);
    }

    private static function parseCircleElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Shape
    {
        $cx = (float)($el['cx'] ?? 0);
        $cy = (float)($el['cy'] ?? 0);
        $r = (float)($el['r'] ?? 0);

        $path = Path::circle($cx, $cy, $r);
        return self::buildShape($path, $el, $defs, $logger);
    }

    private static function parseEllipseElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Shape
    {
        $cx = (float)($el['cx'] ?? 0);
        $cy = (float)($el['cy'] ?? 0);
        $rx = (float)($el['rx'] ?? 0);
        $ry = (float)($el['ry'] ?? 0);

        $path = Path::ellipse($cx, $cy, $rx, $ry);
        return self::buildShape($path, $el, $defs, $logger);
    }

    private static function parseLineElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Shape
    {
        $x1 = (float)($el['x1'] ?? 0);
        $y1 = (float)($el['y1'] ?? 0);
        $x2 = (float)($el['x2'] ?? 0);
        $y2 = (float)($el['y2'] ?? 0);

        $path = Path::line($x1, $y1, $x2, $y2);
        return self::buildShape($path, $el, $defs, $logger);
    }

    private static function parsePolylineElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Shape
    {
        $pointsStr = (string)($el['points'] ?? '');
        $points = self::parsePointsAttr($pointsStr);

        if (count($points) < 2) {
            $path = new Path();
        } else {
            $path = Path::polyline($points);
        }
        return self::buildShape($path, $el, $defs, $logger);
    }

    private static function parsePolygonElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Shape
    {
        $pointsStr = (string)($el['points'] ?? '');
        $points = self::parsePointsAttr($pointsStr);

        if (count($points) < 2) {
            $path = new Path();
        } else {
            $path = Path::polygon($points);
        }
        return self::buildShape($path, $el, $defs, $logger);
    }

    private static function parseDefsElement(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Group
    {
        $group = new Group();
        foreach ($el->children() as $child) {
            $id = (string)($child['id'] ?? '');
            if ($id !== '') {
                $defs[$id] = $child;
            }
        }
        return $group;
    }

    private static function buildShape(Path $path, \SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): Shape
    {
        $fill = self::parsePaintAttr($el, 'fill', $defs, $logger);
        $stroke = self::parseStrokeAttr($el, $defs, $logger);
        $transform = self::parseOptionalTransform($el);
        $opacity = self::parseFloatAttr($el, 'opacity');
        $fillOpacity = self::parseFloatAttr($el, 'fill-opacity');
        $fillRule = self::parseFillRuleAttr($el);

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

    private static function parsePaintAttr(\SimpleXMLElement $el, string $attr, array &$defs, ?LoggerInterface $logger): ?Paint
    {
        $val = (string)($el[$attr] ?? '');
        if ($val === '' || $val === 'none') {
            return null;
        }

        if (preg_match('/^url\(#(.+)\)$/', $val, $m)) {
            $id = $m[1];
            if (isset($defs[$id])) {
                return $defs[$id];
            }
            $logger?->warning("SVG reference not found: #{$id}");
            return null;
        }

        $rgb = SvgColor::parse($val);
        if ($rgb === null) {
            return null;
        }

        $code = IrcPalette::nearestColor($rgb[0], $rgb[1], $rgb[2]);
        return new Color($code, null);
    }

    private static function parseStrokeAttr(\SimpleXMLElement $el, array &$defs, ?LoggerInterface $logger): ?StrokeStyle
    {
        $strokeVal = (string)($el['stroke'] ?? '');
        if ($strokeVal === '' || $strokeVal === 'none') {
            return null;
        }

        $paint = null;
        if (preg_match('/^url\(#(.+)\)$/', $strokeVal, $m)) {
            $id = $m[1];
            if (isset($defs[$id])) {
                $paint = $defs[$id];
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

        $width = (float)($el['stroke-width'] ?? 1.0);
        if ($width < 0) {
            $width = 1.0;
        }

        $dashArray = null;
        $dashStr = (string)($el['stroke-dasharray'] ?? '');
        if ($dashStr !== '' && $dashStr !== 'none') {
            $dashArray = array_map('floatval', preg_split('/[\s,]+/', trim($dashStr)));
            $dashArray = array_filter($dashArray, fn($v) => $v > 0);
            if (empty($dashArray)) {
                $dashArray = null;
            }
        }

        $lineCap = match ((string)($el['stroke-linecap'] ?? 'butt')) {
            'round' => LineCap::Round,
            'square' => LineCap::Square,
            default => LineCap::Butt,
        };

        $lineJoin = match ((string)($el['stroke-linejoin'] ?? 'miter')) {
            'round' => LineJoin::Round,
            'bevel' => LineJoin::Bevel,
            default => LineJoin::Miter,
        };

        $miterLimit = (float)($el['stroke-miterlimit'] ?? 4.0);
        $strokeOpacity = self::parseFloatAttr($el, 'stroke-opacity') ?? 1.0;

        return new StrokeStyle(
            paint: $paint,
            width: $width,
            dashArray: $dashArray === null ? null : array_values($dashArray),
            dashOffset: (float)($el['stroke-dashoffset'] ?? 0.0),
            lineCap: $lineCap,
            lineJoin: $lineJoin,
            miterLimit: $miterLimit,
            opacity: $strokeOpacity,
        );
    }

    private static function parseOptionalTransform(\SimpleXMLElement $el): ?Transform
    {
        $val = (string)($el['transform'] ?? '');
        if ($val === '') {
            return null;
        }
        return self::parseTransform($val);
    }

    private static function parseFloatAttr(\SimpleXMLElement $el, string $attr): ?float
    {
        $val = (string)($el[$attr] ?? '');
        if ($val === '') {
            return null;
        }
        return (float)$val;
    }

    private static function parseFillRuleAttr(\SimpleXMLElement $el): ?FillRule
    {
        $val = (string)($el['fill-rule'] ?? '');
        if ($val === '') {
            return null;
        }
        return match ($val) {
            'evenodd' => FillRule::EvenOdd,
            'nonzero' => FillRule::NonZero,
            default => null,
        };
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
