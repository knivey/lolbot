<?php

function stripTimestamp(string $line): string {
    if (!preg_match('@^[\s│┃║|]*\[?(\d{1,2}:\d{2}(?::\d{2})?)\]?\s+@', $line, $m)) {
        return $line;
    }
    if (!strtotime($m[1])) {
        return $line;
    }
    $fullMatch = $m[0];
    return substr($line, strlen($fullMatch));
}
