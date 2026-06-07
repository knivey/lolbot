<?php

namespace Tests\Quotes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../library/strip_timestamp.php';

class StripTimestampTest extends TestCase
{
    public function test_bracketed_hh_mm_ss(): void
    {
        $input = '[12:28:31] <slime> anytime i open a tech article';
        $this->assertSame('<slime> anytime i open a tech article', stripTimestamp($input));
    }

    public function test_bracketed_hh_mm(): void
    {
        $input = '[00:42] <&sniff> i\'m going to assassinate joe biden';
        $this->assertSame('<&sniff> i\'m going to assassinate joe biden', stripTimestamp($input));
    }

    public function test_unbracketed_hh_mm_ss(): void
    {
        $input = '12:43:42 <~Altair8800> was going down on darkmage\'s mum';
        $this->assertSame('<~Altair8800> was going down on darkmage\'s mum', stripTimestamp($input));
    }

    public function test_unbracketed_hh_mm(): void
    {
        $input = '06:20 <+darkmage> don\'t worry nes you\'ll get yours';
        $this->assertSame('<+darkmage> don\'t worry nes you\'ll get yours', stripTimestamp($input));
    }

    public function test_single_digit_hour_with_seconds(): void
    {
        $input = ' 9:38:00 <+darkmage> last girl i met off okc was unimpressive';
        $this->assertSame('<+darkmage> last girl i met off okc was unimpressive', stripTimestamp($input));
    }

    public function test_leading_space_h_mm_ss(): void
    {
        $input = ' 8:20:44 --> hgc (~hgc@kick.dog) has joined #sniff';
        $this->assertSame('--> hgc (~hgc@kick.dog) has joined #sniff', stripTimestamp($input));
    }

    public function test_box_drawing_prefix(): void
    {
        $input = "\xe2\x94\x82" . '00:17:34 +sn1ff <marquee> welcome to l0de\'s geocities page';
        $this->assertSame('+sn1ff <marquee> welcome to l0de\'s geocities page', stripTimestamp($input));
    }

    public function test_box_drawing_prefix_full_line_from_db(): void
    {
        $input = "\xe2\x94\x82" . '21:17:29       +ct8 | you been listening to cernovich';
        $this->assertSame('+ct8 | you been listening to cernovich', stripTimestamp($input));
    }

    public function test_leading_space_h_mm_ss_with_nick(): void
    {
        $input = ' 5:53:17 <~zamn> i think my cock would explode';
        $this->assertSame('<~zamn> i think my cock would explode', stripTimestamp($input));
    }

    public function test_bracketed_hh_mm_ss_with_space_after(): void
    {
        $input = '[09:34:09] ~octopus: apple headphones are actually p nice';
        $this->assertSame('~octopus: apple headphones are actually p nice', stripTimestamp($input));
    }

    public function test_unbracketed_h_mm_ss(): void
    {
        $input = ' 1:23:18 <~mavericks> srs tho i feel like there\'s a lot';
        $this->assertSame('<~mavericks> srs tho i feel like there\'s a lot', stripTimestamp($input));
    }

    public function test_plain_nick_message_unchanged(): void
    {
        $input = '<chunky> lol';
        $this->assertSame($input, stripTimestamp($input));
    }

    public function test_nick_with_angles_unchanged(): void
    {
        $input = '<~chunky> i ate 4 whole smoked chickens in 1 day';
        $this->assertSame($input, stripTimestamp($input));
    }

    public function test_at_prefixed_nick_unchanged(): void
    {
        $input = '@sansGato | dw1 ... WHO AM I RN';
        $this->assertSame($input, stripTimestamp($input));
    }

    public function test_empty_string_unchanged(): void
    {
        $this->assertSame('', stripTimestamp(''));
    }

    public function test_nick_before_timestamp_unchanged(): void
    {
        $input = 'sniff 21:33:24 <~sniff> don\'t make fun of me';
        $this->assertSame($input, stripTimestamp($input));
    }
}
