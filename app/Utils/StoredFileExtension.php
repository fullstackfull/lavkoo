<?php

namespace App\Utils;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The extension a stored upload gets, and which uploaded images are stored as they came.
 *
 * A file lands on a disk the web server serves, so its name decides what a request for it does: a script engine runs a name it is set up
 * for (.php, .phtml, .pht, .phar, .phps, .inc, ...), and a browser opens .html, .svg, .xml or .shtml as a page that runs script in the
 * shop's own origin. Whatever extension the client wrote, a stored file gets one from the short list of data types below, and anything
 * else is stored as `bin`.
 *
 * The client's own extension is kept when it is on the list: a .pdf is served as a document whatever its bytes are. When it is not, the
 * content is asked instead, so a real JPEG that carries code is stored as .jpg and no longer runs, while a page, an SVG or a script is
 * stored as `bin`.
 */
final class StoredFileExtension
{
    /** The formats the platform accepts for images, documents, archives, audio and video, without the ones a browser renders as a page. */
    private const DATA_TYPES = [
        'jpg', 'jpeg', 'jpe', 'jif', 'jfif', 'jfi', 'png', 'gif', 'webp', 'tif', 'tiff', 'bmp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv',
        'zip', 'rar', 'tar',
        'mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac',
        'mp4', 'mkv', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mpeg', 'mpg', 'm4v', '3gp', 'ogv',
    ];

    public const UNKNOWN = 'bin';

    /** @param File|null $file the uploaded file, when the content can be asked */
    public static function of(string $clientExtension, ?File $file = null): string
    {
        $extension = strtolower($clientExtension);
        if (in_array($extension, self::DATA_TYPES, true)) {
            return $extension;
        }

        $detected = strtolower((string) $file?->guessExtension());

        return in_array($detected, self::DATA_TYPES, true) ? $detected : self::UNKNOWN;
    }

    /**
     * An animated image is the one image that is stored as it came, because encoding it again would keep only its first frame. It is a GIF
     * by its name and by its content; everything else is decoded and encoded again, which also keeps an SVG or a page out of an image field.
     */
    public static function isGifToKeep(File $image): bool
    {
        return $image instanceof UploadedFile
            && $image->getClientOriginalExtension() === 'gif'
            && $image->getMimeType() === 'image/gif';
    }
}
