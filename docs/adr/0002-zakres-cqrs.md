# ADR 0002 — Zakres rozdziału zapisu od odczytu

Status: przyjęty · Data: 2026-09-02

## Kontekst

`CLAUDE.md` §2 nakazuje stosować CQRS tam, gdzie zapis i odczyt mają realnie różny kształt,
i zapisać wprost, gdzie go nie ma. Poprzednie podejście stosowało rozdział wszędzie, także tam,
gdzie obie strony robiły to samo.

## Decyzja

Rozdzielone są **tylko** taksonomia i jej odczyt:

| | zapis (`Import`) | odczyt (`Catalog`) |
|---|---|---|
| jednostka pracy | cały rekord źródła w jednej transakcji | jedno `SELECT` |
| wyzwalacz | wiadomość RabbitMQ | żądanie HTTP |
| kształt | upsert po kluczu naturalnym w 4 tabelach | join 4 tabel, filtr, `LIMIT` |
| model | DTO ładunku z portu | DTO odpowiedzi |

**Nierozdzielone, świadomie:**

- `api_key` — czyta filtr uwierzytelniający, pisze komenda konsolowa, ten sam kształt
  (jeden wiersz po kluczu naturalnym). Oba pliki w `src/Api/`.
- `autocentrum_page_cache` — czyta i pisze wyłącznie adapter, w tej samej metodzie.
- `import_run` — pisze i czyta wyłącznie `Import`, kilkadziesiąt wierszy rocznie.

Nie ma drugiej szyny, osobnej bazy odczytowej, projekcji, spójności ostatecznej ani szyny
komend. Wiadomość `ImportRecord` jest zadaniem dla workera, a nie komendą w rozumieniu CQRS.

## Konsekwencje

- `Catalog` nigdy nie widzi DTO ładunku, `Import` nigdy nie widzi DTO odpowiedzi. Zmiana kształtu
  odpowiedzi API nie dotyka zapisu i odwrotnie.
- Obie strony czytają ten sam schemat. To nie jest naruszenie CQRS — rozdzielony jest model,
  nie magazyn.
- Odczyt widzi dane natychmiast po zatwierdzeniu transakcji zapisu. Brak opóźnienia
  projekcyjnego jest cechą, nie brakiem.

## Odrzucone alternatywy

**Osobna baza / tabela odczytowa.** Największa tabela ma ~32 000 wierszy, a zapytania odczytu
kończą się na czterech złączeniach po kluczu głównym. Nie ma czego przyspieszać.

**Szyna komend dla zapisu.** `RecordWriter::write()` ma jedno wywołanie i jednego wywołującego.
Interfejs komendy, magistrala i handler dołożyłyby trzy pliki bez jednej nowej możliwości.

**CQRS także dla `api_key`.** Jeden wiersz, dwa razy w roku, ten sam kształt. Ceremonia.
