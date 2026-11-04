<?php

namespace App\Http\Controllers\RestAPI\v3;

use App\Http\Controllers\Controller;
  use App\Models\AppCarouselSetting;
class newcontroller extends Controller
{
  

public function curselre()
    {
        $settings = AppCarouselSetting::first();

        return response()->json([
            'img' =>
                $settings?->images ?? [],

            'txt' =>
                $settings?->texts ?? [],

            'showtxt' =>
                $settings?->show_text
                    ? 'yes'
                    : 'no',

            /*
             * لا نغير اسم المفتاح حتى لا ينكسر التطبيق الحالي
             */
            'sowcur' =>
                $settings?->show_carousel
                    ? 'yes'
                    : 'no',
        ]);
    }



}