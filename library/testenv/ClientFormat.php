<?php

namespace library\testenv;

/**
 * Pure output-formatting helpers for the test-env driver client
 * (testenv/client.php). No I/O — the REPL does all reading/writing and
 * renders every inbound PRIVMSG through formatLine().
 */
class ClientFormat
{
    /**
     * Render a parsed PRIVMSG as one terminal line: `[HH:MM] <from> text`.
     *
     * CTCP messages (\x01...\x01, whether they wrap the whole text or are
     * embedded in it) collapse to `[CTCP ...]`; mIRC color codes (\x03 with
     * optional 1-2 digit fg/bg params) are stripped; remaining C0 control
     * chars (and \x7f) are neutralized to spaces so printed lines can never
     * smuggle escapes/newlines into the terminal.
     *
     * @param array{from: string, target: string, text: string} $msg
     */
    public static function formatLine(array $msg): string
    {
        $text = preg_replace('/\x01([^\x01]*)\x01/', '[CTCP $1]', $msg['text']) ?? $msg['text'];
        return sprintf('[%s] <%s> %s', date('H:i'), self::neutralize($msg['from']), self::neutralize($text));
    }

    /**
     * Strip \x03 color sequences (with their digit params, e.g. \x034,
     * \x0312,5) and replace any other C0 control or DEL character with a
     * plain space.
     */
    private static function neutralize(string $text): string
    {
        $text = preg_replace('/\x03\d{0,2}(?:,\d{0,2})?/', '', $text) ?? '';
        return preg_replace('/[\x00-\x1f\x7f]/', ' ', $text) ?? '';
    }
}
