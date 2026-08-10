@php
    $type = $block['type'] ?? 'text';
    $data = is_array($block['data'] ?? null) ? $block['data'] : [];
    $levelValue = (string) ($data['level'] ?? '2');
    $level = in_array($levelValue, ['2', '3', '4'], true) ? $levelValue : '2';
    $alignmentValue = (string) ($data['align'] ?? 'left');
    $alignment = in_array($alignmentValue, ['left', 'center', 'right'], true) ? $alignmentValue : 'left';
    $dividerValue = (string) ($data['style'] ?? 'solid');
    $dividerStyle = in_array($dividerValue, ['solid', 'dashed', 'ornament'], true) ? $dividerValue : 'solid';
    $safePublicUrl = static function (mixed $value, array $schemes = ['http', 'https']): string {
        $url = trim((string) $value);
        if ($url === '' || str_starts_with($url, '//')) {
            return '';
        }
        if (str_starts_with($url, '/')) {
            return $url;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        return in_array(mb_strtolower((string) parse_url($url, PHP_URL_SCHEME)), $schemes, true) ? $url : '';
    };
    $blockSrc = $safePublicUrl($data['src'] ?? '');
    $blockUrl = $safePublicUrl($data['url'] ?? '');
    $linkUrl = $safePublicUrl($data['link_url'] ?? '');
@endphp

@if($type === 'heading')
    <section class="site-block site-block-heading">
        <h{{ $level }}>{{ $data['text'] ?? '' }}</h{{ $level }}>
    </section>

@elseif($type === 'text')
    <section class="site-block site-block-text align-{{ $alignment }}">
        <p>{!! nl2br(e($data['content'] ?? '')) !!}</p>
    </section>

@elseif($type === 'image' && $blockSrc !== '')
    <figure class="site-block site-block-image">
        <img loading="lazy" decoding="async" src="{{ $blockSrc }}" alt="{{ $data['alt'] ?? '' }}">
        @if(!empty($data['caption']))<figcaption>{{ $data['caption'] }}</figcaption>@endif
    </figure>

@elseif($type === 'gallery')
    @php
        $images = collect(preg_split('/\R/u', (string) ($data['images'] ?? '')))
            ->map(fn ($value) => $safePublicUrl($value))
            ->filter()
            ->unique()
            ->values();
        $columns = max(2, min(4, (int) ($data['columns'] ?? 3)));
    @endphp
    @if($images->isNotEmpty())
        <section class="site-block site-gallery site-gallery-columns-{{ $columns }}">
            @foreach($images as $image)
                <img loading="lazy" decoding="async" src="{{ $image }}" alt="">
            @endforeach
        </section>
    @endif

@elseif($type === 'video' && $blockUrl !== '')
    @php
        $videoUrl = $blockUrl;
        $embedUrl = null;
        if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/)([A-Za-z0-9_-]{6,})~', $videoUrl, $match)) {
            $embedUrl = 'https://www.youtube-nocookie.com/embed/'.$match[1];
        } elseif (preg_match('~vimeo\.com/(\d+)~', $videoUrl, $match)) {
            $embedUrl = 'https://player.vimeo.com/video/'.$match[1];
        }
    @endphp
    <figure class="site-block site-block-video">
        @if($embedUrl)
            <div class="site-video-frame"><iframe src="{{ $embedUrl }}" title="Видео" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe></div>
        @elseif(preg_match('/\.(mp4|webm)(\?.*)?$/i', $videoUrl))
            <video controls preload="metadata" src="{{ $videoUrl }}"></video>
        @else
            <a class="site-button site-button-secondary" href="{{ $videoUrl }}" target="_blank" rel="noopener noreferrer">Открыть видео</a>
        @endif
        @if(!empty($data['caption']))<figcaption>{{ $data['caption'] }}</figcaption>@endif
    </figure>

@elseif($type === 'button' && !empty($data['label']) && $blockUrl !== '')
    <section class="site-block site-block-button">
        <a class="site-button site-button-{{ ($data['style'] ?? 'primary') === 'secondary' ? 'secondary' : 'primary' }}" href="{{ $blockUrl }}" @if($data['new_tab'] ?? false) target="_blank" rel="noopener noreferrer" @endif>
            {{ $data['label'] }}
        </a>
    </section>

@elseif($type === 'list')
    @php $items = collect(preg_split('/\R/u', (string) ($data['items'] ?? '')))->map(fn ($value) => trim($value))->filter(); @endphp
    @if($items->isNotEmpty())
        <section class="site-block site-block-list">
            @if($data['ordered'] ?? false)<ol>@else<ul>@endif
                @foreach($items as $item)<li>{{ $item }}</li>@endforeach
            @if($data['ordered'] ?? false)</ol>@else</ul>@endif
        </section>
    @endif

@elseif($type === 'card')
    <section class="site-block site-info-card">
        @if(!empty($data['title']))<h3>{{ $data['title'] }}</h3>@endif
        @if(!empty($data['text']))<p>{!! nl2br(e($data['text'])) !!}</p>@endif
        @if(!empty($data['link_label']) && $linkUrl !== '')<a href="{{ $linkUrl }}">{{ $data['link_label'] }} →</a>@endif
    </section>

@elseif($type === 'divider')
    <div class="site-block site-divider is-{{ $dividerStyle }}" aria-hidden="true"></div>

@elseif($type === 'columns')
    <section class="site-block site-columns">
        <div>{!! $data['left_html'] ?? '' !!}</div>
        <div>{!! $data['right_html'] ?? '' !!}</div>
    </section>

@elseif($type === 'contacts')
    <section class="site-block site-contact-card">
        @if(!empty($data['address']))<div><strong>Адрес</strong><span>{{ $data['address'] }}</span></div>@endif
        @if(!empty($data['phone']))<div><strong>Телефон</strong><a href="tel:{{ preg_replace('/[^+0-9]/', '', $data['phone']) }}">{{ $data['phone'] }}</a></div>@endif
        @if(!empty($data['email']))<div><strong>Email</strong><a href="mailto:{{ $data['email'] }}">{{ $data['email'] }}</a></div>@endif
    </section>

@elseif($type === 'telegram' && $blockUrl !== '')
    <section class="site-block site-telegram-card">
        <span class="site-telegram-icon" aria-hidden="true">➤</span>
        <div>
            <strong>{{ $data['label'] ?? 'Telegram' }}</strong>
            @if(!empty($data['text']))<p>{{ $data['text'] }}</p>@endif
        </div>
        <a href="{{ $blockUrl }}" target="_blank" rel="noopener noreferrer">Открыть</a>
    </section>

@elseif($type === 'alert_map')
    @php
        $mapTitle = trim((string) ($data['title'] ?? 'Карта воздушных тревог Украины'));
        $mapSizeValue = (string) ($data['size'] ?? 'standard');
        $mapSize = in_array($mapSizeValue, ['compact', 'standard', 'large'], true) ? $mapSizeValue : 'standard';
        $mapModeValue = (string) ($data['mode'] ?? 'lite');
        $mapMode = in_array($mapModeValue, ['lite', 'full'], true) ? $mapModeValue : 'lite';
        $mapLayoutValue = (string) ($data['layout'] ?? 'contained');
        $mapLayout = in_array($mapLayoutValue, ['contained', 'full', 'site'], true) ? $mapLayoutValue : 'contained';
        $mapShowTitle = filter_var($data['show_title'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        $mapUrl = $mapMode === 'full' ? 'https://alerts.in.ua/' : 'https://alerts.in.ua/lite';
    @endphp
    <section @class([
        'site-block',
        'site-alert-map',
        'is-full-block' => $mapLayout === 'full',
        'is-full-site' => $mapLayout === 'site',
        'has-title' => $mapShowTitle && $mapTitle !== '',
    ])>
        @if($mapShowTitle && $mapTitle !== '')<h2 class="site-alert-map-title">{{ $mapTitle }}</h2>@endif
        <div class="site-alert-map-frame is-{{ $mapSize }}">
            <iframe
                src="{{ $mapUrl }}"
                title="{{ $mapTitle !== '' ? $mapTitle : 'Карта воздушных тревог Украины' }}"
                loading="lazy"
                referrerpolicy="strict-origin-when-cross-origin"
                sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox"
            ></iframe>
        </div>
        <p class="site-alert-map-source">
            Карта загружается с внешнего сервиса alerts.in.ua.
            <a href="{{ $mapUrl }}" target="_blank" rel="noopener noreferrer">Открыть карту отдельно</a>
        </p>
        <noscript>
            <p><a href="{{ $mapUrl }}" target="_blank" rel="noopener noreferrer">Открыть карту воздушных тревог</a></p>
        </noscript>
    </section>

@elseif($type === 'html')
    <section class="site-block site-block-html">{!! $data['html'] ?? '' !!}</section>
@endif
