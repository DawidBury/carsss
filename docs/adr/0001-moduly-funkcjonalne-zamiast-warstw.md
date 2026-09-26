# ADR 0001 — Moduły funkcjonalne zamiast warstw

Status: przyjęty · Data: 2026-09-02

## Kontekst

Poprzednie podejście (`/Users/dbury/Downloads/cars`) dzieliło kod na `Domain`, `Application`,
`Infrastructure`, `Presentation` wewnątrz bounded contextów. Właściciel odrzucił je wprost.
Dwie recenzje projektu tamtego rozwiązania pokazują, gdzie ta struktura kosztowała: reguła
konwersji jednostek trafiła raz do `Application`, raz do `Infrastructure` i oba dokumenty nie
mogły być prawdziwe naraz; interfejsy z jedną implementacją konsumowane z własnej warstwy;
pojęcia z jednego kontekstu przeciekały do drugiego przez „opublikowany język”.

Jednocześnie brak DDD nie może oznaczać braku granic — cztery katalogi bez reguł zamienią się
w jeden wielki katalog.

## Decyzja

Kod dzieli się na **moduły nazwane od tego, co robią**: `Source`, `Import`, `Catalog`, `Api`.
Wewnątrz modułu katalogi nazywają się od **roli pliku**: `Parser`, `Writer`, `Reader`,
`Controller`, `Console`, `Message`. Zależności są jednokierunkowe i wypisane w tabeli
w `docs/architecture.md` §4; naruszenie tabeli jest defektem blokującym.

Zakazane w tym projekcie: bounded contexty, agregaty, encje domenowe wymuszające niezmienniki,
value objecty jako domyślne opakowanie skalarów, interfejsy repozytoriów, zdarzenia domenowe,
katalogi `Domain`/`Application`/`Infrastructure`/`Presentation`.

## Konsekwencje

- Po nazwie katalogu widać, co jest w środku. Pytanie „gdzie to położyć” ma odpowiedź „gdzie
  robi się ta rzecz”, a nie „do której warstwy to należy”.
- Niezmienniki, które w DDD pilnowałby agregat, pilnuje baza (`docs/database.md` §3.5). To jest
  mocniejsza gwarancja: obowiązuje też przy zapisie z psql.
- Nie ma warstwy antykorupcyjnej. Jej rolę pełni port źródła — jedno miejsce, jeden kierunek.
- Ryzyko: bez narzędzia pilnującego kierunku zależności reguła zgnije. Dlatego tabela zależności
  jest wprost w dokumencie i ma być sprawdzana maszynowo.

## Odrzucone alternatywy

**Warstwy heksagonalne bez nazwy „DDD”.** Ta sama struktura katalogów pod inną nazwą. Właściciel
odrzucił strukturę, nie etykietę.

**Jeden płaski `src/` bez modułów.** Przy czterech przepływach i siedmiu tabelach przez pół roku
działałoby. Upada w momencie, w którym trzeba odpowiedzieć na pytanie „co się zmieni, gdy
podmienimy scraper” — bez granicy modułu odpowiedź brzmi „nie wiadomo”, a to jest wymaganie
wiążące.

**Podział według tabel (`Brand`, `Model`, `Version`, `Variant`).** Każdy przepływ (import,
odczyt) przecinałby wszystkie cztery moduły, więc żadna zmiana nie byłaby lokalna. Odwrotność
tego, po co się dzieli kod.
