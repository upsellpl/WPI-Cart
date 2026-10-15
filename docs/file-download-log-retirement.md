# Wycofanie logów pobrań EDD

## Zachowanie WPI-Cart

Pobrania nie zapisują już `file_download`. Wspólny `EDD_Logging::insert_log()`
odrzuca ten typ przed utworzeniem wpisu. Pozostałe typy logów i hook
`wpi_file_downloaded` pozostają aktywne. Nie zmienia się termin ważności linków,
walidacja podpisów ani wymóg poprawnego zakupu. Historyczna walidacja sprawdza
wygaśnięcie również przed zaakceptowaniem wariantu cenowego i odrzuca niezgodny wariant.

Publiczne funkcje pozostają jako adaptery kompatybilności:

- `edd_record_download_in_log()` i setter override nie wykonują operacji.
- Liczniki i gettery limitów zwracają `0`; `edd_is_file_at_download_limit()` zwraca `false`.
- `edd_get_file_download_log()` zwraca `false`.
- `EDD_Payment::has_unlimited_downloads` zwraca `true`; zapis tej flagi jest ignorowany.
- API `total_downloads` pozostaje liczbą `0` wyłącznie dla kompatybilności.
  Nie jest już miarą faktycznych pobrań.
- `get_download_logs()` zwraca tablicę z komunikatem `error`; wycofany tryb
  `file-download-logs` w `set_query_mode()` zwraca HTTP 410.

Typ `file_download` pozostaje rozpoznawany w loggerze do obsługi historycznych
danych. Nie wolno usuwać całej klasy, taksonomii ani typów `sale`, `gateway_error`,
`api_request`. Stara migracja nie odtwarza logów pobrań. Historyczne opcje limitów,
override i `_edd_file_download_log` pozostają nieaktywne i nie są kasowane tym narzędziem.

## Przygotowanie GO/BOX

1. Wdrożyć nowy kod we wszystkich instancjach i workerach używających danej bazy.
2. Sprawdzić motywy, MU-plugins i rozszerzenia pod kątem bezpośrednich zapisów
   `file_download`, własnych ekranów raportów i zależności od liczników/API.
   Audyt samego repozytorium WPI-Cart nie obejmuje zewnętrznych rozszerzeń.
3. Wybrać okres niskiego ruchu. Wstrzymać równoległe migracje, importy i modyfikacje
   klasyfikacji historycznych logów. Blokada komendy chroni przed drugim uruchomieniem
   tego narzędzia, ale nie przed innymi skryptami SQL lub zmianami w rozszerzeniach.
4. Zapewnić WP-CLI, InnoDB dla wszystkich pięciu tabel i dostęp do ich metadanych
   w `information_schema`. Narzędzie odmawia kasowania dla innego silnika.
5. Dla multisite operować osobno dla każdej witryny przez `--url`; prefiks bierze
   się z bieżącego `$wpdb`. Nie zakładać prefiksu `wp_`.

## Raport, backup, wykonanie

Komendy uruchamiać w katalogu właściwej instalacji WordPressa, jako jej operator:

```sh
wp edd cleanup-file-download-logs --url=https://sklep.example
```

Domyślnie nic nie jest kasowane. Raport `Before` zawiera liczbę logów we wszystkich
statusach, wpisów `postmeta`, relacji i niejednoznacznie sklasyfikowanych logów.
Raportowane meta i relacje obejmują także rekordy niejednoznaczne, pomijane przy kasowaniu.

Wykonać spójny backup bazy zgodnie z procedurą operatora GO/BOX. Przykład dla
bazy z tabelami InnoDB, bez równoległych zmian schematu:

```sh
wp db export /bezpieczna-lokalizacja/przed-czyszczeniem.sql --single-transaction
```

Backup przechowywać poza katalogiem publicznym, z ograniczonym dostępem: zawiera
m.in. dane klientów i IP. Zweryfikować kompletność i możliwość odtworzenia na
odizolowanej bazie. Zapisać instalację, datę, wersję wdrożenia, prefiks, raport oraz
lokalizację backupu. Sama obecność parametru `--backup` nie weryfikuje backupu.

```sh
wp edd cleanup-file-download-logs --url=https://sklep.example --execute --backup=/bezpieczna-lokalizacja/przed-czyszczeniem.sql --batch-size=500
```

Komenda wymaga potwierdzenia; `--yes` pomija interaktywne pytanie w kontrolowanej
automatyzacji. Rozmiar partii: 1–5000. Nie wykonywać czyszczenia automatycznie przy
aktualizacji wtyczki ani w żądaniu HTTP.

Selekcja wymaga `post_type=edd_log`, taksonomii `edd_log_type` i terminu o slugu
`file_download`. Wpisy posiadające dodatkowy typ logu są zachowywane do ręcznej
analizy. Każda partia usuwa wyłącznie metadane i relacje wybranych wpisów oraz same
wpisy. Nie usuwa terminów ani taksonomii. Krótkie transakcje ograniczają blokady;
przy nadmiernym obciążeniu zmniejszyć partię lub przerwać proces.

## Błędy, wznowienie i weryfikacja danych

Po każdej partii komenda drukuje liczbę zatwierdzonych usunięć. Błąd SQL wycofuje
bieżącą partię. Deadlock i timeout blokady mają maksymalnie trzy próby. Pozostałe
błędy kończą komendę kodem błędu. Przerwanie procesu zwalnia blokadę po zakończeniu
połączenia i wycofuje niezakończoną transakcję. Zatwierdzone partie pozostają usunięte.

Można ponowić tę samą komendę: selekcjonuje wyłącznie pozostałe rekordy. Nie używa
`OFFSET`. Nie uruchamia hooków `wp_delete_post`: to celowa operacja SQL na logach,
bez skutków ubocznych hooków rozszerzeń. Odświeża cache wpisów/metadanych/relacji
i przelicza liczniki powiązanych zarejestrowanych taksonomii po commit.

Po wykonaniu zapisać raport `After` i ponownie uruchomić tryb raportowania.
Oczekiwane `logs=0`, chyba że zachowano zgłoszone rekordy `ambiguous`.
Porównać z backupem zakres usuniętych danych i potwierdzić zachowanie pozostałych
logów; przy działającej instalacji uwzględnić ich naturalny przyrost. Brak terminu
`file_download` daje raport zerowy, a nie szerszą selekcję.

Jeżeli proces zakończy się po commit, lecz przed odświeżeniem cache/liczników,
wyczyścić cache WordPressa zgodnie z procedurą środowiska oraz wykonać:

```sh
wp term recount edd_log_type
```

Przed wznowieniem przeanalizować zgłoszone rekordy i błędy. Narzędzie nie usuwa
osieroconych lub nietypowanych wpisów na podstawie samych metadanych.

## Odtworzenie

Rollback dotyczy tylko bieżącej niezatwierdzonej partii. Zatwierdzone dane można
odzyskać z backupu. Najpierw odtworzyć backup do osobnej bazy i wyselekcjonować
usunięte logi wraz z ich meta i relacjami; sprawdzić kolizje ID przed przywróceniem.
Nie importować całego starego backupu na działający sklep: utraciłby późniejsze
zamówienia. Pełne odtworzenie jest dopuszczalne wyłącznie w uzgodnionym oknie
serwisowym z zabezpieczeniem wszystkich późniejszych zmian. Cofnięcie samego kodu
nie przywraca danych i ponownie uruchomiłoby ich gromadzenie.
