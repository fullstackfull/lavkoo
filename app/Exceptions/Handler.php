<?php

namespace App\Exceptions;

use App\Traits\ErrorLogsTrait;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Handler extends ExceptionHandler
{
    use ErrorLogsTrait;

    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    /**
     * Report or log an exception.
     *
     * @param Throwable $e
     * @return void
     *
     * @throws Throwable
     */
    public function report(Throwable $e): void
    {
        parent::report($e);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param Request $request
     * @param Throwable $e
     * @return Response
     *
     * @throws Throwable
     */
    public function render($request, Throwable $e): Response
    {
        if ($this->isHttpException($e) && $e?->getStatusCode() == 404) {
            $redirectUrl = $this->storeErrorLogsUrl(url: $request->fullUrl(), statusCode: $e->getStatusCode());
            if ($redirectUrl && isset($redirectUrl['redirect_url'])) {
                return redirect(to: $redirectUrl['redirect_url'], status: ($redirectUrl['redirect_status'] ?? '301'));
            }
        }
        return parent::render($request, $e);
    }
}
