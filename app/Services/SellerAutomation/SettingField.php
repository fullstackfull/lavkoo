<?php

namespace App\Services\SellerAutomation;

/**
 * The form control a setting needs, read out of the setting's own validation rule.
 *
 * A rule builder has to render a field per setting, and the obvious way to do that is a second list
 * somewhere describing each one. That list drifts: a trigger whose threshold gains an upper bound
 * keeps offering an unbounded number field until somebody remembers to change the other place, and
 * the seller finds out by being refused after they press save.
 *
 * So the descriptor is derived from the rule string that will actually judge the value. A field
 * cannot offer what the validator will reject, because there is only one statement of what is
 * allowed.
 *
 * Labels are keyed by convention (`automation_field_{key}`) rather than declared, which keeps the
 * translation in the copy file where every other string lives.
 */
class SettingField
{
    /**
     * @param  array<string, mixed>  $rules  a trigger's or an action's `rules()`
     * @return array<int, array{key: string, type: string, required: bool, min: ?float, max: ?float, options: array<int, string>, label: string}>
     */
    public static function describe(array $rules): array
    {
        $fields = [];

        foreach ($rules as $key => $rule) {
            $parts = is_array($rule) ? $rule : explode('|', (string) $rule);
            $parts = array_map(fn ($part) => is_string($part) ? trim($part) : '', $parts);

            $fields[] = [
                'key' => $key,
                'type' => self::type($parts),
                'required' => in_array('required', $parts, true),
                'min' => self::bound($parts, 'min') ?? self::exclusiveBound($parts, 'gt'),
                'max' => self::bound($parts, 'max') ?? self::exclusiveBound($parts, 'lt'),
                'options' => self::options($parts),
                'label' => 'automation_field_' . $key,
            ];
        }

        return $fields;
    }

    /** @param array<int, string> $parts */
    private static function type(array $parts): string
    {
        return match (true) {
            self::options($parts) !== [] => 'choice',
            // A list of ids the seller picks from a list of real records, not a number they type.
            in_array('array', $parts, true) => 'id_list',
            in_array('integer', $parts, true) => 'integer',
            in_array('numeric', $parts, true) => 'decimal',
            in_array('boolean', $parts, true) => 'toggle',
            default => 'text',
        };
    }

    /** @param array<int, string> $parts */
    private static function options(array $parts): array
    {
        foreach ($parts as $part) {
            if (str_starts_with($part, 'in:')) {
                return array_values(array_filter(explode(',', substr($part, 3))));
            }
        }

        return [];
    }

    /**
     * `min:` and `max:` mean length on a string and value on a number; only the numeric reading is
     * useful to a form control, so a text field reports no bounds rather than a misleading one.
     *
     * @param  array<int, string>  $parts
     */
    private static function bound(array $parts, string $name): ?float
    {
        if (in_array(self::type($parts), ['text', 'choice'], true)) {
            return null;
        }

        foreach ($parts as $part) {
            if (str_starts_with($part, $name . ':')) {
                return (float) substr($part, strlen($name) + 1);
            }
        }

        return null;
    }

    /**
     * `gt:0` is "greater than zero", which no number input can express. Reported as the smallest
     * value the field's own precision can hold above it, so the control refuses what the validator
     * would refuse and nothing else.
     *
     * @param  array<int, string>  $parts
     */
    private static function exclusiveBound(array $parts, string $name): ?float
    {
        foreach ($parts as $part) {
            if (!str_starts_with($part, $name . ':')) {
                continue;
            }

            $value = (float) substr($part, strlen($name) + 1);
            $step = in_array('integer', $parts, true) ? 1 : 0.01;

            return $name === 'gt' ? $value + $step : $value - $step;
        }

        return null;
    }
}
