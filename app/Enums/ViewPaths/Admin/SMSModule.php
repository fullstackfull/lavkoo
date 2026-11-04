<?php

namespace App\Enums\ViewPaths\Admin;

enum SMSModule
{
    const VIEW = [
        URI => 'sms-module',
        VIEW => 'admin-views.third-party.sms-index'
    ];

    const UPDATE = [
        URI => 'addon-sms-set',
        VIEW => ''
    ];

}
