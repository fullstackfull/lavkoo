<?php

namespace Tests\Feature\Storefront;

use App\Services\Platform\Policy;
use App\Services\Platform\PolicyRegistry;
use App\Services\Storefront\StorefrontLegalPublisher;
use App\Services\Storefront\StorefrontLegalReadiness;
use App\Services\Storefront\StorefrontLegalReview;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\Support\NamesLegalDocumentTypes;
use Tests\Support\PlatformWriteScanner;
use Tests\TestCase;

/**
 * I2-06: the legal texts a customer accepts are not the operating rules the platform applies to itself. They are different things, they are kept in different stores, they are named
 * differently, and neither can change the other.
 *
 *   a legal text      the terms, the privacy policy, the refund policy of one storefront   rows of `storefront_legal_documents`, written by StorefrontLegalPublisher
 *   an operating rule a number or a switch (hours before an order is stuck, payout days)   rows of `business_settings`, declared by PolicyRegistry and written by Policy
 *
 * PolicyRegistryLegalSeparationTest pins what the two classes hold and that no group or policy of the registry is named for a legal document type. This one pins the rule about the
 * NAMES AND THE STORES: the titles the admin screen shows, that the readiness of a storefront comes from its documents and from nothing in `business_settings`, and that neither
 * store is written by the other side.
 *
 * WHAT EACH TEST IS EVIDENCE OF, which is not the same for all three (the lesson of I2-09: a source scan is honest evidence for "no code does this" and dishonest evidence for
 * "this cannot happen").
 *
 * 1. THE NAMES read the live class constant and the live configuration — not the source text, and not behaviour. They fail when a group, a policy or a title of the registry names a
 *    legal document type the configuration of the storefronts knows, in either direction: a type added to `gcc.storefronts.legal` that an existing rule is named for fails just as
 *    a rule named for an existing type does. Neither side is written out by hand here.
 * 2. THE STORES are BEHAVIOUR: they write and read the real tables through the real services. Saving every operating rule of the registry leaves the legal documents untouched;
 *    writing a legal text from its draft to its revocation leaves the business settings untouched, whatever path the writes take; and the readiness answer moves with the documents
 *    and with nothing a business setting says.
 * 3. THE SCANS read the text of the files of the legal chain. They are evidence that NO FILE OF THAT CHAIN NAMES the registry, its reader or the settings table — not that a write
 *    into those tables is impossible, which only the behaviour tests above can say, because a file reaches another store through a service it calls and a scan of one file cannot
 *    see that. The scan asserts first that it has read the files it names, so a wrong root cannot make it pass by finding nothing.
 *
 * THE LEGAL CHAIN, for the scans, is every file of the application named for it and the two that ask it for an answer without being named for it, all listed below by path.
 *
 * THE ONE BUSINESS SETTING THE LEGAL CHAIN KEEPS is the record of the independent legal review of a storefront (I2-07): who reviewed the texts, where the opinion is written and
 * on which day. That is an operating decision about the texts, not a text, a version or a readiness answer, and it is listed below by name with its reason and pinned to hold no
 * text. The state of a storefront (active, stopped) is another such decision and belongs to the lifecycle, not to this chain.
 */
class LegalVersusOperatingRulesTest extends TestCase
{
    use BuildsStorefrontLegalDocuments;
    use NamesLegalDocumentTypes;

    private const STOREFRONT = 'AE';

    private const AUTHOR = 31;

    private const APPROVER = 32;

    /**
     * The titles of two groups of the registry hold a legal document type as a whole word. A title is the label the admin screen prints, so these two are what the admin reads
     * above a set of operating rules; they are listed here with what they really are, and any other title that does the same fails. The renaming is the owner's to decide, and the
     * admin-label half of I2-06 (the menu naming operating rules and legal documents differently) waits for the legal-document screen of I2-03.
     *
     * @var array<string, string>
     */
    private const TITLES_THAT_HOLD_A_LEGAL_DOCUMENT_TYPE = [
        'title of group:finance' => "'payment_terms': the terms on which the platform pays a seller out — holding days, the minimum payout, the amount that needs two people — not the terms of service a customer accepts",
        'title of group:analytics' => "'analytics_and_privacy': what the analytics of the platform may keep about a visit — consent, masked addresses, retention in days — not the privacy policy a customer reads",
    ];

    /** How the files of the legal chain are found: every file of the application whose name says it is one. */
    private const LEGAL_CHAIN_FILE_NAME = 'StorefrontLegal';

    /** The files of the legal chain the scan must be reading: the day the rule that finds them stops finding one of these, the scan fails instead of passing on an empty list. */
    private const FILES_OF_THE_LEGAL_CHAIN = [
        'Console/Commands/StorefrontLegalReviewCommand.php',
        'Console/Commands/StorefrontLegalTextCommand.php',
        'Http/Controllers/Admin/Storefront/StorefrontLegalTextController.php',
        'Http/Requests/Admin/Storefront/StorefrontLegalTextRequest.php',
        'Models/StorefrontLegalAcceptance.php',
        'Models/StorefrontLegalDocument.php',
        'Services/Storefront/StorefrontLegalAcceptanceRecorder.php',
        'Services/Storefront/StorefrontLegalPublisher.php',
        'Services/Storefront/StorefrontLegalReadiness.php',
        'Services/Storefront/StorefrontLegalReview.php',
        'Services/Storefront/StorefrontLegalTextBoard.php',
    ];

    /**
     * The files that are not named for the legal chain and ask it for an answer. They are read with it, because a legal text, a version or a readiness answer taken from a business
     * setting would be the same defect wherever it is written.
     */
    private const FILES_THAT_ASK_THE_LEGAL_CHAIN = [
        'Console/Commands/StorefrontCurrencyReadinessCommand.php',
        'Services/Storefront/StorefrontPreflight.php',
    ];

    /** The files that declare, read and write the operating rules. */
    private const FILES_OF_THE_OPERATING_RULES = [
        'Services/Platform/PolicyRegistry.php',
        'Services/Platform/Policy.php',
        'Http/Controllers/Admin/Settings/PlatformPolicyController.php',
    ];

    /** How a file names the store of the operating rules, in each of the ways it could: the registry, its reader, their namespace, the model of the settings table, the table and the reader of a setting. */
    private const NAMES_OF_THE_OPERATING_RULE_STORE = [
        'the registry' => '/\bPolicyRegistry\b/',
        'its reader' => '/\bPolicy\b/',
        'their namespace' => '/App\\\\Services\\\\Platform/',
        'the model of the settings table' => '/\bBusinessSetting\b/',
        'the settings table' => '/\bbusiness_settings\b/',
        'the reader of a setting' => '/\bgetWebConfig\b/',
    ];

    /**
     * The only file of the legal chain that names the store of the operating rules, and what it names there. The record of the independent legal review of a storefront is a
     * business setting (`storefront_legal_review_<country>`) because it is a decision about the texts, like the tax approval and the lifecycle of a storefront; it holds no text, no
     * version and no readiness answer, which the behaviour test below pins. The two mentions are the sentence it prints when that table is missing.
     *
     * @var array<string, list<string>>
     */
    private const WHERE_THE_LEGAL_CHAIN_NAMES_THE_OPERATING_RULE_STORE = [
        'Services/Storefront/StorefrontLegalReview.php' => ['the settings table'],
    ];

    /** The models whose writes the scanner is to see on either side. */
    private const MODELS_OF_THE_TWO_STORES = ['BusinessSetting', 'StorefrontLegalDocument', 'StorefrontLegalAcceptance'];

    /** The tables and models of the legal store: what the legal chain may write. */
    private const THE_LEGAL_STORE = ['model StorefrontLegalAcceptance', 'model StorefrontLegalDocument', 'storefront_legal_acceptances', 'storefront_legal_documents'];

    /** The tables and models of the operating-rule store: what the registry's own files may write. */
    private const THE_OPERATING_RULE_STORE = ['business_settings', 'model BusinessSetting'];

    /** How the business pages and the cookie text of the Saudi storefront are named in `business_settings`: a text under one of these names is what a readiness answer must not read. */
    private const SETTING_NAMES_OF_THE_LEGAL_PAGES = [
        'terms_condition', 'privacy_policy', 'refund_policy', 'return_policy', 'cancellation_policy', 'shipping_policy', 'cookie_setting',
    ];

    /** @var array<string, array{existed: bool, value: mixed}> what the settings this test wrote held before it wrote them */
    private array $settingsToRestore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontLegalDocumentsTable();
        $this->buildTheTablesOfAStorefrontDecision(settingPrefix: StorefrontLegalReview::settingName(country: ''));
    }

    protected function tearDown(): void
    {
        foreach ($this->settingsToRestore as $type => $before) {
            $before['existed']
                ? DB::table('business_settings')->where('type', $type)->update(['value' => $before['value']])
                : DB::table('business_settings')->where('type', $type)->delete();
        }
        $this->settingsToRestore = [];
        clearWebConfigCacheKeys();

        parent::tearDown();
    }

    /**
     * Remembers what these business settings hold, so that tearDown can put them back: a real server keeps the rows of one test class for the next.
     *
     * @param list<string> $types
     */
    private function rememberBusinessSettings(array $types): void
    {
        foreach ($types as $type) {
            $existing = DB::table('business_settings')->where('type', $type)->first();
            $this->settingsToRestore[$type] ??= ['existed' => $existing !== null, 'value' => $existing->value ?? null];
        }
    }

    /** @param array<string, string> $valuesByType */
    private function writeBusinessSettings(array $valuesByType): void
    {
        $this->rememberBusinessSettings(types: array_keys($valuesByType));

        foreach ($valuesByType as $type => $value) {
            DB::table('business_settings')->updateOrInsert(['type' => $type], ['value' => $value, 'updated_at' => now()]);
        }

        clearWebConfigCacheKeys();
    }

    /** @return array<string, string> a text under every name a legal page of the platform is stored under, and a claim about the version of one */
    private function legalTextsWrittenAsBusinessSettings(): array
    {
        $settings = ['storefront_legal_terms_version' => '9'];
        foreach (self::SETTING_NAMES_OF_THE_LEGAL_PAGES as $name) {
            $settings[$name] = "A long approved {$name} of the test world, in every locale, effective since yesterday.";
        }

        return $settings;
    }

    /** @return array<string, string> every operating rule of the registry at a value other than the one it holds */
    private function everyOperatingRuleMoved(): array
    {
        $values = [];
        foreach (PolicyRegistry::definitions() as $key => $definition) {
            $values[$key] = match ($definition['type']) {
                'int' => (string) ($definition['default'] === $definition['max'] ? $definition['min'] : $definition['default'] + 1),
                'decimal', 'ratio' => (string) ($definition['default'] === $definition['max'] ? $definition['min'] : $definition['default'] + 0.01),
                'toggle' => $definition['default'] ? '0' : '1',
                default => null,
            };
        }

        return array_filter($values, static fn (?string $value): bool => $value !== null);
    }

    private function readiness(): StorefrontLegalReadiness
    {
        return app(StorefrontLegalReadiness::class);
    }

    /** @return list<array<string, mixed>> every row of a table, oldest first, as plain arrays */
    private function rowsOf(string $table): array
    {
        return array_map(static fn (object $row): array => (array) $row, DB::table($table)->orderBy('id')->get()->all());
    }

    /** @return list<SplFileInfo> every file of the application under a directory */
    private function filesUnder(string $directory): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }

    /** @return array<string, string> the code of every file of the legal chain, by its path under app/: the files named for it, and the ones listed as asking it for an answer */
    private function legalChain(): array
    {
        $chain = [];
        foreach ($this->filesUnder(app_path()) as $file) {
            if (str_starts_with($file->getBasename(), self::LEGAL_CHAIN_FILE_NAME)) {
                $path = str_replace([app_path() . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], ['', '/'], $file->getPathname());
                $chain[$path] = (string) file_get_contents($file->getPathname());
            }
        }
        foreach (self::FILES_THAT_ASK_THE_LEGAL_CHAIN as $path) {
            $chain[$path] = (string) file_get_contents(app_path($path));
        }
        ksort($chain);

        return $chain;
    }

    /** The code of one file of the legal chain, which the scan must have read for a check over it to mean anything. */
    private function codeOfTheLegalChainFile(string $path): string
    {
        $chain = $this->legalChain();
        $this->assertArrayHasKey($path, $chain, 'The scan did not read a file of the legal chain: its root or the rule that finds them is wrong.');

        return $chain[$path];
    }

    /**
     * @param array<string, string> $files the code of each, by path
     * @return array<string, list<string>> the ways each file names the store of the operating rules
     */
    private function namesOfTheOperatingRuleStoreIn(array $files): array
    {
        $found = [];
        foreach ($files as $path => $code) {
            $named = array_keys(array_filter(self::NAMES_OF_THE_OPERATING_RULE_STORE, static fn (string $pattern): bool => preg_match($pattern, $code) === 1));
            if ($named !== []) {
                $found[$path] = array_values($named);
            }
        }
        ksort($found);

        return $found;
    }

    /** @return list<string> every table and model the code writes */
    private function writesIn(string $code): array
    {
        return (new PlatformWriteScanner(platformModels: self::MODELS_OF_THE_TWO_STORES))->writesIn($code);
    }

    /**
     * Every group, policy and title of the registry, as it stands, that is named for a legal document type the storefronts know, as they stand. Neither side is written out here.
     *
     * @return array<string, list<string>> the types each name holds, by the label a failure prints
     */
    private function namesOfTheRegistryThatHoldALegalDocumentType(): array
    {
        return $this->legalDocumentTypesNamedIn(
            names: $this->namesOfTheRegistry(groups: PolicyRegistry::GROUPS) + $this->titlesOfTheRegistry(groups: PolicyRegistry::GROUPS),
            types: $this->legalDocumentTypesInTheCode(),
        );
    }

    public function test_no_name_of_the_registry_is_named_for_a_legal_document_type_of_the_storefronts_but_the_ones_listed(): void
    {
        $found = $this->namesOfTheRegistryThatHoldALegalDocumentType();
        $listed = array_merge($this->operatingRuleNamesThatHoldALegalDocumentType(), self::TITLES_THAT_HOLD_A_LEGAL_DOCUMENT_TYPE);

        $unlisted = array_diff_key($found, $listed);
        $this->assertSame(
            [],
            $unlisted,
            'A group, a policy or a title of the registry of operating rules is named for a legal document type of the storefronts (I2-06). An operating rule is a number the '
                . 'platform applies to itself; a legal document is a text a customer accepts. Name the rule for what it decides, or list it with the reason it is not a text. Found: '
                . implode('; ', array_map(static fn (array $types, string $name): string => "{$name} (" . implode(', ', $types) . ')', $unlisted, array_keys($unlisted))),
        );
        $this->assertSame(
            [],
            array_values(array_diff(array_keys($listed), array_keys($found))),
            'A listed name no longer holds a legal document type: take it off the list.',
        );
    }

    public function test_every_listed_name_says_why_it_is_an_operating_rule_and_not_a_legal_text(): void
    {
        foreach (array_merge($this->operatingRuleNamesThatHoldALegalDocumentType(), self::TITLES_THAT_HOLD_A_LEGAL_DOCUMENT_TYPE) as $name => $reason) {
            $this->assertGreaterThan(40, strlen($reason), "{$name} is tolerated without a reason");
        }
    }

    public function test_a_document_type_the_configuration_of_the_storefronts_adds_is_checked_against_the_registry_as_it_stands(): void
    {
        config(['gcc.storefronts.legal.required_documents' => ['inventory'], 'gcc.storefronts.legal.explicit_acceptance' => ['stock_policy']]);

        $found = $this->namesOfTheRegistryThatHoldALegalDocumentType();

        $this->assertSame(['inventory', 'stock_policy'], $this->legalDocumentTypesInTheCode());
        $this->assertSame(['inventory'], $found['group:inventory'] ?? [], 'A legal document type named like a group of the registry is not seen: the types are read from the configuration, not from a list in this test.');
        $this->assertSame(['stock_policy'], $found['title of group:inventory'] ?? [], 'A type of several words is not seen in a title.');
    }

    public function test_the_registry_knows_no_legal_document_type_of_the_storefronts(): void
    {
        foreach ($this->legalDocumentTypesInTheCode() as $type) {
            $this->assertFalse(PolicyRegistry::has(key: $type), "'{$type}' is a legal document type of the storefronts and is declared as an operating rule");
        }
    }

    public function test_the_readiness_of_a_storefront_comes_from_its_documents_and_no_business_setting_moves_it(): void
    {
        $this->writeBusinessSettings($this->legalTextsWrittenAsBusinessSettings());
        $this->writeBusinessSettings($this->everyOperatingRuleMoved());

        $this->assertFalse($this->readiness()->isReady(self::STOREFRONT), 'A storefront with no legal document of its own is not ready, whatever the business settings hold.');
        $this->assertNull($this->readiness()->currentTextFor(countryCode: self::STOREFRONT, documentType: 'terms', locale: 'en'));

        $this->publishRequiredLegalDocuments(country: self::STOREFRONT, reviewed: false, activated: false);

        $this->assertTrue($this->readiness()->isReady(self::STOREFRONT));
        $this->assertSame(1, (int) $this->readiness()->currentTextFor(countryCode: self::STOREFRONT, documentType: 'terms', locale: 'en')->version);

        $this->writeBusinessSettings($this->legalTextsWrittenAsBusinessSettings());

        $this->assertTrue($this->readiness()->isReady(self::STOREFRONT), 'The settings must not move a readiness answer in either direction.');
        $this->assertSame(1, (int) $this->readiness()->currentTextFor(countryCode: self::STOREFRONT, documentType: 'terms', locale: 'en')->version, 'The version of the text in force is the one the document holds, not the one a setting claims.');

        DB::table('storefront_legal_documents')->where('country_code', self::STOREFRONT)->where('document_type', 'terms')->delete();

        $this->assertFalse($this->readiness()->isReady(self::STOREFRONT), 'The answer follows the documents.');
    }

    public function test_saving_every_operating_rule_of_the_registry_writes_no_legal_text(): void
    {
        $this->publishRequiredLegalDocuments(country: self::STOREFRONT, reviewed: false, activated: false);
        $documents = $this->rowsOf('storefront_legal_documents');
        $moved = $this->everyOperatingRuleMoved();
        $this->rememberBusinessSettings(types: array_keys($moved));

        $changed = app(Policy::class)->save($moved);

        $this->assertSame(
            [],
            array_values(array_diff(array_keys(PolicyRegistry::definitions()), array_keys($changed))),
            'Every operating rule was given another value, so every one must have changed: a rule that did not move proves nothing about the legal store.',
        );
        $this->assertNotEmpty($documents);
        $this->assertSame($documents, $this->rowsOf('storefront_legal_documents'), 'Saving the operating rules of the platform changed a legal text of a storefront.');
        $this->assertSame((string) $moved['payout_holding_days'], (string) DB::table('business_settings')->where('type', 'payout_holding_days')->value('value'));
    }

    public function test_writing_a_legal_text_from_its_draft_to_its_revocation_writes_no_business_setting(): void
    {
        $this->writeBusinessSettings($this->everyOperatingRuleMoved());
        $settings = $this->rowsOf('business_settings');
        $publisher = app(StorefrontLegalPublisher::class);

        $draft = $publisher->draftFromTemplate(country: self::STOREFRONT, documentType: 'terms', locale: 'en', template: 'TEMPLATE: replace this sentence with the terms of the country.', author: self::AUTHOR);
        $publisher->edit(textId: $draft->id, body: 'The terms of the test world.', editor: self::AUTHOR);
        $publisher->approve(textId: $draft->id, approver: self::APPROVER);
        $publisher->publish(textId: $draft->id, publisher: self::APPROVER);
        $publisher->revoke(textId: $draft->id, revoker: self::APPROVER, reason: 'The test world is done with it.');

        $this->assertCount(1, $this->rowsOf('storefront_legal_documents'));
        $this->assertNotEmpty($settings);
        $this->assertSame($settings, $this->rowsOf('business_settings'), 'Writing a legal text changed a business setting: the operating rules of the platform are not where a legal text lives.');
    }

    public function test_the_one_business_setting_the_legal_chain_keeps_is_the_record_of_the_review_and_it_holds_no_text(): void
    {
        $before = DB::table('business_settings')->pluck('type')->all();

        $this->recordLegalReviewFor(self::STOREFRONT);

        $added = array_values(array_diff(DB::table('business_settings')->pluck('type')->all(), $before));
        $setting = StorefrontLegalReview::settingName(country: self::STOREFRONT);

        $this->assertSame([$setting], $added, 'Recording the independent legal review of a storefront wrote a business setting other than its own record.');
        $this->assertFalse(PolicyRegistry::has(key: $setting), 'The record of a legal review takes the name of an operating rule: saving that rule would write over the record.');
        $this->assertSame(
            ['reviewed_by', 'reference', 'reviewed_on', 'recorded_at'],
            array_keys((array) json_decode((string) DB::table('business_settings')->where('type', $setting)->value('value'), true)),
            'The record of a review says who reviewed, where the opinion is written and when. A text, a version or a hash in it would make a business setting a legal store.',
        );
    }

    public function test_the_scan_reads_the_files_of_the_legal_chain_it_names(): void
    {
        $chain = $this->legalChain();

        foreach (array_merge(self::FILES_OF_THE_LEGAL_CHAIN, self::FILES_THAT_ASK_THE_LEGAL_CHAIN) as $file) {
            $this->assertArrayHasKey($file, $chain, 'The scan did not read a file of the legal chain: its root or the rule that finds them is wrong.');
        }
        $this->assertGreaterThanOrEqual(count(self::FILES_OF_THE_LEGAL_CHAIN) + count(self::FILES_THAT_ASK_THE_LEGAL_CHAIN), count($chain));
        foreach (self::FILES_OF_THE_OPERATING_RULES as $file) {
            $this->assertFileExists(app_path($file));
        }
        $this->assertSame(
            ['Services/Storefront/StorefrontLegalReview.php'],
            array_keys($this->namesOfTheOperatingRuleStoreIn(['Services/Storefront/StorefrontLegalReview.php' => $this->codeOfTheLegalChainFile(path: 'Services/Storefront/StorefrontLegalReview.php')])),
            'The scan found nothing in the one file of the chain that does name the settings table, so it would find nothing anywhere.',
        );
    }

    public function test_no_file_of_the_legal_chain_names_the_store_of_the_operating_rules_but_the_record_of_the_review(): void
    {
        $found = $this->namesOfTheOperatingRuleStoreIn($this->legalChain());

        $this->assertSame(
            self::WHERE_THE_LEGAL_CHAIN_NAMES_THE_OPERATING_RULE_STORE,
            $found,
            'A file of the legal chain names the registry of operating rules, its reader or the settings table (I2-06). A legal text, its version and the readiness answer come from '
                . 'storefront_legal_documents alone. This reads the text of the files: a write made through another service is not seen here, which is what the behaviour tests above are for.',
        );
    }

    public function test_no_file_of_the_legal_chain_writes_a_store_that_is_not_the_legal_one(): void
    {
        $written = [];
        foreach ($this->legalChain() as $path => $code) {
            $outside = array_values(array_diff($this->writesIn($code), self::THE_LEGAL_STORE));
            if ($outside !== []) {
                $written[$path] = $outside;
            }
        }

        $this->assertSame([], $written, 'A file of the legal chain writes a store that is not the legal one.');
        $this->assertSame(['model StorefrontLegalDocument'], $this->writesIn($this->codeOfTheLegalChainFile(path: 'Services/Storefront/StorefrontLegalPublisher.php')), 'The scan sees no write where the one writer of the legal texts makes them.');
    }

    public function test_no_file_of_the_operating_rules_writes_the_legal_store(): void
    {
        $written = [];
        foreach (self::FILES_OF_THE_OPERATING_RULES as $file) {
            $written[$file] = $this->writesIn((string) file_get_contents(app_path($file)));
        }

        $this->assertSame([], array_values(array_diff(array_merge(...array_values($written)), self::THE_OPERATING_RULE_STORE)), 'A file of the operating rules writes a store that is not business_settings.');
        $this->assertSame(['model BusinessSetting'], $written['Services/Platform/Policy.php'], 'The scan sees no write where the reader of the operating rules saves them.');
    }

    /** @param list<string> $expected */
    #[DataProvider('codeThatNamesTheStoreOfTheOperatingRules')]
    public function test_the_scan_sees_the_store_of_the_operating_rules_named_in_each_of_the_ways_it_could_be(string $code, array $expected): void
    {
        $this->assertSame($expected === [] ? [] : ['a file' => $expected], $this->namesOfTheOperatingRuleStoreIn(['a file' => $code]));
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function codeThatNamesTheStoreOfTheOperatingRules(): array
    {
        return [
            'the reader imported' => ['use App\Services\Platform\Policy;', ['its reader', 'their namespace']],
            'the reader injected' => ['public function __construct(private readonly Policy $policies) {}', ['its reader']],
            'the registry read' => ['$definition = PolicyRegistry::definition(key: $type);', ['the registry']],
            'the settings model' => ['BusinessSetting::query()->where(\'type\', $type)->value(\'value\');', ['the model of the settings table']],
            'the settings table' => ["DB::table('business_settings')->where('type', \$type)->delete();", ['the settings table']],
            'a setting read through the helper' => ['$text = getWebConfig(name: \'terms_condition\');', ['the reader of a setting']],
            'the password policy of the platform is not the reader' => ['app(PasswordPolicy::class)->minimumLength();', []],
            'a legal page named in a string is not the settings table' => ["\$type = 'privacy_policy';", []],
            'the legal store' => ["StorefrontLegalDocument::query()->where('country_code', \$country)->get();", []],
        ];
    }
}
