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
eos_prepare_session_storage($eosConfig);
session_name('EOSTOOLSSESSID');
session_start();

$eosActingUser = null;
$eosActingRole = null;
$eosActingSite = null;

eos_ensure_runtime();

function eos_prepare_session_storage(array $config): void
{
    $storageRoot = $config['paths']['storage'] ?? (__DIR__ . '/storage');
    $sessionPath = $storageRoot . '/sessions';

    if (!is_dir($sessionPath)) {
        @mkdir($sessionPath, 0777, true);
    }

    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }
}

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
        dirname(eos_config('network.state_file')),
        dirname(eos_config('telegram.poll_state_file')),
        eos_config('images.cache_dir'),
    ];

    foreach ($directories as $directory) {
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }
    }

    foreach ([eos_config('paths.app_log'), eos_config('paths.telegram_log'), eos_config('paths.network_log')] as $file) {
        if (!is_file($file)) {
            @file_put_contents($file, '');
        }
    }

    foreach ([eos_config('paths.ticket_log'), eos_config('paths.ticket_index'), eos_config('paths.user_store')] as $file) {
        if ($file && !is_file($file)) {
            @file_put_contents($file, '');
        }
    }

    eos_ensure_user_store();
}

function eos_ensure_user_store(): void
{
    $file = (string) eos_config('paths.user_store', '');
    if ($file === '') {
        return;
    }

    $users = eos_read_user_store();
    if ($users !== []) {
        return;
    }

    $defaults = [];
    foreach ((array) eos_config('users', []) as $username => $value) {
        if (is_array($value)) {
            $password = (string) ($value['password'] ?? '');
            $role = (string) ($value['role'] ?? ($username === 'halotec' ? 'admin' : 'eos'));
            $site = (string) ($value['site'] ?? 'SERVER');
        } else {
            $password = (string) $value;
            $role = $username === 'halotec' ? 'admin' : 'eos';
            $site = 'SERVER';
        }

        $defaults[] = eos_normalize_user_record([
            'username' => $username,
            'password' => $password,
            'role' => $role,
            'site' => $site,
            'active' => true,
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ]);
    }

    if ($defaults === []) {
        $defaults[] = eos_normalize_user_record([
            'username' => 'halotec',
            'password' => 'halotec',
            'role' => 'admin',
            'site' => 'SERVER',
            'active' => true,
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ]);
    }

    eos_write_user_store($defaults);
}

function eos_read_user_store(): array
{
    $file = (string) eos_config('paths.user_store', '');
    if ($file === '' || !is_file($file)) {
        return [];
    }

    $raw = trim((string) @file_get_contents($file));
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function eos_write_user_store(array $users): void
{
    $file = (string) eos_config('paths.user_store', '');
    if ($file === '') {
        return;
    }

    @file_put_contents($file, json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function eos_normalize_user_record(array $user): array
{
    $password = (string) ($user['password'] ?? '');
    if ($password !== '' && password_get_info($password)['algo'] === null) {
        $password = password_hash($password, PASSWORD_DEFAULT);
    }

    return [
        'username' => trim((string) ($user['username'] ?? '')),
        'password' => $password,
        'role' => strtolower((string) ($user['role'] ?? 'eos')) === 'admin' ? 'admin' : 'eos',
        'site' => strtoupper(trim((string) ($user['site'] ?? 'SERVER'))),
        'active' => array_key_exists('active', $user) ? (bool) $user['active'] : true,
        'created_at' => (string) ($user['created_at'] ?? date('c')),
        'updated_at' => (string) ($user['updated_at'] ?? date('c')),
    ];
}

function eos_users(): array
{
    $users = [];
    foreach (eos_read_user_store() as $user) {
        if (!is_array($user)) {
            continue;
        }
        $normalized = eos_normalize_user_record($user);
        if ($normalized['username'] !== '') {
            $users[$normalized['username']] = $normalized;
        }
    }
    return $users;
}

function eos_save_users(array $users): void
{
    $normalized = [];
    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }
        $row = eos_normalize_user_record($user);
        if ($row['username'] !== '') {
            $normalized[] = $row;
        }
    }
    eos_write_user_store($normalized);
}

function eos_user_public_record(array $user): array
{
    return [
        'username' => (string) ($user['username'] ?? ''),
        'role' => (string) ($user['role'] ?? 'eos'),
        'site' => (string) ($user['site'] ?? 'SERVER'),
        'active' => (bool) ($user['active'] ?? true),
        'created_at' => (string) ($user['created_at'] ?? ''),
        'updated_at' => (string) ($user['updated_at'] ?? ''),
    ];
}

function eos_user_list_public(): array
{
    return array_values(array_map('eos_user_public_record', eos_users()));
}

function eos_create_user(string $username, string $password, string $role, string $site): array
{
    eos_require_admin();
    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['ok' => false, 'message' => 'Username dan password wajib diisi.'];
    }

    $users = eos_users();
    if (isset($users[$username])) {
        return ['ok' => false, 'message' => 'Username sudah ada.'];
    }

    $site = eos_resolve_requested_site($site);
    if (!in_array($site, eos_ticket_sites(), true)) {
        return ['ok' => false, 'message' => 'Site tidak valid.'];
    }

    $users[$username] = eos_normalize_user_record([
        'username' => $username,
        'password' => $password,
        'role' => $role,
        'site' => $site,
        'active' => true,
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ]);
    eos_save_users($users);
    eos_log_event('AUTH', 'user_created', 'INFO', ['username' => $username, 'role' => $role, 'site' => $site]);
    return ['ok' => true, 'message' => 'User berhasil dibuat.'];
}

function eos_update_user(string $username, string $role, string $site, string $password = '', ?bool $active = null): array
{
    eos_require_admin();
    $users = eos_users();
    if (!isset($users[$username])) {
        return ['ok' => false, 'message' => 'User tidak ditemukan.'];
    }

    $site = eos_resolve_requested_site($site);
    if (!in_array($site, eos_ticket_sites(), true)) {
        return ['ok' => false, 'message' => 'Site tidak valid.'];
    }

    $user = $users[$username];
    $user['role'] = strtolower($role) === 'admin' ? 'admin' : 'eos';
    $user['site'] = $site;
    if ($password !== '') {
        $user['password'] = $password;
    }
    if ($active !== null) {
        $user['active'] = $active;
    }
    $user['updated_at'] = date('c');

    $users[$username] = eos_normalize_user_record($user);
    eos_save_users($users);
    eos_log_event('AUTH', 'user_updated', 'INFO', ['username' => $username, 'role' => $user['role'], 'site' => $site]);
    return ['ok' => true, 'message' => 'User berhasil diperbarui.'];
}

function eos_delete_user(string $username): array
{
    eos_require_admin();
    $users = eos_users();
    if (!isset($users[$username])) {
        return ['ok' => false, 'message' => 'User tidak ditemukan.'];
    }
    if ($username === (eos_current_user() ?: '')) {
        return ['ok' => false, 'message' => 'User login aktif tidak bisa dihapus.'];
    }

    $adminCount = count(array_filter($users, static fn($user) => ($user['role'] ?? '') === 'admin'));
    if (($users[$username]['role'] ?? '') === 'admin' && $adminCount <= 1) {
        return ['ok' => false, 'message' => 'Admin terakhir tidak boleh dihapus.'];
    }

    unset($users[$username]);
    eos_save_users($users);
    eos_log_event('AUTH', 'user_deleted', 'INFO', ['username' => $username]);
    return ['ok' => true, 'message' => 'User berhasil dihapus.'];
}

function eos_ticket_sites(): array
{
    $sites = [];
    foreach ((array) eos_config('sites.options', []) as $site) {
        $site = strtoupper(trim((string) $site));
        if ($site !== '') {
            $sites[$site] = $site;
        }
    }

    foreach ((array) eos_config('devices.gates', []) as $gate) {
        $site = strtoupper(trim((string) ($gate['id'] ?? '')));
        if ($site !== '') {
            $sites[$site] = $site;
        }
    }

    if ($sites === []) {
        $sites['SERVER'] = 'SERVER';
    }

    ksort($sites);
    return array_values($sites);
}

function eos_site_label(string $site): string
{
    $normalized = strtoupper(trim($site));
    if ($normalized === 'SERVER') {
        return 'Common Gate Area';
    }

    return $normalized !== '' ? $normalized : trim($site);
}

function eos_current_user(): ?string
{
    global $eosActingUser;
    if (is_string($eosActingUser) && $eosActingUser !== '') {
        return $eosActingUser;
    }
    return $_SESSION[eos_config('session_key')] ?? null;
}

function eos_current_user_record(): ?array
{
    $username = eos_current_user();
    if ($username === null) {
        return null;
    }

    $users = eos_users();
    return $users[$username] ?? null;
}

function eos_current_user_role(): ?string
{
    global $eosActingRole;
    if (is_string($eosActingRole) && $eosActingRole !== '') {
        return $eosActingRole;
    }
    $user = eos_current_user_record();
    return $user['role'] ?? null;
}

function eos_current_user_site(): ?string
{
    global $eosActingSite;
    if (is_string($eosActingSite) && $eosActingSite !== '') {
        return $eosActingSite;
    }
    $user = eos_current_user_record();
    return $user['site'] ?? null;
}

function eos_begin_impersonation(string $username, string $role, string $site): void
{
    global $eosActingUser, $eosActingRole, $eosActingSite;
    $eosActingUser = $username;
    $eosActingRole = strtolower($role) === 'admin' ? 'admin' : 'eos';
    $eosActingSite = strtoupper(trim($site)) !== '' ? strtoupper(trim($site)) : 'SERVER';
}

function eos_end_impersonation(): void
{
    global $eosActingUser, $eosActingRole, $eosActingSite;
    $eosActingUser = null;
    $eosActingRole = null;
    $eosActingSite = null;
}

function eos_is_admin(): bool
{
    return eos_current_user_role() === 'admin';
}

function eos_require_login(): void
{
    if (!eos_current_user()) {
        eos_json(['ok' => false, 'message' => 'Akses ditolak.'], 403);
    }
}

function eos_require_admin(): void
{
    eos_require_login();
    if (!eos_is_admin()) {
        eos_json(['ok' => false, 'message' => 'Hanya admin yang boleh mengakses fitur ini.'], 403);
    }
}

function eos_user_can_access_site(string $site): bool
{
    $site = strtoupper(trim($site));
    if ($site === '') {
        return false;
    }
    if (eos_is_admin()) {
        return true;
    }
    return strtoupper((string) eos_current_user_site()) === $site;
}

function eos_resolve_requested_site(?string $site = null): string
{
    if (!eos_is_admin()) {
        return strtoupper((string) eos_current_user_site());
    }

    $site = strtoupper(trim((string) $site));
    if ($site === '') {
        return 'SERVER';
    }
    return $site;
}

function eos_login(string $username, string $password): bool
{
    $users = eos_users();
    if (!array_key_exists($username, $users)) {
        return false;
    }

    $user = $users[$username];
    if (!($user['active'] ?? true)) {
        return false;
    }

    $expected = (string) ($user['password'] ?? '');
    $valid = $expected !== '' && password_verify($password, $expected);

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
    $logMap = [
        'app' => eos_config('paths.app_log'),
        'telegram' => eos_config('paths.telegram_log'),
        'network' => eos_config('paths.network_log'),
    ];
    $file = $logMap[$type] ?? eos_config('paths.app_log');
    $user = $actor ?: (eos_current_user() ?: 'system');
    $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $user, trim($message));
    @file_put_contents($file, $line, FILE_APPEND);
}

function eos_log_event(string $module, string $event, string $level = 'INFO', array $context = [], ?string $actor = null, string $type = 'app'): void
{
    $module = strtoupper(trim($module));
    $level = strtoupper(trim($level));
    $actor = $actor ?: (eos_current_user() ?: 'system');

    $pairs = [];
    foreach ($context as $key => $value) {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $pairs[] = $key . '=' . str_replace(["\n", "\r"], ' ', (string) $value);
    }

    $message = sprintf('[%s] [%s] [%s] %s', $level, $module, $actor, $event);
    if ($pairs) {
        $message .= ' | ' . implode(' | ', $pairs);
    }

    eos_log($message, $type, $actor);
}

function eos_ticket_log_path(): string
{
    return (string) eos_config('paths.ticket_log');
}

function eos_ticket_index_path(): string
{
    return (string) eos_config('paths.ticket_index');
}

function eos_ticket_empty_index(): array
{
    return [
        'source_size' => 0,
        'source_mtime' => 0,
        'tickets' => [],
        'order' => [],
        'by_day' => [],
        'by_month' => [],
    ];
}

function eos_ticket_load_index(): array
{
    $file = eos_ticket_index_path();
    if ($file === '' || !is_file($file)) {
        return eos_ticket_empty_index();
    }

    $raw = trim((string) @file_get_contents($file));
    if ($raw === '') {
        return eos_ticket_empty_index();
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? array_merge(eos_ticket_empty_index(), $decoded) : eos_ticket_empty_index();
}

function eos_ticket_save_index(array $index): void
{
    $file = eos_ticket_index_path();
    if ($file === '') {
        return;
    }

    @file_put_contents($file, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function eos_ticket_sort_and_index(array $index): array
{
    $tickets = $index['tickets'] ?? [];
    uasort($tickets, static function ($a, $b) {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });

    $index['tickets'] = $tickets;
    $index['order'] = array_keys($tickets);
    $index['by_day'] = [];
    $index['by_month'] = [];

    foreach ($tickets as $ticketId => $ticket) {
        $createdAt = (string) ($ticket['created_at'] ?? '');
        $day = substr($createdAt, 0, 10);
        $month = substr($createdAt, 0, 7);
        if (strlen($day) === 10) {
            $index['by_day'][$day][] = $ticketId;
        }
        if (strlen($month) === 7) {
            $index['by_month'][$month][] = $ticketId;
        }
    }

    return $index;
}

function eos_ticket_apply_event_to_index(array $index, array $entry): array
{
    if (!is_array($entry['payload'] ?? null)) {
        return $index;
    }

    $payload = $entry['payload'];
    $ticketId = (string) ($payload['ticket_id'] ?? '');
    if ($ticketId === '') {
        return $index;
    }

    $tickets = $index['tickets'] ?? [];
    $event = (string) ($entry['event'] ?? '');

    if ($event === 'ticket_created') {
        $tickets[$ticketId] = [
            'ticket_id' => $ticketId,
            'created_at' => (string) ($payload['created_at'] ?? ($entry['ts'] ?? date('c'))),
            'issue_time' => (string) ($payload['issue_time'] ?? ''),
            'site' => strtoupper((string) ($payload['site'] ?? 'SERVER')),
            'issue' => (string) ($payload['issue'] ?? ''),
            'status' => 'open',
            'created_by' => (string) ($payload['created_by'] ?? ($entry['actor'] ?? 'system')),
            'checked_at' => null,
            'checked_by' => null,
            'done_at' => null,
            'done_by' => null,
            'note' => '',
        ];
    } elseif (isset($tickets[$ticketId])) {
        if ($event === 'ticket_on_check') {
            $tickets[$ticketId]['status'] = 'on_check';
            $tickets[$ticketId]['checked_at'] = (string) ($payload['checked_at'] ?? ($entry['ts'] ?? date('c')));
            $tickets[$ticketId]['checked_by'] = (string) ($payload['checked_by'] ?? ($entry['actor'] ?? 'system'));
        } elseif ($event === 'ticket_done') {
            $tickets[$ticketId]['status'] = 'done';
            $tickets[$ticketId]['done_at'] = (string) ($payload['done_at'] ?? ($entry['ts'] ?? date('c')));
            $tickets[$ticketId]['done_by'] = (string) ($payload['done_by'] ?? ($entry['actor'] ?? 'system'));
            $tickets[$ticketId]['note'] = (string) ($payload['note'] ?? '');
        }
    }

    foreach ($tickets as &$ticket) {
        $ticket['repair_minutes'] = eos_ticket_repair_minutes($ticket);
    }
    unset($ticket);

    $index['tickets'] = $tickets;
    return eos_ticket_sort_and_index($index);
}

function eos_ticket_rebuild_index(): array
{
    $file = eos_ticket_log_path();
    $index = eos_ticket_empty_index();
    if (!is_file($file)) {
        eos_ticket_save_index($index);
        return $index;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $entry = json_decode($line, true);
        if (!is_array($entry)) {
            continue;
        }
        $index = eos_ticket_apply_event_to_index($index, $entry);
    }

    $index['source_size'] = (int) @filesize($file);
    $index['source_mtime'] = (int) @filemtime($file);
    eos_ticket_save_index($index);
    return $index;
}

function eos_ticket_synced_index(): array
{
    $file = eos_ticket_log_path();
    if (!is_file($file)) {
        return eos_ticket_empty_index();
    }

    $index = eos_ticket_load_index();
    $size = (int) @filesize($file);
    $mtime = (int) @filemtime($file);

    if (($index['source_size'] ?? -1) !== $size || ($index['source_mtime'] ?? -1) !== $mtime) {
        return eos_ticket_rebuild_index();
    }

    return $index;
}

function eos_ticket_append_event(string $event, array $payload): void
{
    $row = [
        'ts' => date('c'),
        'event' => $event,
        'actor' => eos_current_user() ?: 'system',
        'payload' => $payload,
    ];
    $file = eos_ticket_log_path();
    @file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

    $index = eos_ticket_load_index();
    $index = eos_ticket_apply_event_to_index($index, $row);
    $index['source_size'] = (int) @filesize($file);
    $index['source_mtime'] = (int) @filemtime($file);
    eos_ticket_save_index($index);
}

function eos_generate_ticket_id(): string
{
    return 'TCK-' . date('Ymd-His') . '-' . strtoupper(substr(md5((string) microtime(true)), 0, 4));
}

function eos_ticket_records(): array
{
    $index = eos_ticket_synced_index();
    return array_values($index['tickets'] ?? []);
}

function eos_limit_rows(array $items, ?int $limit = null): array
{
    if ($limit === null || $limit <= 0) {
        return array_values($items);
    }

    return array_slice(array_values($items), 0, $limit);
}

function eos_ticket_repair_minutes(array $ticket): ?int
{
    $start = (string) ($ticket['checked_at'] ?: $ticket['created_at'] ?? '');
    $end = (string) ($ticket['done_at'] ?? '');
    if ($start === '' || $end === '') {
        return null;
    }

    try {
        $startAt = new DateTime($start);
        $endAt = new DateTime($end);
    } catch (Exception $e) {
        return null;
    }

    return max(0, (int) floor(($endAt->getTimestamp() - $startAt->getTimestamp()) / 60));
}

function eos_ticket_duration_label(?int $minutes): string
{
    if ($minutes === null) {
        return '-';
    }
    if ($minutes < 60) {
        return $minutes . ' menit';
    }
    $hours = floor($minutes / 60);
    $rest = $minutes % 60;
    return $hours . ' jam' . ($rest > 0 ? ' ' . $rest . ' menit' : '');
}

function eos_visible_tickets(?int $limit = null): array
{
    $tickets = eos_ticket_records();
    if (eos_is_admin()) {
        return eos_limit_rows($tickets, $limit);
    }

    $site = strtoupper((string) eos_current_user_site());
    if ($site === '') {
        return [];
    }

    $filtered = [];
    foreach ($tickets as $ticket) {
        if (strtoupper((string) ($ticket['site'] ?? '')) !== $site) {
            continue;
        }
        $filtered[] = $ticket;
        if ($limit !== null && $limit > 0 && count($filtered) >= $limit) {
            break;
        }
    }

    return $filtered;
}

function eos_find_ticket(string $ticketId): ?array
{
    foreach (eos_ticket_records() as $ticket) {
        if (($ticket['ticket_id'] ?? '') === $ticketId) {
            return $ticket;
        }
    }
    return null;
}

function eos_create_ticket(string $issueTime, string $site, string $issue): array
{
    $issue = trim($issue);
    if ($issue === '') {
        return ['ok' => false, 'message' => 'Kendala wajib diisi.'];
    }

    $site = eos_resolve_requested_site($site);
    if (!in_array($site, eos_ticket_sites(), true)) {
        return ['ok' => false, 'message' => 'Site tidak valid.'];
    }

    $ticketId = eos_generate_ticket_id();
    $createdAt = date('c');
    eos_ticket_append_event('ticket_created', [
        'ticket_id' => $ticketId,
        'created_at' => $createdAt,
        'issue_time' => trim($issueTime) !== '' ? trim($issueTime) : date('Y-m-d H:i:s'),
        'site' => $site,
        'issue' => $issue,
        'created_by' => eos_current_user() ?: 'system',
    ]);

    eos_log_event('TICKET', 'ticket_created', 'INFO', [
        'ticket_id' => $ticketId,
        'site' => $site,
        'issue' => $issue,
    ]);

    return ['ok' => true, 'message' => 'Tiket berhasil dibuat.', 'ticket_id' => $ticketId];
}

function eos_mark_ticket_on_check(string $ticketId): array
{
    eos_require_admin();
    return eos_mark_ticket_on_check_by_actor($ticketId);
}

function eos_mark_ticket_on_check_by_actor(string $ticketId): array
{
    $ticket = eos_find_ticket($ticketId);
    if ($ticket === null) {
        return ['ok' => false, 'message' => 'Tiket tidak ditemukan.'];
    }
    if (($ticket['status'] ?? '') !== 'open') {
        return ['ok' => false, 'message' => 'Hanya tiket OPEN yang bisa diubah ke ON CHECK.'];
    }

    eos_ticket_append_event('ticket_on_check', [
        'ticket_id' => $ticketId,
        'checked_at' => date('c'),
        'checked_by' => eos_current_user() ?: 'system',
    ]);
    eos_log_event('TICKET', 'ticket_on_check', 'INFO', ['ticket_id' => $ticketId]);
    return ['ok' => true, 'message' => 'Tiket masuk status ON CHECK.'];
}

function eos_mark_ticket_done(string $ticketId, string $note): array
{
    eos_require_admin();
    return eos_mark_ticket_done_by_actor($ticketId, $note);
}

function eos_mark_ticket_done_by_actor(string $ticketId, string $note): array
{
    $ticket = eos_find_ticket($ticketId);
    if ($ticket === null) {
        return ['ok' => false, 'message' => 'Tiket tidak ditemukan.'];
    }
    if (($ticket['status'] ?? '') === 'done') {
        return ['ok' => false, 'message' => 'Tiket sudah DONE.'];
    }

    eos_ticket_append_event('ticket_done', [
        'ticket_id' => $ticketId,
        'done_at' => date('c'),
        'done_by' => eos_current_user() ?: 'system',
        'note' => trim($note),
    ]);
    eos_log_event('TICKET', 'ticket_done', 'INFO', ['ticket_id' => $ticketId, 'note' => trim($note)]);
    return ['ok' => true, 'message' => 'Tiket berhasil ditutup.'];
}

function eos_extract_ticket_id_from_text(string $text): ?string
{
    if (preg_match('/\b(TCK-\d{8}-\d{6}-[A-Z0-9]{4})\b/i', $text, $match)) {
        return strtoupper($match[1]);
    }
    return null;
}

function eos_telegram_ticket_created_message(string $ticketId): string
{
    $ticket = eos_find_ticket($ticketId);
    if ($ticket === null) {
        return 'Tiket berhasil dibuat.';
    }

    return "🎫 <b>Tiket Baru Dibuat</b>\n" .
        "No Tiket: <b>" . eos_format_plain((string) $ticket['ticket_id']) . "</b>\n" .
        "Jam: <b>" . eos_format_plain((string) $ticket['issue_time']) . "</b>\n" .
        "Site: <b>" . eos_format_plain((string) $ticket['site']) . "</b>\n" .
        "Kendala: " . eos_format_plain((string) $ticket['issue']) . "\n" .
        "Balas pesan ini dengan <b>on proses</b> untuk mulai penanganan, atau <b>done catatan...</b> untuk menutup tiket.";
}

function eos_telegram_ticket_status_message(string $ticketId, string $status): string
{
    $ticket = eos_find_ticket($ticketId);
    if ($ticket === null) {
        return 'Tiket tidak ditemukan.';
    }

    if ($status === 'on_check') {
        return "🛠️ <b>Tiket On Proses</b>\n" .
            "No Tiket: <b>" . eos_format_plain((string) $ticket['ticket_id']) . "</b>\n" .
            "Site: <b>" . eos_format_plain((string) $ticket['site']) . "</b>\n" .
            "Kendala: " . eos_format_plain((string) $ticket['issue']) . "\n" .
            "Diproses oleh: <b>" . eos_format_plain((string) ($ticket['checked_by'] ?? '-')) . "</b>\n" .
            "Mulai: <b>" . eos_format_plain((string) ($ticket['checked_at'] ?? '-')) . "</b>\n" .
            "Balas pesan ini dengan <b>done catatan...</b> jika penanganan sudah selesai.";
    }

    return "✅ <b>Tiket Selesai</b>\n" .
        "No Tiket: <b>" . eos_format_plain((string) $ticket['ticket_id']) . "</b>\n" .
        "Site: <b>" . eos_format_plain((string) $ticket['site']) . "</b>\n" .
        "Kendala: " . eos_format_plain((string) $ticket['issue']) . "\n" .
        "Diproses oleh: <b>" . eos_format_plain((string) ($ticket['checked_by'] ?? '-')) . "</b>\n" .
        "Selesai oleh: <b>" . eos_format_plain((string) ($ticket['done_by'] ?? '-')) . "</b>\n" .
        "Lama Penanganan: <b>" . eos_format_plain(eos_ticket_duration_label($ticket['repair_minutes'] ?? null)) . "</b>\n" .
        "Catatan: " . eos_format_plain((string) ($ticket['note'] ?: '-'));
}

function eos_ticket_monthly_report(string $month): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }

    $visibleSites = eos_is_admin() ? null : [strtoupper((string) eos_current_user_site()) => true];
    $index = eos_ticket_synced_index();
    $ticketMap = $index['tickets'] ?? [];
    $ticketIds = $index['by_month'][$month] ?? [];
    $items = [];
    foreach ($ticketIds as $ticketId) {
        $ticket = $ticketMap[$ticketId] ?? null;
        if (!is_array($ticket)) {
            continue;
        }
        if ($visibleSites !== null && !isset($visibleSites[strtoupper((string) ($ticket['site'] ?? ''))])) {
            continue;
        }
        $items[] = $ticket;
    }

    return array_map(static function ($ticket) {
        return [
            'ticket_id' => $ticket['ticket_id'],
            'site' => $ticket['site'],
            'issue' => $ticket['issue'],
            'issue_time' => $ticket['issue_time'],
            'status' => $ticket['status'],
            'repair_duration' => eos_ticket_duration_label($ticket['repair_minutes']),
            'note' => $ticket['note'],
        ];
    }, $items);
}

function eos_ticket_daily_report(string $date): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    $visibleSites = eos_is_admin() ? null : [strtoupper((string) eos_current_user_site()) => true];
    $index = eos_ticket_synced_index();
    $ticketMap = $index['tickets'] ?? [];
    $ticketIds = $index['by_day'][$date] ?? [];
    $items = [];
    foreach ($ticketIds as $ticketId) {
        $ticket = $ticketMap[$ticketId] ?? null;
        if (!is_array($ticket)) {
            continue;
        }
        if ($visibleSites !== null && !isset($visibleSites[strtoupper((string) ($ticket['site'] ?? ''))])) {
            continue;
        }
        $items[] = $ticket;
    }

    return [
        'date' => $date,
        'open' => count(array_filter($items, static fn($ticket) => ($ticket['status'] ?? '') === 'open')),
        'on_check' => count(array_filter($items, static fn($ticket) => ($ticket['status'] ?? '') === 'on_check')),
        'done' => count(array_filter($items, static fn($ticket) => ($ticket['status'] ?? '') === 'done')),
        'items' => array_map(static function ($ticket) {
            return [
                'ticket_id' => $ticket['ticket_id'],
                'site' => $ticket['site'],
                'issue' => $ticket['issue'],
                'issue_time' => $ticket['issue_time'],
                'status' => $ticket['status'],
                'repair_duration' => eos_ticket_duration_label($ticket['repair_minutes']),
                'repair_minutes' => $ticket['repair_minutes'],
                'note' => $ticket['note'],
            ];
        }, $items),
    ];
}

function eos_telegram_ticket_daily_summary_message(string $date): string
{
    $report = eos_ticket_daily_report($date);
    $items = $report['items'] ?? [];

    $lines = [
        "📅 <b>Summary Ticket Harian {$report['date']}</b>",
        "Open: <b>{$report['open']}</b>",
        "On Check: <b>{$report['on_check']}</b>",
        "Done: <b>{$report['done']}</b>",
    ];

    if ($items === []) {
        $lines[] = "Detail: tidak ada tiket untuk tanggal ini.";
        return implode("\n", $lines);
    }

    $lines[] = "Detail:";
    foreach (array_slice($items, 0, 8) as $item) {
        $lines[] = eos_format_plain(
            $item['ticket_id'] . ' | ' .
            $item['site'] . ' | ' .
            strtoupper((string) $item['status']) . ' | ' .
            $item['issue'] . ' | penanganan: ' . ($item['repair_duration'] ?: '-')
        );
    }

    return implode("\n", $lines);
}

function eos_extract_site_from_text(string $text): string
{
    $gateId = eos_extract_gate_id($text);
    if ($gateId !== null) {
        return $gateId;
    }

    if (preg_match('/\bsite\s+([a-z0-9_-]+)\b/i', $text, $match) === 1) {
        return strtoupper($match[1]);
    }

    if (preg_match('/\bserver\b/i', $text) === 1) {
        return 'SERVER';
    }

    return '';
}

function eos_strip_ticket_intent_prefix(string $text): string
{
    $clean = trim($text);
    $patterns = [
        '/^@?[a-z0-9_]+\s+/i',
        '/\b(tolong|please|mohon|bantu)\b/iu',
        '/\b(buatkan|buat|bikin|open|input|catat|laporkan|lapor)\b/iu',
        '/\b(ticket|tiket)\b/iu',
        '/\b(ada kendala|ada trouble|ada problem|kendala|trouble|masalah|problem|gangguan)\b/iu',
        '/\b(di|untuk)\s+site\s+[a-z0-9_-]+\b/iu',
    ];

    foreach ($patterns as $pattern) {
        $clean = preg_replace($pattern, ' ', $clean) ?? $clean;
    }

    return trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
}

function eos_telegram_natural_ticket_create(string $text, string $lower, array $actorContext): ?array
{
    $looksLikeTicket = preg_match('/\b(ticket|tiket|kendala|trouble|masalah|problem|gangguan)\b/u', $lower) === 1;
    $looksLikeCreate = preg_match('/\b(buat|bikin|buatkan|lapor|laporkan|catat|input|open|tolong)\b/u', $lower) === 1;

    if (!$looksLikeTicket || !$looksLikeCreate) {
        return null;
    }

    $site = ($actorContext['role'] ?? 'eos') === 'admin'
        ? (eos_extract_site_from_text($text) ?: 'SERVER')
        : (string) ($actorContext['site'] ?? 'SERVER');

    $issue = eos_strip_ticket_intent_prefix($text);
    if ($issue === '') {
        $issue = trim($text);
    }

    return [
        'site' => $site,
        'issue' => $issue,
    ];
}

function eos_telegram_natural_ticket_summary_intent(string $lower): ?array
{
    if (preg_match('/\b(summary|ringkasan|rekap|laporan|tampilkan|tunjukkan|lihat|cek|show|display|list|daftar)\b/u', $lower) !== 1) {
        return null;
    }

    if (preg_match('/\b(ticket|tiket)\b/u', $lower) !== 1) {
        return null;
    }

    if (preg_match('/\b(aktif|active|open|on check|oncheck|belum selesai|ongoing|progress)\b/u', $lower) === 1) {
        return ['type' => 'active', 'value' => 'active'];
    }

    if (preg_match('/\b(hari ini|today|harian)\b/u', $lower) === 1) {
        return ['type' => 'day', 'value' => date('Y-m-d')];
    }

    if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $lower, $match) === 1) {
        return ['type' => 'day', 'value' => $match[1]];
    }

    if (preg_match('/\b(\d{4}-\d{2})\b/', $lower, $match) === 1) {
        return ['type' => 'month', 'value' => $match[1]];
    }

    if (preg_match('/\b(bulan ini|monthly|perbulan|bulanan)\b/u', $lower) === 1) {
        return ['type' => 'month', 'value' => date('Y-m')];
    }

    return null;
}

function eos_telegram_reply_is_on_process(string $text): bool
{
    return preg_match('/^(on proses|on process|proses|sedang diproses|lagi diproses|sedang di cek|sedang dicek|oncheck|on check)$/iu', trim($text)) === 1;
}

function eos_telegram_reply_done_note(string $text): ?string
{
    $trimmed = trim($text);
    if (preg_match('/^(done|selesai|sudah selesai|sudah beres|beres|clear)(?:\s*[:\-]?\s*(.+))?$/isu', $trimmed, $match) === 1) {
        return trim((string) ($match[2] ?? ''));
    }
    return null;
}

function eos_telegram_actor_context(array $message): array
{
    $from = $message['from'] ?? [];
    $telegramUsername = trim((string) ($from['username'] ?? ''));
    $fallbackUsername = 'telegram_' . (string) ($from['id'] ?? 'guest');
    $actorUsername = $telegramUsername !== '' ? $telegramUsername : $fallbackUsername;

    $users = eos_users();
    if (isset($users[$actorUsername])) {
        return [
            'username' => $actorUsername,
            'role' => (string) ($users[$actorUsername]['role'] ?? 'eos'),
            'site' => (string) ($users[$actorUsername]['site'] ?? 'SERVER'),
            'mapped' => true,
        ];
    }

    return [
        'username' => $actorUsername,
        'role' => 'eos',
        'site' => 'SERVER',
        'mapped' => false,
    ];
}

function eos_telegram_ticket_row(array $ticket): string
{
    return $ticket['ticket_id'] . ' | ' . $ticket['site'] . ' | ' . strtoupper((string) ($ticket['status'] ?? '-')) . ' | ' . ($ticket['issue'] ?? '-');
}

function eos_visible_active_tickets(?int $limit = null): array
{
    $tickets = eos_visible_tickets($limit);
    $tickets = array_values(array_filter($tickets, static function ($ticket) {
        $status = strtolower((string) ($ticket['status'] ?? ''));
        return $status === 'open' || $status === 'on_check';
    }));

    return eos_limit_rows($tickets, $limit);
}

function eos_telegram_visible_tickets_text(array $tickets, int $limit = 6): string
{
    if ($tickets === []) {
        return 'Belum ada tiket yang terlihat untuk user ini.';
    }

    $lines = [];
    foreach (array_slice($tickets, 0, $limit) as $ticket) {
        $lines[] = eos_telegram_ticket_row($ticket);
    }
    return implode("\n", $lines);
}

function eos_tail(string $file, int $limit = 80): array
{
    if (!is_file($file)) {
        return [];
    }

    if ($limit <= 0) {
        return [];
    }

    $limit = min($limit, 300);
    $handle = fopen($file, 'rb');
    if (!$handle) {
        return [];
    }

    $chunkSize = 8192;
    $position = -1;
    $buffer = '';
    $lineCount = 0;

    while (true) {
        if (fseek($handle, $position * $chunkSize, SEEK_END) !== 0) {
            fseek($handle, 0, SEEK_SET);
            $buffer = fread($handle, $chunkSize) . $buffer;
            break;
        }

        $buffer = fread($handle, $chunkSize) . $buffer;
        $lineCount = substr_count($buffer, "\n");
        if (ftell($handle) === 0 || $lineCount >= $limit) {
            break;
        }

        $position++;
    }

    fclose($handle);

    $trimmed = rtrim($buffer);
    if ($trimmed === '') {
        return [];
    }

    $lines = preg_split('/\R/', $trimmed) ?: [];
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

function eos_send_telegram(string $message, ?array $chatIds = null, array $options = []): array
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
            array_filter([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'reply_to_message_id' => $options['reply_to_message_id'] ?? null,
                'disable_web_page_preview' => $options['disable_web_page_preview'] ?? true,
            ], static fn($value) => $value !== null)
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

function eos_runtime_identity(): array
{
    $configuredLabel = (string) eos_config('runtime.responder_label', 'AUTO');
    $configuredIp = (string) eos_config('runtime.responder_ip', 'AUTO');

    $hostname = gethostname() ?: php_uname('n') ?: 'unknown-host';
    $detectedIp = '';

    if (!empty($_SERVER['SERVER_ADDR'])) {
        $detectedIp = (string) $_SERVER['SERVER_ADDR'];
    }
    if ($detectedIp === '' && !empty($_SERVER['LOCAL_ADDR'])) {
        $detectedIp = (string) $_SERVER['LOCAL_ADDR'];
    }
    if ($detectedIp === '' && $hostname !== '') {
        $resolved = @gethostbyname($hostname);
        if (is_string($resolved) && $resolved !== $hostname) {
            $detectedIp = $resolved;
        }
    }
    if ($detectedIp === '') {
        $detectedIp = 'unknown-ip';
    }

    return [
        'label' => $configuredLabel !== 'AUTO' ? $configuredLabel : $hostname,
        'ip' => $configuredIp !== 'AUTO' ? $configuredIp : $detectedIp,
        'hostname' => $hostname,
    ];
}

function eos_telegram_with_identity(string $message): string
{
    if (!eos_config('telegram.include_responder_identity', true)) {
        return $message;
    }

    $identity = eos_runtime_identity();
    return rtrim($message) . "\n\n" .
        "<i>Dibalas oleh program di:</i>\n" .
        "<b>{$identity['label']}</b> / <code>{$identity['ip']}</code>";
}

function eos_telegram_display_name(array $message): string
{
    $from = $message['from'] ?? [];
    $name = trim((string) (($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')));
    if ($name !== '') {
        return $name;
    }
    if (!empty($from['username'])) {
        return '@' . $from['username'];
    }
    return 'rekan';
}

function eos_telegram_is_addressed(string $text, array $message): bool
{
    $lower = strtolower(trim($text));
    if ($lower === '') {
        return false;
    }

    foreach ((array) eos_config('telegram.aliases', []) as $alias) {
        if (strpos($lower, strtolower($alias)) !== false) {
            return true;
        }
    }

    $reply = $message['reply_to_message'] ?? [];
    if (($reply['from']['is_bot'] ?? false) === true) {
        return true;
    }

    return false;
}

function eos_telegram_human_reply(string $text, array $message): ?string
{
    $lower = strtolower(trim($text));
    $name = eos_telegram_display_name($message);
    $botName = (string) eos_config('telegram.bot_name', 'EOS Tools');

    if ($lower === '') {
        return null;
    }

    $smartReply = eos_telegram_smart_intent_reply($lower, $name, $botName);
    if ($smartReply !== null) {
        return $smartReply;
    }

    if (preg_match('/\b(halo|hallo|hai|hello|pagi|siang|sore|malam)\b/u', $lower)) {
        return "Halo {$name}, {$botName} siap bantu. Kalau mau, saya bisa ringkas status server, cek disk, atau cek perangkat yang offline.";
    }

    if (strpos($lower, 'terima kasih') !== false || strpos($lower, 'makasih') !== false || strpos($lower, 'thanks') !== false) {
        return "Sama-sama {$name}. Kalau ada yang mau dicek lagi, tinggal panggil {$botName}.";
    }

    if (strpos($lower, 'siapa kamu') !== false || strpos($lower, 'kamu siapa') !== false) {
        return "{$botName} adalah control board assistant untuk restart service, cek disk, pantau jaringan, dan bantu operasional lewat Telegram.";
    }

    if (strpos($lower, 'status') !== false || strpos($lower, 'kondisi') !== false) {
        return eos_telegram_overall_status_reply($name);
    }

    if (preg_match('/\b(tolong|bantu|cek)\b/u', $lower)) {
        return "Siap {$name}. Saya bisa bantu cek status umum, perangkat offline, gate tertentu, /disk, /health, restart pool dengan /restart NAMA_POOL, atau restart group dengan /restart-group NAMA_GROUP.";
    }

    return "Saya tangkap pesan Anda, {$name}. Kalau ingin aksi cepat, sebut saja yang mau dicek atau pakai command seperti /disk, /health, /restart, atau /iis.";
}

function eos_telegram_normalize_command_text(string $text): string
{
    $trimmed = trim($text);
    if ($trimmed === '') {
        return '';
    }

    return preg_replace('/^(\/[a-z0-9_-]+)@[a-z0-9_]+/iu', '$1', $trimmed) ?? $trimmed;
}

function eos_telegram_smart_intent_reply(string $lower, string $name, string $botName): ?string
{
    $asksDisk = preg_match('/\b(disk|storage|penyimpanan|kapasitas|drive c|sisa disk|disk tinggal)\b/u', $lower) === 1;
    $asksNetwork = preg_match('/\b(jaringan|network|internet|server|ping|kamera|domain|host)\b/u', $lower) === 1;
    $asksHow = preg_match('/\b(bagaimana|gimana|berapa|sehat|aman|normal|status|kondisi|tinggal)\b/u', $lower) === 1;
    $asksOnline = preg_match('/\b(online|offline|hidup|mati|putus|nyambung|terhubung)\b/u', $lower) === 1;
    $asksProblem = preg_match('/\b(masalah|gangguan|kendala|error|bermasalah|offline semua|yang offline|mana yang offline|apa yang offline)\b/u', $lower) === 1;
    $asksSummary = preg_match('/\b(ringkas|ringkasan|summary|singkat|overview|sekilas)\b/u', $lower) === 1;

    $deviceReply = eos_telegram_device_status_reply($lower, $name);
    if ($deviceReply !== null) {
        return $deviceReply;
    }

    if ($asksDisk) {
        $disk = eos_disk_space_report();
        if (!($disk['ok'] ?? false)) {
            return "{$name}, saya belum bisa baca disk saat ini. Detailnya: " . ($disk['message'] ?? 'unknown error') . ".";
        }

        return "{$name}, sisa disk {$disk['drive']} sekarang {$disk['free_human']} atau {$disk['free_percent']}%. " .
            "Yang terpakai {$disk['used_human']} ({$disk['used_percent']}%). Statusnya " . strtoupper((string) $disk['status']) . '.';
    }

    if ($asksSummary || ($asksHow && preg_match('/\b(umum|keseluruhan|overall|semua)\b/u', $lower) === 1)) {
        return eos_telegram_overall_status_reply($name);
    }

    if ($asksProblem) {
        return eos_telegram_problem_focus_reply($name);
    }

    if ($asksNetwork || $asksOnline || ($asksHow && preg_match('/\b(server|koneksi|domain|kamera|gate|barrier|adam|timbangan)\b/u', $lower) === 1)) {
        $network = eos_network_monitor(false);
        $targets = $network['targets'] ?? [];
        $online = 0;
        $offlineNames = [];
        foreach ($targets as $target) {
            if (($target['status'] ?? '') === 'online') {
                $online++;
            } else {
                $offlineNames[] = $target['label'] ?? 'unknown';
            }
        }

        if (!$targets) {
            return "{$name}, saya belum dapat data jaringan saat ini.";
        }

        if (!$offlineNames) {
            return "{$name}, jaringan terpantau bagus. Semua target online ({$online}/" . count($targets) . "). Tidak ada perangkat yang perlu dicek sekarang.";
        }

        $offlineText = implode(', ', array_slice($offlineNames, 0, 3));
        return "{$name}, kondisi jaringan saat ini " . strtoupper((string) $network['overall']) . ". " .
            "Target online {$online}/" . count($targets) . ". Yang perlu dicek: {$offlineText}. " . eos_telegram_network_follow_up($network);
    }

    return null;
}

function eos_telegram_device_status_reply(string $lower, string $name): ?string
{
    $gateId = eos_extract_gate_id($lower);
    $network = eos_network_monitor(false);
    $targets = $network['targets'] ?? [];
    $specificTargetReply = eos_telegram_specific_target_reply($lower, $name, $targets);
    if ($specificTargetReply !== null) {
        return $specificTargetReply;
    }

    if ($gateId !== null) {
        $matches = array_values(array_filter($targets, static function ($target) use ($gateId) {
            return stripos((string) ($target['gate_id'] ?? ''), $gateId) !== false;
        }));

        if (!$matches) {
            return "{$name}, saya belum menemukan perangkat yang terdaftar untuk {$gateId}.";
        }

        $requestedType = eos_extract_device_type($lower);
        if ($requestedType !== null) {
            $matches = array_values(array_filter($matches, static function ($target) use ($requestedType) {
                return ($target['device_type'] ?? null) === $requestedType;
            }));
            if (!$matches) {
                return "{$name}, " . eos_device_type_label($requestedType) . " untuk {$gateId} belum terdaftar di inventori.";
            }
        }

        $online = count(array_filter($matches, static fn($target) => ($target['status'] ?? '') === 'online'));
        $offline = array_values(array_filter($matches, static fn($target) => ($target['status'] ?? '') !== 'online'));
        $details = implode('; ', array_map(static function ($target) {
            return eos_telegram_target_status_snippet($target);
        }, array_slice($matches, 0, 4)));

        if (!$offline) {
            return "{$name}, semua perangkat {$gateId} terpantau online ({$online}/" . count($matches) . "). {$details}.";
        }

        return "{$name}, perangkat {$gateId} yang online {$online}/" . count($matches) . ". Detail: {$details}.";
    }

    $type = eos_extract_device_type($lower);
    if ($type === null) {
        return null;
    }

    $matches = array_values(array_filter($targets, static function ($target) use ($type) {
        return ($target['device_type'] ?? null) === $type;
    }));

    if (!$matches) {
        return null;
    }

    $online = count(array_filter($matches, static fn($target) => ($target['status'] ?? '') === 'online'));
    $offline = array_values(array_filter($matches, static fn($target) => ($target['status'] ?? '') !== 'online'));
    if (!$offline) {
        return "{$name}, status " . eos_device_type_label($type) . " saat ini bagus. Semua online ({$online}/" . count($matches) . ").";
    }

    $details = implode('; ', array_map(static function ($target) {
        return eos_telegram_target_status_snippet($target);
    }, array_slice($offline, 0, 4)));
    return "{$name}, status " . eos_device_type_label($type) . " saat ini {$online}/" . count($matches) . " online. Yang sedang offline: {$details}.";
}

function eos_extract_gate_id(string $text): ?string
{
    if (preg_match('/\b(gate\s*0?([1-9]|1[0-9]))\s*([io])\b/i', $text, $match)) {
        return 'GATE' . str_pad($match[2], 2, '0', STR_PAD_LEFT) . strtoupper($match[3]);
    }
    if (preg_match('/\b(gate0?[0-9]{1,2}[io])\b/i', $text, $match)) {
        return strtoupper(str_replace(' ', '', $match[1]));
    }
    return null;
}

function eos_extract_device_type(string $text): ?string
{
    if (preg_match('/\b(camera|kamera)\b/u', $text) === 1) {
        return 'camera';
    }
    if (preg_match('/\b(barrier|adam)\b/u', $text) === 1) {
        return 'barrier';
    }
    if (preg_match('/\b(timbangan)\b/u', $text) === 1) {
        return 'timbangan';
    }
    return null;
}

function eos_device_type_label(string $type): string
{
    switch (strtolower($type)) {
        case 'camera':
            return 'camera';
        case 'barrier':
            return 'barrier/adam';
        case 'timbangan':
            return 'timbangan';
        default:
            return $type;
    }
}

function eos_telegram_specific_target_reply(string $lower, string $name, array $targets): ?string
{
    $bestScore = 0;
    $matches = [];

    foreach ($targets as $target) {
        $score = eos_match_network_target_score($lower, $target);
        if ($score <= 0) {
            continue;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $matches = [$target];
            continue;
        }
        if ($score === $bestScore) {
            $matches[] = $target;
        }
    }

    if ($bestScore < 3 || !$matches) {
        return null;
    }

    if (count($matches) === 1) {
        $target = $matches[0];
        $statusText = eos_status_label((string) ($target['status'] ?? 'unknown'));
        $detail = (string) ($target['detail'] ?? '-');
        $latency = (string) ($target['latency'] ?? '-');
        return "{$name}, {$target['label']} saat ini {$statusText}. Endpoint {$target['endpoint']}. Latency {$latency}. Detail {$detail}.";
    }

    $details = implode('; ', array_map(static function ($target) {
        return eos_telegram_target_status_snippet($target);
    }, array_slice($matches, 0, 4)));
    return "{$name}, saya temukan beberapa target terkait. {$details}.";
}

function eos_match_network_target_score(string $lower, array $target): int
{
    $score = 0;
    $label = strtolower((string) ($target['label'] ?? ''));
    $endpoint = strtolower((string) ($target['endpoint'] ?? ''));
    $gateId = strtolower((string) ($target['gate_id'] ?? ''));

    if ($gateId !== '' && strpos($lower, strtolower(str_replace(' ', '', $gateId))) !== false) {
        $score += 4;
    }

    if ($endpoint !== '' && strpos($lower, $endpoint) !== false) {
        $score += 5;
    }

    if ($label !== '' && strpos($lower, $label) !== false) {
        $score += 5;
    }

    foreach (eos_network_target_terms($target) as $term) {
        if ($term !== '' && strpos($lower, $term) !== false) {
            $score += 3;
        }
    }

    return $score;
}

function eos_network_target_terms(array $target): array
{
    $terms = [];
    $label = strtolower((string) ($target['label'] ?? ''));
    $endpoint = strtolower((string) ($target['endpoint'] ?? ''));

    if ($endpoint !== '') {
        $terms[] = $endpoint;
        $host = parse_url($endpoint, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $terms[] = strtolower($host);
            $parts = preg_split('/[^a-z0-9]+/', strtolower($host)) ?: [];
            foreach ($parts as $part) {
                if (strlen($part) >= 4) {
                    $terms[] = $part;
                }
            }
        }
    }

    $parts = preg_split('/[^a-z0-9]+/', $label) ?: [];
    foreach ($parts as $part) {
        if (strlen($part) >= 4 && !in_array($part, ['server', 'camera', 'barrier', 'adam', 'domain'], true)) {
            $terms[] = $part;
        }
    }

    return array_values(array_unique(array_filter($terms)));
}

function eos_telegram_target_status_snippet(array $target): string
{
    $status = eos_status_label((string) ($target['status'] ?? 'unknown'));
    $latency = (string) ($target['latency'] ?? '-');
    $endpoint = (string) ($target['endpoint'] ?? '-');
    return "{$target['label']} {$status} ({$latency}) [{$endpoint}]";
}

function eos_telegram_overall_status_reply(string $name): string
{
    $summary = eos_dashboard_summary('server');
    $network = $summary['network'] ?? ['targets' => [], 'overall' => 'standby'];
    $targets = $network['targets'] ?? [];
    $online = count(array_filter($targets, static fn($target) => ($target['status'] ?? '') === 'online'));
    $offline = array_values(array_filter($targets, static fn($target) => ($target['status'] ?? '') !== 'online'));
    $diskStatus = strtoupper((string) ($summary['disk']['status'] ?? 'unknown'));
    $busState = (string) ($summary['board']['bus_state'] ?? 'UNKNOWN');

    if (!$offline) {
        return "{$name}, status umum saat ini bagus. Bus {$busState}, disk {$diskStatus}, dan network {$online}/" . count($targets) . " target online.";
    }

    $details = implode('; ', array_map(static function ($target) {
        return eos_telegram_target_status_snippet($target);
    }, array_slice($offline, 0, 3)));
    return "{$name}, status umum saat ini perlu perhatian. Bus {$busState}, disk {$diskStatus}, network {$online}/" . count($targets) . " target online. Yang sedang bermasalah: {$details}.";
}

function eos_telegram_problem_focus_reply(string $name): string
{
    $network = eos_network_monitor(false);
    $offline = array_values(array_filter(($network['targets'] ?? []), static fn($target) => ($target['status'] ?? '') !== 'online'));

    if (!$offline) {
        return "{$name}, saat ini tidak ada target yang terpantau offline. Semua perangkat dan host yang dimonitor sedang online.";
    }

    $details = implode('; ', array_map(static function ($target) {
        return eos_telegram_target_status_snippet($target);
    }, array_slice($offline, 0, 5)));
    return "{$name}, yang sedang perlu dicek: {$details}. " . eos_telegram_network_follow_up($network);
}

function eos_telegram_network_follow_up(array $network): string
{
    $overall = strtolower((string) ($network['overall'] ?? 'standby'));
    if ($overall === 'fault') {
        return 'Saran saya cek endpoint tersebut lebih dulu dari sisi ping, power, atau koneksi LAN.';
    }
    if ($overall === 'warning') {
        return 'Masih ada respons, tetapi ada indikasi warning yang sebaiknya dipantau.';
    }
    return 'Kondisi umum terlihat stabil.';
}

function eos_status_label(string $status): string
{
    switch (strtolower($status)) {
        case 'online':
        case 'ready':
            return 'online';
        case 'offline':
        case 'fault':
            return 'offline';
        case 'warning':
            return 'warning';
        default:
            return 'unknown';
    }
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
    eos_log_event('IIS', 'restart_pool', $ok ? 'INFO' : 'WARN', [
        'pool' => $normalized,
        'duration' => $duration . 's',
        'state' => $parsed['finalState'] ?? 'Unknown',
        'note' => $note ?: '-',
    ], $actor);
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
    eos_log_event('IIS', 'restart_group', $allOk ? 'INFO' : 'WARN', [
        'group' => $normalized,
        'total_pool' => count($results),
        'status' => $allOk ? 'OK' : 'PERLU_CEK',
        'note' => $note ?: '-',
    ], $actor ?: (eos_current_user() ?: 'system'));

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
    eos_log_event('IIS', 'restart_iis', $ok ? 'INFO' : 'WARN', [
        'duration' => $duration . 's',
        'code' => $code,
        'reason' => $reason ?: '-',
    ], $actor);
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
        eos_log_event('DISK', 'warning_sent', 'WARN', [
            'drive' => $report['drive'],
            'free_percent' => $report['free_percent'],
            'free_space' => $report['free_human'],
        ]);
    } elseif ($forceNotify) {
        eos_send_telegram(
            "ℹ️ <b>Disk Space Report</b>\nDrive: <b>{$report['drive']}</b>\nFree: <b>{$report['free_human']}</b> ({$report['free_percent']}%)\nUsed: {$report['used_human']} ({$report['used_percent']}%)\nStatus: " . strtoupper($report['status'])
        );
        eos_log('Disk report manual terkirim ke Telegram.');
        eos_log_event('DISK', 'manual_report_sent', 'INFO', [
            'drive' => $report['drive'],
            'free_percent' => $report['free_percent'],
            'status' => $report['status'],
        ]);
    }

    file_put_contents($stateFile, json_encode([
        'status' => $report['status'],
        'free_percent' => $report['free_percent'],
        'checked_at' => date('c'),
        'notified' => $shouldAlert,
    ], JSON_PRETTY_PRINT));

    return $report;
}

function eos_network_cached_state(): array
{
    $stateFile = eos_config('network.state_file');
    if (!is_file($stateFile)) {
        return [
            'updated_at' => null,
            'overall' => 'standby',
            'targets' => [],
        ];
    }

    $state = json_decode((string) file_get_contents($stateFile), true);
    return is_array($state) ? $state : [
        'updated_at' => null,
        'overall' => 'standby',
        'targets' => [],
    ];
}

function eos_network_ping_target(string $host, int $timeoutSeconds): array
{
    $hostArg = escapeshellarg($host);
    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'ping -n 1 -w ' . ((int) $timeoutSeconds * 1000) . ' ' . $hostArg . ' 2>&1';
    } else {
        $command = 'ping -c 1 -W ' . (int) $timeoutSeconds . ' ' . $hostArg . ' 2>&1';
    }

    $output = [];
    $code = 1;
    @exec($command, $output, $code);
    $text = trim(implode("\n", $output));

    return [
        'ok' => $code === 0,
        'latency' => eos_parse_ping_latency($text),
        'detail' => $text,
    ];
}

function eos_parse_ping_latency(string $text): ?string
{
    if (preg_match('/time[=<]([0-9\.]+)\s*ms/i', $text, $match)) {
        return $match[1] . ' ms';
    }
    if (preg_match('/Average = ([0-9]+)ms/i', $text, $match)) {
        return $match[1] . ' ms';
    }
    return null;
}

function eos_network_http_target(string $url, int $timeoutSeconds): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $time = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);

    return [
        'ok' => $error === '' && $status >= 200 && $status < 500,
        'latency' => $time > 0 ? round($time * 1000) . ' ms' : null,
        'detail' => $error !== '' ? $error : 'HTTP ' . $status,
        'status_code' => $status,
    ];
}

function eos_network_monitor(bool $forceLog = false): array
{
    $targets = eos_network_targets();
    $timeout = (int) eos_config('network.timeout_seconds', 2);
    $previousState = eos_network_cached_state();
    $previousTargets = [];
    foreach (($previousState['targets'] ?? []) as $target) {
        $previousTargets[$target['key']] = $target;
    }
    $results = [];
    $hasWarning = false;
    $hasFault = false;

    foreach ($targets as $target) {
        $type = $target['type'] ?? 'ping';
        $label = $target['label'] ?? ($target['host'] ?? $target['url'] ?? 'unknown');
        $startedAt = microtime(true);

        if ($type === 'http') {
            $check = eos_network_http_target((string) $target['url'], $timeout);
            $endpoint = (string) $target['url'];
        } else {
            $check = eos_network_ping_target((string) $target['host'], $timeout);
            $endpoint = (string) $target['host'];
        }

        $duration = round((microtime(true) - $startedAt) * 1000);
        $status = $check['ok'] ? 'online' : 'offline';
        if (!$check['ok']) {
            $hasFault = true;
        }

        $row = [
            'key' => $target['key'] ?? md5($label),
            'label' => $label,
            'type' => $type,
            'endpoint' => $endpoint,
            'status' => $status,
            'latency' => $check['latency'] ?? null,
            'detail' => $check['detail'] ?? '',
            'duration_ms' => $duration,
        ];

        if (isset($check['status_code'])) {
            $row['status_code'] = $check['status_code'];
            if ($check['status_code'] >= 400) {
                $hasWarning = true;
            }
        }

        $previousTarget = $previousTargets[$row['key']] ?? null;
        $previousStatus = $previousTarget['status'] ?? null;
        if (
            eos_config('network.notify_on_change', true) &&
            $previousStatus !== null &&
            $previousStatus !== $status
        ) {
            $emoji = $status === 'online' ? '✅' : '🚨';
            $stateLabel = $status === 'online' ? 'KEMBALI ONLINE' : 'PUTUS / OFFLINE';
            eos_send_telegram(
                "{$emoji} <b>Network State Change</b>\nTarget: <b>{$label}</b>\nEndpoint: <code>{$endpoint}</code>\nStatus: <b>{$stateLabel}</b>\nSebelumnya: <b>" . strtoupper($previousStatus) . "</b>\nLatency: <b>" . eos_format_plain((string) ($row['latency'] ?: '-')) . "</b>\nDetail: " . eos_format_plain((string) $row['detail'])
            );
            eos_log_event('NET', 'state_change', $status === 'online' ? 'INFO' : 'ERROR', [
                'target' => $label,
                'endpoint' => $endpoint,
                'from' => $previousStatus,
                'to' => $status,
                'latency' => $row['latency'] ?: '-',
            ], null, 'network');
        }

        if ($forceLog || !$check['ok']) {
            eos_log_event('NET', 'target_check', $check['ok'] ? 'INFO' : 'ERROR', [
                'target' => $label,
                'endpoint' => $endpoint,
                'status' => $status,
                'latency' => $row['latency'] ?: '-',
                'detail' => $row['detail'],
            ], null, 'network');
        }

        $results[] = $row;
    }

    $overall = $hasFault ? 'fault' : ($hasWarning ? 'warning' : 'ready');
    $payload = [
        'updated_at' => date('c'),
        'overall' => $overall,
        'targets' => $results,
    ];

    file_put_contents(eos_config('network.state_file'), json_encode($payload, JSON_PRETTY_PRINT));
    return $payload;
}

function eos_network_targets(): array
{
    $targets = eos_config('network.targets', []);

    foreach ((array) eos_config('devices.cameras', []) as $camera) {
        $targets[] = [
            'key' => 'camera_' . strtolower((string) $camera['gate_id']) . '_' . preg_replace('/[^0-9]/', '_', (string) $camera['ip']) . '_' . strtolower((string) $camera['name']),
            'label' => (string) $camera['gate_id'] . ' Camera ' . (string) $camera['name'],
            'type' => 'ping',
            'host' => (string) $camera['ip'],
            'gate_id' => (string) $camera['gate_id'],
            'device_type' => 'camera',
            'inventory_group' => 'camera',
        ];
    }

    foreach ((array) eos_config('devices.gates', []) as $gate) {
        $targets[] = [
            'key' => 'barrier_' . strtolower((string) $gate['id']),
            'label' => (string) $gate['id'] . ' Barrier',
            'type' => 'ping',
            'host' => (string) $gate['barrier_ip'],
            'gate_id' => (string) $gate['id'],
            'device_type' => 'barrier',
            'inventory_group' => 'gate',
        ];
        $targets[] = [
            'key' => 'timbangan_' . strtolower((string) $gate['id']),
            'label' => (string) $gate['id'] . ' Timbangan',
            'type' => 'ping',
            'host' => (string) $gate['timbangan_ip'],
            'gate_id' => (string) $gate['id'],
            'device_type' => 'timbangan',
            'inventory_group' => 'gate',
        ];
    }

    return $targets;
}

function eos_network_targets_by_key(array $networkState): array
{
    $map = [];
    foreach (($networkState['targets'] ?? []) as $target) {
        $map[$target['key']] = $target;
    }
    return $map;
}

function eos_network_device_key_camera(array $camera): string
{
    return 'camera_' . strtolower((string) $camera['gate_id']) . '_' . preg_replace('/[^0-9]/', '_', (string) $camera['ip']) . '_' . strtolower((string) $camera['name']);
}

function eos_network_device_status(array $networkMap, string $key): array
{
    return $networkMap[$key] ?? [];
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
    eos_log_event('IMG', 'image_fetch', 'INFO', [
        'gate' => $gate,
        'datetime' => $dateTimeInput,
        'result_count' => count($results),
    ]);

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

function eos_dashboard_summary(?string $scope = null): array
{
    $scope = strtolower(trim((string) $scope));
    $isServerScope = $scope === 'server';
    $disk = eos_disk_space_report();
    $network = eos_network_cached_state();
    $tickets = eos_visible_tickets();
    $openTickets = array_filter($tickets, static fn($ticket) => ($ticket['status'] ?? '') === 'open');
    $onCheckTickets = array_filter($tickets, static fn($ticket) => ($ticket['status'] ?? '') === 'on_check');
    $ticketCounts = [
        'open' => count($openTickets),
        'on_check' => count($onCheckTickets),
    ];

    $modules = eos_controller_modules($disk, $network, $ticketCounts);
    $controller = eos_controller_state();
    $runtime = eos_runtime_identity();
    $networkMap = [];
    if (!$isServerScope) {
        $networkMap = eos_network_targets_by_key($network);
    }
    $cameras = $isServerScope
        ? []
        : array_map(static function ($camera) use ($networkMap) {
            $network = eos_network_device_status($networkMap, eos_network_device_key_camera($camera));
            $camera['status'] = $network['status'] ?? 'unknown';
            $camera['latency'] = $network['latency'] ?? null;
            return $camera;
        }, (array) eos_config('devices.cameras', []));
    $gates = $isServerScope
        ? []
        : array_map(static function ($gate) use ($networkMap) {
            $barrierKey = 'barrier_' . strtolower((string) $gate['id']);
            $timbanganKey = 'timbangan_' . strtolower((string) $gate['id']);
            $gate['barrier_status'] = $networkMap[$barrierKey]['status'] ?? 'unknown';
            $gate['barrier_latency'] = $networkMap[$barrierKey]['latency'] ?? null;
            $gate['timbangan_status'] = $networkMap[$timbanganKey]['status'] ?? 'unknown';
            $gate['timbangan_latency'] = $networkMap[$timbanganKey]['latency'] ?? null;
            return $gate;
        }, (array) eos_config('devices.gates', []));
    return [
        'app_name' => eos_config('app_name'),
        'board_name' => 'EOS CONTROL BOARD',
        'board_mode' => 'MICROCONTROLLER',
        'server_time' => date('Y-m-d H:i:s'),
        'user' => eos_current_user(),
        'runtime' => $runtime,
        'disk' => $disk,
        'network' => $network,
        'pools' => $isServerScope ? [] : eos_config('iis.app_pools', []),
        'groups' => $isServerScope ? [] : array_keys(eos_config('iis.restart_groups', [])),
        'gates' => $isServerScope ? [] : eos_config('images.gates', []),
        'devices' => [
            'cameras' => $cameras,
            'gates' => $gates,
        ],
        'auth' => [
            'username' => eos_current_user(),
            'role' => eos_current_user_role(),
            'site' => eos_current_user_site(),
            'is_admin' => eos_is_admin(),
            'sites' => eos_ticket_sites(),
        ],
        'tickets' => [
            'open' => $ticketCounts['open'],
            'on_check' => $ticketCounts['on_check'],
            'recent' => $isServerScope ? [] : array_slice($tickets, 0, 8),
        ],
        'modules' => $modules,
        'controller' => $controller,
        'board' => [
            'firmware' => 'EOS-FW 1.0',
            'uptime_hint' => $isServerScope ? 'Polling 5s / no auto logs' : 'Polling 3s / logs 10s / disk 30s',
            'bus_state' => eos_controller_bus_state($modules),
            'module_count' => count($modules),
        ],
        'activity_logs' => $isServerScope ? [] : eos_tail(eos_config('paths.app_log'), 12),
        'telegram_logs' => $isServerScope ? [] : eos_tail(eos_config('paths.telegram_log'), 12),
        'network_logs' => $isServerScope ? [] : eos_tail(eos_config('paths.network_log'), 12),
    ];
}

function eos_controller_modules(?array $disk = null, ?array $network = null, ?array $ticketCounts = null): array
{
    $disk = $disk ?: eos_disk_space_report();
    $network = $network ?: eos_network_cached_state();
    $telegramReady = eos_config('telegram.bot_token', '') !== '' && count(eos_config('telegram.chat_ids', [])) > 0;
    $imageRoots = eos_config('images.roots', []);
    $statePath = eos_config('disk.state_file');
    $stateExists = is_file($statePath);
    $networkTargets = $network['targets'] ?? [];
    $networkOnline = 0;
    foreach ($networkTargets as $target) {
        if (($target['status'] ?? '') === 'online') {
            $networkOnline++;
        }
    }
    $ticketOpen = (int) (($ticketCounts['open'] ?? null) ?? 0);
    $ticketCheck = (int) (($ticketCounts['on_check'] ?? null) ?? 0);

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
        [
            'key' => 'TKT',
            'label' => 'TICKET BUS',
            'status' => $ticketOpen > 0 ? 'warning' : ($ticketCheck > 0 ? 'standby' : 'ready'),
            'led' => $ticketOpen > 0 ? '#f97316' : ($ticketCheck > 0 ? '#eab308' : '#22c55e'),
            'description' => 'Pelacakan trouble, on check, dan tiket selesai berbasis log file.',
            'meta' => $ticketOpen . ' open / ' . $ticketCheck . ' on check',
        ],
        [
            'key' => 'NET',
            'label' => 'NET BUS',
            'status' => $network['overall'] ?? 'standby',
            'led' => ($network['overall'] ?? 'standby') === 'ready'
                ? '#27d3a2'
                : (($network['overall'] ?? 'standby') === 'warning' ? '#f6c14b' : (($network['overall'] ?? 'standby') === 'fault' ? '#ff6b6b' : '#eab308')),
            'description' => 'Cek reachability server, kamera, dan domain operasional.',
            'meta' => $networkOnline . '/' . count(eos_network_targets()) . ' target online',
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
        eos_log_event('CTRL', 'arm', 'INFO', ['state' => 'armed'], $actor);
        return ['ok' => true, 'message' => 'Controller armed.', 'state' => eos_controller_save_state($state)];
    }

    if ($cmd === 'reset' || $cmd === 'disarm') {
        $state['armed'] = false;
        $state['last_command'] = $cmd;
        $state['last_target'] = '-';
        $state['last_result'] = 'disarmed';
        eos_log('Controller disarm/reset oleh ' . $actor);
        eos_log_event('CTRL', $cmd, 'INFO', ['state' => 'disarmed'], $actor);
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
    $replyMessageId = $message['message_id'] ?? null;
    $actorContext = eos_telegram_actor_context($message);
    eos_begin_impersonation($actorContext['username'], $actorContext['role'], $actorContext['site']);

    try {
        return eos_telegram_process_update_as_actor($text, $lower, $chatId, $replyMessageId, $message, $actorContext);
    } finally {
        eos_end_impersonation();
    }
}

function eos_telegram_process_update_as_actor(string $text, string $lower, string $chatId, $replyMessageId, array $message, array $actorContext): array
{
    $text = eos_telegram_normalize_command_text($text);
    $lower = strtolower($text);

    if ($lower === '/start' || $lower === '/help') {
        eos_send_telegram(
            eos_telegram_with_identity("EOS Tools siap.\nPerintah:\n/disk\n/network\n/health\n/restart <POOL>\n/restart-group <GROUP>\n/iis\n/ticket <kendala>\n/ticket <SITE> | <kendala>\n/tickets\n/ticket-report <YYYY-MM>\n/ticket-day [YYYY-MM-DD]\n\nAlur cepat tiket:\n1. buat tiket dengan /ticket ...\n2. reply balasan tiket dengan: on proses\n3. reply lagi dengan: done catatan..."),
            [$chatId],
            ['reply_to_message_id' => $replyMessageId]
        );
        return ['handled' => true, 'command' => 'help'];
    }

    if (preg_match('/^\/ticket-day(?:\s+(\d{4}-\d{2}-\d{2}))?$/i', trim($text), $match)) {
        $date = $match[1] ?? date('Y-m-d');
        eos_send_telegram(
            eos_telegram_with_identity(eos_telegram_ticket_daily_summary_message($date)),
            [$chatId],
            ['reply_to_message_id' => $replyMessageId]
        );
        return ['handled' => true, 'command' => 'ticket_day'];
    }

    $replyText = trim((string) (($message['reply_to_message']['text'] ?? $message['reply_to_message']['caption'] ?? '')));
    $repliedTicketId = $replyText !== '' ? eos_extract_ticket_id_from_text($replyText) : null;

    if ($repliedTicketId !== null) {
        if (eos_telegram_reply_is_on_process($text)) {
            $result = eos_mark_ticket_on_check_by_actor($repliedTicketId);
            $messageText = $result['ok']
                ? eos_telegram_ticket_status_message($repliedTicketId, 'on_check')
                : '⚠️ ' . $result['message'];
            eos_send_telegram(
                eos_telegram_with_identity($messageText),
                [$chatId],
                ['reply_to_message_id' => $replyMessageId]
            );
            return ['handled' => true, 'command' => 'ticket_reply_on_check'];
        }

        $doneNote = eos_telegram_reply_done_note($text);
        if ($doneNote !== null) {
            $note = $doneNote;
            $result = eos_mark_ticket_done_by_actor($repliedTicketId, $note);
            $messageText = $result['ok']
                ? eos_telegram_ticket_status_message($repliedTicketId, 'done')
                : '⚠️ ' . $result['message'];
            eos_send_telegram(
                eos_telegram_with_identity($messageText),
                [$chatId],
                ['reply_to_message_id' => $replyMessageId]
            );
            return ['handled' => true, 'command' => 'ticket_reply_done'];
        }
    }

    if (eos_telegram_is_addressed($text, $message)) {
        $ticketSummaryIntent = eos_telegram_natural_ticket_summary_intent($lower);
        if ($ticketSummaryIntent !== null) {
            if (($ticketSummaryIntent['type'] ?? '') === 'active') {
                $activeTickets = eos_visible_active_tickets(12);
                $messageText = "🎫 <b>Tiket Aktif</b>\n" . eos_format_plain(eos_telegram_visible_tickets_text($activeTickets, 10));
            } elseif (($ticketSummaryIntent['type'] ?? '') === 'month') {
                $monthValue = (string) $ticketSummaryIntent['value'];
                $rows = eos_ticket_monthly_report($monthValue);
                $messageText = "🗓️ <b>Ticket Report {$monthValue}</b>\n" . eos_format_plain(eos_telegram_visible_tickets_text(array_map(static function ($row) {
                    return [
                        'ticket_id' => $row['ticket_id'],
                        'site' => $row['site'],
                        'status' => $row['status'],
                        'issue' => $row['issue'] . ' | ' . ($row['repair_duration'] ?: '-') . ' | ' . ($row['note'] ?: '-'),
                    ];
                }, $rows), 10));
            } else {
                $messageText = eos_telegram_ticket_daily_summary_message((string) $ticketSummaryIntent['value']);
            }
            eos_send_telegram(
                eos_telegram_with_identity($messageText),
                [$chatId],
                ['reply_to_message_id' => $replyMessageId]
            );
            return ['handled' => true, 'command' => 'ticket_natural_summary'];
        }

        $naturalTicket = eos_telegram_natural_ticket_create($text, $lower, $actorContext);
        if ($naturalTicket !== null) {
            $result = eos_create_ticket(date('Y-m-d H:i:s'), (string) $naturalTicket['site'], (string) $naturalTicket['issue']);
            $messageText = $result['ok']
                ? eos_telegram_ticket_created_message((string) $result['ticket_id'])
                : '⚠️ ' . $result['message'];
            eos_send_telegram(
                eos_telegram_with_identity($messageText),
                [$chatId],
                ['reply_to_message_id' => $replyMessageId]
            );
            return ['handled' => true, 'command' => 'ticket_natural_create'];
        }
    }

    if (preg_match('/^\/ticket-report(?:\s+(\d{4}-\d{2}))?$/i', trim($text), $match)) {
        $month = $match[1] ?? date('Y-m');
        $rows = eos_ticket_monthly_report($month);
        eos_send_telegram(
            eos_telegram_with_identity("🗓️ <b>Ticket Report {$month}</b>\n" . eos_format_plain(eos_telegram_visible_tickets_text(array_map(static function ($row) {
                return [
                    'ticket_id' => $row['ticket_id'],
                    'site' => $row['site'],
                    'status' => $row['status'],
                    'issue' => $row['issue'] . ' | ' . ($row['repair_duration'] ?: '-') . ' | ' . ($row['note'] ?: '-'),
                ];
            }, $rows), 10))),
            [$chatId],
            ['reply_to_message_id' => $replyMessageId]
        );
        return ['handled' => true, 'command' => 'ticket_report'];
    }

    if ($lower === '/tickets' || $lower === '/ticket-list') {
        $tickets = eos_visible_tickets(12);
        eos_send_telegram(
            eos_telegram_with_identity("🎫 <b>Ticket Board</b>\n" . eos_format_plain(eos_telegram_visible_tickets_text($tickets))),
            [$chatId],
            ['reply_to_message_id' => $replyMessageId]
        );
        return ['handled' => true, 'command' => 'tickets'];
    }

    if (preg_match('/^\/ticket\s+(.+)$/is', trim($text), $match)) {
        $input = trim($match[1]);
        $site = $actorContext['role'] === 'admin' ? 'SERVER' : (string) ($actorContext['site'] ?? 'SERVER');
        $issue = $input;
        if (strpos($input, '|') !== false) {
            [$left, $right] = array_map('trim', explode('|', $input, 2));
            if (($actorContext['role'] ?? 'eos') === 'admin' && $left !== '' && $right !== '') {
                $site = strtoupper($left);
                $issue = $right;
            } elseif ($right !== '') {
                $issue = $right;
            }
        }

        $result = eos_create_ticket(date('Y-m-d H:i:s'), $site, $issue);
        $messageText = $result['ok']
            ? eos_telegram_ticket_created_message((string) $result['ticket_id'])
            : '⚠️ ' . $result['message'];
        eos_send_telegram(
            eos_telegram_with_identity($messageText),
            [$chatId],
            ['reply_to_message_id' => $replyMessageId]
        );
        return ['handled' => true, 'command' => 'ticket_create'];
    }

    if ($lower === '/disk' || strpos($lower, 'disk') !== false) {
        $disk = eos_monitor_disk(true);
        if (!($disk['ok'] ?? false)) {
            eos_send_telegram(
                eos_telegram_with_identity("📦 <b>Disk Report</b>\nStatus: <b>GAGAL DIBACA</b>\nDetail: " . eos_format_plain((string) ($disk['message'] ?? 'Unknown error'))),
                [$chatId],
                ['reply_to_message_id' => $replyMessageId]
            );
            return ['handled' => true, 'command' => 'disk'];
        }
        eos_send_telegram(
            eos_telegram_with_identity("📦 <b>Disk Report</b>\nDrive: <b>{$disk['drive']}</b>\nFree: <b>{$disk['free_human']}</b> ({$disk['free_percent']}%)\nUsed: {$disk['used_human']} ({$disk['used_percent']}%)\nStatus: <b>" . strtoupper($disk['status']) . '</b>'),
            [$chatId],
            ['reply_to_message_id' => $replyMessageId]
        );
        return ['handled' => true, 'command' => 'disk'];
    }

    if ($lower === '/network' || $lower === '/net') {
        $network = eos_network_monitor(true);
        $online = 0;
        $offlineTargets = [];
        foreach (($network['targets'] ?? []) as $target) {
            if (($target['status'] ?? '') === 'online') {
                $online++;
            } else {
                $offlineTargets[] = eos_telegram_target_status_snippet($target);
            }
        }
        $detail = $offlineTargets
            ? "\nPerlu dicek: " . eos_format_plain(implode('; ', array_slice($offlineTargets, 0, 4)))
            : "\nSemua target terpantau online.";
        eos_send_telegram(
            eos_telegram_with_identity("🌐 <b>Network Report</b>\nStatus bus: <b>" . strtoupper((string) $network['overall']) . "</b>\nTarget online: <b>{$online}/" . count($network['targets'] ?? []) . "</b>{$detail}"),
            [$chatId],
            ['reply_to_message_id' => $replyMessageId]
        );
        return ['handled' => true, 'command' => 'network'];
    }

    if ($lower === '/health') {
        $summary = eos_dashboard_summary('server');
        $disk = $summary['disk'];
        eos_send_telegram(
            eos_telegram_with_identity("🩺 <b>EOS Tools Health</b>\nTime: {$summary['server_time']}\nDisk C Free: {$disk['free_human']} ({$disk['free_percent']}%)\nJumlah Pool: " . count($summary['pools'])),
            [$chatId],
            ['reply_to_message_id' => $replyMessageId]
        );
        return ['handled' => true, 'command' => 'health'];
    }

    if (preg_match('/^\/restart\s+([A-Za-z0-9]+)/', $text, $match)) {
        $result = eos_restart_app_pool($match[1], 'Dari Telegram', 'telegram');
        eos_send_telegram(
            eos_telegram_with_identity(($result['ok'] ? '✅' : '⚠️') . ' Restart pool ' . strtoupper($match[1]) . ': ' . $result['message']),
            [$chatId],
            ['reply_to_message_id' => $replyMessageId]
        );
        return ['handled' => true, 'command' => 'restart_pool'];
    }

    if (preg_match('/^\/restart-group\s+([A-Za-z0-9_-]+)/', $text, $match)) {
        $result = eos_restart_group($match[1], 'Dari Telegram', 'telegram');
        eos_send_telegram(
            eos_telegram_with_identity(($result['ok'] ? '✅' : '⚠️') . ' Restart group ' . strtoupper($match[1]) . ': ' . $result['message']),
            [$chatId],
            ['reply_to_message_id' => $replyMessageId]
        );
        return ['handled' => true, 'command' => 'restart_group'];
    }

    if ($lower === '/iis') {
        $result = eos_restart_iis('Perintah Telegram', 'telegram');
        eos_send_telegram(
            eos_telegram_with_identity(($result['ok'] ? '✅' : '⚠️') . ' Restart IIS: ' . $result['message']),
            [$chatId],
            ['reply_to_message_id' => $replyMessageId]
        );
        return ['handled' => true, 'command' => 'restart_iis'];
    }

    if (eos_telegram_is_addressed($text, $message)) {
        $reply = eos_telegram_human_reply($text, $message);
        if ($reply) {
            eos_send_telegram(eos_telegram_with_identity($reply), [$chatId], ['reply_to_message_id' => $replyMessageId]);
            eos_log_event('TG', 'chat_reply', 'INFO', [
                'chat_id' => $chatId,
                'message' => $text,
            ], 'telegram', 'telegram');
            return ['handled' => true, 'command' => 'chat_reply'];
        }
    }

    eos_send_telegram(eos_telegram_with_identity('Perintah tidak dikenali. Kirim /help untuk daftar perintah.'), [$chatId], ['reply_to_message_id' => $replyMessageId]);
    return ['handled' => true, 'command' => 'unknown'];
}

function eos_telegram_poll_once(): array
{
    $token = (string) eos_config('telegram.bot_token', '');
    if ($token === '') {
        return ['ok' => false, 'message' => 'Bot token belum diisi.'];
    }

    $stateFile = eos_config('telegram.poll_state_file');
    $state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
    $offset = (int) ($state['offset'] ?? 0);

    $query = 'https://api.telegram.org/bot' . $token . '/getUpdates?timeout=1&offset=' . $offset . '&limit=25';
    $raw = @file_get_contents($query);
    $json = json_decode((string) $raw, true);

    if (!is_array($json) || !($json['ok'] ?? false)) {
        return ['ok' => false, 'message' => 'Gagal mengambil update Telegram.', 'raw' => $raw];
    }

    $handled = [];
    $maxOffset = $offset;
    foreach (($json['result'] ?? []) as $update) {
        $handled[] = eos_telegram_process_update($update);
        $maxOffset = max($maxOffset, ((int) ($update['update_id'] ?? 0)) + 1);
    }

    file_put_contents($stateFile, json_encode([
        'offset' => $maxOffset,
        'updated_at' => date('c'),
    ], JSON_PRETTY_PRINT));

    return [
        'ok' => true,
        'handled' => $handled,
        'next_offset' => $maxOffset,
        'count' => count($handled),
        'updated_at' => date('c'),
    ];
}
