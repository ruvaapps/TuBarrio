// sw.js - Service Worker para PWA y Notificaciones Push
const CACHE_NAME = 'barrio-conectado-v1';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(clients.claim());
});

self.addEventListener('fetch', (event) => {
    // Permite que las peticiones a la API y archivos fluyan normalmente
    event.respondWith(fetch(event.request).catch(() => caches.match(event.request)));
});

// --- ESCUCHA DE NOTIFICACIONES PUSH ---
self.addEventListener('push', function(event) {
    let data = { title: 'Nueva Notificación', body: 'Tenés una nueva actualización en TuBarrio.', url: '/' };
    
    if (event.data) {
        try {
            data = event.data.json();
        } catch (e) {
            data.body = event.data.text();
        }
    }

    const options = {
        body: data.body,
        icon: 'assets/icon.png', // Opcional
        badge: 'assets/badge.png', // Opcional
        data: { url: data.url || '/' }
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    event.waitUntil(
        clients.openWindow(event.notification.data.url)
    );
});