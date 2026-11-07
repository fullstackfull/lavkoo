<?php

namespace Tests\Support;

use App\Models\AddressCity;
use App\Models\ProviderCity;
use App\Services\Address\AddressSchema;
use App\Services\Address\AddressSnapshot;
use App\Services\Fulfillment\Support\ArabicText;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Fixtures for the Torod Point city mapping: a provider directory with the cases that matter (Riyadh listed twice, under two region
 * ids; a name shared across regions; a region we cannot place; a city shared by two of ours), the store's own catalog from
 * BuildsAddressSchema, order snapshots built the way the checkout builds them, and a scriptable fake of the Lastmile city list.
 */
trait BuildsTorodDirectory
{
    protected const LASTMILE_CITIES = 'https://apisv2.logestechs.com/api/addresses/cities*';

    /** @var array<string, int> the store's cities by handle (and region_RUH / region_MAK / region_EAS) */
    protected array $ids = [];

    /** @var array{0: list<array<string, mixed>>, 1: array<string, mixed>}|null what the faked city list answers with now */
    private ?array $lastmileScript = null;

    protected function createAuditLogTable(): void
    {
        Schema::create('audit_logs', static function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    /** The address migrations on top of the fulfillment ones, the store's catalog, and Torod's cached directory. */
    protected function buildCityMappingWorld(): void
    {
        $this->createLegacyAddressTables();
        $this->runAddressMigrations();
        $this->createAuditLogTable();
        $this->ids = $this->seedAddressCatalog();
        $this->seedTorodDirectory();
        AddressSchema::flush();
    }

    protected function seedTorodDirectory(): void
    {
        ProviderCity::query()->delete();

        $this->torodCity('36189', 'الرياض', 'Riyadh', '1204', 'Riyadh', 'الرياض', true, 'RYH');
        $this->torodCity('230194', 'الرياض', 'Riyadh', '1265', 'Riyadh', 'الرياض', true, 'RYH');
        $this->torodCity('41126', 'جدة', 'Jeddah', '1336', 'Makkah', 'مكة المكرمة', true, 'JED');
        $this->torodCity('41127', 'الدمام', 'Dammam', '1337', 'Eastern Province', 'المنطقة الشرقية', false, 'DMM');
        $this->torodCity('50001', 'الخبر', 'Khobar', '1337');
        $this->torodCity('50002', 'الهفوف', 'Hofuf', '1337', 'Eastern Province', 'المنطقة الشرقية', true, 'HOF');
        $this->torodCity('50003', 'الهفوف', 'Hofuf', '1204', 'Riyadh', 'الرياض', true, 'HOF');
        $this->torodCity('50004', 'الطائف', 'Taif', '1999', 'Region 77');
        $this->torodCity('50005', 'أم سليم', 'Umm Sulaym', '1204', 'Riyadh', 'الرياض');
        $this->torodCity('50006', 'مكة المكرمة', 'Makkah', '1336', 'Makkah', 'مكة المكرمة', true, 'MAK');
    }

    protected function torodCity(
        string $id,
        string $arabic,
        string $english,
        string $regionId,
        ?string $regionEnglish = null,
        ?string $regionArabic = null,
        ?bool $selected = null,
        ?string $prefix = null,
        string $provider = 'torod_point',
    ): ProviderCity {
        return ProviderCity::create([
            'provider' => $provider, 'external_id' => $id, 'name_ar' => $arabic, 'name_en' => $english, 'region_external_id' => $regionId,
            'normalized_ar' => ArabicText::normalize($arabic), 'normalized_en' => ArabicText::normalize($english),
            'region_name_ar' => $regionArabic, 'region_name_en' => $regionEnglish, 'prefix' => $prefix, 'is_selected' => $selected,
            'raw' => ['id' => (int) $id, 'name' => $english, 'arabicName' => $arabic, 'englishName' => $english, 'regionId' => (int) $regionId],
            'synced_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    /** @return array<string, mixed> an order snapshot built the way the checkout builds it, for a city of the store's catalog */
    protected function unifiedSnapshot(string $handle, array $overrides = []): array
    {
        $city = AddressCity::query()->findOrFail($this->ids[$handle]);

        return AddressSnapshot::build($overrides + [
            'contact_person_name' => 'Sara Alharbi', 'phone' => '0551234567', 'address' => 'King Fahd Rd 12', 'district' => 'Al Olaya',
            'city' => $city->name_ar, 'state' => null, 'country' => 'Saudi Arabia', 'zip' => null, 'email' => 'sara@example.test',
            'city_id' => $city->id, 'region_id' => $city->region_id, 'national_address' => 'RAGI2929',
        ]);
    }

    protected function snapshotJson(string $handle, array $overrides = []): string
    {
        return (string) json_encode($this->unifiedSnapshot($handle, $overrides), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Fakes the Lastmile city list as a server that answers per page, so the sync's reading can be scripted. Calling it again
     * changes what the same fake answers with (Laravel keeps the first stub that matches, so a second Http::fake would never be asked).
     *
     * @param list<array<string, mixed>> $directory every city the provider has
     * @param array{
     *     first?: int|null,      cities in the response that carries no page (null: all of them)
     *     size?: int|null,       cities per page whatever pageSize asks (null: pageSize is honoured)
     *     total?: bool,          whether responses state totalElements
     *     pages?: bool,          whether the server pages at all (false: every request gets the first response)
     *     fail_page?: int|null,  the page that answers 500
     * } $behaviour
     */
    protected function fakeLastmileList(array $directory, array $behaviour = []): void
    {
        $registered = $this->lastmileScript !== null;
        $this->lastmileScript = [$directory, $behaviour + ['first' => null, 'size' => null, 'total' => false, 'pages' => true, 'fail_page' => null]];
        if ($registered) {
            return;
        }

        Http::fake([self::LASTMILE_CITIES => function (Request $request) {
            [$directory, $behaviour] = $this->lastmileScript;
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = isset($query['page']) ? (int) $query['page'] : null;
            $envelope = $behaviour['total'] ? ['totalElements' => count($directory)] : [];

            if ($page === null || !$behaviour['pages']) {
                return Http::response(['data' => array_slice($directory, 0, $behaviour['first'] ?? count($directory))] + $envelope);
            }
            if ($page === $behaviour['fail_page']) {
                return Http::response(['message' => 'Server error'], 500);
            }

            $size = $behaviour['size'] ?? (int) ($query['pageSize'] ?? 10);

            return Http::response(['data' => array_slice($directory, ($page - 1) * $size, $size)] + $envelope);
        }]);
    }

    /** @return list<array<string, mixed>> $count Lastmile city records, like the ones a real list holds */
    protected function lastmileDirectory(int $count, int $firstId = 1000): array
    {
        $cities = [];
        for ($index = 0; $index < $count; $index++) {
            $id = $firstId + $index;
            $cities[] = [
                'id' => $id, 'name' => "City {$id}", 'arabicName' => "مدينة {$id}", 'englishName' => "City {$id}", 'regionId' => 1300 + $index % 4,
                'regionName' => 'Region ' . (1300 + $index % 4), 'arabicRegionName' => 'منطقة ' . (1300 + $index % 4), 'isSelected' => $index % 2 === 0,
                'isAddedByUser' => false, 'prefix' => 'C' . ($index % 7), 'hubId' => 0, 'companyId' => 714,
            ];
        }

        return $cities;
    }
}
