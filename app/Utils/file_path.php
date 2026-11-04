<?php

use App\Enums\GlobalConstant;
use App\Rules\DisallowedExtension;
use App\Utils\FileManagerLogic;
use Illuminate\Support\Str;


if (!function_exists('productImagePath')) {
    function productImagePath(string $type): string
    {
        return asset(GlobalConstant::FILE_PATH['product'][$type]);
    }
}

if (!function_exists('getStorageImages')) {
    function getStorageImages($path, $type = null, $source = null): string
    {
        $path = is_array($path) ? $path : (array)$path;
        if ($source && base_path($source)) {
            if ($type == 'payment-banner' && DOMAIN_POINTED_DIRECTORY == 'public') {
                return asset(str_replace('app/public/', '', $source));
            }
            return (!empty($path) && $path['status'] == 200) ? $path['path'] : dynamicAsset($source);
        }
        if ($source && file_exists($source)) {
            return (!empty($path) && $path['status'] == 200) ? $path['path'] : $source;
        }
        $placeholderMap = [
            'backend-basic' => 'back-end/img/placeholder/placeholder-1-1.png',
            'backend-brand' => 'back-end/img/placeholder/brand.png',
            'backend-banner' => 'back-end/img/placeholder/placeholder-4-1.png',
            'backend-category' => 'back-end/img/placeholder/category.png',
            'backend-logo' => 'back-end/img/placeholder/placeholder-4-1.png',
            'backend-product' => 'back-end/img/placeholder/product.png',
            'backend-profile' => 'back-end/img/placeholder/user.png',
            'backend-payment' => 'back-end/img/placeholder/placeholder-4-1.png',
            'backend-placeholder' => 'back-end/img/placeholder/placeholder-8-1.png',
            'product' => 'public/assets/front-end/img/placeholder/placeholder-1-1.png',
            'avatar' => 'public/assets/front-end/img/placeholder/user.png',
            'banner' => 'public/assets/front-end/img/placeholder/placeholder-2-1.png',
            'wide-banner' => 'public/assets/front-end/img/placeholder/placeholder-4-1.png',
            'brand' => 'public/assets/front-end/img/placeholder/placeholder-1-1.png',
            'category' => 'public/assets/front-end/img/placeholder/placeholder-1-1.png',
            'logo' => 'public/assets/front-end/img/placeholder/placeholder-4-1.png',
            'shop' => 'public/assets/front-end/img/placeholder/shop.png',
            'shop-banner' => 'public/assets/front-end/img/placeholder/seller-banner.png',
            'business-page' => 'public/assets/front-end/img/placeholder/business-page.png',
        ];
        if (isset($placeholderMap[$type])) {
            $placeholderPath = str_starts_with($placeholderMap[$type], 'public/')
                ? theme_asset(path: $placeholderMap[$type])
                : dynamicAsset(path: 'public/assets/' . $placeholderMap[$type]);
            return (!empty($path) && isset($path['status']) && $path['status'] == 200) ? $path['path'] : $placeholderPath;
        }

        return (!empty($path) && $path['status'] == 200) ? $path['path'] : dynamicStorage(path: 'public/assets/front-end/img/placeholder/placeholder-2-1.png');
    }
}
if(!function_exists('checkImageStatus')){
    function checkImageStatus($path, $storagePath): bool|array
    {
        $storageHelper = new class {
            use \App\Traits\StorageTrait;
        };
        return $storageHelper->storageLink('product/thumbnail', $path, $storagePath ?? 'public');
    }
}

if (!function_exists('dynamicAsset')) {
    function dynamicAsset(string $path): string
    {
        if (DOMAIN_POINTED_DIRECTORY == 'public') {
            $position = strpos($path, 'public/');
            $result = $path;
            if ($position === 0) {
                $result = preg_replace('/public/', '', $path, 1);
            }
        } else {
            $result = $path;
        }
        return asset($result);
    }
}

if (!function_exists('dynamicStorage')) {
    function dynamicStorage(string $path): string
    {
        if (DOMAIN_POINTED_DIRECTORY == 'public') {
            $result = str_replace('storage/app/public', 'storage', $path);
        } else {
            $result = $path;
        }
        return asset($result);
    }
}

if (!function_exists('getValidImage')) {
    function getValidImage($path, $type = null, $source = null): string
    {
        if (DOMAIN_POINTED_DIRECTORY == 'public') {
            $path = str_replace('storage/app/public', 'storage', $path);
        }

        $givenPath = dynamicStorage($path);

        if ($source) {
            return is_file($path) ? $givenPath : $source;
        }

        $placeholderMap = [
            'backend-basic' => 'back-end/img/placeholder/placeholder-1-1.png',
            'backend-brand' => 'back-end/img/placeholder/brand.png',
            'backend-banner' => 'back-end/img/placeholder/placeholder-4-1.png',
            'backend-category' => 'back-end/img/placeholder/category.png',
            'backend-logo' => 'back-end/img/placeholder/placeholder-4-1.png',
            'backend-product' => 'back-end/img/placeholder/product.png',
            'backend-profile' => 'back-end/img/placeholder/user.png',
            'backend-payment' => 'back-end/img/placeholder/placeholder-4-1.png',
            'product' => 'public/assets/front-end/img/placeholder/placeholder-1-1.png',
            'avatar' => 'public/assets/front-end/img/placeholder/user.png',
            'banner' => 'public/assets/front-end/img/placeholder/placeholder-2-1.png',
            'wide-banner' => 'public/assets/front-end/img/placeholder/placeholder-4-1.png',
            'brand' => 'public/assets/front-end/img/placeholder/placeholder-1-1.png',
            'category' => 'public/assets/front-end/img/placeholder/placeholder-1-1.png',
            'logo' => 'public/assets/front-end/img/placeholder/placeholder-4-1.png',
            'shop' => 'public/assets/front-end/img/placeholder/shop.png',
            'shop-banner' => 'public/assets/front-end/img/placeholder/seller-banner.png',
        ];

        if (isset($placeholderMap[$type])) {
            $placeholderPath = str_starts_with($placeholderMap[$type], 'public/')
                ? theme_asset(path: $placeholderMap[$type])
                : dynamicAsset(path: 'public/assets/' . $placeholderMap[$type]);

            return is_file($path) ? $givenPath : $placeholderPath;
        }

        return is_file($path) ? $givenPath : dynamicStorage(path: 'public/assets/front-end/img/placeholder/placeholder-2-1.png');
    }
}

if (!function_exists('validFileCheck')) {
    function validFileCheck($path)
    {
        $headers = get_headers($path);
        if (stripos($headers[0], "200 OK")) {
            return $path;
        } else {
            return null;
        }
    }
}

if (!function_exists('getTemporaryImageForExport')) {
    function getTemporaryImageForExport($imagePath)
    {
        $imageData = file_get_contents($imagePath);
        return imagecreatefromstring($imageData);
    }
}

if (!function_exists('getImageForExport')) {
    function getImageForExport($imagePath)
    {
        $temporaryImage = getTemporaryImageForExport($imagePath);
        $pngImage = imagecreatetruecolor(imagesx($temporaryImage), imagesy($temporaryImage));
        imagealphablending($pngImage, false);
        imagesavealpha($pngImage, true);
        imagecopy($pngImage, $temporaryImage, 0, 0, 0, 0, imagesx($temporaryImage), imagesy($temporaryImage));
        return $pngImage;
    }
}

if (!function_exists('getFileInfoFromURL')) {
    function getFileInfoFromURL($url = null): array
    {
        if (!$url) {
            return [
                'extension' => '',
                'mime_type' => '',
                'mime_category' => '',
                'size' => 0,
                'sizeReadable' => '0 B',
            ];
        }

        $isLocal = str_starts_with($url, 'http://127.0.0.1') ||
            str_starts_with($url, 'http://localhost');

        if ($isLocal) {
            $relativePath = str_replace(url('/'), '', $url);
            $filePath = public_path($relativePath);

            if (!file_exists($filePath)) {
                return [
                    'extension' => '',
                    'mime_type' => '',
                    'mime_category' => '',
                    'size' => 0,
                    'sizeReadable' => '0 B',
                ];
            }

            $fileMimeType = mime_content_type($filePath);
            $fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);
            $fileSize = filesize($filePath);

        } else {
            try {
                $headers = get_headers($url, true);
            } catch (\Throwable $e) {
                return [
                    'extension' => '',
                    'mime_type' => '',
                    'mime_category' => '',
                    'size' => 0,
                    'sizeReadable' => '0 B',
                ];
            }

            $fileMimeType = is_array($headers['Content-Type'] ?? null)
                ? end($headers['Content-Type'])
                : ($headers['Content-Type'] ?? 'unknown');

            $fileExtension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
            $fileSize = $headers['Content-Length'] ?? 0;
        }

        return [
            'extension' => $fileExtension,
            'mime_type' => $fileMimeType,
            'mime_category' => getCategorizeFileMimeType($fileMimeType),
            'size' => $fileSize,
            'sizeReadable' => FileManagerLogic::formatBytes($fileSize),
        ];
    }

}

if (!function_exists('getCategorizeFileMimeType')) {
    function getCategorizeFileMimeType($mimeType = ''): string
    {
        $mimeCategories = [
            'image' => [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/bmp', 'image/tiff',
                'image/x-icon', 'image/heif', 'image/heic'
            ],
            'video' => [
                'video/mp4', 'video/mpeg', 'video/ogg', 'video/webm', 'video/avi', 'video/quicktime',
                'video/x-msvideo', 'video/x-ms-wmv', 'video/x-flv', 'video/3gpp', 'video/3gpp2'
            ],
            'audio' => [
                'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/webm', 'audio/aac', 'audio/x-aac', 'audio/flac',
                'audio/mp4', 'audio/3gpp', 'audio/3gpp2', 'audio/midi', 'audio/x-midi', 'audio/x-wav',
                'audio/x-ms-wma', 'audio/x-ms-wmv', 'audio/x-realaudio'
            ],
            'document' => [
                'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.template', 'application/rtf', 'text/plain',
                'text/html', 'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.presentation',
                'application/vnd.oasis.opendocument.spreadsheet', 'application/vnd.oasis.opendocument.graphics',
                'application/x-abiword', 'application/x-appleworks', 'application/x-iwork-keynote-sffkey',
                'application/x-iwork-pages-sffpages', 'application/x-iwork-numbers-sffnumbers'
            ],
            'spreadsheet' => [
                'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.oasis.opendocument.spreadsheet', 'application/vnd.sun.xml.calc', 'application/vnd.lotus-1-2-3'
            ],
            'presentation' => [
                'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.openxmlformats-officedocument.presentationml.slideshow', 'application/vnd.oasis.opendocument.presentation'
            ],
            'application' => [
                'application/zip', 'application/x-rar-compressed', 'application/x-tar', 'application/x-7z-compressed',
                'application/x-bzip', 'application/x-bzip2', 'application/x-gzip', 'application/x-httpd-php',
                'application/x-shockwave-flash', 'application/x-java-archive', 'application/x-msdownload',
                'application/vnd.android.package-archive', 'application/x-msaccess', 'application/x-cab',
                'application/x-debian-package', 'application/x-redhat-package-manager'
            ],
            'archive' => [
                'application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed', 'application/x-tar',
                'application/gzip', 'application/x-bzip2', 'application/x-lzma', 'application/x-lzip',
                'application/x-xz'
            ],
            'font' => [
                'font/otf', 'font/ttf', 'font/woff', 'font/woff2', 'application/x-font-ttf',
                'application/x-font-woff', 'application/font-woff'
            ],
            'code' => [
                'text/css', 'text/javascript', 'application/javascript', 'application/json', 'application/xml',
                'text/xml', 'text/x-python', 'text/x-php', 'text/x-csrc', 'text/x-c++src', 'text/x-java-source',
                'application/x-sh', 'application/x-perl', 'text/x-ruby'
            ]
            // Add more categories and MIME types as needed
        ];

        foreach ($mimeCategories as $category => $types) {
            if (in_array($mimeType, $types)) {
                return $category;
            }
        }

        // Fallback if MIME type doesn't match any category
        if (strpos($mimeType, 'image/') === 0) {
            return 'image';
        } elseif (strpos($mimeType, 'video/') === 0) {
            return 'video';
        } elseif (strpos($mimeType, 'audio/') === 0) {
            return 'audio';
        } elseif (strpos($mimeType, 'text/') === 0) {
            return 'document';
        } elseif (strpos($mimeType, 'application/') === 0) {
            return 'application';
        }

        return 'unknown';
    }
}

if (!function_exists('checkUploadMaxFileSizeLimit')) {
    function checkUploadMaxFileSizeLimit($limit = 0): bool
    {
        return convertIniSizeToMB(ini_get('upload_max_filesize')) >= $limit;
    }
}

if (!function_exists('checkPostMaxFileSizeLimit')) {
    function checkPostMaxFileSizeLimit($limit = 0): bool
    {
        return convertIniSizeToMB(ini_get('post_max_size')) >= $limit;
    }
}

if (!function_exists('convertIniSizeToMB')) {
    function convertIniSizeToMB($config): int
    {
        $unit = Str::upper(Str::substr($config, -1));
        $value = (int)$config;
        if ($unit === 'G') {
            return $value * 1024;
        } elseif ($unit === 'K') {
            return $value / 1024;
        } elseif ($unit === 'M' || $unit === '') {
            return $value;
        }
        return $value;
    }
}

if (!function_exists('getFileUploadFormats')) {
    function getFileUploadFormats(string $type = "image", string|array|null $skip = null, bool $asRule = false, bool $asMessage = false, bool $asBladeMessage = false): string
    {
        $formats = [
            'image' => ['.jpeg', '.png', '.jpg', '.gif', '.svg', '.webp'],
            'file' => ['.pdf', '.doc', '.docx', '.txt','.zip', '.xls','.xlsx','.rar', '.mp4', '.mkv', '.avi', '.mov', '.wmv', '.flv', '.webm', '.mpeg', '.mpg', '.m4v', '.3gp', '.ogv'],
        ];
        $allowed = $formats[$type] ?? $formats['image'];
        if (!empty($skip)) {
            $skip = is_array($skip) ? $skip : explode(',', str_replace(' ', '', $skip));
            $allowed = array_values(array_diff($allowed, $skip));
        }
        $formatedResult = [];
        if ($asRule) {
            foreach ($allowed as $format) {
                $formatedResult[] = str_replace('.', '', $format);
            }
            $allowed = array_values($formatedResult);
        }

        if ($asBladeMessage) {
            return Str::upper(str_replace('.', '', implode(', ', $allowed)));
        }

        return $asMessage ? implode('_', $allowed) : ($asRule ? 'mimes:' . implode(',', $allowed) : implode(',', $allowed));
    }
}

if (!function_exists('getFileUploadMaxSize')) {
    function getFileUploadMaxSize(?string $type = 'image', string $unit = 'mb'): int {

        $uploadMaxInMB = (int)(checkServerUploadMaxFileSizeInMB(maxLimit: 1024) / 1024);
        if (env('APP_MODE', 'dev') === 'demo') {
            $finalValue = min($uploadMaxInMB, 1);
        } elseif (is_null($type) || $type === 'image') {
            $finalValue = min($uploadMaxInMB, 20);
        } elseif ($type === 'file') {
            $finalValue = min($uploadMaxInMB, 50);
        } else {
            $finalValue = min($uploadMaxInMB, 20);
        }
        return strtolower($unit) === 'kb' ? $finalValue * 1024 : $finalValue;
    }
}



if (!function_exists('getRulesStringForImageValidation')) {
    function getRulesStringForImageValidation(array $rules = [], array $skipMimes = [], ?int $maxSize = null, bool $isDisallowed = false) : array|string
    {
        $formats = getFileUploadFormats(skip: $skipMimes, asRule: true);
        $maxSize = $maxSize ?: getFileUploadMaxSize(unit: 'kb');
        if ($isDisallowed) {
            $arrayRules = [];
            foreach ($rules as $rule) {
                $arrayRules[] = $rule;
            }
            if (!empty($formats)) {
                $arrayRules[] = $formats;
            }
            $arrayRules[] = "max:{$maxSize}";
            $arrayRules[] = new DisallowedExtension();
            return $arrayRules;
        }
        $rulesJoin = !empty($rules) ? implode('|', $rules) : '';
        $parts = array_filter([$rulesJoin, $formats, "max:{$maxSize}"]);
        return implode('|', $parts);
    }
}

if (!function_exists('getDisallowedExtensionsListArray')) {
    function getDisallowedExtensionsListArray(): array
    {
        $groups = [
            'server_side' => [
                'php','php3','php4','php5','php7','php8','phtml','phar',
                'asp','aspx','jsp','cgi','pl','py','rb',
            ],
            'client_side' => [
                'js','mjs','html','htm','xhtml',
            ],
            'shell' => [
                'sh','bash','bat','cmd','ps1','zsh','ksh',
            ],
            'executables' => [
                'exe','dll','so','bin','msi','app',
            ],
            'java' => [
                'java','class','jar',
            ],
            'archives' => [
                '7z','gz','bz2','xz',
            ],
            'config' => [
                'env','ini','conf','config','yml','yaml','log',
            ],
            'data_backup' => [
                'sql','db','bak','old','swp','tmp',
            ],
        ];

        if (config('app.mode') === 'demo') {
            $groups['archives'][] = 'zip';
        }

        return array_unique(
            array_merge(...array_values($groups))
        );
    }
}


