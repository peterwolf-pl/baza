<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/auth.php';
if (!userCan('generate_reports')) {
    http_response_code(403);
    echo 'Brak uprawnień do generowania raportów i eksportów.';
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/header.php';

$collections = [
    'ksiazki-artystyczne' => [
        'label' => 'Książki Artystyczne',
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

$action = (string)($_GET['action'] ?? '');
$mainTable = $collections[$selectedCollection]['main'];
$logTable = $collections[$selectedCollection]['log'];

function listTableColumns(PDO $pdo, string $tableName): array
{
    $columns = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM {$tableName}");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (isset($row['Field'])) {
            $columns[] = (string)$row['Field'];
        }
    }
    return $columns;
}

function csvFilenameBase(string $prefix, string $collection): string
{
    return $prefix . '_' . preg_replace('/[^a-z0-9_-]+/i', '-', $collection) . '_' . date('Y-m-d_H-i-s');
}

function buildCollectionReport(PDO $pdo, array $collectionConfig): array
{
    $mainTable = (string)$collectionConfig['main'];
    $logTable = (string)$collectionConfig['log'];
    $movesTable = (string)($collectionConfig['moves'] ?? '');
    $mainColumns = listTableColumns($pdo, $mainTable);
    $logColumns = listTableColumns($pdo, $logTable);
    $mainCount = (int)$pdo->query("SELECT COUNT(*) FROM {$mainTable}")->fetchColumn();
    $logCount = (int)$pdo->query("SELECT COUNT(*) FROM {$logTable}")->fetchColumn();
    $movesCount = $movesTable !== '' ? (int)$pdo->query("SELECT COUNT(*) FROM {$movesTable}")->fetchColumn() : 0;

    $groupableCandidates = ['sposob_nabycia', 'stan_zachowania', 'lokalizacja', 'typ', 'rodzaj'];
    $groupableColumns = array_values(array_intersect($groupableCandidates, $mainColumns));
    $grouped = [];

    foreach ($groupableColumns as $groupColumn) {
        $sql = "SELECT COALESCE(NULLIF(TRIM(CAST({$groupColumn} AS CHAR)), ''), '(puste)') AS value_label, COUNT(*) AS cnt
                FROM {$mainTable}
                GROUP BY value_label
                ORDER BY cnt DESC, value_label ASC";
        $stmt = $pdo->query($sql);
        $grouped[$groupColumn] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    return [
        'mainColumns' => $mainColumns,
        'logColumns' => $logColumns,
        'mainCount' => $mainCount,
        'logCount' => $logCount,
        'movesCount' => $movesCount,
        'grouped' => $grouped,
    ];
}

function streamCsvDownload(string $filename, array $headerRow, iterable $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        http_response_code(500);
        echo 'Nie udało się wygenerować pliku CSV.';
        exit;
    }

    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headerRow, ';');
    foreach ($rows as $row) {
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}

if ($action === 'export_ledger_csv') {
    $columns = listTableColumns($pdo, $mainTable);
    $stmt = $pdo->query("SELECT * FROM {$mainTable}");

    $rows = (function () use ($stmt, $columns) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $line = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? '';
                if (is_scalar($value) || $value === null) {
                    $line[] = (string)$value;
                } else {
                    $line[] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
                }
            }
            yield $line;
        }
    })();

    streamCsvDownload(csvFilenameBase('pelna_ksiega', $selectedCollection), $columns, $rows);
}

if ($action === 'report_csv') {
    $reportData = buildCollectionReport($pdo, $collections[$selectedCollection]);
    $mainColumns = $reportData['mainColumns'];
    $logColumns = $reportData['logColumns'];
    $mainCount = $reportData['mainCount'];
    $logCount = $reportData['logCount'];
    $grouped = $reportData['grouped'];

    $reportRows = [];
    $reportRows[] = ['Sekcja', 'Pole', 'Wartość'];
    $reportRows[] = ['Meta', 'Kolekcja', $selectedCollection];
    $reportRows[] = ['Meta', 'Wygenerowano', date('Y-m-d H:i:s')];
    $reportRows[] = ['Meta', 'Liczba rekordów księgi', (string)$mainCount];
    $reportRows[] = ['Meta', 'Liczba wpisów logów zmian', (string)$logCount];
    $reportRows[] = ['Meta', 'Liczba kolumn księgi', (string)count($mainColumns)];
    $reportRows[] = ['Meta', 'Liczba kolumn logów', (string)count($logColumns)];

    foreach ($grouped as $groupColumn => $rowsForGroup) {
        $reportRows[] = ['', '', ''];
        $reportRows[] = ['Zestawienie', $groupColumn, 'Liczność'];
        foreach ($rowsForGroup as $row) {
            $reportRows[] = ['Zestawienie', (string)($row['value_label'] ?? ''), (string)($row['cnt'] ?? '0')];
        }
    }

    streamCsvDownload(
        csvFilenameBase('raport', $selectedCollection),
        $reportRows[0],
        array_slice($reportRows, 1)
    );
}

if ($action === 'view_reports') {
    $esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $reports = [];
    foreach ($collections as $collectionKey => $collectionConfig) {
        $reports[$collectionKey] = buildCollectionReport($pdo, $collectionConfig);
    }
    ?>
    <!DOCTYPE html>
    <html lang="pl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Generowanie raportów | baza.mkal.pl</title>
        <link rel="stylesheet" href="styles.css">
        <style>
            .reports-page { max-width: 1100px; margin: 12px auto 24px; padding: 0 12px; }
            .reports-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 12px; padding: 14px; margin-bottom: 12px; }
            .reports-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
            .reports-grid { display: grid; gap: 12px; }
            .reports-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px; margin: 10px 0; }
            .reports-meta div { background: var(--color-surface-strong); border: 1px solid var(--color-border); border-radius: 8px; padding: 8px; }
            .reports-table-wrap { overflow: auto; border: 1px solid var(--color-border); border-radius: 8px; margin-top: 10px; }
            .reports-table { width: 100%; border-collapse: collapse; font-size: 12px; }
            .reports-table th, .reports-table td { border: 1px solid var(--color-border); padding: 6px 8px; text-align: left; vertical-align: top; }
            .reports-table th { background: var(--color-table-head); }
            @media print {
                .reports-no-print { display: none !important; }
                .reports-page { max-width: none; margin: 0; padding: 0; }
                .reports-card { break-inside: avoid; border-color: #bbb; }
            }
        </style>
    </head>
    <body>
    <?php
    renderAppHeader([
        'selectedCollection' => $selectedCollection,
        'collections' => $collections,
        'lists' => [],
        'showListEditor' => false,
        'showColumnButton' => false,
        'logoHref' => 'index.php?collection=' . rawurlencode($selectedCollection),
        'username' => (string)($_SESSION['username'] ?? ''),
    ]);
    ?>
    <main class="reports-page">
        <section class="reports-card reports-no-print">
            <h1>Generowanie raportów</h1>
            <p>Raport zbiorczy dla 4 kolekcji. Możesz wydrukować stronę lub zapisać ją jako PDF z okna drukowania.</p>
            <div class="reports-actions">
                <a role="button" id="toggleButton" href="<?php echo $esc('organizacyjne.php?collection=' . rawurlencode($selectedCollection) . '#wydruk-raportow'); ?>">Powrót do wymagań organizacyjnych</a>
                <button type="button" id="toggleButton" onclick="window.print()">Drukuj / Zapisz PDF</button>
            </div>
        </section>

        <div class="reports-grid">
            <?php foreach ($collections as $collectionKey => $collectionConfig): ?>
                <?php $data = $reports[$collectionKey]; ?>
                <section class="reports-card">
                    <h2><?php echo $esc($collectionConfig['label']); ?></h2>
                    <div class="reports-meta">
                        <div><strong>Kolekcja:</strong><br><?php echo $esc($collectionKey); ?></div>
                        <div><strong>Rekordy księgi:</strong><br><?php echo (int)$data['mainCount']; ?></div>
                        <div><strong>Historie edycji (logi):</strong><br><?php echo (int)$data['logCount']; ?></div>
                        <div><strong>Przemieszczenia:</strong><br><?php echo (int)$data['movesCount']; ?></div>
                        <div><strong>Schemat: kolumny (księga / logi):</strong><br><?php echo count($data['mainColumns']); ?> / <?php echo count($data['logColumns']); ?></div>
                    </div>
                    <div class="reports-actions reports-no-print">
                        <a role="button" id="toggleButton" href="<?php echo $esc('organizacyjne_actions.php?collection=' . rawurlencode($collectionKey) . '&action=report_csv'); ?>">Pobierz raport CSV</a>
                    </div>
                    <?php if (empty($data['grouped'])): ?>
                        <p>Brak pól do zestawień grupowanych w tej kolekcji.</p>
                    <?php else: ?>
                        <?php foreach ($data['grouped'] as $groupColumn => $rowsForGroup): ?>
                            <h3>Zestawienie: <?php echo $esc($groupColumn); ?></h3>
                            <div class="reports-table-wrap">
                                <table class="reports-table">
                                    <thead>
                                        <tr>
                                            <th>Wartość</th>
                                            <th>Liczność</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($rowsForGroup)): ?>
                                            <tr><td colspan="2">Brak danych</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($rowsForGroup as $row): ?>
                                                <tr>
                                                    <td><?php echo $esc($row['value_label'] ?? ''); ?></td>
                                                    <td><?php echo (int)($row['cnt'] ?? 0); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    </main>
    <?php include __DIR__ . '/footer.php'; ?>
    </body>
    </html>
    <?php
    exit;
}

if ($action === 'view_change_history') {
    $logColumns = listTableColumns($pdo, $logTable);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 100;
    $offset = ($page - 1) * $perPage;

    $totalRows = (int)$pdo->query("SELECT COUNT(*) FROM {$logTable}")->fetchColumn();
    $preferredOrderColumns = ['change_date', 'changed_at', 'updated_at', 'created_at', 'id', 'ID'];
    $orderColumn = null;
    foreach ($preferredOrderColumns as $candidate) {
        if (in_array($candidate, $logColumns, true)) {
            $orderColumn = $candidate;
            break;
        }
    }

    $sql = "SELECT * FROM {$logTable}";
    if ($orderColumn !== null) {
        $sql .= " ORDER BY {$orderColumn} DESC";
    }
    $sql .= " LIMIT {$offset}, {$perPage}";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="pl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Historia zmian | baza.mkal.pl</title>
        <link rel="stylesheet" href="styles.css">
        <style>
            .history-page { max-width: 98vw; margin: 12px auto 20px; }
            .history-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 12px; padding: 14px; margin-bottom: 12px; }
            .history-meta { display: flex; flex-wrap: wrap; gap: 10px 14px; }
            .history-meta span { background: var(--color-surface-strong); border: 1px solid var(--color-border); border-radius: 8px; padding: 6px 10px; }
            .history-table-wrap { overflow: auto; border: 1px solid var(--color-border); border-radius: 10px; }
            .history-table { width: 100%; border-collapse: collapse; font-size: 12px; }
            .history-table th, .history-table td { border: 1px solid var(--color-border); padding: 6px 8px; vertical-align: top; text-align: left; }
            .history-table th { position: sticky; top: 0; background: var(--color-table-head); }
            .history-pager { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
            .history-table tbody tr.history-row-link { cursor: pointer; }
            .history-table tbody tr.history-row-link:hover { background: color-mix(in srgb, var(--color-table-head) 45%, transparent); }
        </style>
    </head>
    <body>
    <?php
    renderAppHeader([
        'selectedCollection' => $selectedCollection,
        'collections' => $collections,
        'lists' => [],
        'showListEditor' => false,
        'showColumnButton' => false,
        'logoHref' => 'index.php?collection=' . rawurlencode($selectedCollection),
        'username' => (string)($_SESSION['username'] ?? ''),
    ]);
    ?>
    <main class="history-page">
        <section class="history-card">
            <h1>Historia zmian</h1>
            <div class="history-meta">
                <span>Kolekcja: <?php echo $esc($collections[$selectedCollection]['label']); ?></span>
                <span>Tabela logów: <?php echo $esc($logTable); ?></span>
                <span>Wiersze: <?php echo (int)$totalRows; ?></span>
                <span>Strona: <?php echo (int)$page; ?> / <?php echo (int)$totalPages; ?></span>
            </div>
            <p class="history-pager">
                <a role="button" id="toggleButton" href="<?php echo $esc('organizacyjne.php?collection=' . rawurlencode($selectedCollection) . '#udostepnienie-historii-zmian'); ?>">Powrót do wymagań organizacyjnych</a>
                <?php if ($page > 1): ?>
                    <a role="button" id="toggleButton" href="<?php echo $esc('organizacyjne_actions.php?collection=' . rawurlencode($selectedCollection) . '&action=view_change_history&page=' . ($page - 1)); ?>">Poprzednia strona</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a role="button" id="toggleButton" href="<?php echo $esc('organizacyjne_actions.php?collection=' . rawurlencode($selectedCollection) . '&action=view_change_history&page=' . ($page + 1)); ?>">Następna strona</a>
                <?php endif; ?>
            </p>
        </section>

        <section class="history-card">
            <div class="history-table-wrap">
                <table class="history-table">
                    <thead>
                        <tr>
                            <?php foreach ($logColumns as $column): ?>
                                <th><?php echo $esc($column); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="<?php echo max(1, count($logColumns)); ?>">Brak danych w logach zmian.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $recordId = isset($row['karta_id']) ? (int)$row['karta_id'] : 0;
                                $recordHref = $recordId > 0
                                    ? 'karta.php?id=' . $recordId . '&collection=' . rawurlencode($selectedCollection)
                                    : '';
                                ?>
                                <tr<?php if ($recordHref !== ''): ?> class="history-row-link" data-href="<?php echo $esc($recordHref); ?>" title="Otwórz kartę rekordu #<?php echo $recordId; ?>"<?php endif; ?>>
                                    <?php foreach ($logColumns as $column): ?>
                                        <td>
                                            <?php if ($recordHref !== '' && $column === 'karta_id'): ?>
                                                <a href="<?php echo $esc($recordHref); ?>" onclick="event.stopPropagation();"><?php echo $esc($row[$column] ?? ''); ?></a>
                                            <?php else: ?>
                                                <?php echo $esc($row[$column] ?? ''); ?>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
    <?php include __DIR__ . '/footer.php'; ?>
    <script>
        document.querySelectorAll('.history-row-link').forEach(function (row) {
            row.addEventListener('click', function (event) {
                if (event.target && event.target.closest('a, button, input, select, textarea')) {
                    return;
                }
                const href = row.getAttribute('data-href');
                if (href) {
                    window.location.href = href;
                }
            });
        });
    </script>
    </body>
    </html>
    <?php
    exit;
}

http_response_code(400);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nieznana akcja | baza.mkal.pl</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<?php
renderAppHeader([
    'selectedCollection' => $selectedCollection,
    'collections' => $collections,
    'lists' => [],
    'showListEditor' => false,
    'showColumnButton' => false,
    'logoHref' => 'index.php?collection=' . rawurlencode($selectedCollection),
    'username' => (string)($_SESSION['username'] ?? ''),
]);
?>
<main class="organizacyjne-page">
    <section class="organizacyjne-card">
        <h1>Nieznana akcja</h1>
        <p>Nie rozpoznano parametru <code>action</code>.</p>
        <p><a role="button" id="toggleButton" href="<?php echo htmlspecialchars('organizacyjne.php?collection=' . rawurlencode($selectedCollection), ENT_QUOTES, 'UTF-8'); ?>">Powrót</a></p>
    </section>
</main>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
