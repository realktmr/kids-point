<?php
declare(strict_types=1);

namespace KidsPoint;

require __DIR__ . '/../src/ApiError.php';
require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/Auth.php';
require __DIR__ . '/../src/Domain.php';

$dataDir = Store::defaultDataDir(__DIR__);
$store = new Store($dataDir);

Auth::bootstrapSession();
tryRememberLogin($store);
enforceActiveSession($store);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

try {
    if ($method === 'GET') {
        Http::jsonResponse(200, handleGet($store, $action));
    } elseif ($method === 'POST') {
        Auth::requireCsrf();
        $body = Http::jsonBody();
        Http::jsonResponse(200, handlePost($store, $action, $body));
    } else {
        throw new ApiError(405, 'method_not_allowed', '許可されていないメソッドです');
    }
} catch (ApiError $e) {
    Http::jsonResponse($e->httpStatus, ['ok' => false, 'error' => $e->errorCode, 'message' => $e->getMessage()] + $e->extra);
} catch (\Throwable $e) {
    error_log('[kids-point] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    Http::jsonResponse(500, ['ok' => false, 'error' => 'internal_error', 'message' => '内部エラーが発生しました']);
}

// ---------------------------------------------------------------------

function tryRememberLogin(Store $store): void
{
    if (Auth::isLoggedIn()) {
        return;
    }
    $token = $_COOKIE[Auth::REMEMBER_COOKIE] ?? null;
    if (!is_string($token) || $token === '') {
        return;
    }
    $outcome = $store->mutate(function (array $data) use ($token) {
        $now = time();
        Auth::pruneExpired($data, $now);
        $consumed = Auth::consumeRememberToken($data, $token, $now);
        $login = null;
        if ($consumed['status'] === 'ok_rotate' || $consumed['status'] === 'ok_no_rotate') {
            $user = $data['users'][$consumed['user_id']] ?? null;
            if ($user && !empty($user['active'])) {
                $login = [
                    'user_id' => $consumed['user_id'],
                    'role' => $user['role'],
                    'new_token' => $consumed['new_token'] ?? null,
                    'touch_cookie' => $consumed['status'] === 'ok_rotate',
                ];
            }
        }
        return ['data' => $data, 'result' => ['status' => $consumed['status'], 'login' => $login]];
    });
    $login = $outcome['login'];
    if ($login) {
        Auth::login($login['user_id'], $login['role']);
        if ($login['touch_cookie'] && $login['new_token'] !== null) {
            Auth::setRememberCookie($login['new_token']);
        }
        // ok_no_rotate（猶予時間内の重複リクエスト）は cookie に触らない
    } elseif ($outcome['status'] === 'not_found') {
        // トークンが見つからない・期限切れ・猶予切れのときだけ cookie を消す
        Auth::clearRememberCookie();
    }
}

function enforceActiveSession(Store $store): void
{
    if (!Auth::isLoggedIn()) {
        return;
    }
    $data = $store->read();
    $userId = (string) $_SESSION['user_id'];
    $user = $data['users'][$userId] ?? null;
    if (!$user || empty($user['active'])) {
        Auth::logout();
    }
}

function scopedChildId(array $body_or_query, ?string $key = 'child_id'): string
{
    if ($_SESSION['role'] === 'child') {
        return (string) $_SESSION['user_id'];
    }
    Auth::requireParent();
    $id = $body_or_query[$key] ?? null;
    if (!is_string($id) || $id === '') {
        throw new ApiError(400, 'invalid_input', 'child_id が必要です');
    }
    return $id;
}

function handleGet(Store $store, string $action): array
{
    switch ($action) {
        case 'me':
            return handleMe($store);
        case 'login_children':
            $data = $store->read();
            return ['ok' => true, 'children' => Domain::publicChildren($data)];
        case 'state':
            Auth::requireLogin();
            $data = $store->read();
            $childId = scopedChildId($_GET);
            if (!isset($data['users'][$childId]) || $data['users'][$childId]['role'] !== 'child') {
                throw new ApiError(404, 'not_found', '子が見つかりません');
            }
            $balance = Domain::balance($data, $childId);
            $rateX = (int) $data['settings']['rate_x'];
            $rateY = (int) $data['settings']['rate_y'];
            return [
                'ok' => true,
                'child_id' => $childId,
                'name' => $data['users'][$childId]['name'],
                'color' => $data['users'][$childId]['color'] ?? null,
                'balance' => $balance,
                'balance_yen' => (int) round($balance / $rateX * $rateY),
                'available_for_use' => Domain::availableForUse($data, $childId),
                'pending_count' => Domain::pendingCount($data, $childId),
                'settings' => $data['settings'],
            ];
        case 'calendar':
            Auth::requireLogin();
            $data = $store->read();
            $childId = scopedChildId($_GET);
            $year = (int) ($_GET['year'] ?? 0);
            $month = (int) ($_GET['month'] ?? 0);
            if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
                throw new ApiError(400, 'invalid_input', '年月が不正です');
            }
            return ['ok' => true] + Domain::calendar($data, $childId, $year, $month);
        case 'entries':
            Auth::requireLogin();
            $data = $store->read();
            if ($_SESSION['role'] === 'child') {
                $childId = (string) $_SESSION['user_id'];
            } else {
                Auth::requireParent();
                $childId = isset($_GET['child_id']) && $_GET['child_id'] !== '' ? (string) $_GET['child_id'] : null;
            }
            $status = isset($_GET['status']) && $_GET['status'] !== '' ? (string) $_GET['status'] : null;
            $date = isset($_GET['date']) && $_GET['date'] !== '' ? (string) $_GET['date'] : null;
            return ['ok' => true, 'entries' => Domain::entriesFor($data, $childId, $status, $date)];
        case 'items':
            Auth::requireLogin();
            $data = $store->read();
            return ['ok' => true, 'items' => Domain::itemsFor($data, (string) $_SESSION['role'])];
        case 'settings':
            Auth::requireLogin();
            $data = $store->read();
            return ['ok' => true, 'settings' => $data['settings']];
        case 'accounts':
            Auth::requireParent();
            $data = $store->read();
            return ['ok' => true, 'accounts' => Domain::accountsList($data)];
        case 'search':
            Auth::requireParent();
            $q = trim((string) ($_GET['q'] ?? ''));
            if ($q === '' || mb_strlen($q) > 100) {
                throw new ApiError(400, 'invalid_input', 'q が不正です');
            }
            $childId = isset($_GET['child_id']) && $_GET['child_id'] !== '' ? (string) $_GET['child_id'] : null;
            $data = $store->read();
            return ['ok' => true] + Domain::searchEntries($data, $q, $childId);
        default:
            throw new ApiError(404, 'unknown_action', '不明な action です');
    }
}

function handleMe(Store $store): array
{
    if (!Auth::isLoggedIn()) {
        return ['ok' => true, 'authenticated' => false, 'csrf' => Auth::csrfToken()];
    }
    $data = $store->read();
    $userId = (string) $_SESSION['user_id'];
    $user = $data['users'][$userId] ?? null;
    if (!$user || empty($user['active'])) {
        Auth::logout();
        return ['ok' => true, 'authenticated' => false, 'csrf' => Auth::csrfToken()];
    }
    $childScope = $user['role'] === 'child' ? $userId : null;
    return [
        'ok' => true,
        'authenticated' => true,
        'user' => Domain::safeUser($userId, $user),
        'pending_count' => Domain::pendingCount($data, $childScope),
        'csrf' => Auth::csrfToken(),
    ];
}

function handlePost(Store $store, string $action, array $body): array
{
    switch ($action) {
        case 'login_child':
            return handleLoginChild($store, $body);
        case 'login_parent':
            return handleLoginParent($store, $body);
        case 'logout':
            return handleLogout($store);
        case 'request_earn':
            return handleRequestEarn($store, $body);
        case 'request_use':
            return handleRequestUse($store, $body);
        case 'cancel_request':
            return handleCancelRequest($store, $body);
        case 'approve_entry':
            return handleApproveEntry($store, $body);
        case 'reject_entry':
            return handleRejectEntry($store, $body);
        case 'cancel_entry':
            return handleCancelEntry($store, $body);
        case 'direct_add':
            return handleDirectAdd($store, $body);
        case 'items_create':
            Auth::requireParent();
            return $store->mutate(function (array $data) use ($store, $body) {
                $item = Domain::createItem(
                    $data,
                    $store,
                    trim(Http::requireString($body, 'name', 100)),
                    Http::requireInt($body, 'points'),
                    (bool) ($body['active'] ?? true)
                );
                return ['data' => $data, 'result' => ['ok' => true, 'item' => $item]];
            });
        case 'items_update':
            Auth::requireParent();
            return $store->mutate(function (array $data) use ($body) {
                $item = Domain::updateItem(
                    $data,
                    Http::requireString($body, 'id', 50),
                    isset($body['name']) ? trim((string) $body['name']) : null,
                    Http::optionalInt($body, 'points'),
                    isset($body['active']) ? (bool) $body['active'] : null
                );
                return ['data' => $data, 'result' => ['ok' => true, 'item' => $item]];
            });
        case 'items_reorder':
            Auth::requireParent();
            return $store->mutate(function (array $data) use ($body) {
                $ids = $body['ids'] ?? null;
                if (!is_array($ids)) {
                    throw new ApiError(400, 'invalid_input', 'ids が不正です');
                }
                Domain::reorderItems($data, $ids);
                return ['data' => $data, 'result' => ['ok' => true]];
            });
        case 'settings_update':
            Auth::requireParent();
            return $store->mutate(function (array $data) use ($body) {
                Domain::updateSettings($data, Http::requireInt($body, 'rate_x'), Http::requireInt($body, 'rate_y'));
                return ['data' => $data, 'result' => ['ok' => true, 'settings' => $data['settings']]];
            });
        case 'account_create_child':
            Auth::requireParent();
            $parentIdForChildCreate = (string) $_SESSION['user_id'];
            return $store->mutate(function (array $data) use ($store, $body, $parentIdForChildCreate) {
                $account = Domain::createChildAccount(
                    $data,
                    $store,
                    $parentIdForChildCreate,
                    trim(Http::requireString($body, 'name', 50)),
                    Http::optionalString($body, 'color', 20),
                    Http::requireString($body, 'pin', 4),
                    Http::optionalInt($body, 'carryover_points')
                );
                return ['data' => $data, 'result' => ['ok' => true, 'account' => $account]];
            });
        case 'account_update_child':
            Auth::requireParent();
            return $store->mutate(function (array $data) use ($store, $body) {
                $account = Domain::updateChildAccount(
                    $data,
                    $store,
                    Http::requireString($body, 'id', 50),
                    isset($body['name']) ? trim((string) $body['name']) : null,
                    isset($body['color']) ? (string) $body['color'] : null,
                    isset($body['pin']) ? (string) $body['pin'] : null,
                    isset($body['active']) ? (bool) $body['active'] : null
                );
                return ['data' => $data, 'result' => ['ok' => true, 'account' => $account]];
            });
        case 'account_create_parent':
            Auth::requireParent();
            return $store->mutate(function (array $data) use ($store, $body) {
                $account = Domain::createParentAccount(
                    $data,
                    $store,
                    trim(Http::requireString($body, 'login_id', 50)),
                    Http::requireString($body, 'password', 200)
                );
                return ['data' => $data, 'result' => ['ok' => true, 'account' => $account]];
            });
        case 'account_update_parent':
            Auth::requireParent();
            return $store->mutate(function (array $data) use ($body) {
                $account = Domain::updateParentAccount(
                    $data,
                    Http::requireString($body, 'id', 50),
                    isset($body['password']) ? (string) $body['password'] : null,
                    isset($body['active']) ? (bool) $body['active'] : null,
                    (string) $_SESSION['user_id']
                );
                return ['data' => $data, 'result' => ['ok' => true, 'account' => $account]];
            });
        default:
            throw new ApiError(404, 'unknown_action', '不明な action です');
    }
}

function handleLoginChild(Store $store, array $body): array
{
    $childId = Http::requireString($body, 'child_id', 50);
    $pin = Http::requireString($body, 'pin', 4);
    $ip = Http::clientIp();
    $now = time();
    $result = $store->mutate(function (array $data) use ($childId, $pin, $ip, $now) {
        Auth::pruneExpired($data, $now);
        Auth::assertNotLocked($data, "child:{$childId}", $now);
        Auth::assertNotLocked($data, "ip:{$ip}", $now);
        $user = $data['users'][$childId] ?? null;
        $childExists = $user && ($user['role'] ?? null) === 'child';
        $ok = $childExists && !empty($user['active']) && password_verify($pin, $user['pin_hash']);
        if (!$ok) {
            // 存在しない child_id で無限にバケットが作られないよう、実在する子のときだけ記録する
            if ($childExists) {
                Auth::recordFailure($data, "child:{$childId}", Auth::CHILD_MAX, Auth::CHILD_WINDOW, Auth::CHILD_LOCK, $now);
            }
            Auth::recordFailure($data, "ip:{$ip}", Auth::IP_MAX, Auth::IP_WINDOW, Auth::IP_LOCK, $now);
            return ['data' => $data, 'result' => ['ok' => false]];
        }
        Auth::resetBucket($data, "child:{$childId}");
        $remember = Auth::issueRememberToken($data, $childId, $now);
        return ['data' => $data, 'result' => [
            'ok' => true,
            'user' => Domain::safeUser($childId, $user),
            'remember' => $remember,
        ]];
    });
    if (!$result['ok']) {
        throw new ApiError(401, 'invalid_credentials', '名前または PIN が違います');
    }
    Auth::login($childId, 'child');
    Auth::setRememberCookie($result['remember']);
    return ['ok' => true, 'user' => $result['user'], 'csrf' => Auth::csrfToken()];
}

function handleLoginParent(Store $store, array $body): array
{
    $loginId = Http::requireString($body, 'login_id', 50);
    $password = Http::requireString($body, 'password', 200);
    $ip = Http::clientIp();
    $now = time();
    $result = $store->mutate(function (array $data) use ($loginId, $password, $ip, $now) {
        Auth::pruneExpired($data, $now);
        Auth::assertNotLocked($data, "parent:{$loginId}", $now);
        Auth::assertNotLocked($data, "ip:{$ip}", $now);
        $foundId = null;
        $foundUser = null;
        foreach ($data['users'] as $id => $u) {
            if (($u['role'] ?? null) === 'parent' && ($u['login_id'] ?? null) === $loginId) {
                $foundId = $id;
                $foundUser = $u;
                break;
            }
        }
        $ok = $foundUser && !empty($foundUser['active']) && password_verify($password, $foundUser['password_hash']);
        if (!$ok) {
            // 存在しない login_id で無限にバケットが作られないよう、実在する親のときだけ記録する
            if ($foundUser !== null) {
                Auth::recordFailure($data, "parent:{$loginId}", Auth::PARENT_MAX, Auth::PARENT_WINDOW, Auth::PARENT_LOCK, $now);
            }
            Auth::recordFailure($data, "ip:{$ip}", Auth::IP_MAX, Auth::IP_WINDOW, Auth::IP_LOCK, $now);
            return ['data' => $data, 'result' => ['ok' => false]];
        }
        Auth::resetBucket($data, "parent:{$loginId}");
        $remember = Auth::issueRememberToken($data, $foundId, $now);
        return ['data' => $data, 'result' => [
            'ok' => true,
            'user_id' => $foundId,
            'user' => Domain::safeUser($foundId, $foundUser),
            'remember' => $remember,
        ]];
    });
    if (!$result['ok']) {
        throw new ApiError(401, 'invalid_credentials', 'ログイン ID またはパスワードが違います');
    }
    Auth::login($result['user_id'], 'parent');
    Auth::setRememberCookie($result['remember']);
    return ['ok' => true, 'user' => $result['user'], 'csrf' => Auth::csrfToken()];
}

function handleLogout(Store $store): array
{
    $token = $_COOKIE[Auth::REMEMBER_COOKIE] ?? null;
    if (is_string($token) && $token !== '') {
        $store->mutate(function (array $data) use ($token) {
            $hash = hash('sha256', $token);
            unset($data['remember_tokens'][$hash]);
            return ['data' => $data, 'result' => null];
        });
    }
    Auth::clearRememberCookie();
    Auth::logout();
    return ['ok' => true];
}

function handleRequestEarn(Store $store, array $body): array
{
    $childId = Auth::requireChild();
    $itemId = Http::requireString($body, 'item_id', 50);
    $doneDate = Http::optionalString($body, 'done_date', 10);
    if ($doneDate === '') {
        $doneDate = Domain::today();
    }
    $memo = Http::optionalString($body, 'memo', 500);
    return $store->mutate(function (array $data) use ($store, $childId, $itemId, $doneDate, $memo) {
        $entry = Domain::createEarnRequest($data, $store, $childId, $itemId, $doneDate, $memo);
        return ['data' => $data, 'result' => ['ok' => true, 'entry' => $entry]];
    });
}

function handleRequestUse(Store $store, array $body): array
{
    $childId = Auth::requireChild();
    $points = Http::requireInt($body, 'points');
    $memo = Http::optionalString($body, 'memo', 500);
    return $store->mutate(function (array $data) use ($store, $childId, $points, $memo) {
        $entry = Domain::createUseRequest($data, $store, $childId, $points, $memo);
        return ['data' => $data, 'result' => ['ok' => true, 'entry' => $entry]];
    });
}

function handleCancelRequest(Store $store, array $body): array
{
    $childId = Auth::requireChild();
    $entryId = Http::requireString($body, 'entry_id', 50);
    return $store->mutate(function (array $data) use ($childId, $entryId) {
        Domain::cancelOwnRequest($data, $childId, $entryId);
        return ['data' => $data, 'result' => ['ok' => true]];
    });
}

function handleApproveEntry(Store $store, array $body): array
{
    Auth::requireParent();
    $parentId = (string) $_SESSION['user_id'];
    $entryId = Http::requireString($body, 'entry_id', 50);
    return $store->mutate(function (array $data) use ($parentId, $entryId) {
        Domain::approveEntry($data, $entryId, $parentId);
        return ['data' => $data, 'result' => ['ok' => true]];
    });
}

function handleRejectEntry(Store $store, array $body): array
{
    Auth::requireParent();
    $parentId = (string) $_SESSION['user_id'];
    $entryId = Http::requireString($body, 'entry_id', 50);
    $reason = Http::optionalString($body, 'reason', 200);
    return $store->mutate(function (array $data) use ($parentId, $entryId, $reason) {
        Domain::rejectEntry($data, $entryId, $parentId, $reason);
        return ['data' => $data, 'result' => ['ok' => true]];
    });
}

function handleCancelEntry(Store $store, array $body): array
{
    Auth::requireParent();
    $parentId = (string) $_SESSION['user_id'];
    $entryId = Http::requireString($body, 'entry_id', 50);
    return $store->mutate(function (array $data) use ($parentId, $entryId) {
        Domain::cancelApprovedEntry($data, $entryId, $parentId);
        return ['data' => $data, 'result' => ['ok' => true]];
    });
}

function handleDirectAdd(Store $store, array $body): array
{
    Auth::requireParent();
    $parentId = (string) $_SESSION['user_id'];
    $childId = Http::requireString($body, 'child_id', 50);
    $itemId = isset($body['item_id']) && $body['item_id'] !== '' ? (string) $body['item_id'] : null;
    $points = Http::optionalInt($body, 'points');
    $memo = Http::optionalString($body, 'memo', 500);
    $doneDate = isset($body['done_date']) && $body['done_date'] !== '' ? (string) $body['done_date'] : null;
    $carryover = !empty($body['carryover']);
    return $store->mutate(function (array $data) use ($store, $parentId, $childId, $itemId, $points, $memo, $doneDate, $carryover) {
        $entry = Domain::directAdd($data, $store, $parentId, $childId, $itemId, $points, $memo, $doneDate, $carryover);
        return ['data' => $data, 'result' => ['ok' => true, 'entry' => $entry]];
    });
}
