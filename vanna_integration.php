<?php

if (!function_exists('vannaGetRuntimeConfig')) {
    function vannaGetRuntimeConfig(): array
    {
        $dbConfig = [];
        if (isset($GLOBALS['config']) && is_array($GLOBALS['config'])) {
            $dbConfig = $GLOBALS['config'];
        }

        $provider = trim((string)(getenv('VANNA_PROVIDER') ?: ($dbConfig['vanna_provider'] ?? '')));
        $vannaApiKey = trim((string)(getenv('VANNA_API_KEY') ?: ($dbConfig['vanna_api_key'] ?? '')));
        $vannaModel = trim((string)(getenv('VANNA_MODEL') ?: ($dbConfig['vanna_model'] ?? '')));
        $openAiApiKey = trim((string)(getenv('OPENAI_API_KEY') ?: ($dbConfig['openai_api_key'] ?? '')));
        $openAiModel = trim((string)(getenv('VANNA_OPENAI_MODEL') ?: ($dbConfig['vanna_openai_model'] ?? 'gpt-4.1-mini')));

        if ($provider === '') {
            if ($vannaApiKey !== '' && $vannaModel !== '') {
                $provider = 'hosted';
            } elseif ($openAiApiKey !== '') {
                $provider = 'openai';
            }
        }

        $provider = strtolower($provider);
        $isConfigured = false;

        if ($provider === 'hosted') {
            $isConfigured = ($vannaApiKey !== '' && $vannaModel !== '');
        } elseif ($provider === 'openai') {
            $isConfigured = ($openAiApiKey !== '');
        }

        return [
            'provider' => $provider,
            'is_configured' => $isConfigured,
            'api_key' => $provider === 'openai' ? $openAiApiKey : $vannaApiKey,
            'model' => $provider === 'openai' ? $openAiModel : $vannaModel,
            'python_bin' => trim((string)(getenv('VANNA_PYTHON_BIN') ?: ($dbConfig['vanna_python_bin'] ?? ''))),
            'hint' => $dbConfig['vanna_hint'] ?? null,
            'bridge_cache_root' => __DIR__ . '/tmp/vanna_cache',
            'local_cache_root' => __DIR__ . '/tmp/vanna_local',
        ];
    }
}

if (!function_exists('vannaGetConfigurationMessage')) {
    function vannaGetConfigurationMessage(array $runtimeConfig): string
    {
        $provider = (string)($runtimeConfig['provider'] ?? '');
        if ($provider === 'hosted') {
            $hasApiKey = trim((string)($runtimeConfig['api_key'] ?? '')) !== '';
            $hasModel = trim((string)($runtimeConfig['model'] ?? '')) !== '';

            if ($hasApiKey && !$hasModel) {
                return 'Klucz Vanna jest ustawiony, ale brakuje VANNA_MODEL (nazwy modelu/workspace w Vanna).';
            }

            if (!$hasApiKey && $hasModel) {
                return 'Model Vanna jest ustawiony, ale brakuje VANNA_API_KEY.';
            }

            return 'Ustaw VANNA_API_KEY oraz VANNA_MODEL.';
        }

        if ($provider === 'openai') {
            return 'Ustaw OPENAI_API_KEY. Opcjonalnie mozesz wskazac VANNA_OPENAI_MODEL.';
        }

        return 'Ustaw VANNA_API_KEY i VANNA_MODEL albo OPENAI_API_KEY, aby wlaczyc Vanna AI.';
    }
}

if (!function_exists('vannaQuoteIdentifier')) {
    function vannaQuoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}

if (!function_exists('vannaFetchCreateTableSql')) {
    function vannaFetchCreateTableSql(PDO $pdo, string $tableName): ?string
    {
        if ($tableName === '') {
            return null;
        }

        try {
            $stmt = $pdo->query('SHOW CREATE TABLE ' . vannaQuoteIdentifier($tableName));
            $row = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : false;
        } catch (Throwable $e) {
            return null;
        }

        if (!is_array($row) || !isset($row[1]) || !is_string($row[1]) || trim($row[1]) === '') {
            return null;
        }

        return $row[1];
    }
}

if (!function_exists('vannaNormalizeSql')) {
    function vannaNormalizeSql(string $sql): string
    {
        $sql = trim($sql);
        if ($sql === '') {
            return '';
        }

        if (preg_match('/^```(?:sql)?\s*(.*?)\s*```$/is', $sql, $matches) === 1) {
            $sql = trim((string)$matches[1]);
        }

        return rtrim($sql, " \t\n\r\0\x0B;");
    }
}

if (!function_exists('vannaValidateReadonlySql')) {
    function vannaValidateReadonlySql(string $sql): ?string
    {
        $normalizedSql = vannaNormalizeSql($sql);
        if ($normalizedSql === '') {
            return 'Vanna nie zwrocila poprawnego SQL.';
        }

        if (strpos($normalizedSql, ';') !== false) {
            return 'Dozwolone jest tylko jedno zapytanie SQL.';
        }

        if (preg_match('/^\s*(SELECT|WITH|SHOW|DESCRIBE|EXPLAIN)\b/i', $normalizedSql) !== 1) {
            return 'Dozwolone sa tylko zapytania odczytowe (SELECT/WITH/SHOW/DESCRIBE/EXPLAIN).';
        }

        if (preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|TRUNCATE|CREATE|RENAME|GRANT|REVOKE|CALL|HANDLER|LOAD\s+DATA|INTO\s+OUTFILE|INTO\s+DUMPFILE|LOCK|UNLOCK)\b/i', $normalizedSql) === 1) {
            return 'Wygenerowane zapytanie zawiera operacje modyfikujace lub administracyjne.';
        }

        if (preg_match('/\b(karta_ewidencyjna_users|password_reset_tokens|mobile_login_tokens|system_backup_runs|record_share_links)\b/i', $normalizedSql) === 1) {
            return 'Zapytanie odwoluje sie do tabel chronionych.';
        }

        return null;
    }
}

if (!function_exists('vannaApplySafeLimit')) {
    function vannaApplySafeLimit(string $sql, int $limit = 100): string
    {
        $normalizedSql = vannaNormalizeSql($sql);
        if ($normalizedSql === '') {
            return '';
        }

        if (preg_match('/^\s*(SHOW|DESCRIBE|EXPLAIN)\b/i', $normalizedSql) === 1) {
            return $normalizedSql;
        }

        if (preg_match('/\bLIMIT\s+\d+\b/i', $normalizedSql) === 1) {
            return $normalizedSql;
        }

        return $normalizedSql . ' LIMIT ' . max(1, $limit);
    }
}

if (!function_exists('vannaBuildTrainingPayload')) {
    function vannaBuildTrainingPayload(
        PDO $pdo,
        array $runtimeConfig,
        array $collectionMeta,
        string $selectedCollection,
        string $selectedLedger
    ): array {
        $tableCandidates = [];
        foreach (['main', 'log', 'moves'] as $key) {
            if (!empty($collectionMeta[$key]) && is_string($collectionMeta[$key])) {
                $tableCandidates[] = $collectionMeta[$key];
            }
        }
        $tableCandidates[] = 'lists';
        $tableCandidates[] = 'list_items';

        $ddl = [];
        foreach (array_values(array_unique($tableCandidates)) as $tableName) {
            $createSql = vannaFetchCreateTableSql($pdo, (string)$tableName);
            if ($createSql !== null) {
                $ddl[] = $createSql;
            }
        }

        $collectionLabel = (string)($collectionMeta['label'] ?? $selectedCollection);
        $mainTable = (string)($collectionMeta['main'] ?? '');
        $logTable = (string)($collectionMeta['log'] ?? '');
        $movesTable = (string)($collectionMeta['moves'] ?? '');
        $ledgerLabel = (string)(
            $GLOBALS['app_ledger_definitions'][$selectedLedger]['label']
            ?? $selectedLedger
        );

        $documentation = [
            'Aplikacja muzealna. Odpowiadaj tylko zapytaniami SELECT, SHOW, DESCRIBE albo EXPLAIN.',
            'Biezaca kolekcja to "' . $collectionLabel . '" i aktywna ksiega to "' . $ledgerLabel . '".',
            'Tabela glowna dla tej kolekcji to "' . $mainTable . '". Najczesciej uzywane pola to numer_ewidencyjny, nazwa_tytul, autor_wytworca i dokumentacja_wizualna.',
            'Tabela "lists" przechowuje listy, a "list_items" laczy listy z rekordami przez kolumny list_id i entry_id.',
            'Jesli potrzebujesz historii zmian, uzyj tabeli "' . $logTable . '". Jesli potrzebujesz przemieszczen, uzyj tabeli "' . $movesTable . '".',
            'Preferuj LIMIT 100 lub mniej oraz aliasy kolumn po polsku, gdy to pomaga czytelnosci.',
        ];

        if (!empty($runtimeConfig['hint']) && is_string($runtimeConfig['hint'])) {
            $documentation[] = trim($runtimeConfig['hint']);
        }

        $examples = [];
        if ($mainTable !== '') {
            $examples[] = [
                'question' => 'Ile rekordow znajduje sie w biezacej kolekcji?',
                'sql' => 'SELECT COUNT(*) AS liczba_rekordow FROM ' . vannaQuoteIdentifier($mainTable),
            ];
            $examples[] = [
                'question' => 'Pokaz 10 najnowszych rekordow z biezacej kolekcji.',
                'sql' => 'SELECT * FROM ' . vannaQuoteIdentifier($mainTable) . ' ORDER BY ID DESC LIMIT 10',
            ];
            $examples[] = [
                'question' => 'Znajdz rekordy po numerze ewidencyjnym.',
                'sql' => 'SELECT * FROM ' . vannaQuoteIdentifier($mainTable) . ' WHERE numer_ewidencyjny LIKE "%123%" LIMIT 25',
            ];
        }

        $schemaFingerprint = hash(
            'sha256',
            json_encode(
                [
                    'provider' => $runtimeConfig['provider'] ?? '',
                    'model' => $runtimeConfig['model'] ?? '',
                    'collection' => $selectedCollection,
                    'ledger' => $selectedLedger,
                    'ddl' => $ddl,
                    'documentation' => $documentation,
                    'examples' => $examples,
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) ?: ''
        );

        return [
            'provider' => $runtimeConfig['provider'] ?? '',
            'api_key' => $runtimeConfig['api_key'] ?? '',
            'model' => $runtimeConfig['model'] ?? '',
            'cache_namespace' => $selectedCollection . '_' . $selectedLedger,
            'schema_hash' => $schemaFingerprint,
            'ddl' => $ddl,
            'documentation' => $documentation,
            'examples' => $examples,
            'bridge_cache_root' => $runtimeConfig['bridge_cache_root'] ?? (__DIR__ . '/tmp/vanna_cache'),
            'local_cache_root' => $runtimeConfig['local_cache_root'] ?? (__DIR__ . '/tmp/vanna_local'),
        ];
    }
}

if (!function_exists('vannaEnsureSavedQueriesTable')) {
    function vannaEnsureSavedQueriesTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS vanna_saved_queries (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                provider VARCHAR(32) NOT NULL,
                collection_key VARCHAR(64) NOT NULL,
                ledger_key VARCHAR(64) NOT NULL,
                prompt_text TEXT NOT NULL,
                prompt_hash CHAR(64) NOT NULL,
                generated_sql MEDIUMTEXT NOT NULL,
                execution_sql MEDIUMTEXT NOT NULL,
                created_by_user_id INT NULL,
                usage_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_used_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_vanna_saved_prompt (provider, collection_key, ledger_key, prompt_hash),
                KEY idx_vanna_saved_prompt_scope (collection_key, ledger_key, prompt_hash),
                KEY idx_vanna_saved_scope (provider, collection_key, ledger_key, updated_at),
                KEY idx_vanna_saved_usage (collection_key, ledger_key, last_used_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}

if (!function_exists('vannaNormalizePromptForCache')) {
    function vannaNormalizePromptForCache(string $prompt): string
    {
        $normalized = trim($prompt);
        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        if (!is_string($normalized)) {
            $normalized = trim($prompt);
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($normalized, 'UTF-8');
        }

        return strtolower($normalized);
    }
}

if (!function_exists('vannaPromptHash')) {
    function vannaPromptHash(string $prompt): string
    {
        return hash('sha256', vannaNormalizePromptForCache($prompt));
    }
}

if (!function_exists('vannaFetchSavedQueryByPrompt')) {
    function vannaFetchSavedQueryByPrompt(
        PDO $pdo,
        string $collectionKey,
        string $ledgerKey,
        string $prompt
    ): ?array {
        $stmt = $pdo->prepare(
            'SELECT *
             FROM vanna_saved_queries
             WHERE collection_key = :collection_key
               AND ledger_key = :ledger_key
               AND prompt_hash = :prompt_hash
             ORDER BY updated_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'collection_key' => $collectionKey,
            'ledger_key' => $ledgerKey,
            'prompt_hash' => vannaPromptHash($prompt),
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('vannaFetchSavedQueryById')) {
    function vannaFetchSavedQueryById(
        PDO $pdo,
        int $savedQueryId,
        string $collectionKey,
        string $ledgerKey
    ): ?array {
        $stmt = $pdo->prepare(
            'SELECT *
             FROM vanna_saved_queries
             WHERE id = :id
               AND collection_key = :collection_key
               AND ledger_key = :ledger_key
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $savedQueryId,
            'collection_key' => $collectionKey,
            'ledger_key' => $ledgerKey,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('vannaTouchSavedQueryUsage')) {
    function vannaTouchSavedQueryUsage(PDO $pdo, int $savedQueryId): void
    {
        $stmt = $pdo->prepare(
            'UPDATE vanna_saved_queries
             SET usage_count = usage_count + 1,
                 last_used_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute(['id' => $savedQueryId]);
    }
}

if (!function_exists('vannaSaveSavedQuery')) {
    function vannaSaveSavedQuery(
        PDO $pdo,
        string $provider,
        string $collectionKey,
        string $ledgerKey,
        string $prompt,
        string $generatedSql,
        string $executionSql,
        ?int $createdByUserId = null
    ): void {
        $existing = vannaFetchSavedQueryByPrompt(
            $pdo,
            $collectionKey,
            $ledgerKey,
            $prompt
        );

        if ($existing !== null) {
            $stmt = $pdo->prepare(
                'UPDATE vanna_saved_queries
                 SET prompt_text = :prompt_text,
                     prompt_hash = :prompt_hash,
                     generated_sql = :generated_sql,
                     execution_sql = :execution_sql,
                     created_by_user_id = COALESCE(created_by_user_id, :created_by_user_id),
                     usage_count = usage_count + 1,
                     last_used_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => (int)($existing['id'] ?? 0),
                'prompt_text' => trim($prompt),
                'prompt_hash' => vannaPromptHash($prompt),
                'generated_sql' => trim($generatedSql),
                'execution_sql' => trim($executionSql),
                'created_by_user_id' => $createdByUserId,
            ]);
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO vanna_saved_queries (
                provider,
                collection_key,
                ledger_key,
                prompt_text,
                prompt_hash,
                generated_sql,
                execution_sql,
                created_by_user_id,
                usage_count,
                last_used_at
            ) VALUES (
                :provider,
                :collection_key,
                :ledger_key,
                :prompt_text,
                :prompt_hash,
                :generated_sql,
                :execution_sql,
                :created_by_user_id,
                1,
                CURRENT_TIMESTAMP
            )'
        );
        $stmt->execute([
            'provider' => $provider,
            'collection_key' => $collectionKey,
            'ledger_key' => $ledgerKey,
            'prompt_text' => trim($prompt),
            'prompt_hash' => vannaPromptHash($prompt),
            'generated_sql' => trim($generatedSql),
            'execution_sql' => trim($executionSql),
            'created_by_user_id' => $createdByUserId,
        ]);
    }
}

if (!function_exists('vannaListSavedQueries')) {
    function vannaListSavedQueries(
        PDO $pdo,
        string $collectionKey,
        string $ledgerKey,
        int $limit = 12
    ): array {
        $limit = max(1, min(50, $limit));

        $stmt = $pdo->prepare(
            'SELECT *
             FROM vanna_saved_queries
             WHERE collection_key = :collection_key
               AND ledger_key = :ledger_key
             ORDER BY
                CASE WHEN last_used_at IS NULL THEN 1 ELSE 0 END ASC,
                last_used_at DESC,
                updated_at DESC,
                id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([
            'collection_key' => $collectionKey,
            'ledger_key' => $ledgerKey,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('vannaResolvePythonBinary')) {
    function vannaResolvePythonBinary(): string
    {
        $configuredPython = trim((string)(getenv('VANNA_PYTHON_BIN') ?: (($GLOBALS['config']['vanna_python_bin'] ?? ''))));
        if ($configuredPython !== '') {
            if (strpos($configuredPython, DIRECTORY_SEPARATOR) === false) {
                return $configuredPython;
            }
            if (is_file($configuredPython) && is_executable($configuredPython)) {
                return $configuredPython;
            }
        }

        $candidates = [
            'python3',
            'python',
            __DIR__ . '/.venv/bin/python',
            __DIR__ . '/.venv/bin/python3',
        ];

        foreach ($candidates as $candidate) {
            if (strpos($candidate, DIRECTORY_SEPARATOR) !== false) {
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
                continue;
            }

            return $candidate;
        }

        return 'python3';
    }
}

if (!function_exists('vannaProcessRunnerStatus')) {
    function vannaProcessRunnerStatus(): array
    {
        $candidates = ['proc_open', 'exec', 'shell_exec'];
        $status = [];

        foreach ($candidates as $candidate) {
            $status[$candidate] = [
                'exists' => function_exists($candidate),
                'callable' => is_callable($candidate),
            ];
        }

        return $status;
    }
}

if (!function_exists('vannaDescribeProcessRunnerStatus')) {
    function vannaDescribeProcessRunnerStatus(): string
    {
        $status = vannaProcessRunnerStatus();
        $parts = [];

        foreach ($status as $name => $meta) {
            $parts[] = $name
                . '(exists=' . (!empty($meta['exists']) ? 'yes' : 'no')
                . ',callable=' . (!empty($meta['callable']) ? 'yes' : 'no') . ')';
        }

        return implode(', ', $parts);
    }
}

if (!function_exists('vannaGenerateSqlWithBridge')) {
    function vannaDetectProcessRunner(): ?string
    {
        foreach (vannaProcessRunnerStatus() as $candidate => $meta) {
            if (!empty($meta['exists'])) {
                return $candidate;
            }
        }

        return null;
    }
}

if (!function_exists('vannaRunBridgeViaProcOpen')) {
    function vannaRunBridgeViaProcOpen(array $commandParts, string $encodedPayload): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($commandParts, $descriptors, $pipes, __DIR__);
        if (!is_resource($process)) {
            return ['ok' => false, 'error' => 'proc_open nie uruchomil procesu Pythona dla Vanna AI.'];
        }

        fwrite($pipes[0], $encodedPayload);
        fclose($pipes[0]);

        stream_set_timeout($pipes[1], 30);
        stream_set_timeout($pipes[2], 30);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'stdout' => is_string($stdout) ? trim($stdout) : '',
            'stderr' => is_string($stderr) ? trim($stderr) : '',
            'exit_code' => $exitCode,
        ];
    }
}

if (!function_exists('vannaShellQuoteArg')) {
    function vannaShellQuoteArg(string $value): string
    {
        if (function_exists('escapeshellarg') && is_callable('escapeshellarg')) {
            $quoted = escapeshellarg($value);
            if (is_string($quoted) && $quoted !== '') {
                return $quoted;
            }
        }

        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
    }
}

if (!function_exists('vannaBuildShellCommand')) {
    function vannaBuildShellCommand(array $commandParts): string
    {
        $quotedParts = [];
        foreach ($commandParts as $part) {
            $quotedParts[] = vannaShellQuoteArg((string)$part);
        }

        return implode(' ', $quotedParts);
    }
}

if (!function_exists('vannaRunBridgeViaExec')) {
    function vannaRunBridgeViaExec(array $commandParts, string $encodedPayload): array
    {
        $payloadFile = tempnam(sys_get_temp_dir(), 'vanna_payload_');
        if ($payloadFile === false) {
            return ['ok' => false, 'error' => 'Nie udalo sie utworzyc pliku tymczasowego dla Vanna AI.'];
        }

        $payloadWritten = @file_put_contents($payloadFile, $encodedPayload);
        if ($payloadWritten === false) {
            @unlink($payloadFile);
            return ['ok' => false, 'error' => 'Nie udalo sie zapisac payload do pliku tymczasowego dla Vanna AI.'];
        }

        $output = [];
        $exitCode = 0;
        $shellCommand = vannaBuildShellCommand(array_merge($commandParts, [$payloadFile]));
        @exec($shellCommand . ' 2>&1', $output, $exitCode);
        @unlink($payloadFile);

        return [
            'stdout' => trim(implode("\n", $output)),
            'stderr' => '',
            'exit_code' => $exitCode,
        ];
    }
}

if (!function_exists('vannaRunBridgeViaShellExec')) {
    function vannaRunBridgeViaShellExec(array $commandParts, string $encodedPayload): array
    {
        $payloadFile = tempnam(sys_get_temp_dir(), 'vanna_payload_');
        if ($payloadFile === false) {
            return ['ok' => false, 'error' => 'Nie udalo sie utworzyc pliku tymczasowego dla Vanna AI.'];
        }

        $payloadWritten = @file_put_contents($payloadFile, $encodedPayload);
        if ($payloadWritten === false) {
            @unlink($payloadFile);
            return ['ok' => false, 'error' => 'Nie udalo sie zapisac payload do pliku tymczasowego dla Vanna AI.'];
        }

        $shellCommand = vannaBuildShellCommand(array_merge($commandParts, [$payloadFile]));
        $output = @shell_exec($shellCommand . ' 2>&1');
        @unlink($payloadFile);

        return [
            'stdout' => is_string($output) ? trim($output) : '',
            'stderr' => '',
            'exit_code' => 0,
        ];
    }
}

if (!function_exists('vannaDecodeBridgeResponse')) {
    function vannaDecodeBridgeResponse(array $bridgeIo, string $runnerName): array
    {
        if (isset($bridgeIo['ok']) && $bridgeIo['ok'] === false) {
            return $bridgeIo;
        }

        $stdout = (string)($bridgeIo['stdout'] ?? '');
        $stderr = (string)($bridgeIo['stderr'] ?? '');
        $exitCode = (int)($bridgeIo['exit_code'] ?? 1);

        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            $error = 'Mostek Vanna zwrocil nieprawidlowy format odpowiedzi.';
            if ($stdout !== '') {
                $error .= ' Output: ' . $stdout;
            } elseif ($stderr !== '') {
                $error .= ' ' . $stderr;
            }

            return [
                'ok' => false,
                'error' => $error,
                'runner' => $runnerName,
                'exit_code' => $exitCode,
            ];
        }

        if ($exitCode !== 0 || empty($decoded['ok'])) {
            $decoded['ok'] = false;
            if (empty($decoded['error'])) {
                $decoded['error'] = 'Vanna AI nie zwrocila poprawnego wyniku.';
            }
            if ($stderr !== '') {
                $decoded['details'] = $stderr;
            }
            $decoded['runner'] = $runnerName;
            $decoded['exit_code'] = $exitCode;

            return $decoded;
        }

        $decoded['runner'] = $runnerName;
        $decoded['sql'] = vannaNormalizeSql((string)($decoded['sql'] ?? ''));

        return $decoded;
    }
}

if (!function_exists('vannaLocateBridgeScript')) {
    function vannaLocateBridgeScript(): array
    {
        $candidates = [
            __DIR__ . '/cli/vanna_bridge.py',
            __DIR__ . '/vanna_bridge.py',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return [
                    'path' => $candidate,
                    'checked_paths' => $candidates,
                ];
            }
        }

        return [
            'path' => null,
            'checked_paths' => $candidates,
        ];
    }
}

if (!function_exists('vannaBuildDirectOpenAiMessages')) {
    function vannaBuildDirectOpenAiMessages(array $payload): array
    {
        $documentationBlocks = [];
        foreach ((array)($payload['documentation'] ?? []) as $item) {
            if (is_string($item) && trim($item) !== '') {
                $documentationBlocks[] = trim($item);
            }
        }

        $ddlBlocks = [];
        foreach ((array)($payload['ddl'] ?? []) as $item) {
            if (is_string($item) && trim($item) !== '') {
                $ddlBlocks[] = trim($item);
            }
        }

        $exampleBlocks = [];
        foreach ((array)($payload['examples'] ?? []) as $example) {
            if (!is_array($example)) {
                continue;
            }

            $question = trim((string)($example['question'] ?? ''));
            $sql = trim((string)($example['sql'] ?? ''));
            if ($question === '' || $sql === '') {
                continue;
            }

            $exampleBlocks[] = "Pytanie: {$question}\nSQL: {$sql}";
        }

        $systemParts = [
            'You generate exactly one read-only MySQL query for a PHP museum inventory application.',
            'Return only raw SQL with no markdown, no prose, and no code fences.',
            'Allowed statements: SELECT, WITH, SHOW, DESCRIBE, EXPLAIN.',
            'Never produce INSERT, UPDATE, DELETE, DROP, ALTER, CREATE, TRUNCATE, or multiple statements.',
            'Prefer concise MySQL-compatible SQL and include LIMIT 100 or less unless the question clearly asks for a smaller number.',
        ];

        if ($documentationBlocks !== []) {
            $systemParts[] = "Context:\n" . implode("\n", $documentationBlocks);
        }

        if ($ddlBlocks !== []) {
            $systemParts[] = "Relevant schema:\n" . implode("\n\n", $ddlBlocks);
        }

        if ($exampleBlocks !== []) {
            $systemParts[] = "Examples:\n" . implode("\n\n", $exampleBlocks);
        }

        return [
            [
                'role' => 'system',
                'content' => implode("\n\n", $systemParts),
            ],
            [
                'role' => 'user',
                'content' => trim((string)($payload['question'] ?? '')),
            ],
        ];
    }
}

if (!function_exists('vannaExtractChatCompletionText')) {
    function vannaExtractChatCompletionText(array $decoded): string
    {
        $choices = $decoded['choices'] ?? null;
        if (!is_array($choices) || !isset($choices[0]) || !is_array($choices[0])) {
            return '';
        }

        $message = $choices[0]['message'] ?? null;
        if (!is_array($message)) {
            return '';
        }

        $content = $message['content'] ?? '';
        if (is_string($content)) {
            return trim($content);
        }

        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $parts[] = $part['text'];
                }
            }

            return trim(implode("\n", $parts));
        }

        return '';
    }
}

if (!function_exists('vannaHttpPostJson')) {
    function vannaHttpPostJson(string $url, array $headers, array $body): array
    {
        $encodedBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedBody === false) {
            return ['ok' => false, 'error' => 'Nie udalo sie zakodowac payload HTTP do OpenAI.'];
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['ok' => false, 'error' => 'curl_init nie uruchomilo polaczenia do OpenAI.'];
            }

            $normalizedHeaders = [];
            foreach ($headers as $name => $value) {
                $normalizedHeaders[] = $name . ': ' . $value;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $normalizedHeaders,
                CURLOPT_POSTFIELDS => $encodedBody,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_CONNECTTIMEOUT => 15,
            ]);

            $responseBody = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($responseBody === false) {
                return ['ok' => false, 'error' => 'Nie udalo sie polaczyc z OpenAI przez cURL.', 'details' => $curlError];
            }

            return [
                'ok' => true,
                'status' => $httpCode,
                'body' => (string)$responseBody,
            ];
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $encodedBody,
                'timeout' => 45,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            return ['ok' => false, 'error' => 'Nie udalo sie polaczyc z OpenAI przez HTTP stream.'];
        }

        $statusCode = 0;
        $responseHeaders = $http_response_header ?? [];
        foreach ((array)$responseHeaders as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', (string)$line, $matches) === 1) {
                $statusCode = (int)$matches[1];
                break;
            }
        }

        return [
            'ok' => true,
            'status' => $statusCode,
            'body' => (string)$responseBody,
        ];
    }
}

if (!function_exists('vannaGenerateSqlDirectViaOpenAi')) {
    function vannaGenerateSqlDirectViaOpenAi(array $payload): array
    {
        $apiKey = trim((string)($payload['api_key'] ?? ''));
        $model = trim((string)($payload['model'] ?? 'gpt-4.1-mini'));
        $question = trim((string)($payload['question'] ?? ''));

        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'Brakuje OPENAI_API_KEY dla bezposredniego fallbacku OpenAI.'];
        }

        if ($question === '') {
            return ['ok' => false, 'error' => 'Pytanie do OpenAI nie moze byc puste.'];
        }

        $httpResponse = vannaHttpPostJson(
            'https://api.openai.com/v1/chat/completions',
            [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            [
                'model' => $model,
                'messages' => vannaBuildDirectOpenAiMessages($payload),
            ]
        );

        if (empty($httpResponse['ok'])) {
            return $httpResponse;
        }

        $status = (int)($httpResponse['status'] ?? 0);
        $body = (string)($httpResponse['body'] ?? '');
        $decoded = json_decode($body, true);

        if ($status >= 400) {
            $message = 'OpenAI zwrocilo blad HTTP ' . $status . '.';
            if (is_array($decoded) && isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                $message .= ' ' . $decoded['error']['message'];
            }

            return [
                'ok' => false,
                'error' => $message,
            ];
        }

        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'error' => 'OpenAI zwrocilo nieprawidlowy JSON.',
                'details' => $body,
            ];
        }

        $sql = vannaExtractChatCompletionText($decoded);
        if ($sql === '') {
            return [
                'ok' => false,
                'error' => 'OpenAI nie zwrocilo tekstu SQL.',
            ];
        }

        return [
            'ok' => true,
            'sql' => vannaNormalizeSql($sql),
            'runner' => 'openai_http',
        ];
    }
}

if (!function_exists('vannaRpcCall')) {
    function vannaRpcCall(string $method, array $params, string $apiKey, string $org): array
    {
        $httpResponse = vannaHttpPostJson(
            'https://ask.vanna.ai/rpc',
            [
                'Content-Type' => 'application/json',
                'Vanna-Key' => $apiKey,
                'Vanna-Org' => $org,
            ],
            [
                'method' => $method,
                'params' => $params,
            ]
        );

        if (empty($httpResponse['ok'])) {
            return $httpResponse;
        }

        $status = (int)($httpResponse['status'] ?? 0);
        $body = (string)($httpResponse['body'] ?? '');
        $decoded = json_decode($body, true);

        if ($status >= 400) {
            return [
                'ok' => false,
                'error' => 'Vanna zwrocila blad HTTP ' . $status . '.',
                'details' => $body,
            ];
        }

        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'error' => 'Vanna zwrocila nieprawidlowy JSON.',
                'details' => $body,
            ];
        }

        if (isset($decoded['error'])) {
            $message = 'Vanna zwrocila blad RPC.';
            if (is_array($decoded['error']) && isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                $message = 'Vanna zwrocila blad RPC: ' . $decoded['error']['message'];
            }

            return [
                'ok' => false,
                'error' => $message,
                'details' => $body,
            ];
        }

        return [
            'ok' => true,
            'result' => $decoded['result'] ?? null,
        ];
    }
}

if (!function_exists('vannaGenerateSqlDirectViaHosted')) {
    function vannaGenerateSqlDirectViaHosted(array $payload): array
    {
        $apiKey = trim((string)($payload['api_key'] ?? ''));
        $model = trim((string)($payload['model'] ?? ''));
        $question = trim((string)($payload['question'] ?? ''));

        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'Brakuje VANNA_API_KEY dla trybu hosted.'];
        }

        if ($model === '') {
            return ['ok' => false, 'error' => 'Brakuje VANNA_MODEL dla trybu hosted.'];
        }

        if ($question === '') {
            return ['ok' => false, 'error' => 'Pytanie do Vanna nie moze byc puste.'];
        }

        $messages = vannaBuildDirectOpenAiMessages($payload);
        $prompt = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($prompt === false) {
            return ['ok' => false, 'error' => 'Nie udalo sie zakodowac promptu dla Vanna hosted.'];
        }

        $rpcResponse = vannaRpcCall(
            'submit_prompt',
            [
                ['data' => $prompt],
            ],
            $apiKey,
            $model
        );

        if (empty($rpcResponse['ok'])) {
            return $rpcResponse;
        }

        $result = $rpcResponse['result'] ?? null;
        $sql = '';

        if (is_array($result) && isset($result['data']) && is_string($result['data'])) {
            $sql = trim($result['data']);
        } elseif (is_string($result)) {
            $sql = trim($result);
        }

        if ($sql === '') {
            return [
                'ok' => false,
                'error' => 'Vanna hosted nie zwrocila SQL.',
            ];
        }

        return [
            'ok' => true,
            'sql' => vannaNormalizeSql($sql),
            'runner' => 'vanna_http',
        ];
    }
}

if (!function_exists('vannaGenerateSqlWithBridge')) {
    function vannaGenerateSqlWithBridge(array $payload): array
    {
        if (($payload['provider'] ?? '') === 'hosted') {
            return vannaGenerateSqlDirectViaHosted($payload);
        }

        $runner = vannaDetectProcessRunner();
        if ($runner === null && ($payload['provider'] ?? '') === 'openai') {
            $directResponse = vannaGenerateSqlDirectViaOpenAi($payload);
            if (!empty($directResponse['ok'])) {
                return $directResponse;
            }

            return [
                'ok' => false,
                'error' => (string)($directResponse['error'] ?? 'Bezposredni fallback OpenAI nie powiodl sie.'),
                'details' => (string)($directResponse['details'] ?? ''),
            ];
        }

        $bridgeScript = vannaLocateBridgeScript();
        $bridgePath = $bridgeScript['path'] ?? null;
        if (!is_string($bridgePath) || $bridgePath === '') {
            return [
                'ok' => false,
                'error' => 'Brakuje pliku mostka Vanna (vanna_bridge.py).',
                'details' => 'Sprawdzone sciezki: ' . implode(', ', (array)($bridgeScript['checked_paths'] ?? [])),
            ];
        }

        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedPayload === false) {
            return ['ok' => false, 'error' => 'Nie udalo sie przygotowac danych dla Vanna AI.'];
        }

        $commandParts = [vannaResolvePythonBinary(), $bridgePath];
        if ($runner === null) {
            return [
                'ok' => false,
                'error' => 'Biezacy proces PHP nie raportuje dostepnej metody uruchomienia procesu (proc_open, exec ani shell_exec).',
                'details' => 'SAPI: ' . PHP_SAPI
                    . '; disable_functions=' . (string)ini_get('disable_functions')
                    . '; runners=' . vannaDescribeProcessRunnerStatus(),
            ];
        }

        if ($runner === 'proc_open') {
            $bridgeIo = vannaRunBridgeViaProcOpen($commandParts, $encodedPayload);
            if (!isset($bridgeIo['ok']) || $bridgeIo['ok'] !== false) {
                return vannaDecodeBridgeResponse($bridgeIo, $runner);
            }

            if (function_exists('exec')) {
                $execIo = vannaRunBridgeViaExec($commandParts, $encodedPayload);
                if (!isset($execIo['ok']) || $execIo['ok'] !== false) {
                    return vannaDecodeBridgeResponse($execIo, 'exec');
                }

                if (function_exists('shell_exec')) {
                    $shellIo = vannaRunBridgeViaShellExec($commandParts, $encodedPayload);
                    if (!isset($shellIo['ok']) || $shellIo['ok'] !== false) {
                        return vannaDecodeBridgeResponse($shellIo, 'shell_exec');
                    }
                }
            } elseif (function_exists('shell_exec')) {
                $shellIo = vannaRunBridgeViaShellExec($commandParts, $encodedPayload);
                if (!isset($shellIo['ok']) || $shellIo['ok'] !== false) {
                    return vannaDecodeBridgeResponse($shellIo, 'shell_exec');
                }
            }

            return [
                'ok' => false,
                'error' => 'Nie udalo sie uruchomic mostka Vanna zadna dostepna metoda.',
                'details' => 'proc_open: ' . (string)($bridgeIo['error'] ?? 'blad bez opisu')
                    . '; runners=' . vannaDescribeProcessRunnerStatus(),
            ];
        }

        if ($runner === 'exec') {
            return vannaDecodeBridgeResponse(vannaRunBridgeViaExec($commandParts, $encodedPayload), $runner);
        }

        return vannaDecodeBridgeResponse(vannaRunBridgeViaShellExec($commandParts, $encodedPayload), $runner);
    }
}
