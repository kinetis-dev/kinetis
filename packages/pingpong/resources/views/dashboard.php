<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Kinetis :: ping-pong</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="stylesheet" href="/dashboard.css">
</head>
<body>
<div class="crt">
  <header>
    <div class="logo" role="img" aria-label="Kinetis"></div>
    <div class="title">
      <h1>PING &middot; PONG</h1>
      <p>a Kinetis runtime demo</p>
    </div>
  </header>

  <section class="diagram">
    <div class="diagram-row">
      <div class="node" id="node-browser">[ BROWSER ]</div>
    </div>
    <div class="diagram-row">
      <div class="node" id="node-app">[ API ]<span class="sub">nginx + PHP-FPM</span></div>
      <div class="node" id="node-soketi">[ SOKETI ]<span class="sub">realtime push</span></div>
    </div>
    <div class="diagram-row">
      <div class="node" id="node-queue">[ QUEUE&nbsp;WORKER ]<span class="sub">queued pongs</span></div>
      <div class="node" id="node-cron">[ CRON ]<span class="sub">self-triggered / 5s</span></div>
      <div class="node" id="node-mysql">[ MYSQL ]<span class="sub">ping_messages</span></div>
    </div>
  </section>

  <section class="controls">
    <button id="pong-direct">DIRECT PONG</button>
    <button id="pong-queued">QUEUED PONG</button>
    <div class="auto-group">
      <span id="auto-indicator">auto-ping: <strong id="auto-state">ON</strong></span>
      <button id="toggle-auto">PAUSE AUTO-PING</button>
    </div>
  </section>

  <section class="tally">
    <span>DIRECT: <strong id="tally-direct">0</strong></span>
    <span>QUEUED: <strong id="tally-queued">0</strong></span>
    <span>CRON: <strong id="tally-cron">0</strong></span>
  </section>

  <section class="log">
    <div class="log-header">&gt; last 10 messages</div>
    <ul id="log-list"></ul>
  </section>
</div>

<script id="soketi-config" type="application/json"><?= json_encode($soketiConfig, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="https://js.pusher.com/8.4.0/pusher.min.js"></script>
<script src="/dashboard.js"></script>
</body>
</html>
