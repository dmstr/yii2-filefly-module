#!/bin/bash
# file generated with AI assistance: Claude Code - [2026-11-12 18:30:00 UTC]
# Runs PHPUnit on the host against one database server of ./compose.yaml.
#
# Usage, from the repository root, with the server started
# (docker compose -p filefly-db -f tests/db/compose.yaml up -d --wait <service>):
#   ./tests/db/run.sh <service> [phpunit arguments...]
#   ./tests/db/run.sh mariadb106 tests/api
#   eval "$(./tests/db/run.sh --env postgres16)"   # only export the variables
# Services: mariadb106, mariadb118, mysql84, postgres16. Extra environment such as
# FILEFLY_TEST_DB_INIT_SQL is passed through to PHPUnit.
set -euo pipefail

script_dir="$(cd "$(dirname "$0")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"

die() {
  echo "$*" >&2
  exit 1
}

# host:port of the published container port of a service
published_address() {
  local service="$1" container_port="$2" address
  address="$(docker compose -p filefly-db -f "$script_dir/compose.yaml" \
    port "$service" "$container_port")" \
    || die "service $service is not running, start it first (see usage)"
  test -n "$address" || die "service $service publishes no port $container_port"
  echo "$address"
}

print_only=0
if test "${1:-}" = "--env"; then
  print_only=1
  shift
fi
service="${1:-}"
test -n "$service" || die "usage: $0 [--env] <service> [phpunit arguments...]"
shift

set -a
# shellcheck source=./test-credentials.env
. "$script_dir/test-credentials.env"
set +a

case "$service" in
  mariadb*|mysql*)
    address="$(published_address "$service" 3306)"
    dsn="mysql:host=${address%:*};port=${address##*:};dbname=$MYSQL_DATABASE"
    user="$MYSQL_USER"
    password="$MYSQL_PASSWORD"
    ;;
  postgres*)
    address="$(published_address "$service" 5432)"
    dsn="pgsql:host=${address%:*};port=${address##*:};dbname=$POSTGRES_DB"
    user="$POSTGRES_USER"
    password="$POSTGRES_PASSWORD"
    ;;
  *)
    die "unknown service $service"
    ;;
esac

export FILEFLY_TEST_DB_DSN="$dsn"
export FILEFLY_TEST_DB_USER="$user"
export FILEFLY_TEST_DB_PASSWORD="$password"

if test "$print_only" = 1; then
  # %q quotes for the calling shell, the DSN contains semicolons
  printf 'export FILEFLY_TEST_DB_DSN=%q\n' "$dsn"
  printf 'export FILEFLY_TEST_DB_USER=%q\n' "$user"
  printf 'export FILEFLY_TEST_DB_PASSWORD=%q\n' "$password"
  exit 0
fi

cd "$repo_root"
exec vendor/bin/phpunit "$@"
