<?php

namespace App\Traits;


trait ThemeHelper
{
    /**
     * Add-on menu routes came from installable themes (public/addon/theme_routes.php).
     * The single built-in theme ships none, so the admin sidebar gets no extra menu.
     */
    public function getThemeRoutesArray(): array
    {
        return [];
    }
}
