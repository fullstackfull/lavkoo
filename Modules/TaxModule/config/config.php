<?php

return [

    /**
     * The display name of the module.
     */
    'name' => 'TaxModule',

    'project' => '6valley',

    'version' => '1.0.0',

    'pagination' => 10,

    'country_type' => 'single',

    /**
     * The path to the module's thumbnail image.
     *
     * Uses the `getModuleDynamicAsset` helper to generate
     * the correct public URL for the asset.
     */
    'thumbnail' => getModuleDynamicAsset(path: 'public/Modules/TaxModule/module-assets/thumbnail.png'),
];
