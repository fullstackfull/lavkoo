<?php

namespace Tests\Feature\Storefront;

use App\Services\Platform\Policy;
use App\Services\Platform\PolicyRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use Tests\Support\NamesLegalDocumentTypes;
use Tests\TestCase;

/**
 * I2-06: the registry of the operating rules of the platform (PolicyRegistry, read through Policy) and the legal texts of a storefront are different things, and the legal text work does not
 * touch the registry. A rule such as "hours before an order is raised as stuck" is a number an operator tunes; a text such as the terms or the privacy policy is a document a lawyer reads. A registry
 * key or group that took the name of a legal document type (terms, privacy_policy) would blur the two, and a key shares its table, business_settings, with the setting that holds a business page.
 *
 * Criterion 1, unchanged: the groups and the policies of each, and the public members of the two classes, are written out below by hand. A test cannot diff a branch against main, so it pins what the
 * legal work must not disturb: adding, renaming, moving or removing a group or a policy fails it, with the list of what changed. Not pinned: the definitions (type, default, bounds, labels, help) and
 * the code of Policy, and the other file of app/Services/Platform (PasswordPolicy).
 *
 * Criterion 2, not named for a legal document: no group or policy name equals or contains, as a whole word, the type of a legal text (the types of the legal text store: the documents a storefront must
 * publish, read from its configuration, and the ones no storefront requires yet), no type is a policy of the registry, and no policy takes the setting name of a business page (the page list and the
 * cookie text, which shares the table of the registry). Whole words only, the singular and the plural being one word: 'returns' is 'return' and 'cookie' is 'cookies', but 'returnable' is not 'return'.
 * The check is proved on in-memory registries below, one that blurs the two and one that does not. A name that holds no type and is not a setting name of a page is not judged: what a rule is called
 * is the owner's, the test only keeps the names of the legal documents and of the pages out of the registry.
 *
 * KNOWN COINCIDENCES, LISTED BY NAME. Eleven names of the registry do contain a type as a whole word, and each is listed with the reason it is an operating rule all the same, by
 * the check itself (Tests\Support\NamesLegalDocumentTypes, which LegalVersusOperatingRulesTest reads too); any other name that does the same fails. Whether the owner wants them
 * renamed is the owner's to say. The titles of two groups use the words too ('payment_terms', 'analytics_and_privacy'); titles are labels, not names, and are checked by
 * LegalVersusOperatingRulesTest, not here.
 */
class PolicyRegistryLegalSeparationTest extends TestCase
{
    use NamesLegalDocumentTypes;

    private const UPDATE_THE_LIST = 'The registry of the platform\'s operating rules is not touched by the legal text work (I2-06). If this change is deliberate, update EXPECTED_POLICIES in this test in the same change, and say why in its message.';

    /** @var array<string, list<string>> the groups of PolicyRegistry and the policies of each, as they are today */
    private const EXPECTED_POLICIES = [
        'operations' => [
            'ops_stuck_order_hours', 'ops_stuck_stop_after_days', 'ops_sla_urgent_fraction', 'ops_sla_closing_minutes', 'ops_sla_soon_minutes',
            'ops_returns_response_hours', 'ops_returns_processing_hours', 'ops_finance_grace_hours', 'ops_batch_expiry_days',
        ],
        'inventory' => [
            'stock_cover_critical_days', 'stock_cover_low_days', 'stock_cover_raise_days', 'stock_cover_opportunity_days',
            'stock_velocity_days', 'stock_stale_days', 'stock_stale_minimum_units',
        ],
        'catalog' => ['catalog_quality_bar', 'catalog_price_swing_ratio', 'catalog_price_swing_hours'],
        'commerce' => [
            'commerce_max_pins', 'commerce_max_exclusions', 'commerce_max_boosts', 'commerce_max_boost_weight', 'commerce_max_chain',
            'commerce_max_collection_rules', 'commerce_max_segment_rules', 'commerce_max_campaign_overrides', 'commerce_max_variants',
        ],
        'shipping' => ['shipping_silent_hours', 'shipping_stop_after_days'],
        'compliance' => [
            'compliance_expiry_notice_days',
            'health_watch_cancellation_rate', 'health_watch_return_rate', 'health_watch_refund_rate', 'health_watch_rating', 'health_watch_strikes',
            'health_at_risk_cancellation_rate', 'health_at_risk_return_rate', 'health_at_risk_refund_rate', 'health_at_risk_rating', 'health_at_risk_strikes',
        ],
        'security' => ['password_minimum_length', 'auth_attempts_per_minute', 'recaptcha_minimum_score', 'api_requests_per_minute'],
        'analytics' => [
            'analytics_enabled', 'analytics_respect_do_not_track', 'analytics_require_consent', 'analytics_mask_ip', 'analytics_store_country',
            'analytics_exclude_bots', 'analytics_exclude_internal', 'analytics_session_gap_minutes', 'analytics_engaged_after_seconds',
            'analytics_retention_event_days', 'analytics_retention_session_days', 'analytics_retention_daily_days',
        ],
        'developer' => ['developer_console_enabled', 'developer_console_allow_writes', 'developer_console_rate_limit', 'developer_record_response_shapes'],
        'integrations' => ['webhook_max_attempts', 'webhook_timeout_seconds', 'webhook_backoff_minutes'],
        'finance' => [
            'payout_holding_days', 'payout_minimum_amount', 'payout_dual_control_amount', 'payout_bank_change_freeze_hours', 'reconciliation_lookback_days',
        ],
        'platform' => ['limit_automation_sweep', 'limit_audit_rows', 'limit_control_tower_rows', 'limit_admin_seller_rollup'],
    ];

    /** @var list<string> */
    private const PUBLIC_MEMBERS_OF_THE_REGISTRY = ['GROUPS', 'definition', 'definitions', 'has', 'keysIn'];

    /** @var list<string> */
    private const PUBLIC_MEMBERS_OF_THE_READER = ['__construct', 'all', 'float', 'get', 'int', 'isSet', 'rules', 'save'];

    /** @var list<string> the types of legal document that a storefront is asked for or may be asked for; the documents it must publish are added from its configuration */
    private const LEGAL_DOCUMENT_TYPES = ['terms', 'privacy', 'refund', 'return', 'cancellation', 'shipping', 'cookies'];

    /** @var list<string> how the business pages of the Saudi storefront and its cookie text are named in business_settings and in the page list: a policy with one of these names would be written over a legal page */
    private const SETTING_NAMES_OF_THE_LEGAL_PAGES = [
        'terms_condition', 'privacy_policy', 'refund_policy', 'return_policy', 'cancellation_policy', 'shipping_policy',
        'refund-policy', 'return-policy', 'cancellation-policy', 'shipping-policy',
        'terms-and-conditions', 'privacy-policy', 'about_us', 'about-us',
        'cookie_setting', 'cookie_policy', 'cookie-policy', 'cookies_policy',
    ];

    /** @return array<string, list<string>> */
    private function policiesByGroup(): array
    {
        return array_map(static fn (array $group): array => array_keys($group['policies']), PolicyRegistry::GROUPS);
    }

    /**
     * @param array<string, list<string>> $expected
     * @param array<string, list<string>> $actual
     * @return list<string> what changed, in words
     */
    private function driftFrom(array $expected, array $actual): array
    {
        $drift = [];

        foreach (array_diff_key($actual, $expected) as $group => $policies) {
            $drift[] = "group '{$group}' was added, with " . implode(', ', $policies);
        }
        foreach (array_diff_key($expected, $actual) as $group => $policies) {
            $drift[] = "group '{$group}' was removed, with " . implode(', ', $policies);
        }
        foreach (array_intersect_key($expected, $actual) as $group => $policies) {
            foreach (array_diff($actual[$group], $policies) as $policy) {
                $drift[] = "policy '{$policy}' was added to group '{$group}'";
            }
            foreach (array_diff($policies, $actual[$group]) as $policy) {
                $drift[] = "policy '{$policy}' was removed from group '{$group}'";
            }
        }

        return $drift;
    }

    /** @return list<string> the constants and the public methods of a class, sorted */
    private function publicMembersOf(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $members = array_merge(
            array_keys($reflection->getConstants(ReflectionClassConstant::IS_PUBLIC)),
            array_map(static fn (ReflectionMethod $method): string => $method->getName(), $reflection->getMethods(ReflectionMethod::IS_PUBLIC)),
        );
        sort($members);

        return $members;
    }

    /** @return list<string> the legal document types the names of the registry are checked against */
    private function legalDocumentTypes(): array
    {
        return array_values(array_unique(array_merge(self::LEGAL_DOCUMENT_TYPES, $this->legalDocumentTypesInTheCode())));
    }

    /**
     * @param array<string, array{policies: array<string, mixed>}> $groups
     * @param list<string> $types
     * @return array<string, list<string>> each name of a group ('group:x') or of a policy ('policy:x') that equals or contains a type as a whole word, with the types it names
     */
    private function namesForLegalDocumentTypes(array $groups, array $types): array
    {
        return $this->legalDocumentTypesNamedIn(names: $this->namesOfTheRegistry(groups: $groups), types: $types);
    }

    /**
     * @param array<string, array{policies: array<string, mixed>}> $groups
     * @param list<string> $names
     * @return list<string> the policies that are called one of the names
     */
    private function policiesCalled(array $groups, array $names): array
    {
        $policies = array_merge(...array_map(static fn (array $group): array => array_keys($group['policies']), array_values($groups)));

        return array_values(array_intersect($policies, $names));
    }

    public function test_the_groups_and_the_policies_of_the_registry_are_the_ones_written_here(): void
    {
        $this->assertSame([], $this->driftFrom(expected: self::EXPECTED_POLICIES, actual: $this->policiesByGroup()), self::UPDATE_THE_LIST);
    }

    public function test_no_policy_is_declared_in_two_groups(): void
    {
        $declared = array_merge(...array_values($this->policiesByGroup()));
        $declaredTwice = array_keys(array_filter(array_count_values($declared), static fn (int $times): bool => $times > 1));

        $this->assertSame([], $declaredTwice, 'a policy declared in two groups is kept once by definitions() and the other is lost');
        $this->assertCount(count($declared), PolicyRegistry::definitions());
    }

    public function test_the_registry_and_its_reader_offer_the_members_written_here(): void
    {
        $expectedOfTheRegistry = self::PUBLIC_MEMBERS_OF_THE_REGISTRY;
        $expectedOfTheReader = self::PUBLIC_MEMBERS_OF_THE_READER;
        sort($expectedOfTheRegistry);
        sort($expectedOfTheReader);

        $this->assertSame($expectedOfTheRegistry, $this->publicMembersOf(class: PolicyRegistry::class), self::UPDATE_THE_LIST);
        $this->assertSame($expectedOfTheReader, $this->publicMembersOf(class: Policy::class), self::UPDATE_THE_LIST);
    }

    public function test_the_check_of_the_registry_sees_a_policy_that_was_added_renamed_moved_or_removed(): void
    {
        $expected = ['alpha' => ['one', 'two'], 'beta' => ['three']];

        $this->assertSame([], $this->driftFrom(expected: $expected, actual: $expected));
        $this->assertSame(["policy 'four' was added to group 'beta'"], $this->driftFrom(expected: $expected, actual: ['alpha' => ['one', 'two'], 'beta' => ['three', 'four']]));
        $this->assertSame(["policy 'two' was removed from group 'alpha'", "policy 'two' was added to group 'beta'"], $this->driftFrom(expected: $expected, actual: ['alpha' => ['one'], 'beta' => ['three', 'two']]));
        $this->assertSame(["policy 'uno' was added to group 'alpha'", "policy 'one' was removed from group 'alpha'"], $this->driftFrom(expected: $expected, actual: ['alpha' => ['uno', 'two'], 'beta' => ['three']]));
        $this->assertSame(["group 'gamma' was added, with five"], $this->driftFrom(expected: $expected, actual: $expected + ['gamma' => ['five']]));
        $this->assertSame(["group 'beta' was removed, with three"], $this->driftFrom(expected: $expected, actual: ['alpha' => ['one', 'two']]));
    }

    public function test_a_type_that_only_the_configuration_of_the_legal_texts_names_is_checked_too(): void
    {
        config(['gcc.storefronts.legal.required_documents' => ['terms', 'consumer_rights']]);

        $this->assertContains('consumer_rights', $this->legalDocumentTypes());
        $this->assertSame(
            ['policy:consumer_rights_days' => ['consumer_rights']],
            $this->namesForLegalDocumentTypes(groups: ['legal' => ['policies' => ['consumer_rights_days' => []]]], types: $this->legalDocumentTypes()),
        );
    }

    public function test_no_group_or_policy_of_the_registry_is_named_for_a_legal_document_type_but_the_operating_thresholds_listed_here(): void
    {
        $found = $this->namesForLegalDocumentTypes(groups: PolicyRegistry::GROUPS, types: $this->legalDocumentTypes());
        $listed = array_keys($this->operatingRuleNamesThatHoldALegalDocumentType());

        $unlisted = array_diff_key($found, array_flip($listed));
        $this->assertSame(
            [],
            $unlisted,
            'A group or a policy of the registry is named for a legal document type. Operating rules and legal texts are different things (I2-06): name the rule for what it does. Found: '
                . implode('; ', array_map(static fn (array $types, string $name): string => "{$name} (" . implode(', ', $types) . ')', $unlisted, array_keys($unlisted))),
        );
        $this->assertSame(
            [],
            array_values(array_diff($listed, array_keys($found))),
            'A name on the list of operating thresholds that share a word with a legal document type no longer does: take it off the list in this test.',
        );
    }

    public function test_every_name_on_the_list_of_shared_words_says_why_it_is_not_a_legal_text(): void
    {
        foreach ($this->operatingRuleNamesThatHoldALegalDocumentType() as $name => $reason) {
            $this->assertGreaterThan(20, strlen($reason), "{$name} has no reason");
        }
    }

    public function test_no_legal_document_type_is_a_policy_of_the_registry_and_the_reader_does_not_know_it(): void
    {
        $reader = new Policy();

        foreach ($this->legalDocumentTypes() as $type) {
            $this->assertFalse(PolicyRegistry::has(key: $type), "'{$type}' is a legal document type and is declared as a policy");
            $this->assertNull(PolicyRegistry::definition(key: $type));
            $this->assertThrows(fn () => $reader->get(key: $type), InvalidArgumentException::class, "Unknown policy: {$type}");
        }
    }

    public function test_no_policy_takes_the_setting_name_of_a_legal_page(): void
    {
        $this->assertSame([], $this->policiesCalled(groups: PolicyRegistry::GROUPS, names: self::SETTING_NAMES_OF_THE_LEGAL_PAGES));
        foreach (self::SETTING_NAMES_OF_THE_LEGAL_PAGES as $name) {
            $this->assertFalse(PolicyRegistry::has(key: $name), "'{$name}' is the setting name of a legal page and is declared as a policy: saving the policy would write over the page");
        }
    }

    #[DataProvider('registriesThatBlurTheOperatingRulesAndTheLegalTexts')]
    public function test_the_check_names_a_group_or_a_policy_that_is_named_for_a_legal_document_type(array $groups, array $types, array $expectedNames): void
    {
        $this->assertSame($expectedNames, $this->namesForLegalDocumentTypes(groups: $groups, types: $types));
    }

    public static function registriesThatBlurTheOperatingRulesAndTheLegalTexts(): array
    {
        $types = ['terms', 'privacy', 'refund', 'return', 'cancellation', 'shipping', 'cookies'];

        return [
            'a policy called terms' => [['legal' => ['policies' => ['terms' => []]]], $types, ['policy:terms' => ['terms']]],
            'a policy called privacy_policy' => [['legal' => ['policies' => ['privacy_policy' => []]]], $types, ['policy:privacy_policy' => ['privacy']]],
            'a policy for the refund window' => [['finance' => ['policies' => ['refund_window_days' => []]]], $types, ['policy:refund_window_days' => ['refund']]],
            'a group called cookies' => [['cookies' => ['policies' => ['retention_days' => []]]], $types, ['group:cookies' => ['cookies']]],
            'a policy that ends in a type' => [['ops' => ['policies' => ['courier_shipping' => []]]], $types, ['policy:courier_shipping' => ['shipping']]],
            'a policy written in camel case' => [['ops' => ['policies' => ['returnWindow' => []]]], $types, ['policy:returnWindow' => ['return']]],
            'a policy written with hyphens' => [['ops' => ['policies' => ['cancellation-policy' => []]]], $types, ['policy:cancellation-policy' => ['cancellation']]],
            'a policy that names two types' => [['ops' => ['policies' => ['terms_and_privacy_mode' => []]]], $types, ['policy:terms_and_privacy_mode' => ['terms', 'privacy']]],
            'a type of several words' => [['ops' => ['policies' => ['terms_of_service_version' => []]]], ['terms_of_service'], ['policy:terms_of_service_version' => ['terms_of_service']]],
            'a plural of a type' => [['ops' => ['policies' => ['returns_policy' => [], 'refunds_window' => []]]], $types, ['policy:refunds_window' => ['refund'], 'policy:returns_policy' => ['return']]],
            'a singular of a type that is a plural' => [['ops' => ['policies' => ['cookie_text' => [], 'term_days' => []]]], $types, ['policy:cookie_text' => ['cookies'], 'policy:term_days' => ['terms']]],
            'a plural of a type of several words' => [['ops' => ['policies' => ['terms_of_services_version' => []]]], ['terms_of_service'], ['policy:terms_of_services_version' => ['terms_of_service']]],
            'a group and its policy' => [['shipping' => ['policies' => ['shipping_label_size' => []]]], $types, ['group:shipping' => ['shipping'], 'policy:shipping_label_size' => ['shipping']]],
        ];
    }

    #[DataProvider('registriesThatKeepThemApart')]
    public function test_the_check_leaves_a_name_alone_when_it_does_not_contain_a_type_as_a_whole_word(array $groups, array $types): void
    {
        $this->assertSame([], $this->namesForLegalDocumentTypes(groups: $groups, types: $types));
    }

    public static function registriesThatKeepThemApart(): array
    {
        $types = ['terms', 'privacy', 'refund', 'return', 'cancellation', 'shipping', 'cookies'];

        return [
            'operating rules' => [['operations' => ['policies' => ['ops_stuck_order_hours' => [], 'stock_cover_low_days' => []]]], $types],
            'a word that only starts with a type' => [['ops' => ['policies' => ['returnable_items' => [], 'refunded_total' => []]]], $types],
            'a word that only holds a type' => [['ops' => ['policies' => ['terminal_id' => [], 'shippingless' => []]]], $types],
            'a type of several words needs all of them' => [['ops' => ['policies' => ['terms_version' => []]]], ['terms_of_service']],
        ];
    }

    public function test_the_check_sees_a_type_that_is_a_policy_and_a_policy_that_takes_the_setting_name_of_a_legal_page(): void
    {
        $registry = ['legal' => ['policies' => ['terms' => [], 'privacy_policy' => [], 'ops_stuck_order_hours' => []]]];

        $this->assertSame(['terms'], $this->policiesCalled(groups: $registry, names: ['terms', 'privacy', 'refund']));
        $this->assertSame(['privacy_policy'], $this->policiesCalled(groups: $registry, names: self::SETTING_NAMES_OF_THE_LEGAL_PAGES));
        $this->assertSame([], $this->policiesCalled(groups: ['ops' => ['policies' => ['ops_stuck_order_hours' => []]]], names: self::SETTING_NAMES_OF_THE_LEGAL_PAGES));
    }
}
