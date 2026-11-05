<?php

namespace Tests\Feature\Fulfillment;

use App\Services\Fulfillment\Support\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A supplier's description is printed to customers as HTML. Whatever the supplier sends, what comes out may contain only
 * the plain formatting the sanitiser writes itself.
 */
class HtmlSanitizerTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function attacks(): iterable
    {
        yield 'script tag' => ['<p>hi</p><script>alert(1)</script>'];
        yield 'script with attributes and case' => ['<ScRiPt src="https://evil.example/x.js"></sCrIpT>'];
        yield 'img onerror' => ['<img src=x onerror=alert(1)>'];
        yield 'img onerror with a valid src' => ['<img src="https://ok.example/a.png" onerror="alert(1)" onload="alert(2)">'];
        yield 'javascript url' => ['<a href="javascript:alert(1)">x</a>'];
        yield 'javascript url with tab' => ["<a href=\"java\tscript:alert(1)\">x</a>"];
        yield 'javascript url with entity' => ['<a href="jav&#x09;ascript:alert(1)">x</a>'];
        yield 'javascript url with leading space' => ['<a href="  javascript:alert(1)">x</a>'];
        yield 'javascript url with control characters' => ["<a href=\"\x01javascript:alert(1)\">x</a>"];
        yield 'vbscript url' => ['<a href="vbscript:msgbox(1)">x</a>'];
        yield 'data url' => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>'];
        yield 'data url image' => ['<img src="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+">'];
        yield 'svg onload' => ['<svg onload=alert(1)><circle r=1 /></svg>'];
        yield 'svg with script' => ['<svg><script>alert(1)</script></svg>'];
        yield 'math' => ['<math><mi xlink:href="javascript:alert(1)">x</mi></math>'];
        yield 'iframe' => ['<iframe src="https://evil.example"></iframe>'];
        yield 'iframe srcdoc' => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>'];
        yield 'object and embed' => ['<object data="x.swf"></object><embed src="x.swf">'];
        yield 'style block' => ['<style>@import "https://evil.example/x.css"; body{display:none}</style>'];
        yield 'inline style' => ['<p style="position:fixed;top:0;left:0;width:100%;height:100%">overlay</p>'];
        yield 'event handler on a div' => ['<div onmouseover="alert(1)">x</div>'];
        yield 'form' => ['<form action="https://evil.example"><input name="card"><button>pay</button></form>'];
        yield 'base tag' => ['<base href="https://evil.example/">'];
        yield 'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.example">'];
        yield 'link tag' => ['<link rel="stylesheet" href="https://evil.example/x.css">'];
        yield 'broken nesting' => ['<<script>script>alert(1)<</script>/script>'];
        yield 'noscript mutation' => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>">'];
        yield 'comment trick' => ['<!--><script>alert(1)</script>-->'];
        yield 'cdata trick' => ['<![CDATA[<script>alert(1)</script>]]>'];
        yield 'attribute breakout' => ['<a href="https://ok.example" title="x\" onmouseover=\"alert(1)">x</a>'];
        yield 'template' => ['<template><script>alert(1)</script></template>'];
        yield 'textarea breakout' => ['<textarea></textarea><script>alert(1)</script>'];
        yield 'xmp' => ['<xmp><script>alert(1)</script></xmp>'];
    }

    #[DataProvider('attacks')]
    public function test_nothing_that_can_run_survives(string $attack): void
    {
        $clean = strtolower(HtmlSanitizer::clean($attack));

        foreach (['<script', 'javascript:', 'vbscript:', 'data:', 'onerror', 'onload', 'onmouseover', '<iframe', '<svg', '<math', '<style', '<object', '<embed', '<form', '<input', '<meta', '<link', '<base', 'style=', 'srcdoc'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $clean, "'{$forbidden}' survived in: {$clean}");
        }
    }

    public function test_ordinary_formatting_survives(): void
    {
        $html = '<h2>Vitamin C</h2><p>Take <strong>one</strong> tablet <em>daily</em>.</p><ul><li>500 mg</li><li>60 tablets</li></ul>'
            . '<table><tr><th>Dose</th><td colspan="2">1</td></tr></table><br><hr>';

        $this->assertSame($html, HtmlSanitizer::clean($html));
    }

    public function test_safe_links_and_images_are_kept_with_their_safe_attributes_only(): void
    {
        $clean = HtmlSanitizer::clean('<a href="https://ok.example/a?b=1&c=2" target="_blank" onclick="x()" style="color:red">link</a>'
            . '<img src="https://ok.example/a.png" alt="A pill" width="120" height="x" class="big">');

        $this->assertSame(
            '<a href="https://ok.example/a?b=1&amp;c=2" target="_blank" rel="noopener noreferrer nofollow">link</a>'
            . '<img src="https://ok.example/a.png" alt="A pill" width="120">',
            $clean,
        );
    }

    public function test_links_may_use_http_https_mailto_tel_and_relative_paths_only(): void
    {
        foreach (['http://a.example', 'https://a.example', 'mailto:a@a.example', 'tel:+966500000000', '/about', '#top', 'page.html'] as $href) {
            $this->assertStringContainsString('href=', HtmlSanitizer::clean("<a href=\"{$href}\">x</a>"), $href);
        }
        foreach (['ftp://a.example', 'file:///etc/passwd', 'javascript:1', '//evil.example/x'] as $href) {
            $this->assertStringNotContainsString('href=', HtmlSanitizer::clean("<a href=\"{$href}\">x</a>"), $href);
        }
    }

    public function test_an_image_needs_an_https_source_or_it_is_dropped(): void
    {
        $this->assertSame('', HtmlSanitizer::clean('<img src="http://ok.example/a.png">'));
        $this->assertSame('', HtmlSanitizer::clean('<img alt="no source">'));
        $this->assertSame('', HtmlSanitizer::clean('<img src="/relative.png">'));
    }

    public function test_unknown_tags_are_unwrapped_but_their_text_is_kept(): void
    {
        $this->assertSame('<p>Hello <b>there</b></p>', HtmlSanitizer::clean('<custom-box><p>Hello <font color="red"><b>there</b></font></p></custom-box>'));
    }

    public function test_text_is_escaped_so_it_cannot_become_markup_again(): void
    {
        $this->assertSame('Dose &lt; 5 &amp; &gt; 1', HtmlSanitizer::clean('Dose < 5 & > 1'));
    }

    public function test_arabic_text_survives_unchanged(): void
    {
        $this->assertSame('<p>فيتامين سي – ٥٠٠ ملغ</p>', HtmlSanitizer::clean('<p>فيتامين سي – ٥٠٠ ملغ</p>'));
    }

    public function test_empty_and_null_input_give_an_empty_string(): void
    {
        $this->assertSame('', HtmlSanitizer::clean(null));
        $this->assertSame('', HtmlSanitizer::clean(''));
        $this->assertSame('', HtmlSanitizer::clean('   '));
    }

    public function test_cleaning_is_idempotent(): void
    {
        $once = HtmlSanitizer::clean('<p onclick="x()">Hi <a href="javascript:1">a</a> <b>b</b></p><script>1</script>');

        $this->assertSame($once, HtmlSanitizer::clean($once));
    }
}
