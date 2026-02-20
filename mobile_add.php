<?php
session_start();

include 'db.php';

$collections = [
    'ksiazki-artystyczne' => 'karta_ewidencyjna',
    'kolekcja-maszyn' => 'karta_ewidencyjna_maszyny',
    'kolekcja-matryc' => 'karta_ewidencyjna_matryce',
    'biblioteka' => 'karta_ewidencyjna_bib',
];

$selectedCollection = $_GET['collection'] ?? 'ksiazki-artystyczne';
if (!isset($collections[$selectedCollection])) {
    $selectedCollection = 'ksiazki-artystyczne';
}
$mainTable = $collections[$selectedCollection];

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

function nextNumericValue(PDO $pdo, string $table, string $column): int {
    $stmt = $pdo->query("SELECT COALESCE(MAX(CAST({$column} AS UNSIGNED)), 0) + 1 AS next_val FROM {$table}");
    return (int)$stmt->fetchColumn();
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
        $_SESSION['user_id'] = (int)$tokenData['user_id'];
        $_SESSION['username'] = $tokenData['username'];

        $markUsed = $pdo->prepare('UPDATE mobile_login_tokens SET used = 1 WHERE token = :token');
        $markUsed->execute(['token' => $token]);

        header('Location: mobile_add.php?collection=' . urlencode($tokenData['collection']) . '&mobile=1');
        exit;
    }

    $tokenError = 'Link mobilny jest nieprawidłowy lub wygasł.';
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $photoName = null;
        if (isset($_FILES['mobile_photo']) && $_FILES['mobile_photo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/gfx';
            $thumbDir = $uploadDir . '/thumbs';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }
            if (!is_dir($thumbDir)) {
                mkdir($thumbDir, 0775, true);
            }

            $originalName = basename((string)$_FILES['mobile_photo']['name']);
            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
            $safeName = $safeName ?: ('zdjecie_' . date('Ymd_His') . '.jpg');
            $targetName = uniqid('mobile_', true) . '_' . $safeName;
            $targetPath = $uploadDir . '/' . $targetName;
            $thumbPath = $thumbDir . '/' . $targetName;

            if (move_uploaded_file($_FILES['mobile_photo']['tmp_name'], $targetPath)) {
                $photoName = $targetName;
                createThumbnail($targetPath, $thumbPath, 125, 70);
            }
        }

        $new_data = [];
        foreach ($valid_columns as $column) {
            if ($column === 'numer_ewidencyjny') {
                $new_data[$column] = nextNumericValue($pdo, $mainTable, 'numer_ewidencyjny');
            } elseif ($column === 'data_opracowania') {
                $new_data[$column] = currentProcessingDate($pdo, $mainTable);
            } elseif ($column === 'opracowujacy') {
                $new_data[$column] = $_SESSION['username'] ?? null;
            } elseif ($column === 'nazwa_tytul' || $column === 'autor_wytworca') {
                $new_data[$column] = trim((string)($_POST[$column] ?? '')) ?: null;
            } elseif ($column === 'dokumentacja_wizualna') {
                $new_data[$column] = $photoName;
            } else {
                $new_data[$column] = null;
            }
        }

        $primaryKeyColumn = getPrimaryKeyColumn($pdo, $mainTable);
        if ($primaryKeyColumn !== null && !array_key_exists($primaryKeyColumn, $new_data)) {
            $new_data[$primaryKeyColumn] = nextNumericValue($pdo, $mainTable, $primaryKeyColumn);
        }

        $sql = 'INSERT INTO ' . $mainTable . ' (' . implode(', ', array_keys($new_data)) . ') VALUES ('
            . implode(', ', array_map(fn($key) => ':' . $key, array_keys($new_data))) . ')';

        $insert_stmt = $pdo->prepare($sql);
        $insert_stmt->execute($new_data);

        $newId = isset($primaryKeyColumn, $new_data[$primaryKeyColumn])
            ? (int)$new_data[$primaryKeyColumn]
            : (int)$pdo->lastInsertId();

        header('Location: karta.php?id=' . $newId . '&collection=' . urlencode($selectedCollection) . '&from_mobile_add=1');
        exit;
    } catch (PDOException $e) {
        $formError = 'Błąd dodawania: ' . $e->getMessage();
    }
}
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
    <h1>Mobile Add</h1>

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
        <form method="post" class="mobile-add-form" enctype="multipart/form-data">
            <label for="nazwa_tytul">Tytuł</label>
            <input type="text" name="nazwa_tytul" id="nazwa_tytul" required>

            <label for="autor_wytworca">Autor</label>
            <input type="text" name="autor_wytworca" id="autor_wytworca" required>

            <div class="camera-trigger">
                <button type="button" id="openCamera" aria-label="Otwórz aparat">📷</button>
                <span id="photoName">Nie wybrano zdjęcia</span>
            </div>
            <input type="file" name="mobile_photo" id="mobile_photo" accept="image/*" capture="environment" style="display:none;">

            <input type="submit" value="Zapisz wpis">
        </form>
    <?php endif; ?>
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

        if (openCamera && mobilePhoto) {
            openCamera.addEventListener('click', () => mobilePhoto.click());
            mobilePhoto.addEventListener('change', () => {
                photoName.textContent = mobilePhoto.files.length ? mobilePhoto.files[0].name : 'Nie wybrano zdjęcia';
            });
        }
    </script>
<?php endif; ?>
</body>
</html>
