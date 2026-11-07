<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * What a person may type about how a listing takes its part of a pool, and about a pool's own reserve and buffer. The ranges are checked here and nowhere else: the
 * application's database connection is not strict, so a percentage of 250 or a negative number of units would be stored as it came (or cut to fit) and later divided as if it
 * were meant. Everything that is accepted is returned in the shape it is stored: a mode that needs no value has none, a mode that needs one has it.
 */
class StockShareValidator
{
    private const MAX_PRIORITY = 1000;

    /**
     * @param array<string, mixed> $input stock_share_mode, stock_share_value, stock_priority
     * @return array{stock_share_mode: string|null, stock_share_value: int|null, stock_priority: int|null}
     *
     * @throws ValidationException
     */
    public function share(array $input, string $errorBag = 'default'): array
    {
        $input = $this->blanksAreNull($input, ['stock_share_mode', 'stock_share_value', 'stock_priority']);
        $mode = $input['stock_share_mode'] ?? null;
        $valueIsNeeded = in_array($mode, [StockShare::FIXED, StockShare::PERCENT], true);

        $validated = Validator::make($input, [
            'stock_share_mode' => ['nullable', 'in:' . implode(',', StockShare::MODES)],
            'stock_share_value' => [$valueIsNeeded ? 'required' : 'nullable', 'integer', 'min:0', 'max:' . ($mode === StockShare::PERCENT ? 100 : StockShare::MAX_UNITS)],
            'stock_priority' => ['nullable', 'integer', 'min:0', 'max:' . self::MAX_PRIORITY],
        ])->validateWithBag($errorBag);

        return [
            'stock_share_mode' => $mode,
            'stock_share_value' => $valueIsNeeded ? (int) $validated['stock_share_value'] : null,
            'stock_priority' => isset($validated['stock_priority']) ? (int) $validated['stock_priority'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $input storefront_reserve_mode, storefront_reserve_value, safety_buffer
     * @return array{storefront_reserve_mode: string|null, storefront_reserve_value: int|null, safety_buffer: int|null}
     *
     * @throws ValidationException
     */
    public function pool(array $input, string $errorBag = 'default'): array
    {
        $input = $this->blanksAreNull($input, ['storefront_reserve_mode', 'storefront_reserve_value', 'safety_buffer']);
        $mode = $input['storefront_reserve_mode'] ?? null;

        $validated = Validator::make($input, [
            'storefront_reserve_mode' => ['nullable', 'in:' . StorefrontReserve::FIXED . ',' . StorefrontReserve::PERCENT],
            'storefront_reserve_value' => [$mode === null ? 'nullable' : 'required', 'integer', 'min:0', 'max:' . ($mode === StorefrontReserve::PERCENT ? 100 : StockShare::MAX_UNITS)],
            'safety_buffer' => ['nullable', 'integer', 'min:0', 'max:' . StockShare::MAX_UNITS],
        ])->validateWithBag($errorBag);

        return [
            'storefront_reserve_mode' => $mode,
            'storefront_reserve_value' => $mode === null ? null : (int) $validated['storefront_reserve_value'],
            'safety_buffer' => isset($validated['safety_buffer']) ? (int) $validated['safety_buffer'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private function blanksAreNull(array $input, array $keys): array
    {
        foreach ($keys as $key) {
            $input[$key] = isset($input[$key]) && trim((string) $input[$key]) !== '' ? $input[$key] : null;
        }

        return $input;
    }
}
