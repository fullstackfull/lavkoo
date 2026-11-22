<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The address edit page prints the coordinates of the saved address into a script, in the place of a number. `{{ }}` escapes for HTML and not
 * for script, and the address form stores whatever latitude and longitude it is sent, so `1};alert(document.domain);var z={a:1` ran on the
 * page of the customer whose address it was (a customer who was made to sign in, or who was tricked into saving it). The add page prints the
 * shop's default location the same way, a setting the staff write without any check that it is a number.
 */
class AddressMapScriptTest extends TestCase
{
    private const VIEW = 'resources/themes/default/web-views/users-profile/account-address-edit.blade.php';

    private const ADD_VIEW = 'resources/themes/default/web-views/users-profile/account-address.blade.php';

    /** @return list<string> the lines of the page that print a coordinate into the script */
    private function coordinateLines(): array
    {
        preg_match_all('/^.*\blat:\s*\{\{.*$/m', (string) file_get_contents(base_path(self::VIEW)), $matches);

        return $matches[0];
    }

    /** @return array<string, array{0: mixed, 1: mixed, 2: string}> */
    public static function coordinates(): array
    {
        return [
            'a script in the latitude' => ['1};alert(document.domain);var z={a:1', '2', 'lat: 1, lng: 2'],
            'a script in the longitude' => ['1', '2});alert(1);({a:2', 'lat: 1, lng: 2'],
            'a quote and a tag' => ['1"</script><script>alert(1)</script>', '2', 'lat: 1, lng: 2'],
            'real coordinates' => ['24.7136', '46.6753', 'lat: 24.7136, lng: 46.6753'],
            'negative coordinates' => ['-33.8688', '151.2195', 'lat: -33.8688, lng: 151.2195'],
            'no saved coordinates' => [null, null, 'lat: -33.8688, lng: 151.2195'],
        ];
    }

    #[DataProvider('coordinates')]
    public function test_a_coordinate_is_printed_as_a_number_and_nothing_else(mixed $latitude, mixed $longitude, string $printed): void
    {
        $lines = $this->coordinateLines();
        $this->assertCount(2, $lines, 'the page prints the coordinates in two places');

        foreach ($lines as $line) {
            $rendered = Blade::render($line, ['shipping_latitude' => $latitude, 'shipping_longitude' => $longitude]);

            $this->assertStringContainsString($printed, $rendered);
            $this->assertStringNotContainsString('alert', $rendered);
            $this->assertStringNotContainsString('<script', $rendered);
        }
    }

    /** @return list<string> the lines of the add page that print a coordinate into the script */
    private function defaultLocationLines(): array
    {
        preg_match_all('/^.*\b(?:lat|lng):\s*\{\{.*$/m', (string) file_get_contents(base_path(self::ADD_VIEW)), $matches);

        return $matches[0];
    }

    /** @return array<string, array{0: mixed, 1: mixed, 2: string}> */
    public static function defaultLocations(): array
    {
        return [
            'a script in the latitude' => ['1};alert(document.domain);var z={a:1', '2', 'lat: 1, lng: 2'],
            'a script in the longitude' => ['1', '2});alert(1);({a:2', 'lat: 1, lng: 2'],
            'a quote and a tag' => ['1"</script><script>alert(1)</script>', '2', 'lat: 1, lng: 2'],
            'real coordinates' => ['24.7136', '46.6753', 'lat: 24.7136, lng: 46.6753'],
            'negative coordinates' => ['-33.8688', '151.2195', 'lat: -33.8688, lng: 151.2195'],
            'no default location' => [null, null, 'lat: -33.8688, lng: 151.2195'],
        ];
    }

    #[DataProvider('defaultLocations')]
    public function test_the_default_location_of_the_add_page_is_printed_as_a_number_and_nothing_else(mixed $latitude, mixed $longitude, string $printed): void
    {
        $lines = $this->defaultLocationLines();
        $this->assertCount(2, $lines, 'the page prints the default location in one place, two lines');
        $location = $latitude === null ? null : ['lat' => $latitude, 'lng' => $longitude];

        $rendered = trim((string) preg_replace('/\s+/', ' ', Blade::render(implode("\n", $lines), ['default_location' => $location])));

        $this->assertSame($printed, $rendered);
        $this->assertStringNotContainsString('alert', $rendered);
        $this->assertStringNotContainsString('<script', $rendered);
    }
}
