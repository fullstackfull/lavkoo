<?php

/*
|--------------------------------------------------------------------------
| Unified Saudi address
|--------------------------------------------------------------------------
|
| One address book for the whole store. Regions and cities are rows the administrator manages (address_regions,
| address_cities); every courier, warehouse or marketplace is mapped to those rows and never keeps a city list of its own.
|
| Whether customers are asked for the structured address is NOT decided here: it is the `unified_address_mode` setting,
| changed in the admin panel (Business setup > Address system), and it stays `off` until an administrator turns it on.
*/

return [

    /*
    | Country the structured address applies to. Addresses in any other country keep the old free-text city and the ZIP code.
    */
    'country' => 'SA',

    /*
    | National Address (العنوان الوطني), the short address issued by Saudi Post (SPL): four letters followed by four digits,
    | for example RAGI2929. The digits are the building number; the letters are assigned from the postal code and are not
    | chosen by the customer. SPL publishes this shape but not the list of letters in use, so only the shape is enforced:
    | nothing here pretends to know which letter combinations exist. See docs/address/national-address.md.
    */
    'national_address' => [
        'letters' => 4,
        'digits' => 4,
    ],

    /*
    | How many results the city search returns, and how short a search may be before it is answered with the first cities of
    | the region instead.
    */
    'search' => [
        'limit' => 25,
        'max_limit' => 100,
    ],

    /*
    | Data files the `address:import` command reads. Each file records where its rows come from; nothing is imported silently.
    */
    'seed' => [
        'regions' => database_path('data/address/regions.json'),
        'cities' => database_path('data/address/cities.json'),
        'aliases' => database_path('data/address/aliases.json'),
    ],
];
