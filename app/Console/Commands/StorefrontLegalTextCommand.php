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
    public const MAXIMUM_FILE_BYTES = 1048576;

    private const UTF8_BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    /** The hash of a text is shown, and named back to the command, by its first characters: enough to tell one text from another, short enough to read aloud. */
    private const MINIMUM_HASH_PREFIX_LENGTH = 12;

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
        {--hash= : with approve and publish: the first 12 characters (or all 64) of the hash of the text you read, as list shows it; refused when the text is not that any more, and when it is empty (leave it out to name none)}
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
            ['id', 'country', 'type', 'locale', 'version', 'status', 'hash', 'author', 'approved by', 'effective from (UTC)', 'revoked'],
            $texts->map(fn (StorefrontLegalDocument $text): array => [
                $text->id, $text->country_code, $text->document_type, $text->locale, $text->version, $text->status, $this->hashPrefixOf($text), $text->created_by ?? '-', $text->approved_by ?? '-',
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
            $this->line('A copy that is not edited is not approved: edit it (--body-file), then someone other than its author approves it.');
            $this->reportHash($text);

            return self::SUCCESS;
        }

        $text = $publisher->draftFromTemplate(
            country: $this->requiredOption('country', meaning: 'the storefront, for example KW'),
            documentType: $this->requiredOption('type', meaning: 'the document type, for example terms'),
            locale: $this->requiredOption('locale', meaning: 'the locale, ar or en'),
            template: $this->fileOption('template-file'),
            author: $admin,
        );

        $this->info("Draft {$text->id}: the {$text->document_type} ({$text->locale}) of {$text->country_code}, version {$text->version}, a copy of the template.");
        $this->line('A text that is still the template is not approved: edit it (--body-file), then someone other than its author approves it.');
        $this->reportHash($text);

        return self::SUCCESS;
    }

    private function edit(StorefrontLegalPublisher $publisher): int
    {
        $text = $publisher->edit(textId: $this->integerOption('id'), body: $this->fileOption('body-file'), editor: $this->admin());

        $this->info("Text {$text->id} is changed and is a draft of admin {$text->created_by}: its approval, if it had one, is void.");
        $this->reportHash($text);

        return self::SUCCESS;
    }

    private function approve(StorefrontLegalPublisher $publisher, StorefrontLegalReadiness $readiness): int
    {
        $textId = $this->integerOption('id');
        $text = $publisher->approve(textId: $textId, approver: $this->admin(), expectedBodyHash: $this->expectedBodyHash($textId));

        $this->info("Text {$text->id} is approved by admin {$text->approved_by} (written by admin {$text->created_by}). It is not in force until it is published.");
        $this->reportHash($text);
        $this->reportReadiness(readiness: $readiness, country: $text->country_code);

        return self::SUCCESS;
    }

    private function publish(StorefrontLegalPublisher $publisher, StorefrontLegalReadiness $readiness): int
    {
        $textId = $this->integerOption('id');
        $text = $publisher->publish(textId: $textId, publisher: $this->admin(), effectiveFrom: $this->effectiveFrom(), expectedBodyHash: $this->expectedBodyHash($textId));

        $this->info("Text {$text->id} is published and takes effect on {$text->effective_from->utc()->format('Y-m-d H:i')} UTC.");
        $this->reportReadiness(readiness: $readiness, country: $text->country_code);

        return self::SUCCESS;
    }

    private function revoke(StorefrontLegalPublisher $publisher, StorefrontLegalReadiness $readiness): int
    {
        $text = $publisher->revoke(textId: $this->integerOption('id'), revoker: $this->admin(), reason: (string) $this->option('reason'));

        $this->info($text->status === StorefrontLegalDocument::STATUS_ARCHIVED
            ? "Text {$text->id} is revoked before it took effect: it is archived and never takes effect, and the text in force stays in force."
            : "Text {$text->id} is revoked: while it is the text in force the storefront does not sell. Write its replacement from a template (draft --template-file), edit it, approve it and publish it.");
        $this->reportReadiness(readiness: $readiness, country: $text->country_code);

        return self::SUCCESS;
    }

    private function discard(StorefrontLegalPublisher $publisher): int
    {
        $text = $publisher->discard(textId: $this->integerOption('id'), admin: $this->admin(), reason: (string) $this->option('reason'));

        $this->info("Text {$text->id} is discarded and kept as history.");

        return self::SUCCESS;
    }

    private function hashPrefixOf(StorefrontLegalDocument $text): string
    {
        return substr((string) $text->body_hash, offset: 0, length: self::MINIMUM_HASH_PREFIX_LENGTH);
    }

    private function reportHash(StorefrontLegalDocument $text): void
    {
        $this->line("Hash of the text as it stands: {$this->hashPrefixOf($text)}. Whoever approves or publishes it names this hash (--hash) after reading it, so that the step is taken on those words only.");
    }

    /**
     * The hash of the words the person read, when they name one: the whole hash of the text as it stands now, so that the service compares it again under its own lock (a text that
     * changes between this check and the step is refused there). Null only when --hash is left out: an empty or blank value is not "no hash", it is refused like any value that is not
     * a hash, so that a script whose variable turned out empty does not take the step on words nobody named.
     */
    private function expectedBodyHash(int $textId): ?string
    {
        $named = $this->option('hash');

        if ($named === null) {
            return null;
        }

        $prefix = strtolower(trim((string) $named));

        if (preg_match('/^[0-9a-f]{' . self::MINIMUM_HASH_PREFIX_LENGTH . ',64}$/', $prefix) !== 1) {
            throw new InvalidArgumentException('--hash is the first ' . self::MINIMUM_HASH_PREFIX_LENGTH . ' characters, or all 64, of the hash of the text you read, as list shows it.');
        }

        $text = StorefrontLegalDocument::query()->find($textId);

        if ($text === null) {
            throw new InvalidArgumentException("There is no legal text {$textId}.");
        }

        if (!str_starts_with((string) $text->body_hash, $prefix)) {
            throw new RuntimeException("Text {$textId} changed after it was read: its hash starts with {$this->hashPrefixOf($text)}, not {$prefix}. Read it again before taking this step.");
        }

        return (string) $text->body_hash;
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

    private function requiredOption(string $option, string $meaning): string
    {
        if (!$this->filled($option)) {
            throw new InvalidArgumentException("--{$option} is missing: it is {$meaning}.");
        }

        return (string) $this->option($option);
    }

    private function integerOption(string $option, string $meaning = 'the number of a text'): int
    {
        $value = trim((string) $this->option($option));

        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new InvalidArgumentException("--{$option} is {$meaning}.");
        }

        return (int) $value;
    }

    /** The admin who takes the step: an id in the table of admins whose status is on (the status the login checks), because a step by somebody who does not exist or may no longer act is a step by nobody. */
    private function admin(): int
    {
        $id = $this->integerOption('admin', meaning: 'the id of an admin');
        $admin = Admin::query()->find($id, columns: ['id', 'status']);

        if ($admin === null) {
            throw new InvalidArgumentException("There is no admin {$id}.");
        }

        if (!$admin->status) {
            throw new InvalidArgumentException("Admin {$id} is switched off and takes no step.");
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

        $content = (string) file_get_contents($path);

        if (str_starts_with($content, self::UTF8_BYTE_ORDER_MARK)) {
            $content = substr($content, offset: strlen(self::UTF8_BYTE_ORDER_MARK));
        }

        // A file in Windows-1256 or in UTF-16 would be stored as text that reads as nonsense, on a page a customer is asked to accept: its bytes are not UTF-8 (UTF-16 without a mark is
        // all but NUL bytes, which no text holds).
        if (!mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            throw new InvalidArgumentException("{$path} is not UTF-8 text: save the file as UTF-8 (a file in Windows-1256 or UTF-16 is refused).");
        }

        return $content;
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
