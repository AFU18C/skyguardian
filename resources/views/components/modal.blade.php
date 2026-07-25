@props(['id', 'title', 'size' => 'lg'])

<div id="{{ $id }}" class="sg-modal" data-modal hidden>
    <div class="sg-modal-backdrop" data-modal-close></div>
    <section class="sg-modal-panel sg-modal-{{ $size }}" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title">
        <header class="sg-modal-header">
            <div>
                <p class="sg-eyebrow">SkyGuardian</p>
                <h2 id="{{ $id }}-title">{{ $title }}</h2>
            </div>
            <button type="button" class="sg-modal-close" data-modal-close aria-label="Закрыть">×</button>
        </header>
        <div class="sg-modal-body">
            {{ $slot }}
        </div>
    </section>
</div>
