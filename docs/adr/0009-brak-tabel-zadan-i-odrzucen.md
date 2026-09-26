# ADR 0009 — Brak tabeli zadań do pobrania i tabeli odrzuceń

Status: przyjęty · Data: 2026-09-02

## Kontekst

Poprzednie podejście miało tabelę `crawl_target` (jeden wiersz na adres, ze statusem i licznikiem
niepowodzeń) oraz tabelę `ingestion_rejection` (jeden wiersz na pominiętą stronę). Obie okazały
się defektami:

- `crawl_target` miał indeks częściowy na `status IN ('pending','failed')` i **nikt nie resetował
  statusu na starcie przebiegu**, więc drugi przebieg nie kolejkował niczego. Tabela nie
  odróżniała „jeszcze nie w tym przebiegu” od „jeszcze nigdy”. Blokujący defekt B5.
- `ingestion_rejection` rosła monotonicznie: ~4 600 wierszy na przebieg, ~240 000 rocznie,
  nigdy nie czytane przez API. Do tego wiersz odrzucenia nie mógł powstać, gdy przyczyną było
  naruszenie `CHECK` — transakcja była już przerwana.

## Decyzja

**Nie ma tabeli zadań do pobrania.** Lista rekordów jest funkcją źródła i wyliczana jest przy
starcie przebiegu przez `SourceCatalog::listRecords()`. Jedynym stanem „co zostało do zrobienia”
jest kolejka RabbitMQ. Ponowienia należą do Messengera (konfiguracja transportu), a wiadomości
po wyczerpaniu ponowień lądują w transporcie `failed` — to konfiguracja, nie schemat.

**Nie ma tabeli odrzuceń.** Pominięta strona i pominięty wariant trafiają do logu
strukturalnego z powodem i referencją rekordu. Na AWS to CloudWatch Logs Insights.
Zamknięta lista powodów pominięcia:

| Powód | Znaczenie |
|---|---|
| `absent` | źródło nie ma tego rekordu albo nie niesie on danych |
| `no_usable_variants` | żaden blok nie miał przyspieszenia |
| `unknown_fuel_token` | slug silnika nie zawiera znanego tokenu paliwa (ADR 0016) |
| `implausible_value` | wartość odrzucona przez kontrolę wiarygodności w adapterze |

**Nierozpoznana wartość słownikowa nie jest powodem pominięcia i nigdy nim nie będzie.**
Nieznana skrzynia lub napęd trafia do kolumny `*_unmapped_label`, wiersz powstaje, import idzie
dalej — to wymaganie właściciela wprost (ADR 0006).

Wyjątkiem, który zostaje w bazie, są **nierozpoznane etykiety słowników** — bo dotyczą wiersza,
który powstał, i mieszczą się w nim jako kolumna (ADR 0006). Raport z nich jest zapytaniem SQL,
nie przeszukaniem logu.

## Konsekwencje

- Przerwany przebieg wznawia się przez powtórzenie komendy startowej. Nie jest to darmowe, ale
  jest tanie: warunkowy GET sprawia, że powtórny przebieg pobiera prawie same nagłówki.
- Nie ma stanu, który mógłby się rozjechać z rzeczywistością — bo nie ma stanu.
- Nie ma zapytania „pokaż mi wszystkie odrzucone strony z ostatniego przebiegu” w SQL; jest
  w logach. To jest pytanie operacyjne, nie produktowe, i log jest jego właściwym miejscem.
- Baza nie rośnie z liczby przebiegów. Rośnie tylko z liczby samochodów.
- Ryzyko: retencja logów (domyślnie skończona) jest krótsza niż życie bazy. Przyjęte świadomie —
  odrzucenie sprzed roku nie jest informacją, na której ktokolwiek działa.

## Odrzucone alternatywy

**`crawl_target` z resetem statusu przy starcie przebiegu.** Naprawia defekt B5, ale wymaga
masowego `UPDATE` na 27 384 wierszach na starcie i wprowadza drugą kolejkę obok RabbitMQ,
z którą trzeba tę pierwszą synchronizować.

**`crawl_target` z `last_enqueued_run_id` zamiast statusu.** Elegantsze, daje wznawialność
w obrębie przebiegu bez masowego `UPDATE`. Odrzucone dlatego, że wznawialność kupujemy taniej
warunkowym GET-em, a tabela musiałaby przechowywać adresy — czyli kształt źródła w rdzeniu
(ADR 0008).

**Tabela odrzuceń z retencją (kasowanie po N przebiegach).** Dokłada zadanie czyszczące i
politykę retencji, żeby przechowywać dane, które i tak są w logu.

**Licznik odrzuceń w `import_run`.** Inkrementowany przez 27 384 transakcje serializuje wszystkie
workery na jednym wierszu. Ten sam defekt, co przy licznikach przebiegu.
