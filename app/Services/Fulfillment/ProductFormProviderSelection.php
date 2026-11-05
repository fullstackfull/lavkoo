<?php

namespace App\Services\Fulfillment;

use App\Services\Fulfillment\Data\AssignmentResult;
use App\Utils\Helpers;
use Illuminate\Http\Request;

/**
 * Turns the product form's "Fulfillment provider" field into an assignment.
 *
 * The field is optional: a form that was not shown it (vendor product, gallery clone, an admin without the
 * third-party permission, a database where the migration has not run) does not send it, and nothing
 * happens. The assignment service stays the only thing that changes a product's provider, and it refuses
 * what is unsafe; this class only decides whether the form had a say.
 */
class ProductFormProviderSelection
{
    public const FIELD = 'fulfillment_provider';

    public const PERMISSION = '3rd_party_setup';

    public function __construct(private readonly ProductProviderAssignmentService $assignments)
    {
    }

    /** The outcome, or null when this submission carried no choice that this admin may make. */
    public function apply(Request $request, int $productId): ?AssignmentResult
    {
        if (!$request->has(self::FIELD) || !FulfillmentSchema::productColumnReady() || !Helpers::module_permission_check(self::PERMISSION)) {
            return null;
        }

        return $this->assignments->assign(
            productId: $productId,
            provider: (string) $request->input(self::FIELD),
            actorType: 'admin',
            actorId: auth('admin')->id(),
            reason: 'product_form',
        );
    }
}
