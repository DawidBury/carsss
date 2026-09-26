# ADR 0010 — Pochodzenie danych bez kolumn czasu i bez miękkiego kasowania

Status: przyjęty · Data: 2026-09-02

## Kontekst

Wymaganie: **da się prześledzić, z którego źródła i przebiegu pochodzi rekord.**

Odruchowa realizacja to komplet `created_at`, `updated_at`, `data_source_id`, `first_seen_run_id`,
`last_seen_run_id`, `retired_at` na każdej tabeli. To sześć kolumn na tabelę, z których część
nigdy nie jest czytana — a poprzednie podejście zostało odrzucone głównie za nadmiarowość.

## Decyzja

Pochodzenie niesie **jedna tabela i najwyżej dwa klucze obce**:

| Tabela | Kolumny pochodzenia |
|---|---|
| `import_run` | `source`, `started_at`, `finished_at`, `status` |
| `brand`, `car_model` | `first_seen_run_id` |
| `version` | `first_seen_run_id`, `last_seen_run_id` |
| `variant` | `first_seen_run_id` |

Źródło rekordu = `first_seen_run_id → import_run.source`, wypełniane z `SourceCatalog::name()`.
Czas powstania = `first_seen_run_id → import_run.started_at`.

**Nie ma** `created_at`, `updated_at`, `data_source_id` ani `retired_at`.

Nieaktualność wiersza jest wyprowadzalna: `version.last_seen_run_id` nie wskazuje na ostatni
zakończony przebieg. Usuwanie takich wierszy to procedura operatorska, nie automat
(`docs/database.md` §8).

## Konsekwencje

- Wymaganie jest spełnione jednym złączeniem, dla każdego wiersza w schemacie.
- Osiem kolumn mniej niż w wariancie odruchowym, bez utraty żadnej odpowiedzi.
- Odczyty nie mają predykatu widoczności. Przy miękkim kasowaniu **każde** zapytanie `Catalog`
  musiałoby nieść `AND retired_at IS NULL`, a pominięcie go w jednym miejscu jest defektem,
  którego test nie złapie.
- Czasy są dokładne do przebiegu (jedna doba), nie do sekundy. Nikt nie zamówił dokładności
  do sekundy i żaden endpoint jej nie pokazuje.
- Ryzyko: gdyby kiedyś doszedł endpoint „co się zmieniło od czasu T”, `updated_at` byłby
  potrzebny. To jest jedna migracja `ADD COLUMN`, a nie przepisanie schematu.

## Odrzucone alternatywy

**`created_at`/`updated_at` na każdej tabeli „bo taka konwencja”.** Osiem kolumn, których nie
czyta żadne zapytanie w `docs/database.md` §6. Kolumna, której nic nie czyta i której wartości
nikt nie sprawdza, jest defektem.

**Tabela `data_source` z kluczem obcym na każdej tabeli.** Wymaganie brzmi „podmienić źródło”,
a nie „uruchomić dwa naraz”. Poprzednie podejście miało wielo-źródłowość w schemacie i nie
miało jej w API — `GET /v1/vehicles` zwracał każdy samochód dwa razy w trakcie przełączania
(uwaga S6 pierwszej recenzji). Nazwa źródła w `import_run.source` odpowiada na to samo pytanie
i nie tworzy stanu, którego API nie umie obsłużyć.

**`retired_at` z miękkim kasowaniem.** Dokłada predykat do każdego zapytania odczytu i drugi
stan do każdego upsertu („wskrzeszenie” wiersza) — a druga recenzja poprzedniego podejścia
znalazła tam defekt: wersja wracała do życia, ale jej warianty zostawały wycofane (D5).
Twarde kasowanie takiego stanu nie ma.

**`last_seen_run_id` także na `brand` i `car_model`.** Odrzucone z powodu rywalizacji o blokady
— ADR 0005.
