<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Storefront\StorefrontChoice;
use App\Services\Storefront\StorefrontPathPrefix;
use App\Services\Storefront\StorefrontUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The visitor's choice of market (A1-06): the one thing that writes it, and the only one.
 *
 * It is a POST because it changes what the site serves this visitor, and a GET that did it could be done to them by a link, an image or a
 * prefetch. It keeps the choice ({@see StorefrontChoice}) and then sends them to the page they were on, inside the market they chose -- so
 * the address they end on names the market as well, and a share or a bookmark of it carries it (A1-05).
 *
 * A market the platform does not serve is refused, with nothing kept, and the visitor is left where they were.
 */
class StorefrontChoiceController extends Controller
{
    public function __construct(
        private readonly StorefrontChoice $choice,
        private readonly StorefrontPathPrefix $prefix,
        private readonly StorefrontUrl $urls,
    ) {
    }

    public function choose(Request $request): RedirectResponse
    {
        if (!$this->choice->isOffered()) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $country = $request->input('storefront');

        if (!$this->choice->remember(country: is_string($country) ? $country : null)) {
            return back()->with('error', translate('this_store_is_not_available'));
        }

        return redirect()->to($this->urls->of(url: $this->pageTheyWereOn($request), country: (string) $country));
    }

    /**
     * The page the visitor was on, with any market's segment taken off it, so the market they chose can be put on instead -- a visitor
     * switching from `/kw/contacts` to Emirates belongs at `/ae/contacts`, never at `/ae/kw/contacts`.
     *
     * A referer from anywhere but this installation, and no referer at all, answer the installation's own root: the choice is still kept,
     * and they land on the home page of the market they asked for.
     */
    private function pageTheyWereOn(Request $request): string
    {
        $back = (string) $request->headers->get('referer', '');
        $root = rtrim(url('/'), '/');

        if ($back !== $root && !str_starts_with($back, $root . '/')) {
            return $root;
        }

        $rest = substr($back, strlen($root));
        $query = parse_url($rest, PHP_URL_QUERY);

        return $root . $this->prefix->withoutAnyPrefix((string) (parse_url($rest, PHP_URL_PATH) ?: '/'))
            . ($query === null || $query === '' ? '' : '?' . $query);
    }
}
