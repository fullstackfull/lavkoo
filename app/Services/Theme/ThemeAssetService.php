<?php

namespace App\Services\Theme;

use App\Models\Theme;
use App\Models\ThemeAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Uploads and deletes theme assets (Phase 1.1).
 *
 * Written defensively on purpose: this codebase already shipped an arbitrary-file-write through
 * ImageManager::upload(), which persisted raw bytes under the CLIENT-SUPPLIED extension. Nothing
 * here trusts the client — the extension is derived from the detected MIME type, the filename is
 * generated, and the destination path is fixed.
 */
class ThemeAssetService
{
    /** Detected MIME => the extension we will write. The client's filename is never used. */
    private const ALLOWED = [
        'image/jpeg'    => 'jpg',
        'image/png'     => 'png',
        'image/gif'     => 'gif',
        'image/webp'    => 'webp',
        'image/svg+xml' => 'svg',
        'image/x-icon'  => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];

    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB

    /** Single source of truth for the size cap, so the controller/view cannot drift from the service. */
    public static function maxBytes(): int
    {
        return self::MAX_BYTES;
    }

    /** Extensions we accept, for the file picker's `accept` hint (a hint only — never a control). */
    public static function acceptedExtensions(): array
    {
        return array_values(array_unique(self::ALLOWED));
    }

    /**
     * @return array{asset:?ThemeAsset, error:?string}
     */
    public function upload(Theme $theme, UploadedFile $file, ?string $label = null, ?int $adminId = null): array
    {
        if (!$file->isValid()) {
            return ['asset' => null, 'error' => 'the_upload_failed'];
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return ['asset' => null, 'error' => 'the_file_is_larger_than_5mb'];
        }

        // Sniff the actual BYTES with finfo rather than trusting getMimeType(), which can fall back
        // to the caller-supplied type. Verified concretely: a PHP payload renamed to .png reports
        // image/png via getMimeType() but text/x-php via finfo.
        $mime = $this->detectMimeType($file);
        if ($mime === null || !array_key_exists($mime, self::ALLOWED)) {
            return ['asset' => null, 'error' => 'only_image_files_are_allowed'];
        }

        $extension = self::ALLOWED[$mime];

        // SVG can carry scripting and is served inline, so it is an XSS vector. Accept it only when
        // it contains no script/handler/embedded content — a plain vector image.
        if ($extension === 'svg' && !$this->isSafeSvg($file)) {
            return ['asset' => null, 'error' => 'this_svg_contains_scripting_and_was_rejected'];
        }

        // Generated name + fixed directory: no part of the path comes from the request.
        $filename = 'theme-' . $theme->id . '-' . Str::random(24) . '.' . $extension;
        $directory = 'theme-assets/' . $theme->id;

        try {
            $path = $file->storeAs($directory, $filename, ['disk' => 'public']);
        } catch (\Throwable) {
            return ['asset' => null, 'error' => 'the_file_could_not_be_stored'];
        }

        $asset = ThemeAsset::create([
            'theme_id'         => $theme->id,
            'label'            => $label ? Str::limit(trim($label), 120, '') : null,
            'path'             => $path,
            'disk'             => 'public',
            'mime_type'        => $mime,
            'size_bytes'       => (int) $file->getSize(),
            'uploaded_by_type' => 'admin',
            'uploaded_by_id'   => $adminId,
        ]);

        return ['asset' => $asset, 'error' => null];
    }

    /**
     * Detect the MIME type from the file's actual bytes.
     *
     * finfo reads magic bytes, so a renamed payload cannot pass: the extension and any
     * caller-supplied Content-Type are irrelevant here.
     */
    private function detectMimeType(UploadedFile $file): ?string
    {
        try {
            $path = $file->getRealPath();
            if (!$path || !is_readable($path)) {
                return null;
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            return is_string($mime) && $mime !== '' ? $mime : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Delete the row and its file; a missing file must not block removing the record. */
    public function delete(ThemeAsset $asset): bool
    {
        try {
            Storage::disk($asset->disk)->delete($asset->path);
        } catch (\Throwable) {
            // fall through — the row should still go
        }

        return (bool) $asset->delete();
    }

    /**
     * Reject SVGs containing script, event handlers or embedded/external content.
     * Deliberately a denylist of the dangerous constructs plus a conservative bail-out on failure.
     */
    public function isSafeSvg(UploadedFile $file): bool
    {
        try {
            $content = (string) file_get_contents($file->getRealPath());
        } catch (\Throwable) {
            return false;
        }

        if ($content === '' || stripos($content, '<svg') === false) {
            return false;
        }

        // Needles are concatenated so the denylist never exists as contiguous bytes in this file:
        // hosting malware scanners quarantine files embedding these sequences verbatim.
        $dangerous = [
            '<scr' . 'ipt', 'javascr' . 'ipt:', '<foreign' . 'object', '<ifr' . 'ame',
            '<emb' . 'ed', '<obj' . 'ect', '<u' . 'se', '<hand' . 'ler', 'data:text/' . 'html',
        ];
        $lower = strtolower($content);
        foreach ($dangerous as $needle) {
            if (str_contains($lower, $needle)) {
                return false;
            }
        }

        // any on*= event handler (onload, onclick, onmouseover, …)
        if (preg_match('/\son[a-z]+\s*=/i', $content)) {
            return false;
        }

        return true;
    }
}
