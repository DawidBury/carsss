# ADR 0013 — Terraform w `infra/`, oddzielony od kodu aplikacji

Status: przyjęty · Data: 2026-09-02

## Kontekst

Produkcyjnie system idzie na AWS ECS/Fargate. Trzeba zdecydować, czym opisać infrastrukturę
i gdzie to położyć, tak żeby nie mieszało się z kodem aplikacji. Docker i `composer.json`
prowadzi inna osoba — ten ADR ich nie dotyka.

## Decyzja

**Terraform**, w katalogu `infra/terraform/`, z podziałem na moduły i środowiska:

```
infra/terraform/
├── modules/{network,database,queue,secrets,api-service,import-task,migration-task}/
└── envs/{staging,production}/{main.tf,variables.tf,backend.tf,terraform.tfvars}
```

Reguła rozdziału, sprawdzalna mechanicznie:

- `infra/` nie zawiera ani jednego pliku `.php`, ani jednej ścieżki do `src/`.
- `src/` i `config/` nie zawierają ani jednego pliku `.tf`, ani jednego ARN-u, ani jednej nazwy
  zasobu AWS.
- Jedynym kontraktem między nimi jest **lista zmiennych środowiskowych**, opisana w
  `infra/CLAUDE.md`. To jedyne miejsce, gdzie obie strony się spotykają.

Cztery jednostki uruchomieniowe z jednego obrazu, różniące się wyłącznie poleceniem:
`api-service`, `worker-service` (`messenger:consume`), `import-task` (`app:import:start`,
z harmonogramu), `migration-task` (uruchamiane raz, przed przełączeniem ruchu).

## Konsekwencje

- Zmiana infrastruktury nigdy nie wymaga zmiany w `src/` i odwrotnie. Przeglądy obu rodzajów
  zmian są rozłączne.
- Stan Terraforma jest zdalny (S3 + blokada), więc dwie osoby nie nadpiszą sobie środowiska.
- Rozdzielenie usług pozwala skalować je niezależnie: serwis API ruchem HTTP, workery
  głębokością kolejki, import wcale (jedno zadanie z harmonogramu).
- Migracje w osobnym zadaniu wykluczają wyścig trzech równolegle startujących zadań o tę samą
  migrację.
- Repozytorium ma dwa łańcuchy narzędzi (PHP i Terraform), ale nie mają one wspólnych plików.

## Odrzucone alternatywy

**AWS CDK.** Wciągnąłby do repozytorium trzeci język i menedżer pakietów Node, obok PHP
i Composera — czyli dokładnie to mieszanie, którego ten podział ma unikać. Do tego syntezuje
CloudFormation, więc diagnostyka błędu wdrożenia przechodzi przez dwie warstwy.

**CloudFormation wprost / SAM.** Wiąże z jednym dostawcą mocniej niż Terraform i nie ma
porównywalnego podziału na moduły wielokrotnego użytku.

**Konsola AWS („kliknięte ręcznie”).** Brak odtwarzalności, brak przeglądu zmian, brak drugiego
środowiska bez ręcznego powtórzenia wszystkiego.

**`infra/` wewnątrz `src/` albo pliki `.tf` obok kodu modułu.** Kusi bliskością („serwis
i jego infrastruktura razem”), ale sprawia, że każdy przegląd kodu aplikacji dotyka też
infrastruktury, a narzędzia statyczne PHP muszą wykluczać katalogi. Wprost wykluczone
przez wymaganie.

**Migracje uruchamiane przy starcie kontenera aplikacji.** Najprostsze do napisania i najgorsze
w skutkach: trzy zadania ECS startujące równolegle wyścigują się o tę samą migrację.
