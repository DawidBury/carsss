# `infra/` — infrastruktura (jeden host EC2)

Przeczytaj ten plik **przed** zmianą czegokolwiek w tym katalogu.

## Stan na dziś

Terraform opisuje **jedną instancję EC2** z całym stosem w Docker Compose, nie ECS/Fargate.
Zmiana kształtu wobec pierwotnego projektu jest udokumentowana w **ADR 0021**: pełny układ
z ALB, NAT, RDS i Amazon MQ kosztuje ~115 USD miesięcznie przy zerowym ruchu, czyli cały
budżet projektu w miesiąc, i wymusza jeden worker importu zamiast czterech.

Runbook wdrożenia, bootstrap i odtwarzanie bazy: `docs/deployment.md`. Ten plik opisuje
wyłącznie katalog `infra/`.

## Za co odpowiada

Opis infrastruktury AWS w Terraformie: sieć, host, adres, rola IAM, kubełek na kopie, grupa
logów importu i budżet.
Docker, `compose.prod.yaml`, `deploy/` i `composer.json` **nie należą do tego katalogu**
i prowadzi je kto inny.

Ten sam host serwuje też SPA z `../carsss_front` pod `FRONT_DOMAIN`. Statyczne pliki nie
kosztują nic ponad to, co już płacimy, i nie potrzebują ani jednego zasobu AWS więcej —
dlatego w Terraformie nie ma po nich śladu. Procedura budowy i wysyłki: `docs/deployment.md`.

## Granica — to jest cały sens tego katalogu

| | |
|---|---|
| `infra/` nie zawiera | ani jednego pliku `.php`, ani jednej ścieżki do `src/` |
| `src/` i `config/` nie zawierają | ani jednego pliku `.tf`, ani jednego ARN-u, ani jednej nazwy zasobu AWS |
| jedyny wspólny kontrakt | **lista zmiennych środowiskowych poniżej** |

Naruszenie któregokolwiek z trzech wierszy jest defektem blokującym.

## Kontrakt: zmienne środowiskowe

To jedyne miejsce, w którym infrastruktura i aplikacja się spotykają. Zmiana tej listy jest
zmianą kontraktu i wymaga zmiany po obu stronach w tym samym zadaniu.

| Zmienna | Źródło | Używana przez |
|---|---|---|
| `DATABASE_URL` | `.env.prod.local` na hoście | `api`, `worker`, import, migracja |
| `MESSENGER_TRANSPORT_DSN` | `.env.prod.local` na hoście | `api`, `worker`, import |
| `APP_ENV` | `.env.prod.local` na hoście | wszystkie |
| `APP_SECRET` | `.env.prod.local` na hoście | wszystkie |
| `SOURCE_USER_AGENT` | `.env.prod.local` na hoście | import (etykieta wobec źródła) |
| `API_DOMAIN` | `.env.prod.local` na hoście | Caddy — nazwa, pod którą stoi samo API |
| `FRONT_DOMAIN` | `.env.prod.local` na hoście | Caddy — nazwa, pod którą stoi SPA |
| `CARS_API_KEY` | `.env.prod.local` na hoście | Caddy — klucz wstrzykiwany do żądań `/api/*` z SPA |

Aplikacja **nigdy** nie odczytuje nic z AWS bezpośrednio — żadnego SDK, żadnego
Secrets Managera w kodzie. Sekrety wstrzykuje Compose przez `env_file`.

Secrets Manager kosztowałby 0,40 USD miesięcznie za sekret przy hoście, na którym plik
`.env.prod.local` z uprawnieniami `600` daje to samo. Wzorzec `env_file` jest identyczny
z wstrzykiwaniem sekretów przez ECS, więc powrót do kształtu z ADR 0013 nie dotyka aplikacji.

## Układ

```
infra/terraform/
└── envs/
    └── production/
        ├── versions.tf          provider i wersje
        ├── backend.tf           pusty blok backendu, konfiguracja w backend.hcl
        ├── variables.tf
        ├── main.tf              VPC, podsieć, IGW, SG, IAM, S3, grupa logów, EC2, EIP, budżet
        ├── user-data.sh         Docker, wtyczka compose, swap, /opt/cars
        └── outputs.tf
```

Katalogu `modules/` **nie ma i to jest decyzja, nie zaniedbanie**: moduł Terraforma z jednym
wywołującym to abstrakcja z jedną implementacją, czyli defekt z `CLAUDE.md` §3.

Środowiska `staging` też nie ma. Drugie środowisko podwaja największą pozycję rachunku
w systemie z jednym operatorem. Gdy będzie potrzebne, powstanie przez skopiowanie katalogu —
tak samo jak przewidywał ADR 0013.

## Decyzje lokalne, których nie wolno cofnąć

1. **Terraform, nie CDK, nie konsola** (ADR 0013 — podtrzymane w całości).
2. **Stan Terraforma jest zdalny (S3) z blokadą.** Kubełek stanu powstaje ręcznie przed
   `terraform init`, bo backend nie może stworzyć własnego magazynu.
3. **Migracje w osobnym uruchomieniu, przed przełączeniem ruchu.** `deploy/deploy.sh` podnosi
   bazę i kolejkę, uruchamia migrację, dopiero potem API. Nigdy przy starcie kontenera.
4. **`delete_on_termination = false` na wolumenie głównym.** Baza żyje na tym dysku.
5. **`ignore_changes = [ami, user_data]` na instancji.** AL2023 dostaje nowy obraz co kilka dni;
   bez pierwszego wpisu każde `terraform apply` proponowałoby odtworzenie hosta razem z bazą.
   Drugi wpis chroni przed tym samym przy poprawianiu `user-data.sh`: ten skrypt i tak wykonuje
   się wyłącznie przy pierwszym starcie, więc jego zmiana nie ma prawa ruszyć działającej maszyny.
6. **Brak portu 22 w security group.** Dostęp powłoki idzie tunelem SSM (`deploy/ssh.config`).
7. **`credit_specification = unlimited`.** Import na poziomie ~0,5 vCPU przez 3,8 h ociera się
   o bazowy przydział `t4g.small`; nadwyżka kosztuje ~0,04 USD za vCPU-godzinę, a tryb
   `standard` zamiast tego dławiłby przebieg.
8. **The import log group is created here, with `retention_in_days` and nowhere else** (ADR 0022).
   `awslogs-create-group` stays off in `compose.prod.yaml`: a group created by the Docker daemon
   has **no expiry**, and unbounded retention is the one way this design gets expensive. The
   retention value is `var.log_retention_days` and its justification is ADR 0022 §2 — an owner
   decision, not a knob to tune. `var.log_retention_days` sits next to `var.backup_retention_days`
   deliberately: both are retention policies of this environment, declared the same way, so a
   reader finds them together.
9. **`aws_iam_role_policy.logs` is scoped to one ARN.** `logs:CreateLogStream` and
   `logs:PutLogEvents` on `"${aws_cloudwatch_log_group.worker.arn}:*"` — the documented minimum
   for the `awslogs` driver. Never `logs:*`, never `Resource = "*"`, and never
   `logs:CreateLogGroup`: the driver must not be able to create a group whose retention nobody
   set. If a future Docker release needs `logs:DescribeLogStreams`, add that one action to the
   same statement; do not widen the resource.
10. **The log group name and the region are literals in `compose.prod.yaml`.** Making them
    environment variables would add a row to the contract table above — the boundary this whole
    file exists to keep small — so that the host could inject a value that is fixed for the only
    account, region and environment there is. The reasoning is ADR 0022 §1; the standing rule here
    is the coupling it leaves behind: Terraform derives the group name from `var.project` and the
    region from `var.aws_region`, so **overriding either in `terraform.tfvars` means editing
    `compose.prod.yaml` in the same commit.** This is safe only because the failure is loud and
    immediate — the worker container refuses to start (`docs/deployment.md`, trap 10) — not
    because the coupling is nice.
11. **Log transport is the Docker daemon's job, never the application's.** The `awslogs` driver
    is what keeps the boundary at the top of this file intact: no AWS SDK, no credentials and no
    region lookup under `src/`. A CloudWatch Agent on the host and a PHP CloudWatch handler were
    both weighed and rejected in ADR 0022; neither may come back without amending it.

## Pułapki

1. **`user-data.sh` wykonuje się tylko przy pierwszym starcie.** Zmiana tego pliku nie zmienia
   działającego hosta. Diagnostyka: `/var/log/cloud-init-output.log`.
2. **AL2023 wnosi buildx w wersji za starej dla Compose v5.** Pakiet `docker` z dnf daje
   `0.12.1`, a `docker compose build` wymaga `>= 0.17.0` i odmawia komunikatem, który nie mówi
   nic o źródle problemu. `user-data.sh` dokłada bieżącą binarkę do
   `/usr/local/lib/docker/cli-plugins`, bo ten katalog ma pierwszeństwo przed
   `/usr/libexec/docker/cli-plugins`. W repozytoriach dnf nie ma pakietu `docker-buildx-plugin`.
3. **Kubełek kopii ma nazwę z identyfikatorem konta**, bo nazwy S3 są globalne. Nie zmieniaj
   jej po pierwszym `apply` — zmiana nazwy to skasowanie kubełka razem ze zrzutami.
4. **Budżet z alertem nie zatrzymuje wydatków**, tylko wysyła maila. To jedyny hamulec, jaki
   jest, więc `alert_email` musi być adresem, który ktoś czyta.
5. **`terraform destroy` zostawia wolumen główny i adres EIP nie jest darmowy, gdy nie jest
   podpięty.** Po zniszczeniu środowiska sprawdź `aws ec2 describe-volumes` i
   `describe-addresses`, inaczej rachunek biegnie dalej.
6. **NIE PODNOŚ liczby workerów powyżej czterech.** Cztery są bezpieczne wyłącznie dlatego,
   że repliki montują wspólny wolumen `app_share` na `/app/var/share` i dzielą jedno wiadro
   tokenów. Piąty proces poza tym wolumenem — na przykład konsument uruchomiony ręcznie
   w kontenerze bez tego montowania — dokłada własne 2 żąd./s ponad zadeklarowany limit
   wobec cudzego serwisu. Liczba workerów mieszka w `compose.prod.yaml`, nie w poleceniu.
   ADR 0015 i ADR 0021.
7. **Pakiet `docker` z AL2023 odrzuca opcje `awslogs-stream-prefix` i `tag` sterownika
   `awslogs`** komunikatem `unknown log opt` — ta sama kategoria rozjazdu z upstream Docker CE
   co pułapka 2, tym razem w sterowniku logów, nie w buildx. Zmierzone bezpośrednio na hoście
   przy pierwszym uruchomieniu workera. `compose.prod.yaml` nie ustawia nazwy strumienia —
   szczegóły i konsekwencje w ADR 0022 §1.
