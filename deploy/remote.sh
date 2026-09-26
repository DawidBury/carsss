#!/usr/bin/env bash
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

if [ "$#" -eq 0 ]; then
	exec ssh -t -F "${ROOT}/deploy/ssh.config" "${REMOTE}"
fi

remote "$@"
