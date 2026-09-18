# Baza MKAL – ewidencja zbiorów

Aplikacja webowa w PHP do ewidencji obiektów muzealnych i bibliotecznych (wiele kolekcji i ksiąg), wyszukiwania, list, importu, etykiet EAN oraz pracy mobilnej.

Produkcja: [baza.mkal.pl](https://baza.mkal.pl)

## Base info

- **Technologia:** PHP (sesje, PDO), MySQL/MariaDB, HTML/CSS/JS.
- **Charakter projektu:** klasyczna aplikacja serwerowa (bez frameworka).
- **Główne kolekcje:**
  - `ksiazki-artystyczne`
  - `kolekcja-maszyn`
  - `kolekcja-matryc`
  - `biblioteka`
  - `kolekcja-klisz`
- **Księgi (ledgers):** inwentarzowa, depozytowa, nabytków i ubytków.
- **Autoryzacja:** logowanie użytkowników + panel `admin.php` (konto `root`).

## Project structure (najważniejsze pliki)

- `index.php` – tabela rekordów, lazy loading, kolumny, miniatury.
- `search.php` – wyszukiwarka wielopolowa.
- `karta.php` / `list_view.php` – karta obiektu, historia, przemieszczenia.
- `lists.php`, `add_list.php`, `add_to_list.php` – listy użytkownika.
- `neww.php` – dodawanie rekordu.
- `mobile_add.php` – mobilne dodawanie (QR / token + zdjęcia, tryb serii).
- `login.php`, `authenticate.php`, `logout.php`, `rejestracja.php` – sesja.
- `admin.php` – użytkownicy, uprawnienia, eksport.
- `museum_system.php` – numery inwentarzowe, sekwencje, unikalność.
- `inventory_import.php` – import inwentarza.
- `ean_labels.php` – etykiety.
- `share.php` – udostępnianie rekordów.
- `organizacyjne.php` – sprawy organizacyjne.
- `vanna.php` – asystent AI (opcjonalnie).
- `db.php` + `db_config.php` – połączenie PDO i konfiguracja ksiąg.
- `header.php` / `footer.php` – wspólny layout.

## Functions / capabilities

1. Wiele kolekcji i trzech ksiąg (ledgers) z mapowaniem tabel.
2. Lista rekordów z lazy loading i miniaturami.
3. Wyszukiwanie z normalizacją tekstu (m.in. polskie znaki).
4. Listy użytkownika, dodawanie po ID i po numerze ewidencyjnym.
5. Karta obiektu z logiem zmian i przemieszczeniami.
6. Mobilne dodawanie wpisów (token QR, upload, miniatury GD, tryb wielokrotny).
7. Import inwentarza, etykiety EAN, załączniki, linki udostępniania.
8. Panel administracyjny, uprawnienia, reset hasła.
9. Opcjonalny Vanna/OpenAI (klucze tylko w `db_config.php` albo env).

## Local setup

1. Serwer WWW z PHP oraz MySQL/MariaDB.
2. Skopiuj `db_config.example.php` → `db_config.php` i uzupełnij dane.
3. Upewnij się, że PHP może zapisywać do `gfx/`, `gfx/thumbs/`, `uploads/`, `tmp/`.
4. Otwórz `login.php`.

`db_config.php` nie jest w repozytorium (hasła i klucze API).

## Sync note

To repozytorium odzwierciedla aktualny kod z serwera produkcyjnego (bez uploadów zdjęć i bez sekretów).
