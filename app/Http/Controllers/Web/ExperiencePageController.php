<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ExperiencePage;
use App\Models\Theme;
use App\Services\Theme\Channel;
use App\Services\Theme\ExperiencePageService;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A page the merchant composed, on the website.
 *
 * Custom pages were reachable by the app and by nothing else — the storefront knew three page names
 * and had a route for one of them. A merchant could build an Offers page, see it in the builder,
 * serve it to the phone, and have no address to put on a banner.
 *
 * This is that address. It renders through the same shell every composed page uses, so a section
 * behaves identically here and on the home page; the only thing this adds is which page to draw and
 * who is allowed to see it.
 */
class ExperiencePageController extends Controller
{
    public function __construct(private readonly ExperiencePageService $pages)
    {
    }

    public function show(string $slug): View
    {
        $theme = Theme::query()->where('is_active', true)->first();

        if ($theme === null) {
            throw new NotFoundHttpException();
        }

        // The built-in pages are not addresses: home already has one, and the header and footer
        // are fragments of every page rather than pages of their own. Serving them here would give
        // the home page a second URL and the fragments a first.
        if (in_array($slug, ExperiencePage::SYSTEM_SLUGS, true)) {
            throw new NotFoundHttpException();
        }

        // The website may show a page that is shared or made for the web. A page a merchant made
        // for the app is the app's alone, and a 404 is the honest answer for it here — not an
        // empty page that looks like the shop is broken.
        $servable = $this->pages->servableSlugs($theme->id, Channel::WEB);

        if (!in_array($slug, $servable, true)) {
            throw new NotFoundHttpException();
        }

        $page = $this->pages->find($theme->id, $slug);

        return view('theme-sections.page', [
            'pageSlug'  => $slug,
            'pageTitle' => $page?->displayTitle() ?? ucfirst($slug),
        ]);
    }
}
