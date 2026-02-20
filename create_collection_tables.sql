-- Skrypt tworzy 3 dodatkowe kolekcje przez pełne klonowanie struktury tabel bazowych.
-- Dzięki CREATE TABLE ... LIKE ... kopiowany jest cały układ tabeli źródłowej:
-- kolumny, typy, wartości domyślne, indeksy oraz AUTO_INCREMENT.
--
-- WAŻNE:
-- tabela użytkowników jest jedna wspólna: karta_ewidencyjna_users
-- i NIE jest duplikowana dla pozostałych kolekcji.

-- KOLEKCJA MASZYN
CREATE TABLE IF NOT EXISTS karta_ewidencyjna_maszyny LIKE karta_ewidencyjna;
CREATE TABLE IF NOT EXISTS karta_ewidencyjna_maszyny_log LIKE karta_ewidencyjna_log;
CREATE TABLE IF NOT EXISTS karta_ewidencyjna_maszyny_przemieszczenia LIKE karta_ewidencyjna_przemieszczenia;

-- KOLEKCJA MATRYC
CREATE TABLE IF NOT EXISTS karta_ewidencyjna_matryce LIKE karta_ewidencyjna;
CREATE TABLE IF NOT EXISTS karta_ewidencyjna_matryce_log LIKE karta_ewidencyjna_log;
CREATE TABLE IF NOT EXISTS karta_ewidencyjna_matryce_przemieszczenia LIKE karta_ewidencyjna_przemieszczenia;

-- BIBLIOTEKA
CREATE TABLE IF NOT EXISTS karta_ewidencyjna_bib LIKE karta_ewidencyjna;
CREATE TABLE IF NOT EXISTS karta_ewidencyjna_bib_log LIKE karta_ewidencyjna_log;
CREATE TABLE IF NOT EXISTS karta_ewidencyjna_bib_przemieszczenia LIKE karta_ewidencyjna_przemieszczenia;

-- (opcjonalnie) szybkie kontrole porównania liczby kolumn:
-- SELECT 'karta_ewidencyjna' AS src, COUNT(*) AS cols FROM information_schema.columns
-- WHERE table_schema = DATABASE() AND table_name = 'karta_ewidencyjna'
-- UNION ALL
-- SELECT 'karta_ewidencyjna_maszyny', COUNT(*) FROM information_schema.columns
-- WHERE table_schema = DATABASE() AND table_name = 'karta_ewidencyjna_maszyny';
