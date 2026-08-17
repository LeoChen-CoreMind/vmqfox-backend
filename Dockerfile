# Multi-stage Dockerfile for vmqfox-backend (ThinkPHP)
# Based on CI config: PHP 8.2 with extensions: mbstring, zip, pdo_mysql

# --- Stage 1: Composer dependencies ---
FROM composer:2 AS vendor
WORKDIR /app
# Copy composer files first to leverage docker layer cache
COPY composer.json composer.lock* ./
# Install PHP dependencies (no dev) with optimized autoloader
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --no-progress \
    --ignore-platform-req=ext-bcmath \
    --optimize-autoloader

# --- Stage 2: Runtime (PHP-FPM) ---
FROM php:8.2-fpm-alpine3.22 AS runtime

ARG TZ=Asia/Shanghai
ENV TZ=${TZ}

# Install required system libs and PHP extensions
RUN set -eux; \
    apk add --no-cache \
      tzdata \
      curl-dev \
      libzip-dev \
      libxml2-dev \
      oniguruma-dev \
      icu-data-full icu-libs \
      zbar \
      python3 \
      py3-opencv \
      py3-zxing-cpp \
      tesseract-ocr \
      tesseract-ocr-data-eng; \
    docker-php-ext-install \
      bcmath \
      curl \
      pdo_mysql \
      mbstring \
      zip \
      xml \
      simplexml; \
    ln -snf /usr/share/zoneinfo/${TZ} /etc/localtime && echo ${TZ} > /etc/timezone

# Fail the image build early when a QR recognition dependency is unavailable.
RUN set -eux; \
    zbarimg --version; \
    python3 -c 'import cv2, zxingcpp; print(cv2.__version__)'; \
    tesseract --version; \
    php -r 'if (!function_exists("bcmul") || !function_exists("curl_init") || !function_exists("proc_open") || !class_exists("SimpleXMLElement")) { exit(1); }'

# (Optional) create a non-root user to run PHP-FPM
RUN addgroup -g 1000 www && adduser -D -G www -u 1000 www

WORKDIR /var/www/html

# Copy application code
COPY . .
# Copy vendor from the builder stage
COPY --from=vendor /app/vendor ./vendor

# Add entrypoint to generate .env from environment variables
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Ensure runtime/cache directories are writable (ThinkPHP uses runtime/)
RUN set -eux; \
    mkdir -p runtime && \
    chown -R www:www /var/www/html

USER www

# Expose ThinkPHP built-in server port
EXPOSE 8000

ENTRYPOINT ["/entrypoint.sh"]
CMD ["php", "think", "run"]
