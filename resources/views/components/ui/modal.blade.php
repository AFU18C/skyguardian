@props(['name', 'title'])

<div x-cloak x-show="$store.modal?.active === '{{ $name }}'" x-transition.opacity class="modal-backdrop"
     @keydown.escape.window="$store.modal.active = null">
    <section class="modal" @click.outside="$store.modal.active = null" role="dialog" aria-modal="true">
        <header class="panel-header">
            <div class="panel-title">{{ $title }}</div>
            <button class="icon-button" type="button" @click="$store.modal.active = null" aria-label="Закрыть">
                <x-icon name="close" :size="17"/>
            </button>
        </header>
        <div class="panel-body">{{ $slot }}</div>
    </section>
</div>
