# ADR 0011 — Uwierzytelnianie kluczem API w jednej tabeli

Status: przyjęty · Data: 2026-09-02

## Kontekst

`CLAUDE.md` §7 przesądza: klucz w nagłówku `X-API-Key`, jedna tabela z hashem klucza, bez
OAuth2. Poprzednie podejście miało pod uwierzytelnianie cztery tabele, z czego dwie nigdy nie
zapisywane.

Pozostaje jedna realna decyzja techniczna: jaką funkcją skrótu.

## Decyzja

Jedna tabela, cztery kolumny:

```sql
CREATE TABLE api_key (
    id         BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name       TEXT        NOT NULL,
    key_hash   BYTEA       NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT api_key_hash_is_sha256 CHECK (octet_length(key_hash) = 32)
);
CREATE UNIQUE INDEX api_key_key_hash_key ON api_key (key_hash);
CREATE UNIQUE INDEX api_key_name_key     ON api_key (name);
```

Klucz to **32 bajty z generatora kryptograficznego**, przekazywane klientowi w base64url.
W bazie leży `sha256(klucz)`. Wyszukanie: jedno `SELECT` po indeksie unikalnym.

Wydanie klucza: `app:api-key:create <nazwa>`, wypisuje sekret raz. Odwołanie: `DELETE`.

Brak nagłówka i nieznany klucz dają identyczną odpowiedź `401`, żeby nie różnicować.

## Konsekwencje

- Uwierzytelnienie to jedno zapytanie po indeksie — nie da się zrobić taniej.
- Sekret nie jest odtwarzalny z bazy. Wyciek zrzutu bazy nie daje dostępu do API.
- Brak `revoked_at`: odwołany klucz nie zostawia śladu. Świadome — historia wydanych kluczy nie
  jest wymaganiem, a kolumna z predykatem w zapytaniu uwierzytelniającym jest kosztem stałym.
- Brak `last_used_at`: nie zapisujemy nic na ścieżce odczytu. Kolumna aktualizowana przy każdym
  żądaniu to rywalizacja o wiersz w zapytaniu, które ma być najtańsze w systemie.
- Brak zakresów uprawnień: API jest w całości tylko do odczytu, więc nie ma czego rozdzielać.

## Odrzucone alternatywy

**Argon2id lub bcrypt zamiast SHA-256.** Właściwe dla haseł ludzi, bo chronią przed atakiem
słownikowym na hasło o niskiej entropii. Tutaj sekret ma 256 bitów entropii, więc atak
słownikowy nie istnieje. Za to funkcja z solą **uniemożliwia wyszukanie po indeksie** — każde
żądanie musiałoby przejść po wszystkich wierszach i policzyć drogi skrót dla każdego. Zamiana
zapytania `O(log n)` na `O(n)` razy koszt Argon2, w zamian za nic.

**HMAC z sekretem aplikacji zamiast gołego SHA-256.** Chroni przed odtworzeniem klucza ze zrzutu
bazy metodą tęczowych tablic — nieistotne przy 256 bitach entropii. Dokłada za to sekret
do zarządzania i awarię typu „zgubiliśmy sekret, wszystkie klucze przestały działać”.

**OAuth2 / JWT.** Wprost odrzucone przez właściciela. Nie ma tu użytkowników, delegacji
uprawnień ani stron trzecich — jest maszyna wołająca API.

**Klucz w query stringu.** Trafia do logów serwera i historii przeglądarki. Nagłówek nie.
