<?php
declare(strict_types=1);

/**
 * Saves each scorecard as one JSON file in the data folder.
 *
 * Writes go to a temporary file that is renamed over the old one, so a reader never
 * sees a half-written file. Changes to one scorecard are serialized with an
 * exclusive lock on a matching .lock file, so two people entering places at the
 * same moment never overwrite each other.
 */
final class Store
{
    // No 0/O, 1/l/I, so a link read aloud or typed by hand is less error-prone.
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    private const ID_LENGTH = 12;

    public function __construct(private string $dir)
    {
    }

    public static function isValidId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9]{' . self::ID_LENGTH . '}$/', $id) === 1;
    }

    public function isWritable(): bool
    {
        return is_dir($this->dir) && is_writable($this->dir);
    }

    public function checkWritable(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
            throw new ApiError(500, 'The data folder is missing and could not be created. Check data_dir in config.php.');
        }
        if (!is_writable($this->dir)) {
            throw new ApiError(500, 'The web server cannot save to the data folder. See step 3 in INSTALL.md.');
        }
    }

    public function count(): int
    {
        $files = glob($this->dir . '/*.json', GLOB_NOSORT);
        return $files === false ? 0 : count($files);
    }

    /**
     * The most recently changed scorecards, newest first. Only names and times, never scorer keys.
     */
    public function list(int $limit): array
    {
        $times = [];
        foreach (glob($this->dir . '/*.json', GLOB_NOSORT) ?: [] as $file) {
            $time = @filemtime($file);
            if ($time !== false) {
                $times[$file] = $time;
            }
        }
        arsort($times);
        $cards = [];
        foreach (array_slice(array_keys($times), 0, $limit) as $file) {
            $raw = @file_get_contents($file);
            $card = $raw === false ? null : json_decode($raw, true);
            if (!is_array($card) || !isset($card['id'], $card['name']) || !self::isValidId((string) $card['id'])) {
                continue;
            }
            $cards[] = [
                'id' => (string) $card['id'],
                'name' => (string) $card['name'],
                'updated' => (int) ($card['updated'] ?? $times[$file]),
                'locked' => ($card['locked'] ?? false) === true,
            ];
        }
        return $cards;
    }

    public function newId(): string
    {
        $last = strlen(self::ALPHABET) - 1;
        do {
            $id = '';
            for ($i = 0; $i < self::ID_LENGTH; $i++) {
                $id .= self::ALPHABET[random_int(0, $last)];
            }
        } while (is_file($this->path($id)));
        return $id;
    }

    public function create(array $card): void
    {
        $handle = @fopen($this->path($card['id']), 'x');
        if ($handle === false) {
            throw new ApiError(500, 'The new scorecard could not be saved. Check the data folder permissions.');
        }
        fwrite($handle, self::encode($card));
        fclose($handle);
    }

    public function read(string $id): ?array
    {
        $path = $this->path($id);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        $card = $raw === false ? null : json_decode($raw, true);
        if (!is_array($card)) {
            throw new ApiError(500, 'This scorecard file could not be read.');
        }
        return $card;
    }

    /**
     * Applies $change to the saved scorecard while holding its lock, then saves the result.
     */
    public function update(string $id, callable $change): array
    {
        $this->path($id); // validates the id before touching the file system
        $lock = @fopen($this->dir . '/' . $id . '.lock', 'c');
        if ($lock === false) {
            throw new ApiError(500, 'The scorecard could not be locked for saving. Check the data folder permissions.');
        }
        try {
            flock($lock, LOCK_EX);
            $card = $this->read($id);
            if ($card === null) {
                throw new ApiError(404, 'This scorecard no longer exists.');
            }
            $card = $change($card);
            $this->replace($id, $card);
            return $card;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function replace(string $id, array $card): void
    {
        $tmp = $this->dir . '/' . $id . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, self::encode($card)) === false || !rename($tmp, $this->path($id))) {
            @unlink($tmp);
            throw new ApiError(500, 'The scorecard could not be saved. Check the data folder permissions.');
        }
    }

    private function path(string $id): string
    {
        if (!self::isValidId($id)) {
            throw new ApiError(400, 'That scorecard link is not valid.');
        }
        return $this->dir . '/' . $id . '.json';
    }

    public static function encode(array $card): string
    {
        return json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
