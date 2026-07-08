let currentDashboardMode = 'user';
let userLogoutTimerId = null;
let refreshTimers = {
    summary: null,
    logs: null,
    disk: null,
    network: null,
};
let refreshCycleToken = 0;
let telegramPollTimeoutId = null;

function clearDashboardTimers() {
    refreshCycleToken += 1;
    Object.entries(refreshTimers).forEach(([, timerId]) => {
        if (timerId) {
            clearTimeout(timerId);
        }
    });
    refreshTimers = {summary: null, logs: null, disk: null, network: null};
}

function scheduleRecurringTask(taskName, taskRunner, intervalMs) {
    if (intervalMs <= 0) {
        refreshTimers[taskName] = null;
        return;
    }

    const cycleToken = refreshCycleToken;
    const run = async () => {
        try {
            await taskRunner();
        } finally {
            if (refreshCycleToken === cycleToken) {
                refreshTimers[taskName] = setTimeout(run, intervalMs);
            }
        }
    };

    refreshTimers[taskName] = setTimeout(run, intervalMs);
}

function applyDashboardModeClass() {
    document.body.classList.toggle('dashboard-mode-server', isServerMode());
}

function getRefreshProfile() {
    return isServerMode() ? DASHBOARD_REFRESH.server : DASHBOARD_REFRESH.user;
}

function configureAutoRefresh() {
    clearDashboardTimers();
    const profile = getRefreshProfile();

    scheduleRecurringTask('summary', refreshSummary, profile.summary);
    scheduleRecurringTask('logs', refreshLogs, profile.logs);
    scheduleRecurringTask('disk', () => checkDisk(false), profile.disk);
    scheduleRecurringTask('network', () => scanNetwork(false), profile.network);
}

function isServerMode() {
    return currentDashboardMode === 'server';
}

function loadDashboardMode() {
    const savedMode = window.localStorage.getItem(DASHBOARD_MODE_KEY);
    return savedMode === 'server' ? 'server' : 'user';
}

function setDashboardMode(mode) {
    currentDashboardMode = mode === 'server' ? 'server' : 'user';
    window.localStorage.setItem(DASHBOARD_MODE_KEY, currentDashboardMode);
    applyDashboardModeClass();
    updateDashboardModeUI();
    configureTelegramPolling();
    configureAutoLogout();
    configureAutoRefresh();
    setOutput(
        currentDashboardMode === 'server'
            ? DASHBOARD_TEXT.modeServer
            : DASHBOARD_TEXT.modeUser
    );
    refreshAll();
}

function updateDashboardModeUI() {
    const isServer = isServerMode();
    setText('dashboardModeLabel', isServer ? 'SERVER' : 'USER');
    setText('autoPollLabel', isServer ? 'ON' : 'OFF');
    setText('autoLogoutLabel', isServer ? 'OFF' : 'ON');
    document.getElementById('dashboardModeHint').textContent = isServer
        ? 'Mode server aktif: auto-poll Telegram 1 menit tetap hidup untuk warning, view difokuskan ke statistik.'
        : 'Mode user aktif: auto-poll Telegram dimatikan, kontrol dan data lengkap tetap aktif.';
    document.getElementById('modeUserBtn').classList.toggle('active', !isServer);
    document.getElementById('modeServerBtn').classList.toggle('active', isServer);
    if (!isServer) {
        document.getElementById('telegramPollState').textContent = 'USER MODE';
    }
    applyDashboardModeClass();
}

function configureTelegramPolling() {
    if (telegramPollTimeoutId) {
        clearTimeout(telegramPollTimeoutId);
        telegramPollTimeoutId = null;
    }
    if (isServerMode()) {
        const cycleToken = refreshCycleToken;
        const runTelegramPoll = async () => {
            try {
                await pollTelegramSilently();
            } finally {
                if (refreshCycleToken === cycleToken && isServerMode()) {
                    telegramPollTimeoutId = setTimeout(runTelegramPoll, 60000);
                }
            }
        };
        runTelegramPoll();
    } else {
        document.getElementById('telegramPollState').textContent = 'USER MODE';
    }
}

function performAutoLogout() {
    setOutput('Idle timeout tercapai. Dashboard logout otomatis karena mode USER aktif.');
    window.location.href = '?logout=1';
}

function resetUserLogoutTimer() {
    if (isServerMode()) {
        return;
    }
    if (userLogoutTimerId) {
        clearTimeout(userLogoutTimerId);
    }
    userLogoutTimerId = setTimeout(performAutoLogout, USER_IDLE_TIMEOUT_MS);
}

function configureAutoLogout() {
    if (userLogoutTimerId) {
        clearTimeout(userLogoutTimerId);
        userLogoutTimerId = null;
    }
    if (!isServerMode()) {
        resetUserLogoutTimer();
    }
}

function bindUserActivityListeners() {
    ['click', 'keydown', 'mousemove', 'scroll', 'touchstart'].forEach((eventName) => {
        window.addEventListener(eventName, resetUserLogoutTimer, {passive: true});
    });
}

function refreshAll() {
    const tasks = [refreshSummary()];
    if (!isServerMode()) {
        tasks.push(refreshLogs());
        tasks.push(loadTickets());
        tasks.push(loadTicketReport());
        tasks.push(loadUsers());
    }
    return Promise.all(tasks);
}

function initializeDashboard() {
    setupSectionNavigation();
    currentDashboardMode = loadDashboardMode();
    applyDashboardModeClass();
    updateDashboardModeUI();
    configureTelegramPolling();
    configureAutoLogout();
    configureAutoRefresh();
    bindUserActivityListeners();
    refreshAll();
}

initializeDashboard();
