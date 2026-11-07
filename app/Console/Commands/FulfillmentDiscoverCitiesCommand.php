<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilMigrated;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\AddressCity;
use App\Services\Fulfillment\Providers\TorodPoint\TorodPointAdapter;
use App\Services\Fulfillment\ProviderCityMappingService;
use App\Services\Fulfillment\ProviderCityReview;
use App\Services\Fulfillment\ProviderConnectionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class FulfillmentDiscoverCitiesCommand extends Command
{
    use SkipsUntilMigrated;

    private const MAX_CITIES = 100;

    protected $signature = 'address:discover-provider-cities
        {provider : torod_point}
        {--city=* : the id of one of the store\'s cities to look for (repeatable)}
        {--held : look for the cities the held orders are waiting on}
        {--apply : keep what is found and propose the exact matches (without it the command only asks the provider and reports)}';

    protected $description = 'Ask a provider which of its cities are named like ours, and propose the exact matches for a person to approve';

    public function handle(TorodPointAdapter $adapter, ProviderCityMappingService $mappings, ProviderCityReview $review, ProviderConnectionManager $connections): int
    {
        if (ProviderCode::tryFrom((string) $this->argument('provider')) !== ProviderCode::TorodPoint) {
            $this->error('Only torod_point has a city list that can be searched.');

            return self::FAILURE;
        }
        if ($this->notMigratedYet()) {
            return self::SUCCESS;
        }
        if (!config('fulfillment.enabled') || !$connections->canCall(ProviderCode::TorodPoint)) {
            $this->line('Fulfillment integrations or this provider are switched off; nothing to do.');

            return self::SUCCESS;
        }
        if (!$this->addressMigrated($mappings)) {
            return self::SUCCESS;
        }

        $cities = $this->cities($review);
        if ($cities->isEmpty()) {
            $this->line('There is no city to look for. Name one with --city=<id>, or use --held.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $this->line($apply ? 'Applying: found cities are kept and exact matches proposed.' : 'Dry run: the provider is asked; no city and no mapping is stored (each request is logged, like every call to the provider). Add --apply to keep what is found.');

        $failed = false;
        foreach ($cities as $city) {
            $result = $adapter->discoverCities(city: $city, apply: $apply);
            $failed = $failed || !$result['ok'];
            $this->line($result['ok']
                ? sprintf('#%d %s: %d found, exact name: [%s]%s', $city->id, $city->name_en, $result['found'], implode(', ', $result['matches']), $apply ? sprintf(', %d proposed', $result['proposed']) : '')
                : sprintf('#%d %s: %s', $city->id, $city->name_en, $result['error']));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function addressMigrated(ProviderCityMappingService $mappings): bool
    {
        if ($mappings->available()) {
            return true;
        }

        $this->line('The address migrations have not been run yet; nothing to do.');

        return false;
    }

    /** @return Collection<int, AddressCity> */
    private function cities(ProviderCityReview $review): Collection
    {
        $ids = array_map('intval', (array) $this->option('city'));
        if ($this->option('held')) {
            $ids = [...$ids, ...$review->heldCityIds(ProviderCode::TorodPoint->value)];
        }

        $ids = array_slice(array_values(array_unique(array_filter($ids))), 0, self::MAX_CITIES);

        return AddressCity::query()->with('region')->whereIn('id', $ids)->orderBy('id')->get();
    }
}
