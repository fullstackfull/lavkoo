@if((!isset($productDetailsMeta) || !$productDetailsMeta) && !isset($blogData))
    @if(isset($robotsMetaContentData) && $robotsMetaContentData?->meta_title)
        <title>{{ $robotsMetaContentData?->meta_title }}</title>
        <meta name="title" content="{{ $robotsMetaContentData?->meta_title }}">
        <meta property="og:title" content="{{ $robotsMetaContentData?->meta_title }}">
        <meta name="twitter:title" content="{{ $robotsMetaContentData?->meta_title }}">
    @elseif(isset($robotsMetaContentData) && $robotsMetaContentData?->title)
        <title>{{ $robotsMetaContentData?->title }}</title>
        <meta name="title" content="{{ $robotsMetaContentData?->title }}">
        <meta property="og:title" content="{{ $robotsMetaContentData?->title }}">
        <meta name="twitter:title" content="{{ $robotsMetaContentData?->title }}">
    @elseif($web_config['default_meta_content'])
        <meta name="title" content="{{ $web_config['default_meta_content']['meta_title'] }} "/>
        <meta property="og:title" content="{{ $web_config['default_meta_content']['meta_title'] }} "/>
        <meta name="twitter:title" content="{{ $web_config['default_meta_content']['meta_title'] }}"/>
    @else
        <meta name="title" content="{{ $web_config['meta_title'] }} "/>
        <meta property="og:title" content="{{ $web_config['meta_title'] }} "/>
        <meta name="twitter:title" content="{{ $web_config['meta_title'] }}"/>
    @endif

    @if(isset($robotsMetaContentData) && $robotsMetaContentData?->meta_description)
        <meta name="description" content="{{ $robotsMetaContentData?->meta_description }}">
        <meta property="og:description" content="{{ $robotsMetaContentData?->meta_description }}">
        <meta name="twitter:description" content="{{ $robotsMetaContentData?->meta_description }}">
    @elseif(isset($robotsMetaContentData) && $robotsMetaContentData?->description)
        <meta name="description" content="{{ $robotsMetaContentData?->description }}">
        <meta property="og:description" content="{{ $robotsMetaContentData?->description }}">
        <meta name="twitter:description" content="{{ $robotsMetaContentData?->description }}">
    @elseif($web_config['default_meta_content'])
        <meta name="description" content="{{ substr(strip_tags(str_replace('&nbsp;', ' ', $web_config['default_meta_content']['meta_description'])),0,160) }}">
        <meta property="og:description" content="{{ substr(strip_tags(str_replace('&nbsp;', ' ', $web_config['default_meta_content']['meta_description'])),0,160) }}">
        <meta name="twitter:description" content="{{ substr(strip_tags(str_replace('&nbsp;', ' ', $web_config['default_meta_content']['meta_description'])),0,160) }}">
    @else
        <meta name="description" content="{{ $web_config['meta_description'] }}">
        <meta property="og:description" content="{{ $web_config['meta_description'] }}">
        <meta name="twitter:description" content="{{ $web_config['meta_description'] }}">
    @endif

    <meta property="og:type" content="website" />
    <meta property="og:url" content="{{ env('APP_URL') }}">
    <meta name="twitter:url" content="{{ env('APP_URL') }}">

    @if(isset($robotsMetaContentData) && isset($robotsMetaContentData?->meta_image_full_url) && $robotsMetaContentData?->meta_image_full_url['path'])
        <meta property="og:image" content="{{ $robotsMetaContentData?->meta_image_full_url['path'] }}">
        <meta name="twitter:image" content="{{ $robotsMetaContentData?->meta_image_full_url['path'] }}">
        <meta name="twitter:card" content="{{ $robotsMetaContentData?->meta_image_full_url['path'] }}">
    @elseif(isset($robotsMetaContentData) && isset($robotsMetaContentData?->image_full_url) && $robotsMetaContentData?->image_full_url['path'])
        <meta property="og:image" content="{{ $robotsMetaContentData?->image_full_url['path'] }}">
        <meta name="twitter:image" content="{{ $robotsMetaContentData?->image_full_url['path'] }}">
        <meta name="twitter:card" content="{{ $robotsMetaContentData?->image_full_url['path'] }}">
    @elseif($web_config['default_meta_content'])
        <meta property="og:image" content="{{ $web_config['default_meta_content']?->meta_image_full_url['path'] }}"/>
        <meta name="twitter:image" content="{{ $web_config['default_meta_content']?->meta_image_full_url['path'] }}"/>
        <meta name="twitter:card" content="{{ $web_config['default_meta_content']?->meta_image_full_url['path'] }}"/>
    @else
        <meta property="og:image" content="{{$web_config['web_logo']['path']}}"/>
        <meta name="twitter:image" content="{{$web_config['web_logo']['path']}}"/>
        <meta name="twitter:card" content="{{$web_config['web_logo']['path']}}"/>
    @endif

    @if(isset($robotsMetaContentData) && $robotsMetaContentData?->canonicals_url)
        <link rel="canonical" href="{{ $robotsMetaContentData?->canonicals_url }}">
    @endif

    @if(!isset($productDetailsMeta) || !$productDetailsMeta)

            <?php
            $robots = [];

            if(isset($robotsMetaContentData)) {
                if($robotsMetaContentData->index != 'noindex') {
                    $robots[] = 'index';
                } else {
                    $robots[] = 'noindex';
                }

                if($robotsMetaContentData->no_follow) {
                    $robots[] = 'nofollow';
                } else {
                    $robots[] = 'follow';
                }

                if($robotsMetaContentData->no_image_index) $robots[] = 'noimageindex';
                if($robotsMetaContentData->no_archive) $robots[] = 'noarchive';
                if($robotsMetaContentData->no_snippet) $robots[] = 'nosnippet';
                if($robotsMetaContentData->meta_max_snippet) {
                    $robots[] = 'max-snippet' . ($robotsMetaContentData->max_snippet_value ? ':' . $robotsMetaContentData->max_snippet_value : '');
                }
                if($robotsMetaContentData->max_video_preview) {
                    $robots[] = 'max-video-preview' . ($robotsMetaContentData->max_video_preview_value ? ':' . $robotsMetaContentData->max_video_preview_value : '');
                }
                if($robotsMetaContentData->max_image_preview) {
                    $robots[] = 'max-image-preview' . ($robotsMetaContentData->max_image_preview_value ? ':' . $robotsMetaContentData->max_image_preview_value : '');
                }
            }
            ?>

        <meta name="robots" content="{{ implode(', ', $robots) }}">
    @endif

@endif
