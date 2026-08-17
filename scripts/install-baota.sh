#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="${VMQ_PROJECT_ROOT:-$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)}"
cd -- "$ROOT"

if [[ "$(id -u)" -ne 0 ]]; then
    printf 'Run this script as root in the Baota/Linux terminal.\n' >&2
    exit 1
fi

php_version() {
    "$1" -v 2>/dev/null | sed -n 's/^PHP \([0-9][0-9.]*\).*/\1/p' | head -n 1
}

version_is_supported() {
    local version="$1"
    [[ -n "$version" ]] || return 1
    [[ "$(printf '%s\n' "$version" '8.2' | sort -V | head -n 1)" == '8.2' ]]
}

declare -a PHP_CANDIDATES=()
declare -a PHP_VERSIONS=()
add_candidate() {
    local candidate="$1"
    local candidate_version
    [[ -x "$candidate" ]] || return 0
    candidate_version="$(php_version "$candidate")"
    version_is_supported "$candidate_version" || return 0
    for existing in "${PHP_CANDIDATES[@]:-}"; do
        [[ "$existing" == "$candidate" ]] && return 0
    done
    PHP_CANDIDATES+=("$candidate")
    PHP_VERSIONS+=("$candidate_version")
}

if command -v php >/dev/null 2>&1; then
    add_candidate "$(command -v php)"
fi
shopt -s nullglob
for candidate in /www/server/php/*/bin/php; do
    add_candidate "$candidate"
done
shopt -u nullglob

(( ${#PHP_CANDIDATES[@]} > 0 )) || { printf 'No PHP >= 8.2 installation was detected. Set VMQ_PHP_BIN to the PHP used by this site.\n' >&2; exit 1; }

printf 'Detected PHP candidates:\n'
for index in "${!PHP_CANDIDATES[@]}"; do
    printf '  [%d] PHP %s - %s\n' "$((index + 1))" "${PHP_VERSIONS[$index]}" "${PHP_CANDIDATES[$index]}"
done

PHP_BIN="${VMQ_PHP_BIN:-}"
if [[ -n "$PHP_BIN" ]]; then
    [[ -x "$PHP_BIN" ]] || { printf 'VMQ_PHP_BIN does not point to an executable: %s\n' "$PHP_BIN" >&2; exit 1; }
    explicit_version="$(php_version "$PHP_BIN")"
    version_is_supported "$explicit_version" || { printf 'VMQ_PHP_BIN uses PHP %s; VMQFox requires PHP >= 8.2.\n' "$explicit_version" >&2; exit 1; }
elif [[ -n "${VMQ_PHP_VERSION:-}" ]]; then
    for index in "${!PHP_CANDIDATES[@]}"; do
        if [[ "${PHP_VERSIONS[$index]}" == "${VMQ_PHP_VERSION}"* ]]; then
            PHP_BIN="${PHP_CANDIDATES[$index]}"
            break
        fi
    done
    [[ -n "$PHP_BIN" ]] || { printf 'VMQ_PHP_VERSION=%s was not found in the scanned candidates.\n' "$VMQ_PHP_VERSION" >&2; exit 1; }
elif [[ -t 0 && -t 1 ]]; then
    while true; do
        read -r -p 'Select the PHP binary used by this site [1-'"${#PHP_CANDIDATES[@]}"']: ' selection
        if [[ "$selection" =~ ^[0-9]+$ ]] && (( selection >= 1 && selection <= ${#PHP_CANDIDATES[@]} )); then
            PHP_BIN="${PHP_CANDIDATES[$((selection - 1))]}"
            break
        fi
        printf 'Invalid selection. Enter one of the listed numbers.\n' >&2
    done
else
    # CI/non-interactive deployments must be deterministic but still use a scanned version.
    best=0
    for index in "${!PHP_CANDIDATES[@]}"; do
        if (( best == 0 )) || [[ "$(printf '%s\n' "${PHP_VERSIONS[$best]}" "${PHP_VERSIONS[$index]}" | sort -V | tail -n 1)" == "${PHP_VERSIONS[$index]}" ]]; then
            best="$index"
        fi
    done
    PHP_BIN="${PHP_CANDIDATES[$best]}"
    printf 'Non-interactive input; selected highest scanned PHP %s.\n' "${PHP_VERSIONS[$best]}"
fi

PHP_VERSION="$(php_version "$PHP_BIN")"
printf 'Selected PHP %s at %s\n' "$PHP_VERSION" "$PHP_BIN"

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y \
    php-bcmath php-curl php-mysql php-mbstring php-zip php-xml \
    composer zbar-tools tesseract-ocr tesseract-ocr-eng

# QR Python packages vary by Debian release; zbar and Tesseract remain available.
apt-get install -y python3-opencv python3-zxing-cpp 2>/dev/null || \
    printf 'Optional Python QR packages were unavailable; zbarimg/Tesseract will still be used.\n' >&2

COMPOSER_BIN="$(command -v composer || true)"
[[ -n "$COMPOSER_BIN" ]] || { printf 'Composer was not found after package installation.\n' >&2; exit 1; }
"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --prefer-dist --optimize-autoloader --no-interaction

mkdir -p runtime
if id www >/dev/null 2>&1; then
    chown -R www:www vendor runtime
fi
chmod -R 755 vendor
chmod -R 775 runtime

printf 'Baota dependencies and Composer packages are installed.\n'
printf 'In Baota, restart the PHP %s service, then open /install/ to finish setup.\n' "$PHP_VERSION"
printf 'The exact PHP-FPM service name is panel-specific; restart the version used by this site.\n'
