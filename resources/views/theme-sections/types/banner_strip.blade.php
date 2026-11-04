@include('theme-sections.partials.banner-strip', [
    'card' => [
        'image' => $s['image'] ?? null, 'eyebrow' => $s['eyebrow'] ?? null,
        'title' => $s['title'] ?? null, 'subtitle' => $s['subtitle'] ?? null,
        'link' => $s['link'] ?? null, 'button_text' => $s['button_text'] ?? null,
    ],
    'settings' => $s, 'placeholder' => $__placeholder,
])
