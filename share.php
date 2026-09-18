<?php
include 'db.php';

$collections = [
    'ksiazki-artystyczne' => [
        'label' => 'Książki Artystyczne',
        'main' => 'karta_ewidencyjna',
        'moves' => 'karta_ewidencyjna_przemieszczenia',
    ],
    'kolekcja-maszyn' => [
        'label' => 'Maszyny',
        'main' => 'karta_ewidencyjna_maszyny',
        'moves' => 'karta_ewidencyjna_maszyny_przemieszczenia',
    ],
    'kolekcja-matryc' => [
        'label' => 'Matryce',
        'main' => 'karta_ewidencyjna_matryce',
        'moves' => 'karta_ewidencyjna_matryce_przemieszczenia',
    ],
    'biblioteka' => [
        'label' => 'Biblioteka',
        'main' => 'karta_ewidencyjna_bib',
        'moves' => 'karta_ewidencyjna_bib_przemieszczenia',
    ],
    'kolekcja-klisz' => [
        'label' => 'Klisze drukarskie',
        'main' => 'karta_ewidencyjna_klisze',
        'moves' => 'karta_ewidencyjna_klisze_przemieszczenia',
    ],
];

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

function trackGrowthEvent(PDO $pdo, string $eventName, string $collection, ?int $recordId, ?string $shareToken, array $meta = []): void {
    try {
        $metaJson = empty($meta) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $pdo->prepare(
            "INSERT INTO growth_events (event_name, collection, record_id, share_token, user_username, meta_json)
             VALUES (:event_name, :collection, :record_id, :share_token, NULL, :meta_json)"
        );
        $stmt->execute([
            'event_name' => $eventName,
            'collection' => $collection,
            'record_id' => $recordId,
            'share_token' => $shareToken,
            'meta_json' => $metaJson !== false ? $metaJson : null,
        ]);
    } catch (Throwable $e) {
        // Metryki nie mogą blokować publicznego podglądu.
    }
}

function buildImagePaths(?string $rawImageValue): array {
    if ($rawImageValue === null) {
        return [null, null];
    }

    $normalizedImageValue = trim(trim($rawImageValue), " '\"");
    if ($normalizedImageValue === '') {
        return [null, null];
    }

    if (preg_match('#^https?://#i', $normalizedImageValue) === 1) {
        return [$normalizedImageValue, null];
    }

    $relativeImagePath = ltrim($normalizedImageValue, '/');
    $encodedSegments = array_map('rawurlencode', array_filter(explode('/', $relativeImagePath), 'strlen'));
    if (empty($encodedSegments)) {
        return [null, null];
    }

    $encodedPath = implode('/', $encodedSegments);
    return [
        'https://baza.mkal.pl/gfx/' . $encodedPath,
        'https://mkalodz.pl/bazagfx/' . $encodedPath,
    ];
}

ensureShareTables($pdo);
$museumLogoPath = 'https://mkal.pl/wp-content/uploads/2025/03/logo-MKA.png';
$museumLogoFallbackPath = 'https://mkal.pl/wp-content/uploads/2024/04/LOGOTYPY-MKA_NO_L_MARGIN_white_onside.png';

$token = trim((string)($_GET['t'] ?? ''));
if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
    http_response_code(404);
    $shareError = 'Link jest nieprawidłowy.';
} else {
    $shareStmt = $pdo->prepare(
        "SELECT token, collection, record_id, expires_at
         FROM record_share_links
         WHERE token = :token
           AND revoked = 0
           AND expires_at >= NOW()
         LIMIT 1"
    );
    $shareStmt->execute(['token' => $token]);
    $shareData = $shareStmt->fetch(PDO::FETCH_ASSOC);

    if (!$shareData || !isset($collections[$shareData['collection']])) {
        http_response_code(404);
        $shareError = 'Link wygasł albo został wyłączony.';
    } else {
        $selectedCollection = $shareData['collection'];
        $mainTable = $collections[$selectedCollection]['main'];
        $movesTable = $collections[$selectedCollection]['moves'];
        $recordId = (int)$shareData['record_id'];

        $recordStmt = $pdo->prepare("SELECT * FROM {$mainTable} WHERE ID = :id");
        $recordStmt->execute(['id' => $recordId]);
        $record = $recordStmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            http_response_code(404);
            $shareError = 'Nie znaleziono rekordu dla tego linku.';
        } else {
            $updateShareStmt = $pdo->prepare(
                "UPDATE record_share_links
                 SET opened_count = opened_count + 1, last_opened_at = NOW()
                 WHERE token = :token"
            );
            $updateShareStmt->execute(['token' => $token]);

            trackGrowthEvent(
                $pdo,
                'share_link_opened',
                $selectedCollection,
                $recordId,
                $token,
                ['user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]
            );

            [$imagePath, $imageFallbackPath] = buildImagePaths($record['dokumentacja_wizualna'] ?? null);
            $movesStmt = $pdo->prepare(
                "SELECT data_przemieszczenia, data_zwrotu, numer_przemieszczenia, miejsce_przemieszczenia, powod_cel_przemieszczenia
                 FROM {$movesTable}
                 WHERE karta_id = :id
                 ORDER BY CAST(numer_przemieszczenia AS UNSIGNED) DESC, data_przemieszczenia DESC"
            );
            $movesStmt->execute(['id' => $recordId]);
            $movesRows = $movesStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Udostępniona karta | baza.mkal.pl</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .share-actions {
            max-width: 1180px;
            margin: 12px auto 8px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .share-actions button {
            cursor: pointer;
            border: 1px solid #111;
            background: #fff;
            color: #111;
            padding: 8px 14px;
            font-size: 14px;
            font-family: Arial, sans-serif;
        }

        .sheet {
            max-width: 1180px;
            margin: 14px auto 40px;
            border: 2px solid #111;
            background: #fff;
            color: #000;
            font-family: "Times New Roman", serif;
        }

        .sheet-header {
            display: grid;
            grid-template-columns: 1fr 320px;
            border-bottom: 2px solid #111;
        }

        .sheet-title {
            padding: 16px;
        }

        .sheet-title-row {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: nowrap;
        }

        .sheet-title-text {
            min-width: 0;
        }

        .sheet-title h1 {
            margin: 0;
            text-transform: none;
            font-size: 34px;
            letter-spacing: .2px;
        }

        .sheet-title p {
            margin: 8px 0 0;
            font-size: 16px;
        }

        .sheet-meta {
            border-left: 2px solid #111;
            padding: 14px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 6px;
            font-size: 14px;
        }

        .title-logo {
            margin-bottom: 0;
            flex: 0 0 auto;
        }

        .title-logo img {
            width: 210px;
            max-width: 100%;
            height: auto;
            display: block;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
        }

        .field {
            border-right: 1px solid #111;
            border-bottom: 1px solid #111;
            min-height: 84px;
            padding: 8px 10px;
        }

        .field-full { grid-column: span 12; }
        .field-8 { grid-column: span 8; }
        .field-6 { grid-column: span 6; }
        .field-4 { grid-column: span 4; }

        .field-label {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 6px;
        }

        .field-value {
            white-space: pre-wrap;
            word-break: break-word;
            line-height: 1.25;
            font-size: 15px;
            min-height: 26px;
        }

        .visual-wrap img {
            max-width: 100%;
            height: auto;
            display: block;
            border: 1px solid #333;
        }

        .moves-section {
            border-top: 2px solid #111;
        }

        .moves-section h2 {
            margin: 0;
            text-transform: none;
            font-size: 22px;
            padding: 10px 12px;
            border-bottom: 1px solid #111;
        }

        .moves-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0;
        }

        .moves-table th,
        .moves-table td {
            color: #000;
            background: #fff;
            border: 1px solid #111;
            vertical-align: top;
            font-size: 14px;
            padding: 8px 7px;
        }

        .public-note {
            margin: 0;
            padding: 8px 12px 12px;
            font-size: 13px;
        }

        @media (max-width: 900px) {
            .sheet-header {
                grid-template-columns: 1fr;
            }

            .sheet-title-row {
                flex-wrap: wrap;
            }

            .sheet-meta {
                border-left: 0;
                border-top: 2px solid #111;
            }

            .field-8,
            .field-6,
            .field-4 {
                grid-column: span 12;
            }
        }

        @media print {
            .share-actions {
                display: none;
            }

            .sheet {
                margin: 0;
                max-width: none;
            }
        }
    </style>
</head>
<body>
    <?php if (!empty($shareError)): ?>
        <div class="sheet">
            <div class="sheet-title">
                <h1>Udostępniona karta</h1>
                <p class="message-error"><?php echo htmlspecialchars($shareError); ?></p>
            </div>
        </div>
    <?php else: ?>
        <div class="share-actions">
            <button type="button" onclick="exportToPdf()">Eksport do PDF</button>
            <button type="button" onclick="window.print()">Drukuj</button>
        </div>
        <?php
        $fieldMap = [
            ['nr' => '1', 'label' => 'Numer ewidencyjny', 'key' => 'numer_ewidencyjny', 'class' => 'field-4'],
            ['nr' => '2', 'label' => 'Nazwa / tytuł', 'key' => 'nazwa_tytul', 'class' => 'field-8'],
            ['nr' => '3', 'label' => 'Czas powstania', 'key' => 'czas_powstania', 'class' => 'field-4'],
            ['nr' => '4', 'label' => 'Inne numery ewidencyjne', 'key' => 'inne_numery_ewidencyjne', 'class' => 'field-4'],
            ['nr' => '5', 'label' => 'Autor, wytwórca', 'key' => 'autor_wytworca', 'class' => 'field-4'],
            ['nr' => '6', 'label' => 'Miejsce powstania', 'key' => 'miejsce_powstania', 'class' => 'field-6'],
            ['nr' => '7', 'label' => 'Materiał', 'key' => 'material', 'class' => 'field-6'],
            ['nr' => '8', 'label' => 'Dział', 'key' => 'dzial', 'class' => 'field-6'],
            ['nr' => '9', 'label' => 'Dokumentacja wizualna', 'key' => 'dokumentacja_wizualna', 'class' => 'field-6'],
            ['nr' => '10', 'label' => 'Liczba', 'key' => 'liczba', 'class' => 'field-4'],
            ['nr' => '11', 'label' => 'Pochodzenie', 'key' => 'pochodzenie', 'class' => 'field-4'],
            ['nr' => '12', 'label' => 'Technika wykonania', 'key' => 'technika_wykonania', 'class' => 'field-4'],
            ['nr' => '13', 'label' => 'Wymiary', 'key' => 'wymiary', 'class' => 'field-4'],
            ['nr' => '14', 'label' => 'Cechy charakterystyczne', 'key' => 'cechy_charakterystyczne', 'class' => 'field-8'],
            ['nr' => '15', 'label' => 'Dane o dokumentacji wizualnej', 'key' => 'dane_o_dokumentacji_wizualnej', 'class' => 'field-4'],
            ['nr' => '16', 'label' => 'Właściciel', 'key' => 'wlasciciel', 'class' => 'field-4'],
            ['nr' => '17', 'label' => 'Sposób oznakowania', 'key' => 'sposob_oznakowania', 'class' => 'field-4'],
            ['nr' => '18', 'label' => 'Autorskie prawa majątkowe', 'key' => 'autorskie_prawa_majatkowe', 'class' => 'field-4'],
            ['nr' => '19', 'label' => 'Kontrola zbiorów', 'key' => 'kontrola_zbiorow', 'class' => 'field-4'],
            ['nr' => '20', 'label' => 'Miejsce przechowywania', 'key' => 'miejsce_przechowywania', 'class' => 'field-4'],
            ['nr' => '21', 'label' => 'Wartość w dniu nabycia', 'key' => 'wartosc_w_dniu_nabycia', 'class' => 'field-4'],
            ['nr' => '22', 'label' => 'Wartość w dniu sporządzenia', 'key' => 'wartosc_w_dniu_sporzadzenia', 'class' => 'field-4'],
            ['nr' => '23', 'label' => 'Uwagi', 'key' => 'uwagi', 'class' => 'field-8'],
            ['nr' => '24', 'label' => 'Data opracowania', 'key' => 'data_opracowania', 'class' => 'field-4'],
            ['nr' => '25', 'label' => 'Opracowujący', 'key' => 'opracowujacy', 'class' => 'field-4'],
        ];
        ?>
        <div class="sheet">
            <div class="sheet-header">
                <div class="sheet-title">
                    <div class="sheet-title-row">
                        <?php if ($museumLogoPath !== null): ?>
                            <div class="title-logo">
                                <img src="<?php echo htmlspecialchars($museumLogoPath); ?>" alt="Logo Muzeum Książki Artystycznej w Łodzi" onerror='if (this.src !== <?php echo json_encode($museumLogoFallbackPath); ?>) this.src = <?php echo json_encode($museumLogoFallbackPath); ?>;'>
                            </div>
                        <?php endif; ?>
                        <div class="sheet-title-text">
                            <h1>Karta ewidencyjna</h1>
                            <p>Nazwa instytucji: Muzeum Książki Artystycznej w Łodzi</p>
                        </div>
                    </div>
                </div>
                <div class="sheet-meta">
                    <div><strong>Kolekcja:</strong> <?php echo htmlspecialchars($collections[$selectedCollection]['label']); ?></div>
                    <div><strong>ID rekordu:</strong> <?php echo (int)$recordId; ?></div>
                    <div><strong>Status:</strong> Publiczny podgląd tylko do odczytu</div>
                </div>
            </div>

            <div class="form-grid">
                <?php foreach ($fieldMap as $field): ?>
                    <div class="field <?php echo $field['class']; ?>">
                        <div class="field-label"><?php echo htmlspecialchars($field['nr'] . '. ' . $field['label']); ?></div>
                        <div class="field-value">
                            <?php echo nl2br(htmlspecialchars((string)($record[$field['key']] ?? ''))); ?>
                        </div>
                        <?php if ($field['key'] === 'dokumentacja_wizualna' && !empty($imagePath)): ?>
                            <div class="visual-wrap">
                                <img src="<?php echo htmlspecialchars($imagePath); ?>" alt="Dokumentacja wizualna"<?php if (!empty($imageFallbackPath)): ?> onerror='if (this.src !== <?php echo json_encode($imageFallbackPath); ?>) this.src = <?php echo json_encode($imageFallbackPath); ?>;'<?php endif; ?>>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="moves-section">
                <h2>26. Przemieszczenia obiektu o nr. ewidencyjnym <?php echo htmlspecialchars((string)($record['numer_ewidencyjny'] ?? '')); ?></h2>
                <table class="moves-table">
                    <thead>
                        <tr>
                            <th>Data przemieszczenia</th>
                            <th>Data zwrotu</th>
                            <th>Numer przemieszczenia</th>
                            <th>Miejsce przemieszczenia</th>
                            <th>Powód/cel przemieszczenia</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($movesRows)): ?>
                            <?php foreach ($movesRows as $move): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)($move['data_przemieszczenia'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($move['data_zwrotu'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($move['numer_przemieszczenia'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($move['miejsce_przemieszczenia'] ?? '')); ?></td>
                                    <td><?php echo nl2br(htmlspecialchars((string)($move['powod_cel_przemieszczenia'] ?? ''))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5">&nbsp;</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <p class="public-note">Dokument wygenerowany z publicznego linku udostępniania.</p>
            </div>
        </div>
    <?php endif; ?>
    <script>
        function exportToPdf() {
            window.print();
        }
    </script>
    <?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
