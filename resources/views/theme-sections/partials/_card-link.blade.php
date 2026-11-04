{{--
    The whole banner, made clickable.

    A banner is a picture with a destination, and on this storefront three of its presentations —
    the hero, the split panel and the campaign strip — put that destination only on a small button
    inside the caption. A slide with no title therefore had no clickable element at all, and the
    app, where the entire card has always been tappable, disagreed with the web about the same
    banner. This is the link that covers the card, so both behave the same way.

    A stretched overlay rather than a wrapper: the caption already contains its own anchor, and an
    <a> inside an <a> is invalid markup that browsers silently unnest. The button keeps working by
    sitting a layer above this (`.ml-stretch ~ * .ml-btn`).

    Parameters: $link (required to render), $label for screen readers, $bannerId when the card came
    from a Banner Setup row, so its click is counted against that row.
--}}
@if (!empty($link))
    <a class="ml-stretch" href="{{ $link }}"
       aria-label="{{ $label ?: translate('banner') }}"
       @if (!empty($bannerId)) data-analytics="banner_clicked" data-analytics-type="banner" data-analytics-id="{{ $bannerId }}" @endif></a>
@endif
