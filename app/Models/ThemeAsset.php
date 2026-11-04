<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * An image uploaded for a theme (logo, favicon, section background).
 *
 * Belongs to the THEME, not a version, so assets survive publishing and revision restores.
 */
class ThemeAsset extends Model
{
    protected $fillable = [
        'theme_id', 'label', 'path', 'disk', 'mime_type', 'size_bytes',
        'uploaded_by_type', 'uploaded_by_id',
    ];

    protected $casts = ['size_bytes' => 'integer'];

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    /** Public URL, or null if the file has gone missing (never throws in a view). */
    public function getUrlAttribute(): ?string
    {
        try {
            return Storage::disk($this->disk)->url($this->path);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Human-readable size.
     *
     * Rounding straight to KB renders a 197-byte icon as "0 KB", which reads as a failed upload,
     * so anything under 1 KB stays in bytes and KB keeps one decimal.
     */
    public function getSizeForHumansAttribute(): string
    {
        $bytes = max(0, (int) $this->size_bytes);

        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 1), '0'), '.') . ' KB';
        }

        return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1), '0'), '.') . ' MB';
    }
}
