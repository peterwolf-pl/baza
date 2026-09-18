<?php
session_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

include 'db.php';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/vanna_integration.php';

$collections = [
    'ksiazki-artystyczne' => [
        'label' => 'Ksiazki Artystyczne',
        'main' => 'karta_ewidencyjna',
        'log' => 'karta_ewidencyjna_log',
        'moves' => 'karta_ewidencyjna_przemieszczenia',
    ],
    'kolekcja-maszyn' => [
        'label' => 'Maszyny',
        'main' => 'karta_ewidencyjna_maszyny',
        'log' => 'karta_ewidencyjna_maszyny_log',
        'moves' => 'karta_ewidencyjna_maszyny_przemieszczenia',
    ],
    'kolekcja-matryc' => [
        'label' => 'Matryce',
        'main' => 'karta_ewidencyjna_matryce',
        'log' => 'karta_ewidencyjna_matryce_log',
        'moves' => 'karta_ewidencyjna_matryce_przemieszczenia',
    ],
    'biblioteka' => [
        'label' => 'Biblioteka',
        'main' => 'karta_ewidencyjna_bib',
        'log' => 'karta_ewidencyjna_bib_log',
        'moves' => 'karta_ewidencyjna_bib_przemieszczenia',
    ],
    'kolekcja-klisz' => [
        'label' => 'Klisze drukarskie',
        'main' => 'karta_ewidencyjna_klisze',
        'log' => 'karta_ewidencyjna_klisze_log',
        'moves' => 'karta_ewidencyjna_klisze_przemieszczenia',
    ],
];

$selectedCollection = (string)($_GET['collection'] ?? 'ksiazki-artystyczne');
if (!isset($collections[$selectedCollection])) {
    $selectedCollection = 'ksiazki-artystyczne';
}

$selectedLedger = (string)($GLOBALS['app_selected_ledger'] ?? ($_SESSION['selected_ledger'] ?? 'depozytowa'));
$pageHref = 'vanna.php?collection=' . rawurlencode($selectedCollection) . '&ledger=' . rawurlencode($selectedLedger);
require_once __DIR__ . '/auth.php';
$canUseVanna = userCan('full_view');
$runtimeConfig = vannaGetRuntimeConfig();
$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

$question = trim((string)($_POST['question'] ?? ''));
$generatedSql = '';
$executedSql = '';
$successMessage = '';
$errorMessage = '';
$debugMessage = '';
$resultRows = [];
$resultColumns = [];
$savedQueries = [];
$savedQueriesReady = false;
$savedQueriesNotice = '';
$canSaveCurrentQuery = false;
$activeSavedQueryId = null;
$activeQuerySource = '';
$hasAttempt = false;

$questionLength = function_exists('mb_strlen')
    ? mb_strlen($question, 'UTF-8')
    : strlen($question);

if (!function_exists('vannaPageExecuteSql')) {
    function vannaPageExecuteSql(
        PDO $pdo,
        string $generatedSql,
        string &$executedSql,
        array &$resultRows,
        array &$resultColumns,
        string &$errorMessage,
        string &$debugMessage,
        string $failureMessage
    ): bool {
        $validationError = vannaValidateReadonlySql($generatedSql);
        if ($validationError !== null) {
            $errorMessage = $validationError;
            return false;
        }

        $executedSql = vannaApplySafeLimit($generatedSql, 100);

        try {
            $stmt = $pdo->query($executedSql);
            $resultRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $resultColumns = [];

            if (!empty($resultRows)) {
                $resultColumns = array_keys($resultRows[0]);
            } else {
                $columnCount = $stmt->columnCount();
                for ($i = 0; $i < $columnCount; $i++) {
                    $meta = $stmt->getColumnMeta($i);
                    $resultColumns[] = (string)($meta['name'] ?? ('kolumna_' . ($i + 1)));
                }
            }

            return true;
        } catch (Throwable $e) {
            $errorMessage = $failureMessage;
            $debugMessage = $e->getMessage();
            return false;
        }
    }
}

if (!$canUseVanna) {
    http_response_code(403);
    $errorMessage = 'Brak uprawnien do korzystania z Vanna AI.';
}

if ($canUseVanna) {
    try {
        vannaEnsureSavedQueriesTable($pdo);
        $savedQueriesReady = true;
    } catch (Throwable $e) {
        $savedQueriesNotice = 'Zapisywanie promptow jest chwilowo niedostepne.';
        if ($debugMessage === '') {
            $debugMessage = $e->getMessage();
        }
    }
}

if ($canUseVanna && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $hasAttempt = true;

    $action = (string)($_POST['vanna_action'] ?? 'generate');

    if ($action === 'run_saved') {
        $savedQueryId = max(0, (int)($_POST['saved_query_id'] ?? 0));

        if (!$savedQueriesReady) {
            $errorMessage = 'Zapisywanie promptow jest chwilowo niedostepne.';
        } elseif ($savedQueryId < 1) {
            $errorMessage = 'Nie wskazano zapisanego zapytania.';
        } else {
            $savedQuery = vannaFetchSavedQueryById(
                $pdo,
                $savedQueryId,
                $selectedCollection,
                $selectedLedger
            );

            if ($savedQuery === null) {
                $errorMessage = 'Nie znaleziono zapisanego zapytania dla tej kolekcji i ksiegi.';
            } else {
                $activeSavedQueryId = (int)($savedQuery['id'] ?? 0);
                $activeQuerySource = 'saved';
                $question = trim((string)($savedQuery['prompt_text'] ?? ''));
                $generatedSql = trim((string)($savedQuery['generated_sql'] ?? ''));

                if (vannaPageExecuteSql(
                    $pdo,
                    $generatedSql,
                    $executedSql,
                    $resultRows,
                    $resultColumns,
                    $errorMessage,
                    $debugMessage,
                    'Zapisane zapytanie nie wykonalo sie poprawnie.'
                )) {
                    vannaTouchSavedQueryUsage($pdo, $activeSavedQueryId);
                    $successMessage = 'Uruchomiono zapisane zapytanie.';
                }
            }
        }
    } elseif ($action === 'save') {
        $generatedSql = trim((string)($_POST['generated_sql'] ?? ''));

        if ($question === '') {
            $errorMessage = 'Brakuje promptu do zapisania.';
        } elseif ($generatedSql === '') {
            $errorMessage = 'Brakuje SQL do zapisania.';
        } elseif (!$savedQueriesReady) {
            $errorMessage = 'Zapisywanie promptow jest chwilowo niedostepne.';
        } elseif (vannaPageExecuteSql(
            $pdo,
            $generatedSql,
            $executedSql,
            $resultRows,
            $resultColumns,
            $errorMessage,
            $debugMessage,
            'Zapisane zapytanie nie wykonalo sie poprawnie.'
        )) {
            vannaSaveSavedQuery(
                $pdo,
                (string)($runtimeConfig['provider'] ?? ''),
                $selectedCollection,
                $selectedLedger,
                $question,
                $generatedSql,
                $executedSql,
                $currentUserId
            );

            $savedQuery = vannaFetchSavedQueryByPrompt(
                $pdo,
                $selectedCollection,
                $selectedLedger,
                $question
            );
            if ($savedQuery !== null) {
                $activeSavedQueryId = (int)($savedQuery['id'] ?? 0);
            }

            $activeQuerySource = 'saved';
            $successMessage = 'Zapytanie zapisano i wykonano poprawnie.';
        }
    } else {
        if ($question === '') {
            $errorMessage = 'Wpisz pytanie w jezyku naturalnym.';
        } elseif ($questionLength > 600) {
            $errorMessage = 'Pytanie jest zbyt dlugie. Skroc je do maksymalnie 600 znakow.';
        } else {
            $savedQuery = null;
            if ($savedQueriesReady) {
                $savedQuery = vannaFetchSavedQueryByPrompt(
                    $pdo,
                    $selectedCollection,
                    $selectedLedger,
                    $question
                );
            }

            if ($savedQuery !== null) {
                $activeSavedQueryId = (int)($savedQuery['id'] ?? 0);
                $activeQuerySource = 'saved';
                $generatedSql = trim((string)($savedQuery['generated_sql'] ?? ''));

                if (vannaPageExecuteSql(
                    $pdo,
                    $generatedSql,
                    $executedSql,
                    $resultRows,
                    $resultColumns,
                    $errorMessage,
                    $debugMessage,
                    'Zapisane zapytanie nie wykonalo sie poprawnie.'
                )) {
                    vannaTouchSavedQueryUsage($pdo, $activeSavedQueryId);
                    $successMessage = 'Uzyto zapisanego zapytania bez wywolania API.';
                }
            } elseif (empty($runtimeConfig['is_configured'])) {
                $errorMessage = vannaGetConfigurationMessage($runtimeConfig);
            } else {
                $payload = vannaBuildTrainingPayload(
                    $pdo,
                    $runtimeConfig,
                    $collections[$selectedCollection],
                    $selectedCollection,
                    $selectedLedger
                );
                $payload['question'] = $question;

                $bridgeResponse = vannaGenerateSqlWithBridge($payload);
                if (!empty($bridgeResponse['log']) && is_string($bridgeResponse['log'])) {
                    $debugMessage = trim($bridgeResponse['log']);
                }

                if (empty($bridgeResponse['ok'])) {
                    $errorMessage = (string)($bridgeResponse['error'] ?? 'Vanna AI nie odpowiedziala poprawnie.');
                    if (!empty($bridgeResponse['details']) && is_string($bridgeResponse['details'])) {
                        $debugMessage = trim((string)$bridgeResponse['details']);
                    }
                } else {
                    $generatedSql = (string)($bridgeResponse['sql'] ?? '');
                    $activeQuerySource = 'generated';

                    if (vannaPageExecuteSql(
                        $pdo,
                        $generatedSql,
                        $executedSql,
                        $resultRows,
                        $resultColumns,
                        $errorMessage,
                        $debugMessage,
                        'Wygenerowany SQL nie wykonal sie poprawnie.'
                    )) {
                        $canSaveCurrentQuery = $savedQueriesReady;
                        $successMessage = 'Zapytanie wykonane poprawnie.';
                    }
                }
            }
        }
    }
}

if ($canUseVanna && $savedQueriesReady) {
    try {
        $savedQueries = vannaListSavedQueries(
            $pdo,
            $selectedCollection,
            $selectedLedger
        );
    } catch (Throwable $e) {
        if ($savedQueriesNotice === '') {
            $savedQueriesNotice = 'Nie udalo sie pobrac listy zapisanych zapytan.';
        }
        if ($debugMessage === '') {
            $debugMessage = $e->getMessage();
        }
    }
}

$esc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$formatCell = static function ($value): string {
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_array($value) || is_object($value)) {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? '[unserializable]' : $encoded;
    }
    return (string)$value;
};
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Vanna AI | baza.mkal.pl</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .vanna-page { padding: 0 16px 24px; }
        .vanna-box {
            margin: 16px 0;
            padding: 16px;
            border: 1px solid #c9ced6;
            border-radius: 12px;
            background: #fff;
        }
        .vanna-box pre {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            margin: 0;
        }
        .vanna-form textarea {
            width: 100%;
            min-height: 120px;
            resize: vertical;
            margin-top: 8px;
        }
        .vanna-meta {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin: 8px 0 16px;
            font-size: 0.95rem;
        }
        .vanna-chip {
            padding: 6px 10px;
            border-radius: 999px;
            background: #eef3f8;
            border: 1px solid #d9e3ef;
        }
        .vanna-status-ok { color: #0f6a2f; }
        .vanna-status-error { color: #9a1f1f; }
        .vanna-table-wrap {
            overflow-x: auto;
            margin-top: 16px;
        }
        .vanna-table-wrap table {
            min-width: 100%;
        }
        details.vanna-debug {
            margin-top: 16px;
        }
        .vanna-action-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 12px;
        }
        .vanna-inline-form {
            margin: 0;
        }
        .vanna-muted {
            color: #4c5a6a;
            font-size: 0.95rem;
        }
        .vanna-saved-grid {
            display: grid;
            gap: 12px;
        }
        .vanna-saved-item {
            padding: 12px;
            border: 1px solid #d9e3ef;
            border-radius: 10px;
            background: #f8fafc;
        }
        .vanna-saved-item.is-active {
            border-color: #78a5d6;
            box-shadow: inset 0 0 0 1px #78a5d6;
        }
        .vanna-saved-meta {
            margin: 8px 0 0;
            color: #4c5a6a;
            font-size: 0.9rem;
        }
        .vanna-saved-item details {
            margin-top: 10px;
        }
    </style>
</head>
<body>
<?php
renderAppHeader([
    'selectedCollection' => $selectedCollection,
    'collections' => $collections,
    'username' => $_SESSION['username'] ?? '',
    'logoHref' => 'index.php?collection=' . rawurlencode($selectedCollection) . '&ledger=' . rawurlencode($selectedLedger),
    'showListEditor' => false,
    'primaryActions' => [
        ['label' => 'Powrot do strony glownej', 'href' => 'index.php?collection=' . rawurlencode($selectedCollection) . '&ledger=' . rawurlencode($selectedLedger)],
        ['label' => 'Klasyczne wyszukiwanie', 'href' => 'search.php?collection=' . rawurlencode($selectedCollection) . '&ledger=' . rawurlencode($selectedLedger)],
    ],
]);
?>

<main class="vanna-page">
    <div class="vanna-box">
        <h2>Vanna AI</h2>
        <p>
            Zadaj pytanie po polsku, a aplikacja wygeneruje tylko odczytowe SQL dla aktywnej kolekcji i ksiegi.
            SQL jest uruchamiany przez istniejace PDO aplikacji, wiec zachowuje biezacy kontekst danych.
        </p>
        <div class="vanna-meta">
            <span class="vanna-chip">Kolekcja: <?php echo $esc($collections[$selectedCollection]['label']); ?></span>
            <span class="vanna-chip">Ksiega: <?php echo $esc($GLOBALS['app_ledger_definitions'][$selectedLedger]['label'] ?? $selectedLedger); ?></span>
            <span class="vanna-chip">Provider: <?php echo $esc($runtimeConfig['provider'] !== '' ? $runtimeConfig['provider'] : 'brak konfiguracji'); ?></span>
        </div>

        <?php if ($successMessage !== ''): ?>
            <p class="vanna-status-ok"><?php echo $esc($successMessage); ?></p>
        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>
            <p class="vanna-status-error"><?php echo $esc($errorMessage); ?></p>
        <?php endif; ?>

        <?php if (empty($runtimeConfig['is_configured'])): ?>
            <p>
                Aby wlaczyc integracje, skonfiguruj srodowisko serwera i zainstaluj pakiet Python
                <code>vanna</code>. Ten ekran obsluguje tryb hosted (VANNA_API_KEY + VANNA_MODEL)
                oraz tryb openai (OPENAI_API_KEY, opcjonalnie VANNA_OPENAI_MODEL). Jesli Vanna
                jest zainstalowana w innym interpreterze, ustaw <code>VANNA_PYTHON_BIN</code>.
            </p>
        <?php endif; ?>
    </div>

    <div class="vanna-box">
        <form method="post" action="<?php echo $esc($pageHref); ?>" class="vanna-form">
            <label for="vanna-question"><strong>Pytanie naturalne</strong></label>
            <textarea id="vanna-question" name="question" placeholder="Np. Pokaz 20 rekordow z kolekcji, w ktorych autor zawiera slowo Kantor."><?php echo $esc($question); ?></textarea>
            <div style="margin-top:12px;">
                <button type="submit" <?php echo $canUseVanna ? '' : 'disabled'; ?>>Generuj SQL i uruchom</button>
            </div>
        </form>
    </div>

    <div class="vanna-box">
        <h3>SQL od Vanna AI</h3>
        <pre><code><?php echo $esc($generatedSql !== '' ? $generatedSql : 'Jeszcze nic nie wygenerowano.'); ?></code></pre>

        <?php if ($generatedSql !== '' && $errorMessage === ''): ?>
            <div class="vanna-action-row">
                <?php if ($canSaveCurrentQuery): ?>
                    <form method="post" action="<?php echo $esc($pageHref); ?>" class="vanna-inline-form">
                        <input type="hidden" name="vanna_action" value="save">
                        <input type="hidden" name="question" value="<?php echo $esc($question); ?>">
                        <input type="hidden" name="generated_sql" value="<?php echo $esc($generatedSql); ?>">
                        <button type="submit">Zapisz</button>
                    </form>
                    <span class="vanna-muted">Zapisz prompt i SQL, aby kolejne uruchomienie nie zuzywalo tokenow API.</span>
                <?php elseif ($activeSavedQueryId !== null): ?>
                    <span class="vanna-muted">
                        <?php echo $activeQuerySource === 'saved' ? 'To zapytanie uruchomiono z zapisanej biblioteki.' : 'To zapytanie jest juz zapisane w bibliotece.'; ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($executedSql !== '' && $executedSql !== $generatedSql): ?>
            <h3 style="margin-top:16px;">SQL wykonany w aplikacji</h3>
            <pre><code><?php echo $esc($executedSql); ?></code></pre>
            <p>Jesli Vanna nie dodala LIMIT, aplikacja dopiela bezpieczny LIMIT 100.</p>
        <?php endif; ?>

        <?php if ($debugMessage !== '' && userIsRoot()): ?>
            <details class="vanna-debug">
                <summary>Detale techniczne</summary>
                <pre><code><?php echo $esc($debugMessage); ?></code></pre>
            </details>
        <?php endif; ?>
    </div>

    <?php if ($hasAttempt && $errorMessage === '' && $generatedSql !== ''): ?>
        <div class="vanna-box">
            <h3>Wyniki</h3>

            <?php if (!empty($resultColumns)): ?>
                <div class="vanna-table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <?php foreach ($resultColumns as $columnName): ?>
                                    <th><?php echo $esc($columnName); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($resultRows)): ?>
                                <?php foreach ($resultRows as $row): ?>
                                    <tr>
                                        <?php foreach ($resultColumns as $columnName): ?>
                                            <td><?php echo $esc($formatCell($row[$columnName] ?? null)); ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?php echo count($resultColumns); ?>">Zapytanie wykonalo sie poprawnie, ale nie zwrocilo zadnych wierszy.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p>Zapytanie wykonalo sie poprawnie, ale nie zwrocilo kolumn do wyswietlenia.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($canUseVanna): ?>
        <div class="vanna-box">
            <h3>Zapisane zapytania</h3>

            <?php if ($savedQueriesNotice !== ''): ?>
                <p class="vanna-status-error"><?php echo $esc($savedQueriesNotice); ?></p>
            <?php elseif (empty($savedQueries)): ?>
                <p>Brak zapisanych zapytan dla tej kolekcji i ksiegi.</p>
            <?php else: ?>
                <div class="vanna-saved-grid">
                    <?php foreach ($savedQueries as $savedQuery): ?>
                        <?php
                        $savedId = (int)($savedQuery['id'] ?? 0);
                        $savedPrompt = trim((string)($savedQuery['prompt_text'] ?? ''));
                        $savedSqlText = trim((string)($savedQuery['generated_sql'] ?? ''));
                        $savedUsageCount = (int)($savedQuery['usage_count'] ?? 0);
                        $savedLastUsed = (string)($savedQuery['last_used_at'] ?? '');
                        $savedProvider = trim((string)($savedQuery['provider'] ?? ''));
                        $isActiveSaved = $activeSavedQueryId !== null && $activeSavedQueryId === $savedId;
                        ?>
                        <div class="vanna-saved-item<?php echo $isActiveSaved ? ' is-active' : ''; ?>">
                            <strong><?php echo $esc($savedPrompt !== '' ? $savedPrompt : 'Bez nazwy'); ?></strong>
                            <p class="vanna-saved-meta">
                                Uzycia: <?php echo $savedUsageCount; ?>
                                <?php if ($savedLastUsed !== ''): ?> | Ostatnio: <?php echo $esc($savedLastUsed); ?><?php endif; ?>
                                <?php if ($savedProvider !== ''): ?> | Zrodlo: <?php echo $esc($savedProvider); ?><?php endif; ?>
                            </p>
                            <div class="vanna-action-row">
                                <form method="post" action="<?php echo $esc($pageHref); ?>" class="vanna-inline-form">
                                    <input type="hidden" name="vanna_action" value="run_saved">
                                    <input type="hidden" name="saved_query_id" value="<?php echo $savedId; ?>">
                                    <button type="submit">Uruchom</button>
                                </form>
                            </div>
                            <details>
                                <summary>Podglad SQL</summary>
                                <pre><code><?php echo $esc($savedSqlText); ?></code></pre>
                            </details>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
