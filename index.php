<?php
require __DIR__ . '/bootstrap.php';

if (isset($_GET['logout'])) {
    eos_log('Logout berhasil.');
    eos_log_event('AUTH', 'logout', 'INFO', [], eos_current_user() ?: 'system');
    eos_logout();
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (eos_login($username, $password)) {
        eos_log('Login berhasil.', 'app', $username);
        eos_log_event('AUTH', 'login', 'INFO', [], $username);
        header('Location: index.php');
        exit;
    }
    $loginError = 'Username atau password salah.';
}

if (isset($_GET['api'])) {
    $api = (string) $_GET['api'];
    eos_require_login();

    $readInt = static function ($value, int $default = 0, int $max = 200): int {
        $v = (int) $value;
        if ($v <= 0) {
            return $default;
        }
        return min($v, $max);
    };

    $requirePost = static function () {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            eos_json(['ok' => false, 'message' => 'Method tidak valid.'], 405);
        }
    };

    $sendResult = static function (array $result): void {
        eos_json($result, ($result['ok'] ?? false) ? 200 : 422);
    };

    switch ($api) {
        case 'summary':
            $scope = strtolower((string) ($_GET['scope'] ?? 'user'));
            eos_json(['ok' => true, 'data' => eos_dashboard_summary($scope)]);
            break;

        case 'logs':
            $logLimit = $readInt($_GET['limit'] ?? 0, 80, 300);
            $type = $_GET['type'] ?? 'app';
            $file = $type === 'telegram'
                ? eos_config('paths.telegram_log')
                : ($type === 'network' ? eos_config('paths.network_log') : eos_config('paths.app_log'));
            eos_json(['ok' => true, 'logs' => eos_tail($file, $logLimit)]);
            break;

        case 'restart_pool':
            $requirePost();
            $result = eos_restart_app_pool((string) ($_POST['pool'] ?? ''), trim((string) ($_POST['note'] ?? '')));
            $sendResult($result);
            break;

        case 'restart_group':
            $requirePost();
            $result = eos_restart_group((string) ($_POST['group'] ?? ''), trim((string) ($_POST['note'] ?? '')));
            $sendResult($result);
            break;

        case 'restart_iis':
            $requirePost();
            $result = eos_restart_iis(trim((string) ($_POST['reason'] ?? '')));
            $sendResult($result);
            break;

        case 'disk':
            eos_json(['ok' => true, 'data' => eos_disk_space_report()]);
            break;

        case 'monitor_disk':
            $report = eos_monitor_disk(isset($_GET['notify']));
            eos_json(['ok' => true, 'data' => $report]);
            break;

        case 'network':
            $report = eos_network_monitor(isset($_GET['log']));
            eos_json(['ok' => true, 'data' => $report]);
            break;

        case 'find_images':
            $requirePost();
            $result = eos_find_backup_images((string) ($_POST['gate'] ?? ''), (string) ($_POST['datetime'] ?? ''));
            $sendResult($result);
            break;

        case 'test_telegram':
            eos_send_telegram(
                "🔔 <b>Test EOS Tools</b>\nUser: <b>" . eos_format_plain((string) eos_current_user()) . "</b>\nTime: " . date('Y-m-d H:i:s')
            );
            eos_log_event('TG', 'manual_test', 'INFO', [], (string) eos_current_user(), 'telegram');
            eos_json(['ok' => true, 'message' => 'Pesan test Telegram dikirim.']);
            break;

        case 'telegram_poll':
            $result = eos_telegram_poll_once();
            eos_json($result, $result['ok'] ? 200 : 502);
            break;

        case 'tickets':
            $ticketLimit = $readInt($_GET['limit'] ?? 0, 0, 200);
            $tickets = eos_visible_tickets();
            if ($ticketLimit > 0) {
                $tickets = array_slice($tickets, 0, $ticketLimit);
            }
            eos_json(['ok' => true, 'data' => $tickets]);
            break;

        case 'ticket_create':
            $requirePost();
            $result = eos_create_ticket(
                (string) ($_POST['issue_time'] ?? ''),
                (string) ($_POST['site'] ?? ''),
                (string) ($_POST['issue'] ?? '')
            );
            $sendResult($result);
            break;

        case 'ticket_on_check':
            $requirePost();
            $result = eos_mark_ticket_on_check((string) ($_POST['ticket_id'] ?? ''));
            $sendResult($result);
            break;

        case 'ticket_done':
            $requirePost();
            $result = eos_mark_ticket_done((string) ($_POST['ticket_id'] ?? ''), (string) ($_POST['note'] ?? ''));
            $sendResult($result);
            break;

        case 'ticket_report':
            $month = (string) ($_GET['month'] ?? date('Y-m'));
            eos_json(['ok' => true, 'data' => eos_ticket_monthly_report($month)]);
            break;

        case 'users':
            eos_require_admin();
            $userLimit = $readInt($_GET['limit'] ?? 0, 0, 500);
            $users = eos_user_list_public();
            if ($userLimit > 0) {
                $users = array_slice($users, 0, $userLimit);
            }
            eos_json(['ok' => true, 'data' => $users]);
            break;

        case 'user_create':
            $requirePost();
            $result = eos_create_user(
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['role'] ?? 'eos'),
                (string) ($_POST['site'] ?? 'SERVER')
            );
            $sendResult($result);
            break;

        case 'user_update':
            $requirePost();
            $active = isset($_POST['active']) ? ((string) $_POST['active'] === '1') : null;
            $result = eos_update_user(
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['role'] ?? 'eos'),
                (string) ($_POST['site'] ?? 'SERVER'),
                (string) ($_POST['password'] ?? ''),
                $active
            );
            $sendResult($result);
            break;

        case 'user_delete':
            $requirePost();
            $result = eos_delete_user((string) ($_POST['username'] ?? ''));
            $sendResult($result);
            break;
    }

    eos_json(['ok' => false, 'message' => 'API tidak ditemukan.'], 404);
}

if (!eos_current_user()):
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | EOS Tools</title>
    <style>
        :root{
            --bg:#041016;
            --panel:#091a22;
            --line:#1b3c48;
            --text:#d9f3fb;
            --muted:#79a8b8;
            --accent:#27d3a2;
            --accent2:#25a7ff;
            --danger:#ff6b6b;
        }
        *{box-sizing:border-box}
        body{
            margin:0;
            min-height:100vh;
            display:grid;
            place-items:center;
            color:var(--text);
            font-family:Consolas, Monaco, monospace;
            background:
                radial-gradient(circle at 15% 20%, rgba(39,211,162,.16), transparent 24%),
                radial-gradient(circle at 85% 15%, rgba(37,167,255,.18), transparent 22%),
                linear-gradient(180deg, #02070a 0%, #07131a 100%);
        }
        .login-card{
            width:min(430px, calc(100vw - 32px));
            background:linear-gradient(180deg, rgba(9,26,34,.96), rgba(5,16,22,.94));
            border:1px solid var(--line);
            border-radius:28px;
            padding:32px;
            box-shadow:0 30px 90px rgba(0,0,0,.45);
        }
        .micro-label{
            display:inline-flex;
            gap:8px;
            align-items:center;
            margin-bottom:12px;
            padding:7px 12px;
            border:1px solid #1f5564;
            border-radius:999px;
            color:#a3f7df;
            font-size:12px;
            letter-spacing:.16em;
            text-transform:uppercase;
        }
        .micro-dot{width:9px;height:9px;border-radius:50%;background:var(--accent);box-shadow:0 0 14px var(--accent)}
        h1{margin:0 0 8px;font-size:34px}
        p{margin:0 0 24px;color:var(--muted);line-height:1.6}
        label{display:block;margin:14px 0 8px;color:#a5dbe8}
        input{
            width:100%;
            border:1px solid #214957;
            border-radius:14px;
            padding:14px 16px;
            background:#04121a;
            color:var(--text);
            font:inherit;
        }
        input:focus{
            outline:none;
            border-color:var(--accent2);
            box-shadow:0 0 0 4px rgba(37,167,255,.12);
        }
        button{
            width:100%;
            margin-top:20px;
            border:none;
            border-radius:14px;
            background:linear-gradient(135deg, #27d3a2, #25a7ff);
            color:#041016;
            padding:14px 16px;
            font:700 15px Consolas, Monaco, monospace;
            cursor:pointer;
        }
        .error{
            margin-top:14px;
            padding:12px 14px;
            border-radius:12px;
            background:rgba(255,107,107,.12);
            color:#ffc5c5;
            border:1px solid rgba(255,107,107,.34);
        }
    </style>
</head>
<body>
    <form class="login-card" method="post">
        <input type="hidden" name="action" value="login">
        <div class="micro-label"><span class="micro-dot"></span> booting microcontroller board</div>
        <h1>EOS Tools</h1>
        <p>Panel kontrol operasional dengan konsep mikrocontroller: cepat, modular, dan fokus ke aksi inti.</p>
        <label for="username">USER</label>
        <input id="username" name="username" required autofocus>
        <label for="password">PASSKEY</label>
        <input id="password" type="password" name="password" required>
        <button type="submit">INIT CONTROL BOARD</button>
        <?php if (!empty($loginError)): ?>
            <div class="error"><?= eos_h($loginError) ?></div>
        <?php endif; ?>
    </form>
</body>
</html>
<?php
exit;
endif;

$summary = eos_dashboard_summary();
$auth = $summary['auth'] ?? [];
$isAdmin = (bool) ($auth['is_admin'] ?? false);
$siteOptions = (array) ($auth['sites'] ?? []);
$lockedSite = (string) ($auth['site'] ?? 'SERVER');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EOS Tools Control Board</title>
    <style>
        :root{
            --bg:#02080d;
            --bg2:#07131a;
            --panel:#08151d;
            --panel-2:#0b1c25;
            --line:#163745;
            --line-soft:#0f2a35;
            --text:#d5f2fb;
            --muted:#7ea3b4;
            --green:#27d3a2;
            --blue:#25a7ff;
            --yellow:#f6c14b;
            --red:#ff6b6b;
            --white:#e8fbff;
            --shadow:0 18px 40px rgba(0,0,0,.38);
        }
        *{box-sizing:border-box}
        html{scroll-behavior:smooth}
        body{
            margin:0;
            color:var(--text);
            font-family:Consolas, Monaco, monospace;
            background:
                radial-gradient(circle at 10% 12%, rgba(39,211,162,.12), transparent 18%),
                radial-gradient(circle at 88% 8%, rgba(37,167,255,.12), transparent 16%),
                linear-gradient(180deg, var(--bg) 0%, var(--bg2) 100%);
        }
        .shell{max-width:1480px;margin:0 auto;padding:22px}
        .board{
            position:relative;
            border:1px solid var(--line);
            border-radius:30px;
            background:
                linear-gradient(180deg, rgba(8,21,29,.98), rgba(4,12,16,.96)),
                repeating-linear-gradient(90deg, transparent 0, transparent 49px, rgba(37,167,255,.02) 50px),
                repeating-linear-gradient(0deg, transparent 0, transparent 49px, rgba(39,211,162,.02) 50px);
            box-shadow:var(--shadow);
            overflow:hidden;
        }
        .board:before{
            content:"";
            position:absolute;
            inset:0;
            background:
                radial-gradient(circle at 20px 20px, rgba(255,255,255,.05) 0 2px, transparent 2px) 0 0/44px 44px;
            pointer-events:none;
            opacity:.5;
        }
        .topbar{
            position:relative;
            z-index:1;
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:18px;
            flex-wrap:wrap;
            padding:28px;
            border-bottom:1px solid var(--line-soft);
        }
        .chip-label{
            display:inline-flex;
            align-items:center;
            gap:10px;
            margin-bottom:14px;
            padding:8px 14px;
            border-radius:999px;
            border:1px solid #215466;
            color:#a4f4dd;
            font-size:12px;
            text-transform:uppercase;
            letter-spacing:.16em;
        }
        .pulse{
            width:10px;height:10px;border-radius:50%;background:var(--green);
            box-shadow:0 0 18px var(--green);
            animation:pulse 1.6s infinite;
        }
        @keyframes pulse{
            0%,100%{transform:scale(1);opacity:1}
            50%{transform:scale(.75);opacity:.7}
        }
        h1{margin:0;font-size:38px;letter-spacing:.04em}
        .subtitle{margin:12px 0 0;color:var(--muted);max-width:760px;line-height:1.7}
        .top-meta{
            display:grid;
            grid-template-columns:repeat(2, minmax(180px,1fr));
            gap:12px;
            min-width:min(100%,420px);
        }
        .meta-card{
            border:1px solid var(--line);
            border-radius:18px;
            background:rgba(11,28,37,.92);
            padding:14px 16px;
        }
        .meta-card .k{font-size:11px;color:var(--muted);letter-spacing:.12em;text-transform:uppercase}
        .meta-card .v{margin-top:8px;font-size:18px;color:var(--white);font-weight:700}
        .logout{
            text-decoration:none;
            color:#08151d;
            background:linear-gradient(135deg, #ff8c8c, #ff6b6b);
            padding:13px 16px;
            border-radius:14px;
            font-weight:700;
        }
        .runtime-stack{
            display:flex;
            flex-direction:column;
            gap:12px;
            align-items:stretch;
            min-width:min(100%, 360px);
        }
        .mode-card{
            border:1px solid var(--line);
            border-radius:20px;
            background:rgba(11,28,37,.92);
            padding:14px 16px;
        }
        .mode-card-head{
            display:flex;
            justify-content:space-between;
            gap:12px;
            align-items:center;
            flex-wrap:wrap;
        }
        .mode-card-head strong{
            color:var(--white);
            letter-spacing:.08em;
            font-size:13px;
            text-transform:uppercase;
        }
        .mode-card p{
            margin:10px 0 0;
            color:#9ec7d4;
            font-size:12px;
            line-height:1.6;
        }
        .toggle-row{
            display:inline-flex;
            gap:8px;
            padding:6px;
            border-radius:999px;
            border:1px solid #173846;
            background:#06131a;
        }
        .toggle-btn{
            border:none;
            border-radius:999px;
            padding:10px 14px;
            background:transparent;
            color:#9ec7d4;
            font:700 12px Consolas, Monaco, monospace;
            letter-spacing:.08em;
            text-transform:uppercase;
            cursor:pointer;
        }
        .toggle-btn.active{
            color:#041016;
            background:linear-gradient(135deg, #27d3a2, #25a7ff);
            box-shadow:0 10px 24px rgba(37,167,255,.2);
        }
        .toggle-status{
            display:flex;
            gap:8px;
            flex-wrap:wrap;
            margin-top:12px;
        }
        .toggle-pill{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:7px 12px;
            border-radius:999px;
            border:1px solid #173846;
            background:#06131a;
            color:#bde8f3;
            font-size:11px;
            text-transform:uppercase;
            letter-spacing:.08em;
        }
        .content{position:relative;z-index:1;padding:24px}
        .nav-shell{
            display:grid;
            grid-template-columns:240px 1fr;
            gap:18px;
            align-items:start;
        }
        .sidebar{
            position:sticky;
            top:18px;
            border:1px solid var(--line);
            border-radius:24px;
            background:linear-gradient(180deg, rgba(10,24,32,.96), rgba(7,18,24,.95));
            padding:16px;
            box-shadow:var(--shadow);
        }
        .sidebar h3{
            margin:0 0 12px;
            font-size:14px;
            color:#9fd8e7;
            letter-spacing:.12em;
            text-transform:uppercase;
        }
        .menu-list{
            display:grid;
            gap:10px;
        }
        .menu-link{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:10px;
            text-decoration:none;
            color:#d5f2fb;
            padding:12px 14px;
            border-radius:16px;
            border:1px solid #173846;
            background:#06131a;
            font-size:13px;
        }
        .menu-link:hover{
            border-color:#25a7ff;
            background:#091923;
        }
        .menu-link.active{
            border-color:#27d3a2;
            background:linear-gradient(135deg, rgba(39,211,162,.18), rgba(37,167,255,.14));
            color:#ffffff;
            box-shadow:inset 0 0 0 1px rgba(39,211,162,.12);
        }
        .menu-link span:last-child{
            color:#7ec9da;
            font-size:11px;
        }
        .main-panels{
            display:grid;
            gap:16px;
        }
        .top-menu{
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            margin-bottom:16px;
        }
        .top-menu a{
            text-decoration:none;
            color:#cdeef7;
            border:1px solid #173846;
            background:#06131a;
            border-radius:999px;
            padding:9px 14px;
            font-size:12px;
        }
        .top-menu a:hover{
            border-color:#27d3a2;
            color:#ffffff;
        }
        .top-menu a.active{
            border-color:#27d3a2;
            color:#ffffff;
            background:linear-gradient(135deg, rgba(39,211,162,.18), rgba(37,167,255,.14));
        }
        .section-anchor{
            scroll-margin-top:18px;
        }
        .status-grid{
            display:grid;
            grid-template-columns:repeat(6, minmax(0,1fr));
            gap:14px;
        }
        .module-card{
            position:relative;
            border:1px solid var(--line);
            border-radius:22px;
            background:linear-gradient(180deg, rgba(11,28,37,.98), rgba(7,19,26,.98));
            padding:18px;
            min-height:170px;
        }
        .module-head{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:10px;
        }
        .led{
            width:13px;height:13px;border-radius:50%;
            box-shadow:0 0 14px currentColor;
        }
        .module-key{font-size:24px;font-weight:700;letter-spacing:.08em}
        .module-label{margin-top:6px;color:var(--muted);font-size:12px;letter-spacing:.12em;text-transform:uppercase}
        .module-desc{margin-top:12px;color:#a9d4e1;font-size:13px;line-height:1.6}
        .module-meta{margin-top:18px;color:var(--white);font-size:13px}
        .busbar{
            margin:18px 0;
            display:flex;
            align-items:center;
            gap:14px;
            padding:14px 18px;
            border:1px solid var(--line);
            border-radius:18px;
            background:rgba(6,16,22,.84);
        }
        .bus-pill{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:8px 12px;
            border-radius:999px;
            border:1px solid var(--line);
            font-size:12px;
            color:#b8e4f0;
        }
        .board-grid{
            display:grid;
            grid-template-columns:1.15fr .95fr .9fr;
            gap:16px;
        }
        .panel{
            border:1px solid var(--line);
            border-radius:24px;
            background:linear-gradient(180deg, rgba(10,24,32,.96), rgba(7,18,24,.95));
            padding:20px;
            box-shadow:var(--shadow);
        }
        .panel h2,.panel h3{margin:0}
        .panel p{margin:8px 0 0;color:var(--muted);line-height:1.6;font-size:14px}
        .field{margin-top:16px}
        .field label{display:block;margin-bottom:8px;color:#bce4f0;font-size:13px;letter-spacing:.08em}
        input,select,textarea,button{font:inherit}
        input,select,textarea{
            width:100%;
            border:1px solid #1f4654;
            border-radius:14px;
            padding:13px 14px;
            background:#041119;
            color:var(--white);
        }
        textarea{min-height:92px;resize:vertical}
        input:focus,select:focus,textarea:focus{
            outline:none;
            border-color:var(--blue);
            box-shadow:0 0 0 4px rgba(37,167,255,.12);
        }
        .button-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:16px}
        button{
            border:none;
            border-radius:14px;
            padding:13px 14px;
            cursor:pointer;
            font-weight:700;
        }
        .btn-main{background:linear-gradient(135deg, var(--green), var(--blue));color:#051016}
        .btn-soft{background:#0d2a36;color:#bce4f0;border:1px solid #1e4856}
        .btn-warn{background:#2a1807;color:#ffd89b;border:1px solid #75461e}
        .btn-danger{background:#2c0e12;color:#ffb7bc;border:1px solid #7a2a33}
        .quick-switches{
            margin-top:16px;
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:10px;
        }
        .switch-btn{
            display:flex;
            justify-content:space-between;
            align-items:center;
            background:#08131a;
            color:#c8ebf5;
            border:1px solid #173846;
            border-radius:16px;
            padding:13px 14px;
        }
        .switch-btn span:last-child{color:#86cbdc}
        .stack{
            display:grid;
            gap:16px;
        }
        .telemetry{
            display:grid;
            grid-template-columns:repeat(2, minmax(0,1fr));
            gap:12px;
            margin-top:16px;
        }
        .telemetry-box{
            border:1px solid var(--line);
            border-radius:18px;
            background:#06131a;
            padding:15px;
        }
        .telemetry-box .big{font-size:24px;font-weight:700;margin-top:8px}
        .telemetry-box .small{font-size:12px;color:var(--muted);margin-top:8px;line-height:1.5}
        .terminal{
            border:1px solid #133340;
            border-radius:20px;
            background:#02070a;
            min-height:280px;
            padding:16px;
            color:#8ff7d2;
            font-size:13px;
            overflow:auto;
            white-space:pre-wrap;
            box-shadow:inset 0 0 0 1px rgba(39,211,162,.05);
        }
        .hint{
            margin-top:14px;
            padding:14px 16px;
            border:1px dashed #215466;
            border-radius:16px;
            background:rgba(7,19,26,.7);
            color:#8fb7c5;
            line-height:1.6;
            font-size:13px;
        }
        body.dashboard-mode-server .mode-user {
            display:none !important;
        }
        .gallery{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:12px;
            margin-top:16px;
        }
        .gallery-item{
            overflow:hidden;
            border-radius:18px;
            border:1px solid var(--line);
            background:#06131a;
        }
        .gallery-item img{
            width:100%;
            aspect-ratio:4/3;
            object-fit:cover;
            display:block;
            background:#0b1c25;
        }
        .gallery-meta{padding:12px}
        .network-grid{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:12px;
            margin-top:16px;
        }
        .network-card{
            border:1px solid var(--line);
            border-radius:18px;
            background:#06131a;
            padding:14px;
        }
        .network-card .top{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:10px;
        }
        .network-card .name{font-weight:700;color:var(--white)}
        .network-card .endpoint-text{margin-top:8px;font-size:12px;color:#8fb7c5;word-break:break-word}
        .network-card .detail{margin-top:10px;font-size:12px;color:#bcdfea;line-height:1.5}
        .network-card .state{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:6px 10px;
            border-radius:999px;
            font-size:11px;
            border:1px solid #173846;
        }
        .state-dot{width:9px;height:9px;border-radius:50%;box-shadow:0 0 12px currentColor}
        .log-grid{
            margin-top:16px;
            display:grid;
            grid-template-columns:1fr 1fr 1fr .9fr;
            gap:16px;
        }
        .log-box{
            border:1px solid #133340;
            border-radius:18px;
            background:#02070a;
            min-height:240px;
            padding:14px;
            color:#b4dce8;
            font-size:12px;
            white-space:pre-wrap;
            overflow:auto;
        }
        .endpoint{
            margin-top:12px;
            border:1px solid #173846;
            border-radius:14px;
            padding:12px;
            background:#051017;
            color:#bfe8f3;
            font-size:12px;
            line-height:1.6;
        }
        .guide-grid{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:12px;
            margin-top:16px;
        }
        .guide-card{
            border:1px solid var(--line);
            border-radius:18px;
            background:#06131a;
            padding:16px;
        }
        .guide-card h4{
            margin:0 0 10px;
            color:var(--white);
            font-size:15px;
        }
        .guide-card ul{
            margin:0;
            padding-left:18px;
            color:#b8dce8;
            font-size:13px;
            line-height:1.7;
        }
        .guide-card li + li{margin-top:4px}
        .table-wrap{
            margin-top:16px;
            border:1px solid var(--line);
            border-radius:18px;
            overflow:auto;
            background:#06131a;
        }
        table.inventory{
            width:100%;
            border-collapse:collapse;
            font-size:12px;
            color:#c3e8f2;
            min-width:760px;
        }
        table.inventory th,table.inventory td{
            padding:10px 12px;
            border-bottom:1px solid #123543;
            text-align:left;
            vertical-align:top;
        }
        table.inventory th{
            background:#081821;
            color:#8fd3e4;
            text-transform:uppercase;
            letter-spacing:.08em;
            font-size:11px;
        }
        .inventory-status{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:5px 10px;
            border-radius:999px;
            border:1px solid #17404f;
            background:#08161d;
            font-size:11px;
            text-transform:uppercase;
            letter-spacing:.08em;
            white-space:nowrap;
        }
        .inventory-status::before{
            content:'';
            width:8px;
            height:8px;
            border-radius:50%;
            background:currentColor;
            box-shadow:0 0 10px currentColor;
        }
        .inventory-status.online{color:#27d3a2;border-color:#175544}
        .inventory-status.offline{color:#ff7b7b;border-color:#6a2a2a}
        .inventory-status.warning,
        .inventory-status.unknown{color:#f6c14b;border-color:#6c5721}
        .inventory-meta{
            display:block;
            margin-top:4px;
            color:#8dbdca;
            font-size:11px;
        }
        code{color:#a7f3d0}
        @media (max-width: 1240px){
            .status-grid{grid-template-columns:repeat(3, minmax(0,1fr))}
            .board-grid,.log-grid{grid-template-columns:1fr}
            .nav-shell{grid-template-columns:1fr}
            .sidebar{position:static}
        }
        @media (max-width: 760px){
            .shell{padding:12px}
            .topbar,.content{padding:16px}
            .status-grid,.top-meta,.telemetry,.gallery,.button-grid,.quick-switches,.network-grid,.guide-grid{grid-template-columns:1fr}
            h1{font-size:30px}
        }
    </style>
</head>
<body>
    <div class="shell">
        <div class="board">
            <section class="topbar">
                <div>
                    <div class="chip-label"><span class="pulse"></span> <?= eos_h($summary['board_name']) ?> / <?= eos_h($summary['board_mode']) ?></div>
                    <h1>EOS Tools Control Board</h1>
                    <div class="subtitle">Dashboard dibuat seperti papan mikrocontroller: modul kecil, status LED, bus sinkron, dan eksekusi langsung untuk restart, monitoring, Telegram, dan image backup.</div>
                </div>
                <div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap;">
                    <div class="top-meta">
                        <div class="meta-card">
                            <div class="k">Firmware</div>
                            <div class="v"><?= eos_h($summary['board']['firmware']) ?></div>
                        </div>
                        <div class="meta-card">
                            <div class="k">Bus State</div>
                            <div class="v" id="busState"><?= eos_h($summary['board']['bus_state']) ?></div>
                        </div>
                        <div class="meta-card">
                            <div class="k">Operator</div>
                            <div class="v"><?= eos_h((string) $summary['user']) ?></div>
                        </div>
                        <div class="meta-card">
                            <div class="k">RTC</div>
                            <div class="v" id="serverTime"><?= eos_h($summary['server_time']) ?></div>
                        </div>
                        <div class="meta-card">
                            <div class="k">Server Label</div>
                            <div class="v" id="runtimeLabel"><?= eos_h((string) $summary['runtime']['label']) ?></div>
                        </div>
                        <div class="meta-card">
                            <div class="k">Server IP</div>
                            <div class="v" id="runtimeIp"><?= eos_h((string) $summary['runtime']['ip']) ?></div>
                        </div>
                        <div class="meta-card">
                            <div class="k">Controller</div>
                            <div class="v" id="controllerState"><?= $summary['controller']['armed'] ? 'ARMED' : 'DISARMED' ?></div>
                        </div>
                        <div class="meta-card">
                            <div class="k">Last Fire</div>
                            <div class="v" id="controllerLast"><?= eos_h((string) $summary['controller']['last_command']) ?></div>
                        </div>
                        <div class="meta-card">
                            <div class="k">Network Bus</div>
                            <div class="v" id="networkState"><?= strtoupper(eos_h((string) ($summary['network']['overall'] ?? 'standby'))) ?></div>
                        </div>
                        <div class="meta-card">
                            <div class="k">Network Scan</div>
                            <div class="v" id="networkScanTime"><?= eos_h((string) ($summary['network']['updated_at'] ?? '-')) ?></div>
                        </div>
                        <div class="meta-card">
                            <div class="k">Telegram Poll</div>
                            <div class="v" id="telegramPollState">IDLE</div>
                        </div>
                        <div class="meta-card">
                            <div class="k">Telegram Poll Time</div>
                            <div class="v" id="telegramPollTime">-</div>
                        </div>
                        <div class="meta-card">
                            <div class="k">Role</div>
                            <div class="v" id="authRole"><?= strtoupper(eos_h((string) ($auth['role'] ?? '-'))) ?></div>
                        </div>
                        <div class="meta-card">
                            <div class="k">Site Scope</div>
                            <div class="v" id="authSite"><?= eos_h((string) ($auth['site'] ?? '-')) ?></div>
                        </div>
                        <div class="meta-card">
                            <div class="k">Ticket Open</div>
                            <div class="v" id="ticketOpenCount"><?= eos_h((string) ($summary['tickets']['open'] ?? 0)) ?></div>
                        </div>
                        <div class="meta-card">
                            <div class="k">Ticket On Check</div>
                            <div class="v" id="ticketOnCheckCount"><?= eos_h((string) ($summary['tickets']['on_check'] ?? 0)) ?></div>
                        </div>
                    </div>
                    <div class="runtime-stack">
                        <div class="mode-card">
                            <div class="mode-card-head">
                                <strong>Run Mode</strong>
                                <div class="toggle-row">
                                    <button type="button" class="toggle-btn active" id="modeUserBtn" onclick="setDashboardMode('user')">User</button>
                                    <button type="button" class="toggle-btn" id="modeServerBtn" onclick="setDashboardMode('server')">Server</button>
                                </div>
                            </div>
                            <div class="toggle-status">
                                <span class="toggle-pill">Mode: <strong id="dashboardModeLabel">USER</strong></span>
                                <span class="toggle-pill">Auto Poll: <strong id="autoPollLabel">OFF</strong></span>
                                <span class="toggle-pill">Auto Logout: <strong id="autoLogoutLabel">ON</strong></span>
                            </div>
                            <p id="dashboardModeHint">Mode user: polling Telegram per 1 menit dimatikan agar browser operator tidak ikut memproses kiriman bot.</p>
                        </div>
                        <a class="logout" href="?logout=1">POWER OFF</a>
                    </div>
                </div>
            </section>

            <section class="content">
                <div class="top-menu">
                    <a class="nav-anchor" href="#overview" data-target="overview">Overview</a>
                    <a class="nav-anchor mode-user" href="#control" data-target="control">Control</a>
                    <a class="nav-anchor" href="#sensor" data-target="sensor">Sensor</a>
                    <a class="nav-anchor mode-user" href="#ticketing" data-target="ticketing">Ticketing</a>
                    <a class="nav-anchor mode-user" href="#report" data-target="report">Report</a>
                    <?php if ($isAdmin): ?>
                        <a class="nav-anchor mode-user" href="#accounts" data-target="accounts">Accounts</a>
                    <?php endif; ?>
                    <a class="nav-anchor mode-user" href="#terminal" data-target="terminal">Terminal</a>
                    <a class="nav-anchor mode-user" href="#guide" data-target="guide">Panduan</a>
                    <a class="nav-anchor mode-user" href="#inventory" data-target="inventory">Inventory</a>
                    <a class="nav-anchor mode-user" href="#logs" data-target="logs">Logs</a>
                </div>

                <div class="nav-shell">
                    <aside class="sidebar">
                    <h3>Sub Menu</h3>
                        <div class="menu-list">
                            <a class="menu-link nav-anchor" href="#overview" data-target="overview"><span>Overview Board</span><span>01</span></a>
                            <a class="menu-link nav-anchor mode-user" href="#control" data-target="control"><span>Switch Matrix</span><span>02</span></a>
                            <a class="menu-link nav-anchor" href="#sensor" data-target="sensor"><span>Sensor Rack</span><span>03</span></a>
                            <a class="menu-link nav-anchor mode-user" href="#ticketing" data-target="ticketing"><span>Ticketing</span><span>04</span></a>
                            <a class="menu-link nav-anchor mode-user" href="#report" data-target="report"><span>Monthly Report</span><span>05</span></a>
                            <?php if ($isAdmin): ?>
                                <a class="menu-link nav-anchor mode-user" href="#accounts" data-target="accounts"><span>Account Access</span><span>06</span></a>
                            <?php endif; ?>
                            <a class="menu-link nav-anchor mode-user" href="#terminal" data-target="terminal"><span>Serial Terminal</span><span><?= $isAdmin ? '07' : '06' ?></span></a>
                            <a class="menu-link nav-anchor mode-user" href="#guide" data-target="guide"><span>Panduan Chat</span><span><?= $isAdmin ? '08' : '07' ?></span></a>
                            <a class="menu-link nav-anchor mode-user" href="#inventory" data-target="inventory"><span>Inventori</span><span><?= $isAdmin ? '09' : '08' ?></span></a>
                            <a class="menu-link nav-anchor mode-user" href="#logs" data-target="logs"><span>System Logs</span><span><?= $isAdmin ? '10' : '09' ?></span></a>
                        </div>
                    </aside>

                    <div class="main-panels">
                        <section id="overview" class="section-anchor">
                            <div id="statusModules" class="status-grid">
                                <?php foreach ($summary['modules'] as $module): ?>
                                    <article class="module-card">
                                        <div class="module-head">
                                            <div>
                                                <div class="module-key"><?= eos_h($module['key']) ?></div>
                                                <div class="module-label"><?= eos_h($module['label']) ?></div>
                                            </div>
                                            <div class="led" style="color:<?= eos_h($module['led']) ?>;background:<?= eos_h($module['led']) ?>"></div>
                                        </div>
                                        <div class="module-desc"><?= eos_h($module['description']) ?></div>
                                        <div class="module-meta"><?= eos_h($module['meta']) ?></div>
                                    </article>
                                <?php endforeach; ?>
                            </div>

                            <div class="busbar">
                                <div class="bus-pill">BUS STATE: <strong id="busStateBar"><?= eos_h($summary['board']['bus_state']) ?></strong></div>
                                <div class="bus-pill">MODULES: <span id="moduleCount"><?= eos_h((string) $summary['board']['module_count']) ?></span></div>
                                <div class="bus-pill">DRIVE SENSOR: <span id="diskHeadline"><?= eos_h(($summary['disk']['free_human'] ?? '-') . ' / ' . ($summary['disk']['free_percent'] ?? '-') . '%') ?></span></div>
                                <div class="bus-pill">NET BUS: <span id="networkHeadline"><?= strtoupper(eos_h((string) ($summary['network']['overall'] ?? 'standby'))) ?></span></div>
                                <div class="bus-pill">HOST: <span id="runtimeHost"><?= eos_h((string) $summary['runtime']['label']) ?> / <?= eos_h((string) $summary['runtime']['ip']) ?></span></div>
                                <div class="bus-pill">SCAN LOOP: <?= eos_h($summary['board']['uptime_hint']) ?></div>
                            </div>
                        </section>

                        <div class="board-grid">
                    <section id="control" class="panel section-anchor mode-user">
                        <h2>Switch Matrix</h2>
                        <p>Panel eksekusi utama seperti saklar pada control board.</p>
                        <div class="field">
                            <label for="poolName">APP POOL SLOT</label>
                            <select id="poolName">
                                <?php foreach ($summary['pools'] as $pool): ?>
                                    <option value="<?= eos_h($pool) ?>"><?= eos_h($pool) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="groupName">STACK GROUP SLOT</label>
                            <select id="groupName">
                                <?php foreach ($summary['groups'] as $group): ?>
                                    <option value="<?= eos_h($group) ?>"><?= eos_h($group) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="restartNote">OPERATOR NOTE</label>
                            <textarea id="restartNote" placeholder="misal: gate 02 timeout, recycle modul CGSIN"></textarea>
                        </div>
                        <div class="button-grid">
                            <button class="btn-main" onclick="runRestartPool()">RESTART POOL</button>
                            <button class="btn-soft" onclick="runRestartGroup()">RESTART GROUP</button>
                            <button class="btn-danger" onclick="runRestartIis()">HARD RESET IIS</button>
                            <button class="btn-warn" onclick="sendTestTelegram()">TEST KIRIM TELEGRAM</button>
                        </div>
                        <div class="quick-switches">
                            <button class="switch-btn" onclick="quickRestart('CGSIN')"><span>CGSIN</span><span>ARM</span></button>
                            <button class="switch-btn" onclick="quickRestart('AMS')"><span>AMS</span><span>ARM</span></button>
                            <button class="switch-btn" onclick="quickGroup('CGSIN_STACK')"><span>CGSIN_STACK</span><span>RUN</span></button>
                            <button class="switch-btn" onclick="quickGroup('CORE_SERVICES')"><span>CORE_SERVICES</span><span>RUN</span></button>
                        </div>
                        <div class="hint">Command Telegram aktif: <code>/disk</code>, <code>/network</code>, <code>/health</code>, <code>/restart POOL</code>, <code>/restart-group GROUP</code>, dan <code>/iis</code>. Bot juga akan membalas jika pesannya di-reply atau saat namanya disebut.</div>
                    </section>

                    <section id="sensor" class="panel stack section-anchor">
                        <div>
                            <h2>Sensor Rack</h2>
                            <p>Disk monitor, network bus, dan image fetch bekerja sebagai sensor/reader module.</p>
                            <div class="telemetry">
                                <div class="telemetry-box">
                                    <div>DISK STATUS</div>
                                    <div class="big" id="statDisk"><?= strtoupper(eos_h((string) ($summary['disk']['status'] ?? '-'))) ?></div>
                                    <div class="small" id="statDiskDetail"><?= eos_h(($summary['disk']['free_human'] ?? '-') . ' free dari ' . ($summary['disk']['total_human'] ?? '-')) ?></div>
                                </div>
                                <div class="telemetry-box">
                                    <div>NET BUS</div>
                                    <div class="big" id="netBusTile"><?= strtoupper(eos_h((string) ($summary['network']['overall'] ?? 'standby'))) ?></div>
                                    <div class="small" id="netBusDetail">Scan IP server, kamera, dan domain operasional.</div>
                                </div>
                            </div>
                            <div class="hint" id="diskPanel">Memuat status disk...</div>
                            <div class="button-grid">
                                <button class="btn-main" onclick="checkDisk(false)">REFRESH DISK</button>
                                <button class="btn-soft" onclick="checkDisk(true)">REPORT TO TG</button>
                                <button class="btn-main" onclick="scanNetwork(false)">SCAN NETWORK</button>
                                <button class="btn-soft" onclick="scanNetwork(true)">SCAN + LOG</button>
                            </div>
                            <div id="networkGrid" class="network-grid"></div>
                        </div>
                        <div>
                            <div class="field">
                                <label for="gateName">GATE SENSOR</label>
                                <select id="gateName">
                                    <?php foreach ($summary['gates'] as $gate): ?>
                                        <option value="<?= eos_h($gate) ?>"><?= eos_h($gate) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="photoTime">DATETIME SLOT</label>
                                <input id="photoTime" placeholder="24-02-2024 10:16">
                            </div>
                            <button class="btn-main" onclick="findImages()">FETCH BACKUP IMAGE</button>
                            <div id="imageResult" class="hint">Belum ada pencarian image.</div>
                            <div id="imageGallery" class="gallery"></div>
                        </div>
                    </section>

                    <section id="terminal" class="panel section-anchor mode-user">
                        <h2>Serial Terminal</h2>
                        <p>Output live seperti monitor serial untuk operator dengan format log yang lebih jelas.</p>
                        <div id="outputBox" class="terminal">Menunggu perintah...</div>
                        <div class="endpoint"><strong>Disk monitor</strong><br><code>monitor.php?key=<?= eos_h(eos_config('telegram.webhook_key')) ?></code></div>
                        <div class="endpoint"><strong>Telegram poll</strong><br><code>telegram_poll.php?key=<?= eos_h(eos_config('telegram.webhook_key')) ?></code></div>
                        <div class="endpoint"><strong>Telegram webhook</strong><br><code>telegram_webhook.php?key=<?= eos_h(eos_config('telegram.webhook_key')) ?></code></div>
                        <div class="endpoint"><strong>Controller status</strong><br><code>controller.php?key=<?= eos_h(eos_config('telegram.webhook_key')) ?>&cmd=status</code></div>
                        <div class="endpoint"><strong>Controller arm/fire</strong><br><code>controller.php?key=<?= eos_h(eos_config('telegram.webhook_key')) ?>&cmd=arm</code><br><code>controller.php?key=<?= eos_h(eos_config('telegram.webhook_key')) ?>&cmd=fire&action=restart_pool&target=CGSIN</code></div>
                    </section>
                        </div>

                <div id="ticketing" class="board-grid section-anchor mode-user" style="margin-top:16px;">
                    <section class="panel">
                        <h2>Ticket Intake</h2>
                        <p>EOS input kendala awal. Admin/petugas akan lanjutkan ke ON CHECK lalu DONE.</p>
                        <div class="field">
                            <label for="ticketIssueTime">JAM KEJADIAN</label>
                            <input id="ticketIssueTime" value="<?= eos_h(date('Y-m-d H:i:s')) ?>">
                        </div>
                        <div class="field">
                            <label for="ticketSite">SITE</label>
                            <select id="ticketSite" <?= $isAdmin ? '' : 'disabled' ?>>
                                <?php foreach ($siteOptions as $site): ?>
                                    <option value="<?= eos_h($site) ?>" <?= $site === $lockedSite ? 'selected' : '' ?>><?= eos_h(eos_site_label($site)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="ticketIssue">KENDALA / TROUBLE</label>
                            <textarea id="ticketIssue" placeholder="misal: barrier gate 03i tidak merespons, kamera gate 02o putus"></textarea>
                        </div>
                        <div class="button-grid">
                            <button class="btn-main" onclick="createTicket()">BUAT TIKET</button>
                            <button class="btn-soft" onclick="loadTickets()">REFRESH TIKET</button>
                        </div>
                        <div class="hint">Role `eos` dikunci ke site miliknya. Role `admin` bisa input dan lihat lintas site.</div>
                    </section>

                    <section class="panel" style="grid-column:span 2;">
                        <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
                            <div>
                                <h2>Ticket Board</h2>
                                <p>Daftar tiket aktif dan riwayat singkat yang diambil dari log file ticket.</p>
                            </div>
                            <div class="toggle-status">
                                <span class="toggle-pill">Open: <strong id="ticketOpenBadge"><?= eos_h((string) ($summary['tickets']['open'] ?? 0)) ?></strong></span>
                                <span class="toggle-pill">On Check: <strong id="ticketCheckBadge"><?= eos_h((string) ($summary['tickets']['on_check'] ?? 0)) ?></strong></span>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table class="inventory">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Jam</th>
                                        <th>Site</th>
                                        <th>Kendala</th>
                                        <th>Status</th>
                                        <th>Lama</th>
                                        <th>Catatan</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody id="ticketTableBody">
                                    <?php foreach (($summary['tickets']['recent'] ?? []) as $ticket): ?>
                                        <tr>
                                            <td><?= eos_h((string) $ticket['ticket_id']) ?></td>
                                            <td><?= eos_h((string) $ticket['issue_time']) ?></td>
                                            <td><?= eos_h((string) $ticket['site']) ?></td>
                                            <td><?= eos_h((string) $ticket['issue']) ?></td>
                                            <td><?= strtoupper(eos_h((string) $ticket['status'])) ?></td>
                                            <td><?= eos_h(eos_ticket_duration_label($ticket['repair_minutes'] ?? null)) ?></td>
                                            <td><?= eos_h((string) ($ticket['note'] ?? '-')) ?></td>
                                            <td><?= $isAdmin ? 'Admin action via refresh' : '-' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <div id="report" class="log-grid section-anchor mode-user" style="margin-top:16px;">
                    <section class="panel" style="grid-column:1 / -1;">
                        <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
                            <div>
                                <h3>Monthly Ticket Report</h3>
                                <p>Rekap bulanan tiket dari file log: kendala, jam, lama perbaikan, dan catatan.</p>
                            </div>
                            <div style="display:flex;gap:10px;align-items:center;">
                                <input id="reportMonth" type="month" value="<?= eos_h(date('Y-m')) ?>" style="width:auto;min-width:180px;">
                                <button class="btn-main" onclick="loadTicketReport()">LOAD REPORT</button>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table class="inventory">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Site</th>
                                        <th>Kendala</th>
                                        <th>Jam</th>
                                        <th>Status</th>
                                        <th>Lama Perbaikan</th>
                                        <th>Catatan</th>
                                    </tr>
                                </thead>
                                <tbody id="ticketReportBody"></tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <?php if ($isAdmin): ?>
                <div id="accounts" class="log-grid section-anchor mode-user" style="margin-top:16px;">
                    <section class="panel">
                        <h3>CRUD Akun</h3>
                        <p>Admin membuat dan mengatur akun `admin` atau `eos` tanpa database.</p>
                        <div class="field">
                            <label for="userUsername">USERNAME</label>
                            <input id="userUsername" placeholder="misal: petugas_gate03">
                        </div>
                        <div class="field">
                            <label for="userPassword">PASSWORD</label>
                            <input id="userPassword" placeholder="kosongkan saat update jika tidak diganti">
                        </div>
                        <div class="field">
                            <label for="userRole">ROLE</label>
                            <select id="userRole">
                                <option value="eos">EOS</option>
                                <option value="admin">ADMIN</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="userSite">SITE</label>
                            <select id="userSite">
                                <?php foreach ($siteOptions as $site): ?>
                                    <option value="<?= eos_h($site) ?>"><?= eos_h(eos_site_label($site)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="userActive">STATUS AKUN</label>
                            <select id="userActive">
                                <option value="1">ACTIVE</option>
                                <option value="0">INACTIVE</option>
                            </select>
                        </div>
                        <div class="button-grid">
                            <button class="btn-main" onclick="createUserAccount()">BUAT USER</button>
                            <button class="btn-soft" onclick="updateUserAccount()">UPDATE USER</button>
                            <button class="btn-danger" onclick="deleteUserAccount()">HAPUS USER</button>
                            <button class="btn-soft" onclick="loadUsers()">REFRESH USER</button>
                        </div>
                    </section>

                    <section class="panel" style="grid-column:span 3;">
                        <h3>Daftar Akun</h3>
                        <p>Role `eos` dibatasi ke site tertentu. Role `admin` dapat berpindah dan mengelola semua site.</p>
                        <div class="table-wrap">
                            <table class="inventory">
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Role</th>
                                        <th>Site</th>
                                        <th>Active</th>
                                        <th>Created</th>
                                        <th>Updated</th>
                                    </tr>
                                </thead>
                                <tbody id="userTableBody"></tbody>
                            </table>
                        </div>
                    </section>
                </div>
                <?php endif; ?>

                <div id="guide" class="log-grid section-anchor mode-user" style="margin-top:16px;">
                    <section class="panel" style="grid-column:1 / -1;">
                        <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
                            <div>
                                <h3>Menu Panduan Chat</h3>
                                <p>Panduan cepat agar operator tahu cara berbicara dengan bot di grup Telegram.</p>
                            </div>
                            <div class="bus-pill">PANGGIL BOT: `@Pak_Lurah_Dapit_bot` / `pak lurah dapit`</div>
                        </div>
                        <div class="guide-grid">
                            <article class="guide-card">
                                <h4>Command Resmi</h4>
                                <ul>
                                    <li><code>/help</code> untuk bantuan singkat.</li>
                                    <li><code>/disk</code> untuk cek kapasitas drive C.</li>
                                    <li><code>/network</code> untuk cek server, kamera, dan domain.</li>
                                    <li><code>/health</code> untuk ringkasan status sistem.</li>
                                    <li><code>/ticket kendala...</code> untuk buat tiket.</li>
                                    <li><code>/ticket GATE03I | barrier tidak respon</code> untuk tiket per site.</li>
                                    <li><code>/tickets</code> untuk lihat daftar tiket.</li>
                                    <li><code>/ticket-report 2026-07</code> untuk report bulanan.</li>
                                    <li><code>/ticket-day</code> untuk summary tiket hari ini.</li>
                                    <li><code>/ticket-day 2026-07-08</code> untuk summary tanggal tertentu.</li>
                                    <li><code>reply: on proses</code> untuk ubah status jadi on check.</li>
                                    <li><code>reply: done catatan...</code> untuk tutup tiket.</li>
                                    <li><code>/restart AMS</code> untuk restart app pool tertentu.</li>
                                    <li><code>/restart-group CGSIN_STACK</code> untuk restart satu grup.</li>
                                    <li><code>/iis</code> untuk restart IIS.</li>
                                </ul>
                            </article>
                            <article class="guide-card">
                                <h4>Contoh Chat Natural</h4>
                                <ul>
                                    <li><code>@Pak_Lurah_Dapit_bot disk tinggal berapa</code></li>
                                    <li><code>@Pak_Lurah_Dapit_bot jaringan bagaimana</code></li>
                                    <li><code>@Pak_Lurah_Dapit_bot status server bagaimana</code></li>
                                    <li><code>@Pak_Lurah_Dapit_bot domain cusmod hidup tidak</code></li>
                                    <li><code>@Pak_Lurah_Dapit_bot kamera online semua?</code></li>
                                    <li><code>@Pak_Lurah_Dapit_bot mana yang offline sekarang</code></li>
                                    <li><code>@Pak_Lurah_Dapit_bot ringkas status umum</code></li>
                                    <li><code>@Pak_Lurah_Dapit_bot barrier gate 03i online tidak</code></li>
                                    <li><code>@Pak_Lurah_Dapit_bot adam gate 03i online tidak</code></li>
                                    <li><code>@Pak_Lurah_Dapit_bot timbangan gate02o bagaimana</code></li>
                                    <li><code>/ticket GATE03I | barrier tidak respon</code></li>
                                    <li><code>/ticket-day</code></li>
                                    <li><code>reply ke tiket: on proses</code></li>
                                    <li><code>reply ke tiket: done barrier normal kembali</code></li>
                                    <li><code>@Pak_Lurah_Dapit_bot tolong bantu cek disk</code></li>
                                </ul>
                            </article>
                            <article class="guide-card">
                                <h4>Cara Bot Merespons</h4>
                                <ul>
                                    <li>Bot menjawab jika di-mention, di-reply, atau dipanggil namanya.</li>
                                    <li>Balasan bisa berisi status disk, jaringan, health, atau hasil restart.</li>
                                    <li>Balasan interaktif menyertakan server/IP responder.</li>
                                    <li>Polling Telegram berjalan tiap 1 menit selama dashboard terbuka.</li>
                                </ul>
                            </article>
                            <article class="guide-card">
                                <h4>Nama Yang Bisa Dipanggil</h4>
                                <ul>
                                    <li><code>@Pak_Lurah_Dapit_bot</code></li>
                                    <li><code>pak lurah dapit</code></li>
                                    <li><code>pak lurah</code></li>
                                    <li><code>dapit bot</code></li>
                                    <li><code>eos</code></li>
                                    <li><code>eos tools</code></li>
                                    <li><code>eostools</code></li>
                                    <li><code>bot eos</code></li>
                                </ul>
                            </article>
                        </div>
                    </section>
                </div>

                <div id="inventory" class="log-grid section-anchor mode-user" style="margin-top:16px;">
                    <section class="panel" style="grid-column:1 / -1;">
                        <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
                            <div>
                                <h3>Inventori Perangkat</h3>
                                <p>Daftar camera, barrier, dan timbangan yang sudah terdaftar di sistem.</p>
                            </div>
                            <div class="bus-pill">LB SERVER: 172.27.0.36</div>
                        </div>

                        <div class="table-wrap">
                            <table class="inventory">
                                <thead>
                                    <tr>
                                        <th>Gate ID</th>
                                        <th>Camera IP</th>
                                        <th>Status</th>
                                        <th>Model</th>
                                        <th>Auth Profile</th>
                                        <th>Name</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($summary['devices']['cameras'] ?? []) as $camera): ?>
                                        <tr>
                                            <td><?= eos_h((string) $camera['gate_id']) ?></td>
                                            <td><?= eos_h((string) $camera['ip']) ?></td>
                                            <td>
                                                <span class="inventory-status <?= eos_h((string) ($camera['status'] ?? 'unknown')) ?>">
                                                    <?= strtoupper(eos_h((string) ($camera['status'] ?? 'unknown'))) ?>
                                                </span>
                                                <span class="inventory-meta">Latency: <?= eos_h((string) ($camera['latency'] ?? '-')) ?></span>
                                            </td>
                                            <td><?= eos_h((string) $camera['model']) ?></td>
                                            <td><?= eos_h((string) $camera['auth_profile']) ?></td>
                                            <td><?= eos_h((string) $camera['name']) ?></td>
                                            <td><?= eos_h((string) ($camera['action'] ?: '-')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="table-wrap">
                            <table class="inventory">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Gate Name</th>
                                        <th>I/E</th>
                                        <th>I/O</th>
                                        <th>Barrier IP</th>
                                        <th>Barrier Status</th>
                                        <th>Timbangan IP</th>
                                        <th>Timbangan Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($summary['devices']['gates'] ?? []) as $gate): ?>
                                        <tr>
                                            <td><?= eos_h((string) $gate['id']) ?></td>
                                            <td><?= eos_h((string) $gate['gate_name']) ?></td>
                                            <td><?= eos_h((string) $gate['zone']) ?></td>
                                            <td><?= eos_h((string) $gate['io']) ?></td>
                                            <td><?= eos_h((string) $gate['barrier_ip']) ?></td>
                                            <td>
                                                <span class="inventory-status <?= eos_h((string) ($gate['barrier_status'] ?? 'unknown')) ?>">
                                                    <?= strtoupper(eos_h((string) ($gate['barrier_status'] ?? 'unknown'))) ?>
                                                </span>
                                                <span class="inventory-meta">Latency: <?= eos_h((string) ($gate['barrier_latency'] ?? '-')) ?></span>
                                            </td>
                                            <td><?= eos_h((string) $gate['timbangan_ip']) ?></td>
                                            <td>
                                                <span class="inventory-status <?= eos_h((string) ($gate['timbangan_status'] ?? 'unknown')) ?>">
                                                    <?= strtoupper(eos_h((string) ($gate['timbangan_status'] ?? 'unknown'))) ?>
                                                </span>
                                                <span class="inventory-meta">Latency: <?= eos_h((string) ($gate['timbangan_latency'] ?? '-')) ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <div id="logs" class="log-grid section-anchor mode-user">
                    <section class="panel">
                        <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
                            <div>
                                <h3>Bus Activity Log</h3>
                                <p>Riwayat eksekusi utama dengan format level, module, actor, dan context.</p>
                            </div>
                            <button class="btn-soft" onclick="refreshLogs()">REFRESH</button>
                        </div>
                        <div id="activityLog" class="log-box"><?= eos_h(implode("\n", $summary['activity_logs'])) ?></div>
                    </section>
                    <section class="panel">
                        <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
                            <div>
                                <h3>Telegram RX/TX Log</h3>
                                <p>Status command dan pengiriman notifikasi.</p>
                            </div>
                            <button class="btn-soft" onclick="refreshLogs()">REFRESH</button>
                        </div>
                        <div id="telegramLog" class="log-box"><?= eos_h(implode("\n", $summary['telegram_logs'])) ?></div>
                    </section>
                    <section class="panel">
                        <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
                            <div>
                                <h3>Network Log</h3>
                                <p>Status cek IP server, kamera, dan domain.</p>
                            </div>
                            <button class="btn-soft" onclick="refreshLogs()">REFRESH</button>
                        </div>
                        <div id="networkLog" class="log-box"><?= eos_h(implode("\n", $summary['network_logs'])) ?></div>
                    </section>
                    <section class="panel">
                        <h3>Microcontroller Notes</h3>
                        <p>Konsep ini membuat tiap fitur terasa seperti modul papan kontrol, bukan menu terpisah.</p>
                        <div class="hint">`IIS BUS` untuk aksi reset, `DISK SENSOR` untuk health check, `NET BUS` untuk reachability jaringan, `TELEGRAM RX/TX` untuk komunikasi, `IMAGE FETCH` untuk pembacaan backup, dan `AUTOMATION` untuk loop scheduler.</div>
                        <div class="hint">Scheduler Windows cukup memanggil endpoint monitor dan poll secara periodik, sehingga board tetap ringan tanpa daemon panjang di PHP.</div>
                    </section>
                </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <script>
        window.EOS_DASHBOARD = <?= json_encode([
            'isAdmin' => (bool) $isAdmin,
            'lockedSite' => $lockedSite,
            'requestLimits' => [
                'tickets' => 20,
                'users' => 300,
                'logs' => 60,
                'networkTargets' => 40,
            ],
            'modeKey' => 'eos_tools_dashboard_mode',
            'autoLogoutMs' => 15 * 60 * 1000,
            'refresh' => [
                'user' => ['summary' => 3000, 'logs' => 10000, 'disk' => 30000, 'network' => 30000],
                'server' => ['summary' => 5000, 'logs' => 0, 'disk' => 0, 'network' => 0],
            ],
            'text' => [
                'modeServer' => 'Mode SERVER aktif. Fokus statistik, auto ping Telegram tetap jalan untuk warning.',
                'modeUser' => 'Mode USER aktif. UI operasional full: log, ticket, scan dan user management aktif.',
            ],
            'apiPath' => [
                'summary' => '?api=summary',
                'logs' => '?api=logs',
                'tickets' => '?api=tickets',
                'users' => '?api=users',
                'monitor_disk' => '?api=monitor_disk',
                'network' => '?api=network',
                'disk' => '?api=disk',
                'ticket_create' => '?api=ticket_create',
                'ticket_on_check' => '?api=ticket_on_check',
                'ticket_done' => '?api=ticket_done',
                'ticket_report' => '?api=ticket_report',
                'user_create' => '?api=user_create',
                'user_update' => '?api=user_update',
                'user_delete' => '?api=user_delete',
                'restart_pool' => '?api=restart_pool',
                'restart_group' => '?api=restart_group',
                'restart_iis' => '?api=restart_iis',
                'test_telegram' => '?api=test_telegram',
                'telegram_poll' => '?api=telegram_poll',
                'find_images' => '?api=find_images',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>;
    </script>
    <script src="assets/dashboard-api.js"></script>
    <script src="assets/dashboard-actions.js"></script>
    <script src="assets/dashboard-state.js"></script>
    </body>
</html>
