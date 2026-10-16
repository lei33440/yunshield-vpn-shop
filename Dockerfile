FROM php:7.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libcurl4-openssl-dev curl \
    && docker-php-ext-install curl pdo_mysql zip \
    && curl -fsSL https://curl.se/ca/cacert.pem -o /usr/local/share/ca-certificates/cacert.pem \
    && update-ca-certificates \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/router.php"]
