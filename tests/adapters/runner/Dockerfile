# file generated with AI assistance: Claude Code - [2026-11-11 21:00:00 UTC]
# PHP runner for the adapter probe. The official image lacks ext-ftp, so it
# is built here with OpenSSL (needed for FTPS). Composer installs the probe
# dependencies into /opt/probe-vendor inside the image, NOT into the checkout:
# vendor/ below tests/ would contain *Test.php files that the module's root
# PHPUnit configuration (directory `tests`) would pick up.
FROM php:8.4.27-cli-alpine3.24

RUN apk add --no-cache git unzip openssl \
    && apk add --no-cache --virtual .build-deps openssl-dev \
    && docker-php-ext-configure ftp --with-ftp-ssl \
    && docker-php-ext-install ftp \
    && apk del .build-deps \
    && php -r 'exit(function_exists("ftp_ssl_connect") ? 0 : 1);'

COPY --from=composer:2.11.2 /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_VENDOR_DIR=/opt/probe-vendor \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /adapters/probe
COPY probe/composer.json ./
RUN composer install --no-interaction --no-progress --no-dev \
        --no-autoloader \
    && rm -f composer.lock

# The probe sources are bind-mounted at runtime (compose.yaml); the
# autoloader is dumped at start, so src/ changes need no image rebuild.
CMD ["sh", "-c", "composer dump-autoload --quiet && php run-probe.php"]
