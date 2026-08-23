# Benchmarks

Kinetis wins five of six TechEmpower-style benchmark tests against eight
other PHP frameworks — Laravel, Laravel Octane, Symfony, CodeIgniter,
Yii2, CakePHP, and Slim on two runtimes — measured on isolated AWS
hardware on August 18, 2026. TechEmpower sunset its own benchmark
project in March 2026, so no neutral third party runs this kind of
comparison anymore, which is exactly why the full methodology and every
framework's implementation are public in the
[benchmark repository](https://github.com/aln-1/kinetis-benchmarks): the
numbers below are reproducible, not just reported. This page covers what
was tested and what came out, not how the rig is built.

## What was tested

Six [TechEmpower Framework Benchmarks](https://github.com/TechEmpower/FrameworkBenchmarks)
test types — `/json`, `/plaintext`, `/db`, `/fortunes`, `/queries`,
`/updates` — against identical MySQL schema and data, one implementation
per framework using that framework's own idiomatic routing, database
layer, and templating. Every target runs the same production PHP
configuration (opcache with a tracing JIT, timestamps disabled, access
logging off) and its own ahead-of-time caching mechanism where it has
one. Three separate EC2 instances — application, database, load
generator — provide real machine isolation, the same methodology
TechEmpower's own benchmark used; its test types remain the de facto
standard for comparing web frameworks.

Two pairs of targets separate the framework from the runtime it runs on.
`kinetis` and `kinetis-fpm` are the identical, unmodified application on
FrankenPHP worker mode and on classic nginx with PHP-FPM.
`slim-frankenphp` and `slim` are the same pairing for Slim.

Slim is a minimalistic "framework": the target is nothing more than a
request router and a PSR-7 implementation (`slim/slim` with
`slim/psr7`) — no ORM, no templating, no service container, no
validation. That is what makes the second pair a control rather than a
competitor: `slim-frankenphp` puts that bare router on the same
FrankenPHP worker runtime Kinetis runs on, which is the only way to tell
how much of a result belongs to the framework and how much to the
runtime underneath it. It is excluded from the leader board below and
given its own comparison further down.

What that comparison shows is worth saying up front: Kinetis carries a
full framework on top of that same runtime — attribute routing,
validation, dependency injection, a middleware pipeline, OpenAPI
generation — and where it trails the bare router it trails by
2.4% on average and 3.8% at worst. Once a request
issues more than a handful of queries, it is ahead instead.

The fairness rules — what was tuned, why, and what was deliberately left
alone — are documented in full in the repository's own README; they
aren't repeated here.

## Who leads each test

Winner among the eight competing frameworks at the heaviest level of
each test, with the margin over second place.

<div class="bench-cards">
<div class="bench-card"><p class="bench-card-test">/json · c=256</p><p class="bench-card-name bench-card-name--kinetis">kinetis</p><p class="bench-card-value">25,676<span>req/s</span></p><p class="bench-card-margin">+95% over slim</p></div><div class="bench-card"><p class="bench-card-test">/plaintext · c=256</p><p class="bench-card-name bench-card-name--kinetis">kinetis</p><p class="bench-card-value">25,888<span>req/s</span></p><p class="bench-card-margin">+98% over slim</p></div><div class="bench-card"><p class="bench-card-test">/db · c=256</p><p class="bench-card-name bench-card-name--kinetis">kinetis</p><p class="bench-card-value">18,732<span>req/s</span></p><p class="bench-card-margin">+147% over slim</p></div><div class="bench-card"><p class="bench-card-test">/fortunes · c=256</p><p class="bench-card-name bench-card-name--kinetis">kinetis</p><p class="bench-card-value">15,689<span>req/s</span></p><p class="bench-card-margin">+120% over slim</p></div><div class="bench-card"><p class="bench-card-test">/queries · n=20</p><p class="bench-card-name bench-card-name--kinetis">kinetis</p><p class="bench-card-value">4,538<span>req/s</span></p><p class="bench-card-margin">+5% over slim</p></div><div class="bench-card"><p class="bench-card-test">/updates · n=20</p><p class="bench-card-name">slim</p><p class="bench-card-value">1,860<span>req/s</span></p><p class="bench-card-margin">+17% over yii2</p></div>
</div>

The same application on classic PHP-FPM tells its own story. `kinetis-fpm`
leads every full framework in the field — including `yii2`, the
fastest of them — on `/json`, `/plaintext` and `/db`, by around
3%, and is level with the fastest on `/fortunes`. It gives
that back on `/queries` and `/updates`: under a boot-and-die runtime
Kinetis uses a blocking PDO driver, so a request's concurrent query
fan-out has nothing to overlap and pays for the scheduling anyway. Query
fan-out is where the persistent worker earns its keep — see
{doc}`runtime-adapters`.

## Every measurement

Requests per second; higher is better. Bar length is relative to the
fastest result anywhere in that test, so a row reads across columns.
The highlighted figure leads its column. The last column is p99 latency
at the heaviest level. Kinetis rows are tinted; the same-runtime control
is marked.

### `/json`

Serialize a single JSON object. No database.

<div class="bench-scroll">
<table class="bench-table">
<thead><tr><th class="corner">concurrency</th><th>16</th><th>32</th><th>64</th><th>128</th><th>256</th><th class="p99h">p99 ms</th></tr></thead>
<tbody><tr class="is-kinetis"><th scope="row">kinetis</th><td class="num"><span class="bench-bar" style="width:90.6%"></span><span class="bench-fig">23,848</span></td><td class="num"><span class="bench-bar" style="width:93.4%"></span><span class="bench-fig">24,585</span></td><td class="num"><span class="bench-bar" style="width:95.0%"></span><span class="bench-fig">25,005</span></td><td class="num"><span class="bench-bar" style="width:95.7%"></span><span class="bench-fig">25,181</span></td><td class="num"><span class="bench-bar" style="width:97.6%"></span><span class="bench-fig">25,676</span></td><td class="num p99">22.8</td></tr><tr class="is-control"><th scope="row">slim-frankenphp</th><td class="num is-best"><span class="bench-bar" style="width:92.7%"></span><span class="bench-fig">24,389</span></td><td class="num is-best"><span class="bench-bar" style="width:96.1%"></span><span class="bench-fig">25,280</span></td><td class="num is-best"><span class="bench-bar" style="width:97.0%"></span><span class="bench-fig">25,527</span></td><td class="num is-best"><span class="bench-bar" style="width:97.9%"></span><span class="bench-fig">25,766</span></td><td class="num is-best"><span class="bench-bar" style="width:100.0%"></span><span class="bench-fig">26,310</span></td><td class="num p99">21.1</td></tr><tr><th scope="row">slim</th><td class="num"><span class="bench-bar" style="width:42.7%"></span><span class="bench-fig">11,236</span></td><td class="num"><span class="bench-bar" style="width:45.8%"></span><span class="bench-fig">12,053</span></td><td class="num"><span class="bench-bar" style="width:47.7%"></span><span class="bench-fig">12,547</span></td><td class="num"><span class="bench-bar" style="width:48.7%"></span><span class="bench-fig">12,820</span></td><td class="num"><span class="bench-bar" style="width:49.9%"></span><span class="bench-fig">13,141</span></td><td class="num p99">43.0</td></tr><tr class="is-kinetis"><th scope="row">kinetis-fpm</th><td class="num"><span class="bench-bar" style="width:34.3%"></span><span class="bench-fig">9,025</span></td><td class="num"><span class="bench-bar" style="width:36.4%"></span><span class="bench-fig">9,579</span></td><td class="num"><span class="bench-bar" style="width:37.5%"></span><span class="bench-fig">9,857</span></td><td class="num"><span class="bench-bar" style="width:38.1%"></span><span class="bench-fig">10,034</span></td><td class="num"><span class="bench-bar" style="width:38.6%"></span><span class="bench-fig">10,149</span></td><td class="num p99">60.7</td></tr><tr><th scope="row">yii2</th><td class="num"><span class="bench-bar" style="width:33.2%"></span><span class="bench-fig">8,730</span></td><td class="num"><span class="bench-bar" style="width:35.2%"></span><span class="bench-fig">9,271</span></td><td class="num"><span class="bench-bar" style="width:35.7%"></span><span class="bench-fig">9,381</span></td><td class="num"><span class="bench-bar" style="width:37.0%"></span><span class="bench-fig">9,738</span></td><td class="num"><span class="bench-bar" style="width:37.3%"></span><span class="bench-fig">9,812</span></td><td class="num p99">61.7</td></tr><tr><th scope="row">symfony</th><td class="num"><span class="bench-bar" style="width:18.4%"></span><span class="bench-fig">4,840</span></td><td class="num"><span class="bench-bar" style="width:18.9%"></span><span class="bench-fig">4,985</span></td><td class="num"><span class="bench-bar" style="width:19.3%"></span><span class="bench-fig">5,074</span></td><td class="num"><span class="bench-bar" style="width:19.4%"></span><span class="bench-fig">5,091</span></td><td class="num"><span class="bench-bar" style="width:19.4%"></span><span class="bench-fig">5,095</span></td><td class="num p99">99.4</td></tr><tr><th scope="row">codeigniter</th><td class="num"><span class="bench-bar" style="width:21.8%"></span><span class="bench-fig">5,745</span></td><td class="num"><span class="bench-bar" style="width:23.0%"></span><span class="bench-fig">6,040</span></td><td class="num"><span class="bench-bar" style="width:23.3%"></span><span class="bench-fig">6,133</span></td><td class="num"><span class="bench-bar" style="width:23.6%"></span><span class="bench-fig">6,210</span></td><td class="num"><span class="bench-bar" style="width:23.5%"></span><span class="bench-fig">6,191</span></td><td class="num p99">97.9</td></tr><tr><th scope="row">cakephp</th><td class="num"><span class="bench-bar" style="width:21.1%"></span><span class="bench-fig">5,539</span></td><td class="num"><span class="bench-bar" style="width:22.0%"></span><span class="bench-fig">5,789</span></td><td class="num"><span class="bench-bar" style="width:22.4%"></span><span class="bench-fig">5,885</span></td><td class="num"><span class="bench-bar" style="width:22.4%"></span><span class="bench-fig">5,892</span></td><td class="num"><span class="bench-bar" style="width:22.3%"></span><span class="bench-fig">5,855</span></td><td class="num p99">91.4</td></tr><tr><th scope="row">laravel-octane</th><td class="num"><span class="bench-bar" style="width:21.0%"></span><span class="bench-fig">5,521</span></td><td class="num"><span class="bench-bar" style="width:21.0%"></span><span class="bench-fig">5,526</span></td><td class="num"><span class="bench-bar" style="width:20.7%"></span><span class="bench-fig">5,454</span></td><td class="num"><span class="bench-bar" style="width:20.0%"></span><span class="bench-fig">5,267</span></td><td class="num"><span class="bench-bar" style="width:19.1%"></span><span class="bench-fig">5,020</span></td><td class="num p99">138.4</td></tr><tr><th scope="row">laravel</th><td class="num"><span class="bench-bar" style="width:9.7%"></span><span class="bench-fig">2,543</span></td><td class="num"><span class="bench-bar" style="width:9.8%"></span><span class="bench-fig">2,588</span></td><td class="num"><span class="bench-bar" style="width:9.7%"></span><span class="bench-fig">2,542</span></td><td class="num"><span class="bench-bar" style="width:9.8%"></span><span class="bench-fig">2,567</span></td><td class="num"><span class="bench-bar" style="width:9.7%"></span><span class="bench-fig">2,553</span></td><td class="num p99">157.5</td></tr></tbody>
</table>
</div>

### `/plaintext`

Return a fixed string. The floor of framework overhead.

<div class="bench-scroll">
<table class="bench-table">
<thead><tr><th class="corner">concurrency</th><th>16</th><th>32</th><th>64</th><th>128</th><th>256</th><th class="p99h">p99 ms</th></tr></thead>
<tbody><tr class="is-kinetis"><th scope="row">kinetis</th><td class="num"><span class="bench-bar" style="width:90.4%"></span><span class="bench-fig">23,766</span></td><td class="num"><span class="bench-bar" style="width:93.6%"></span><span class="bench-fig">24,607</span></td><td class="num"><span class="bench-bar" style="width:93.7%"></span><span class="bench-fig">24,643</span></td><td class="num"><span class="bench-bar" style="width:94.4%"></span><span class="bench-fig">24,835</span></td><td class="num"><span class="bench-bar" style="width:98.4%"></span><span class="bench-fig">25,888</span></td><td class="num p99">22.1</td></tr><tr class="is-control"><th scope="row">slim-frankenphp</th><td class="num is-best"><span class="bench-bar" style="width:92.7%"></span><span class="bench-fig">24,378</span></td><td class="num is-best"><span class="bench-bar" style="width:96.1%"></span><span class="bench-fig">25,272</span></td><td class="num is-best"><span class="bench-bar" style="width:97.1%"></span><span class="bench-fig">25,538</span></td><td class="num is-best"><span class="bench-bar" style="width:98.2%"></span><span class="bench-fig">25,826</span></td><td class="num is-best"><span class="bench-bar" style="width:100.0%"></span><span class="bench-fig">26,303</span></td><td class="num p99">21.5</td></tr><tr><th scope="row">slim</th><td class="num"><span class="bench-bar" style="width:42.4%"></span><span class="bench-fig">11,146</span></td><td class="num"><span class="bench-bar" style="width:45.7%"></span><span class="bench-fig">12,009</span></td><td class="num"><span class="bench-bar" style="width:46.7%"></span><span class="bench-fig">12,288</span></td><td class="num"><span class="bench-bar" style="width:48.2%"></span><span class="bench-fig">12,679</span></td><td class="num"><span class="bench-bar" style="width:49.6%"></span><span class="bench-fig">13,049</span></td><td class="num p99">50.3</td></tr><tr class="is-kinetis"><th scope="row">kinetis-fpm</th><td class="num"><span class="bench-bar" style="width:34.5%"></span><span class="bench-fig">9,069</span></td><td class="num"><span class="bench-bar" style="width:36.7%"></span><span class="bench-fig">9,648</span></td><td class="num"><span class="bench-bar" style="width:37.8%"></span><span class="bench-fig">9,936</span></td><td class="num"><span class="bench-bar" style="width:39.0%"></span><span class="bench-fig">10,252</span></td><td class="num"><span class="bench-bar" style="width:39.0%"></span><span class="bench-fig">10,246</span></td><td class="num p99">56.3</td></tr><tr><th scope="row">yii2</th><td class="num"><span class="bench-bar" style="width:33.6%"></span><span class="bench-fig">8,836</span></td><td class="num"><span class="bench-bar" style="width:35.9%"></span><span class="bench-fig">9,436</span></td><td class="num"><span class="bench-bar" style="width:37.2%"></span><span class="bench-fig">9,777</span></td><td class="num"><span class="bench-bar" style="width:38.0%"></span><span class="bench-fig">9,985</span></td><td class="num"><span class="bench-bar" style="width:37.9%"></span><span class="bench-fig">9,968</span></td><td class="num p99">63.1</td></tr><tr><th scope="row">symfony</th><td class="num"><span class="bench-bar" style="width:18.4%"></span><span class="bench-fig">4,835</span></td><td class="num"><span class="bench-bar" style="width:19.1%"></span><span class="bench-fig">5,021</span></td><td class="num"><span class="bench-bar" style="width:19.3%"></span><span class="bench-fig">5,069</span></td><td class="num"><span class="bench-bar" style="width:19.4%"></span><span class="bench-fig">5,108</span></td><td class="num"><span class="bench-bar" style="width:19.5%"></span><span class="bench-fig">5,123</span></td><td class="num p99">102.9</td></tr><tr><th scope="row">codeigniter</th><td class="num"><span class="bench-bar" style="width:21.4%"></span><span class="bench-fig">5,642</span></td><td class="num"><span class="bench-bar" style="width:22.9%"></span><span class="bench-fig">6,026</span></td><td class="num"><span class="bench-bar" style="width:23.3%"></span><span class="bench-fig">6,126</span></td><td class="num"><span class="bench-bar" style="width:23.3%"></span><span class="bench-fig">6,137</span></td><td class="num"><span class="bench-bar" style="width:23.3%"></span><span class="bench-fig">6,140</span></td><td class="num p99">95.5</td></tr><tr><th scope="row">cakephp</th><td class="num"><span class="bench-bar" style="width:20.9%"></span><span class="bench-fig">5,485</span></td><td class="num"><span class="bench-bar" style="width:21.9%"></span><span class="bench-fig">5,762</span></td><td class="num"><span class="bench-bar" style="width:22.4%"></span><span class="bench-fig">5,894</span></td><td class="num"><span class="bench-bar" style="width:22.7%"></span><span class="bench-fig">5,964</span></td><td class="num"><span class="bench-bar" style="width:22.5%"></span><span class="bench-fig">5,914</span></td><td class="num p99">89.8</td></tr><tr><th scope="row">laravel-octane</th><td class="num"><span class="bench-bar" style="width:21.1%"></span><span class="bench-fig">5,542</span></td><td class="num"><span class="bench-bar" style="width:21.1%"></span><span class="bench-fig">5,549</span></td><td class="num"><span class="bench-bar" style="width:20.8%"></span><span class="bench-fig">5,477</span></td><td class="num"><span class="bench-bar" style="width:20.3%"></span><span class="bench-fig">5,337</span></td><td class="num"><span class="bench-bar" style="width:19.5%"></span><span class="bench-fig">5,119</span></td><td class="num p99">129.2</td></tr><tr><th scope="row">laravel</th><td class="num"><span class="bench-bar" style="width:9.7%"></span><span class="bench-fig">2,559</span></td><td class="num"><span class="bench-bar" style="width:9.8%"></span><span class="bench-fig">2,578</span></td><td class="num"><span class="bench-bar" style="width:9.6%"></span><span class="bench-fig">2,514</span></td><td class="num"><span class="bench-bar" style="width:9.8%"></span><span class="bench-fig">2,572</span></td><td class="num"><span class="bench-bar" style="width:9.7%"></span><span class="bench-fig">2,550</span></td><td class="num p99">157.4</td></tr></tbody>
</table>
</div>

### `/db`

One random row by primary key, per request.

<div class="bench-scroll">
<table class="bench-table">
<thead><tr><th class="corner">concurrency</th><th>16</th><th>32</th><th>64</th><th>128</th><th>256</th><th class="p99h">p99 ms</th></tr></thead>
<tbody><tr class="is-kinetis"><th scope="row">kinetis</th><td class="num is-best"><span class="bench-bar" style="width:85.8%"></span><span class="bench-fig">16,075</span></td><td class="num is-best"><span class="bench-bar" style="width:88.9%"></span><span class="bench-fig">16,656</span></td><td class="num is-best"><span class="bench-bar" style="width:90.4%"></span><span class="bench-fig">16,942</span></td><td class="num is-best"><span class="bench-bar" style="width:95.0%"></span><span class="bench-fig">17,801</span></td><td class="num is-best"><span class="bench-bar" style="width:100.0%"></span><span class="bench-fig">18,732</span></td><td class="num p99">29.1</td></tr><tr class="is-control"><th scope="row">slim-frankenphp</th><td class="num"><span class="bench-bar" style="width:82.3%"></span><span class="bench-fig">15,410</span></td><td class="num"><span class="bench-bar" style="width:86.3%"></span><span class="bench-fig">16,170</span></td><td class="num"><span class="bench-bar" style="width:89.5%"></span><span class="bench-fig">16,768</span></td><td class="num"><span class="bench-bar" style="width:93.0%"></span><span class="bench-fig">17,415</span></td><td class="num"><span class="bench-bar" style="width:96.8%"></span><span class="bench-fig">18,140</span></td><td class="num p99">29.1</td></tr><tr><th scope="row">slim</th><td class="num"><span class="bench-bar" style="width:34.3%"></span><span class="bench-fig">6,426</span></td><td class="num"><span class="bench-bar" style="width:38.1%"></span><span class="bench-fig">7,129</span></td><td class="num"><span class="bench-bar" style="width:39.6%"></span><span class="bench-fig">7,424</span></td><td class="num"><span class="bench-bar" style="width:40.4%"></span><span class="bench-fig">7,577</span></td><td class="num"><span class="bench-bar" style="width:40.4%"></span><span class="bench-fig">7,573</span></td><td class="num p99">70.8</td></tr><tr class="is-kinetis"><th scope="row">kinetis-fpm</th><td class="num"><span class="bench-bar" style="width:28.3%"></span><span class="bench-fig">5,304</span></td><td class="num"><span class="bench-bar" style="width:30.8%"></span><span class="bench-fig">5,765</span></td><td class="num"><span class="bench-bar" style="width:31.3%"></span><span class="bench-fig">5,870</span></td><td class="num"><span class="bench-bar" style="width:32.1%"></span><span class="bench-fig">6,008</span></td><td class="num"><span class="bench-bar" style="width:32.0%"></span><span class="bench-fig">6,003</span></td><td class="num p99">96.5</td></tr><tr><th scope="row">yii2</th><td class="num"><span class="bench-bar" style="width:27.3%"></span><span class="bench-fig">5,114</span></td><td class="num"><span class="bench-bar" style="width:30.1%"></span><span class="bench-fig">5,630</span></td><td class="num"><span class="bench-bar" style="width:31.5%"></span><span class="bench-fig">5,896</span></td><td class="num"><span class="bench-bar" style="width:32.1%"></span><span class="bench-fig">6,007</span></td><td class="num"><span class="bench-bar" style="width:31.2%"></span><span class="bench-fig">5,853</span></td><td class="num p99">89.7</td></tr><tr><th scope="row">symfony</th><td class="num"><span class="bench-bar" style="width:19.8%"></span><span class="bench-fig">3,715</span></td><td class="num"><span class="bench-bar" style="width:20.6%"></span><span class="bench-fig">3,861</span></td><td class="num"><span class="bench-bar" style="width:20.8%"></span><span class="bench-fig">3,892</span></td><td class="num"><span class="bench-bar" style="width:20.8%"></span><span class="bench-fig">3,889</span></td><td class="num"><span class="bench-bar" style="width:21.0%"></span><span class="bench-fig">3,943</span></td><td class="num p99">111.3</td></tr><tr><th scope="row">codeigniter</th><td class="num"><span class="bench-bar" style="width:11.6%"></span><span class="bench-fig">2,165</span></td><td class="num"><span class="bench-bar" style="width:12.0%"></span><span class="bench-fig">2,243</span></td><td class="num"><span class="bench-bar" style="width:11.9%"></span><span class="bench-fig">2,229</span></td><td class="num"><span class="bench-bar" style="width:11.4%"></span><span class="bench-fig">2,133</span></td><td class="num"><span class="bench-bar" style="width:11.6%"></span><span class="bench-fig">2,181</span></td><td class="num p99">172.6</td></tr><tr><th scope="row">cakephp</th><td class="num"><span class="bench-bar" style="width:15.7%"></span><span class="bench-fig">2,946</span></td><td class="num"><span class="bench-bar" style="width:16.5%"></span><span class="bench-fig">3,098</span></td><td class="num"><span class="bench-bar" style="width:16.5%"></span><span class="bench-fig">3,091</span></td><td class="num"><span class="bench-bar" style="width:16.7%"></span><span class="bench-fig">3,119</span></td><td class="num"><span class="bench-bar" style="width:16.5%"></span><span class="bench-fig">3,093</span></td><td class="num p99">125.3</td></tr><tr><th scope="row">laravel-octane</th><td class="num"><span class="bench-bar" style="width:24.8%"></span><span class="bench-fig">4,653</span></td><td class="num"><span class="bench-bar" style="width:25.5%"></span><span class="bench-fig">4,780</span></td><td class="num"><span class="bench-bar" style="width:25.4%"></span><span class="bench-fig">4,758</span></td><td class="num"><span class="bench-bar" style="width:25.0%"></span><span class="bench-fig">4,679</span></td><td class="num"><span class="bench-bar" style="width:24.1%"></span><span class="bench-fig">4,523</span></td><td class="num p99">151.9</td></tr><tr><th scope="row">laravel</th><td class="num"><span class="bench-bar" style="width:10.6%"></span><span class="bench-fig">1,978</span></td><td class="num"><span class="bench-bar" style="width:10.9%"></span><span class="bench-fig">2,042</span></td><td class="num"><span class="bench-bar" style="width:10.8%"></span><span class="bench-fig">2,015</span></td><td class="num"><span class="bench-bar" style="width:10.8%"></span><span class="bench-fig">2,026</span></td><td class="num"><span class="bench-bar" style="width:10.8%"></span><span class="bench-fig">2,025</span></td><td class="num p99">184.2</td></tr></tbody>
</table>
</div>

### `/fortunes`

Fetch a table, add a row, sort, render HTML.

<div class="bench-scroll">
<table class="bench-table">
<thead><tr><th class="corner">concurrency</th><th>16</th><th>32</th><th>64</th><th>128</th><th>256</th><th class="p99h">p99 ms</th></tr></thead>
<tbody><tr class="is-kinetis"><th scope="row">kinetis</th><td class="num"><span class="bench-bar" style="width:85.0%"></span><span class="bench-fig">13,631</span></td><td class="num"><span class="bench-bar" style="width:87.8%"></span><span class="bench-fig">14,090</span></td><td class="num"><span class="bench-bar" style="width:90.9%"></span><span class="bench-fig">14,577</span></td><td class="num"><span class="bench-bar" style="width:94.5%"></span><span class="bench-fig">15,158</span></td><td class="num"><span class="bench-bar" style="width:97.8%"></span><span class="bench-fig">15,689</span></td><td class="num p99">33.3</td></tr><tr class="is-control"><th scope="row">slim-frankenphp</th><td class="num is-best"><span class="bench-bar" style="width:86.2%"></span><span class="bench-fig">13,820</span></td><td class="num is-best"><span class="bench-bar" style="width:90.6%"></span><span class="bench-fig">14,530</span></td><td class="num is-best"><span class="bench-bar" style="width:93.8%"></span><span class="bench-fig">15,040</span></td><td class="num is-best"><span class="bench-bar" style="width:97.6%"></span><span class="bench-fig">15,658</span></td><td class="num is-best"><span class="bench-bar" style="width:100.0%"></span><span class="bench-fig">16,040</span></td><td class="num p99">29.2</td></tr><tr><th scope="row">slim</th><td class="num"><span class="bench-bar" style="width:37.9%"></span><span class="bench-fig">6,072</span></td><td class="num"><span class="bench-bar" style="width:42.1%"></span><span class="bench-fig">6,749</span></td><td class="num"><span class="bench-bar" style="width:43.4%"></span><span class="bench-fig">6,955</span></td><td class="num"><span class="bench-bar" style="width:44.4%"></span><span class="bench-fig">7,117</span></td><td class="num"><span class="bench-bar" style="width:44.5%"></span><span class="bench-fig">7,134</span></td><td class="num p99">75.5</td></tr><tr class="is-kinetis"><th scope="row">kinetis-fpm</th><td class="num"><span class="bench-bar" style="width:31.0%"></span><span class="bench-fig">4,971</span></td><td class="num"><span class="bench-bar" style="width:33.7%"></span><span class="bench-fig">5,405</span></td><td class="num"><span class="bench-bar" style="width:34.3%"></span><span class="bench-fig">5,498</span></td><td class="num"><span class="bench-bar" style="width:34.6%"></span><span class="bench-fig">5,543</span></td><td class="num"><span class="bench-bar" style="width:34.3%"></span><span class="bench-fig">5,501</span></td><td class="num p99">105.4</td></tr><tr><th scope="row">yii2</th><td class="num"><span class="bench-bar" style="width:30.7%"></span><span class="bench-fig">4,925</span></td><td class="num"><span class="bench-bar" style="width:33.3%"></span><span class="bench-fig">5,341</span></td><td class="num"><span class="bench-bar" style="width:33.6%"></span><span class="bench-fig">5,384</span></td><td class="num"><span class="bench-bar" style="width:34.4%"></span><span class="bench-fig">5,516</span></td><td class="num"><span class="bench-bar" style="width:34.7%"></span><span class="bench-fig">5,558</span></td><td class="num p99">91.1</td></tr><tr><th scope="row">symfony</th><td class="num"><span class="bench-bar" style="width:21.8%"></span><span class="bench-fig">3,494</span></td><td class="num"><span class="bench-bar" style="width:22.4%"></span><span class="bench-fig">3,589</span></td><td class="num"><span class="bench-bar" style="width:23.0%"></span><span class="bench-fig">3,696</span></td><td class="num"><span class="bench-bar" style="width:22.7%"></span><span class="bench-fig">3,643</span></td><td class="num"><span class="bench-bar" style="width:22.6%"></span><span class="bench-fig">3,621</span></td><td class="num p99">120.6</td></tr><tr><th scope="row">codeigniter</th><td class="num"><span class="bench-bar" style="width:12.7%"></span><span class="bench-fig">2,037</span></td><td class="num"><span class="bench-bar" style="width:12.8%"></span><span class="bench-fig">2,050</span></td><td class="num"><span class="bench-bar" style="width:12.8%"></span><span class="bench-fig">2,059</span></td><td class="num"><span class="bench-bar" style="width:12.7%"></span><span class="bench-fig">2,030</span></td><td class="num"><span class="bench-bar" style="width:12.6%"></span><span class="bench-fig">2,024</span></td><td class="num p99">186.9</td></tr><tr><th scope="row">cakephp</th><td class="num"><span class="bench-bar" style="width:17.5%"></span><span class="bench-fig">2,801</span></td><td class="num"><span class="bench-bar" style="width:18.2%"></span><span class="bench-fig">2,912</span></td><td class="num"><span class="bench-bar" style="width:18.0%"></span><span class="bench-fig">2,892</span></td><td class="num"><span class="bench-bar" style="width:18.2%"></span><span class="bench-fig">2,916</span></td><td class="num"><span class="bench-bar" style="width:17.8%"></span><span class="bench-fig">2,862</span></td><td class="num p99">133.7</td></tr><tr><th scope="row">laravel-octane</th><td class="num"><span class="bench-bar" style="width:26.9%"></span><span class="bench-fig">4,308</span></td><td class="num"><span class="bench-bar" style="width:27.6%"></span><span class="bench-fig">4,420</span></td><td class="num"><span class="bench-bar" style="width:27.4%"></span><span class="bench-fig">4,392</span></td><td class="num"><span class="bench-bar" style="width:26.9%"></span><span class="bench-fig">4,317</span></td><td class="num"><span class="bench-bar" style="width:26.0%"></span><span class="bench-fig">4,177</span></td><td class="num p99">162.7</td></tr><tr><th scope="row">laravel</th><td class="num"><span class="bench-bar" style="width:11.7%"></span><span class="bench-fig">1,874</span></td><td class="num"><span class="bench-bar" style="width:12.0%"></span><span class="bench-fig">1,917</span></td><td class="num"><span class="bench-bar" style="width:12.0%"></span><span class="bench-fig">1,924</span></td><td class="num"><span class="bench-bar" style="width:11.9%"></span><span class="bench-fig">1,905</span></td><td class="num"><span class="bench-bar" style="width:11.7%"></span><span class="bench-fig">1,870</span></td><td class="num p99">173.9</td></tr></tbody>
</table>
</div>

### `/queries`

N random rows per request, at concurrency 256.

<div class="bench-scroll">
<table class="bench-table">
<thead><tr><th class="corner">queries</th><th>1</th><th>5</th><th>10</th><th>15</th><th>20</th><th class="p99h">p99 ms</th></tr></thead>
<tbody><tr class="is-kinetis"><th scope="row">kinetis</th><td class="num"><span class="bench-bar" style="width:98.6%"></span><span class="bench-fig">17,555</span></td><td class="num is-best"><span class="bench-bar" style="width:65.3%"></span><span class="bench-fig">11,624</span></td><td class="num is-best"><span class="bench-bar" style="width:45.0%"></span><span class="bench-fig">8,008</span></td><td class="num is-best"><span class="bench-bar" style="width:32.5%"></span><span class="bench-fig">5,788</span></td><td class="num is-best"><span class="bench-bar" style="width:25.5%"></span><span class="bench-fig">4,538</span></td><td class="num p99">62.3</td></tr><tr class="is-control"><th scope="row">slim-frankenphp</th><td class="num is-best"><span class="bench-bar" style="width:100.0%"></span><span class="bench-fig">17,812</span></td><td class="num"><span class="bench-bar" style="width:63.5%"></span><span class="bench-fig">11,308</span></td><td class="num"><span class="bench-bar" style="width:39.4%"></span><span class="bench-fig">7,011</span></td><td class="num"><span class="bench-bar" style="width:28.2%"></span><span class="bench-fig">5,022</span></td><td class="num"><span class="bench-bar" style="width:21.9%"></span><span class="bench-fig">3,906</span></td><td class="num p99">67.7</td></tr><tr><th scope="row">slim</th><td class="num"><span class="bench-bar" style="width:42.1%"></span><span class="bench-fig">7,502</span></td><td class="num"><span class="bench-bar" style="width:35.8%"></span><span class="bench-fig">6,385</span></td><td class="num"><span class="bench-bar" style="width:30.8%"></span><span class="bench-fig">5,483</span></td><td class="num"><span class="bench-bar" style="width:26.9%"></span><span class="bench-fig">4,797</span></td><td class="num"><span class="bench-bar" style="width:24.4%"></span><span class="bench-fig">4,339</span></td><td class="num p99">94.8</td></tr><tr class="is-kinetis"><th scope="row">kinetis-fpm</th><td class="num"><span class="bench-bar" style="width:30.3%"></span><span class="bench-fig">5,391</span></td><td class="num"><span class="bench-bar" style="width:21.8%"></span><span class="bench-fig">3,884</span></td><td class="num"><span class="bench-bar" style="width:16.5%"></span><span class="bench-fig">2,932</span></td><td class="num"><span class="bench-bar" style="width:13.6%"></span><span class="bench-fig">2,415</span></td><td class="num"><span class="bench-bar" style="width:11.5%"></span><span class="bench-fig">2,042</span></td><td class="num p99">146.8</td></tr><tr><th scope="row">yii2</th><td class="num"><span class="bench-bar" style="width:32.5%"></span><span class="bench-fig">5,789</span></td><td class="num"><span class="bench-bar" style="width:26.4%"></span><span class="bench-fig">4,701</span></td><td class="num"><span class="bench-bar" style="width:21.7%"></span><span class="bench-fig">3,865</span></td><td class="num"><span class="bench-bar" style="width:18.3%"></span><span class="bench-fig">3,263</span></td><td class="num"><span class="bench-bar" style="width:16.1%"></span><span class="bench-fig">2,875</span></td><td class="num p99">129.2</td></tr><tr><th scope="row">symfony</th><td class="num"><span class="bench-bar" style="width:21.5%"></span><span class="bench-fig">3,823</span></td><td class="num"><span class="bench-bar" style="width:19.1%"></span><span class="bench-fig">3,406</span></td><td class="num"><span class="bench-bar" style="width:17.4%"></span><span class="bench-fig">3,101</span></td><td class="num"><span class="bench-bar" style="width:16.0%"></span><span class="bench-fig">2,841</span></td><td class="num"><span class="bench-bar" style="width:14.6%"></span><span class="bench-fig">2,602</span></td><td class="num p99">127.0</td></tr><tr><th scope="row">codeigniter</th><td class="num"><span class="bench-bar" style="width:12.0%"></span><span class="bench-fig">2,144</span></td><td class="num"><span class="bench-bar" style="width:11.3%"></span><span class="bench-fig">2,017</span></td><td class="num"><span class="bench-bar" style="width:10.3%"></span><span class="bench-fig">1,827</span></td><td class="num"><span class="bench-bar" style="width:9.6%"></span><span class="bench-fig">1,707</span></td><td class="num"><span class="bench-bar" style="width:8.9%"></span><span class="bench-fig">1,586</span></td><td class="num p99">246.4</td></tr><tr><th scope="row">cakephp</th><td class="num"><span class="bench-bar" style="width:16.8%"></span><span class="bench-fig">2,987</span></td><td class="num"><span class="bench-bar" style="width:12.8%"></span><span class="bench-fig">2,282</span></td><td class="num"><span class="bench-bar" style="width:10.8%"></span><span class="bench-fig">1,928</span></td><td class="num"><span class="bench-bar" style="width:9.0%"></span><span class="bench-fig">1,607</span></td><td class="num"><span class="bench-bar" style="width:7.9%"></span><span class="bench-fig">1,409</span></td><td class="num p99">209.1</td></tr><tr><th scope="row">laravel-octane</th><td class="num"><span class="bench-bar" style="width:24.8%"></span><span class="bench-fig">4,420</span></td><td class="num"><span class="bench-bar" style="width:17.3%"></span><span class="bench-fig">3,075</span></td><td class="num"><span class="bench-bar" style="width:12.6%"></span><span class="bench-fig">2,242</span></td><td class="num"><span class="bench-bar" style="width:9.9%"></span><span class="bench-fig">1,765</span></td><td class="num"><span class="bench-bar" style="width:8.0%"></span><span class="bench-fig">1,432</span></td><td class="num p99">352.8</td></tr><tr><th scope="row">laravel</th><td class="num"><span class="bench-bar" style="width:11.1%"></span><span class="bench-fig">1,984</span></td><td class="num"><span class="bench-bar" style="width:9.2%"></span><span class="bench-fig">1,631</span></td><td class="num"><span class="bench-bar" style="width:7.7%"></span><span class="bench-fig">1,373</span></td><td class="num"><span class="bench-bar" style="width:6.6%"></span><span class="bench-fig">1,174</span></td><td class="num"><span class="bench-bar" style="width:5.8%"></span><span class="bench-fig">1,041</span></td><td class="num p99">283.2</td></tr></tbody>
</table>
</div>

### `/updates`

N rows read and written per request, at concurrency 256.

<div class="bench-scroll">
<table class="bench-table">
<thead><tr><th class="corner">queries</th><th>1</th><th>5</th><th>10</th><th>15</th><th>20</th><th class="p99h">p99 ms</th></tr></thead>
<tbody><tr class="is-kinetis"><th scope="row">kinetis</th><td class="num is-best"><span class="bench-bar" style="width:100.0%"></span><span class="bench-fig">14,151</span></td><td class="num is-best"><span class="bench-bar" style="width:43.1%"></span><span class="bench-fig">6,098</span></td><td class="num"><span class="bench-bar" style="width:21.2%"></span><span class="bench-fig">3,000</span></td><td class="num"><span class="bench-bar" style="width:13.9%"></span><span class="bench-fig">1,973</span></td><td class="num"><span class="bench-bar" style="width:10.6%"></span><span class="bench-fig">1,504</span></td><td class="num p99">347.9</td></tr><tr class="is-control"><th scope="row">slim-frankenphp</th><td class="num"><span class="bench-bar" style="width:97.0%"></span><span class="bench-fig">13,727</span></td><td class="num"><span class="bench-bar" style="width:38.2%"></span><span class="bench-fig">5,399</span></td><td class="num"><span class="bench-bar" style="width:20.4%"></span><span class="bench-fig">2,882</span></td><td class="num"><span class="bench-bar" style="width:14.2%"></span><span class="bench-fig">2,008</span></td><td class="num"><span class="bench-bar" style="width:10.8%"></span><span class="bench-fig">1,533</span></td><td class="num p99">245.6</td></tr><tr><th scope="row">slim</th><td class="num"><span class="bench-bar" style="width:48.5%"></span><span class="bench-fig">6,864</span></td><td class="num"><span class="bench-bar" style="width:34.6%"></span><span class="bench-fig">4,903</span></td><td class="num is-best"><span class="bench-bar" style="width:22.4%"></span><span class="bench-fig">3,175</span></td><td class="num is-best"><span class="bench-bar" style="width:16.7%"></span><span class="bench-fig">2,364</span></td><td class="num is-best"><span class="bench-bar" style="width:13.1%"></span><span class="bench-fig">1,860</span></td><td class="num p99">200.2</td></tr><tr class="is-kinetis"><th scope="row">kinetis-fpm</th><td class="num"><span class="bench-bar" style="width:34.1%"></span><span class="bench-fig">4,820</span></td><td class="num"><span class="bench-bar" style="width:19.7%"></span><span class="bench-fig">2,789</span></td><td class="num"><span class="bench-bar" style="width:13.5%"></span><span class="bench-fig">1,906</span></td><td class="num"><span class="bench-bar" style="width:10.1%"></span><span class="bench-fig">1,423</span></td><td class="num"><span class="bench-bar" style="width:8.0%"></span><span class="bench-fig">1,130</span></td><td class="num p99">426.8</td></tr><tr><th scope="row">yii2</th><td class="num"><span class="bench-bar" style="width:38.5%"></span><span class="bench-fig">5,445</span></td><td class="num"><span class="bench-bar" style="width:26.3%"></span><span class="bench-fig">3,728</span></td><td class="num"><span class="bench-bar" style="width:18.8%"></span><span class="bench-fig">2,662</span></td><td class="num"><span class="bench-bar" style="width:14.5%"></span><span class="bench-fig">2,047</span></td><td class="num"><span class="bench-bar" style="width:11.2%"></span><span class="bench-fig">1,588</span></td><td class="num p99">245.9</td></tr><tr><th scope="row">symfony</th><td class="num"><span class="bench-bar" style="width:26.5%"></span><span class="bench-fig">3,752</span></td><td class="num"><span class="bench-bar" style="width:19.6%"></span><span class="bench-fig">2,773</span></td><td class="num"><span class="bench-bar" style="width:17.5%"></span><span class="bench-fig">2,474</span></td><td class="num"><span class="bench-bar" style="width:12.9%"></span><span class="bench-fig">1,826</span></td><td class="num"><span class="bench-bar" style="width:10.6%"></span><span class="bench-fig">1,500</span></td><td class="num p99">368.2</td></tr><tr><th scope="row">codeigniter</th><td class="num"><span class="bench-bar" style="width:15.0%"></span><span class="bench-fig">2,123</span></td><td class="num"><span class="bench-bar" style="width:13.2%"></span><span class="bench-fig">1,863</span></td><td class="num"><span class="bench-bar" style="width:11.1%"></span><span class="bench-fig">1,565</span></td><td class="num"><span class="bench-bar" style="width:9.4%"></span><span class="bench-fig">1,325</span></td><td class="num"><span class="bench-bar" style="width:8.5%"></span><span class="bench-fig">1,200</span></td><td class="num p99">376.3</td></tr><tr><th scope="row">cakephp</th><td class="num"><span class="bench-bar" style="width:20.8%"></span><span class="bench-fig">2,937</span></td><td class="num"><span class="bench-bar" style="width:14.1%"></span><span class="bench-fig">1,997</span></td><td class="num"><span class="bench-bar" style="width:10.3%"></span><span class="bench-fig">1,452</span></td><td class="num"><span class="bench-bar" style="width:8.2%"></span><span class="bench-fig">1,161</span></td><td class="num"><span class="bench-bar" style="width:7.1%"></span><span class="bench-fig">1,002</span></td><td class="num p99">370.7</td></tr><tr><th scope="row">laravel-octane</th><td class="num"><span class="bench-bar" style="width:27.9%"></span><span class="bench-fig">3,948</span></td><td class="num"><span class="bench-bar" style="width:16.1%"></span><span class="bench-fig">2,281</span></td><td class="num"><span class="bench-bar" style="width:10.1%"></span><span class="bench-fig">1,431</span></td><td class="num"><span class="bench-bar" style="width:7.6%"></span><span class="bench-fig">1,079</span></td><td class="num"><span class="bench-bar" style="width:5.9%"></span><span class="bench-fig">841</span></td><td class="num p99">435.2</td></tr><tr><th scope="row">laravel</th><td class="num"><span class="bench-bar" style="width:13.3%"></span><span class="bench-fig">1,884</span></td><td class="num"><span class="bench-bar" style="width:9.6%"></span><span class="bench-fig">1,355</span></td><td class="num"><span class="bench-bar" style="width:7.4%"></span><span class="bench-fig">1,043</span></td><td class="num"><span class="bench-bar" style="width:5.9%"></span><span class="bench-fig">830</span></td><td class="num"><span class="bench-bar" style="width:5.1%"></span><span class="bench-fig">716</span></td><td class="num p99">799.0</td></tr></tbody>
</table>
</div>


## Same runtime, different framework

`kinetis` and `slim-frankenphp` both run FrankenPHP in worker mode with
20 workers, so this pair isolates the framework itself from the runtime
underneath it. Ratios above 1.05&times; or below 0.95&times; are
highlighted; anything between sits inside this rig's run-to-run noise.

<div class="bench-scroll">
<table class="bench-table">
<thead><tr><th class="corner">test</th><th>level</th><th>kinetis</th>
<th>slim-frankenphp</th><th>ratio</th></tr></thead>
<tbody><tr><th scope="row">/json</th><td class="num">16</td><td class="num">23,848</td><td class="num">24,389</td><td class="num ratio-flat">0.98&times;</td></tr><tr><th scope="row">/json</th><td class="num">32</td><td class="num">24,585</td><td class="num">25,280</td><td class="num ratio-flat">0.97&times;</td></tr><tr><th scope="row">/json</th><td class="num">64</td><td class="num">25,005</td><td class="num">25,527</td><td class="num ratio-flat">0.98&times;</td></tr><tr><th scope="row">/json</th><td class="num">128</td><td class="num">25,181</td><td class="num">25,766</td><td class="num ratio-flat">0.98&times;</td></tr><tr><th scope="row">/json</th><td class="num">256</td><td class="num">25,676</td><td class="num">26,310</td><td class="num ratio-flat">0.98&times;</td></tr><tr><th scope="row">/plaintext</th><td class="num">16</td><td class="num">23,766</td><td class="num">24,378</td><td class="num ratio-flat">0.97&times;</td></tr><tr><th scope="row">/plaintext</th><td class="num">32</td><td class="num">24,607</td><td class="num">25,272</td><td class="num ratio-flat">0.97&times;</td></tr><tr><th scope="row">/plaintext</th><td class="num">64</td><td class="num">24,643</td><td class="num">25,538</td><td class="num ratio-flat">0.96&times;</td></tr><tr><th scope="row">/plaintext</th><td class="num">128</td><td class="num">24,835</td><td class="num">25,826</td><td class="num ratio-flat">0.96&times;</td></tr><tr><th scope="row">/plaintext</th><td class="num">256</td><td class="num">25,888</td><td class="num">26,303</td><td class="num ratio-flat">0.98&times;</td></tr><tr><th scope="row">/db</th><td class="num">16</td><td class="num">16,075</td><td class="num">15,410</td><td class="num ratio-flat">1.04&times;</td></tr><tr><th scope="row">/db</th><td class="num">32</td><td class="num">16,656</td><td class="num">16,170</td><td class="num ratio-flat">1.03&times;</td></tr><tr><th scope="row">/db</th><td class="num">64</td><td class="num">16,942</td><td class="num">16,768</td><td class="num ratio-flat">1.01&times;</td></tr><tr><th scope="row">/db</th><td class="num">128</td><td class="num">17,801</td><td class="num">17,415</td><td class="num ratio-flat">1.02&times;</td></tr><tr><th scope="row">/db</th><td class="num">256</td><td class="num">18,732</td><td class="num">18,140</td><td class="num ratio-flat">1.03&times;</td></tr><tr><th scope="row">/fortunes</th><td class="num">16</td><td class="num">13,631</td><td class="num">13,820</td><td class="num ratio-flat">0.99&times;</td></tr><tr><th scope="row">/fortunes</th><td class="num">32</td><td class="num">14,090</td><td class="num">14,530</td><td class="num ratio-flat">0.97&times;</td></tr><tr><th scope="row">/fortunes</th><td class="num">64</td><td class="num">14,577</td><td class="num">15,040</td><td class="num ratio-flat">0.97&times;</td></tr><tr><th scope="row">/fortunes</th><td class="num">128</td><td class="num">15,158</td><td class="num">15,658</td><td class="num ratio-flat">0.97&times;</td></tr><tr><th scope="row">/fortunes</th><td class="num">256</td><td class="num">15,689</td><td class="num">16,040</td><td class="num ratio-flat">0.98&times;</td></tr><tr><th scope="row">/queries</th><td class="num">1</td><td class="num">17,555</td><td class="num">17,812</td><td class="num ratio-flat">0.99&times;</td></tr><tr><th scope="row">/queries</th><td class="num">5</td><td class="num">11,624</td><td class="num">11,308</td><td class="num ratio-flat">1.03&times;</td></tr><tr><th scope="row">/queries</th><td class="num">10</td><td class="num">8,008</td><td class="num">7,011</td><td class="num ratio-up">1.14&times;</td></tr><tr><th scope="row">/queries</th><td class="num">15</td><td class="num">5,788</td><td class="num">5,022</td><td class="num ratio-up">1.15&times;</td></tr><tr><th scope="row">/queries</th><td class="num">20</td><td class="num">4,538</td><td class="num">3,906</td><td class="num ratio-up">1.16&times;</td></tr><tr><th scope="row">/updates</th><td class="num">1</td><td class="num">14,151</td><td class="num">13,727</td><td class="num ratio-flat">1.03&times;</td></tr><tr><th scope="row">/updates</th><td class="num">5</td><td class="num">6,098</td><td class="num">5,399</td><td class="num ratio-up">1.13&times;</td></tr><tr><th scope="row">/updates</th><td class="num">10</td><td class="num">3,000</td><td class="num">2,882</td><td class="num ratio-flat">1.04&times;</td></tr><tr><th scope="row">/updates</th><td class="num">15</td><td class="num">1,973</td><td class="num">2,008</td><td class="num ratio-flat">0.98&times;</td></tr><tr><th scope="row">/updates</th><td class="num">20</td><td class="num">1,504</td><td class="num">1,533</td><td class="num ratio-flat">0.98&times;</td></tr></tbody>
</table>
</div>

The two track each other within a few percent until query fan-out grows:
0.99&times; at one query per request, 1.14&times; at
ten, 1.16&times; at twenty. That gap is Kinetis issuing a
request's queries concurrently — see {doc}`concurrency` — and it is the
one place where the framework rather than the runtime shows up. The
single 1.13&times; on `/updates` at five queries is an outlier the
levels either side of it don't support; treat it as noise.

## RoadRunner: a third runtime

Kinetis also runs on [RoadRunner](https://roadrunner.dev/) — see
{doc}`runtime-adapters` — a second persistent-worker runtime alongside
FrankenPHP. A follow-up sweep, same full methodology and machine
isolation as above, compared three targets on the same AWS rig:
`kinetis` (the FrankenPHP leader from above), `kinetis-roadrunner` (the
identical Kinetis application under RoadRunner instead), and
`spiral-roadrunner` — [Spiral Framework](https://spiral.dev/)'s own
RoadRunner target, built and tuned specifically for that runtime.
RoadRunner's worker pool was sized the same way as the FrankenPHP worker
threads above, 2.5&times; vCPUs. Full detail — every concurrency level,
every query count — is in the
[benchmark repository](https://github.com/aln-1/kinetis-benchmarks);
this is the overview.

| Test | <span class="bench-card-name--kinetis">`kinetis`</span> (FrankenPHP) | `kinetis-roadrunner` | `spiral-roadrunner` |
|---|---|---|---|
| `/json` &middot; c=256 | 26,112 | 16,137 | 14,573 |
| `/plaintext` &middot; c=256 | 26,046 | 16,112 | 14,553 |
| `/db` &middot; c=256 | 18,860 | 12,192 | 12,259 |
| `/fortunes` &middot; c=256 | 15,907 | 10,820 | 11,509 |
| `/queries` &middot; n=20 | 4,497 | 4,510 | 3,303 |
| `/updates` &middot; n=20 | 1,514 | 1,493 | 1,302 |

**FrankenPHP beats RoadRunner by a wide margin on the same Kinetis
application** — <span class="bench-stat-win">47&ndash;62%</span> ahead on
`/json`, `/plaintext`, `/db`, and `/fortunes`. That gap narrows to
roughly nothing on `/queries` and `/updates`, the same pattern the
FrankenPHP-vs-PHP-FPM and FrankenPHP-vs-Slim comparisons above both
show: once a request is dominated by database round trips rather than
dispatch overhead, the runtime underneath matters far less than what
happens inside the request.

**Spiral, purpose-built and tuned for RoadRunner, still trails Kinetis
on that same runtime.** Kinetis leads by
<span class="bench-stat-win">11&ndash;36%</span> on `/json`,
`/plaintext`, `/queries`, and `/updates` — the query-fan-out routes show
the widest gap, the same concurrent-dispatch advantage the framework
shows on FrankenPHP too. Spiral only edges ahead on `/db` and
`/fortunes` — <span class="bench-stat-loss">&lt;1&ndash;6%</span> — and
by a small margin.

## Versions tested

| Target | Package | Version | PHP |
|---|---|---|---|
| `kinetis` | kinetis/framework | 1.0.0 | 8.5.9 (FrankenPHP, ZTS) |
| `kinetis-fpm` | kinetis/framework | 1.0.0 | 8.4.24 |
| `slim-frankenphp` | slim/slim | 4.15.2 | 8.5.9 (FrankenPHP, ZTS) |
| `slim` | slim/slim | 4.15.2 | 8.4.24 |
| `yii2` | yiisoft/yii2 | 2.0.55 | 8.4.24 |
| `symfony` | symfony/framework-bundle | 7.4.16 | 8.4.24 |
| `cakephp` | cakephp/cakephp | 5.4.1 | 8.4.24 |
| `codeigniter` | codeigniter4/framework | 4.7.4 | 8.4.24 |
| `laravel` | laravel/framework | 13.25.0 | 8.4.24 |
| `laravel-octane` | laravel/framework + laravel/octane | 13.25.0 + 2.19.0 | 8.5.9 (FrankenPHP, ZTS) |

`kinetis`/`kinetis-fpm` also ran kinetis/persistence 1.0.0 and
kinetis/query-builder 1.0.0. FrankenPHP-based targets run a newer PHP
than the PHP-FPM targets because that's what the `dunglas/frankenphp`
image ships — see {doc}`runtime-adapters` for how Kinetis picks a
runtime.

## Reading these numbers

**Worker count is a variable this run holds fixed.** Both worker-mode
targets got 20 workers on 8 vCPUs. An earlier run on a 2-CPU machine
with 2 workers each put `kinetis` further ahead of the control on
`/queries` and `/updates`. Where workers are plentiful, a blocking
framework gets its parallelism from workers instead, and the gap
narrows — see {doc}`performance-tuning` for how to size a deployment.

**`/updates` is bound by the database's own write path.** At twenty
queries per request every target lands between 716
and 1,860 req/s, and `slim` wins it. Row locks,
index maintenance and the redo log set that ceiling; the database
instance runs at the same load for every target, so no application-side
change moves that number.

**One measurement each, not an average.** Targets ran back to back under
identical conditions, but every cell is a single 15-second run after a
5-second warmup. Differences under roughly 5% are not distinguishable
from noise.

**Not comparable to published TechEmpower figures.** The methodology
matches — separate application, database and client machines — but the
hardware and configuration do not.

## Full methodology

The [benchmark repository](https://github.com/aln-1/kinetis-benchmarks)
has the complete picture: every framework's implementation, the AWS
infrastructure as code, and the fairness rules applied identically
across all ten targets — everything needed to run the same sweep
yourself and check these numbers directly.

## See also

- {doc}`performance-tuning` — the settings these numbers were produced
  with, and how to apply them to your own application.
- {doc}`runtime-adapters` — worker thread sizing, the single setting that
  moved these results most.
- {doc}`caching` — the production AOT cache the benchmarked configuration
  builds ahead of time.
- {doc}`concurrency` — the reason the query-heavy tests scale the way
  they do.
