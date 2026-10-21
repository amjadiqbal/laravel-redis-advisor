<?php

declare(strict_types=1);

namespace AmjadIqbal\RedisAdvisor\Checks\Server;

use AmjadIqbal\RedisAdvisor\Category;
use AmjadIqbal\RedisAdvisor\Checks\Check;
use AmjadIqbal\RedisAdvisor\Checks\Thresholds;
use AmjadIqbal\RedisAdvisor\Finding;
use AmjadIqbal\RedisAdvisor\Redis\Topology;
use AmjadIqbal\RedisAdvisor\Severity;
use AmjadIqbal\RedisAdvisor\Support\Bytes;

/**
 * Memory the OS gave Redis versus memory Redis uses: fragmentation above, swap below.
 *
 * The ratio alone misleads on small servers (a few MB always look fragmented),
 * so it only counts once the wasted bytes are large.
 */
final class Fragmentation implements Check
{
    public function id(): string
    {
        return 'fragmentation';
    }

    public function title(): string
    {
        return 'Memory fragmentation and swap';
    }

    public function category(): Category
    {
        return Category::Server;
    }

    public function run(Topology $redis, Thresholds $thresholds): array
    {
        $findings = [];

        foreach ($redis->servers() as $server) {
            $info = $server->info();
            $used = (int) ($info['used_memory'] ?? 0);
            $rss = (int) ($info['used_memory_rss'] ?? 0);
            $address = $server->server()->address;

            if ($used === 0 || $rss === 0) {
                continue;
            }

            $ratio = isset($info['mem_fragmentation_ratio']) ? (float) $info['mem_fragmentation_ratio'] : $rss / $used;
            $wasted = (int) ($info['mem_fragmentation_bytes'] ?? $rss - $used);
            $evidence = ['used_memory' => Bytes::human($used), 'used_memory_rss' => Bytes::human($rss), 'mem_fragmentation_ratio' => round($ratio, 2), 'allocator' => (string) ($info['mem_allocator'] ?? '?')];

            if ($ratio < $thresholds->float('swap_ratio', 0.9) && $used >= $thresholds->int('swap_min_used_bytes', 100 * 1024 ** 2)) {
                $findings[] = new Finding(
                    check: $this->id(),
                    category: $this->category(),
                    severity: Severity::Critical,
                    subject: $address,
                    title: "Part of Redis on {$address} is in swap (RSS ".Bytes::human($rss).' for '.Bytes::human($used).' of data)',
                    detail: 'Redis uses more memory than the OS keeps in RAM for it, so some reads come from disk and every command slows down. '
                        .'Lower maxmemory below the RAM the machine can spare, or give it more RAM, and keep swap out of its way.',
                    fix: "# redis.conf on {$address}: a limit the machine can hold in RAM\nmaxmemory ".Bytes::config($rss * 0.8)."\n\n# on the host (Linux)\nsysctl vm.swappiness=1",
                    evidence: $evidence,
                );

                continue;
            }

            if ($ratio < $thresholds->float('fragmentation_ratio', 1.5) || $wasted < $thresholds->int('fragmentation_min_bytes', 100 * 1024 ** 2)) {
                continue;
            }

            $jemalloc = str_contains(strtolower((string) ($info['mem_allocator'] ?? '')), 'jemalloc');

            $findings[] = new Finding(
                check: $this->id(),
                category: $this->category(),
                severity: Severity::Warning,
                subject: $address,
                title: "Memory on {$address} is ".round($ratio, 1).'x fragmented ('.Bytes::human($wasted).' held but unused)',
                detail: 'After many keys are deleted or rewritten, the allocator keeps memory Redis no longer uses. It counts against the machine, not against maxmemory, '
                    .'so the server can run out of RAM while Redis reports room to spare. '
                    .($jemalloc
                        ? 'This server uses jemalloc, so active defragmentation can give it back while it runs.'
                        : 'Active defragmentation needs jemalloc, which this build lacks; a restart in a quiet window (with persistence on) or a failover to a replica gives the memory back.'),
                fix: $jemalloc
                    ? "# redis.conf on {$address}\nactivedefrag yes\n\n# or at runtime (this package does not run it):\nCONFIG SET activedefrag yes"
                    : null,
                evidence: $evidence + ['fragmentation_bytes' => Bytes::human($wasted)],
            );
        }

        return $findings;
    }
}
