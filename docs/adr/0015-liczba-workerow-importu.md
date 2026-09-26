# ADR 0015 — Liczba workerów importu i zasięg limitera tempa

Status: przyjęty (decyzja właściciela) · Data: 2026-09-02 ·
**Przesłanka obalona pomiarem i decyzja zmieniona 2026-09-02**

## Kontekst

Etykieta wobec źródła to **2 żądania na sekundę, burst 5** (`CLAUDE.md`, „Etykieta wobec
źródła"). Pytanie brzmi: ilu workerów wolno użyć, żeby tego nie złamać, i ile to daje czasu
przebiegu.

Pierwsza wersja tego ADR odpowiadała: limiter jest per proces, więc N workerów daje 2N żądań/s,
więc jeden worker, a przebieg trwa 27 384 ÷ 2 ≈ 3,8 h.

**Pomiar na pełnym imporcie (przebieg #4, 27 384 strony, na żywo) obalił obie przesłanki.**

| Konfiguracja | Zmierzone tempo | Wykorzystanie budżetu 2/s | Czas przebiegu |
|---|---|---|---|
| 1 worker, limiter 2/s | **0,40 str./s** | 20% | **~19 h** |
| 4 workery, limiter 0,5/s każdy | 0,50 str./s | 25% | ~15 h |
| 4 workery, limiter 2/s | **1,99 str./s** | **99%** | **~3,8 h** |

**Obalenie 1: jeden worker nie wysyca limitu.** Osiąga 0,40 str./s, czyli 20% budżetu, bo wąskim
gardłem jest **czas obsługi jednej strony (~2,5 s** — round-trip HTTP, parsowanie, transakcja),
a nie ogranicznik. Liczba „3,8 h" była policzona jako 27 384 ÷ 2, czyli przy założeniu pełnego
wysycenia, którego jeden worker nigdy nie osiąga. Realnie jeden worker potrzebuje **~19 h** —
pięciokrotnie dłużej, niż deklarował poprzedni zapis.

**Obalenie 2: limiter nie jest per proces w tym wdrożeniu — jest współdzielony.**
`cache.app` to `cache.adapter.filesystem`, a w `compose.yaml` serwis `worker` montuje wyłącznie
bind katalogu repozytorium (`.:/app`), bez nazwanego wolumenu `php_cache`, który dostaje tylko
serwis `php`. Wszystkie repliki workera widzą więc **ten sam katalog `var/` hosta**, a stan
wiadra tokenów leży we wspólnym pliku. Dowód nie wprost jest w tabeli: obniżenie limitera do
0,5/s na worker dało globalne **0,50/s zamiast 2,0/s** — gdyby wiadra były osobne, cztery
workery dałyby 2,0/s.

## Decyzja

**Liczba workerów zależy od środowiska, bo zasięg limitera zależy od środowiska.**

| Środowisko | Zasięg limitera | Workerów | Gwarantowany sufit |
|---|---|---|---|
| lokalnie (Docker Compose) | **współdzielony** — bind `.:/app`, wspólny `var/` | **4** | 2 żąd./s globalnie |
| AWS ECS/Fargate | **per zadanie** — własny system plików | **1** | 2 żąd./s |

Konfiguracja produkcyjna importu lokalnie: 4 workery, `token_bucket` 2/s z burst 5, zmierzone
1,99 str./s, przebieg 3,8 h.

**Warunek skalowania na ECS.** `desired_count > 1` wolno ustawić **dopiero po** przeniesieniu
stanu limitera do magazynu współdzielonego między zadaniami — np. `cache.adapter.redis` na
ElastiCache. Bez tego cztery zadania to cztery niezależne wiadra i **8 żądań na sekundę**,
czyli czterokrotne złamanie zadeklarowanej etykiety. To zmiana projektowa wymagająca ADR,
nie zmiana `terraform.tfvars`.

Ostrzeżenie z pierwszej wersji tego ADR **pozostaje w mocy — ale wyłącznie dla ECS.** Zostało
zawężone, nie usunięte, bo tam jest nadal prawdziwe.

## Konsekwencje

- **Ta sama konfiguracja zachowuje się inaczej w dwóch środowiskach.** To jest najważniejsza
  rzecz do zapamiętania z tego ADR. „Lokalnie działało" **nie jest** argumentem za podniesieniem
  `desired_count` na ECS — lokalnie działało dlatego, że współdzielony katalog przypadkiem
  scalił wiadra.
- Czas przebiegu wyznacza wysycenie limitu, a nie liczba workerów sama w sobie. 3,8 h jest
  prawdziwe **tylko** przy wysyceniu 2 żąd./s, czyli lokalnie przy czterech workerach.
  Przy jednym workerze — 19 h, w obu środowiskach.
- Kolejka RabbitMQ daje wznawialność i ponowienia; przy czterech workerach daje **także**
  przepustowość, czego poprzednia wersja tego ADR wyraźnie zaprzeczała.
- Import jest realnie równoległy, więc decyzje o bezpieczeństwie zapisu przy współbieżności
  (ADR 0005, `docs/database.md` §3.5) przestają być polisą na przyszłość i zaczynają
  obowiązywać na co dzień. Wcześniejsza uwaga „przy jednym workerze rywalizacja praktycznie
  nie występuje" jest nieaktualna.
- Nadal nie ma autoskalowania. Liczba workerów jest stała i wybrana pod sufit żądań, a nie pod
  obciążenie.
- Import trwający 3,8 h musi mieścić się w oknie harmonogramu i nie kolidować z kolejnym
  startem — chroni przed tym `import_run_one_running_per_source`.

## Odrzucone alternatywy

**Zostawienie jednego workera także lokalnie.** Zgodne z pierwotnym ADR-em i bezpieczne, ale
zużywa 20% uzgodnionego budżetu i wydłuża przebieg do 19 h — bez żadnej korzyści dla źródła,
któremu obiecaliśmy 2 żąd./s, a nie 0,4.

**Podział budżetu: N workerów po 2/N żądań/s.** Zmierzone i odrzucone empirycznie: przy
współdzielonym wiadrze cztery workery po 0,5/s dały 0,50 str./s, czyli 25% budżetu — limity
się nie sumują, tylko mnożą przez wspólne wiadro. Dodatkowo wymaga, żeby liczba workerów była
znana konfiguracji i zgodna z rzeczywistością; rozjazd daje ciche złamanie limitu.

**Poleganie na współdzielonym systemie plików także na ECS** (EFS zamontowany do wszystkich
zadań). Działałoby, ale stawia gwarancję etykiety na wydajności i dostępności sieciowego
systemu plików w gorącej ścieżce każdego żądania. Redis jest do tego właściwym narzędziem.

**Zwiększenie limitu wobec źródła.** Nie do nas należy. `robots.txt` nie podaje `Crawl-delay`,
więc 2/s jest naszą własną, konserwatywną deklaracją — i skoro ją złożyliśmy, obowiązuje.

**Rezygnacja z kolejki na rzecz jednego procesu w pętli.** Odrzucone już w pierwszej wersji
i tym mocniej teraz: kolejka daje ponowienia, transport `failed`, wznawialność **oraz**
zrównoleglenie, które okazało się potrzebne do wysycenia limitu.
