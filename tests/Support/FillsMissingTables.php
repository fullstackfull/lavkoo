<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a public endpoint answer in a test without a hand-built schema of every table its query touches. The installation dump is not in the
 * repository, so a test that asks what an endpoint says (not how it reaches it) creates, empty, each table or column the query reports missing
 * and asks again.
 */
trait FillsMissingTables
{
    private function fillMissingTables(callable $call): mixed
    {
        for ($attempt = 0; $attempt < 25; $attempt++) {
            try {
                return $call();
            } catch (\Illuminate\Database\QueryException $e) {
                // SQLite says "no such table: x", MySQL and MariaDB say "Table 'db.x' doesn't exist".
                if (preg_match('/no such table: (\w+)|Table \'[^\']*?(\w+)\' doesn\'t exist/', $e->getMessage(), $m)) {
                    $m[1] = $m[1] !== '' ? $m[1] : $m[2];
                    Schema::create($m[1], function (Blueprint $t) {
                        $t->id();
                        foreach (['product_id', 'customer_id', 'user_id', 'flash_deal_id', 'seller_id', 'category_id', 'order_id', 'brand_id', 'tag_id'] as $c) {
                            $t->unsignedBigInteger($c)->nullable();
                        }
                        foreach (['status', 'is_active'] as $c) {
                            $t->integer($c)->default(1);
                        }
                        foreach (['start_date', 'end_date', 'name', 'title', 'slug', 'deal_type', 'type', 'code', 'image', 'tag'] as $c) {
                            $t->string($c)->nullable();
                        }
                        $t->timestamps();
                    });
                } elseif (preg_match('/no such column: (?:(\w+)\.)?(\w+)|Unknown column \'(?:(\w+)\.)?(\w+)\' in/', $e->getMessage(), $m)) {
                    $m = ['', ($m[3] ?? '') !== '' ? $m[3] : ($m[1] ?? ''), ($m[4] ?? '') !== '' ? $m[4] : ($m[2] ?? '')];
                    $table = $m[1] !== '' ? $m[1] : (preg_match('/from [`"](\w+)[`"]/', $e->getMessage(), $tm) ? $tm[1] : null);
                    if ($table === null || !Schema::hasTable($table)) {
                        throw $e;
                    }
                    Schema::table($table, fn (Blueprint $t) => $t->string($m[2])->nullable());
                } else {
                    throw $e;
                }
            }
        }
        throw new \RuntimeException('too many missing tables');
    }
}
