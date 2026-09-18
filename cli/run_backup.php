<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Ten skrypt uruchamiaj z CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/db.php';
require_once $root . '/museum_system.php';

$kind = $argv[1] ?? '';
$allowedKinds = ['daily', 'weekly', 'monthly'];

if (!in_array($kind, $allowedKinds, true)) {
    fwrite(STDERR, "Użycie: php cli/run_backup.php [daily|weekly|monthly]\n");
    exit(2);
}

try {
    $result = museumRunSqlBackup($pdo, [
        'backup_kind' => $kind,
        'storage_scope' => 'local',
        'initiated_by' => 'system',
        'note' => 'Backup uruchomiony przez CLI.',
        'return_dump' => false,
    ]);

    fwrite(STDOUT, "OK backup {$kind}\n");
    fwrite(STDOUT, "id=" . (int)$result['id'] . "\n");
    fwrite(STDOUT, "file=" . (string)$result['file_path'] . "\n");
    fwrite(STDOUT, "sha256=" . (string)($result['sha256_hash'] ?? '') . "\n");
    fwrite(STDOUT, "retention_deleted=" . (int)($result['retention_deleted_count'] ?? 0) . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "BŁĄD: " . $e->getMessage() . "\n");
    exit(1);
}

