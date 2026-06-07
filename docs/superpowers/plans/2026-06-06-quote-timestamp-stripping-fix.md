# Quote Timestamp Stripping Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the `stripTimestamp()` function in `artbot_scripts/quotes.php` to properly strip all common IRC timestamp formats from the beginning of lines during quote recording.

**Architecture:** Extract `stripTimestamp()` into a standalone library file (`library/strip_timestamp.php`) so it can be tested independently without loading the full quotes.php (which has heavy dependencies on RedBean, Irc, etc.). The quotes script includes the library file. A PHPUnit test validates all observed timestamp formats plus negative cases.

**Tech Stack:** PHP 8.1+, PHPUnit 13, existing test patterns from `tests/`.

---

### Task 1: Create library function file and write tests

**Files:**
- Create: `library/strip_timestamp.php`
- Create: `tests/Quotes/StripTimestampTest.php`

- [ ] **Step 1: Create the library file with the fixed function**

```php
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
```

- [ ] **Step 2: Write the test file**

```php
<?php

namespace Tests\Quotes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../library/strip_timestamp.php';

class StripTimestampTest extends TestCase
{
    // --- Formats observed in the real DB ---

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

    public function test_leading_space_hh_mm_ss(): void
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
        // Quote #55: │21:17:29       +ct8 | you been listening to cernovich
        $input = "\xe2\x94\x82" . '21:17:29       +ct8 | you been listening to cernovich';
        $this->assertSame('+ct8 | you been listening to cernovich', stripTimestamp($input));
    }

    public function test_leading_space_h_mm_ss(): void
    {
        $input = ' 5:53:17 <~zamn> i think my cock would explode';
        $this->assertSame('<~zamn> i think my cock would explode', stripTimestamp($input));
    }

    public function test_bracketed_hh_mm_ss_with_space_after(): void
    {
        $input = '[09:34:09] ~octopus: apple headphones are actually p nice';
        $this->assertSame('~octopus: apple headphones are actually p nice', stripTimestamp($input));
    }

    public function test_unbracketed_h_mm(): void
    {
        $input = ' 1:23:18 <~mavericks> srs tho i feel like there\'s a lot';
        $this->assertSame('<~mavericks> srs tho i feel like there\'s a lot', stripTimestamp($input));
    }

    // --- Negative cases: should NOT strip ---

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
        // Quote #89: sniff 21:33:24 <~sniff> - the nick IS part of the quote
        $input = 'sniff 21:33:24 <~sniff> don\'t make fun of me';
        $this->assertSame($input, stripTimestamp($input));
    }
}
```

- [ ] **Step 3: Run the tests and verify they pass**

Run: `vendor/bin/phpunit tests/Quotes/StripTimestampTest.php`
Expected: All tests PASS

- [ ] **Step 4: Commit**

```bash
git add library/strip_timestamp.php tests/Quotes/StripTimestampTest.php
git commit -m "feat: extract stripTimestamp to library with improved regex and tests (ref #47)"
```

---

### Task 2: Update quotes.php to use the library function

**Files:**
- Modify: `artbot_scripts/quotes.php`

- [ ] **Step 1: Add require_once and remove inline function**

At the top of `artbot_scripts/quotes.php`, after the existing `use` statements (line 9), add:

```php
require_once __DIR__ . '/../library/strip_timestamp.php';
```

Then remove the inline `stripTimestamp` function definition (lines 90-101):

```php
function stripTimestamp(string $line): string {
    //var_dump($line);
    if(!preg_match("@^( *\[? *[\d:\-\\\/ ]+ *(?:am|pm)? *[\d:\-\\\/ ]* *]? *).+$@i", $line, $m)) {
        return $line;
    }
    $test = str_replace(['[',']'], '', $m[1]);
    //var_dump($test);
    if(!strtotime(trim($test))) {
        return $line;
    }
    return substr($line, strlen($m[1]));
}
```

- [ ] **Step 2: Run tests to verify nothing broke**

Run: `vendor/bin/phpunit tests/Quotes/StripTimestampTest.php`
Expected: All tests PASS

- [ ] **Step 3: Run static analysis**

Run: `composer phpstan`
Expected: No new errors

- [ ] **Step 4: Commit**

```bash
git add artbot_scripts/quotes.php
git commit -m "refactor: quotes.php uses library stripTimestamp function (ref #47)"
```
