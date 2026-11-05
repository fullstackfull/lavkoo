<?php

namespace Tests\Feature\Fulfillment;

use App\Services\Fulfillment\Support\SafeToast;
use PHPUnit\Framework\TestCase;

/**
 * The toast library prints its message as HTML inside a script block. Whatever a provider's error body or a supplier's
 * product name contains must reach the administrator as text.
 */
class SafeToastTest extends TestCase
{
    public function test_our_text_is_kept_and_the_untrusted_detail_is_escaped(): void
    {
        $shown = SafeToast::compose('Connection test failed', '<img src=x onerror=alert(1)> & "quotes" </script><script>alert(2)</script>');

        $this->assertStringStartsWith('Connection test failed: ', $shown);
        foreach (['<img', '<script', '</script', '"quotes"'] as $markup) {
            $this->assertStringNotContainsString($markup, $shown);
        }
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $shown);
    }

    public function test_a_detail_alone_is_escaped_and_nothing_is_added_around_an_empty_detail(): void
    {
        $this->assertSame('&lt;b&gt;x&lt;/b&gt;', SafeToast::compose('', '<b>x</b>'));
        $this->assertSame('Sync completed', SafeToast::compose('Sync completed', null));
        $this->assertSame('Sync completed', SafeToast::compose('Sync completed', ''));
    }

    /**
     * Every toast in the fulfillment admin either shows one of our own translations or goes through SafeToast. A raw
     * toast that carries a variable is how provider text reaches the browser unescaped.
     */
    public function test_no_fulfillment_admin_toast_carries_unescaped_dynamic_text(): void
    {
        $offenders = [];
        foreach (glob(dirname(__DIR__, 3) . '/app/Http/Controllers/Admin/Fulfillment/*.php') as $file) {
            foreach (file($file) as $number => $line) {
                if (preg_match_all('/ToastMagic::\w+\(([^;]*)/', $line, $matches) === 0) {
                    continue;
                }
                foreach ($matches[1] as $argument) {
                    $onlyOurTranslation = preg_match('/^translate\(\'[a-z_]+\'\)\)?[,)]?\s*[:)]?\s*$/', trim($argument)) === 1
                        || preg_match('/^translate\(\$key\)\)/', trim($argument)) === 1
                        || preg_match('/^translate\(\'[a-z_]+\'\)\)\s*:/', trim($argument)) === 1
                        || preg_match('/^translate\(\$result->outcome === /', trim($argument)) === 1;
                    if (!$onlyOurTranslation) {
                        $offenders[] = basename($file) . ':' . ($number + 1) . ' ' . trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'These toasts print dynamic text without escaping it; use SafeToast.');
    }
}
