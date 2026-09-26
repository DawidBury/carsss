# Schemat bazy danych (PostgreSQL 17)

Ten plik jest jedynym miejscem, w którym opisany jest schemat. Migracje w `migrations/` są jego
wykonaniem — jeśli się rozjadą, źródłem prawdy jest baza, a ten plik jest defektem do naprawy.

Zasady, których trzyma się cały schemat:

1. Niezmiennik, który da się wyrazić w bazie, jest wyrażony w bazie.
2. `GENERATED ALWAYS AS IDENTITY`, nigdy `SERIAL`.
3. Klucz wewnętrzny jest liczbowy i nie wychodzi na zewnątrz; tożsamość publiczna to osobna
   kolumna `public_id`.
4. **Ograniczenie, które może wywalić import całej strony, jest ostatnią linią obrony, a nie
   pierwszą.** Sprawdzanie sensowności wartości należy do adaptera; w bazie zostają tylko
   niezmienniki, których naruszenie oznacza błąd programu, nie błąd danych.
5. Indeks bez zapytania, które go używa, jest defektem. Lista zapytanie → indeks w §6.
6. Kolumna, której nic nie zapisuje, jest defektem.

## 1. Widok całości

```
import_run ──┬── brand ── car_model ── version ──< variant
             │     (first_seen_run_id / last_seen_run_id)
             │
api_key      └── (bez relacji)
autocentrum_page_cache  (własność adaptera, poza rdzeniem)
```

Siedem tabel. Nie ma tabeli źródeł danych, tabeli zadań do pobrania, tabeli odrzuceń, tabeli
atrybutów, tabeli aliasów etykiet ani tabeli generacji i nadwozi. Każdy z tych braków ma ADR.

## 2. `import_run` — przebieg importu

```sql
CREATE TABLE import_run (
    id          BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source      TEXT        NOT NULL,
    status      TEXT        NOT NULL,
    started_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    finished_at TIMESTAMPTZ,

    CONSTRAINT import_run_source_not_blank
        CHECK (length(btrim(source)) > 0),
    CONSTRAINT import_run_status_allowed
        CHECK (status IN ('running', 'completed', 'failed')),
    CONSTRAINT import_run_finished_at_matches_status
        CHECK ((status = 'running') = (finished_at IS NULL))
);

CREATE UNIQUE INDEX import_run_one_running_per_source
    ON import_run (source) WHERE status = 'running';
```

| Element | Uzasadnienie |
|---|---|
| PK `id` | Wiersz nie ma klucza naturalnego — dwa przebiegi z tego samego źródła w tej samej sekundzie są legalne. Klucz surogatowy jest jedynym poprawnym wyborem. |
| `source` NOT NULL | Odpowiedź na wymaganie „z którego źródła pochodzi rekord”. Wypełniana z `SourceCatalog::name()`, więc nie da się jej pominąć ani skłamać w konfiguracji. Bez `CHECK` na zamkniętą listę — nazwy adapterów są otwarte, a lista w bazie musiałaby być aktualizowana przy każdym nowym adapterze, co łamie regułę „nowy katalog + wpis w konfiguracji”. |
| `status` TEXT + CHECK | Trzy stany. Dlaczego `TEXT` a nie typ wyliczeniowy — ADR 0006. |
| `started_at` DEFAULT `now()` | Jedyne źródło czasu w schemacie taksonomii. Dzięki niemu tabele potomne nie potrzebują `created_at` (ADR 0010). |
| `finished_at` NULL | `NULL` znaczy dokładnie „trwa”. Nie potrzeba osobnej flagi. |
| CHECK `finished_at_matches_status` | Wyklucza zakończony przebieg bez daty i trwający z datą. Naruszenie to błąd programu, nie danych — spełnia zasadę 4. |
| UNIQUE częściowy `(source) WHERE status='running'` | Dwa równoległe przebiegi tego samego źródła rozbiłyby wykrywanie nieaktualnych wierszy (§8): część wierszy nosiłaby jeden `last_seen_run_id`, część drugi, a czyszczenie skasowałoby aktualne dane. Indeks częściowy pozwala mieć dowolnie wiele zakończonych przebiegów i najwyżej jeden trwający. Obsługuje zapytanie **Q-R**. |

**Brak liczników** (`pages_fetched`, `skipped`, `failed`). Licznik inkrementowany przez 27 384
transakcje serializuje wszystkie workery na jednym wierszu — to defekt wydajnościowy wykryty
w recenzji poprzedniego podejścia. Liczby wynikają z logów.

**Wiersze `import_run` nigdy nie są kasowane** (52 wiersze rocznie). Dlatego wszystkie klucze
obce na nią wskazujące są `ON DELETE RESTRICT` i **żaden z nich nie jest indeksowany** —
indeks pod klucz obcy jest potrzebny tylko po to, żeby przyspieszyć kasowanie rodzica, a tu nie
ma kasowania rodzica. Gdyby ktoś kiedyś spróbował, `RESTRICT` zatrzyma go sekwencyjnym
skanem 32 000 wierszy, czyli w kilkanaście milisekund.

## 3. Taksonomia

### 3.1 `brand`

```sql
CREATE TABLE brand (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    public_id         UUID   NOT NULL DEFAULT gen_random_uuid(),
    source_ref        TEXT   NOT NULL,
    name              TEXT   NOT NULL,
    first_seen_run_id BIGINT NOT NULL REFERENCES import_run (id) ON DELETE RESTRICT,

    CONSTRAINT brand_source_ref_not_blank CHECK (length(btrim(source_ref)) > 0),
    CONSTRAINT brand_name_not_blank       CHECK (length(btrim(name)) > 0)
);

CREATE UNIQUE INDEX brand_public_id_key  ON brand (public_id);
CREATE UNIQUE INDEX brand_source_ref_key ON brand (source_ref);
```

| Element | Uzasadnienie |
|---|---|
| PK `id` BIGINT identity | Wąski (8 B) klucz do złączeń z `car_model`. `INT` wystarczyłby przy 91 markach, ale jednolitość typów kluczy w całym schemacie jest warta więcej niż 4 bajty na wiersz. |
| `public_id` UUID `DEFAULT gen_random_uuid()` | Tożsamość w API. Domyślna wartość po stronie bazy, więc `ON CONFLICT DO NOTHING` nigdy jej nie zmienia — identyfikator jest stały przez całe życie wiersza. `gen_random_uuid()` jest w rdzeniu PostgreSQL od wersji 13, żadne rozszerzenie nie jest potrzebne. Dlaczego UUID a nie slug — ADR 0004. |
| `source_ref` NOT NULL | Klucz naturalny ze źródła. Nieprzezroczysty ciąg — dla scrapera segment ścieżki `audi`, dla API dostawcy jego identyfikator rekordu. **Nigdy nie wychodzi w odpowiedzi HTTP** (wyciekłby kształt źródła). |
| UNIQUE `source_ref` | Cel `ON CONFLICT` przy pobierz-lub-utwórz oraz jedyny sposób na idempotencję: powtórny przebieg trafia w istniejący wiersz zamiast tworzyć drugi. Obsługuje **Q-L**. Zakres globalny, nie w obrębie rodzica — marka nie ma rodzica. |
| UNIQUE `public_id` | Gwarantuje unikalność identyfikatora publicznego i jest punktem wejścia filtrów `?brandId=` (**Q-B**, **Q-H**). Zmierzone: przy 91 wierszach planista woli tu `Seq Scan` po jednej stronie danych — indeks pozostaje uzasadniony jako **ograniczenie**, nie jako przyspieszenie (§6). |
| `name` NOT NULL | Jedyna rzecz, którą API pokazuje o marce. |
| CHECK `*_not_blank` | Pusty ciąg to nie brak danych, tylko błąd parsera. `NOT NULL` sam tego nie łapie. Naruszenie = błąd programu (zasada 4). |
| `first_seen_run_id` NOT NULL, RESTRICT | Odpowiedź na „z którego przebiegu pochodzi rekord”. Ustawiane tylko przy `INSERT`, nigdy nadpisywane. Razem z `import_run.started_at` i `.source` daje pełne pochodzenie bez własnych kolumn czasu. |
| **brak** `last_seen_run_id` | Świadomie. Aktualizowanie go dla 91 marek przy 27 384 transakcjach oznaczałoby, że każda transakcja bierze blokadę wiersza na jednej z 91 marek — czyli seryjne wykonanie całego importu. Świeżość marki wynika z jej wersji: `SELECT max(v.last_seen_run_id) … `. ADR 0005. |
| **brak** indeksu na `name` | 91 wierszy. Sortowanie 91 wierszy w pamięci to ułamek milisekundy; planista nigdy nie wybrałby indeksu. Indeks byłby kultem cargo. |

### 3.2 `car_model`

```sql
CREATE TABLE car_model (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    public_id         UUID   NOT NULL DEFAULT gen_random_uuid(),
    brand_id          BIGINT NOT NULL REFERENCES brand (id) ON DELETE RESTRICT,
    source_ref        TEXT   NOT NULL,
    name              TEXT   NOT NULL,
    first_seen_run_id BIGINT NOT NULL REFERENCES import_run (id) ON DELETE RESTRICT,

    CONSTRAINT car_model_source_ref_not_blank CHECK (length(btrim(source_ref)) > 0),
    CONSTRAINT car_model_name_not_blank       CHECK (length(btrim(name)) > 0)
);

CREATE UNIQUE INDEX car_model_public_id_key  ON car_model (public_id);
CREATE UNIQUE INDEX car_model_source_ref_key ON car_model (source_ref);
CREATE        INDEX car_model_brand_id_idx   ON car_model (brand_id);
```

| Element | Uzasadnienie |
|---|---|
| FK `brand_id` **ON DELETE RESTRICT** | Skasowanie marki, która ma modele, jest zawsze pomyłką operatora — nie ma przepływu biznesowego, który by tego chciał. Jedyna legalna ścieżka kasowania (§8) idzie od dołu: najpierw wersje, potem osierocone modele, potem osierocone marki. Przy takim porządku `RESTRICT` nigdy nie przeszkadza, a chroni przed `DELETE FROM brand WHERE …` napisanym z ręki. `CASCADE` odrzucone: cicho skasowałoby 1 400 modeli i 32 000 wariantów. |
| UNIQUE `source_ref` **globalnie**, nie `(brand_id, source_ref)` | Reguła całego schematu: **adapter gwarantuje, że `source_ref` jest unikalny w obrębie tabeli**. Scraper spełnia to, budując referencję z prefiksem ścieżki (`audi/a4`), API dostawcy — swoim identyfikatorem rekordu. Zakres globalny daje jeden indeks zamiast dwóch i pozwala odnaleźć wiersz bez znajomości rodzica. Obsługuje **Q-L**. |
| INDEX `brand_id` | Obsługuje **Q-B** (`GET /v1/models?brandId=`) i pierwszy krok **Q-H** (`GET /v1/variants?brandId=`). Bez niego filtr po marce jest skanem 1 400 wierszy — do przeżycia, ale to jedyny indeks, który zarabia na siebie już przy tej skali, bo przecina 1 400 wierszy do ~15. |
| brak `last_seen_run_id` | Jak w `brand`, z tego samego powodu (ADR 0005). |

### 3.3 `version` — strona silnikowa

```sql
CREATE TABLE version (
    id                      BIGINT  GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    public_id               UUID    NOT NULL DEFAULT gen_random_uuid(),
    car_model_id            BIGINT  NOT NULL REFERENCES car_model (id) ON DELETE RESTRICT,
    source_ref              TEXT    NOT NULL,
    name                    TEXT    NOT NULL,
    fuel_type               TEXT    NOT NULL,
    generation              TEXT,
    body_style              TEXT,
    unresolved_segment      TEXT,
    power_hp                NUMERIC(5,1),
    engine_displacement_ccm INTEGER,
    boot_capacity_l         INTEGER,
    first_seen_run_id       BIGINT  NOT NULL REFERENCES import_run (id) ON DELETE RESTRICT,
    last_seen_run_id        BIGINT  NOT NULL REFERENCES import_run (id) ON DELETE RESTRICT,

    CONSTRAINT version_source_ref_not_blank CHECK (length(btrim(source_ref)) > 0),
    CONSTRAINT version_name_not_blank       CHECK (length(btrim(name)) > 0),
    CONSTRAINT version_fuel_type_allowed    CHECK (fuel_type IN ('petrol', 'diesel', 'hybrid', 'electric')),
    CONSTRAINT version_generation_not_blank CHECK (generation IS NULL OR length(btrim(generation)) > 0),
    CONSTRAINT version_body_style_not_blank CHECK (body_style IS NULL OR length(btrim(body_style)) > 0),
    CONSTRAINT version_unresolved_segment_not_blank
        CHECK (unresolved_segment IS NULL OR length(btrim(unresolved_segment)) > 0),
    CONSTRAINT version_unresolved_segment_excludes_resolved
        CHECK (unresolved_segment IS NULL OR (generation IS NULL AND body_style IS NULL)),
    CONSTRAINT version_power_hp_positive    CHECK (power_hp IS NULL OR power_hp > 0),
    CONSTRAINT version_displacement_positive CHECK (engine_displacement_ccm IS NULL OR engine_displacement_ccm > 0),
    CONSTRAINT version_boot_capacity_positive CHECK (boot_capacity_l IS NULL OR boot_capacity_l > 0)
);

CREATE UNIQUE INDEX version_public_id_key    ON version (public_id);
CREATE UNIQUE INDEX version_source_ref_key   ON version (source_ref);
CREATE        INDEX version_car_model_id_idx ON version (car_model_id);
```

| Element | Uzasadnienie |
|---|---|
| UNIQUE `source_ref` globalnie | Dla scrapera referencją jest **pełna ścieżka kanoniczna** (`link[rel=canonical]` po pobraniu), bo sam segment `silnik-*` powtarza się między generacjami tego samego modelu. Ten indeks jest celem `ON CONFLICT` przy upsercie (**Q-M**) **oraz** obsługuje ścieżkę „bez zmian”: warunkowy GET zwraca 304, wtedy jedyne, co robimy, to `UPDATE version SET last_seen_run_id = … WHERE source_ref = …` (**Q-O**). Bez globalnej unikalności ta ścieżka wymagałaby najpierw ustalenia modelu, czyli sparsowania strony, której właśnie nie pobraliśmy. |
| `fuel_type` **NOT NULL** | Jedyne pole, którego źródłem **nie jest DOM, tylko adres** — token w slugu silnika. Słownik jest zamknięty i pokrywa 100% z 27 384 stron, dlatego `NOT NULL` (`docs/source-contract.md` §4). `TEXT` + `CHECK` zamiast typu wyliczeniowego, dla spójności z `gearbox` i `drive` — uzasadnienie w ADR 0006. Konsekwencja wymagalności: nieznany token oznacza, że adapter **nie może zbudować rekordu** i strona zostaje pominięta z logiem, a nie zapisana z fałszywym paliwem. ADR 0016. |
| `generation` NULL, `body_style` NULL, `unresolved_segment` NULL | Trzy kolumny na taksonomię poniżej modelu, wypełniane **wyłącznie według głębokości ścieżki**, bez ani jednej reguły zgadującej: głębokość 5 → `generation` + `body_style` z pozycji segmentów; głębokość 4 → środkowy segment do `unresolved_segment`; głębokość 3 → wszystkie trzy `NULL`. Zmierzono, że breadcrumb **nie etykietuje poziomów** (`docs/source-contract.md` §3.1), więc rozstrzygnięcie przy głębokości 4 jest niemożliwe z samego źródła. Trzecia kolumna sprawia, że **nic nie ginie i nic nie jest zgadywane**: 8 078 segmentów jest zachowanych i da się je rozstrzygnąć później bez ponownego scrapowania. |
| CHECK `unresolved_segment_excludes_resolved` | Zakaz zgadywania wyrażony w bazie: nie da się mieć jednocześnie segmentu nierozstrzygniętego i rozstrzygniętego. Naruszenie oznacza, że parser złamał regułę głębokości — czyli błąd programu (zasada 4). **Świadomie nie dodano** symetrycznego `(generation IS NULL) = (body_style IS NULL)`: wynika on mechanicznie z reguły głębokości, nie koduje żadnej decyzji, a każde dodatkowe ograniczenie to kolejny sposób na utratę całej strony. |
| brak indeksów na `generation` / `body_style` / `unresolved_segment` | Nie są dziś osią filtrowania w API (`docs/api.md` §4). Indeks bez zapytania to defekt. Dodanie filtru później to jedna linia w białej liście i jeden `CREATE INDEX CONCURRENTLY` — bez zmiany kształtu tabeli. |
| brak indeksu na `fuel_type` | Zmierzone, nie założone — patrz §6. Dla wartości nieselektywnych planista nakłada filtr przy uporządkowanym skanie (jak przy `gearbox`), dla selektywnych zmienia kształt planu i kosztuje **4,4 ms**. Indeks skróciłby to do 1,8 ms — poniżej progu rewizji z ADR 0014, więc go nie ma. |
| `power_hp NUMERIC(5,1)` | Źródło podaje `150 KM`, ale wartości ułamkowe występują (pomiar na poprzednim zbiorze: jedna na 27 384). `NUMERIC` je zachowuje bez zaokrąglenia. Zakres do 9 999,9 KM mieści każdy samochód i nie da się go przepełnić literówką w źródle — a przepełnienie typu przerwałoby transakcję i utraciło całą stronę (zasada 4). |
| `engine_displacement_ccm INTEGER` | Wartość całkowita (`1984 cm³`). `INTEGER`, nie `SMALLINT`: `SMALLINT` mieści realne silniki, ale literówka w źródle (`198400`) przepełniłaby typ i wywaliła import strony. Dwa dodatkowe bajty na 22 800 wierszy to 45 kB. |
| `boot_capacity_l INTEGER` | To **minimalna** pojemność (`Minimalna pojemność bagażnika (siedzenia rozłożone)`), zgodnie z wymaganiem. Maksymalna, choć obecna na 38/78 stron, nie jest zapisywana — nie poproszono o nią. |
| CHECK `> 0` na trzech miarach | Zero i wartości ujemne to nie dane, tylko błąd parsera. Adapter mapuje zero na `NULL` i loguje; CHECK jest siatką bezpieczeństwa. **Świadomie brak górnych granic** — górna granica to sąd o wiarygodności danych, a nie niezmiennik; jej naruszenie kosztowałoby całą stronę. Wiarygodność sprawdza adapter. |
| `last_seen_run_id` NOT NULL | Odpowiada na „czy ten wiersz nadal istnieje w źródle”. Aktualizowany przy każdym zapisie i przy 304. Konflikt z `brand`/`car_model` pozorny: wersja jest dotykana przez jedną stronę, więc nie ma rywalizacji o blokadę. |
| brak indeksu na `last_seen_run_id` | Jedyne zapytanie, które go filtruje, to czyszczenie po przebiegu (**Q-P**), uruchamiane raz na dobę na 22 800 wierszach. Skan sekwencyjny jest tam tańszy niż utrzymywanie indeksu przy każdym z 27 384 upsertów. |

### 3.4 `variant` — konfiguracja napędu

```sql
CREATE TABLE variant (
    id                       BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    public_id                UUID         NOT NULL DEFAULT gen_random_uuid(),
    version_id               BIGINT       NOT NULL REFERENCES version (id) ON DELETE CASCADE,
    source_ref               TEXT         NOT NULL,
    acceleration_0_100_s     NUMERIC(4,1) NOT NULL,
    fuel_consumption_l_100km NUMERIC(4,1),
    fuel_tank_l              INTEGER,
    gearbox                  TEXT,
    gearbox_unmapped_label   TEXT,
    drive                    TEXT,
    drive_unmapped_label     TEXT,
    first_seen_run_id        BIGINT       NOT NULL REFERENCES import_run (id) ON DELETE RESTRICT,

    CONSTRAINT variant_source_ref_not_blank
        CHECK (length(btrim(source_ref)) > 0),
    CONSTRAINT variant_acceleration_positive
        CHECK (acceleration_0_100_s > 0),
    CONSTRAINT variant_fuel_consumption_positive
        CHECK (fuel_consumption_l_100km IS NULL OR fuel_consumption_l_100km > 0),
    CONSTRAINT variant_fuel_tank_positive
        CHECK (fuel_tank_l IS NULL OR fuel_tank_l > 0),
    CONSTRAINT variant_gearbox_allowed
        CHECK (gearbox IS NULL OR gearbox IN ('manual', 'automatic')),
    CONSTRAINT variant_drive_allowed
        CHECK (drive IS NULL OR drive IN ('front', 'rear', 'all')),
    CONSTRAINT variant_gearbox_mapped_or_labelled
        CHECK (gearbox IS NULL OR gearbox_unmapped_label IS NULL),
    CONSTRAINT variant_drive_mapped_or_labelled
        CHECK (drive IS NULL OR drive_unmapped_label IS NULL),
    CONSTRAINT variant_gearbox_label_not_blank
        CHECK (gearbox_unmapped_label IS NULL OR length(btrim(gearbox_unmapped_label)) > 0),
    CONSTRAINT variant_drive_label_not_blank
        CHECK (drive_unmapped_label IS NULL OR length(btrim(drive_unmapped_label)) > 0)
);

CREATE UNIQUE INDEX variant_public_id_key             ON variant (public_id);
CREATE UNIQUE INDEX variant_version_id_source_ref_key ON variant (version_id, source_ref);
CREATE        INDEX variant_acceleration_idx          ON variant (acceleration_0_100_s, public_id);
```

| Element | Uzasadnienie |
|---|---|
| FK `version_id` **ON DELETE CASCADE** | Jedyny klucz obcy kaskadujący w schemacie. Wariant bez wersji nie znaczy nic — nie ma marki, modelu ani mocy. Kasowanie wersji ma zabrać wariantów ze sobą; `RESTRICT` zmusiłby czyszczenie do dwóch kroków bez żadnej korzyści. |
| UNIQUE `(version_id, source_ref)` **z zakresem rodzica** | Jedyne odstępstwo od globalnej unikalności `source_ref` w schemacie, i celowe. Kolejność ustalania referencji wariantu to `cid` → `option[data-id]` → **pozycja w dokumencie**; ostatni wariant awaryjny daje `0`, `1`, `2`, które są unikalne wyłącznie w obrębie strony. Zakres rodzica czyni regułę prawdziwą dla wszystkich trzech przypadków. Indeks jest celem `ON CONFLICT` (**Q-N**) i — dzięki prefiksowi `version_id` — obsługuje też filtr `?versionId=` (**Q-F**) oraz kaskadę kasowania. Osobny indeks na `(version_id)` byłby duplikatem i został pominięty. |
| `acceleration_0_100_s` **NOT NULL** | Wymaganie wiążące. To jedyne pole, którego brak dyskwalifikuje wiersz. Egzekwowane w bazie, nie w PHP — wariant bez przyspieszenia po prostu nie ma jak powstać. Odpowiada za 7 z 98 zmierzonych bloków (zaślepki pierwszej opcji `<select>`), które adapter odrzuca, bo typ w porcie jest nienullowalny (`float`, nie `?float`). |
| `NUMERIC(4,1)` | Źródło podaje jedno miejsce po przecinku (`9.3 s`). `NUMERIC` zamiast `REAL`, bo porównania zakresowe w filtrze i kursor stronicowania muszą być dokładne — `REAL` dałby `4.3 > 4.3` w zależności od zaokrąglenia. Zakres do 999,9 s: nie do przepełnienia. |
| `fuel_consumption_l_100km` NULL | Opcjonalne, poziom wariantu (zmierzone). Jednostka w nazwie kolumny, bo kolumna bez jednostki w nazwie jest zaproszeniem do pomyłki. **Adapter przyjmuje wyłącznie `l/100km`**; zużycie energii aut elektrycznych w `kWh/100km` nie trafia tu nigdy — to byłaby cicha korupcja danych. |
| `fuel_tank_l` NULL, INTEGER | Opcjonalne, **poziom wariantu** — wbrew intuicji, ale zmierzone jednoznacznie: 93 wystąpienia na poziomie wariantu, zero na poziomie wersji. |
| `gearbox` / `drive` TEXT + CHECK, **nullowalne** | Słowniki kanoniczne: `manual`/`automatic` oraz `front`/`rear`/`all` (`4x4` → `all`). `NULL` oznacza „źródło nie podało albo podało coś, czego nie znamy”. Zmierzone: skrzynia była na wszystkich 98 blokach, napęd na 91 z 98 — brak napędu jest realny i musi być reprezentowalny. Dlaczego `TEXT` a nie typ wyliczeniowy PostgreSQL — ADR 0006. |
| `*_unmapped_label` NULL | **Bezpośrednia realizacja wymagania „nieznana wartość nie może wywalić importu”.** Etykieta, której adapter nie umiał zmapować, ląduje tutaj, kolumna kanoniczna zostaje `NULL`, transakcja się kończy powodzeniem. Bez tej kolumny nieznana skrzynia byłaby w bazie nieodróżnialna od skrzyni nieopublikowanej przez źródło, a operator nie miałby jak zobaczyć skali problemu (logi na ECS wygasają). W praktyce kolumna jest `NULL` w ~100% wierszy, więc kosztuje bit w mapie nullowalności. Zapytanie raportowe: `SELECT gearbox_unmapped_label, count(*) FROM variant WHERE gearbox_unmapped_label IS NOT NULL GROUP BY 1;` |
| CHECK `*_mapped_or_labelled` | Zabrania stanu „zmapowane **i** niezmapowane naraz”. Trzy legalne stany: obie `NULL` (pole nieobecne), kanoniczna wypełniona (rozpoznane), etykieta wypełniona (nierozpoznane). |
| INDEX `(acceleration_0_100_s, public_id)` | Domyślne i jedyne sortowanie kolekcji wariantów oraz oba filtry zakresowe. Kolumna wiodąca daje uporządkowany skan bez sortowania i wczesne zatrzymanie przy `LIMIT`; `public_id` jest rozstrzygnięciem remisów, które czyni porządek totalnym — to warunek konieczny stronicowania kluczem. **`public_id`, a nie `id`**, bo kursor jest jawnie kodowany i nie wolno mu zawierać klucza z bazy (ADR 0012). Obsługuje **Q-C**, **Q-D**, **Q-E**. |
| brak `last_seen_run_id` | Wyprowadzalny. Warianty są zapisywane komplet-na-stronę: zapis kasuje te, których nie ma w ładunku, i wstawia resztę. Wariant nie może więc być nieaktualny, jeśli jego wersja jest aktualna. |
| brak indeksów na `gearbox` / `drive` | Dwie i trzy wartości na 32 000 wierszy — selektywność 30–65%. Indeks przy takiej selektywności jest wolniejszy od skanu i planista i tak by go pominął. Próg rewizji: rząd wielkości więcej wierszy (~1 mln), i wtedy raczej indeks częściowy niż pełny. |

### 3.5 Niezmiennik: wersja ma co najmniej jeden wariant

Wersja bez wariantu nie ma przyspieszenia, czyli nie ma tego jedynego pola, które jest wymagane —
jest bezużyteczna dla API. Zmierzone: 13 z 78 stron (17%) to takie zaślepki. Reguła jest wyrażona
**w bazie**, bo da się ją tam wyrazić — i musi obowiązywać wobec **każdego** pisarza, także
dwóch naraz.

Sercem mechanizmu jest jedna funkcja, która **najpierw blokuje wiersz rodzica, a dopiero potem
sprawdza**:

```sql
CREATE FUNCTION version_must_keep_variant(p_version_id BIGINT) RETURNS void
LANGUAGE plpgsql AS $$
DECLARE
    v_source_ref TEXT;
BEGIN
    SELECT source_ref INTO v_source_ref
    FROM version WHERE id = p_version_id FOR NO KEY UPDATE;

    IF NOT FOUND THEN
        RETURN;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM variant WHERE version_id = p_version_id) THEN
        RAISE EXCEPTION 'version % has no variant', v_source_ref
            USING ERRCODE = 'integrity_constraint_violation';
    END IF;
END;
$$;
```

Wywołują ją cztery wyzwalacze, zamykające cztery różne drogi do pustej wersji:

```sql
CREATE FUNCTION version_requires_variant() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    PERFORM version_must_keep_variant(NEW.id);
    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER version_requires_variant_check
    AFTER INSERT ON version
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION version_requires_variant();

CREATE FUNCTION variant_keeps_version_populated() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    PERFORM version_must_keep_variant(OLD.version_id);

    IF TG_OP = 'UPDATE' AND NEW.version_id IS DISTINCT FROM OLD.version_id THEN
        PERFORM version_must_keep_variant(NEW.version_id);
    END IF;

    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER variant_delete_keeps_version_populated_check
    AFTER DELETE ON variant
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION variant_keeps_version_populated();

CREATE CONSTRAINT TRIGGER variant_move_keeps_version_populated_check
    AFTER UPDATE OF version_id ON variant
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION variant_keeps_version_populated();

CREATE FUNCTION variant_truncate_keeps_versions_populated() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM version) THEN
        RAISE EXCEPTION 'truncating variant would leave versions without variants'
            USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    RETURN NULL;
END;
$$;

CREATE TRIGGER variant_truncate_keeps_versions_populated_check
    AFTER TRUNCATE ON variant
    FOR EACH STATEMENT EXECUTE FUNCTION variant_truncate_keeps_versions_populated();
```

Dlaczego dokładnie tak:

- **`FOR NO KEY UPDATE` przed sprawdzeniem — to jest cały mechanizm.** Samo `EXISTS` jest
  odczytem bez blokady, więc dwie transakcje kasujące **różne** warianty tej samej wersji
  obie widzą wariant tej drugiej i obie przechodzą. Zmierzone na 2 000 wersjach po dwa warianty,
  dwie równoległe sesje w autocommit: **1 706 z 2 000 wersji zostało bez wariantu.**
  Blokada wiersza rodzica serializuje takich pisarzy; ta sama próba po poprawce daje
  **0 naruszeń i 2 000 błędów, po jednym na wersję** (§12, tura 3).
  Wybrano `FOR NO KEY UPDATE`, a nie `FOR UPDATE`, bo ten pierwszy nie koliduje z `FOR KEY SHARE`,
  które bierze wstawienie wariantu przez klucz obcy. Kasujący blokują się nawzajem, ale nie
  blokują wstawiających — a wstawienie wariantu nie może naruszyć niezmiennika.
- **`READ COMMITTED` jest tu warunkiem poprawności.** Każda instrukcja w PL/pgSQL bierze świeży
  snapshot, więc sprawdzenie wykonane **po** uzyskaniu blokady widzi zatwierdzone kasowanie
  drugiej transakcji. Przy `REPEATABLE READ` blokada by nie wystarczyła.
- **`DEFERRABLE INITIALLY DEFERRED`** — zapis wstawia wersję przed wariantami. Sprawdzenie
  natychmiastowe odrzuciłoby każdy poprawny zapis.
- **Wyzwalacz na `UPDATE OF version_id`** zamyka przeniesienie ostatniego wariantu na inną
  wersję. Bez niego `UPDATE variant SET version_id = …` zostawiał pustą wersję po cichu.
  Sprawdzane są obie strony (`OLD` i `NEW`), choć strona docelowa zawsze przechodzi — koszt
  to jedno `EXISTS`, a reguła nie zależy wtedy od kierunku przeniesienia.
- **Wyzwalacz na `TRUNCATE`** jest zwykły, nie „constraint" — wyzwalacze wierszowe nie strzelają
  przy `TRUNCATE`, a `CREATE CONSTRAINT TRIGGER` nie przyjmuje tego zdarzenia. Wariant `AFTER`
  zamiast `BEFORE` jest celowy: pozwala odróżnić `TRUNCATE variant` (wersje zostają → błąd)
  od `TRUNCATE version CASCADE` i `TRUNCATE version, variant` (wersje też znikają → legalny
  reset). Obie ścieżki zweryfikowane.
- **`NOT FOUND` przepuszcza kaskadę** — gdy kasowana jest sama wersja, wariant ma prawo zniknąć.
- **Komunikat drukuje `source_ref`, nie `id`.** Po wycofaniu transakcji wiersza o tym `id` już
  nie ma, więc identyfikator liczbowy w logu jest bezużyteczny; `source_ref` prowadzi wprost
  do rekordu w źródle.
- **Wyzwalacza na `UPDATE` wersji nie ma.** Aktualizacja `last_seen_run_id` nie dotyka wariantów,
  a sprawdzanie tam kosztowałoby jedno zapytanie na każdą z ~22 800 niezmienionych stron
  w każdym przebiegu.
- **Odrzucone: kolumna licznikowa `variant_count` z `CHECK > 0`.** Licznik utrzymywany
  wyzwalaczem to agregat liczony w tej samej instrukcji, która go zapisuje — wzorzec, który
  zgubił wartość pod współbieżnością w poprzednim podejściu. Sprawdzenie pod blokadą nie ma
  stanu do zgubienia.

Konsekwencja operacyjna, o której trzeba wiedzieć: **`TRUNCATE variant` jest zabroniony**.
Reset danych wykonuje się przez `TRUNCATE version CASCADE` albo `DELETE FROM version`.
Dotyczy to także sprzątania w testach.

## 4. `api_key`

```sql
CREATE TABLE api_key (
    id         BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name       TEXT        NOT NULL,
    key_hash   BYTEA       NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT api_key_name_not_blank  CHECK (length(btrim(name)) > 0),
    CONSTRAINT api_key_hash_is_sha256  CHECK (octet_length(key_hash) = 32)
);

CREATE UNIQUE INDEX api_key_key_hash_key ON api_key (key_hash);
CREATE UNIQUE INDEX api_key_name_key     ON api_key (name);
```

| Element | Uzasadnienie |
|---|---|
| Jedna tabela | Poprzednie podejście miało pod uwierzytelnianie cztery tabele, z czego dwie nigdy nie zapisywane. Wymaganie brzmi „klucz w nagłówku `X-API-Key`” i to jest cały zakres. |
| `key_hash BYTEA`, SHA-256 | Klucz to 32 losowe bajty o pełnej entropii, nie hasło użytkownika. Dobór funkcji spowalniającej (bcrypt, Argon2) chroni przed atakiem słownikowym, którego tu nie ma, a **uniemożliwia wyszukanie po indeksie** — trzeba by porównać żądanie z każdym wierszem. SHA-256 daje wyszukanie `O(log n)` po indeksie unikalnym (**Q-K**). Klucz jawny nie jest przechowywany nigdzie. |
| CHECK `octet_length = 32` | Wyklucza wpisanie do kolumny klucza jawnego albo skrótu innej funkcji. Tani, jednoznaczny niezmiennik. |
| UNIQUE `key_hash` | Konieczne dla poprawności wyszukania (jeden klucz = jeden wiersz) i wyklucza dwukrotne wydanie tego samego sekretu. |
| UNIQUE `name` | Nazwa jest etykietą operacyjną („worker raportowy”). Unikalność chroni przed dwoma kluczami o tej samej nazwie, których nie da się później rozróżnić przy odwoływaniu. |
| `created_at` | Jedyna tabela z własnym znacznikiem czasu, bo jest jedyną, która nie ma `import_run` do oparcia się o nią. Pytanie „kiedy wydano ten klucz” jest realnym pytaniem operacyjnym. |
| **brak** `revoked_at`, `last_used_at`, `scopes`, `expires_at` | Odwołanie klucza to `DELETE`. `last_used_at` oznaczałby zapis przy każdym żądaniu — rywalizacja o wiersz na ścieżce odczytu. Zakresy uprawnień nie mają dziś czego rozdzielać: API jest w całości tylko do odczytu. Wygasanie nie zostało zamówione. |

## 5. `autocentrum_page_cache` — własność adaptera

```sql
CREATE TABLE autocentrum_page_cache (
    url           TEXT PRIMARY KEY,
    etag          TEXT NOT NULL,
    canonical_ref TEXT NOT NULL,

    CONSTRAINT autocentrum_page_cache_etag_not_blank CHECK (length(btrim(etag)) > 0),
    CONSTRAINT autocentrum_page_cache_canonical_ref_not_blank CHECK (length(btrim(canonical_ref)) > 0)
);
```

Ta tabela **nie należy do rdzenia**. Nazwa nosi nazwę adaptera celowo: gdy scraper zostanie
zastąpiony wywołaniem API, tabela znika razem z nim, jedną migracją. Żaden inny moduł jej nie
czyta ani nie pisze, żaden `SELECT` z `Catalog` jej nie dotyka.

| Element | Uzasadnienie |
|---|---|
| PK `url` (naturalny, tekstowy) | Jedyny dostęp to `url → (etag, canonical_ref)`, tuż przed żądaniem (**Q-Q**). Klucz surogatowy dołożyłby drugi indeks bez żadnego zapytania, które by go używał. ~27 384 wiersze. |
| `etag` NOT NULL | Wiersz bez ETagu nie ma po co istnieć — brak wpisu i wpis pusty znaczyłyby to samo. |
| `canonical_ref` NOT NULL | Odwzorowanie adresu z sitemapu na formę kanoniczną, czyli na `version.source_ref`. Bez niego ścieżka „bez zmian” oznacza wersję pod złą nazwą i `markSeen` nie trafia w nic dla 6,2% stron (ADR 0019, `docs/source-contract.md` §6.5). `NOT NULL`, bo wiersz z ETagiem i bez formy kanonicznej jest stanem, który nie ma prawa istnieć: warunkowy GET jest możliwy dopiero po udanym pobraniu, a to samo pobranie daje obie wartości. Zweryfikowane: baza odrzuca wstawienie ETagu bez formy kanonicznej. |
| **brak** UNIQUE na `canonical_ref` | Zbieganie się dwóch adresów z sitemapu do jednej formy kanonicznej to zjawisko, które ta kolumna **rejestruje**, a nie błąd. Zweryfikowane: para zbieżna wchodzi bez przeszkód. |
| brak `fetched_at`, `last_status`, `body_hash` | Wymuszenie pełnego pobrania to `TRUNCATE autocentrum_page_cache` — nie potrzeba do tego kolumny. Treść nie jest przechowywana: pamięć podręczna odpowiedzi to nie jest to samo co pamięć podręczna walidatora. |
| wiersz powstaje **po udanym sparsowaniu**, nie po odpowiedzi HTTP | Forma kanoniczna istnieje dopiero po sparsowaniu, więc zapis musi być po nim. Przy okazji znika druga wada: dziś strona, której nie da się sparsować, dostaje walidator, którego nie zasłużyła, i wraca `304` w kółko (ADR 0019). |
| **ETag nigdy nie przekracza portu** | Rdzeń nie wie, że coś takiego jak ETag istnieje. To jest cały powód, dla którego ta tabela nie ma kolumny w `version` — w poprzednim podejściu miała i została wytknięta jako wyciek HTTP do rdzenia. |

## 5a. `variant_search` i `search_term` — indeks wyszukiwarki (`Catalog`)

Dwa widoki materializowane obsługujące `GET /v1/search`. Nie są tabelami i nie są zmapowane
encjami — DBAL ich nie introspekuje, więc nie pojawiają się w porównaniu schematu z §12 i nie
wymagają wpisu w `schema_filter`. Sprawdzone: `doctrine:schema:update --dump-sql` po ich dodaniu
zwraca te same pięć instrukcji `GENERATED BY DEFAULT AS IDENTITY`, co wcześniej.

```sql
CREATE MATERIALIZED VIEW variant_search AS
SELECT va.public_id AS variant_public_id,
       b.name AS brand_name, cm.name AS model_name, ve.name AS version_name,
       ve.fuel_type, ve.generation, ve.body_style, ve.unresolved_segment,
       va.gearbox, va.drive,
       to_tsvector('simple', concat_ws(' ', b.name, cm.name, ve.generation,
           ve.body_style, ve.unresolved_segment, ve.name)) AS document
FROM variant va
JOIN version ve   ON ve.id = va.version_id
JOIN car_model cm ON cm.id = ve.car_model_id
JOIN brand b      ON b.id = cm.brand_id;

CREATE MATERIALIZED VIEW search_term AS
SELECT DISTINCT lower(word) AS term
FROM ( … regexp_split_to_table po nazwach marek, modeli, wersji i taksonomii … ) words
WHERE length(word) >= 3;
```

| Obiekt | Rola |
|---|---|
| `variant_search_variant_public_id_key` | unikalny — **wymagany przez `REFRESH … CONCURRENTLY`**, bez niego odświeżenie blokuje odczyty |
| `variant_search_document_idx` | GIN po `tsvector` — obsługuje Q-U |
| `search_term_term_key` | unikalny — jw., plus deduplikacja słownika |
| `search_term_trgm_idx` | GIN `gin_trgm_ops` — obsługuje **oba** predykaty Q-V: `LIKE token || '%'` i operator `%` |

Rzeczy, które trzeba wiedzieć:

1. **Słownik `simple`, nie `polish`.** Etykiety to nazwy własne i oznaczenia w rodzaju
   `2.0 TDI 140KM`. Stemmer polski obciąłby je i zepsuł dopasowanie przedrostkiem.
2. **`gearbox_unmapped_label` i `drive_unmapped_label` nie wchodzą do widoku.** To polski tekst
   ze źródła; wariant z nierozpoznaną etykietą pokazuje się klientowi jako `null` — dokładnie
   jak w `GET /v1/variants`.
3. **Widoki są nieaktualne między przebiegami importu.** To jest poprawne: źródło jest scrapowane
   raz na dobę. Odświeża je `app:search:refresh`, osobny krok runbooka — `Import` nie może go
   wywołać, bo `Import → Catalog` jest zależnością zakazaną (`docs/architecture.md` §2).
4. **`REFRESH … CONCURRENTLY` nie działa w transakcji** i wymaga widoku już zapełnionego.
   Migracja tworzy oba `WITH DATA`, więc pierwszy `REFRESH` ma na czym pracować.
5. **Próg podobieństwa jest ustawiany jawnie przy każdym wywołaniu korekty**
   (`SET pg_trgm.similarity_threshold`), a zapytanie niesie **dodatkowo** predykat
   `similarity(...) >= 0.3`. Operator `%` daje indeks, predykat daje niezależność od konfiguracji
   serwera. Usunięcie operatora kosztuje 32× czasu, usunięcie predykatu — determinizm (ADR 0020).

## 6. Zapytanie → indeks (kompletna lista)

Każdy indeks w schemacie musi wystąpić w tej tabeli. Indeks, którego tu nie ma, jest do usunięcia.

### Odczyt (`Catalog`)

Kolumna „zmierzony plan” pochodzi z `EXPLAIN (ANALYZE, BUFFERS)` na **żywym PostgreSQL 17.11**
z danymi w skali docelowej (91 marek, 1 400 modeli, 22 800 wersji, 27 360 wariantów, po `ANALYZE`).
To nie są przewidywania.

Uwaga o dwóch różnych liczbach wariantów, żeby nikt ich nie mylił: **~32 000 to szacunek
produkcyjny** (`docs/source-contract.md` §7), **27 360 to zestaw testowy**, na którym mierzono
plany. Różnica bierze się z przyjętego w zestawie współczynnika wariantów na wersję i nie zmienia
kształtu żadnego planu — przy obu wielkościach planista podejmuje te same decyzje.

| # | Zapytanie | Indeks | Zmierzony plan |
|---|---|---|---|
| Q-A | `GET /v1/brands` — `SELECT … FROM brand ORDER BY name, public_id LIMIT n` | **brak, celowo** | `Seq Scan` + `quicksort` na 91 wierszach, **0,08 ms** |
| Q-B | `GET /v1/models?brandId=X` — `… FROM car_model m JOIN brand b ON b.id = m.brand_id WHERE b.public_id = :x ORDER BY m.name, m.public_id` | `car_model_brand_id_idx` | `Bitmap Index Scan` na `car_model_brand_id_idx` (15 wierszy) + `quicksort`, **0,37 ms** |
| Q-C | `GET /v1/variants` bez filtrów — `… FROM variant va JOIN version … ORDER BY va.acceleration_0_100_s, va.public_id LIMIT n` | `variant_acceleration_idx` | `Index Scan` uporządkowany, **bez węzła `Sort`**, trzy złączenia po PK z memoizacją, **0,87 ms** |
| Q-D | `…&accelerationMax=6` — `WHERE va.acceleration_0_100_s <= 6` | `variant_acceleration_idx` | `Index Only Scan`, `Index Cond: acceleration_0_100_s <= 6`, **0,04 ms** |
| Q-E | `…&accelerationMin=3` | `variant_acceleration_idx` | jw., skan zakresowy |
| Q-F | `…&versionId=X` | `version_public_id_key` → `variant_version_id_source_ref_key` (prefiks `version_id`) | `Index Scan` po prefiksie |
| Q-G | `…&modelId=X` | `car_model_public_id_key` → `version_car_model_id_idx` → `variant_version_id_source_ref_key` | jak Q-H, o jeden poziom krócej |
| Q-H | `…&brandId=X` | `car_model_brand_id_idx` → `version_car_model_id_idx` → `variant_version_id_source_ref_key` | pełny łańcuch trzech `Index Scan` + `top-N heapsort` na 292 wierszach, **1,79 ms** |
| Q-I | `…&gearbox=manual`, `…&drive=all` | **brak, celowo** | `Filter: gearbox = 'manual'` nałożony na skan `variant_acceleration_idx`, **0,03 ms** — dokładnie jak zakładano |
| Q-S | `…&fuelType=petrol` (57% wierszy) | **brak, celowo** | `Filter: fuel_type = 'petrol'` na zmemoizowanym odczycie `version` przy uporządkowanym skanie `variant_acceleration_idx` — przeczytano 91 wariantów, żeby wypełnić stronę 51, **0,44 ms** |
| Q-S′ | `…&fuelType=electric` (1,3% wierszy) | **brak, celowo** | planista zmienia kształt planu: `Hash Join` + `Seq Scan` po `variant` + `top-N heapsort`, **4,4 ms**. Z indeksem `version (fuel_type)` byłoby 1,8 ms — patrz uzasadnienie pod tabelą |
| Q-J | `GET /v1/variants/{id}` — od ADR 0018 jedno zapytanie ORM ze złączeniem `variant → version → car_model → brand` | `variant_public_id_key`, dalej trzy klucze główne | `Index Scan` po `variant_public_id_key` + trzy `Index Scan` po PK w pętlach zagnieżdżonych, 12 stron, **0,20 ms** (zmierzone na żywej bazie `cars` przy 1 388 wersjach; kształt planu nie zależy od skali — wszystkie cztery dostępy są po indeksach unikalnych) |
| Q-U | `GET /v1/search?q=…` — `… FROM variant_search, to_tsquery('simple', :q) tsq WHERE document @@ tsq ORDER BY ts_rank(…) DESC, … LIMIT n` | `variant_search_document_idx` | `Bitmap Index Scan` po GIN + `top-N heapsort`. Mediana z 9 przebiegów przy `LIMIT 10` (domyślnym): **1,91 ms** przy 26 221 wierszach i 1 716 trafieniach (min 1,76, max 2,67); **0,27 ms** na żywej bazie `cars` przy 2 017 wariantach (min 0,19, max 0,77). Wielkość `LIMIT` nie zmienia kosztu w sposób mierzalny — płaci się za zbiór kandydatów, nie za `heapsort` |
| Q-V | korekta literówek — dla tokenów niebędących przedrostkiem żadnego znanego słowa, najlepszy termin ze słownika | `search_term_trgm_idx` (oba predykaty) | `Bitmap Index Scan` po `term % :token` (26 kandydatów zamiast 12 202) + `Anti Join` po `LIKE`, **1,23 ms** przy 12 202 terminach. Bez operatora `%`, na samym `similarity() >= 0.3`: `Seq Scan`, **39,36 ms** |
| Q-W | `app:search:refresh` — `REFRESH MATERIALIZED VIEW CONCURRENTLY` obu widoków | `variant_search_variant_public_id_key`, `search_term_term_key` | **261 ms** przy 23 596 wierszach, raz na przebieg importu. `CONCURRENTLY` wymaga indeksu unikalnego i nie działa w transakcji |
| Q-K | uwierzytelnienie — `SELECT 1 FROM api_key WHERE key_hash = :h` | `api_key_key_hash_key` | `Index Scan` po indeksie unikalnym |
| Q-T | wydanie klucza — `INSERT INTO api_key … ` (kolizja nazwy) | `api_key_name_key` | ograniczenie, nie przyspieszenie; kilka wierszy |

Stronicowanie kluczem dokłada do Q-C…Q-I predykat `(va.acceleration_0_100_s, va.public_id) > (:a, :p)`
(przy sortowaniu malejącym `<`). Zmierzone: planista zamienia to na
`Index Cond: ROW(acceleration_0_100_s, public_id) > ROW(…)` na `variant_acceleration_idx` —
prawdziwe wyszukanie w indeksie, nie filtr — **0,31 ms**.

**Dlaczego nie ma indeksu na `fuel_type`, skoro pomiar pokazuje różnicę.** Bo różnica jest
poniżej progu, który sami ogłosiliśmy. `fuelType=electric` to najgorszy przypadek w całym API
i kosztuje **4,4 ms**; indeks skróciłby go do 1,8 ms. ADR 0014 mówi, że indeks dokładamy przy
medianie powyżej 50 ms albo przy rzędzie wielkości więcej wierszy — i ta reguła obowiązuje także
wtedy, gdy akurat kusi ją złamać. Dwa fakty, które trzeba znać przy rewizji:

1. Indeks na `version (fuel_type)` usuwa `Seq Scan` **tylko po `version`** (22 800 wierszy).
   `Seq Scan` po `variant` zostaje w obu planach, więc oszczędność jest częściowa.
2. Lepszy plan dla wartości selektywnych już jest dostępny — przez `version_fuel_type_idx`
   do `variant_version_id_source_ref_key` — i planista sięgnie po niego sam, gdy proporcje się
   zmienią. Nie trzeba mu w tym pomagać z wyprzedzeniem.

**Uwaga o `brand_public_id_key` i `car_model_public_id_key`.** Przy 91 i 1 400 wierszach planista
dla filtru `?brandId=` wybiera `Seq Scan` na `brand` (jedna strona danych), a nie indeks — i ma
rację. Te indeksy istnieją więc przede wszystkim **jako ograniczenie unikalności identyfikatora
publicznego**, a rolę wyszukiwawczą pełnią dopiero `car_model_brand_id_idx` i dalsze ogniwa
łańcucha. Nie jest to defekt: indeks unikalny na `public_id` jest wymagany przez poprawność,
niezależnie od tego, czy planista go wybierze. Dla `version` i `variant` (22 800 i 27 360 wierszy)
planista wybiera je normalnie — potwierdzone w Q-J.

### Zapis (`Import`)

| # | Zapytanie | Indeks |
|---|---|---|
| Q-L | pobierz-lub-utwórz markę/model (**DBAL**) — `INSERT … ON CONFLICT (source_ref) DO NOTHING`, potem `SELECT id … WHERE source_ref = :r` | `brand_source_ref_key`, `car_model_source_ref_key` |
| Q-M | odszukanie wersji po kluczu naturalnym (**ORM**) — `SELECT … FROM version WHERE source_ref = :r`, potem `INSERT` albo `UPDATE … WHERE id = :id` | `version_source_ref_key` (wyszukanie), `version_pkey` (zapis). Zmierzone na żywej bazie: `Index Scan using version_source_ref_key`, **0,088 ms** |
| Q-N | wczytanie kompletu wariantów wersji (**ORM**) — `SELECT … FROM variant WHERE version_id = :v`, potem `INSERT` / `UPDATE … WHERE id = :id` / `DELETE … WHERE id = :id` | `variant_version_id_source_ref_key` po prefiksie `version_id`. Zmierzone: `Index Scan`, **0,037 ms**. Zbiorczy `DELETE … WHERE source_ref <> ALL(:refs)` już nie istnieje — komplet-na-stronę realizuje `orphanRemoval`, kasując po kluczu głównym (ADR 0018) |
| Q-O | strona bez zmian (304) — `UPDATE version SET last_seen_run_id = :r WHERE source_ref = :ref` | `version_source_ref_key`. **`:ref` to forma kanoniczna z Q-Q, nigdy adres z wiadomości** — inaczej `UPDATE` nie trafia w nic dla 6,2% stron (ADR 0019) |
| Q-P | czyszczenie po przebiegu — `DELETE FROM version WHERE last_seen_run_id <> :r` | **brak, celowo** — raz na dobę, 22 800 wierszy. Zmierzone: `Seq Scan` po całej tabeli, **3,15 ms**. Utrzymanie indeksu kosztowałoby 27 384 wstawienia do B-drzewa na przebieg, żeby zaoszczędzić trzy milisekundy raz na dobę |
| Q-P2 | czyszczenie, krok 2 — `DELETE FROM car_model m WHERE NOT EXISTS (…)` | **brak, celowo** — 1 400 wierszy, ręczna procedura operatorska (§8) |
| Q-P3 | czyszczenie, krok 3 — `DELETE FROM brand b WHERE NOT EXISTS (…)` | **brak, celowo** — 91 wierszy, jw. |
| Q-Q | ETag i forma kanoniczna — `SELECT etag, canonical_ref FROM autocentrum_page_cache WHERE url = :u` | PK `autocentrum_page_cache`. Jeden odczyt, obie wartości — brak drugiego zapytania i brak okna, w którym wiersz mógłby zniknąć między nimi |
| Q-R | jeden trwający przebieg na źródło — wymuszenie przy `INSERT import_run` | `import_run_one_running_per_source` |

Indeksy unikalne na `public_id` (`brand`, `car_model`, `version`, `variant`) są jednocześnie
ograniczeniem (identyfikator publiczny musi być unikalny) i mechanizmem wyszukiwania w Q-B, Q-F,
Q-G, Q-H, Q-J.

## 7. Idempotencja importu

Powtórny przebieg **nie tworzy duplikatów** i nie zmienia identyfikatorów publicznych. Mechanizm:

```sql
-- 1. marka i model: pobierz-lub-utwórz, BEZ aktualizacji (patrz niżej)
INSERT INTO brand (source_ref, name, first_seen_run_id)
VALUES (:ref, :name, :run)
ON CONFLICT (source_ref) DO NOTHING
RETURNING id;
-- gdy nic nie wróciło:
SELECT id FROM brand WHERE source_ref = :ref;

-- 2. wersja: odszukanie po kluczu naturalnym, potem INSERT albo UPDATE po kluczu głównym (ORM)
SELECT … FROM version WHERE source_ref = :ref;
-- brak wiersza  → INSERT INTO version (…) VALUES (…)
-- wiersz istnieje → UPDATE version SET <tylko zmienione kolumny> WHERE id = :id

-- 3. warianty: komplet-na-stronę, dopasowanie po (version_id, source_ref) (ORM)
SELECT … FROM variant WHERE version_id = :v;
-- ref z ładunku, którego nie ma w bazie → INSERT
-- ref w obu                            → UPDATE … WHERE id = :id
-- ref w bazie, którego nie ma w ładunku → DELETE … WHERE id = :id
```

Reguły, których **nie wolno** złamać:

- `public_id` nigdy nie jest aktualizowane. Identyfikator publiczny jest nadawany raz — dla
  `brand` i `car_model` przez `DEFAULT gen_random_uuid()` (kolumna nie występuje w `INSERT`),
  dla `version` i `variant` w konstruktorze encji, właściwością `readonly` i `updatable: false`.
- `first_seen_run_id` nigdy nie jest aktualizowane. Inaczej „pierwszy raz widziany”
  znaczyłoby „ostatni raz widziany”. W DBAL-owym `DO UPDATE` kolumna po prostu nie występuje;
  w ORM właściwość jest `readonly` i nie ma settera.
- Warianty są **dopasowywane po `source_ref`**, nie kasowane i wstawiane na nowo. Skasowanie
  całego kompletu i ponowne wstawienie obraca `public_id` przy każdym przebiegu, a przy kolejności
  instrukcji, którą wybiera ORM (wstawienia przed kasowaniami), po prostu się nie udaje —
  zmierzone, ADR 0018.
- Cała trójka (marka, model, wersja + warianty) idzie w **jednej transakcji**. Częściowo
  zapisana strona nie jest stanem, który ma prawo istnieć — a odroczony wyzwalacz z §3.5 i tak
  by ją odrzucił. `EntityManager::flush()` wywołany wewnątrz otwartej transakcji DBAL zakłada
  `SAVEPOINT`, a nie drugą transakcję (zmierzone, §13).

**Cena po stronie współbieżności, której wcześniej nie było.** `ON CONFLICT (source_ref) DO UPDATE`
na `version` rozstrzygał kolizję wewnątrz jednej instrukcji: druga transakcja czekała na blokadę
i wygrywała. Odczyt-potem-zapis przez ORM ma między tymi krokami okno, więc dwie wiadomości
dotyczące tej samej strony (280 liści w `cars.xml` zbiega się po 301) kończą się
`SQLSTATE 23505` na `version_source_ref_key` i wycofaniem całej strony. Wiadomość jest ponawiana
przez Messengera; ponowienie zastaje wersję zapisaną przez zwycięzcę i wchodzi w ścieżkę
aktualizacji, więc kosztuje **jedno warunkowe żądanie GET** — walidator `ETag` przeżył wycofanie,
bo jest zapisywany poza transakcją strony, a `markSeen` trafia w wiersz, bo szuka po formie
kanonicznej (ADR 0019; bez niej to zdanie było fałszywe właśnie dla par zbieżnych). Wyścig
dotyczy wyłącznie pierwszego przebiegu takiej pary. Zmierzone i przyjęte — ADR 0018.

**Dlaczego marka i model używają `DO NOTHING`, a nie `DO UPDATE`:**
`ON CONFLICT DO UPDATE` bierze blokadę na kolidującym wierszu **zawsze**, nawet gdy klauzula
`WHERE` wykluczy aktualizację. Przy 91 markach i 27 384 stronach oznaczałoby to, że każda
transakcja importu czeka na jedną z 91 blokad. `ON CONFLICT DO NOTHING` blokady nie bierze.

To nie jest zapas na przyszłość: import chodzi **lokalnie na czterech workerach**
(`docs/architecture.md` §5, ADR 0015), więc ta własność jest używana na co dzień. Na ECS
obowiązuje jeden worker, bo tam limiter jest per zadanie — ale schemat ma być poprawny w obu
konfiguracjach, a nie w tej akurat uruchomionej.

Cena po drugiej stronie: zmiana nazwy marki w źródle nie jest podchwytywana automatycznie.
Przy 91 markach jest to zdarzenie na lata, do naprawy jednym `UPDATE`. Szczegóły i odrzucone
alternatywy w ADR 0005.

**Ścieżka „bez zmian” (HTTP 304).** Adapter zwraca `Unchanged`, zapis wykonuje wyłącznie Q-O.
Warianty nie są dotykane — są aktualne z definicji, skoro strona się nie zmieniła.

## 8. Wykrywanie i usuwanie nieaktualnych wierszy

W schemacie **nie ma kolumny `retired_at` ani miękkiego kasowania**. Nie zamówiono go, a
wprowadza dwa stany widoczności do każdego zapytania odczytu.

Nieaktualność jest wyprowadzalna: wiersz `version`, którego `last_seen_run_id` nie wskazuje na
ostatni zakończony przebieg, nie istnieje już w źródle. Czyszczenie jest **operacją operatorską**,
a nie automatyczną częścią importu — kasowanie danych na podstawie przebiegu, który mógł się
wywalić w połowie, byłoby groźne.

```sql
-- ostatni zakończony powodzeniem przebieg
WITH last_run AS (
    SELECT max(id) AS id FROM import_run
    WHERE source = :source AND status = 'completed'
)
-- 1. wersje (kaskaduje na warianty)
DELETE FROM version WHERE last_seen_run_id < (SELECT id FROM last_run);
-- 2. osierocone modele
DELETE FROM car_model m WHERE NOT EXISTS (SELECT 1 FROM version v WHERE v.car_model_id = m.id);
-- 3. osierocone marki
DELETE FROM brand b WHERE NOT EXISTS (SELECT 1 FROM car_model m WHERE m.brand_id = b.id);
```

Kolejność jest wymuszona przez `ON DELETE RESTRICT` na `version.car_model_id` i
`car_model.brand_id` — od dołu do góry. Gdyby ktoś odwrócił kolejność, baza go zatrzyma.
To jest dokładnie ta ochrona, dla której te klucze nie są `CASCADE`.

**Czyszczenie rozjeżdża rdzeń z pamięcią podręczną adaptera.** Skasowane wersje znikają
z bazy, ale walidator `ETag` przeżywa w `autocentrum_page_cache`, więc kolejny przebieg dostanie
`304` i nie odtworzy wierszy. Nie polegamy na tym, że operator o tym pamięta — zapis wykrywa
rozjazd sam i wymusza pełne pobranie (ADR 0017). Zalecane mimo to, jako tańsze:
po czyszczeniu wykonać `TRUNCATE autocentrum_page_cache`, żeby rozjazd nie wystąpił w ogóle.

## 9. Bezpieczne migracje na zapełnionej tabeli

Tabele są małe (≤ 32 000 wierszy), więc większość operacji przejdzie tu w milisekundach. Reguły
poniżej obowiązują mimo to — kosztują jedną linię, a chronią przed sytuacją, w której baza
urośnie i ta sama migracja zatrzyma produkcję.

**Zasada zerowa: każda migracja zaczyna się od limitu blokady.**

```sql
SET lock_timeout = '3s';
SET statement_timeout = '5min';
```

`ALTER TABLE` czeka w kolejce na `ACCESS EXCLUSIVE` i **blokuje za sobą wszystkie odczyty**,
także te, które nie potrzebują tej blokady. Bez `lock_timeout` jedno długie zapytanie zatrzymuje
całe API. Z nim migracja odpada po trzech sekundach i jest powtarzana.

| Operacja | Bezpieczny sposób |
|---|---|
| Nowa kolumna nullowalna | `ALTER TABLE … ADD COLUMN … NULL` — od PostgreSQL 11 to zmiana samych metadanych, bez przepisania tabeli. |
| Nowa kolumna z wartością domyślną | To samo, o ile domyślna jest niezmienna (`ADD COLUMN x INTEGER DEFAULT 0`). `DEFAULT now()` wymusza przepisanie — dodaj kolumnę bez domyślnej, wypełnij partiami, potem `SET DEFAULT`. |
| Nowe `CHECK` | Dwa kroki: `ADD CONSTRAINT … CHECK (…) NOT VALID;` (bierze `ACCESS EXCLUSIVE` na moment, nie skanuje), potem osobno `VALIDATE CONSTRAINT …;` (bierze `SHARE UPDATE EXCLUSIVE`, nie blokuje odczytów ani zapisów). |
| Rozszerzenie słownika (`gearbox`, `drive`, `status`) | Dokładnie jak wyżej: `DROP CONSTRAINT` starego `CHECK` i `ADD CONSTRAINT … NOT VALID` + `VALIDATE`. Gdyby to był typ wyliczeniowy PostgreSQL, byłoby to `ALTER TYPE … ADD VALUE`, którego nowej wartości nie wolno użyć w tej samej transakcji, plus własny typ DBAL — ADR 0006. |
| Nowy indeks | `CREATE INDEX CONCURRENTLY` — **nie wolno w transakcji**. W migracji Doctrine: `public function isTransactional(): bool { return false; }`. Po niepowodzeniu zostaje indeks `INVALID` — sprawdź `pg_index.indisvalid`, usuń `DROP INDEX CONCURRENTLY` i powtórz. |
| Usunięcie indeksu | `DROP INDEX CONCURRENTLY`, ta sama reguła o transakcji. |
| `NOT NULL` na istniejącej kolumnie | Trzy kroki: `ADD CONSTRAINT tmp CHECK (col IS NOT NULL) NOT VALID` → `VALIDATE CONSTRAINT tmp` → `ALTER COLUMN col SET NOT NULL` (od PostgreSQL 12 korzysta ze zwalidowanego `CHECK` i pomija skan) → `DROP CONSTRAINT tmp`. |
| Zmiana typu kolumny | Przepisuje tabelę i blokuje ją na cały czas. Zamiast tego: nowa kolumna, podwójny zapis, przepisanie partiami, zamiana nazw, usunięcie starej. |
| Usunięcie / zmiana nazwy kolumny | Nigdy w jednym wdrożeniu. Rozszerz-i-zwęź: (1) wdroż kod, który nie czyta kolumny, (2) osobnym wdrożeniem `DROP COLUMN` (zmiana metadanych). W przeciwnym razie stara wersja zadania ECS, jeszcze obsługująca ruch, wysypie się na brakującej kolumnie. |

**Uruchamianie na produkcji:**

1. Migracja to **osobne zadanie ECS**, uruchamiane raz, przed przełączeniem ruchu na nową
   wersję. Nigdy przy starcie kontenera — trzy zadania startujące równolegle wyścigowałyby się
   o tę samą migrację.
2. Kolejność wdrożenia zawsze taka, żeby schemat był zgodny **z obiema** wersjami kodu przez
   czas wdrożenia (kod stary i nowy działają obok siebie).
3. Migracja, która nie jest odwracalna bez utraty danych, ma `down()` rzucający wyjątek zamiast
   udawać, że cofnie. Wycofanie takiej zmiany to przywrócenie z kopii.
4. Wyzwalacze ograniczeń z §3.5 są tworzone i kasowane razem z tabelami, których dotyczą;
   `CREATE OR REPLACE FUNCTION` jest bezpieczne w transakcji.

## 10. Rozszerzalność bez EAV

Nie ma tabeli atrybutów typu klucz-wartość i nie będzie. Poprzednie podejście miało katalog
atrybutów, tabelę definicji, tabelę aliasów etykiet i rejestr kolumn — cztery mechanizmy, których
jedynym zadaniem było odwzorowanie polskiej etykiety na kolumnę. Recenzja wykazała, że ten
łańcuch potrafi po cichu nie zapisać pola oznaczonego jako `NOT NULL` i zakończyć przebieg
statusem „powodzenie” z zerem wierszy.

Rozszerzalność wynika tu z kształtu schematu: cztery poziomy taksonomii, każdy z własnym
miejscem na atrybuty tego poziomu. Dodanie pola to zawsze te same trzy kroki. Przykład —
maksymalna pojemność bagażnika (obecna na 38 z 78 stron, dziś świadomie niezapisywana):

1. `migrations/Version…php`:
   `ALTER TABLE version ADD COLUMN max_boot_capacity_l INTEGER;`
   `ALTER TABLE version ADD CONSTRAINT version_max_boot_positive CHECK (max_boot_capacity_l IS NULL OR max_boot_capacity_l > 0) NOT VALID;` + `VALIDATE`.
2. `src/Source/Contract/` — nowe pole w DTO ładunku; `src/Source/Autocentrum/Parser/` — mapowanie
   etykiety `Maksymalna pojemność bagażnika (siedzenia złożone)`.
3. `src/Import/Writer/` — kolumna w `INSERT` i w `DO UPDATE`; `src/Catalog/` + `src/Api/Response/` —
   pole w odpowiedzi.

Żadna istniejąca tabela nie jest przepisywana, żaden istniejący wiersz nie jest dotykany, stary
kod działa dalej (kolumna jest nullowalna). Filtr po nowym polu to dodatkowo jedna pozycja na
białej liście i jeden `CREATE INDEX CONCURRENTLY`.

Granica: gdyby kiedyś doszło **kilkadziesiąt** pól opcjonalnych, o różnym pokryciu, których API
nie filtruje, właściwą odpowiedzią jest jedna kolumna `JSONB` na tym poziomie taksonomii,
a nie tabela EAV. Dziś takiej potrzeby nie ma i kolumny `JSONB` w schemacie nie ma.

## 11. Świadome braki

| Czego nie ma | Dlaczego |
|---|---|
| `created_at` / `updated_at` w taksonomii | Wyprowadzalne przez `first_seen_run_id → import_run.started_at`. Dwie kolumny × 4 tabele bez jednego zapytania, które by je czytało. ADR 0010. |
| `retired_at` / miękkie kasowanie | §8. Nie zamówiono; dokłada predykat do każdego zapytania odczytu. |
| tabela źródeł danych | Wymaganie brzmi „podmienić”, nie „uruchomić dwa naraz”. Nazwa źródła jest w `import_run.source`. |
| tabela zadań do pobrania | ADR 0009. Kolejka jest jedynym stanem „co zostało”. |
| tabela odrzuceń | ADR 0009. Log strukturalny odpowiada na to samo pytanie i nie rośnie w bazie. |
| maksymalna pojemność bagażnika | Nie zamówiono. §10 pokazuje koszt dodania. |
| liczba biegów, norma emisji, `Euro 5` z etykiety wariantu | Nie zamówiono. |
| indeksy na `gearbox`, `drive`, `generation`, `body_style`, `power_hp` | Brak zapytania albo zła selektywność. §6. |

**Pytania otwarte** — brak. Cztery pytania z pierwszej wersji projektu zostały rozstrzygnięte
przez właściciela:

1. **Rodzaj paliwa — dodany**, `NOT NULL` na poziomie wersji, wyprowadzany z adresu (ADR 0016).
2. **Segment przy głębokości 4 — trzecia kolumna** `unresolved_segment`, bez klasyfikatora.
   Pomiar breadcrumbu okazał się rozstrzygający i negatywny: breadcrumb nie etykietuje poziomów
   (`docs/source-contract.md` §3.1), więc rozstrzygnięcie z samego źródła jest niemożliwe.
3. **Doctrine ORM z encjami, DBAL do zapytań złożonych** — decyzja zmieniona 2026-09-03
   (`CLAUDE.md` §9, ADR 0018). Poprzednio obowiązywało „tylko DBAL, bez encji". Schemat pozostaje
   źródłem prawdy; mapowanie ma pasować do niego, nie odwrotnie — §13.
4. **Liczba workerów importu wynika z zasięgu limitera** — lokalnie 4, na ECS 1 (ADR 0015).

## 12. Weryfikacja schematu na żywej bazie

Cały schemat z `migrations/` został zastosowany i przetestowany na **PostgreSQL 17.11**
(kontener `postgres:17-alpine`, osobna baza robocza, usunięta po weryfikacji). Twierdzenia
z tego dokumentu nie są przewidywaniami.

### Tura 1 — niezmienniki i idempotencja

| Test | Oczekiwane | Wynik |
|---|---|---|
| Schemat stosuje się bez błędów | — | OK (7 tabel, 2 funkcje, 2 wyzwalacze ograniczeń) |
| Wersja bez wariantu | odrzucona przy `COMMIT` | `ERROR: version 1 has no variant`; **cała transakcja wycofana**, marka i model też nie powstały |
| Poprawna strona: wersja przed wariantami w jednej transakcji | przechodzi | OK — odroczenie działa, kolejność instrukcji nie ma znaczenia |
| Nieznana etykieta napędu (`na cztery koła i pół`) | **nie przerywa importu** | OK — wiersz powstał, `drive IS NULL`, `drive_unmapped_label` wypełniona |
| Zapis kanonicznej i niezmapowanej etykiety naraz | odrzucony | `ERROR: … violates check constraint "variant_drive_mapped_or_labelled"` |
| Usunięcie **wszystkich** wariantów wersji | odrzucone | `ERROR: version 2 would be left without a variant`, wycofane |
| Usunięcie jednego z dwóch wariantów | przechodzi | OK |
| Usunięcie wersji | kaskaduje, **nie jest blokowane** przez wyzwalacz | OK — wersji 0, wariantów 0 |
| `DELETE FROM brand` przy istniejących modelach | odrzucone przez `RESTRICT` | `ERROR: … violates foreign key constraint "car_model_brand_id_fkey"` |
| Czyszczenie od dołu do góry (wersje → modele → marki) | przechodzi | OK |
| Drugi trwający przebieg tego samego źródła | odrzucony | `ERROR: duplicate key … "import_run_one_running_per_source"` |
| Przebieg `completed` bez `finished_at` | odrzucony | `ERROR: … "import_run_finished_at_matches_status"` |
| **Idempotencja**: powtórzenie tego samego ładunku | brak duplikatów, stabilne identyfikatory | 1 marka / 1 model / 1 wersja / 1 wariant; `public_id` **niezmienione**, `first_seen_run_id` **niezmienione**, `last_seen_run_id` zaktualizowane, moc i przyspieszenie zaktualizowane, zniknięty wariant usunięty |
| Wstawienie 22 800 wersji i 27 360 wariantów w **jednej** transakcji | odroczony wyzwalacz sprawdza wszystkie przy `COMMIT` | OK — mechanizm skaluje się do pełnego importu |

### Tura 2 — `fuel_type` i `unresolved_segment`

Świeża baza robocza, dane w skali docelowej z **realnym rozkładem paliw i głębokości ścieżek**
(22 800 wersji: 14 788 o głębokości 5, 6 648 o głębokości 4, 1 364 o głębokości 3).

| Test | Oczekiwane | Wynik |
|---|---|---|
| Nieznany rodzaj paliwa (`wodorowy`) | odrzucony | `ERROR: … violates check constraint "version_fuel_type_allowed"` |
| Wersja bez rodzaju paliwa | odrzucona | `ERROR: null value in column "fuel_type" … violates not-null constraint` |
| `unresolved_segment` **razem z** `generation` | odrzucone | `ERROR: … "version_unresolved_segment_excludes_resolved"` — zakaz zgadywania działa |
| Trzy legalne kształty taksonomii (głębokość 5, 4, 3) | wszystkie przechodzą | OK — `b8`+`sedan` / `ii` w `unresolved_segment` / trzy `NULL` |
| Koszt filtru `fuelType` | zmierzyć, nie zakładać | `petrol` 0,44 ms (`Filter`), `electric` 4,4 ms (zmiana kształtu planu), z indeksem 1,8 ms → §6 |

### Tura 3 — współbieżność niezmiennika (po recenzji projektowej)

2 000 wersji po dwa warianty. Dwie równoległe sesje `psql` w autocommit, po jednym `DELETE`
na wersję (2 000 niezależnych transakcji na sesję): sesja A kasuje wariant `a`, sesja B wariant
`b` tej samej wersji. Bez żadnych opóźnień ani sztuczek synchronizacyjnych.

| Wersja wyzwalacza | Wersji bez wariantu | Błędów z bazy |
|---|---|---|
| gołe `EXISTS` (przed poprawką) | **1 706 z 2 000** | 294 |
| `FOR NO KEY UPDATE` + `EXISTS` | **0** | 2 000 |

| Test | Oczekiwane | Wynik |
|---|---|---|
| Przeniesienie jedynego wariantu na inną wersję | odrzucone | `ERROR: version w1 has no variant` — komunikat niesie `source_ref`, nie `id` |
| Przeniesienie jednego z dwóch wariantów | przechodzi | OK — w1 zostaje 1, w2 ma 2 |
| `TRUNCATE variant` przy istniejących wersjach | odrzucone | `ERROR: truncating variant would leave versions without variants`, tabela nietknięta |
| `TRUNCATE version CASCADE` (legalny reset) | przechodzi | OK — 0 wersji, 0 wariantów |

### Tura 4 — mapowanie ORM wobec żywego schematu (po zmianie decyzji z ADR 0018)

Osobna baza robocza odtworzona ze zrzutu schematu produkcyjnego, plus porównanie schematu
wykonane **na żywej bazie `cars`** (sama introspekcja, bez zapisu). PostgreSQL 17.11,
`doctrine/orm` 3.6.8, `doctrine/dbal` 4.4.4.

| Test | Oczekiwane | Wynik |
|---|---|---|
| Mapowanie encji wobec żywego schematu | zgodne poza znanymi wyjątkami | `validateMapping()` czysty; różnica = **5 instrukcji**, wszystkie tej samej klasy: DBAL 4.4 nie introspekuje `GENERATED ALWAYS AS IDENTITY` (zmierzone: `GENERATED BY DEFAULT` introspekuje) |
| Strona zapisana przez ORM w jawnej transakcji DBAL | jedna transakcja, nie dwie | `SAVEPOINT DOCTRINE_2` / `RELEASE`, jeden `BEGIN`, jeden `COMMIT` |
| Wersja i warianty w **dwóch** `flush()` wewnątrz jednej transakcji | przechodzi | OK — odroczony wyzwalacz nie strzela przy zwolnieniu punktu zapisu |
| `flush()` samej wersji **bez** jawnej transakcji | odrzucone | `version … has no variant` przy `COMMIT` transakcji założonej przez `flush()` |
| Kolejność instrukcji w jednym `flush()` | — | `INSERT` wersji → `INSERT` nowych wariantów → `UPDATE` → `DELETE` usuniętych |
| Wymiana **wszystkich** wariantów w jednym `flush()` (`orphanRemoval`) | przechodzi | OK — komplet-na-stronę nie łamie niezmiennika |
| `clear()` kolekcji i ponowne dodanie tych samych `source_ref` | odrzucone | `ERROR: duplicate key … "variant_version_id_source_ref_key"` — ORM wstawia przed kasowaniem |
| Stabilność `public_id` i `first_seen_run_id` przy drugim przebiegu | niezmienione | OK — `UPDATE version SET name = ?, last_seen_run_id = ? WHERE id = ?`, bez `first_seen_run_id` i bez `public_id` |
| Dwie równoległe sesje zapisujące **tę samą** wersję przez ORM | jedna wygrywa, druga dostaje błąd | sesja B czekała na indeksie unikalnym, po zatwierdzeniu A dostała `SQLSTATE 23505` na `version_source_ref_key` |
| Naruszenie `CHECK` przez ORM | odrzucone przy `flush()` | `SQLSTATE 23514`, komunikat niesie nazwę ograniczenia |
| Naruszenie niezmiennika przy `COMMIT` | transakcja już zamknięta | `isTransactionActive()` = **false** po nieudanym `COMMIT`; bezwarunkowy `rollBack()` rzuciłby „no active transaction" i zasłonił prawdziwy błąd |
| Koszt zapisu strony: DBAL vs ORM (500 stron) | zmierzyć, nie zakładać | dwa niezależne przebiegi: wstawienia 1,57 → 1,90 i 1,02 → 1,61 ms/stronę, aktualizacje 1,32 → 1,45 i 0,84 → 1,20 ms/stronę. Stosunek **1,1–1,6×**, niestabilny między maszynami; wobec ~2,5 s obsługi strony bez znaczenia |
| Import bez czyszczenia jednostki pracy (2 000 stron) | zmierzyć | 4,0 s z `clear()` między stronami, **45,4 s** bez |
| Menedżer encji po nieudanym `flush()` | — | **zamknięty**, a `clear()` go nie otwiera (kończy się powodzeniem, `isOpen` dalej `false`); kolejna poprawna strona w tym procesie kończy się `EntityManagerClosed` bez `SQLSTATE`. Otwiera dopiero `ManagerRegistry::resetManager()` |
| Migracja `Version20260903100000` (ADR 0019) | `up`, `down`, upsert i para zbieżna działają | OK — kolumna `canonical_ref NOT NULL` dodana na opróżnionej tabeli, dwa adresy z jedną formą kanoniczną przyjęte, wstawienie ETagu bez formy kanonicznej **odrzucone** przez `NOT NULL` |
| Rozbieżność adres-ze-sitemapu ↔ `version.source_ref` | zmierzyć na żywej bazie | **86 z 1 388 wersji (6,2%)** ma `source_ref` spoza 34 969 adresów `<loc>` w `cars.xml` (ADR 0019) |

### Wnioski, które trzeba znać przy implementacji

- **Zapis strony musi być w jawnej transakcji.** W trybie autocommit `INSERT` do `version`
  jest własną transakcją i wyzwalacz odrzuca go natychmiast, bo wariantów jeszcze nie ma.
  To nie jest wada — to dowód, że niezmiennik działa. Ale kod, który zapomni
  `beginTransaction()`, nie zapisze **niczego**.
- **Naruszenie niezmiennika zgłasza się przy `COMMIT`**, a nie przy `INSERT`. Obsługa błędów
  musi otaczać zatwierdzenie, nie tylko poszczególne instrukcje.
- Wartości `GENERATED ALWAYS AS IDENTITY` są konsumowane także przez wycofane transakcje —
  w kluczach będą luki. To normalne i nie wolno na tej podstawie wnioskować o liczbie wierszy.
- **`TRUNCATE variant` jest zabroniony.** Reset danych w testach i lokalnie:
  `TRUNCATE version CASCADE` albo `DELETE FROM version`.
- **Poziom izolacji `READ COMMITTED` jest warunkiem poprawności niezmiennika**, nie tylko
  domyślną wartością Doctrine. Podniesienie go do `REPEATABLE READ` wymaga ponownej analizy §3.5.
- **`EntityManager::flush()` musi być wywołany wewnątrz jawnej transakcji.** Sam z siebie zakłada
  własną transakcję i zatwierdza ją — wersja bez wariantów wtedy nie przejdzie, a marka i model
  zapisane przez DBAL zostałyby poza transakcją strony.
- **Po nieudanym `COMMIT` nie wolno wołać `rollBack()` bez sprawdzenia `isTransactionActive()`.**

## 13. Czego mapowanie ORM nie wyraża

Schemat pozostaje źródłem prawdy. Mapowanie encji (`docs/orm.md`) ma do niego pasować — nigdy
odwrotnie. Mapowanie **nie wyraża** i nie ma wyrażać:

| Element schematu | Ile | Gdzie opisany |
|---|---|---|
| ograniczenia `CHECK` | 30 (27 na tabelach zmapowanych) | §2–§4 |
| wyzwalacze ograniczeń `DEFERRABLE INITIALLY DEFERRED` | 3 | §3.5 |
| wyzwalacz na `TRUNCATE` | 1 | §3.5 |
| funkcje PL/pgSQL | 4 | §3.5 |
| `GENERATED ALWAYS AS IDENTITY` | 5 kluczy głównych | zasada 2 |

Trzy pierwsze pozycje są **niewidoczne dla `doctrine:schema:validate`** — migracja, która po cichu
skasuje wyzwalacz albo `CHECK`, przejdzie kontrolę zgodności mapowania. Pilnują ich testy
zachowania z §12, nie mapowanie. Ostatnia pozycja jest widoczna jako stała, znana różnica pięciu
instrukcji, bo DBAL 4.4 introspekuje `GENERATED BY DEFAULT`, ale nie `GENERATED ALWAYS`.

Mechanizm wykrywania rozjazdu, pełna lista tego, co wykrywa i czego nie, oraz kontrakt zapisu
strony: `docs/orm.md`. Ten dokument ich nie powiela.
