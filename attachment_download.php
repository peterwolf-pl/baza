<?php
require_once __DIR__ . '/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/museum_system.php';

museumEnsureAttachmentTables($pdo);

$versionId = (int)($_GET['version_id'] ?? 0);
if ($versionId <= 0) {
    http_response_code(400);
    echo 'Nieprawidłowy identyfikator wersji załącznika.';
    exit;
}

$stmt = $pdo->prepare(
    "SELECT v.*, a.collection, a.record_id, a.title
     FROM record_attachment_versions v
     JOIN record_attachments a ON a.id = v.attachment_id
     WHERE v.id = :id
     LIMIT 1"
);
$stmt->execute(['id' => $versionId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo 'Nie znaleziono załącznika.';
    exit;
}

$storedRelPath = ltrim((string)($row['stored_rel_path'] ?? ''), '/');
if ($storedRelPath === '') {
    http_response_code(404);
    echo 'Brak ścieżki pliku.';
    exit;
}

$absPath = __DIR__ . '/' . $storedRelPath;
if (!is_file($absPath)) {
    http_response_code(404);
    echo 'Plik załącznika nie istnieje na dysku.';
    exit;
}

$realFile = realpath($absPath);
$realBase = realpath(museumAttachmentBaseDir());
if ($realFile === false || $realBase === false || strncmp($realFile, $realBase, strlen($realBase)) !== 0) {
    http_response_code(403);
    echo 'Odmowa dostępu do pliku.';
    exit;
}

$mimeType = (string)($row['mime_type'] ?? '');
if ($mimeType === '') {
    $mimeType = museumDetectMimeType($realFile) ?? 'application/octet-stream';
}

$originalFilename = (string)($row['original_filename'] ?? 'zalacznik');
$safeDownloadName = museumSanitizeFilename($originalFilename);
$inlineRequested = isset($_GET['inline']) && $_GET['inline'] === '1';
$ext = strtolower((string)($row['file_ext'] ?? pathinfo($safeDownloadName, PATHINFO_EXTENSION)));
$inlineAllowed = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'], true);
$disposition = ($inlineRequested && $inlineAllowed) ? 'inline' : 'attachment';

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($realFile));
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '_', $safeDownloadName) . '"');
header('X-Content-Type-Options: nosniff');
readfile($realFile);
exit;

