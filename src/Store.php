<?php
declare(strict_types=1);

namespace KidsPoint;

/**
 * data/kids-point.json の読み書きを1か所にまとめる。
 * - kids-point.lock を flock(LOCK_EX) で排他し、その中で 読む→変える→一時ファイルに書く→rename する
 * - その日の最初の書き込みの前に、置き換える前の状態を backup/YYYY-MM-DD.json に写す
 * - 60日より古いバックアップは消す
 */
final class Store
{
    private string $dataDir;
    private string $dataFile;
    private string $lockFile;
    private string $backupDir;

    public function __construct(string $dataDir)
    {
        $this->dataDir = rtrim($dataDir, '/');
        $this->dataFile = $this->dataDir . '/kids-point.json';
        $this->lockFile = $this->dataDir . '/kids-point.lock';
        $this->backupDir = $this->dataDir . '/backup';
    }

    public static function defaultDataDir(string $publicDir): string
    {
        $env = getenv('KIDS_POINT_DATA_DIR');
        if ($env !== false && $env !== '') {
            return $env;
        }
        $real = realpath($publicDir . '/../data');
        if ($real !== false) {
            return $real;
        }
        // 初回起動などでまだ data/ が無い場合は素直に計算したパスを使う
        return $publicDir . '/../data';
    }

    private function ensureDirs(): void
    {
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0770, true);
        }
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0770, true);
        }
    }

    private function defaultData(): array
    {
        return [
            'schema_version' => 1,
            'users' => [],
            'items' => [],
            'entries' => [],
            'settings' => ['rate_x' => 1, 'rate_y' => 1],
            'login_failures' => [],
            'remember_tokens' => [],
            'seq' => ['users' => 0, 'items' => 0, 'entries' => 0],
        ];
    }

    private function loadRaw(): array
    {
        if (!file_exists($this->dataFile)) {
            return $this->defaultData();
        }
        $raw = file_get_contents($this->dataFile);
        if ($raw === false || trim($raw) === '') {
            return $this->defaultData();
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('データファイルが壊れています');
        }
        return $data + $this->defaultData();
    }

    /**
     * @template T
     * @param callable(array):array $fn 全体データを受け取り、書き込みたい状態を返す
     * @return array 実際に $fn に渡って処理された結果一式
     */
    private function withLock(callable $fn): mixed
    {
        $this->ensureDirs();
        $lockHandle = fopen($this->lockFile, 'c');
        if ($lockHandle === false) {
            throw new \RuntimeException('ロックファイルを開けません');
        }
        if (!flock($lockHandle, LOCK_EX)) {
            fclose($lockHandle);
            throw new \RuntimeException('ロックを取得できません');
        }
        try {
            return $fn();
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /** 読み取り専用。排他ロックの中で読むので、書き込み途中の中途半端な状態を見ない */
    public function read(): array
    {
        return $this->withLock(fn() => $this->loadRaw());
    }

    /**
     * 読む→変える→一時ファイルに書く→rename、を1ロック内で行う。
     * $mutator(array $data): array{data: array, result: mixed}
     * result を呼び出し元に返す。
     */
    public function mutate(callable $mutator): mixed
    {
        return $this->withLock(function () use ($mutator) {
            $data = $this->loadRaw();
            $this->backupIfNeeded();
            $outcome = $mutator($data);
            if (!is_array($outcome) || !array_key_exists('data', $outcome)) {
                throw new \RuntimeException('mutator は data キーを返す必要があります');
            }
            $this->writeAtomic($outcome['data']);
            return $outcome['result'] ?? null;
        });
    }

    private function backupIfNeeded(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
        $backupPath = $this->backupDir . '/' . $today . '.json';
        if (file_exists($backupPath)) {
            return;
        }
        $current = file_exists($this->dataFile)
            ? file_get_contents($this->dataFile)
            : json_encode($this->defaultData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($backupPath, $current, LOCK_EX);
        $this->pruneOldBackups();
    }

    private function pruneOldBackups(): void
    {
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))->modify('-60 days');
        foreach (glob($this->backupDir . '/*.json') ?: [] as $path) {
            $base = basename($path, '.json');
            $d = \DateTimeImmutable::createFromFormat('Y-m-d', $base, new \DateTimeZone('Asia/Tokyo'));
            if ($d !== false && $d < $cutoff) {
                @unlink($path);
            }
        }
    }

    private function writeAtomic(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('JSON エンコードに失敗しました');
        }
        $tmp = $this->dataFile . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        $written = file_put_contents($tmp, $json, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException('一時ファイルの書き込みに失敗しました');
        }
        if (!rename($tmp, $this->dataFile)) {
            @unlink($tmp);
            throw new \RuntimeException('データファイルの置き換えに失敗しました');
        }
    }

    public function nextId(array &$data, string $collection): string
    {
        $data['seq'][$collection] = ($data['seq'][$collection] ?? 0) + 1;
        $prefix = match ($collection) {
            'users' => 'u',
            'items' => 'i',
            'entries' => 'e',
            default => 'x',
        };
        return $prefix . $data['seq'][$collection];
    }
}
