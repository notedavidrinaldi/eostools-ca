<?php
require __DIR__ . '/bootstrap.php';

$key = (string) ($_GET['key'] ?? '');
if ($key !== (string) eos_config('telegram.webhook_key')) {
    eos_json(['ok' => false, 'message' => 'Unauthorized'], 403);
}

$report = eos_monitor_disk(isset($_GET['notify']));
eos_json(['ok' => true, 'data' => $report]);
