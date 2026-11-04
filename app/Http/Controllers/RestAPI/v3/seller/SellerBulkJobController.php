<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SellerApiAuthMiddleware;
use App\Models\SellerBulkJob;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Marketplace\Bulk\SellerBulkJobService;
use App\Services\Marketplace\SellerPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk changes, and the receipt for each one.
 *
 * The receipt is the feature. Applying four hundred price changes is the easy half; being able to
 * say afterwards which of them landed, and why the rest did not, is what makes the tool safe to use
 * on a real catalogue. So the endpoints are shaped around the job rather than the action: creating
 * one answers with a job the client can follow, and the failure list is downloadable as a file the
 * seller can work through offline.
 */
class SellerBulkJobController extends Controller
{
    public function __construct(private readonly SellerBulkJobService $bulkJobs)
    {
    }

    #[ApiDoc(
        summary: 'Bulk jobs this seller has run',
        description: 'Newest first, paginated. Each entry carries its counts and progress so a client '
            . 'can show a job still running and a job that finished in the same list. Statuses are '
            . 'queued, processing, completed, partial and failed — partial means the job ran to the '
            . 'end and some rows were refused, with the reasons on the job itself.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function index(Request $request): JsonResponse
    {
        $jobs = SellerBulkJob::forSeller($request->seller->id)
            ->orderByDesc('id')
            ->paginate(perPage: $this->limit($request), page: $this->page($request));

        return response()->json([
            'total_size' => $jobs->total(),
            'limit' => $jobs->perPage(),
            'offset' => $jobs->currentPage(),
            'types' => $this->bulkJobs->availableTypes(),
            'jobs' => collect($jobs->items())->map(fn (SellerBulkJob $job) => $this->summary($job))->values(),
        ], 200);
    }

    #[ApiDoc(
        summary: 'One bulk job, with every refused row and its reason',
        description: 'The full receipt: counts, progress, what was asked for, and the list of rows '
            . 'that did not do what was asked with a translated reason for each. A job belonging to '
            . 'another seller answers 404, the same as one that does not exist.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function show(Request $request, $id): JsonResponse
    {
        $job = $this->jobFor($request, $id);

        if (!$job) {
            return $this->notFound();
        }

        return response()->json([
            'job' => $this->summary($job) + [
                'input' => $job->input,
                'error' => $job->error,
                'failures' => $this->failures($job),
            ],
        ], 200);
    }

    #[ApiDoc(
        summary: 'Change many prices at once',
        description: 'Takes product_ids and a mode: set, increase_percent, decrease_percent, '
            . 'increase_amount or decrease_amount, with value. Optionally sets discount and '
            . 'discount_type. Answers with the queued job — the work runs in the background and the '
            . 'client follows the job for the outcome. Products the seller does not own are refused '
            . 'as not found rather than applied.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function storePriceUpdate(Request $request): JsonResponse
    {
        return $this->store($request, 'price_update');
    }

    #[ApiDoc(
        summary: 'Change many stock levels at once',
        description: 'Takes product_ids and a mode: set, increase or decrease, with value. Each change '
            . 'goes through the stock ledger, so it cannot drive a balance negative and every movement '
            . 'is recorded. Variant products are refused with a reason — their stock is set per '
            . 'variant. Answers with the queued job.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function storeStockUpdate(Request $request): JsonResponse
    {
        return $this->store($request, 'stock_update');
    }

    #[ApiDoc(
        summary: 'Download the refused rows as a CSV',
        description: 'One row per product the job did not change, with the reason. This is what makes '
            . 'a partial result workable on a large catalogue: the seller fixes the listed products '
            . 'rather than re-running the whole selection. A job with no failures answers with a file '
            . 'containing only its header.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function downloadFailures(Request $request, $id): StreamedResponse|JsonResponse
    {
        $job = $this->jobFor($request, $id);

        if (!$job) {
            return $this->notFound();
        }

        $rows = $this->failures($job);

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [translate('product_id'), translate('product'), translate('reason')]);
            foreach ($rows as $row) {
                fputcsv($handle, [$row['product_id'], $row['name'], $row['message']]);
            }
            fclose($handle);
        }, "bulk-job-{$job->id}-failures.csv", ['Content-Type' => 'text/csv']);
    }

    private function store(Request $request, string $type): JsonResponse
    {
        $job = $this->bulkJobs->create(
            principal: $this->principal($request),
            type: $type,
            payload: $request->all(),
        );

        return response()->json([
            'message' => translate('bulk_job_queued'),
            'job' => $this->summary($job->refresh()),
        ], 202);
    }

    /**
     * The person acting, resolved by the auth middleware.
     *
     * Falls back to the shop owner only for a request that carried an owner token without a staff
     * identity — never to a seller id taken from the request body.
     */
    private function principal(Request $request): SellerPrincipal
    {
        $principal = $request->attributes->get(SellerApiAuthMiddleware::PRINCIPAL);

        return $principal instanceof SellerPrincipal ? $principal : SellerPrincipal::owner($request->seller);
    }

    private function jobFor(Request $request, $id): ?SellerBulkJob
    {
        return SellerBulkJob::forSeller($request->seller->id)->find($id);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['errors' => [
            ['code' => 'bulk_job', 'message' => translate('not_found')],
        ]], 404);
    }

    /** @return array<string, mixed> */
    private function summary(SellerBulkJob $job): array
    {
        return [
            'id' => $job->id,
            'type' => $job->type,
            'status' => $job->status,
            'total' => $job->total,
            'processed' => $job->processed,
            'succeeded' => $job->succeeded,
            'failed' => $job->failed,
            'progress' => $job->progress(),
            'is_finished' => $job->isFinished(),
            'created_at' => $job->created_at,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
        ];
    }

    /**
     * The refused rows, each carrying both the stable reason key and the seller's own language, so a
     * client can branch on the key without parsing a sentence.
     *
     * @return array<int, array<string, mixed>>
     */
    private function failures(SellerBulkJob $job): array
    {
        return array_map(fn (array $failure) => [
            'product_id' => $failure['product_id'] ?? null,
            'name' => $failure['name'] ?? null,
            'reason' => $failure['reason'] ?? null,
            'message' => translate($failure['reason'] ?? 'bulk_reason_unexpected_error'),
        ], $job->failures ?? []);
    }

    private function limit(Request $request): int
    {
        return max(1, min((int) $request->query('limit', 25), 100));
    }

    private function page(Request $request): int
    {
        return max(1, (int) $request->query('offset', 1));
    }
}
