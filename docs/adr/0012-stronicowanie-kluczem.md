# ADR 0012 — Stronicowanie kluczem po `(acceleration_0_100_s, public_id)`

Status: przyjęty · Data: 2026-09-02

## Kontekst

Kolekcja `/v1/variants` ma ~32 000 wierszy i musi być stronicowana. Trzeba wybrać mechanizm
oraz klucz porządkujący, a klucz musi dawać porządek **totalny** — inaczej stronicowanie gubi
i powiela wiersze.

Dodatkowe ograniczenie: `CLAUDE.md` i rola architekta zabraniają wystawiania klucza z bazy.
Kursor jest kodowany, nie szyfrowany, więc wszystko, co w nim jest, należy uznać za jawne.

## Decyzja

Stronicowanie kluczem po parze `(acceleration_0_100_s, public_id)`, wspartej indeksem

```sql
CREATE INDEX variant_acceleration_idx ON variant (acceleration_0_100_s, public_id);
```

- Kursor: base64url z pary wartości ostatniego zwróconego wiersza + kierunek sortowania.
- Predykat: `(acceleration_0_100_s, public_id) > (:a, :p)` rosnąco, `<` malejąco — jedno
  porównanie krotek, obsługiwane wprost przez indeks.
- Serwer pobiera `limit + 1` wierszy; nadmiarowy sygnalizuje kolejną stronę i nie trafia do
  odpowiedzi. `page.next` to kursor albo `null`.
- Kursor niosący kierunek pozwala odrzucić żądanie, w którym zmieniono `sort` przy zachowanym
  kursorze — `400`, a nie ciche pominięcie wyników.
- Kolekcje `/v1/brands` i `/v1/models` stronicują tak samo, po `(name, public_id)`, ale **bez
  indeksu** — 91 i 1 400 wierszy sortuje się w pamięci szybciej, niż planista zdążyłby rozważyć
  indeks.

`acceleration_0_100_s` jest `NOT NULL`, więc nie ma pytania o `NULLS FIRST`/`NULLS LAST`.
To dodatkowy argument za tym, żeby domyślnym porządkiem było właśnie przyspieszenie.

## Konsekwencje

- Koszt strony nie zależy od jej numeru. Strona 400 kosztuje tyle samo co strona 1.
- Wynik jest stabilny mimo równoległego importu **wobec wstawień**: wiersz dopisany podczas
  przeglądania nie przesuwa granic stron.
- **Zastrzeżenie: `acceleration_0_100_s` jest kolumną wiodącą porządku i bywa aktualizowana.**
  Upsert wariantu nadpisuje przyspieszenie, więc wiersz może przeskoczyć w porządku między
  pobraniem dwóch stron: jeśli przesunie się przed kursor — zniknie z wyników, jeśli za kursor —
  pojawi się drugi raz. Żadne stronicowanie po zmiennym kluczu tego nie eliminuje; usunęłaby to
  dopiero migawka (`REPEATABLE READ` na całe przeglądanie) albo porządek po kolumnie
  niezmiennej. Przy jednym imporcie na dobę okno wynosi ~3,8 h raz na dobę i akceptujemy je
  świadomie. Gdyby przeszkadzało, właściwą odpowiedzią jest sortowanie po `public_id`, które
  nigdy się nie zmienia — nie zaś porzucenie stronicowania kluczem.
- **Kursor nie zawiera klucza z bazy** — para składa się z `public_id`. To jest jedyny powód,
  dla którego indeks jest zbudowany na `public_id`, a nie na `id`; różnica to 8 bajtów na wpis
  przy 32 000 wierszy.
- Jedna oś sortowania. Sortowanie po mocy wymagałoby indeksu na `version`, którego żaden `LIMIT`
  by nie wykorzystał bez denormalizacji — a denormalizacja agregatu na wersji to dokładnie ten
  mechanizm, który zgubił wartość pod współbieżnością w poprzednim podejściu.
- Klient nie dostaje `total`. Policzenie go wymaga drugiego zapytania po całym zbiorze, którego
  żaden `LIMIT` nie skróci.

## Odrzucone alternatywy

**`LIMIT … OFFSET`.** Prostsze do napisania, ale liczy i odrzuca wszystkie pominięte wiersze,
a przy równoległym zapisie gubi i powiela wiersze między stronami. Tutaj jest wręcz droższe,
bo indeks porządkujący i tak istnieje.

**Kursor z `id` z bazy, „bo i tak jest zakodowany”.** Base64 to nie szyfrowanie. Klucz z bazy
w kursorze to klucz z bazy na zewnątrz.

**Kursor podpisany HMAC-iem.** Pozwoliłby wykryć manipulację i wtedy zawartość mogłaby być
dowolna. Dokłada sekret do zarządzania po to, żeby móc umieścić w kursorze wartość, której
i tak nie musimy tam umieszczać.

**Numer strony jako identyfikator (`?page=3`).** Ten sam problem co offset, plus obietnica
stabilności, której nie da się dotrzymać.
