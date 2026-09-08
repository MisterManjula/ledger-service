#!/bin/sh
# Brings the schema up to date before the HTTP server starts accepting requests,
# so `docker compose up` on a fresh checkout yields a usable service rather than
# one that 500s until someone runs a migration by hand.
#
# Compose already gates this container on the database's healthcheck, so there is
# no wait-for-it loop here: if psql cannot connect, that is a real failure and
# should stop the container rather than be retried away.
set -eu

sh /app/bin/migrate

# Demo data is opt-in, because seeding on every start is only ever correct for a
# throwaway environment. docker-compose.yml turns it on; nothing else does.
if [ "${SEED_ON_START:-false}" = "true" ]; then
    sh /app/bin/seed
fi

exec docker-php-entrypoint "$@"
