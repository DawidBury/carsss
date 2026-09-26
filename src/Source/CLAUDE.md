# Moduł `Source` — pozyskanie danych

Przeczytaj ten plik **przed** zmianą czegokolwiek w tym module. Jeśli zmieniasz zachowanie
modułu, zaktualizuj ten plik w tym samym zadaniu.

## Za co odpowiada

Sprowadzenie danych ze świata zewnętrznego do **jednego, źródłowo-neutralnego kształtu**.
To jedyne miejsce w systemie, które wie, skąd biorą się dane.

Moduł ma dwie części o zupełnie różnym statusie:

| | Status |
|---|---|
| `SourceCatalog.php` + `Contract/` | **port** — stabilny kontrakt, zmiana wymaga ADR |
| `Autocentrum/` | **adapter** — implementacja jednego źródła, wymienna |

## Granice

**Wolno tu** (i tylko tu w całym systemie): HTML, DOM, selektory CSS, HTTP, ETag, adresy URL,
polskie etykiety (`Rodzaj skrzyni`, `Moc silnika`), jednostki (`KM`, `l/100km`, `cm³`, `s`),
separatory dziesiętne, spacje nierozdzielające `U+00A0`, `cars.xml`, limit tempa żądań.

**Nie wolno tu**: Doctrine ORM, encji, `App\Entity`, SQL rdzenia, nazw jego tabel i kolumn,
Messengera, Symfony HttpFoundation, niczego z `App\Import`, `App\Catalog`, `App\Api`.

Wyjątek, który wygląda na złamanie granicy, a nim nie jest: adapter `Autocentrum` ma **własną
tabelę** `autocentrum_page_cache` na walidatory ETag i sam do niej pisze — surowym SQL-em przez
DBAL. To jego prywatny stan, nie rdzeń. Trzyma go po to, żeby znacznik zmiany **nigdy nie
przekroczył portu**. Tabela celowo **nie ma encji** i jest wyłączona z porównania schematu
(`docs/orm.md` §2): encja w dzielonej przestrzeni `App\Entity` przekreśliłaby regułę „tabela
adaptera znika razem z adapterem".

Tabela trzyma **dwie** rzeczy na wiersz: walidator `ETag` i **formę kanoniczną** adresu
(ADR 0019). Reguły, których nie wolno cofnąć:

- **Wiersz powstaje dopiero po udanym sparsowaniu strony**, w `AutocentrumCatalog::fetchRecord()`,
  a nie w kliencie HTTP zaraz po odpowiedzi. Forma kanoniczna nie istnieje wcześniej. Efekt
  uboczny, który jest naprawą osobnej wady: strona, której nie da się sparsować, nie dostaje już
  walidatora, którego nie zasłużyła, i nie wraca `304` w kółko.
- **`canonical_ref` jest `NOT NULL` i to jest cały mechanizm.** Warunkowy GET jest możliwy tylko
  wtedy, gdy istnieje wiersz; wiersz istnieje tylko po udanym sparsowaniu; sparsowanie daje formę
  kanoniczną. „`304` bez znanej formy kanonicznej" jest więc nieosiągalne, a baza czyni je
  niereprezentowalnym. Nie zmieniaj tej kolumny na nullowalną — wróci gałąź, którą ADR 0019
  odrzucił.
- **Jeden odczyt na stronę**: `SELECT etag, canonical_ref … WHERE url = :u` (**Q-Q**). Nie
  rozbijaj tego na dwa zapytania.
- **Brak `UNIQUE` na `canonical_ref`.** Dwa adresy zbiegające się do jednej formy to zjawisko,
  które ta kolumna rejestruje.

Dwie rzeczy, o które trzeba dbać przy okazji ADR 0018:

- **`PageCache` używa tego samego połączenia DBAL co menedżer encji.** Zapis walidatora `ETag`
  ma zostać **poza** transakcją strony — tak jest dziś i tak ma zostać. Gdyby wjechał do środka,
  wycofanie strony kasowałoby walidator, a przy wyścigu o tę samą wersję (ADR 0018) tracilibyśmy
  warunkowy GET, który czyni ponowienie tanim.
- Adapter nadal nie wie, że w systemie istnieje jakakolwiek baza. Powyższe jest własnością
  wdrożenia, nie kontraktu portu.

## Port — sygnatury

```php
namespace App\Source;

interface SourceCatalog
{
    public function name(): string;                      // trafia do import_run.source

    /** @return iterable<string> nieprzezroczyste referencje rekordów */
    public function listRecords(): iterable;

    public function fetchRecord(string $ref, Contract\FetchMode $mode): Contract\SourceFetchResult;
}
```

**Wynik pobrania nazywa rekord jego własną referencją, nigdy tą, o którą pytano** (ADR 0019):

```php
final readonly class SourceFetchResult
{
    public SourceRecordState $state;
    public ?SourceRecord $record;
    public ?string $ref;

    public static function record(SourceRecord $record): self;   // ref = $record->ref
    public static function unchanged(string $ref): self;
    public static function absent(): self;                       // ref = null
}
```

To nie jest kosmetyka. `listRecords()` daje adresy z `cars.xml`, a `link[rel=canonical]` potrafi
je przepisać — zmierzone 6,2% (`docs/source-contract.md` §6.5). Dopóki `Unchanged` nie niosło
referencji, rdzeń oznaczał wersję pod nazwą, której w bazie nie ma. Adapter bez problemu
aliasowania po prostu zwraca to, co dostał — jedna linia.

`FetchMode::Full` **zabrania** zwrócenia `Unchanged` — musisz wtedy pobrać bezwarunkowo
(ADR 0017).

Pełny kontrakt ładunku (`Contract/`) jest wypisany w `docs/adr/0008-jeden-waski-port-zrodla.md`
i to jest jego jedyne miejsce. Nie powielaj go tutaj — dwie kopie się rozjadą.

## Decyzje lokalne, których nie wolno cofnąć

1. **Wartości przechodzą przez port już w jednostkach docelowych** — `float 6.3`, nigdy
   `"6,3 l/100km"`. Konwersja i parsowanie liczby są w adapterze. Cofnięcie tego przenosi
   wiedzę o polskim formacie do rdzenia i łamie wymianę źródła (defekt B3 recenzji poprzedniego
   podejścia).
2. **Znacznik zmiany (ETag) nie przekracza portu.** Nie dodawaj `?string $changeToken` do
   sygnatur ani kolumny na niego w `version`.
3. **Pola wymagane są nienullowalne w typach**: `SourceVariant::$acceleration0To100S` (`float`)
   i `SourceRecord::$fuelType` (`FuelType`). Tak wyrażone są oba wymagania obowiązkowe. Zmiana
   któregokolwiek na typ nullowalny przepuściłaby dane, które baza i tak odrzuci — tylko później
   i drożej.
3a. **Rodzaj paliwa wyprowadzasz z adresu, nie z DOM.** Token w slugu silnika:
   `benzynowy → petrol`, `diesla → diesel`, `hybrydowy → hybrid`, `elektryczny → electric`.
   **Token to PIERWSZY segment slugu bezpośrednio po prefiksie `silnik-`, nigdy dowolne
   wystąpienie tokenu w slugu.** 58 slugów zawiera dwa tokeny
   (`silnik-hybrydowy-2.0-b5-benzynowy-mild-hybrid-264km-od-2020`), więc szukanie „gdziekolwiek"
   zapisuje **18 hybryd jako benzynę** — cicho, bo obie wartości przechodzą `CHECK`.
   Reguła „pierwszy segment" pokrywa 100% z 27 384 stron; liczby kontrolne
   i porównanie obu reguł: `docs/source-contract.md` §1.
   **Nieznany token = nie da się zbudować rekordu** → zwróć `absent()` i zaloguj
   `unknown_fuel_token`. Nie podstawiaj wartości domyślnej — `petrol` „bo najczęstszy"
   to cicha korupcja 18 004 wierszy. ADR 0016.
4. **Mapowanie słowników jest w adapterze.** Nierozpoznana etykieta idzie do
   `$gearboxUnmappedLabel` / `$driveUnmappedLabel`, a wartość kanoniczna zostaje `null`.
   **Nigdy nie rzucaj wyjątku na nieznaną wartość słownikową** — wymaganie mówi wprost, że
   nieznana wartość nie może wywalić importu.
5. **Trzy stany wyniku, nie dwa.** `Record` / `Unchanged` / `Absent`. Sklejenie `Unchanged`
   z `Absent` powoduje, że niezmieniona strona zostanie uznana za usuniętą i wyczyszczona.
6. **`listRecords()` zwraca `iterable`**, żeby dało się strumieniować. `cars.xml` ma 5 MB;
   wczytanie go w całości do tablicy jest defektem.

## Pułapki potwierdzone pomiarem

Liczby i selektory: `docs/source-contract.md`. Poniżej tylko konsekwencje dla kodu.

1. **13 z 78 stron (17%) nie ma ani jednego `config-box`.** Zwróć `SourceRecord` z pustą
   listą wariantów albo `absent()` — nie wymyślaj przyspieszenia. `Import` odrzuci taki rekord
   i zaloguje powód.
2. **7 z 98 bloków nie ma przyspieszenia.** Pierwsza opcja `<select>` bywa zaślepką (~2 KB
   zamiast ~13 KB). Pomiń taki blok. Typ konstruktora i tak Ci nie pozwoli go zbudować.
3. **Kolejność ustalania referencji wariantu: `cid` → `option[data-id]` → pozycja w dokumencie.**
   Zaślepka z pkt. 2 potrafi nie mieć `cid`. Referencja pozycyjna jest unikalna tylko w obrębie
   strony — dlatego klucz w bazie to `(version_id, source_ref)`, a nie samo `source_ref`.
4. **`<select>` istnieje tylko przy wielu wariantach** (22 z 78 stron). Przy jednym wariancie
   etykietę bierz z `div.engine-configuration h2.primary-header`.
5. **Mapowanie opcji na bloki jest pozycyjne.** Nie zakładaj, że `data-id` odpowiada kolejności
   w DOM — sprawdź obie listy i przerwij parsowanie strony, jeśli mają różną długość.
6. **Pusta wartość = pole nieobecne.** Nie ma literału `b/d`. Wiersz bez danych ma pusty
   `span.dt-param-value`, klasę `dt-row no-value` i opakowanie `div.dt-missing.hidden`.
7. **Czytaj tekst węzła, nie HTML.** `Moc silnika` ma zagnieżdżony `<span>`, a jednostka bywa
   oddzielona `U+00A0`.
8. **Separator dziesiętny.** Zmierzona próbka ma kropkę, ale akceptuj też przecinek.
9. **Rozróżnienie poziomu jest pozycyjne w DOM**: `div.dt-row` **wewnątrz** `config-box` to
   wariant, **poza** nim — wersja. `Pojemność zbiornika paliwa` jest na poziomie **wariantu**
   (93 wystąpienia, ani jednego na poziomie wersji), mimo że intuicja mówi inaczej.
10. **Tożsamość ustalaj z `link[rel=canonical]` PO pobraniu.** Część adresów robi 301 na formę
    kanoniczną (`limuzyna` → `limousine`), więc dwa adresy z `cars.xml` mogą dać ten sam rekord.
    Nie deduplikuj przed pobraniem — nie masz jak.
11. **Auta elektryczne.** Przyjmuj `Średnie spalanie` **wyłącznie** w `l/100km`. Wartość
    w `kWh/100km` zwróć jako `null`. Wpisanie kilowatogodzin do kolumny litrów to cicha
    korupcja danych, której nikt nie wykryje.
12. **Głębokość ścieżki wyznacza wszystko** — i jest jedyną regułą, jakiej wolno tu użyć:

    | Segmentów | `generation` | `bodyStyle` | `unresolvedSegment` |
    |---|---|---|---|
    | 5 | pozycja 3 | pozycja 4 | `null` |
    | 4 | `null` | `null` | środkowy segment |
    | 3 | `null` | `null` | `null` |

    **Nie buduj klasyfikatora ani słownika rozstrzygającego** — jest to wprost zakazane
    (`CLAUDE.md` §5, ADR 0003). Baza wymusza wykluczanie się tych kolumn, więc złamanie reguły
    kończy się utratą całej strony, a nie cichym błędem.
13. **Nazwy wyświetlane bierz z breadcrumbu, ale on nie etykietuje poziomów.** Breadcrumb skleja
    nazwy narastająco (`Dane techniczne` → `Dane techniczne Audi` → `Dane techniczne Audi A4`
    → `… A4 B8` → `… A4 B8 Avant Facelifting`), a jego pozycje odwzorowują pozycje segmentów
    w adresie. Nazwę segmentu otrzymujesz przez **odjęcie narastającego prefiksu**. Gdy
    breadcrumb nie ma wpisu na danej głębokości — odwrotem jest **odslugowanie segmentu**.
    Zmierzone: `docs/source-contract.md` §3.1.

## Etykieta wobec źródła

2 żądania na sekundę, burst 5. Prawdziwy, kontaktowy `User-Agent`. **Żadnych query stringów** —
`robots.txt` zabrania `/*?` oraz `/ac-ajax/`. Warunkowy GET przez `ETag` z
`autocentrum_page_cache`. Wymuszenie pełnego pobrania: `TRUNCATE autocentrum_page_cache`
(ta tabela nie ma wyzwalaczy i wolno ją opróżniać — w odróżnieniu od `variant`, gdzie
`TRUNCATE` jest zabroniony, `docs/database.md` §3.5).

**`FetchMode::Full` zabrania zwrócenia `Unchanged`.** Rdzeń używa go, gdy jego wiersz wersji
zniknął, a Twoja pamięć podręczna wciąż twierdzi „bez zmian" (ADR 0017). Musisz wtedy pobrać
bezwarunkowo — pomiń walidator, nie wysyłaj `If-None-Match`. Zignorowanie trybu zapętla rekord
w stanie, z którego nie ma wyjścia.

**Zasięg limitera zależy od środowiska — i to jest pułapka, nie szczegół.**

| Środowisko | Zasięg | Workerów | Sufit |
|---|---|---|---|
| lokalnie (Docker Compose) | **współdzielony** — repliki `worker` montują bind `.:/app`, więc dzielą `var/` hosta i jedno wiadro tokenów | 4 | 2 żąd./s globalnie |
| AWS ECS/Fargate | **per zadanie** — własny system plików | 1 | 2 żąd./s |

Lokalna współdzielność jest **artefaktem montowania**, nie własnością kodu. Na ECS cztery
zadania to cztery wiadra i **8 żądań/s**. Skalowanie tam wolno włączyć dopiero po przeniesieniu
stanu limitera do wspólnego magazynu (np. `cache.adapter.redis`) — ADR 0015.

**Zmierzone tempo** (pełny import, na żywo): jeden worker osiąga **0,40 strony/s**, czyli 20%
budżetu, bo obsługa jednej strony zajmuje **~2,5 s** (round-trip HTTP + parsowanie + transakcja).
Limit 2 żąd./s **nie jest** wąskim gardłem pojedynczego workera — jest nim czas obsługi strony.
Cztery workery ze wspólnym wiadrem dają 1,99 str./s (99% budżetu) i pełny przebieg w **3,8 h**;
jeden worker potrzebuje **~19 h**. Liczby: `docs/source-contract.md` §8.

Optymalizując parser, pamiętaj, że skracasz te ~2,5 s — czyli realnie zwiększasz tempo aż do
sufitu 2 żąd./s, po którym dalsze przyspieszanie nic nie daje i jest niedozwolone.

## Testy

Testy parsera **wyłącznie na utrwalonych fixture'ach HTML** w `tests/Fixtures/Source/Autocentrum/`,
nigdy przez żywe HTTP. Zestaw fixture'ów musi zawierać co najmniej: stronę bez `config-box`,
stronę z zaślepką jako pierwszą opcją `<select>`, stronę z jednym wariantem (bez `<select>`),
stronę o głębokości 3, 4 i 5, oraz stronę z nierozpoznaną wartością słownikową.
