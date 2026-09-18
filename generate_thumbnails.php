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
$autoContinue = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    appRequireCsrf();
    if (!userCanGenerateThumbnails()) {
        $errors[] = 'Brak uprawnień do generowania miniatur.';
    } elseif (!$gdAvailable) {
        $errors[] = 'Rozszerzenie PHP GD nie jest dostępne na serwerze.';
    } else {
        @set_time_limit(90);
        $batchSize = max(1, min(50, (int)($_POST['batch_size'] ?? 20)));
        $missingBefore = museumListMissingThumbnails();
        $slice = array_slice($missingBefore, 0, $batchSize);
        if ($slice === []) {
            $messages[] = 'Nie znaleziono brakujących miniatur.';
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
            $messages[] = 'Ta partia: wygenerowano ' . $generatedOk
                . ', błędów ' . $generatedFail
                . ' (z ' . count($slice) . ' plików).';
        }
        $autoContinue = $generatedFail === 0
            && count(museumListMissingThumbnails()) > 0
            && isset($_POST['auto_continue']);
    }
}

$sourceImages = [];
$missing = [];
try {
    $sourceImages = museumListGfxSourceImages();
    $missing = museumListMissingThumbnails();
} catch (Throwable $e) {
    appLogException('generate_thumbnails.php scan', $e);
    $errors[] = 'Nie udało się przeskanować katalogu gfx/.';
}

$sourceCount = count($sourceImages);
$missingCount = count($missing);
$existingCount = max(0, $sourceCount - $missingCount);
$previewMissing = array_slice($missing, 0, 25);
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
                Zaznacz automatyczną kontynuację, jeśli brakuje wielu miniatur.
            </p>
            <form method="post" action="<?php echo $esc($pageHref); ?>" id="thumbsGenerateForm">
                <?= appCsrfField() ?>
                <input type="hidden" name="collection" value="<?php echo $esc($selectedCollection); ?>">
                <div class="thumbs-actions">
                    <label for="batch_size">Partia</label>
                    <input type="number" id="batch_size" name="batch_size" min="1" max="50" value="<?php echo (int)$batchSize; ?>">
                    <label>
                        <input type="checkbox" name="auto_continue" value="1" <?php echo $autoContinue ? 'checked' : ''; ?>>
                        Kontynuuj automatycznie
                    </label>
                    <button type="submit" id="toggleButton" <?php echo ($gdAvailable && $missingCount > 0) ? '' : 'disabled'; ?>>
                        Generuj brakujące miniatury
                    </button>
                </div>
            </form>
            <?php if ($missingCount === 0 && $sourceCount > 0): ?>
                <p class="thumbs-ok">Wszystkie znalezione zdjęcia mają miniatury.</p>
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

        <?php if ($previewMissing !== []): ?>
            <div class="thumbs-card">
                <h2>Przykłady brakujących (<?php echo (int)$missingCount; ?>)</h2>
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
