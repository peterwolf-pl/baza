-- Tworzy dodatkowe kolekcje o takiej samej strukturze jak podstawowe tabele.
-- Uruchom po połączeniu z docelową bazą danych (USE nazwa_bazy;).

CREATE TABLE IF NOT EXISTS karta_ewidencyjna_maszyny LIKE karta_ewidencyjna;
CREATE TABLE IF NOT EXISTS karta_ewidencyjna_maszyny_log LIKE karta_ewidencyjna_log;
CREATE TABLE IF NOT EXISTS karta_ewidencyjna_maszyny_przemieszczenia LIKE karta_ewidencyjna_przemieszczenia;

CREATE TABLE IF NOT EXISTS karta_ewidencyjna_matryce LIKE karta_ewidencyjna;
CREATE TABLE IF NOT EXISTS karta_ewidencyjna_matryce_log LIKE karta_ewidencyjna_log;
CREATE TABLE IF NOT EXISTS karta_ewidencyjna_matryce_przemieszczenia LIKE karta_ewidencyjna_przemieszczenia;

CREATE TABLE IF NOT EXISTS karta_ewidencyjna_bib LIKE karta_ewidencyjna;
CREATE TABLE IF NOT EXISTS karta_ewidencyjna_bib_log LIKE karta_ewidencyjna_log;
CREATE TABLE IF NOT EXISTS karta_ewidencyjna_bib_przemieszczenia LIKE karta_ewidencyjna_przemieszczenia;
