@props([
    'title',
    'description',
    'icon' => 'channels',
    'action' => null,
])

<div {{ $attributes->class(['empty-state']) }}>
    <div>
        <div class="empty-icon"><x-icon :name="$icon" :size="24"/></div>
        <div class="empty-title">{{ $title }}</div>
        <p class="empty-copy">{{ $description }}</p>
        @if($action)
            <div class="empty-action">
                <x-ui.button variant="primary">
                    <x-icon name="plus" :size="15"/>
                    {{ $action }}
                </x-ui.button>
            </div>
        @endif
    </div>
</div>
