# Cars API

**Dane są pobierane ze strony internetowej Autocentrum; projekt nie jest przeznaczony do celów komercyjnych.**

REST API z danymi technicznymi samochodów. Dane pozyskiwane są przez scrapowanie
autocentrum.pl na podstawie listy adresów w `cars_details.xml` (28 892 strony silnikowe).

Kluczową daną jest **przyspieszenie 0–100 km/h dla konkretnego wariantu napędu**
(skrzynia, napęd) — jest obowiązkowa. Opcjonalnie zapisujemy moc, pojemność silnika,
pojemność bagażnika, pojemność baku i średnie spalanie.

## Stos

PHP 8.4 · Symfony 8.1 · PostgreSQL 17 · RabbitMQ 3.13 · Docker · AWS (jeden host EC2)

## Start

```bash
make build      # zbuduj obrazy
make up         # Postgres, RabbitMQ, php-fpm, nginx, 4 workery
make migrate    # schemat bazy
```

API odpowiada na `http://localhost:8080`, panel RabbitMQ na `http://localhost:15672`
(login i hasło: `cars`). Postgres jest wystawiony na porcie **5433**, żeby nie kolidować
z lokalną instalacją na 5432. Wszystkie porty nadpiszesz zmiennymi `HTTP_PORT`,
`POSTGRES_PORT`, `RABBITMQ_PORT`.

## Import danych

```bash
make import-sample   # 50 stron — do sprawdzenia parsera
make import          # pełny przebieg ze sitemapu
```

Komenda wyłącznie **kolejkuje** strony do pobrania. Pobieraniem i zapisem zajmują się
workery konsumujące kolejkę RabbitMQ.

Tempo pobierania jest celowo ograniczone do **2 żądań na sekundę**.

### Ile workerów

`make up` startuje **cztery** workery — `compose.yaml` deklaruje je przez `deploy.replicas`,
zgodnie z konfiguracją lokalną z `CLAUDE.md` §10. Pełny import trwa na nich około
**3,8 godziny**.

Jeden worker nie wysyca limitu — wąskim gardłem jest czas obsługi strony, nie ogranicznik —
więc ten sam przebieg na jednym trwałby około 19 godzin.

Cztery workery są bezpieczne **wyłącznie lokalnie**, gdzie repliki montują ten sam katalog
`var/` i dzielą jedno wiadro tokenów. Na ECS każde zadanie ma własny system plików, więc
każde dostałoby własne wiadro; tam obowiązuje jedno zadanie, a `desired_count > 1` wymaga
najpierw przeniesienia stanu limitera do magazynu współdzielonego. Liczba workerów **nie jest
parametrem do podkręcenia** — pomiary, uzasadnienie i warunek skalowania: `CLAUDE.md` §10
i ADR 0015.

Kolejne przebiegi są znacznie krótsze, bo `ETag` pozwala pominąć niezmienione strony.

### Po każdej zmianie kodu zrestartuj workera

```bash
make restart-worker
```

Worker jest procesem długo żyjącym: kompiluje kontener wstrzykiwania zależności przy starcie
i trzyma go w pamięci przez cały przebieg. Zmiana sygnatury konstruktora albo dopisanie usługi
**nie dociera** do działającego workera — będzie padał na każdej wiadomości z błędem o starym
kształcie zależności, mimo że kod na dysku jest poprawny. Mylące jest przy tym to, że
`docker compose logs worker` pokazuje również linie sprzed restartu, więc łatwo uznać, że
restart nie pomógł. Rozstrzyga dopiero `--since`, albo sprawdzenie, czy dane przyrastają.

Import jest idempotentny — powtórny przebieg aktualizuje istniejące rekordy zamiast
tworzyć duplikaty, a strony niezmienione od poprzedniego razu są pomijane dzięki `ETag`.

### Po imporcie odśwież indeks wyszukiwarki

```bash
make search-refresh
```

`GET /v1/search` czyta z dwóch widoków materializowanych, a nie wprost z tabel. Import ich nie
odświeża — indeks wyszukiwarki należy do modułu `Catalog`, a `Import` nie ma prawa od niego
zależeć, więc kolejność kroków pilnuje runbook, nie kod (ADR 0020). Pominięcie tego kroku
niczego nie psuje: wyszukiwarka po prostu zwraca wyniki sprzed poprzedniego przebiegu.

## Wyszukiwarka

```bash
curl -H "X-API-Key: $KEY" 'http://localhost:8080/v1/search?q=audi+a4+tdi'
```

Zwraca do 20 podpowiedzi (domyślnie 10), każda z marką, modelem, wersją i wariantem. `id`
z podpowiedzi idzie wprost do `GET /v1/variants/{id}` po komplet danych. Ostatni token frazy
jest dopasowywany przedrostkiem, więc działa w trakcie pisania, a literówki w pełnych słowach
są poprawiane słownikiem nazw. Stoi to na full-text search PostgreSQL, nie na osobnym silniku —
pomiary i próg, po którym warto to zmienić, są w ADR 0020.

## Weryfikacja

```bash
make check      # testy + analiza statyczna + styl kodu
```

Testy parsera działają na utrwalonych fixture'ach HTML w `tests/Fixtures/Source/Autocentrum/`,
nigdy przez żywe HTTP. Opis każdego fixture'a i przypadku brzegowego, który pokrywa,
jest w `manifest.json` w tym katalogu.

## Dokumentacja

| Plik | Zawartość |
|---|---|
| `CLAUDE.md` | ustalenia projektowe wiążące dla całego zespołu |
| `docs/architecture.md` | podział na moduły i zależności |
| `docs/database.md` | schemat PostgreSQL wraz z uzasadnieniem każdej decyzji |
| `docs/api.md` | kontrakt REST API |
| `docs/source-contract.md` | zmierzony kontrakt źródła: selektory, etykiety, pułapki |
| `docs/deployment.md` | wdrożenie na AWS: bootstrap, operacje, odtwarzanie bazy |
| `docs/adr/` | decyzje architektoniczne z odrzuconymi alternatywami |

## Wdrożenie

```bash
export CARS_INSTANCE_ID=i-...
make deploy          # synchronizacja, budowa obrazu, migracje, restart
make remote-import   # pełny import na hoście i odświeżenie wyszukiwarki
```

Produkcja to **jedna instancja EC2** z tą samą topologią Compose co lokalnie: Postgres,
RabbitMQ, php-fpm, cztery workery i Caddy z certyfikatem Let's Encrypt. Pełny układ
ECS/Fargate kosztowałby ~115 USD miesięcznie przy zerowym ruchu i wymuszał jeden worker
zamiast czterech — wycena i odrzucone warianty są w ADR 0021, procedura w `docs/deployment.md`.

## Podmiana źródła danych

System nie zakłada, że dane pochodzą ze strony WWW. Parsowanie HTML, jednostki i polskie
etykiety żyją wyłącznie w adapterze źródła. Zastąpienie scrapera wywołaniem API zewnętrznego
oznacza dopisanie nowego katalogu adaptera i jednego wpisu w konfiguracji — reszta systemu
pozostaje nietknięta. Szczegóły w `docs/adr/`.
