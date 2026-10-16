FROM ubuntu:24.04

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
       ca-certificates curl git unzip openssl \
       php-cli php-xml php-mbstring php-curl php-zip \
    && rm -rf /var/lib/apt/lists/* \
    && curl -fsSL -o /tmp/composer-setup.php https://getcomposer.org/installer \
    && EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)" \
    && ACTUAL="$(openssl dgst -sha384 -binary /tmp/composer-setup.php | openssl base64 -A)" \
    && [ "$EXPECTED" = "$ACTUAL" ] \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm -f /tmp/composer-setup.php

USER ubuntu
