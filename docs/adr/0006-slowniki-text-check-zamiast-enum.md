# ADR 0006 — Słowniki jako TEXT + CHECK, nie jako typ wyliczeniowy PostgreSQL

Status: przyjęty · Data: 2026-09-02

## Kontekst

Trzy kolumny mają zamknięty zbiór wartości: `variant.gearbox` (`manual`, `automatic`),
`variant.drive` (`front`, `rear`, `all`) i `import_run.status`.

Wymaganie brzmi: **nieznana wartość nie może wywalić importu**. Zmierzone słowniki
(`docs/source-contract.md` §5) są zamknięte na próbce 78 stron, ale próbka to nie cały zbiór
27 384 stron.

W poprzednim podejściu te same kolumny były natywnymi typami wyliczeniowymi. Druga recenzja
odtworzyła awarię wprost:

```
SELECT 'na przednią oś'::drive_type;  -> ERROR: invalid input value for enum drive_type
```

Adapter wpisywał polską etykietę, transakcja się przerywała i **cała strona przepadała** —
łącznie z danymi, które sparsowały się poprawnie.

## Decyzja

Kolumna `TEXT` + `CHECK` na zamkniętą listę **plus dopuszczenie `NULL`**, a obok niej kolumna
`*_unmapped_label` na etykietę, której adapter nie umiał zmapować:

```sql
gearbox                TEXT,
gearbox_unmapped_label TEXT,
CONSTRAINT variant_gearbox_allowed
    CHECK (gearbox IS NULL OR gearbox IN ('manual', 'automatic')),
CONSTRAINT variant_gearbox_mapped_or_labelled
    CHECK (gearbox IS NULL OR gearbox_unmapped_label IS NULL)
```

Mapowanie etykiety na wartość kanoniczną należy **wyłącznie do adaptera**. Rdzeń widzi już
tylko `Gearbox::Manual` albo `null` + etykietę. Trzy legalne stany: obie `NULL` (źródło nie
podało), kanoniczna wypełniona (rozpoznane), etykieta wypełniona (nierozpoznane).

W PHP zbiór wartości jest enumem (`enum Gearbox: string`), więc typowanie nic nie traci.

## Konsekwencje

- **Nieznana wartość nie przerywa transakcji.** Wiersz powstaje, reszta pól strony ocalała,
  a operator widzi problem zapytaniem
  `SELECT gearbox_unmapped_label, count(*) FROM variant WHERE gearbox_unmapped_label IS NOT NULL GROUP BY 1;`
- Rozszerzenie słownika to `DROP CONSTRAINT` + `ADD CONSTRAINT … NOT VALID` + `VALIDATE
  CONSTRAINT` — operacja online, bez wyłącznej blokady na czas skanu
  (`docs/database.md` §9). Przy typie wyliczeniowym byłoby to `ALTER TYPE … ADD VALUE`, którego
  nowej wartości nie wolno użyć w tej samej transakcji.
- Nie trzeba rejestrować własnego typu DBAL ani filtru schematu, żeby `doctrine:schema:update`
  nie proponował w kółko usunięcia typu.
- Cena: `TEXT` zamiast 4-bajtowego OID, czyli ok. 6 dodatkowych bajtów na wiersz przy 32 000
  wierszy — ok. 200 kB. Oraz kolumna `*_unmapped_label`, w praktyce `NULL` w ~100% wierszy.

## Odrzucone alternatywy

**Natywny typ wyliczeniowy.** Odrzucony z powodu udokumentowanej awarii i kosztu migracji.

**Tabela słownikowa z kluczem obcym.** Nieznana wartość albo przerywa import (klucz obcy), albo
wymaga automatycznego dopisywania wierszy — a wtedy słownik przestaje być słownikiem, bo
zbiera literówki źródła.

**Sam `TEXT` bez `CHECK`.** Nie ma wtedy żadnej gwarancji, że w kolumnie filtrowanej przez API
są tylko wartości z kontraktu. Filtr `?gearbox=manual` przestaje być rozstrzygalny.

**Mapowanie w rdzeniu zamiast w adapterze.** Wprowadziłoby polskie etykiety do `Import`, co
łamie ADR 0008 i `CLAUDE.md` §6.

**Brak kolumny `*_unmapped_label`, samo logowanie.** Kusząco mniejsze, ale w bazie nieznana
skrzynia byłaby nieodróżnialna od skrzyni nieopublikowanej przez źródło, a logi na ECS wygasają.
Jedna kolumna nullowalna kupuje sprawdzalność wymagania.
