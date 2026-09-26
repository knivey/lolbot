<?php
namespace Tests\Irc;

use Irc\Message;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    public function test_untagged_line_parses_as_before(): void
    {
        $m = Message::parse(':nick!user@host PRIVMSG #chan :hello there');
        $this->assertNotNull($m);
        $this->assertSame('nick!user@host', $m->getHostString());
        $this->assertSame('PRIVMSG', $m->command);
        $this->assertSame(['#chan', 'hello there'], $m->args);
        $this->assertNull($m->tags);
    }

    public function test_literal_zero_args_are_preserved(): void
    {
        // e.g. WHOX 354 uses 0 as the logged-out account sentinel; a bare
        // array_filter() would drop it and shift every later arg
        $m = Message::parse(':srv 354 me 123 ident host nick H@ 0');
        $this->assertNotNull($m);
        $this->assertSame(['me', '123', 'ident', 'host', 'nick', 'H@', '0'], $m->args);
    }

    public function test_doubled_spaces_still_drop_empty_args(): void
    {
        $m = Message::parse(':srv 354 me  123 ident host nick H@');
        $this->assertNotNull($m);
        $this->assertSame(['me', '123', 'ident', 'host', 'nick', 'H@'], $m->args);
    }

    public function test_tags_parse_with_prefix_and_args(): void
    {
        $m = Message::parse('@account=zen;msgid=x1y2 :nick!user@host PRIVMSG #chan :hi');
        $this->assertNotNull($m);
        $this->assertSame(['account' => 'zen', 'msgid' => 'x1y2'], $m->tags);
        $this->assertSame('nick!user@host', $m->getHostString());
        $this->assertSame('PRIVMSG', $m->command);
        $this->assertSame(['#chan', 'hi'], $m->args);
    }

    public function test_tags_without_prefix(): void
    {
        $m = Message::parse('@intent=ACTION PRIVMSG #chan :goes');
        $this->assertNotNull($m);
        $this->assertSame(['intent' => 'ACTION'], $m->tags);
        $this->assertNull($m->nick);
        $this->assertSame(['#chan', 'goes'], $m->args);
    }

    public function test_valueless_and_empty_tag_values(): void
    {
        $m = Message::parse('@away;empty=;active=1 :s NOTICE * :x');
        $this->assertNotNull($m);
        $this->assertSame(['away' => '', 'empty' => '', 'active' => '1'], $m->tags);
    }

    public function test_tag_escapes_are_unescaped(): void
    {
        // \: -> ;, \s -> space, \\ -> backslash, \r, \n
        // (wire tag block: reply=a\sb\:c;d=\r\ne — 'd' has value \r\ne)
        $m = Message::parse('@reply=a\\sb\\:c;d=\\r\\ne :n!u@h PRIVMSG #c :x');
        $this->assertNotNull($m);
        $this->assertSame(['reply' => 'a b;c', 'd' => "\r\ne"], $m->tags);
    }

    public function test_escaped_backslash_before_colon_survives_unescape(): void
    {
        // wire value a\\:b = escaped backslash followed by a bare colon; the
        // backslash must survive and the colon must NOT be re-read as a \:
        // escape (the classic replace-order bug)
        $m = Message::parse('@k=a\\\\:b :n!u@h PRIVMSG #c :x');
        $this->assertNotNull($m);
        $this->assertSame(['k' => 'a\\:b'], $m->tags);
    }

    public function test_lone_at_sign_is_not_a_tag_block(): void
    {
        // '@' followed by space is not valid tags; treat line as normal.
        // Pinned behavior: the empty tag block yields an empty tag array and
        // the remainder of the line still parses as a normal message.
        $m = Message::parse('@ :n!u@h PRIVMSG #c :x');
        $this->assertNotNull($m);
        $this->assertSame([], $m->tags);
        $this->assertSame('n!u@h', $m->getHostString());
        $this->assertSame('PRIVMSG', $m->command);
        $this->assertSame(['#c', 'x'], $m->args);
    }

    public function test_trailing_backslash_in_value(): void
    {
        // dangling escape is dropped; must not fatal and the line still parses
        $m = Message::parse('@k=v\\ :n!u@h PRIVMSG #c :x');
        $this->assertNotNull($m);
        $this->assertSame(['k' => 'v'], $m->tags);
        $this->assertSame('PRIVMSG', $m->command);
        $this->assertSame(['#c', 'x'], $m->args);
    }

    public function test_tags_survive_the_unknown_fallback(): void
    {
        // '@a=b ' leaves an empty message after the tag block; it falls back
        // to UNKNOWN but the parsed tags must still be carried through
        $m = Message::parse('@a=b ');
        $this->assertNotNull($m);
        $this->assertSame('UNKNOWN', $m->command);
        $this->assertSame(['a' => 'b'], $m->tags);
    }

    public function test_at_sign_without_space_is_not_a_tag_block(): void
    {
        // current behavior: '@' with no following space is not a tag block,
        // so the line parses as a plain command and tags stay null
        $m = Message::parse('@foo');
        $this->assertNotNull($m);
        $this->assertSame('@foo', $m->command);
        $this->assertNull($m->tags);
    }

    public function test_constructor_accepts_optional_tags(): void
    {
        $m = new Message('PRIVMSG', ['#chan', 'hi'], 'nick!user@host', ['account' => 'zen']);
        $this->assertSame(['account' => 'zen'], $m->tags);
        $this->assertSame('PRIVMSG', $m->command);

        $bare = new Message('PING');
        $this->assertNull($bare->tags);
    }
}
