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

    switch ($api) {
        case 'summary':
            eos_json(['ok' => true, 'data' => eos_dashboard_summary()]);
            break;

        case 'logs':
            $type = $_GET['type'] ?? 'app';
            $file = $type === 'telegram'
                ? eos_config('paths.telegram_log')
                : ($type === 'network' ? eos_config('paths.network_log') : eos_config('paths.app_log'));
            eos_json(['ok' => true, 'logs' => eos_tail($file, 80)]);
            break;

        case 'restart_pool':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                eos_json(['ok' => false, 'message' => 'Method tidak valid.'], 405);
            }
            $result = eos_restart_app_pool((string) ($_POST['pool'] ?? ''), trim((string) ($_POST['note'] ?? '')));
            eos_json($result, $result['ok'] ? 200 : 422);
            break;

        case 'restart_group':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                eos_json(['ok' => false, 'message' => 'Method tidak valid.'], 405);
            }
            $result = eos_restart_group((string) ($_POST['group'] ?? ''), trim((string) ($_POST['note'] ?? '')));
            eos_json($result, $result['ok'] ? 200 : 422);
            break;

        case 'restart_iis':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                eos_json(['ok' => false, 'message' => 'Method tidak valid.'], 405);
            }
            $result = eos_restart_iis(trim((string) ($_POST['reason'] ?? '')));
            eos_json($result, $result['ok'] ? 200 : 422);
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
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                eos_json(['ok' => false, 'message' => 'Method tidak valid.'], 405);
            }
            $result = eos_find_backup_images((string) ($_POST['gate'] ?? ''), (string) ($_POST['datetime'] ?? ''));
            eos_json($result, $result['ok'] ? 200 : 422);
            break;

        case 'test_telegram':
            eos_send_telegram(
                "🔔 <b>Test EOS Tools</b>\nUser: <b>" . eos_format_plain((string) eos_current_user()) . "</b>\nTime: " . date('Y-m-d H:i:s')
            );
            eos_log_event('TG', 'manual_test', 'INFO', [], (string) eos_current_user(), 'telegram');
            eos_json(['ok' => true, 'message' => 'Pesan test Telegram dikirim.']);
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
        .content{position:relative;z-index:1;padding:24px}
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
        code{color:#a7f3d0}
        @media (max-width: 1240px){
            .status-grid{grid-template-columns:repeat(3, minmax(0,1fr))}
            .board-grid,.log-grid{grid-template-columns:1fr}
        }
        @media (max-width: 760px){
            .shell{padding:12px}
            .topbar,.content{padding:16px}
            .status-grid,.top-meta,.telemetry,.gallery,.button-grid,.quick-switches,.network-grid{grid-template-columns:1fr}
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
                    </div>
                    <a class="logout" href="?logout=1">POWER OFF</a>
                </div>
            </section>

            <section class="content">
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
                    <div class="bus-pill">SCAN LOOP: <?= eos_h($summary['board']['uptime_hint']) ?></div>
                </div>

                <div class="board-grid">
                    <section class="panel">
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

                    <section class="panel stack">
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

                    <section class="panel">
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

                <div class="log-grid">
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
            </section>
        </div>
    </div>

    <script>
        async function api(path, options = {}) {
            const response = await fetch(path, options);
            const data = await response.json();
            if (!response.ok) {
                throw new Error(data.message || 'Request gagal');
            }
            return data;
        }

        function setOutput(value) {
            document.getElementById('outputBox').textContent = value;
        }

        function formatObject(value) {
            return typeof value === 'string' ? value : JSON.stringify(value, null, 2);
        }

        function renderModules(modules) {
            document.getElementById('statusModules').innerHTML = modules.map((module) => `
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

        function getStateColor(status) {
            if (status === 'online' || status === 'ready') return '#27d3a2';
            if (status === 'warning' || status === 'standby') return '#f6c14b';
            return '#ff6b6b';
        }

        function renderNetworkTargets(targets) {
            const root = document.getElementById('networkGrid');
            root.innerHTML = targets.map((target) => {
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
            }).join('');
        }

        async function runRestartPool() {
            const pool = document.getElementById('poolName').value;
            const note = document.getElementById('restartNote').value;
            setOutput('> recycle pool ' + pool + '\n> board command armed...');
            try {
                const result = await api('?api=restart_pool', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({pool, note})
                });
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
                const result = await api('?api=restart_group', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({group, note})
                });
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
                const result = await api('?api=restart_iis', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({reason})
                });
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
                const result = await api('?api=test_telegram');
                setOutput(result.message);
                refreshLogs();
            } catch (error) {
                setOutput('ERROR: ' + error.message);
            }
        }

        async function checkDisk(notify) {
            document.getElementById('diskPanel').textContent = 'Scanning disk sensor...';
            try {
                const query = notify ? '?api=monitor_disk&notify=1' : '?api=disk';
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
                const result = await api(writeLog ? '?api=network&log=1' : '?api=network');
                const network = result.data;
                document.getElementById('networkState').textContent = String(network.overall).toUpperCase();
                document.getElementById('networkHeadline').textContent = String(network.overall).toUpperCase();
                document.getElementById('netBusTile').textContent = String(network.overall).toUpperCase();
                document.getElementById('networkScanTime').textContent = network.updated_at || '-';
                document.getElementById('netBusDetail').textContent = `${network.targets.filter(t => t.status === 'online').length}/${network.targets.length} target online`;
                renderNetworkTargets(network.targets);
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
                const result = await api('?api=find_images', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({gate, datetime})
                });
                resultBox.textContent = result.message + ' Folder: ' + result.searched_folder;
                gallery.innerHTML = result.images.map((image) => `
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

        async function refreshLogs() {
            try {
                const [activity, telegram, network] = await Promise.all([
                    api('?api=logs&type=app'),
                    api('?api=logs&type=telegram'),
                    api('?api=logs&type=network')
                ]);
                document.getElementById('activityLog').textContent = activity.logs.join('\n');
                document.getElementById('telegramLog').textContent = telegram.logs.join('\n');
                document.getElementById('networkLog').textContent = network.logs.join('\n');
            } catch (error) {
                setOutput('ERROR: ' + error.message);
            }
        }

        async function refreshSummary() {
            try {
                const result = await api('?api=summary');
                document.getElementById('serverTime').textContent = result.data.server_time;
                document.getElementById('busState').textContent = result.data.board.bus_state;
                document.getElementById('busStateBar').textContent = result.data.board.bus_state;
                document.getElementById('controllerState').textContent = result.data.controller.armed ? 'ARMED' : 'DISARMED';
                document.getElementById('controllerLast').textContent = result.data.controller.last_command;
                document.getElementById('moduleCount').textContent = result.data.board.module_count;
                document.getElementById('networkState').textContent = String(result.data.network.overall || 'standby').toUpperCase();
                document.getElementById('networkHeadline').textContent = String(result.data.network.overall || 'standby').toUpperCase();
                document.getElementById('networkScanTime').textContent = result.data.network.updated_at || '-';
                renderModules(result.data.modules);
            } catch (error) {
            }
        }

        function refreshAll() {
            refreshLogs();
            checkDisk(false);
            scanNetwork(false);
            refreshSummary();
        }

        checkDisk(false);
        scanNetwork(false);
        setInterval(refreshSummary, 1000);
        setInterval(refreshLogs, 10000);
        setInterval(() => checkDisk(false), 30000);
        setInterval(() => scanNetwork(false), 30000);
    </script>
</body>
</html>
