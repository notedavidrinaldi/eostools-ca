        const APP = window.EOS_DASHBOARD || {};
        const IS_ADMIN = !!APP.isAdmin;
        const LOCKED_SITE = String(APP.lockedSite || 'SERVER');
        const REQUEST_LIMITS = Object.assign({tickets: 20, users: 300, logs: 60, networkTargets: 40}, APP.requestLimits || {});
        const DASHBOARD_MODE_KEY = APP.modeKey || 'eos_tools_dashboard_mode';
        const USER_IDLE_TIMEOUT_MS = Number(APP.autoLogoutMs || (15 * 60 * 1000));
        const DASHBOARD_REFRESH = Object.assign({
            user: {
                summary: 3000,
                logs: 10000,
                disk: 30000,
                network: 30000,
            },
            server: {
                summary: 5000,
                logs: 0,
                disk: 0,
                network: 0,
            },
        }, APP.refresh || {});
        const DASHBOARD_TEXT = Object.assign({
            modeServer: 'Mode SERVER aktif. Fokus statistik, auto ping Telegram tetap jalan untuk warning.',
            modeUser: 'Mode USER aktif. UI operasional full: log, ticket, scan dan user management aktif.',
        }, APP.text || {});
        const API_PATH = Object.assign({
            summary: '?api=summary',
            logs: '?api=logs',
            tickets: '?api=tickets',
            users: '?api=users',
            monitor_disk: '?api=monitor_disk',
            network: '?api=network',
            disk: '?api=disk',
            ticket_create: '?api=ticket_create',
            ticket_on_check: '?api=ticket_on_check',
            ticket_done: '?api=ticket_done',
            ticket_report: '?api=ticket_report',
            user_create: '?api=user_create',
            user_update: '?api=user_update',
            user_delete: '?api=user_delete',
            restart_pool: '?api=restart_pool',
            restart_group: '?api=restart_group',
            restart_iis: '?api=restart_iis',
            test_telegram: '?api=test_telegram',
            telegram_poll: '?api=telegram_poll',
            find_images: '?api=find_images',
        }, APP.apiPath || {});
        const REQUEST_STATE = new Map();
        const API_HEADERS = { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' };

        async function api(path, options = {}) {
            const requestKey = options.requestKey || path;
            const controller = new AbortController();
            if (REQUEST_STATE.has(requestKey)) {
                REQUEST_STATE.get(requestKey).abort();
            }
            REQUEST_STATE.set(requestKey, controller);
            try {
                const response = await fetch(path, {...options, signal: controller.signal});
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(data.message || 'Request gagal');
                }
                return data;
            } finally {
                if (REQUEST_STATE.get(requestKey) === controller) {
                    REQUEST_STATE.delete(requestKey);
                }
            }
        }

        function buildApi(path, params = {}) {
            const suffix = new URLSearchParams(params).toString();
            return suffix ? `${path}&${suffix}` : path;
        }

        function buildLogApi(type, limit = REQUEST_LIMITS.logs) {
            return buildApi(API_PATH.logs, {type, limit});
        }

        function apiPost(path, formData, options = {}) {
            return api(path, {
                method: 'POST',
                headers: {
                    ...API_HEADERS,
                    ...(options.headers || {})
                },
                body: new URLSearchParams(formData),
                requestKey: options.requestKey || path,
                ...options,
            });
        }

