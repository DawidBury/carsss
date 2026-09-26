# ADR 0003 — Taksonomia: cztery poziomy, generacja i nadwozie jako kolumny

Status: przyjęty (decyzja właściciela) · Data: 2026-09-02 · **Zaktualizowany 2026-09-02 po pomiarze breadcrumbu**

## Kontekst

Adres strony silnikowej ma zmienną głębokość (`docs/source-contract.md` §1):

| głębokość | kształt | liczba liści |
|---|---|---|
| 3 | `marka/model/silnik-*` | 1 710 |
| 4 | `marka/model/{generacja \| nadwozie}/silnik-*` | 8 078 |
| 5 | `marka/model/generacja/nadwozie/silnik-*` | 17 596 |

Przy głębokości 4 pozycja środkowego segmentu **nie rozstrzyga**, czy to generacja, czy nadwozie.
Poprzednie podejście zbudowało pod to silnik rozstrzygania dwuznaczności i to on był głównym
źródłem uwag w obu recenzjach.

## Decyzja

Cztery poziomy: `brand` → `car_model` → `version` → `variant`.

`generation` i `body_style` są **nullowalnymi kolumnami tekstowymi na `version`**, nie encjami.
Nie mają własnych tabel, kluczy obcych ani indeksów. Towarzyszy im **trzecia kolumna
`unresolved_segment`** na segment, którego źródło nie etykietuje.

| Głębokość ścieżki | `generation` | `body_style` | `unresolved_segment` |
|---|---|---|---|
| 5 (17 596 stron) | z pozycji 3 | z pozycji 4 | `NULL` |
| 4 (8 078 stron) | `NULL` | `NULL` | środkowy segment |
| 3 (1 710 stron) | `NULL` | `NULL` | `NULL` |

Reguła jest funkcją **samej głębokości ścieżki**. Nie ma żadnej heurystyki, żadnego słownika
rozstrzygającego i żadnego klasyfikatora. Wykluczenie się kolumn jest wymuszone w bazie przez
`CHECK version_unresolved_segment_excludes_resolved`.

Nazwy wyświetlane segmentów pochodzą z breadcrumbu (przez odjęcie narastającego prefiksu),
a gdy breadcrumb nie ma wpisu na danej głębokości — z odslugowania segmentu.

## Aktualizacja po pomiarze (2026-09-02)

Pierwsza wersja tej decyzji zostawiała przy głębokości 4 obie kolumny `NULL` i zapisywała
otwarte pytanie: czy da się rozstrzygnąć segment z treści strony albo z breadcrumbu.

**Pomiar odpowiedział: nie da się.** Breadcrumb skleja nazwy narastająco
(`Dane techniczne Audi A4 B8 Avant Facelifting`), a jego pozycje odwzorowują dokładnie pozycje
segmentów w adresie. Daje więc ładną nazwę wyświetlaną każdego segmentu, ale **nie etykietuje
poziomów** (`docs/source-contract.md` §3.1).

Wniosek: rozstrzygnięcie jest niemożliwe z samego źródła, więc pierwotny wybór między
„zgadywać" a „tracić dane" był fałszywą alternatywą. Trzecia kolumna wybiera trzecią opcję —
**zachować segment bez etykiety**. 8 078 segmentów jest zapisanych, żaden nie jest zgadnięty,
a rozstrzygnięcie ich w przyszłości (np. gdy pojawi się zewnętrzny słownik generacji) nie
wymaga ponownego scrapowania.

## Konsekwencje

- Zero błędnych danych w tych kolumnach i **zero utraconych**. Wcześniejszy wariant tracił
  informację o 8 078 segmentach; ten nie traci nic.
- Nie istnieje kod, który zgaduje. Nie ma czego testować ani czego mylić.
- Żadna z trzech kolumn nie jest dziś osią filtrowania w API, więc brak rozstrzygnięcia nie ma
  widocznego skutku dla klienta.
- API wystawia `unresolvedSegment` wprost, zamiast udawać, że wie. Klient dostaje uczciwą
  informację: „ten segment istnieje, źródło nie mówi, czym jest".
- Wypromowanie kolumn później do tabel nie zmienia kontraktu API.
- Koszt: jedna kolumna nullowalna i jeden `CHECK`.

## Odrzucone alternatywy

**Tabele `generation` i `body_style` z kluczami obcymi.** Wymaga rozstrzygnięcia, czym jest
środkowy segment przy 8 078 stronach, zanim powstanie wiersz. Dokładnie ten silnik został
odrzucony.

**Jedna kolumna `taxonomy_segment` na to, co jest w środku.** Uczciwa wobec dwuznaczności,
ale nie do wystawienia w API ani do odfiltrowania — klient dostałby pole, o którym nie wiadomo,
co znaczy. Odrzucone także dlatego, że właściciel wskazał dwie kolumny.

**Zapisywanie środkowego segmentu przy głębokości 4 do `generation` zawsze.** Wpisałoby
`sedan`, `kombi`, `suv` do kolumny nazwanej „generacja”. Błędne dane są gorsze od braku danych.

**Pozostawienie segmentu niezapisanego (pierwsza wersja tej decyzji).** Uczciwe, ale traciło
informację, którą źródło podaje wprost, i to bez żadnej korzyści — skoro segment i tak jest
w adresie, przechowanie go nie wymaga ani jednej reguły.

**Klasyfikator generacja/nadwozie na podstawie słownika segmentów z głębokości 5.** Kuszące,
bo statystycznie skuteczne. Odrzucone wprost przez właściciela i przez historię: to jest
dokładnie ten silnik rozstrzygania, który był głównym źródłem błędów w poprzednim podejściu.
Skuteczność statystyczna oznacza tu, że część wierszy jest cicho błędna — a nie wiadomo która.
