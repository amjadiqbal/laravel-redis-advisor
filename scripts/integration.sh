#!/usr/bin/env bash
# Runs tests/Integration against real servers in throwaway Docker containers: Redis 7, Redis 8 and
# Valkey 8, each with predis and (when a PHP with the extension is found) phpredis, plus a Redis 7
# with CONFIG renamed away, as managed hosts do. Each check is seen firing on a real problem and clearing once fixed.
#   scripts/integration.sh                   everything
#   SERVERS=valkey scripts/integration.sh    one server (redis7, redis8, valkey, restricted)
#   CLIENTS=predis scripts/integration.sh    one client
#   KEEP=1 scripts/integration.sh            leave the containers running afterwards
set -uo pipefail
cd "$(dirname "$0")/.."

SERVERS=${SERVERS:-"redis7 redis8 valkey restricted"}
CLIENTS=${CLIENTS:-"predis phpredis"}
PHP=${PHP:-php}
FAILED=0; RESULTS=()

# phpredis is an extension: find a PHP that has it (Homebrew's php@8.x with pecl redis).
PHPREDIS_PHP=${PHPREDIS_PHP:-}
if [ -z "$PHPREDIS_PHP" ]; then
  for p in "$PHP" php8.5 php8.4 php8.3 "$(brew --prefix 2>/dev/null)/opt/php@8.5/bin/php" "$(brew --prefix 2>/dev/null)/opt/php@8.4/bin/php" "$(brew --prefix 2>/dev/null)/opt/php@8.3/bin/php"; do
    if command -v "$p" >/dev/null 2>&1 && "$p" -r 'exit(extension_loaded("redis") ? 0 : 1);' 2>/dev/null; then PHPREDIS_PHP=$p; break; fi
  done
fi

# vendor/ is resolved for $PHP; phpredis may live in another PHP version, so it gets its own copy
# of the package (build/phpredis, gitignored) with dependencies resolved for that PHP.
phpredis_dir() {
  local dir=build/phpredis
  mkdir -p "$dir"
  rsync -a --delete --exclude vendor --exclude build --exclude .git --exclude composer.lock ./ "$dir/" || return 1
  (cd "$dir" && "$PHPREDIS_PHP" "$(command -v composer)" update --no-interaction --prefer-dist --no-progress -q) || return 1
  echo "$dir"
}

start() { # name image port args...
  local name=$1 image=$2 port=$3; shift 3
  docker rm -f "$name" >/dev/null 2>&1
  docker run -d --name "$name" -p "$port:6379" "$image" "$@" >/dev/null || return 1
  for _ in $(seq 1 30); do
    [ "$(docker exec "$name" sh -c 'redis-cli ping 2>/dev/null || valkey-cli ping' 2>/dev/null)" = PONG ] && return 0
    sleep 1
  done
  echo "$name did not start"; return 1
}

for server in $SERVERS; do
  restricted=""
  case $server in
    redis7)     name=rla-it-redis7;  image=redis:7-alpine;          port=36379; args=(redis-server) ;;
    redis8)     name=rla-it-redis8;  image=redis:8-alpine;          port=36380; args=(redis-server) ;;
    valkey)     name=rla-it-valkey8; image=valkey/valkey:8-alpine;  port=36381; args=(valkey-server) ;;
    restricted) name=rla-it-noconfig; image=redis:7-alpine;         port=36382; restricted=1
                args=(redis-server --rename-command CONFIG "" --maxmemory 64mb --maxmemory-policy allkeys-lru --save "" --appendonly no) ;;
    *) echo "unknown server $server"; exit 2 ;;
  esac
  printf '\n== %s (%s)\n' "$server" "$image"
  if ! start "$name" "$image" "$port" "${args[@]}"; then RESULTS+=("FAIL  $server: did not start"); FAILED=1; continue; fi

  for client in $CLIENTS; do
    php_bin=$PHP
    if [ "$client" = phpredis ]; then
      if [ -z "$PHPREDIS_PHP" ]; then RESULTS+=("skip  $server / phpredis: no PHP with the redis extension"); continue; fi
      php_bin=$PHPREDIS_PHP
      PHPREDIS_DIR=${PHPREDIS_DIR:-$(phpredis_dir)} || { RESULTS+=("FAIL  $server / phpredis: could not install for $PHPREDIS_PHP"); FAILED=1; continue; }
      dir=$PHPREDIS_DIR
    else
      dir=.
    fi
    if (cd "$dir" && REDIS_ADVISOR_TEST_PORT=$port REDIS_ADVISOR_TEST_CLIENT=$client REDIS_ADVISOR_TEST_RESTRICTED=$restricted \
       "$php_bin" vendor/bin/pest --group=integration); then
      RESULTS+=("pass  $server / $client")
    else
      RESULTS+=("FAIL  $server / $client"); FAILED=1
    fi
  done
  [ -n "${KEEP:-}" ] || docker rm -f "$name" >/dev/null 2>&1
done

printf '\n== Integration summary\n'; printf '%s\n' "${RESULTS[@]}"
exit $FAILED
