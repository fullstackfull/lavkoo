<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppCarouselSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppCarouselController extends Controller
{
    public function index(): View
    {
        $settings = AppCarouselSetting::first();

        if (!$settings) {
            $settings = AppCarouselSetting::create([
                'images' => [],
                'texts' => [],
                'show_text' => false,
                'show_carousel' => false,
            ]);
        }

        return view(
            'admin-views.app-carousel.index',
            compact('settings')
        );
    }


    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'texts' => [
                'nullable',
                'array',
            ],

            'texts.*' => [
                'nullable',
                'string',
                'max:500',
            ],

            'images' => [
                'nullable',
                'array',
            ],

            'images.*' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ]);


        $settings = AppCarouselSetting::firstOrCreate(
            [],
            [
                'images' => [],
                'texts' => [],
                'show_text' => false,
                'show_carousel' => false,
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Texts
        |--------------------------------------------------------------------------
        */

        $texts = collect(
            $validated['texts'] ?? []
        )
            ->filter(fn ($text) => filled($text))
            ->values()
            ->toArray();


        /*
        |--------------------------------------------------------------------------
        | Existing images
        |--------------------------------------------------------------------------
        */

        $images = $settings->images ?? [];


        /*
        |--------------------------------------------------------------------------
        | Upload new images directly to public/uploads/app-carousel
        |--------------------------------------------------------------------------
        */

        if ($request->hasFile('images')) {

            $destinationPath = public_path(
                'uploads/app-carousel'
            );

            /*
             * إنشاء المجلد إذا لم يكن موجودًا.
             */
            if (!is_dir($destinationPath)) {

                if (
                    !mkdir(
                        $destinationPath,
                        0755,
                        true
                    ) &&
                    !is_dir($destinationPath)
                ) {
                    return redirect()
                        ->route('admin.app-carousel.index')
                        ->with(
                            'error',
                            'Unable to create carousel upload directory.'
                        );
                }
            }


            foreach ($request->file('images') as $image) {

                if (!$image) {
                    continue;
                }

                $extension = strtolower(
                    $image->getClientOriginalExtension()
                );

                $fileName =
                    'carousel_'
                    . uniqid('', true)
                    . '.'
                    . $extension;


                $image->move(
                    $destinationPath,
                    $fileName
                );


                /*
                 * URL كامل يتم حفظه في DB
                 */
                $images[] = asset(
                    'uploads/app-carousel/' . $fileName
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Save
        |--------------------------------------------------------------------------
        */

        $settings->update([
            'images' => array_values($images),

            'texts' => $texts,

            'show_text' =>
                $request->boolean('show_text'),

            'show_carousel' =>
                $request->boolean('show_carousel'),
        ]);


        return redirect()
            ->route('admin.app-carousel.index')
            ->with(
                'success',
                'App carousel settings updated successfully.'
            );
    }


    public function deleteImage(
        Request $request
    ): RedirectResponse {

        $validated = $request->validate([
            'image' => [
                'required',
                'string',
            ],
        ]);


        $settings = AppCarouselSetting::first();


        if (!$settings) {

            return redirect()
                ->route('admin.app-carousel.index')
                ->with(
                    'error',
                    'Carousel settings not found.'
                );
        }


        $imageUrl = $validated['image'];


        /*
        |--------------------------------------------------------------------------
        | Remove image URL from database
        |--------------------------------------------------------------------------
        */

        $images = collect(
            $settings->images ?? []
        )
            ->reject(
                fn ($image) =>
                    $image === $imageUrl
            )
            ->values()
            ->toArray();


        /*
        |--------------------------------------------------------------------------
        | Delete physical file
        |--------------------------------------------------------------------------
        |
        | نتعامل فقط مع الصور الموجودة داخل:
        |
        | public/uploads/app-carousel
        |
        */

        $urlPath = parse_url(
            $imageUrl,
            PHP_URL_PATH
        );


        if (
            is_string($urlPath) &&
            str_contains(
                $urlPath,
                '/uploads/app-carousel/'
            )
        ) {

            $fileName = basename($urlPath);

            $filePath = public_path(
                'uploads/app-carousel/' . $fileName
            );


            /*
             * حماية إضافية:
             * نحذف فقط من مجلد carousel.
             */

            $carouselDirectory = realpath(
                public_path('uploads/app-carousel')
            );

            $realFilePath = realpath($filePath);


            if (
                $carouselDirectory &&
                $realFilePath &&
                str_starts_with(
                    $realFilePath,
                    $carouselDirectory
                ) &&
                is_file($realFilePath)
            ) {
                @unlink($realFilePath);
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Update database
        |--------------------------------------------------------------------------
        */

        $settings->update([
            'images' => $images,
        ]);


        return redirect()
            ->route('admin.app-carousel.index')
            ->with(
                'success',
                'Carousel image deleted successfully.'
            );
    }
}