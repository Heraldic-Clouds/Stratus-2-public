<?php
declare(strict_types=1);

// Runs inside the isolated real Nextcloud/Moto installation, never production.
if (getenv('STRATUS2_TEST_MODE') !== '1' || getenv('STRATUS2_TRANSFER_TEST_MODE') !== '1') {
    throw new RuntimeException('Only available in isolated CI');
}
define('OC_CONSOLE', true);
chdir('/var/www/html');
require 'lib/base.php';
$app = new \OCA\StratusTransfer\AppInfo\Application();
$receipts = $app->getContainer()->get(\OCA\StratusTransfer\Service\Receipts::class);

if (($argv[1] ?? '') === 'child') {
    $uid = $argv[2] ?? '';
    $ticket = $argv[3] ?? '';
    if (!preg_match('/^transfer_concurrency_[a-f0-9]{12}$/D', $uid) || !preg_match('/^[a-f0-9]{32}$/D', $ticket)) {
        throw new RuntimeException('Invalid isolated fixture');
    }
    for ($position = 1000; $position <= 3000; $position += 1000) {
        $receipts->progress($uid, $ticket, $position);
        $receipts->summary($uid);
    }
    exit(0);
}

$uid = 'transfer_concurrency_' . bin2hex(random_bytes(6));
$user = \OCP\Server::get(\OCP\IUserManager::class)->createUser($uid, bin2hex(random_bytes(32)));
$children = [];
try {
    $ticket = $receipts->create($uid, ['size' => 3000, 'mode' => 'direct']);
    for ($i = 0; $i < 4; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, 'child', $uid, $ticket],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {throw new RuntimeException('Could not launch concurrent request');}
        fclose($pipes[0]);
        $children[] = [$process, $pipes];
    }
    $failed = false;
    foreach ($children as [$process, $pipes]) {
        // Suppress framework diagnostics: test output must never expose config.
        stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) {$failed = true;}
    }
    $children = [];
    if ($failed) {throw new RuntimeException('A concurrent receipt request failed');}
    if ($receipts->summary($uid)['direct_bytes'] !== 3000 || $receipts->get($uid, $ticket)['position'] !== 3000) {
        throw new RuntimeException('Concurrent accounting lost updates or counted retries twice');
    }
    echo "PASS: four independent processes preserve receipt progress and idempotent totals\n";
} finally {
    foreach ($children as [$process, $pipes]) {
        proc_terminate($process);
        foreach ($pipes as $pipe) {if (is_resource($pipe)) {fclose($pipe);}}
        proc_close($process);
    }
    $app->getContainer()->get(\OCP\Files\IAppData::class)->getFolder('receipts')->getFile('user_' . hash('sha256', $uid))->delete();
    $user->delete();
}
