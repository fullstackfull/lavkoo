<?php

namespace App\Enums\ViewPaths\Admin;

enum SocialLoginSettings
{
    const VIEW = [
        URI => 'view',
        VIEW => 'admin-views.third-party.social-login.view'
    ];

    const UPDATE = [
        URI => 'update',
        VIEW => ''
    ];
}
