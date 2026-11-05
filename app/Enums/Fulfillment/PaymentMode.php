<?php

namespace App\Enums\Fulfillment;

enum PaymentMode: string
{
    case Prepaid = 'prepaid';
    case CashOnDelivery = 'cod';
}
