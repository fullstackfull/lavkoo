<?php

namespace Tests\Feature\Storefront;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\ComplianceClaimScanner;

/**
 * I2-07 criteria 0 and 4: no string a person can read says a country is legally compliant, and a test scans the language files and the views for such claims (the readiness answers an API returns are
 * scanned in ComplianceClaimPayloadsTest). The platform records what a person tells it about a market and reviews and approves nothing, so a refusal says a storefront is not open for checkout, or not
 * approved, and nothing says it is compliant, certified or approved by an authority.
 *
 * What is read: every PHP and JSON file of every language directory under resources/lang and under the lang directory of a module (by the key of each string, which translate() shows when a text is
 * missing, and by its text), and every Blade view under resources/views, resources/themes (the customer storefront) and Modules/<module>/resources/views, line by line. The phrases are in
 * Tests\Support\ComplianceClaimScanner, in English and in Arabic; each is proved below on sentences that must be flagged and on sentences that must not, and the walkers are proved on a tree of fixtures. It
 * needs no database.
 *
 * What the refusals say: the English and the Arabic text of the refusal of a sale and of the maintenance message are pinned by what they say (not open for checkout, under maintenance), so that a refusal
 * cannot be reworded into one that says nothing or says the storefront is open. The English refusal is also read through the real routes in ComplianceClaimPayloadsTest; the Arabic one is read in its language
 * file only.
 *
 * FINDINGS, NOT APPROVED. Four sentences of the tax setup help of the admin and seller panels (an imported template: it says "restaurant" and "food-wise") do claim compliance: F-1 "stays legally
 * compliant", F-2 "stay tax compliant", F-3 and F-4 "accurate and compliant billing". They are tolerated by name in KNOWN_CLAIMS, in the views listed, and only in staff panels (F-4 is shown by no view); a
 * language string is tolerated only with a text that is on record for its key, in any of the languages it has. A customer-facing view, a language string that is not one of the four, a reworded text of one of
 * them in any language, or a PHP file that uses one of them fails, and so does an entry that no longer matches, so that the list can only shrink. The legitimate occurrences (a disclaimer that says the text
 * is not legal advice) would go to LEGITIMATE with the reason; today there is none. Not covered: strings in the JavaScript assets, in the PHP code that builds a message (read through the API payloads where
 * ComplianceClaimPayloadsTest drives it), in the database, and in the Flutter apps (other repositories).
 */
class ComplianceClaimScanTest extends TestCase
{
    private const WHAT_TO_DO = 'The platform records what a person tells it about a market and reviews and approves nothing: say that a storefront is not open for checkout, or not approved, or say nothing (I2-07). '
        . 'A sentence that is not a claim but matches one is decided here: add it to LEGITIMATE with the reason, never to KNOWN_CLAIMS.';

    /** @var list<string> the staff panels: a tolerated claim may be shown only there, never to a customer */
    private const STAFF_PANEL_VIEWS = [
        'resources/views/admin-views/',
        'resources/views/vendor-views/',
        'resources/views/seller-views/',
        'resources/views/layouts/admin/',
        'resources/views/layouts/vendor/',
        'resources/views/layouts/seller/',
        'Modules/TaxModule/resources/views/6valley/',
    ];

    /**
     * Findings that exist today and are tolerated until the owner words them again: the key of the string in the language files, the texts it has in the language files (English, Arabic, Bengali and Hindi, once
     * each), the names of the claims it makes in any language, the views that show it, and why it is tolerated. None of them is legitimate.
     *
     * @var list<array{key: string, texts: list<string>, claims: list<string>, views: list<string>, reason: string}>
     */
    private const KNOWN_CLAIMS = [
        [
            'key' => 'ensure_your_restaurant_stays_legally_compliant.',
            'texts' => [
                'Ensure your restaurant stays legally compliant.',
                'নিশ্চিত করুন যে আপনার রেস্তোরাঁ আইনিভাবে মেনে চলছে।',
                'सुनिश्चित करें कि आपका रेस्तरां कानूनी रूप से अनुपालनशील रहे।',
                'تأكد من أن مطعمك يظل متوافقًا مع القانون.',
            ],
            'claims' => ['legally compliant', 'compliant', 'متوافق مع القانون'],
            'views' => ['Modules/TaxModule/resources/views/6valley/offcanvas/_vat-tax-create.blade.php'],
            'reason' => 'F-1. The help line of the form that creates a VAT or tax rate in the admin panel: it promises the business stays legally compliant once a tax is set up. A claim about the business, shown to staff only.',
        ],
        [
            'key' => 'add_your_tin_number_upload_the_certificate_and_set_the_expiry_date_to_stay_tax_compliant',
            'texts' => [
                'Add your tin number upload the certificate and set the expiry date to stay tax compliant',
                'আপনার টিন নম্বর যোগ করুন সার্টিফিকেট আপলোড করুন এবং ট্যাক্স অনুগত থাকার মেয়াদ শেষ হওয়ার তারিখ সেট করুন',
                'अपना टिन नंबर जोड़ें, प्रमाणपत्र अपलोड करें और कर अनुपालन में बने रहने के लिए समाप्ति तिथि निर्धारित करें',
                'أضف رقمك القصديري وقم بتحميل الشهادة وحدد تاريخ انتهاء الصلاحية لتظل متوافقًا مع الضرائب',
            ],
            'claims' => ['compliant', 'متوافق مع القانون'],
            'views' => [
                'resources/views/layouts/vendor/partials/offcanvas/_shop-info-other-setup.blade.php',
                'resources/views/layouts/vendor/partials/offcanvas/_shop-other-setup.blade.php',
            ],
            'reason' => 'F-2. The guide of the seller panel for the tax number and its certificate: it tells a seller how to stay tax compliant. Shown to a seller, never to a customer.',
        ],
        [
            'key' => 'You_can_apply_VAT/Taxes_order-wise,_product-wise,_category-wise,_ensuring_accurate_and_compliant_billing_across_all_operations.',
            'texts' => [
                'You can apply VAT/Taxes order-wise, product-wise, category-wise, ensuring accurate and compliant billing across all operations.',
                'يمكنك تطبيق ضريبة القيمة المضافة/الضرائب حسب الطلب، حسب المنتج، حسب الفئة، مما يضمن إعداد فواتير دقيقة ومتوافقة عبر جميع العمليات.',
            ],
            'claims' => ['compliant'],
            'views' => ['Modules/TaxModule/resources/views/6valley/offcanvas/_setup-vat-tax-calculation.blade.php'],
            'reason' => 'F-3. The guide of the system tax setup in the admin panel: it says the setup ensures compliant billing. Shown to staff only.',
        ],
        [
            'key' => 'You_can_apply_taxes_order-wise,_food-wise,_category-wise,_or_even_on_specific_services_like_delivery_and_reservation_fees_ensuring_accurate_and_compliant_billing_across_all_operations.',
            'texts' => [
                'You can apply taxes order-wise, food-wise, category-wise, or even on specific services like delivery and reservation fees ensuring accurate and compliant billing across all operations.',
                'আপনি অর্ডার অনুযায়ী, খাদ্য-ভিত্তিক, বিভাগ অনুযায়ী ট্যাক্স প্রয়োগ করতে পারেন, বা এমনকি নির্দিষ্ট পরিষেবাগুলিতে যেমন ডেলিভারি এবং রিজার্ভেশন ফি সমস্ত ক্রিয়াকলাপে সঠিক এবং সঙ্গতিপূর্ণ বিলিং নিশ্চিত করে।',
                "आप ऑर्डर-वार, भोजन-वार, श्रेणी-वार, या यहां तक \u{200B}\u{200B}कि डिलीवरी और आरक्षण शुल्क जैसी विशिष्ट सेवाओं पर भी कर लागू कर सकते हैं, जिससे सभी कार्यों में सटीक और अनुपालन बिलिंग सुनिश्चित हो सके।",
                'يمكنك تطبيق الضرائب حسب الطلب، أو الطعام، أو الفئة، أو حتى على خدمات محددة مثل رسوم التوصيل والحجز، مما يضمن إعداد فواتير دقيقة ومتوافقة عبر جميع العمليات.',
            ],
            'claims' => ['compliant'],
            'views' => [],
            'reason' => 'F-4. The food-delivery wording of F-3, left in the language files by the template: no view and no PHP file shows it.',
        ],
    ];

    /**
     * Occurrences that match a phrase and are not a claim, each with the reason: a disclaimer, a quotation of what the platform does not say. The same shape as KNOWN_CLAIMS; may be shown to a customer.
     *
     * @var list<array{key: string, texts: list<string>, claims: list<string>, views: list<string>, reason: string}>
     */
    private const LEGITIMATE = [];

    /** @var list<array<string, mixed>>|null */
    private static ?array $languageFindings = null;

    /** @var list<array<string, mixed>>|null */
    private static ?array $viewFindings = null;

    private ?string $fixtureRoot = null;

    protected function tearDown(): void
    {
        if ($this->fixtureRoot !== null) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->fixtureRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->fixtureRoot);
            $this->fixtureRoot = null;
        }

        parent::tearDown();
    }

    private static function repository(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<string> */
    private static function viewRoots(): array
    {
        return array_merge(
            [self::repository() . '/resources/views', self::repository() . '/resources/themes'],
            glob(self::repository() . '/Modules/*/[Rr]esources/views', GLOB_ONLYDIR) ?: [],
        );
    }

    /** @return list<string> */
    private static function languageRoots(): array
    {
        return array_merge(
            [self::repository() . '/resources/lang'],
            glob(self::repository() . '/Modules/*/lang', GLOB_ONLYDIR) ?: [],
            glob(self::repository() . '/Modules/*/[Rr]esources/lang', GLOB_ONLYDIR) ?: [],
        );
    }

    /** @return list<array<string, mixed>> */
    private static function languageFindings(): array
    {
        return self::$languageFindings ??= (new ComplianceClaimScanner())->inLanguageFiles(languageRoots: self::languageRoots(), repository: self::repository());
    }

    /** @return list<array<string, mixed>> */
    private static function viewFindings(): array
    {
        return self::$viewFindings ??= (new ComplianceClaimScanner())->inViews(directories: self::viewRoots(), repository: self::repository());
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>> the findings no entry tolerates
     */
    private function untolerated(array $findings, array $entries): array
    {
        return array_values(array_filter($findings, fn (array $finding): bool => !$this->isTolerated(finding: $finding, entries: $entries)));
    }

    /**
     * A string is tolerated by the entry that names its key and holds its text, when it claims nothing the entry does not name; a line of a view by the entry that lists the view and the key it passes to
     * translate().
     *
     * @param array<string, mixed> $finding
     * @param list<array<string, mixed>> $entries
     */
    private function isTolerated(array $finding, array $entries): bool
    {
        foreach ($entries as $entry) {
            if (array_diff($finding['claims'], $entry['claims']) !== []) {
                continue;
            }

            $isNamed = match ($finding['source']) {
                ComplianceClaimScanner::LANGUAGE => $finding['key'] === $entry['key'] && in_array($finding['text'], $entry['texts'], true),
                ComplianceClaimScanner::VIEW => in_array($finding['file'], $entry['views'], true) && in_array($entry['key'], $finding['keys'], true),
                default => false,
            };

            if ($isNamed) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $findings @return list<string> */
    private function described(array $findings): array
    {
        $scanner = new ComplianceClaimScanner();

        return array_map(static fn (array $finding): string => $scanner->describe(finding: $finding), $findings);
    }

    /** @return string a directory of fixtures that the test removes when it ends */
    private function fixtureTree(array $files): string
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/claim_scan_' . bin2hex(random_bytes(6));

        foreach ($files as $path => $contents) {
            if (!is_dir(dirname($this->fixtureRoot . '/' . $path))) {
                mkdir(dirname($this->fixtureRoot . '/' . $path), 0777, true);
            }
            file_put_contents($this->fixtureRoot . '/' . $path, $contents);
        }

        return $this->fixtureRoot;
    }

    #[DataProvider('sentencesThatClaimCompliance')]
    public function test_the_scan_flags_a_sentence_that_claims_compliance(string $sentence, string $claim): void
    {
        $this->assertContains($claim, (new ComplianceClaimScanner())->claimsIn(text: $sentence));
    }

    public static function sentencesThatClaimCompliance(): array
    {
        return [
            'legally compliant' => ['This country is legally compliant.', 'legally compliant'],
            'in capitals' => ['LEGALLY COMPLIANT', 'legally compliant'],
            'a translation key' => ['ensure_your_restaurant_stays_legally_compliant.', 'legally compliant'],
            'with a hyphen' => ['The Kuwait storefront is legally-compliant', 'legally compliant'],
            'legal compliance guaranteed' => ['Guaranteed legal compliance', 'guaranteed legal'],
            'fully compliant' => ['The Kuwait storefront is fully compliant.', 'fully compliant'],
            'in full compliance' => ['Operating in full compliance', 'fully compliant'],
            'a hundred per cent' => ['100% compliant storefront', '100% compliant'],
            'a hundred percent spelled out' => ['100 percent compliant', '100% compliant'],
            'compliant with the law' => ['Compliant with the law of Qatar', 'compliant with the law'],
            'compliant with a regulation' => ['Compliant with VAT regulations', 'compliant with the law'],
            'complies with the regulations' => ['Complies with local regulations', 'compliant with the law'],
            'compliant' => ['Kuwait: compliant', 'compliant'],
            'stay tax compliant' => ['to stay tax compliant', 'compliant'],
            'certified' => ['Certified by the ministry', 'certified'],
            'certified text' => ['Certified legal texts', 'certified'],
            'approved by the authority' => ['Approved by the authority', 'approved by the authority'],
            'approved by the government' => ['Approved by the government of Oman', 'approved by the authority'],
            'government approved' => ['Government-approved texts', 'government approved'],
            'a regulator approved' => ['Regulator approved', 'government approved'],
            'guaranteed to be legal' => ['Guaranteed by law', 'guaranteed legal'],
            'we guarantee legal' => ['We guarantee legal use', 'guaranteed legal'],
            'legally approved' => ['This market is legally approved', 'legally approved'],
            'fully legal' => ['Fully legal in the UAE', 'legally approved'],
            'a negation that is not just before the claim' => ['This text does not make you legally compliant', 'legally compliant'],
            'a negation of something else' => ['Not only legally compliant but also certified', 'legally compliant'],
            'arabic: legally compliant' => ['هذا المتجر متوافق قانونيا', 'متوافق قانونيا'],
            'arabic: legally compliant with the vowel marks' => ['النص متوافق قانونيًا', 'متوافق قانونيا'],
            'arabic: compliant' => ['المتجر ممتثل لجميع الأنظمة', 'ممتثل'],
            'arabic: compliant with the digits of the arabic script' => ['ممتثل ١٠٠٪', 'ممتثل'],
            'arabic: compliant with the law' => ['تأكد من أن مطعمك يظل متوافقًا مع القانون.', 'متوافق مع القانون'],
            'arabic: compliant with the regulations' => ['السوق متوافق مع اللوائح', 'متوافق مع القانون'],
            'arabic: compliant with the tax' => ['المتجر متوافق مع ضريبة القيمة المضافة', 'متوافق مع القانون'],
            'arabic: conforming to the law' => ['السوق مطابق للقانون', 'متوافق مع القانون'],
            'arabic: abiding by the law' => ['نحن ملتزمون بالقانون', 'متوافق مع القانون'],
            'arabic: fully compliant' => ['المتجر متوافق بالكامل', 'متوافق بالكامل'],
            'arabic: approved by the authorities' => ['هذه الدولة معتمدة من الجهات الحكومية', 'معتمد من الجهات'],
            'arabic: approved by the ministry' => ['هذا المتجر معتمد من وزارة التجارة', 'معتمد من الجهات'],
            'arabic: officially certified' => ['المتجر معتمد رسميا', 'معتمد من الجهات'],
            'arabic: guaranteed legally' => ['مضمون قانونيا', 'مضمون قانونيا'],
            'arabic: we guarantee legal compliance' => ['نضمن لك الامتثال القانوني', 'مضمون قانونيا'],
            'arabic: legally approved' => ['هذا البلد معتمد قانونيا', 'معتمد قانونيا'],
            'arabic: legally approved, feminine' => ['الأسواق معتمدة قانونيا', 'معتمد قانونيا'],
            'arabic: legally certified' => ['النصوص موثقة قانونيا', 'معتمد قانونيا'],
            'arabic: fully legal' => ['هذا المتجر قانوني بالكامل', 'معتمد قانونيا'],
            'arabic: in legal compliance' => ['هذا البلد في امتثال قانوني', 'متوافق قانونيا'],
            'arabic: the legal compliance of the market' => ['الامتثال القانوني للسوق', 'متوافق قانونيا'],
            'arabic: in full legal compliance' => ['هذا البلد في امتثال قانوني كامل', 'متوافق بالكامل'],
            'arabic: in total compliance' => ['المتجر في امتثال تام', 'متوافق بالكامل'],
            'arabic: the full compliance' => ['الامتثال الكامل للمتجر', 'متوافق بالكامل'],
        ];
    }

    #[DataProvider('sentencesThatDoNotClaimIt')]
    public function test_the_scan_leaves_alone_a_sentence_that_does_not_claim_compliance(string $sentence): void
    {
        $this->assertSame([], (new ComplianceClaimScanner())->claimsIn(text: $sentence));
    }

    public static function sentencesThatDoNotClaimIt(): array
    {
        return [
            'the refusal of the sale gate' => ['This storefront is not open for checkout yet.'],
            'the refusal as a key' => ['storefront_not_open_for_checkout_yet'],
            'not approved' => ['Not approved'],
            'pending approval' => ['Pending approval'],
            'awaiting a reviewer' => ['Awaiting approval by the legal reviewer'],
            'approved inside the platform' => ['The text was approved by someone other than its author'],
            'a column of approvals' => ['Approved by'],
            'approved products' => ['Approved products'],
            'approved by the admin' => ['Approved by admin'],
            'the review is on record' => ['The legal review of the texts is on record'],
            'reviewed by a lawyer' => ['Reviewed by a lawyer of the country'],
            'a disclaimer' => ['This text is an engineering default, not legal advice'],
            'not a legal advisor' => ['We are not a legal advisor and make no claim of compliance'],
            'compliance as a topic' => ['Compliance documents'],
            'compliance in a list of notifications' => ['Notifications about orders, stock, payouts and compliance appear here.'],
            'compliance in reporting' => ['This enables accurate, detailed VAT/GST reporting for compliance and accounting.'],
            'a certificate' => ['TIN certificate'],
            'upload the certificate' => ['Upload the certificate'],
            'uncertified' => ['Uncertified'],
            'not certified' => ['not certified'],
            'not legally compliant' => ['The country is not legally compliant'],
            'not yet fully compliant' => ['This storefront is not yet fully compliant'],
            'non-compliant' => ['Non-compliant products are hidden'],
            'compatibility' => ['Compatible with S3'],
            'complies with a protocol' => ['Complies with the S3 API'],
            'committed' => ['Fully committed to your satisfaction'],
            'a field of the list of storefronts' => ['sellable'],
            'arabic: the refusal of the sale gate' => ['هذا المتجر غير متاح للشراء حتى الآن.'],
            'arabic: the maintenance' => ['هذا المتجر تحت الصيانة حاليًا. طلباتك السابقة ومدفوعاتك لا تتأثر.'],
            'arabic: compliance documents' => ['مستندات الامتثال'],
            'arabic: approved products' => ['المنتجات المعتمدة'],
            'arabic: an S3 compatible storage' => ['التخزين المتوافق مع s3'],
            'arabic: not compliant' => ['غير ممتثل'],
            'arabic: not yet compliant' => ['ليس ممتثلا بعد'],
            'arabic: not legally compliant' => ['غير متوافق قانونيا'],
            'arabic: not yet approved' => ['لم تتم الموافقة عليه بعد'],
            'arabic: not legally approved' => ['غير معتمد قانونيا'],
            'arabic: not fully legal' => ['ليس قانونيا بالكامل'],
            'arabic: legal documents that are complete' => ['المستندات القانونية كاملة'],
            'arabic: an exact match' => ['مرتبط تلقائيًا: رمز المنتج مطابق تمامًا'],
            'arabic: fully committed' => ['ملتزمون بالكامل برضاك'],
            'arabic: a legal text' => ['نص قانوني'],
            'arabic: a legal requirement' => ['العنوان مطلوب قانونًا في كل بلد'],
        ];
    }

    #[DataProvider('namesOfFieldsThatClaimIt')]
    public function test_the_scan_flags_the_name_of_a_field_that_holds_compliance_or_certification(string $name): void
    {
        $this->assertNotSame([], (new ComplianceClaimScanner())->claimsInFieldName(name: $name));
    }

    public static function namesOfFieldsThatClaimIt(): array
    {
        return [['compliant'], ['is_compliant'], ['legally_compliant'], ['legal_compliance'], ['compliance_status'], ['certified'], ['certification'], ['approvedByAuthority']];
    }

    #[DataProvider('namesOfFieldsThatDoNot')]
    public function test_the_scan_leaves_alone_the_name_of_a_field_of_a_readiness_answer(string $name): void
    {
        $this->assertSame([], (new ComplianceClaimScanner())->claimsInFieldName(name: $name));
    }

    public static function namesOfFieldsThatDoNot(): array
    {
        return [['sellable'], ['state'], ['stop_mode'], ['stop_message'], ['stop_until'], ['redirect_to'], ['is_default'], ['default_country'], ['legal_texts'], ['legal_review'], ['unmet'], ['code'], ['message']];
    }

    public function test_the_walkers_find_a_claim_in_every_kind_of_file_they_read_and_in_nothing_else(): void
    {
        $root = $this->fixtureTree(files: [
            'resources/lang/en/messages.php' => "<?php\n\nreturn ['fine_key' => 'Nothing to see', 'a_claim' => 'This market is fully compliant with the law.', 'validation' => ['custom' => ['deep' => 'Certified by the ministry']]];\n",
            'resources/lang/sa/new-messages.php' => "<?php\n\nreturn ['an_arabic_claim' => 'هذا المتجر متوافق قانونيا'];\n",
            'resources/lang/fr/messages.php.bak' => "<?php\n\nreturn ['a_backup' => 'Fully compliant'];\n",
            'resources/lang/fr.json' => '{"a_json_claim": "Approved by the authority", "a_clean_one": "Bonjour"}',
            'Modules/Shop/lang/en/messages.php' => "<?php\n\nreturn ['a_module_claim' => 'Government approved'];\n",
            'resources/views/a.blade.php' => "<p>{{ translate('fine_key') }}</p>\n<p>This market is fully\n   compliant</p>\n",
            'resources/views/b.blade.php' => "<p>{{ translate('ensure_your_restaurant_stays_legally_compliant.') }}</p>\n",
            'resources/views/c.php' => "<p>Fully compliant</p>\n",
            'Modules/Shop/Resources/views/d.blade.php' => "<p>Government approved</p>\n",
        ]);
        $scanner = new ComplianceClaimScanner();
        $project = static fn (array $findings): array => array_map(static fn (array $finding): array => [$finding['file'], $finding['line'], $finding['key'], $finding['claims']], $findings);

        $languages = $scanner->inLanguageFiles(languageRoots: [$root . '/resources/lang', ...glob($root . '/Modules/*/lang')], repository: $root);
        $views = $scanner->inViews(directories: [$root . '/resources/views', ...glob($root . '/Modules/*/[Rr]esources/views')], repository: $root);

        $this->assertEqualsCanonicalizing([
            ['resources/lang/en/messages.php', null, 'a_claim', ['compliant with the law', 'compliant', 'fully compliant']],
            ['resources/lang/en/messages.php', null, 'validation.custom.deep', ['certified']],
            ['resources/lang/sa/new-messages.php', null, 'an_arabic_claim', ['متوافق قانونيا', 'متوافق مع القانون']],
            ['resources/lang/fr.json', null, 'a_json_claim', ['approved by the authority']],
            ['Modules/Shop/lang/en/messages.php', null, 'a_module_claim', ['government approved']],
        ], $project(findings: $languages), 'a claim in a nested array, in an Arabic file, in a JSON file and in the language directory of a module is found; a backup file and a clean string are not');
        $this->assertEqualsCanonicalizing([
            ['resources/views/a.blade.php', 3, null, ['compliant']],
            ['resources/views/a.blade.php', null, null, ['fully compliant']],
            ['resources/views/b.blade.php', 1, null, ['legally compliant', 'compliant']],
            ['Modules/Shop/Resources/views/d.blade.php', 1, null, ['government approved']],
        ], $project(findings: $views), 'a sentence broken across two lines is found for its file; a file that is not a Blade view is not read');
    }

    public function test_the_walkers_find_the_keys_a_view_passes_to_translate(): void
    {
        $scanner = new ComplianceClaimScanner();

        $this->assertSame(['a_key', 'another.key'], $scanner->translationKeysIn(line: "{{ translate('a_key') }} and {!! translate(\"another.key\") !!}"));
        $this->assertSame([], $scanner->translationKeysIn(line: '{{ $text }}'));
    }

    public function test_the_walkers_find_a_claim_in_the_names_and_the_texts_of_a_payload(): void
    {
        $payload = ['storefronts' => [['country' => 'AE', 'legally_compliant' => true, 'message' => 'Approved by the authority', 'state' => 'active', 'nested' => ['certified' => ['yes']]]]];

        $findings = (new ComplianceClaimScanner())->inPayload(label: 'a payload', payload: $payload);

        $this->assertEqualsCanonicalizing(
            ['storefronts.0.legally_compliant', 'storefronts.0.message', 'storefronts.0.nested.certified'],
            array_column($findings, 'key'),
        );
        $this->assertSame([], (new ComplianceClaimScanner())->inPayload(label: 'a clean payload', payload: ['enabled' => true, 'storefronts' => [['country' => 'KW', 'sellable' => false, 'state' => 'draft']]]));
    }

    public function test_the_scan_reads_every_language_directory_and_the_views_of_the_panels_the_modules_and_the_theme(): void
    {
        $scanner = new ComplianceClaimScanner();
        $languageFiles = $scanner->languageFiles(languageRoots: self::languageRoots());
        $viewFiles = array_map(static fn (string $file): string => substr($file, strlen((string) realpath(self::repository())) + 1), $scanner->viewFiles(directories: self::viewRoots()));

        foreach (glob(self::repository() . '/resources/lang/*', GLOB_ONLYDIR) as $directory) {
            $this->assertNotEmpty(
                array_filter($languageFiles, static fn (string $file): bool => str_starts_with($file, realpath($directory) . '/')),
                basename($directory) . ' was not read',
            );
        }
        foreach (['en', 'sa', 'ae'] as $language) {
            $this->assertDirectoryExists(self::repository() . "/resources/lang/{$language}");
        }
        $this->assertGreaterThan(500, count($viewFiles));
        foreach ([
            'resources/views/admin-views/storefronts/legal-texts.blade.php',
            'resources/views/layouts/vendor/partials/offcanvas/_shop-other-setup.blade.php',
            'resources/themes/default/layouts/front-end/partials/_footer.blade.php',
            'Modules/TaxModule/resources/views/6valley/offcanvas/_vat-tax-create.blade.php',
        ] as $view) {
            $this->assertContains($view, $viewFiles, "{$view} was not read");
        }
    }

    public function test_no_language_file_claims_compliance_beyond_the_known_findings(): void
    {
        $this->assertSame([], $this->described(findings: $this->untolerated(findings: self::languageFindings(), entries: array_merge(self::KNOWN_CLAIMS, self::LEGITIMATE))), self::WHAT_TO_DO);
    }

    public function test_no_view_claims_compliance_beyond_the_known_findings(): void
    {
        $this->assertSame([], $this->described(findings: $this->untolerated(findings: self::viewFindings(), entries: array_merge(self::KNOWN_CLAIMS, self::LEGITIMATE))), self::WHAT_TO_DO);
    }

    public function test_every_known_finding_is_still_in_the_language_files_and_in_the_views_listed_and_in_no_others(): void
    {
        foreach (array_merge(self::KNOWN_CLAIMS, self::LEGITIMATE) as $entry) {
            $inLanguages = array_values(array_filter(self::languageFindings(), static fn (array $finding): bool => $finding['key'] === $entry['key']));
            $inViews = array_values(array_filter(self::viewFindings(), static fn (array $finding): bool => in_array($entry['key'], $finding['keys'], true)));

            $this->assertNotEmpty($inLanguages, "'{$entry['key']}' no longer makes a claim in any language file: take it off the list in this test");
            $this->assertEqualsCanonicalizing($entry['claims'], array_values(array_unique(array_merge(...array_column($inLanguages, 'claims')))), "the claims of '{$entry['key']}' changed: update its entry");
            $this->assertEqualsCanonicalizing($entry['views'], array_values(array_unique(array_column($inViews, 'file'))), "the views that show '{$entry['key']}' changed: update its entry");
        }
    }

    public function test_the_texts_of_every_known_finding_are_the_ones_written_here_in_every_language(): void
    {
        foreach (array_merge(self::KNOWN_CLAIMS, self::LEGITIMATE) as $entry) {
            $texts = array_column(array_filter(self::languageFindings(), static fn (array $finding): bool => $finding['key'] === $entry['key']), 'text');

            $this->assertEqualsCanonicalizing($entry['texts'], array_values(array_unique($texts)), "the texts of '{$entry['key']}' changed: a reworded claim, or a new translation of it, is a new decision");
        }
    }

    public function test_every_known_finding_is_shown_to_staff_only_and_used_by_no_php_code(): void
    {
        foreach (self::KNOWN_CLAIMS as $entry) {
            foreach ($entry['views'] as $view) {
                $this->assertNotEmpty(
                    array_filter(self::STAFF_PANEL_VIEWS, static fn (string $panel): bool => str_starts_with($view, $panel)),
                    "{$view} shows '{$entry['key']}' and is not a view of a staff panel: a customer must never read a claim of compliance",
                );
            }

            $this->assertSame([], $this->phpFilesNaming(text: $entry['key']), "'{$entry['key']}' is used by PHP code, which this test does not read: a message built there may reach a customer");
        }
    }

    public function test_the_refusal_of_a_sale_and_the_maintenance_message_say_in_english_and_in_arabic_what_they_refuse(): void
    {
        $english = require self::repository() . '/resources/lang/en/messages.php';
        $arabic = require self::repository() . '/resources/lang/sa/messages.php';

        $this->assertStringContainsStringIgnoringCase('not open for checkout', $english['storefront_not_open_for_checkout_yet']);
        $this->assertStringContainsString('غير متاح للشراء', $arabic['storefront_not_open_for_checkout_yet']);
        $this->assertStringContainsStringIgnoringCase('under maintenance', $english['storefront_is_in_maintenance']);
        $this->assertStringContainsString('تحت الصيانة', $arabic['storefront_is_in_maintenance']);
    }

    public function test_every_known_finding_and_every_legitimate_occurrence_says_why(): void
    {
        foreach (array_merge(self::KNOWN_CLAIMS, self::LEGITIMATE) as $entry) {
            $this->assertGreaterThan(40, strlen($entry['reason']), "'{$entry['key']}' has no reason");
        }
    }

    /** @return list<string> the PHP files of the application, the modules, the routes and the configuration that hold the text */
    private function phpFilesNaming(string $text): array
    {
        $files = [];

        foreach (['app', 'Modules', 'routes', 'config'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::repository() . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (str_ends_with($file->getFilename(), '.php') && !str_ends_with($file->getFilename(), '.blade.php') && str_contains((string) file_get_contents($file->getPathname()), $text)) {
                    $files[] = substr($file->getPathname(), strlen(self::repository()) + 1);
                }
            }
        }

        return $files;
    }

    /** @param array<string, mixed> $finding */
    #[DataProvider('findingsAgainstAnEntry')]
    public function test_a_finding_is_tolerated_only_by_the_entry_that_names_it(array $finding, bool $isTolerated): void
    {
        $entries = [[
            'key' => 'a_known_key',
            'texts' => ['A known text', 'Un texte connu'],
            'claims' => ['compliant'],
            'views' => ['resources/views/admin-views/known.blade.php'],
            'reason' => 'A reason that is long enough to be a reason for the tolerance of it.',
        ]];

        $this->assertSame($isTolerated, $this->isTolerated(finding: $finding, entries: $entries));
    }

    public static function findingsAgainstAnEntry(): array
    {
        $string = static fn (array $override): array => $override + ['source' => ComplianceClaimScanner::LANGUAGE, 'file' => 'resources/lang/en/messages.php', 'line' => null, 'key' => 'a_known_key', 'text' => 'A known text', 'claims' => ['compliant'], 'keys' => []];
        $line = static fn (array $override): array => $override + ['source' => ComplianceClaimScanner::VIEW, 'file' => 'resources/views/admin-views/known.blade.php', 'line' => 5, 'key' => null, 'text' => '{{ translate(\'a_known_key\') }}', 'claims' => ['compliant'], 'keys' => ['a_known_key']];

        return [
            'its string, in any language file' => [$string([]), true],
            'its string in another language, with the text it has there' => [$string(['file' => 'resources/lang/fr/messages.php', 'text' => 'Un texte connu']), true],
            'its string claiming more than the entry names' => [$string(['claims' => ['compliant', 'fully compliant']]), false],
            'its string reworded, claiming the same' => [$string(['text' => 'This country is compliant.']), false],
            'its string reworded in another language' => [$string(['file' => 'resources/lang/fr/messages.php', 'text' => 'Ce pays est conforme.']), false],
            'another string' => [$string(['key' => 'another_key']), false],
            'its line in the view that is listed' => [$line([]), true],
            'its line reworded to claim more' => [$line(['claims' => ['compliant', 'certified']]), false],
            'its line in a view that is not listed' => [$line(['file' => 'resources/themes/default/web-views/home.blade.php']), false],
            'a line of the view that passes another key' => [$line(['keys' => ['another_key']]), false],
            'a line of the view that writes its own words' => [$line(['keys' => []]), false],
            'a sentence broken across lines' => [$line(['line' => null, 'keys' => []]), false],
            'a field of a payload with the same name' => [['source' => ComplianceClaimScanner::PAYLOAD, 'file' => 'GET /api', 'line' => null, 'key' => 'a_known_key', 'text' => 'compliant', 'claims' => ['compliant'], 'keys' => []], false],
        ];
    }
}
