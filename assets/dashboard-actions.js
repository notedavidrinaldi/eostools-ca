function setText(id, value) {
    const node = document.getElementById(id);
    if (node) {
        node.textContent = value;
    }
}

function setHtml(id, value) {
    const node = document.getElementById(id);
    if (node) {
        node.innerHTML = value;
    }
}

function setOutput(value) {
    setText('outputBox', value);
}

function formatObject(value) {
    return typeof value === 'string' ? value : JSON.stringify(value, null, 2);
}

function asArray(value) {
    return Array.isArray(value) ? value : [];
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function renderModules(modules) {
    const moduleList = asArray(modules);
    document.getElementById('statusModules').innerHTML = moduleList.map((module) => `
        <article class="module-card">
            <div class="module-head">
                <div>
                    <div class="module-key">${module.key}</div>
                    <div class="module-label">${module.label}</div>
                </div>
                <div class="led" style="color:${module.led};background:${module.led}"></div>
            </div>
            <div class="module-desc">${module.description}</div>
            <div class="module-meta">${module.meta}</div>
        </article>
    `).join('');
}

function setActiveNav(targetId) {
    document.querySelectorAll('.nav-anchor').forEach((link) => {
        link.classList.toggle('active', link.dataset.target === targetId);
    });
}

function setupSectionNavigation() {
    const sections = Array.from(document.querySelectorAll('.section-anchor'));
    const observer = new IntersectionObserver((entries) => {
        const visible = entries
            .filter((entry) => entry.isIntersecting)
            .sort((a, b) => b.intersectionRatio - a.intersectionRatio);
        if (visible.length > 0) {
            setActiveNav(visible[0].target.id);
        }
    }, {
        rootMargin: '-10% 0px -55% 0px',
        threshold: [0.2, 0.4, 0.6]
    });

    sections.forEach((section) => observer.observe(section));

    document.querySelectorAll('.nav-anchor').forEach((link) => {
        link.addEventListener('click', () => {
            setActiveNav(link.dataset.target);
        });
    });

    const initial = window.location.hash ? window.location.hash.slice(1) : 'overview';
    setActiveNav(initial);
}

function getStateColor(status) {
    if (status === 'online' || status === 'ready') return '#27d3a2';
    if (status === 'warning' || status === 'standby') return '#f6c14b';
    return '#ff6b6b';
}

function renderNetworkTargets(targets) {
    const list = asArray(targets);
    const preview = isServerMode() ? list.slice(0, REQUEST_LIMITS.networkTargets) : list;
    const over = isServerMode() && list.length > REQUEST_LIMITS.networkTargets ? ` (menampilkan ${preview.length} dari ${list.length})` : '';
    if (preview.length === 0) {
        setHtml('networkGrid', `<article class="network-card"><div class="detail">Belum ada data network.</div></article>`);
        return;
    }
    setHtml('networkGrid', preview.map((target) => {
        const color = getStateColor(target.status);
        return `
            <article class="network-card">
                <div class="top">
                    <div class="name">${target.label}</div>
                    <div class="state" style="color:${color}">
                        <span class="state-dot" style="color:${color};background:${color}"></span>
                        <span>${String(target.status).toUpperCase()}</span>
                    </div>
                </div>
                <div class="endpoint-text">${target.endpoint}</div>
                <div class="detail">
                    Latency: ${target.latency || '-'}<br>
                    Detail: ${target.detail || '-'}
                </div>
            </article>
        `;
    }).join('') + (over ? `<article class="network-card"><div class="detail">${over}</div></article>` : ''));
}

async function runParallel(tasks, includeLogs = false) {
    const queue = Array.isArray(tasks) ? tasks : [];
    if (includeLogs && !isServerMode()) {
        queue.push(refreshLogs());
    }
    if (queue.length === 0) {
        return [];
    }
    return Promise.all(queue);
}

async function runRestartPool() {
    const pool = document.getElementById('poolName').value;
    const note = document.getElementById('restartNote').value;
    setOutput('> recycle pool ' + pool + '\n> board command armed...');
    try {
        const result = await apiPost(API_PATH.restart_pool, {pool, note});
        setOutput(formatObject(result));
        refreshAll();
    } catch (error) {
        setOutput('ERROR: ' + error.message);
    }
}

async function runRestartGroup() {
    const group = document.getElementById('groupName').value;
    const note = document.getElementById('restartNote').value;
    setOutput('> run stack group ' + group + '\n> synchronizing board bus...');
    try {
        const result = await apiPost(API_PATH.restart_group, {group, note});
        setOutput(formatObject(result));
        refreshAll();
    } catch (error) {
        setOutput('ERROR: ' + error.message);
    }
}

async function runRestartIis() {
    const reason = document.getElementById('restartNote').value || 'Restart dari control board';
    if (!confirm('Yakin ingin hard reset IIS?')) {
        return;
    }
    setOutput('> hard reset iis\n> high priority command...');
    try {
        const result = await apiPost(API_PATH.restart_iis, {reason});
        setOutput(formatObject(result));
        refreshAll();
    } catch (error) {
        setOutput('ERROR: ' + error.message);
    }
}

function quickRestart(pool) {
    document.getElementById('poolName').value = pool;
    runRestartPool();
}

function quickGroup(group) {
    document.getElementById('groupName').value = group;
    runRestartGroup();
}

async function sendTestTelegram() {
    setOutput('> ping telegram link\n> tx line active...');
    try {
        const result = await api(API_PATH.test_telegram, {requestKey: API_PATH.test_telegram});
        setOutput(result.message);
        refreshLogs();
    } catch (error) {
        setOutput('ERROR: ' + error.message);
    }
}

async function pollTelegramSilently() {
    if (!isServerMode()) {
        document.getElementById('telegramPollState').textContent = 'USER MODE';
        return;
    }
    try {
        const result = await api(API_PATH.telegram_poll, {requestKey: API_PATH.telegram_poll});
        document.getElementById('telegramPollState').textContent = result.count > 0 ? `RX ${result.count}` : 'LISTEN';
        document.getElementById('telegramPollTime').textContent = result.updated_at || new Date().toISOString();
        if (result.count > 0) {
            const output = `RX ${result.count} @ ${result.updated_at || new Date().toISOString()}`;
            setOutput(output);
        }
    } catch (error) {
        document.getElementById('telegramPollState').textContent = 'ERROR';
    }
}

async function checkDisk(notify) {
    document.getElementById('diskPanel').textContent = 'Scanning disk sensor...';
    try {
        const query = notify ? buildApi(API_PATH.monitor_disk, {notify: 1}) : API_PATH.disk;
        const result = await api(query);
        const disk = result.data;
        const text = [
            'Drive: ' + disk.drive,
            'Free: ' + disk.free_human + ' (' + disk.free_percent + '%)',
            'Used: ' + disk.used_human + ' (' + disk.used_percent + '%)',
            'State: ' + disk.status.toUpperCase(),
            'Threshold: ' + disk.threshold_percent + '%'
        ].join('\n');
        document.getElementById('diskPanel').textContent = text;
        document.getElementById('statDisk').textContent = disk.status.toUpperCase();
        document.getElementById('statDiskDetail').textContent = disk.free_human + ' free dari ' + disk.total_human;
        document.getElementById('diskHeadline').textContent = disk.free_human + ' / ' + disk.free_percent + '%';
        if (notify) {
            setOutput('Disk report diperiksa dan dikirim ke Telegram.');
            refreshLogs();
        }
    } catch (error) {
        document.getElementById('diskPanel').textContent = 'ERROR: ' + error.message;
    }
}

async function scanNetwork(writeLog) {
    try {
        document.getElementById('netBusDetail').textContent = 'Scanning target jaringan...';
        const result = await api(writeLog ? buildApi(API_PATH.network, {log: 1}) : API_PATH.network);
        const network = result.data;
        document.getElementById('networkState').textContent = String(network.overall).toUpperCase();
        document.getElementById('networkHeadline').textContent = String(network.overall).toUpperCase();
        document.getElementById('netBusTile').textContent = String(network.overall).toUpperCase();
        document.getElementById('networkScanTime').textContent = network.updated_at || '-';
        const targets = network.targets || [];
        document.getElementById('netBusDetail').textContent = `${targets.filter((t) => t.status === 'online').length}/${targets.length} target online`;
        renderNetworkTargets(targets);
        if (writeLog) {
            refreshLogs();
        }
    } catch (error) {
        document.getElementById('netBusDetail').textContent = 'ERROR: ' + error.message;
    }
}

async function findImages() {
    const gate = document.getElementById('gateName').value;
    const datetime = document.getElementById('photoTime').value;
    const resultBox = document.getElementById('imageResult');
    const gallery = document.getElementById('imageGallery');
    resultBox.textContent = 'Reading backup image bus...';
    gallery.innerHTML = '';
    try {
        const result = await apiPost(API_PATH.find_images, {gate, datetime});
        resultBox.textContent = result.message + ' Folder: ' + result.searched_folder;
        gallery.innerHTML = asArray(result.images).map((image) => `
            <article class="gallery-item">
                <a href="${image.url}" target="_blank" rel="noopener">
                    <img src="${image.url}" alt="${image.name}">
                </a>
                <div class="gallery-meta">
                    <strong>${image.name}</strong><br>
                    <span>${image.mtime}</span>
                </div>
            </article>
        `).join('');
        refreshLogs();
    } catch (error) {
        resultBox.textContent = 'ERROR: ' + error.message;
    }
}

function renderTicketRows(tickets) {
    const ticketList = asArray(tickets);
    const root = document.getElementById('ticketTableBody');
    document.getElementById('ticketOpenBadge').textContent = ticketList.filter((ticket) => ticket.status === 'open').length;
    document.getElementById('ticketCheckBadge').textContent = ticketList.filter((ticket) => ticket.status === 'on_check').length;
    root.innerHTML = ticketList.map((ticket) => {
        const actions = [];
        if (IS_ADMIN && ticket.status === 'open') {
            actions.push(`<button class="btn-soft" onclick="markTicketOnCheck('${escapeHtml(ticket.ticket_id)}')">ON CHECK</button>`);
        }
        if (IS_ADMIN && ticket.status !== 'done') {
            actions.push(`<button class="btn-main" onclick="markTicketDone('${escapeHtml(ticket.ticket_id)}')">DONE</button>`);
        }
        return `
            <tr>
                <td>${escapeHtml(ticket.ticket_id)}</td>
                <td>${escapeHtml(ticket.issue_time)}</td>
                <td>${escapeHtml(ticket.site)}</td>
                <td>${escapeHtml(ticket.issue)}</td>
                <td>${escapeHtml(String(ticket.status || '-').toUpperCase())}</td>
                <td>${escapeHtml(ticket.repair_minutes === null ? '-' : `${ticket.repair_minutes} menit`)}</td>
                <td>${escapeHtml(ticket.note || '-')}</td>
                <td>${actions.join(' ') || '-'}</td>
            </tr>
        `;
    }).join('') || '<tr><td colspan="8">Belum ada tiket.</td></tr>';
}

async function loadTickets(limit = REQUEST_LIMITS.tickets) {
    try {
        const endpoint = limit > 0 ? buildApi(API_PATH.tickets, {limit}) : API_PATH.tickets;
        const result = await api(endpoint);
        renderTicketRows(result.data);
    } catch (error) {
        setOutput('ERROR loadTickets: ' + error.message);
    }
}

async function createTicket() {
    const issue_time = document.getElementById('ticketIssueTime').value;
    const site = IS_ADMIN ? document.getElementById('ticketSite').value : LOCKED_SITE;
    const issue = document.getElementById('ticketIssue').value;
    setOutput('> create ticket\n> writing ticket log...');
    try {
        const result = await apiPost(API_PATH.ticket_create, {issue_time, site, issue});
        setOutput(result.message + ' ID: ' + result.ticket_id);
        document.getElementById('ticketIssue').value = '';
        await runParallel([loadTickets(), refreshSummary(), loadTicketReport()], true);
    } catch (error) {
        setOutput('ERROR createTicket: ' + error.message);
    }
}

async function markTicketOnCheck(ticketId) {
    setOutput('> ticket ' + ticketId + '\n> set status on check...');
    try {
        const result = await apiPost(API_PATH.ticket_on_check, {ticket_id: ticketId});
        setOutput(result.message);
        await runParallel([loadTickets(), refreshSummary(), loadTicketReport()], true);
    } catch (error) {
        setOutput('ERROR markTicketOnCheck: ' + error.message);
    }
}

async function markTicketDone(ticketId) {
    const note = prompt('Catatan penyelesaian tiket:', '');
    if (note === null) {
        return;
    }
    setOutput('> ticket ' + ticketId + '\n> set status done...');
    try {
        const result = await apiPost(API_PATH.ticket_done, {ticket_id: ticketId, note});
        setOutput(result.message);
        await runParallel([loadTickets(), refreshSummary(), loadTicketReport()], true);
    } catch (error) {
        setOutput('ERROR markTicketDone: ' + error.message);
    }
}

async function loadTicketReport() {
    try {
        const month = document.getElementById('reportMonth').value || new Date().toISOString().slice(0, 7);
        const result = await api(buildApi(API_PATH.ticket_report, {month}));
        const root = document.getElementById('ticketReportBody');
        root.innerHTML = asArray(result.data).map((item) => `
            <tr>
                <td>${escapeHtml(item.ticket_id)}</td>
                <td>${escapeHtml(item.site)}</td>
                <td>${escapeHtml(item.issue)}</td>
                <td>${escapeHtml(item.issue_time)}</td>
                <td>${escapeHtml(String(item.status || '-').toUpperCase())}</td>
                <td>${escapeHtml(item.repair_duration || '-')}</td>
                <td>${escapeHtml(item.note || '-')}</td>
            </tr>
        `).join('') || '<tr><td colspan="7">Belum ada data untuk bulan ini.</td></tr>';
    } catch (error) {
        setOutput('ERROR loadTicketReport: ' + error.message);
    }
}

async function loadUsers() {
    if (!IS_ADMIN) return;
    try {
        const result = await api(buildApi(API_PATH.users, {limit: REQUEST_LIMITS.users}));
        const root = document.getElementById('userTableBody');
        root.innerHTML = asArray(result.data).map((user) => `
            <tr onclick="fillUserForm('${escapeHtml(user.username)}','${escapeHtml(user.role)}','${escapeHtml(user.site)}','${user.active ? '1' : '0'}')" style="cursor:pointer;">
                <td>${escapeHtml(user.username)}</td>
                <td>${escapeHtml(String(user.role).toUpperCase())}</td>
                <td>${escapeHtml(user.site)}</td>
                <td>${user.active ? 'ACTIVE' : 'INACTIVE'}</td>
                <td>${escapeHtml(user.created_at || '-')}</td>
                <td>${escapeHtml(user.updated_at || '-')}</td>
            </tr>
        `).join('') || '<tr><td colspan="6">Belum ada user.</td></tr>';
    } catch (error) {
        setOutput('ERROR loadUsers: ' + error.message);
    }
}

function fillUserForm(username, role, site, active) {
    if (!IS_ADMIN) return;
    document.getElementById('userUsername').value = username;
    document.getElementById('userRole').value = role;
    document.getElementById('userSite').value = site;
    document.getElementById('userActive').value = active;
    document.getElementById('userPassword').value = '';
}

async function createUserAccount() {
    const username = document.getElementById('userUsername').value;
    const password = document.getElementById('userPassword').value;
    const role = document.getElementById('userRole').value;
    const site = document.getElementById('userSite').value;
    try {
        const result = await apiPost(API_PATH.user_create, {username, password, role, site});
        setOutput(result.message);
        await runParallel([loadUsers()], true);
    } catch (error) {
        setOutput('ERROR createUserAccount: ' + error.message);
    }
}

async function updateUserAccount() {
    const username = document.getElementById('userUsername').value;
    const password = document.getElementById('userPassword').value;
    const role = document.getElementById('userRole').value;
    const site = document.getElementById('userSite').value;
    const active = document.getElementById('userActive').value;
    try {
        const result = await apiPost(API_PATH.user_update, {username, password, role, site, active});
        setOutput(result.message);
        await runParallel([loadUsers()], true);
    } catch (error) {
        setOutput('ERROR updateUserAccount: ' + error.message);
    }
}

async function deleteUserAccount() {
    const username = document.getElementById('userUsername').value;
    if (!username || !confirm('Hapus user ' + username + '?')) {
        return;
    }
    try {
        const result = await apiPost(API_PATH.user_delete, {username});
        setOutput(result.message);
        await runParallel([loadUsers()], true);
    } catch (error) {
        setOutput('ERROR deleteUserAccount: ' + error.message);
    }
}

async function refreshLogs() {
    if (isServerMode()) {
        return;
    }
    try {
        const [activity, telegram, network] = await Promise.all([
            api(buildLogApi('app', REQUEST_LIMITS.logs), {requestKey: 'logs_app'}),
            api(buildLogApi('telegram', REQUEST_LIMITS.logs), {requestKey: 'logs_telegram'}),
            api(buildLogApi('network', REQUEST_LIMITS.logs), {requestKey: 'logs_network'})
        ]);
        setText('activityLog', asArray(activity.logs).join('\n'));
        setText('telegramLog', asArray(telegram.logs).join('\n'));
        setText('networkLog', asArray(network.logs).join('\n'));
    } catch (error) {
        setOutput('ERROR: ' + error.message);
    }
}

async function refreshSummary() {
    try {
        const scope = isServerMode() ? 'server' : 'user';
        const result = await api(buildApi(API_PATH.summary, {scope}));
        const boardState = result.data.board || {};
        const networkState = result.data.network || {};
        const diskState = result.data.disk || {};
        const modules = result.data.modules || [];
        const networkTargets = networkState.targets || [];

        setText('serverTime', result.data.server_time);
        setText('busState', boardState.bus_state || '-');
        setText('busStateBar', boardState.bus_state || '-');
        setText('controllerState', result.data.controller.armed ? 'ARMED' : 'DISARMED');
        setText('controllerLast', result.data.controller.last_command);
        setText('moduleCount', boardState.module_count || 0);
        setText('networkState', String(networkState.overall || 'standby').toUpperCase());
        setText('networkHeadline', String(networkState.overall || 'standby').toUpperCase());
        setText('networkScanTime', networkState.updated_at || '-');
        setText('runtimeLabel', result.data.runtime.label || '-');
        setText('runtimeIp', result.data.runtime.ip || '-');
        setText('runtimeHost', `${result.data.runtime.label || '-'} / ${result.data.runtime.ip || '-'}`);
        setText('authRole', String(result.data.auth.role || '-').toUpperCase());
        setText('authSite', result.data.auth.site || '-');
        setText('ticketOpenCount', result.data.tickets.open || 0);
        setText('ticketOnCheckCount', result.data.tickets.on_check || 0);
        setText('statDisk', String(diskState.status || '-').toUpperCase());
        setText('statDiskDetail', `${diskState.free_human || '-'} free dari ${diskState.total_human || '-'}`);
        setText('diskHeadline', `${diskState.free_human || '-'} / ${diskState.free_percent || '-'}%`);
        setText('netBusTile', String(networkState.overall || 'standby').toUpperCase());
        setText('netBusDetail', `${(networkTargets || []).filter((target) => target.status === 'online').length}/${(networkTargets || []).length} target online`);
        setText('diskPanel', networkState.updated_at
            ? `Drive ${diskState.drive || '-'} status ${String(diskState.status || '-').toUpperCase()} | Free ${diskState.free_human || '-'} (${diskState.free_percent || '-'}%)`
            : 'Memuat status disk...');

        renderNetworkTargets(networkTargets);
        renderModules(modules);
    } catch (error) {
        setOutput('ERROR refreshSummary: ' + error.message);
    }
}
