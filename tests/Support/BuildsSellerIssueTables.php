<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `seller_sla_breaches` and `approval_requests`, defined once for the tests that need them.
 *
 * Neither has a create migration in this repository: both arrive with the installation dump, as `payment_requests` does. So a test that needs
 * them has to build them, and the definition lives here rather than in each test -- two drifting copies of a table the dump owns is how a test
 * comes to pass against a shape production does not have. The columns are the ones `App\Models\SellerSlaBreach` and
 * `App\Models\ApprovalRequest` declare fillable or cast, which is all the application reads or writes.
 */
trait BuildsSellerIssueTables
{
    protected function createSellerSlaBreachesTable(): void
    {
        if (Schema::hasTable('seller_sla_breaches')) {
            return;
        }

        Schema::create('seller_sla_breaches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('metric');
            $table->decimal('threshold', 12, 4)->nullable();
            $table->decimal('actual_value', 12, 4)->nullable();
            $table->string('status')->default('open');
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();
        });
    }

    protected function createApprovalRequestsTable(): void
    {
        if (Schema::hasTable('approval_requests')) {
            return;
        }

        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->string('workflow');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('status')->default('pending');
            $table->decimal('amount', 21, 12)->nullable();
            $table->string('currency', 8)->nullable();
            $table->unsignedInteger('required_approvals')->default(1);
            $table->unsignedInteger('approvals_count')->default(0);
            $table->string('requested_by_type')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->text('request_note')->nullable();
            $table->text('payload')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
        });
    }
}
