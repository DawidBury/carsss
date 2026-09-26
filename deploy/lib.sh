set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REMOTE_DIR=/opt/cars
COMPOSE='docker compose --env-file .env.prod.local -f compose.prod.yaml'

: "${CARS_INSTANCE_ID:?set CARS_INSTANCE_ID to the EC2 instance id from terraform output}"

REMOTE="ec2-user@${CARS_INSTANCE_ID}"

remote() {
	ssh -F "${ROOT}/deploy/ssh.config" "${REMOTE}" "$@"
}
