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
function Duration_toString($duration, $periods = null) {
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
 * @author knivey
 * @param string $duration
 */
function string2Seconds($duration) {
	global $Duration_periods;
    $string = trim($duration);
    $string = str_split($string);
    $num = '';
    $total = 0;
    foreach ($string as $chunk) {
        if (is_numeric($chunk)) { //its a number!
            $num .= $chunk;
        } else {
            $times = 1;
            if(array_key_exists($chunk, $Duration_periods)) {
            	$total += $num * $Duration_periods[$chunk];
            } else {
            	return "$chunk is an unknown time identifier";
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
function Duration_int2array($seconds, $periods = null) {
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
function Duration_array2string($duration, $nopad = false) {
    if (!is_array($duration)) {
        return false;
    }
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

