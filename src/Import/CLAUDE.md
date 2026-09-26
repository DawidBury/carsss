# Moduł `Import` — zapis

Przeczytaj ten plik **przed** zmianą czegokolwiek w tym module. Jeśli zmieniasz zachowanie
modułu, zaktualizuj ten plik w tym samym zadaniu.

## Za co odpowiada

Strona zapisu w rozdziale zapis/odczyt. Trzy rzeczy:

1. cykl życia przebiegu (`import_run`),
2. rozesłanie zadań na RabbitMQ i ich konsumpcja,
3. **idempotentny upsert jednego rekordu źródła w jednej transakcji**.

## Granice

**Zależy od:** `App\Source` (port + kontrakt ładunku), `App\Entity`, Doctrine ORM i DBAL,
Messengera.

**Nie wolno tu**: HTML, DOM, adresów URL, ETagów, polskich etykiet, jednostek, nazw pól ze
strony WWW, niczego z `App\Api` ani `App\Catalog`. Jeśli piszesz w tym module słowo
`autocentrum`, coś poszło źle.

**Tabele, do których pisze:** `import_run`, `brand`, `car_model`, `version`, `variant`.
Nikt inny do nich nie pisze. `Catalog` je tylko czyta.

## Przepływ

```
app:import:start [--limit=N]
  └─ INSERT import_run (status='running', source = SourceCatalog::name())
  └─ foreach SourceCatalog::listRecords() → dispatch ImportRecord(runId, ref)
       └─ --limit=N kolejkuje tylko pierwszych N referencji

ImportRecordHandler (lokalnie 4 workery, na ECS 1 — ADR 0015)
  └─ SourceCatalog::fetchRecord(message.ref, FetchMode::Incremental)
       ├─ Record    → RecordWriter::write(runId, result.record)   [jedna transakcja]
       ├─ Unchanged → RecordWriter::markSeen(runId, result.ref)   [zwraca liczbę wierszy]
       │               └─ 0 → fetchRecord(message.ref, FetchMode::Full) i obsłuż wynik
       └─ Absent    → log, brak zapisu

app:import:finish <runId>            → status='completed', finished_at=now()
app:import:finish <runId> --failed   → status='failed',    finished_at=now()

app:import:status                    → tylko odczyt, bezpieczna w dowolnej chwili,
                                        także w trakcie trwającego przebiegu
```

`--limit=N` istnieje po to, żeby dało się sprawdzić ścieżkę importu end-to-end bez pełnego
przebiegu trwającego ~3,8 godziny — na przykład po zmianie w parserze. Wartość musi być
dodatnią liczbą całkowitą; niepoprawna kończy się błędem **przed** utworzeniem przebiegu,
więc nie zostawia po sobie wiersza `running` do odblokowania. Bez opcji zachowanie jest
pełne i niezmienione.

## Log contract

Written in English by the language rule in the root `CLAUDE.md`; the rest of this file is
awaiting a separate translation pass.

This module is the only place that can answer "how much did this run do". There are no counters
in `import_run` (pkt 11), there is no result table (ADR 0009), and `variant` has no
`last_seen_run_id` (pkt 5) — so every question about a run is a CloudWatch Logs Insights query
over the events below. Transport, retention, level and cost: ADR 0022. Operator queries:
`docs/deployment.md`. **This section is the normative contract and its only place.**

`ImportRecordHandler` and `RecordWriter` both carry `#[WithMonologChannel('import')]`.

| Event (the Monolog message) | Level | Emitted in | Context |
|---|---|---|---|
| `import.version_written` | `info` | `RecordWriter::write()`, after the transaction commits | `runId`, `ref`, `variantCount` |
| `import.version_unchanged` | `info` | `ImportRecordHandler::handleUnchanged()`, when `markSeen` returned > 0 | `runId`, `ref` |
| `import.refetch_forced` | `info` | `ImportRecordHandler::handleUnchanged()`, when `markSeen` returned 0 | `runId`, `ref` |
| `import.record_skipped` | `info` | `ImportRecordHandler::logAbsent()` and the early return in `RecordWriter::write()` | `runId`, `ref`, `reason` |
| `import.record_failed` | `error` | `ImportRecordHandler::__invoke()` | `runId`, `ref`, `exception` |

Rules that are not negotiable:

1. **The message string is the event name, and the list above is closed.** No prose, no
   interpolation, no second `event` key in the context. A query filters
   `message = "import.version_written"`; a message that reads nicely but varies is not queryable.
2. **`ref` is `SourceFetchResult::$ref`** (pkt 5d, ADR 0019) with exactly one exception:
   `SourceFetchResult::absent()` carries `ref = null` by the port contract, so the `absent` skip
   is the single case where `ImportRecord::$ref` may be logged — there is nothing else. Inside
   `RecordWriter`, `SourceRecord::$ref` **is** the canonical reference
   (`SourceFetchResult::record()` copies it), so `$record->ref` is correct there.
3. **`reason` classifies a skip and keeps the closed ADR 0009 list** — that list lives in ADR 0009
   and is not restated here. Only two of its values are emitted by this module: `absent` and
   `no_usable_variants`. **`absent` is a superset, not a peer of the adapter's reasons.**
   `SourceCatalog::fetchRecord()` returns `Absent` both when the source has no such page and when
   the adapter's parser rejected one, so a page dropped for `unknown_fuel_token` produces the
   adapter's `warning` on the `app` channel **and** an `import.record_skipped` with
   `reason: absent` here. Per-run accounting counts `import.record_skipped` and nothing else;
   the adapter's warnings are a separate diagnostic query (`docs/deployment.md`). Summing the two
   double-counts pages. `implausible_value` has no producer anywhere in the tree — ADR 0009
   reserves the value, nothing emits it. Do not rename the field and do not add a fifth value
   without amending ADR 0009.
4. **`import.record_failed` logs and rethrows.** Pkt 7 stands: retries belong to Messenger, and a
   `catch` that swallows acknowledges a message that was never written. The `catch` wraps the whole
   body of `__invoke` so it covers `fetchRecord()` as well as the writer, and it is the **only**
   place in this module that logs a failure — `RecordWriter` already rethrows and must not log the
   same failure twice.
4a. **One record is one failed *attempt*, not one lost page.** Because the `catch` rethrows, every
   redelivery logs again: with `max_retries: 5` (`config/packages/messenger.yaml`) a page that
   never succeeds emits six `import.record_failed` records. Counting them counts attempts and
   overstates lost pages roughly six-fold. **The page is lost only when Messenger gives up**, and
   that moment is Messenger's own `critical` record ("Removing from transport after N retries") on
   the `messenger` channel. `CRITICAL` is a different level from `ERROR`, so a query filtering on
   `ERROR` alone never sees it; the message's arrival in the `failed` transport is the same fact,
   countable through `app:import:status`. Queries for all of it: `docs/deployment.md`. Messenger's
   records carry neither `runId` nor `ref`, which is why `import.record_failed` exists alongside
   them.
5. **One level for every failure, no SQLSTATE classification.** `SQLSTATE 23505` (pkt 5c) and
   `40P01` (pułapka 2) are routine and still `error`; `context.exception.class` discriminates in
   the query. Branching on SQLSTATE to choose a level is one step from branching on it to choose
   a retry policy, which this module does not do.
6. **`variantCount` is `count($record->variants)`.** It is the only per-run measure of variant
   throughput that exists, because `variant` carries no run reference (pkt 5).
7. **`import.refetch_forced` exists to make pkt 10 observable.** Expected volume is a handful per
   run; a mass of them means `markSeen` is being called with the wrong key — the failure ADR 0017
   and pkt 10 describe, which nothing measured until now.
8. **Nothing else in this module logs, and nothing logs at `debug`.** 28 892 pages a night times
   one superfluous line is ~10 MB a month of noise, shipped and paid for. The same arithmetic is
   why the `messenger` channel is held at `warning` in production (ADR 0022 §3): its three `info`
   records per message would triple the run's volume with lines that carry neither `runId`
   nor `ref`.

## Podział ORM / DBAL w tym module

Obowiązuje od ADR 0018. Projekt mapowania i kontrakt zapisu: `docs/orm.md`. Tu jest tylko to,
czego kod ma pilnować.

| Co | Czym | Dlaczego |
|---|---|---|
| `brand`, `car_model` | **DBAL**, `ON CONFLICT (source_ref) DO NOTHING` | wiersze gorące, współdzielone przez tysiące stron; odczyt-potem-zapis przez ORM daje wyścig i utratę całej strony (ADR 0005, ADR 0018) |
| `version`, `variant` | **ORM** | lokalne dla jednej strony |
| `markSeen` (ścieżka 304) | **DBAL**, jeden `UPDATE` | masowy `UPDATE` w DQL też zwróciłby liczbę wierszy bez `SELECT`-a, więc powód jest inny: DQL masowy **omija `UnitOfWork`** i zostawia zarządzane encje nieaktualnymi, a tu i tak nie ma czego hydratować — jedna linia SQL jest po prostu prostsza |
| cykl życia `import_run` | **DBAL** | dwie instrukcje na przebieg, `app:import:status` liczy agregaty |

## Decyzje lokalne, których nie wolno cofnąć

1. **Cała strona w jednej jawnej transakcji, `flush()` wewnątrz niej.** To nie jest zalecenie
   stylistyczne: odroczony wyzwalacz `version_requires_variant_check` sprawdza niezmiennik przy
   `COMMIT`. `EntityManager::flush()` wywołany bez otwartej transakcji zakłada własną i od razu
   zatwierdza — wersja bez wariantów zostanie wtedy odrzucona, a marka i model zapisane przez
   DBAL wylądowałyby poza transakcją strony. Wewnątrz otwartej transakcji `flush()` zakłada
   `SAVEPOINT`, nie drugą transakcję (DBAL 4 nie umie zagnieżdżać inaczej). Zweryfikowane na
   żywym PostgreSQL 17 (`docs/database.md` §12, tura 4).
2. **Naruszenie niezmiennika zgłasza się przy `COMMIT`, nie przy `INSERT`.** Obsługa błędów
   musi otaczać zatwierdzenie transakcji, nie poszczególne instrukcje.
2a. **`rollBack()` tylko pod warunkiem `isTransactionActive()`.** Po nieudanym `COMMIT`
   transakcji już nie ma; bezwarunkowe wycofanie rzuca „There is no active transaction" i zasłania
   prawdziwy błąd. Zmierzone. Dzisiejszy `RecordWriter` ma tę wadę i trzeba ją naprawić przy
   przepisywaniu na ORM.
3. **`brand` i `car_model`: `ON CONFLICT DO NOTHING` + `SELECT`, nigdy `DO UPDATE`, nigdy ORM.**
   `DO UPDATE` bierze blokadę na kolidującym wierszu **zawsze**, także gdy `WHERE` wyklucza
   aktualizację. Przy 91 markach i 27 384 stronach zamieniłoby to import w wykonanie szeregowe.
   ORM ma wadę innego rodzaju: `SELECT`-potem-`INSERT` bez blokady między krokami, czyli utrata
   całej strony przy dwóch workerach. ADR 0005 i ADR 0018. Cena jest znana i przyjęta: zmiana
   nazwy marki w źródle nie jest podchwytywana automatycznie.
4. **`public_id` i `first_seen_run_id` nigdy nie są aktualizowane.** W DBAL: nie występują
   w `DO UPDATE`. W ORM: `public_id` jest nadawane w konstruktorze encji (`Uuid::v4()`),
   `firstSeenRun` jest właściwością `readonly` bez settera. Nie dokładaj settera „dla wygody
   testów" — to jedyny mechanizm, który trzyma „pierwszy raz widziany" przy życiu.
5. **Warianty zapisujemy komplet-na-stronę, dopasowując po `source_ref`:** kolekcja jest mapowana
   `indexBy: 'sourceRef'` i `orphanRemoval: true`, więc zapis to „zaktualizuj te, które są
   w ładunku, dodaj brakujące, usuń resztę". Dlatego `variant` nie ma `last_seen_run_id`
   i mieć go nie musi.
5a. **Nigdy `$version->variants()->clear()` i ponowne dodanie.** To dosłowne tłumaczenie starego
   `DELETE … WHERE source_ref <> ALL(:refs)` i **nie działa**: ORM wykonuje wstawienia przed
   kasowaniami, więc ponowne dodanie istniejącego `source_ref` kończy się `SQLSTATE 23505` na
   `variant_version_id_source_ref_key`. Nawet gdyby przechodziło, obracałoby każde `public_id`
   przy każdym przebiegu. Zmierzone.
5b. **Po udanym zapisie czyść jednostkę pracy; po nieudanym — resetuj menedżera. To dwa różne
   wywołania i pomylenie ich boli.** Zmierzone na 2 000 stron: 4,0 s z czyszczeniem między
   stronami, **45,4 s** bez, i różnica rośnie z liczbą zarządzanych encji.
   - **Ścieżka zdrowa:** `clear()`. W workerze robi to
     `DoctrineClearEntityManagerWorkerSubscriber` rejestrowany przez doctrine-bundle — nie
     wyłączaj go.
   - **Ścieżka błędu:** nieudany `flush()` **zamyka** menedżera, a `clear()` go **nie otwiera** —
     kończy się powodzeniem i zostawia zamkniętego. Kolejna, całkiem poprawna strona w tym samym
     procesie kończy się wtedy `EntityManagerClosed`: wyjątkiem **bez `SQLSTATE`**, wyglądającym
     na błąd w zupełnie innym miejscu. Otwiera z powrotem dopiero
     `ManagerRegistry::resetManager()`. W workerze dzieje się to samo z siebie, przez resetowanie
     usług z tagiem `kernel.reset` między wiadomościami. **Każda pętla zapisująca więcej niż jedną
     stronę w jednym procesie — komenda, test, ładowarka fixture'ów — musi zawołać
     `resetManager()` sama.** Po ADR 0018 nieudany `flush()` przestał być awarią i stał się
     zdarzeniem rutynowym (23505 przy zbiegu dwóch adresów), więc ta ścieżka jest chodzona,
     a nie teoretyczna.

   Nie dodawaj `messenger.middleware.doctrine_transaction` na szynę importu: opakowałoby
   w transakcję także ~2,5-sekundowe pobranie HTTP.
5c. **Ta sama wersja z dwóch workerów kończy się `SQLSTATE 23505`, nie czekaniem.** Przy
   `ON CONFLICT DO UPDATE` druga transakcja czekała na blokadę i wygrywała; przy odczycie-potem-
   -zapisie przez ORM przegrywa całą stronę. To jest przyjęta cena ADR 0018. Wiadomość ma zostać
   **ponowiona**, a nie odrzucona; pętli ponowień nie będzie, bo ponowienie zastaje wersję
   zapisaną przez zwycięzcę i wchodzi w ścieżkę aktualizacji. Ponowienie kosztuje **jedno
   warunkowe żądanie GET** — ale dopiero od ADR 0019. Wcześniej to zdanie było fałszywe dokładnie
   dla tych stron, które w ogóle mogą się ścigać: dwa adresy zbiegają się do jednej formy
   kanonicznej, `markSeen` szukał po adresie z wiadomości i zwracał 0, więc ADR 0017 wymuszał
   pełne bezwarunkowe pobranie i pełny zapis.
5d. **Do bazy trafia wyłącznie `SourceFetchResult::$ref`, nigdy `ImportRecord::$ref`.**
   Referencja z wiadomości służy tylko do oddania jej z powrotem portowi
   (`fetchRecord()`). Zapis rekordu, `markSeen` i log pominięcia idą po referencji z **wyniku**.
   Powód i pomiar: ADR 0019 — dla 6,2% stron obie wartości są różne, a wersja jest w bazie pod
   kanoniczną. To jest reguła sprawdzalna przez przeczytanie jednego pliku; jeśli
   w `ImportRecordHandler` widzisz `$message->ref` w argumencie czegokolwiek poza
   `fetchRecord()`, jest to defekt.
6. **Rekord z pustą listą wariantów jest odrzucany przed otwarciem transakcji**, z logiem
   `no_usable_variants`. Nie próbuj zapisać „samej wersji na razie” — baza i tak odrzuci,
   a Ty stracisz czytelny komunikat.
   Zamknięta lista powodów pominięcia jest w ADR 0009 i tylko tam. **Nierozpoznana skrzynia lub
   napęd NIE jest powodem pominięcia** — idzie do kolumny `*_unmapped_label` i import leci dalej
   (ADR 0006).
6a. **`fuel_type` jest `NOT NULL` i nie ma wartości domyślnej.** Rekord bez rodzaju paliwa nie
   dojdzie do tego modułu — port ma typ nienullowalny — więc jeśli kiedykolwiek zobaczysz tu
   `null`, jest to błąd adaptera, a nie brak danych. Nie „naprawiaj" tego wartością zastępczą.
6b. **Trzy kolumny taksonomii wykluczają się** (`generation`/`body_style` **albo**
   `unresolved_segment` **albo** nic). Przepisujesz je z ładunku jeden do jednego; baza
   wymusza wykluczenie (`version_unresolved_segment_excludes_resolved`).
7. **Ponowienia należą do Messengera**, nie do kodu tego modułu i nie do schematu bazy.
   Nie dodawaj `failure_count` ani własnej pętli ponowień.
8. **Czyszczenie nieaktualnych wierszy NIE jest częścią importu.** To procedura operatorska
   (`docs/database.md` §8). Automatyczne kasowanie po przebiegu, który mógł się wywalić
   w połowie, skasowałoby aktualne dane. Czyszczenie bierze pod uwagę wyłącznie przebiegi
   `completed`, więc przebieg zamknięty jako `failed` nigdy nie skasuje danych.
9. **Przebieg MUSI dać się zamknąć po awarii.** `app:import:finish <runId> --failed` ustawia
   `status='failed'` i `finished_at`. Bez tego worker ubity przez ECS zostawia wiersz `running`
   na zawsze, a każdy kolejny `app:import:start` odbija się od
   `import_run_one_running_per_source` — i nie ma z tego wyjścia bez ręcznego `UPDATE`.
   Obie ścieżki to ten sam kod, różnią się wyłącznie zapisywanym statusem.
10. **`markSeen` zwraca liczbę zaktualizowanych wierszy i zero JEST sygnałem, nie ciszą.**
   Oznacza, że pamięć podręczna adaptera twierdzi „bez zmian", a rdzeń tej wersji nie ma
   (np. po czyszczeniu). Wtedy pobierasz ponownie w trybie `FetchMode::Full`, który zabrania
   adapterowi zwrócenia `Unchanged`. Zignorowanie zera zapętla rekord w stanie, z którego
   nie ma wyjścia przy żadnej liczbie przebiegów (ADR 0017).
   **Zero ma to jedno znaczenie dopiero od ADR 0019.** Wcześniej `markSeen` szukał po adresie
   z wiadomości, a wersja siedziała w bazie pod formą kanoniczną, więc dla 6,2% stron zwracał
   zero **przy każdym zdrowym przebiegu** i wymuszał ~1 700 bezwarunkowych pobrań na przebieg.
   Jeśli kiedyś zobaczysz, że wymuszone pobrania są masowe zamiast pojedyncze, to nie ADR 0017
   jest zepsuty — to znowu ktoś woła `markSeen` niewłaściwym kluczem.
11. **Brak liczników w `import_run`.** Licznik inkrementowany przez 27 384 transakcje
    serializuje wszystkie workery na jednym wierszu. Odpowiedź na „ile ten przebieg zrobił"
    daje log — sekcja „Log contract" powyżej.
12. **Ten moduł nie wie, które źródło jest podłączone.** Zero gałęzi `if ($source === …)`.
    Nazwa źródła wchodzi do bazy wyłącznie przez `SourceCatalog::name()`.
13. **`app:import:status` nie dostaje własnych liczników i nigdy ich nie dostanie** — to ta sama
    decyzja co pkt 11. Zamiast tego liczy tanio z tego, co już istnieje:
    - **Mianownik postępu jest szacunkiem, nie faktem.** `SourceCatalog::listRecords()` daje
      prawdziwą liczbę stron, ale tylko przez pełne przeczytanie 5 MB `cars.xml` — nie do
      zaakceptowania przy komendzie odpytywanej co kilka sekund. Zamiast tego: mianownik =
      liczba wersji „dotkniętych" w tym przebiegu (`version.last_seen_run_id = :runId`, czyli
      zapisane LUB oznaczone `markSeen`) **plus** głębokość kolejki. To zaniża prawdziwą liczbę
      skonsumowanych wiadomości o tyle, ile stron w tym przebiegu zostało pominiętych
      (`absent`, `no_usable_variants`, `unknown_fuel_token`) — takie strony opuszczają kolejkę,
      ale nigdy nie dotykają `version`. Procent jest więc **zaniżony w trakcie przebiegu** i
      dochodzi do 100% dopiero, gdy kolejka się opróżni — świadomie, bo lepiej pokazać uczciwie
      mniej niż zmyślić dokładny procent. Komenda drukuje to zastrzeżenie wprost w swoim
      wyjściu, nie tylko tutaj.
    - **Głębokość kolejki i liczbę wiadomości `failed` czyta się przez Messengera**
      (`MessageCountAwareInterface::getMessageCount()` na usłudze `messenger.transport.import`
      / `messenger.transport.failed`), **nigdy przez `rabbitmqctl`**. Powód: komenda ma działać
      identycznie na Amazon MQ na AWS, gdzie nie ma dostępu do CLI brokera. Transport, który nie
      wspiera liczenia (np. `in-memory://` w testach), zwraca „unavailable" w wyjściu — nigdy
      ciche zero, bo zero wygląda jak „kolejka pusta", a nie jak „nie umiem policzyć".
    - **Szacowany czas do końca liczy się z rzeczywistego tempa tego przebiegu**
      (wersje dotknięte ÷ czas od `started_at`), nie z założonych 2 żądań/s — limiter mówi, ile
      *najwyżej* wolno wysłać, nie ile faktycznie się przetwarza (opóźnienia sieci, strony bez
      zmian zwracające szybkie `304` itd. zmieniają rzeczywiste tempo). Brak danych (przebieg
      dopiero wystartował, kolejka nieznana, tempo zerowe) daje `null`, nie zero ani ekstrapolację
      z powietrza.
    - **Komenda jest tylko do odczytu.** Nie wysyła, nie potwierdza ani nie odrzuca żadnej
      wiadomości — `get()` na transporcie nigdy nie jest wywoływane, tylko `getMessageCount()`.
14. **The log is the run report, and its shape is fixed** — see "Log contract" above. Because
    pkt 11 and pkt 13 keep counters out of both `import_run` and `app:import:status`, the events in
    that section are the only record of what a run did. Adding, renaming or silencing one of them
    breaks a query an operator runs at 07:00; changing the set requires amending ADR 0022 and this
    file in the same task.

## Pułapki

1. **Ta sama wersja z dwóch workerów naraz — to jest codzienność, nie przypadek brzegowy.**
   Import chodzi na czterech workerach (ADR 0015). 280 liści w `cars.xml` niesie segment
   `limuzyna` lub `limousine`, a te formy zbiegają się po 301, więc dwie wiadomości mogą
   dotyczyć jednego rekordu. Od ADR 0018 **nie kończy się to czekaniem, tylko błędem**: zapis
   przez ORM to `SELECT`-potem-`INSERT`, więc przegrywająca transakcja dostaje
   `UniqueConstraintViolationException` (`SQLSTATE 23505`, `version_source_ref_key`) i traci całą
   stronę. Zmierzone na dwóch równoległych procesach. Rozpoznaj to i **ponów wiadomość** —
   ponowienie trafia na `304`, wchodzi w ścieżkę `markSeen` i kończy się jednym żądaniem HTTP.
   To ostatnie zdanie jest prawdziwe **dzięki ADR 0019**, nie samo z siebie: bez utrwalonej
   formy kanonicznej `markSeen` zwracał zero właśnie dla par zbieżnych i ponowienie kosztowało
   pełne pobranie plus pełny zapis. Wyścig dotyczy zresztą wyłącznie **pierwszego** przebiegu
   pary: gdy wiersz wersji już istnieje, oba adresy idą ścieżką „bez zmian", a przy zmienionej
   stronie oba robią `UPDATE` i serializują się na blokadzie wiersza, bez błędu.
   Nie próbuj tego „naprawiać" blokadą doradczą ani powrotem do `ON CONFLICT DO UPDATE` na
   `version` bez ADR-a.
2. **Zakleszczenie przy równoległym zapisie** jest możliwe i jest **błędem powtarzalnym**.
   Wiadomość ma zostać ponowiona, a nie odrzucona. Rozpoznaj `SQLSTATE 40P01`.
3. **`TRUNCATE variant` jest zabroniony przez wyzwalacz.** Reset danych to
   `TRUNCATE version CASCADE` albo `DELETE FROM version`. Dotyczy też sprzątania w testach.
4. **Przeniesienie wariantu między wersjami jest pilnowane.** `UPDATE variant SET version_id`
   zostawiające pustą wersję zostanie odrzucone przy `COMMIT`.
5. **Luki w kluczach.** `GENERATED ALWAYS AS IDENTITY` konsumuje wartości także w wycofanych
   transakcjach. Nie wnioskuj z `max(id)` o liczbie wierszy.
6. **Ścieżka `Unchanged` musi oznaczyć wersję jako widzianą.** Pominięcie tego sprawia, że
   niezmieniona strona wygląda na nieaktualną i zostanie skasowana przy czyszczeniu.
7. **`app:import:finish` jest osobną komendą**, bo start nie wie, kiedy 27 384 wiadomości
   zostaną skonsumowane. Na AWS to drugie zadanie ECS, uruchamiane po opróżnieniu kolejki.
8. **Liczba workerów zależy od środowiska** (ADR 0015). Lokalnie **4 workery** dzielą jedno
   wiadro tokenów (bind `.:/app` → wspólny `var/`) i dają zmierzone 1,99 str./s, czyli 99%
   budżetu i przebieg 3,8 h. Na **ECS/Fargate limiter jest per zadanie**, więc obowiązuje
   `desired_count = 1`, dopóki stan limitera nie trafi do wspólnego magazynu (np. Redis) —
   cztery zadania bez tego to 8 żąd./s, czterokrotne złamanie etykiety.
   **Nie przenoś lokalnej liczby workerów na ECS.** Jeden worker to ~19 h, nie 3,8 h:
   sam nie wysyca limitu, bo obsługa strony zajmuje ~2,5 s.
   Kolejka daje wznawialność i ponowienia, a przy czterech workerach także przepustowość.
9. **Nierozpoznane tokeny paliwa da się wykryć przed pobieraniem.** Rodzaj paliwa pochodzi
   z adresu, więc `app:import:start` może policzyć nierozpoznane tokeny na całym sitemapie
   przed pierwszym żądaniem HTTP. Warto to zrobić: awaria słownika ujawnia się w sekundy,
   a nie po godzinach importu, w których każda taka strona jest pomijana.
10. **Jeden trwający przebieg na źródło** wymusza indeks częściowy. Próba wystartowania drugiego
    kończy się naruszeniem unikalności — to jest zamierzone, nie awaria. Komenda ma to
    zaraportować po ludzku.
