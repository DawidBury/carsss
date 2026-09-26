# Kontrakt źródła danych: autocentrum.pl

Wszystko poniżej zostało **zmierzone empirycznie** (pobrane strony na żywo + analiza `cars_details.xml`),
a nie przepisane z dokumentacji. Surowe liczby: `docs/source-survey.json`.
Próba: 78 stron silnikowych wylosowanych systematycznie z całego sitemapu (co 351. rekord).

## 1. Sitemap `cars_details.xml`

| Wielkość | Wartość |
|---|---|
| wszystkich `<loc>` | 37 395 |
| stron silnikowych (liści) | **28 892** |
| stron indeksowych (nie pobieramy) | 8 503 |

Rozpoznanie liścia: **ostatni segment ścieżki zaczyna się od `silnik-`**.
Zweryfikowane: `silnik-` nie występuje w żadnej innej pozycji ścieżki (0 przypadków), więc reguła jest pewna.

Głębokość ścieżki pod `/dane-techniczne/` jest zmienna:

| Głębokość | Kształt | Liczba liści |
|---|---|---|
| 3 | `marka/model/silnik-*` | 1 710 |
| 4 | `marka/model/{generacja \| nadwozie}/silnik-*` | 8 078 |
| 5 | `marka/model/generacja/nadwozie/silnik-*` | 17 596 |

**Przy głębokości 4 środkowy segment jest dwuznaczny** — `ii`/`b8`/`w205` to generacje,
`sedan`/`kombi`/`suv` to nadwozia, ale ta sama pozycja przyjmuje jedne i drugie.
Dlatego nie modelujemy generacji ani nadwozia jako encji (patrz ADR o taksonomii).

Rodzaj paliwa jest zakodowany w slugu silnika: `benzynowy` (18 482), `diesla` (8 837),
`hybrydowy` (915), `elektryczny` (658).

**Token to pierwszy segment slugu bezpośrednio po prefiksie `silnik-`, a nie dowolne wystąpienie
tokenu w slugu.** Reguła jest ostra i zmierzona na całym `cars_details.xml`:

| Reguła odczytu | benzynowy | diesla | hybrydowy | elektryczny |
|---|---|---|---|---|
| **pierwszy segment po `silnik-`** | **18 482** | **8 837** | **915** | **658** |
| dowolne wystąpienie w slugu | 18 022 | 8 563 | 526 | 273 |

Pierwszy wariant pokrywa **100% z 28 892 liści** — nie ma ani jednego slugu, w którym pierwszy
segment po `silnik-` nie byłby jednym z czterech tokenów. Drugi wariant przypisuje **18 hybryd
do benzyny**, bo 58 slugów zawiera więcej niż jeden token, np.
`silnik-hybrydowy-2.0-b5-benzynowy-mild-hybrid-264km-od-2020`. Pomyłka jest cicha: obie wartości
przechodzą `CHECK`, a kolumna jest `NOT NULL`.

## 2. Strona jest w pełni renderowana serwerowo

**Nie potrzeba przeglądarki headless ani żadnego wywołania AJAX.** Wszystkie warianty napędu
znajdują się w jednym dokumencie HTML; `<select>` tylko przełącza widoczność bloków przez CSS.

```html
<div class="engine-configuration margin25">
  <h2 class="select-hd primary-header">Wybierz parametry napędu (4)</h2>
  <select id="config-select" class="form-control">
    <option data-id="25208" selected="selected">manualna 6 biegowa</option>
    <option data-id="15751">manualna 6 biegowa AWD Quattro Euro 5</option>
    <option data-id="20312">automatyczna Multitronic FWD Euro 5</option>
    <option data-id="23416">automatyczna Multitronic AWD Quattro Euro 5</option>
  </select>
  <div class="config-box" data-context="config"></div>
  <div class="config-box" data-context="config" style="display: none"></div>
  ...
```

`robots.txt` pozwala na `/dane-techniczne/`, ale zabrania `/*?` (żadnych query stringów)
oraz `/ac-ajax/`. Budujemy więc wyłącznie czyste URL-e bez `?`.

## 3. Kontrakt DOM

| Element | Znaczenie |
|---|---|
| `div.config-box[data-context="config"]` | jeden blok = jeden wariant napędu |
| `select#config-select option[data-id]` | etykieta wariantu; mapowanie **pozycyjne** na bloki |
| `a.dt-param-ranking[href*="cid="]` | `cid` = identyfikator wariantu w źródle |
| `div.dt-row` **poza** `config-box` | dane na poziomie wersji (nadwozie, silnik) |
| `div.dt-row` **wewnątrz** `config-box` | dane na poziomie wariantu |
| `div.dt-row__text[data-label="…"]` | polska etykieta pola |
| `span.dt-param-value` | wartość surowa |
| `link[rel=canonical]` | URL kanoniczny |
| `script[type="application/ld+json"]` (`BreadcrumbList`) | nazwy **wyświetlane** segmentów — patrz §3.1, **nie etykietuje poziomów** |

Wiersz bez danych ma **pusty** `span.dt-param-value`, jest opakowany w `div.dt-missing.hidden`
i ma klasę `dt-row no-value`. Nie występuje literał `b/d`. Pusta wartość = pole nieobecne.

### 3.1 BreadcrumbList — co daje, a czego nie daje

Zmierzone osobno, po pytaniu o rozstrzygnięcie dwuznaczności segmentu przy głębokości 4.
Wynik jest **rozstrzygający i negatywny**:

- Breadcrumb **skleja nazwy narastająco**: kolejne pozycje to
  `Dane techniczne` → `Dane techniczne Audi` → `Dane techniczne Audi A4` →
  `Dane techniczne Audi A4 B8` → `Dane techniczne Audi A4 B8 Avant Facelifting`.
- Pozycje w breadcrumbie odwzorowują **dokładnie** pozycje segmentów w adresie.
- **Breadcrumb nie etykietuje poziomów.** Nie mówi, czy segment jest generacją, czy nadwoziem.

Co z tego wynika dla parsera:

1. Nazwę wyświetlaną segmentu otrzymuje się przez **odjęcie narastającego prefiksu** od pozycji
   breadcrumbu na tej samej głębokości.
2. Gdy breadcrumb nie ma wpisu na danej głębokości — odwrotem jest **odslugowanie segmentu**
   z adresu.
3. Rozstrzygnięcie „generacja czy nadwozie" przy głębokości 4 jest **niemożliwe z samego
   źródła**. Dlatego taki segment trafia do osobnej kolumny na segment nierozstrzygnięty,
   a żadnego klasyfikatora ani słownika rozstrzygającego się nie buduje (`CLAUDE.md` §5).

## 4. Pola, które zapisujemy

| Nasze pole | `data-label` na stronie | Poziom | Format surowy |
|---|---|---|---|
| przyspieszenie 0–100 (**wymagane**) | `Przyspieszenie (od 0 do 100km/h)` | wariant | `9.3 s` |
| średnie spalanie | `Średnie spalanie (cykl mieszany)` | wariant | `6.3 l/100km` |
| pojemność baku | `Pojemność zbiornika paliwa` | **wariant** | `65 l` |
| moc | `Moc silnika` | **wersja** | `150 KM` lub `211 KM (155 kW) przy 4300 obr/min` |
| pojemność silnika | `Pojemność skokowa` | **wersja** | `1984 cm³` |
| pojemność bagażnika | `Minimalna pojemność bagażnika (siedzenia rozłożone)` | **wersja** | `460 l` |
| rodzaj paliwa (**wymagane**) | *brak — nie pochodzi z DOM* | **wersja** | token w slugu silnika, patrz §1 |

**Rodzaj paliwa jest jedynym polem, którego nie ma w DOM.** Wyprowadzamy go z tokenu w slugu
silnika w adresie (§1), a nie ze scrapowania treści. Słownik jest zamknięty i pokrywa
**100% z 28 892 stron** (18 482 + 8 837 + 915 + 658 = 28 892), więc kolumna jest `NOT NULL`.
Konsekwencje tej wymagalności: `docs/adr/0016-rodzaj-paliwa-z-adresu.md`.

Pomiar przynależności do poziomu (na 78 stronach): `Moc silnika` 78/78 na poziomie wersji,
`Pojemność skokowa` 68/78 na poziomie wersji, bagażnik 58/78 na poziomie wersji,
**`Pojemność zbiornika paliwa` 93 razy na poziomie wariantu i ani razu na poziomie wersji**.

Istnieje też `Maksymalna pojemność bagażnika (siedzenia złożone)` (38/78). Zapisujemy tylko
minimalną — to ta, o którą prosi wymaganie; maksymalna jest łatwa do dodania później.

**Separatorem dziesiętnym w zmierzonej próbie jest kropka**, ale parser musi akceptować także
przecinek — to konwencja polska i źródło może ją stosować na innych podstronach.
Jednostka bywa oddzielona spacją nierozdzielającą `&nbsp;` (U+00A0), a przy `Moc silnika`
zwykłą spacją i zagnieżdżonym `<span>`. Parser czyta **tekst** węzła, nie jego HTML.

## 5. Słowniki wariantu (zmierzone, zamknięte)

```
Rodzaj skrzyni : manualna (62), automatyczna (36)
Rodzaj napędu  : na przednią oś (55), 4x4 (20), na tylną oś (16)
```

Mapujemy je w bazie na wartości kanoniczne, ale **nieznana wartość nie może wywalić importu** —
zapisujemy ją i raportujemy, zamiast przerywać transakcję.

## 6. Pułapki potwierdzone pomiarem

1. **13 z 78 stron (17%) nie ma ani jednego `config-box`.** Takie strony nie mają przyspieszenia
   nigdzie w dokumencie — to zaślepki dla starych/rzadkich aut (np. Cadillac Eldorado 1955,
   Ford Bronco IV). Mają tylko dane nadwozia i silnika.
   **Skutek: takiej wersji nie zapisujemy w ogóle**, logujemy jako pominiętą z powodem.
2. **7 z 98 bloków (7%) nie ma przyspieszenia**, mimo że blok istnieje. Pierwsza opcja w
   `<select>` bywa zaślepką (`selected`, ~2 KB zamiast ~13 KB, sam `Rodzaj skrzyni`).
   **Skutek: taki wariant pomijamy** — przyspieszenie jest wymagane.
3. `cid` znaleziono w 98 na 98 bloków, ale zaślepka z pkt. 2 potrafi go nie mieć.
   Kolejność ustalania klucza wariantu: `cid` → `option[data-id]` → pozycja w dokumencie.
4. Liczba bloków na stronę: 0 (13×), 1 (43×), 2 (16×), 3 (2×), 4 (3×), 5 (1×).
   `<select>` pojawia się tylko przy wielu wariantach (22 strony); przy jednym wariancie
   etykietę bierzemy z `div.engine-configuration h2.primary-header`.
5. Sluga nie deduplikujemy przed pobraniem — część adresów robi 301 na formę kanoniczną
   (`limuzyna` → `limousine`). Tożsamość ustalamy z `link[rel=canonical]` **po** pobraniu.
   Zmierzone w `cars.xml`: segment `limuzyna` występuje w **169** ścieżkach liści, `limousine`
   w **111**, razem **280**.

   **Zmierzone po częściowym imporcie (1 388 wersji na żywej bazie): 86 zapisanych
   `source_ref`, czyli 6,2%, nie występuje wśród 34 969 adresów `<loc>` w `cars.xml`.**
   To pierwszy pomiar rozbieżności adres-ze-sitemapu ↔ forma kanoniczna i wychodzi on
   szerszy niż sam przypadek `limuzyna`/`limousine`:

   | W sitemapie | Forma kanoniczna | Na czym polega różnica |
   |---|---|---|
   | `…/acura/tl/iv/silnik-benzynowy-3.5-280km-od-2008/` | `…-3.5-280km-2008-2011/` | przedział lat zamiast „od roku" |
   | `…/alfa-romeo/tonale/silnik-hybrydowy-1.5-t4-hybrid-130km-od-2022/` | `…/tonale/suv/silnik-…/` | **wstawiony segment nadwozia** |
   | `…/mercedes/eqs/limousine/…` | `…/mercedes/eqs/limuzyna/…` | zamiana formy segmentu |

   Drugi wiersz zmienia **głębokość ścieżki**, czyli wejście reguły taksonomii z ADR 0003.
   Parser jest na to odporny, bo wszystko wyprowadza z adresu kanonicznego
   (`EnginePath::fromUrl($canonicalUrl)`) — ale rdzeń musi znać rekord pod jedną nazwą, i to
   kanoniczną. Odwzorowanie adres-ze-sitemapu → forma kanoniczna utrwala adapter
   (ADR 0019); 6,2% to dolne oszacowanie, bo pochodzi z przerwanego przebiegu.

   Ile adresów zbiega się parami po kanonizacji, nadal ustala dopiero pobranie — sitemap tego
   nie mówi i nie zakładamy tu żadnej liczby.

## 7. Szacowana skala po imporcie

| Tabela | Szacunek |
|---|---|
| `brand` | ~91 |
| `car_model` | ~1 400 |
| `version` | ~24 000 (28 892 minus ~17% zaślepek) |
| `variant` | ~32 000 |

## 8. Etykieta wobec serwisu

Brak `Crawl-delay` w `robots.txt`, więc stosujemy własny limit: **2 żądania/s, burst 5**.
Prawdziwy, kontaktowy `User-Agent`. Conditional GET przez `ETag` — powtórny przebieg
pobiera wielokrotnie mniej stron.
