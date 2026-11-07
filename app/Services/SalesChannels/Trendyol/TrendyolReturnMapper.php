<?php

namespace App\Services\SalesChannels\Trendyol;

use App\Services\SalesChannels\Data\RemoteReturn;
use Carbon\CarbonImmutable;

/**
 * Turns a Trendyol claim (a customer return) into the units it has given back. Only claim items the channel has ACCEPTED count: Created, WaitingInAction, InAnalysis,
 * Rejected, Unresolved and Cancelled return nothing. A claim names the OUTBOUND package (`orderOutboundPackageId`), which is the package we hold; its own
 * `orderShipmentPackageId` is the return parcel. Each claim item is one unit of one order line.
 */
class TrendyolReturnMapper
{
    /** @param array<string, mixed> $claim one element of `content[]` */
    public function map(array $claim): ?RemoteReturn
    {
        $orderNumber = (string) ($claim['orderNumber'] ?? '');
        $outbound = (string) ($claim['orderOutboundPackageId'] ?? '');
        if ($orderNumber === '' || $outbound === '') {
            return null;
        }

        $accepted = [];
        $statuses = [];
        foreach ((array) ($claim['items'] ?? []) as $item) {
            $lineId = (string) ($item['orderLine']['id'] ?? $item['orderLine']['lineId'] ?? '');
            // Every line the claim names is listed, with 0 when nothing of it is accepted: a claim that was accepted and then changed must be able to take its units back.
            if ($lineId !== '') {
                $accepted[$lineId] ??= 0;
            }
            foreach ((array) ($item['claimItems'] ?? []) as $claimItem) {
                $status = (string) ($claimItem['claimItemStatus']['name'] ?? '');
                $statuses[] = $status;
                if ($lineId !== '' && $status === 'Accepted') {
                    $accepted[$lineId] = ($accepted[$lineId] ?? 0) + 1;
                }
            }
        }

        return new RemoteReturn(
            claimId: (string) ($claim['claimId'] ?? $claim['id'] ?? ''),
            orderId: $orderNumber,
            packageId: $outbound,
            status: $statuses === [] ? 'unknown' : implode(',', array_values(array_unique($statuses))),
            acceptedUnits: $accepted,
            updatedAt: is_numeric($claim['lastModifiedDate'] ?? null) ? CarbonImmutable::createFromTimestampMsUTC((int) $claim['lastModifiedDate']) : null,
        );
    }
}
