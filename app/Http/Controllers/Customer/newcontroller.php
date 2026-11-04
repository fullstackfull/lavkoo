<?php

namespace App\Http\Controllers\Customer;


use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Validator;
use App\Models\ThemeSection;
use App\Models\ThemeBlock;
use App\Models\Banner;


class newController extends Controller
{
public function banner_ndex()
{
    // جلب الأقسام الظاهرة
    $theme = ThemeSection::where('type', 'banner_mosaic')
                         ->where('theme_version_id', '25')
                         ->where('is_visible', '1')
                         ->get();

    // التكرار على كل قسم
    foreach ($theme as $the) {
        $blocks = ThemeBlock::where('theme_section_id', $the->id)
                            ->where('is_visible', '1')
                            ->get();

        // التكرار على البلوكات لجلب رابط البانر
        foreach ($blocks as $block) {
            $settings = is_array($block->settings) 
                ? $block->settings 
                : json_decode(json_encode($block->settings), true);

            if (!empty($settings['banner_id'])) {
                // جلب قيمة الـ url فقط باستخدام value() لتكون مباشرة وسريعة
                $block->url = Banner::where('id', $settings['banner_id'])->value('url');
            } else {
                $block->url = null;
            }
        }

        // إضافة البلوكات للقسم الحالي
        $the->block = $blocks; 
    }

    return response()->json(['section' => $theme]);
}

}