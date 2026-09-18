<?php

function museumQuoteIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function museumStripUrlQueryParam(string $url, string $queryParam): string
{
    $url = trim($url);
    if ($url === '' || $queryParam === '') {
        return $url;
    }

    $parts = parse_url($url);
    if ($parts === false || !array_key_exists('query', $parts)) {
        return $url;
    }

    $queryParams = [];
    parse_str((string)$parts['query'], $queryParams);
    if (!array_key_exists($queryParam, $queryParams)) {
        return $url;
    }

    unset($queryParams[$queryParam]);

    $rebuilt = '';
    if (isset($parts['scheme'])) {
        $rebuilt .= (string)$parts['scheme'] . '://';
    }
    if (isset($parts['user'])) {
        $rebuilt .= (string)$parts['user'];
        if (isset($parts['pass'])) {
            $rebuilt .= ':' . (string)$parts['pass'];
        }
        $rebuilt .= '@';
    }
    if (isset($parts['host'])) {
        $rebuilt .= (string)$parts['host'];
    }
    if (isset($parts['port'])) {
        $rebuilt .= ':' . (int)$parts['port'];
    }

    if (isset($parts['path'])) {
        $rebuilt .= (string)$parts['path'];
    } elseif ($rebuilt === '') {
        return $url;
    }

    $query = http_build_query($queryParams);
    if ($query !== '') {
        $rebuilt .= '?' . $query;
    }
    if (isset($parts['fragment']) && (string)$parts['fragment'] !== '') {
        $rebuilt .= '#' . (string)$parts['fragment'];
    }

    return $rebuilt !== '' ? $rebuilt : $url;
}

function museumNormalizeImageReference(?string $rawValue): ?string
{
    if ($rawValue === null) {
        return null;
    }

    $normalized = trim(trim($rawValue), " '\"");
    if ($normalized === '') {
        return null;
    }

    return museumStripUrlQueryParam($normalized, 'ledger');
}

function museumFileExtensionVariants(string $path): array
{
    $path = str_replace('\\', '/', $path);
    $dot = strrpos($path, '.');
    $slash = strrpos($path, '/');
    if ($dot === false || ($slash !== false && $dot < $slash) || $dot === strlen($path) - 1) {
        return [$path];
    }

    $stem = substr($path, 0, $dot);
    $ext = substr($path, $dot + 1);
    $variants = [
        $path,
        $stem . '.' . strtolower($ext),
        $stem . '.' . strtoupper($ext),
    ];

    return array_values(array_unique($variants));
}

function museumEncodeMediaPath(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $segments = array_map('rawurlencode', array_values(array_filter(explode('/', $relativePath), 'strlen')));
    return implode('/', $segments);
}

function museumBuildMediaUrls(?string $rawImageValue, string $collection = '', bool $asThumbnail = false): array
{
    $normalized = museumNormalizeImageReference($rawImageValue);
    if ($normalized === null) {
        return [];
    }

    if (preg_match('#^https?://#i', $normalized) === 1) {
        return museumFileExtensionVariants($normalized);
    }

    $urls = [];
    foreach (museumFileExtensionVariants(ltrim($normalized, '/')) as $variant) {
        $encoded = museumEncodeMediaPath($variant);
        if ($encoded === '') {
            continue;
        }
        $relative = $asThumbnail ? ('thumbs/' . $encoded) : $encoded;
        $bazaUrl = 'https://baza.mkal.pl/gfx/' . $relative;
        $cdnUrl = 'https://mkalodz.pl/bazagfx/' . $relative;
        if ($collection === 'ksiazki-artystyczne') {
            $urls[] = $cdnUrl;
            $urls[] = $bazaUrl;
        } else {
            $urls[] = $bazaUrl;
            $urls[] = $cdnUrl;
        }
    }

    return array_values(array_unique($urls));
}

function museumImgSrcFallbackAttributes(array $urls): string
{
    $urls = array_values(array_filter($urls, static fn($url): bool => is_string($url) && $url !== ''));
    if ($urls === []) {
        return '';
    }

    $primary = array_shift($urls);
    $attr = 'src="' . htmlspecialchars($primary, ENT_QUOTES, 'UTF-8') . '"';
    if ($urls !== []) {
        $json = json_encode(array_values($urls), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $attr .= ' data-image-fallbacks="' . htmlspecialchars((string)$json, ENT_QUOTES, 'UTF-8') . '"';
        $attr .= ' onerror="if(typeof museumNextImageFallback===\'function\'){museumNextImageFallback(this);}else{(function(el){try{var u=JSON.parse(el.getAttribute(\'data-image-fallbacks\')||\'[]\');if(!u.length){el.onerror=null;return;}var n=u.shift();el.setAttribute(\'data-image-fallbacks\',JSON.stringify(u));if(n){el.src=n;}else{el.onerror=null;}}catch(e){el.onerror=null;}})(this);}"';
    }

    return $attr;
}

function museumEan13ChecksumIsValid(string $ean13): bool
{
    if (preg_match('/^[0-9]{13}$/', $ean13) !== 1) {
        return false;
    }

    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $digit = (int)$ean13[$i];
        $position = $i + 1;
        $sum += ($position % 2 === 0) ? ($digit * 3) : $digit;
    }

    $expectedChecksum = (10 - ($sum % 10)) % 10;
    return $expectedChecksum === (int)$ean13[12];
}

function museumTryDecodeInventoryNumberFromEan(string $rawValue): ?string
{
    $candidate = preg_replace('/\s+/', '', trim($rawValue));
    if (!is_string($candidate) || $candidate === '' || preg_match('/^[0-9]{13}$/', $candidate) !== 1) {
        return null;
    }

    if (!museumEan13ChecksumIsValid($candidate)) {
        return null;
    }

    $inventoryDigits = substr($candidate, 3, 9);
    if (!is_string($inventoryDigits) || $inventoryDigits === '') {
        return null;
    }

    $normalized = ltrim($inventoryDigits, '0');
    return $normalized === '' ? '0' : $normalized;
}

function museumNormalizeInventoryLookupValue(string $rawValue): string
{
    $trimmed = trim($rawValue);
    if ($trimmed === '') {
        return '';
    }

    $decoded = museumTryDecodeInventoryNumberFromEan($trimmed);
    if ($decoded !== null) {
        return $decoded;
    }

    return $trimmed;
}

function museumEnsureSequenceTable(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `inventory_sequences` (
            `sequence_key` VARCHAR(128) NOT NULL,
            `current_value` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`sequence_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $done = true;
}

function museumNextSequenceValue(PDO $pdo, string $sequenceKey): int
{
    museumEnsureSequenceTable($pdo);

    $insertStmt = $pdo->prepare(
        "INSERT IGNORE INTO `inventory_sequences` (`sequence_key`, `current_value`)
         VALUES (:sequence_key, 0)"
    );
    $insertStmt->execute(['sequence_key' => $sequenceKey]);

    $updateStmt = $pdo->prepare(
        "UPDATE `inventory_sequences`
         SET `current_value` = LAST_INSERT_ID(`current_value` + 1),
             `updated_at` = CURRENT_TIMESTAMP
         WHERE `sequence_key` = :sequence_key"
    );
    $updateStmt->execute(['sequence_key' => $sequenceKey]);

    $value = (int)$pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();
    if ($value <= 0) {
        throw new RuntimeException('Nie udało się pobrać kolejnej wartości sekwencji.');
    }

    return $value;
}

function museumInventorySequenceKey(string $collection): string
{
    return 'inventory_number:' . $collection;
}

function museumGlobalInventorySequenceKey(): string
{
    return 'inventory_number:global';
}

function museumInventoryTables(): array
{
    return [
        'karta_ewidencyjna',
        'karta_ewidencyjna_maszyny',
        'karta_ewidencyjna_matryce',
        'karta_ewidencyjna_bib',
        'karta_ewidencyjna_klisze',
    ];
}

function museumMoveSequenceKey(string $collection): string
{
    return 'move_number:' . $collection;
}

function museumCurrentMaxNumericValue(PDO $pdo, string $tableName, string $columnName): int
{
    $tableSql = museumQuoteIdentifier($tableName);
    $columnSql = museumQuoteIdentifier($columnName);
    $stmt = $pdo->query(
        "SELECT COALESCE(MAX(CAST({$columnSql} AS UNSIGNED)), 0)
         FROM {$tableSql}
         WHERE {$columnSql} IS NOT NULL
           AND CAST({$columnSql} AS CHAR) REGEXP '^[0-9]+$'"
    );
    return (int)$stmt->fetchColumn();
}

function museumCurrentGlobalMaxInventoryNumber(PDO $pdo): int
{
    $max = 0;
    foreach (museumInventoryTables() as $tableName) {
        try {
            $max = max($max, museumCurrentMaxNumericValue($pdo, $tableName, 'numer_ewidencyjny'));
        } catch (Throwable $ignored) {
            // Pomijamy brakującą tabelę/kolumnę, aby nie blokować działania na częściowo wdrożonej bazie.
        }
    }
    return $max;
}

function museumSyncSequenceToAtLeast(PDO $pdo, string $sequenceKey, int $minValue): void
{
    museumEnsureSequenceTable($pdo);

    $insertStmt = $pdo->prepare(
        "INSERT IGNORE INTO `inventory_sequences` (`sequence_key`, `current_value`)
         VALUES (:sequence_key, 0)"
    );
    $insertStmt->execute(['sequence_key' => $sequenceKey]);

    $updateStmt = $pdo->prepare(
        "UPDATE `inventory_sequences`
         SET `current_value` = GREATEST(`current_value`, :min_value),
             `updated_at` = CURRENT_TIMESTAMP
         WHERE `sequence_key` = :sequence_key"
    );
    $updateStmt->execute([
        'min_value' => max(0, $minValue),
        'sequence_key' => $sequenceKey,
    ]);
}

function museumNextInventoryNumberFromTable(PDO $pdo, string $tableName, string $collection): int
{
    $globalMax = museumCurrentGlobalMaxInventoryNumber($pdo);
    museumSyncSequenceToAtLeast($pdo, museumGlobalInventorySequenceKey(), $globalMax);
    return museumNextSequenceValue($pdo, museumGlobalInventorySequenceKey());
}

function museumSuggestedNextInventoryNumber(PDO $pdo, string $tableName, string $collection): int
{
    $globalMax = museumCurrentGlobalMaxInventoryNumber($pdo);
    museumSyncSequenceToAtLeast($pdo, museumGlobalInventorySequenceKey(), $globalMax);
    return $globalMax + 1;
}

function museumSuggestedNextInventoryNumberAfterDuplicate(PDO $pdo, string $tableName, string $collection, $attemptedInventoryNumber): int
{
    $suggested = museumSuggestedNextInventoryNumber($pdo, $tableName, $collection);

    $attempted = is_scalar($attemptedInventoryNumber) ? trim((string)$attemptedInventoryNumber) : '';
    if ($attempted !== '' && preg_match('/^[0-9]+$/', $attempted) === 1) {
        $attemptedInt = (int)$attempted;
        if ($suggested <= $attemptedInt) {
            $suggested = $attemptedInt + 1;
        }
    }

    return $suggested;
}

function museumIsInventoryNumberConstraintViolation(PDOException $e): bool
{
    $message = (string)$e->getMessage();
    $messageLower = strtolower($message);

    if (str_contains($messageLower, 'uniq_numer_ewidencyjny')) {
        return true;
    }

    // Fallback dla różnych formatów komunikatów MySQL/MariaDB.
    if (preg_match("/for key '([^']*numer_ewidencyjny[^']*)'/i", $message) === 1) {
        return true;
    }
    if (preg_match('/for key `([^`]*numer_ewidencyjny[^`]*)`/i', $message) === 1) {
        return true;
    }

    return false;
}

function museumInventoryNumberExistsAnywhere(PDO $pdo, string $inventoryNumber): bool
{
    $inventoryNumber = trim($inventoryNumber);
    if ($inventoryNumber === '') {
        return false;
    }

    foreach (museumInventoryTables() as $tableName) {
        try {
            $tableSql = museumQuoteIdentifier($tableName);
            $stmt = $pdo->prepare(
                "SELECT 1
                 FROM {$tableSql}
                 WHERE TRIM(CAST(`numer_ewidencyjny` AS CHAR)) = :inventory
                 LIMIT 1"
            );
            $stmt->execute(['inventory' => $inventoryNumber]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        } catch (Throwable $ignored) {
            // Pomijamy niedostępne tabele, żeby nie blokować zapisu.
        }
    }

    return false;
}

function museumEnsureUniqueInventoryNumberConstraint(PDO $pdo, string $tableName): void
{
    static $checked = [];
    $cacheKey = $tableName;
    if (isset($checked[$cacheKey])) {
        return;
    }

    $tableSql = museumQuoteIdentifier($tableName);

    $indexStmt = $pdo->query("SHOW INDEX FROM {$tableSql}");
    $hasUnique = false;
    while ($row = $indexStmt->fetch(PDO::FETCH_ASSOC)) {
        if (($row['Column_name'] ?? null) === 'numer_ewidencyjny' && (int)($row['Non_unique'] ?? 1) === 0) {
            $hasUnique = true;
            break;
        }
    }

    $checked[$cacheKey] = true;
    if ($hasUnique) {
        return;
    }

    // Unikalność włączamy migracją / panelem admina, nie ALTER-em na zwykłym requeście.
}

function museumEnsureBackupTables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `system_backup_runs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `backup_kind` VARCHAR(32) NOT NULL,
            `storage_scope` VARCHAR(32) NOT NULL DEFAULT 'local',
            `status` VARCHAR(16) NOT NULL,
            `file_name` VARCHAR(255) DEFAULT NULL,
            `file_path` VARCHAR(512) DEFAULT NULL,
            `file_size_bytes` BIGINT UNSIGNED DEFAULT NULL,
            `sha256_hash` CHAR(64) DEFAULT NULL,
            `initiated_by` VARCHAR(255) DEFAULT NULL,
            `note` TEXT,
            `retention_deleted_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `deleted_by_retention` TINYINT(1) NOT NULL DEFAULT 0,
            `deleted_at` DATETIME DEFAULT NULL,
            `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `finished_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_kind_status_finished` (`backup_kind`, `status`, `finished_at`),
            KEY `idx_started_at` (`started_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `system_restore_test_logs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `backup_run_id` BIGINT UNSIGNED DEFAULT NULL,
            `test_date` DATE NOT NULL,
            `result` VARCHAR(16) NOT NULL,
            `protocol_ref` VARCHAR(255) DEFAULT NULL,
            `tested_by` VARCHAR(255) DEFAULT NULL,
            `notes` TEXT,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_test_date` (`test_date`),
            KEY `idx_backup_run_id` (`backup_run_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $done = true;
}

function museumEnsureAttachmentTables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `record_attachments` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `collection` VARCHAR(64) NOT NULL,
            `record_id` INT UNSIGNED NOT NULL,
            `attachment_type` VARCHAR(64) NOT NULL DEFAULT 'inne',
            `title` VARCHAR(255) NOT NULL,
            `sprawa_ref` VARCHAR(255) DEFAULT NULL,
            `document_number` VARCHAR(255) DEFAULT NULL,
            `document_date` DATE DEFAULT NULL,
            `description` TEXT,
            `current_version_id` BIGINT UNSIGNED DEFAULT NULL,
            `current_version_no` INT UNSIGNED DEFAULT NULL,
            `created_by` VARCHAR(255) DEFAULT NULL,
            `updated_by` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_collection_record` (`collection`, `record_id`),
            KEY `idx_attachment_type` (`attachment_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `record_attachment_versions` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `attachment_id` BIGINT UNSIGNED NOT NULL,
            `version_no` INT UNSIGNED NOT NULL,
            `version_note` TEXT,
            `original_filename` VARCHAR(255) NOT NULL,
            `stored_filename` VARCHAR(255) NOT NULL,
            `stored_rel_path` VARCHAR(512) NOT NULL,
            `mime_type` VARCHAR(191) DEFAULT NULL,
            `file_ext` VARCHAR(16) DEFAULT NULL,
            `file_size_bytes` BIGINT UNSIGNED DEFAULT NULL,
            `sha256_hash` CHAR(64) DEFAULT NULL,
            `uploaded_by` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_attachment_version` (`attachment_id`, `version_no`),
            KEY `idx_attachment_created` (`attachment_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $done = true;
}

function museumAttachmentBaseDir(): string
{
    return __DIR__ . '/output/attachments';
}

function museumAttachmentBaseWebPath(): string
{
    return 'output/attachments';
}

function museumAllowedAttachmentExtensions(): array
{
    return [
        'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp',
        'tif', 'tiff',
        'doc', 'docx', 'odt', 'rtf', 'txt',
        'xls', 'xlsx', 'ods', 'csv',
        'ppt', 'pptx', 'odp',
        'zip', '7z',
    ];
}

function museumSanitizeFilename(string $filename): string
{
    $basename = basename($filename);
    $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '_', $basename);
    $sanitized = trim((string)$sanitized, '._-');
    if ($sanitized === '') {
        return 'plik';
    }

    $ext = strtolower((string)pathinfo($sanitized, PATHINFO_EXTENSION));
    $stem = (string)pathinfo($sanitized, PATHINFO_FILENAME);
    $stem = trim($stem, '._-');
    if ($stem === '') {
        $stem = 'plik';
    }
    if ($ext === '') {
        return $stem;
    }

    return $stem . '.' . $ext;
}

function museumDetectMimeType(string $filePath): ?string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $filePath);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
    }

    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($filePath);
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }
    }

    return null;
}

function museumStoreUploadedAttachmentVersion(
    string $collection,
    int $recordId,
    int $attachmentId,
    int $versionNo,
    array $fileInfo
): array {
    if (($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Nie udało się wgrać pliku (błąd uploadu).');
    }

    $tmpName = (string)($fileInfo['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Nieprawidłowy plik tymczasowy.');
    }

    $originalFilename = (string)($fileInfo['name'] ?? 'plik');
    $safeOriginal = museumSanitizeFilename($originalFilename);
    $ext = strtolower((string)pathinfo($safeOriginal, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, museumAllowedAttachmentExtensions(), true)) {
        throw new RuntimeException('Niedozwolony typ pliku. Dozwolone: PDF, obrazy, dokumenty biurowe, archiwa.');
    }

    $baseDir = rtrim(museumAttachmentBaseDir(), '/\\');
    $collectionPart = preg_replace('/[^a-z0-9_-]+/i', '-', $collection);
    $recordPart = 'record-' . $recordId;
    $attachmentPart = 'attachment-' . $attachmentId;
    $targetDir = $baseDir . '/' . $collectionPart . '/' . $recordPart . '/' . $attachmentPart;

    if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Nie udało się utworzyć katalogu załączników.');
    }

    $timestamp = date('Ymd_His');
    $random = substr(bin2hex(random_bytes(4)), 0, 8);
    $storedFilename = 'v' . $versionNo . '_' . $timestamp . '_' . $random . '_' . $safeOriginal;
    $targetPath = $targetDir . '/' . $storedFilename;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('Nie udało się zapisać pliku na dysku.');
    }

    $relativePath = museumAttachmentBaseWebPath()
        . '/' . $collectionPart
        . '/' . $recordPart
        . '/' . $attachmentPart
        . '/' . $storedFilename;

    $fileSize = @filesize($targetPath);
    $fileSize = $fileSize === false ? null : (int)$fileSize;
    $sha256 = hash_file('sha256', $targetPath) ?: null;
    $mimeType = museumDetectMimeType($targetPath);

    return [
        'original_filename' => $originalFilename,
        'stored_filename' => $storedFilename,
        'stored_rel_path' => $relativePath,
        'stored_abs_path' => $targetPath,
        'mime_type' => $mimeType,
        'file_ext' => $ext,
        'file_size_bytes' => $fileSize,
        'sha256_hash' => $sha256,
    ];
}

function museumBackupRetentionPolicy(): array
{
    return [
        'manual' => 20,
        'daily' => 14,
        'weekly' => 12,
        'monthly' => 24,
    ];
}

function museumBackupBaseDir(): string
{
    return __DIR__ . '/output/backups/sql';
}

function museumEnsureBackupDir(string $backupKind): string
{
    $dir = rtrim(museumBackupBaseDir(), '/\\') . '/' . preg_replace('/[^a-z0-9_-]+/i', '-', $backupKind);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Nie udało się utworzyć katalogu backupów: ' . $dir);
    }
    return $dir;
}

function museumBuildSqlDump(PDO $pdo): string
{
    $tablesStmt = $pdo->query('SHOW TABLES');
    $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

    $dump = "-- Export bazy danych\n";
    $dump .= '-- Wygenerowano: ' . date('Y-m-d H:i:s') . "\n\n";

    foreach ($tables as $table) {
        $tableQuoted = museumQuoteIdentifier((string)$table);
        $createStmt = $pdo->query('SHOW CREATE TABLE ' . $tableQuoted);
        $createRow = $createStmt->fetch(PDO::FETCH_ASSOC);
        $createSql = $createRow['Create Table'] ?? '';

        $dump .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $dump .= $createSql . ";\n\n";

        $rowsStmt = $pdo->query('SELECT * FROM ' . $tableQuoted);
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $columns = array_map(static function ($column) {
                return museumQuoteIdentifier((string)$column);
            }, array_keys($row));

            $values = array_map(static function ($value) use ($pdo) {
                if ($value === null) {
                    return 'NULL';
                }
                return $pdo->quote((string)$value);
            }, array_values($row));

            $dump .= 'INSERT INTO ' . $tableQuoted . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n";
        }

        $dump .= "\n";
    }

    return $dump;
}

function museumApplyBackupRetention(PDO $pdo, string $backupKind): int
{
    $policy = museumBackupRetentionPolicy();
    $keepCount = (int)($policy[$backupKind] ?? 0);
    if ($keepCount <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare(
        "SELECT `id`, `file_path`
         FROM `system_backup_runs`
         WHERE `backup_kind` = :backup_kind
           AND `status` = 'success'
           AND `storage_scope` = 'local'
           AND `deleted_by_retention` = 0
           AND `deleted_at` IS NULL
           AND `file_path` IS NOT NULL
         ORDER BY COALESCE(`finished_at`, `started_at`) DESC, `id` DESC"
    );
    $stmt->execute(['backup_kind' => $backupKind]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) <= $keepCount) {
        return 0;
    }

    $toDelete = array_slice($rows, $keepCount);
    $updateStmt = $pdo->prepare(
        "UPDATE `system_backup_runs`
         SET `deleted_by_retention` = 1,
             `deleted_at` = NOW()
         WHERE `id` = :id"
    );

    $deletedCount = 0;
    foreach ($toDelete as $row) {
        $filePath = (string)($row['file_path'] ?? '');
        if ($filePath !== '' && is_file($filePath)) {
            @unlink($filePath);
        }
        $updateStmt->execute(['id' => (int)$row['id']]);
        $deletedCount++;
    }

    return $deletedCount;
}

function museumRunSqlBackup(PDO $pdo, array $options = []): array
{
    museumEnsureBackupTables($pdo);

    $backupKind = (string)($options['backup_kind'] ?? 'manual');
    $storageScope = (string)($options['storage_scope'] ?? 'local');
    $initiatedBy = isset($options['initiated_by']) ? (string)$options['initiated_by'] : null;
    $note = isset($options['note']) ? (string)$options['note'] : null;
    $returnDump = !empty($options['return_dump']);

    $insertRunStmt = $pdo->prepare(
        "INSERT INTO `system_backup_runs`
        (`backup_kind`, `storage_scope`, `status`, `initiated_by`, `note`, `started_at`)
        VALUES (:backup_kind, :storage_scope, 'running', :initiated_by, :note, NOW())"
    );
    $insertRunStmt->execute([
        'backup_kind' => $backupKind,
        'storage_scope' => $storageScope,
        'initiated_by' => $initiatedBy,
        'note' => $note,
    ]);
    $runId = (int)$pdo->lastInsertId();

    try {
        $dump = museumBuildSqlDump($pdo);
        $backupDir = museumEnsureBackupDir($backupKind);
        $timestamp = date('Ymd_His');
        $randomSuffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $fileName = $backupKind . '_backup_' . $timestamp . '_' . $randomSuffix . '.sql';
        $filePath = $backupDir . '/' . $fileName;

        if (file_put_contents($filePath, $dump, LOCK_EX) === false) {
            throw new RuntimeException('Nie udało się zapisać pliku backupu.');
        }

        $sha256 = hash_file('sha256', $filePath) ?: null;
        $fileSize = @filesize($filePath);
        $fileSize = $fileSize === false ? null : (int)$fileSize;

        $retentionDeletedCount = museumApplyBackupRetention($pdo, $backupKind);

        $finishStmt = $pdo->prepare(
            "UPDATE `system_backup_runs`
             SET `status` = 'success',
                 `file_name` = :file_name,
                 `file_path` = :file_path,
                 `file_size_bytes` = :file_size_bytes,
                 `sha256_hash` = :sha256_hash,
                 `retention_deleted_count` = :retention_deleted_count,
                 `finished_at` = NOW()
             WHERE `id` = :id"
        );
        $finishStmt->execute([
            'file_name' => $fileName,
            'file_path' => $filePath,
            'file_size_bytes' => $fileSize,
            'sha256_hash' => $sha256,
            'retention_deleted_count' => $retentionDeletedCount,
            'id' => $runId,
        ]);

        return [
            'id' => $runId,
            'backup_kind' => $backupKind,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'file_size_bytes' => $fileSize,
            'sha256_hash' => $sha256,
            'retention_deleted_count' => $retentionDeletedCount,
            'dump' => $returnDump ? $dump : null,
        ];
    } catch (Throwable $e) {
        $failStmt = $pdo->prepare(
            "UPDATE `system_backup_runs`
             SET `status` = 'failed',
                 `note` = CONCAT(COALESCE(`note`, ''), CASE WHEN COALESCE(`note`, '') = '' THEN '' ELSE '\n' END, :error_note),
                 `finished_at` = NOW()
             WHERE `id` = :id"
        );
        $failStmt->execute([
            'error_note' => 'Błąd: ' . $e->getMessage(),
            'id' => $runId,
        ]);
        throw $e;
    }
}
