# Moduł `Api` — HTTP

Przeczytaj ten plik **przed** zmianą czegokolwiek w tym module. Jeśli zmieniasz zachowanie
modułu, zaktualizuj ten plik w tym samym zadaniu.

## Za co odpowiada

Tłumaczenie HTTP na kryterium zapytania i wyniku na JSON. Uwierzytelnienie kluczem API.
Format błędu. Wydanie klucza API komendą konsolową.

Kontrakt, którego ten moduł jest wykonaniem: `docs/api.md`. Ten plik go **nie powiela** —
opisuje tylko to, czego kod ma pilnować.

## Granice

**Zależy od:** `App\Catalog`, Symfony HttpKernel/HttpFoundation.

**Nie wolno tu**: SQL, nazw tabel i kolumn, Doctrine DBAL, Doctrine ORM, **`App\Entity`**,
`App\Import`, `App\Source`, niczego o HTML-u i o tym, skąd pochodzą dane.

`App\Entity` istnieje od ADR 0018 i **nie wolno go tu użyć**. Do kontrolera wchodzą wyłącznie DTO
z `App\Catalog\Result`. Encja podana serializatorowi wciąga leniwe ładowanie do warstwy HTTP
(zapytanie do bazy w trakcie budowania odpowiedzi) i stawia `id` oraz `source_ref` o jedno
odwołanie od treści odpowiedzi — a oba mają nie opuszczać `Catalog`.

**Tabele:** czyta `api_key` (filtr uwierzytelniający), pisze `api_key` (komenda tworząca klucz).
To jedyne miejsce, które dotyka tej tabeli, i jedyne miejsce w projekcie, gdzie zapis i odczyt
tej samej tabeli **świadomie nie są rozdzielone** — ten sam kształt, dwa razy w roku (ADR 0002).

## Decyzje lokalne, których nie wolno cofnąć

1. **Kontroler tłumaczy HTTP na kryterium i z powrotem. Nic więcej.** Bez SQL, bez warunków
   biznesowych, bez formatowania liczb.
2. **Walidacja wejścia jest tutaj, nie w `Catalog`.** Do `Catalog` wchodzi kryterium
   o poprawnych typach i wartościach z dozwolonych zbiorów.
3. **Biała lista parametrów jest zamknięta.** Parametr spoza listy → `400`. Ciche ignorowanie
   nieznanego parametru jest zakazane: literówka w nazwie filtru dawałaby po cichu pełny zbiór
   wyników.
4. **Wszystkie błędy w `application/problem+json` (RFC 9457).** Nigdy goły tekst, nigdy pusta
   treść, nigdy domyślna strona błędu Symfony. Dotyczy też `404` na nieznanej ścieżce
   i `405` na złej metodzie.
5. **`500` nie ujawnia komunikatu wyjątku ani śladu stosu.** Niesie `traceId`, ten sam, który
   trafia do logu.
6. **Brak nagłówka `X-API-Key` i zły klucz dają identyczną odpowiedź `401`.** Różnicowanie
   powiedziałoby atakującemu, że klucz istnieje.
7. **`GET /health` jest poza `/v1`, bez uwierzytelnienia i nie dotyka bazy.** Odpytuje go
   target group ALB, który nie ma klucza. Sprawdzanie bazy w health checku zamienia awarię
   bazy w wyrejestrowanie wszystkich zadań ECS, czyli awarię częściową w całkowitą.
8. **`sha256` klucza, nie `bcrypt`/`argon2`.** Sekret ma 256 bitów entropii, więc atak
   słownikowy nie istnieje, a funkcja z solą uniemożliwiłaby wyszukanie po indeksie
   (ADR 0011). Nie „popraw” tego.
9. **Klucz jawny nie jest nigdzie zapisywany.** Komenda `app:api-key:create` wypisuje go raz.
10. **Tylko `GET`.** Nie dodawaj żadnej metody zapisującej pod żadną ścieżką — API jest
    z założenia tylko do odczytu, a import uruchamia konsola.
11. **Kursor jest nieprzezroczysty dla klienta, ale nie jest zaszyfrowany.** Wszystko, co
    w nim jest, uznaj za jawne — dlatego zawiera `public_id`, a nigdy klucza z bazy.

## Pułapki

1. **`{id}`, który nie jest UUID, to `400`, nie `404`.** To błąd składni żądania, nie brak
   zasobu. Nieznany, ale poprawny UUID to `404`.
2. **Nieznany UUID w **filtrze** (`?brandId=`) to pusta kolekcja, nie `404`.** Filtr nie jest
   zasobem.
3. **`WWW-Authenticate: ApiKey realm="cars-api"` przy `401`.** Wymagane przez RFC 7235,
   łatwe do przeoczenia przy własnym schemacie uwierzytelniania.
4. **Klucz w nagłówku, nigdy w query stringu.** Query string trafia do logów serwera.
5. **Liczby dziesiętne w JSON.** `acceleration0To100S` ma być liczbą (`9.3`), nie napisem
   (`"9.3"`). `NUMERIC` z PostgreSQL wraca do PHP jako `string` — konwersja jest tutaj i musi
   być świadoma.
6. **Porównanie skrótu klucza.** Zapytanie idzie po indeksie unikalnym na skrócie; samo
   porównanie skrótów nie jest sekretem i nie wymaga porównania stałoczasowego. Nie znaczy to,
   że wolno logować wartość nagłówka — nie wolno.
7. **`fuelType` przyjmuje wyłącznie `petrol|diesel|hybrid|electric`** — te same wartości, co
   `CHECK` w bazie. Wartość spoza zbioru to `400`, nie pusta kolekcja: klient ma się dowiedzieć,
   że pomylił słownik, a nie że nie ma takich aut.
8. **`unresolvedSegment` wychodzi w odpowiedzi i to jest zamierzone.** Nie ukrywaj go i nie
   scalaj z `generation` ani `bodyStyle`. Niesie uczciwą informację: „segment istnieje, źródło
   nie mówi, czym jest" (ADR 0003). Nie jest filtrem.
9. **Domyślny `limit` to 50, maksymalny 200.** `limit=0` i `limit=201` to `400`, nie ciche
   obcięcie. **Wyszukiwarka ma własne granice: domyślnie 10, maksymalnie 20** — `limit=21` na
   `/v1/search` to `400`. Obie pary granic wychodzą z tej samej metody
   `QueryValidation::limitBetween()`; nie duplikuj walidacji.
10. **`q` w `/v1/search` jest jedynym parametrem wymaganym w całym API.** Jego brak, pusta
    wartość i sama biała znakowość to `400` z `reason` odpowiednio `missing_parameter`
    i `invalid_value`. Fraza dłuższa niż 100 znaków to też `400` — bez tego jedno żądanie
    może zbudować dowolnie długie `tsquery`.
11. **Fraza bez ani jednego użytecznego tokenu to pusta kolekcja, nie `400`.** `?q=!@#$` ma
    poprawną składnię żądania — po prostu nic nie pasuje. To ta sama zasada, co przy nieznanym
    UUID w filtrze (pułapka 2).
12. **`/v1/search` nie ma `page` i nie przyjmuje filtrów.** Wyniki są uszeregowane trafnością,
    która nie jest porządkiem totalnym, więc kursor nie miałby na czym stanąć (ADR 0020).
    Zawężanie należy do `/v1/variants` — nie dokładaj tu `fuelType` ani `brandId`.
13. **Wyszukiwarka zwraca wariant, nie wersję.** `id` z `/v1/search` idzie wprost do
    `GET /v1/variants/{id}` i to jest cały drugi krok przepływu. Nie dodawaj endpointu
    „pobierz szczegóły podpowiedzi" — istnieje.
