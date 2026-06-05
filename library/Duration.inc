<?php

/**
 * Many of the methods were created by Aidan Lister but I have modified
 * them quite a bit to suit our needs. - knivey
 */

/**
 * A class for making time periods readable.
 *
 * This class allows for the conversion of an integer
 * number of seconds into a readable string.
 * For example, '121' into '2 minutes, 1 second'.
 * 
 * If an array is passed to the class, the associative
 * keys are used for the names of the time segments.
 * For example, array('seconds' => 12, 'minutes' => 1)
 * into '1 minute, 12 seconds'.
 *
 * This class is plural aware. Time segments with values
 * other than 1 will have an 's' appended.
 * For example, '1 second' not '1 seconds'.
 *
 * @author      Aidan Lister <aidan@php.net>
 * @version     1.2.1
 * @link        http://aidanlister.com/repos/v/Duration.php
 */


/**
 * All in one method
 *
 * @param   int|array  $duration  Array of time segments or a number of seconds
 * @return  string
 */
/**
 * @param array<string, int|float>|int $duration
 * @param array<string, int|float>|null $periods
 */
function Duration_toString(array|int $duration, ?array $periods = null): string {
    if (!is_array($duration)) {
        $duration = Duration_int2array($duration, $periods);
    }

    return Duration_array2string($duration);
}

//After tons of calculations I've decided on these numbers -knivey
$Duration_periods = array(
		'y' => 31533336,
		'M' => 2627778,
		'w' => 604800,
		'd' => 86400,
		'h' => 3600,
		'm' => 60,
		's' => 1
);

/**
 * Takes a string duration like srvx ex(1h30m) and turns it into int seconds
 * @param string $duration
 * @return int|string
 * @author knivey
 */
function string2Seconds(string $duration): int|string {
	global $Duration_periods;
    $string = trim($duration);
    $string = str_split($string);
    $num = 0;
    $total = 0;
    foreach ($string as $chunk) {
        if (is_numeric($chunk)) {
            $num .= $chunk;
        } else {
            if(array_key_exists($chunk, $Duration_periods)) {
                if($num == 0) {
                    return "No value given for time identifier $chunk";
                }
            	$total += $num * $Duration_periods[$chunk];
            } else {
            	return "$chunk is an unknown time identifier";
            }
            if($total > PHP_INT_MAX) {
                return "$duration is too great";
            }
            if(!is_int($total)) {
                return "$duration not understood";
            }
            $num = 0;
        }
    }
    return $total;
}

/**
 * Return an array of date segments.
 *
 * @param        int $seconds Number of seconds to be parsed
 * @return       mixed An array containing named segments
 */
/**
 * @param int|float $seconds
 * @param array<string, int|float>|null $periods
 * @return array<string, int|float>|null
 */
function Duration_int2array(int|float $seconds, ?array $periods = null): ?array {
	global $Duration_periods;
    // Define time periods
    if (!is_array($periods)) {
        $periods = $Duration_periods;
    }

    // Loop
    $seconds = (float) $seconds;
    foreach ($periods as $period => $value) {
        $count = floor($seconds / $value);

        if ($count == 0) {
            continue;
        }

        $values[$period] = $count;
        $seconds = $seconds % $value;
    }

    // Return
    if (empty($values)) {
        $values = null;
    }

    return $values;
}

/**
 * Return a string of time periods.
 *
 * @package      Duration
 * @param        mixed $duration An array of named segments
 * @return       string
 */
/**
 * @param array<string, int|float> $duration
 */
function Duration_array2string(array $duration, bool $nopad = false): string {
    $array = [];
    foreach ($duration as $key => $value) {
        //$segment_name = substr($key, 0, -1);
        //$segment = $value . ' ' . $segment_name; 
        $segment = $value . $key;
        // Plural
        //if ($value != 1) {
        //    $segment .= 's';
        //}

        $array[] = $segment;
    }

    if($nopad) {
        return implode('', $array);
    }
    return implode(', ', $array);

}

class ParseResult
{
    public function __construct(
        public readonly int $seconds,
        public readonly string $remainder,
        public readonly ?int $targetTime = null,
    ) {}
}

function parseDuration(string $input, ?string $tzName = null): ?ParseResult
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }

    $remainder = '';
    $seconds = parseDurationRegex($input, $remainder);
    if ($seconds !== null) {
        return new ParseResult($seconds, trim($remainder));
    }

    $result = parseDurationDate($input, $tzName);
    if ($result !== null) {
        return $result;
    }

    return null;
}

function parseDurationRegex(string $input, string &$remainder = ''): ?int
{
    global $Duration_periods;

    $wordUnits = [
        'years'   => $Duration_periods['y'],
        'year'    => $Duration_periods['y'],
        'yrs'     => $Duration_periods['y'],
        'yr'      => $Duration_periods['y'],
        'months'  => $Duration_periods['M'],
        'month'   => $Duration_periods['M'],
        'weeks'   => $Duration_periods['w'],
        'week'    => $Duration_periods['w'],
        'wks'     => $Duration_periods['w'],
        'wk'      => $Duration_periods['w'],
        'days'    => $Duration_periods['d'],
        'day'     => $Duration_periods['d'],
        'hours'   => $Duration_periods['h'],
        'hour'    => $Duration_periods['h'],
        'hrs'     => $Duration_periods['h'],
        'hr'      => $Duration_periods['h'],
        'minutes' => $Duration_periods['m'],
        'minute'  => $Duration_periods['m'],
        'mins'    => $Duration_periods['m'],
        'min'     => $Duration_periods['m'],
        'seconds' => $Duration_periods['s'],
        'second'  => $Duration_periods['s'],
        'secs'    => $Duration_periods['s'],
        'sec'     => $Duration_periods['s'],
    ];

    $singleLetter = implode('', array_keys($Duration_periods));
    $wordAlternation = implode('|', array_keys($wordUnits));

    $pattern = '/^([\d.]+\s*[' . preg_quote($singleLetter, '/') . '](?![a-zA-Z])\s*|([\d.]+)\s*(' . $wordAlternation . ')\s*)+/i';

    if (!preg_match($pattern, $input, $matches, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $matched = $matches[0][0];
    $matchEnd = $matches[0][1] + strlen($matched);
    $remainder = substr($input, $matchEnd);

    $total = 0;
    $tokenPattern = '/([\d.]+)\s*([' . preg_quote($singleLetter, '/') . '])(?![a-zA-Z])|([\d.]+)\s*(' . $wordAlternation . ')/i';
    if (!preg_match_all($tokenPattern, $matched, $tokens, PREG_SET_ORDER)) {
        return null;
    }

    foreach ($tokens as $token) {
        if (!empty($token[2])) {
            $num = (float) $token[1];
            $unit = $token[2];
            if (!isset($Duration_periods[$unit])) {
                $unitLower = strtolower($unit);
                if (!isset($Duration_periods[$unitLower])) {
                    return null;
                }
                $unit = $unitLower;
            }
            $total += $num * $Duration_periods[$unit];
        } elseif (!empty($token[4])) {
            $num = (float) $token[3];
            $unit = strtolower($token[4]);
            if (!isset($wordUnits[$unit])) {
                return null;
            }
            $total += $num * $wordUnits[$unit];
        }
    }

    if ($total <= 0) {
        return null;
    }

    if ($total > PHP_INT_MAX) {
        return null;
    }

    return (int) $total;
}

function parseDurationDate(string $input, ?string $tzName = null): ?ParseResult
{
    $dayNames = [
        'monday'    => 1, 'mon'  => 1,
        'tuesday'   => 2, 'tues' => 2, 'tue' => 2,
        'wednesday' => 3, 'wed'  => 3,
        'thursday'  => 4, 'thurs'=> 4, 'thu' => 4,
        'friday'    => 5, 'fri'  => 5,
        'saturday'  => 6, 'sat'  => 6,
        'sunday'    => 7, 'sun'  => 7,
    ];
    $monthNames = [
        'january'  => 1,  'jan' => 1,
        'february' => 2,  'feb' => 2,
        'march'    => 3,  'mar' => 3,
        'april'    => 4,  'apr' => 4,
        'may'      => 5,
        'june'     => 6,  'jun' => 6,
        'july'     => 7,  'jul' => 7,
        'august'   => 8,  'aug' => 8,
        'september'=> 9,  'sep' => 9,  'sept' => 9,
        'october'  => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11,
        'december' => 12, 'dec' => 12,
    ];
    $ordinals = [
        'first'  => 1, '1st' => 1,
        'second' => 2, '2nd' => 2,
        'third'  => 3, '3rd' => 3,
        'fourth' => 4, '4th' => 4,
        'fifth'  => 5, '5th' => 5,
    ];

    $dayPattern = implode('|', array_keys($dayNames));
    $monthPattern = implode('|', array_keys($monthNames));
    $ordinalPattern = implode('|', array_keys($ordinals));
    $timePattern = '(\d{1,2})(?::(\d{2}))?(?::(\d{2}))?\s*(am|pm)';
    $timeGroup = '(?:(?:at\s+)?' . $timePattern . ')?';

    $patterns = [
        '/^(today)\s*' . $timeGroup . '\s*(.*)$/i',
        '/^(tomorrow)\s*' . $timeGroup . '\s*(.*)$/i',
        '/^(?:next|this)\s+(' . $dayPattern . ')\b\s*' . $timeGroup . '\s*(.*)$/i',
        '/^(?:next|this)\s+(week|month|year|yrs?|mo|wks?)\b\s*' . $timeGroup . '\s*(.*)$/i',
        '/^(' . $ordinalPattern . ')\s+week\s+of\s+(' . $monthPattern . ')\b\s*' . $timeGroup . '\s*(.*)$/i',
        '/^(' . $monthPattern . ')\s+(\d{1,2})(?:st|nd|rd|th)?\s*' . $timeGroup . '\s*(.*)$/i',
        '/^(' . $dayPattern . ')\b\s*' . $timeGroup . '\s*(.*)$/i',
        '/^' . $timePattern . '\s+(?:next\s+|this\s+)?(' . $dayPattern . ')\b\s*(.*)$/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $input, $m)) {
            $result = parseDurationDateMatch($m, $dayNames, $monthNames, $ordinals, $tzName);
            if ($result !== null) {
                return $result;
            }
        }
    }

    return null;
}

function parseDurationDateMatch(
    array $m,
    array $dayNames,
    array $monthNames,
    array $ordinals,
    ?string $tzName = null,
): ?ParseResult {
    $now = time();

    if ($tzName !== null) {
        $tz = new \DateTimeZone($tzName);
        $nowDt = new \DateTime('now', $tz);
        $baseDt = new \DateTime('today', $tz);
    } else {
        $baseTimestamp = strtotime('today');
    }

    if (strtolower($m[1]) === 'today') {
        $timeOffset = 2;
    } elseif (strtolower($m[1]) === 'tomorrow') {
        if ($tzName !== null) {
            $baseDt->modify('+1 day');
        } else {
            $baseTimestamp += 86400;
        }
        $timeOffset = 2;
    } elseif (isset($dayNames[strtolower($m[1])])) {
        $target = $dayNames[strtolower($m[1])];
        if ($tzName !== null) {
            $today = (int) $nowDt->format('N');
        } else {
            $today = (int) date('N');
        }
        $diff = $target - $today;
        if ($diff <= 0) {
            $diff += 7;
        }
        if ($tzName !== null) {
            $baseDt->modify("+{$diff} days");
        } else {
            $baseTimestamp += $diff * 86400;
        }
        $timeOffset = 2;
    } elseif (in_array(strtolower($m[1]), ['week', 'month', 'year', 'yr', 'yrs', 'mo', 'wk', 'wks'])) {
        $unit = strtolower($m[1]);
        if ($tzName !== null) {
            switch ($unit) {
                case 'week':
                case 'wk':
                case 'wks':
                    $today = (int) $nowDt->format('N');
                    $daysUntilMon = (8 - $today) % 7;
                    if ($daysUntilMon === 0) {
                        $daysUntilMon = 7;
                    }
                    $baseDt->modify("+{$daysUntilMon} days");
                    break;
                case 'month':
                case 'mo':
                    $baseDt->modify('+1 month');
                    break;
                case 'year':
                case 'yr':
                case 'yrs':
                    $baseDt->modify('+1 year');
                    break;
                default:
                    return null;
            }
        } else {
            switch ($unit) {
                case 'week':
                case 'wk':
                case 'wks':
                    $today = (int) date('N');
                    $daysUntilMon = (8 - $today) % 7;
                    if ($daysUntilMon === 0) {
                        $daysUntilMon = 7;
                    }
                    $baseTimestamp += $daysUntilMon * 86400;
                    break;
                case 'month':
                case 'mo':
                    $baseTimestamp = strtotime('+1 month', $baseTimestamp);
                    break;
                case 'year':
                case 'yr':
                case 'yrs':
                    $baseTimestamp = strtotime('+1 year', $baseTimestamp);
                    break;
                default:
                    return null;
            }
        }
        $timeOffset = 2;
    } elseif (isset($ordinals[strtolower($m[1])])) {
        $n = $ordinals[strtolower($m[1])];
        $month = $monthNames[strtolower($m[2])];
        if ($tzName !== null) {
            $year = (int) $nowDt->format('Y');
        } else {
            $year = (int) date('Y');
        }
        $ts = parseDurationDateNthMonday($n, $month, $year);
        if ((int) date('n', $ts) !== $month || $ts <= $now + 15) {
            $ts = parseDurationDateNthMonday($n, $month, $year + 1);
            if ((int) date('n', $ts) !== $month) {
                return null;
            }
        }
        if ($tzName !== null) {
            $baseDt = new \DateTime('@' . $ts, $tz);
            $baseDt->setTimezone($tz);
            $baseDt->setTime(0, 0, 0);
        } else {
            $baseTimestamp = $ts;
        }
        $timeOffset = 3;
    } elseif (isset($monthNames[strtolower($m[1])])) {
        $month = $monthNames[strtolower($m[1])];
        $day = (int) $m[2];
        if ($tzName !== null) {
            $year = (int) $nowDt->format('Y');
            try {
                $baseDt = new \DateTime("{$year}-{$month}-{$day}", $tz);
            } catch (\Exception $e) {
                return null;
            }
            if ($baseDt->getTimestamp() <= $now + 15) {
                try {
                    $baseDt = new \DateTime(($year + 1) . "-{$month}-{$day}", $tz);
                } catch (\Exception $e) {
                    return null;
                }
            }
        } else {
            $year = (int) date('Y');
            $ts = mktime(0, 0, 0, $month, $day, $year);
            if ($ts === false || $ts <= $now + 15) {
                $ts = mktime(0, 0, 0, $month, $day, $year + 1);
            }
            if ($ts === false) {
                return null;
            }
            $baseTimestamp = $ts;
        }
        $timeOffset = 3;
    } elseif (!empty($m[1]) && preg_match('/^\d{1,2}/', $m[1]) && isset($m[5]) && isset($dayNames[strtolower($m[5])])) {
        $target = $dayNames[strtolower($m[5])];
        if ($tzName !== null) {
            $today = (int) $nowDt->format('N');
            $diff = $target - $today;
            if ($diff <= 0) {
                $diff += 7;
            }
            $targetDt = (clone $baseDt)->modify("+{$diff} days");
        } else {
            $today = (int) date('N');
            $diff = $target - $today;
            if ($diff <= 0) {
                $diff += 7;
            }
            $targetTs = $baseTimestamp + $diff * 86400;
        }
        $hours = (int) $m[1];
        $minutes = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        $seconds = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;
        $ampm = strtolower($m[4]);
        if ($ampm === 'pm' && $hours < 12) {
            $hours += 12;
        }
        if ($ampm === 'am' && $hours === 12) {
            $hours = 0;
        }
        if ($tzName !== null) {
            $targetDt->setTime($hours, $minutes, $seconds);
            $targetTime = $targetDt->getTimestamp();
        } else {
            $targetTime = $targetTs + $hours * 3600 + $minutes * 60 + $seconds;
        }
        $remainder = isset($m[6]) ? trim($m[6]) : '';
        if ($targetTime <= $now + 15) {
            return null;
        }
        return new ParseResult(
            seconds: $targetTime - $now,
            remainder: $remainder,
            targetTime: $targetTime,
        );
    } else {
        return null;
    }

    $hours = 0;
    $minutes = 0;
    $seconds = 0;
    if (!empty($m[$timeOffset])) {
        $hours = (int) $m[$timeOffset];
        $minutes = isset($m[$timeOffset + 1]) && $m[$timeOffset + 1] !== '' ? (int) $m[$timeOffset + 1] : 0;
        $seconds = isset($m[$timeOffset + 2]) && $m[$timeOffset + 2] !== '' ? (int) $m[$timeOffset + 2] : 0;
        $ampm = strtolower($m[$timeOffset + 3]);
        if ($ampm === 'pm' && $hours < 12) {
            $hours += 12;
        }
        if ($ampm === 'am' && $hours === 12) {
            $hours = 0;
        }
    }

    if ($tzName !== null) {
        $baseDt->setTime($hours, $minutes, $seconds);
        $targetTime = $baseDt->getTimestamp();
    } else {
        $targetTime = $baseTimestamp + $hours * 3600 + $minutes * 60 + $seconds;
    }
    $remainder = isset($m[$timeOffset + 4]) ? trim($m[$timeOffset + 4]) : '';

    if ($targetTime <= $now + 15) {
        return null;
    }

    return new ParseResult(
        seconds: $targetTime - $now,
        remainder: $remainder,
        targetTime: $targetTime,
    );
}

function parseDurationDateNthMonday(int $n, int $month, int $year): int
{
    $firstDay = mktime(0, 0, 0, $month, 1, $year);
    $dow = (int) date('N', $firstDay);
    $daysToFirstMon = (8 - $dow) % 7;
    $firstMonday = 1 + $daysToFirstMon;
    $targetDay = $firstMonday + ($n - 1) * 7;
    return mktime(0, 0, 0, $month, $targetDay, $year);
}

