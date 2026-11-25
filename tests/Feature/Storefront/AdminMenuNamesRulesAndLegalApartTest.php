<?php

namespace Tests\Feature\Storefront;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * I2-06: the admin menu may not call the operating rules and the legal documents the same thing.
 *
 * Two different things lived under one word. `PolicyRegistry` holds the numbers and switches the PLATFORM applies to itself -- what counts as
 * low stock, how long a courier may go quiet, how short a password may be -- and its screen called itself "platform policies". The documents a
 * CUSTOMER accepts (the terms, the privacy notice, the refund and return policies) are legal texts, live in another store entirely
 * (`business_pages` and `storefront_legal_documents`) and are reached from Business Pages and from a storefront's legal texts. An operator
 * who opens "Policies" expecting the privacy notice and finds a stuck-order threshold has been misled by the menu, and the reverse mistake --
 * editing an operating rule believing it is the text a customer agreed to -- is the one with legal consequences.
 *
 * So the rules screen is named for what it holds, it is IN the menu at all (it had a screen and no way to reach it), and this asserts that
 * neither entry's label names the other's kind.
 */
class AdminMenuNamesRulesAndLegalApartTest extends AdminStorefrontScreenTestCase
{
    /** The words that name a document a customer accepts. A menu entry for the operating rules may hold none of them. */
    private const LEGAL_WORDS = ['policy', 'policies', 'terms', 'privacy', 'refund', 'return', 'cancellation', 'cookies', 'legal'];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The side bar reads tables of the whole panel: this test builds minimal ones in the throwaway SQLite database, and no real server keeps them.');
        }

        $this->buildTheTablesTheSideBarReads();
    }

    public function test_the_operating_rules_screen_is_in_the_menu_and_named_for_the_rules_it_holds(): void
    {
        $sideBar = view('layouts.admin.partials.v2._side-bar')->render();

        $this->assertStringContainsString(route('admin.settings.policies.index'), $sideBar, 'the operating-rules screen has no way in from the menu');
        $this->assertSame(translate('operating_rules'), $this->labelOfTheEntryFor(route('admin.settings.policies.index'), $sideBar));
    }

    public function test_the_label_of_the_operating_rules_entry_names_no_legal_document(): void
    {
        $label = strtolower($this->labelOfTheEntryFor(route('admin.settings.policies.index'), view('layouts.admin.partials.v2._side-bar')->render()));

        foreach (self::LEGAL_WORDS as $word) {
            $this->assertStringNotContainsString($word, $label, "the operating-rules entry is labelled [{$label}], which names a legal document");
        }
    }

    /** And the other direction: the entry that leads to the legal texts is not labelled with the rules screen's name. */
    public function test_the_legal_document_entries_are_not_labelled_as_the_operating_rules(): void
    {
        $sideBar = view('layouts.admin.partials.v2._side-bar')->render();
        $rules = translate('operating_rules');

        foreach ([route('admin.pages-and-media.list'), route('admin.storefronts.index')] as $legalWayIn) {
            $this->assertNotSame($rules, $this->labelOfTheEntryFor($legalWayIn, $sideBar), "[{$legalWayIn}] is labelled as the operating rules");
        }
    }

    /** The screen's own heading, which is what an operator reads once they are on it. */
    public function test_the_screen_names_itself_for_the_rules_and_says_where_the_legal_documents_are(): void
    {
        $page = $this->get(route('admin.settings.policies.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('operating_rules')), (string) $page);
        $this->assertStringNotContainsString(e(translate('platform_policies')), (string) $page, 'the screen still calls itself policies');
        $this->assertStringContainsString(
            e(translate('these_are_not_the_legal_documents_a_customer_accepts_those_are_in_business_pages_and_in_each_storefronts_legal_texts')),
            (string) $page,
            'the screen does not say where the legal documents are',
        );
    }

    /** No legal document type the platform requires is the name of an operating-rules MENU entry, which is the naming half of the separation. */
    public function test_no_required_legal_document_type_is_the_name_of_the_rules_entry(): void
    {
        $label = strtolower($this->labelOfTheEntryFor(route('admin.settings.policies.index'), view('layouts.admin.partials.v2._side-bar')->render()));

        foreach ((array) config('gcc.storefronts.legal.required_documents', []) as $type) {
            $this->assertStringNotContainsString(str_replace('_', ' ', strtolower($type)), $label, "the rules entry is named after the legal document [{$type}]");
        }
    }

    /** The text of the one menu entry whose link is this address. */
    private function labelOfTheEntryFor(string $url, string $sideBar): string
    {
        $entry = strstr($sideBar, 'href="' . $url . '"');

        $this->assertIsString($entry, "the menu has no entry for [{$url}]");
        $this->assertSame(1, preg_match('/v2-nav-label">([^<]*)</', (string) $entry, $label), "the entry for [{$url}] has no label");

        return trim(html_entity_decode($label[1]));
    }

    private function buildTheTablesTheSideBarReads(): void
    {
        // The setup guide of the side bar counts categories, brands and products, and a product brings its translations and reviews.
        Schema::create('categories', static fn (Blueprint $table) => $table->id());
        Schema::create('brands', static fn (Blueprint $table) => $table->id());
        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->integer('request_status')->default(1);
            $table->integer('status')->default(1);
            $table->string('added_by')->default('admin');
            $table->integer('is_shipping_cost_updated')->nullable();
        });
        Schema::create('translations', static function (Blueprint $table): void {
            $table->id();
            $table->string('translationable_type')->nullable();
            $table->unsignedBigInteger('translationable_id')->nullable();
            $table->string('locale')->nullable();
            $table->string('key')->nullable();
            $table->text('value')->nullable();
        });
        Schema::create('reviews', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->integer('status')->default(1);
        });
    }
}
