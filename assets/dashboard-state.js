let currentDashboardMode = 'user';
let telegramPollIntervalId = null;
let userLogoutTimerId = null;
let refreshTimers = {
    summary: null,
    logs: null,
    disk: null,
    network: null,
};

function clearDashboardTimers() {
    Object.entries(refreshTimers).forEach(([, timerId]) => {
        if (timerId) {
            clearInterval(timerId);
        }
    });
    refreshTimers = {summary: null, logs: null, disk: null, network: null};
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

    if (profile.summary > 0) {
        refreshTimers.summary = setInterval(refreshSummary, profile.summary);
    }
    if (profile.logs > 0) {
        refreshTimers.logs = setInterval(refreshLogs, profile.logs);
    }
    if (profile.disk > 0) {
        refreshTimers.disk = setInterval(() => checkDisk(false), profile.disk);
    }
    if (profile.network > 0) {
        refreshTimers.network = setInterval(() => scanNetwork(false), profile.network);
    }
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
    if (telegramPollIntervalId) {
        clearInterval(telegramPollIntervalId);
        telegramPollIntervalId = null;
    }
    if (isServerMode()) {
        pollTelegramSilently();
        telegramPollIntervalId = setInterval(pollTelegramSilently, 60000);
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
