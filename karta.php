<?php
require_once __DIR__ . '/bootstrap.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include 'db.php';
require_once __DIR__ . '/museum_system.php';
require_once __DIR__ . '/header.php';


$collections = [
    'ksiazki-artystyczne' => [
        'main' => 'karta_ewidencyjna',
        'log' => 'karta_ewidencyjna_log',
        'moves' => 'karta_ewidencyjna_przemieszczenia',
    ],
    'kolekcja-maszyn' => [
        'main' => 'karta_ewidencyjna_maszyny',
        'log' => 'karta_ewidencyjna_maszyny_log',
        'moves' => 'karta_ewidencyjna_maszyny_przemieszczenia',
    ],
    'kolekcja-matryc' => [
        'main' => 'karta_ewidencyjna_matryce',
        'log' => 'karta_ewidencyjna_matryce_log',
        'moves' => 'karta_ewidencyjna_matryce_przemieszczenia',
    ],
    'biblioteka' => [
        'main' => 'karta_ewidencyjna_bib',
        'log' => 'karta_ewidencyjna_bib_log',
        'moves' => 'karta_ewidencyjna_bib_przemieszczenia',
    ],
    'kolekcja-klisz' => [
        'main' => 'karta_ewidencyjna_klisze',
        'log' => 'karta_ewidencyjna_klisze_log',
        'moves' => 'karta_ewidencyjna_klisze_przemieszczenia',
    ],
];

$selectedCollection = $_GET['collection'] ?? 'ksiazki-artystyczne';
if (!isset($collections[$selectedCollection])) {
    $selectedCollection = 'ksiazki-artystyczne';
}

$mainTable = $collections[$selectedCollection]['main'];
$logTable = $collections[$selectedCollection]['log'];
$movesTable = $collections[$selectedCollection]['moves'];
$username = $_SESSION['username'] ?? '';
$canUpdateRecords = userCan('update_records');
$canFullDatabaseView = userCan('full_view');
$canMoveRecords = userCan('move_records');
$selectedLedger = appSelectedLedger();

function ensureShareTables(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS record_share_links (
            token VARCHAR(64) NOT NULL,
            collection VARCHAR(64) NOT NULL,
            record_id INT UNSIGNED NOT NULL,
            created_by VARCHAR(255) DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            opened_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_opened_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (token),
            KEY idx_collection_record (collection, record_id),
            KEY idx_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS growth_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_name VARCHAR(64) NOT NULL,
            collection VARCHAR(64) NOT NULL,
            record_id INT UNSIGNED DEFAULT NULL,
            share_token VARCHAR(64) DEFAULT NULL,
            user_username VARCHAR(255) DEFAULT NULL,
            meta_json TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_event_name_created_at (event_name, created_at),
            KEY idx_collection_record (collection, record_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function trackGrowthEvent(PDO $pdo, string $eventName, string $collection, ?int $recordId, ?string $shareToken, ?string $username, array $meta = []): void {
    try {
        $metaJson = empty($meta) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $pdo->prepare(
            "INSERT INTO growth_events (event_name, collection, record_id, share_token, user_username, meta_json)
             VALUES (:event_name, :collection, :record_id, :share_token, :user_username, :meta_json)"
        );
        $stmt->execute([
            'event_name' => $eventName,
            'collection' => $collection,
            'record_id' => $recordId,
            'share_token' => $shareToken,
            'user_username' => $username,
            'meta_json' => $metaJson !== false ? $metaJson : null,
        ]);
    } catch (Throwable $e) {
        // Zbieranie metryk nie może blokować głównego przepływu.
    }
}

function buildAbsoluteAppUrl(string $fileName, array $query = []): string {
    return appPublicUrl($fileName, $query);
}

function findLatestActiveShareLink(PDO $pdo, string $collection, int $recordId): ?array {
    $stmt = $pdo->prepare(
        "SELECT token, expires_at
         FROM record_share_links
         WHERE collection = :collection
           AND record_id = :record_id
           AND revoked = 0
           AND expires_at >= NOW()
         ORDER BY created_at DESC
         LIMIT 1"
    );
    $stmt->execute([
        'collection' => $collection,
        'record_id' => $recordId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function formatDateTimeForUi(string $value): string {
    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }

    return date('Y-m-d H:i', $ts);
}

// Sprawdzenie, czy ID zostało przekazane w URL
if (!isset($_GET['id'])) {
    die("Brak ID w zapytaniu.");
}

$id = intval($_GET['id']);
$isFirstOpenFromMobileAdd = isset($_GET['from_mobile_add']) && $_GET['from_mobile_add'] === '1';
$searchReturnUrl = '';
if (isset($_GET['search_return'])) {
    $candidateReturnUrl = trim((string)$_GET['search_return']);
    if (preg_match('/^search\.php(?:\?.*)?$/', $candidateReturnUrl)) {
        $searchReturnUrl = $candidateReturnUrl;
    }
}

// Pobranie danych karty ewidencyjnej
$stmt = $pdo->prepare("SELECT * FROM {$mainTable} WHERE ID = :id");
$stmt->execute(['id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    die("Nie znaleziono rekordu.");
}

ensureShareTables($pdo);
museumEnsureAttachmentTables($pdo);
$shareLinkError = null;
$shareLinkSuccess = null;
$shareLinkExpiresAt = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    appRequireCsrf();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_share_link'])) {
    if (!$canFullDatabaseView) {
        http_response_code(403);
        die('Brak uprawnień do tworzenia linku publicznego.');
    }
    try {
        $shareToken = bin2hex(random_bytes(24));
        $shareExpiresAt = date('Y-m-d H:i:s', strtotime('+14 days'));

        $insertShareStmt = $pdo->prepare(
            "INSERT INTO record_share_links (token, collection, record_id, created_by, expires_at)
             VALUES (:token, :collection, :record_id, :created_by, :expires_at)"
        );
        $insertShareStmt->execute([
            'token' => $shareToken,
            'collection' => $selectedCollection,
            'record_id' => $id,
            'created_by' => $_SESSION['username'] ?? null,
            'expires_at' => $shareExpiresAt,
        ]);

        trackGrowthEvent(
            $pdo,
            'share_link_created',
            $selectedCollection,
            $id,
            $shareToken,
            $_SESSION['username'] ?? null,
            ['expires_at' => $shareExpiresAt]
        );

        $shareLinkSuccess = buildAbsoluteAppUrl('share.php', ['t' => $shareToken, 'ledger' => $selectedLedger]);
        $shareLinkExpiresAt = $shareExpiresAt;
    } catch (Throwable $e) {
        $shareLinkError = 'Nie udało się wygenerować linku publicznego.';
    }
}

$latestShareLinkData = findLatestActiveShareLink($pdo, $selectedCollection, $id);
if ($shareLinkSuccess === null && $latestShareLinkData !== null) {
    $shareLinkSuccess = buildAbsoluteAppUrl('share.php', ['t' => $latestShareLinkData['token'], 'ledger' => $selectedLedger]);
    $shareLinkExpiresAt = $latestShareLinkData['expires_at'] ?? null;
}

// Pobranie ścieżki obrazka
$image_urls = museumBuildMediaUrls($row['dokumentacja_wizualna'] ?? null, $selectedCollection, false);
$image_path = $image_urls[0] ?? null;
$image_fallback_path = $image_urls[1] ?? null;

if (!$canFullDatabaseView) {
    ?>
    <!DOCTYPE html>
    <html lang="pl">
    <head>
        <meta charset="UTF-8">
        <title>Podgląd ograniczony</title>
        <link rel="stylesheet" href="styles.css">
    </head>
    <body>
    <?php
    renderAppHeader([
        'selectedCollection' => $selectedCollection,
        'collections' => $collections,
        'lists' => [],
        'username' => $username,
        'showColumnButton' => false,
        'showListEditor' => false,
        'primaryActions' => [
            ['label' => 'Powrót do listy', 'href' => 'index.php?collection=' . rawurlencode($selectedCollection) . '&ledger=' . rawurlencode($selectedLedger)],
        ],
    ]);
    ?>
    <div style="max-width:900px;margin:20px auto;padding:16px;">
        <h2>Podgląd ograniczony</h2>
        <p>Masz dostęp tylko do miniatury/fotografii oraz podstawowych danych rekordu.</p>
        <?php if ($image_path): ?>
            <p><img <?php echo museumImgSrcFallbackAttributes($image_urls); ?> alt="Zdjęcie rekordu" style="max-width:100%;height:auto;"></p>
        <?php endif; ?>
        <table>
            <tr><th>Tytuł / nazwa</th><td><?php echo htmlspecialchars((string)($row['nazwa_tytul'] ?? '')); ?></td></tr>
            <tr><th>Autor / wytwórca</th><td><?php echo htmlspecialchars((string)($row['autor_wytworca'] ?? '')); ?></td></tr>
        </table>
    </div>
    <?php include __DIR__ . '/footer.php'; ?>
    </body>
    </html>
    <?php
    exit;
}

function ensureMoveUsernameColumn(PDO $pdo, string $movesTable): bool {
    try {
        $moveTableColumns = $pdo->query("SHOW COLUMNS FROM {$movesTable}")
            ->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('user_username', $moveTableColumns, true)) {
            $pdo->exec("ALTER TABLE {$movesTable} ADD COLUMN user_username VARCHAR(255) NULL");
        }

        return true;
    } catch (PDOException $e) {
        return false;
    }
}

$hasMoveUsernameColumn = ensureMoveUsernameColumn($pdo, $movesTable);

$moveAddError = null;
$moveAddSuccess = null;
$attachmentCreateError = null;
$attachmentCreateSuccess = null;
$attachmentVersionError = null;
$attachmentVersionSuccess = null;

function attachmentTypeLabels(): array {
    return [
        'umowa' => 'Umowa',
        'protokol' => 'Protokół',
        'karta-konserwatorska' => 'Karta konserwatorska',
        'decyzja' => 'Decyzja',
        'korespondencja' => 'Korespondencja',
        'zdjecie' => 'Zdjęcie',
        'inne' => 'Inne',
    ];
}

function attachmentBytesToUi(?int $bytes): string {
    if ($bytes === null) {
        return '-';
    }
    $units = ['B', 'KB', 'MB', 'GB'];
    $value = (float)$bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    return number_format($value, $unit === 0 ? 0 : 2, ',', ' ') . ' ' . $units[$unit];
}

function fetchAttachmentForRecord(PDO $pdo, int $attachmentId, string $collection, int $recordId): ?array {
    $stmt = $pdo->prepare(
        "SELECT *
         FROM record_attachments
         WHERE id = :id AND collection = :collection AND record_id = :record_id
         LIMIT 1"
    );
    $stmt->execute([
        'id' => $attachmentId,
        'collection' => $collection,
        'record_id' => $recordId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_przemieszczenie'])) {
    if (!$canMoveRecords) {
        http_response_code(403);
        die('Brak uprawnień do dodawania przemieszczeń.');
    }
    $dataPrzemieszczenia = trim($_POST['data_przemieszczenia'] ?? '');
    $dataZwrotu = trim($_POST['data_zwrotu'] ?? '');
    $miejscePrzemieszczenia = trim($_POST['miejsce_przemieszczenia'] ?? '');
    $powodCelPrzemieszczenia = trim($_POST['powod_cel_przemieszczenia'] ?? '');

    if ($dataPrzemieszczenia === '' || $miejscePrzemieszczenia === '') {
        $moveAddError = 'Uzupełnij pola wymagane: data i miejsce przemieszczenia.';
    } else {
        try {
            $numerPrzemieszczenia = (string)museumNextSequenceValue($pdo, museumMoveSequenceKey($selectedCollection));

            if ($hasMoveUsernameColumn) {
                $insertStmt = $pdo->prepare(
                    "INSERT INTO {$movesTable}
                    (karta_id, data_przemieszczenia, data_zwrotu, numer_przemieszczenia, miejsce_przemieszczenia, powod_cel_przemieszczenia, user_username)
                    VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $insertStmt->execute([
                    $id,
                    $dataPrzemieszczenia,
                    $dataZwrotu !== '' ? $dataZwrotu : null,
                    $numerPrzemieszczenia,
                    $miejscePrzemieszczenia,
                    $powodCelPrzemieszczenia !== '' ? $powodCelPrzemieszczenia : null,
                    $_SESSION['username'] ?? null
                ]);
            } else {
                $insertStmt = $pdo->prepare(
                    "INSERT INTO {$movesTable}
                    (karta_id, data_przemieszczenia, data_zwrotu, numer_przemieszczenia, miejsce_przemieszczenia, powod_cel_przemieszczenia)
                    VALUES (?, ?, ?, ?, ?, ?)"
                );
                $insertStmt->execute([
                    $id,
                    $dataPrzemieszczenia,
                    $dataZwrotu !== '' ? $dataZwrotu : null,
                    $numerPrzemieszczenia,
                    $miejscePrzemieszczenia,
                    $powodCelPrzemieszczenia !== '' ? $powodCelPrzemieszczenia : null
                ]);
            }

            $moveAddSuccess = 'Dodano przemieszczenie nr ' . $numerPrzemieszczenia . '.';
        } catch (PDOException $e) {
            appLogException('karta.php move', $e);
            $moveAddError = 'Błąd dodawania przemieszczenia.';
        } catch (RuntimeException $e) {
            appLogException('karta.php move runtime', $e);
            $moveAddError = 'Błąd dodawania przemieszczenia.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_attachment'])) {
    if (!$canUpdateRecords) {
        http_response_code(403);
        die('Brak uprawnień do dodawania załączników.');
    }

    $attachmentType = trim((string)($_POST['attachment_type'] ?? 'inne'));
    $title = trim((string)($_POST['attachment_title'] ?? ''));
    $sprawaRef = trim((string)($_POST['sprawa_ref'] ?? ''));
    $documentNumber = trim((string)($_POST['document_number'] ?? ''));
    $documentDate = trim((string)($_POST['document_date'] ?? ''));
    $description = trim((string)($_POST['attachment_description'] ?? ''));
    $versionNote = trim((string)($_POST['version_note'] ?? ''));
    $typeLabels = attachmentTypeLabels();
    if (!isset($typeLabels[$attachmentType])) {
        $attachmentType = 'inne';
    }

    if ($title === '') {
        $attachmentCreateError = 'Podaj tytuł załącznika.';
    } elseif (!isset($_FILES['attachment_file']) || (int)($_FILES['attachment_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $attachmentCreateError = 'Wybierz plik załącznika.';
    } elseif ($documentDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $documentDate)) {
        $attachmentCreateError = 'Nieprawidłowa data dokumentu.';
    } else {
        $storedFile = null;
        try {
            $pdo->beginTransaction();

            $insertAttachmentStmt = $pdo->prepare(
                "INSERT INTO record_attachments
                (collection, record_id, attachment_type, title, sprawa_ref, document_number, document_date, description, created_by, updated_by, created_at, updated_at)
                VALUES (:collection, :record_id, :attachment_type, :title, :sprawa_ref, :document_number, :document_date, :description, :created_by, :updated_by, NOW(), NOW())"
            );
            $insertAttachmentStmt->execute([
                'collection' => $selectedCollection,
                'record_id' => $id,
                'attachment_type' => $attachmentType,
                'title' => $title,
                'sprawa_ref' => $sprawaRef !== '' ? $sprawaRef : null,
                'document_number' => $documentNumber !== '' ? $documentNumber : null,
                'document_date' => $documentDate !== '' ? $documentDate : null,
                'description' => $description !== '' ? $description : null,
                'created_by' => $_SESSION['username'] ?? null,
                'updated_by' => $_SESSION['username'] ?? null,
            ]);
            $attachmentId = (int)$pdo->lastInsertId();

            $storedFile = museumStoreUploadedAttachmentVersion(
                $selectedCollection,
                $id,
                $attachmentId,
                1,
                $_FILES['attachment_file']
            );

            $insertVersionStmt = $pdo->prepare(
                "INSERT INTO record_attachment_versions
                (attachment_id, version_no, version_note, original_filename, stored_filename, stored_rel_path, mime_type, file_ext, file_size_bytes, sha256_hash, uploaded_by, created_at)
                VALUES (:attachment_id, :version_no, :version_note, :original_filename, :stored_filename, :stored_rel_path, :mime_type, :file_ext, :file_size_bytes, :sha256_hash, :uploaded_by, NOW())"
            );
            $insertVersionStmt->execute([
                'attachment_id' => $attachmentId,
                'version_no' => 1,
                'version_note' => $versionNote !== '' ? $versionNote : null,
                'original_filename' => $storedFile['original_filename'],
                'stored_filename' => $storedFile['stored_filename'],
                'stored_rel_path' => $storedFile['stored_rel_path'],
                'mime_type' => $storedFile['mime_type'],
                'file_ext' => $storedFile['file_ext'],
                'file_size_bytes' => $storedFile['file_size_bytes'],
                'sha256_hash' => $storedFile['sha256_hash'],
                'uploaded_by' => $_SESSION['username'] ?? null,
            ]);
            $versionId = (int)$pdo->lastInsertId();

            $updateAttachmentStmt = $pdo->prepare(
                "UPDATE record_attachments
                 SET current_version_id = :current_version_id,
                     current_version_no = :current_version_no,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id"
            );
            $updateAttachmentStmt->execute([
                'current_version_id' => $versionId,
                'current_version_no' => 1,
                'updated_by' => $_SESSION['username'] ?? null,
                'id' => $attachmentId,
            ]);

            $pdo->commit();
            $attachmentCreateSuccess = 'Dodano załącznik "' . $title . '" (wersja 1).';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (is_array($storedFile) && !empty($storedFile['stored_abs_path']) && is_file($storedFile['stored_abs_path'])) {
                @unlink((string)$storedFile['stored_abs_path']);
            }
            appLogException('karta.php attachment', $e);
            $attachmentCreateError = ($e instanceof RuntimeException)
                ? $e->getMessage()
                : 'Nie udało się dodać załącznika.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_attachment_version'])) {
    if (!$canUpdateRecords) {
        http_response_code(403);
        die('Brak uprawnień do wersjonowania załączników.');
    }

    $attachmentId = (int)($_POST['attachment_id'] ?? 0);
    $versionNote = trim((string)($_POST['version_note'] ?? ''));

    if ($attachmentId <= 0) {
        $attachmentVersionError = 'Nieprawidłowy identyfikator załącznika.';
    } elseif (!isset($_FILES['attachment_version_file']) || (int)($_FILES['attachment_version_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $attachmentVersionError = 'Wybierz plik nowej wersji.';
    } else {
        $storedFile = null;
        try {
            $attachmentRow = fetchAttachmentForRecord($pdo, $attachmentId, $selectedCollection, $id);
            if ($attachmentRow === null) {
                throw new RuntimeException('Załącznik nie należy do tej karty.');
            }

            $pdo->beginTransaction();

            $versionNoStmt = $pdo->prepare(
                "SELECT COALESCE(MAX(version_no), 0) + 1
                 FROM record_attachment_versions
                 WHERE attachment_id = :attachment_id"
            );
            $versionNoStmt->execute(['attachment_id' => $attachmentId]);
            $nextVersionNo = (int)$versionNoStmt->fetchColumn();
            if ($nextVersionNo <= 0) {
                $nextVersionNo = 1;
            }

            $storedFile = museumStoreUploadedAttachmentVersion(
                $selectedCollection,
                $id,
                $attachmentId,
                $nextVersionNo,
                $_FILES['attachment_version_file']
            );

            $insertVersionStmt = $pdo->prepare(
                "INSERT INTO record_attachment_versions
                (attachment_id, version_no, version_note, original_filename, stored_filename, stored_rel_path, mime_type, file_ext, file_size_bytes, sha256_hash, uploaded_by, created_at)
                VALUES (:attachment_id, :version_no, :version_note, :original_filename, :stored_filename, :stored_rel_path, :mime_type, :file_ext, :file_size_bytes, :sha256_hash, :uploaded_by, NOW())"
            );
            $insertVersionStmt->execute([
                'attachment_id' => $attachmentId,
                'version_no' => $nextVersionNo,
                'version_note' => $versionNote !== '' ? $versionNote : null,
                'original_filename' => $storedFile['original_filename'],
                'stored_filename' => $storedFile['stored_filename'],
                'stored_rel_path' => $storedFile['stored_rel_path'],
                'mime_type' => $storedFile['mime_type'],
                'file_ext' => $storedFile['file_ext'],
                'file_size_bytes' => $storedFile['file_size_bytes'],
                'sha256_hash' => $storedFile['sha256_hash'],
                'uploaded_by' => $_SESSION['username'] ?? null,
            ]);
            $versionId = (int)$pdo->lastInsertId();

            $updateAttachmentStmt = $pdo->prepare(
                "UPDATE record_attachments
                 SET current_version_id = :current_version_id,
                     current_version_no = :current_version_no,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id"
            );
            $updateAttachmentStmt->execute([
                'current_version_id' => $versionId,
                'current_version_no' => $nextVersionNo,
                'updated_by' => $_SESSION['username'] ?? null,
                'id' => $attachmentId,
            ]);

            $pdo->commit();
            $attachmentVersionSuccess = 'Dodano wersję ' . $nextVersionNo . ' do załącznika "' . (string)$attachmentRow['title'] . '".';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (is_array($storedFile) && !empty($storedFile['stored_abs_path']) && is_file($storedFile['stored_abs_path'])) {
                @unlink((string)$storedFile['stored_abs_path']);
            }
            appLogException('karta.php attachment version', $e);
            $attachmentVersionError = ($e instanceof RuntimeException)
                ? $e->getMessage()
                : 'Nie udało się dodać wersji załącznika.';
        }
    }
}


// Obsługa formularza edycji
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_karta'])) {
    if (!$canUpdateRecords) {
        http_response_code(403);
        die('Brak uprawnień do aktualizacji danych w systemie ewidencyjnym.');
    }
    try {
        // Obsługiwane kolumny
        $valid_columns = [
            'nazwa_tytul', 'czas_powstania', 'inne_numery_ewidencyjne',
            'autor_wytworca', 'miejsce_powstania', 'liczba', 'material', 
            'dokumentacja_wizualna', 'dzial', 'pochodzenie', 'technika_wykonania', 
            'wymiary', 'cechy_charakterystyczne', 'dane_o_dokumentacji_wizualnej', 
            'wlasciciel', 'sposob_oznakowania', 'autorskie_prawa_majatkowe', 
            'kontrola_zbiorow', 'wartosc_w_dniu_nabycia', 'wartosc_w_dniu_sporzadzenia', 
            'miejsce_przechowywania', 'uwagi', 'data_opracowania', 'opracowujacy'
        ];

        $postedInventoryNumber = isset($_POST['numer_ewidencyjny']) ? trim((string)$_POST['numer_ewidencyjny']) : null;
        $currentInventoryNumber = trim((string)($row['numer_ewidencyjny'] ?? ''));
        if ($postedInventoryNumber !== null && $postedInventoryNumber !== $currentInventoryNumber) {
            http_response_code(400);
            die('Numer inwentarzowy jest trwały i nie podlega zmianie.');
        }

        // Filtrowanie danych
        $updated_data = [];
        foreach ($valid_columns as $column) {
            if (isset($_POST[$column])) {
                $updated_data[$column] = $_POST[$column];
            } else {
                $updated_data[$column] = $row[$column];
            }

            if ($column === 'dokumentacja_wizualna') {
                $sourceImageValue = isset($updated_data[$column]) ? (string)$updated_data[$column] : null;
                $normalizedImageValue = museumNormalizeImageReference($sourceImageValue);
                $updated_data[$column] = $normalizedImageValue ?? ($sourceImageValue !== null ? '' : null);
            }
        }

        // Tworzenie zapytania SQL
        $sql = "UPDATE {$mainTable} SET " . 
            implode(", ", array_map(fn($key) => "$key = :$key", array_keys($updated_data))) . 
            " WHERE ID = :id";

        $updated_data['id'] = $id; // Dodanie ID do parametrów

        // Wykonanie zapytania
        $update_stmt = $pdo->prepare($sql);
        $update_stmt->execute($updated_data);

        // Logowanie zmian
        $changes = [];
        foreach ($updated_data as $key => $new_value) {
            if ($key === 'id' || $key === 'ID') {
                continue;
            }
            if (($row[$key] ?? null) != $new_value) {
                $changes[] = [
                    'field' => $key,
                    'old_value' => $row[$key],
                    'new_value' => $new_value
                ];
            }
        }

        if (!empty($changes)) {
            $log_stmt = $pdo->prepare("INSERT INTO {$logTable} 
                (karta_id, user_username, changed_field, old_value, new_value, change_date) 
                VALUES (:karta_id, :user_username, :changed_field, :old_value, :new_value, NOW())");

            foreach ($changes as $change) {
                $log_stmt->execute([
                    'karta_id' => $id,
                    'user_username' => $_SESSION['username'],
                    'changed_field' => $change['field'],
                    'old_value' => $change['old_value'],
                    'new_value' => $change['new_value']
                ]);
            }
        }

        // Przeładuj stronę
        header("Location: karta.php?id=" . $id . "&collection=" . urlencode($selectedCollection) . "&ledger=" . urlencode($selectedLedger));
        exit;

    } catch (PDOException $e) {
        appLogException('karta.php update', $e);
        echo "Błąd aktualizacji karty.";
        die();
    }
}
// Pobranie załączników procesowych i ich wersji
$attachments_stmt = $pdo->prepare(
    "SELECT a.*,
            v.id AS current_version_row_id,
            v.version_note AS current_version_note,
            v.original_filename AS current_original_filename,
            v.mime_type AS current_mime_type,
            v.file_ext AS current_file_ext,
            v.file_size_bytes AS current_file_size_bytes,
            v.sha256_hash AS current_sha256_hash,
            v.uploaded_by AS current_uploaded_by,
            v.created_at AS current_uploaded_at
     FROM record_attachments a
     LEFT JOIN record_attachment_versions v ON v.id = a.current_version_id
     WHERE a.collection = :collection
       AND a.record_id = :record_id
     ORDER BY a.created_at DESC, a.id DESC"
);
$attachments_stmt->execute([
    'collection' => $selectedCollection,
    'record_id' => $id,
]);
$attachments = $attachments_stmt->fetchAll(PDO::FETCH_ASSOC);

$attachmentVersionsByAttachmentId = [];
if (!empty($attachments)) {
    $attachmentIds = array_map(static fn(array $a): int => (int)$a['id'], $attachments);
    $attachmentIds = array_values(array_filter($attachmentIds, static fn(int $value): bool => $value > 0));

    if (!empty($attachmentIds)) {
        $placeholders = implode(',', array_fill(0, count($attachmentIds), '?'));
        $versionsStmt = $pdo->prepare(
            "SELECT *
             FROM record_attachment_versions
             WHERE attachment_id IN ({$placeholders})
             ORDER BY attachment_id ASC, version_no DESC, created_at DESC"
        );
        $versionsStmt->execute($attachmentIds);
        foreach ($versionsStmt->fetchAll(PDO::FETCH_ASSOC) as $versionRow) {
            $attachmentVersionsByAttachmentId[(int)$versionRow['attachment_id']][] = $versionRow;
        }
    }
}

// Pobranie logów zmian
$log_stmt = $pdo->prepare("SELECT * FROM {$logTable} WHERE karta_id = :id ORDER BY change_date DESC");
$log_stmt->execute(['id' => $id]);
$log_entries = $log_stmt->fetchAll(PDO::FETCH_ASSOC);

// Pobranie przemieszczeń
$przemieszczenia_stmt = $pdo->prepare("SELECT * FROM {$movesTable} WHERE karta_id = :id");
$przemieszczenia_stmt->execute(['id' => $id]);
$przemieszczenia_rows = $przemieszczenia_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Karta Ewidencyjna kolekcji MKA</title>
        <link rel="stylesheet" href="styles.css">
</head>
<body>
    <?php
    $kartaActions = [
        ['label' => 'Powrót do listy', 'href' => 'index.php?collection=' . rawurlencode($selectedCollection)],
    ];
    if ($searchReturnUrl !== '') {
        $kartaActions[] = ['label' => 'Powrót do wyszukiwania', 'href' => $searchReturnUrl];
    }
    if ($isFirstOpenFromMobileAdd) {
        $kartaActions[] = ['label' => 'Dodaj następny', 'href' => 'mobile_add.php?collection=' . rawurlencode($selectedCollection) . '&mobile=1'];
    }

    renderAppHeader([
        'selectedCollection' => $selectedCollection,
        'collections' => $collections,
        'lists' => [],
        'username' => $username,
        'logoHref' => 'index.php?collection=' . rawurlencode($selectedCollection),
        'showColumnButton' => false,
        'showListEditor' => false,
        'primaryActions' => $kartaActions,
    ]);
    ?>
    
    <h1>Karta Ewidencji</h1>
    <div class="share-link-box">
        <h3>Udostępnij kartę</h3>
        <form method="post" class="form-inline">
            <?= appCsrfField() ?>
            <input type="hidden" name="create_share_link" value="1">
            <button type="submit">Wygeneruj publiczny link (14 dni)</button>
        </form>

        <?php if ($shareLinkSuccess !== null): ?>
            <p class="muted">
                Link tylko do odczytu. Wygasa:
                <strong><?php echo htmlspecialchars(formatDateTimeForUi((string)$shareLinkExpiresAt)); ?></strong>
            </p>
            <div class="share-link-actions">
                <input
                    type="text"
                    id="shareLinkInput"
                    class="share-link-input"
                    value="<?php echo htmlspecialchars($shareLinkSuccess); ?>"
                    readonly
                >
                <button type="button" onclick="copyShareLink()">Kopiuj link</button>
                <a role="button" id="toggleButton" href="<?php echo htmlspecialchars($shareLinkSuccess); ?>" target="_blank" rel="noopener noreferrer">Otwórz link w nowej karcie</a>
            </div>
            <p id="shareCopyStatus" class="muted"></p>
        <?php endif; ?>

        <?php if ($shareLinkError): ?>
            <p class="message-error"><?= htmlspecialchars($shareLinkError) ?></p>
        <?php endif; ?>
    </div>
    <table>
        <?php foreach ($row as $key => $value): ?>
            <tr>
                <th width="300px"><?= htmlspecialchars($key) ?></th>
                <td>
                    <?php if ($key === 'dokumentacja_wizualna' && $image_path): ?>
                        <a href="<?= htmlspecialchars($image_path) ?>" target="_blank" rel="noopener noreferrer">
                            <?= htmlspecialchars((string)$value) ?>
                        </a>
                    <?php else: ?>
                        <?= htmlspecialchars($value) ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($image_path): ?>
            <tr>
                <td colspan="2">
                    <img <?php echo museumImgSrcFallbackAttributes($image_urls); ?> alt="Obrazek obiektu" width="600">
                </td>
            </tr>
        <?php endif; ?>
    </table>

    <button type="button" id="toggleAttachmentsButton" onclick="toggleAttachments()">Pokaż/Ukryj dokumenty i załączniki procesowe</button>
    <div id="attachmentsContainer" class="attachments-box" style="margin-top:20px; display:none;">
        <h2>Dokumenty i załączniki procesowe</h2>
        <p class="muted" style="margin-top:0;">
            Moduł obsługuje załączniki z metadanymi i wersjonowaniem (np. umowy, protokoły, karty konserwatorskie) powiązane z tą kartą i sprawą.
        </p>

        <?php if ($attachmentCreateSuccess): ?>
            <p class="message-success"><?= htmlspecialchars($attachmentCreateSuccess) ?></p>
        <?php endif; ?>
        <?php if ($attachmentCreateError): ?>
            <p class="message-error"><?= htmlspecialchars($attachmentCreateError) ?></p>
        <?php endif; ?>
        <?php if ($attachmentVersionSuccess): ?>
            <p class="message-success"><?= htmlspecialchars($attachmentVersionSuccess) ?></p>
        <?php endif; ?>
        <?php if ($attachmentVersionError): ?>
            <p class="message-error"><?= htmlspecialchars($attachmentVersionError) ?></p>
        <?php endif; ?>

        <?php $attachmentTypeMap = attachmentTypeLabels(); ?>

        <?php if (empty($attachments)): ?>
            <p>Brak załączników dla tej karty.</p>
        <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:14px;">
                <?php foreach ($attachments as $attachment): ?>
                    <?php
                    $attachmentId = (int)$attachment['id'];
                    $versions = $attachmentVersionsByAttachmentId[$attachmentId] ?? [];
                    $currentVersionRowId = (int)($attachment['current_version_row_id'] ?? 0);
                    ?>
                    <section style="border:1px solid #ddd; border-radius:10px; padding:12px; background:#fff;">
                        <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                            <div>
                                <h3 style="margin:0 0 6px 0;"><?= htmlspecialchars((string)$attachment['title']) ?></h3>
                                <div class="muted">
                                    Typ: <strong><?= htmlspecialchars($attachmentTypeMap[(string)($attachment['attachment_type'] ?? 'inne')] ?? (string)($attachment['attachment_type'] ?? 'inne')) ?></strong>
                                    | Wersja bieżąca: <strong><?= (int)($attachment['current_version_no'] ?? 0) ?></strong>
                                    | ID załącznika: <strong><?= $attachmentId ?></strong>
                                </div>
                            </div>
                            <?php if ($currentVersionRowId > 0): ?>
                                <div style="display:flex; gap:8px; align-items:flex-start; flex-wrap:wrap;">
                                    <a role="button" id="toggleButton" href="attachment_download.php?version_id=<?= $currentVersionRowId ?>">Pobierz bieżącą wersję</a>
                                    <?php
                                    $inlineExt = strtolower((string)($attachment['current_file_ext'] ?? ''));
                                    $isInline = in_array($inlineExt, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'], true);
                                    ?>
                                    <?php if ($isInline): ?>
                                        <a role="button" id="toggleButton" href="attachment_download.php?version_id=<?= $currentVersionRowId ?>&inline=1" target="_blank" rel="noopener noreferrer">Otwórz</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <table style="margin-top:10px;">
                            <tr><th style="width:220px;">Powiązanie ze sprawą</th><td><?= htmlspecialchars((string)($attachment['sprawa_ref'] ?? '')) ?: '—' ?></td></tr>
                            <tr><th>Numer dokumentu</th><td><?= htmlspecialchars((string)($attachment['document_number'] ?? '')) ?: '—' ?></td></tr>
                            <tr><th>Data dokumentu</th><td><?= htmlspecialchars((string)($attachment['document_date'] ?? '')) ?: '—' ?></td></tr>
                            <tr><th>Opis</th><td><?= nl2br(htmlspecialchars((string)($attachment['description'] ?? ''))) ?: '—' ?></td></tr>
                            <tr><th>Utworzył / aktualizował</th><td><?= htmlspecialchars((string)($attachment['created_by'] ?? '')) ?: '—' ?> / <?= htmlspecialchars((string)($attachment['updated_by'] ?? '')) ?: '—' ?></td></tr>
                            <tr><th>Daty</th><td><?= htmlspecialchars((string)($attachment['created_at'] ?? '')) ?> / <?= htmlspecialchars((string)($attachment['updated_at'] ?? '')) ?></td></tr>
                        </table>

                        <h4 style="margin:12px 0 6px;">Historia wersji</h4>
                        <div style="overflow:auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Wersja</th>
                                        <th>Plik</th>
                                        <th>Typ MIME</th>
                                        <th>Rozmiar</th>
                                        <th>SHA-256</th>
                                        <th>Wgrał</th>
                                        <th>Data</th>
                                        <th>Opis wersji</th>
                                        <th>Akcje</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($versions)): ?>
                                        <tr><td colspan="9">Brak wersji.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($versions as $version): ?>
                                            <?php $versionId = (int)$version['id']; ?>
                                            <tr>
                                                <td><?= (int)($version['version_no'] ?? 0) ?></td>
                                                <td><?= htmlspecialchars((string)($version['original_filename'] ?? '')) ?></td>
                                                <td><?= htmlspecialchars((string)($version['mime_type'] ?? '')) ?: '—' ?></td>
                                                <td><?= htmlspecialchars(attachmentBytesToUi(isset($version['file_size_bytes']) ? (int)$version['file_size_bytes'] : null)) ?></td>
                                                <td><code><?= htmlspecialchars(!empty($version['sha256_hash']) ? substr((string)$version['sha256_hash'], 0, 12) . '…' : '—') ?></code></td>
                                                <td><?= htmlspecialchars((string)($version['uploaded_by'] ?? '')) ?: '—' ?></td>
                                                <td><?= htmlspecialchars((string)($version['created_at'] ?? '')) ?></td>
                                                <td><?= htmlspecialchars((string)($version['version_note'] ?? '')) ?: '—' ?></td>
                                                <td>
                                                    <a href="attachment_download.php?version_id=<?= $versionId ?>">Pobierz</a>
                                                    <?php
                                                    $vExt = strtolower((string)($version['file_ext'] ?? ''));
                                                    $vInline = in_array($vExt, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'], true);
                                                    ?>
                                                    <?php if ($vInline): ?>
                                                        | <a href="attachment_download.php?version_id=<?= $versionId ?>&inline=1" target="_blank" rel="noopener noreferrer">Otwórz</a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($canUpdateRecords): ?>
                            <h4 style="margin:12px 0 6px;">Dodaj nową wersję</h4>
                            <form method="post" enctype="multipart/form-data" class="add-form" style="max-width:700px;">
                                <?= appCsrfField() ?>
                                <input type="hidden" name="add_attachment_version" value="1">
                                <input type="hidden" name="attachment_id" value="<?= $attachmentId ?>">
                                <label for="attachment_version_file_<?= $attachmentId ?>">Plik nowej wersji</label>
                                <input type="file" id="attachment_version_file_<?= $attachmentId ?>" name="attachment_version_file" required>
                                <label for="version_note_<?= $attachmentId ?>">Opis wersji (opcjonalnie)</label>
                                <input type="text" id="version_note_<?= $attachmentId ?>" name="version_note" placeholder="np. podpisana wersja / korekta daty / skan lepszej jakości">
                                <button type="submit">Dodaj wersję</button>
                            </form>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($canUpdateRecords): ?>
            <h3 style="margin-top:18px;">Dodaj nowy załącznik</h3>
            <form method="post" enctype="multipart/form-data" class="add-form">
                <?= appCsrfField() ?>
                <input type="hidden" name="create_attachment" value="1">

                <label for="attachment_type">Typ dokumentu</label>
                <select id="attachment_type" name="attachment_type">
                    <?php foreach ($attachmentTypeMap as $typeKey => $typeLabel): ?>
                        <option value="<?= htmlspecialchars($typeKey) ?>"><?= htmlspecialchars($typeLabel) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="attachment_title">Tytuł</label>
                <input type="text" id="attachment_title" name="attachment_title" required placeholder="np. Umowa wypożyczenia / Karta prac konserwatorskich">

                <label for="sprawa_ref">Sprawa / numer sprawy (opcjonalnie)</label>
                <input type="text" id="sprawa_ref" name="sprawa_ref" placeholder="np. MKA/2026/12">

                <label for="document_number">Numer dokumentu (opcjonalnie)</label>
                <input type="text" id="document_number" name="document_number" placeholder="np. UM/04/2026">

                <label for="document_date">Data dokumentu (opcjonalnie)</label>
                <input type="date" id="document_date" name="document_date">

                <label for="attachment_description">Opis / metadane (opcjonalnie)</label>
                <textarea id="attachment_description" name="attachment_description" rows="3" placeholder="Krótki opis dokumentu, zakres, uwagi..."></textarea>

                <label for="version_note">Opis wersji 1 (opcjonalnie)</label>
                <input type="text" id="version_note" name="version_note" placeholder="np. skan podpisanego oryginału">

                <label for="attachment_file">Plik</label>
                <input type="file" id="attachment_file" name="attachment_file" required>
                <small>Dozwolone m.in. PDF, obrazy, dokumenty biurowe i archiwa.</small>

                <button type="submit">Dodaj załącznik</button>
            </form>
        <?php else: ?>
            <p style="color:#b10000;">Nie masz uprawnień do dodawania i wersjonowania załączników.</p>
        <?php endif; ?>
    </div>

    <!-- Tabela Edycji Karty -->
    <?php if ($canUpdateRecords): ?>
    <button type="button" id="toggleEditKartaButton" onclick="toggleEditKarta()">Pokaż/Ukryj tabelę edycji karty</button>
    <div id="editKartaContainer">
        <h2>Edytuj Kartę</h2>
        <form method="post">
            <?= appCsrfField() ?>
            <input type="hidden" name="edit_karta" value="1">
            <table>
                <?php foreach ($row as $key => $value): ?>
                    <tr>
                        <th width="300px"><label for="<?= $key ?>"><?= htmlspecialchars($key) ?></label></th>
                        <td>
                            <?php if ($key === 'ID' || $key === 'numer_ewidencyjny'): ?>
                                <input type="text" id="<?= $key ?>" value="<?= htmlspecialchars((string)$value) ?>" readonly>
                            <?php else: ?>
                                <input type="text" name="<?= $key ?>" id="<?= $key ?>" value="<?= htmlspecialchars((string)$value) ?>">
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <button type="submit">Zapisz zmiany</button>
        </form>
    </div>
    <?php else: ?>
    <p style="color:#b10000;">Nie masz uprawnień do aktualizacji danych tej karty.</p>
    <?php endif; ?>

    <!-- Tabela Historii Zmian -->
    <button type="button" id="toggleLogButton" onclick="toggleLog()">Pokaż/Ukryj historię zmian</button>
    <div id="logContainer">
        <h2>Historia Zmian</h2>
        <table>
            <thead>
                <tr>
                    <th>Data zmiany</th>
                    <th>Użytkownik</th>
                    <th>Pole</th>
                    <th>Stara wartość</th>
                    <th>Nowa wartość</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($log_entries as $entry): ?>
                    <tr>
                        <td><?= htmlspecialchars($entry['change_date']) ?></td>
                        <td><?= htmlspecialchars($entry['user_username']) ?></td>
                        <td><?= htmlspecialchars($entry['changed_field']) ?></td>
                        <td><?= htmlspecialchars($entry['old_value']) ?></td>
                        <td><?= htmlspecialchars($entry['new_value']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Tabela Przemieszczeń -->
    <button type="button" id="togglePrzemieszczeniaButton" onclick="togglePrzemieszczenia()">Pokaż/Ukryj tabelę przemieszczeń</button>
    <div id="przemieszczeniaContainer">
        <h2>Przemieszczenia</h2>
        <table>
            <thead>
                <tr>
                    <th>Data Przemieszczenia</th>
                    <th>Data Zwrotu</th>
                    <th>Numer Przemieszczenia</th>
                    <th>Miejsce Przemieszczenia</th>
                    <th>Powód/Cel Przemieszczenia</th>
                    <th>Użytkownik</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($przemieszczenia_rows as $przemieszczenie): ?>
                    <tr>
                        <td><?= htmlspecialchars($przemieszczenie['data_przemieszczenia']); ?></td>
                        <td><?= htmlspecialchars($przemieszczenie['data_zwrotu']); ?></td>
                        <td><?= htmlspecialchars($przemieszczenie['numer_przemieszczenia']); ?></td>
                        <td><?= htmlspecialchars($przemieszczenie['miejsce_przemieszczenia']); ?></td>
                        <td><?= htmlspecialchars($przemieszczenie['powod_cel_przemieszczenia']); ?></td>
                        <td><?= htmlspecialchars($przemieszczenie['user_username'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

<br><br>

   <?php if ($canMoveRecords): ?>
   <h3>Dodaj nowe przemieszczenie:</h3>
        <form method="post" class="add-form">
            <?= appCsrfField() ?>
            <input type="hidden" name="add_przemieszczenie" value="1">
            <label for="data_przemieszczenia">Data Przemieszczenia</label>
            <input type="date" name="data_przemieszczenia" id="data_przemieszczenia" required>

            <label for="data_zwrotu">Data Zwrotu</label>
            <input type="date" name="data_zwrotu" id="data_zwrotu">

            <label for="numer_przemieszczenia">Numer Przemieszczenia (nadawany automatycznie)</label>
            <input type="text" id="numer_przemieszczenia" value="Nadawany przy zapisie" readonly>

            <label for="miejsce_przemieszczenia">Miejsce Przemieszczenia</label>
            <input type="text" name="miejsce_przemieszczenia" id="miejsce_przemieszczenia" required>

            <label for="powod_cel_przemieszczenia">Powód/Cel Przemieszczenia</label>
            <textarea name="powod_cel_przemieszczenia" id="powod_cel_przemieszczenia"></textarea>

            <button id="togglePrzemieszczeniaButton" type="submit">Dodaj</button>
        </form>
   <?php endif; ?>

        <?php if ($moveAddSuccess): ?>
            <p class="message-success"><?= htmlspecialchars($moveAddSuccess) ?></p>
        <?php endif; ?>
        <?php if ($moveAddError): ?>
            <p class="message-error"><?= htmlspecialchars($moveAddError) ?></p>
        <?php endif; ?>

    </div>




    <div style="height: 100px;" aria-hidden="true"></div>

    <?php include __DIR__ . '/footer.php'; ?>

    <script>
        function toggleContainer(containerId) {
            const container = document.getElementById(containerId);
            if (!container) {
                return;
            }

            const isHidden = container.style.display === 'none' || container.style.display === '';
            container.style.display = isHidden ? 'block' : 'none';

            if (isHidden) {
                container.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        function toggleEditKarta() {
            toggleContainer('editKartaContainer');
        }

        function toggleLog() {
            toggleContainer('logContainer');
        }

        function togglePrzemieszczenia() {
            toggleContainer('przemieszczeniaContainer');
        }

        function toggleAttachments() {
            toggleContainer('attachmentsContainer');
        }

        function setShareCopyStatus(message, isError = false) {
            const statusNode = document.getElementById('shareCopyStatus');
            if (!statusNode) {
                return;
            }

            statusNode.textContent = message;
            statusNode.className = isError ? 'message-error' : 'message-success';
        }

        async function copyShareLink() {
            const input = document.getElementById('shareLinkInput');
            if (!input) {
                return;
            }

            const link = input.value;

            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(link);
                    setShareCopyStatus('Link został skopiowany.');
                    return;
                }
            } catch (error) {
                // Fallback poniżej.
            }

            input.focus();
            input.select();

            try {
                const copied = document.execCommand('copy');
                setShareCopyStatus(copied ? 'Link został skopiowany.' : 'Nie udało się skopiować linku.', !copied);
            } catch (error) {
                setShareCopyStatus('Nie udało się skopiować linku.', true);
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            const shouldOpenMoves = <?= json_encode($moveAddSuccess !== null || $moveAddError !== null) ?>;
            if (shouldOpenMoves) {
                const container = document.getElementById('przemieszczeniaContainer');
                if (container) {
                    container.style.display = 'block';
                }
            }

            const shouldOpenAttachments = <?= json_encode(
                $attachmentCreateSuccess !== null
                || $attachmentCreateError !== null
                || $attachmentVersionSuccess !== null
                || $attachmentVersionError !== null
            ) ?>;
            if (shouldOpenAttachments) {
                const container = document.getElementById('attachmentsContainer');
                if (container) {
                    container.style.display = 'block';
                }
            }
        });
    </script>
</body>
</html>
