<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\BaseController;
use App\Models\Product;
use App\Services\Seo\SeoAuditService;
use App\Services\Seo\SeoMetaResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * SEO Health — an admin surface over the previously-orphaned SeoAuditService.
 *
 * Runs the audit over a page of products (the largest SEO surface), resolving each product's title and
 * description through the real SeoMetaResolver chain (translation -> per-entity -> template) so the
 * issues reflect what actually renders. Read-only: it reports, it never changes SEO data.
 */
class SeoHealthController extends BaseController
{
    public function __construct(
        private readonly SeoAuditService $audit,
        private readonly SeoMetaResolver $resolver,
    ) {
    }

    public function index(?Request $request = null, ?string $type = null): View
    {
        $request ??= request();
        $language = getDefaultLanguage();

        $products = Product::active()->latest('id')->paginate(30)->appends($request->all());

        $rows = [];
        $totals = ['critical' => 0, 'warning' => 0, 'notice' => 0, 'total' => 0];

        foreach ($products as $product) {
            $meta = $this->resolver->resolve(
                seoableType: Product::class,
                seoableId: $product->id,
                languageCode: $language,
                entityType: 'product',
                templateData: ['name' => $product->name],
            );

            $issues = $this->audit->analyze(
                entity: [
                    'title' => $meta['title'] ?? '',
                    'description' => $meta['description'] ?? '',
                ],
                context: [
                    'h1' => $product->name,
                    'canonical' => route('product', $product->slug),
                    'has_schema' => true, // PDP emits Product JSON-LD
                    'is_indexable' => true,
                ],
            );

            $summary = $this->audit->summarize($issues);
            foreach (['critical', 'warning', 'notice', 'total'] as $k) {
                $totals[$k] += $summary[$k] ?? 0;
            }

            if (($summary['total'] ?? 0) > 0) {
                $rows[] = ['product' => $product, 'issues' => $issues, 'summary' => $summary];
            }
        }

        return view('admin-views.seo-settings.health', [
            'products' => $products,
            'rows' => $rows,
            'totals' => $totals,
        ]);
    }
}
