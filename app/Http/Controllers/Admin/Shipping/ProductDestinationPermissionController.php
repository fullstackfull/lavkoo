<?php

namespace App\Http\Controllers\Admin\Shipping;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductDestinationPermission;
use Illuminate\Support\Facades\DB;
use App\Services\AuditLogger;
use App\Services\Shipping\ProductDestinationPermission as PermissionReader;
use App\Services\Shipping\RegulatedShippingGate;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Where a person grants or withdraws one product's permission to reach one country (H1-03).
 *
 * The three permissions this screen writes -- carrying a thing, taking it out of the country it is sold in, bringing it into the country it
 * is going to -- are granted by three different authorities, and the platform refuses a destination it has no record for. So this screen is
 * where a refusal becomes a permission, and every write of it is audited: who, when, what it was before and what it is now. Withdrawing is
 * the same act in reverse, and deleting the row is how a destination goes back to being refused.
 *
 * It grants nothing by itself and assumes nothing: a product with no row here may not go abroad, which is the platform's default and not an
 * oversight. Who is entitled to decide these lists is the owner's Q-13; this screen records whoever does.
 */
class ProductDestinationPermissionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly RegulatedShippingGate $regulated)
    {
    }

    public function index(Request $request): View
    {
        $product = $request->filled('product_id') ? $this->productNamed($request->integer('product_id')) : null;

        return view('admin-views.shipping.destination-permissions', [
            'product' => $product,
            'permissions' => $product === null || !$this->tableReady()
                ? collect()
                : ProductDestinationPermission::query()->where('product_id', $product->id)->orderBy('destination_country')->get(),
            'countries' => $this->destinations(),
            'ready' => $this->tableReady(),
            'clearanceNeededFor' => $product === null ? [] : $this->clearanceNeededFor((int) $product->id),
            'mayRecordClearance' => $this->regulated->mayRecordClearance((int) auth('admin')->id()),
        ]);
    }

    /**
     * Writes one product's permission for one destination, or withdraws it.
     *
     * A destination the platform does not serve is refused here rather than stored: a permission for a country nobody sells in is a row
     * that will be read one day and believed.
     */
    public function record(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'destination_country' => ['required', 'string', 'size:2'],
            'may_ship' => ['nullable', 'boolean'],
            'may_export' => ['nullable', 'boolean'],
            'may_import' => ['nullable', 'boolean'],
            'withdraw' => ['nullable', 'boolean'],
            'clearance_reference' => ['nullable', 'string', 'max:191'],
        ]);

        if (!$this->tableReady()) {
            return back()->withErrors(['product_id' => translate('the_shipping_permission_table_has_not_been_created_yet')]);
        }

        $destination = strtoupper(trim($validated['destination_country']));

        if (!in_array($destination, $this->destinations(), true)) {
            return back()->withErrors(['destination_country' => translate('that_country_is_not_one_this_platform_serves')]);
        }

        $product = $this->productNamed((int) $validated['product_id']);

        if ($product === null) {
            return back()->withErrors(['product_id' => translate('product_not_found')]);
        }

        $reference = trim((string) ($validated['clearance_reference'] ?? ''));
        $mayClear = $this->regulated->mayRecordClearance((int) auth('admin')->id());

        if ($reference !== '' && !$mayClear) {
            return back()->withErrors(['clearance_reference' => translate('you_are_not_authorised_to_record_a_regulatory_clearance')]);
        }

        $existing = ProductDestinationPermission::query()
            ->where('product_id', $product->id)
            ->where('destination_country', $destination)
            ->first();
        $before = $existing?->only(['may_ship', 'may_export', 'may_import', 'clearance_reference']);

        if ($request->boolean('withdraw')) {
            $existing?->delete();
            $this->audit->record(
                action: 'shipping.destination_permission_withdrawn',
                subject: ['type' => Product::class, 'id' => $product->id],
                before: $before,
                after: null,
                context: ['destination_country' => $destination],
            );
            PermissionReader::forget();
            RegulatedShippingGate::forget();

            return back()->with('success', translate('the_permission_was_withdrawn_and_that_destination_is_refused_again'));
        }

        $after = [
            'may_ship' => $request->boolean('may_ship'),
            'may_export' => $request->boolean('may_export'),
            'may_import' => $request->boolean('may_import'),
            // Only somebody entitled to record a clearance may change one, in either direction. Their form disables the field, so an
            // ordinary save by somebody else arrives empty -- and an empty field must not quietly withdraw a licence reference that is
            // on file, which would both destroy a record and be a regulatory act by somebody who may not perform it.
            'clearance_reference' => $mayClear ? ($reference === '' ? null : $reference) : $existing?->clearance_reference,
        ];

        ProductDestinationPermission::query()->updateOrCreate(
            ['product_id' => $product->id, 'destination_country' => $destination],
            $after + ['recorded_by' => auth('admin')->id(), 'recorded_at' => now()],
        );

        $this->audit->record(
            action: 'shipping.destination_permission_recorded',
            subject: ['type' => Product::class, 'id' => $product->id],
            before: $before,
            after: $after,
            context: ['destination_country' => $destination],
        );
        PermissionReader::forget();
        RegulatedShippingGate::forget();

        return back()->with('success', translate('the_permission_was_recorded'));
    }

    /**
     * The product's id and name, read as a row rather than as a model.
     *
     * A permission screen has no use for a product's translations, reviews, stocks or images, and loading the model drags all of them in:
     * this page is about which countries an id may reach, so an id and a name is the whole of what it needs.
     */
    private function productNamed(int $productId): ?object
    {
        return DB::table('products')->where('id', $productId)->first(['id', 'name']);
    }

    /**
     * The destinations this product needs a clearance for (H1-04), so the screen asks for the reference while a person is granting the
     * permission rather than leaving a refused customer to discover it.
     *
     * @return list<string>
     */
    private function clearanceNeededFor(int $productId): array
    {
        $source = StorefrontResolver::fromConfig()->defaultCountry();

        return array_values(array_filter(
            $this->destinations(),
            fn (string $destination): bool => $this->regulated->needsClearance(productId: $productId, source: $source, destination: $destination),
        ));
    }

    /** The countries this platform serves, which are the only destinations a permission may name. */
    private function destinations(): array
    {
        return StorefrontResolver::fromConfig()->configuredCountries();
    }

    private function tableReady(): bool
    {
        return Schema::hasTable('product_destination_permissions');
    }
}
