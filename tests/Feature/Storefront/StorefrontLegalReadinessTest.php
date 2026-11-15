<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontLegalReadiness;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A storefront sells only once its legal documents are published and approved by someone other than their author. The default storefront is
 * grandfathered, and nothing about another country's documents can open a storefront.
 */
class StorefrontLegalReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('storefront_legal_documents', function (Blueprint $table) {
            $table->id();
            $table->char('country_code', 2);
            $table->string('document_type', 32);
            $table->string('locale', 8)->default('en');
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 16)->default('draft');
            $table->longText('body');
            $table->char('body_hash', 64);
            $table->char('template_hash', 64)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['country_code', 'document_type', 'locale', 'version']);
        });
    }

    private function publish(string $country, string $type, array $overrides = []): void
    {
        $body = 'Approved text for ' . $type;

        DB::table('storefront_legal_documents')->insert(array_merge([
            'country_code' => $country,
            'document_type' => $type,
            'locale' => 'en',
            'version' => 1,
            'status' => 'published',
            'body' => $body,
            'body_hash' => hash('sha256', $body),
            'template_hash' => null,
            'created_by' => 1,
            'approved_by' => 2,
            'approved_at' => now(),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function publishEveryRequiredDocument(string $country): void
    {
        foreach (config('gcc.storefronts.legal.required_documents') as $type) {
            $this->publish($country, $type);
        }
    }

    private function readiness(): StorefrontLegalReadiness
    {
        return app(StorefrontLegalReadiness::class);
    }

    public function test_the_default_storefront_is_grandfathered_and_needs_no_rows(): void
    {
        $this->assertTrue($this->readiness()->isReady('SA'));
    }

    public function test_a_storefront_with_no_documents_is_not_ready(): void
    {
        $this->assertFalse($this->readiness()->isReady('AE'));
    }

    public function test_a_storefront_with_every_required_document_published_and_approved_is_ready(): void
    {
        $this->publishEveryRequiredDocument('AE');

        $this->assertTrue($this->readiness()->isReady('AE'));
    }

    public function test_one_missing_required_document_keeps_the_storefront_closed(): void
    {
        foreach (config('gcc.storefronts.legal.required_documents') as $type) {
            if ($type !== 'refund') {
                $this->publish('KW', $type);
            }
        }

        $this->assertFalse($this->readiness()->isReady('KW'));
    }

    public function test_a_document_approved_by_its_own_author_does_not_count(): void
    {
        $this->publish('QA', 'terms', ['approved_by' => 1, 'created_by' => 1]);

        $this->assertFalse($this->readiness()->isReady('QA'));
        $this->assertFalse($this->readiness()->isReady('qa'));
    }

    public function test_a_draft_or_unapproved_document_does_not_count(): void
    {
        $this->publish('BH', 'privacy', ['status' => 'draft', 'approved_by' => null, 'approved_at' => null]);

        $this->assertFalse($this->readiness()->isReady('BH'));
    }

    public function test_an_empty_body_does_not_count(): void
    {
        $this->publish('OM', 'shipping', ['body' => "   \n", 'body_hash' => hash('sha256', "   \n")]);

        $this->assertFalse($this->readiness()->isReady('OM'));
    }

    public function test_an_unedited_template_does_not_count(): void
    {
        $body = 'Template text';
        $this->publish('AE', 'return', [
            'body' => $body,
            'body_hash' => hash('sha256', $body),
            'template_hash' => hash('sha256', $body),
        ]);

        $this->assertFalse($this->readiness()->isReady('AE'));
    }

    public function test_a_template_copy_that_was_edited_counts_as_approved_text(): void
    {
        foreach (config('gcc.storefronts.legal.required_documents') as $type) {
            if ($type === 'return') {
                $this->publish('AE', 'return', [
                    'body' => 'Edited for the UAE',
                    'body_hash' => hash('sha256', 'Edited for the UAE'),
                    'template_hash' => hash('sha256', 'Template text'),
                ]);
            } else {
                $this->publish('AE', $type);
            }
        }

        $this->assertTrue($this->readiness()->isReady('AE'));
    }

    public function test_documents_of_another_country_do_not_open_a_storefront(): void
    {
        $this->publishEveryRequiredDocument('KW');

        $this->assertFalse($this->readiness()->isReady('AE'));
        $this->assertTrue($this->readiness()->isReady('KW'));
    }
}
