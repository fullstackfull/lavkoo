<?php

namespace App\Models\Scopes;

use App\Services\Notification\NewsletterMarkets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * A subscriber query of a request that is narrowed to one or more markets only reaches the subscribers of those markets (I1-04).
 *
 * It is a scope rather than an argument for the reason {@see StaffCountryProductScope} is: the admin subscriber list and its export go
 * through the SAME repository method with different arguments, so narrowing at the call sites means narrowing twice and keeping the two in
 * step forever -- and an export that forgets is a file of every market's addresses leaving the building. One rule on the model narrows both,
 * and whatever reads the table next.
 *
 * Eloquent scope rather than a `where`, so what the query already holds is wrapped in parentheses first: the subscriber search ends in an
 * `orWhere` on the e-mail, and a bare `and` after it would let every market's matching address through.
 *
 * It does nothing for a request that bound no market, which is every storefront page, every queue, every console command, and every screen
 * while storefronts are off or before the column exists.
 */
final class NewsletterMarketScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $markets = app(NewsletterMarkets::class)->boundMarkets();

        if ($markets === null) {
            return;
        }

        $builder->where(function (Builder $scoped) use ($markets, $model): void {
            $scoped->whereIn($model->qualifyColumn(NewsletterMarkets::COLUMN), $markets);

            // The default storefront also owns the rows that name no market: a subscriber who signed up before the markets existed
            // signed up on the only storefront there has ever been. The migration says why this is the opposite of the broadcast rule.
            if (in_array(app(NewsletterMarkets::class)->of(null), $markets, true)) {
                $scoped->orWhereNull($model->qualifyColumn(NewsletterMarkets::COLUMN));
            }
            // The default storefront also owns the rows that name no market: a subscriber who signed up before the markets existed
            // signed up on the only storefront there has ever been. The migration says why this is the opposite of the broadcast rule.

        });
    }
}
