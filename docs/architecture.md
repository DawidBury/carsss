# Architektura

Dokument opisuje podział na moduły, kierunek zależności i układ katalogów — również tych
infrastrukturalnych. Decyzje pojedyncze mają własne ADR-y w `docs/adr/`; tutaj jest obraz całości.

Fakty o źródle nie są tu powtarzane. Jedynym ich miejscem jest `docs/source-contract.md`.
Schemat bazy ma jedno miejsce: `docs/database.md`. Kontrakt HTTP: `docs/api.md`.

## 1. Co system robi

Trzy przepływy, nic więcej:

1. **Import** — komenda konsolowa wylicza rekordy do pobrania i publikuje po jednej wiadomości
   na rekord do RabbitMQ. Worker pobiera rekord przez port źródła i zapisuje go w jednej
   transakcji. Druga komenda zamyka przebieg.
2. **Odczyt** — HTTP GET, filtr z białej listy, stronicowanie kluczem, odpowiedź JSON.
3. **Administracja** — utworzenie klucza API komendą konsolową.

Nie ma nic poza tym. Brak endpointów sterujących importem, brak panelu, brak zapisu przez HTTP.

## 2. Moduły

Cztery moduły w `src/`, nazwane od tego, co robią. Żadnej warstwy `Domain`, `Application`,
`Infrastructure` ani `Presentation` (ADR 0001). Obok nich, poza podziałem na moduły, leży
`src/Entity/` — mapowanie schematu, bez zachowania (ADR 0018, `docs/orm.md`).

| Moduł | Odpowiada za | Zależy od |
|---|---|---|
| `Source` | pozyskanie danych ze świata zewnętrznego i sprowadzenie ich do jednego kształtu | — |
| `Import` | zapis: cykl życia przebiegu, kolejka, idempotentny upsert rekordu | `Source`, `Entity` |
| `Catalog` | odczyt: filtrowane, stronicowane zapytania SQL do DTO wyniku oraz indeks wyszukiwarki | `Entity` |
| `Api` | HTTP: routing, walidacja parametrów, uwierzytelnienie, serializacja, błędy | `Catalog` |

Zależności są jednokierunkowe i acykliczne:

```
Api ──► Catalog          (odczyt)
Import ──► Source        (zapis)
Source/Autocentrum ──► Source (port + kontrakt ładunku)
```

Czego **nie ma** i mieć nie może:

- `Catalog` nie wie o istnieniu `Import`, `Source` ani HTTP.
- `Import` nie wie o istnieniu `Api` ani `Catalog`.
- `Source` nie wie o istnieniu bazy danych, Doctrine, Messengera ani HTTP-owego API.
- Żaden moduł poza `Source/Autocentrum` nie wie, że dane pochodzą ze strony WWW.

Uzasadnienie każdego modułu:

**`Source`** istnieje, bo wymaganie „podmienialne źródło” jest wiążące. Gdyby scrapera nie dało
się wymienić, ten moduł byłby zbędny i parser mieszkałby w `Import`. Zawiera **port**
(`SourceCatalog` + DTO ładunku) oraz katalogi adapterów. Adapter jest jedynym miejscem w całym
systemie, gdzie żyje HTML, HTTP, jednostki (`km`, `l/100km`, `cm³`) i polskie etykiety.

**`Import`** istnieje, bo zapis ma inny kształt niż odczyt (ADR 0002) i inny cykl życia:
uruchamiany z harmonogramu, przez kolejkę, transakcyjny, idempotentny. Nie ma tu żadnego kodu
HTTP-owego API ani żadnego parsowania.

**`Catalog`** istnieje, bo odczyt to zapytanie SQL zwracające płaski wynik do serializacji.
Osobny moduł od `Import`, bo łączy je wyłącznie schemat tabel — nie wywołują się nawzajem.

**`Api`** istnieje, bo kontrolery muszą gdzieś mieszkać, a kontrakt HTTP (nazwy parametrów,
format błędu, nagłówek klucza) jest niezależny od tego, jak wygląda zapytanie SQL. Kontroler
tłumaczy HTTP na kryterium zapytania i wynik na JSON. Nic więcej.

### Czy to nie za mało modułów?

Cztery moduły na siedem tabel to właściwa proporcja. Poprzednie podejście miało więcej granic
niż realnych zależności i recenzja wytknęła to jako główny defekt. Jeśli kiedyś dojdzie drugi
kanał odczytu (np. eksport CSV), będzie to nowy moduł obok `Api`, a `Catalog` się nie zmieni —
to jest test poprawności tej granicy i ona go przechodzi.

## 3. CQRS — gdzie jest i gdzie go świadomie nie ma

Rozdzielone, bo kształt jest realnie różny:

| | zapis (`Import`) | odczyt (`Catalog`) |
|---|---|---|
| jednostka pracy | cały rekord źródła w jednej transakcji | jedno zapytanie `SELECT` |
| wyzwalacz | wiadomość z RabbitMQ | żądanie HTTP |
| kształt | upsert po kluczu naturalnym w 4 tabelach | join 4 tabel, filtr, `LIMIT` |
| model danych | ładunek z portu źródła | DTO odpowiedzi |

**Gdzie CQRS nie jest stosowane i dlaczego:**

- **`api_key`** — czyta go filtr uwierzytelniający, pisze komenda konsolowa. Ten sam kształt
  (jeden wiersz po kluczu naturalnym), dwa razy w roku. Rozdzielanie tego byłoby ceremonią.
  Oba pliki żyją w `src/Api/`.
- **`autocentrum_page_cache`** — czyta i pisze wyłącznie adapter, tym samym kształtem
  (`url → (etag, canonical_ref)`). Odczyt jest w kliencie HTTP przed żądaniem, zapis w katalogu
  po udanym sparsowaniu (ADR 0019) — to jedna ścieżka rozpięta na dwie klasy, a nie rozdział
  zapisu od odczytu.
- **`import_run`** — pisze i czyta wyłącznie `Import`, kilka wierszy na rok. Bez rozdziału.

Nie ma drugiej szyny, osobnej bazy odczytowej, projekcji ani spójności ostatecznej. Nic tego
nie uzasadnia: ~32 000 wierszy w największej tabeli i jeden zapis na dobę.

## 4. Układ katalogów

```
carsss/
├── CLAUDE.md                     ustalenia wiążące właściciela
├── cars_details.xml              lista adresów źródła (wejście importu)
├── bin/console
├── public/index.php
├── config/                       konfiguracja Symfony (nie należy do żadnego modułu)
│   ├── services.yaml             ← tu jest jedyny wpis wyboru źródła danych
│   ├── routes.yaml
│   └── packages/
├── migrations/                   migracje Doctrine (SQL)
│   ├── CLAUDE.md
│   └── Version*.php
├── src/
│   ├── Entity/                   encje Doctrine — mapowanie schematu, poza modułami
│   │   ├── CLAUDE.md
│   │   └── *.php                 ImportRun, Brand, CarModel, Version, Variant + enumy słowników
│   ├── Api/
│   │   ├── CLAUDE.md
│   │   ├── Controller/           jeden kontroler na zasób
│   │   ├── Request/              odczyt i walidacja query stringa, biała lista, kursor
│   │   ├── Response/             DTO odpowiedzi + serializacja
│   │   ├── Error/                RFC 9457: mapowanie wyjątków na problem+json
│   │   ├── Security/             filtr X-API-Key
│   │   └── Console/              app:api-key:create
│   ├── Catalog/
│   │   ├── CLAUDE.md
│   │   ├── Criteria/             obiekty kryteriów (już zwalidowane, bez HTTP)
│   │   ├── Reader/               składanie i wykonanie SQL
│   │   ├── Result/               płaskie DTO wyniku
│   │   ├── Index/                odświeżanie widoków wyszukiwarki
│   │   └── Console/              app:search:refresh
│   ├── Import/
│   │   ├── CLAUDE.md
│   │   ├── Console/              app:import:start, app:import:finish
│   │   ├── Message/              ImportRecord
│   │   ├── MessageHandler/       ImportRecordHandler
│   │   ├── Run/                  cykl życia import_run
│   │   └── Writer/               upsert rekordu w jednej transakcji
│   └── Source/
│       ├── CLAUDE.md
│       ├── SourceCatalog.php     PORT — jedyny interfejs modułu
│       ├── Contract/             DTO ładunku + enumy słowników
│       └── Autocentrum/          adapter scrapujący
│           ├── Sitemap/          czytanie cars.xml
│           ├── Http/             klient, limiter tempa, ETag
│           └── Parser/           DOM → DTO ładunku
├── infra/                        INFRASTRUKTURA — patrz §6
│   ├── CLAUDE.md
│   └── terraform/
├── docs/
│   ├── architecture.md           ten plik
│   ├── database.md               schemat i uzasadnienia
│   ├── api.md                    kontrakt HTTP
│   ├── source-contract.md        zmierzony kontrakt źródła
│   ├── source-survey.json        surowe pomiary
│   └── adr/
└── tests/
    ├── Api/ Catalog/ Import/ Source/
    └── Fixture/Autocentrum/      utrwalone HTML-e do testów parsera
```

Katalog wewnątrz modułu nazywa się od **roli pliku**, nie od warstwy: `Parser`, `Writer`,
`Reader`, `Controller`. Po nazwie katalogu widać, co jest w środku.

### Doctrine ORM z encjami, DBAL tam, gdzie zarabia na siebie

Decyzja właściciela z 2026-09-03 (`CLAUDE.md` §9, ADR 0018) zastąpiła poprzednią „tylko DBAL,
bez encji". Schemat jest zmapowany encjami, `version` i `variant` zapisywane są przez ORM,
`brand` i `car_model` zostają na DBAL-owym `ON CONFLICT DO NOTHING`, a listy z filtrami
i stronicowaniem kluczem — na surowym SQL. Pełny projekt mapowania i kontrakt zapisu:
`docs/orm.md`.

**Encje mieszkają w `src/Entity/` (`App\Entity`), poza wszystkimi modułami.** Powód jest
strukturalny, nie estetyczny: encje są współdzielone — pisze je `Import`, czyta `Catalog` —
więc umieszczenie ich w którymkolwiek z tych modułów tworzy zależność zakazaną w §4
(`Import → Catalog` albo `Catalog → Import`). `src/Entity` nie jest piątym modułem: moduł to
jednostka zachowania nazwana od tego, co robi, a tu nie ma żadnego zachowania — jest mapowanie.
Encje **nie są** encjami domenowymi w rozumieniu DDD: nie wymuszają niezmienników, nie opakowują
skalarów, nie publikują zdarzeń. Niezmienniki zostają w bazie (`CLAUDE.md` §1 obowiązuje bez zmian).

Kontrolery mieszkają nadal w `src/Api/Controller/`; katalogi `src/Repository/` i `src/Controller/`
ze szkieletu nie istnieją i nie powstaną. Żadna encja nie deklaruje `repositoryClass` — klasa
repozytorium musiałaby mieszkać w module, a encja wskazywałaby na moduł.

Praktyczna konsekwencja dla migracji jest niezmieniona, ale ma nowy powód:
**`doctrine:migrations:diff` nadal nie wolno commitować.** Wcześniej dlatego, że nie było
metadanych; teraz dlatego, że metadane są z założenia niepełne — mapowanie nie wyraża 30
ograniczeń `CHECK`, 4 wyzwalaczy, 4 funkcji PL/pgSQL ani kolumn `GENERATED ALWAYS AS IDENTITY`,
więc wygenerowana migracja proponuje skasowanie tego, czego nie widzi. Migracje powstają przez
`doctrine:migrations:generate` i są wypełniane ręcznie SQL-em. Szczegóły: `migrations/CLAUDE.md`.

Zgodności mapowania ze schematem pilnuje test, nie recenzja: `doctrine:schema:validate --skip-sync`
plus porównanie `SchemaValidator::getUpdateSchemaList()` z zatwierdzoną listą pięciu znanych
różnic. **Czego ten mechanizm nie wykryje** — ograniczeń `CHECK`, wyzwalaczy i funkcji — jest
wypisane wprost w `docs/orm.md` §9.

### Pilnowanie kierunku zależności

Kierunek zależności to jedyna reguła strukturalna, którą łatwo złamać przypadkiem, więc powinna
być sprawdzana maszynowo, a nie w recenzji. Proponowany zestaw reguł (konfiguracja narzędzia
należy do osoby prowadzącej `composer.json`, tutaj jest tylko treść reguł):

| Moduł | Wolno mu zależeć od |
|---|---|
| `App\Entity` | niczego z `App\` |
| `App\Source` | niczego z `App\` |
| `App\Source\Autocentrum` | `App\Source`, `App\Source\Contract` |
| `App\Import` | `App\Source`, `App\Source\Contract`, `App\Entity` |
| `App\Catalog` | `App\Entity` |
| `App\Api` | `App\Catalog` |

`App\Entity` jest jedynym elementem, od którego wolno zależeć wszystkim: nie zależy od niczego,
więc nie tworzy cyklu. `App\Api` **nie** jest na tej liście i nie ma się na niej znaleźć — encja
podana serializatorowi wciąga leniwe ładowanie do warstwy HTTP i stawia `id` oraz `source_ref`
o jedno odwołanie od odpowiedzi. `App\Source` też nie: adapter nie wie o istnieniu bazy.

Naruszenie tej tabeli jest defektem blokującym — a od ADR 0018 **musi być sprawdzane maszynowo,
bo przestało być trudne do złamania przypadkiem.** Wcześniej granice pilnowały się same przez
kształt kodu: żeby złamać `Api → Import`, trzeba było napisać w kontrolerze SQL albo parser, co
widać w recenzji z odległości metra. Teraz wystarczy `use App\Entity\Variant;` w kontrolerze —
kompiluje się, przechodzi analizę statyczną, przechodzi kontrolę mapowania i wygląda niewinnie,
a przewraca dwie reguły naraz (encja w warstwie HTTP i `id` w odpowiedzi).

Minimalne wystarczające sprawdzenie, bez dokładania narzędzia i konfiguracji: **test, który skanuje
`src/` i sprawdza dwie zakazane pary** — `App\Entity` w `src/Api` oraz cokolwiek z `App\` poza
`App\Source` w `src/Source`. To kilkanaście linii i zamyka dokładnie tę furtkę, którą ADR 0018
otworzył. Pełny graf zależności z tabeli wyżej zasługuje na `deptrac` albo `phparkitect`, ale
dopiero wtedy, gdy ktoś zechce go pilnować w całości; dopóki tego nie ma, tabela bez testu jest
deklaracją, a nie regułą, i nie wolno jej nazywać blokującą.

## 5. Przepływ importu

```
app:import:start
  └─ INSERT import_run (status='running', source = SourceCatalog::name())
  └─ SourceCatalog::listRecords()  →  dla każdego ref: dispatch ImportRecord(runId, ref)
                                                              │
                                                    RabbitMQ (kolejka „import”)
                                                              │
ImportRecordHandler (lokalnie 4 workery, na ECS 1 — ADR 0015)  ◄─┘
  └─ SourceCatalog::fetchRecord(message.ref, Incremental)
       ├─ Record    → RecordWriter::write(runId, result.record)  [jedna transakcja]
       ├─ Unchanged → RecordWriter::markSeen(runId, result.ref)  [jedno UPDATE]
       │               └─ 0 wierszy? → fetchRecord(message.ref, Full) i obsłuż wynik  (ADR 0017)
       └─ Absent    → log, brak zapisu

app:import:finish <runId>            → status='completed'
app:import:finish <runId> --failed   → status='failed'

app:search:refresh                   → REFRESH obu widoków wyszukiwarki (Catalog, ADR 0020)
```

Uwagi projektowe:

- **Nie ma tabeli zadań do pobrania.** Lista rekordów jest funkcją źródła i wyliczana jest przy
  starcie przebiegu. Kolejka RabbitMQ jest jedynym stanem „co zostało do zrobienia”, a
  odporność na przerwanie zapewnia warunkowy GET: powtórny przebieg pobiera prawie wyłącznie
  nagłówki. ADR 0009 opisuje odrzucone alternatywy.
- **Ponowienia należą do Messengera**, nie do schematu bazy. Wiadomość, która wyczerpała
  ponowienia, ląduje w transporcie `failed` — to konfiguracja, nie tabela.
- **`app:import:finish` jest osobną komendą**, bo start nie może wiedzieć, kiedy 27 384
  wiadomości zostaną skonsumowane. Na AWS to drugie zadanie ECS uruchamiane po opróżnieniu
  kolejki. Alternatywa (licznik w wierszu przebiegu inkrementowany przez workery) serializuje
  wszystkie workery na jednym wierszu i została odrzucona.
- **`app:search:refresh` jest ostatnim krokiem runbooka, nie częścią importu.** Indeks
  wyszukiwarki należy do `Catalog`, a `Import → Catalog` jest zależnością zakazaną, więc
  kolejności pilnuje runbook (Makefile lokalnie, definicja zadania ECS produkcyjnie), a nie
  wywołanie w kodzie. Pominięcie tego kroku nie psuje danych — zostawia wyszukiwarkę
  z wynikami poprzedniego przebiegu.
- **Rekordy pominięte nie trafiają do bazy, tylko do logu strukturalnego** z powodem:
  `absent`, `no_usable_variants`, `unknown_fuel_token`, `implausible_value`. Uzasadnienie
  w ADR 0009. To samo dotyczy wyniku pozytywnego: skoro `import_run` nie ma liczników, jedyną
  odpowiedzią na „ile ten przebieg zrobił" jest log, a nie SQL. Zamknięty zestaw zdarzeń
  i pól: `src/Import/CLAUDE.md`, sekcja „Log contract"; transport i retencja: ADR 0022.
  **Nierozpoznana wartość słownikowa (skrzynia, napęd) nie jest na tej liście i nigdy nie
  będzie** — zapisujemy ją w kolumnie `*_unmapped_label` i idziemy dalej (ADR 0006). Pominięcie
  strony z tego powodu jest wprost sprzeczne z wymaganiem właściciela.
- **Przebieg trzeba umieć zamknąć także po awarii.** `app:import:finish <runId> --failed`
  ustawia `status='failed'` i `finished_at`. Bez tego worker ubity przez ECS zostawiłby wiersz
  `running` na zawsze, a każdy kolejny `app:import:start` odbijałby się od indeksu
  `import_run_one_running_per_source`. Procedura odblokowania:
  `SELECT id, source, started_at FROM import_run WHERE status = 'running';`
  → `bin/console app:import:finish <id> --failed`.
  Przebieg zamknięty jako `failed` **nie jest** brany pod uwagę przy czyszczeniu nieaktualnych
  wierszy (`docs/database.md` §8 filtruje po `status = 'completed'`), więc nieudany przebieg
  nigdy nie skasuje danych.
- **Ścieżka „bez zmian" musi potwierdzić, że wiersz istnieje.** `markSeen` zwraca liczbę
  zaktualizowanych wierszy; zero oznacza, że pamięć podręczna adaptera twierdzi „bez zmian",
  a rdzeń tej wersji nie ma. Wtedy rekord jest pobierany ponownie w trybie `Full`. Uzasadnienie
  i odrzucone alternatywy: ADR 0017.

### Ile workerów — zależy od środowiska, bo zasięg limitera zależy od środowiska

Etykieta wobec źródła to **2 żądania na sekundę, burst 5** (`CLAUDE.md`). To ograniczenie jest
wiążące, ale **nie wyznacza samo czasu przebiegu** — wyznacza go dopiero to, czy limit jest
wysycony. Zmierzone na pełnym imporcie (27 384 strony, na żywo):

| Konfiguracja | Tempo | Budżet 2/s | Czas przebiegu |
|---|---|---|---|
| 1 worker, limiter 2/s | 0,40 str./s | 20% | ~19 h |
| 4 workery, limiter 0,5/s każdy | 0,50 str./s | 25% | ~15 h |
| **4 workery, limiter 2/s** | **1,99 str./s** | **99%** | **~3,8 h** |

Jeden worker **nie wysyca limitu**: wąskim gardłem jest czas obsługi strony (~2,5 s — HTTP,
parsowanie, transakcja), nie ogranicznik. Zrównoleglenie jest więc potrzebne, żeby w ogóle
wykorzystać budżet, który sami sobie przyznaliśmy.

**Zasięg limitera nie jest taki sam w obu środowiskach — i to jest najważniejsze zdanie
tej sekcji:**

| Środowisko | Zasięg limitera | Workerów | Sufit |
|---|---|---|---|
| lokalnie (Docker Compose) | **współdzielony** — serwis `worker` montuje bind `.:/app`, więc repliki dzielą `var/` hosta i jedno wiadro tokenów | **4** (`deploy.replicas` w `compose.yaml`) | 2 żąd./s globalnie |
| AWS, jeden host EC2 | **współdzielony** — repliki montują wolumen `app_share` na `/app/var/share`, czyli katalog, w którym limiter trzyma stan | **4** (`deploy.replicas` w `compose.prod.yaml`) | 2 żąd./s globalnie |
| AWS ECS/Fargate | **per zadanie** — każde zadanie ma własny system plików | **1** | 2 żąd./s |

Cztery repliki są **zadeklarowane w `compose.yaml`** (`worker.deploy.replicas`), a nie
podawane flagą przy uruchomieniu — liczba workerów wynika z decyzji, więc jej miejscem jest
plik, a nie pamięć osoby, która wpisuje polecenie.

**Dodatkowy konsument startuje w serwisie `worker`, nigdy w `api`** — `make worker` używa
`docker compose run --rm worker` właśnie dlatego. Serwis `api` montuje nazwany wolumen
`php_cache` na `/app/var`, więc konsument uruchomiony przez `exec api` **nie** widzi `var/`
hosta, dostaje własne wiadro tokenów i dokłada swoje 2 żąd./s ponad limit czterech workerów.
Zmierzone wprost: plik zapisany do `/app/var` z `run worker` pojawia się na hoście, ten sam
zapis z `exec api` — nie. Nie „upraszczaj" tego celu z powrotem do `exec api`.

Współdzielenie wiadra jest **artefaktem wdrożenia**, nie własnością aplikacji — lokalnie
bierze się z bind mounta, na hoście EC2 z nazwanego wolumenu montowanego celowo w tym jednym
punkcie (ADR 0021).
Na ECS `desired_count = 4` dałoby cztery niezależne wiadra i **8 żądań na sekundę**, czyli
czterokrotne złamanie deklarowanej etykiety. Podniesienie `desired_count` na ECS jest dozwolone
**dopiero po** przeniesieniu stanu limitera do magazynu współdzielonego (np. `cache.adapter.redis`
na ElastiCache) — zmiana projektowa wymagająca ADR, nie zmiana konfiguracji. Argument
„lokalnie działało" jest tu bezwartościowy: lokalnie działało dlatego, że wspólny katalog
przypadkiem scalił wiadra. Pomiary i odrzucone warianty: ADR 0015.

Kolejka daje wznawialność i ponowienia — nieudana strona jest ponawiana przez Messengera i nie
zatrzymuje pozostałych 27 383 — a przy czterech workerach daje **także** przepustowość.

Zapis jest zaprojektowany jako bezpieczny przy współbieżności (ADR 0005, `docs/database.md`
§3.5) i przy czterech workerach ta własność jest używana na co dzień, a nie trzymana na zapas.

## 6. Infrastruktura (jeden host EC2)

Kod aplikacji i kod infrastruktury nie mieszają się nigdzie: `infra/` nie zawiera ani jednego
pliku PHP, a `src/` nie zawiera ani jednego pliku `.tf`, ARN-u ani nazwy zasobu AWS. Jedynym
kontraktem między nimi są **zmienne środowiskowe** — ich lista jest w `infra/CLAUDE.md` i to
jedyne miejsce, w którym obie strony się spotykają.

Produkcja stoi na **jednej instancji EC2 `t4g.small`** z tą samą topologią Compose co lokalnie.
Powód, wycena wobec ECS/Fargate i odrzucone warianty: ADR 0021. Runbook: `docs/deployment.md`.

```
infra/
├── CLAUDE.md
└── terraform/
    └── envs/
        └── production/   VPC z jedną podsiecią publiczną, security group, EC2, EIP,
                          rola IAM (SSM + zrzuty do S3 + logi importu), kubełek na kopie,
                          grupa logów CloudWatch, budżet
```

```
compose.prod.yaml     caddy → api (php-fpm) → database, rabbitmq; worker w profilu import
deploy/               deploy.sh i remote.sh z workstacji, host/ z timerami systemd
front/                statyczny build SPA z ../carsss_front, montowany do Caddy'ego
```

Decyzje:

- **Terraform, nie CDK i nie konsola** (ADR 0013, podtrzymane). Stan zdalny w S3 z blokadą.
- **Jeden moduł mniej niż zero.** Zasoby są zadeklarowane wprost w katalogu środowiska, bo
  moduł Terraforma z jednym wywołującym to abstrakcja z jedną implementacją, czyli defekt
  z `CLAUDE.md` §3.
- **Jeden obraz, cztery polecenia.** `api` i `worker` w `compose.prod.yaml` dzielą
  `image: cars:latest` i różnią się wyłącznie `command`; migracja to `compose run --rm api`.
- **Migracje nie uruchamiają się przy starcie kontenera.** `deploy/deploy.sh` podnosi bazę
  i kolejkę, uruchamia migrację osobno, a dopiero potem API i Caddy'ego — procedura
  w `docs/database.md` §9.
- **Cztery workery są legalne, bo wiadro tokenów jest współdzielone.** Repliki montują ten sam
  wolumen `app_share` na `/app/var/share`, gdzie limiter trzyma stan. Wolumen montujemy na
  `/app/var/share`, **nigdy** na `/app/var` — wolumen na całym `var/` zamroziłby cache
  kontenera DI wypalony w obrazie. Warunek z ADR 0015 jest spełniony, nie obchodzony.
- **Workery żyją tylko w oknie importu** (profil `import` w Compose). Cztery bezczynne procesy
  zajmują ~400 MB, których host z 2 GB potrzebuje gdzie indziej.
- **Ten sam host serwuje SPA.** Caddy oddaje statyczny build pod `FRONT_DOMAIN`, a żądania
  `/api/*` z tego samego origin przepisuje na API, doklejając `X-API-Key`. Klucz zostaje po
  stronie serwera — dokładnie tak, jak robi to proxy serwera deweloperskiego Vite, więc bundel
  go nie zawiera. To jedyny powód, dla którego front nie może stać na samym kubełku.
- **`GET /health` jest poza `/v1` i poza uwierzytelnieniem.** Dziś nie odpytuje go żaden
  balanser — zostaje, bo jest tanim sprawdzianem po wdrożeniu i wymaga go ADR 0013 na wypadek
  powrotu do kształtu z ALB.

## 7. Wymiana źródła danych — pełna lista zmian

Port jest zaprojektowany tak, żeby ta lista miała dwie pozycje (ADR 0008, sygnatury
w `src/Source/CLAUDE.md`):

**Do dodania**

1. `src/Source/VendorX/` — dowolna liczba plików prywatnych adaptera; publiczna jest tylko
   klasa implementująca `App\Source\SourceCatalog`.
2. `config/services.yaml` — jedna linia:
   ```yaml
   App\Source\SourceCatalog: '@App\Source\VendorX\VendorXCatalog'
   ```

**Do usunięcia po wygaszeniu scrapera (opcjonalne, nie blokuje wymiany)**

3. `src/Source/Autocentrum/`, `tests/Source/Autocentrum/`, `tests/Fixtures/Source/Autocentrum/`.
4. Migracja kasująca `autocentrum_page_cache` — tabela należy do adaptera, nie do rdzenia.

**Co się nie zmienia:** `Import`, `Catalog`, `Api`, schemat tabel taksonomii, wiadomości
Messengera, kontrakt HTTP, testy poza katalogiem adaptera.

Gdyby ta lista urosła, port byłby źle postawiony. Test kontrolny: **żadna nazwa z domeny HTTP
(URL, ETag, nagłówek, status) ani z domeny HTML (selektor, etykieta) nie występuje w sygnaturach
portu ani w schemacie tabel taksonomii.** Ten test jest spełniony.

## 8. Skala, na której są podejmowane decyzje

| | |
|---|---|
| `brand` | ~91 |
| `car_model` | ~1 400 |
| `version` | ~22 800 |
| `variant` | ~32 000 |
| zapisy | jeden przebieg importu na dobę |
| odczyty | pojedyncze żądania HTTP, bez znanego SLA |

To jest **mała baza**. Każda decyzja o indeksie, denormalizacji, partycjonowaniu czy pamięci
podręcznej musi być mierzona wobec tych liczb, a nie wobec wyobrażonej przyszłości. Konsekwencje
tego w `docs/database.md` §6 i ADR 0014.
