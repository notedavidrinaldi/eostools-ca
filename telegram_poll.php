<?php
require __DIR__ . '/bootstrap.php';

$key = (string) ($_GET['key'] ?? '');
if ($key !== (string) eos_config('telegram.webhook_key')) {
    eos_json(['ok' => false, 'message' => 'Unauthorized'], 403);
}

$token = (string) eos_config('telegram.bot_token', '');
if ($token === '') {
    eos_json(['ok' => false, 'message' => 'Bot token belum diisi.'], 422);
}

$stateFile = eos_config('telegram.poll_state_file');
$state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
$offset = (int) ($state['offset'] ?? 0);

$query = 'https://api.telegram.org/bot' . $token . '/getUpdates?timeout=1&offset=' . $offset;
$raw = @file_get_contents($query);
$json = json_decode((string) $raw, true);
if (!is_array($json) || !($json['ok'] ?? false)) {
    eos_json(['ok' => false, 'message' => 'Gagal mengambil update Telegram.', 'raw' => $raw], 502);
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

eos_json([
    'ok' => true,
    'handled' => $handled,
    'next_offset' => $maxOffset,
    'count' => count($handled),
]);
