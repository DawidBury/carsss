#!/usr/bin/env bash
set -euo pipefail

cd /opt/cars

set -a
. ./.env.prod.local
set +a

COMPOSE=(docker compose --env-file .env.prod.local -f compose.prod.yaml)

queue_depth() {
	"${COMPOSE[@]}" exec -T -u www-data api php bin/console messenger:stats import --format=json \
		| grep -o '"count":[0-9]*' | head -1 | cut -d: -f2
}

running_run_id() {
	"${COMPOSE[@]}" exec -T database psql -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" -tAc \
		"SELECT id FROM import_run WHERE status = 'running' ORDER BY id DESC LIMIT 1"
}

if [ -n "$(running_run_id | tr -d '[:space:]')" ]; then
	echo "an import run is already running" >&2
	exit 1
fi

shut_down() {
	status=$?

	"${COMPOSE[@]}" --profile import rm -sf worker || true

	if [ "${status}" -ne 0 ]; then
		failed_run_id="$(running_run_id | tr -d '[:space:]')" || failed_run_id=""
		if [ -n "${failed_run_id}" ]; then
			"${COMPOSE[@]}" exec -T -u www-data api php bin/console app:import:finish "${failed_run_id}" --failed || true
		fi
	fi
}
trap shut_down EXIT
trap 'exit 1' INT TERM

"${COMPOSE[@]}" --profile import up -d worker
"${COMPOSE[@]}" exec -T -u www-data api php bin/console app:import:start

deadline=$(( $(date +%s) + 32400 ))
drained=0

while [ "${drained}" -lt 3 ]; do
	if [ "$(date +%s)" -gt "${deadline}" ]; then
		echo "import did not drain the queue before the deadline" >&2
		exit 1
	fi

	sleep 60

	if [ "$(queue_depth)" = "0" ]; then
		drained=$(( drained + 1 ))
	else
		drained=0
	fi
done

run_id="$(running_run_id | tr -d '[:space:]')"
if [ -n "${run_id}" ]; then
	"${COMPOSE[@]}" exec -T -u www-data api php bin/console app:import:finish "${run_id}"
fi

"${COMPOSE[@]}" exec -T -u www-data api php bin/console app:search:refresh
