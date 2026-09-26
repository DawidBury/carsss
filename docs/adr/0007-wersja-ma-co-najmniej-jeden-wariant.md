# ADR 0007 — Niezmiennik „wersja ma co najmniej jeden wariant” w bazie

Status: przyjęty · Data: 2026-09-02 · **Poprawiony 2026-09-02 po recenzji projektowej**

## Kontekst

Wersja bez wariantu nie ma przyspieszenia — jedynego pola wymaganego — więc jest bezużyteczna
dla API. Zmierzone: 13 z 78 stron (17%) nie ma ani jednego `config-box`; to zaślepki dla starych
i rzadkich aut. Dodatkowo 7 z 98 bloków nie ma przyspieszenia mimo istnienia bloku.

Wymaganie brzmi: **taki stan nie może powstać**. „Nie może powstać” to nie to samo co
„aplikacja go nie tworzy” — aplikacja to jeden pisarz z wielu (migracja, skrypt naprawczy, psql).

## Decyzja

Niezmiennik jest wyrażony w bazie: **jedna funkcja, która blokuje wiersz rodzica przed
sprawdzeniem**, wywoływana przez cztery wyzwalacze (pełny SQL w `docs/database.md` §3.5).

```sql
SELECT source_ref INTO v_source_ref
FROM version WHERE id = p_version_id FOR NO KEY UPDATE;
```

| Wyzwalacz | Zamyka drogę |
|---|---|
| `AFTER INSERT ON version` (odroczony) | wersja zapisana bez wariantów |
| `AFTER DELETE ON variant` (odroczony) | skasowanie ostatniego wariantu |
| `AFTER UPDATE OF version_id ON variant` (odroczony) | przeniesienie ostatniego wariantu gdzie indziej |
| `AFTER TRUNCATE ON variant` (zwykły, `FOR EACH STATEMENT`) | opróżnienie tabeli wariantów |

Wyzwalacza na `UPDATE version` nie ma: aktualizacja `last_seen_run_id` na ścieżce „bez zmian”
nie dotyka wariantów, a sprawdzanie tam kosztowałoby jedno zapytanie na każdą z ~22 800
niezmienionych stron w każdym przebiegu.

## Poprawka po recenzji (2026-09-02)

Pierwsza wersja miała w wyzwalaczu **gołe `EXISTS`, bez blokady**, i dwa braki pokrycia.
Recenzja wykazała, że obietnica z tego ADR była wtedy nieprawdziwa.

**Współbieżność.** `EXISTS` to czysty odczyt. Dwie transakcje kasujące różne warianty tej samej
wersji obie widzą wariant tej drugiej i obie przechodzą. Zmierzone: 2 000 wersji po dwa warianty,
dwie sesje w autocommit — **1 706 z 2 000 wersji zostało bez wariantu**.
Po dodaniu `FOR NO KEY UPDATE` ta sama próba daje **0 naruszeń i 2 000 błędów, po jednym
na wersję**.

Dlaczego `FOR NO KEY UPDATE`, a nie `FOR UPDATE`: ten pierwszy nie koliduje z `FOR KEY SHARE`,
które bierze wstawienie wariantu przez klucz obcy. Kasujący serializują się nawzajem, ale nie
blokują wstawiających — a wstawienie wariantu nie może naruszyć niezmiennika.

Poprawność zależy od poziomu izolacji: przy `READ COMMITTED` każda instrukcja w PL/pgSQL bierze
świeży snapshot, więc sprawdzenie **po** uzyskaniu blokady widzi cudze zatwierdzone kasowanie.
Przy `REPEATABLE READ` sama blokada by nie wystarczyła.

**Dwa braki pokrycia**, oba potwierdzone i zamknięte: `UPDATE variant SET version_id`
(przeniesienie ostatniego wariantu zostawiało pustą wersję po cichu) oraz `TRUNCATE variant`
(wyzwalacze wierszowe nie strzelają przy `TRUNCATE`). Wyzwalacz `TRUNCATE` jest `AFTER`, nie
`BEFORE`, żeby odróżnić `TRUNCATE variant` (wersje zostają → błąd) od `TRUNCATE version CASCADE`
(wersje też znikają → legalny reset).

Uzupełniająco, po stronie kodu: typ `SourceVariant::$acceleration0To100S` jest nienullowalnym
`float`, więc wariantu bez przyspieszenia nie da się w ogóle skonstruować, a rekord z pustą
listą wariantów `RecordWriter` odrzuca przed otwarciem transakcji, logując powód.

## Konsekwencje

- API nigdy nie zwróci wersji bez danych napędu, niezależnie od tego, kto pisał do bazy.
- Odroczenie do `COMMIT` sprawia, że kolejność instrukcji w transakcji nie ma znaczenia —
  zapis może wstawić wersję przed wariantami.
- Niezmiennik obowiązuje wobec **każdego** pisarza i wobec dwóch naraz — także wobec psql,
  skryptu naprawczego i migracji, czyli dokładnie tych, dla których ten ADR powstał.
- Koszt: jedno `SELECT … FOR NO KEY UPDATE` plus jedno `EXISTS` na dotkniętą wersję, oba po
  indeksie. Przy ~22 800 stronach raz na dobę — nieistotny.
- **`TRUNCATE variant` przestaje być dostępny operatorowi.** Reset danych to
  `TRUNCATE version CASCADE` albo `DELETE FROM version`. Dotyczy też sprzątania w testach
  i jest zapisane w `database.md` §3.5 oraz `Import/CLAUDE.md`.
- Kasujący blokują się nawzajem na wierszu wersji. Przy jednym workerze (ADR 0015) rywalizacja
  praktycznie nie występuje; przy równoległym czyszczeniu z ręki — serializuje, i o to chodzi.
- Naruszenie objawia się jako `SQLSTATE 23000` przy `COMMIT`, czyli **poza** blokiem, w którym
  wykonywano `INSERT`-y. Kod obsługujący błędy musi to uwzględnić.
- Ten ADR przetrwał zmianę narzędzia zapisu bez poprawki. Przy zapisie przez ORM (ADR 0018)
  obojętność na kolejność instrukcji jest tym, co pozwala `orphanRemoval` wymienić komplet
  wariantów w jednym `flush()`; zweryfikowane ponownie na żywej bazie, `docs/database.md` §12,
  tura 4. Dochodzi jedna pułapka narzędzia: `EntityManager::flush()` wywołany **bez** otwartej
  transakcji zakłada własną i zatwierdza ją, więc wersja bez wariantów zostanie odrzucona —
  co jest poprawne, ale kosztuje całą stronę.

## Odrzucone alternatywy

**Sprawdzenie tylko w PHP.** Nie obowiązuje przy zapisie z psql, ze skryptu naprawczego ani
z migracji. `CLAUDE.md` i rola architekta wymagają wyrażenia w bazie tego, co się da wyrazić.

**Odroczony wyzwalacz z gołym `EXISTS`, bez blokady.** Pierwsza wersja tej decyzji. Wygląda
poprawnie i przechodzi każdy test jednowątkowy — a pod współbieżnością nie wymusza niczego.
Odrzucone na podstawie pomiaru, nie rozumowania.

**Kolumna licznikowa `variant_count` z `CHECK (variant_count > 0)`, utrzymywana wyzwalaczem.**
To agregat liczony w tej samej instrukcji, która go zapisuje. Dokładnie ten wzorzec zgubił
wartość pod współbieżnością w recenzji poprzedniego podejścia (`min()` w wyzwalaczu wyliczany
ze snapshotu sprzed blokady). Sprawdzenie `EXISTS` nie ma stanu do zgubienia.

**Wyzwalacz natychmiastowy zamiast odroczonego.** Odrzuciłby każdy poprawny zapis, bo wersja
musi powstać przed wariantami.

**Klucz obcy z `version` na „pierwszy wariant” (odwrotna zależność).** Cykliczne klucze obce
wymagają odroczenia i tak, a dokładają kolumnę oraz pytanie „który wariant jest pierwszy”,
na które nie ma odpowiedzi.
