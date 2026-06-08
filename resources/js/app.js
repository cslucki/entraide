import './bootstrap';

import Alpine from 'alpinejs';
window.Alpine = Alpine;

// Service Worker registration
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js');
    });
}

Alpine.start();
