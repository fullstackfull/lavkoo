<?php

namespace App\Utils;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

class ImageManager
{
    public static function upload(string $dir, string $format, $image, $file_type = 'image'): string
    {
        $storage = config('filesystems.disks.default') ?? 'public';
        if ($image != null) {
            if (!Storage::disk($storage)->exists($dir)) {
                Storage::disk($storage)->makeDirectory($dir);
            }

            // NOTE: an unconditional raw write used to happen here, persisting the uploaded bytes
            // under the CLIENT-SUPPLIED extension (e.g. .php/.phtml) before any re-encoding — and
            // its filename was then overwritten by both branches below, so the file was orphaned
            // and never even used. That made every upload path an arbitrary-file-write primitive.
            // Removed: each branch below writes the file it actually returns.

            if (in_array($image->getClientOriginalExtension(), ['gif', 'svg'])) {
                $imageName = Carbon::now()->toDateString() . "-" . uniqid() . "." . $image->getClientOriginalExtension();
                Storage::disk($storage)->put($dir . $imageName, file_get_contents($image));
            } else {
                if (in_array(request()->ip(), ['127.0.0.1', '::1']) && !(imagetypes() & IMG_WEBP) || env('APP_DEBUG') && !(imagetypes() & IMG_WEBP)) {
                    $format = 'png';
                }
                $imageWebp = Image::make($image)->encode($format, 85);
                $imageName = Carbon::now()->toDateString() . "-" . uniqid() . "." . $format;
                Storage::disk($storage)->put($dir . $imageName, $imageWebp);
                $imageWebp->destroy();
            }
        } else {
            $imageName = 'def.webp';
        }
        return $imageName;
    }

    public static function file_upload(string $dir, string $format, $file = null): string
    {
        $storage = config('filesystems.disks.default') ?? 'public';
        if ($file != null) {
            $fileName = Carbon::now()->toDateString() . "-" . uniqid() . "." . $format;
            if (!Storage::disk($storage)->exists($dir)) {
                Storage::disk($storage)->makeDirectory($dir);
            }
            Storage::disk($storage)->put($dir . $fileName, file_get_contents($file));
        } else {
            $fileName = 'def.png';
        }

        return $fileName;
    }

    public static function update(string $dir, $old_image, string $format, $image, $file_type = 'image'): string
    {
        if (self::checkFileExists(filePath: $dir.$old_image)['status']) {
            Storage::disk(self::checkFileExists(filePath: $dir . $old_image)['disk'])->delete($dir . $old_image);
        }

        return $file_type == 'file' ? ImageManager::file_upload($dir, $format, $image) : ImageManager::upload($dir, $format, $image);
    }

    public static function delete($full_path): array
    {
        if (self::checkFileExists(filePath: $full_path)['status']) {
            Storage::disk(self::checkFileExists(filePath: $full_path)['disk'])->delete($full_path);
        }
        return [
            'success' => 1,
            'message' => 'Removed successfully !'
        ];

    }
    public static function checkFileExists(string $filePath): array
    {
        if (Storage::disk('public')->exists($filePath)) {
            return [
                'status' => true,
                'disk' => 'public'
            ];
        } elseif (config('filesystems.disks.default') == 's3' && Storage::disk('s3')->exists($filePath)) {
            return [
                'status' => true,
                'disk' => 's3'
            ];
        } else {
            return [
                'status' => false,
                'disk' => config('filesystems.disks.default') ?? 'public'
            ];
        }
    }

}
