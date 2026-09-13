<?php
declare(strict_types=1);

namespace KidsPoint;

// 最初の親アカウントを作る CLI 専用コマンド。
// Web から呼べないように PHP_SAPI を確認する。
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "このスクリプトは CLI からのみ実行できます。\n";
    exit(1);
}

require __DIR__ . '/../src/ApiError.php';
require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/Domain.php';
require __DIR__ . '/../src/Auth.php';

function readLine(string $prompt): string
{
    echo $prompt;
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

function readHidden(string $prompt): string
{
    echo $prompt;
    if (stripos(PHP_OS, 'WIN') === 0) {
        // Windows では非対応。そのまま表示入力にフォールバック。
        $line = fgets(STDIN);
        return $line === false ? '' : trim($line);
    }
    system('stty -echo 2>/dev/null');
    $line = fgets(STDIN);
    system('stty echo 2>/dev/null');
    echo "\n";
    return $line === false ? '' : trim($line);
}

$publicDir = __DIR__ . '/../public';
$dataDir = Store::defaultDataDir($publicDir);
$store = new Store($dataDir);

$existing = $store->read();
$hasParent = false;
foreach ($existing['users'] as $u) {
    if (($u['role'] ?? null) === 'parent') {
        $hasParent = true;
        break;
    }
}
if ($hasParent) {
    echo "既に親アカウントが存在します。追加する場合は画面の「アカウントの管理」から行ってください。\n";
    echo "それでも CLI から追加しますか？ (y/N): ";
    $ans = trim((string) fgets(STDIN));
    if (strtolower($ans) !== 'y') {
        echo "中止しました。\n";
        exit(0);
    }
}

$loginId = readLine('ログインID: ');
if ($loginId === '') {
    echo "ログインIDが空です。中止しました。\n";
    exit(1);
}
$password = readHidden('パスワード（12文字以上）: ');
$password2 = readHidden('パスワード（確認）: ');
if ($password !== $password2) {
    echo "パスワードが一致しません。中止しました。\n";
    exit(1);
}

try {
    $account = $store->mutate(function (array $data) use ($store, $loginId, $password) {
        $account = Domain::createParentAccount($data, $store, $loginId, $password);
        return ['data' => $data, 'result' => $account];
    });
} catch (ApiError $e) {
    echo '失敗しました: ' . $e->getMessage() . "\n";
    exit(1);
}

echo "親アカウントを作成しました: id={$account['id']} login_id={$account['login_id']}\n";
