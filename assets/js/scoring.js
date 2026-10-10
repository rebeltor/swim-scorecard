/*
 * Swim Scorecard scoring rules.
 *
 * Each event lists who finished in each place, in order:
 *   'H' first team (JD), 'A' second team (Opp), 'T' Tie, 'DQ' disqualified, '' nothing entered.
 *
 * - Only a set number of swimmers (or relays) per team can score in an event: 3 and 2 by
 *   default. A team's extra finishers get no points and don't take a place, so the next
 *   eligible finisher moves up.
 * - DQs and empty places are skipped the same way.
 * - Places marked Tie next to each other form one tie. The tied swimmers share the points for
 *   the places they cover. A two-way tie is one swimmer from each team unless someone says
 *   otherwise. For three or more, someone has to pick how many were on the first team.
 * - Places nobody finished in are never awarded.
 */
(function (root) {
  'use strict';

  function ordinal(n) {
    var mod100 = n % 100;
    if (mod100 >= 11 && mod100 <= 13) return n + 'th';
    return n + ({ 1: 'st', 2: 'nd', 3: 'rd' }[n % 10] || 'th');
  }

  function fmt(n) {
    var r = Math.round(n * 100) / 100;
    if (Object.is(r, -0)) r = 0;
    return Number.isInteger(r) ? String(r) : r.toFixed(2).replace(/0$/, '');
  }

  function splitHome(event, start) {
    var splits = event.splits || {};
    var value = splits[start];
    return value === undefined || value === null ? null : Number(value);
  }

  /**
   * limits: { individual: 3, relay: 2 }. 0 or missing means no limit.
   */
  function scoreEvent(event, points, limits) {
    var table = (event.relay ? points.relay : points.individual) || [];
    var pointsFor = function (index) { return Number(table[index]) || 0; };
    var scoringPlaces = table.filter(function (v) { return Number(v) > 0; }).length;
    var cap = limits ? Math.max(0, Math.floor(Number(event.relay ? limits.relay : limits.individual) || 0)) : 0;
    var places = event.places || [];

    var finishers = [];
    var dqs = 0;
    places.forEach(function (value, slot) {
      if (value === 'H' || value === 'A' || value === 'T') finishers.push({ slot: slot, value: value });
      else if (value === 'DQ') dqs++;
    });

    var slots = places.map(function (value) { return { value: value, place: null, points: null }; });
    var groups = [];
    var issues = [];
    var home = 0;
    var away = 0;
    var scored = { H: 0, A: 0 };   // swimmers from each team who score
    var seen = { H: 0, A: 0 };     // swimmers from each team who finished, scoring or not
    var room = function (team) { return cap ? Math.max(0, cap - scored[team]) : Infinity; };
    var place = 0;                 // scoring places handed out so far
    var overLimit = 0;

    for (var k = 0; k < finishers.length;) {
      var entry = finishers[k];
      if (entry.value !== 'T') {
        var info = slots[entry.slot];
        seen[entry.value]++;
        if (room(entry.value) === 0) {
          info.over = true;
          info.points = 0;
          overLimit++;
          issues.push({ type: 'over', slot: entry.slot, team: entry.value, nth: seen[entry.value], cap: cap, matters: place < scoringPlaces });
          info.matters = place < scoringPlaces;
          k++;
          continue;
        }
        var p = pointsFor(place);
        if (entry.value === 'H') home += p; else away += p;
        scored[entry.value]++;
        info.place = place + 1;
        info.points = p;
        place++;
        k++;
        continue;
      }

      var j = k;
      while (j < finishers.length && finishers[j].value === 'T') j++;
      var members = finishers.slice(k, j).map(function (f) { return f.slot; });
      var size = members.length;
      members.forEach(function (slot) { slots[slot].place = place + 1; });

      if (size < 2) {
        slots[members[0]].lone = true;
        issues.push({ type: 'lone', slot: members[0], place: place + 1 });
        place++;
        k = j;
        continue;
      }

      var homeCount = splitHome(event, members[0]);
      if (homeCount === null || !Number.isInteger(homeCount) || homeCount < 0 || homeCount > size) {
        homeCount = size === 2 ? 1 : null;
      }
      var group = {
        start: members[0],
        end: members[members.length - 1],
        members: members,
        size: size,
        place: place + 1,
        home: homeCount,
        overHome: 0,
        overAway: 0,
        index: groups.length
      };
      var q, sum = 0, each;
      if (homeCount === null) {
        for (q = place; q < place + size; q++) sum += pointsFor(q);
        each = sum / size;
        issues.push({ type: 'split', slot: members[0], place: place + 1, size: size });
        place += size;
      } else {
        var homeScoring = Math.min(homeCount, room('H'));
        var awayScoring = Math.min(size - homeCount, room('A'));
        var scoring = homeScoring + awayScoring;
        for (q = place; q < place + scoring; q++) sum += pointsFor(q);
        each = scoring ? sum / scoring : 0;
        home += each * homeScoring;
        away += each * awayScoring;
        seen.H += homeCount;
        seen.A += size - homeCount;
        scored.H += homeScoring;
        scored.A += awayScoring;
        group.overHome = homeCount - homeScoring;
        group.overAway = size - homeCount - awayScoring;
        if (scoring < size) {
          overLimit += size - scoring;
          issues.push({ type: 'tieOver', slot: members[0], place: place + 1, overHome: group.overHome, overAway: group.overAway, cap: cap });
        }
        place += scoring;
      }
      group.each = each;
      members.forEach(function (slot) { slots[slot].points = each; slots[slot].group = group.index; });
      groups.push(group);
      k = j;
    }

    var lastFinisher = finishers.length ? finishers[finishers.length - 1].slot : -1;
    places.forEach(function (value, slot) {
      if (value === '' && slot < lastFinisher) issues.push({ type: 'gap', slot: slot });
    });

    return {
      home: home,
      away: away,
      slots: slots,
      groups: groups,
      issues: issues,
      finishers: finishers.length,
      entered: finishers.length + dqs,
      dqs: dqs,
      overLimit: overLimit,
      placesScored: Math.min(place, scoringPlaces),
      scoringPlaces: scoringPlaces,
      cap: cap
    };
  }

  var api = { scoreEvent: scoreEvent, ordinal: ordinal, fmt: fmt };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.SwimScoring = api;
})(this);
