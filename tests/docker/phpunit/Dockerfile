# PHP CLI with PHPUnit for the unit tests of the header function.
# Built once for PHP 8.4 (same as the Roundcube images) and once for 8.1,
# the lowest PHP version the plugin supports.
ARG PHP_STAGE=php84

FROM composer:2@sha256:50ea4d33fdcde1adb0f045547295faa1548b33872e5f789dae5b02ce2fb5de93 AS phpunit_dependencies

ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock /opt/phpunit/
RUN composer install --working-dir=/opt/phpunit --no-interaction --no-progress \
    --no-plugins --no-scripts --classmap-authoritative

FROM php:8.4-cli@sha256:6b13a8678b38ca38e4e3950be57b664ed8c8706131a0586111f68792aede4c58 AS php84
FROM php:8.1-cli@sha256:76e563191d1ade120313a8736df24154d21da5155c0756f147c0b01bd19d9087 AS php81

FROM ${PHP_STAGE}

ENV PATH=/opt/phpunit/vendor/bin:$PATH
COPY --from=phpunit_dependencies /opt/phpunit /opt/phpunit
RUN phpunit --version
