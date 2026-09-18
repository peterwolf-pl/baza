<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Ten skrypt działa tylko z CLI.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/db.php';

function qid_cli(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

$tables = [
    'karta_ewidencyjna',
    'karta_ewidencyjna_maszyny',
    'karta_ewidencyjna_matryce',
    'karta_ewidencyjna_bib',
    'karta_ewidencyjna_klisze',
];

foreach ($tables as $table) {
    echo "=== {$table} ===\n";
    try {
        $columns = $pdo->query('SHOW COLUMNS FROM ' . qid_cli($table))->fetchAll(PDO::FETCH_ASSOC);
        $pkRows = $pdo->query('SHOW KEYS FROM ' . qid_cli($table) . " WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);

        if (count($pkRows) !== 1) {
            echo "SKIP: PRIMARY KEY nie jest pojedynczą kolumną.\n\n";
            continue;
        }

        $pkColumn = (string)($pkRows[0]['Column_name'] ?? '');
        $columnMeta = null;
        foreach ($columns as $col) {
            if ((string)($col['Field'] ?? '') === $pkColumn) {
                $columnMeta = $col;
                break;
            }
        }
        if ($pkColumn === '' || $columnMeta === null) {
            echo "ERROR: nie udało się odczytać kolumny PK.\n\n";
            continue;
        }

        $type = (string)($columnMeta['Type'] ?? '');
        $extra = strtolower((string)($columnMeta['Extra'] ?? ''));
        $unsigned = stripos($type, 'unsigned') !== false;
        $isAutoIncrement = str_contains($extra, 'auto_increment');
        if (preg_match('/^(tinyint|smallint|mediumint|int|bigint)\b/i', $type) !== 1) {
            echo "SKIP: PK {$pkColumn} nie jest typem liczbowym ({$type}).\n\n";
            continue;
        }

        $maxStmt = $pdo->query(
            'SELECT COALESCE(MAX(CAST(' . qid_cli($pkColumn) . ' AS UNSIGNED)), 0) FROM ' . qid_cli($table)
        );
        $maxId = (int)$maxStmt->fetchColumn();
        $nextAuto = max(1, $maxId + 1);
        $baseType = trim((string)preg_replace('/\s+unsigned/i', '', $type));
        $normalizedType = $unsigned ? ($baseType . ' UNSIGNED') : $baseType;
        $modifySql = 'ALTER TABLE ' . qid_cli($table)
            . ' MODIFY COLUMN ' . qid_cli($pkColumn)
            . ' ' . $normalizedType . ' NOT NULL AUTO_INCREMENT';
        $autoSql = 'ALTER TABLE ' . qid_cli($table) . ' AUTO_INCREMENT = ' . $nextAuto;

        echo "PK: {$pkColumn}\nType: {$type}\nMAX(PK): {$maxId}\nAUTO_INCREMENT: {$nextAuto}\n";

        $pdo->beginTransaction();
        if (!$isAutoIncrement) {
            $pdo->exec($modifySql);
            echo "Wykonano: {$modifySql}\n";
        } else {
            echo "Kolumna ma już AUTO_INCREMENT.\n";
        }
        $pdo->exec($autoSql);
        $pdo->commit();
        echo "Wykonano: {$autoSql}\n\n";
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n\n");
    }
}
