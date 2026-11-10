<?php

// Restore into a NEW test database; this helper cannot overwrite an existing database.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Symfony\Component\Process\Process;

$database = (string) getenv('DB_DATABASE');
$output = (string) getenv('ATICE_BACKUP_TEST_ROOT');
if (getenv('DB_CONNECTION') !== 'mysql' || ! preg_match('/^[a-zA-Z0-9_]*test[a-zA-Z0-9_]*$/', $database) || $output === '') {
    throw new RuntimeException('Requires an explicitly named MySQL test database and ATICE_BACKUP_TEST_ROOT.');
}
if (file_exists($output)) {
    throw new RuntimeException('Use a new backup drill directory.');
}
mkdir($output, 0700, true);
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$user = getenv('DB_USERNAME') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$restore = $database.'_restore_'.bin2hex(random_bytes(3));
$pdo->exec("CREATE DATABASE `{$restore}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$common = ['--no-defaults', '--host='.$host, '--port='.$port, '--user='.$user];
$environment = ['MYSQL_PWD' => $password];
$dumpFile = $output.'/database.sql';
$dump = new Process(array_merge([getenv('MYSQLDUMP_BIN') ?: 'mysqldump'], $common, ['--single-transaction', '--no-tablespaces', '--set-gtid-purged=OFF', '--routines', '--triggers', '--result-file='.$dumpFile, $database]), null, $environment, null, 180);
$dump->mustRun();
$restoreProcess = new Process(array_merge([getenv('MYSQL_BIN') ?: 'mysql'], $common, [$restore]), null, $environment, file_get_contents($dumpFile), 900);
$restoreProcess->mustRun();

$signatures = function (string $name) use ($pdo): array {
    $pdo->exec("USE `{$name}`");
    $tables = $pdo->query("SHOW TABLES FROM `{$name}`")->fetchAll(PDO::FETCH_COLUMN);
    sort($tables);
    $result = [];
    foreach ($tables as $table) {
        $quoted = str_replace('`', '``', $table);
        $rows = $pdo->query("SELECT * FROM `{$name}`.`{$quoted}`")->fetchAll(PDO::FETCH_ASSOC);
        $encoded = array_map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR), $rows);
        sort($encoded);
        $columns = $pdo->query("SHOW FULL COLUMNS FROM `{$name}`.`{$quoted}`")->fetchAll(PDO::FETCH_ASSOC);
        $definition = $pdo->query("SHOW CREATE TABLE `{$name}`.`{$quoted}`")->fetch(PDO::FETCH_NUM)[1];
        // MySQL can make an inherited character set explicit after an import.
        // Compare actual column collations above, then normalize that DDL spelling.
        $definition = preg_replace('/ CHARACTER SET [a-zA-Z0-9_]+/', '', $definition);
        $result[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', implode("\n", $encoded)), 'columns' => hash('sha256', json_encode($columns, JSON_THROW_ON_ERROR)), 'definition' => hash('sha256', $definition)];
    }

    return $result;
};
$source = $signatures($database);
$restored = $signatures($restore);
if ($source !== $restored || $source === []) {
    file_put_contents($output.'/comparison-failed.json', json_encode(['source' => $source, 'restored' => $restored], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    throw new RuntimeException('Restored rows or column definitions do not match.');
}

// Include synthetic public/private attachments in the drill, never real files.
$attachments = [];
foreach (['public/delivery-proof.jpg', 'private/face-fixture.bin'] as $path) {
    $original = $output.'/source-storage/'.$path;
    $backup = $output.'/backup-storage/'.$path;
    $restoredFile = $output.'/restored-storage/'.$path;
    foreach ([$original, $backup, $restoredFile] as $file) {
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0700, true);
        }
    }
    file_put_contents($original, random_bytes(256));
    copy($original, $backup);
    copy($backup, $restoredFile);
    if (hash_file('sha256', $original) !== hash_file('sha256', $restoredFile)) {
        throw new RuntimeException('Restored attachment mismatch.');
    }
    $attachments[$path] = hash_file('sha256', $restoredFile);
}
$evidence = ['source_database' => $database, 'restored_database' => $restore, 'tables' => $source, 'attachments' => $attachments, 'dump_sha256' => hash_file('sha256', $dumpFile), 'result' => 'PASS'];
file_put_contents($output.'/restore-evidence.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'PASS: '.count($source).' tables and '.count($attachments)." synthetic attachments restored and matched.\n";
