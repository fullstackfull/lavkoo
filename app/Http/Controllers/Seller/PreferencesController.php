<?php

namespace App\Http\Controllers\Seller;

use App\Services\SellerCenter\Shell;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

/**
 * Density and reading direction.
 *
 * Both are stored server-side and applied on the app root at render time. A client-side class swap
 * after paint is a visible flash on every navigation, and the density decides row heights the
 * server already knows how to emit.
 */
class PreferencesController extends SellerCenterController
{
    public function density(Request $request): RedirectResponse
    {
        Shell::setDensity((string) $request->query('value'));

        return $this->back($request);
    }

    /**
     * The top-bar EN / ع switch.
     *
     * It sets the language as well as the direction, because they are one decision: a panel reading
     * right-to-left in English strands every ellipsis and every mixed-script line on the wrong side.
     * The language code comes from the marketplace's own configured list rather than a constant, so
     * an install using a different Arabic locale folder still resolves.
     */
    public function direction(Request $request): RedirectResponse
    {
        $rtl = $request->query('value') === 'rtl';
        Session::put('direction', $rtl ? 'rtl' : 'ltr');
        Session::put('local', $this->languageFor($rtl ? 'rtl' : 'ltr'));

        return $this->back($request);
    }

    private function languageFor(string $direction): string
    {
        foreach ((array) getWebConfig(name: 'language') as $language) {
            if (($language['direction'] ?? 'ltr') === $direction && ($language['status'] ?? 0)) {
                return (string) $language['code'];
            }
        }

        return $direction === 'rtl' ? 'sy' : 'en';
    }

    /** Only ever back to this panel: a `back` parameter is an open-redirect if it is trusted. */
    private function back(Request $request): RedirectResponse
    {
        $target = (string) $request->query('back', '');
        $path = parse_url($target, PHP_URL_PATH) ?: '';

        return redirect()->to(str_starts_with($path, '/vendor') ? $target : route('seller.home'));
    }
}
