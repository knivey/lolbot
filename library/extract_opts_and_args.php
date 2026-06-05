<?php

/**
 * Extracts --option[=value] tokens from text, returning [opts, positionalArgs].
 * Only tokens starting with -- (two dashes) followed by at least one non-dash
 * character are treated as options. Single-dash tokens are left as positional args.
 *
 * @param string $msg
 * @return array{0: array<string, string|null>, 1: array<string>}
 */
function extractOptsAndArgs(string $msg): array {
    $opts = [];
    $args = [];
    $words = explode(' ', $msg);
    foreach ($words as $w) {
        if ($w === '') {
            continue;
        }
        if (preg_match('/^--([^-].*)$/', $w, $m)) {
            if (str_contains($m[1], '=')) {
                [$lhs, $rhs] = explode('=', $m[1], 2);
                $opts['--' . $lhs] = $rhs;
            } else {
                $opts['--' . $m[1]] = null;
            }
        } else {
            $args[] = $w;
        }
    }
    return [$opts, $args];
}
