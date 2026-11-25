<?php

namespace App\Models\Scopes;

/**
 * A shipping query of a request that is narrowed to the countries of the signed-in employee only reaches the rows of those countries
 * (GCC-14c slice 6; H1-14).
 *
 * The refusals came first: an employee restricted to Kuwait could not CHANGE the Saudi store's delivery price, but still saw it on the list,
 * which is a screen that shows what it will not let you touch. This closes that, and it closes it for every list, counter, picker and filter
 * of those screens at once, whether written with the repository, the model or a relation.
 *
 * It is `StaffCountryMarketRowScope` under the name the shipping tables already use: the rule is the same for every table whose rows carry
 * their own market, and there is one implementation of it. Two models name this class (`ShippingMethod`, `CategoryShippingCost`) and the
 * shipping lane reads it by this name, so the name stays; the rule itself, and why an unmarked row is the default storefront's, are written
 * out in the parent.
 */
final class StaffCountryShippingScope extends StaffCountryMarketRowScope
{
}
