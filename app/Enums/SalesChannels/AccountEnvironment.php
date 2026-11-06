<?php

namespace App\Enums\SalesChannels;

enum AccountEnvironment: string
{
    case Production = 'production';
    case Sandbox = 'sandbox';
}
