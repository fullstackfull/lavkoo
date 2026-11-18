<?php

namespace Tests\Feature\Storefront;

use App\Services\Theme\Channel;
use App\Services\Theme\ComponentCapabilityRegistry;
use App\Services\Theme\SectionRegistry;
use ReflectionClass;
use Tests\Support\BuildsThemeWorld;
use Tests\TestCase;

/**
 * GCC F-10: one section library and one component capability registry serve every storefront. What a merchant can compose and what the app can draw are the same list whichever storefront asks,
 * and a section type is never copied for a country: no type, block type, capability or section file carries a storefront suffix, so a storefront differs from another in its catalogue and its theme,
 * never in the sections it can use.
 */
class StorefrontThemeSectionLibraryTest extends TestCase
{
    use BuildsThemeWorld;

    /** A name that ends in a country the platform sells in, joined by an underscore or a hyphen. */
    private const NAMES_A_COUNTRY = '/(_|-)(sa|ae|kw|qa|bh|om)$/i';

    private const COUNTRIES = ['SA', 'AE', 'KW', 'QA', 'BH', 'OM'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->openEveryStorefront();
    }

    /** @return array<string, mixed> everything the two registries answer, asked of fresh instances while the request is served from one storefront */
    private function whatTheLibraryAnswersTo(string $country): array
    {
        $this->serveRequestFromStorefront($country);
        $sections = new SectionRegistry();
        $capabilities = new ComponentCapabilityRegistry();
        $types = array_keys($sections->types());
        $channels = [Channel::WEB, Channel::CUSTOMER_APP, Channel::VENDOR_APP];

        return [
            'types' => $sections->types(),
            'blocks' => $sections->blockTypes(),
            'catalogue' => $sections->catalogue(),
            'catalogue of the home page' => $sections->catalogue(page: 'home'),
            'manifest' => $capabilities->manifest(),
            'what each channel can draw' => array_map(static fn (string $channel): array => $capabilities->servableToChannel(channel: $channel), array_combine($channels, $channels)),
            'channels of each type' => array_map(static fn (string $type): array => $capabilities->channelsFor(type: $type), array_combine($types, $types)),
            'requirement of each type' => array_map(static fn (string $type): ?array => $capabilities->requirementFor(type: $type), array_combine($types, $types)),
            'why each type is withheld' => array_map(static fn (string $type): ?string => $capabilities->exclusionReason(type: $type), array_combine($types, $types)),
        ];
    }

    /** @return array<string, list<string>> every list of names the library keeps, under what it calls them */
    private function namesInTheLibrary(): array
    {
        $sections = new SectionRegistry();
        $capabilities = new ComponentCapabilityRegistry();
        $names = [
            'section types' => array_keys($sections->types()),
            'block types' => array_keys($sections->blockTypes()),
            'component manifest' => array_column($capabilities->manifest(), 'type'),
            'component types the app can draw' => $capabilities->servableToChannel(channel: Channel::CUSTOMER_APP),
        ];

        foreach ([SectionRegistry::class, ComponentCapabilityRegistry::class] as $class) {
            foreach ((new ReflectionClass($class))->getConstants() as $constant => $value) {
                if (is_array($value) && !array_is_list($value)) {
                    $names[$class . '::' . $constant] = array_keys($value);
                }
            }
        }

        return $names;
    }

    public function test_every_storefront_is_offered_the_same_sections_and_the_same_app_capabilities(): void
    {
        $asked = array_combine(self::COUNTRIES, array_map(fn (string $country): array => $this->whatTheLibraryAnswersTo(country: $country), self::COUNTRIES));

        $this->assertGreaterThan(30, count($asked['SA']['types']), 'the library has something to offer');
        $this->assertNotSame([], $asked['SA']['what each channel can draw'][Channel::CUSTOMER_APP], 'and the app something to draw');
        foreach (self::COUNTRIES as $country) {
            $this->assertEquals($asked['SA'], $asked[$country], "{$country} is offered another library than SA.");
        }
    }

    public function test_the_library_is_the_same_while_storefronts_are_off(): void
    {
        $withStorefrontsOn = $this->whatTheLibraryAnswersTo(country: 'AE');

        config(['gcc.storefronts.enabled' => false]);

        $this->assertEquals($withStorefrontsOn, $this->whatTheLibraryAnswersTo(country: 'AE'));
    }

    public function test_no_name_in_the_library_carries_a_country(): void
    {
        foreach ($this->namesInTheLibrary() as $list => $names) {
            $this->assertNotSame([], $names, "{$list} holds nothing, so the check below would prove nothing.");
            $this->assertSame([], array_values(array_filter($names, static fn (string $name): bool => preg_match(self::NAMES_A_COUNTRY, $name) === 1)), "{$list} holds a name that ends in a country.");
        }
    }

    public function test_no_section_file_is_a_copy_of_a_type_for_a_country(): void
    {
        $types = array_keys((new SectionRegistry())->types());
        $files = array_map(static fn (string $path): string => basename($path, '.blade.php'), glob(base_path('resources/views/theme-sections/types/*.blade.php')));

        $this->assertNotSame([], $files);
        $this->assertSame([], array_values(array_diff($files, $types)), 'a section file that is not the file of a type of the library');
        $this->assertSame([], array_values(array_filter($files, static fn (string $name): bool => preg_match(self::NAMES_A_COUNTRY, $name) === 1)));
    }

    public function test_the_check_sees_a_name_that_ends_in_a_country(): void
    {
        foreach (['hero_banner_ae', 'product_slider-sa', 'spacer_KW', 'faq_qa', 'brand_slider_bh', 'bundle-om'] as $copy) {
            $this->assertSame(1, preg_match(self::NAMES_A_COUNTRY, $copy), $copy);
        }
        foreach (['hero_banner', 'custom_html', 'category_grid', 'banner_mosaic', 'usp_strip', 'sale', 'omega_set'] as $type) {
            $this->assertSame(0, preg_match(self::NAMES_A_COUNTRY, $type), $type);
        }
    }
}
