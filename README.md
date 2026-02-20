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

## Security notes

- Nie trzymaj prawdziwych danych dostępowych do DB w repozytorium publicznym.
- W środowisku produkcyjnym wyłącz `display_errors`.
- Wymuś HTTPS (szczególnie przy logowaniu i tokenach mobilnych).

---

Jeżeli chcesz, mogę w kolejnym kroku przygotować także:
- sekcję **Database schema** (z listą tabel i relacji),
- sekcję **API endpoints** (request/response dla JSON endpointów),
- krótką instrukcję **deploymentu na Apache/Nginx**.
