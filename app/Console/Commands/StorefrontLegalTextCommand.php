<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\StorefrontLegalDocument;
use App\Services\Storefront\StorefrontLegalPublisher;
use App\Services\Storefront\StorefrontLegalReadiness;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class StorefrontLegalTextCommand extends Command
{
    /** A text or a template is read from a file of at most this many bytes. */
    private const MAXIMUM_FILE_BYTES = 1048576;

    protected $signature = 'gcc:storefront:legal-text
        {action : list, draft, edit, approve, publish, revoke or discard}
        {--country= : the storefront, for example KW (list and draft)}
        {--type= : the document type, for example terms (draft; narrows list)}
        {--locale= : the locale, ar or en (draft; narrows list)}
        {--id= : the number of the text (edit, approve, publish, revoke, discard)}
        {--new-version-of= : with draft: the number of a published text, whose next version is started as a draft}
        {--admin= : the id of the admin who takes the step}
        {--template-file= : with draft: the file that holds the template to copy}
        {--body-file= : with edit: the file that holds the new text}
        {--effective-from= : with publish: when the text takes effect, as YYYY-MM-DD HH:MM in UTC; now when it is left out}
        {--reason= : with revoke and discard: why}';

    protected $description = 'Writes the legal text of a storefront: copies a template into a draft, edits it, approves it (someone other than its author), publishes it from a day, revokes it or discards it. Every step is audited and the readiness of the storefront reads what is written. Works while the switches are off';

    public function handle(StorefrontLegalPublisher $publisher, StorefrontLegalReadiness $readiness): int
    {
        try {
            return match ((string) $this->argument('action')) {
                'list' => $this->list(),
                'draft' => $this->draft($publisher),
                'edit' => $this->edit($publisher),
                'approve' => $this->approve($publisher, $readiness),
                'publish' => $this->publish($publisher, $readiness),
                'revoke' => $this->revoke($publisher, $readiness),
                'discard' => $this->discard($publisher),
                default => throw new InvalidArgumentException('The action is list, draft, edit, approve, publish, revoke or discard.'),
            };
        } catch (InvalidArgumentException|RuntimeException $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }
    }

    private function list(): int
    {
        $texts = StorefrontLegalDocument::query()
            ->when($this->filled('country'), fn ($query) => $query->where('country_code', strtoupper(trim((string) $this->option('country')))))
            ->when($this->filled('type'), fn ($query) => $query->where('document_type', strtolower(trim((string) $this->option('type')))))
            ->when($this->filled('locale'), fn ($query) => $query->where('locale', strtolower(trim((string) $this->option('locale')))))
            ->orderBy('country_code')->orderBy('document_type')->orderBy('locale')->orderBy('version')
            ->get();

        $this->table(
            ['id', 'country', 'type', 'locale', 'version', 'status', 'author', 'approved by', 'effective from (UTC)', 'revoked'],
            $texts->map(fn (StorefrontLegalDocument $text): array => [
                $text->id, $text->country_code, $text->document_type, $text->locale, $text->version, $text->status, $text->created_by ?? '-', $text->approved_by ?? '-',
                $text->effective_from?->utc()->format('Y-m-d H:i') ?? '-', $text->revoked_at === null ? '-' : $text->revoked_at->utc()->format('Y-m-d H:i'),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function draft(StorefrontLegalPublisher $publisher): int
    {
        $admin = $this->admin();

        if ($this->filled('new-version-of')) {
            $text = $publisher->draftNewVersionOf(textId: $this->integerOption('new-version-of'), author: $admin);
            $this->info("Draft {$text->id} is version {$text->version} of the {$text->document_type} ({$text->locale}) of {$text->country_code}, a copy of the text it replaces.");
            $this->line('Edit it (--body-file), then someone other than its author approves it.');

            return self::SUCCESS;
        }

        $text = $publisher->draftFromTemplate(
            country: (string) $this->option('country'),
            documentType: (string) $this->option('type'),
            locale: (string) $this->option('locale'),
            template: $this->fileOption('template-file'),
            author: $admin,
        );

        $this->info("Draft {$text->id}: the {$text->document_type} ({$text->locale}) of {$text->country_code}, version {$text->version}, a copy of the template.");
        $this->line('A text that is still the template is not approved: edit it (--body-file), then someone other than its author approves it.');

        return self::SUCCESS;
    }

    private function edit(StorefrontLegalPublisher $publisher): int
    {
        $text = $publisher->edit(textId: $this->integerOption('id'), body: $this->fileOption('body-file'), editor: $this->admin());

        $this->info("Text {$text->id} is changed and is a draft of admin {$text->created_by}: its approval, if it had one, is void.");

        return self::SUCCESS;
    }

    private function approve(StorefrontLegalPublisher $publisher, StorefrontLegalReadiness $readiness): int
    {
        $text = $publisher->approve(textId: $this->integerOption('id'), approver: $this->admin());

        $this->info("Text {$text->id} is approved by admin {$text->approved_by} (written by admin {$text->created_by}). It is not in force until it is published.");
        $this->reportReadiness(readiness: $readiness, country: $text->country_code);

        return self::SUCCESS;
    }

    private function publish(StorefrontLegalPublisher $publisher, StorefrontLegalReadiness $readiness): int
    {
        $text = $publisher->publish(textId: $this->integerOption('id'), publisher: $this->admin(), effectiveFrom: $this->effectiveFrom());

        $this->info("Text {$text->id} is published and takes effect on {$text->effective_from->utc()->format('Y-m-d H:i')} UTC.");
        $this->reportReadiness(readiness: $readiness, country: $text->country_code);

        return self::SUCCESS;
    }

    private function revoke(StorefrontLegalPublisher $publisher, StorefrontLegalReadiness $readiness): int
    {
        $text = $publisher->revoke(textId: $this->integerOption('id'), revoker: $this->admin(), reason: (string) $this->option('reason'));

        $this->info("Text {$text->id} is revoked: while it is the text in force the storefront does not sell. Publish a new version to replace it.");
        $this->reportReadiness(readiness: $readiness, country: $text->country_code);

        return self::SUCCESS;
    }

    private function discard(StorefrontLegalPublisher $publisher): int
    {
        $text = $publisher->discard(textId: $this->integerOption('id'), admin: $this->admin(), reason: (string) $this->option('reason'));

        $this->info("Text {$text->id} is discarded and kept as history.");

        return self::SUCCESS;
    }

    private function reportReadiness(StorefrontLegalReadiness $readiness, string $country): void
    {
        $this->line($readiness->isReady($country)
            ? "{$country}: every required text is published and approved. The storefront also needs the other conditions (see gcc:storefront:currency-readiness)."
            : "{$country}: a required text is still missing, not in force, revoked or not approved by someone other than its author.");
    }

    private function filled(string $option): bool
    {
        return trim((string) $this->option($option)) !== '';
    }

    private function integerOption(string $option, string $meaning = 'the number of a text'): int
    {
        $value = trim((string) $this->option($option));

        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new InvalidArgumentException("--{$option} is {$meaning}.");
        }

        return (int) $value;
    }

    /** The admin who takes the step: an id that is in the table of admins, because a step by somebody who does not exist is a step by nobody. */
    private function admin(): int
    {
        $id = $this->integerOption('admin', meaning: 'the id of an admin');

        if (!Admin::query()->whereKey($id)->exists()) {
            throw new InvalidArgumentException("There is no admin {$id}.");
        }

        return $id;
    }

    private function fileOption(string $option): string
    {
        $path = trim((string) $this->option($option));

        if ($path === '') {
            throw new InvalidArgumentException("--{$option} names the file that holds the text.");
        }

        if (!is_file($path) || !is_readable($path) || filesize($path) > self::MAXIMUM_FILE_BYTES) {
            throw new InvalidArgumentException("{$path} is not a readable file of at most " . self::MAXIMUM_FILE_BYTES . ' bytes.');
        }

        return (string) file_get_contents($path);
    }

    private function effectiveFrom(): ?CarbonImmutable
    {
        if (!$this->filled('effective-from')) {
            return null;
        }

        $text = trim((string) $this->option('effective-from'));
        $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $text, new DateTimeZone('UTC'));

        if ($moment === false || $moment->format('Y-m-d H:i') !== $text) {
            throw new InvalidArgumentException('--effective-from is a day and a time in UTC, as YYYY-MM-DD HH:MM.');
        }

        return CarbonImmutable::instance($moment);
    }
}
