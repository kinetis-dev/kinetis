(function () {
  var broadcastConfig = JSON.parse(document.getElementById('broadcast-config').textContent);

  var tally = { direct: 0, queued: 0, cron: 0 };

  function renderTally() {
    for (var scenario in tally) {
      var el = document.getElementById('tally-' + scenario);
      if (el) el.textContent = String(tally[scenario]);
    }
  }

  // Starts the counters from the real totals instead of zero; the
  // Soketi-driven increments below continue on top of this base.
  fetch('/pong/tally')
    .then(function (response) { return response.json(); })
    .then(function (data) {
      var counts = data.counts;
      for (var scenario in tally) {
        if (typeof counts[scenario] === 'number') tally[scenario] = counts[scenario];
      }
      renderTally();
    });

  var stageToNode = {
    app: 'node-app',
    db: 'node-mysql',
    queue: 'node-queue',
    cron: 'node-cron',
    socket: 'node-soketi'
  };

  var flashTimers = {};

  // Briefly highlights a diagram node.
  function flashNode(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.classList.add('active');
    if (flashTimers[id]) {
      clearTimeout(flashTimers[id]);
    }
    flashTimers[id] = setTimeout(function () {
      el.classList.remove('active');
      delete flashTimers[id];
    }, 1200);
  }

  function bumpTally(scenario) {
    if (tally[scenario] === undefined) return;
    tally[scenario]++;
    var el = document.getElementById('tally-' + scenario);
    if (el) el.textContent = String(tally[scenario]);
  }

  function trimLog(list) {
    while (list.children.length > 10) {
      list.removeChild(list.lastChild);
    }
  }

  // Opens a pending log row for a new ping.
  function startLogRow(id, scenario) {
    if (document.getElementById('log-row-' + id)) return;
    var list = document.getElementById('log-list');
    var li = document.createElement('li');
    li.id = 'log-row-' + id;
    li.className = 'status-pending';
    var tag = scenario ? scenario.toUpperCase() : '&hellip;';
    li.innerHTML = '#' + id + ' <span class="tag">[' + tag + ']</span> ping &hellip; <span class="waiting">waiting</span>';
    list.insertBefore(li, list.firstChild);
    trimLog(list);
  }

  // Completes the log row for a ponged ping.
  function completeLogRow(id, scenario) {
    var list = document.getElementById('log-list');
    var li = document.getElementById('log-row-' + id);
    if (!li) {
      li = document.createElement('li');
      li.id = 'log-row-' + id;
      list.insertBefore(li, list.firstChild);
    }
    li.className = 'status-ponged';
    li.innerHTML = '#' + id + ' <span class="tag">[' + scenario.toUpperCase() + ']</span> ping &rarr; pong';
    trimLog(list);
  }

  // A message delivered only to a subscriber pusher-js has already
  // authorized against POST /broadcasting/auth — this list is proof the
  // handshake succeeded, not merely that the public channel works.
  function logPrivateNotification(id, scenario) {
    var list = document.getElementById('private-log-list');
    var li = document.createElement('li');
    li.innerHTML = '#' + id + ' <span class="tag">[' + scenario.toUpperCase() + ']</span> your ping was ponged';
    list.insertBefore(li, list.firstChild);
    trimLog(list);
  }

  // Sends a ping; the UI updates only once the broadcast arrives.
  function sendPing(url) {
    flashNode('node-browser');
    fetch(url, { method: 'POST' });
  }

  function sendRandomPing() {
    sendPing(Math.random() < 0.5 ? '/pong/direct' : '/pong/queued');
  }

  var autoPingTimer = null;
  var autoPingEnabled = false;

  function updateAutoControls() {
    var state = document.getElementById('auto-state');
    if (state) state.textContent = autoPingEnabled ? 'ON' : 'OFF';
    var btn = document.getElementById('toggle-auto');
    if (btn) btn.textContent = autoPingEnabled ? 'PAUSE AUTO-PING' : 'RESUME AUTO-PING';
  }

  function startAutoPing() {
    if (autoPingTimer) return;
    autoPingTimer = setInterval(sendRandomPing, 4000);
    autoPingEnabled = true;
    updateAutoControls();
  }

  function stopAutoPing() {
    if (autoPingTimer) {
      clearInterval(autoPingTimer);
      autoPingTimer = null;
    }
    autoPingEnabled = false;
    updateAutoControls();
  }

  document.getElementById('pong-direct').addEventListener('click', function () { sendPing('/pong/direct'); });
  document.getElementById('pong-queued').addEventListener('click', function () { sendPing('/pong/queued'); });
  document.getElementById('toggle-auto').addEventListener('click', function () {
    if (autoPingEnabled) { stopAutoPing(); } else { startAutoPing(); }
  });
  startAutoPing();

  var pusher = new Pusher(broadcastConfig.key, {
    wsHost: broadcastConfig.host,
    wsPort: broadcastConfig.port,
    forceTLS: false,
    enabledTransports: ['ws'],
    cluster: 'kinetis',
    // Only reached for the private-notifications subscription below —
    // pusher-js calls this itself before a private/presence channel is
    // allowed to subscribe. Kinetis\Broadcasting\Http\BroadcastAuthController
    // is the server side of this handshake.
    authEndpoint: '/broadcasting/auth'
  });

  var channel = pusher.subscribe('ping-pong');
  channel.bind('action', function (data) {
    var nodeId = stageToNode[data.stage];
    if (nodeId) flashNode(nodeId);

    if (data.stage === 'app') {
      startLogRow(data.id, data.scenario);
    } else if (data.stage === 'cron') {
      startLogRow(data.id, 'cron');
    } else if (data.stage === 'socket') {
      bumpTally(data.scenario);
      completeLogRow(data.id, data.scenario);
    }
  });

  // Demonstrates kinetis/broadcasting's private channels: subscribing
  // here only succeeds once NotificationChannelAuthorizer has signed it
  // off, through the real /broadcasting/auth round trip above.
  var privateChannel = pusher.subscribe('private-notifications');
  privateChannel.bind('pusher:subscription_succeeded', function () {
    console.log('private-notifications: authorized and subscribed');
  });
  privateChannel.bind('pusher:subscription_error', function (status) {
    console.error('private-notifications: authorization failed', status);
  });
  privateChannel.bind('pong.notified', function (data) {
    logPrivateNotification(data.id, data.scenario);
  });
})();
