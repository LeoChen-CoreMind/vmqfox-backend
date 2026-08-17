#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
MODE=""
if [[ "${1:-}" == "--mode" ]]; then
    MODE="${2:-}"
fi

case "$MODE" in
    docker)
        exec bash "$SCRIPT_DIR/install-docker.sh" "${@:3}"
        ;;
    baota|linux)
        exec bash "$SCRIPT_DIR/install-baota.sh" "${@:3}"
        ;;
    *)
        printf 'Usage: %s --mode docker|baota\n' "$0" >&2
        exit 2
        ;;
esac
