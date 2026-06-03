<?php

namespace draw;

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
}
