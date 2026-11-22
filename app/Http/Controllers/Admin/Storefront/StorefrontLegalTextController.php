<?php

namespace App\Http\Controllers\Admin\Storefront;

use App\Http\Requests\Admin\Storefront\StorefrontLegalTextRequest;
use App\Services\Storefront\StorefrontLegalPublisher;
use App\Services\Storefront\StorefrontLegalTextBoard;
use App\Services\Storefront\StorefrontPermissionService;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * I2-03: the screen where the legal text of a storefront is written, approved, published and revoked. Writing (a draft from a template, an edit, a new version, putting a draft
 * away) and approving (approving, publishing from a day, revoking) are two permissions, so that the person who writes a text is not the person who puts it in force; the
 * publisher refuses the author as the approver in any case. Every step is the one StorefrontLegalPublisher takes for the console command, audited in the same transaction.
 */
class StorefrontLegalTextController extends StorefrontAdminController
{
    public function __construct(
        private readonly StorefrontLegalPublisher $publisher,
        private readonly StorefrontLegalTextBoard $board,
    ) {
    }

    public function index(string $country): View
    {
        $code = strtoupper($country);
        abort_unless(in_array($code, StorefrontResolver::fromConfig()->configuredCountries(), true), 404);

        return view('admin-views.storefronts.legal-texts', [
            'board' => $this->board->of($code),
            'tableExists' => $this->board->tableExists(),
            'canWrite' => StorefrontPermissionService::can(StorefrontPermissionService::LEGAL_WRITE),
            'canApprove' => StorefrontPermissionService::can(StorefrontPermissionService::LEGAL_APPROVE),
            'viewer' => (int) auth('admin')->id(),
        ]);
    }

    public function draft(StorefrontLegalTextRequest $request, string $country): RedirectResponse
    {
        return $this->attempt(
            step: fn () => $this->publisher->draftFromTemplate(country: $country, documentType: $request->documentType(), locale: $request->locale(), template: $request->template(), author: $request->admin()),
            done: 'the_draft_is_written',
        );
    }

    public function newVersion(StorefrontLegalTextRequest $request, int $text): RedirectResponse
    {
        return $this->attempt(
            step: fn () => $this->publisher->draftNewVersionOf(textId: $text, author: $request->admin()),
            done: 'the_new_version_is_a_draft',
        );
    }

    public function edit(StorefrontLegalTextRequest $request, int $text): RedirectResponse
    {
        return $this->attempt(
            step: fn () => $this->publisher->edit(textId: $text, body: $request->body(), editor: $request->admin()),
            done: 'the_text_is_saved',
        );
    }

    public function discard(StorefrontLegalTextRequest $request, int $text): RedirectResponse
    {
        return $this->attempt(
            step: fn () => $this->publisher->discard(textId: $text, admin: $request->admin(), reason: $request->reason()),
            done: 'the_text_is_put_away',
        );
    }

    public function approve(StorefrontLegalTextRequest $request, int $text): RedirectResponse
    {
        return $this->attempt(
            step: fn () => $this->publisher->approve(textId: $text, approver: $request->admin()),
            done: 'the_text_is_approved',
        );
    }

    public function publish(StorefrontLegalTextRequest $request, int $text): RedirectResponse
    {
        return $this->attempt(
            step: fn () => $this->publisher->publish(textId: $text, publisher: $request->admin(), effectiveFrom: $request->effectiveFrom()),
            done: 'the_text_is_published',
        );
    }

    public function revoke(StorefrontLegalTextRequest $request, int $text): RedirectResponse
    {
        return $this->attempt(
            step: fn () => $this->publisher->revoke(textId: $text, revoker: $request->admin(), reason: $request->reason()),
            done: 'the_text_is_revoked',
        );
    }
}
