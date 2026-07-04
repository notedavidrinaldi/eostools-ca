<?php
require __DIR__ . '/bootstrap.php';

$key = (string) ($_GET['key'] ?? '');
if ($key !== (string) eos_config('telegram.webhook_key')) {
    eos_json(['ok' => false, 'message' => 'Unauthorized'], 403);
}

$result = eos_telegram_poll_once();
eos_json($result, $result['ok'] ? 200 : 502);
