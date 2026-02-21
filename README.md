# Baza MKAL – ewidencja zbiorów

Prosta aplikacja webowa w PHP do prowadzenia ewidencji obiektów muzealnych i bibliotecznych (wiele kolekcji), przeszukiwania rekordów oraz pracy z listami.

## Base info

- **Technologia:** PHP (sesje, PDO), MySQL/MariaDB, HTML/CSS/JS.
- **Charakter projektu:** klasyczna aplikacja serwerowa (bez frameworka), uruchamiana na serwerze WWW z obsługą PHP.
- **Główne kolekcje:**
  - `ksiazki-artystyczne`
  - `kolekcja-maszyn`
  - `kolekcja-matryc`
  - `biblioteka`
- **Autoryzacja:** logowanie użytkowników + panel administracyjny (`root`) do zarządzania i eksportu.

## Project structure (najważniejsze pliki)

- `index.php` – główny widok tabeli rekordów, ładowanie danych AJAX, ustawienia kolumn i miniaturek.
- `search.php` – wyszukiwarka wielopolowa (normalizacja tekstu, wyszukiwanie przybliżone).
- `karta.php` / `list_view.php` – widok i operacje na karcie obiektu, historia/przemieszczenia.
- `lists.php` – listy użytkownika i przejścia do widoków list.
- `add_list.php`, `add_to_list.php` – endpointy API JSON do tworzenia list i dodawania pozycji.
- `neww.php` – formularz dodawania nowego rekordu.
- `mobile_add.php` – mobilne dodawanie wpisów (tokeny jednorazowe + upload zdjęć).
- `login.php`, `authenticate.php`, `logout.php` – logowanie i sesja.
- `admin.php` – panel administracyjny (zmiana haseł, eksport SQL itp.).
- `db.php` – konfiguracja połączenia z bazą (PDO).
- `create_collection_tables.sql` – skrypt pomocniczy dla struktur tabel kolekcji.

## Functions / capabilities

1. **Obsługa wielu kolekcji**
   - przełączanie tabel głównych zależnie od kolekcji,
   - walidacja dozwolonych kolekcji na endpointach.

2. **Lista rekordów z lazy loading**
   - pobieranie rekordów partiami (`fetch_rows`),
   - dynamiczne wykrywanie kolumn i klucza głównego,
   - miniatury dla `dokumentacja_wizualna`.

3. **Wyszukiwanie i filtrowanie**
   - normalizacja tekstu (m.in. znaki diakrytyczne),
   - wyszukiwanie dokładne/przybliżone po wielu polach.

4. **Listy użytkownika**
   - tworzenie list,
   - dodawanie rekordów do list,
   - odczyt list per kolekcja.

5. **Obsługa miniaturek i obrazów**
   - generowanie ścieżek miniatur,
   - fallback URL,
   - tworzenie miniatur po uploadzie (GD).

6. **Mobilne dodawanie wpisów**
   - tokeny logowania czasowe,
   - formularz mobilny,
   - upload zdjęcia i zapis do kolekcji.

7. **Administracja**
   - logowanie administratora `root`,
   - zmiana haseł użytkowników,
   - eksport danych SQL.

## Local setup (quick start)

1. Skonfiguruj serwer WWW z PHP oraz bazę MySQL/MariaDB.
2. Uzupełnij dane połączenia w `db.php`.
3. Zaimportuj/utwórz tabele (np. na bazie `create_collection_tables.sql` oraz istniejącego dumpa).
4. Umieść repozytorium w katalogu serwera i otwórz `login.php`.



@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$
@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$
@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$
@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$
@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$@#$@#$@#$#@$




APP SUMMARY: baza.mkal.pl Source basis: repository files in /Users/piotrek/Downloads/baza
What it is
A PHP + MySQL web app for managing catalog records of multiple Muzeum Ksiazki Ar tystycznej collections. It provides authenticated access to browse, search, edit, and track entries.
Who it's for
Primary users: museum staff and collection/documentation operators maintaining r ecords.
What it does
- Supports four collections via table mapping: ksiazki-artystyczne, kolekcja-mas
zyn, kolekcja-matryc, biblioteka. - Authenticated browsing with lazy row loading and session-persisted visible col
umns.
- Search across table columns with normalized matching and saved search state UR
Ls.
- Record detail/edit (karta) with per-field change logging into dedicated *_log tables.
- Movement tracking (przemieszczenia) for single records and bulk add from list view.
- List management: create, rename, delete lists; add/remove entries per collecti
on.
- Mobile add flow: one-time QR token login, optional photo upload, thumbnail cre ation.
How it works (repo evidence only) - UI/service layer: PHP page controllers render HTML (index.php, search.php, kar ta.php, list_view.php, mobile_add.php, admin.php). - Data layer: shared PDO connection in db.php; direct SQL in controllers; no ORM found.
- Auth/session flow: login.php -> authenticate.php -> session checks on protecte d pages. - Collection routing maps to main/log/moves DB tables per selected collection. - Media flow stores uploads in gfx/ and generated thumbs in gfx/thumbs (GD-based ).
- Admin panel (root login) supports user password changes and full SQL DB export
.
How to run (minimal)
1. Configure database credentials in db.php. 2. Ensure MySQL schema/tables exist and PHP can write to gfx/ (uploads/thumbnail s).
3. Serve the directory with PHP-capable web server and open /login.php. 4. Sign in with a user from karta_ewidencyjna_users. 5. Not found in repo: schema migration/setup scripts and an official local run c ommand.
