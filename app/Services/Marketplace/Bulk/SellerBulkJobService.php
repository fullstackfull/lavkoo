<?php

namespace App\Services\Marketplace\Bulk;

use App\Jobs\RunSellerBulkJob;
use App\Models\Product;
use App\Models\ProductPriceChange;
use App\Models\SellerBulkJob;
use App\Services\AuditLogger;
use App\Services\Marketplace\PriceChangeRecorder;
use App\Services\Marketplace\SellerPermissionService;
use App\Services\Marketplace\SellerPrincipal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs a bulk operation and writes down what it actually did.
 *
 * Everything that is the same for every bulk operation lives here, and deliberately so. Ownership
 * scoping is the clearest example: an operation never sees a product the seller does not own,
 * because this loads them with the seller's own id in the WHERE clause rather than trusting the ids
 * in the request. An id the seller does not own and an id that does not exist are answered the same
 * way — refused as not found — so the endpoint cannot be used to discover a rival's catalogue.
 *
 * The other invariant is the receipt. A row is counted as succeeded only after its operation said so,
 * every refusal is stored with a reason, and the final status distinguishes "all of it" from "some of
 * it" from "none of it". A bulk operation must never report a success it did not achieve.
 */
class SellerBulkJobService
{
    /**
     * One request may not ask for more than this. It is a bound on how long a single job holds a
     * worker and how large a failure list can grow, not a statement about how many products a seller
     * may own — larger catalogues are changed in several passes.
     */
    public const MAX_ROWS = 1000;

    /** How many products are loaded per round trip while the job runs. */
    private const CHUNK = 100;

    /** @var array<string, class-string<BulkOperation>> */
    private const OPERATIONS = [
        'price_update' => BulkPriceOperation::class,
        'stock_update' => BulkStockOperation::class,
    ];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /** @return array<int, string> */
    public function availableTypes(): array
    {
        return array_keys(self::OPERATIONS);
    }

    public function operationFor(string $type): ?BulkOperation
    {
        $class = self::OPERATIONS[$type] ?? null;

        return $class ? app($class) : null;
    }

    /**
     * Validate a request and record it as a job, before any of it runs.
     *
     * The receipt exists from the first moment: if the worker dies halfway, there is still a row
     * saying what was asked for and how far it got, rather than a silence the seller has to
     * interpret.
     *
     * @param  array<string, mixed>  $payload  product_ids plus the operation's own settings
     *
     * @throws ValidationException
     */
    public function create(SellerPrincipal $principal, string $type, array $payload): SellerBulkJob
    {
        $operation = $this->operationFor($type);

        if (!$operation) {
            throw ValidationException::withMessages([
                'type' => translate('bulk_unknown_operation_type'),
            ]);
        }

        $validated = Validator::make($payload, array_merge([
            'product_ids' => 'required|array|min:1|max:' . self::MAX_ROWS,
            'product_ids.*' => 'required|integer|min:1',
        ], $operation->rules()))->validate();

        // Deduplicated before it is counted, so a list with the same product twice does not report a
        // total the seller never chose or apply an increase to it twice.
        $productIds = array_values(array_unique(array_map('intval', $validated['product_ids'])));
        unset($validated['product_ids']);

        $job = SellerBulkJob::create([
            'seller_id' => $principal->sellerId(),
            'created_by_staff_id' => $principal->staffId(),
            'created_by_api_key_id' => $principal->apiKeyId(),
            'type' => $type,
            'status' => SellerBulkJob::STATUS_QUEUED,
            'total' => count($productIds),
            'input' => ['product_ids' => $productIds, 'settings' => $validated],
        ]);

        $this->audit->record(
            action: 'seller.bulk_job_queued',
            subject: $job,
            context: ['type' => $type, 'total' => $job->total, 'seller_id' => $principal->sellerId()],
        );

        RunSellerBulkJob::dispatch($job->id);

        return $job;
    }

    /**
     * Do the work.
     *
     * Runs outside a single transaction on purpose. A bulk change is not atomic and should not
     * pretend to be: rolling four hundred successful price changes back because the four hundred and
     * first product was deleted mid-run would be a worse outcome than the partial result the receipt
     * describes precisely.
     */
    public function run(SellerBulkJob $job): SellerBulkJob
    {
        $operation = $this->operationFor($job->type);

        if (!$operation) {
            return $this->finishWithError($job, 'bulk_unknown_operation_type');
        }

        $principal = $this->principalFor($job);

        if (!$principal) {
            // The shop was suspended or removed between queueing and running. Nothing is applied on
            // behalf of an account that may no longer act.
            return $this->finishWithError($job, 'bulk_reason_seller_no_longer_active');
        }

        // Checked again here, not only at the endpoint. A permission revoked while the job sat in
        // the queue has to take effect on the work, not merely on the next request that asks for it.
        if (!$principal->can($operation->permission())) {
            return $this->finishWithError($job, 'bulk_reason_permission_revoked');
        }

        $job->forceFill([
            'status' => SellerBulkJob::STATUS_PROCESSING,
            'started_at' => now(),
        ])->save();

        $requestedIds = array_map('intval', $job->input['product_ids'] ?? []);
        $settings = $job->input['settings'] ?? [];
        $failures = [];
        $succeeded = 0;
        $processed = 0;

        // Everything this job changes is attributed to the job, not to whoever happens to be signed
        // in when the worker runs — which, on a queue, is nobody.
        return PriceChangeRecorder::attributeTo(
            ProductPriceChange::SOURCE_BULK_JOB,
            'Bulk job #' . $job->id . ' (' . $job->type . ')',
            fn () => $this->apply($job, $operation, $principal),
        );
    }

    /**
     * The loop itself, separated so the attribution above wraps every write inside it.
     */
    private function apply(SellerBulkJob $job, BulkOperation $operation, SellerPrincipal $principal): SellerBulkJob
    {
        $requestedIds = array_map('intval', $job->input['product_ids'] ?? []);
        $settings = $job->input['settings'] ?? [];
        $failures = [];
        $succeeded = 0;
        $processed = 0;

        foreach (array_chunk($requestedIds, self::CHUNK) as $chunk) {
            // Scoped on the seller's own id, never on the ids the caller sent. This is the line that
            // makes a forged product id useless.
            // Without the translate scope, which eager-loads every translation and review of every
            // product it touches. A bulk pass reads a price, a stock level and an owner and nothing
            // else, so on a thousand rows that scope is several thousand rows of pure waste.
            $products = Product::withoutGlobalScope('translate')
                ->where('added_by', 'seller')
                ->where('user_id', $job->seller_id)
                ->whereIn('id', $chunk)
                ->get()
                ->keyBy('id');

            foreach ($chunk as $productId) {
                $processed++;
                $product = $products->get($productId);

                if (!$product) {
                    $failures[] = ['product_id' => $productId, 'name' => null, 'reason' => 'bulk_reason_product_not_found'];
                    continue;
                }

                try {
                    $result = $operation->apply($product, $settings, $principal);
                } catch (Throwable $exception) {
                    // One product's problem is not the job's. It is recorded against that row and the
                    // rest of the list still runs.
                    report($exception);
                    $result = ['ok' => false, 'reason' => 'bulk_reason_unexpected_error'];
                }

                if ($result['ok'] ?? false) {
                    $succeeded++;
                    continue;
                }

                $failures[] = [
                    'product_id' => $productId,
                    'name' => $product->name,
                    'reason' => $result['reason'] ?? 'bulk_reason_unexpected_error',
                ];
            }

            $job->forceFill([
                'processed' => $processed,
                'succeeded' => $succeeded,
                'failed' => count($failures),
                'failures' => $failures,
            ])->save();
        }

        $job->forceFill([
            'status' => $this->outcome(total: $job->total, succeeded: $succeeded, failed: count($failures)),
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => count($failures),
            'failures' => $failures,
            'finished_at' => now(),
        ])->save();

        $this->audit->record(
            action: 'seller.bulk_job_finished',
            subject: $job,
            after: ['status' => $job->status, 'succeeded' => $succeeded, 'failed' => count($failures)],
        );

        return $job;
    }

    /**
     * All of it, some of it, or none of it.
     *
     * A job that was asked to do nothing is completed rather than failed — an empty selection is a
     * no-op, not a breakdown.
     */
    private function outcome(int $total, int $succeeded, int $failed): string
    {
        if ($failed === 0) {
            return SellerBulkJob::STATUS_COMPLETED;
        }

        return $succeeded > 0 ? SellerBulkJob::STATUS_PARTIAL : SellerBulkJob::STATUS_FAILED;
    }

    private function finishWithError(SellerBulkJob $job, string $error): SellerBulkJob
    {
        $job->forceFill([
            'status' => SellerBulkJob::STATUS_FAILED,
            'error' => $error,
            'finished_at' => now(),
        ])->save();

        return $job;
    }

    /**
     * Who this job runs as, resolved now rather than when it was queued.
     *
     * A shop suspended or an employee deactivated between queueing and running must stop the work,
     * which is why this is read fresh from the same place a request would read it.
     */
    private function principalFor(SellerBulkJob $job): ?SellerPrincipal
    {
        return app(SellerPermissionService::class)->principalForSeller(
            sellerId: $job->seller_id,
            staffId: $job->created_by_staff_id,
            apiKeyId: $job->created_by_api_key_id,
        );
    }
}
