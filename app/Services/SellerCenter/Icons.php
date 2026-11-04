<?php

namespace App\Services\SellerCenter;

/**
 * The Seller Center glyph set.
 *
 * The design references Phosphor by semantic name (handoff 03 §12, 06). The panel ships them as
 * inline SVG from this one map rather than pulling an icon font from a CDN: the panel must render
 * with no outbound request, the glyphs must inherit `currentColor` so a severity token can carry
 * them, and a missing network must never leave a control with no icon at all.
 *
 * Names are the Phosphor names the handoff uses, so a spec line reading `clock-countdown` maps
 * here without translation. An unknown name renders nothing rather than a broken box.
 */
class Icons
{
    /**
     * 24×24 stroke paths, drawn on the same grid and weight so the set reads as one family.
     *
     * @var array<string, string>
     */
    private const PATHS = [
        // ── rail groups ──────────────────────────────────────────────────
        'gauge' => '<path d="M12 20a8 8 0 1 0-8-8"/><path d="M4 12a8 8 0 0 1 16 0"/><path d="m12 12 4-4"/><circle cx="12" cy="12" r="1.4"/>',
        'receipt' => '<path d="M5 21V4a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v17l-3-2-3 2-3-2-3 2Z"/><path d="M9 8h6M9 12h6"/>',
        'tag' => '<path d="M4 12.5V5a1 1 0 0 1 1-1h7.5a1 1 0 0 1 .7.3l7 7a1 1 0 0 1 0 1.4l-6.5 6.5a1 1 0 0 1-1.4 0l-7-7a1 1 0 0 1-.3-.7Z"/><circle cx="8.5" cy="8.5" r="1.2"/>',
        'stack' => '<path d="m3 8 9-4 9 4-9 4-9-4Z"/><path d="m3 12 9 4 9-4"/><path d="m3 16 9 4 9-4"/>',
        'price-tag' => '<path d="M4 12.5V5a1 1 0 0 1 1-1h7.5a1 1 0 0 1 .7.3l7 7a1 1 0 0 1 0 1.4l-6.5 6.5a1 1 0 0 1-1.4 0l-7-7a1 1 0 0 1-.3-.7Z"/><path d="M9 11h3M9 14h4"/>',
        'truck' => '<path d="M3 6h11v10H3z"/><path d="M14 9h4l3 3v4h-7z"/><circle cx="7" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/>',
        'bank' => '<path d="m3 9 9-5 9 5"/><path d="M5 9v9M9.5 9v9M14.5 9v9M19 9v9"/><path d="M3 20h18"/>',
        'activity' => '<path d="M3 12h4l2.5-7 5 14L17 12h4"/>',
        'chart-line-up' => '<path d="M4 19V5"/><path d="M4 19h16"/><path d="m7 15 3.5-4 3 2.5L19 7"/><path d="M15 7h4v4"/>',
        'seal-check' => '<path d="m12 3 2.2 1.6 2.7-.2.8 2.6 2.3 1.4-1 2.6 1 2.6-2.3 1.4-.8 2.6-2.7-.2L12 21l-2.2-1.6-2.7.2-.8-2.6L4 15.6l1-2.6-1-2.6L6.3 9l.8-2.6 2.7.2Z"/><path d="m9 12 2 2 4-4"/>',
        'plugs' => '<path d="M7 3v5M11 3v5"/><path d="M5 8h8v3a4 4 0 0 1-8 0V8Z"/><path d="M9 15v6"/><path d="M17 21v-5M21 21v-5"/><path d="M19 16h0"/>',
        'users-three' => '<circle cx="12" cy="9" r="2.6"/><circle cx="5.5" cy="10.5" r="2.2"/><circle cx="18.5" cy="10.5" r="2.2"/><path d="M7.5 18a5 5 0 0 1 9 0"/><path d="M2 17a4 4 0 0 1 4-2.6"/><path d="M22 17a4 4 0 0 0-4-2.6"/>',
        'translate' => '<path d="M3 6h8M7 4v2c0 4-1.6 7-4 9"/><path d="M5 11c1.6 2.6 3.6 4.2 6 5"/><path d="m13 20 4-10 4 10"/><path d="M14.5 17h5"/>',

        // ── severity + status ────────────────────────────────────────────
        'warning-octagon' => '<path d="M8.3 3h7.4L21 8.3v7.4L15.7 21H8.3L3 15.7V8.3Z"/><path d="M12 8v5"/><circle cx="12" cy="16.2" r=".9" fill="currentColor" stroke="none"/>',
        'warning' => '<path d="M12 4 2.8 20h18.4Z"/><path d="M12 10v4"/><circle cx="12" cy="17.2" r=".9" fill="currentColor" stroke="none"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><circle cx="12" cy="8.2" r=".9" fill="currentColor" stroke="none"/>',
        'dot-outline' => '<circle cx="12" cy="12" r="4"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
        'x-circle' => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7"/>',
        'x' => '<path d="M6 6 18 18M18 6 6 18"/>',
        'note-pencil' => '<path d="M19 13v6a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h6"/><path d="M17.5 3.5a2.1 2.1 0 0 1 3 3L13 14l-4 1 1-4Z"/>',
        'pause' => '<path d="M9 5v14M15 5v14"/>',
        'play' => '<path d="M7 5.5 18.5 12 7 18.5Z"/>',
        'calendar-dot' => '<rect x="3.5" y="5" width="17" height="15" rx="1.5"/><path d="M8 3v4M16 3v4M3.5 10h17"/><circle cx="12" cy="15" r="1.4" fill="currentColor" stroke="none"/>',
        'calendar-x' => '<rect x="3.5" y="5" width="17" height="15" rx="1.5"/><path d="M8 3v4M16 3v4M3.5 10h17"/><path d="m10 13.5 4 4M14 13.5l-4 4"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15" rx="1.5"/><path d="M8 3v4M16 3v4M3.5 10h17"/>',
        'hourglass' => '<path d="M7 3h10M7 21h10"/><path d="M7 3c0 4 5 6 5 9s-5 5-5 9"/><path d="M17 3c0 4-5 6-5 9s5 5 5 9"/>',
        'spinner' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8"/>',
        'paper-plane-tilt' => '<path d="M21 3 3 10.5l7.5 3L13.5 21Z"/><path d="m10.5 13.5 4-4"/>',
        'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'clock-countdown' => '<path d="M12 3a9 9 0 1 1-8.5 6"/><path d="M3.2 6.5 3.5 9.6l3.1-.5"/><path d="M12 7.5V12l3 1.8"/>',
        'scales' => '<path d="M12 4v16M7 20h10"/><path d="M4 9h6l-3 5Z"/><path d="M14 9h6l-3 5Z"/><path d="m4.5 8 15-2"/>',
        'magnifying-glass' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'seal-warning' => '<path d="m12 3 2.2 1.6 2.7-.2.8 2.6 2.3 1.4-1 2.6 1 2.6-2.3 1.4-.8 2.6-2.7-.2L12 21l-2.2-1.6-2.7.2-.8-2.6L4 15.6l1-2.6-1-2.6L6.3 9l.8-2.6 2.7.2Z"/><path d="M12 8v4.5"/><circle cx="12" cy="15.6" r=".8" fill="currentColor" stroke="none"/>',
        'prohibit' => '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>',
        'question' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3 2.4V14"/><circle cx="12.5" cy="16.6" r=".85" fill="currentColor" stroke="none"/>',
        'lock' => '<rect x="4.5" y="10" width="15" height="10" rx="1.5"/><path d="M8 10V7.5a4 4 0 0 1 8 0V10"/>',

        // ── interface ────────────────────────────────────────────────────
        'caret-up-down' => '<path d="m8 10 4-4 4 4M8 14l4 4 4-4"/>',
        'caret-down' => '<path d="m6 9.5 6 6 6-6"/>',
        'caret-right' => '<path d="m9.5 6 6 6-6 6"/>',
        'caret-left' => '<path d="m14.5 6-6 6 6 6"/>',
        'bell' => '<path d="M6 10a6 6 0 0 1 12 0c0 4 1.5 5.5 1.5 5.5h-15S6 14 6 10Z"/><path d="M10 19a2 2 0 0 0 4 0"/>',
        'gear-six' => '<circle cx="12" cy="12" r="3"/><path d="M12 3.2 14 5h2.6l1.3 2.3 2 1.3v2.6l1 2.2-1.7 1.7-.6 2.5-2.5.6-1.7 1.7-2.2-1-2.2 1-1.7-1.7-2.5-.6-.6-2.5L3.5 13l1-2.2V8.2L6.4 7 7.7 4.7 10 4Z"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1Z"/>',
        'lifebuoy' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/><path d="m5.6 5.6 3.6 3.6M14.8 14.8l3.6 3.6M18.4 5.6l-3.6 3.6M9.2 14.8l-3.6 3.6"/>',
        'arrow-left' => '<path d="M20 12H4"/><path d="m10 6-6 6 6 6"/>',
        'arrow-right' => '<path d="M4 12h16"/><path d="m14 6 6 6-6 6"/>',
        'arrow-up' => '<path d="M12 20V4"/><path d="m6 10 6-6 6 6"/>',
        'arrow-down' => '<path d="M12 4v16"/><path d="m6 14 6 6 6-6"/>',
        'arrow-up-right' => '<path d="M7 17 17 7"/><path d="M9 7h8v8"/>',
        'arrow-down-right' => '<path d="M7 7l10 10"/><path d="M17 9v8H9"/>',
        'arrow-elbow-down-left' => '<path d="M20 5v7a2 2 0 0 1-2 2H5"/><path d="m9 10-4 4 4 4"/>',
        'arrow-clockwise' => '<path d="M20 12a8 8 0 1 1-2.4-5.7"/><path d="M20.5 4v4h-4"/>',
        'arrows-down-up' => '<path d="M7 4v13M4 14l3 3 3-3"/><path d="M17 20V7M14 10l3-3 3 3"/>',
        'dots-three' => '<circle cx="6" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="18" cy="12" r="1.4" fill="currentColor" stroke="none"/>',
        'funnel' => '<path d="M3.5 5h17l-6.5 8v6l-4 2v-8Z"/>',
        'rows' => '<rect x="3.5" y="4.5" width="17" height="6" rx="1.2"/><rect x="3.5" y="13.5" width="17" height="6" rx="1.2"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'minus' => '<path d="M5 12h14"/>',
        'upload-simple' => '<path d="M12 15V4"/><path d="m8 8 4-4 4 4"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
        'download-simple' => '<path d="M12 4v11"/><path d="m8 11 4 4 4-4"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
        'target' => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="1.2" fill="currentColor" stroke="none"/>',
        'package' => '<path d="m12 3 8.5 4.5v9L12 21l-8.5-4.5v-9Z"/><path d="M3.5 7.5 12 12l8.5-4.5M12 12v9"/>',
        'factory' => '<path d="M4 20V9l5 3V9l5 3V9l6 3.5V20Z"/><path d="M4 9 5 4h3l1 5"/><path d="M9 16h2M14 16h2"/>',
        'handshake' => '<path d="m3 11 3-3 4 1 2-1 2 1 4-1 3 3"/><path d="m6 8 3 5 2-1 2 2 2-1 3-5"/><path d="M4 11v5a1 1 0 0 0 1 1h2M20 11v5a1 1 0 0 1-1 1h-2"/>',
        'storefront' => '<path d="M4 10v9a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-9"/><path d="M3 10 5 4h14l2 6a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0Z"/><path d="M10 20v-5h4v5"/>',
        'printer' => '<path d="M7 9V4h10v5"/><path d="M5 9h14a2 2 0 0 1 2 2v5h-4v4H7v-4H3v-5a2 2 0 0 1 2-2Z"/>',
        'trash' => '<path d="M4 7h16"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/><path d="M6.5 7 7.5 20h9L17.5 7"/><path d="M10.5 11v5M13.5 11v5"/>',
        'pencil-simple' => '<path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4.5 1.5L5 15Z"/>',
        'link' => '<path d="M10 13.5a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7L11.5 6.3"/><path d="M14 10.5a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1.5-1.5"/>',
        'user' => '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>',
        'sign-out' => '<path d="M14 5H6a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8"/><path d="M17 8.5 20.5 12 17 15.5"/><path d="M20 12h-9"/>',
        'keyboard' => '<rect x="2.5" y="6" width="19" height="12" rx="1.5"/><path d="M6 9.5h.01M9.5 9.5h.01M13 9.5h.01M16.5 9.5h.01M6 13h.01M18 9.5h.01M18 13h.01M9 13h6"/>',
        'shield-check' => '<path d="M12 3.5 20 6v6c0 4.5-3.4 7.5-8 9-4.6-1.5-8-4.5-8-9V6Z"/><path d="m9 12 2 2 4-4"/>',
        'file-text' => '<path d="M13 3H7a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V8Z"/><path d="M13 3v5h5"/><path d="M9 13h6M9 17h4"/>',
        'barcode' => '<path d="M3 6v12M6 6v12M9.5 6v12M13 6v8M16.5 6v12M20 6v12"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
        'currency-circle-dollar' => '<circle cx="12" cy="12" r="9"/><path d="M12 6.5v11"/><path d="M14.5 9.2a2.6 2.6 0 0 0-2.5-1.4c-1.5 0-2.6.8-2.6 2s1 1.8 2.6 2.2 2.6 1 2.6 2.2-1.1 2-2.6 2a2.6 2.6 0 0 1-2.5-1.4"/>',
        'squares-four' => '<rect x="4" y="4" width="7" height="7" rx="1"/><rect x="13" y="4" width="7" height="7" rx="1"/><rect x="4" y="13" width="7" height="7" rx="1"/><rect x="13" y="13" width="7" height="7" rx="1"/>',
        'sliders-horizontal' => '<path d="M3 7h11M18 7h3M3 17h3M10 17h11"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
        'copy' => '<rect x="8" y="8" width="12" height="12" rx="1.5"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/>',
    ];

    /** @return array<int, string> */
    public static function names(): array
    {
        return array_keys(self::PATHS);
    }

    public static function has(string $name): bool
    {
        return isset(self::PATHS[$name]);
    }

    /** The inner markup of one glyph, or an empty string for an unknown name. */
    public static function paths(string $name): string
    {
        return self::PATHS[$name] ?? '';
    }
}
