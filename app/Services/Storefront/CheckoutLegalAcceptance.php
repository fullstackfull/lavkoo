<?php

namespace App\Services\Storefront;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Records, at checkout, which legal texts the customer confirmed they were shown (GCC-11, J-15).
 *
 * `StorefrontLegalAcceptanceRecorder` has been able to write a receipt for a while and NOTHING CALLED IT, so the platform kept no record of
 * what any customer ever agreed to. This is the caller: the three v1 order-placement endpoints the customer app uses hand over the order
 * ids once the orders exist, and one row is written per order and per required document type.
 *
 * **The server decides what must be accepted, the client only says what it showed.** The loop is over
 * `gcc.storefronts.legal.explicit_acceptance`, which ships EMPTY, so nothing is required and nothing is recorded until an operator lists a
 * type -- the Saudi checkout is untouched -- and a client naming a thousand invented types costs a thousand nothings rather than a
 * thousand lookups. The request's `accepted_documents` only FILTERS that list: a required type the client did not name writes no row,
 * because a receipt for a text nobody was shown would be worse than none. The recorder refuses a type it does not require as well, so the
 * two cannot disagree about what is required.
 *
 * **The locale is the one the API served in**, not a second claim from the client: `/api/v1` sets the application locale from the request's
 * own language header, and that is the language the text was answered in.
 *
 * **The market is the ORDER's**, read from the order row rather than from the request, because the row is the record that outlives the
 * request and a receipt must never name a market its own order does not. At these three endpoints the two agree by construction -- the
 * checkout records the market it was served from -- so the difference is only visible when the rows say different things, which is what the
 * service's own test drives. An order that records no market, which is every order of a platform with storefronts off, falls back to the
 * market being served and then to the default one.
 *
 * **It never fails a checkout.** The orders exist by the time this runs, the money may already have moved, and a missing text or a database
 * refusal is a gap in the record rather than a reason to lose a paid order: the failure is reported and the order stands. That is a
 * deliberate trade, and the gate that would REFUSE a checkout for an unaccepted text is an owner decision this does not take.
 */
#[Singleton]
final class CheckoutLegalAcceptance
{
    public function __construct(private readonly StorefrontLegalAcceptanceRecorder $recorder)
    {
    }

    /**
     * @param list<int|string> $orderIds the orders the checkout just created
     * @return int how many receipts were written
     */
    public function record(Request $request, array $orderIds, ?int $customerId = null, ?string $guestId = null): int
    {
        $showed = $this->typesTheClientShowed($request);
        $confirmed = array_values(array_filter(
            $this->typesThatNeedAcceptance(),
            static fn (string $type): bool => in_array($type, $showed, true),
        ));

        if ($confirmed === [] || $orderIds === []) {
            return 0;
        }

        $locale = strtolower(app()->getLocale());
        $markets = $this->marketOfEachOrder($orderIds);
        $written = 0;

        foreach ($orderIds as $orderId) {
            foreach ($confirmed as $documentType) {
                $written += $this->recordOne(
                    country: $markets[(string) $orderId] ?? $this->marketBeingServed(),
                    documentType: $documentType,
                    locale: $locale,
                    customerId: $customerId,
                    guestId: $guestId,
                    orderId: (int) $orderId,
                );
            }
        }

        return $written;
    }

    private function recordOne(string $country, string $documentType, string $locale, ?int $customerId, ?string $guestId, int $orderId): int
    {
        try {
            return $this->recorder->recordIfRequired($country, $documentType, $locale, customerId: $customerId, guestId: $guestId, orderId: $orderId) === null ? 0 : 1;
        } catch (RuntimeException | Throwable $failure) {
            // The order exists and may be paid for. A gap in the record is reported, not thrown.
            report($failure);

            return 0;
        }
    }

    /** @return list<string> */
    private function typesThatNeedAcceptance(): array
    {
        $configured = array_map(static fn (mixed $type): string => strtolower(trim((string) $type)), (array) config('gcc.storefronts.legal.explicit_acceptance', []));

        return array_values(array_unique(array_filter($configured, static fn (string $type): bool => $type !== '')));
    }

    /**
     * The document types the client says it showed, from `accepted_documents`: a list of names, or one comma-separated string, because both
     * are what a form and a JSON client send. Anything else is read as none.
     *
     * @return list<string>
     */
    private function typesTheClientShowed(Request $request): array
    {
        $sent = $request->input('accepted_documents');

        if (is_string($sent)) {
            $sent = explode(',', $sent);
        }

        if (!is_array($sent)) {
            return [];
        }

        $names = array_map(
            static fn (mixed $entry): string => strtolower(trim((string) (is_array($entry) ? ($entry['type'] ?? '') : $entry))),
            $sent,
        );

        return array_values(array_unique(array_filter($names, static fn (string $name): bool => $name !== '')));
    }

    /**
     * The market each order records, by order id as a string.
     *
     * @param list<int|string> $orderIds
     * @return array<string, string>
     */
    private function marketOfEachOrder(array $orderIds): array
    {
        $markets = [];

        foreach (DB::table('orders')->whereIn('id', $orderIds)->get() as $order) {
            $country = strtoupper(trim((string) ($order->storefront_country ?? '')));

            if ($country !== '') {
                $markets[(string) $order->id] = $country;
            }
        }

        return $markets;
    }

    private function marketBeingServed(): string
    {
        $resolver = StorefrontResolver::fromConfig();
        $country = StorefrontCatalog::country();

        return is_string($country) && trim($country) !== '' ? strtoupper($country) : $resolver->defaultCountry();
    }
}
