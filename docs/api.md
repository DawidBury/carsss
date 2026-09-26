# Kontrakt REST API

API jest **wyłącznie do odczytu**. Nie ma żadnego endpointu, który cokolwiek zapisuje, w tym
żadnego sterującego importem — import uruchamia komenda konsolowa (`CLAUDE.md` §8).

Wszystkie odpowiedzi to `application/json`, poza błędami, które są `application/problem+json`
(RFC 9457). Kodowanie UTF-8. Nazwy pól w `lowerCamelCase`.

Baza ścieżki: `/v1`. Wersja jest w ścieżce, bo zmiana kształtu odpowiedzi jest zmianą łamiącą
zgodność, a klienci nie negocjują wersji nagłówkiem.

## 1. Uwierzytelnienie

Każde żądanie do `/v1/**` musi nieść nagłówek:

```
X-API-Key: <32 losowe bajty zakodowane base64url>
```

Serwer liczy `sha256` z wartości nagłówka i szuka wiersza po `api_key.key_hash`
(zapytanie **Q-K**, indeks `api_key_key_hash_key`). Brak nagłówka i zły klucz dają ten sam
wynik — `401` — żeby nie dawać atakującemu informacji, czy klucz istnieje.

```
HTTP/1.1 401 Unauthorized
WWW-Authenticate: ApiKey realm="cars-api"
Content-Type: application/problem+json
```

Klucze wydaje komenda `app:api-key:create <nazwa>`, która wypisuje sekret **raz** — w bazie
jest tylko skrót. Odwołanie klucza to skasowanie wiersza.

`GET /health` jest poza `/v1` i **nie wymaga klucza** — odpytuje go target group ALB, który
klucza nie ma. Zwraca `200` i pusty obiekt; nie dotyka bazy, żeby awaria bazy nie wyrejestrowała
wszystkich zadań ECS i nie zamieniła awarii częściowej w całkowitą.

## 2. Zasoby

| Metoda i ścieżka | Zwraca |
|---|---|
| `GET /v1/brands` | kolekcja marek |
| `GET /v1/models` | kolekcja modeli |
| `GET /v1/variants` | kolekcja wariantów napędu — **główny zasób** |
| `GET /v1/variants/{id}` | jeden wariant |
| `GET /v1/search` | podpowiedzi wyszukiwarki — do 20 wariantów uszeregowanych trafnością |
| `GET /health` | stan procesu (bez uwierzytelnienia) |

**Dlaczego głównym zasobem jest wariant, a nie wersja.** Wariant jest jedynym poziomem, na którym
samochód jest w pełni określony: ma przyspieszenie (pole wymagane), skrzynię i napęd, a przez
wersję — moc, pojemność silnika i bagażnika. Kolekcja wariantów ma porządek totalny po
przyspieszeniu, więc filtrowanie i stronicowanie są jednoznaczne. Kolekcja wersji wymagałaby
semantyki „istnieje wariant taki, że…”, a wtedy trzeba by jeszcze rozstrzygać, które warianty
pokazać w odpowiedzi — to była jedna z blokujących uwag w recenzji poprzedniego podejścia.

**Dlaczego nie ma `GET /v1/versions/{id}`.** Odpowiada na to `GET /v1/variants?versionId={id}`,
który zwraca wszystkie warianty jednej wersji wraz z jej danymi. Osobny endpoint byłby drugim
sposobem na zadanie tego samego pytania.

**Identyfikatory.** Wszystkie `id` w API to `public_id` — UUID nadawany przy wstawieniu wiersza
i nigdy niezmieniany. **Klucz główny z bazy nie wychodzi na zewnątrz nigdy**, w tym w kursorze
stronicowania. Nie wychodzi też `source_ref` — jest kształtem źródła i wyciekłby przy jego
podmianie.

## 3. Kształt odpowiedzi

### `GET /v1/variants`

```json
{
  "data": [
    {
      "id": "9f1c0f1e-4b2a-4b60-9a1e-4f7c3a1d55e2",
      "acceleration0To100S": 9.3,
      "fuelConsumptionL100Km": 6.3,
      "fuelTankL": 65,
      "gearbox": "manual",
      "drive": "front",
      "version": {
        "id": "3a7b21c4-9e0d-4a11-8f2b-0c6d9a4e77b1",
        "name": "2.0 TDI 140 KM",
        "fuelType": "diesel",
        "generation": "b8",
        "bodyStyle": "sedan",
        "unresolvedSegment": null,
        "powerHp": 140,
        "engineDisplacementCcm": 1968,
        "bootCapacityL": 480,
        "model": {
          "id": "b21e9d70-5c3a-4f88-9d21-6a0f2c5b8e34",
          "name": "A4",
          "brand": {
            "id": "6d2f8a11-77c4-4a3e-b0d9-1e5a9c3f2b70",
            "name": "Audi"
          }
        }
      }
    }
  ],
  "page": {
    "limit": 50,
    "next": "eyJhIjoiOS4zIiwicCI6IjlmMWMwZjFlLTRiMmEtNGI2MC05YTFlLTRmN2MzYTFkNTVlMiJ9"
  }
}
```

Reguły kształtu:

- **Pole nieznane źródłu jest `null`, nigdy nie znika z odpowiedzi.** Klient nie musi rozróżniać
  „brak pola” od „brak wartości”, a kształt odpowiedzi jest stały.
- `acceleration0To100S` i `fuelConsumptionL100Km` są liczbami JSON z jednym miejscem po
  przecinku; `powerHp` też jest liczbą (może mieć część ułamkową).
- Jednostka jest w nazwie pola. Nie ma osobnego pola z jednostką ani zagnieżdżonego obiektu
  `{value, unit}` — jednostka jest stała dla pola i wysyłanie jej przy każdym wierszu to szum.
- `gearbox` ∈ `{manual, automatic, null}`, `drive` ∈ `{front, rear, all, null}`.
  **`gearbox_unmapped_label` i `drive_unmapped_label` nigdy nie wychodzą na zewnątrz** — to
  polski tekst ze źródła, czyli dokładnie to, czego API nie ma zdradzać. Etykieta nierozpoznana
  objawia się klientowi jako `null`.
- `version.fuelType` ∈ `{petrol, diesel, hybrid, electric}` i **nigdy nie jest `null`** — to
  jedyne pole wersji z gwarancją obecności.
- `version.generation`, `version.bodyStyle` i `version.unresolvedSegment` to etykiety ze źródła,
  przekazywane bez normalizacji, i **wykluczają się**: albo wypełnione są dwie pierwsze
  (ścieżka 5-segmentowa), albo trzecia (4-segmentowa), albo żadna (3-segmentowa).
  `unresolvedSegment` niesie segment, o którym **źródło nie mówi**, czy jest generacją, czy
  nadwoziem — API oddaje go klientowi takim, jaki jest, zamiast zgadywać
  (`docs/source-contract.md` §3.1).
- Marka i model są zagnieżdżone w wersji, a nie płaskie, żeby ich identyfikatory dały się podać
  wprost jako `?brandId=` / `?modelId=` bez zgadywania, do czego należą.

### `GET /v1/brands`

```json
{"data": [{"id": "6d2f8a11-…", "name": "Audi"}], "page": {"limit": 50, "next": null}}
```

### `GET /v1/models`

```json
{"data": [{"id": "b21e9d70-…", "name": "A4",
           "brand": {"id": "6d2f8a11-…", "name": "Audi"}}],
 "page": {"limit": 50, "next": null}}
```

### `GET /v1/variants/{id}`

Pojedynczy obiekt wariantu (bez opakowania `data`), o kształcie jak element kolekcji.
Nieznany identyfikator → `404`. Wartość, która nie jest UUID → `400` (a nie `404`: to błąd
składni żądania, nie brak zasobu).

### `GET /v1/search`

Podpowiedzi do pola wyszukiwania. Zwraca **tylko to, co potrzebne do narysowania wiersza
podpowiedzi** — nie cały wariant. Pełne dane pobiera się dopiero po wyborze, przez
`GET /v1/variants/{id}` z identyfikatorem stąd.

```json
{
  "data": [
    {
      "id": "9f1c0f1e-4b2a-4b60-9a1e-4f7c3a1d55e2",
      "brand": "Audi",
      "model": "A4",
      "version": "2.0 TDI 140 KM",
      "fuelType": "diesel",
      "generation": "b8",
      "bodyStyle": "sedan",
      "unresolvedSegment": null,
      "gearbox": "manual",
      "drive": "front"
    }
  ]
}
```

Reguły:

- **Jednostką wyniku jest wariant**, bo tylko na tym poziomie określone są wszystkie cztery
  szczeble taksonomii naraz. Dlatego `id` można podać wprost do `GET /v1/variants/{id}`.
- **Nie ma `page`.** Wyniki są uszeregowane trafnością, a nie porządkiem totalnym, więc
  stronicowanie kluczem nie miałoby stabilnej podstawy — i nikt nie stronicuje podpowiedzi.
  Zamiast tego `limit` z twardym sufitem 20 (ADR 0020).
- Kolejność jest deterministyczna: trafność malejąco, dalej marka, model, nazwa wersji
  i identyfikator. Dwa identyczne żądania zwracają identyczną listę.
- **Ostatni token frazy jest dopasowywany przedrostkiem**, pozostałe dokładnie. Dzięki temu
  `audi a4 td` znajduje `2.0 TDI`, a wyszukiwarka działa w trakcie pisania.
- **Literówki są poprawiane**, ale tylko w tokenach, które nie są przedrostkiem żadnego znanego
  słowa. `audi sed` zostaje bez zmian (bo `sedan` zaczyna się od `sed`), a `audi sedn` jest
  poprawiane. Trafienia dokładne zawsze wyprzedzają poprawione.
- Fraza bez ani jednego użytecznego tokenu (`?q=!@#$`) to **pusta kolekcja**, nie `400` —
  składnia żądania jest poprawna, po prostu nic nie pasuje.
- Indeks wyszukiwarki jest odświeżany po imporcie, komendą `app:search:refresh`. Między
  przebiegami jest nieaktualny o tyle, o ile nieaktualne są dane — czyli o nic, bo źródło
  jest scrapowane raz na dobę.

## 4. Filtry — biała lista

Parametr spoza tabeli **kończy się `400`**, nie jest cicho ignorowany. Literówka w nazwie
filtru dająca po cichu pełny zbiór wyników jest gorsza niż błąd.

### `GET /v1/variants`

| Parametr | Typ | Zapytanie | Indeks |
|---|---|---|---|
| `brandId` | UUID | Q-H | `car_model_brand_id_idx` → `version_car_model_id_idx` → `variant_version_id_source_ref_key` (samą markę planista przy 91 wierszach skanuje sekwencyjnie — `docs/database.md` §6) |
| `modelId` | UUID | Q-G | `car_model_public_id_key` → `version_car_model_id_idx` → `variant_version_id_source_ref_key` |
| `versionId` | UUID | Q-F | `version_public_id_key` → `variant_version_id_source_ref_key` |
| `fuelType` | `petrol` \| `diesel` \| `hybrid` \| `electric` | Q-S | **brak, celowo** — dla wartości częstych planista nakłada `Filter` przy uporządkowanym skanie (`petrol` 0,44 ms); dla rzadkich zmienia kształt planu i kosztuje 4,4 ms (`electric`), co i tak mieści się poniżej progu z ADR 0014. Uzasadnienie i pomiary: `docs/database.md` §6 |
| `gearbox` | `manual` \| `automatic` | Q-I | brak — filtr nakładany przy skanie `variant_acceleration_idx` (selektywność ~50%, indeks byłby wolniejszy) |
| `drive` | `front` \| `rear` \| `all` | Q-I | jw. (selektywność ~30–55%) |
| `accelerationMin` | liczba > 0 | Q-E | `variant_acceleration_idx` |
| `accelerationMax` | liczba > 0 | Q-D | `variant_acceleration_idx` |
| `sort` | `acceleration` \| `-acceleration` | Q-C | `variant_acceleration_idx` (skan wprost / wstecz) |
| `limit` | 1–200, domyślnie 50 | — | — |
| `cursor` | nieprzezroczysty | Q-C…Q-I | `variant_acceleration_idx` |

`brandId`, `modelId` i `versionId` są ze sobą sprzeczne tylko pozornie — wolno je podać razem,
zawężają się iloczynem. Nieznany UUID daje pustą kolekcję, nie `404`: to filtr, nie zasób.

### `GET /v1/search`

| Parametr | Typ | Zapytanie | Indeks |
|---|---|---|---|
| `q` | tekst, 1–100 znaków, **wymagany** | Q-U | `variant_search_document_idx` |
| `limit` | 1–20, domyślnie **10** | — | — |

`q` pusty, złożony z samych białych znaków albo dłuższy niż 100 znaków to `400`. Brak `q` też —
to jedyny parametr wymagany w całym API. `limit=0` i `limit=21` to `400`, nie ciche obcięcie.
Wyszukiwarka **nie przyjmuje filtrów** (`fuelType`, `brandId`, …) — zawężanie wyników należy do
`GET /v1/variants`, a mieszanie obu dałoby dwa sposoby na to samo pytanie.

### `GET /v1/models`

| Parametr | Typ | Zapytanie | Indeks |
|---|---|---|---|
| `brandId` | UUID | Q-B | `car_model_brand_id_idx` |
| `limit`, `cursor` | jw. | Q-B | brak (sortowanie 1 400 wierszy w pamięci) |

### `GET /v1/brands`

Tylko `limit` i `cursor`. Zapytanie **Q-A**, bez indeksu — 91 wierszy.

### Filtry świadomie niezaimplementowane

`powerMin` / `powerMax`, `fuelConsumptionMax`, `fuelTankMin`, `bodyStyle`, `generation`,
`unresolvedSegment`, `brandName`. Żadnego nie zamówiono. Każdy kosztuje dokładnie: jedna pozycja na białej liście,
jedna gałąź w składaniu SQL i — jeśli selektywność to uzasadni — jeden
`CREATE INDEX CONCURRENTLY`. Schemat nie wymaga zmiany. Nie dodajemy ich „na wszelki wypadek”.

### Jak składany jest SQL

Do zapytania trafiają **wyłącznie te warunki, które klient podał**. Zakazany jest wzorzec
`WHERE (:param IS NULL OR kolumna = :param)` — planista nie może wtedy użyć indeksu i wszystkie
zapytania degradują się do skanu. Zapytanie jest budowane z białej listy: nazwa kolumny pochodzi
ze stałej w kodzie, wartość zawsze przez parametr wiązany.

## 5. Stronicowanie

Stronicowanie **kluczem** (keyset), nie offsetem.

```
GET /v1/variants?limit=50
GET /v1/variants?limit=50&cursor=eyJhIjoiOS4zIiwicCI6IjlmMWMwZjFlLS4uLiJ9
```

- `page.next` to nieprzezroczysty ciąg albo `null`, gdy nie ma dalszych wyników.
- Serwer pobiera `limit + 1` wierszy; nadmiarowy wiersz oznacza istnienie następnej strony
  i nie trafia do odpowiedzi.
- Kursor koduje parę `(acceleration0To100S, id wariantu)` ostatniego zwróconego wiersza oraz
  kierunek sortowania. Predykat: `(acceleration_0_100_s, public_id) > (:a, :p)` przy sortowaniu
  rosnącym, `<` przy malejącym — jedno porównanie krotek, obsługiwane wprost przez
  `variant_acceleration_idx`.
- **W kursorze nie ma klucza z bazy.** Kursor jest zakodowany (base64url), nie zaszyfrowany,
  więc wszystko, co w nim jest, uznajemy za jawne — i dlatego para składa się z `public_id`,
  a nie z `id`. To także powód, dla którego indeks sortujący jest zbudowany na `public_id`.
- **Kursor musi pasować do reszty żądania.** Zmiana `sort` albo któregokolwiek filtru przy
  zachowanym kursorze daje `400`, a nie ciche pominięcie wyników. Kursor niosący kierunek
  pozwala to wykryć bez podpisywania go kluczem.
- Kursor niepoprawny składniowo → `400`.

Dlaczego nie offset: `LIMIT … OFFSET 20000` liczy i odrzuca 20 000 wierszy, a przy równoległym
imporcie gubi i powiela wiersze między stronami. Keyset nie ma obu tych wad i tutaj jest wręcz
tańszy, bo indeks sortujący i tak istnieje.

Dlaczego nie ma `total`: policzenie liczby wyników wymaga drugiego zapytania po całym zbiorze,
którego żaden `LIMIT` nie skróci — czyli podwaja koszt każdego żądania, żeby dostarczyć liczbę,
której klient stronicujący kluczem i tak nie użyje.

## 6. Błędy — RFC 9457

`Content-Type: application/problem+json`. Zawsze pełny obiekt, nigdy goły tekst ani pusta treść.

```json
{
  "type": "https://api.example.com/problems/invalid-parameter",
  "title": "Invalid request parameter",
  "status": 400,
  "detail": "Parameter 'sort' must be one of: acceleration, -acceleration.",
  "instance": "/v1/variants",
  "errors": [
    {"parameter": "sort", "reason": "not_allowed_value"}
  ]
}
```

`errors` jest członem rozszerzającym w rozumieniu RFC 9457 §3.2 — wolno go pominąć, gdy błąd
nie dotyczy konkretnych parametrów. Klient rozpoznaje rodzaj błędu po `type`, nie po `detail`,
który jest tekstem dla człowieka i może się zmienić.

| `type` (skrót) | Status | Kiedy |
|---|---|---|
| `invalid-parameter` | 400 | nieznany parametr, zła wartość, zły UUID, zły albo niepasujący kursor |
| `unauthorized` | 401 | brak nagłówka `X-API-Key` albo nieznany klucz |
| `not-found` | 404 | poprawny UUID, którego nie ma; nieznana ścieżka |
| `method-not-allowed` | 405 | metoda inna niż `GET` (z nagłówkiem `Allow: GET`) |
| `internal-error` | 500 | wszystko inne |

`500` nie zawiera `detail` z komunikatem wyjątku ani śladu stosu — zamiast tego niesie
`traceId`, ten sam, który trafia do logu:

```json
{"type": "…/internal-error", "title": "Internal server error", "status": 500,
 "instance": "/v1/variants", "traceId": "01J8Z…"}
```

Nie ma `403` — nie ma uprawnień do rozróżnienia. Nie ma `429` — nie ma dziś limitowania tempa
po stronie API (limit tempa dotyczy adaptera wobec źródła, nie klientów naszego API). Gdyby
doszło, dołoży `429` i nagłówek `Retry-After`.

## 7. Czego API nie robi

- Nie ma `POST`, `PUT`, `PATCH`, `DELETE` — pod żadną ścieżką.
- Nie ma nagłówków pamięci podręcznej (`ETag`, `Cache-Control`) na odpowiedziach. Dane zmieniają
  się raz na dobę, więc korzyść jest realna, ale nikt tego nie zamówił, a poprawne unieważnianie
  przy filtrach jest pułapką (odpowiedź może się zmienić, bo wiersz **wypadł** ze zbioru, mimo
  że żaden zwrócony wiersz się nie zmienił). Do dołożenia, gdy pojawi się mierzona potrzeba.
- Nie negocjuje typu treści. Zawsze JSON; nagłówek `Accept` jest ignorowany, `406` nie występuje.
- Nie ma HATEOAS, nie ma `_links`, nie ma osadzania sterowanego parametrem (`?expand=`).
  Kształt odpowiedzi jest jeden i stały.
- Nie ujawnia liczby wierszy w bazie ani ich kolejności wstawiania — `public_id` jest losowy
  (UUID v4 z `gen_random_uuid()`), a nie rosnący.
