#!/usr/bin/env bash
# Playwright の webServer から呼ばれる起動スクリプト。
# 使い捨てデータディレクトリを使い、初回の親アカウント(oya/testpass1234)を作ってから php -S を起動する。
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

: "${KIDS_POINT_DATA_DIR:?KIDS_POINT_DATA_DIR must be set}"
: "${PORT:?PORT must be set}"

mkdir -p "$KIDS_POINT_DATA_DIR"
export KIDS_POINT_INSECURE_COOKIES=1
export KIDS_POINT_COOKIE_PATH=/

printf 'oya\ntestpass1234\ntestpass1234\n' | php "$ROOT_DIR/bin/create-parent.php" >&2

exec php -S 127.0.0.1:"$PORT" -t "$ROOT_DIR/public"
