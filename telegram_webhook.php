<?php
require __DIR__ . '/bootstrap.php';

$key = (string) ($_GET['key'] ?? '');
if ($key !== (string) eos_config('telegram.webhook_key')) {
    eos_json(['ok' => false, 'message' => 'Unauthorized'], 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    eos_json(['ok' => true, 'message' => 'EOS Tools webhook aktif.']);
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    eos_json(['ok' => false, 'message' => 'Payload tidak valid.'], 422);
}

$result = eos_telegram_process_update($payload);
eos_json(['ok' => true, 'result' => $result]);
