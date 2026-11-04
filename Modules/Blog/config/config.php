<?php

return [

    /**
     * The display name of the module.
     */
    'name' => 'Blog',

    /**
     * The path to the module's thumbnail image.
     *
     * Uses the `getModuleDynamicAsset` helper to generate
     * the correct public URL for the asset.
     */
    'thumbnail' => getModuleDynamicAsset(path: 'public/Modules/Blog/module-assets/thumbnail.png'),
];
