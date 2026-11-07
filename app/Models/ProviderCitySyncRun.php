<?php

namespace App\Models;

use App\Enums\Fulfillment\CitySyncVerdict;
use Illuminate\Database\Eloquent\Model;

/** One attempt to refresh a provider's city list, and whether it was complete. */
class ProviderCitySyncRun extends Model
{
    public const UPDATED_AT = null;

    /** The weakest evidence of a complete list: only how many cities came back, with nothing from the provider saying that is all. */
    public const EVIDENCE_HEURISTIC = 'distinct_floor';

    protected $table = 'provider_city_sync_runs';

    protected $guarded = [];

    protected $casts = [
        'request' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** Was the list shown to be complete by what the provider said or by paging to its end, and not only by how many cities came back? */
    public function completenessProven(): bool
    {
        $evidence = $this->request['evidence'] ?? null;

        return $this->verdict === CitySyncVerdict::Full->value && is_string($evidence) && !in_array($evidence, ['', 'none', self::EVIDENCE_HEURISTIC], true);
    }
}
