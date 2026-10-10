/*
 * Swim Scorecard front end (Vue 3, no build step).
 *
 * Changes are applied on screen right away, queued, and sent to api.php in order.
 * Every few seconds the open scorecard checks the server for changes made by
 * other people. If the connection drops, queued changes are retried until they
 * go through.
 *
 * Anyone can follow a scorecard. Entering places needs the scorer key from the
 * scorer link (kept on this device once opened) or an organizer signed in with Google.
 */
(function () {
  'use strict';

  var Vue = window.Vue;
  var S = window.SwimScoring;
  var ICONS = window.SWIM_ICONS || {};
  var CFG = Object.assign({
    api: 'api.php',
    pollSeconds: 3,
    teams: [{ name: 'JD', color: '#C8102E' }, { name: 'Opp', color: '#F58220' }],
    limits: { individual: 3, relay: 2 }
  }, window.SWIM_CONFIG || {});

  var KEYS_KEY = 'swimScorecard.keys.v1';
  var MAX_PLACES = 12;
  var POINT_ROWS = 8;
  var DEFAULT_POINTS = { individual: [6, 4, 3, 2, 1, 0, 0, 0], relay: [8, 4, 2, 0, 0, 0, 0, 0] };
  var SWATCHES = ['#C8102E', '#F58220', '#F2B01E', '#2E7D32', '#1565C0', '#5E35B1', '#00838F', '#12263F', '#6D4C41', '#AD1457'];

  // ---------- team colors ----------
  // Each team color gets matching shades: text that is readable on white and on the navy
  // scoreboard, and the right label color (white or navy) for buttons filled with it.
  function hexToRgb(hex) {
    var m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
    if (!m) return null;
    var n = parseInt(m[1], 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
  }
  function toHex(rgb) {
    return '#' + rgb.map(function (v) { return ('0' + Math.round(v).toString(16)).slice(-2); }).join('').toUpperCase();
  }
  function luminance(rgb) {
    var c = rgb.map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  }
  function contrast(a, b) {
    var la = luminance(a), lb = luminance(b);
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
  }
  function mix(rgb, target, t) { return rgb.map(function (v, i) { return v + (target[i] - v) * t; }); }
  var WHITE = [255, 255, 255], NAVY = [18, 38, 63], BLACK = [0, 0, 0];
  function palette(hex) {
    var base = hexToRgb(hex) || [200, 16, 46];
    var onText = contrast(base, WHITE) >= 4.5 || contrast(base, WHITE) >= contrast(base, NAVY) ? '#FFFFFF' : '#12263F';
    var dark = base, light = base, i;
    for (i = 0; i < 24 && contrast(dark, WHITE) < 4.5; i++) dark = mix(dark, BLACK, 0.08);
    for (i = 0; i < 24 && contrast(light, NAVY) < 4.5; i++) light = mix(light, WHITE, 0.1);
    return { base: toHex(base), on: onText, text: toHex(dark), light: toHex(light), tint: toHex(mix(base, WHITE, 0.85)) };
  }

  function clone(value) { return JSON.parse(JSON.stringify(value)); }

  function normalize(card) {
    card.events.forEach(function (ev) {
      if (!ev.splits || Array.isArray(ev.splits)) {
        var map = {};
        (ev.splits || []).forEach(function (v, i) { if (v !== null && v !== undefined) map[i] = v; });
        ev.splits = map;
      }
    });
    return card;
  }

  function newEvent(id, name, relay) {
    var places = [];
    for (var i = 0; i < (relay ? 4 : 6); i++) places.push('');
    return { id: id, name: name, relay: relay, places: places, splits: {} };
  }

  /** Same rules as Scorecard::applyChange on the server, for instant feedback. */
  function applyLocal(card, change) {
    var ev = change.event ? card.events.find(function (e) { return e.id === change.event; }) : null;
    switch (change.op) {
      case 'set':
        if (ev && change.slot === ev.places.length && ev.places.length < MAX_PLACES) ev.places.push('');
        if (ev && change.slot < ev.places.length) ev.places[change.slot] = change.value;
        break;
      case 'split':
        if (ev) {
          var splits = Object.assign({}, ev.splits);
          if (change.home === null) delete splits[change.slot]; else splits[change.slot] = change.home;
          ev.splits = splits;
        }
        break;
      case 'slots':
        if (ev) {
          while (ev.places.length < change.count) ev.places.push('');
          if (ev.places.length > change.count) ev.places.length = change.count;
        }
        break;
      case 'clear':
        if (ev) { ev.places = ev.places.map(function () { return ''; }); ev.splits = {}; }
        break;
      case 'rename':
        card.name = change.name;
        break;
      case 'points':
        card.points = { individual: change.individual.slice(), relay: change.relay.slice() };
        break;
      case 'teams':
        card.teams = change.teams.map(function (t) { return { name: t.name, color: t.color }; });
        break;
      case 'limits':
        card.limits = { individual: change.individual, relay: change.relay };
        break;
      case 'events':
        var byId = {};
        card.events.forEach(function (e) { byId[e.id] = e; });
        card.events = change.events.map(function (item) {
          var existing = byId[item.id];
          if (existing) return Object.assign({}, existing, { name: item.name, relay: item.relay });
          return newEvent(item.id, item.name, item.relay);
        });
        break;
    }
    return card;
  }

  function request(action, options) {
    options = options || {};
    var params = new URLSearchParams({ action: action });
    if (options.id) params.set('id', options.id);
    if (options.version !== undefined && options.version !== null) params.set('v', String(options.version));
    var headers = {};
    var key = options.id ? keyFor(options.id) : '';
    if (key) headers['X-Edit-Key'] = key;
    var post = options.body !== undefined;
    if (post) { headers['Content-Type'] = 'application/json'; headers['X-Swim'] = '1'; }
    return fetch(CFG.api + '?' + params.toString(), {
      method: post ? 'POST' : 'GET',
      headers: headers,
      body: post ? JSON.stringify(options.body) : undefined,
      cache: 'no-store',
      credentials: 'same-origin'
    }).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (data) {
        if (!res.ok) {
          var err = new Error((data && data.error) || ('The server answered with error ' + res.status + '.'));
          err.status = res.status;
          throw err;
        }
        return data;
      });
    });
  }

  /** Scorer keys this device has been given, by scorecard id. */
  function loadKeys() {
    try {
      var map = JSON.parse(localStorage.getItem(KEYS_KEY) || '{}');
      return map && typeof map === 'object' && !Array.isArray(map) ? map : {};
    } catch (e) { return {}; }
  }
  var scorerKeys = loadKeys();
  function keyFor(id) { return typeof scorerKeys[id] === 'string' ? scorerKeys[id] : ''; }
  function rememberKey(id, key) {
    if (!/^[A-Za-z0-9]{12}$/.test(id) || !/^[A-Za-z0-9]{8,64}$/.test(key || '') || scorerKeys[id] === key) return;
    scorerKeys[id] = key;
    try { localStorage.setItem(KEYS_KEY, JSON.stringify(scorerKeys)); } catch (e) { /* private mode */ }
  }
  function forgetKey(id) {
    if (!(id in scorerKeys)) return;
    delete scorerKeys[id];
    try { localStorage.setItem(KEYS_KEY, JSON.stringify(scorerKeys)); } catch (e) { /* private mode */ }
  }

  function idFromUrl() {
    var params = new URLSearchParams(location.search);
    var id = params.get('s');
    if (id && /^[A-Za-z0-9]{12}$/.test(id)) {
      var key = params.get('k');
      if (key) rememberKey(id, key);
      return id;
    }
    return id ? 'invalid' : null;
  }

  var SIGN_IN_MESSAGES = {
    ok: 'Signed in',
    denied: 'That Google account can’t start scorecards',
    cancelled: 'Sign-in was cancelled',
    expired: 'Sign-in took too long. Try again.',
    setup: 'Google sign-in isn’t set up on this server yet',
    error: 'Google sign-in didn’t work. Try again.'
  };

  var app = Vue.createApp({
    setup: function () {
      var ref = Vue.ref, reactive = Vue.reactive, computed = Vue.computed, nextTick = Vue.nextTick;

      var view = ref('loading');
      var card = ref(null);
      var version = 0;
      var pendingId = ref(null);
      var loadError = ref('');

      var newName = ref('');
      var creating = ref(false);
      var startError = ref('');
      var nameInput = ref(null);
      var session = reactive({ loaded: false, signedIn: false, email: '', canCreate: false, googleReady: false });
      var cardList = ref([]);
      var listLoaded = ref(false);
      var listError = ref('');

      var openId = ref(null);
      var board = ref(null);
      var boardVisible = ref(true);
      var ticked = ref(false);

      var sheet = ref(null);
      var settingsTab = ref('events');
      var draftName = ref('');
      var draftEvents = ref([]);
      var draftPoints = reactive({ individual: [], relay: [] });
      var formError = ref('');
      var clearTarget = ref(null);
      var copied = ref('');
      var toast = ref('');
      var toastTimer = null;

      var sync = reactive({ status: 'saved', queue: [], inflight: false, retryMs: 0, message: '' });
      var canEdit = computed(function () { return !!(card.value && card.value.canEdit); });

      // ---------- teams ----------
      var teams = computed(function () {
        var list = card.value && Array.isArray(card.value.teams) && card.value.teams.length === 2 ? card.value.teams : CFG.teams;
        return list.map(function (t, i) {
          return { name: (t && t.name) || CFG.teams[i].name, color: (t && t.color) || CFG.teams[i].color };
        });
      });
      function teamName(i) { return teams.value[i].name; }
      function teamOf(value) { return value === 'H' ? teamName(0) : teamName(1); }
      var teamStyle = computed(function () {
        var style = {};
        teams.value.forEach(function (t, i) {
          var pal = palette(t.color), n = i + 1;
          style['--t' + n] = pal.base;
          style['--t' + n + '-on'] = pal.on;
          style['--t' + n + '-text'] = pal.text;
          style['--t' + n + '-light'] = pal.light;
          style['--t' + n + '-tint'] = pal.tint;
        });
        return style;
      });
      var options = computed(function () {
        return [
          { value: 'H', label: teamName(0) },
          { value: 'A', label: teamName(1) },
          { value: 'T', label: 'Tie' },
          { value: 'DQ', label: 'DQ' }
        ];
      });
      function limitsOf(c) { return c && c.limits ? c.limits : CFG.limits; }
      var pollTimer = null;
      var observer = null;

      // ---------- scoring ----------
      var scores = computed(function () {
        var out = {};
        if (!card.value) return out;
        card.value.events.forEach(function (ev) { out[ev.id] = S.scoreEvent(ev, card.value.points, limitsOf(card.value)); });
        return out;
      });

      var totals = computed(function () {
        var home = 0, away = 0, scored = 0;
        if (card.value) {
          card.value.events.forEach(function (ev) {
            var s = scores.value[ev.id];
            home += s.home; away += s.away;
            if (s.finishers > 0) scored++;
          });
        }
        return { home: home, away: away, scored: scored };
      });

      var leader = computed(function () {
        var t = totals.value;
        if (t.home + t.away === 0) return 'No places entered yet';
        var diff = t.home - t.away;
        if (Math.abs(diff) < 0.005) return 'Tied';
        return (diff > 0 ? teamName(0) : teamName(1)) + ' leads by ' + S.fmt(Math.abs(diff));
      });

      var homeShare = computed(function () {
        var t = totals.value, sum = t.home + t.away;
        return sum ? (t.home / sum) * 100 : 50;
      });

      function shownIssues(ev) {
        return scores.value[ev.id].issues.filter(function (i) { return i.type !== 'over' || i.matters; });
      }
      /** The warning triangle is only for things a scorer has to fix: a lone Tie, or a tie that needs a choice. */
      function needsAttention(ev) {
        if (!canEdit.value) return false;
        return shownIssues(ev).some(function (i) { return i.type === 'lone' || i.type === 'split'; });
      }
      function slotInfo(ev, slot) { return scores.value[ev.id].slots[slot]; }
      function movedTo(ev, slot) {
        var info = slotInfo(ev, slot);
        if (!info || info.place === null || info.value === 'T' || info.over) return 0;
        return info.place !== slot + 1 ? info.place : 0;
      }
      function isOver(ev, slot) { var info = slotInfo(ev, slot); return !!(info && info.over); }
      function rowNote(ev, slot) {
        var info = slotInfo(ev, slot);
        if (!info) return '';
        if (info.value === 'DQ') return 'no place';
        if (info.over) return 'no points';
        var moved = movedTo(ev, slot);
        return moved ? 'scores ' + S.ordinal(moved) : '';
      }
      function rowPoints(ev, slot) {
        var info = slotInfo(ev, slot);
        if (!info) return '';
        if (info.value === 'DQ') return 'DQ';
        if (info.over) return '0';
        if (info.value === 'T') return info.lone ? '?' : S.fmt(info.points);
        if (info.value === 'H' || info.value === 'A') return '+' + S.fmt(info.points);
        return '';
      }
      function pointsClass(ev, slot) {
        var info = slotInfo(ev, slot);
        if (!info) return '';
        if (info.over) return 'text-dq';
        return { H: 't1-text', A: 't2-text', T: 'text-ink', DQ: 'text-dq' }[info.value] || '';
      }
      function chipClass(ev, slot) {
        var v = ev.places[slot];
        if (isOver(ev, slot)) return 'chip-over';
        return 'chip-' + (v || 'none');
      }
      /** The places to show: the saved ones, plus one more when a scoring place is still open. */
      function displayPlaces(ev) {
        var s = scores.value[ev.id];
        var last = ev.places[ev.places.length - 1];
        if (canEdit.value && last && ev.places.length < MAX_PLACES && s.placesScored < s.scoringPlaces) {
          return ev.places.concat(['']);
        }
        return ev.places;
      }
      function tiesEndingAt(ev, slot) {
        return scores.value[ev.id].groups.filter(function (g) { return g.end === slot; });
      }
      function splitOptions(size) {
        var list = [];
        for (var h = size; h >= 0; h--) list.push(h);
        if (size === 2) return [1, 2, 0];
        return list;
      }
      function splitLabel(h, size) {
        var a = teamName(0), b = teamName(1);
        if (size === 2) return h === 1 ? a + ' and ' + b : (h === 2 ? 'Both ' + a : 'Both ' + b);
        if (h === size) return 'All ' + a;
        if (h === 0) return 'All ' + b;
        return h + ' ' + a + ', ' + (size - h) + ' ' + b;
      }
      function issueText(issue) {
        if (issue.type === 'lone') return S.ordinal(issue.slot + 1) + ' is marked Tie. Pick Tie on each tied place, like 4th and 5th for a tie for 4th.';
        if (issue.type === 'split') return 'Pick how many ' + teamName(0) + ' swimmers tied for ' + S.ordinal(issue.place) + '. Their points aren’t counted until you do.';
        if (issue.type === 'gap') return S.ordinal(issue.slot + 1) + ' is empty, so the places below it move up.';
        if (issue.type === 'over') {
          return S.ordinal(issue.slot + 1) + ' is ' + teamOf(issue.team) + '’s ' + S.ordinal(issue.nth) + ' finisher. Only ' + issue.cap +
            ' per team can score, so this place gets no points and everyone behind moves up. If that’s a mistake, change it.';
        }
        if (issue.type === 'tieOver') {
          var who = issue.overHome ? teamName(0) : teamName(1);
          return 'The tie for ' + S.ordinal(issue.place) + ' includes more ' + who + ' swimmers than can score (' + issue.cap + ' per team), so only the ones who can score share the points.';
        }
        return '';
      }
      function shortField(ev) {
        var s = scores.value[ev.id];
        if (s.finishers === 0 || s.placesScored >= s.scoringPlaces) return '';
        var shown = displayPlaces(ev);
        var next = -1;
        for (var i = shown.length - 1; i >= 0 && shown[i] === ''; i--) next = i;
        if ((s.dqs || s.overLimit) && next >= 0) {
          return 'Swimmers behind a DQ or a non-scoring swimmer move up, so ' + (s.scoringPlaces - s.placesScored) +
            (s.scoringPlaces - s.placesScored === 1 ? ' scoring place is' : ' scoring places are') + ' still open. Pick who finished ' + S.ordinal(next + 1) + '.';
        }
        return s.placesScored + ' of ' + s.scoringPlaces + ' scoring places filled. Places nobody fills don’t score.';
      }

      // ---------- sync ----------
      var syncLabel = computed(function () {
        if (card.value && card.value.locked) return 'Locked';
        if (!canEdit.value) return sync.status === 'offline' ? 'Offline, will retry' : 'Live, view only';
        if (sync.status === 'saving') return 'Saving';
        if (sync.status === 'offline') return 'Offline, will retry';
        if (sync.status === 'error') return sync.message || 'Not saved, retrying';
        return 'Saved';
      });
      var syncDot = computed(function () {
        if (card.value && card.value.locked) return 'bg-white/50';
        return { saving: 'bg-away', offline: 'bg-white/50', error: 'bg-danger' }[sync.status] || 'bg-[#4ADE80]';
      });

      function adopt(serverCard, fromPoll) {
        if (fromPoll && serverCard.version < version) return;
        normalize(serverCard);
        version = serverCard.version;
        if (serverCard.editKey) rememberKey(serverCard.id, serverCard.editKey);
        var local = clone(serverCard);
        sync.queue.forEach(function (change) { applyLocal(local, change); });
        card.value = local;
      }

      function versionTag() {
        var c = card.value || {};
        return version + (c.isScorer ? 'e' : 'v') + (c.canLock ? 'c' : '');
      }

      function queueChange(change) {
        if (!card.value) return;
        if (!canEdit.value) { showToast(card.value.locked ? 'This scorecard is locked' : 'This scorecard is view-only for you'); return; }
        applyLocal(card.value, change);
        sync.queue.push(change);
        flush();
      }

      function flush() {
        if (sync.inflight || !sync.queue.length || !card.value) return;
        var batch = sync.queue.splice(0, 50);
        var id = card.value.id;
        sync.inflight = true;
        sync.status = 'saving';
        request('change', { id: id, body: { changes: batch } }).then(function (serverCard) {
          sync.inflight = false;
          sync.retryMs = 0;
          adopt(serverCard, false);
          sync.status = sync.queue.length ? 'saving' : 'saved';
          flush();
        }).catch(function (err) {
          sync.inflight = false;
          if (err.status && err.status < 500) {
            // The server refused these changes (for example, someone removed the event). Show the real state.
            sync.status = 'saved';
            showToast(err.message);
            if (err.status === 404) { view.value = 'missing'; loadError.value = err.message; return; }
            if (err.status === 403) { sync.queue.splice(0); forgetKey(id); }
            refresh();
            flush();
            return;
          }
          sync.queue.unshift.apply(sync.queue, batch);
          sync.status = err.status ? 'error' : 'offline';
          sync.message = err.status ? err.message : '';
          sync.retryMs = Math.min((sync.retryMs || 1000) * 2, 20000);
          setTimeout(flush, sync.retryMs);
        });
      }

      function refresh() {
        if (!card.value) return Promise.resolve();
        return request('get', { id: card.value.id }).then(function (c) {
          if (!sync.inflight) adopt(c, false);
        }).catch(function () { /* the next poll will try again */ });
      }

      function schedulePoll() {
        clearTimeout(pollTimer);
        pollTimer = setTimeout(poll, CFG.pollSeconds * 1000);
      }

      function poll() {
        if (view.value !== 'card' || !card.value || document.hidden || sync.inflight || sync.queue.length) {
          schedulePoll();
          return;
        }
        var id = card.value.id;
        request('get', { id: id, version: versionTag() }).then(function (res) {
          if (!card.value || card.value.id !== id) return;
          if (!res.unchanged && !sync.inflight && !sync.queue.length) adopt(res, true);
          if (sync.status === 'offline' || sync.status === 'error') sync.status = 'saved';
        }).catch(function (err) {
          if (!err.status) sync.status = 'offline';
          else if (err.status === 404) { view.value = 'missing'; loadError.value = err.message; }
        }).then(schedulePoll);
      }

      // ---------- sign-in and the scorecard list ----------
      function loadSession() {
        return request('session').then(function (s) {
          session.loaded = true;
          session.signedIn = !!s.signedIn;
          session.email = s.email || '';
          session.canCreate = !!s.canCreate;
          session.googleReady = !!s.googleReady;
        }).catch(function () { session.loaded = true; });
      }
      function loadList() {
        listError.value = '';
        return request('list').then(function (res) {
          cardList.value = Array.isArray(res.cards) ? res.cards : [];
          listLoaded.value = true;
        }).catch(function (err) {
          listLoaded.value = true;
          listError.value = err.status ? err.message : 'The scorecards couldn’t be loaded. Check your connection.';
        });
      }
      function hasKey(id) { return !!keyFor(id); }
      var signInUrl = computed(function () {
        return 'auth.php?action=start' + (card.value && view.value === 'card' ? '&return=' + encodeURIComponent(card.value.id) : '');
      });
      function signOut() {
        request('signout', { body: {} }).then(function () {
          session.signedIn = false; session.email = ''; session.canCreate = false;
          showToast('Signed out');
          if (view.value === 'card') refresh();
        }).catch(function () { showToast('Sign-out didn’t go through. Try again.'); });
      }
      function readSignInResult() {
        var params = new URLSearchParams(location.search);
        var result = params.get('signin');
        if (!result) return;
        params.delete('signin');
        var query = params.toString();
        history.replaceState(null, '', location.pathname.replace(/index\.php$/, '') + (query ? '?' + query : ''));
        showToast(SIGN_IN_MESSAGES[result] || SIGN_IN_MESSAGES.error);
      }

      function enterCard(c) {
        normalize(c);
        sync.queue.splice(0);
        sync.status = 'saved';
        version = c.version;
        card.value = c;
        ticked.value = false;
        view.value = 'card';
        if (c.editKey) rememberKey(c.id, c.editKey);
        var firstOpen = c.events.find(function (ev) { return S.scoreEvent(ev, c.points).entered === 0; });
        openId.value = firstOpen ? firstOpen.id : null;
        document.title = c.name + ' · Swim Scorecard';
        nextTick(function () {
          watchBoard();
          setTimeout(function () { ticked.value = true; }, 50);
        });
        schedulePoll();
      }

      function openCard(id) {
        pendingId.value = id;
        view.value = 'loading';
        request('get', { id: id }).then(enterCard).catch(function (err) {
          loadError.value = err.status === 404 || err.status === 400
            ? 'Check that the link was copied in full, or start a new scorecard.'
            : (err.status ? err.message : 'The server couldn’t be reached. Check your connection and try again.');
          view.value = err.status === 404 || err.status === 400 ? 'missing' : 'error';
        });
      }

      function route() {
        var id = idFromUrl();
        clearTimeout(pollTimer);
        if (id === 'invalid') {
          view.value = 'missing';
          loadError.value = 'Check that the link was copied in full, or start a new scorecard.';
        } else if (id) {
          if (!card.value || card.value.id !== id || view.value !== 'card') openCard(id);
        } else {
          card.value = null;
          view.value = 'start';
          document.title = 'Swim Scorecard';
          loadList();
        }
      }

      function goToCard(id) {
        history.pushState(null, '', '?s=' + encodeURIComponent(id));
        route();
      }

      function goHome() {
        if (sync.queue.length || sync.inflight) {
          showToast('Still saving. Try again in a moment.');
          flush();
          return;
        }
        history.pushState(null, '', location.pathname);
        route();
      }

      function createCard() {
        var name = newName.value.trim();
        startError.value = '';
        if (!name) { startError.value = 'Enter a name for the scorecard, like JD vs Bville.'; return; }
        if (!session.canCreate) { startError.value = 'Sign in with Google to start a scorecard.'; return; }
        creating.value = true;
        request('create', { body: { name: name } }).then(function (c) {
          creating.value = false;
          newName.value = '';
          history.pushState(null, '', '?s=' + encodeURIComponent(c.id));
          enterCard(c);
        }).catch(function (err) {
          creating.value = false;
          startError.value = err.status ? err.message : 'The server couldn’t be reached. Check your connection and try again.';
          if (err.status === 401) loadSession();
        });
      }

      // ---------- entering places ----------
      function pick(ev, slot, value) {
        if (!canEdit.value) return;
        var next = ev.places[slot] === value ? '' : value;
        queueChange({ op: 'set', event: ev.id, slot: slot, value: next });
        if (next === 'H' || next === 'A') {
          var info = scores.value[ev.id].slots[slot];
          if (info && info.over && info.matters) {
            showToast(teamOf(next) + ' already has ' + scores.value[ev.id].cap + ' scoring. This one gets no points.');
          }
        }
        if (navigator.vibrate) { try { navigator.vibrate(8); } catch (e) { /* not supported */ } }
      }
      function setSplit(ev, start, home) {
        queueChange({ op: 'split', event: ev.id, slot: start, home: home });
      }
      function addPlace(ev) {
        if (ev.places.length < MAX_PLACES) queueChange({ op: 'slots', event: ev.id, count: ev.places.length + 1 });
      }
      function askClear(ev) {
        clearTarget.value = ev;
        sheet.value = 'clear';
      }
      function confirmClear() {
        if (clearTarget.value) queueChange({ op: 'clear', event: clearTarget.value.id });
        closeSheet();
        showToast('Event cleared');
      }
      function scrollToEvent(id) {
        nextTick(function () {
          var el = document.getElementById('event-' + id);
          if (!el) return;
          var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
          el.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
        });
      }
      function toggle(id) {
        openId.value = openId.value === id ? null : id;
        if (openId.value) scrollToEvent(id);
      }
      function nextEvent(i) {
        var next = card.value.events[i + 1];
        openId.value = next ? next.id : null;
        if (next) scrollToEvent(next.id);
      }

      // ---------- locking ----------
      var locking = ref(false);
      function setLock(locked) {
        if (!card.value || !card.value.canLock || locking.value) return;
        if (sync.queue.length || sync.inflight) { showToast('Still saving. Try again in a moment.'); flush(); return; }
        locking.value = true;
        request('lock', { id: card.value.id, body: { locked: locked } }).then(function (c) {
          locking.value = false;
          adopt(c, false);
          showToast(locked ? 'Scorecard locked' : 'Scorecard unlocked');
          if (sheet.value === 'settings') closeSheet();
        }).catch(function (err) {
          locking.value = false;
          showToast(err.status ? err.message : 'That didn’t go through. Check your connection and try again.');
          refresh();
        });
      }

      // ---------- sheets ----------
      var sheetTitle = computed(function () {
        return { share: 'Share this scorecard', rename: 'Rename', settings: 'Scorecard settings', clear: 'Clear this event?' }[sheet.value] || '';
      });
      function closeSheet() { sheet.value = null; formError.value = ''; copied.value = ''; }

      var viewUrl = computed(function () {
        return card.value ? location.origin + location.pathname.replace(/index\.php$/, '') + '?s=' + encodeURIComponent(card.value.id) : '';
      });
      var scorerUrl = computed(function () {
        if (!card.value || !card.value.isScorer) return '';
        var key = card.value.editKey || keyFor(card.value.id);
        return key ? viewUrl.value + '&k=' + encodeURIComponent(key) : '';
      });
      var shareUrl = computed(function () { return scorerUrl.value || viewUrl.value; });
      var qrSvg = computed(function () {
        if (!shareUrl.value || typeof window.qrcode !== 'function') return '';
        var qr = window.qrcode(0, 'M');
        qr.addData(shareUrl.value);
        qr.make();
        return qr.createSvgTag({ cellSize: 4, margin: 8 });
      });
      var canShare = typeof navigator.share === 'function';

      function openShare() { copied.value = ''; sheet.value = 'share'; }
      function copyLink(which) {
        var url = which === 'view' ? viewUrl.value : shareUrl.value;
        var inputId = which === 'view' ? 'view-url' : 'share-url';
        var done = function (ok) {
          if (ok) { copied.value = which || 'main'; setTimeout(function () { copied.value = ''; }, 2500); }
          else showToast('Press and hold the link to copy it');
        };
        var fallback = function () {
          var input = document.getElementById(inputId);
          if (!input) return done(false);
          input.focus(); input.select();
          var ok = false;
          try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
          done(ok);
        };
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(url).then(function () { done(true); }, fallback);
        } else {
          fallback();
        }
      }
      function nativeShare() {
        var text = scorerUrl.value ? 'Help score ' + card.value.name : 'Follow ' + card.value.name + ' live';
        if (!scorerUrl.value && card.value.locked) text = 'Final score: ' + card.value.name;
        navigator.share({ title: card.value.name, text: text, url: shareUrl.value }).catch(function () { /* closed */ });
      }

      function openRename() {
        if (!canEdit.value) return;
        draftName.value = card.value.name;
        formError.value = '';
        sheet.value = 'rename';
        nextTick(function () { var el = document.getElementById('rename-input'); if (el) el.focus(); });
      }
      function saveName() {
        var name = draftName.value.trim();
        if (!name) { formError.value = 'Enter a name for the scorecard.'; return; }
        queueChange({ op: 'rename', name: name });
        document.title = name + ' · Swim Scorecard';
        closeSheet();
        showToast('Name saved');
      }

      var draftTeams = ref([]);
      var draftLimits = reactive({ individual: '3', relay: '2' });
      function openSettings() {
        if (!canEdit.value && !(card.value && card.value.canLock)) return;
        draftTeams.value = teams.value.map(function (t) { return { name: t.name, color: t.color }; });
        draftLimits.individual = String(limitsOf(card.value).individual);
        draftLimits.relay = String(limitsOf(card.value).relay);
        var counter = 0;
        draftEvents.value = card.value.events.map(function (ev) {
          return { key: 'k' + (counter++), id: ev.id, name: ev.name, relay: ev.relay, hasPlaces: ev.places.some(function (v) { return v !== ''; }) };
        });
        draftPoints.individual = card.value.points.individual.map(String);
        draftPoints.relay = card.value.points.relay.map(String);
        while (draftPoints.individual.length < POINT_ROWS) draftPoints.individual.push('0');
        while (draftPoints.relay.length < POINT_ROWS) draftPoints.relay.push('0');
        formError.value = '';
        settingsTab.value = 'teams';
        sheet.value = 'settings';
      }
      function chipStyle(color) {
        var pal = palette(color);
        return { background: pal.base, color: pal.on };
      }
      function pickSwatch(i, color) { draftTeams.value[i].color = color; }
      function saveTeams() {
        var list = draftTeams.value.map(function (t) { return { name: String(t.name || '').trim(), color: String(t.color || '').toUpperCase() }; });
        var blank = list.findIndex(function (t) { return !t.name; });
        if (blank >= 0) { formError.value = 'Team ' + (blank + 1) + ' needs a name.'; return; }
        if (list.some(function (t) { return Array.from(t.name).length > 10; })) { formError.value = 'Keep team names to 10 characters so they fit on the buttons.'; return; }
        if (list.some(function (t) { return !/^#[0-9A-F]{6}$/.test(t.color); })) { formError.value = 'Pick a color for each team.'; return; }
        if (list[0].name.toLowerCase() === list[1].name.toLowerCase()) { formError.value = 'Give the two teams different names.'; return; }
        queueChange({ op: 'teams', teams: list });
        closeSheet();
        showToast('Teams saved');
      }
      var removedWithPlaces = computed(function () {
        if (!card.value || sheet.value !== 'settings') return 0;
        var kept = {};
        draftEvents.value.forEach(function (e) { kept[e.id] = true; });
        return card.value.events.filter(function (ev) {
          return !kept[ev.id] && ev.places.some(function (v) { return v !== ''; });
        }).length;
      });
      function moveEvent(k, dir) {
        var list = draftEvents.value.slice();
        var item = list.splice(k, 1)[0];
        list.splice(k + dir, 0, item);
        draftEvents.value = list;
      }
      function removeEvent(k) {
        var list = draftEvents.value.slice();
        list.splice(k, 1);
        draftEvents.value = list;
      }
      function addEvent() {
        var id = 'n' + Math.random().toString(36).slice(2, 10);
        draftEvents.value = draftEvents.value.concat([{ key: id, id: id, name: '', relay: false, hasPlaces: false }]);
        nextTick(function () {
          var inputs = document.querySelectorAll('[aria-label$=" name"]');
          if (inputs.length) inputs[inputs.length - 1].focus();
        });
      }
      function saveEvents() {
        var list = draftEvents.value;
        if (!list.length) { formError.value = 'Keep at least one event.'; return; }
        if (list.length > 30) { formError.value = 'A scorecard can have up to 30 events.'; return; }
        var blank = list.findIndex(function (e) { return !e.name.trim(); });
        if (blank >= 0) { formError.value = 'Event ' + (blank + 1) + ' needs a name.'; return; }
        queueChange({ op: 'events', events: list.map(function (e) { return { id: e.id, name: e.name.trim(), relay: !!e.relay }; }) });
        closeSheet();
        showToast('Events saved');
      }
      function resetPoints() {
        draftPoints.individual = DEFAULT_POINTS.individual.map(String);
        draftPoints.relay = DEFAULT_POINTS.relay.map(String);
      }
      function parsePoints(list) {
        var out = [];
        for (var i = 0; i < list.length; i++) {
          var raw = String(list[i] === undefined || list[i] === null ? '' : list[i]).trim();
          if (raw === '') { out.push(0); continue; }
          var n = Number(raw);
          if (!isFinite(n) || n < 0 || n > 100) return null;
          out.push(Math.round(n * 100) / 100);
        }
        return out;
      }
      function savePoints() {
        var individual = parsePoints(draftPoints.individual);
        var relay = parsePoints(draftPoints.relay);
        if (!individual || !relay) { formError.value = 'Points must be numbers from 0 to 100.'; return; }
        var li = Number(String(draftLimits.individual).trim()), lr = Number(String(draftLimits.relay).trim());
        if (!Number.isInteger(li) || !Number.isInteger(lr) || li < 0 || lr < 0 || li > MAX_PLACES || lr > MAX_PLACES) {
          formError.value = 'Scorers per team must be whole numbers from 0 to ' + MAX_PLACES + '. Use 0 for no limit.';
          return;
        }
        queueChange({ op: 'limits', individual: li, relay: lr });
        queueChange({ op: 'points', individual: individual, relay: relay });
        closeSheet();
        showToast('Points saved');
      }

      // ---------- small helpers ----------
      function showToast(message) {
        toast.value = message;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toast.value = ''; }, 2800);
      }
      function icon(name) {
        var inner = ICONS[name] || '';
        return '<svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + inner + '</svg>';
      }
      function when(ts) {
        if (!ts) return '';
        var d = new Date(ts), now = new Date();
        var time = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        if (d.toDateString() === now.toDateString()) return 'Today, ' + time;
        return d.toLocaleDateString([], { month: 'short', day: 'numeric', year: d.getFullYear() === now.getFullYear() ? undefined : 'numeric' }) + ', ' + time;
      }
      function watchBoard() {
        if (observer) observer.disconnect();
        if (!board.value || !('IntersectionObserver' in window)) { boardVisible.value = true; return; }
        observer = new IntersectionObserver(function (entries) {
          boardVisible.value = entries[0].isIntersecting;
        }, { rootMargin: '-60px 0px 0px 0px' });
        observer.observe(board.value);
      }

      // ---------- lifecycle ----------
      Vue.onMounted(function () {
        window.addEventListener('popstate', route);
        document.addEventListener('visibilitychange', function () {
          if (!document.hidden && view.value === 'card') { flush(); poll(); }
        });
        window.addEventListener('online', function () { flush(); poll(); });
        window.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sheet.value) closeSheet(); });
        window.addEventListener('beforeunload', function (e) {
          if (sync.queue.length || sync.inflight) { e.preventDefault(); e.returnValue = ''; }
        });
        readSignInResult();
        loadSession();
        route();
      });

      return {
        view: view, card: card, pendingId: pendingId, loadError: loadError,
        newName: newName, creating: creating, startError: startError, nameInput: nameInput,
        session: session, cardList: cardList, listLoaded: listLoaded, listError: listError, hasKey: hasKey,
        signInUrl: signInUrl, signOut: signOut, canEdit: canEdit, viewUrl: viewUrl, scorerUrl: scorerUrl,
        teams: teams, teamName: teamName, teamStyle: teamStyle, options: options, SWATCHES: SWATCHES,
        draftTeams: draftTeams, draftLimits: draftLimits, chipStyle: chipStyle, pickSwatch: pickSwatch, saveTeams: saveTeams,
        locking: locking, setLock: setLock, displayPlaces: displayPlaces, isOver: isOver, rowNote: rowNote,
        chipClass: chipClass, shownIssues: shownIssues,
        openId: openId, board: board, boardVisible: boardVisible, ticked: ticked,
        sheet: sheet, sheetTitle: sheetTitle, settingsTab: settingsTab, draftName: draftName, draftEvents: draftEvents,
        draftPoints: draftPoints, formError: formError, clearTarget: clearTarget, copied: copied, toast: toast,
        scores: scores, totals: totals, leader: leader, homeShare: homeShare, syncLabel: syncLabel, syncDot: syncDot,
        shareUrl: shareUrl, qrSvg: qrSvg, canShare: canShare, removedWithPlaces: removedWithPlaces,
        MAX_PLACES: MAX_PLACES, POINT_ROWS: POINT_ROWS,
        fmt: S.fmt, ordinal: S.ordinal, icon: icon, when: when,
        needsAttention: needsAttention, movedTo: movedTo, rowPoints: rowPoints, pointsClass: pointsClass,
        tiesEndingAt: tiesEndingAt, splitOptions: splitOptions, splitLabel: splitLabel, issueText: issueText, shortField: shortField,
        createCard: createCard, openCard: openCard, goToCard: goToCard, goHome: goHome,
        pick: pick, setSplit: setSplit, addPlace: addPlace, askClear: askClear, confirmClear: confirmClear,
        toggle: toggle, nextEvent: nextEvent,
        closeSheet: closeSheet, openShare: openShare, copyLink: copyLink, nativeShare: nativeShare,
        openRename: openRename, saveName: saveName,
        openSettings: openSettings, moveEvent: moveEvent, removeEvent: removeEvent, addEvent: addEvent, saveEvents: saveEvents,
        resetPoints: resetPoints, savePoints: savePoints
      };
    }
  });

  app.mount('#app');
})();
