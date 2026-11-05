<?php

namespace App\Services\Fulfillment\Verification;

use App\Services\Fulfillment\Support\HttpResult;
use Closure;

/**
 * One read-only question put to a provider. The call is a closure over a provider client: only operations the registry
 * classifies as a read (or the login that only returns a token) are ever called from here, and a test proves it.
 */
final readonly class VerificationCheck
{
    /**
     * @param string $label translation key of the check's name
     * @param Closure(CheckContext): (HttpResult|null) $call null means "cannot ask yet" (a prerequisite is missing); say why through the context
     * @param array<string, mixed> $request what is shown as the request: method, path, query. Never a header or a credential.
     * @param bool $strict the response is about people (an order list): keep no free text
     * @param bool $expectsList the response should hold a list of records (false for a login or one record's detail)
     * @param (Closure(HttpResult, CheckContext, array<string, mixed>): array<string, mixed>)|null $analyse extra findings that need the raw response
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $operation,
        public array $request,
        public Closure $call,
        public bool $strict = false,
        public ?Closure $analyse = null,
        public bool $expectsList = true,
    ) {
    }
}
