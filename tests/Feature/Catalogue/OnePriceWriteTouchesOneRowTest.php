<?php

namespace Tests\Feature\Catalogue;

use App\Models\Product;
use App\Models\Seller;
use App\Services\Marketplace\Bulk\BulkPriceOperation;
use App\Services\Marketplace\SellerPrincipal;
use App\Services\SellerAutomation\Actions\SetDiscountAction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A price write changes the row it names and no other (B-02).
 *
 * Two copies of one product in two countries are two SEPARATE ROWS with no link column and no sync
 * job: that is the whole mechanism by which one market's price is independent of another's. So the
 * property that makes it true is narrower and stronger than "countries are independent" -- it is that
 * EVERY writer of a price, a discount or a stock figure touches exactly one row.
 *
 * That property does not need a country column to be tested, and this branch is off `main`, which has
 * none. What is proved here is the row isolation itself; the country label is what a later branch
 * puts on the rows. Said plainly rather than left to look like more than it is.
 *
 * TWO HALVES, because "every writer" cannot be shown by driving two of them:
 *
 *   1. BEHAVIOUR, for the two writers that work over a SET of products and are therefore where an
 *      unscoped write would actually live: the bulk price job and the automation action.
 *   2. STRUCTURE, over every price and stock write in the application at once, so a writer added
 *      tomorrow is covered without anyone remembering to come back here.
 */
class OnePriceWriteTouchesOneRowTest extends TestCase
{
    /** The columns whose write must never be unscoped. */
    private const MONEY_AND_STOCK = ['unit_price', 'discount', 'discount_type', 'current_stock'];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('products');
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('added_by')->default('seller');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('product_type')->default('physical');
            $table->decimal('unit_price', 16, 2)->default(0);
            $table->decimal('discount', 16, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->integer('current_stock')->default(0);
            $table->integer('status')->default(1);
            $table->timestamps();
        });

        /*
         * The Product model eager-loads a chain of its own relations -- translations, reviews and the
         * rest -- and the price writers read the store's settings on the way through, so those tables
         * have to exist for the REAL writer to run at all. Each arrives empty: nothing here depends on
         * a translated name, a review or a particular setting.
         */
        foreach (['translations' => function (Blueprint $table) {
            $table->id();
            $table->string('translationable_type')->nullable();
            $table->unsignedBigInteger('translationable_id')->nullable();
            $table->string('locale', 8)->nullable();
            $table->string('key')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        }, 'reviews' => function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->integer('rating')->default(5);
            $table->integer('status')->default(1);
            $table->timestamps();
        }] as $name => $definition) {
            Schema::dropIfExists($name);
            Schema::create($name, $definition);
        }

        // The settings table the writers read.
        Schema::dropIfExists('business_settings');
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('sellers');
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('status')->default('approved');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /** Two rows of the same product: what a second country's copy is, structurally. */
    private function twoCopiesOfOneProduct(): array
    {
        foreach ([1, 2] as $id) {
            DB::table('products')->insert([
                'id' => $id, 'name' => 'Paracetamol', 'added_by' => 'seller', 'user_id' => 9,
                'product_type' => 'physical', 'unit_price' => 100.00, 'discount' => 5.00,
                'discount_type' => 'amount', 'current_stock' => 40, 'status' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [Product::find(1), Product::find(2)];
    }

    private function rowOf(int $id): array
    {
        return (array) DB::table('products')->where('id', $id)->first();
    }

    private function aSellerPrincipal(): SellerPrincipal
    {
        DB::table('sellers')->insert(['id' => 9, 'f_name' => 'Vendor', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);

        return SellerPrincipal::owner(Seller::find(9));
    }

    public function test_the_bulk_price_job_changes_the_product_it_was_given_and_no_other(): void
    {
        [$first] = $this->twoCopiesOfOneProduct();
        $untouched = $this->rowOf(2);

        $result = app(BulkPriceOperation::class)->apply(
            product: $first,
            settings: ['mode' => BulkPriceOperation::MODE_INCREASE_PERCENT, 'value' => 10],
            principal: $this->aSellerPrincipal(),
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('110.00', number_format((float) $this->rowOf(1)['unit_price'], 2, '.', ''), 'the product it was given did not change');
        $this->assertSame($untouched, $this->rowOf(2), 'the other copy moved when one copy was repriced');
    }

    public function test_the_automation_action_changes_the_product_it_was_given_and_no_other(): void
    {
        [$first] = $this->twoCopiesOfOneProduct();
        $untouched = $this->rowOf(2);

        app(SetDiscountAction::class)->apply(
            subject: $first,
            settings: ['discount_type' => 'percent', 'discount_value' => 20, 'min_price_after_discount' => 1],
            principal: $this->aSellerPrincipal(),
        );

        $this->assertNotSame($untouched['discount'], $this->rowOf(1)['discount'], 'the product it was given did not change');
        $this->assertSame($untouched, $this->rowOf(2), 'the other copy moved when one copy was discounted');
    }

    public function test_no_price_or_stock_write_in_the_application_is_unscoped_except_the_one_already_known(): void
    {
        $writes = $this->priceAndStockWrites();

        // Measured on this head, not chosen: 13 statements write one of these four columns, and 11 of
        // them name the row they change. The figure is asserted so a write that appears without being
        // read here cannot pass unnoticed.
        $this->assertCount(13, $writes, 'the number of price and stock writes moved; each new one wants reading');

        $unscoped = array_values(array_filter($writes, static fn (array $w): bool => !$w['scoped']));

        /*
         * THE ONLY UNSCOPED WRITE, and it is not a price writer: app/Traits/UpdateClass.php sets EVERY
         * digital product's stock to a sentinel, as part of the software-update routine, because a
         * digital product has no finite stock. It is global on purpose. It appears twice because the
         * trait carries the statement twice.
         *
         * It is named here rather than excluded quietly: a digital product existing in two countries
         * would have both rows written by it. That is a question for whoever adds the country column,
         * and it is recorded, not fixed.
         */
        $this->assertCount(2, $unscoped, 'a new unscoped price or stock write was added');
        foreach ($unscoped as $write) {
            $this->assertSame('app/Traits/UpdateClass.php', $write['file']);
            $this->assertStringContainsString("'product_type' => 'digital'", $write['statement']);
            $this->assertStringContainsString('current_stock', $write['statement']);
            $this->assertStringNotContainsString('unit_price', $write['statement'], 'the known unscoped write now moves a PRICE');
            $this->assertStringNotContainsString('discount', $write['statement'], 'the known unscoped write now moves a DISCOUNT');
        }
    }

    public function test_every_other_price_or_stock_write_names_its_row(): void
    {
        foreach ($this->priceAndStockWrites() as $write) {
            if ($write['file'] === 'app/Traits/UpdateClass.php') {
                continue;
            }

            $this->assertTrue(
                $write['scoped'],
                "{$write['file']} writes a price or stock figure without naming the row it changes:\n    {$write['statement']}"
            );
        }
    }

    /**
     * Does this statement name the row it writes?
     *
     * Two shapes count, and nothing else does:
     *
     *   - a write THROUGH A MODEL INSTANCE (`$product->forceFill(...)->save()`), which is one row by
     *     construction because the instance is one row;
     *   - a query keyed BY ID (`where('id', ...)`, `whereKey`, `find`).
     *
     * A query-builder write keyed by anything else -- a seller, a type, a category -- is NOT scoped,
     * however many single rows it happens to hit today.
     *
     * THIS METHOD IS A CORRECTION OF MY OWN FIRST VERSION, and my own mutation is what caught it. The
     * first version looked for `$product->` ANYWHERE in the statement, so
     * `Product::where('user_id', $product->user_id)->update([...])` read as scoped because the words
     * `$product->` appeared inside it. A guard that can be satisfied by a mention rather than by a
     * structure is worse than none, because it reports safety it has not checked.
     */
    private function namesItsRow(string $statement): bool
    {
        $statement = ltrim($statement);

        // Through an instance: the statement BEGINS with the variable it writes.
        if (preg_match('/^\$(product|subject)\b/', $statement) === 1) {
            return true;
        }

        return preg_match("/where\\(\\s*\\[?\\s*'id'\\s*(,|=)|whereKey\\(|->find\\(|->findOrFail\\(/", $statement) === 1;
    }

    /**
     * Every statement in app/ and Modules/ that writes one of the four columns, and whether it names
     * its row.
     *
     * @return list<array{file: string, scoped: bool, statement: string}>
     */
    private function priceAndStockWrites(): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];

        foreach (['app', 'Modules'] as $tree) {
            $directory = $root . '/' . $tree;
            if (!is_dir($directory)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());
                preg_match_all("/(Product::|\\\$product->|\\\$products->|DB::table\('products'\))[^;]{0,400};/s", $source, $matches);

                foreach ($matches[0] as $statement) {
                    $touchesMoney = false;
                    foreach (self::MONEY_AND_STOCK as $column) {
                        if (str_contains($statement, "'" . $column . "'")) {
                            $touchesMoney = true;
                        }
                    }
                    $writes = str_contains($statement, '->update(') || str_contains($statement, '->forceFill(') || str_contains($statement, '->save()');

                    if (!$touchesMoney || !$writes) {
                        continue;
                    }

                    $found[] = [
                        'file' => str_replace($root . '/', '', $file->getPathname()),
                        'scoped' => $this->namesItsRow($statement),
                        'statement' => trim(preg_replace('/\s+/', ' ', $statement)),
                    ];
                }
            }
        }

        return $found;
    }
}
