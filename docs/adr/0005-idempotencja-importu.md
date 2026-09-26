# ADR 0005 — Idempotencja importu i unikanie rywalizacji o blokady

Status: przyjęty · Data: 2026-09-02 · **częściowo zastąpiony przez ADR 0018 (2026-09-03)**

Co zostaje bez zmian: `brand` i `car_model` idą przez DBAL-owy `ON CONFLICT (source_ref)
DO NOTHING` i cała strona idzie w jednej transakcji — to jest właśnie powód, dla którego ADR 0018
nie przeniósł tych dwóch tabel do ORM-a. Co się zmieniło: `version` i `variant` nie są już
zapisywane przez `ON CONFLICT … DO UPDATE`, tylko przez ORM (odszukanie po kluczu naturalnym,
potem `INSERT` albo `UPDATE` po kluczu głównym). Wynika z tego jedna konsekwencja wprost sprzeczna
ze zdaniem w sekcji „Konsekwencje" niżej: **upsert wersji nie serializuje już dwóch transakcji
dotyczących tej samej strony** — przegrywająca dostaje `SQLSTATE 23505` i jest ponawiana przez
Messengera.

## Kontekst

Wymaganie: powtórny przebieg importu nie tworzy duplikatów. Naturalna realizacja to upsert po
kluczu naturalnym (`ON CONFLICT … DO UPDATE`) w każdej z czterech tabel.

Skala rozkłada się jednak skrajnie nierówno: 27 384 strony rozpisują się na ~22 800 wersji,
~1 400 modeli i **91 marek**. Każda strona zna swoją markę i swój model, więc naiwny upsert
oznaczałby, że każda z 27 384 transakcji dotyka jednego z 91 wierszy marki.

`INSERT … ON CONFLICT DO UPDATE` **bierze blokadę na kolidującym wierszu zawsze** — również
wtedy, gdy klauzula `WHERE` wykluczy aktualizację. `ON CONFLICT DO NOTHING` blokady nie bierze.

Uczciwe postawienie sprawy: **dziś import i tak jest praktycznie szeregowy**, bo ogranicza go
etykieta wobec źródła (2 żądania/s, jeden worker — `docs/architecture.md` §5). Rywalizacja
o 91 wierszy marek nie jest więc problemem, który dziś boli. Jest problemem, który **pojawi się
w dniu, w którym ktoś zwiększy liczbę workerów albo doda źródło bez limitu tempa** — i wtedy
objawi się jako niewytłumaczalne spowolnienie, a nie jako błąd. Koszt uniknięcia go teraz
wynosi zero.

## Decyzja

Dwa różne wzorce, dobrane do liczby wierszy w tabeli:

- **`brand`, `car_model`** — pobierz-lub-utwórz:
  `INSERT … ON CONFLICT (source_ref) DO NOTHING RETURNING id`, a gdy nic nie wróciło,
  `SELECT id … WHERE source_ref = :ref`. **Bez aktualizacji.** W konsekwencji te tabele
  nie mają kolumny `last_seen_run_id` — nie miałby jej kto aktualizować.
- **`version`, `variant`** — pełny upsert `ON CONFLICT … DO UPDATE`. Rywalizacja jest tu znikoma:
  jedną wersję dotyka jedna strona, a jedyne realne kolizje to adresy zbiegające się po 301 na
  wspólną formę kanoniczną (`limuzyna` → `limousine`) — zmierzone 280 takich liści w `cars.xml`,
  z czego część zbiega się parami (`docs/source-contract.md` §6.5).

Cała strona (marka, model, wersja, warianty) idzie w **jednej transakcji**. `public_id`
i `first_seen_run_id` nigdy nie występują w `DO UPDATE`.

Warianty są zapisywane komplet-na-stronę: najpierw `DELETE … WHERE version_id = :v AND
source_ref <> ALL(:refs)`, potem upsert pozostałych. Dlatego `variant` nie potrzebuje
`last_seen_run_id` — nie może być nieaktualny, jeśli jego wersja jest aktualna.

## Konsekwencje

- Import pozostanie realnie równoległy, jeśli kiedykolwiek zostanie zrównoleglony. Workery nie
  czekają na siebie na wierszach marek. Dziś, przy jednym workerze, korzyść jest hipotetyczna,
  a koszt zerowy — to jest ta rzadka sytuacja, w której warto zapłacić z góry.
- Cena: **zmiana nazwy marki lub modelu w źródle nie jest podchwytywana automatycznie.**
  Przy 91 markach i 1 400 modelach jest to zdarzenie na lata, naprawiane jednym `UPDATE`.
  Świadomie przyjęte.
- Świeżość marki jest wyprowadzalna z jej wersji, nie z własnej kolumny.
- **Zniknięcie bloku w źródle trwale zmienia `public_id` wariantu.** Warianty zapisujemy
  komplet-na-stronę, więc blok, który zniknął ze strony i wrócił w kolejnym przebiegu, dostaje
  nowy wiersz i nowy identyfikator publiczny. Stabilność `public_id` jest gwarantowana wobec
  **powtórzenia tego samego ładunku**, a nie wobec dowolnych zmian w źródle. Klient, który
  zapamiętał identyfikator wariantu, może dostać `404` — i to jest poprawne zachowanie, bo to
  jest inny wariant.
- Upsert wersji bierze blokadę wiersza `version`, co przy okazji serializuje dwie transakcje
  zapisujące tę samą wersję — i to zamyka pytanie o współbieżność odroczonego wyzwalacza
  z `docs/database.md` §3.5.

## Odrzucone alternatywy

**Pełny upsert wszędzie.** Prostszy do opisania, ale zamienia import w wykonanie szeregowe na
91 wierszach. Wydajność zapisu nie jest tu krytyczna, ale utrata równoległości bez żadnej
korzyści to defekt, nie kompromis.

**`ON CONFLICT DO UPDATE … WHERE brand.name IS DISTINCT FROM EXCLUDED.name`.** Wygląda na
oszczędność, ale blokada jest brana przed oceną `WHERE`, więc nie usuwa problemu — usuwa tylko
martwą krotkę.

**Wcześniejsze utworzenie wszystkich marek i modeli, przed rozesłaniem wiadomości.** Usunęłoby
rywalizację całkowicie, ale wymaga poznania pełnej taksonomii przed pobraniem stron, czyli
wyprowadzania jej z adresów — a to jest wiedza o kształcie źródła przeniesiona do rdzenia.
Łamie ADR 0008.

**Deduplikacja adresów przed pobraniem (`limuzyna`/`limousine`).** Wymagałaby znajomości reguł
przekierowań źródła przed pobraniem. Tożsamość ustalamy po pobraniu, z adresu kanonicznego —
tak, jak mówi `docs/source-contract.md` §6.5.
