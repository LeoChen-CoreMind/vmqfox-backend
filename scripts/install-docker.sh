#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="${VMQ_PROJECT_ROOT:-$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)}"
cd -- "$ROOT"
ENV_FILE="${VMQ_DOCKER_ENV_FILE:-$ROOT/.env.docker}"
EXAMPLE="$ROOT/.env.docker.example"

command -v docker >/dev/null 2>&1 || { printf 'Docker is not installed. Install Docker Engine first.\n' >&2; exit 1; }
docker compose version >/dev/null 2>&1 || { printf 'Docker Compose v2 is not available.\n' >&2; exit 1; }
[[ -f "$EXAMPLE" ]] || { printf 'Missing %s\n' "$EXAMPLE" >&2; exit 1; }

if [[ ! -f "$ENV_FILE" ]]; then
    cp -- "$EXAMPLE" "$ENV_FILE"
    chmod 600 "$ENV_FILE"
    printf 'Created %s. Replace all replace-with-* values, then run this command again.\n' "$ENV_FILE"
    exit 2
fi

required=(ADMIN_USERNAME ADMIN_PASSWORD VMQ_DB_PASSWORD VMQ_DB_ROOT_PASSWORD)
for key in "${required[@]}"; do
    value="${!key:-}"
    if [[ -z "$value" ]]; then
        value="$(sed -n "s/^${key}=//p" "$ENV_FILE" | head -n 1 | tr -d '\r')"
    fi
    if [[ -z "$value" || "$value" == replace-with-* || "$value" == admin/admin ]]; then
        printf '%s in %s must be replaced with a real value.\n' "$key" "$ENV_FILE" >&2
        exit 1
    fi
done

docker compose --env-file "$ENV_FILE" config >/dev/null
docker compose --env-file "$ENV_FILE" up -d --build
port="$(sed -n 's/^VMQ_BACKEND_PORT=//p' "$ENV_FILE" | head -n 1 | tr -d '\r')"
port="${port:-8000}"
printf 'VMQFox backend started. Open http://127.0.0.1:%s/install/ to finish the web installer.\n' "$port"
