<?php

namespace App\Services\SalesChannels\Shipments;

use InvalidArgumentException;

/**
 * One way of cutting one shipment package in two or more, in the shape the channel's own endpoint takes.
 *
 * Trendyol publishes **four** split endpoints for the international (Gulf) set, with four different bodies, and the choice
 * between them is not a style question: each one can express a cut the others cannot.
 *
 *  - `lines`           `POST …/shipment-packages/{id}/split`           `{"orderLineIds":[…]}`
 *                      Whole lines into their own package; whatever is left over Trendyol puts in another new package.
 *  - `groups`          `POST …/shipment-packages/{id}/multi-split`     `{"splitGroups":[{"orderLineIds":[…]},…]}`
 *                      Several packages in one call. Trendyol states the remaining lines get an automatic final package, and
 *                      states that **all** of a package's lines must not be sent -- so a plan that names every line is refused
 *                      here rather than at Trendyol.
 *  - `line_quantities` `POST …/shipment-packages/{id}/quantity-split`  `{"quantitySplit":[{"orderLineId":…,"quantities":[…]}]}`
 *                      One line's UNITS split across packages: three of five now, two later.
 *  - `package_details` `POST …/shipment-packages/{id}/split-packages`  `{"splitPackages":[{"packageDetails":[{"orderLineId":…,"quantities":…}]}]}`
 *                      Named packages, each holding named quantities of named lines. Note the nesting and the SINGULAR
 *                      `quantities` integer here, against the ARRAY of the same name in `quantity-split`: Trendyol's two
 *                      schemas really do differ in that one field, and getting it wrong is a 400 nobody could read.
 *
 * `shouldKeepPreviousStatus` is Trendyol's own field, default `false`, documented as "Whether to keep the previous status
 * (true) or create new package with Created status (false)". Its one documented rule is quoted where it is used.
 *
 * **No split endpoint documents a response body in the international set** -- all four say 200 "Package split successfully"
 * with no content schema. So nothing here reads an id out of the answer, and nothing may: the new packages are learned by
 * reading the order again, which is what Trendyol's own pages tell an integrator to do.
 */
final readonly class PackageSplitPlan
{
    public const LINES = 'lines';

    public const GROUPS = 'groups';

    public const LINE_QUANTITIES = 'line_quantities';

    public const PACKAGE_DETAILS = 'package_details';

    private const PATHS = [
        self::LINES => '/split',
        self::GROUPS => '/multi-split',
        self::LINE_QUANTITIES => '/quantity-split',
        self::PACKAGE_DETAILS => '/split-packages',
    ];

    private const OPERATIONS = [
        self::LINES => 'trendyol.package_split',
        self::GROUPS => 'trendyol.package_multi_split',
        self::LINE_QUANTITIES => 'trendyol.package_quantity_split',
        self::PACKAGE_DETAILS => 'trendyol.package_split_packages',
    ];

    /**
     * @param array<int, mixed> $parts what the cut is made of, in the kind's own terms (see the named constructors)
     */
    private function __construct(
        public string $kind,
        public array $parts,
        public bool $keepPreviousStatus = false,
    ) {
    }

    /**
     * Whole lines into their own package.
     *
     * @param list<int|string> $orderLineIds
     */
    public static function ofLines(array $orderLineIds, bool $keepPreviousStatus = false): self
    {
        return new self(self::LINES, self::ids($orderLineIds), $keepPreviousStatus);
    }

    /**
     * Several packages at once, each a list of whole lines.
     *
     * @param list<list<int|string>> $groups
     */
    public static function ofGroups(array $groups, bool $keepPreviousStatus = false): self
    {
        return new self(self::GROUPS, array_values(array_map(static fn (array $group): array => self::ids($group), $groups)), $keepPreviousStatus);
    }

    /**
     * One or more lines split by units: `[lineId => [3, 2]]` sends three units in one package and two in another.
     *
     * @param array<int|string, list<int>> $quantitiesByLine
     */
    public static function ofLineQuantities(array $quantitiesByLine, bool $keepPreviousStatus = false): self
    {
        $parts = [];
        foreach ($quantitiesByLine as $lineId => $quantities) {
            $units = array_values(array_filter(array_map('intval', (array) $quantities), static fn (int $unit): bool => $unit > 0));
            if ((int) $lineId > 0 && $units !== []) {
                $parts[] = ['orderLineId' => (int) $lineId, 'quantities' => $units];
            }
        }

        return new self(self::LINE_QUANTITIES, $parts, $keepPreviousStatus);
    }

    /**
     * Named packages, each holding named quantities of named lines: `[[lineId => 2, otherLine => 1], [lineId => 3]]`.
     *
     * @param list<array<int|string, int>> $packages
     */
    public static function ofPackageDetails(array $packages, bool $keepPreviousStatus = false): self
    {
        $parts = [];
        foreach ($packages as $package) {
            $details = [];
            foreach ((array) $package as $lineId => $quantity) {
                if ((int) $lineId > 0 && (int) $quantity > 0) {
                    // SINGULAR here on purpose: `split-packages` takes one integer per line, where `quantity-split` takes a list.
                    $details[] = ['orderLineId' => (int) $lineId, 'quantities' => (int) $quantity];
                }
            }
            if ($details !== []) {
                $parts[] = ['packageDetails' => $details];
            }
        }

        return new self(self::PACKAGE_DETAILS, $parts, $keepPreviousStatus);
    }

    /**
     * The plan as it was recorded, rebuilt from the body that was stored.
     *
     * A recorded split is replayed from the BODY, not from the arguments somebody once passed: the row is what a person asked
     * for, and the row is what gets sent. `keepPreviousStatus` is passed separately because the channel's rule about it is
     * settled against the package, not against the plan.
     *
     * @param array<string, mixed> $body
     */
    public static function fromStored(string $kind, array $body, bool $keepPreviousStatus): self
    {
        $parts = match ($kind) {
            self::LINES => self::ids((array) ($body['orderLineIds'] ?? [])),
            self::GROUPS => array_values(array_map(static fn (array $group): array => self::ids((array) ($group['orderLineIds'] ?? [])), (array) ($body['splitGroups'] ?? []))),
            self::LINE_QUANTITIES => array_values(array_map(
                static fn (array $part): array => ['orderLineId' => (int) ($part['orderLineId'] ?? 0), 'quantities' => array_values(array_map('intval', (array) ($part['quantities'] ?? [])))],
                (array) ($body['quantitySplit'] ?? []),
            )),
            self::PACKAGE_DETAILS => array_values(array_map(
                static fn (array $part): array => ['packageDetails' => array_values(array_map(
                    static fn (array $detail): array => ['orderLineId' => (int) ($detail['orderLineId'] ?? 0), 'quantities' => (int) ($detail['quantities'] ?? 0)],
                    (array) ($part['packageDetails'] ?? []),
                ))],
                (array) ($body['splitPackages'] ?? []),
            )),
            default => throw new InvalidArgumentException("'{$kind}' is not one of the four splits Trendyol documents."),
        };

        return new self($kind, $parts, $keepPreviousStatus);
    }

    /** @return list<int> */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    }

    public function path(): string
    {
        return self::PATHS[$this->kind];
    }

    public function operation(): string
    {
        return self::OPERATIONS[$this->kind];
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        $body = match ($this->kind) {
            self::LINES => ['orderLineIds' => $this->parts],
            self::GROUPS => ['splitGroups' => array_map(static fn (array $group): array => ['orderLineIds' => $group], $this->parts)],
            self::LINE_QUANTITIES => ['quantitySplit' => $this->parts],
            self::PACKAGE_DETAILS => ['splitPackages' => $this->parts],
        };

        return $body + ['shouldKeepPreviousStatus' => $this->keepPreviousStatus];
    }

    /** Every line this plan names, whatever shape it names it in: what the plan touches, for the checks below. */
    public function lineIds(): array
    {
        return match ($this->kind) {
            self::LINES => $this->parts,
            self::GROUPS => self::ids(array_merge(...array_values($this->parts) ?: [[]])),
            self::LINE_QUANTITIES => self::ids(array_column($this->parts, 'orderLineId')),
            self::PACKAGE_DETAILS => self::ids(array_merge(...array_map(static fn (array $part): array => array_column($part['packageDetails'], 'orderLineId'), $this->parts) ?: [[]])),
        };
    }

    /** How many packages this plan asks Trendyol to make, not counting the one it makes for the remainder by itself. */
    public function packageCount(): int
    {
        return match ($this->kind) {
            self::LINES => 1,
            self::GROUPS, self::PACKAGE_DETAILS => count($this->parts),
            // A line split into three quantities is three packages; several lines split together land in the same ones.
            self::LINE_QUANTITIES => max(array_map(static fn (array $part): int => count($part['quantities']), $this->parts) ?: [0]),
        };
    }

    /**
     * Why this plan cannot be sent, or null.
     *
     * `$packageLineIds` is the package as the channel last described it. A plan is checked against the package it is for,
     * because the alternative is a 400 whose reason a person then has to decipher.
     *
     * @param list<string> $packageLineIds
     */
    public function refusal(array $packageLineIds): ?string
    {
        if ($this->parts === []) {
            return 'package_split_names_nothing';
        }
        $named = $this->lineIds();
        if ($named === []) {
            return 'package_split_names_no_line';
        }
        $known = array_map('intval', $packageLineIds);
        if ($known !== [] && array_diff($named, $known) !== []) {
            // A line the package does not hold. Sending it would either be refused or -- worse -- split somebody else's package.
            return 'package_split_names_a_line_the_package_does_not_hold';
        }
        if ($this->kind === self::GROUPS && $known !== [] && array_diff($known, $named) === []) {
            // Trendyol's own rule for this endpoint: "All orderLines in a package should not be sent to this service."
            return 'package_multi_split_may_not_name_every_line';
        }
        if ($this->kind === self::LINES && $known !== [] && array_diff($known, $named) === []) {
            // Not Trendyol's rule, ours: a "split" that moves every line makes one package out of one package. Whatever
            // Trendyol would do with it, it is not a split, and it would invalidate a package id for nothing.
            return 'package_split_of_every_line_splits_nothing';
        }

        return null;
    }
}
