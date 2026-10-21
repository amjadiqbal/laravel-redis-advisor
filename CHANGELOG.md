# Changelog

All notable changes to `laravel-redis-advisor` are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Read-only Redis and Valkey advisor for Laravel, for phpredis and predis: 12 checks across queues
  (eviction, backlog, Horizon trimming), keys (cache:clear blast radius, prefix collisions, keys without a
  TTL, big keys), server (persistence, memory, fragmentation, reachability) and sessions (TTL against
  SESSION_LIFETIME).
- `redis-advisor:report` with text and JSON output and a CI exit code (`--fail-on`).
- Blade dashboard at `/redis-advisor`, behind the `viewRedisAdvisor` gate (local only by default).
- Works where hosts disable CONFIG (ElastiCache, MemoryDB, Upstash): settings come from INFO first, and
  checks that need more are skipped with the reason.
