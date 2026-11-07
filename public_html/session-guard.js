// session-guard.js - utilidades de sesion compartidas por las pantallas
(function (global) {
    'use strict';

    function apiFetch(url, options) {
        return fetch(url, Object.assign({ credentials: 'same-origin' }, options || {}));
    }

    async function tryRefreshSession(apiBase) {
        const base = apiBase || 'index.php?route=';

        try {
            const response = await apiFetch(base + '/auth/refresh', { method: 'POST' });
            return response.ok;
        } catch (error) {
            return false;
        }
    }

    async function getCurrentSession(apiBase) {
        const base = apiBase || 'index.php?route=';

        try {
            let response = await apiFetch(base + '/auth/me');
            if (response.status === 401) {
                const refreshed = await tryRefreshSession(base);
                if (refreshed) {
                    response = await apiFetch(base + '/auth/me');
                }
            }
            let result = {};

            try {
                result = await response.json();
            } catch (parseError) {
                result = {};
            }

            if (!response.ok || !result.success || !result.usuario) {
                return {
                    ok: false,
                    error: result.error || 'Sesion invalida'
                };
            }

            return {
                ok: true,
                usuario: result.usuario
            };
        } catch (error) {
            return {
                ok: false,
                error: 'No se pudo validar la sesion'
            };
        }
    }

    async function requireSession(config) {
        const cfg = config || {};
        const apiBase = cfg.apiBase || 'index.php?route=';
        const redirectTo = cfg.redirectTo || 'login';
        const allowedRoles = Array.isArray(cfg.allowedRoles) ? cfg.allowedRoles : [];

        const session = await getCurrentSession(apiBase);
        if (!session.ok) {
            localStorage.clear();
            window.location.href = redirectTo;
            return null;
        }

        const usuario = session.usuario;
        localStorage.setItem('usuario', JSON.stringify(usuario));

        if (allowedRoles.length > 0 && !allowedRoles.includes(usuario.rol)) {
            localStorage.clear();
            window.location.href = redirectTo;
            return null;
        }

        return usuario;
    }

    global.SessionGuard = {
        apiFetch: apiFetch,
        tryRefreshSession: tryRefreshSession,
        getCurrentSession: getCurrentSession,
        requireSession: requireSession
    };
})(window);

