<?php
declare(strict_types=1);

namespace KidsPoint;

/** ポイントの業務ロジック（残高・カレンダー・申請・承認など）。純粋関数的に $data を扱う */
final class Domain
{
    public static function tz(): \DateTimeZone
    {
        return new \DateTimeZone('Asia/Tokyo');
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::tz());
    }

    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }

    private static function isValidDate(string $s): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s, self::tz());
        return $d !== false && $d->format('Y-m-d') === $s;
    }

    // ---- users ----

    public static function publicChildren(array $data): array
    {
        $out = [];
        foreach ($data['users'] as $id => $u) {
            if (($u['role'] ?? null) === 'child' && !empty($u['active'])) {
                $out[] = ['id' => $id, 'name' => $u['name'], 'color' => $u['color'] ?? '#888888'];
            }
        }
        return $out;
    }

    public static function safeUser(string $id, array $u): array
    {
        return [
            'id' => $id,
            'role' => $u['role'],
            'name' => $u['name'],
            'color' => $u['color'] ?? null,
            'login_id' => $u['login_id'] ?? null,
            'active' => (bool) ($u['active'] ?? true),
        ];
    }

    public static function accountsList(array $data): array
    {
        $out = [];
        foreach ($data['users'] as $id => $u) {
            $out[] = self::safeUser($id, $u);
        }
        return $out;
    }

    // ---- items ----

    public static function itemsFor(array $data, string $role): array
    {
        $items = [];
        foreach ($data['items'] as $id => $it) {
            if ($role === 'child' && (!$it['active'] || $it['points'] <= 0)) {
                continue;
            }
            $items[] = $it + ['id' => $id];
        }
        usort($items, fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
        return $items;
    }

    public static function createItem(array &$data, Store $store, string $name, int $points, bool $active = true): array
    {
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ApiError(400, 'invalid_input', '項目名が不正です');
        }
        if ($points === 0) {
            throw new ApiError(400, 'invalid_input', 'ポイントは0以外にしてください');
        }
        $id = $store->nextId($data, 'items');
        $maxOrder = 0;
        foreach ($data['items'] as $it) {
            $maxOrder = max($maxOrder, $it['order'] ?? 0);
        }
        $item = ['name' => $name, 'points' => $points, 'active' => $active, 'order' => $maxOrder + 1];
        $data['items'][$id] = $item;
        return $item + ['id' => $id];
    }

    public static function updateItem(array &$data, string $id, ?string $name, ?int $points, ?bool $active): array
    {
        if (!isset($data['items'][$id])) {
            throw new ApiError(404, 'not_found', '項目が見つかりません');
        }
        if ($name !== null) {
            if ($name === '' || mb_strlen($name) > 100) {
                throw new ApiError(400, 'invalid_input', '項目名が不正です');
            }
            $data['items'][$id]['name'] = $name;
        }
        if ($points !== null) {
            if ($points === 0) {
                throw new ApiError(400, 'invalid_input', 'ポイントは0以外にしてください');
            }
            $data['items'][$id]['points'] = $points;
        }
        if ($active !== null) {
            $data['items'][$id]['active'] = $active;
        }
        return $data['items'][$id] + ['id' => $id];
    }

    public static function reorderItems(array &$data, array $ids): void
    {
        $order = 1;
        foreach ($ids as $id) {
            if (!isset($data['items'][$id])) {
                throw new ApiError(400, 'invalid_input', "不明な項目 {$id}");
            }
            $data['items'][$id]['order'] = $order++;
        }
    }

    // ---- settings ----

    public static function updateSettings(array &$data, int $rateX, int $rateY): void
    {
        if ($rateX <= 0 || $rateY <= 0) {
            throw new ApiError(400, 'invalid_input', 'レートは正の整数にしてください');
        }
        $data['settings'] = ['rate_x' => $rateX, 'rate_y' => $rateY];
    }

    // ---- 残高・集計 ----

    public static function balance(array $data, string $childId): int
    {
        $sum = 0;
        foreach ($data['entries'] as $e) {
            if ($e['child_id'] === $childId && $e['status'] === 'approved') {
                $sum += $e['points'];
            }
        }
        return $sum;
    }

    public static function pendingUseTotal(array $data, string $childId): int
    {
        $sum = 0;
        foreach ($data['entries'] as $e) {
            if ($e['child_id'] === $childId && $e['status'] === 'pending' && $e['type'] === 'use') {
                $sum += abs($e['points']);
            }
        }
        return $sum;
    }

    public static function availableForUse(array $data, string $childId): int
    {
        return self::balance($data, $childId) - self::pendingUseTotal($data, $childId);
    }

    public static function pendingCount(array $data, ?string $childId): int
    {
        $n = 0;
        foreach ($data['entries'] as $e) {
            if ($e['status'] !== 'pending') {
                continue;
            }
            if ($childId !== null && $e['child_id'] !== $childId) {
                continue;
            }
            $n++;
        }
        return $n;
    }

    public static function calendar(array $data, string $childId, int $year, int $month): array
    {
        $days = [];
        $earned = 0;
        $reduced = 0;
        $used = 0;
        $carryover = 0;
        foreach ($data['entries'] as $id => $e) {
            if ($e['child_id'] !== $childId || $e['status'] !== 'approved') {
                continue;
            }
            $d = $e['done_date'];
            $dy = (int) substr($d, 0, 4);
            $dm = (int) substr($d, 5, 2);
            if ($dy !== $year || $dm !== $month) {
                continue;
            }
            $days[$d] ??= ['earned' => 0, 'reduced' => 0, 'used' => 0, 'carryover' => 0];
            $p = $e['points'];
            if ($e['type'] === 'carryover') {
                // 繰越は「もらった／減った／使った」のどれにも数えず、別枠として扱う
                $days[$d]['carryover'] += $p;
                $carryover += $p;
            } elseif ($e['type'] === 'use') {
                $days[$d]['used'] += abs($p);
                $used += abs($p);
            } elseif ($p >= 0) {
                $days[$d]['earned'] += $p;
                $earned += $p;
            } else {
                $days[$d]['reduced'] += abs($p);
                $reduced += abs($p);
            }
        }
        ksort($days);
        return [
            'days' => $days,
            'month_summary' => [
                'earned' => $earned,
                'reduced' => $reduced,
                'used' => $used,
                'carryover' => $carryover,
                'net' => $earned - $reduced - $used,
            ],
            'balance' => self::balance($data, $childId),
            'pending_count' => self::pendingCount($data, $childId),
        ];
    }

    public static function entriesFor(array $data, ?string $childId, ?string $status, ?string $date): array
    {
        $out = [];
        foreach ($data['entries'] as $id => $e) {
            if ($childId !== null && $e['child_id'] !== $childId) {
                continue;
            }
            if ($status !== null && $e['status'] !== $status) {
                continue;
            }
            if ($date !== null && $e['done_date'] !== $date) {
                continue;
            }
            $childName = $data['users'][$e['child_id']]['name'] ?? '';
            $out[] = $e + ['id' => $id, 'child_name' => $childName];
        }
        usort($out, fn ($a, $b) => strcmp($b['requested_at'], $a['requested_at']));
        return $out;
    }

    // ---- 記録の検索（親のみ） ----

    private const SEARCH_LIMIT = 200;

    /** 全角/半角・大文字/小文字・半角カナ/全角カナの違いを区別しないように正規化する */
    private static function normalizeForSearch(string $s): string
    {
        return mb_strtolower(mb_convert_kana($s, 'asKV', 'UTF-8'), 'UTF-8');
    }

    private static function searchKeywords(string $query): array
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return [];
        }
        $keywords = [];
        foreach (preg_split('/[\s　]+/u', $trimmed) as $part) {
            if ($part !== '') {
                $keywords[] = self::normalizeForSearch($part);
            }
        }
        return $keywords;
    }

    /**
     * item_name・memo・reject_reason を対象に、キーワードすべてを含む（AND）記録を探す。
     * 並びは done_date の新しい順（無ければ requested_at の日付で代用）、同日なら requested_at の新しい順。
     */
    public static function searchEntries(array $data, string $query, ?string $childId): array
    {
        $keywords = self::searchKeywords($query);
        $matches = [];
        foreach ($data['entries'] as $id => $e) {
            if ($childId !== null && $e['child_id'] !== $childId) {
                continue;
            }
            $haystacks = array_map(
                fn ($h) => self::normalizeForSearch((string) $h),
                [$e['item_name'] ?? '', $e['memo'] ?? '', $e['reject_reason'] ?? '']
            );
            $allFound = true;
            foreach ($keywords as $kw) {
                $found = false;
                foreach ($haystacks as $h) {
                    if ($h !== '' && str_contains($h, $kw)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $allFound = false;
                    break;
                }
            }
            if (!$allFound) {
                continue;
            }
            $childName = $data['users'][$e['child_id']]['name'] ?? '';
            $matches[] = $e + ['id' => $id, 'child_name' => $childName];
        }

        usort($matches, function ($a, $b) {
            $keyA = $a['done_date'] ?: substr($a['requested_at'], 0, 10);
            $keyB = $b['done_date'] ?: substr($b['requested_at'], 0, 10);
            if ($keyA !== $keyB) {
                return strcmp($keyB, $keyA);
            }
            return strcmp($b['requested_at'], $a['requested_at']);
        });

        $count = count($matches);
        $truncated = $count > self::SEARCH_LIMIT;
        $approvedTotal = 0;
        foreach ($matches as $e) {
            if ($e['status'] === 'approved') {
                $approvedTotal += $e['points'];
            }
        }
        $limited = $truncated ? array_slice($matches, 0, self::SEARCH_LIMIT) : $matches;

        return [
            'entries' => $limited,
            'count' => $count,
            'approved_total' => $approvedTotal,
            'truncated' => $truncated,
        ];
    }

    // ---- entry 作成・状態遷移 ----

    private static function baseEntry(string $childId, string $type, string $requestedBy, string $now): array
    {
        return [
            'child_id' => $childId,
            'type' => $type,
            'item_id' => null,
            'item_name' => null,
            'points' => 0,
            'yen' => null,
            'rate_x' => null,
            'rate_y' => null,
            'memo' => '',
            'done_date' => null,
            'status' => 'pending',
            'requested_by' => $requestedBy,
            'requested_at' => $now,
            'decided_by' => null,
            'decided_at' => null,
            'reject_reason' => null,
            'cancelled_by' => null,
            'cancelled_at' => null,
        ];
    }

    public static function createEarnRequest(array &$data, Store $store, string $childId, string $itemId, string $doneDate, string $memo): array
    {
        $item = $data['items'][$itemId] ?? null;
        if (!$item || !$item['active'] || $item['points'] <= 0) {
            throw new ApiError(400, 'invalid_input', '選べない項目です');
        }
        if (!self::isValidDate($doneDate)) {
            throw new ApiError(400, 'invalid_input', '日付が不正です');
        }
        $today = self::today();
        $earliest = self::now()->modify('-60 days')->format('Y-m-d');
        if ($doneDate > $today || $doneDate < $earliest) {
            throw new ApiError(400, 'invalid_input', '行った日は今日から60日前までにしてください');
        }
        $nowIso = self::now()->format(DATE_ATOM);
        $entry = [
            'item_id' => $itemId,
            'item_name' => $item['name'],
            'points' => $item['points'],
            'memo' => mb_substr($memo, 0, 500),
            'done_date' => $doneDate,
        ] + self::baseEntry($childId, 'earn', $childId, $nowIso);
        $id = $store->nextId($data, 'entries');
        $data['entries'][$id] = $entry;
        return $entry + ['id' => $id];
    }

    public static function createUseRequest(array &$data, Store $store, string $childId, int $points, string $memo): array
    {
        $rateX = (int) $data['settings']['rate_x'];
        $rateY = (int) $data['settings']['rate_y'];
        if ($points <= 0 || $points % $rateX !== 0) {
            throw new ApiError(400, 'invalid_input', "使う点数は{$rateX}の倍数にしてください");
        }
        $available = self::availableForUse($data, $childId);
        if ($points > $available) {
            throw new ApiError(400, 'insufficient_balance', '使えるポイントを超えています');
        }
        $nowIso = self::now()->format(DATE_ATOM);
        $yen = intdiv($points, $rateX) * $rateY;
        $entry = [
            'points' => -$points,
            'yen' => $yen,
            'rate_x' => $rateX,
            'rate_y' => $rateY,
            'memo' => mb_substr($memo, 0, 500),
        ] + self::baseEntry($childId, 'use', $childId, $nowIso);
        $id = $store->nextId($data, 'entries');
        $data['entries'][$id] = $entry;
        return $entry + ['id' => $id];
    }

    public static function cancelOwnRequest(array &$data, string $childId, string $entryId): void
    {
        $e = $data['entries'][$entryId] ?? null;
        if (!$e || $e['child_id'] !== $childId) {
            throw new ApiError(403, 'forbidden', '自分の申請以外は取り下げられません');
        }
        if ($e['status'] !== 'pending') {
            throw new ApiError(400, 'invalid_state', '申請中のものしか取り下げられません');
        }
        $nowIso = self::now()->format(DATE_ATOM);
        $data['entries'][$entryId]['status'] = 'cancelled';
        $data['entries'][$entryId]['cancelled_by'] = $childId;
        $data['entries'][$entryId]['cancelled_at'] = $nowIso;
    }

    public static function approveEntry(array &$data, string $entryId, string $parentId): void
    {
        $e = $data['entries'][$entryId] ?? null;
        if (!$e) {
            throw new ApiError(404, 'not_found', '申請が見つかりません');
        }
        if ($e['status'] !== 'pending') {
            throw new ApiError(400, 'invalid_state', '申請中のものしか承認できません');
        }
        if ($e['type'] === 'use') {
            $balance = self::balance($data, $e['child_id']);
            if ($balance < abs($e['points'])) {
                throw new ApiError(400, 'insufficient_balance', '残高が足りないため承認できません');
            }
            $e['done_date'] = self::today();
        }
        $nowIso = self::now()->format(DATE_ATOM);
        $e['status'] = 'approved';
        $e['decided_by'] = $parentId;
        $e['decided_at'] = $nowIso;
        $data['entries'][$entryId] = $e;
    }

    public static function rejectEntry(array &$data, string $entryId, string $parentId, string $reason): void
    {
        $e = $data['entries'][$entryId] ?? null;
        if (!$e) {
            throw new ApiError(404, 'not_found', '申請が見つかりません');
        }
        if ($e['status'] !== 'pending') {
            throw new ApiError(400, 'invalid_state', '申請中のものしか却下できません');
        }
        $nowIso = self::now()->format(DATE_ATOM);
        $e['status'] = 'rejected';
        $e['decided_by'] = $parentId;
        $e['decided_at'] = $nowIso;
        $e['reject_reason'] = $reason !== '' ? $reason : null;
        $data['entries'][$entryId] = $e;
    }

    public static function cancelApprovedEntry(array &$data, string $entryId, string $parentId): void
    {
        $e = $data['entries'][$entryId] ?? null;
        if (!$e) {
            throw new ApiError(404, 'not_found', '記録が見つかりません');
        }
        if ($e['status'] !== 'approved') {
            throw new ApiError(400, 'invalid_state', '承認済みのものしか取り消せません');
        }
        $nowIso = self::now()->format(DATE_ATOM);
        $e['status'] = 'cancelled';
        $e['cancelled_by'] = $parentId;
        $e['cancelled_at'] = $nowIso;
        $data['entries'][$entryId] = $e;
    }

    public static function directAdd(array &$data, Store $store, string $parentId, string $childId, ?string $itemId, ?int $manualPoints, string $memo, ?string $doneDate, bool $carryover = false): array
    {
        if (!isset($data['users'][$childId]) || $data['users'][$childId]['role'] !== 'child') {
            throw new ApiError(400, 'invalid_input', '子が不正です');
        }
        $today = self::today();
        $date = $doneDate ?? $today;
        if (!self::isValidDate($date) || $date > $today) {
            throw new ApiError(400, 'invalid_input', '日付が不正です');
        }
        $nowIso = self::now()->format(DATE_ATOM);

        if ($itemId !== null) {
            if ($carryover) {
                throw new ApiError(400, 'invalid_input', '繰越は手入力のときだけ使えます');
            }
            $item = $data['items'][$itemId] ?? null;
            if (!$item || !$item['active']) {
                throw new ApiError(400, 'invalid_input', '選べない項目です');
            }
            $type = $item['points'] >= 0 ? 'earn' : 'penalty';
            $entry = [
                'item_id' => $itemId,
                'item_name' => $item['name'],
                'points' => $item['points'],
                'memo' => mb_substr($memo, 0, 500),
                'done_date' => $date,
            ] + self::baseEntry($childId, $type, $parentId, $nowIso);
        } else {
            if ($manualPoints === null || $manualPoints === 0) {
                throw new ApiError(400, 'invalid_input', '点数が不正です');
            }
            $entry = [
                'points' => $manualPoints,
                'memo' => mb_substr($memo, 0, 500),
                'done_date' => $date,
            ] + self::baseEntry($childId, $carryover ? 'carryover' : 'adjust', $parentId, $nowIso);
        }
        $entry['status'] = 'approved';
        $entry['decided_by'] = $parentId;
        $entry['decided_at'] = $nowIso;

        $id = $store->nextId($data, 'entries');
        $data['entries'][$id] = $entry;
        return $entry + ['id' => $id];
    }

    // ---- アカウント管理 ----

    public static function validatePin(string $pin): void
    {
        if (!preg_match('/^\d{4}$/', $pin)) {
            throw new ApiError(400, 'invalid_input', 'PIN は数字4桁にしてください');
        }
    }

    public static function validatePassword(string $password): void
    {
        if (mb_strlen($password) < 12 || mb_strlen($password) > 200) {
            throw new ApiError(400, 'invalid_input', 'パスワードは12文字以上にしてください');
        }
    }

    public static function validateColor(string $color): void
    {
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            throw new ApiError(400, 'invalid_input', '色の形式が不正です（例: #4a6fa5）');
        }
    }

    private static function loginIdTaken(array $data, string $loginId, ?string $exceptUserId = null): bool
    {
        foreach ($data['users'] as $id => $u) {
            if ($id === $exceptUserId) {
                continue;
            }
            if (($u['login_id'] ?? null) === $loginId) {
                return true;
            }
        }
        return false;
    }

    public static function createChildAccount(array &$data, Store $store, string $parentId, string $name, string $color, string $pin, ?int $carryoverPoints = null): array
    {
        if ($name === '' || mb_strlen($name) > 50) {
            throw new ApiError(400, 'invalid_input', '名前が不正です');
        }
        self::validatePin($pin);
        if ($color !== '') {
            self::validateColor($color);
        }
        $id = $store->nextId($data, 'users');
        $data['users'][$id] = [
            'role' => 'child',
            'name' => $name,
            'color' => $color !== '' ? $color : '#888888',
            'pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
            'active' => true,
        ];
        if ($carryoverPoints !== null && $carryoverPoints !== 0) {
            self::createCarryoverEntry($data, $store, $parentId, $id, $carryoverPoints, '引き継ぎ', self::today());
        }
        return self::safeUser($id, $data['users'][$id]);
    }

    /** Famipoi 等からの残高引き継ぎ用に、承認済みの carryover entry を1件作る（親のみ） */
    private static function createCarryoverEntry(array &$data, Store $store, string $parentId, string $childId, int $points, string $memo, string $doneDate): array
    {
        $nowIso = self::now()->format(DATE_ATOM);
        $entry = [
            'points' => $points,
            'memo' => mb_substr($memo, 0, 500),
            'done_date' => $doneDate,
        ] + self::baseEntry($childId, 'carryover', $parentId, $nowIso);
        $entry['status'] = 'approved';
        $entry['decided_by'] = $parentId;
        $entry['decided_at'] = $nowIso;
        $id = $store->nextId($data, 'entries');
        $data['entries'][$id] = $entry;
        return $entry + ['id' => $id];
    }

    public static function updateChildAccount(array &$data, Store $store, string $id, ?string $name, ?string $color, ?string $pin, ?bool $active): array
    {
        $u = $data['users'][$id] ?? null;
        if (!$u || $u['role'] !== 'child') {
            throw new ApiError(404, 'not_found', '子が見つかりません');
        }
        if ($name !== null) {
            if ($name === '' || mb_strlen($name) > 50) {
                throw new ApiError(400, 'invalid_input', '名前が不正です');
            }
            $u['name'] = $name;
        }
        if ($color !== null && $color !== '') {
            self::validateColor($color);
            $u['color'] = $color;
        }
        if ($pin !== null) {
            self::validatePin($pin);
            $u['pin_hash'] = password_hash($pin, PASSWORD_DEFAULT);
            Auth::revokeAllRememberTokensFor($data, $id);
        }
        if ($active !== null) {
            $u['active'] = $active;
        }
        $data['users'][$id] = $u;
        return self::safeUser($id, $u);
    }

    public static function createParentAccount(array &$data, Store $store, string $loginId, string $password): array
    {
        if ($loginId === '' || mb_strlen($loginId) > 50) {
            throw new ApiError(400, 'invalid_input', 'ログインIDが不正です');
        }
        if (self::loginIdTaken($data, $loginId)) {
            throw new ApiError(400, 'invalid_input', 'そのログインIDは既に使われています');
        }
        self::validatePassword($password);
        $id = $store->nextId($data, 'users');
        $data['users'][$id] = [
            'role' => 'parent',
            'name' => $loginId,
            'login_id' => $loginId,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'active' => true,
        ];
        return self::safeUser($id, $data['users'][$id]);
    }

    public static function updateParentAccount(array &$data, string $id, ?string $password, ?bool $active, string $actingParentId): array
    {
        $u = $data['users'][$id] ?? null;
        if (!$u || $u['role'] !== 'parent') {
            throw new ApiError(404, 'not_found', '親が見つかりません');
        }
        if ($active === false) {
            if ($id === $actingParentId) {
                throw new ApiError(400, 'invalid_input', '自分自身は無効化できません');
            }
            $activeParents = array_filter($data['users'], fn ($x) => ($x['role'] ?? null) === 'parent' && !empty($x['active']));
            if (count($activeParents) <= 1) {
                throw new ApiError(400, 'invalid_input', '最後の親アカウントは無効化できません');
            }
            $u['active'] = false;
        } elseif ($active === true) {
            $u['active'] = true;
        }
        if ($password !== null) {
            self::validatePassword($password);
            $u['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            Auth::revokeAllRememberTokensFor($data, $id);
        }
        $data['users'][$id] = $u;
        return self::safeUser($id, $u);
    }
}
