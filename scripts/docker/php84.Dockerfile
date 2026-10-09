# PHP 8.4 CLI with the extensions the test suites need. For machines without a
# local PHP: see "Docker toolbox" in docs/TESTING.md. Local testing only.
FROM php:8.4-cli
RUN docker-php-ext-install pdo_mysql mysqli pcntl \
 && apt-get update && apt-get install -y --no-install-recommends mariadb-client \
 && rm -rf /var/lib/apt/lists/*
