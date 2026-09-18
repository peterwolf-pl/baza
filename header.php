<?php

require_once __DIR__ . '/app_settings.php';
require_once __DIR__ . '/auth.php';

if (!function_exists('renderAppHeader')) {
    function renderAppHeader(array $config = []): void
    {
        $organizationProfile = appSettingsGetOrganizationProfile();
        $organizationName = $organizationProfile['name'] !== '' ? $organizationProfile['name'] : 'Baza';
        $selectedCollection = (string)($config['selectedCollection'] ?? 'ksiazki-artystyczne');
        $collections = is_array($config['collections'] ?? null) ? $config['collections'] : [];
        $lists = is_array($config['lists'] ?? null) ? $config['lists'] : [];
        $username = (string)($config['username'] ?? ($_SESSION['username'] ?? ''));
        $showColumnButton = (bool)($config['showColumnButton'] ?? false);
        $showListEditor = (bool)($config['showListEditor'] ?? true);
        $listsExtraHtml = (string)($config['listsExtraHtml'] ?? '');
        $hasCustomScannerHtml = array_key_exists('scannerHtml', $config);
        $scannerHtml = $hasCustomScannerHtml ? (string)($config['scannerHtml'] ?? '') : '';
        $logoHref = (string)($config['logoHref'] ?? 'index.php');
        $logoutHref = (string)($config['logoutHref'] ?? 'logout.php');
        $primaryActions = is_array($config['primaryActions'] ?? null) ? $config['primaryActions'] : [];
        $showBulkBar = (bool)($config['showBulkBar'] ?? false);
        $canEditLists = userCan('edit_lists');
        $canAccessAdmin = userIsRoot();
        $canImportInventory = userCan('inventory_entries');
        $canUseVanna = userCan('full_view');
        $organizacyjneBaseHref = 'organizacyjne.php?collection=' . rawurlencode($selectedCollection);
        $organizacyjneActionsBaseHref = 'organizacyjne_actions.php?collection=' . rawurlencode($selectedCollection);
        $availableLedgers = is_array($config['ledgers'] ?? null) ? $config['ledgers'] : [];
        if ($availableLedgers === [] && is_array($GLOBALS['app_ledger_definitions'] ?? null)) {
            $availableLedgers = $GLOBALS['app_ledger_definitions'];
        }
        if ($availableLedgers === []) {
            $availableLedgers = [
                'inwentarzowa' => ['label' => 'Inwentarzowa'],
                'depozytowa' => ['label' => 'Depozytowa'],
                'nabytki_ubytki' => ['label' => 'Nabytków i ubytków'],
            ];
        }

        $selectedLedger = (string)($config['selectedLedger'] ?? ($GLOBALS['app_selected_ledger'] ?? ($_SESSION['selected_ledger'] ?? 'depozytowa')));
        if (!isset($availableLedgers[$selectedLedger])) {
            $selectedLedger = (string)array_key_first($availableLedgers);
        }

        $appendQueryParam = static function (string $url, string $key, string $value): string {
            if ($value === '') {
                return $url;
            }

            $parts = parse_url($url);
            if ($parts === false) {
                return $url;
            }

            $path = (string)($parts['path'] ?? $url);
            $queryParams = [];
            if (isset($parts['query'])) {
                parse_str((string)$parts['query'], $queryParams);
            }
            $queryParams[$key] = $value;
            $query = http_build_query($queryParams);

            $rebuilt = $path;
            if ($query !== '') {
                $rebuilt .= '?' . $query;
            }
            if (isset($parts['fragment']) && (string)$parts['fragment'] !== '') {
                $rebuilt .= '#' . (string)$parts['fragment'];
            }

            return $rebuilt;
        };

        $organizacyjneBaseHref = $appendQueryParam($organizacyjneBaseHref, 'ledger', $selectedLedger);
        $organizacyjneActionsBaseHref = $appendQueryParam($organizacyjneActionsBaseHref, 'ledger', $selectedLedger);
        $logoHref = $appendQueryParam($logoHref, 'ledger', $selectedLedger);

        $defaultCollectionLabels = [
            'ksiazki-artystyczne' => 'Książki Artystyczne',
            'kolekcja-maszyn' => 'Maszyny',
            'kolekcja-matryc' => 'Matryce',
            'biblioteka' => 'Biblioteka',
            'kolekcja-klisz' => 'Klisze drukarskie',
        ];

        $esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        if (!$hasCustomScannerHtml) {
            $scannerHtml = '<div class="app-header-scanner app-header-bubble">'
                . '<strong>Skaner:</strong>'
                . '<button type="button" id="headerCardScannerButton" class="scanner-mode-button">Spr. karty</button>'
                . '<input type="text" id="headerCardScannerInput" class="scanner-input" placeholder="' . $esc('Zeskanuj numer ewidencyjny i nacisnij Enter') . '" autocomplete="off" spellcheck="false">'
                . '<p id="headerCardScannerStatusMessage" class="scanner-status" role="status" aria-live="polite" hidden></p>'
                . '</div>';
        }
        ?>
        <div class="app-header">
            <div class="app-header-top">
                <a class="app-header-logo-link" href="<?php echo $esc($logoHref); ?>">
                    <img src="bazamka.png" width="400" alt="<?php echo $esc('Logo bazy ' . $organizationName); ?>" class="logo app-header-logo">
                </a>

                <div class="app-header-right">
                    <div class="app-header-primary-row">
                        <div class="collection-switcher app-header-collections app-header-bubble is-expanded">
                            <strong>Kolekcje:</strong>
                            <?php foreach ($collections as $collectionKey => $collectionValue): ?>
                                <?php
                                $label = $defaultCollectionLabels[$collectionKey] ?? $collectionKey;
                                if (is_array($collectionValue) && isset($collectionValue['label'])) {
                                    $label = (string)$collectionValue['label'];
                                }
                                $href = 'index.php?collection=' . rawurlencode((string)$collectionKey) . '&ledger=' . rawurlencode($selectedLedger);
                                ?>
                                <a role="button" id="toggleButton" href="<?php echo $esc($href); ?>" class="<?php echo $selectedCollection === (string)$collectionKey ? 'active' : ''; ?>">
                                    <?php echo $esc($label); ?>
                                </a>
                            <?php endforeach; ?>
                            <strong>Baza:</strong>
                            <?php if ($showColumnButton || !empty($primaryActions)): ?>
                                <details class="app-header-dropdown app-header-ledger-dropdown-wrap">
                                    <summary class="app-header-dropdown-summary">Księga</summary>
                                    <div class="app-header-dropdown-menu">
                                        <?php if ($showColumnButton): ?><a href="#" onclick="toggleColumnSelector(); return false;">Wybierz kolumny</a><?php endif; ?>
                                        <?php foreach ($primaryActions as $action): ?>
                                            <?php if (!is_array($action) || empty($action['label']) || empty($action['href'])) { continue; } ?>
                                            <a href="<?php echo $esc($action['href']); ?>"><?php echo $esc($action['label']); ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php endif; ?>
                            <details class="app-header-dropdown">
                                <summary class="app-header-dropdown-summary">Organizacja</summary>
                                <div class="app-header-dropdown-menu">
                                    <a href="<?php echo $esc($organizacyjneBaseHref . '#regulamin-ewidencji'); ?>">Regulamin prowadzenia ewidencji</a>
                                    <a href="<?php echo $esc($organizacyjneBaseHref . '#instrukcja-obiegu'); ?>">Instrukcja obiegu dokumentów</a>
                                    <a href="<?php echo $esc($organizacyjneBaseHref . '#polityka-backupow'); ?>">Polityka backupów</a>
                                    <a href="<?php echo $esc($organizacyjneBaseHref . '#upowaznienia'); ?>">Upoważnienia dla pracowników</a>
                                    <div class="app-header-dropdown-separator" aria-hidden="true">-------------</div>
                                    <a href="<?php echo $esc($organizacyjneActionsBaseHref . '&action=export_ledger_csv'); ?>">Eksportowanie pełnej księgi</a>
                                    <a href="<?php echo $esc($organizacyjneActionsBaseHref . '&action=view_reports'); ?>">Generowanie raportów</a>
                                    <a href="<?php echo $esc($organizacyjneActionsBaseHref . '&action=view_change_history'); ?>">Udostępnienie historii zmian</a>
                                </div>
                            </details>
                            <details class="app-header-dropdown app-header-system-dropdown-wrap">
                                <summary class="app-header-dropdown-summary">System</summary>
                                <div class="app-header-dropdown-menu">
                                    <a href="<?php echo $esc($logoutHref); ?>">Wyloguj się</a>
                                    <a href="change_password.php?collection=<?php echo $esc(rawurlencode($selectedCollection)); ?>">Zmień hasło dla <?php echo $esc($username); ?></a>   <!-- CHECK THIS !!! nie podoba mi sie change_password.php?collection=  -- bo to nie hasło do kolekcji , tylko do usera ?!?! -->
                                     <?php if ($canAccessAdmin): ?><a href="admin.php">Panel administracyjny</a><?php endif; ?>
                                    <div class="app-header-dropdown-separator" aria-hidden="true">-------------</div>
                                    <a href="project_info.php">Info</a>
                                    
                                   
                                    <?php if ($canImportInventory): ?><a href="inventory_import.php?collection=<?php echo $esc(rawurlencode($selectedCollection)); ?>&amp;ledger=<?php echo $esc(rawurlencode($selectedLedger)); ?>">Import Excel / CSV</a><?php endif; ?>
                                    <?php if ($canImportInventory): ?><a href="ean_labels.php?collection=<?php echo $esc(rawurlencode($selectedCollection)); ?>&amp;ledger=<?php echo $esc(rawurlencode($selectedLedger)); ?>">Generator kodów paskowych EAN (PDF)</a><?php endif; ?>
                                    <div class="app-header-dropdown-separator" aria-hidden="true">-------------</div>
                                   <!-- <a href="#" onclick="if (typeof toggleThemeOverride === 'function') { toggleThemeOverride(); } return false;">Tryb jasny / ciemny</a>   -->
                                   <?php if ($canUseVanna): ?><a href="vanna.php?collection=<?php echo $esc(rawurlencode($selectedCollection)); ?>&amp;ledger=<?php echo $esc(rawurlencode($selectedLedger)); ?>">Kwerendy z AI </a><?php endif; ?>
                                </div>
                            </details>
                        </div>
                    </div>

                    <?php if (($showListEditor || !empty($lists)) || $scannerHtml !== ''): ?>
                        <div class="app-header-secondary-row">
                            <?php if ($showListEditor || !empty($lists)): ?>
                                <div class="header-low app-header-lists app-header-bubble">
                                    <?php if (!empty($lists)): ?>
                                        <strong>Listy:</strong>
                                        <select
                                            aria-label="Wybierz listę"
                                            onchange="if (this.value) { window.location.href = this.value; this.selectedIndex = 0; }"
                                        >
                                            <option value="">Wybierz listę</option>
                                            <?php foreach ($lists as $list): ?>
                                                <?php if (!isset($list['id'], $list['list_name'])) { continue; } ?>
                                                <option value="list_view.php?list_id=<?php echo (int)$list['id']; ?>&amp;collection=<?php echo $esc(rawurlencode($selectedCollection)); ?>&amp;ledger=<?php echo $esc(rawurlencode($selectedLedger)); ?>">
                                                    <?php echo $esc($list['list_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                    <?php if ($showListEditor && $canEditLists): ?>
                                        <a role="button" id="toggleButton" href="lists.php?collection=<?php echo $esc(rawurlencode($selectedCollection)); ?>&amp;ledger=<?php echo $esc(rawurlencode($selectedLedger)); ?>">Edytor list</a>
                                    <?php endif; ?>
                                    <?php if ($listsExtraHtml !== ''): ?>
                                        <?php echo $listsExtraHtml; ?>
                                    <?php endif; ?>

                                    <?php if ($showBulkBar): ?>
                                        <div class="bulk-bar app-header-bulk-bar">
                                            <strong>Dodaj do listy</strong>
                                            <select id="bulkList" onchange="handleBulkAdd(this)">
                                                <option value="">Wybierz</option>
                                                <option value="new">+ Nowa lista</option>
                                                <option disabled>──────────</option>
                                                <?php foreach ($lists as $list): ?>
                                                    <?php if (!isset($list['id'], $list['list_name'])) { continue; } ?>
                                                    <option value="<?php echo (int)$list['id']; ?>"><?php echo $esc($list['list_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>

                                            <label style="display:inline-flex; align-items:center; gap:3px; margin-left:8px;">
                                                <input type="checkbox" id="selectAllBoth" onclick="selectAllBothTables(this)">
                                                Zaznacz wszystko
                                            </label>

                                            <span id="bulkCount" class="muted">Nic nie zaznaczono</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($scannerHtml !== ''): ?>
                                <?php echo $scannerHtml; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    </div>
            </div>
        </div>
        <script>
        (function () {
            if (!window.__appHeaderDropdownOutsideCloseBound) {
                window.__appHeaderDropdownOutsideCloseBound = true;

                document.addEventListener('click', function (event) {
                    document.querySelectorAll('details.app-header-dropdown[open]').forEach(function (el) {
                        if (!el.contains(event.target)) {
                            el.removeAttribute('open');
                        }
                    });
                });

                document.addEventListener('keydown', function (event) {
                    if (event.key !== 'Escape') return;
                    document.querySelectorAll('details.app-header-dropdown[open]').forEach(function (el) {
                        el.removeAttribute('open');
                    });
                });
            }

            if (window.__appHeaderBubbleExpansionBound) {
                return;
            }
            window.__appHeaderBubbleExpansionBound = true;

            var collapseDelayMs = 2666;
            var activeBubble = null;
            var collapseTimerId = null;

            function clearCollapseTimer() {
                if (collapseTimerId === null) {
                    return;
                }
                window.clearTimeout(collapseTimerId);
                collapseTimerId = null;
            }

            function setBubbleExpanded(bubble, expanded) {
                if (!bubble) {
                    return;
                }
                if (bubble.classList.contains('app-header-collections')) {
                    bubble.classList.add('is-expanded');
                    return;
                }
                bubble.classList.toggle('is-expanded', expanded);
            }

            function activateBubble(bubble) {
                if (!bubble) {
                    return;
                }

                clearCollapseTimer();
                if (activeBubble && activeBubble !== bubble) {
                    setBubbleExpanded(activeBubble, false);
                }

                activeBubble = bubble;
                setBubbleExpanded(bubble, true);
            }

            function scheduleBubbleCollapse(bubble) {
                if (!bubble || activeBubble !== bubble) {
                    return;
                }

                clearCollapseTimer();
                collapseTimerId = window.setTimeout(function () {
                    if (activeBubble === bubble) {
                        setBubbleExpanded(bubble, false);
                        activeBubble = null;
                    }
                    collapseTimerId = null;
                }, collapseDelayMs);
            }

            function maybeScheduleBubbleCollapse(bubble) {
                if (!bubble) {
                    return;
                }

                window.setTimeout(function () {
                    if (bubble.matches(':hover') || bubble.contains(document.activeElement)) {
                        return;
                    }
                    scheduleBubbleCollapse(bubble);
                }, 0);
            }

            document.querySelectorAll('.app-header-bubble').forEach(function (bubble) {
                bubble.addEventListener('mouseenter', function () {
                    activateBubble(bubble);
                });

                bubble.addEventListener('mouseleave', function () {
                    maybeScheduleBubbleCollapse(bubble);
                });

                bubble.addEventListener('focusin', function () {
                    activateBubble(bubble);
                });

                bubble.addEventListener('focusout', function () {
                    maybeScheduleBubbleCollapse(bubble);
                });

                bubble.addEventListener('click', function (event) {
                    if (
                        bubble.classList.contains('app-header-collections')
                        || bubble.classList.contains('app-header-scanner')
                    ) {
                        activateBubble(bubble);
                        return;
                    }

                    var target = event.target;
                    if (!(target instanceof Element)) {
                        return;
                    }

                    var actionable = target.closest('a, button');
                    if (!actionable || !bubble.contains(actionable)) {
                        return;
                    }

                    window.setTimeout(function () {
                        if (document.activeElement instanceof HTMLElement && bubble.contains(document.activeElement)) {
                            document.activeElement.blur();
                        }
                        maybeScheduleBubbleCollapse(bubble);
                    }, 0);
                });
            });

            var headerCardScannerInput = document.getElementById('headerCardScannerInput');
            var headerCardScannerButton = document.getElementById('headerCardScannerButton');
            var headerCardScannerStatus = document.getElementById('headerCardScannerStatusMessage');
            if (headerCardScannerInput && headerCardScannerButton && headerCardScannerStatus) {
                var headerCardScannerBusy = false;
                var headerCardScannerStatusTimerId = null;
                var headerCardScannerCollection = <?php echo json_encode($selectedCollection); ?>;

                function showHeaderCardScannerStatus(message, type) {
                    headerCardScannerStatus.textContent = message;
                    headerCardScannerStatus.dataset.type = type;
                    headerCardScannerStatus.hidden = message === '';

                    if (headerCardScannerStatusTimerId !== null) {
                        window.clearTimeout(headerCardScannerStatusTimerId);
                        headerCardScannerStatusTimerId = null;
                    }

                    if (message === '') {
                        return;
                    }

                    headerCardScannerStatusTimerId = window.setTimeout(function () {
                        headerCardScannerStatus.hidden = true;
                        headerCardScannerStatus.textContent = '';
                        headerCardScannerStatusTimerId = null;
                    }, 4000);
                }

                function buildHeaderCardUrl(entryId) {
                    var normalizedEntryId = Number.parseInt(String(entryId ?? ''), 10);
                    if (!Number.isInteger(normalizedEntryId) || normalizedEntryId <= 0) {
                        return null;
                    }

                    return 'karta.php?id=' + encodeURIComponent(String(normalizedEntryId))
                        + '&collection=' + encodeURIComponent(headerCardScannerCollection);
                }

                async function submitHeaderCardScanner() {
                    var rawValue = String(headerCardScannerInput.value ?? '').trim();
                    if (rawValue === '') {
                        headerCardScannerInput.focus();
                        return;
                    }
                    if (headerCardScannerBusy) {
                        showHeaderCardScannerStatus('Poczekaj na zakonczenie poprzedniego skanu.', 'info');
                        return;
                    }

                    headerCardScannerBusy = true;
                    headerCardScannerInput.disabled = true;
                    headerCardScannerButton.disabled = true;
                    showHeaderCardScannerStatus('Wyszukiwanie karty...', 'info');

                    try {
                        var response = await fetch('find_entry_by_inventory.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                inventory_number: rawValue,
                                collection: headerCardScannerCollection
                            })
                        });
                        var responseData = await response.json();

                        if (!response.ok || !responseData.success) {
                            showHeaderCardScannerStatus(responseData.message || 'Nie znaleziono karty.', 'error');
                            return;
                        }

                        var cardUrl = buildHeaderCardUrl(responseData.entry_id);
                        if (cardUrl === null) {
                            showHeaderCardScannerStatus('Niepoprawny identyfikator karty.', 'error');
                            return;
                        }

                        showHeaderCardScannerStatus('Otwieranie karty...', 'info');
                        window.location.href = cardUrl;
                    } catch (error) {
                        showHeaderCardScannerStatus('Blad polaczenia. Sprobuj ponownie.', 'error');
                    } finally {
                        headerCardScannerBusy = false;
                        headerCardScannerInput.disabled = false;
                        headerCardScannerButton.disabled = false;
                        headerCardScannerInput.value = '';
                        headerCardScannerInput.focus();
                    }
                }

                headerCardScannerButton.addEventListener('click', function () {
                    if (String(headerCardScannerInput.value ?? '').trim() === '') {
                        headerCardScannerInput.focus();
                        return;
                    }

                    submitHeaderCardScanner();
                });

                headerCardScannerInput.addEventListener('keydown', function (event) {
                    if (event.key !== 'Enter') {
                        return;
                    }

                    event.preventDefault();
                    submitHeaderCardScanner();
                });
            }
        })();
        </script>
        <?php
    }
}
