<?php

namespace Tests\Unit\Utils;

use App\Utils\CssScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The scanner reads CSS where the browser does: where a comment, a string or an unquoted address starts and ends decides what a browser
 * loads, and no pattern over the raw text knows it. These pin the pieces one by one; InertSvgTest pins what the file does with them.
 */
class CssScannerTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function textsAndTheirPlainText(): array
    {
        return [
            // comments
            'a comment is removed and leaves a space' => ['a/* x */b', 'a b'],
            'a comment that is not closed runs to the end' => ['a/* x', 'a '],
            'a comment is closed by the first end marker' => ['/* a */ b */', '  b */'],
            'the slash and the star of an opener do not close it' => ['/*/ a', ' '],
            'two comments in a row' => ['a/**//**/b', 'a  b'],
            'a comment between the name and the parenthesis keeps them apart' => ['ur/**/l(', 'ur l('],
            'a lone slash and a lone star are text' => ['a / b * c', 'a / b * c'],
            // strings
            'a comment marker in a double quoted string is text' => ['"/* x */"', '"/* x */"'],
            'a comment marker in a single quoted string is text' => ["'/*' x '*/'", "'/*' x '*/'"],
            'a string closes at its own quote only' => ['"a\'/*"b/* c */', '"a\'/*"b '],
            'a string that is not closed runs to the end' => ['"a/* b */', '"a/* b */'],
            'a string ends at a line break, and the line break is not part of it' => ["\"a\n/* b */c", "\"a\n c"],
            'a backslash before a line break joins the lines of a string' => ["\"a\\\nb\"", '"ab"'],
            'an escaped quote does not close a string' => ['"a\"/* b */"', "\"a\u{FFFD}/* b */\""],
            'a backslash at the end of a string that is not closed is dropped' => ['"a\\', '"a'],
            // addresses that are not quoted
            'a comment marker in an address is text' => ['url(a/* x */b)', 'url(a/* x */b)'],
            'an address ends at the first closing parenthesis' => ['url(a/*) b /* c */ d', 'url(a/*) b   d'],
            'an address that is not closed runs to the end' => ['url(a/* b */', 'url(a/* b */'],
            'a quote in an address is text' => ['url(a"/* b */) c', 'url(a"/* b */) c'],
            'an address after spaces and a comment is still an address' => ['url( /**/#a) /**/', 'url( /**/#a)  '],
            'a quoted address is a string followed by tokens' => ['url( "a"/* b */)', 'url( "a" )'],
            'an address in capitals is one token too' => ['URL(a/* x */b)', 'URL(a/* x */b)'],
            'an address in mixed letters is one token too' => ['uRl(a/* x */b)', 'uRl(a/* x */b)'],
            'an address whose name is written with an escape is one token too' => ['\\75rl(a/* x */b)', 'url(a/* x */b)'],
            'a name that is not url does not make an address' => ['curl(a/* b */)', 'curl(a )'],
            'a name that ends in url is not url' => ['-url(a/* b */)', '-url(a )'],
            'url and its parenthesis with a space between is not an address' => ['url (a/* b */)', 'url (a )'],
            // escapes
            'one hex digit' => ['\\75rl(', 'url('],
            'a hex escape that a space ends takes the space' => ['\\75 rl(', 'url('],
            'a hex escape that two spaces follow keeps the second' => ['\\75  rl(', 'u rl('],
            'a hex escape that a tab ends takes the tab' => ["\\75\trl(", 'url('],
            'a hex escape that a line break ends takes it' => ["\\75\nrl(", 'url('],
            'a hex escape that a carriage return and a line feed end takes both' => ["\\75\r\nrl(", 'url('],
            'six hex digits' => ['\\000075rl(', 'url('],
            'a seventh digit is not part of the escape' => ['\\0000075rl(', "\u{FFFD}5rl("],
            'an escape in capitals' => ['\\55RL(', 'URL('],
            'a letter that is not a hex digit stands for itself' => ['u\\r\\l(', 'url('],
            'a colon' => ['.sm\\:fill-red', '.sm:fill-red'],
            'an id that starts with a digit' => ['#\\31 23', '#123'],
            'a unicode escape in a string' => ['"\\201C"', "\"\u{201C}\""],
            'a backslash and a letter outside ASCII' => ["a\\\u{E9}b", "a\u{E9}b"],
            'the largest character' => ['\\10ffff x', "\u{10FFFF}x"],
            'nul is the replacement character' => ['\\0 x', "\u{FFFD}x"],
            'a surrogate is the replacement character' => ['\\d800 x', "\u{FFFD}x"],
            'a value above the last character is the replacement character' => ['\\110000 x', "\u{FFFD}x"],
            'a backslash before a line break is a character of its own' => ["a\\\nb", "a\\\nb"],
            'a backslash at the end is a character of its own' => ['a\\', 'a\\'],
            'an escape that spells the comment markers does not open a comment' => ['\\2f\\2a x \\2a\\2f ', '/*x */'],
            'an escape that spells a quote does not open a string, it is not a quote at all' => ['\\22 /* x */', "\u{FFFD} "],
            'an escape that spells a parenthesis is not a parenthesis' => ['url\\28 a)', "url\u{FFFD}a)"],
            'an escape in an address does not close it, it is not a parenthesis' => ['url(a\\29 /* b */)', "url(a\u{FFFD}/* b */)"],
            'an escape that starts an address is not an empty address' => ['url(\\29 ../x)', "url(\u{FFFD}../x)"],
            'escapes that spell quotes around a fragment are not quotes' => ['url(\\22 #a\\22 )', "url(\u{FFFD}#a\u{FFFD})"],
            'an escaped single quote' => ['\\27 a', "\u{FFFD}a"],
            'an escaped space' => ['a\\20 b', "a\u{FFFD}b"],
            'an escaped tab' => ['a\\9 b', "a\u{FFFD}b"],
            'an escaped line break' => ['a\\a b', "a\u{FFFD}b"],
            'an escaped control character' => ['a\\1 b', "a\u{FFFD}b"],
            'an escaped backslash' => ['a\\\\b', "a\u{FFFD}b"],
            'an escaped quote written without digits' => ['a\\"b', "a\u{FFFD}b"],
            'an escaped parenthesis written without digits' => ['a\\(b\\)', "a\u{FFFD}b\u{FFFD}"],
            'an escaped space written without digits' => ['a\\ b', "a\u{FFFD}b"],
            'an escaped slash and an escaped star are text' => ['a\\/\\*b', 'a/*b'],
            'an escaped at sign is text' => ['\\40 import', '@import'],
            'an escaped colon and hyphen are text' => ['a\\3a b\\2d c', 'a:b-c'],
            'an escaped slash does not open a comment' => ['\\/* a */ b', '/* a */ b'],
            // line ends
            'a carriage return and a line feed are one line break' => ["a\r\nb", "a\nb"],
            'a carriage return alone is a line break' => ["a\rb", "a\nb"],
            'a form feed is a line break' => ["a\fb", "a\nb"],
            'nul is the replacement character in the text itself' => ["a\0b", "a\u{FFFD}b"],
        ];
    }

    #[DataProvider('textsAndTheirPlainText')]
    public function test_the_plain_text_has_no_comments_and_its_escapes_written_out(string $css, string $expected): void
    {
        $this->assertSame($expected, CssScanner::plainText($css));
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function textsAndTheAddressesTheyLoad(): array
    {
        return [
            'nothing' => ['fill:red', []],
            'a fragment' => ['fill:url(#a)', ['#a']],
            'a fragment in capitals' => ['fill:URL(#a)', ['#a']],
            'a fragment in double quotes' => ['fill:url("#a")', ['#a']],
            'a fragment in single quotes' => ["fill:url('#a')", ['#a']],
            'a fragment with spaces around the quotes' => ['fill:url( "#a" )', ['#a']],
            'a fragment with spaces inside the quotes' => ['fill:url(" #a ")', ['#a']],
            'a fragment with a line break around it' => ["fill:url(\n#a\n)", ['#a']],
            'an empty address' => ['fill:url()', ['']],
            'an empty string' => ['fill:url("")', ['']],
            'two addresses' => ['fill:url(#a) url(#b)', ['#a', '#b']],
            'an address of another site' => ['fill:url(https://evil.test/p.svg#a)', ['https://evil.test/p.svg#a']],
            'an address of another site in a string' => ['fill:url("https://evil.test/p.svg#a")', ['https://evil.test/p.svg#a']],
            'a raster image given as data' => ['x:url(data:image/png;base64,AAAA)', ['data:image/png;base64,AAAA']],
            'a data address with a space in a string' => ["x:url('data:image/png;base64,AA AA')", ['data:image/png;base64,AA AA']],
            'an address that is written with escapes' => ['x:\\75rl(https://evil.test/p.png)', ['https://evil.test/p.png']],
            'an address in a string of a comment marker' => ['a{b:"/*"} c{d:url(https://evil.test/p.png)} e{f:"*/"}', ['https://evil.test/p.png']],
            'an address after an address that holds a comment marker' => ['a{b:url(#a/*)} c{d:url(https://evil.test/p.png)} /* */', ['#a/*', 'https://evil.test/p.png']],
            'an address in a comment is no address' => ['/* url(https://evil.test/p.png) */ a{b:c}', []],
            'a name that ends in url' => ['x:curl(https://evil.test/p.png)', []],
            'a name that starts with a hyphen' => ['x:-url(https://evil.test/p.png)', []],
            'a name that ends in a hyphen and url' => ['x:my-url(https://evil.test/p.png)', []],
            'a name with a digit before url' => ['x:9url(https://evil.test/p.png)', []],
            'url and a parenthesis with a space between' => ['x:url (https://evil.test/p.png)', []],
            'a name that a comment cuts' => ['x:ur/**/l(https://evil.test/p.png)', []],
            'the word url in words' => ['curl C:\\Temp\\export', []],
            'a fragment with tabs inside the quotes' => ["fill:url(\"\t#a\t\")", ['#a']],
            'a name that ends in image' => ['x:bgimage(a.png)', []],
            'a name with a hyphen before image' => ['x:my-image(a.png)', []],
            'a name that ends in src' => ['x:data-src(a.png)', []],
            'a name that ends in element' => ['x:pseudo-element(a)', []],
            'a name that ends in attr' => ['x:data-attr(a)', []],
            'a name that ends in cross-fade' => ['x:my-cross-fade(a.png)', []],
            'a name that ends in image-set' => ['x:my-image-set(a.png)', []],
            'an image set with a space before the parenthesis is no function' => ['x:image-set ("a.png" 1x)', []],
            'a source with a space before the parenthesis is no function' => ['x:src ("a.png")', []],
            'an attribute with a space before the parenthesis is no function' => ['x:attr (data-u url)', []],
            'a prefixed image set with a space before the parenthesis is no function' => ['x:-webkit-image-set ("a.png" 1x)', []],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('textsAndTheAddressesTheyLoad')]
    public function test_a_style_loads_the_addresses_of_its_url_calls(string $css, array $expected): void
    {
        $this->assertSame($expected, CssScanner::addressesOfStyle($css));
        $this->assertSame($expected, CssScanner::addressesOfValue($css));
    }

    /** @return array<string, array{0: string}> */
    public static function textsThatCannotBeRead(): array
    {
        return [
            'an address that is not closed' => ['fill:url(#a'],
            'a url that ends the text' => ['fill:url('],
            'a string that is not closed' => ['fill:url("#a"'],
            'a string that is not closed, in single quotes' => ["fill:url('#a"],
            'an address with a space and more' => ['fill:url(#a b)'],
            'a string and more' => ['fill:url("#a" x)'],
            'an address with a parenthesis' => ['fill:url(a(b))'],
            'an address with a quote' => ['fill:url(#a"b)'],
            'a comment and a string' => ['fill:url(/**/"#a")'],
            'a comment before an address that is not closed' => ['/* ) */ rect{mask-image:url(https://evil.test/p.png'],
            'a comment after an address that is not closed' => ['rect{mask-image:url(https://evil.test/p.png /* ) */'],
            'an address that is not closed, after a string with a parenthesis' => ['.x{content:")"} rect{mask-image:url(https://evil.test/p.png'],
            'an address inside another' => ['x:url(url(#a))'],
            'a url written with an escape and not closed' => ['x:\\75rl(https://evil.test/p.png'],
            'an image set' => ['x:image-set("a.png" 1x)'],
            'an image set in capitals' => ['x:IMAGE-SET("a.png" 1x)'],
            'an image set with a prefix' => ['x:-webkit-image-set("a.png" 1x)'],
            'a cross fade' => ['x:cross-fade(url(#a), url(#b), 50%)'],
            'a cross fade with a prefix' => ['x:-webkit-cross-fade(url(#a), url(#b), 50%)'],
            'an element' => ['x:element(#a)'],
            'an element with another prefix' => ['x:-moz-element(#a)'],
            'an image' => ['x:image("a.png")'],
            'a source' => ['x:src("a.png")'],
            'a source after a comment' => ['x:/**/src("a.png")'],
            'a source after a hyphen and a letter that a comment cuts' => ['x:a/**/src("a.png")'],
            'an attribute read as an address' => ['x:attr(data-u url)'],
            'a function written with an escape' => ['x:image-s\\65t("a.png" 1x)'],
            'a function written with a first escape' => ['x:\\69mage-set("a.png" 1x)'],
        ];
    }

    #[DataProvider('textsThatCannotBeRead')]
    public function test_a_text_that_cannot_be_read_loads_nothing_that_can_be_named(string $css): void
    {
        $this->assertNull(CssScanner::addressesOfStyle($css));
        $this->assertNull(CssScanner::addressesOfValue($css));
    }

    /** @return array<string, array{0: string}> */
    public static function textsOfAStyleSheetOnly(): array
    {
        return [
            'an import' => ["@import 'a.css';"],
            'an import written with an escape' => ["@\\69mport 'a.css';"],
            'an import in capitals' => ["@IMPORT 'a.css';"],
            'an import in a string' => ['x:"@import"'],
            'an expression' => ['width:expression(alert(1))'],
            'an expression with a space' => ['width:expression (alert(1))'],
            'a behavior' => ['behavior:url(#a)'],
            'a behaviour' => ['behaviour:foo'],
            'a behavior with a comment before the colon' => ['behavior/**/:foo'],
            'a binding' => ['-moz-binding:foo'],
            'a script url' => ['x:javascript:alert(1)'],
            'a script url with a space' => ['x:javascript :alert(1)'],
            'a basic script url' => ['x:vbscript:msgbox(1)'],
        ];
    }

    #[DataProvider('textsOfAStyleSheetOnly')]
    public function test_a_style_sheet_refuses_what_only_a_style_sheet_means_and_another_value_does_not(string $text): void
    {
        $this->assertNull(CssScanner::addressesOfStyle($text));
        $this->assertNotNull(CssScanner::addressesOfValue($text));
    }

    public function test_words_in_a_label_are_not_css(): void
    {
        $this->assertSame([], CssScanner::addressesOfValue('Behavior: normal'));
        $this->assertSame([], CssScanner::addressesOfValue('@import and -moz-binding are old CSS'));
    }

    public function test_a_very_long_address_is_read_like_a_short_one(): void
    {
        $address = 'https://evil.test/p.png?' . str_repeat('a', 2_000_000);

        $this->assertSame([$address], CssScanner::addressesOfStyle('x:url(' . $address . ')'));
        $this->assertSame([$address], CssScanner::addressesOfStyle('x:url("' . $address . '")'));
        $this->assertNull(CssScanner::addressesOfStyle('x:url(' . $address));
    }

    public function test_a_long_run_of_parentheses_and_strings_is_read_quickly(): void
    {
        $started = microtime(true);

        $this->assertNull(CssScanner::addressesOfStyle(str_repeat('url(', 100000)));
        $this->assertSame([], CssScanner::addressesOfStyle(str_repeat('(', 1_000_000) . str_repeat('"a"', 300000)));
        $this->assertLessThan(10, microtime(true) - $started);
    }
}
