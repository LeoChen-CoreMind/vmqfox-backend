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

PHP_BIN="$(command -v php || true)"
[[ -n "$PHP_BIN" && -x "$PHP_BIN" ]] || { printf 'The default PHP command was not found. Configure the default PHP CLI version in Baota first.\n' >&2; exit 1; }
PHP_VERSION="$(php_version "$PHP_BIN")"
version_is_supported "$PHP_VERSION" || { printf 'The default PHP is %s; VMQFox requires PHP >= 8.2. Change the default PHP version in Baota first.\n' "$PHP_VERSION" >&2; exit 1; }
printf 'Using the default PHP %s at %s\n' "$PHP_VERSION" "$PHP_BIN"

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

if [[ ! -e .env ]]; then
    printf '# VMQFOX_INSTALLER_PLACEHOLDER\n' > .env
fi
if [[ ! -s .env ]] || grep -qx '# VMQFOX_INSTALLER_PLACEHOLDER' .env; then
    if id www >/dev/null 2>&1; then
        chown www:www .env
    fi
    chmod 600 .env
fi

printf 'Baota dependencies and Composer packages are installed.\n'
printf 'In Baota, restart the PHP %s service, then open /install/ to finish setup.\n' "$PHP_VERSION"
printf 'The exact PHP-FPM service name is panel-specific; restart the version used by this site.\n'
