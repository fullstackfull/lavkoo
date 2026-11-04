<?php

namespace App\Services\Analytics\Support;

/**
 * A QR code, rendered as an SVG, with no dependency.
 *
 * Scope is deliberately narrow: byte mode, error-correction level M, versions 1 to 10 — 213 bytes
 * at the largest version, which is comfortably more than a short link needs and is where encode()
 * refuses rather than truncates. (Version 10 holds 216 data codewords; the mode indicator and the
 * 16-bit length field take 20 of the 1,728 bits, leaving 213 whole bytes.) A general QR library
 * handles kanji, structured append and version 40; none of that is wanted here, and the cost of a
 * composer dependency and its transitive tree is not worth a poster.
 *
 * Level M is chosen over L on purpose. These get printed, photographed off a shop window and
 * scanned in bad light; M recovers from 15% damage against L's 7%, for one version step.
 */
class QrCode
{
    /** Data codeword capacity at error-correction level M, by version (1-10). */
    private const CAPACITY_M = [1 => 16, 2 => 28, 3 => 44, 4 => 64, 5 => 86, 6 => 108, 7 => 124, 8 => 154, 9 => 182, 10 => 216];

    /** Error-correction blocks at level M: [total EC codewords, block count, ...]. */
    private const EC_M = [
        1 => ['ec' => 10, 'groups' => [[1, 16]]],
        2 => ['ec' => 16, 'groups' => [[1, 28]]],
        3 => ['ec' => 26, 'groups' => [[1, 44]]],
        4 => ['ec' => 18, 'groups' => [[2, 32]]],
        5 => ['ec' => 24, 'groups' => [[2, 43]]],
        6 => ['ec' => 16, 'groups' => [[4, 27]]],
        7 => ['ec' => 18, 'groups' => [[4, 31]]],
        8 => ['ec' => 22, 'groups' => [[2, 38], [2, 39]]],
        9 => ['ec' => 22, 'groups' => [[3, 36], [2, 37]]],
        10 => ['ec' => 26, 'groups' => [[4, 43], [1, 44]]],
    ];

    /** Alignment-pattern centres by version. */
    private const ALIGNMENT = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** Pre-computed format information for level M, masks 0-7. */
    private const FORMAT_M = [
        0b101010000010010, 0b101000100100101, 0b101111001111100, 0b101101101001011,
        0b100010111111001, 0b100000011001110, 0b100111110010111, 0b100101010100000,
    ];

    /** @var array<int, int> */
    private array $exp = [];

    /** @var array<int, int> */
    private array $log = [];

    public function __construct()
    {
        $this->buildGaloisTables();
    }

    /**
     * @return string|null  the SVG, or null when the payload will not fit
     */
    public function svg(string $text, int $size = 220): ?string
    {
        $matrix = $this->matrix($text);

        if ($matrix === null) {
            return null;
        }

        $modules = count($matrix);
        // A quiet zone is part of the specification, not decoration: without it a scanner cannot
        // find the finder patterns against a busy page.
        $quiet = 4;
        $total = $modules + $quiet * 2;

        $paths = [];

        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $paths[] = 'M' . ($x + $quiet) . ' ' . ($y + $quiet) . 'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '"'
            . ' viewBox="0 0 ' . $total . ' ' . $total . '" shape-rendering="crispEdges" role="img">'
            . '<rect width="' . $total . '" height="' . $total . '" fill="#ffffff"/>'
            . '<path fill="#000000" d="' . implode('', $paths) . '"/>'
            . '</svg>';
    }

    /**
     * @return array<int, array<int, bool>>|null
     */
    public function matrix(string $text): ?array
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $version = $this->versionFor(count($bytes));

        if ($version === null) {
            return null;
        }

        $codewords = $this->encode($bytes, $version);
        $final = $this->interleave($codewords, $version);

        $size = 17 + $version * 4;
        $matrix = array_fill(0, $size, array_fill(0, $size, null));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        $this->placeFunctionPatterns($matrix, $reserved, $version, $size);
        $this->placeData($matrix, $reserved, $final, $size);

        // Every mask is scored and the lowest wins. Skipping this and always using mask 0 produces
        // codes that scan on a phone in good light and fail on a cheap handheld reader.
        $best = null;
        $bestPenalty = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $this->applyMask($matrix, $reserved, $mask, $size);
            $this->placeFormat($candidate, $mask, $size);
            $penalty = $this->penalty($candidate, $size);

            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $candidate;
            }
        }

        return array_map(static fn (array $row) => array_map(static fn ($cell) => (bool) $cell, $row), $best ?? []);
    }

    // -------------------------------------------------------------------------------------------

    private function versionFor(int $length): ?int
    {
        foreach (self::CAPACITY_M as $version => $capacity) {
            // 4 bits mode + 8 or 16 bits length + the data itself.
            $header = 4 + ($version >= 10 ? 16 : 8);
            if ((int) ceil(($header + $length * 8) / 8) <= $capacity) {
                return $version;
            }
        }

        return null;
    }

    /**
     * @param  array<int, int>  $bytes
     * @return array<int, int>
     */
    private function encode(array $bytes, int $version): array
    {
        $bits = '';
        $bits .= '0100';                                                    // byte mode
        $bits .= str_pad(decbin(count($bytes)), $version >= 10 ? 16 : 8, '0', STR_PAD_LEFT);

        foreach ($bytes as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        $capacity = self::CAPACITY_M[$version] * 8;
        $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));        // terminator
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);              // pad to a byte

        $codewords = [];
        foreach (str_split($bits, 8) as $chunk) {
            $codewords[] = bindec($chunk);
        }

        // The specification's alternating pad bytes, not zeros: a run of zeros is a masking
        // pathology and scores badly.
        $pad = [0xEC, 0x11];
        $index = 0;
        while (count($codewords) < self::CAPACITY_M[$version]) {
            $codewords[] = $pad[$index++ % 2];
        }

        return $codewords;
    }

    /**
     * Split into blocks, compute error correction, and interleave as the specification requires.
     *
     * @param  array<int, int>  $codewords
     * @return array<int, int>
     */
    private function interleave(array $codewords, int $version): array
    {
        $spec = self::EC_M[$version];
        $blocks = [];
        $ecBlocks = [];
        $offset = 0;

        foreach ($spec['groups'] as [$count, $size]) {
            for ($block = 0; $block < $count; $block++) {
                $data = array_slice($codewords, $offset, $size);
                $offset += $size;
                $blocks[] = $data;
                $ecBlocks[] = $this->errorCorrection($data, $spec['ec']);
            }
        }

        $result = [];
        $longest = max(array_map('count', $blocks));

        for ($i = 0; $i < $longest; $i++) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        for ($i = 0; $i < $spec['ec']; $i++) {
            foreach ($ecBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    /**
     * Reed-Solomon error correction over GF(256).
     *
     * @param  array<int, int>  $data
     * @return array<int, int>
     */
    private function errorCorrection(array $data, int $ecLength): array
    {
        $generator = $this->generatorPolynomial($ecLength);
        $remainder = array_merge($data, array_fill(0, $ecLength, 0));

        for ($i = 0; $i < count($data); $i++) {
            $factor = $remainder[$i];

            if ($factor === 0) {
                continue;
            }

            $factorLog = $this->log[$factor];

            foreach ($generator as $index => $coefficient) {
                $remainder[$i + $index] ^= $this->exp[($this->log[$coefficient] + $factorLog) % 255];
            }
        }

        return array_slice($remainder, count($data));
    }

    /**
     * @return array<int, int>
     */
    private function generatorPolynomial(int $degree): array
    {
        $polynomial = [1];

        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($polynomial) + 1, 0);

            foreach ($polynomial as $index => $coefficient) {
                $next[$index] ^= $coefficient;
                $next[$index + 1] ^= $coefficient === 0 ? 0 : $this->exp[($this->log[$coefficient] + $i) % 255];
            }

            $polynomial = $next;
        }

        return $polynomial;
    }

    private function buildGaloisTables(): void
    {
        $value = 1;

        for ($i = 0; $i < 255; $i++) {
            $this->exp[$i] = $value;
            $this->log[$value] = $i;
            $value <<= 1;

            if ($value & 0x100) {
                $value ^= 0x11D;     // the QR generator polynomial
            }
        }

        for ($i = 255; $i < 512; $i++) {
            $this->exp[$i] = $this->exp[$i - 255];
        }
    }

    /**
     * @param  array<int, array<int, mixed>>  $matrix
     * @param  array<int, array<int, bool>>  $reserved
     */
    private function placeFunctionPatterns(array &$matrix, array &$reserved, int $version, int $size): void
    {
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$x, $y]) {
            $this->placeFinder($matrix, $reserved, $x, $y, $size);
        }

        // Timing patterns.
        for ($i = 8; $i < $size - 8; $i++) {
            $dark = $i % 2 === 0;
            $matrix[6][$i] = $dark;
            $matrix[$i][6] = $dark;
            $reserved[6][$i] = true;
            $reserved[$i][6] = true;
        }

        // Alignment patterns, skipping the three that would collide with a finder.
        $centres = self::ALIGNMENT[$version];
        foreach ($centres as $row) {
            foreach ($centres as $column) {
                $nearFinder = ($row <= 8 && $column <= 8)
                    || ($row <= 8 && $column >= $size - 9)
                    || ($row >= $size - 9 && $column <= 8);

                if (!$nearFinder) {
                    $this->placeAlignment($matrix, $reserved, $column, $row);
                }
            }
        }

        // The dark module, which is always set.
        $matrix[$size - 8][8] = true;
        $reserved[$size - 8][8] = true;

        // Version information, required from version 7 upward.
        //
        // Omitting this is invisible in the picture and fatal in a scanner: a decoder cannot infer
        // the version from module count alone at these sizes, so it never gets as far as reading
        // the data. Measured before this was added — versions 1 to 6 decoded 106 out of 106, and
        // versions 7 to 10 decoded 0 out of 94.
        if ($version >= 7) {
            $remainder = $version;

            for ($i = 0; $i < 12; $i++) {
                $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
            }

            $bits = ($version << 12) | ($remainder & 0xFFF);

            for ($i = 0; $i < 18; $i++) {
                $bit = (bool) (($bits >> $i) & 1);
                $far = $size - 11 + $i % 3;
                $near = intdiv($i, 3);

                // Two copies: one left of the top-right finder, one above the bottom-left one.
                $matrix[$near][$far] = $bit;
                $matrix[$far][$near] = $bit;
                $reserved[$near][$far] = true;
                $reserved[$far][$near] = true;
            }
        }

        // Reserve the format information areas.
        for ($i = 0; $i < 9; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
            $reserved[$size - 1 - $i][8] = true;
        }
    }

    private function placeFinder(array &$matrix, array &$reserved, int $x, int $y, int $size): void
    {
        for ($row = -1; $row <= 7; $row++) {
            for ($column = -1; $column <= 7; $column++) {
                $py = $y + $row;
                $px = $x + $column;

                if ($py < 0 || $py >= $size || $px < 0 || $px >= $size) {
                    continue;
                }

                $inRing = ($row >= 0 && $row <= 6 && ($column === 0 || $column === 6))
                    || ($column >= 0 && $column <= 6 && ($row === 0 || $row === 6));
                $inCore = $row >= 2 && $row <= 4 && $column >= 2 && $column <= 4;

                $matrix[$py][$px] = $inRing || $inCore;
                $reserved[$py][$px] = true;
            }
        }
    }

    private function placeAlignment(array &$matrix, array &$reserved, int $x, int $y): void
    {
        for ($row = -2; $row <= 2; $row++) {
            for ($column = -2; $column <= 2; $column++) {
                $matrix[$y + $row][$x + $column] = abs($row) === 2 || abs($column) === 2 || ($row === 0 && $column === 0);
                $reserved[$y + $row][$x + $column] = true;
            }
        }
    }

    /**
     * @param  array<int, int>  $codewords
     */
    private function placeData(array &$matrix, array $reserved, array $codewords, int $size): void
    {
        $bits = '';
        foreach ($codewords as $codeword) {
            $bits .= str_pad(decbin($codeword), 8, '0', STR_PAD_LEFT);
        }

        $index = 0;
        $upward = true;

        for ($right = $size - 1; $right > 0; $right -= 2) {
            if ($right === 6) {
                $right = 5;      // the vertical timing pattern column is skipped entirely
            }

            for ($step = 0; $step < $size; $step++) {
                $row = $upward ? $size - 1 - $step : $step;

                foreach ([$right, $right - 1] as $column) {
                    if ($reserved[$row][$column]) {
                        continue;
                    }

                    $matrix[$row][$column] = ($bits[$index] ?? '0') === '1';
                    $index++;
                }
            }

            $upward = !$upward;
        }
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function applyMask(array $matrix, array $reserved, int $mask, int $size): array
    {
        for ($row = 0; $row < $size; $row++) {
            for ($column = 0; $column < $size; $column++) {
                if ($reserved[$row][$column]) {
                    continue;
                }

                $flip = match ($mask) {
                    0 => ($row + $column) % 2 === 0,
                    1 => $row % 2 === 0,
                    2 => $column % 3 === 0,
                    3 => ($row + $column) % 3 === 0,
                    4 => (intdiv($row, 2) + intdiv($column, 3)) % 2 === 0,
                    5 => ($row * $column) % 2 + ($row * $column) % 3 === 0,
                    6 => (($row * $column) % 2 + ($row * $column) % 3) % 2 === 0,
                    default => (($row + $column) % 2 + ($row * $column) % 3) % 2 === 0,
                };

                if ($flip) {
                    $matrix[$row][$column] = !$matrix[$row][$column];
                }
            }
        }

        return $matrix;
    }

    /**
     * The fifteen format bits, written twice.
     *
     * Both copies index the SAME bit the same way — least significant first — and that consistency
     * is the whole content of this method. An earlier version read the first copy most-significant
     * first and mirrored its positions to match, which cancelled out and decoded; the second copy
     * kept the mirrored bit order without the mirrored positions, so four modules were wrong and
     * nothing would scan. Verified against an independent decoder rather than by eye.
     */
    private function placeFormat(array &$matrix, int $mask, int $size): void
    {
        $format = self::FORMAT_M[$mask];
        $bit = static fn (int $index): bool => (bool) (($format >> $index) & 1);

        // First copy: down column 8 past the timing row, then left along row 8.
        for ($i = 0; $i <= 5; $i++) {
            $matrix[$i][8] = $bit($i);
        }
        $matrix[7][8] = $bit(6);
        $matrix[8][8] = $bit(7);
        $matrix[8][7] = $bit(8);
        for ($i = 9; $i < 15; $i++) {
            $matrix[8][14 - $i] = $bit($i);
        }

        // Second copy: right along row 8 from the far edge, then up column 8 from the bottom.
        for ($i = 0; $i < 8; $i++) {
            $matrix[8][$size - 1 - $i] = $bit($i);
        }
        for ($i = 8; $i < 15; $i++) {
            $matrix[$size - 15 + $i][8] = $bit($i);
        }
    }

    /**
     * The specification's four penalty rules. Lower is more reliably scannable.
     */
    private function penalty(array $matrix, int $size): int
    {
        $penalty = 0;
        $dark = 0;

        // Rule 1: runs of five or more of the same colour, in both directions.
        for ($row = 0; $row < $size; $row++) {
            for ($column = 0; $column < $size; $column++) {
                if ($matrix[$row][$column]) {
                    $dark++;
                }
            }
        }

        foreach ([true, false] as $vertical) {
            for ($a = 0; $a < $size; $a++) {
                $run = 1;

                for ($b = 1; $b < $size; $b++) {
                    $current = $vertical ? $matrix[$b][$a] : $matrix[$a][$b];
                    $previous = $vertical ? $matrix[$b - 1][$a] : $matrix[$a][$b - 1];

                    if ($current === $previous) {
                        $run++;
                    } else {
                        if ($run >= 5) {
                            $penalty += 3 + ($run - 5);
                        }
                        $run = 1;
                    }
                }

                if ($run >= 5) {
                    $penalty += 3 + ($run - 5);
                }
            }
        }

        // Rule 2: 2x2 blocks of one colour.
        for ($row = 0; $row < $size - 1; $row++) {
            for ($column = 0; $column < $size - 1; $column++) {
                $value = $matrix[$row][$column];

                if ($value === $matrix[$row][$column + 1]
                    && $value === $matrix[$row + 1][$column]
                    && $value === $matrix[$row + 1][$column + 1]) {
                    $penalty += 3;
                }
            }
        }

        // Rule 3: the finder-like 1:1:3:1:1 sequence appearing in the data.
        $pattern = [true, false, true, true, true, false, true, false, false, false, false];
        foreach ([true, false] as $vertical) {
            for ($a = 0; $a < $size; $a++) {
                for ($b = 0; $b <= $size - 11; $b++) {
                    $matches = true;

                    for ($k = 0; $k < 11; $k++) {
                        $cell = $vertical ? $matrix[$b + $k][$a] : $matrix[$a][$b + $k];

                        if ((bool) $cell !== $pattern[$k]) {
                            $matches = false;
                            break;
                        }
                    }

                    if ($matches) {
                        $penalty += 40;
                    }
                }
            }
        }

        // Rule 4: imbalance between dark and light.
        $percent = (int) (100 * $dark / ($size * $size));
        $penalty += 10 * (int) (abs($percent - 50) / 5);

        return $penalty;
    }
}
