#!/usr/bin/env bash
set -euo pipefail

cd /opt/cars

set -a
. ./.env.prod.local
set +a

: "${BACKUP_BUCKET:?BACKUP_BUCKET must be set in .env.prod.local}"

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
dump="/tmp/cars-${stamp}.dump"

trap 'rm -f "${dump}"' EXIT

docker compose --env-file .env.prod.local -f compose.prod.yaml exec -T database \
	pg_dump -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" --format=custom > "${dump}"

aws s3 cp "${dump}" "s3://${BACKUP_BUCKET}/postgres/cars-${stamp}.dump"
