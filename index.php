<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$config = app_config();

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

/** Adds the file's modified time so browsers pick up new versions after an update. */
function asset(string $path): string
{
    $file = __DIR__ . '/' . $path;
    return htmlspecialchars($path . '?v=' . (is_file($file) ? filemtime($file) : '1'), ENT_QUOTES);
}

$defaults = Scorecard::withDefaults([], $config);
$clientConfig = [
    'api' => 'api.php',
    'pollSeconds' => max(2, (int) $config['poll_seconds']),
    'teams' => $defaults['teams'],
    'limits' => $defaults['limits'],
];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#12263F">
  <meta name="robots" content="noindex">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <title>Swim Scorecard</title>
  <link rel="icon" href="<?= asset('assets/img/icon.svg') ?>" type="image/svg+xml">
  <link rel="preload" href="assets/fonts/barlow-condensed-latin-700-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
  <script>window.SWIM_CONFIG = <?= json_encode($clientConfig, JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
  <script defer src="<?= asset('assets/js/vue.global.prod.js') ?>"></script>
  <script defer src="<?= asset('assets/js/qrcode.js') ?>"></script>
  <script defer src="<?= asset('assets/js/icons.js') ?>"></script>
  <script defer src="<?= asset('assets/js/scoring.js') ?>"></script>
  <script defer src="<?= asset('assets/js/app.js') ?>"></script>
</head>
<body>
<noscript><p style="padding:24px;font-family:sans-serif">Swim Scorecard needs JavaScript. Turn it on in your browser settings and reload.</p></noscript>

<div id="app" v-cloak>
<div :style="teamStyle">

  <!-- Start: scorecard list, and starting one for the signed-in organizer -->
  <div v-if="view === 'start'" class="flex min-h-dvh flex-col">
    <div class="bg-ink text-white">
      <div class="mx-auto max-w-xl px-5 pb-9 pt-[calc(env(safe-area-inset-top)+22px)]">
        <div class="flex items-center gap-2.5">
          <img src="assets/img/icon.svg" alt="" class="h-9 w-9 rounded-[10px] ring-1 ring-white/25">
          <span class="font-display text-[22px] font-semibold">Swim Scorecard</span>
        </div>
        <h1 class="mt-8 font-display text-[44px] font-bold leading-[1.02]">Score a dual meet</h1>
        <p class="mt-3 max-w-[34ch] text-[17px] leading-relaxed text-white/80">Follow a meet live, or start a scorecard and send the scorer link to the people entering places.</p>
      </div>
      <div class="rope"></div>
    </div>

    <main class="mx-auto w-full max-w-xl flex-1 px-5 py-7">
      <form v-if="session.canCreate" class="mb-10" novalidate @submit.prevent="createCard">
        <label for="card-name" class="block text-[16px] font-semibold">Scorecard name</label>
        <input id="card-name" ref="nameInput" v-model="newName" class="input mt-2" maxlength="60" autocomplete="off"
               autocapitalize="words" enterkeyhint="go" placeholder="JD vs Bville">
        <p v-if="startError" class="mt-2 text-[15px] text-danger" role="alert">{{ startError }}</p>
        <button type="submit" class="btn-primary mt-4 w-full" :disabled="creating">
          <span v-if="creating" class="spin" v-html="icon('loader')"></span>{{ creating ? 'Starting' : 'Start scoring' }}
        </button>
        <p class="mt-3 text-[14px] text-ink-soft">
          Signed in as {{ session.email }}.
          <button type="button" class="font-semibold text-home" @click="signOut">Sign out</button>
        </p>
      </form>

      <section>
        <h2 class="font-display text-[24px] font-semibold">Scorecards</h2>
        <p v-if="!listLoaded" class="mt-2 text-[15px] text-ink-soft">Loading scorecards</p>
        <p v-else-if="listError" class="mt-2 text-[15px] text-danger">{{ listError }}</p>
        <p v-else-if="!cardList.length" class="mt-2 text-[15px] text-ink-soft">No scorecards yet.</p>
        <ul v-else class="mt-2 divide-y divide-rope border-y border-rope">
          <li v-for="c in cardList" :key="c.id">
            <a :href="'?s=' + c.id" class="flex items-center gap-3 py-3.5" @click.prevent="goToCard(c.id)">
              <span class="min-w-0 flex-1">
                <span class="block truncate text-[17px] font-semibold">{{ c.name }}</span>
                <span class="block text-[14px] text-ink-soft">{{ when(c.updated * 1000) }}</span>
              </span>
              <span v-if="c.locked" class="tag inline-flex items-center gap-1"><span class="[&>svg]:h-3.5 [&>svg]:w-3.5" v-html="icon('lock')"></span>Locked</span>
              <span v-else-if="hasKey(c.id) || session.signedIn" class="tag">Scorer</span>
              <span class="-rotate-90 text-ink-soft" v-html="icon('chevron')"></span>
            </a>
          </li>
        </ul>
      </section>

      <section v-if="session.loaded && !session.canCreate" class="mt-10 rounded-2xl border border-rope bg-white p-4">
        <h2 class="font-display text-[22px] font-semibold">Start a scorecard</h2>
        <template v-if="session.googleReady">
          <p class="mt-1 text-[15px] leading-relaxed text-ink-soft">The meet organizer signs in with Google to start a new scorecard.</p>
          <a :href="signInUrl" class="btn-secondary mt-3 w-full"><span v-html="icon('login')"></span>Sign in with Google</a>
        </template>
        <p v-else class="mt-1 text-[15px] leading-relaxed text-ink-soft">Google sign-in isn’t set up on this server yet. Add the Google settings to config.php, as described in INSTALL.md.</p>
      </section>
    </main>
  </div>

  <!-- Loading -->
  <div v-else-if="view === 'loading'" class="grid min-h-dvh place-items-center text-ink-soft">
    <span class="spin" v-html="icon('loader')"></span><span class="sr-only">Loading the scorecard</span>
  </div>

  <!-- Missing or failed to load -->
  <main v-else-if="view === 'missing' || view === 'error'" class="mx-auto max-w-xl px-5 pb-16 pt-[calc(env(safe-area-inset-top)+56px)]">
    <h1 class="font-display text-[36px] font-bold leading-tight">{{ view === 'missing' ? 'Scorecard not found' : 'The scorecard didn’t load' }}</h1>
    <p class="mt-3 text-[17px] text-ink-soft">{{ loadError }}</p>
    <div class="mt-7 flex flex-wrap gap-3">
      <button v-if="view === 'error'" type="button" class="btn-primary" @click="openCard(pendingId)">Try again</button>
      <button type="button" class="btn-secondary" @click="goHome">Start a new scorecard</button>
    </div>
  </main>

  <!-- Scoring -->
  <div v-else-if="view === 'card' && card" class="min-h-dvh pb-28">
    <header class="sticky top-0 z-30 bg-ink pt-[env(safe-area-inset-top)] text-white">
      <div class="mx-auto flex h-[60px] max-w-xl items-center gap-0.5 px-1.5">
        <button type="button" class="icon-btn" aria-label="All scorecards" @click="goHome"><span v-html="icon('home')"></span></button>
        <button type="button" class="min-w-0 flex-1 rounded-md px-1.5 py-1 text-left disabled:cursor-default" :disabled="!canEdit"
                :aria-label="canEdit ? 'Rename this scorecard' : card.name" @click="openRename">
          <span class="block truncate font-display text-[21px] font-semibold leading-tight">{{ card.name }}</span>
          <span class="flex items-center gap-1.5 text-[13px] text-white/70">
            <span class="inline-block h-2 w-2 rounded-full" :class="syncDot"></span>{{ syncLabel }}
          </span>
        </button>
        <span v-if="!boardVisible" class="mr-1 whitespace-nowrap font-display text-[22px] font-bold tabular-nums" aria-hidden="true">
          <span class="t1-light">{{ fmt(totals.home) }}</span><span class="mx-0.5 text-white/40">–</span><span class="t2-light">{{ fmt(totals.away) }}</span>
        </span>
        <button type="button" class="icon-btn" aria-label="Share this scorecard" @click="openShare"><span v-html="icon('share')"></span></button>
        <button v-if="canEdit || card.canLock" type="button" class="icon-btn" aria-label="Scorecard settings" @click="openSettings"><span v-html="icon('settings')"></span></button>
      </div>
    </header>

    <section ref="board" class="bg-ink text-white" aria-label="Meet score">
      <div class="mx-auto max-w-xl px-5 pb-5 pt-1">
        <div class="grid grid-cols-2 items-end">
          <div>
            <span class="team-chip t1-fill">{{ teamName(0) }}</span>
            <p class="score mt-2" :key="'h' + fmt(totals.home)" :class="{ tick: ticked }">{{ fmt(totals.home) }}</p>
          </div>
          <div class="text-right">
            <span class="team-chip t2-fill">{{ teamName(1) }}</span>
            <p class="score mt-2" :key="'a' + fmt(totals.away)" :class="{ tick: ticked }">{{ fmt(totals.away) }}</p>
          </div>
        </div>
        <div class="mt-4 flex h-2.5 overflow-hidden rounded-full bg-white/15" aria-hidden="true">
          <template v-if="totals.home + totals.away > 0">
            <div class="t1-bg transition-[width] duration-500" :style="{ width: homeShare + '%' }"></div>
            <div class="t2-bg flex-1"></div>
          </template>
        </div>
        <p class="mt-3 text-[16px] font-medium" aria-live="polite">{{ leader }}</p>
        <p class="text-[14px] text-white/60">{{ totals.scored }} of {{ card.events.length }} events scored</p>
      </div>
      <div class="rope"></div>
    </section>

    <div v-if="card.locked" class="mx-auto mt-4 max-w-xl px-4 sm:px-0">
      <div class="rounded-xl border border-rope bg-white px-4 py-3">
        <p class="flex gap-2 text-[15px] leading-relaxed">
          <span class="mt-0.5 shrink-0" v-html="icon('lock')"></span>
          <span><span class="font-semibold">Locked.</span> {{ card.canLock ? 'Nobody can change places until you unlock it.' : 'The organizer locked this scorecard, so places can’t be changed.' }}</span>
        </p>
        <button v-if="card.canLock" type="button" class="btn-quiet -ml-2 mt-1" :disabled="locking" @click="setLock(false)"><span v-html="icon('unlock')"></span>Unlock</button>
        <a v-else-if="session.googleReady && !session.signedIn" :href="signInUrl" class="btn-quiet -ml-2 mt-1"><span v-html="icon('login')"></span>Organizer sign-in</a>
      </div>
    </div>
    <div v-else-if="!canEdit" class="mx-auto mt-4 max-w-xl px-4 sm:px-0">
      <div class="rounded-xl border border-rope bg-white px-4 py-3">
        <p class="text-[15px] leading-relaxed"><span class="font-semibold">You’re following this meet.</span> The score updates on its own. Scorers enter places with the scorer link.</p>
        <a v-if="session.googleReady && !session.signedIn" :href="signInUrl" class="btn-quiet -ml-2 mt-1"><span v-html="icon('login')"></span>Organizer sign-in</a>
      </div>
    </div>

    <ol class="mx-auto mt-4 max-w-xl border-y border-rope bg-white sm:overflow-hidden sm:rounded-2xl sm:border">
      <li v-for="(ev, i) in card.events" :key="ev.id" :id="'event-' + ev.id" class="scroll-mt-[76px] border-b border-rope last:border-b-0">
        <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left"
                :aria-expanded="openId === ev.id ? 'true' : 'false'" :aria-controls="'body-' + ev.id" @click="toggle(ev.id)">
          <span class="w-6 shrink-0 font-display text-[18px] font-semibold tabular-nums text-ink-soft">{{ i + 1 }}</span>
          <span class="min-w-0 flex-1">
            <span class="flex items-center gap-2">
              <span class="truncate font-display text-[21px] font-semibold leading-tight">{{ ev.name }}</span>
              <span v-if="ev.relay" class="tag">Relay</span>
            </span>
            <span class="mt-1.5 flex gap-1" aria-hidden="true">
              <span v-for="(v, s) in ev.places" :key="s" class="chip" :class="chipClass(ev, s)"></span>
            </span>
          </span>
          <span v-if="needsAttention(ev)" class="text-warn" title="Needs attention" v-html="icon('alert')"></span>
          <span v-if="scores[ev.id].entered" class="whitespace-nowrap font-display text-[21px] font-bold tabular-nums">
            <span class="t1-text">{{ fmt(scores[ev.id].home) }}</span><span class="mx-0.5 text-ink-soft/50">–</span><span class="t2-text">{{ fmt(scores[ev.id].away) }}</span>
          </span>
          <span class="text-ink-soft transition-transform duration-200" :class="{ 'rotate-180': openId === ev.id }" v-html="icon('chevron')"></span>
        </button>

        <div v-if="openId === ev.id" :id="'body-' + ev.id" class="border-t border-rope bg-deck/50 px-3 pb-4 pt-2">
          <template v-for="(v, s) in displayPlaces(ev)" :key="s">
            <div class="flex items-center gap-2 py-1">
              <div class="w-12 shrink-0 pl-1">
                <span class="block font-display text-[20px] font-semibold leading-none">{{ ordinal(s + 1) }}</span>
                <span v-if="rowNote(ev, s)" class="mt-0.5 block text-[12px] font-semibold leading-tight" :class="isOver(ev, s) ? 'text-warn' : 'text-ink-soft'">{{ rowNote(ev, s) }}</span>
              </div>
              <div class="seg flex-1" role="group" :aria-label="ordinal(s + 1) + ' place'">
                <button v-for="opt in options" :key="opt.value" type="button" class="seg-btn"
                        :class="['opt-' + opt.value, { 'is-over': v === opt.value && isOver(ev, s) }]"
                        :aria-pressed="v === opt.value ? 'true' : 'false'" :disabled="!canEdit" @click="pick(ev, s, opt.value)">{{ opt.label }}</button>
              </div>
              <div class="w-12 shrink-0 whitespace-nowrap text-right text-[15px] font-semibold tabular-nums" :class="pointsClass(ev, s)">{{ rowPoints(ev, s) }}</div>
            </div>

            <div v-for="g in tiesEndingAt(ev, s)" :key="'tie' + g.start" class="mb-2 ml-14 mt-1 rounded-xl border border-ink/15 bg-white p-3">
              <p class="text-[15px]"><span class="font-semibold">Tie for {{ ordinal(g.place) }}.</span> {{ fmt(g.each) }} points each.</p>
              <template v-if="canEdit">
                <p class="mt-1.5 text-[14px] text-ink-soft">{{ g.size === 2 ? 'Who tied?' : 'How many of the ' + g.size + ' tied swimmers were ' + teamName(0) + '?' }}</p>
                <div class="mt-2 flex flex-wrap gap-1.5">
                  <button v-for="h in splitOptions(g.size)" :key="h" type="button" class="pill"
                          :aria-pressed="g.home === h ? 'true' : 'false'" @click="setSplit(ev, g.start, h)">{{ splitLabel(h, g.size) }}</button>
                </div>
              </template>
              <p v-else class="mt-1 text-[14px] text-ink-soft">{{ g.home === null ? 'Waiting for the scorer to say who tied.' : splitLabel(g.home, g.size) }}</p>
            </div>
          </template>

          <ul v-if="canEdit && shownIssues(ev).length" class="mt-2 space-y-1.5">
            <li v-for="(it, k) in shownIssues(ev)" :key="k" class="flex gap-2 rounded-lg bg-warn-tint px-3 py-2 text-[14px] leading-snug text-warn">
              <span class="mt-px" v-html="icon('alert')"></span><span>{{ issueText(it) }}</span>
            </li>
          </ul>
          <p v-if="shortField(ev)" class="mt-2 px-1 text-[14px] text-ink-soft">{{ shortField(ev) }}</p>

          <div class="mt-3 flex items-center gap-1">
            <button v-if="canEdit && ev.places.length < MAX_PLACES" type="button" class="btn-quiet" @click="addPlace(ev)">
              <span v-html="icon('plus')"></span>Add a place
            </button>
            <button v-if="canEdit && scores[ev.id].entered" type="button" class="btn-quiet text-ink-soft" @click="askClear(ev)">Clear</button>
            <span class="flex-1"></span>
            <button type="button" class="btn-next" @click="nextEvent(i)">{{ i < card.events.length - 1 ? 'Next event' : 'Done' }}</button>
          </div>
        </div>
      </li>
    </ol>

    <p v-if="canEdit" class="mx-auto mt-5 max-w-xl px-5 text-[14px] leading-relaxed text-ink-soft">
      Tap a place again to clear it. For a tie, pick Tie on each tied place, like 4th and 5th for a tie for 4th. Pick DQ where a disqualified swimmer finished and everyone behind moves up. Only {{ card.limits ? card.limits.individual : 3 }} swimmers and {{ card.limits ? card.limits.relay : 2 }} relays per team can score in an event. Any more turn grey and get no points.
    </p>
  </div>

  <!-- Bottom sheets -->
  <transition name="fade">
    <div v-if="sheet" class="fixed inset-0 z-40 bg-ink/45" @click="closeSheet"></div>
  </transition>
  <transition name="sheet">
    <div v-if="sheet" class="fixed inset-x-0 bottom-0 z-50 mx-auto max-h-[90dvh] max-w-xl overflow-y-auto overscroll-contain rounded-t-2xl bg-white shadow-sheet"
         role="dialog" aria-modal="true" aria-labelledby="sheet-title">
      <div class="sticky top-0 z-10 bg-white px-5 pb-2 pt-2">
        <div class="mx-auto mb-2 h-1.5 w-10 rounded-full bg-rope" aria-hidden="true"></div>
        <div class="flex items-center">
          <h2 id="sheet-title" class="flex-1 font-display text-[26px] font-bold">{{ sheetTitle }}</h2>
          <button type="button" class="icon-btn-light -mr-2" aria-label="Close" @click="closeSheet"><span v-html="icon('x')"></span></button>
        </div>
      </div>

      <div class="px-5 pb-[calc(env(safe-area-inset-bottom)+22px)]">
        <!-- Share -->
        <template v-if="sheet === 'share'">
          <p class="text-[16px] leading-relaxed text-ink-soft">
            {{ scorerUrl ? 'Send the scorer link to the people entering places. Anyone with it can change the score, so share it only with your scorers.' : 'Anyone with this link can follow the score. Only scorers can change it.' }}
          </p>
          <div class="mx-auto mt-4 w-52 rounded-2xl border border-rope bg-white p-3 [&>svg]:h-auto [&>svg]:w-full" role="img"
               :aria-label="scorerUrl ? 'QR code for the scorer link' : 'QR code for the scorecard link'" v-html="qrSvg"></div>
          <p class="mt-2 text-center text-[14px] text-ink-soft">Scan with a phone camera to open</p>
          <label for="share-url" class="mt-5 block text-[16px] font-semibold">{{ scorerUrl ? 'Scorer link' : 'Link' }}</label>
          <input id="share-url" class="input mt-2 text-[15px]" :value="shareUrl" readonly @focus="$event.target.select()">
          <div class="mt-3 grid gap-2" :class="canShare ? 'grid-cols-2' : 'grid-cols-1'">
            <button type="button" class="btn-primary" @click="copyLink('main')"><span v-html="icon(copied === 'main' ? 'check' : 'copy')"></span>{{ copied === 'main' ? 'Copied' : 'Copy link' }}</button>
            <button v-if="canShare" type="button" class="btn-secondary" @click="nativeShare"><span v-html="icon('share')"></span>Share</button>
          </div>

          <div v-if="scorerUrl" class="mt-7 border-t border-rope pt-5">
            <label for="view-url" class="block text-[16px] font-semibold">View-only link</label>
            <p class="mt-1 text-[15px] leading-relaxed text-ink-soft">For parents and fans. They can follow the score but can’t change it.</p>
            <input id="view-url" class="input mt-2 text-[15px]" :value="viewUrl" readonly @focus="$event.target.select()">
            <button type="button" class="btn-secondary mt-3 w-full" @click="copyLink('view')"><span v-html="icon(copied === 'view' ? 'check' : 'copy')"></span>{{ copied === 'view' ? 'Copied' : 'Copy view-only link' }}</button>
          </div>
        </template>

        <!-- Rename -->
        <form v-else-if="sheet === 'rename'" @submit.prevent="saveName" novalidate>
          <label for="rename-input" class="block text-[16px] font-semibold">Scorecard name</label>
          <input id="rename-input" v-model="draftName" class="input mt-2" maxlength="60" autocomplete="off" enterkeyhint="done">
          <p v-if="formError" class="mt-2 text-[15px] text-danger" role="alert">{{ formError }}</p>
          <button type="submit" class="btn-primary mt-4 w-full">Save name</button>
        </form>

        <!-- Scorecard settings -->
        <template v-else-if="sheet === 'settings'">
          <div v-if="card.canLock" class="mb-4 rounded-xl border border-rope p-3">
            <p class="text-[15px] leading-relaxed">
              {{ card.locked ? 'This scorecard is locked. Nobody can change places until you unlock it.' : 'Lock the scorecard when the meet is over. Nobody, including scorers with the link, can change places until you unlock it.' }}
            </p>
            <button type="button" class="mt-3 w-full" :class="card.locked ? 'btn-primary' : 'btn-secondary'" :disabled="locking" @click="setLock(!card.locked)">
              <span v-html="icon(card.locked ? 'unlock' : 'lock')"></span>{{ card.locked ? 'Unlock scorecard' : 'Lock scorecard' }}
            </button>
          </div>

          <template v-if="canEdit">
          <div class="grid grid-cols-3 gap-1 rounded-xl bg-deck p-1" role="tablist">
            <button type="button" role="tab" class="tab" :aria-selected="settingsTab === 'teams' ? 'true' : 'false'" @click="settingsTab = 'teams'; formError = ''">Teams</button>
            <button type="button" role="tab" class="tab" :aria-selected="settingsTab === 'events' ? 'true' : 'false'" @click="settingsTab = 'events'; formError = ''">Events</button>
            <button type="button" role="tab" class="tab" :aria-selected="settingsTab === 'points' ? 'true' : 'false'" @click="settingsTab = 'points'; formError = ''">Points</button>
          </div>

          <div v-if="settingsTab === 'teams'" class="mt-4 space-y-4">
            <div v-for="(t, i) in draftTeams" :key="i" class="rounded-xl border border-rope p-3">
              <label :for="'team-name-' + i" class="block text-[15px] font-semibold">{{ i === 0 ? 'Your team' : 'Opponent' }}</label>
              <div class="mt-2 flex items-center gap-2">
                <input :id="'team-name-' + i" v-model="t.name" class="input h-11 flex-1 text-[16px]" maxlength="10" autocomplete="off">
                <span class="team-chip shrink-0" :style="chipStyle(t.color)">{{ t.name || '?' }}</span>
              </div>
              <p class="mt-3 text-[14px] text-ink-soft">Color</p>
              <div class="mt-1.5 flex flex-wrap items-center gap-2">
                <button v-for="c in SWATCHES" :key="c" type="button" class="swatch" :style="{ background: c }"
                        :aria-pressed="t.color.toUpperCase() === c ? 'true' : 'false'" :aria-label="'Use color ' + c" @click="pickSwatch(i, c)"></button>
                <label class="swatch-custom" :aria-label="'Pick any color for ' + (t.name || 'this team')">
                  <input type="color" class="sr-only" :value="t.color" @input="pickSwatch(i, $event.target.value.toUpperCase())">
                  <span v-html="icon('pencil')"></span>
                </label>
              </div>
            </div>
            <p v-if="formError" class="text-[15px] text-danger" role="alert">{{ formError }}</p>
            <div class="sticky bottom-0 -mx-5 border-t border-rope bg-white px-5 pb-1 pt-3">
              <button type="button" class="btn-primary w-full" @click="saveTeams">Save teams</button>
            </div>
          </div>

          <div v-else-if="settingsTab === 'events'" class="mt-4">
            <ul class="space-y-2">
              <li v-for="(e, k) in draftEvents" :key="e.key" class="rounded-xl border border-rope p-2.5">
                <div class="flex items-center gap-1">
                  <input v-model="e.name" class="input h-11 flex-1 text-[16px]" maxlength="40" :aria-label="'Event ' + (k + 1) + ' name'">
                  <button type="button" class="icon-btn-light" :disabled="k === 0" aria-label="Move up" @click="moveEvent(k, -1)"><span v-html="icon('up')"></span></button>
                  <button type="button" class="icon-btn-light" :disabled="k === draftEvents.length - 1" aria-label="Move down" @click="moveEvent(k, 1)"><span v-html="icon('down')"></span></button>
                  <button type="button" class="icon-btn-light text-danger" :aria-label="'Remove ' + (e.name || 'event')" @click="removeEvent(k)"><span v-html="icon('trash')"></span></button>
                </div>
                <label class="mt-2 flex items-center gap-2 pl-1 text-[15px]">
                  <input v-model="e.relay" type="checkbox" class="switch"> Relay scoring
                </label>
              </li>
            </ul>
            <button type="button" class="btn-quiet mt-2" @click="addEvent"><span v-html="icon('plus')"></span>Add an event</button>
            <p v-if="removedWithPlaces" class="mt-3 rounded-lg bg-warn-tint px-3 py-2 text-[14px] text-warn">
              {{ removedWithPlaces === 1 ? 'An event you removed has places entered.' : removedWithPlaces + ' events you removed have places entered.' }} Saving deletes those places.
            </p>
            <p v-if="formError" class="mt-3 text-[15px] text-danger" role="alert">{{ formError }}</p>
            <div class="sticky bottom-0 -mx-5 mt-3 border-t border-rope bg-white px-5 pb-1 pt-3">
              <button type="button" class="btn-primary w-full" @click="saveEvents">Save events</button>
            </div>
          </div>

          <div v-else class="mt-4">
            <p class="text-[16px] font-semibold">Scorers per team</p>
            <p class="mt-1 text-[14px] leading-relaxed text-ink-soft">How many from each team can score in an event. Anyone past that gets no points and the next finisher moves up. Use 0 for no limit.</p>
            <div class="mt-2 grid grid-cols-2 gap-3">
              <label class="text-[14px] font-semibold">Individual
                <input v-model="draftLimits.individual" class="input mt-1 h-11 text-center" inputmode="numeric">
              </label>
              <label class="text-[14px] font-semibold">Relay
                <input v-model="draftLimits.relay" class="input mt-1 h-11 text-center" inputmode="numeric">
              </label>
            </div>
            <p class="mt-6 text-[16px] font-semibold">Points for each place</p>
            <p class="mt-1 text-[14px] text-ink-soft">Diving scores as an individual event. Blank means 0.</p>
            <div class="mt-3 grid grid-cols-[3.25rem_1fr_1fr] items-center gap-x-3 gap-y-2">
              <span></span><span class="text-[14px] font-semibold">Individual</span><span class="text-[14px] font-semibold">Relay</span>
              <template v-for="k in POINT_ROWS" :key="k">
                <span class="font-display text-[19px] font-semibold">{{ ordinal(k) }}</span>
                <input v-model="draftPoints.individual[k - 1]" class="input h-11 text-center" inputmode="decimal" :aria-label="ordinal(k) + ' place, individual points'">
                <input v-model="draftPoints.relay[k - 1]" class="input h-11 text-center" inputmode="decimal" :aria-label="ordinal(k) + ' place, relay points'">
              </template>
            </div>
            <button type="button" class="btn-quiet mt-2" @click="resetPoints">Use 6-4-3-2-1 and 8-4-2</button>
            <p v-if="formError" class="mt-3 text-[15px] text-danger" role="alert">{{ formError }}</p>
            <div class="sticky bottom-0 -mx-5 mt-3 border-t border-rope bg-white px-5 pb-1 pt-3">
              <button type="button" class="btn-primary w-full" @click="savePoints">Save points</button>
            </div>
          </div>
          </template>
          <p v-else class="text-[15px] text-ink-soft">Unlock the scorecard to change teams, events or points.</p>
        </template>

        <!-- Clear an event -->
        <template v-else-if="sheet === 'clear' && clearTarget">
          <p class="text-[16px] leading-relaxed">This removes every place entered for {{ clearTarget.name }}, for everyone using this scorecard.</p>
          <div class="mt-5 grid grid-cols-2 gap-2">
            <button type="button" class="btn-secondary" @click="closeSheet">Keep places</button>
            <button type="button" class="btn-danger" @click="confirmClear">Clear event</button>
          </div>
        </template>
      </div>
    </div>
  </transition>

  <transition name="fade">
    <div v-if="toast" class="fixed bottom-[calc(env(safe-area-inset-bottom)+18px)] left-1/2 z-[60] w-max max-w-[92vw] -translate-x-1/2 rounded-2xl bg-ink px-4 py-2.5 text-center text-[15px] font-medium text-white shadow-lg" role="status">{{ toast }}</div>
  </transition>
</div>
</div>
</body>
</html>
