<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An A/B test over one composed section (Phase 3.5).
 *
 * Control is the published section untouched; each variant is a settings patch over it. Stable
 * assignment is a hash, not a stored row: the same shopper lands in the same bucket on every
 * request without anything to persist, expire or leak (§47).
 */
class ExperienceExperiment extends Model
{
    public const STATUS_DRAFT   = 'draft';
    public const STATUS_RUNNING = 'running';
    public const STATUS_STOPPED = 'stopped';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_RUNNING, self::STATUS_STOPPED];

    protected $fillable = ['name', 'key', 'status', 'page', 'section_uuid', 'variants'];

    protected $casts = ['variants' => 'array'];

    /** @return array<int, array<string, mixed>> */
    public function variantRows(): array
    {
        return array_values(array_filter(
            is_array($this->variants) ? $this->variants : [],
            'is_array',
        ));
    }

    /**
     * The variant this subject sees — or null for control (§47: deterministic, per experiment,
     * nothing stored). A subjectless viewer sees control: "we could not identify you" must never
     * mean "you got a random page".
     *
     * @return array<string, mixed>|null
     */
    public function variantFor(?string $subject): ?array
    {
        if ($subject === null || $subject === '') {
            return null;
        }

        $bucket = hexdec(substr(hash('crc32b', $this->key . ':' . $subject), 0, 8)) % 100;
        $floor = 0;

        // Walked in KEY order, not storage order: assignment must survive the variants JSON
        // being rewritten, reordered, or hand-repaired. A shopper's bucket is a function of the
        // experiment key, their subject, and the variant keys — never of row position.
        $rows = $this->variantRows();
        usort($rows, static fn (array $a, array $b) => strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? '')));

        foreach ($rows as $variant) {
            $weight = max(0, min(100, (int) ($variant['weight'] ?? 0)));

            if ($bucket < $floor + $weight) {
                return $variant;
            }

            $floor += $weight;
        }

        return null; // the remainder of the split is control
    }

    public static function keyFor(string $name): string
    {
        return Str::slug(Str::limit(trim($name), 56, ''));
    }
}
