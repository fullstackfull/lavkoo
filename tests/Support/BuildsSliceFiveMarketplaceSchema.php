<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tables the rest of slice 5 reads, for the tests that make real requests to those screens: the returns queue, the pick-pack-ship
 * queue, the commission rules, the settlements and the brand claims.
 *
 * None of these five has a create migration in this repository -- they arrive with the shipped database dump -- so a test that renders the
 * screens has to build them, and the columns here are the ones the models declare fillable plus the keys the services read. A screen whose
 * table is absent shows an empty list (`Schema::hasTable` guards every one of them), which would make a narrowing test pass for the wrong
 * reason: with no rows there is nothing to narrow.
 */
trait BuildsSliceFiveMarketplaceSchema
{
    protected function buildSliceFiveMarketplaceSchema(): void
    {
        $this->giveTheReviewsAndTheShopsWhatTheReviewsScreenReads();

        foreach (['return_shipments', 'order_fulfillments', 'commission_rules', 'vendor_settlements', 'vendor_ledger_entries', 'brand_claims', 'brand_claim_documents', 'review_replies'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('return_shipments', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->unsignedBigInteger('refund_request_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->integer('qty')->default(1);
            $table->string('reason')->nullable();
            $table->string('status')->default('authorized');
            $table->boolean('restock')->default(true);
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_type')->nullable();
            $table->timestamps();
        });

        Schema::create('order_fulfillments', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->dateTime('picked_at')->nullable();
            $table->dateTime('packed_at')->nullable();
            $table->dateTime('shipped_at')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('commission_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_type')->default('global');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('rate_type')->default('percentage');
            $table->decimal('percentage', 8, 2)->default(0);
            $table->decimal('fixed_amount', 24, 2)->default(0);
            $table->decimal('min_amount', 24, 2)->nullable();
            $table->decimal('max_amount', 24, 2)->nullable();
            $table->integer('priority')->default(10);
            $table->boolean('is_active')->default(true);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->string('label')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('vendor_settlements', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->default('seller');
            $table->dateTime('period_start')->nullable();
            $table->dateTime('period_end')->nullable();
            $table->decimal('opening_balance', 24, 4)->default(0);
            $table->decimal('total_credits', 24, 4)->default(0);
            $table->decimal('total_debits', 24, 4)->default(0);
            $table->decimal('net_amount', 24, 4)->default(0);
            $table->decimal('closing_balance', 24, 4)->default(0);
            $table->integer('entry_count')->default(0);
            $table->string('currency')->nullable();
            $table->unsignedBigInteger('legal_entity_id')->nullable();
            $table->string('status')->default('calculated');
            $table->dateTime('calculated_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->string('payout_reference')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // The customer picker of the reviews screen lists `users`, which no other builder of this world creates.
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('f_name')->nullable();
                $table->string('l_name')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->timestamps();
            });
        }

        Schema::create('review_replies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('review_id')->nullable();
            $table->text('reply_text')->nullable();
            $table->string('added_by')->nullable();
            $table->unsignedBigInteger('added_by_id')->nullable();
            $table->timestamps();
        });

        Schema::create('vendor_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->default('seller');
            $table->string('entry_type')->nullable();
            $table->decimal('debit', 24, 4)->default(0);
            $table->decimal('credit', 24, 4)->default(0);
            $table->decimal('balance_after', 24, 4)->default(0);
            $table->string('status')->nullable();
            $table->dateTime('available_at')->nullable();
            $table->string('currency')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('settlement_id')->nullable();
            $table->unsignedBigInteger('legal_entity_id')->nullable();
            $table->timestamps();
        });

        Schema::create('brand_claim_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('brand_claim_id')->nullable();
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->timestamps();
        });

        Schema::create('brand_claims', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('status')->default('submitted');
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * The two tables of the shared world that the real reviews screen needs more of, each column added only where it is missing.
     *
     * `reviews` is built three times over in this suite -- the base table, then `buildStaffPromotionTables()` adds the rating the clearance
     * list averages, then `BuildsStaffReportWorld` adds the rest -- so a column declared unconditionally here is a duplicate on whichever
     * order a test happens to build in. That is not a hypothetical: adding `order_id` and `rating` to the base table cost 85 errors across
     * the staff group in one run.
     *
     * `shops` needs the columns of ONE insert: the in-house shop row is WRITTEN, not read, by any screen that asks
     * `getInHouseShopConfig()` for it, and the reviews screen's vendor picker does.
     */
    private function giveTheReviewsAndTheShopsWhatTheReviewsScreenReads(): void
    {
        $reviewColumns = [
            'customer_id' => 'unsignedBigInteger', 'order_id' => 'unsignedBigInteger', 'comment' => 'text', 'attachment' => 'text',
            'rating' => 'integer',
        ];
        $shopColumns = [
            'slug' => 'string', 'contact' => 'string', 'address' => 'string', 'image' => 'string', 'image_storage_type' => 'string',
            'banner' => 'string', 'banner_storage_type' => 'string', 'offer_banner' => 'string', 'offer_banner_storage_type' => 'string',
            'bottom_banner' => 'string', 'bottom_banner_storage_type' => 'string', 'vacation_duration_type' => 'string',
            'vacation_start_date' => 'date', 'vacation_end_date' => 'date', 'vacation_note' => 'string', 'vacation_status' => 'boolean',
            'temporary_close' => 'boolean',
        ];

        foreach (['reviews' => $reviewColumns, 'shops' => $shopColumns] as $table => $columns) {
            $missing = array_filter($columns, static fn (string $type, string $column): bool => !Schema::hasColumn($table, $column), ARRAY_FILTER_USE_BOTH);
            Schema::table($table, function (Blueprint $blueprint) use ($missing): void {
                foreach ($missing as $column => $type) {
                    $blueprint->{$type}($column)->nullable();
                }
            });
        }

        if (!Schema::hasColumn('reviews', 'created_at')) {
            Schema::table('reviews', static fn (Blueprint $table) => $table->timestamps());
        }
        if (!Schema::hasColumn('shops', 'created_at')) {
            Schema::table('shops', static fn (Blueprint $table) => $table->timestamps());
        }
    }

    /** Puts the five tables back as they were found: absent is their state in a tree that has no dump. */
    protected function dropSliceFiveMarketplaceSchema(): void
    {
        foreach (['brand_claim_documents', 'brand_claims', 'review_replies', 'vendor_ledger_entries', 'vendor_settlements', 'commission_rules', 'order_fulfillments', 'return_shipments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
