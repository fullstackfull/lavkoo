<?php

namespace Tests\Support\Queue;

use App\Jobs\Concerns\CarriesItsStorefront;
use App\Services\Storefront\StorefrontContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A queued job that does nothing but write down which storefront was bound while it ran, so that a test can read what a worker gave it.
 *
 * It stands in for the real appliers of {@see CarriesItsStorefront} (RunSellerBulkJob, and whichever job carries it next) because what has
 * to be proved is the carrying, not what any one job then does with it. The answer is kept in a static, which is where a worker's own
 * process would keep it: the job that runs is a copy rebuilt from the payload, so an instance property would be unreadable from the test.
 */
class RecordsTheStorefrontItRanIn implements ShouldQueue
{
    use CarriesItsStorefront, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var list<array{job: string, country: string|null, bound: bool}> what each run of a job of this kind saw, in order */
    public static array $runs = [];

    public function __construct(public readonly string $name = 'job')
    {
        $this->carryTheCurrentStorefront();
    }

    public static function forget(): void
    {
        self::$runs = [];
    }

    /** @return list<string|null> the storefront each run was served in, in the order the runs happened */
    public static function storefrontsSeen(): array
    {
        return array_map(static fn (array $run): ?string => $run['country'], self::$runs);
    }

    public function handle(): void
    {
        $bound = app()->bound(StorefrontContext::class);

        self::$runs[] = [
            'job' => $this->name,
            'country' => $bound ? app(StorefrontContext::class)->country : null,
            'bound' => $bound,
        ];
    }
}
