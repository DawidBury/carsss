# Cars API — baza wiedzy projektu

REST API serwujące dane techniczne samochodów. Dane pochodzą ze scrapowania
autocentrum.pl; lista adresów jest w `cars_details.xml` (37 395 wpisów, w tym 28 892 strony silnikowe).

Ten plik zawiera **ustalenia wiążące**. Agent, który uważa którekolwiek za błędne, ma to
powiedzieć wprost i uzasadnić — ale nie wolno mu odejść od nich po cichu.

## Stos

| | |
|---|---|
| PHP | 8.4 (`>=8.4.1`, lokalnie 8.4.5) |
| Symfony | 8.1 |
| Baza | PostgreSQL 17 |
| Kolejka | RabbitMQ 3.13 (`symfony/amqp-messenger`) |
| Kontenery | Docker Compose lokalnie i produkcyjnie — jeden host EC2 (ADR 0021) |
| Logi | Monolog → JSON na `stderr`; workery importu przez sterownik `awslogs` do CloudWatch (ADR 0022) |
| Jakość | PHPUnit, PHPStan (max), PHP-CS-Fixer |

## Decyzje architektoniczne (wiążące)

### 1. Bez DDD — obowiązkowo

Właściciel projektu odrzucił DDD po poprzednim podejściu. **Zakazane:** bounded contexty,
agregaty, encje domenowe wymuszające niezmienniki, value objecty jako domyślne opakowanie
skalarów, interfejsy repozytoriów w warstwie domeny, zdarzenia domenowe, podział katalogów
na `Domain/Application/Infrastructure/Presentation`.

**Zamiast tego:** moduły funkcjonalne nazwane od tego, co robią, a w środku katalogi nazwane
od roli pliku. Granice modułów mają być czytelne, a zależności jednokierunkowe — brak DDD
nie jest licencją na bałagan.

### 2. CQRS tam, gdzie ma sens

Zapis i odczyt mają tu realnie różny kształt, więc są rozdzielone:
- **zapis** — idempotentny upsert całej strony w jednej transakcji, wywoływany z workera,
- **odczyt** — filtrowane, stronicowane zapytanie SQL prosto do DTO odpowiedzi.

Nie budujemy drugiej szyny, osobnej bazy odczytowej, projekcji ani spójności ostatecznej.
Gdzie zapis i odczyt mają ten sam kształt, CQRS **nie** jest stosowane i musi to być zapisane.

### 3. SOLID, DRY, YAGNI

YAGNI ma tu szczególną wagę: poprzednie podejście zostało odrzucone głównie za nadmiarowość.
Abstrakcja z jedną implementacją i bez konkretnego planu na drugą jest defektem. Kolumna,
której nic nie zapisuje, jest defektem. Nadmiarowa inżynieria jest traktowana tak samo
poważnie jak brak projektu.

### 4. Zapisujemy tylko to, o co poproszono

| Pole | Poziom | Wymagalność |
|---|---|---|
| przyspieszenie 0–100 km/h | wariant napędu | **wymagane** |
| średnie spalanie | wariant napędu | opcjonalne |
| pojemność zbiornika paliwa | wariant napędu | opcjonalne |
| moc | wersja | opcjonalne |
| pojemność silnika | wersja | opcjonalne |
| pojemność bagażnika | wersja | opcjonalne |
| rodzaj paliwa | wersja | wymagane (wyprowadzane z adresu URL) |

Przypisanie do poziomu **wynika z pomiaru źródła**, nie z intuicji — szczegóły i liczby
w `docs/source-contract.md`. Pojemność baku wbrew oczekiwaniom należy do wariantu, nie wersji.

Wymagalność przyspieszenia jest egzekwowana przez `NOT NULL` w bazie. Wariant bez
przyspieszenia nie jest zapisywany. Wersja, dla której nie ocalał żaden wariant, nie jest
zapisywana — to ~17% stron i są to zaślepki bez danych napędu.

### 5. Taksonomia: cztery poziomy

```
brand → car_model → version (strona silnikowa) → variant (skrzynia/napęd)
```

Generacja i nadwozie są **nullowalnymi kolumnami tekstowymi** na `version`, nie osobnymi
encjami. Powód: przy ścieżce czterosegmentowej (8 078 adresów) środkowy segment jest
dwuznaczny — `ii` to generacja, `sedan` to nadwozie, a pozycja nie rozstrzyga. Zmierzono,
że breadcrumb tego nie rozstrzyga: skleja nazwy narastająco, nie etykietując poziomów.
Poprzednie podejście budowało pod to silnik rozstrzygania i to on był głównym źródłem
błędów w recenzjach.

Dlatego obowiązuje reguła bez zgadywania:

| Głębokość ścieżki | Co zapisujemy |
|---|---|
| 5 | generacja i nadwozie wypełnione z pozycji segmentów |
| 4 | segment trafia do osobnej kolumny na **nierozstrzygnięty** segment |
| 3 | wszystkie trzy kolumny `NULL` |

Nic nie ginie i nic nie jest zgadywane. Rozstrzygnięcie tych segmentów później nie
wymaga ponownego scrapowania. Wypromowanie kolumn do tabel nie zmieni kontraktu API.

### 6. Podmienialne źródło danych

Wymaganie wprost postawione: musi dać się zamienić scraper na wywołanie API bez zmian
w reszcie systemu. Realizacja przez **jeden wąski port** pozyskania danych. Dodanie drugiego
źródła ma kosztować: nowy katalog adaptera + jeden wpis w konfiguracji. Nic więcej.
Parsowanie HTML, jednostki i polskie etykiety żyją **wyłącznie** w adapterze — reszta systemu
nie wie, że dane pochodzą ze strony WWW.

### 7. Dostęp do API

Klucz API w nagłówku `X-API-Key`. Jedna tabela z hashem klucza. Bez OAuth2 — poprzednie
podejście miało pod to cztery tabele, z czego dwie nigdy nie zapisywane.

### 8. API tylko do odczytu

Scrapowanie uruchamiane komendą konsolową, która publikuje wiadomości na RabbitMQ; workery
konsumują i zapisują. Brak endpointów HTTP sterujących importem. Na AWS import uruchamia
timer systemd na hoście (`docs/deployment.md`).

## Context engineering

Każdy moduł ma własny `CLAUDE.md` z bazą wiedzy o sobie: za co odpowiada, jakie ma granice,
jakich decyzji lokalnych nie wolno cofnąć i jakie są w nim pułapki. Agent pracujący w module
czyta ten plik **przed** zmianą. Agent zmieniający zachowanie modułu aktualizuje ten plik
w tym samym zadaniu.

## Agenci

| Agent | Model | Rola |
|---|---|---|
| `architect` | opus | struktura, schemat bazy, ADR-y, `CLAUDE.md` modułów |
| `developer` | sonnet | cały kod produkcyjny i testy |
| `design-reviewer` | opus | recenzja projektu, bazy, granic, szwów, YAGNI |
| `code-reviewer` | sonnet | recenzja kodu, przypadki brzegowe, pułapki parsera |

Przepływ: `architect` projektuje → `design-reviewer` recenzuje → `developer` implementuje →
`code-reviewer` recenzuje. Defekt blokujący wraca do autora, nie jest naprawiany przez recenzenta.

### 9. Doctrine ORM z encjami, DBAL do zapytań złożonych

**Decyzja zmieniona przez właściciela projektu 2026-09-03.** Poprzednio obowiązywało
„tylko DBAL, bez encji". Obowiązuje teraz podział:

| Warstwa | Narzędzie |
|---|---|
| encje i mapowanie schematu | **ORM** |
| zapis importu: `version`, `variant` | **ORM** |
| zapis importu: upsert `brand`, `car_model` | **DBAL**, `ON CONFLICT DO NOTHING` |
| odczyt pojedynczego rekordu po identyfikatorze | **ORM** |
| listy z filtrami i stronicowaniem kluczem | **DBAL** |

Powód wyłączenia `brand` i `car_model` z ORM: to wiersze gorące, współdzielone przez tysiące
stron. `DO UPDATE` bierze blokadę wiersza zawsze, a ORM robi SELECT-potem-INSERT, co przy
czterech workerach wprowadza wyścig, którego dziś nie ma (ADR 0005). `version` i `variant`
są lokalne dla jednej strony, więc idą przez ORM bez tego ryzyka.

**Schemat bazy pozostaje źródłem prawdy, nie mapowanie.** Migracje piszemy ręcznie
(`doctrine:migrations:generate`), bo mapowanie nie wyraża 4 wyzwalaczy, 30 ograniczeń `CHECK`,
indeksu częściowego ani funkcji PL/pgSQL — `doctrine:migrations:diff` chciałby je usunąć.
`diff` wolno użyć wyłącznie do obejrzenia różnicy, nigdy do wygenerowania migracji do commita.
Zgodność mapowania ze schematem pilnuje test oparty o `doctrine:schema:validate`.

Zakaz repozytoriów z §1 dotyczył repozytoriów jako wzorca DDD w warstwie domeny. Repozytoria
Doctrine (`EntityRepository`) jako miejsce na zapytania ORM są dozwolone.

**Encje mieszkają w `src/Entity` (`App\Entity`), poza wszystkimi modułami**, bo są współdzielone:
pisze je `Import`, czyta `Catalog`. `App\Entity` nie zależy od niczego z `App\`; wolno od niego
zależeć `Import` i `Catalog`, ale **nie** `Api` i **nie** `Source`. Encje nie są encjami domenowymi
z §1: nie wymuszają niezmienników, nie opakowują skalarów, nie publikują zdarzeń — są typowanym
odwzorowaniem wiersza. Projekt mapowania, kontrakt zapisu strony i mechanizm wykrywania rozjazdu:
`docs/orm.md`. Decyzja i odrzucone alternatywy: ADR 0018.

### 10. Liczba workerów importu zależy od zasięgu limitera

Limit 2 żądań na sekundę jest nakładany **per proces**, więc liczba workerów, którą wolno
uruchomić, zależy od tego, czy procesy dzielą wiadro tokenów (ADR 0015, pomiary
w `docs/architecture.md` §5):

| Środowisko | Zasięg limitera | Workerów | Pełny przebieg |
|---|---|---|---|
| lokalnie (Docker Compose) | **współdzielony** — repliki montują ten sam `var/` | **4** | ~3,8 h |
| AWS, jeden host EC2 (ADR 0021) | **współdzielony** — repliki montują wolumen `app_share` na `/app/var/share` | **4** | ~3,8 h |
| AWS ECS/Fargate | **per zadanie** | **1** | ~19 h |

Jeden worker **nie wysyca** limitu: wąskim gardłem jest ~2,5 s obsługi strony, nie ogranicznik.
Podniesienie `desired_count` na ECS jest dozwolone dopiero po przeniesieniu stanu limitera do
magazynu współdzielonego — to zmiana projektowa wymagająca ADR, nie parametr konfiguracji.
Na hoście EC2 ten warunek jest spełniony wspólnym wolumenem, nie obchodzony.
Kolejne przebiegi są znacznie krótsze dzięki `ETag`.

## Standard kodu

- `declare(strict_types=1);` wszędzie, `final` domyślnie, właściwości promowane i `readonly`.
- Enumy zamiast stałych łańcuchowych.
- **Zero komentarzy — w kodzie i w plikach konfiguracyjnych.** Dotyczy PHP, YAML, Dockerfile,
  compose, nginx, `.env`, Makefile i wszystkiego innego. Kod wymagający wyjaśnienia dostaje
  lepszą nazwę albo wydzieloną metodę; konfiguracja wymagająca wyjaśnienia dostaje wpis
  w dokumentacji, nie komentarz w pliku. Jedyne wyjątki: atrybuty frameworka, PHPDoc
  z generykami dla analizy statycznej oraz znaczniki `###> ... ###<` w `.env`, które są
  zarządzane maszynowo przez Symfony Flex i ich usunięcie psuje `composer recipes`.
- **Język.** Nowe pliki i nowa dokumentacja powstają **po angielsku**, łącznie z plikami
  `CLAUDE.md` i zawartością `docs/`. Pliki już istniejące po polsku zostają bez zmian —
  przetłumaczymy je osobnym zadaniem. Kod, nazwy tabel, kolumn i identyfikatorów zawsze
  po angielsku.
- Kontroler tłumaczy HTTP na komendę/zapytanie i z powrotem. Nic więcej.
- Parser nie wie o bazie. Zapis nie wie o HTML.

## Weryfikacja przed zgłoszeniem gotowości

```
bin/console doctrine:schema:validate --skip-sync
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix --dry-run --diff
```

Kontrola zgodności mapowania ze schematem **nie może** opierać się na kodzie wyjścia
`doctrine:schema:validate` bez `--skip-sync`: DBAL 4.4 nie introspekuje
`GENERATED ALWAYS AS IDENTITY`, więc pięć różnic jest w niej na stałe. Porównanie z zatwierdzoną
listą tych pięciu jest testem — `docs/orm.md` §9.

Prawdziwe wyjście albo nic. Testy parsera wyłącznie na utrwalonych fixture'ach HTML,
nigdy przez żywe HTTP.

## Etykieta wobec źródła

2 żądania na sekundę, burst 5. Prawdziwy, kontaktowy `User-Agent`. Żadnych query stringów
(`robots.txt` zabrania `/*?`). Conditional GET przez `ETag`.

## Dokumenty

| Plik | Zawartość |
|---|---|
| `docs/source-contract.md` | zmierzony kontrakt źródła — selektory, etykiety, pułapki, liczby |
| `docs/source-survey.json` | surowe wyniki pomiaru na 78 stronach |
| `docs/architecture.md` | moduły, kierunek zależności, przepływ importu, układ katalogów IaC |
| `docs/database.md` | schemat, uzasadnienie każdego ograniczenia i indeksu, migracje na żywej tabeli |
| `docs/orm.md` | encje i mapowanie, kontrakt zapisu strony, wykrywanie rozjazdu ze schematem |
| `docs/api.md` | kontrakt HTTP — zasoby, filtry, stronicowanie, format błędów |
| `docs/deployment.md` | runbook wdrożenia na AWS — bootstrap, operacje, odtwarzanie, pułapki |
| `docs/adr/` | decyzje architektoniczne |

Każdy fakt ma **jedno** miejsce. Pomiary źródła są w `source-contract.md`, schemat w
`database.md`, kontrakt HTTP w `api.md`. Powielenie faktu w drugim dokumencie jest defektem —
w poprzednim podejściu rozjechały się w ten sposób cztery dokumenty naraz.
