<?php

namespace App\Http\Controllers\Admin\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Http\Controllers\Controller;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\Support\SafeToast;
use App\Services\Fulfillment\Verification\CheckContext;
use App\Services\Fulfillment\Verification\EvidenceStore;
use App\Services\Fulfillment\Verification\LiveVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Runs the read-only checks against a provider from the provider's page, and hands out the evidence they leave. */
class LiveVerificationController extends Controller
{
    public function __construct(
        private readonly LiveVerification $verification,
        private readonly EvidenceStore $evidence,
    ) {
    }

    public function run(Request $request, string $provider): RedirectResponse
    {
        $code = ProviderCode::fromSlug($provider) ?? abort(404);
        $validated = $request->validate(['skus' => ['nullable', 'string', 'max:300', 'regex:/^[A-Za-z0-9,._\- ]*$/']]);
        $skus = CheckContext::parseSkus((string) ($validated['skus'] ?? ''));

        try {
            $run = $this->verification->run($code, ['skus' => $skus]);
        } catch (ProviderUnavailableException $exception) {
            SafeToast::warning('', $exception->getMessage());

            return back();
        }

        $summary = $run->summary();
        $detail = "{$summary['pass']} passed, {$summary['warn']} to look at, {$summary['fail']} failed, {$summary['skipped']} skipped";
        $run->failed() ? SafeToast::warning(translate('verification_finished_with_failures'), $detail) : SafeToast::success(translate('verification_finished'), $detail);

        return redirect()->to(route('admin.fulfillment-providers.show', $code->slug()) . '#live-verification');
    }

    public function download(string $provider, string $run): BinaryFileResponse
    {
        $code = ProviderCode::fromSlug($provider) ?? abort(404);
        $path = $this->evidence->path($code->value, $run) ?? abort(404);

        return response()->download($path, $run . '.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }
}
