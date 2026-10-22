<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Redis Advisor</title>
    <style>
        :root {
            --bg: #F8FAFC; --panel: #FFFFFF; --border: #E2E8F0; --text: #0F172A; --muted: #64748B; --soft: #F1F5F9;
            --accent: #4F46E5; --accent-text: #4338CA; --code-bg: #0F172A; --code-text: #E2E8F0; --code-accent: #A5B4FC;
            --critical: #DC2626; --critical-bg: #FEE2E2; --warning: #D97706; --warning-bg: #FEF3C7; --notice: #4F46E5; --notice-bg: #E0E7FF;
            --ok: #16A34A;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0B1020; --panel: #13163A; --border: #262B55; --text: #F8FAFC; --muted: #94A3B8; --soft: #161A3A;
                --accent: #818CF8; --accent-text: #A5B4FC; --code-bg: #0B1020; --code-text: #E2E8F0; --code-accent: #A5B4FC;
                /* Solid colours only: on dark, translucent warm fills read as brown. Badges use a neutral fill and coloured text. */
                --critical: #F87171; --critical-bg: #161A3A; --warning: #FBBF24; --warning-bg: #161A3A; --notice: #818CF8; --notice-bg: #161A3A;
                --ok: #4ADE80;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Helvetica, Arial, sans-serif; }
        a { color: var(--accent-text); }
        .wrap { max-width: 1080px; margin: 0 auto; padding: 28px 16px 56px; }
        header { display: flex; flex-wrap: wrap; gap: 12px 24px; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .brand { display: flex; align-items: center; gap: 12px; }
        .brand h1 { font-size: 22px; margin: 0; letter-spacing: -0.01em; }
        .server { color: var(--muted); font-size: 13.5px; }
        .server b { color: var(--text); font-weight: 600; }
        .refresh { display: inline-block; padding: 7px 14px; border: 1px solid var(--border); border-radius: 8px; background: var(--panel); color: var(--text); text-decoration: none; font-size: 14px; }
        .refresh:hover { border-color: var(--accent); }
        .summary { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; margin-bottom: 18px; }
        .stat { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 12px 14px; }
        .stat .n { font-size: 26px; font-weight: 700; line-height: 1.1; }
        .stat .l { color: var(--muted); font-size: 13px; }
        .stat.critical .n { color: var(--critical); } .stat.warning .n { color: var(--warning); } .stat.notice .n { color: var(--notice); } .stat.ok .n { color: var(--ok); }
        nav { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 16px; }
        nav a { padding: 6px 12px; border-radius: 999px; border: 1px solid var(--border); background: var(--panel); color: var(--text); text-decoration: none; font-size: 13.5px; }
        nav a.on { border-color: var(--accent); color: var(--accent-text); font-weight: 600; }
        .banner { border: 1px solid var(--border); border-left: 4px solid var(--warning); background: var(--panel); border-radius: 10px; padding: 12px 14px; margin-bottom: 16px; font-size: 14px; }
        .banner.error { border-left-color: var(--critical); }
        .finding { background: var(--panel); border: 1px solid var(--border); border-left: 4px solid var(--notice); border-radius: 12px; padding: 16px 18px; margin-bottom: 12px; }
        .finding.critical { border-left-color: var(--critical); } .finding.warning { border-left-color: var(--warning); }
        .meta { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 6px; font-size: 12.5px; color: var(--muted); }
        .badge { padding: 2px 9px; border-radius: 999px; font-weight: 700; font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; border: 1px solid currentColor; }
        .badge.critical { color: var(--critical); background: var(--critical-bg); } .badge.warning { color: var(--warning); background: var(--warning-bg); } .badge.notice { color: var(--notice); background: var(--notice-bg); }
        .finding h2 { font-size: 16px; margin: 0 0 6px; line-height: 1.4; word-break: break-word; }
        .finding p { margin: 0 0 10px; color: var(--text); opacity: .92; }
        .fix { margin-top: 10px; }
        .fix .label { display: flex; justify-content: space-between; align-items: center; font-size: 12.5px; color: var(--muted); margin-bottom: 4px; }
        .fix pre { margin: 0; background: var(--code-bg); color: var(--code-text); border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; overflow-x: auto; font: 13px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; white-space: pre; }
        .copy { border: 1px solid var(--border); background: var(--soft); color: var(--text); border-radius: 6px; padding: 2px 9px; font-size: 12px; cursor: pointer; }
        .copy:hover { border-color: var(--accent); }
        details { margin-top: 10px; font-size: 13.5px; }
        summary { cursor: pointer; color: var(--muted); }
        table.ev { border-collapse: collapse; margin-top: 6px; width: 100%; }
        table.ev td { border-top: 1px solid var(--border); padding: 5px 8px 5px 0; vertical-align: top; word-break: break-word; }
        table.ev td:first-child { color: var(--muted); white-space: nowrap; width: 1%; padding-right: 16px; }
        table.ev code { font: 12.5px ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        h3 { font-size: 15px; margin: 26px 0 10px; }
        .list { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 4px 16px; }
        .list .row { padding: 10px 0; border-top: 1px solid var(--border); font-size: 14px; }
        .list .row:first-child { border-top: 0; }
        .list .why { color: var(--muted); }
        .list pre { margin: 6px 0 0; background: var(--code-bg); color: var(--code-text); border-radius: 6px; padding: 8px 10px; overflow-x: auto; font: 12.5px/1.5 ui-monospace, Menlo, Consolas, monospace; }
        .passed { display: flex; flex-wrap: wrap; gap: 6px; }
        .passed span { font-size: 13px; padding: 4px 10px; border-radius: 999px; border: 1px solid var(--border); background: var(--panel); }
        .passed span::before { content: "✓ "; color: var(--ok); font-weight: 700; }
        .empty { text-align: center; padding: 34px 16px; background: var(--panel); border: 1px solid var(--border); border-radius: 12px; color: var(--muted); }
        .empty b { display: block; color: var(--ok); font-size: 17px; margin-bottom: 4px; }
        footer { margin-top: 34px; color: var(--muted); font-size: 13px; display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; }
        @media (max-width: 720px) { .summary { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div class="brand">
            <svg width="38" height="38" viewBox="0 0 512 512" aria-hidden="true">
                <rect x="8" y="8" width="496" height="496" rx="108" fill="#0F172A" stroke="#334155" stroke-width="16"/>
                <rect x="64" y="96" width="384" height="312" rx="30" fill="#1E293B"/>
                <path d="M40 288 H200 L226 236 L258 344 L286 264 L304 288 H330" fill="none" stroke="#818CF8" stroke-width="22" stroke-linecap="round" stroke-linejoin="round"/>
                <circle cx="382" cy="376" r="78" fill="#4F46E5" stroke="#0F172A" stroke-width="14"/>
                <path d="M346 378 L372 404 L420 352" fill="none" stroke="#FFFFFF" stroke-width="22" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <div>
                <h1>Redis Advisor</h1>
                @if ($report)
                    @foreach ($report->redis['servers'] as $server)
                        <div class="server">
                            <b>{{ $server['name'] }}</b> · <b>{{ $server['address'] }}</b> · up {{ $server['uptime'] }} ·
                            @foreach ($server['connections'] as $c)
                                {{ $c['name'] }} (db{{ $c['database'] }}, {{ $c['client'] }}{{ $c['holds'] !== [] ? ': '.implode(', ', $c['holds']) : '' }}){{ $loop->last ? '' : ',' }}
                            @endforeach
                        </div>
                    @endforeach
                    <div class="server">{{ $report->generatedAt->format('Y-m-d H:i:s') }}</div>
                @endif
            </div>
        </div>
        <a class="refresh" href="{{ request()->fullUrl() }}">Run checks again</a>
    </header>

    @if ($error)
        <div class="banner error">
            <b>Could not inspect Redis.</b><br>
            {{ $error }}
        </div>
    @else
        @php
            $findings = $report->findings();
            $skipped = $report->skipped();
            $passed = $report->passed();
        @endphp

        <div class="summary">
            <div class="stat critical"><div class="n">{{ $report->count(\AmjadIqbal\RedisAdvisor\Severity::Critical) }}</div><div class="l">Critical</div></div>
            <div class="stat warning"><div class="n">{{ $report->count(\AmjadIqbal\RedisAdvisor\Severity::Warning) }}</div><div class="l">Warnings</div></div>
            <div class="stat notice"><div class="n">{{ $report->count(\AmjadIqbal\RedisAdvisor\Severity::Notice) }}</div><div class="l">Notices</div></div>
            <div class="stat ok"><div class="n">{{ count($passed) }}</div><div class="l">Checks passed</div></div>
            <div class="stat"><div class="n">{{ count($skipped) }}</div><div class="l">Skipped</div></div>
        </div>

        <nav aria-label="Category">
            <a href="{{ url()->current() }}" @class(['on' => $category === null])>All</a>
            @foreach ($categories as $c)
                <a href="{{ url()->current() }}?category={{ $c->value }}" @class(['on' => $category === $c->value])>{{ $c->label() }}</a>
            @endforeach
        </nav>

        @foreach ($report->redis['not_inspected'] as $name => $why)
            <div class="banner"><b>The '{{ $name }}' connection was not inspected</b>: {{ $why }}</div>
        @endforeach
        @foreach ($report->redis['unreachable'] as $name => $why)
            <div class="banner error"><b>The '{{ $name }}' connection did not answer</b>, so its checks did not run: {{ $why }}</div>
        @endforeach

        @forelse ($findings as $f)
            <article class="finding {{ $f->severity->value }}">
                <div class="meta">
                    <span class="badge {{ $f->severity->value }}">{{ $f->severity->label() }}</span>
                    <span>{{ $f->category->label() }}</span> · <code>{{ $f->check }}</code>
                </div>
                <h2>{{ $f->title }}</h2>
                <p>{{ $f->detail }}</p>

                @if ($f->fix)
                    <div class="fix">
                        <div class="label"><span>Fix · Redis (not run)</span><button type="button" class="copy">Copy</button></div>
                        <pre>{{ $f->fix }}</pre>
                    </div>
                @endif
                @if ($f->laravel)
                    <div class="fix">
                        <div class="label"><span>Fix · Laravel</span><button type="button" class="copy">Copy</button></div>
                        <pre>{{ $f->laravel }}</pre>
                    </div>
                @endif

                @if ($f->evidence !== [])
                    <details>
                        <summary>Evidence</summary>
                        <table class="ev">
                            @foreach ($f->evidence as $key => $value)
                                <tr><td>{{ str_replace('_', ' ', (string) $key) }}</td><td><code>{{ is_float($value) ? rtrim(rtrim(number_format($value, 2), '0'), '.') : $value }}</code></td></tr>
                            @endforeach
                        </table>
                    </details>
                @endif
            </article>
        @empty
            <div class="empty"><b>No problems found</b>{{ $category ? 'in '.$category.'.' : 'by the checks that could run.' }}</div>
        @endforelse

        @if ($skipped !== [])
            <h3>Skipped checks</h3>
            <div class="list">
                @foreach ($skipped as $r)
                    <div class="row">
                        <b>{{ $r->title }}</b> <span class="why">· {{ $r->skippedBecause }}</span>
                        @if ($r->howToEnable)
                            <pre>{{ $r->howToEnable }}</pre>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($passed !== [])
            <h3>Passed</h3>
            <div class="passed">
                @foreach ($passed as $r)
                    <span>{{ $r->title }}</span>
                @endforeach
            </div>
        @endif
    @endif

    <footer>
        <span>Read-only. Nothing on this page has been run against your Redis.</span>
        <span><a href="https://github.com/amjadiqbal/laravel-redis-advisor" rel="noopener">laravel-redis-advisor</a> {{ $version }}</span>
    </footer>
</div>
<script>
    document.querySelectorAll('.copy').forEach(function (button) {
        button.addEventListener('click', function () {
            var text = button.closest('.fix').querySelector('pre').innerText;
            var done = function (label) { button.textContent = label; setTimeout(function () { button.textContent = 'Copy'; }, 1500); };
            var fallback = function () {
                // The Clipboard API can be refused (permissions, iframes); select-and-copy still works there.
                var area = document.createElement('textarea');
                area.value = text; area.setAttribute('readonly', ''); area.style.position = 'fixed'; area.style.opacity = '0';
                document.body.appendChild(area); area.select();
                var ok = false;
                try { ok = document.execCommand('copy'); } catch (e) {} finally { area.remove(); }
                done(ok ? 'Copied' : 'Press Ctrl+C');
                if (! ok) { window.getSelection().selectAllChildren(button.closest('.fix').querySelector('pre')); }
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function () { done('Copied'); }, fallback);
            } else {
                fallback();
            }
        });
    });
</script>
</body>
</html>
