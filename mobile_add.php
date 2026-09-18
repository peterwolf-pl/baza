<?php
session_start();

include 'db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/museum_system.php';

function userCanCreateEntries(): bool {
    return userCan('inventory_entries');
}

$collections = [
    'ksiazki-artystyczne' => ['main' => 'karta_ewidencyjna', 'log' => 'karta_ewidencyjna_log'],
    'kolekcja-maszyn' => ['main' => 'karta_ewidencyjna_maszyny', 'log' => 'karta_ewidencyjna_maszyny_log'],
    'kolekcja-matryc' => ['main' => 'karta_ewidencyjna_matryce', 'log' => 'karta_ewidencyjna_matryce_log'],
    'biblioteka' => ['main' => 'karta_ewidencyjna_bib', 'log' => 'karta_ewidencyjna_bib_log'],
    'kolekcja-klisz' => ['main' => 'karta_ewidencyjna_klisze', 'log' => 'karta_ewidencyjna_klisze_log'],
];

$selectedCollection = $_GET['collection'] ?? 'ksiazki-artystyczne';
if (!isset($collections[$selectedCollection])) {
    $selectedCollection = 'ksiazki-artystyczne';
}
$mainTable = $collections[$selectedCollection]['main'];
$logTable = $collections[$selectedCollection]['log'];

function ensureMobileTokenTable(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mobile_login_tokens (
            token VARCHAR(64) PRIMARY KEY,
            user_id INT NOT NULL,
            username VARCHAR(255) NOT NULL,
            collection VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function getPrimaryKeyColumn(PDO $pdo, string $table): ?string {
    $stmt = $pdo->query("SHOW KEYS FROM {$table} WHERE Key_name = 'PRIMARY'");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['Column_name'])) {
            return $row['Column_name'];
        }
    }

    return null;
}

function currentProcessingDate(PDO $pdo, string $table): string {
    $stmt = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'data_opracowania'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    $type = strtolower((string)($column['Type'] ?? ''));

    if (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) {
        return date('Y-m-d H:i:s');
    }

    return date('Y-m-d');
}

function isMobileDevice(): bool {
    $agent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    return preg_match('/android|iphone|ipad|ipod|mobile|blackberry|windows phone/', $agent) === 1;
}

function createThumbnail(string $sourcePath, string $thumbPath, int $targetHeight = 125, int $quality = 70): bool {
    if (!extension_loaded('gd')) {
        return false;
    }

    $imageInfo = @getimagesize($sourcePath);
    if (!$imageInfo || empty($imageInfo[0]) || empty($imageInfo[1])) {
        return false;
    }

    [$sourceWidth, $sourceHeight, $imageType] = $imageInfo;
    if ($sourceHeight <= 0) {
        return false;
    }

    $targetWidth = max(1, (int)round($sourceWidth * ($targetHeight / $sourceHeight)));

    $createMap = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG => 'imagecreatefrompng',
        IMAGETYPE_GIF => 'imagecreatefromgif',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
    ];

    if (!isset($createMap[$imageType]) || !function_exists($createMap[$imageType])) {
        return false;
    }

    $sourceImage = @$createMap[$imageType]($sourcePath);
    if ($sourceImage === false) {
        return false;
    }

    $thumbImage = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($thumbImage === false) {
        imagedestroy($sourceImage);
        return false;
    }

    if (in_array($imageType, [IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
        imagealphablending($thumbImage, false);
        imagesavealpha($thumbImage, true);
        $transparent = imagecolorallocatealpha($thumbImage, 0, 0, 0, 127);
        imagefilledrectangle($thumbImage, 0, 0, $targetWidth, $targetHeight, $transparent);
    }

    imagecopyresampled($thumbImage, $sourceImage, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

    $result = false;
    switch ($imageType) {
        case IMAGETYPE_JPEG:
            $result = imagejpeg($thumbImage, $thumbPath, $quality);
            break;
        case IMAGETYPE_PNG:
            $pngCompression = (int)round((100 - $quality) * 9 / 100);
            $result = imagepng($thumbImage, $thumbPath, max(0, min(9, $pngCompression)));
            break;
        case IMAGETYPE_GIF:
            $result = imagegif($thumbImage, $thumbPath);
            break;
        case IMAGETYPE_WEBP:
            $result = imagewebp($thumbImage, $thumbPath, $quality);
            break;
    }

    imagedestroy($thumbImage);
    imagedestroy($sourceImage);

    return $result;
}

function normalizeUploadedPhotos(array $fileField): array {
    $photos = [];
    if (!isset($fileField['error'])) {
        return $photos;
    }

    if (is_array($fileField['error'])) {
        $count = count($fileField['error']);
        for ($i = 0; $i < $count; $i++) {
            $photos[] = [
                'name' => (string)($fileField['name'][$i] ?? ''),
                'tmp_name' => (string)($fileField['tmp_name'][$i] ?? ''),
                'error' => (int)$fileField['error'][$i],
            ];
        }
    } else {
        $photos[] = [
            'name' => (string)($fileField['name'] ?? ''),
            'tmp_name' => (string)($fileField['tmp_name'] ?? ''),
            'error' => (int)$fileField['error'],
        ];
    }

    return array_values(array_filter(
        $photos,
        static fn(array $photo): bool => $photo['error'] === UPLOAD_ERR_OK && is_uploaded_file($photo['tmp_name'])
    ));
}

function storeMobilePhoto(array $photo): ?string {
    $uploadDir = __DIR__ . '/gfx';
    $thumbDir = $uploadDir . '/thumbs';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }
    if (!is_dir($thumbDir)) {
        mkdir($thumbDir, 0775, true);
    }

    $originalName = basename($photo['name']);
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
    $safeName = $safeName ?: ('zdjecie_' . date('Ymd_His') . '.jpg');
    $targetName = uniqid('mobile_', true) . '_' . $safeName;
    $targetPath = $uploadDir . '/' . $targetName;
    $thumbPath = $thumbDir . '/' . $targetName;

    if (!move_uploaded_file($photo['tmp_name'], $targetPath)) {
        return null;
    }

    createThumbnail($targetPath, $thumbPath, 125, 70);
    return $targetName;
}

$valid_columns = [
    'numer_ewidencyjny', 'nazwa_tytul', 'czas_powstania', 'inne_numery_ewidencyjne',
    'autor_wytworca', 'miejsce_powstania', 'liczba', 'material',
    'dokumentacja_wizualna', 'dzial', 'pochodzenie', 'technika_wykonania',
    'wymiary', 'cechy_charakterystyczne', 'dane_o_dokumentacji_wizualnej',
    'wlasciciel', 'sposob_oznakowania', 'autorskie_prawa_majatkowe',
    'kontrola_zbiorow', 'wartosc_w_dniu_nabycia', 'wartosc_w_dniu_sporzadzenia',
    'miejsce_przechowywania', 'uwagi', 'data_opracowania', 'opracowujacy'
];

ensureMobileTokenTable($pdo);

if (!isset($_SESSION['user_id']) && isset($_GET['token'])) {
    $token = (string)$_GET['token'];

    $tokenStmt = $pdo->prepare(
        'SELECT token, user_id, username, collection
         FROM mobile_login_tokens
         WHERE token = :token AND used = 0 AND expires_at >= NOW()'
    );
    $tokenStmt->execute(['token' => $token]);
    $tokenData = $tokenStmt->fetch(PDO::FETCH_ASSOC);

    if ($tokenData) {
        $permStmt = $pdo->prepare('SELECT * FROM karta_ewidencyjna_users WHERE id = :id LIMIT 1');
        $permStmt->execute(['id' => (int)$tokenData['user_id']]);
        $permUser = $permStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $permUser['id'] = (int)$tokenData['user_id'];
        $permUser['username'] = (string)($permUser['username'] ?? $tokenData['username']);
        session_regenerate_id(true);
        appApplyUserSession($permUser);

        $markUsed = $pdo->prepare('UPDATE mobile_login_tokens SET used = 1 WHERE token = :token');
        $markUsed->execute(['token' => $token]);

        header('Location: mobile_add.php?collection=' . urlencode($tokenData['collection']) . '&ledger=' . urlencode(appSelectedLedger()) . '&mobile=1');
        exit;
    }

    $tokenError = 'Link mobilny jest nieprawidłowy lub wygasł.';
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (isset($_GET['clear_series'])) {
    unset($_SESSION['mobile_add_series']);
    header('Location: mobile_add.php?collection=' . urlencode($selectedCollection) . '&ledger=' . urlencode(appSelectedLedger()) . '&mobile=1');
    exit;
}

$isMobile = isMobileDevice() || isset($_GET['mobile']);
$qrUrl = null;

if (!$isMobile && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $token = bin2hex(random_bytes(24));

    $insertToken = $pdo->prepare(
        'INSERT INTO mobile_login_tokens (token, user_id, username, collection, expires_at)
         VALUES (:token, :user_id, :username, :collection, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
    );
    $insertToken->execute([
        'token' => $token,
        'user_id' => (int)$_SESSION['user_id'],
        'username' => (string)($_SESSION['username'] ?? ''),
        'collection' => $selectedCollection,
    ]);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    $qrUrl = $scheme . '://' . $host . $basePath . '/mobile_add.php?collection=' . urlencode($selectedCollection) . '&token=' . urlencode($token);
}

$formError = null;
$justSaved = isset($_GET['saved']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!userCanCreateEntries()) {
        http_response_code(403);
        $formError = 'Brak uprawnień do tworzenia wpisów do księgi inwentarzowej.';
    } else {
        $multipleMode = isset($_POST['multiple_mode']);
        $title = trim((string)($_POST['nazwa_tytul'] ?? ''));
        $author = trim((string)($_POST['autor_wytworca'] ?? ''));
        $photos = isset($_FILES['mobile_photo']) ? normalizeUploadedPhotos($_FILES['mobile_photo']) : [];

        if ($title === '' || $author === '') {
            $formError = 'Podaj tytuł i autora.';
        } elseif ($multipleMode && $photos === []) {
            $formError = 'W trybie wielokrotnym każde zdjęcie tworzy nowy wpis — zrób lub wybierz zdjęcie.';
        } else {
            if ($photos === []) {
                $photos = [null];
            }

            try {
                museumEnsureUniqueInventoryNumberConstraint($pdo, $mainTable);
                $created = [];

                foreach ($photos as $photo) {
                    $photoName = $photo ? storeMobilePhoto($photo) : null;

                    $new_data = [];
                    foreach ($valid_columns as $column) {
                        if ($column === 'numer_ewidencyjny') {
                            $new_data[$column] = museumNextInventoryNumberFromTable($pdo, $mainTable, $selectedCollection);
                        } elseif ($column === 'data_opracowania') {
                            $new_data[$column] = currentProcessingDate($pdo, $mainTable);
                        } elseif ($column === 'opracowujacy') {
                            $new_data[$column] = $_SESSION['username'] ?? null;
                        } elseif ($column === 'nazwa_tytul') {
                            $new_data[$column] = $title;
                        } elseif ($column === 'autor_wytworca') {
                            $new_data[$column] = $author;
                        } elseif ($column === 'dokumentacja_wizualna') {
                            $new_data[$column] = $photoName;
                        } else {
                            $new_data[$column] = null;
                        }
                    }

                    $sql = 'INSERT INTO ' . $mainTable . ' (' . implode(', ', array_keys($new_data)) . ') VALUES ('
                        . implode(', ', array_map(fn($key) => ':' . $key, array_keys($new_data))) . ')';

                    $insert_stmt = $pdo->prepare($sql);
                    $insert_stmt->execute($new_data);

                    $newId = (int)$pdo->lastInsertId();

                    $logStmt = $pdo->prepare("INSERT INTO {$logTable}
                        (karta_id, user_username, changed_field, old_value, new_value, change_date)
                        VALUES (:karta_id, :user_username, :changed_field, :old_value, :new_value, NOW())");
                    $logStmt->execute([
                        'karta_id' => $newId,
                        'user_username' => $_SESSION['username'] ?? null,
                        'changed_field' => 'Utworzenie wpisu',
                        'old_value' => null,
                        'new_value' => $multipleMode ? 'Utworzenie wpisu (seria zdjęć)' : 'Utworzenie wpisu',
                    ]);

                    $created[] = [
                        'id' => $newId,
                        'numer' => $new_data['numer_ewidencyjny'],
                    ];
                }

                if ($multipleMode) {
                    $series = $_SESSION['mobile_add_series'] ?? [
                        'collection' => $selectedCollection,
                        'nazwa_tytul' => $title,
                        'autor_wytworca' => $author,
                        'entries' => [],
                    ];
                    $series['collection'] = $selectedCollection;
                    $series['nazwa_tytul'] = $title;
                    $series['autor_wytworca'] = $author;
                    $series['entries'] = array_merge($series['entries'], $created);
                    $_SESSION['mobile_add_series'] = $series;

                    header('Location: mobile_add.php?collection=' . urlencode($selectedCollection) . '&ledger=' . urlencode(appSelectedLedger()) . '&mobile=1&saved=1');
                    exit;
                }

                $last = $created[count($created) - 1];
                header('Location: karta.php?id=' . (int)$last['id'] . '&collection=' . urlencode($selectedCollection) . '&ledger=' . urlencode(appSelectedLedger()) . '&from_mobile_add=1');
                exit;
            } catch (PDOException $e) {
                if (($e->getCode() ?? '') === '23000' && museumIsInventoryNumberConstraintViolation($e)) {
                    $attemptedInventoryNumber = isset($new_data['numer_ewidencyjny']) ? (string)$new_data['numer_ewidencyjny'] : 'XX';
                    $formError = 'Błąd dodawania: numer inwentarzowy ' . $attemptedInventoryNumber . ' już istnieje (wymuszona unikalność).';
                    try {
                        $suggestedNumber = museumSuggestedNextInventoryNumberAfterDuplicate($pdo, $mainTable, $selectedCollection, $attemptedInventoryNumber);
                        if ($suggestedNumber > 0) {
                            $formError .= ' Proponowany kolejny numer: ' . $suggestedNumber . '.';
                        }
                    } catch (Throwable $ignored) {
                    }
                } elseif (($e->getCode() ?? '') === '23000') {
                    $formError = 'Błąd dodawania (naruszenie ograniczenia danych, nie dotyczy numer_ewidencyjny): ' . $e->getMessage();
                } else {
                    $formError = 'Błąd dodawania: ' . $e->getMessage();
                }
            } catch (RuntimeException $e) {
                $formError = 'Błąd dodawania: ' . $e->getMessage();
            }
        }
    }
}

$series = $_SESSION['mobile_add_series'] ?? null;
if (is_array($series) && (($series['collection'] ?? '') !== $selectedCollection)) {
    $series = null;
}
$multipleChecked = $justSaved || is_array($series);
$prefillTitle = is_array($series) ? (string)($series['nazwa_tytul'] ?? '') : '';
$prefillAuthor = is_array($series) ? (string)($series['autor_wytworca'] ?? '') : '';
$seriesEntries = is_array($series) ? ($series['entries'] ?? []) : [];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mobile Add</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .mobile-add-wrapper { max-width: 520px; margin: 20px auto; }
        .mobile-add-form label { display:block; margin-top: 12px; }
        .mobile-add-form input[type="text"] { width: 100%; padding: 10px; font-size: 16px; }
        .camera-trigger { margin-top: 12px; display: inline-flex; align-items: center; gap: 8px; }
        .camera-trigger button { font-size: 24px; line-height: 1; padding: 8px 12px; cursor: pointer; }
        .qr-panel { text-align:center; background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px; margin-top:20px; }
        #qrcode { display:flex; justify-content:center; margin: 16px 0; }
        .error { color:#b10000; margin: 10px 0; }
        .ok { color:#0a7a0a; margin: 10px 0; }
        .multi-toggle { display:flex; align-items:flex-start; gap:8px; margin: 16px 0; font-weight: bold; }
        .multi-toggle input { margin-top: 3px; }
        .series-box { background:#f6f6f6; border:1px solid #ddd; border-radius:8px; padding:12px; margin: 12px 0; }
        .series-box a { display:inline-block; margin-right:8px; }
    </style>
</head>
<body>
<div class="header">
    <a href="https://baza.mkal.pl">
        <img src="bazamka.png" width="400" alt="Logo bazy" class="logo">
    </a>
</div>

<div class="mobile-add-wrapper">
    <a role="button" id="toggleButton" href="index.php?collection=<?php echo urlencode($selectedCollection); ?>">Powrót do listy</a>
    <h1>Fast Mobile Adder</h1>

    <?php if (!empty($tokenError)): ?>
        <p class="error"><?php echo htmlspecialchars($tokenError); ?></p>
    <?php endif; ?>

    <?php if (!$isMobile && $qrUrl): ?>
        <div class="qr-panel">
            <p>Zeskanuj kod QR telefonem, aby jednorazowo otworzyć zalogowaną sesję w formularzu <strong>mobile add</strong>.</p>
            <div id="qrcode"></div>
            <p><small>Link jest ważny 10 minut i działa tylko raz.</small></p>
            <p><a href="<?php echo htmlspecialchars($qrUrl); ?>"><?php echo htmlspecialchars($qrUrl); ?></a></p>
        </div>
    <?php else: ?>
        <?php if (!empty($formError)): ?>
            <p class="error"><?php echo htmlspecialchars($formError); ?></p>
        <?php endif; ?>
        <?php if ($justSaved && $seriesEntries): ?>
            <?php $last = $seriesEntries[count($seriesEntries) - 1]; ?>
            <p class="ok">Zapisano wpis nr <?php echo htmlspecialchars((string)$last['numer']); ?>. Tytuł i autor zostają — zrób kolejne zdjęcie.</p>
        <?php endif; ?>
        <?php if ($seriesEntries): ?>
            <div class="series-box">
                <strong>Seria (<?php echo count($seriesEntries); ?>):</strong>
                <?php foreach ($seriesEntries as $entry): ?>
                    <a href="karta.php?id=<?php echo (int)$entry['id']; ?>&collection=<?php echo urlencode($selectedCollection); ?>">
                        #<?php echo htmlspecialchars((string)$entry['numer']); ?>
                    </a>
                <?php endforeach; ?>
                <p>
                    <a href="mobile_add.php?collection=<?php echo urlencode($selectedCollection); ?>&mobile=1&clear_series=1">Zakończ serię</a>
                </p>
            </div>
        <?php endif; ?>
        <?php if (!userCanCreateEntries()): ?>
            <p class="error">Nie masz uprawnień do tworzenia nowych wpisów.</p>
        <?php else: ?>
        <form method="post" class="mobile-add-form" enctype="multipart/form-data">
            <label class="multi-toggle">
                <input type="checkbox" name="multiple_mode" id="multiple_mode" value="1" <?php echo $multipleChecked ? 'checked' : ''; ?>>
                <span>Tryb wielokrotny — ten sam tytuł i autor, każde zdjęcie = nowy wpis</span>
            </label>

            <label for="nazwa_tytul">Tytuł</label>
            <input type="text" name="nazwa_tytul" id="nazwa_tytul" required value="<?php echo htmlspecialchars($prefillTitle); ?>">

            <label for="autor_wytworca">Autor</label>
            <input type="text" name="autor_wytworca" id="autor_wytworca" required value="<?php echo htmlspecialchars($prefillAuthor); ?>">

            <div class="camera-trigger">
                <button type="button" id="openCamera" aria-label="Otwórz aparat">📷</button>
                <span id="photoName"><- Kliknij ikone aparatu i zrób zdjęcie</span>
            </div>
            <input type="file" name="mobile_photo[]" id="mobile_photo" accept="image/*" capture="environment" style="display:none;">
<br><br>
            <input type="submit" id="submitBtn" value="Zapisz wpis">
        </form>
        <?php endif; ?>

    <?php endif; ?>
    <br><br><br>
    <p>Po zapisie skrypt bazy generuje miniaturę. <br> Przy ograniczonych zasobach, może to powodować mulenie...<br>
    Jeśli doświadcasz tego problemu, na jutro przygotuj pełne wiaderko internetu. </p>
</div>

<?php if (!$isMobile && $qrUrl): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        new QRCode(document.getElementById('qrcode'), {
            text: <?php echo json_encode($qrUrl); ?>,
            width: 220,
            height: 220
        });
    </script>
<?php else: ?>
    <script>
        const openCamera = document.getElementById('openCamera');
        const mobilePhoto = document.getElementById('mobile_photo');
        const photoName = document.getElementById('photoName');
        const multipleMode = document.getElementById('multiple_mode');
        const form = document.querySelector('.mobile-add-form');
        const submitBtn = document.getElementById('submitBtn');

        function applyMultipleUi() {
            if (!mobilePhoto || !multipleMode || !submitBtn) return;
            if (multipleMode.checked) {
                mobilePhoto.setAttribute('multiple', 'multiple');
                submitBtn.value = 'Zapisz i zrób kolejne';
                if (photoName && !mobilePhoto.files.length) {
                    photoName.textContent = 'Zrób zdjęcie — zapisze się od razu, tytuł i autor zostaną';
                }
            } else {
                mobilePhoto.removeAttribute('multiple');
                submitBtn.value = 'Zapisz wpis';
                if (photoName && !mobilePhoto.files.length) {
                    photoName.textContent = '<- Kliknij ikone aparatu i zrób zdjęcie';
                }
            }
        }

        if (multipleMode) {
            multipleMode.addEventListener('change', applyMultipleUi);
            applyMultipleUi();
        }

        if (openCamera && mobilePhoto) {
            openCamera.addEventListener('click', () => mobilePhoto.click());
            mobilePhoto.addEventListener('change', () => {
                const n = mobilePhoto.files ? mobilePhoto.files.length : 0;
                if (!n) {
                    photoName.textContent = 'Nie wybrano zdjęcia';
                    return;
                }
                photoName.textContent = n === 1 ? mobilePhoto.files[0].name : (n + ' zdjęć');
                if (multipleMode && multipleMode.checked && form) {
                    form.submit();
                }
            });
        }
    </script>
<?php endif; ?>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
