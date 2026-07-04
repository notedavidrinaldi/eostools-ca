<?php

$eosConfig = require __DIR__ . '/config.php';
$localOverride = __DIR__ . '/config.local.php';
if (is_file($localOverride)) {
    $override = require $localOverride;
    if (is_array($override)) {
        $eosConfig = array_replace_recursive($eosConfig, $override);
    }
}

date_default_timezone_set($eosConfig['timezone']);
session_name('EOSTOOLSSESSID');
session_start();

eos_ensure_runtime();

function eos_config(?string $key = null, $default = null)
{
    global $eosConfig;

    if ($key === null) {
        return $eosConfig;
    }

    $value = $eosConfig;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function eos_ensure_runtime(): void
{
    $directories = [
        eos_config('paths.storage'),
        eos_config('paths.logs'),
        dirname(eos_config('disk.state_file')),
        dirname(eos_config('controller.state_file')),
        dirname(eos_config('telegram.poll_state_file')),
        eos_config('images.cache_dir'),
    ];

    foreach ($directories as $directory) {
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }
    }

    foreach ([eos_config('paths.app_log'), eos_config('paths.telegram_log')] as $file) {
        if (!is_file($file)) {
            @file_put_contents($file, '');
        }
    }
}

function eos_current_user(): ?string
{
    return $_SESSION[eos_config('session_key')] ?? null;
}

function eos_require_login(): void
{
    if (!eos_current_user()) {
        eos_json(['ok' => false, 'message' => 'Akses ditolak.'], 403);
    }
}

function eos_login(string $username, string $password): bool
{
    $users = eos_config('users', []);
    if (!array_key_exists($username, $users)) {
        return false;
    }

    $expected = (string) $users[$username];
    $valid = password_get_info($expected)['algo'] !== null
        ? password_verify($password, $expected)
        : hash_equals($expected, $password);

    if ($valid) {
        $_SESSION[eos_config('session_key')] = $username;
    }

    return $valid;
}

function eos_logout(): void
{
    $_SESSION = [];
    if (session_id() !== '') {
        session_destroy();
    }
}

function eos_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function eos_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function eos_log(string $message, string $type = 'app', ?string $actor = null): void
{
    $file = $type === 'telegram' ? eos_config('paths.telegram_log') : eos_config('paths.app_log');
    $user = $actor ?: (eos_current_user() ?: 'system');
    $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $user, trim($message));
    @file_put_contents($file, $line, FILE_APPEND);
}

function eos_tail(string $file, int $limit = 80): array
{
    if (!is_file($file)) {
        return [];
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_reverse(array_slice($lines, -1 * $limit));
}

function eos_http_post_json(string $url, array $payload, int $timeout = 12): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => $timeout,
    ]);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok' => $raw !== false && $status >= 200 && $status < 300,
        'status' => $status,
        'raw' => $raw ?: '',
        'error' => $error ?: '',
        'json' => json_decode((string) $raw, true) ?: [],
    ];
}

function eos_send_telegram(string $message, ?array $chatIds = null): array
{
    $chatIds = $chatIds ?: eos_config('telegram.chat_ids', []);
    $token = (string) eos_config('telegram.bot_token', '');

    if ($token === '' || !$chatIds) {
        eos_log('Telegram dilewati karena token atau chat id belum dikonfigurasi.', 'telegram');
        return ['ok' => false, 'results' => []];
    }

    $results = [];
    foreach ($chatIds as $chatId) {
        $response = eos_http_post_json(
            'https://api.telegram.org/bot' . $token . '/sendMessage',
            [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ]
        );

        $results[] = ['chat_id' => $chatId, 'response' => $response];
        if (($response['json']['ok'] ?? false) === true) {
            eos_log('Telegram terkirim ke ' . $chatId . ': ' . strip_tags($message), 'telegram');
        } else {
            $detail = $response['error'] ?: ($response['json']['description'] ?? $response['raw']);
            eos_log('Telegram gagal ke ' . $chatId . ': ' . $detail, 'telegram');
        }
    }

    return ['ok' => true, 'results' => $results];
}

function eos_run_powershell(string $script): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'eos_tools_');
    $file = $tmp . '.ps1';
    @rename($tmp, $file);
    file_put_contents($file, $script);

    $command = 'powershell -ExecutionPolicy Bypass -NoProfile -File ' . escapeshellarg($file) . ' 2>&1';
    $output = [];
    $code = 0;
    exec($command, $output, $code);
    @unlink($file);

    return [
        'code' => $code,
        'output' => trim(preg_replace('/[^\P{C}\n\r\t]+/u', '', implode("\n", $output)) ?: ''),
    ];
}

function eos_restart_app_pool(string $poolName, string $note = '', ?string $actor = null): array
{
    $allowedPools = array_map('strtoupper', eos_config('iis.app_pools', []));
    $normalized = strtoupper(trim($poolName));
    $actor = $actor ?: (eos_current_user() ?: 'system');

    if (!in_array($normalized, $allowedPools, true)) {
        return ['ok' => false, 'message' => 'App pool tidak terdaftar.'];
    }

    $script = <<<PS
$pool = '$normalized'
Import-Module WebAdministration
$result = [ordered]@{
    pool = $pool
    stop = @()
    start = @()
    finalState = 'Unknown'
}

function Wait-State {
    param(
        [string]\$PoolName,
        [string]\$Expected,
        [int]\$Retry = 10,
        [int]\$Delay = 2
    )

    for (\$i = 1; \$i -le \$Retry; \$i++) {
        try {
            \$state = (Get-WebAppPoolState -Name \$PoolName).Value
            if (\$state -eq \$Expected) {
                return \$true
            }
        } catch {
        }
        Start-Sleep -Seconds \$Delay
    }
    return \$false
}

for (\$i = 1; \$i -le 5; \$i++) {
    try {
        Stop-WebAppPool -Name \$pool -ErrorAction Stop
        if (Wait-State -PoolName \$pool -Expected 'Stopped') {
            \$result.stop += "Stop berhasil pada percobaan \$i"
            break
        }
        \$result.stop += "Stop dipanggil pada percobaan \$i, tetapi status belum Stopped"
    } catch {
        \$result.stop += "Stop gagal pada percobaan \$i: \$($_.Exception.Message)"
    }
    Start-Sleep -Seconds 2
}

for (\$i = 1; \$i -le 5; \$i++) {
    try {
        Start-WebAppPool -Name \$pool -ErrorAction Stop
        if (Wait-State -PoolName \$pool -Expected 'Started') {
            \$result.start += "Start berhasil pada percobaan \$i"
            break
        }
        \$result.start += "Start dipanggil pada percobaan \$i, tetapi status belum Started"
    } catch {
        \$result.start += "Start gagal pada percobaan \$i: \$($_.Exception.Message)"
    }
    Start-Sleep -Seconds 3
}

try {
    \$result.finalState = (Get-WebAppPoolState -Name \$pool).Value
} catch {
    \$result.finalState = "Error: \$($_.Exception.Message)"
}

\$result | ConvertTo-Json -Depth 5
PS;

    $startedAt = microtime(true);
    $run = eos_run_powershell($script);
    $duration = round(microtime(true) - $startedAt, 2);
    $parsed = json_decode($run['output'], true);

    if (!is_array($parsed)) {
        $parsed = [
            'pool' => $normalized,
            'stop' => [],
            'start' => [],
            'finalState' => 'Unknown',
            'rawOutput' => $run['output'],
        ];
    }

    $ok = strtoupper((string) ($parsed['finalState'] ?? '')) === 'STARTED';
    $summary = "Restart App Pool {$normalized} oleh {$actor}. Final state: " . ($parsed['finalState'] ?? 'Unknown');
    if ($note !== '') {
        $summary .= ". Catatan: {$note}";
    }

    eos_log($summary);
    eos_log($run['output'] !== '' ? $run['output'] : 'Tidak ada output PowerShell.');
    eos_send_telegram(
        "♻️ <b>Restart App Pool</b>\nPool: <b>{$normalized}</b>\nUser: <b>{$actor}</b>\nCatatan: " .
        eos_format_plain($note ?: '-') .
        "\nStatus akhir: <b>" . eos_format_plain((string) ($parsed['finalState'] ?? 'Unknown')) . "</b>\nDurasi: {$duration}s"
    );

    return [
        'ok' => $ok,
        'message' => $ok ? 'Restart app pool selesai.' : 'Restart app pool selesai tetapi status akhir belum Started.',
        'duration' => $duration,
        'details' => $parsed,
        'command' => $run,
    ];
}

function eos_restart_group(string $groupKey, string $note = '', ?string $actor = null): array
{
    $groups = eos_config('iis.restart_groups', []);
    $normalized = strtoupper(trim($groupKey));

    if (!isset($groups[$normalized])) {
        return ['ok' => false, 'message' => 'Group restart tidak ditemukan.'];
    }

    $results = [];
    $allOk = true;
    foreach ($groups[$normalized] as $pool) {
        $result = eos_restart_app_pool($pool, $note, $actor);
        $results[] = $result;
        if (!$result['ok']) {
            $allOk = false;
        }
    }

    eos_log("Restart group {$normalized} selesai dengan status " . ($allOk ? 'OK' : 'PERLU CEK'));

    return [
        'ok' => $allOk,
        'message' => $allOk ? 'Restart group selesai.' : 'Restart group selesai, tetapi ada pool yang perlu dicek.',
        'group' => $normalized,
        'results' => $results,
    ];
}

function eos_restart_iis(string $reason = '', ?string $actor = null): array
{
    $actor = $actor ?: (eos_current_user() ?: 'system');
    $startedAt = microtime(true);
    $output = [];
    $code = 0;
    exec('iisreset /restart /noforce 2>&1', $output, $code);
    $duration = round(microtime(true) - $startedAt, 2);
    $text = trim(implode("\n", $output));
    $ok = $code === 0 && stripos($text, 'successfully') !== false;

    eos_log("Restart IIS oleh {$actor}. Alasan: " . ($reason ?: '-'));
    eos_log($text !== '' ? $text : 'Tidak ada output dari iisreset.');
    eos_send_telegram(
        "🚨 <b>Restart IIS</b>\nUser: <b>{$actor}</b>\nAlasan: " . eos_format_plain($reason ?: '-') .
        "\nDurasi: {$duration}s\nStatus: <b>" . ($ok ? 'BERHASIL' : 'PERLU CEK') . '</b>'
    );

    return [
        'ok' => $ok,
        'message' => $ok ? 'Restart IIS selesai.' : 'Restart IIS sudah dijalankan, tetapi output perlu dicek.',
        'duration' => $duration,
        'output' => $text,
        'code' => $code,
    ];
}

function eos_disk_space_report(): array
{
    $drive = eos_config('disk.drive', 'C:');
    $total = @disk_total_space($drive);
    $free = @disk_free_space($drive);

    if ($total === false || $free === false || $total <= 0) {
        return [
            'ok' => false,
            'message' => 'Drive tidak dapat dibaca.',
            'drive' => $drive,
        ];
    }

    $used = $total - $free;
    $freePercent = round(($free / $total) * 100, 2);
    $usedPercent = round(($used / $total) * 100, 2);

    return [
        'ok' => true,
        'drive' => $drive,
        'total' => $total,
        'free' => $free,
        'used' => $used,
        'free_percent' => $freePercent,
        'used_percent' => $usedPercent,
        'threshold_percent' => (float) eos_config('disk.threshold_percent', 5),
        'status' => $freePercent <= (float) eos_config('disk.threshold_percent', 5) ? 'warning' : 'healthy',
        'total_human' => eos_bytes($total),
        'free_human' => eos_bytes($free),
        'used_human' => eos_bytes($used),
    ];
}

function eos_monitor_disk(bool $forceNotify = false): array
{
    $report = eos_disk_space_report();
    if (!$report['ok']) {
        eos_log('Monitor disk gagal: ' . $report['message']);
        return $report;
    }

    $stateFile = eos_config('disk.state_file');
    $previous = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
    $shouldAlert = $forceNotify || $report['status'] === 'warning';

    if ($report['status'] === 'warning' && (($previous['status'] ?? '') !== 'warning' || $forceNotify)) {
        eos_send_telegram(
            "⚠️ <b>Disk Space Warning</b>\nDrive: <b>{$report['drive']}</b>\nFree: <b>{$report['free_human']}</b> ({$report['free_percent']}%)\nUsed: {$report['used_human']} ({$report['used_percent']}%)"
        );
        eos_log('Disk warning terkirim ke Telegram.');
    } elseif ($forceNotify) {
        eos_send_telegram(
            "ℹ️ <b>Disk Space Report</b>\nDrive: <b>{$report['drive']}</b>\nFree: <b>{$report['free_human']}</b> ({$report['free_percent']}%)\nUsed: {$report['used_human']} ({$report['used_percent']}%)\nStatus: " . strtoupper($report['status'])
        );
        eos_log('Disk report manual terkirim ke Telegram.');
    }

    file_put_contents($stateFile, json_encode([
        'status' => $report['status'],
        'free_percent' => $report['free_percent'],
        'checked_at' => date('c'),
        'notified' => $shouldAlert,
    ], JSON_PRETTY_PRINT));

    return $report;
}

function eos_find_backup_images(string $gate, string $dateTimeInput): array
{
    $gate = strtoupper(trim($gate));
    if (!in_array($gate, eos_config('images.gates', []), true)) {
        return ['ok' => false, 'message' => 'Gate tidak valid.'];
    }

    $date = DateTime::createFromFormat('d-m-Y H:i', trim($dateTimeInput));
    if (!$date) {
        return ['ok' => false, 'message' => 'Format tanggal harus DD-MM-YYYY HH:MM.'];
    }

    $formattedFolder = $date->format('Y m d/H');
    $roots = eos_config('images.roots', []);
    $matches = [];

    foreach ($roots as $rootPattern) {
        $directory = str_replace(['%gate%', '%date%'], [$gate, $formattedFolder], $rootPattern);
        if (!is_dir($directory)) {
            continue;
        }

        $files = glob($directory . '*') ?: [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $matches[] = $file;
        }
    }

    if (!$matches) {
        return [
            'ok' => false,
            'message' => 'Tidak ada gambar ditemukan untuk folder waktu tersebut.',
            'searched_folder' => $formattedFolder,
        ];
    }

    usort($matches, static function ($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });

    $requestId = date('Ymd_His') . '_' . substr(md5($gate . $dateTimeInput), 0, 8);
    $targetDirectory = rtrim(eos_config('images.cache_dir'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $requestId;
    if (!is_dir($targetDirectory)) {
        @mkdir($targetDirectory, 0777, true);
    }

    $results = [];
    $maxResults = (int) eos_config('images.max_results', 24);
    foreach (array_slice($matches, 0, $maxResults) as $file) {
        $filename = basename($file);
        $destination = $targetDirectory . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($destination)) {
            @copy($file, $destination);
        }

        $results[] = [
            'name' => $filename,
            'source' => $file,
            'mtime' => date('Y-m-d H:i:s', filemtime($file)),
            'url' => eos_config('images.cache_url_base') . '/' . rawurlencode($requestId) . '/' . rawurlencode($filename),
        ];
    }

    eos_log("Image search {$gate} {$dateTimeInput} menghasilkan " . count($results) . ' file.');

    return [
        'ok' => true,
        'message' => 'Gambar berhasil diambil.',
        'gate' => $gate,
        'searched_folder' => $formattedFolder,
        'images' => $results,
    ];
}

function eos_bytes(float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }

    return number_format($bytes, $index === 0 ? 0 : 2) . ' ' . $units[$index];
}

function eos_format_plain(string $value): string
{
    return str_replace(['<', '>'], ['&lt;', '&gt;'], trim($value));
}

function eos_dashboard_summary(): array
{
    $disk = eos_disk_space_report();
    $modules = eos_controller_modules($disk);
    $controller = eos_controller_state();
    return [
        'app_name' => eos_config('app_name'),
        'board_name' => 'EOS CONTROL BOARD',
        'board_mode' => 'MICROCONTROLLER',
        'server_time' => date('Y-m-d H:i:s'),
        'user' => eos_current_user(),
        'disk' => $disk,
        'pools' => eos_config('iis.app_pools', []),
        'groups' => array_keys(eos_config('iis.restart_groups', [])),
        'gates' => eos_config('images.gates', []),
        'modules' => $modules,
        'controller' => $controller,
        'board' => [
            'firmware' => 'EOS-FW 1.0',
            'uptime_hint' => 'Polling 1s / logs 10s / disk 30s',
            'bus_state' => eos_controller_bus_state($modules),
            'module_count' => count($modules),
        ],
        'activity_logs' => eos_tail(eos_config('paths.app_log'), 12),
        'telegram_logs' => eos_tail(eos_config('paths.telegram_log'), 12),
    ];
}

function eos_controller_modules(?array $disk = null): array
{
    $disk = $disk ?: eos_disk_space_report();
    $telegramReady = eos_config('telegram.bot_token', '') !== '' && count(eos_config('telegram.chat_ids', [])) > 0;
    $imageRoots = eos_config('images.roots', []);
    $statePath = eos_config('disk.state_file');
    $stateExists = is_file($statePath);

    return [
        [
            'key' => 'IIS',
            'label' => 'IIS BUS',
            'status' => 'ready',
            'led' => '#22c55e',
            'description' => 'Kontrol restart IIS dan recycle app pool.',
            'meta' => count(eos_config('iis.app_pools', [])) . ' pool / ' . count(eos_config('iis.restart_groups', [])) . ' group',
        ],
        [
            'key' => 'DISK',
            'label' => 'DISK SENSOR',
            'status' => $disk['ok'] ? ($disk['status'] === 'warning' ? 'warning' : 'ready') : 'fault',
            'led' => $disk['ok'] ? ($disk['status'] === 'warning' ? '#f97316' : '#22c55e') : '#ef4444',
            'description' => 'Monitor storage drive ' . ($disk['drive'] ?? 'C:') . '.',
            'meta' => $disk['ok'] ? ($disk['free_human'] . ' free / ' . $disk['free_percent'] . '%') : ($disk['message'] ?? 'sensor off'),
        ],
        [
            'key' => 'TG',
            'label' => 'TELEGRAM RX/TX',
            'status' => $telegramReady ? 'ready' : 'fault',
            'led' => $telegramReady ? '#22c55e' : '#ef4444',
            'description' => 'Alert, polling command, dan webhook notifikasi.',
            'meta' => $telegramReady ? count(eos_config('telegram.chat_ids', [])) . ' chat sink aktif' : 'token/chat id belum lengkap',
        ],
        [
            'key' => 'IMG',
            'label' => 'IMAGE FETCH',
            'status' => count($imageRoots) > 0 ? 'ready' : 'fault',
            'led' => count($imageRoots) > 0 ? '#38bdf8' : '#ef4444',
            'description' => 'Ambil foto dari share backup ke cache web.',
            'meta' => count($imageRoots) . ' root / ' . count(eos_config('images.gates', [])) . ' gate',
        ],
        [
            'key' => 'AUTO',
            'label' => 'AUTOMATION',
            'status' => $stateExists ? 'ready' : 'standby',
            'led' => $stateExists ? '#a3e635' : '#eab308',
            'description' => 'Scheduler disk monitor dan telegram poll.',
            'meta' => $stateExists ? 'state file aktif' : 'menunggu scheduler pertama',
        ],
    ];
}

function eos_controller_state(): array
{
    $stateFile = eos_config('controller.state_file');
    $default = [
        'armed' => false,
        'last_command' => 'idle',
        'last_target' => '-',
        'last_result' => 'idle',
        'updated_at' => date('c'),
    ];

    if (!is_file($stateFile)) {
        file_put_contents($stateFile, json_encode($default, JSON_PRETTY_PRINT));
        return $default;
    }

    $state = json_decode((string) file_get_contents($stateFile), true);
    return is_array($state) ? array_merge($default, $state) : $default;
}

function eos_controller_save_state(array $state): array
{
    $state['updated_at'] = date('c');
    file_put_contents(eos_config('controller.state_file'), json_encode($state, JSON_PRETTY_PRINT));
    return $state;
}

function eos_controller_command(string $cmd, array $payload = [], ?string $actor = null): array
{
    $actor = $actor ?: (eos_current_user() ?: 'controller');
    $cmd = strtolower(trim($cmd));
    $state = eos_controller_state();

    if ($cmd === 'status') {
        return ['ok' => true, 'message' => 'Controller status.', 'state' => $state];
    }

    if ($cmd === 'arm') {
        $state['armed'] = true;
        $state['last_command'] = 'arm';
        $state['last_target'] = '-';
        $state['last_result'] = 'armed';
        eos_log('Controller armed oleh ' . $actor);
        return ['ok' => true, 'message' => 'Controller armed.', 'state' => eos_controller_save_state($state)];
    }

    if ($cmd === 'reset' || $cmd === 'disarm') {
        $state['armed'] = false;
        $state['last_command'] = $cmd;
        $state['last_target'] = '-';
        $state['last_result'] = 'disarmed';
        eos_log('Controller disarm/reset oleh ' . $actor);
        return ['ok' => true, 'message' => 'Controller disarmed.', 'state' => eos_controller_save_state($state)];
    }

    if ($cmd !== 'fire') {
        return ['ok' => false, 'message' => 'Command controller tidak dikenal.', 'state' => $state];
    }

    if (!$state['armed']) {
        return ['ok' => false, 'message' => 'Controller belum ARMED.', 'state' => $state];
    }

    $action = (string) ($payload['action'] ?? '');
    $allowed = eos_config('controller.commands', []);
    if (!in_array($action, $allowed, true)) {
        return ['ok' => false, 'message' => 'Action tidak diizinkan.', 'state' => $state];
    }

    $result = ['ok' => false, 'message' => 'Action tidak dijalankan.'];
    if ($action === 'restart_pool') {
        $result = eos_restart_app_pool((string) ($payload['target'] ?? ''), (string) ($payload['note'] ?? 'Controller fire'), $actor);
        $state['last_target'] = (string) ($payload['target'] ?? '-');
    } elseif ($action === 'restart_group') {
        $result = eos_restart_group((string) ($payload['target'] ?? ''), (string) ($payload['note'] ?? 'Controller fire'), $actor);
        $state['last_target'] = (string) ($payload['target'] ?? '-');
    } elseif ($action === 'restart_iis') {
        $result = eos_restart_iis((string) ($payload['note'] ?? 'Controller fire'), $actor);
        $state['last_target'] = 'iis';
    } elseif ($action === 'disk_report') {
        $result = ['ok' => true, 'message' => 'Disk report dikirim.', 'data' => eos_monitor_disk(true)];
        $state['last_target'] = eos_config('disk.drive', 'C:');
    } elseif ($action === 'telegram_ping') {
        eos_send_telegram(
            "📡 <b>Controller Ping</b>\nActor: <b>" . eos_format_plain($actor) . "</b>\nTime: " . date('Y-m-d H:i:s')
        );
        $result = ['ok' => true, 'message' => 'Telegram ping dikirim.'];
        $state['last_target'] = 'telegram';
    } elseif ($action === 'image_fetch') {
        $result = eos_find_backup_images((string) ($payload['gate'] ?? ''), (string) ($payload['datetime'] ?? ''));
        $state['last_target'] = (string) ($payload['gate'] ?? '-');
    }

    $state['last_command'] = 'fire:' . $action;
    $state['last_result'] = $result['ok'] ? 'success' : 'error';
    if (eos_config('controller.auto_disarm_after_fire', true)) {
        $state['armed'] = false;
    }
    $saved = eos_controller_save_state($state);

    return [
        'ok' => $result['ok'],
        'message' => $result['message'] ?? 'Controller fire selesai.',
        'state' => $saved,
        'result' => $result,
    ];
}

function eos_controller_bus_state(array $modules): string
{
    $fault = false;
    $warning = false;
    foreach ($modules as $module) {
        if (($module['status'] ?? '') === 'fault') {
            $fault = true;
        }
        if (($module['status'] ?? '') === 'warning' || ($module['status'] ?? '') === 'standby') {
            $warning = true;
        }
    }

    if ($fault) {
        return 'FAULT';
    }
    if ($warning) {
        return 'ATTN';
    }

    return 'SYNC';
}

function eos_telegram_process_update(array $update): array
{
    $message = $update['message'] ?? $update['edited_message'] ?? null;
    if (!$message) {
        return ['handled' => false, 'reason' => 'No message'];
    }

    $chatId = (string) ($message['chat']['id'] ?? '');
    $text = trim((string) ($message['text'] ?? ''));
    if ($chatId === '' || $text === '') {
        return ['handled' => false, 'reason' => 'Empty chat or text'];
    }

    $allowed = array_map('strval', eos_config('telegram.chat_ids', []));
    if (!in_array($chatId, $allowed, true)) {
        eos_log('Telegram update ditolak dari chat id ' . $chatId, 'telegram', 'telegram');
        return ['handled' => false, 'reason' => 'Unauthorized chat'];
    }

    $lower = strtolower($text);
    eos_log('Perintah Telegram diterima: ' . $text, 'telegram', 'telegram');

    if ($lower === '/start' || $lower === '/help') {
        eos_send_telegram(
            "EOS Tools siap.\nPerintah:\n/disk\n/health\n/restart <POOL>\n/restart-group <GROUP>\n/iis",
            [$chatId]
        );
        return ['handled' => true, 'command' => 'help'];
    }

    if ($lower === '/disk' || strpos($lower, 'disk') !== false) {
        $disk = eos_monitor_disk(true);
        eos_send_telegram(
            "📦 <b>Disk Report</b>\nDrive: <b>{$disk['drive']}</b>\nFree: <b>{$disk['free_human']}</b> ({$disk['free_percent']}%)\nUsed: {$disk['used_human']} ({$disk['used_percent']}%)\nStatus: <b>" . strtoupper($disk['status']) . '</b>',
            [$chatId]
        );
        return ['handled' => true, 'command' => 'disk'];
    }

    if ($lower === '/health') {
        $summary = eos_dashboard_summary();
        $disk = $summary['disk'];
        eos_send_telegram(
            "🩺 <b>EOS Tools Health</b>\nTime: {$summary['server_time']}\nDisk C Free: {$disk['free_human']} ({$disk['free_percent']}%)\nJumlah Pool: " . count($summary['pools']),
            [$chatId]
        );
        return ['handled' => true, 'command' => 'health'];
    }

    if (preg_match('/^\/restart\s+([A-Za-z0-9]+)/', $text, $match)) {
        $result = eos_restart_app_pool($match[1], 'Dari Telegram', 'telegram');
        eos_send_telegram(
            ($result['ok'] ? '✅' : '⚠️') . ' Restart pool ' . strtoupper($match[1]) . ': ' . $result['message'],
            [$chatId]
        );
        return ['handled' => true, 'command' => 'restart_pool'];
    }

    if (preg_match('/^\/restart-group\s+([A-Za-z0-9_-]+)/', $text, $match)) {
        $result = eos_restart_group($match[1], 'Dari Telegram', 'telegram');
        eos_send_telegram(
            ($result['ok'] ? '✅' : '⚠️') . ' Restart group ' . strtoupper($match[1]) . ': ' . $result['message'],
            [$chatId]
        );
        return ['handled' => true, 'command' => 'restart_group'];
    }

    if ($lower === '/iis') {
        $result = eos_restart_iis('Perintah Telegram', 'telegram');
        eos_send_telegram(
            ($result['ok'] ? '✅' : '⚠️') . ' Restart IIS: ' . $result['message'],
            [$chatId]
        );
        return ['handled' => true, 'command' => 'restart_iis'];
    }

    eos_send_telegram('Perintah tidak dikenali. Kirim /help untuk daftar perintah.', [$chatId]);
    return ['handled' => true, 'command' => 'unknown'];
}
