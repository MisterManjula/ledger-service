FROM php:8.3-cli

# pdo_pgsql is the only extension this project needs: all SQL is hand-written
# and executed through PDO, with no ORM in between. unzip is for Composer, which
# otherwise cannot unpack the dist archives it downloads; postgresql-client
# provides the psql that bin/migrate and bin/seed drive.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip postgresql-client \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependencies first, so editing source code does not invalidate the install layer.
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-progress

COPY . .

# Kept outside /app so the bind mount in docker-compose.yml cannot shadow it.
COPY docker/entrypoint.sh /usr/local/bin/ledger-entrypoint
RUN chmod +x /usr/local/bin/ledger-entrypoint

EXPOSE 8080
ENTRYPOINT ["ledger-entrypoint"]
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
