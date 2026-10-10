<?php
declare(strict_types=1);

/**
 * The scorecard document and the changes people can make to it.
 *
 * Each event stores one value per place, in finishing order:
 *   'H' first team (JD by default), 'A' second team (Opp by default),
 *   'T' Tie, 'DQ' disqualified, '' not entered.
 * "splits" holds, for a tie that starts at a given place, how many of the tied
 * swimmers were Home. Points are worked out in the browser (assets/js/scoring.js).
 *
 * "editKey" is the secret in the scorer link. Anyone who has it can enter places;
 * everyone else can only watch. It is never sent to people who don't have it.
 * "teams" holds the two team names and colors, "limits" how many from each team can
 * score per event, and "locked" stops all changes until the organizer who created
 * the scorecard ("createdBy") unlocks it.
 */
final class Scorecard
{
    public const MAX_EVENTS = 30;
    public const MAX_PLACES = 12;
    public const POINT_ROWS = 8;
    public const MAX_CHANGES = 50;

    private const VALUES = ['', 'H', 'A', 'T', 'DQ'];

    private const DEFAULT_EVENTS = [
        ['200 Medley Relay', true],
        ['200 Free', false],
        ['200 IM', false],
        ['50 Free', false],
        ['Diving', false],
        ['100 Fly', false],
        ['100 Free', false],
        ['500 Free', false],
        ['200 Free Relay', true],
        ['100 Back', false],
        ['100 Breast', false],
        ['400 Free Relay', true],
    ];

    private const DEFAULT_POINTS = [
        'individual' => [6, 4, 3, 2, 1, 0, 0, 0],
        'relay' => [8, 4, 2, 0, 0, 0, 0, 0],
    ];

    public static function create(string $id, mixed $name, string $editKey, string $createdBy, array $config): array
    {
        $now = time();
        $events = [];
        foreach (self::DEFAULT_EVENTS as $i => [$eventName, $relay]) {
            $events[] = self::newEvent('e' . ($i + 1), $eventName, $relay);
        }
        return self::normalize(self::withDefaults([
            'id' => $id,
            'name' => self::cleanText($name, 60, 'scorecard name'),
            'editKey' => $editKey,
            'createdBy' => strtolower($createdBy),
            'version' => 1,
            'created' => $now,
            'updated' => $now,
            'locked' => false,
            'points' => self::DEFAULT_POINTS,
            'events' => $events,
        ], $config));
    }

    /**
     * Fills in team names, colors and scorer limits for scorecards that don't have them yet.
     */
    public static function withDefaults(array $card, array $config): array
    {
        if (!isset($card['teams']) || !is_array($card['teams']) || count($card['teams']) !== 2) {
            $names = (array) ($config['team_names'] ?? []);
            $colors = (array) ($config['team_colors'] ?? []);
            $fallback = [['JD', '#C8102E'], ['Opp', '#F58220']];
            $card['teams'] = [];
            for ($i = 0; $i < 2; $i++) {
                $name = is_string($names[$i] ?? null) && trim($names[$i]) !== '' ? trim($names[$i]) : $fallback[$i][0];
                $color = is_string($colors[$i] ?? null) && preg_match('/^#[0-9a-fA-F]{6}$/', $colors[$i]) === 1 ? strtoupper($colors[$i]) : $fallback[$i][1];
                $card['teams'][] = ['name' => self::shorten($name, 10), 'color' => $color];
            }
        }
        if (!isset($card['limits']) || !is_array($card['limits'])) {
            $limits = (array) ($config['scorers_per_team'] ?? []);
            $card['limits'] = [
                'individual' => max(0, min(self::MAX_PLACES, (int) ($limits['individual'] ?? 3))),
                'relay' => max(0, min(self::MAX_PLACES, (int) ($limits['relay'] ?? 2))),
            ];
        }
        $card['locked'] = ($card['locked'] ?? false) === true;
        return $card;
    }

    /** Locks or unlocks the scorecard. Who may do this is checked in api.php. */
    public static function setLocked(array $card, bool $locked): array
    {
        $card['locked'] = $locked;
        $card['lockedAt'] = $locked ? time() : null;
        $card['version'] = (int) ($card['version'] ?? 0) + 1;
        $card['updated'] = time();
        return self::normalize($card);
    }

    /**
     * Applies a batch of changes in order. Any invalid change rejects the whole batch.
     */
    public static function applyChanges(array $card, mixed $changes): array
    {
        if (!is_array($changes) || !array_is_list($changes) || $changes === [] || count($changes) > self::MAX_CHANGES) {
            throw new ApiError(400, 'Send between 1 and ' . self::MAX_CHANGES . ' changes at a time.');
        }
        foreach ($changes as $change) {
            if (!is_array($change)) {
                throw new ApiError(400, 'Each change must be an object.');
            }
            $card = self::applyChange($card, $change);
        }
        $card['version'] = (int) ($card['version'] ?? 0) + 1;
        $card['updated'] = time();
        return self::normalize($card);
    }

    /**
     * Makes sure "splits" is written as a JSON object even when it is empty.
     */
    public static function normalize(array $card): array
    {
        foreach ($card['events'] as &$event) {
            $event['places'] = array_values($event['places']);
            $event['splits'] = (object) ($event['splits'] ?? []);
        }
        unset($event);
        return $card;
    }

    /**
     * The scorecard as sent to a browser. The scorer key is only included for people
     * who can already edit, so the scorer link can be shared from the app.
     */
    public static function forOutput(array $card, bool $isScorer, bool $canLock): array
    {
        $card = self::normalize($card);
        if (!$isScorer) {
            unset($card['editKey']);
        }
        unset($card['createdBy']);
        $card['isScorer'] = $isScorer;
        $card['canEdit'] = $isScorer && !$card['locked'];
        $card['canLock'] = $canLock;
        return $card;
    }

    private static function applyChange(array $card, array $change): array
    {
        switch ($change['op'] ?? null) {
            case 'set':
                $i = self::eventIndex($card, $change);
                $count = count($card['events'][$i]['places']);
                $slot = self::int($change['slot'] ?? null, 0, min($count, self::MAX_PLACES - 1), 'place');
                if ($slot === $count) {
                    $card['events'][$i]['places'][] = '';
                }
                $value = $change['value'] ?? null;
                if (!is_string($value) || !in_array($value, self::VALUES, true)) {
                    throw new ApiError(400, 'A place can only be Home, Away, Tie, DQ or empty.');
                }
                $card['events'][$i]['places'][$slot] = $value;
                return $card;

            case 'split':
                $i = self::eventIndex($card, $change);
                $slot = self::int($change['slot'] ?? null, 0, count($card['events'][$i]['places']) - 1, 'place');
                $splits = (array) ($card['events'][$i]['splits'] ?? []);
                if (($change['home'] ?? null) === null) {
                    unset($splits[$slot]);
                } else {
                    $splits[$slot] = self::int($change['home'], 0, self::MAX_PLACES, 'tie choice');
                }
                $card['events'][$i]['splits'] = $splits;
                return $card;

            case 'slots':
                $i = self::eventIndex($card, $change);
                $count = self::int($change['count'] ?? null, 1, self::MAX_PLACES, 'number of places');
                $places = $card['events'][$i]['places'];
                if ($count < count($places)) {
                    foreach (array_slice($places, $count) as $value) {
                        if ($value !== '') {
                            throw new ApiError(409, 'Clear the last place before removing it.');
                        }
                    }
                    $places = array_slice($places, 0, $count);
                } else {
                    $places = array_pad($places, $count, '');
                }
                $card['events'][$i]['places'] = $places;
                return $card;

            case 'clear':
                $i = self::eventIndex($card, $change);
                $card['events'][$i]['places'] = array_fill(0, count($card['events'][$i]['places']), '');
                $card['events'][$i]['splits'] = [];
                return $card;

            case 'rename':
                $card['name'] = self::cleanText($change['name'] ?? null, 60, 'scorecard name');
                return $card;

            case 'points':
                $card['points'] = [
                    'individual' => self::pointList($change['individual'] ?? null),
                    'relay' => self::pointList($change['relay'] ?? null),
                ];
                return $card;

            case 'events':
                $card['events'] = self::rebuildEvents($card['events'], $change['events'] ?? null);
                return $card;

            case 'teams':
                $teams = $change['teams'] ?? null;
                if (!is_array($teams) || !array_is_list($teams) || count($teams) !== 2) {
                    throw new ApiError(400, 'Send both teams.');
                }
                $card['teams'] = [];
                foreach ($teams as $team) {
                    $color = is_array($team) ? ($team['color'] ?? null) : null;
                    if (!is_string($color) || preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
                        throw new ApiError(400, 'Team colors must look like #C8102E.');
                    }
                    $card['teams'][] = [
                        'name' => self::cleanText($team['name'] ?? null, 10, 'team name'),
                        'color' => strtoupper($color),
                    ];
                }
                return $card;

            case 'limits':
                $card['limits'] = [
                    'individual' => self::int($change['individual'] ?? null, 0, self::MAX_PLACES, 'number of scorers'),
                    'relay' => self::int($change['relay'] ?? null, 0, self::MAX_PLACES, 'number of scoring relays'),
                ];
                return $card;
        }
        throw new ApiError(400, 'Unknown change.');
    }

    private static function newEvent(string $id, string $name, bool $relay): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'relay' => $relay,
            'places' => array_fill(0, $relay ? 4 : 6, ''),
            'splits' => [],
        ];
    }

    /**
     * Replaces the event list. Events that keep their id keep their places.
     */
    private static function rebuildEvents(array $current, mixed $list): array
    {
        if (!is_array($list) || !array_is_list($list) || $list === [] || count($list) > self::MAX_EVENTS) {
            throw new ApiError(400, 'A scorecard needs between 1 and ' . self::MAX_EVENTS . ' events.');
        }
        $existing = [];
        foreach ($current as $event) {
            $existing[$event['id']] = $event;
        }
        $result = [];
        $used = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                throw new ApiError(400, 'Each event must be an object.');
            }
            $name = self::cleanText($item['name'] ?? null, 40, 'event name');
            $relay = ($item['relay'] ?? false) === true;
            $id = is_string($item['id'] ?? null) ? $item['id'] : '';
            if ($id === '' || preg_match('/^[A-Za-z0-9_-]{1,16}$/', $id) !== 1 || isset($used[$id])) {
                $id = self::freshEventId($existing, $used);
            }
            if (isset($existing[$id])) {
                $event = $existing[$id];
                $event['name'] = $name;
                $event['relay'] = $relay;
            } else {
                $event = self::newEvent($id, $name, $relay);
            }
            $used[$id] = true;
            $result[] = $event;
        }
        return $result;
    }

    private static function freshEventId(array $existing, array $used): string
    {
        do {
            $id = 'e' . bin2hex(random_bytes(4));
        } while (isset($existing[$id]) || isset($used[$id]));
        return $id;
    }

    private static function pointList(mixed $list): array
    {
        if (!is_array($list) || !array_is_list($list) || count($list) > self::POINT_ROWS) {
            throw new ApiError(400, 'Points must be a list of up to ' . self::POINT_ROWS . ' numbers.');
        }
        $result = [];
        foreach ($list as $value) {
            if ($value === null || $value === '') {
                $result[] = 0;
                continue;
            }
            if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
                throw new ApiError(400, 'Points must be numbers.');
            }
            $number = round((float) $value, 2);
            if ($number < 0 || $number > 100) {
                throw new ApiError(400, 'Points must be between 0 and 100.');
            }
            $result[] = floor($number) === $number ? (int) $number : $number;
        }
        return array_pad($result, self::POINT_ROWS, 0);
    }

    private static function eventIndex(array $card, array $change): int
    {
        $id = $change['event'] ?? null;
        foreach ($card['events'] as $i => $event) {
            if ($event['id'] === $id) {
                return $i;
            }
        }
        throw new ApiError(409, 'That event was removed by someone else. The scorecard has been reloaded.');
    }

    private static function int(mixed $value, int $min, int $max, string $label): int
    {
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new ApiError(400, "That $label is out of range.");
        }
        return $value;
    }

    private static function shorten(string $value, int $max): string
    {
        preg_match_all('/./su', $value, $chars);
        return implode('', array_slice($chars[0], 0, $max));
    }

    private static function cleanText(mixed $value, int $max, string $label): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            throw new ApiError(400, "The $label must be text.");
        }
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
        if ($value === '') {
            throw new ApiError(400, "Enter a $label.");
        }
        if (preg_match_all('/./su', $value) > $max) {
            throw new ApiError(400, "Keep the $label to $max characters or fewer.");
        }
        return $value;
    }
}
