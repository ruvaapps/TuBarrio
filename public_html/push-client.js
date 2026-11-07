// push-client.js - Gestor de suscripción a Notificaciones Push

// Reemplazar con tu VAPID Public Key real cuando la tengas
const VAPID_PUBLIC_KEY = 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgD8Rui18C9P1B_zY159m-v1yJ5Z188XJ-9_XJ5Z188XI=';

async function inicializarNotificacionesPush() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        console.warn('Este navegador no soporta Notificaciones Push.');
        return;
    }

    try {
        const registración = await navigator.serviceWorker.register('sw.js');
        console.log('Service Worker listo para Push.');

        // Verificar o solicitar permiso
        let permiso = Notification.permission;
        if (permiso === 'default') {
            permiso = await Notification.requestPermission();
        }

        if (permiso !== 'granted') {
            console.log('El usuario no concedió permisos de notificación.');
            return;
        }

        // Obtener la suscripción existente o crear una nueva
        let suscripcion = await registración.pushManager.getSubscription();
        if (!suscripcion) {
            const claveConvertida = urlBase64ToUint8Array(VAPID_PUBLIC_KEY);
            suscripcion = await registración.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: claveConvertida
            });
        }

        // Enviar la suscripción al Backend PHP
        await guardarSuscripcionEnServidor(suscripcion);

    } catch (error) {
        console.error('Error al configurar Notificaciones Push:', error);
    }
}

// Convertir clave Base64 a Uint8Array requerido por PushManager
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

// Enviar la suscripción vía API a PHP
async function guardarSuscripcionEnServidor(suscripcion) {
    const subJson = suscripcion.toJSON();
    const payload = {
        endpoint: subJson.endpoint,
        keys: {
            p256dh: subJson.keys.p256dh,
            auth: subJson.keys.auth
        }
    };

    try {
        await fetch('index.php?route=/push/suscripcion', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });
        console.log('Suscripción Push registrada en el servidor.');
    } catch (error) {
        console.error('Error enviando suscripción al servidor:', error);
    }
}