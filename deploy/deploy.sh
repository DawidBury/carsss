#!/usr/bin/env bash
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

FRONT_DIR="${FRONT_DIR:-${ROOT}/../carsss_front}"

rsync -az --delete \
	--exclude-from="${ROOT}/deploy/rsync-exclude" \
	-e "ssh -F ${ROOT}/deploy/ssh.config" \
	"${ROOT}/" "${REMOTE}:${REMOTE_DIR}/"

remote "mkdir -p ${REMOTE_DIR}/front"

if [ -d "${FRONT_DIR}" ]; then
	(
		cd "${FRONT_DIR}"
		. "${HOME}/.nvm/nvm.sh"
		nvm use
		npm ci
		npm run build
	)
	rsync -az --delete \
		-e "ssh -F ${ROOT}/deploy/ssh.config" \
		"${FRONT_DIR}/dist/" "${REMOTE}:${REMOTE_DIR}/front/"
fi

remote "cd ${REMOTE_DIR} && ${COMPOSE} build"
remote "cd ${REMOTE_DIR} && ${COMPOSE} up -d database rabbitmq"
remote "cd ${REMOTE_DIR} && ${COMPOSE} run --rm --user www-data api php bin/console doctrine:migrations:migrate --no-interaction"
remote "cd ${REMOTE_DIR} && ${COMPOSE} up -d api caddy"
remote "cd ${REMOTE_DIR} && ${COMPOSE} exec -T -u www-data api php bin/console cache:warmup"
