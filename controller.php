<?php
require __DIR__ . '/bootstrap.php';

$key = (string) ($_GET['key'] ?? '');
if ($key !== (string) eos_config('telegram.webhook_key')) {
    eos_json(['ok' => false, 'message' => 'Unauthorized'], 403);
}

$cmd = (string) ($_REQUEST['cmd'] ?? 'status');
$payload = [
    'action' => (string) ($_REQUEST['action'] ?? ''),
    'target' => (string) ($_REQUEST['target'] ?? ''),
    'note' => (string) ($_REQUEST['note'] ?? ''),
    'gate' => (string) ($_REQUEST['gate'] ?? ''),
    'datetime' => (string) ($_REQUEST['datetime'] ?? ''),
];

$result = eos_controller_command($cmd, $payload, 'remote-controller');
eos_json($result, $result['ok'] ? 200 : 422);
