<?php

namespace Tests\Support\StaffProbe;

/** An action that names an order only in its path: the order scan reads the path and the parameters too. */
class NewOrderPathOnlyController
{
    public function show(string $orderId): string
    {
        return $orderId;
    }

    public function harmless(): string
    {
        return 'nothing here';
    }
}
