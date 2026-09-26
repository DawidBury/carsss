# ADR 0016 — Rodzaj paliwa wyprowadzany z adresu, `NOT NULL`

Status: przyjęty (decyzja właściciela) · Data: 2026-09-02

## Kontekst

Rodzaj paliwa nie występuje w DOM jako pole z etykietą — jest **zakodowany w slugu silnika**
w adresie strony. Zmierzone liczności na całym sitemapie (`docs/source-contract.md` §1):

```
benzynowy 18 004 · diesla 8 563 · hybrydowy 544 · elektryczny 273  =  27 384
```

Suma jest równa liczbie stron silnikowych co do jednej. Słownik jest więc **zamknięty
i pokrywa 100% zbioru**, a wartość jest znana **przed pobraniem strony**.

W pierwszej wersji projektu tego pola nie było — nie znalazło się na liście pól w `CLAUDE.md` §4,
a zasada „czego nie potrzebujemy dziś, tego nie ma w schemacie" nie pozwalała go dodać
z własnej inicjatywy. Właściciel rozstrzygnął: dodajemy.

## Decyzja

Kolumna `version.fuel_type TEXT NOT NULL` z `CHECK (fuel_type IN ('petrol','diesel','hybrid','electric'))`.

- **Poziom wersji**, bo strona silnikowa ma dokładnie jedno paliwo, wspólne dla wszystkich
  wariantów napędu.
- **`TEXT` + `CHECK`, nie typ wyliczeniowy PostgreSQL** — ta sama konwencja co `gearbox`
  i `drive`, z tych samych powodów (ADR 0006).
- **`NOT NULL`**, bo pokrycie wynosi 100%, a nie „prawie 100%".
- Wyprowadzana **z adresu**, nigdy ze scrapowania treści. Mapowanie tokenu na wartość kanoniczną
  należy do adaptera, jak każde inne mapowanie polskiego słownictwa.
- W porcie: `SourceRecord::$fuelType` typu `FuelType`, **nienullowalne**.
- Filtr `?fuelType=` na białej liście `/v1/variants`, **bez dedykowanego indeksu** —
  zmierzone uzasadnienie w `docs/database.md` §6.

## Konsekwencje

- Rodzaj paliwa jest jedynym polem wersji z gwarancją obecności. API może go zawsze pokazać
  i zawsze po nim filtrować, bez obsługi `null` po stronie klienta.
- Wartość jest znana bez pobierania strony. Dwa praktyczne skutki:
  1. `app:import:start` może policzyć nierozpoznane tokeny **na całym sitemapie, przed
     pierwszym żądaniem HTTP** — awaria słownika jest wykrywalna w sekundy, nie po 3,8 h.
  2. Nieznany token nie marnuje żądania do źródła.
- **Znana i przyjęta wada wymagalności:** nieznany token oznacza, że adapter nie może
  zbudować `SourceRecord`, więc strona zostaje **pominięta** (zwrot `absent()` + log
  `unknown_fuel_token`). Przy nowym paliwie w źródle (np. `wodorowy`) traciłyby się całe strony,
  a nie jedno pole.
  **Dlaczego to jest akceptowalne:** wykrycie jest natychmiastowe i głośne (pkt 1 powyżej),
  a naprawa to jedna migracja — `DROP CONSTRAINT` + `ADD CONSTRAINT … NOT VALID` + `VALIDATE`
  (`docs/database.md` §9) — plus jedna pozycja w mapie adaptera i w enumie.
  Zweryfikowane na żywej bazie: wstawienie `wodorowy` kończy się
  `ERROR: … violates check constraint "version_fuel_type_allowed"`.
- Różnica wobec `gearbox`/`drive` jest zamierzona i wynika z pomiaru: tamte pochodzą z treści
  strony, mają niepełne pokrycie (napęd 91/98 bloków) i **nie wolno im wywalić importu**, więc
  są nullowalne z kolumną na etykietę nierozpoznaną. Paliwo pochodzi z adresu i ma pokrycie
  pełne, więc może być wymagane. Ta sama konwencja typu, inna wymagalność — bo inne pomiary.

## Odrzucone alternatywy

**Kolumna nullowalna z `fuel_type_unmapped_label`, jak przy `gearbox` i `drive`.** Spójne
z resztą schematu i odporne na nowy token. Odrzucone, bo pokrycie wynosi 100%, a nullowalna
kolumna kłamałaby o danych: sugerowałaby, że istnieją wersje bez znanego paliwa. Właściciel
rozstrzygnął na `NOT NULL`.

**Poziom wariantu zamiast wersji.** Wszystkie warianty jednej strony mają to samo paliwo —
byłoby to powielenie tej samej wartości ~1,2 raza na wersję bez żadnej nowej informacji.

**Wyprowadzanie paliwa z treści strony zamiast z adresu.** Wymagałoby pobrania strony, żeby
poznać wartość, którą adres podaje za darmo, i uzależniałoby pole od stabilności DOM zamiast
od stabilności adresów.

**Typ wyliczeniowy PostgreSQL.** Odrzucony tak samo jak przy `gearbox` i `drive` — ADR 0006.

**Osobna tabela słownikowa paliw z kluczem obcym.** Cztery wiersze, które nigdy się nie zmienią,
kosztem złączenia w każdym zapytaniu odczytu i nowego klucza obcego.
