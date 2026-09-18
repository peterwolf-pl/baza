<?php
require_once __DIR__ . '/app_settings.php';

$footerShowThemeToggle = isset($footerShowThemeToggle) ? (bool)$footerShowThemeToggle : true;
$footerShowLoadCacheButton = isset($footerShowLoadCacheButton) ? (bool)$footerShowLoadCacheButton : false;
$footerShowInfoButton = isset($footerShowInfoButton) ? (bool)$footerShowInfoButton : true;
$organizationProfile = appSettingsGetOrganizationProfile();
$footerOrganizationName = $organizationProfile['name'] !== '' ? $organizationProfile['name'] : 'Baza';
?>
<div class="footer-right">
    <?php if ($footerShowThemeToggle || $footerShowLoadCacheButton || $footerShowInfoButton): ?>
        <div class="footer-left-controls">
            <?php if ($footerShowThemeToggle): ?>
                <button type="button" id="themeToggleButton" title="Przełącz tryb jasny/ciemny (override)" onclick="toggleThemeOverride()">N/D</button>
            <?php endif; ?>
            <?php if ($footerShowLoadCacheButton): ?>
                <button type="button" id="loadCacheButton" title="Załaduj wszystkie wyniki strony" onclick="startLoadAndCache()">Load&Cache</button>
            <?php endif; ?>
            <?php if ($footerShowInfoButton): ?>
                <a role="button" href="project_info.php" id="toggleButton" class="footer-button-info" title="Informacje o projekcie">info</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php echo htmlspecialchars($footerOrganizationName, ENT_QUOTES, 'UTF-8'); ?> &reg; All Rights Reserved. &nbsp; &nbsp; &copy; by <a href="https://peterwolf.pl/" target="_blank">peterwolf.pl</a> 2026
</div>
<?php if ($footerShowThemeToggle): ?>
<script>
(function () {
    const THEME_STORAGE_KEY = 'index-theme-override';

    if (typeof window.applyThemeOverride !== 'function') {
        window.applyThemeOverride = function (theme) {
            const root = document.documentElement;
            const normalizedTheme = theme === 'dark' ? 'dark' : 'light';
            root.classList.remove('theme-light', 'theme-dark');
            root.classList.add('theme-' + normalizedTheme);
            root.style.colorScheme = normalizedTheme;

            const button = document.getElementById('themeToggleButton');
            if (button) {
                button.title = normalizedTheme === 'dark'
                    ? 'Aktywny tryb: ciemny. Kliknij, aby przełączyć na jasny.'
                    : 'Aktywny tryb: jasny. Kliknij, aby przełączyć na ciemny.';
            }
        };
    }

    if (typeof window.toggleThemeOverride !== 'function') {
        window.toggleThemeOverride = function () {
            const root = document.documentElement;
            const nextTheme = root.classList.contains('theme-dark') ? 'light' : 'dark';
            localStorage.setItem(THEME_STORAGE_KEY, nextTheme);
            window.applyThemeOverride(nextTheme);
        };
    }

    const storedTheme = localStorage.getItem(THEME_STORAGE_KEY);
    if (storedTheme === 'light' || storedTheme === 'dark') {
        window.applyThemeOverride(storedTheme);
    } else if (!document.documentElement.classList.contains('theme-light') && !document.documentElement.classList.contains('theme-dark')) {
        const systemPrefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        window.applyThemeOverride(systemPrefersDark ? 'dark' : 'light');
    }
})();
</script>
<?php endif; ?>
<script>
(function () {
    function normalizeValue(raw) {
        const value = (raw || '').trim();
        if (value === '') return { type: 'empty', value: '' };

        const dateMatch = value.match(/^(\d{4})-(\d{2})-(\d{2})(?:\s+.*)?$/);
        if (dateMatch) {
            return { type: 'date', value: Date.parse(dateMatch[0].slice(0, 10)) };
        }

        const normalizedNumber = value.replace(/\s/g, '').replace(',', '.');
        if (/^-?\d+(?:\.\d+)?$/.test(normalizedNumber)) {
            return { type: 'number', value: Number(normalizedNumber) };
        }

        return { type: 'string', value: value.toLocaleLowerCase('pl') };
    }

    function compareCells(aText, bText) {
        const a = normalizeValue(aText);
        const b = normalizeValue(bText);
        if (a.type === 'empty' && b.type !== 'empty') return 1;
        if (b.type === 'empty' && a.type !== 'empty') return -1;

        if (a.type === b.type && (a.type === 'number' || a.type === 'date')) {
            return a.value - b.value;
        }

        return String(a.value).localeCompare(String(b.value), 'pl', { numeric: true, sensitivity: 'base' });
    }

    function clearHeaderIndicators(table) {
        table.querySelectorAll('thead th').forEach((th) => {
            th.removeAttribute('data-sort-dir');
        });
    }

    function sortTableByColumn(table, columnIndex, direction) {
        const tbody = table.tBodies && table.tBodies[0];
        if (!tbody) return;

        const rows = Array.from(tbody.rows);
        rows.sort((rowA, rowB) => {
            const aCell = rowA.cells[columnIndex];
            const bCell = rowB.cells[columnIndex];
            const cmp = compareCells(aCell ? aCell.textContent : '', bCell ? bCell.textContent : '');
            return direction === 'desc' ? -cmp : cmp;
        });

        rows.forEach((row) => tbody.appendChild(row));
        table.dataset.sortColumn = String(columnIndex);
        table.dataset.sortDirection = direction;
    }

    function isSortableHeader(th) {
        if (!th) return false;
        if (th.querySelector('input, select, button')) return false;
        const label = (th.textContent || '').trim().toLowerCase();
        if (!label) return false;
        if (label === 'opcje') return false;
        return true;
    }

    function wireTableSorting(table) {
        if (!table || table.dataset.sortableWired === '1') return;
        const headerRow = table.tHead && table.tHead.rows && table.tHead.rows[0];
        if (!headerRow) return;

        Array.from(headerRow.cells).forEach((th, columnIndex) => {
            if (!isSortableHeader(th)) return;
            th.style.cursor = 'pointer';
            th.title = 'Kliknij, aby sortować';
            th.addEventListener('click', function () {
                const currentCol = table.dataset.sortColumn;
                const currentDir = table.dataset.sortDirection || 'asc';
                const nextDir = (currentCol === String(columnIndex) && currentDir === 'asc') ? 'desc' : 'asc';
                clearHeaderIndicators(table);
                th.setAttribute('data-sort-dir', nextDir);
                sortTableByColumn(table, columnIndex, nextDir);
            });
        });

        table.dataset.sortableWired = '1';
    }

    function initTableSorting(root) {
        (root || document).querySelectorAll('table').forEach(wireTableSorting);
    }

    function resortSortableTableById(tableId) {
        const table = document.getElementById(tableId);
        if (!table) return;
        wireTableSorting(table);
        if (table.dataset.sortColumn !== undefined && table.dataset.sortDirection) {
            sortTableByColumn(table, Number(table.dataset.sortColumn), table.dataset.sortDirection);
            const th = table.tHead && table.tHead.rows[0] && table.tHead.rows[0].cells[Number(table.dataset.sortColumn)];
            if (th) {
                clearHeaderIndicators(table);
                th.setAttribute('data-sort-dir', table.dataset.sortDirection);
            }
        }
    }

    window.initTableSorting = initTableSorting;
    window.resortSortableTableById = resortSortableTableById;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initTableSorting(document); });
    } else {
        initTableSorting(document);
    }
})();
</script>
<script>
function museumNextImageFallback(img) {
    if (!img) return;
    let urls = [];
    try {
        urls = JSON.parse(img.getAttribute('data-image-fallbacks') || '[]');
    } catch (e) {
        urls = [];
    }
    if (!Array.isArray(urls) || urls.length === 0) {
        img.onerror = null;
        return;
    }
    const next = urls.shift();
    img.setAttribute('data-image-fallbacks', JSON.stringify(urls));
    if (next) {
        img.src = next;
    } else {
        img.onerror = null;
    }
}
</script>
