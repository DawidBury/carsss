# Moduł `Catalog` — odczyt

Przeczytaj ten plik **przed** zmianą czegokolwiek w tym module. Jeśli zmieniasz zachowanie
modułu, zaktualizuj ten plik w tym samym zadaniu.

## Za co odpowiada

Strona odczytu w rozdziale zapis/odczyt. Zamienia **zwalidowane kryterium** na zapytanie SQL
i wynik na płaskie DTO.

Od ADR 0020 moduł jest też **właścicielem indeksu wyszukiwarki**: dwóch widoków materializowanych
i komendy, która je odświeża. To nadal strona odczytu — widoki są własną strukturą odczytową
`Catalog`, nie danymi źródłowymi. Cztery tabele taksonomii pozostają dla tego modułu
tylko do odczytu.

## Granice

**Zależy od:** Doctrine DBAL, Doctrine ORM, `App\Entity`. I niczego więcej z `App\`.

**Nie wolno tu**: `Request`, `Response`, nagłówków, kodów statusu HTTP, `App\Api`, `App\Import`,
`App\Source`, żadnego parsowania query stringa, żadnej walidacji wejścia użytkownika.

Do tego modułu wchodzą **kryteria już zwalidowane** — obiekt `Criteria` niesie wartości
poprawnych typów z dozwolonych zbiorów. Walidacja jest w `Api`. Jeśli piszesz tu `if (!in_array($sort, …))`,
walidacja jest w złym module.

**Tabele, które czyta:** `brand`, `car_model`, `version`, `variant`. **Nie pisze do żadnej.**

**Widoki materializowane, których jest właścicielem:** `variant_search`, `search_term`. Czyta je
`SearchReader`, odświeża `SearchIndexRefresher` (`app:search:refresh`). To jedyny zapis w tym
module i dotyczy wyłącznie jego własnych struktur odczytowych — `REFRESH`, nie `INSERT` do
taksonomii.

## Decyzje lokalne, których nie wolno cofnąć

1. **Listy: surowy SQL przez DBAL. Pojedynczy rekord po identyfikatorze: ORM.** Podział
   z ADR 0018, nie do zmiany bez ADR-a.
   - **Listy** (`/v1/brands`, `/v1/models`, `/v1/variants`) to jedno zapytanie z filtrami,
     porównaniem krotek i `LIMIT`. Hydratacja encji byłaby tu czystym kosztem, a stronicowanie
     kluczem wymaga dokładnie tego predykatu, który trafia w `variant_acceleration_idx`.
     DQL by tego nie wyraził bez własnej funkcji.
   - **`GET /v1/variants/{id}`** (**Q-J**) to jedno zapytanie DQL ze złączeniem
     `variant → version → car_model → brand`. Zmierzone: jedna instrukcja przy złączeniu jawnym,
     cztery przy leniwym chodzeniu po asocjacjach. Napisz je jako jedno.
1a. **Encje nie opuszczają tego modułu.** Do `Api` wychodzą wyłącznie DTO z `Result/`. Encja
   podana serializatorowi wciąga leniwe ładowanie do warstwy HTTP i stawia `id` oraz `source_ref`
   o jedno odwołanie od odpowiedzi. Kształt DTO nie zmienił się przez ADR 0018 i nie ma się zmienić.
1b. **Nie dokładaj encji dla `api_key` ani `autocentrum_page_cache`.** Nie są zmapowane celowo
   (`docs/orm.md` §2) i są wyłączone z porównania schematu przez `schema_filter`.
2. **Zapytanie składane wyłącznie z warunków, które klient podał.** Wzorzec
   `WHERE (:param IS NULL OR kolumna = :param)` jest **zakazany**: uniemożliwia planiście użycie
   indeksu i degraduje wszystkie zapytania do skanu.
3. **Nazwa kolumny pochodzi ze stałej w kodzie, wartość zawsze przez parametr wiązany.**
   Nigdy interpolacja wartości do SQL, nigdy nazwa kolumny z żądania.
4. **Stronicowanie kluczem po `(acceleration_0_100_s, public_id)`**, nigdy `OFFSET`.
   Predykat to porównanie krotek — `(a, p) > (:a, :p)` — bo tylko ono trafia w indeks
   `variant_acceleration_idx` jako `Index Cond`. Rozbicie go na
   `a > :a OR (a = :a AND p > :p)` daje ten sam wynik i gorszy plan.
5. **Klucz główny nie opuszcza tego modułu.** DTO wyniku niosą `public_id`. Nie dodawaj `id`
   do żadnego DTO „bo się przyda”.
6. **`source_ref` nie opuszcza tego modułu.** To kształt źródła.
7. **Pobieramy `limit + 1` wierszy**, żeby wiedzieć, czy jest następna strona, bez liczenia
   całego zbioru. Nadmiarowy wiersz nie trafia do wyniku.
8. **Brak `COUNT(*)` i brak `total`.** Liczenie wymaga drugiego zapytania po całym zbiorze,
   którego żaden `LIMIT` nie skróci.

## Indeksy — co obsługuje które zapytanie

Pełna lista z **zmierzonymi planami** z żywego PostgreSQL 17.11 jest w `docs/database.md` §6.
Nie powielaj jej tutaj. Zasada obowiązująca przy każdej zmianie zapytania:

> Zmieniasz zapytanie → sprawdzasz `EXPLAIN (ANALYZE, BUFFERS)` i aktualizujesz tabelę
> w `docs/database.md` §6. Zapytanie, którego nie ma w tej tabeli, nie ma prawa istnieć.

Rzeczy, które trzeba wiedzieć, zanim się zdziwisz:

- Filtry `gearbox` i `drive` **celowo nie mają indeksu**. Planista nakłada je jako `Filter`
  na uporządkowany skan `variant_acceleration_idx`. Zmierzone 0,03 ms. To nie jest defekt
  do naprawienia.
- Filtr `fuelType` **też nie ma indeksu**, ale zachowuje się dwojako i trzeba to wiedzieć:
  dla wartości częstych (`petrol`, 57%) planista nakłada `Filter` przy uporządkowanym skanie
  (0,44 ms), dla rzadkich (`electric`, 1,3%) **zmienia kształt planu** na `Hash Join`
  z sekwencyjnym skanem `variant` (4,4 ms). To najdroższe zapytanie w całym API i nadal jest
  o rząd wielkości poniżej progu rewizji z ADR 0014. Zanim dołożysz indeks, przeczytaj
  `docs/database.md` §6 — pomiar z indeksem też tam jest (1,8 ms).
- Filtr `?brandId=` przy 91 markach kończy się `Seq Scan` na `brand` — jedna strona danych.
  Też nie jest defektem.
- Sortowanie kolekcji marek i modeli odbywa się w pamięci, bez indeksu. 91 i 1 400 wierszy.

## Pułapki

1. **`acceleration_0_100_s` to `NUMERIC`, nie `float`.** Porównania w kursorze muszą iść przez
   typ dziesiętny, inaczej `9.3` z kursora nie trafi w `9.3` z bazy. Nie rzutuj na `float`
   po drodze. Dotyczy obu ścieżek jednakowo: DBAL zwraca `string`, a encja mapuje tę kolumnę
   jako `Types::DECIMAL`, czyli też `string`.
2. **Kursor musi pasować do reszty żądania.** Zmiana `sort` lub filtru przy zachowanym kursorze
   to `400`, a nie ciche pominięcie wyników. Kierunek jest zakodowany w kursorze właśnie po to.
3. **`gearbox_unmapped_label` i `drive_unmapped_label` nie wychodzą w odpowiedzi.** To polski
   tekst ze źródła. Klientowi nierozpoznana wartość objawia się jako `null`.
3a. **`fuel_type` jest jedynym polem wersji, które nigdy nie jest `null`.** Nie opakowuj go
   w obsługę braku — `NOT NULL` w bazie jest gwarancją, nie sugestią.
3b. **Trzy kolumny taksonomii wykluczają się nawzajem** i wszystkie trzy idą do odpowiedzi
   takie, jakie są. `unresolved_segment` to segment, o którym źródło nie mówi, czy jest
   generacją, czy nadwoziem — nie próbuj go przypisać do żadnej z pozostałych kolumn przy
   składaniu DTO.
4. **Pole nieznane źródłu jest `null` w odpowiedzi, nigdy nie znika.** Kształt odpowiedzi jest
   stały niezależnie od kompletności danych.
5. **Nie ma predykatu widoczności.** W schemacie nie ma miękkiego kasowania, więc nie dopisuj
   `AND retired_at IS NULL` — takiej kolumny nie ma i nie ma jej mieć (ADR 0010).
6. **Wersja bez wariantów nie istnieje**, więc złączenie `variant → version` nigdy nie gubi
   wierszy. Nie potrzeba `LEFT JOIN` w tę stronę.

## Wyszukiwarka — decyzje, których nie wolno cofnąć

Pełne uzasadnienie i pomiary: ADR 0020. Kształt widoków: `docs/database.md` §5a.

9. **Słownik `simple`, nigdy `polish`.** Etykiety to nazwy własne i oznaczenia w rodzaju
   `2.0 TDI 140KM`. Polski stemmer je obcina i psuje dopasowanie przedrostkiem.
10. **Przedrostkiem dopasowywany jest wyłącznie ostatni token** (`… & tdi:*`). Pozostałe idą
    dokładnie. To jest to, co sprawia, że wyszukiwarka działa w trakcie pisania.
11. **Token jest poprawiany tylko wtedy, gdy nie jest przedrostkiem żadnego znanego słowa.**
    Ten jeden warunek chroni przed poprawianiem słów, których użytkownik jeszcze nie dopisał:
    `sed` zostaje (`sedan` się od niego zaczyna), `sedn` jest poprawiane. Nie zamieniaj tego na
    „popraw, jeśli nie ma wyników” — wtedy `audi sed` zamienia się w coś innego w trakcie pisania.
12. **Tokeny krótsze niż 3 znaki nie są poprawiane.** Słownik ich nie zawiera, a `a4` czy `x5`
    poprawione trigramami trafiłyby w przypadkowe słowo.
13. **Trafienia dokładne wyprzedzają poprawione.** Wynik korekty jest doklejany za wynikiem
    dokładnym i deduplikowany po identyfikatorze, nigdy go nie zastępuje.
14. **Zapytanie korekty niesie operator `%` i predykat `similarity(...) >= 0.3` naraz.**
    Operator daje indeks (26 kandydatów zamiast 12 202), predykat daje niezależność od
    `pg_trgm.similarity_threshold` na serwerze. Zmierzone: bez operatora 39,36 ms zamiast 1,23 ms.
    Próg jest dodatkowo ustawiany jawnie przy każdym wywołaniu.
15. **Fraza jest tokenizowana w PHP do zbioru `[\p{L}\p{N}.]`, zanim trafi do `to_tsquery`.**
    To jedyna rzecz, która stoi między zapytaniem a składnią tsquery: `&`, `|`, `!`, `(`, `)`,
    `:` i `'` nie mają prawa dojść do bazy. Nie zamieniaj tego na wiązanie parametru —
    `to_tsquery` parsuje swój argument, więc parametr nie chroni przed błędem składni.
16. **Brak stronicowania.** Wyniki są uszeregowane trafnością, która nie jest porządkiem
    totalnym i nie jest stabilna między zapytaniami. Kursor nie miałby na czym stanąć.

## Wyszukiwarka — pułapki

7. **Widoki są nieaktualne, dopóki ktoś ich nie odświeży.** Po każdej zmianie danych w teście
   trzeba wywołać `refreshSearchIndex()`, inaczej test widzi dane poprzedniego testu. Traity
   `RefreshesSearchIndex` i `TaxonomyFixtures` idą w parze.
8. **`REFRESH … CONCURRENTLY` nie działa w transakcji** i wymaga indeksu unikalnego oraz widoku
   już zapełnionego. Migracja tworzy oba `WITH DATA` właśnie dlatego.
9. **`Import` nie może wywołać odświeżenia.** `Import → Catalog` jest zależnością zakazaną
   (`docs/architecture.md` §2). Kolejność kroków pilnuje runbook, nie kod.
10. **`ts_rank` liczy się po trafieniach, nie po całej tabeli**, ale przy bardzo ogólnej frazie
    trafień jest kilka tysięcy i to one, a nie indeks, są kosztem. Zmierzone: mediana 1,91 ms
    przy 1 716 trafieniach — jeśli kiedyś urośnie, obcinaj zbiór kandydatów, nie zmieniaj indeksu.
    **Zmiana domyślnego `limit` tego nie rusza**: `top-N heapsort` przy N rzędu dziesiątek jest
    poniżej szumu pomiarowego, płaci się za zbiór kandydatów. Liczby: `docs/database.md` §6.
