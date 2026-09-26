# ADR 0008 — Jeden wąski port pozyskania danych

Status: przyjęty · Data: 2026-09-02

## Kontekst

Wymaganie wprost postawione przez właściciela: **musi dać się zamienić scraper na wywołanie API
i reszta ma działać bez zmian**. Kryterium akceptacji jest ostre — jeśli lista plików do
napisania jest dłuższa niż „nowy katalog adaptera + jeden wpis w konfiguracji”, port jest źle
postawiony.

W poprzednim podejściu port przeciekał: `ETag`/`Last-Modified` przechodziły przez niego pod
nazwami `entityTag`/`lastModified`, adres kanoniczny był polem rdzenia, a konwersja jednostek
i polskich etykiet siedziała po niewłaściwej stronie granicy — druga strona portu musiała znać
`6,4 l/100km` i przecinek dziesiętny, czyli własności autocentrum.pl.

## Decyzja

Jeden interfejs, trzy metody, w `src/Source/SourceCatalog.php`:

```php
namespace App\Source;

use App\Source\Contract\SourceFetchResult;

interface SourceCatalog
{
    public function name(): string;

    /** @return iterable<string> nieprzezroczyste referencje rekordów */
    public function listRecords(): iterable;

    public function fetchRecord(string $ref, FetchMode $mode): SourceFetchResult;
}

enum FetchMode { case Incremental; case Full; }
```

`FetchMode::Full` **zabrania** adapterowi zwrócenia `Unchanged`. Powód i odrzucone alternatywy:
ADR 0017. Adapter bez pojęcia wykrywania zmian spełnia tę regułę za darmo.

Kontrakt ładunku, `src/Source/Contract/` (wyłącznie odczytywalne DTO, bez logiki):

```php
final readonly class SourceFetchResult
{
    private function __construct(
        public SourceRecordState $state,
        public ?SourceRecord $record,
    ) {}

    public static function record(SourceRecord $record): self;
    public static function unchanged(): self;   // źródło twierdzi, że nic się nie zmieniło
    public static function absent(): self;      // rekordu nie ma albo nie niesie danych
}

enum SourceRecordState { case Record; case Unchanged; case Absent; }

final readonly class SourceRecord
{
    /** @param list<SourceVariant> $variants */
    public function __construct(
        public string $ref,                       // tożsamość ustalona po pobraniu
        public SourceTaxonomy $taxonomy,
        public FuelType $fuelType,                // nienullowalny: wymaganie w typie
        public ?float $powerHp,
        public ?int $engineDisplacementCcm,
        public ?int $bootCapacityL,
        public array $variants,
    ) {}
}

final readonly class SourceTaxonomy
{
    public function __construct(
        public string $brandRef,
        public string $brandName,
        public string $modelRef,
        public string $modelName,
        public string $versionName,
        public ?string $generation,          // tylko przy ścieżce 5-segmentowej
        public ?string $bodyStyle,           // tylko przy ścieżce 5-segmentowej
        public ?string $unresolvedSegment,   // tylko przy ścieżce 4-segmentowej
    ) {}
}

final readonly class SourceVariant
{
    public function __construct(
        public string $ref,
        public float $acceleration0To100S,        // nienullowalny: wymaganie w typie
        public ?float $fuelConsumptionL100Km,
        public ?int $fuelTankL,
        public ?Gearbox $gearbox,
        public ?string $gearboxUnmappedLabel,     // niepusty tylko gdy $gearbox === null
        public ?Drive $drive,
        public ?string $driveUnmappedLabel,
    ) {}
}

enum Gearbox: string { case Manual = 'manual'; case Automatic = 'automatic'; }
enum Drive: string   { case Front = 'front'; case Rear = 'rear'; case All = 'all'; }

enum FuelType: string {
    case Petrol = 'petrol'; case Diesel = 'diesel';
    case Hybrid = 'hybrid'; case Electric = 'electric';
}
```

Reguły granicy, wszystkie sprawdzalne:

1. **Wartości są już w jednostkach docelowych.** Adapter zwraca `float 6.3`, a nie
   `"6,3 l/100km"`. Jednostka jest w nazwie pola. Rdzeń nie zna ani jednego polskiego słowa
   ani ani jednego separatora dziesiętnego.
2. **Znacznik zmiany nie przekracza portu.** ETag jest prywatną sprawą adaptera i mieszka w
   jego własnej tabeli `autocentrum_page_cache`. Rdzeń nigdy nie widzi żadnego tokenu ani go
   nie przechowuje. `Unchanged` niesie samą informację, nie mechanizm, a `FetchMode` mówi
   **czego chcemy**, nie **jak adapter wykrywa zmianę**.
3. **Żadna nazwa z domeny HTTP ani HTML nie występuje w sygnaturach.** Brak `url`, `etag`,
   `status`, `header`, `selector`, `label`. Test kontrolny: `grep -iE 'url|http|etag|html|dom'`
   po `src/Source/SourceCatalog.php` i `src/Source/Contract/` nie może dać trafienia.
4. **Wymagania obowiązkowe są w typach**, nie w komentarzach. `SourceVariant::$acceleration0To100S`
   i `SourceRecord::$fuelType` są nienullowalne, więc wariantu bez przyspieszenia ani rekordu
   bez rodzaju paliwa **nie da się skonstruować**. Adapter musi odrzucić taki blok lub rekord.
   Te dwa typy są całym mechanizmem egzekwowania wymagalności po stronie kodu — reszta należy
   do bazy.
4a. **`unresolvedSegment` wyklucza się z `generation`/`bodyStyle`.** Adapter wypełnia albo dwa
   pierwsze (ścieżka 5-segmentowa), albo trzecie (4-segmentowa), albo żadne (3-segmentowa).
   Baza to wymusza (`version_unresolved_segment_excludes_resolved`), a ADR 0003 uzasadnia.
5. **Adapter gwarantuje unikalność `ref`** w obrębie swojego poziomu (ADR 0004).
6. Mapowanie słowników należy do adaptera; nierozpoznana etykieta idzie do `*UnmappedLabel`
   i nie przerywa niczego (ADR 0006).

## Pełna lista zmian przy podmianie źródła

**Do dodania**

1. `src/Source/VendorX/` — dowolna liczba plików prywatnych; publiczna jest tylko klasa
   implementująca `App\Source\SourceCatalog`.
2. `config/services.yaml` — jedna linia:
   ```yaml
   App\Source\SourceCatalog: '@App\Source\VendorX\VendorXCatalog'
   ```

**Do usunięcia po wygaszeniu scrapera (opcjonalne, nie blokuje wymiany)**

3. `src/Source/Autocentrum/`, `tests/Source/Autocentrum/`, `tests/Fixtures/Source/Autocentrum/`.
4. Migracja kasująca `autocentrum_page_cache`.

**Co się nie zmienia:** `Import`, `Catalog`, `Api`, migracje tabel taksonomii, wiadomości
Messengera, kontrakt HTTP, kształt odpowiedzi, testy poza katalogiem adaptera.

## Konsekwencje

- `Import` nie ma ani jednej gałęzi zależnej od tego, które źródło jest podłączone.
- `import_run.source` wypełnia `name()`, więc pochodzenie zapisuje się samo — nie da się go
  pominąć ani ustawić niezgodnie z rzeczywistością w konfiguracji.
- Adapter, który nie ma pojęcia „bez zmian”, po prostu nigdy nie zwraca `Unchanged`; nic go
  do tego nie zmusza.
- `listRecords()` zwraca `iterable`, więc scraper może strumieniować 27 384 adresy z `cars.xml`
  bez wczytywania pliku 5 MB do pamięci, a adapter API dostawcy może stronicować leniwie.
- Trzy metody, jedna implementacja dziś, jedna planowana. To jest dokładnie ten przypadek,
  w którym abstrakcja z jedną implementacją jest uzasadniona: druga implementacja jest
  wymaganiem, nie przypuszczeniem.

## Odrzucone alternatywy

**Osobne porty na wyliczanie i na pobieranie.** Dwa interfejsy, zawsze implementowane razem,
zawsze przez tę samą klasę. Mnożenie bytów.

**Port zwracający surowy dokument (`string $html` / `array $json`) i parser po stronie rdzenia.**
Największy błąd, jaki można tu popełnić: rdzeń musiałby znać format źródła, czyli podmiana
scrapera zmieniałaby `Import`. Dokładnie to wytknęła recenzja poprzedniego podejścia (defekt B3).

**Przenoszenie znacznika zmiany przez port jako `?string $changeToken`.** Kusi, bo rdzeń mógłby
trzymać go w `version`. Ale wtedy 17% stron (zaślepki bez wersji) nie ma gdzie go trzymać,
a rdzeń zyskuje kolumnę o znaczeniu zależnym od adaptera. Cała pamięć podręczna została
w adapterze.

**`fetchRecord(): ?SourceRecord` z `null` na wszystko.** Skleiłoby „nie ma” z „bez zmian”, a to
są dwie różne decyzje zapisu: przy „bez zmian” trzeba oznaczyć wersję jako widzianą, przy
„nie ma” nie wolno.

**Enum `SourceRecordState` zamiast obiektu wyniku.** Wymagałby zwracania krotki albo dwóch
wywołań. Obiekt wyniku z konstruktorami nazwanymi jest tańszy w użyciu i nie da się go źle
zbudować.
