import './bootstrap';
import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.store('modal', {
    active: null,
});

Alpine.start();
