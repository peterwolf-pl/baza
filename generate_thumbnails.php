<?php
require_once __DIR__ . '/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

include 'db.php';
require_once __DIR__ . '/museum_system.php';
require_once __DIR__ . '/header.php';

function userCanGenerateThumbnails(): bool
{
    return userIsRoot() || userCan('inventory_entries');
}

$collections = [
    'ksiazki-artystyczne' => ['label' => 'Książki Artystyczne'],
    'kolekcja-maszyn' => ['label' => 'Maszyny'],
    'kolekcja-matryc' => ['label' => 'Matryce'],
    'biblioteka' => ['label' => 'Biblioteka'],
    'kolekcja-klisz' => ['label' => 'Klisze drukarskie'],
];

$selectedCollection = (string)($_GET['collection'] ?? ($_POST['collection'] ?? 'ksiazki-artystyczne'));
if (!isset($collections[$selectedCollection])) {
    $selectedCollection = 'ksiazki-artystyczne';
}
$selectedLedger = appSelectedLedger();
$pageHref = 'generate_thumbnails.php?collection=' . rawurlencode($selectedCollection) . '&ledger=' . rawurlencode($selectedLedger);

$gdAvailable = extension_loaded('gd');
$batchSize = 20;
$messages = [];
$errors = [];
$batchResults = [];
$generatedOk = 0;
$generatedFail = 0;
$fetchedOk = 0;
$fetchedFail = 0;
$autoContinue = false;
$fetchMissing = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    appRequireCsrf();
    $fetchMissing = isset($_POST['fetch_missing']);
    if (!userCanGenerateThumbnails()) {
        $errors[] = 'Brak uprawnień do generowania miniatur.';
    } elseif (!$gdAvailable) {
        $errors[] = 'Rozszerzenie PHP GD nie jest dostępne na serwerze.';
    } else {
        @set_time_limit(120);
        $batchSize = max(1, min(50, (int)($_POST['batch_size'] ?? 20)));

        if ($fetchMissing) {
            $remoteMissing = museumListMissingLocalOriginals($pdo);
            $fetchSlice = array_slice($remoteMissing, 0, min($batchSize, 15));
            foreach ($fetchSlice as $item) {
                $result = museumFetchAndStoreRemoteOriginal(
                    (string)($item['relative'] ?? ''),
                    (string)($item['collection'] ?? $selectedCollection)
                );
                $batchResults[] = $result;
                if (!empty($result['ok'])) {
                    $fetchedOk++;
                } else {
                    $fetchedFail++;
                    appLogException(
                        'generate_thumbnails.php fetch ' . (string)($result['file'] ?? ''),
                        new RuntimeException((string)($result['message'] ?? 'błąd'))
                    );
                }
            }
            if ($fetchSlice === []) {
                $messages[] = 'Nie znaleziono w bazie zdjęć, których brakuje w gfx/.';
            } else {
                $messages[] = 'Pobieranie: OK ' . $fetchedOk . ', błędów ' . $fetchedFail
                    . ' (z ' . count($fetchSlice) . ').';
            }
        }

        $missingBefore = museumListMissingThumbnails();
        $slice = array_slice($missingBefore, 0, $batchSize);
        if ($slice === []) {
            if (!$fetchMissing) {
                $messages[] = 'Nie znaleziono brakujących miniatur.';
            }
        } else {
            foreach ($slice as $item) {
                $result = museumGenerateThumbnailForImage($item);
                $batchResults[] = $result;
                if (!empty($result['ok'])) {
                    $generatedOk++;
                } else {
                    $generatedFail++;
                    appLogException(
                        'generate_thumbnails.php ' . (string)($result['file'] ?? ''),
                        new RuntimeException((string)($result['message'] ?? 'błąd'))
                    );
                }
            }
            $messages[] = 'Miniatury: wygenerowano ' . $generatedOk
                . ', błędów ' . $generatedFail
                . ' (z ' . count($slice) . ' plików).';
        }

        $stillMissingThumbs = count(museumListMissingThumbnails()) > 0;
        $stillMissingRemote = $fetchMissing && count(museumListMissingLocalOriginals($pdo)) > 0;
        $autoContinue = ($generatedFail + $fetchedFail) === 0
            && ($stillMissingThumbs || $stillMissingRemote)
            && isset($_POST['auto_continue']);
    }
}

$sourceImages = [];
$missing = [];
$missingRemote = [];
try {
    $sourceImages = museumListGfxSourceImages();
    $missing = museumListMissingThumbnails();
    $missingRemote = museumListMissingLocalOriginals($pdo);
} catch (Throwable $e) {
    appLogException('generate_thumbnails.php scan', $e);
    $errors[] = 'Nie udało się przeskanować katalogu gfx/ albo bazy.';
}

$sourceCount = count($sourceImages);
$missingCount = count($missing);
$missingRemoteCount = count($missingRemote);
$existingCount = max(0, $sourceCount - $missingCount);
$previewMissing = array_slice($missing, 0, 25);
$previewRemote = array_slice($missingRemote, 0, 25);
$canRun = $gdAvailable && ($missingCount > 0 || $missingRemoteCount > 0);
$esc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generowanie miniatur | baza.mkal.pl</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .thumbs-page { max-width: 860px; margin: 20px auto 80px; padding: 0 16px; }
        .thumbs-card {
            border: 1px solid var(--color-border);
            border-radius: 10px;
            background: var(--color-bg);
            padding: 20px;
            margin-bottom: 16px;
        }
        .thumbs-card h2 { margin-top: 0; }
        .thumbs-stats { display: flex; flex-wrap: wrap; gap: 12px; margin: 12px 0 0; }
        .thumbs-stat {
            min-width: 140px;
            padding: 12px 14px;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            background: var(--color-surface);
        }
        .thumbs-stat strong { display: block; font-size: 22px; }
        .thumbs-muted { color: var(--color-muted); }
        .thumbs-ok { color: var(--color-success); }
        .thumbs-error { color: var(--color-error); }
        .thumbs-list { margin: 0; padding-left: 18px; max-height: 240px; overflow: auto; }
        .thumbs-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 16px; }
        .thumbs-actions button { min-height: 40px; }
        .thumbs-actions label { display: inline-flex; align-items: center; gap: 6px; }
        .thumbs-actions input[type="number"] { width: 72px; }
    </style>
</head>
<body>
<?php
renderAppHeader([
    'selectedCollection' => $selectedCollection,
    'collections' => $collections,
    'lists' => [],
    'username' => (string)($_SESSION['username'] ?? ''),
    'logoHref' => 'index.php?collection=' . rawurlencode($selectedCollection),
    'showColumnButton' => false,
    'showListEditor' => false,
    'primaryActions' => [
        ['label' => 'Powrót do strony głównej', 'href' => 'index.php?collection=' . rawurlencode($selectedCollection)],
    ],
]);
?>
<main class="thumbs-page">
    <div class="thumbs-card">
        <h2>Generowanie brakujących miniatur</h2>
        <p>
            Miniatury są zapisywane w <code>gfx/thumbs/</code> obok oryginalnych zdjęć z <code>gfx/</code>.
            Lista i wyszukiwarka najpierw ładują miniaturę, a gdy jej nie ma — pełne zdjęcie.
            Opcjonalnie można ściągnąć brakujące oryginały z <code>mkalodz.pl/bazagfx</code> albo z bazy i lekko je skompresować (max. bok 1920 px, JPEG ~72).
        </p>
        <?php if (!$gdAvailable): ?>
            <p class="thumbs-error">Brak rozszerzenia GD — generowanie miniatur jest niemożliwe.</p>
        <?php endif; ?>
        <?php foreach ($errors as $error): ?>
            <p class="thumbs-error"><?php echo $esc($error); ?></p>
        <?php endforeach; ?>
        <?php foreach ($messages as $message): ?>
            <p class="thumbs-ok"><?php echo $esc($message); ?></p>
        <?php endforeach; ?>

        <div class="thumbs-stats">
            <div class="thumbs-stat">
                <span class="thumbs-muted">Zdjęcia źródłowe</span>
                <strong><?php echo (int)$sourceCount; ?></strong>
            </div>
            <div class="thumbs-stat">
                <span class="thumbs-muted">Mają miniaturę</span>
                <strong><?php echo (int)$existingCount; ?></strong>
            </div>
            <div class="thumbs-stat">
                <span class="thumbs-muted">Brakuje miniatury</span>
                <strong><?php echo (int)$missingCount; ?></strong>
            </div>
            <div class="thumbs-stat">
                <span class="thumbs-muted">Brak w gfx/ (są w bazie)</span>
                <strong><?php echo (int)$missingRemoteCount; ?></strong>
            </div>
        </div>
    </div>

    <?php if (!userCanGenerateThumbnails()): ?>
        <div class="thumbs-card">
            <p class="thumbs-error">Ta funkcja jest dostępna dla administratora oraz osób z uprawnieniem do wpisów inwentarzowych.</p>
        </div>
    <?php else: ?>
        <div class="thumbs-card">
            <h2>Uruchom generowanie</h2>
            <p class="thumbs-muted">
                Partie po kilkanaście plików, żeby nie przekroczyć limitu czasu PHP.
                Zaznacz automatyczną kontynuację, jeśli brakuje wielu plików.
            </p>
            <form method="post" action="<?php echo $esc($pageHref); ?>" id="thumbsGenerateForm">
                <?= appCsrfField() ?>
                <input type="hidden" name="collection" value="<?php echo $esc($selectedCollection); ?>">
                <div class="thumbs-actions">
                    <label for="batch_size">Partia</label>
                    <input type="number" id="batch_size" name="batch_size" min="1" max="50" value="<?php echo (int)$batchSize; ?>">
                    <label>
                        <input type="checkbox" name="fetch_missing" value="1" <?php echo $fetchMissing ? 'checked' : ''; ?>>
                        Pobierz brakujące z mkalodz.pl / bazy i skompresuj do gfx/
                    </label>
                    <label>
                        <input type="checkbox" name="auto_continue" value="1" <?php echo $autoContinue ? 'checked' : ''; ?>>
                        Kontynuuj automatycznie
                    </label>
                    <button type="submit" id="toggleButton" <?php echo $canRun ? '' : 'disabled'; ?>>
                        Generuj brakujące miniatury
                    </button>
                </div>
            </form>
            <?php if ($missingCount === 0 && $missingRemoteCount === 0 && $sourceCount > 0): ?>
                <p class="thumbs-ok">Lokalne zdjęcia mają miniatury, a w gfx/ nie brakuje plików z bazy.</p>
            <?php endif; ?>
        </div>

        <?php if ($batchResults !== []): ?>
            <div class="thumbs-card">
                <h2>Ostatnia partia</h2>
                <ul class="thumbs-list">
                    <?php foreach ($batchResults as $row): ?>
                        <li class="<?php echo !empty($row['ok']) ? 'thumbs-ok' : 'thumbs-error'; ?>">
                            <?php echo $esc($row['file'] ?? ''); ?>
                            — <?php echo $esc($row['message'] ?? ''); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($previewRemote !== []): ?>
            <div class="thumbs-card">
                <h2>Brak w lokalnym gfx/ (<?php echo (int)$missingRemoteCount; ?>)</h2>
                <ul class="thumbs-list">
                    <?php foreach ($previewRemote as $item): ?>
                        <li><?php echo $esc($item['relative'] ?? ''); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($missingRemoteCount > count($previewRemote)): ?>
                    <p class="thumbs-muted">…i kolejne <?php echo (int)($missingRemoteCount - count($previewRemote)); ?> plików.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($previewMissing !== []): ?>
            <div class="thumbs-card">
                <h2>Przykłady brakujących miniatur (<?php echo (int)$missingCount; ?>)</h2>
                <ul class="thumbs-list">
                    <?php foreach ($previewMissing as $item): ?>
                        <li><?php echo $esc($item['relative'] ?? ''); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($missingCount > count($previewMissing)): ?>
                    <p class="thumbs-muted">…i kolejne <?php echo (int)($missingCount - count($previewMissing)); ?> plików.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php if ($autoContinue): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('thumbsGenerateForm');
    if (form) {
        window.setTimeout(function () { form.submit(); }, 700);
    }
});
</script>
<?php endif; ?>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
