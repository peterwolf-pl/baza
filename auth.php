<?php

declare(strict_types=1);

function appEnsureCsrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    $token = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || $token === '') {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
    }
    return $token;
}

function appCsrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(appEnsureCsrfToken(), ENT_QUOTES, 'UTF-8')
        . '">';
}

function appVerifyCsrf(?string $token = null): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $expected = $_SESSION['csrf_token'] ?? '';
    $provided = $token ?? (string)($_POST['csrf_token'] ?? '');
    return is_string($expected) && $expected !== '' && hash_equals($expected, $provided);
}

function userIsRoot(): bool
{
    return !empty($_SESSION['is_root']) || (string)($_SESSION['username'] ?? '') === 'root';
}

function userCan(string $capability): bool
{
    if (userIsRoot()) {
        return true;
    }

    switch ($capability) {
        case 'full_view':
            return !empty($_SESSION['can_full_database_view']);
        case 'edit_lists':
            return !empty($_SESSION['can_edit_lists']);
        case 'inventory_entries':
            return !empty($_SESSION['can_inventory_entries']);
        case 'update_records':
            return !empty($_SESSION['can_update_records']);
        case 'manage_deposits':
            return !empty($_SESSION['can_manage_deposits']);
        case 'generate_reports':
            return !empty($_SESSION['can_generate_reports']);
        case 'move_records':
            return !empty($_SESSION['can_manage_deposits']) || !empty($_SESSION['can_update_records']);
        case 'admin':
            return false;
        default:
            return false;
    }
}

function appApplyUserSession(array $user): void
{
    $_SESSION['user_id'] = (int)($user['id'] ?? 0);
    $_SESSION['username'] = (string)($user['username'] ?? '');
    $_SESSION['email'] = (string)($user['email'] ?? '');
    $_SESSION['can_full_database_view'] = (int)($user['can_full_database_view'] ?? 0);
    $_SESSION['can_edit_lists'] = (int)($user['can_edit_lists'] ?? 0);
    $_SESSION['is_root'] = (int)($user['is_root'] ?? 0);
    if ($_SESSION['username'] === 'root') {
        $_SESSION['is_root'] = 1;
    }
    $_SESSION['can_inventory_entries'] = (int)($user['can_inventory_entries'] ?? 0);
    $_SESSION['can_update_records'] = (int)($user['can_update_records'] ?? 0);
    $_SESSION['can_manage_deposits'] = (int)($user['can_manage_deposits'] ?? 0);
    $_SESSION['can_generate_reports'] = (int)($user['can_generate_reports'] ?? 0);
}

function appPreviewAllowedColumns(): array
{
    return ['ID', 'id', 'nazwa_tytul', 'autor_wytworca', 'dokumentacja_wizualna'];
}

function appFilterPreviewRow(array $row): array
{
    $allowed = array_flip(appPreviewAllowedColumns());
    $filtered = array_intersect_key($row, $allowed);
    foreach ($row as $key => $value) {
        if (str_starts_with((string)$key, '__')) {
            $filtered[$key] = $value;
        }
    }
    return $filtered;
}

function appSelectedLedger(): string
{
    $ledger = (string)($GLOBALS['app_selected_ledger'] ?? ($_SESSION['selected_ledger'] ?? 'depozytowa'));
    return $ledger !== '' ? $ledger : 'depozytowa';
}

function appAppendLedger(string $url, ?string $ledger = null): string
{
    $ledger = $ledger ?? appSelectedLedger();
    if ($ledger === '' || preg_match('/(?:^|[?&])ledger=/', $url) === 1) {
        return $url;
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . 'ledger=' . rawurlencode($ledger);
}

function appPublicBaseUrl(): string
{
    $configured = getenv('APP_BASE_URL');
    if (is_string($configured) && $configured !== '') {
        return rtrim($configured, '/');
    }

    $host = (string)($_SERVER['HTTP_HOST'] ?? 'baza.mkal.pl');
    if (preg_match('/^(baza\.mkal\.pl|www\.baza\.mkal\.pl|localhost(?::[0-9]+)?)$/i', $host) !== 1) {
        $host = 'baza.mkal.pl';
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $basePath = rtrim(str_replace('\\', '/', (string)dirname($_SERVER['PHP_SELF'] ?? '')), '/');
    if ($basePath === '/' || $basePath === '\\' || $basePath === '.') {
        $basePath = '';
    }

    return $scheme . '://' . $host . $basePath;
}

function appPublicUrl(string $fileName, array $query = []): string
{
    $url = appPublicBaseUrl() . '/' . ltrim($fileName, '/');
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }
    return $url;
}
