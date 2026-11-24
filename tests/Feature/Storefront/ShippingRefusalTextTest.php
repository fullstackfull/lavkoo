<?php

namespace Tests\Feature\Storefront;

use App\Services\Shipping\ShippingRefusal;
use Tests\TestCase;

/**
 * Every reason the shipping engine can refuse with reads in Arabic and in English (H1-04, H1-06).
 *
 * The engine answers in translation keys rather than sentences, which is what lets the website, the three REST paths, the POS and both
 * apps show the same decision in the customer's own language -- the REST paths translate each reason before they answer, the website
 * translates them in its own view. A key with no text behind it turns that into the key itself on a customer's screen, which is how a
 * shopper is told `shipping_product_may_not_be_exported` and understands nothing.
 *
 * So the keys are read out of the engine's own source rather than listed by hand: a reason added next year is caught by this test on the
 * day it is added, not by a customer. The inventory count is asserted for the same reason the Saudi literal inventory is -- a new key has
 * to be noticed by somebody, not silently absorbed.
 */
class ShippingRefusalTextTest extends TestCase
{
    /** Arabic for the Gulf (`ae`) and for Saudi Arabia (`sa`) are separate files on this platform, and a refusal has to exist in both. */
    private const LANGUAGES = ['en', 'sa', 'ae'];

    private const HOW_MANY_REASONS = 10;

    /** @return list<string> */
    private function reasonKeys(): array
    {
        return ShippingRefusal::all();
    }

    /**
     * The inventory, for the same reason the Saudi literal inventory has one: a reason added to the vocabulary has to be noticed by
     * somebody. The count failing is the prompt to check that the new one reads in both languages, then to raise the number.
     */
    public function test_the_vocabulary_is_the_size_this_test_knows_about(): void
    {
        $keys = $this->reasonKeys();

        $this->assertCount(
            self::HOW_MANY_REASONS,
            $keys,
            "the shipping engine refuses with these reasons:\n" . implode("\n", $keys),
        );
    }

    /** And nothing refuses with a key that is not in the vocabulary: a literal in a service is a reason nobody can enumerate. */
    public function test_no_service_refuses_with_a_key_outside_the_vocabulary(): void
    {
        $known = $this->reasonKeys();
        $columns = ['shipping_address_data', 'shipping_kind', 'shipping_decision'];
        $files = glob(app_path('Services/Shipping/*.php')) ?: [];

        $this->assertNotSame([], $files, 'there is a shipping engine to scan');

        foreach ($files as $file) {
            if (basename($file) === 'ShippingRefusal.php') {
                continue;
            }

            preg_match_all("/'(shipping_[a-z0-9_]+)'/", (string) file_get_contents($file), $matches);

            foreach (array_diff($matches[1], $columns) as $literal) {
                $this->assertContains($literal, $known, basename($file) . " uses '{$literal}' as a literal; name it in ShippingRefusal");
            }
        }
    }

    public function test_every_refusal_reads_in_arabic_and_english(): void
    {
        $keys = $this->reasonKeys();
        $this->assertNotSame([], $keys, 'the engine answers in translation keys, so there is something to read');

        foreach (self::LANGUAGES as $language) {
            $messages = require resource_path("lang/{$language}/new-messages.php");

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $messages, "{$key} has no {$language} text, so a customer would be shown the key");
                $this->assertNotSame('', trim((string) $messages[$key]), "{$key} has empty {$language} text");
                $this->assertNotSame($key, trim((string) $messages[$key]), "{$key} is its own {$language} text");
            }
        }
    }

    /** And the Arabic is Arabic: a key copied from the English file reads as English on an Arabic storefront. */
    public function test_the_arabic_text_is_in_arabic(): void
    {
        foreach (['sa', 'ae'] as $language) {
            $messages = require resource_path("lang/{$language}/new-messages.php");

            foreach ($this->reasonKeys() as $key) {
                $this->assertMatchesRegularExpression(
                    '/\p{Arabic}/u',
                    (string) ($messages[$key] ?? ''),
                    "{$key} has no Arabic letters in its {$language} text",
                );
            }
        }
    }
}
