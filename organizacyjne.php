<?php
require_once __DIR__ . '/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/header.php';

$selectedCollection = (string)($_GET['collection'] ?? 'ksiazki-artystyczne');
$collections = [
    'ksiazki-artystyczne' => ['label' => 'Książki Artystyczne'],
    'kolekcja-maszyn' => ['label' => 'Maszyny'],
    'kolekcja-matryc' => ['label' => 'Matryce'],
    'biblioteka' => ['label' => 'Biblioteka'],
    'kolekcja-klisz' => ['label' => 'Klisze drukarskie'],
];
if (!isset($collections[$selectedCollection])) {
    $selectedCollection = 'ksiazki-artystyczne';
}
$actionsBaseHref = 'organizacyjne_actions.php?collection=' . rawurlencode($selectedCollection);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dokumenty organizacyjne | baza.mkal.pl</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<?php
renderAppHeader([
    'selectedCollection' => $selectedCollection,
    'collections' => $collections,
    'lists' => [],
    'showListEditor' => false,
    'showColumnButton' => false,
    'logoHref' => 'index.php?collection=' . rawurlencode($selectedCollection),
]);
?>

<main class="organizacyjne-page">
    <section class="organizacyjne-card">
        <h1>Wymagania organizacyjne</h1>
        <p>Dokumenty pomocnicze dla prowadzenia ewidencji muzealiów.</p>
        <div class="organizacyjne-jump-wrap" aria-label="Nawigacja po wymaganiach organizacyjnych">
            <strong>Organizacyjne:</strong>
            <select id="organizacyjneJumpSelect">
                <option value="">Wybierz pozycję</option>
                <option value="#regulamin-ewidencji">Regulamin prowadzenia ewidencji</option>
                <option value="#instrukcja-obiegu">Instrukcja obiegu dokumentów</option>
                <option value="#polityka-backupow">Polityka backupów</option>
                <option value="#upowaznienia">Upoważnienia dla pracowników</option>
                <option value="#generowanie-pelnej-ksiegi">Wygenerowanie pełnej księgi</option>
                <option value="#wydruk-raportow">Generowanie raportów</option>
                <option value="#udostepnienie-historii-zmian">Udostępnienie historii zmian</option>
                <option disabled>──────────</option>
                <option value="<?php echo htmlspecialchars($actionsBaseHref . '&action=export_ledger_csv', ENT_QUOTES, 'UTF-8'); ?>">Wygenerowanie pełnej księgi</option>
                <option value="<?php echo htmlspecialchars($actionsBaseHref . '&action=view_reports', ENT_QUOTES, 'UTF-8'); ?>">Generowanie raportów</option>
                <option value="<?php echo htmlspecialchars($actionsBaseHref . '&action=view_change_history', ENT_QUOTES, 'UTF-8'); ?>">Udostępnienie historii zmian</option>
            </select>
            <button type="button" id="toggleButton" onclick="goToOrganizacyjneItem()">Przejdź</button>
        </div>
    </section>

    <section id="regulamin-ewidencji" class="organizacyjne-card organizacyjne-section">
        <h2>Regulamin prowadzenia ewidencji muzealiów</h2>

        <h3>§1 Postanowienia ogólne</h3>
        <ol>
            <li>Regulamin określa zasady prowadzenia ewidencji muzealiów w muzeum.</li>
            <li>Ewidencja prowadzona jest w systemie elektronicznym oraz w formie wydruków archiwalnych.</li>
            <li>Celem ewidencji jest zapewnienie trwałości, integralności i identyfikowalności zbiorów.</li>
        </ol>

        <h3>§2 Zakres ewidencji</h3>
        <ol>
            <li>
                Ewidencja obejmuje:
                <ul>
                    <li>księgę inwentarzową</li>
                    <li>księgę depozytów</li>
                    <li>rejestr wypożyczeń</li>
                    <li>dokumentację nabytków i ubytków</li>
                    <li>dokumentację fotograficzną</li>
                </ul>
            </li>
            <li>Każdy obiekt otrzymuje unikalny numer inwentarzowy.</li>
            <li>Numer inwentarzowy jest trwały i nie podlega zmianie.</li>
        </ol>

        <h3>§3 Zasady wpisu</h3>
        <ol>
            <li>Wpisu dokonuje wyznaczony pracownik.</li>
            <li>
                Wpis zawiera co najmniej:
                <ul>
                    <li>numer inwentarzowy</li>
                    <li>nazwę obiektu</li>
                    <li>datę wpisu</li>
                    <li>sposób nabycia</li>
                    <li>opis</li>
                    <li>stan zachowania</li>
                    <li>lokalizację</li>
                </ul>
            </li>
            <li>Niedopuszczalne jest usuwanie wpisów.</li>
            <li>Korekty dokonywane są wyłącznie poprzez zapis historii zmian.</li>
        </ol>

        <h3>§4 Odpowiedzialność</h3>
        <ol>
            <li>Za prawidłowość ewidencji odpowiada Dyrektor muzeum.</li>
            <li>Bezpośrednią odpowiedzialność ponosi pracownik prowadzący ewidencję.</li>
            <li>Każda zmiana jest rejestrowana w systemie.</li>
        </ol>

        <h3>§5 Kontrola i archiwizacja</h3>
        <ol>
            <li>Raz w roku wykonuje się kontrolę zgodności ewidencji ze stanem faktycznym.</li>
            <li>Raz w roku archiwizuje się pełny eksport księgi inwentarzowej w formacie PDF.</li>
        </ol>
    </section>

    <section id="instrukcja-obiegu" class="organizacyjne-card organizacyjne-section">
        <h2>Instrukcja obiegu dokumentów</h2>

        <h3>1. Dokumenty nabycia</h3>
        <ol>
            <li>Dokument nabycia wpływa do sekretariatu.</li>
            <li>Dokument otrzymuje numer sprawy.</li>
            <li>Dokument przekazywany jest do działu merytorycznego.</li>
            <li>Po zatwierdzeniu przez Dyrektora następuje wpis do księgi inwentarzowej.</li>
        </ol>

        <h3>2. Dokumenty wypożyczeń</h3>
        <ol>
            <li>Wniosek o wypożyczenie składany jest pisemnie.</li>
            <li>Decyzję podejmuje Dyrektor.</li>
            <li>Sporządzana jest umowa wypożyczenia.</li>
            <li>Informacja wprowadzana jest do systemu.</li>
        </ol>

        <h3>3. Dokumenty konserwatorskie</h3>
        <ol>
            <li>Każda ingerencja w obiekt wymaga karty prac konserwatorskich.</li>
            <li>Dokumentacja dołączana jest do rekordu obiektu.</li>
        </ol>

        <h3>4. Archiwizacja</h3>
        <ol>
            <li>Dokumenty przechowuje się w formie papierowej oraz cyfrowej.</li>
            <li>Dokumenty cyfrowe zapisuje się w systemie ewidencyjnym oraz na serwerze archiwalnym.</li>
        </ol>
    </section>

    <section id="polityka-backupow" class="organizacyjne-card organizacyjne-section">
        <h2>Polityka backupów</h2>

        <h3>1. Cel</h3>
        <p>Zapewnienie bezpieczeństwa danych ewidencyjnych oraz ciągłości działania systemu.</p>

        <h3>2. Zakres danych objętych backupem</h3>
        <ul>
            <li>baza danych systemu ewidencji</li>
            <li>dokumentacja cyfrowa</li>
            <li>fotografie</li>
            <li>umowy i dokumenty PDF</li>
        </ul>

        <h3>3. Harmonogram</h3>
        <ol>
            <li>Backup automatyczny codzienny o godzinie 02:00.</li>
            <li>Backup pełny tygodniowy.</li>
            <li>Backup offline raz w miesiącu na nośniku zewnętrznym.</li>
        </ol>

        <h3>4. Lokalizacja kopii</h3>
        <ol>
            <li>Serwer lokalny.</li>
            <li>Zewnętrzny nośnik offline.</li>
            <li>Kopia w innej lokalizacji fizycznej.</li>
        </ol>

        <h3>5. Testy odtwarzania</h3>
        <ol>
            <li>Raz na kwartał przeprowadza się test odtworzenia danych.</li>
            <li>Wynik testu dokumentuje się protokołem.</li>
        </ol>

        <h3>6. Odpowiedzialność</h3>
        <ol>
            <li>Za realizację polityki backupów odpowiada administrator systemu.</li>
            <li>Administrator sporządza roczny raport bezpieczeństwa.</li>
        </ol>
    </section>

    <section id="upowaznienia" class="organizacyjne-card organizacyjne-section">
        <h2>Upoważnienia dla pracowników</h2>
        <h3>Upoważnienie dla pracownika do prowadzenia ewidencji</h3>
        <div class="organizacyjne-template">Imię i nazwisko: __________________________
Stanowisko: _______________________________

Na podstawie obowiązujących przepisów oraz regulaminu prowadzenia ewidencji muzealiów upoważnia się wyżej wymienioną osobę do:
• dokonywania wpisów do księgi inwentarzowej
• aktualizacji danych w systemie ewidencyjnym
• prowadzenia dokumentacji depozytów
• generowania raportów

Upoważnienie obowiązuje od dnia: ____________

Upoważnienie wygasa z dniem zakończenia stosunku pracy lub decyzją Dyrektora.

Podpis Dyrektora: _________________________
Data: _______________________</div>
    </section>

    <section id="generowanie-pelnej-ksiegi" class="organizacyjne-card organizacyjne-section">
        <h2>Wygenerowanie pełnej księgi</h2>
        <p>Funkcja organizacyjna przewidziana do przygotowania pełnego eksportu księgi inwentarzowej (np. PDF) dla wybranej kolekcji.</p>
        <p>
            <a role="button" id="toggleButton" href="<?php echo htmlspecialchars($actionsBaseHref . '&action=export_ledger_csv', ENT_QUOTES, 'UTF-8'); ?>">
                Pobierz pełną księgę (CSV)
            </a>
        </p>
    </section>

    <section id="wydruk-raportow" class="organizacyjne-card organizacyjne-section">
        <h2>Generowanie raportów</h2>
        <p>Funkcja organizacyjna otwiera stronę raportów dla wszystkich 4 kolekcji z możliwością druku oraz zapisania do PDF.</p>
        <p>
            <a role="button" id="toggleButton" href="<?php echo htmlspecialchars($actionsBaseHref . '&action=view_reports', ENT_QUOTES, 'UTF-8'); ?>">
                Otwórz stronę raportów
            </a>
        </p>
    </section>

    <section id="udostepnienie-historii-zmian" class="organizacyjne-card organizacyjne-section">
        <h2>Udostępnienie historii zmian</h2>
        <p>Funkcja organizacyjna przewidziana do udostępniania historii zmian wpisów (logów) uprawnionym użytkownikom.</p>
        <p>
            <a role="button" id="toggleButton" href="<?php echo htmlspecialchars($actionsBaseHref . '&action=view_change_history', ENT_QUOTES, 'UTF-8'); ?>">
                Otwórz historię zmian
            </a>
        </p>
    </section>
</main>

<?php include __DIR__ . '/footer.php'; ?>
<script>
function goToOrganizacyjneItem() {
    const select = document.getElementById('organizacyjneJumpSelect');
    if (!select || !select.value) return;
    const target = select.value;
    if (target.startsWith('#')) {
        window.location.hash = target;
        return;
    }
    window.location.href = target;
}

document.getElementById('organizacyjneJumpSelect')?.addEventListener('change', function () {
    if (this.value) {
        goToOrganizacyjneItem();
    }
});
</script>
</body>
</html>
