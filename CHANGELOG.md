# Changelog

All notable changes to `laravel-redis-advisor` are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.1] - 2026-10-22

### Fixed

- Redis Cluster connections are no longer reported as "did not answer" (which could fail CI). They are listed as
  not inspected, in the report, the JSON and the dashboard, and never become a finding. An app whose only Redis
  connections are clusters exits 2 with that reason.

### Documentation

- Exit codes: `2` also covers invalid options; partial connection failures become `connections` findings.
- What changes on hosts that disable `CONFIG` or `MEMORY` (persistence fallback, big-keys skipped).
- How sampling works: `PTTL` and `MEMORY USAGE` per key, `TYPE` only for reported big keys; `session-ttl`
  samples on its own.
- The tested-servers claim now points to the repository's integration suite.

## [0.1.0] - 2026-10-21

### Added

- Read-only Redis and Valkey advisor for Laravel, for phpredis and predis: 12 checks across queues
  (eviction, backlog, Horizon trimming), keys (cache:clear blast radius, prefix collisions, keys without a
  TTL, big keys), server (persistence, memory, fragmentation, reachability) and sessions (TTL against
  SESSION_LIFETIME).
- `redis-advisor:report` with text and JSON output and a CI exit code (`--fail-on`).
- Blade dashboard at `/redis-advisor`, behind the `viewRedisAdvisor` gate (local only by default).
- Works where hosts disable CONFIG (ElastiCache, MemoryDB, Upstash): settings come from INFO first, and
  checks that need more are skipped with the reason.
