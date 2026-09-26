# ADR 0014 — Zestaw indeksów wyznaczony przez skalę, nie przez odruch

Status: przyjęty · Data: 2026-09-02

## Kontekst

Rola architekta stawia sprawę ostro: **indeks bez zapytania, które go używa, jest defektem**.
Odruch podpowiada indeksowanie każdej kolumny filtrowanej i każdego klucza obcego. Przy tej
skali ten odruch jest kosztowny i nieskuteczny.

Skala po imporcie: `brand` ~91, `car_model` ~1 400, `version` ~22 800, `variant` ~32 000.
Jeden zapis na dobę. Cała baza mieści się w pamięci podręcznej dowolnej instancji RDS.

## Decyzja

Indeks powstaje tylko wtedy, gdy **istnieje konkretne zapytanie, dla którego planista go
wybierze**. Pełna lista zapytanie → indeks jest w `docs/database.md` §6 i jest zamknięta:
indeks spoza tej listy jest do usunięcia.

Zestaw sprowadza się do:

- klucze główne (7),
- indeksy unikalne będące ograniczeniami: `public_id` ×4, `source_ref` ×3,
  `(version_id, source_ref)`, `key_hash`, `name` na `api_key`,
  częściowy `import_run (source) WHERE status = 'running'`,
- **trzy** indeksy nieunikalne: `car_model (brand_id)`, `version (car_model_id)`,
  `variant (acceleration_0_100_s, public_id)`.

Świadomie **bez indeksu**, z podanym powodem:

| Kolumna / zapytanie | Powód |
|---|---|
| `brand (name)`, sortowanie `/v1/brands` | 91 wierszy; planista nigdy nie wybierze indeksu |
| `car_model (name)`, sortowanie `/v1/models` | 1 400 wierszy sortowanych w pamięci |
| `variant (gearbox)`, `variant (drive)` | selektywność 30–65%; skan jest szybszy od indeksu |
| `version (generation)`, `version (body_style)` | nie są dziś osią filtrowania w API |
| `version (last_seen_run_id)` | jedno zapytanie na dobę po 22 800 wierszach |
| wszystkie klucze obce na `import_run` | rodzic nigdy nie jest kasowany, więc nie ma czego przyspieszać |

`variant (version_id)` nie istnieje osobno, bo jest prefiksem indeksu unikalnego
`(version_id, source_ref)` — osobny byłby duplikatem.

## Konsekwencje

- Zapis nie płaci za indeksy, których nikt nie czyta. Przy 27 384 upsertach na przebieg każdy
  zbędny indeks to 27 384 dodatkowe wstawienia do B-drzewa.
- Filtry po `gearbox` i `drive` wykonują się przy skanie indeksu sortującego. Dla żądania
  z `LIMIT 50` oznacza to przeczytanie kilkuset wpisów zamiast pięćdziesięciu — dziesiątki
  mikrosekund.
- Decyzja jest odwracalna jednym `CREATE INDEX CONCURRENTLY`, bez zmiany kształtu tabeli.
- **Próg rewizji jest podany, nie domyślny:** rząd wielkości więcej wierszy (~1 mln w `variant`)
  albo zapytanie w `pg_stat_statements` z medianą powyżej 50 ms. Rewizja ma się opierać na
  `EXPLAIN (ANALYZE, BUFFERS)` z produkcji, nie na intuicji.

## Odrzucone alternatywy

**Indeks na każdą kolumnę filtrowaną przez API.** Dołożyłby pięć indeksów, z których żadnego
planista by nie użył przy tej selektywności i tej liczbie wierszy. Kosztowałby zapis i nie dałby
odczytowi nic.

**Indeks na każdy klucz obcy „bo tak się robi”.** Ta reguła istnieje po to, żeby kasowanie
rodzica nie wymagało skanu tabeli potomnej. Tutaj rodzice albo nie są kasowane (`import_run`),
albo mają indeks z innego powodu (`brand_id`, `car_model_id`, `version_id`). Reguła jest
spełniona co do treści, nie co do litery.

**Indeksy pokrywające (`INCLUDE`) pod zapytania odczytu.** Pozwoliłyby czytać wyłącznie z
indeksu. Przy bazie mieszczącej się w pamięci oszczędzają dostęp do strony, która i tak jest
w buforze, a zwiększają rozmiar indeksu i koszt zapisu.

**Denormalizacja `version.best_acceleration` pod sortowanie wersji.** Byłaby potrzebna, gdyby
głównym zasobem była wersja. Nie jest (`docs/api.md` §2), a poprzednie podejście udowodniło,
że taki agregat utrzymywany wyzwalaczem gubi wartość pod współbieżnością.
