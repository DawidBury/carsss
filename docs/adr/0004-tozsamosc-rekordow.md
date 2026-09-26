# ADR 0004 — Tożsamość: klucz wewnętrzny, publiczny UUID, referencja źródła

Status: przyjęty · Data: 2026-09-02

## Kontekst

Trzeba pogodzić trzy różne wymagania wobec tożsamości wiersza: szybkie złączenia wewnątrz bazy,
identyfikator w API, który nie zdradza klucza z bazy, oraz klucz naturalny pozwalający na
idempotentny import. W poprzednim podejściu API adresowało zasoby przez `slug`, którego w
schemacie nie było — blokujący defekt B1 pierwszej recenzji.

## Decyzja

Każda tabela taksonomii ma **trzy** tożsamości, o rozłącznych rolach:

| Kolumna | Rola | Widoczna na zewnątrz |
|---|---|---|
| `id BIGINT GENERATED ALWAYS AS IDENTITY` | klucz główny, złączenia, klucze obce | nie |
| `public_id UUID DEFAULT gen_random_uuid()` | identyfikator w API i w kursorze | tak |
| `source_ref TEXT` | klucz naturalny ze źródła, cel `ON CONFLICT` | nie |

`public_id` jest nadawany raz i **nigdy nie jest aktualizowany** — jest stały przez całe życie
wiersza. Od ADR 0018 są na to dwa mechanizmy, zależnie od tego, kto pisze wiersz: dla `brand`
i `car_model` domyślna wartość bazy `gen_random_uuid()` (kolumna nie występuje w `INSERT` ani
w `DO UPDATE`), dla `version` i `variant` konstruktor encji (`Uuid::v4()`, ta sama wersja UUID)
z właściwością `readonly` i mapowaniem `updatable: false`. Wybór tożsamości z tego ADR-a nie
zmienia się ani o jotę; zmienia się tylko to, kto generuje wartość.

`source_ref` jest nieprzezroczysty dla rdzenia: dla scrapera to segment ścieżki lub ścieżka
kanoniczna, dla API dostawcy — jego identyfikator rekordu. **Adapter gwarantuje unikalność
`source_ref` w obrębie tabeli.** Wyjątek: `variant`, gdzie unikalność jest w obrębie wersji,
bo ostatni wariant awaryjny ustalania referencji to pozycja w dokumencie
(`docs/database.md` §3.4).

## Konsekwencje

- API nie zdradza kluczy z bazy ani ich kolejności — `gen_random_uuid()` daje UUID v4, nie
  rosnący.
- Podmiana źródła nie zmienia ani jednego adresu w API: identyfikatory publiczne są nasze.
- Kursor stronicowania niesie `public_id`, więc nie ujawnia klucza wewnętrznego mimo
  jawnego kodowania (ADR 0012).
- Koszt: 16 bajtów i jeden indeks unikalny na tabelę. Przy 32 000 wierszy — poniżej 2 MB łącznie.
- `public_id` jest losowy, więc wstawianie do indeksu unikalnego trafia w losowe strony. Przy
  jednym imporcie na dobę i tej skali jest to nieistotne.

## Odrzucone alternatywy

**Slug wyprowadzony z nazwy jako identyfikator publiczny.** Ładniejsze adresy, ale wymaga
rozstrzygania kolizji (`Škoda` i `Skoda` dają ten sam slug — uwaga N8 drugiej recenzji
poprzedniego podejścia), rozstrzygania, co zrobić przy poprawieniu nazwy w źródle, i dodaje
kolumnę oraz indeks na każdą tabelę. Adres nie jest wymaganiem.

**Wystawianie `source_ref` jako identyfikatora.** Zero kosztu, ale wprost przecieka kształt
źródła: po wymianie scrapera na API dostawcy `/v1/variants?brandId=audi` zmieniłoby się
w `?brandId=12345`. Łamie wymaganie o podmienialności.

**Sam UUID jako klucz główny, bez `id`.** Klucze obce urosłyby dwukrotnie, a indeksy na losowych
UUID fragmentują się przy wstawianiu. Nie ma za to żadnej korzyści — klucz główny i tak nie
wychodzi na zewnątrz.

**UUID v7 (uporządkowany w czasie).** Rozwiązałby losowość wstawiania, ale PostgreSQL 17 nie
ma go w rdzeniu (jest od 18), więc trzeba by go generować w PHP — czyli stracić gwarancję,
że wartość powstaje przy `INSERT` niezależnie od tego, kto pisze. Przy tej skali gra niewarta
świeczki, a v7 koduje czas utworzenia, którego akurat nie chcemy ujawniać.
