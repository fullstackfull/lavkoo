<?php

namespace App\Console\Commands;

use App\Services\DigitalFilePrivatizer;
use App\Services\DigitalProductFileService;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

class PrivatizeDigitalFilesCommand extends Command
{
    private const LABELS = [
        DigitalFilePrivatizer::TO_COPY => 'Would be copied',
        DigitalFilePrivatizer::COPIED => 'Copied and verified',
        DigitalFilePrivatizer::ALREADY_COPIED => 'Already copied (the public copy is the same file)',
        DigitalFilePrivatizer::ALREADY_PRIVATE => 'Already private (no public copy)',
        DigitalFilePrivatizer::MISSING => 'Missing (no file on either disk)',
        DigitalFilePrivatizer::CONFLICT => 'Different content on the two disks (left alone)',
        DigitalFilePrivatizer::FAILED => 'Failed (a copy or a check did not pass)',
        DigitalFilePrivatizer::REFUSED => 'Not a plain file name (refused)',
        DigitalFilePrivatizer::BUCKET => 'On a bucket (not touched)',
    ];

    /** The outcomes that have a public copy, whose size is worth printing. */
    private const WITH_SIZE = [DigitalFilePrivatizer::TO_COPY, DigitalFilePrivatizer::COPIED, DigitalFilePrivatizer::ALREADY_COPIED, DigitalFilePrivatizer::CONFLICT, DigitalFilePrivatizer::FAILED];

    private const NAMES_LISTED = 20;

    protected $signature = 'digital-files:privatize
        {--execute : copy the files (without it the command only reports what it would do)}
        {--remove-public : after a copy has been verified, delete the public copy (only with --execute, and only while DIGITAL_FILES_PRIVATE is on)}';

    protected $description = 'Copy the digital files of the public disk to the private disk (dry run by default; checks the size and the hash of every copy; never writes a row or touches a file of a bucket)';

    public function handle(DigitalFilePrivatizer $privatizer, DigitalProductFileService $digitalFiles): int
    {
        $execute = (bool) $this->option('execute');
        $removePublic = (bool) $this->option('remove-public');
        if ($removePublic && !$execute) {
            $this->components->error('--remove-public only works together with --execute.');

            return self::FAILURE;
        }
        if ($removePublic && !$digitalFiles->isPrivate()) {
            $this->components->error('DIGITAL_FILES_PRIVATE is off, so the shop does not read the private disk yet: deleting the public copies now would leave the shop without its files. Turn it on (and run `php artisan config:clear`), then run this again.');

            return self::FAILURE;
        }

        $this->components->info($execute ? ($removePublic ? 'Copying, checking and removing the public copies.' : 'Copying and checking; the public copies stay.') : 'Dry run: nothing is written. Add --execute to copy.');
        if ($execute) {
            $this->line('The private files can be read by their owner only: run this as the user your web server\'s PHP runs as.');
        }
        $this->line('Private mode (DIGITAL_FILES_PRIVATE): ' . ($digitalFiles->isPrivate() ? 'on' : 'off') . '. Private disk: ' . config('filesystems.disks.' . DigitalProductFileService::PRIVATE_DISK . '.root'));

        $report = $privatizer->run(execute: $execute, removePublic: $removePublic);
        $this->printOutcomes(report: $report);
        if ($execute && $removePublic) {
            $this->line('Public copies removed: ' . $report['removed']);
        } elseif ($execute && (isset($report['outcomes'][DigitalFilePrivatizer::COPIED]) || isset($report['outcomes'][DigitalFilePrivatizer::ALREADY_COPIED]))) {
            $this->line('The public copies are still there: once DIGITAL_FILES_PRIVATE is on and the downloads work, run this again with --execute --remove-public.');
        }
        $this->printNamesNeedingALook(report: $report);
        $this->printTheFilesNoRowNames(report: $report);

        return isset($report['outcomes'][DigitalFilePrivatizer::FAILED]) || isset($report['outcomes'][DigitalFilePrivatizer::CONFLICT]) ? self::FAILURE : self::SUCCESS;
    }

    /** @param array{outcomes: array<string, array{files: int, rows: int, bytes: int}>} $report */
    private function printOutcomes(array $report): void
    {
        foreach (self::LABELS as $outcome => $label) {
            if (!isset($report['outcomes'][$outcome])) {
                continue;
            }
            $counts = $report['outcomes'][$outcome];
            $this->components->twoColumnDetail($label, sprintf('files: %d, rows: %d%s', $counts['files'], $counts['rows'], in_array($outcome, self::WITH_SIZE, true) ? ', size: ' . Number::fileSize($counts['bytes'], maxPrecision: 1) : ''));
        }
        if ($report['outcomes'] === []) {
            $this->line('No row names a digital file.');
        }
    }

    /** @param array{names: array<string, list<string>>} $report */
    private function printNamesNeedingALook(array $report): void
    {
        foreach ($report['names'] as $outcome => $names) {
            $this->printNames(heading: self::LABELS[$outcome], names: $names);
        }
    }

    /** @param array{unnamed: list<string>} $report */
    private function printTheFilesNoRowNames(array $report): void
    {
        if ($report['unnamed'] !== []) {
            $this->printNames(heading: 'Still on the public disk and named by no row (nothing here moves them, and their address still answers)', names: $report['unnamed']);
        }
    }

    /** @param list<string> $names */
    private function printNames(string $heading, array $names): void
    {
        $this->line($heading . ':');
        foreach (array_slice($names, 0, self::NAMES_LISTED) as $name) {
            $this->line('  ' . $name);
        }
        if (count($names) > self::NAMES_LISTED) {
            $this->line('  ... and ' . (count($names) - self::NAMES_LISTED) . ' more');
        }
    }
}
