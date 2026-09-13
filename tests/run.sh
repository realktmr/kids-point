#!/usr/bin/env bash
# kids-point api.php の試験。1コマンドで全部走り、失敗したら終了コード0以外になる。
set -u
set -o pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DATA_DIR="$(mktemp -d)"
PORT=$(( (RANDOM % 5000) + 20000 ))
BASE="http://127.0.0.1:${PORT}"
SERVER_LOG="$(mktemp)"
RESP="$(mktemp)"

export KIDS_POINT_DATA_DIR="$DATA_DIR"
export KIDS_POINT_INSECURE_COOKIES=1
export KIDS_POINT_COOKIE_PATH=/

PASS=0
FAIL=0
FAILED_NAMES=()

cleanup() {
  if [ -n "${SERVER_PID:-}" ]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  rm -rf "$DATA_DIR"
  rm -f "$SERVER_LOG" "$RESP"
  rm -f /tmp/kp_test_jar_* /tmp/kp_test_concurrent_* /tmp/kp_test_out_* /tmp/kp_test_index.html
}
trap cleanup EXIT

ok() {
  PASS=$((PASS + 1))
  echo "  OK: $1"
}
ng() {
  FAIL=$((FAIL + 1))
  FAILED_NAMES+=("$1")
  echo "  NG: $1"
  echo "      $2"
}

assert_status() {
  local name="$1" expected="$2" actual="$3"
  if [ "$expected" = "$actual" ]; then
    ok "$name (status=$actual)"
  else
    ng "$name" "expected status $expected, got $actual. body=$(cat "$RESP")"
  fi
}

assert_true() {
  local name="$1" cond="$2" detail="$3"
  if [ "$cond" = "true" ] || [ "$cond" = "1" ]; then
    ok "$name"
  else
    ng "$name" "$detail"
  fi
}

# --- 静的チェック ---
echo "== 静的チェック =="
LINT_FAIL=0
for f in "$ROOT_DIR"/src/*.php "$ROOT_DIR"/public/api.php "$ROOT_DIR"/bin/create-parent.php; do
  if ! php -l "$f" >/tmp/kp_lint_out 2>&1; then
    ng "php -l $f" "$(cat /tmp/kp_lint_out)"
    LINT_FAIL=1
  fi
done
[ "$LINT_FAIL" = 0 ] && ok "php -l (全ファイル)"

if ! node --check "$ROOT_DIR/public/app.js" >/tmp/kp_node_out 2>&1; then
  ng "node --check app.js" "$(cat /tmp/kp_node_out)"
else
  ok "node --check app.js"
fi

# --- サーバ起動 ---
echo "== サーバ起動 (PHP_CLI_SERVER_WORKERS=4, port=$PORT) =="
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:"$PORT" -t "$ROOT_DIR/public" >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!

# 起動待ち（pgrep は使わず、接続できるまでポーリング）
READY=0
for i in $(seq 1 50); do
  if curl -s -o /dev/null -w '%{http_code}' "$BASE/api.php?action=me" 2>/dev/null | grep -q '^200$'; then
    READY=1
    break
  fi
  sleep 0.2
done
if [ "$READY" != 1 ]; then
  echo "サーバが起動しませんでした"
  cat "$SERVER_LOG"
  exit 1
fi
ok "サーバ起動"

# --- HTTP ヘルパー ---
# http_get ACTION JAR QUERY
http_get() {
  local action="$1" jar="$2" query="${3:-}"
  curl -s -c "$jar" -b "$jar" -o "$RESP" -w '%{http_code}' "$BASE/api.php?action=${action}${query}"
}
# http_post ACTION JAR CSRF JSON
http_post() {
  local action="$1" jar="$2" csrf="$3" json="$4"
  if [ -n "$csrf" ]; then
    curl -s -c "$jar" -b "$jar" -X POST -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" -d "$json" -o "$RESP" -w '%{http_code}' "$BASE/api.php?action=${action}"
  else
    curl -s -c "$jar" -b "$jar" -X POST -H 'Content-Type: application/json' -d "$json" -o "$RESP" -w '%{http_code}' "$BASE/api.php?action=${action}"
  fi
}
jf() { jq -r "$1" "$RESP"; }

PARENT_JAR=/tmp/kp_test_jar_parent
CHILD1_JAR=/tmp/kp_test_jar_child1
CHILD2_JAR=/tmp/kp_test_jar_child2
ANON_JAR=/tmp/kp_test_jar_anon
rm -f "$PARENT_JAR" "$CHILD1_JAR" "$CHILD2_JAR" "$ANON_JAR"

# --- 初期データ作成: bin/create-parent.php で最初の親を作る ---
echo "== 初期データ =="
CREATE_OUT=$(printf 'oya\ntestpass1234\ntestpass1234\n' | php "$ROOT_DIR/bin/create-parent.php" 2>&1)
if echo "$CREATE_OUT" | grep -q '親アカウントを作成しました'; then
  ok "bin/create-parent.php で親アカウント作成"
else
  ng "bin/create-parent.php で親アカウント作成" "$CREATE_OUT"
fi

# 親としてログイン
st=$(http_get 'me' "$PARENT_JAR")
CSRF0=$(jf '.csrf')
st=$(http_post 'login_parent' "$PARENT_JAR" "$CSRF0" '{"login_id":"oya","password":"testpass1234"}')
assert_status "親ログイン" 200 "$st"
PARENT_CSRF=$(jf '.csrf')

# 子供2人を作る
st=$(http_post 'account_create_child' "$PARENT_JAR" "$PARENT_CSRF" '{"name":"たろう","color":"#3498db","pin":"1234"}')
assert_status "子(たろう)アカウント作成" 200 "$st"
CHILD1_ID=$(jf '.account.id')

st=$(http_post 'account_create_child' "$PARENT_JAR" "$PARENT_CSRF" '{"name":"はなこ","color":"#e91e63","pin":"5678"}')
assert_status "子(はなこ)アカウント作成" 200 "$st"
CHILD2_ID=$(jf '.account.id')

# 項目を作る（プラスの項目 と マイナスの項目）
st=$(http_post 'items_create' "$PARENT_JAR" "$PARENT_CSRF" '{"name":"おてつだい","points":100}')
assert_status "項目(プラス)作成" 200 "$st"
ITEM_PLUS_ID=$(jf '.item.id')

st=$(http_post 'items_create' "$PARENT_JAR" "$PARENT_CSRF" '{"name":"わすれもの","points":-10}')
assert_status "項目(マイナス)作成" 200 "$st"

# レートを X=10 Y=8 に設定（10ポイント=8円）
st=$(http_post 'settings_update' "$PARENT_JAR" "$PARENT_CSRF" '{"rate_x":10,"rate_y":8}')
assert_status "レート設定 (10P=8円)" 200 "$st"

# 子1ログイン
st=$(http_get 'me' "$CHILD1_JAR")
C1_CSRF0=$(jf '.csrf')
st=$(http_post 'login_child' "$CHILD1_JAR" "$C1_CSRF0" "{\"child_id\":\"$CHILD1_ID\",\"pin\":\"1234\"}")
assert_status "子(たろう)ログイン" 200 "$st"
CHILD1_CSRF=$(jf '.csrf')

# 子2ログイン
st=$(http_get 'me' "$CHILD2_JAR")
C2_CSRF0=$(jf '.csrf')
st=$(http_post 'login_child' "$CHILD2_JAR" "$C2_CSRF0" "{\"child_id\":\"$CHILD2_ID\",\"pin\":\"5678\"}")
assert_status "子(はなこ)ログイン" 200 "$st"
CHILD2_CSRF=$(jf '.csrf')

# ==================================================================
echo "== 権限: 子は親の操作・他の子のデータに触れない =="
# ==================================================================

st=$(http_get 'accounts' "$CHILD1_JAR")
assert_status "子がaccounts(親専用)を読む→403" 403 "$st"

st=$(http_post 'direct_add' "$CHILD1_JAR" "$CHILD1_CSRF" "{\"child_id\":\"$CHILD1_ID\",\"points\":100,\"memo\":\"x\"}")
assert_status "子がdirect_add(親専用)→403" 403 "$st"

st=$(http_post 'items_create' "$CHILD1_JAR" "$CHILD1_CSRF" '{"name":"x","points":1}')
assert_status "子がitems_create(親専用)→403" 403 "$st"

# 子1が「やったよ申請」を作る → 子2がそれを取り下げようとする
st=$(http_post 'request_earn' "$CHILD1_JAR" "$CHILD1_CSRF" "{\"item_id\":\"$ITEM_PLUS_ID\",\"done_date\":\"$(date +%F)\",\"memo\":\"test\"}")
assert_status "子1がやったよ申請" 200 "$st"
ENTRY1_ID=$(jf '.entry.id')

st=$(http_post 'cancel_request' "$CHILD2_JAR" "$CHILD2_CSRF" "{\"entry_id\":\"$ENTRY1_ID\"}")
assert_status "子2が子1の申請を取り下げようとする→403" 403 "$st"

# entries は自分のものしか返らない（child_idパラメータを偽装しても無視される）
st=$(http_get 'entries' "$CHILD2_JAR" "&child_id=${CHILD1_ID}")
OTHER_COUNT=$(jf "[.entries[] | select(.child_id != \"$CHILD2_ID\")] | length")
assert_true "子2がchild_id偽装しても他の子のentriesは見えない" "$([ "$OTHER_COUNT" = "0" ] && echo true || echo false)" "他の子のentriesが$OTHER_COUNT件見えた"

# ==================================================================
echo "== CSRF =="
# ==================================================================

st=$(http_post 'request_earn' "$CHILD1_JAR" "" "{\"item_id\":\"$ITEM_PLUS_ID\",\"done_date\":\"$(date +%F)\",\"memo\":\"nocsrf\"}")
assert_status "CSRFトークン無しのPOST→403" 403 "$st"

st=$(http_post 'request_earn' "$CHILD1_JAR" "wrong-token-xxxxx" "{\"item_id\":\"$ITEM_PLUS_ID\",\"done_date\":\"$(date +%F)\",\"memo\":\"badcsrf\"}")
assert_status "CSRFトークン不一致のPOST→403" 403 "$st"

# ==================================================================
echo "== PINロック（5回失敗で15分ロック） =="
# ==================================================================

LOCK_JAR=/tmp/kp_test_jar_lockcheck
rm -f "$LOCK_JAR"
http_get 'me' "$LOCK_JAR" >/dev/null
LOCK_CSRF=$(jf '.csrf')
for i in 1 2 3 4 5; do
  st=$(http_post 'login_child' "$LOCK_JAR" "$LOCK_CSRF" "{\"child_id\":\"$CHILD1_ID\",\"pin\":\"0000\"}")
done
assert_status "5回連続失敗後も401" 401 "$st"

st=$(http_post 'login_child' "$LOCK_JAR" "$LOCK_CSRF" "{\"child_id\":\"$CHILD1_ID\",\"pin\":\"1234\"}")
assert_status "ロック中は正しいPINでもログイン不可(429)" 429 "$st"

# ==================================================================
echo "== 残高・集計・取り消し・項目後変更の不変性 =="
# ==================================================================

# たろうの申請(ENTRY1_ID, +100)を承認
st=$(http_post 'approve_entry' "$PARENT_JAR" "$PARENT_CSRF" "{\"entry_id\":\"$ENTRY1_ID\"}")
assert_status "やったよ申請を承認" 200 "$st"

st=$(http_get 'state' "$CHILD1_JAR")
BAL=$(jf '.balance')
assert_true "承認後の残高=100" "$([ "$BAL" = "100" ] && echo true || echo false)" "balance=$BAL"

# 項目のポイントを後で変更しても、過去の記録(entry)は変わらない
st=$(http_post 'items_update' "$PARENT_JAR" "$PARENT_CSRF" "{\"id\":\"$ITEM_PLUS_ID\",\"points\":9999}")
assert_status "項目のポイントを変更" 200 "$st"

st=$(http_get 'entries' "$CHILD1_JAR")
STORED_POINTS=$(jf ".entries[] | select(.id==\"$ENTRY1_ID\") | .points")
assert_true "項目変更後もentryのpointsは元の値(100)のまま" "$([ "$STORED_POINTS" = "100" ] && echo true || echo false)" "points=$STORED_POINTS"

st=$(http_get 'state' "$CHILD1_JAR")
BAL2=$(jf '.balance')
assert_true "項目変更後も残高は変わらず100" "$([ "$BAL2" = "100" ] && echo true || echo false)" "balance=$BAL2"

# 親が承認済みentryを取り消す→残高が戻る
st=$(http_post 'cancel_entry' "$PARENT_JAR" "$PARENT_CSRF" "{\"entry_id\":\"$ENTRY1_ID\"}")
assert_status "承認済みentryを取り消し" 200 "$st"

st=$(http_get 'state' "$CHILD1_JAR")
BAL3=$(jf '.balance')
assert_true "取り消し後の残高=0" "$([ "$BAL3" = "0" ] && echo true || echo false)" "balance=$BAL3"

# 月次カレンダー集計: 直接付与で+100と-10を作り、集計を確認
TODAY=$(date +%F)
YEAR=$(date +%Y)
MONTH=$(date +%-m)
st=$(http_post 'direct_add' "$PARENT_JAR" "$PARENT_CSRF" "{\"child_id\":\"$CHILD1_ID\",\"points\":100,\"memo\":\"cal-test-plus\",\"done_date\":\"$TODAY\"}")
assert_status "直接付与+100" 200 "$st"
st=$(http_post 'direct_add' "$PARENT_JAR" "$PARENT_CSRF" "{\"child_id\":\"$CHILD1_ID\",\"points\":-10,\"memo\":\"cal-test-minus\",\"done_date\":\"$TODAY\"}")
assert_status "直接付与-10" 200 "$st"

st=$(http_get 'calendar' "$CHILD1_JAR" "&year=${YEAR}&month=${MONTH}")
EARNED=$(jf '.month_summary.earned')
REDUCED=$(jf '.month_summary.reduced')
assert_true "月次集計: もらった>=100" "$([ "$EARNED" -ge 100 ] 2>/dev/null && echo true || echo false)" "earned=$EARNED"
assert_true "月次集計: 減った>=10" "$([ "$REDUCED" -ge 10 ] 2>/dev/null && echo true || echo false)" "reduced=$REDUCED"

st=$(http_get 'state' "$CHILD1_JAR")
BAL4=$(jf '.balance')
assert_true "残高=90(100-10)" "$([ "$BAL4" = "90" ] && echo true || echo false)" "balance=$BAL4"

# ==================================================================
echo "== 利用申請の上限（申請中の分も数える）と承認時の再確認 =="
# ==================================================================

# 現在残高90。60ポイント使う申請→OK（レートX=10なので60は倍数）
st=$(http_post 'request_use' "$CHILD1_JAR" "$CHILD1_CSRF" '{"points":60,"memo":"use1"}')
assert_status "つかう申請 60P (残高90 → 可)" 200 "$st"
USE1_ID=$(jf '.entry.id')
USE1_YEN=$(jf '.entry.yen')
assert_true "使う60P=48円(X=10,Y=8)" "$([ "$USE1_YEN" = "48" ] && echo true || echo false)" "yen=$USE1_YEN"

# 残り使える枠は 90-60=30。40ポイント使おうとする→上限超えて拒否
st=$(http_post 'request_use' "$CHILD1_JAR" "$CHILD1_CSRF" '{"points":40,"memo":"use2"}')
assert_status "つかう申請 40P (残り枠30 → 拒否)" 400 "$st"

# レートの倍数でない申請は拒否 (X=10)
st=$(http_post 'request_use' "$CHILD1_JAR" "$CHILD1_CSRF" '{"points":15,"memo":"use3"}')
assert_status "つかう申請 15P (Xの倍数でない → 拒否)" 400 "$st"

# 承認前に親が直近の付与(+100)を取り消して残高を減らす→60P承認時に足りずエラーになることを確認
# 現在の90の内訳: 100(direct)-10(direct) 。100分のentryを特定して取り消す
st=$(http_get 'entries' "$PARENT_JAR" "&child_id=${CHILD1_ID}&status=approved")
PLUS100_ID=$(jf '[.entries[] | select(.points==100 and .memo=="cal-test-plus")][0].id')
st=$(http_post 'cancel_entry' "$PARENT_JAR" "$PARENT_CSRF" "{\"entry_id\":\"$PLUS100_ID\"}")
assert_status "承認済み+100を取り消し（残高を意図的に減らす）" 200 "$st"

# これで承認済み残高は 90-100=-10 。60Pの利用申請を承認しようとすると残高不足でエラーになるはず
st=$(http_post 'approve_entry' "$PARENT_JAR" "$PARENT_CSRF" "{\"entry_id\":\"$USE1_ID\"}")
assert_status "残高不足になった利用申請の承認→エラー" 400 "$st"

# 後片付け: 取り消した+100を戻す（直接付与しなおす）、利用申請は却下しておく
http_post 'direct_add' "$PARENT_JAR" "$PARENT_CSRF" "{\"child_id\":\"$CHILD1_ID\",\"points\":100,\"memo\":\"restore\",\"done_date\":\"$TODAY\"}" >/dev/null
st=$(http_post 'reject_entry' "$PARENT_JAR" "$PARENT_CSRF" "{\"entry_id\":\"$USE1_ID\",\"reason\":\"test-cleanup\"}")
assert_status "利用申請を却下してクリーンアップ" 200 "$st"

# ==================================================================
echo "== 同時書き込み（PHP_CLI_SERVER_WORKERS=4で20件を並行付与） =="
# ==================================================================

st=$(http_get 'state' "$CHILD2_JAR")
BEFORE_BAL=$(jf '.balance')

CONC_PIDS=()
for i in $(seq 1 20); do
  (
    curl -s -o "/tmp/kp_test_concurrent_${i}" -w '%{http_code}' -X POST \
      -H 'Content-Type: application/json' -H "X-CSRF-Token: ${PARENT_CSRF}" \
      -b "$PARENT_JAR" \
      -d "{\"child_id\":\"$CHILD2_ID\",\"points\":1,\"memo\":\"concurrent-${i}\"}" \
      "$BASE/api.php?action=direct_add" > "/tmp/kp_test_concurrent_status_${i}"
  ) &
  CONC_PIDS+=($!)
done
for pid in "${CONC_PIDS[@]}"; do
  wait "$pid"
done

CONC_OK=0
CONC_BROKEN_JSON=0
for i in $(seq 1 20); do
  code=$(cat "/tmp/kp_test_concurrent_status_${i}" 2>/dev/null || echo "")
  if [ "$code" = "200" ]; then
    CONC_OK=$((CONC_OK + 1))
  fi
  if ! jq -e . "/tmp/kp_test_concurrent_${i}" >/dev/null 2>&1; then
    CONC_BROKEN_JSON=$((CONC_BROKEN_JSON + 1))
  fi
done
assert_true "同時書き込み20件とも200" "$([ "$CONC_OK" = "20" ] && echo true || echo false)" "成功=$CONC_OK/20"
assert_true "同時書き込みでJSONが壊れたレスポンスは無い" "$([ "$CONC_BROKEN_JSON" = "0" ] && echo true || echo false)" "壊れたレスポンス=$CONC_BROKEN_JSON件"

st=$(http_get 'state' "$CHILD2_JAR")
AFTER_BAL=$(jf '.balance')
EXPECTED=$((BEFORE_BAL + 20))
assert_true "同時書き込み後の残高が20件分そろっている" "$([ "$AFTER_BAL" = "$EXPECTED" ] && echo true || echo false)" "before=$BEFORE_BAL after=$AFTER_BAL expected=$EXPECTED"

# データファイル自体のJSONも壊れていないことを確認
if [ -f "$DATA_DIR/kids-point.json" ] && jq -e . "$DATA_DIR/kids-point.json" >/dev/null 2>&1; then
  ok "データファイルのJSONは壊れていない"
else
  ng "データファイルのJSONは壊れていない" "kids-point.json が不正 or 存在しない"
fi

# ==================================================================
echo "== レスポンスにハッシュが含まれていないこと =="
# ==================================================================

HASH_FOUND=0
for action_query in "accounts::${PARENT_JAR}" "me::${PARENT_JAR}" "me::${CHILD1_JAR}"; do
  action="${action_query%%::*}"
  jar="${action_query##*::}"
  http_get "$action" "$jar" >/dev/null
  if grep -qi 'hash' "$RESP"; then
    HASH_FOUND=1
    echo "      hash を含む可能性のあるレスポンス($action): $(cat "$RESP")"
  fi
done
assert_true "accounts/me のレスポンスにハッシュ関連キーが無い" "$([ "$HASH_FOUND" = 0 ] && echo true || echo false)" "hash を含むレスポンスが見つかった"

# login_child のレスポンスにも pin_hash 等が含まれないこと
st=$(http_get 'me' "$ANON_JAR")
ANON_CSRF=$(jf '.csrf')
st=$(http_post 'login_child' "$ANON_JAR" "$ANON_CSRF" "{\"child_id\":\"$CHILD2_ID\",\"pin\":\"5678\"}")
if grep -qi 'hash' "$RESP"; then
  ng "login_child レスポンスにハッシュが無い" "$(cat "$RESP")"
else
  ok "login_child レスポンスにハッシュが無い"
fi

# ==================================================================
echo "== M1: セッション切れ後の最初のPOSTがCSRFで失敗し、meで回復できる =="
# ==================================================================

# 子1は既にログイン済み(CHILD1_JAR)。KPSESSIDだけを落として kp_remember だけの状態を作る
# （セッションが切れた後、ホーム画面から開き直した状態を模す）
EXPIRED_JAR=/tmp/kp_test_jar_expired_session
cp "$CHILD1_JAR" "$EXPIRED_JAR"
grep -v $'\tKPSESSID\t' "$EXPIRED_JAR" > "${EXPIRED_JAR}.tmp" && mv "${EXPIRED_JAR}.tmp" "$EXPIRED_JAR"

# 古いCSRFトークン(CHILD1_CSRF)でPOSTすると、サーバは保持cookieで裏で再ログインするが
# トークンはまだ古いものを送っているので403 csrfになる
st=$(http_post 'request_earn' "$EXPIRED_JAR" "$CHILD1_CSRF" "{\"item_id\":\"$ITEM_PLUS_ID\",\"done_date\":\"$(date +%F)\",\"memo\":\"expired-session\"}")
assert_status "セッション切れ後、古いCSRFトークンでのPOSTは403" 403 "$st"
EXPIRED_ERROR=$(jf '.error')
assert_true "エラーコードがcsrf" "$([ "$EXPIRED_ERROR" = "csrf" ] && echo true || echo false)" "error=$EXPIRED_ERROR"

# me を呼ぶと、保持cookieから裏で再ログインし、新しいCSRFトークンとauthenticated=trueが返る
st=$(http_get 'me' "$EXPIRED_JAR")
assert_status "me で保持cookieから再ログイン" 200 "$st"
NEW_AUTH=$(jf '.authenticated')
assert_true "再ログイン後 authenticated=true" "$([ "$NEW_AUTH" = "true" ] && echo true || echo false)" "authenticated=$NEW_AUTH"
NEW_CSRF=$(jf '.csrf')
assert_true "新しいCSRFトークンは古いものと異なる" "$([ "$NEW_CSRF" != "$CHILD1_CSRF" ] && echo true || echo false)" "new=$NEW_CSRF old=$CHILD1_CSRF"

# 新しいトークンで再送すると成功する
st=$(http_post 'request_earn' "$EXPIRED_JAR" "$NEW_CSRF" "{\"item_id\":\"$ITEM_PLUS_ID\",\"done_date\":\"$(date +%F)\",\"memo\":\"retry-ok\"}")
assert_status "新しいCSRFトークンで再送すると成功" 200 "$st"

# ==================================================================
echo "== M2: 保持トークン差し替えの競合（セッション無しの同時アクセス2本） =="
# ==================================================================

# 子2のセッションから KPSESSID を落とし、kp_remember だけを残した状態を作る
NOSESSION_JAR=/tmp/kp_test_jar_child2_nosession
cp "$CHILD2_JAR" "$NOSESSION_JAR"
grep -v $'\tKPSESSID\t' "$NOSESSION_JAR" > "${NOSESSION_JAR}.tmp" && mv "${NOSESSION_JAR}.tmp" "$NOSESSION_JAR"

# 同じ remember cookie を持つリクエストを、セッション無しで2本同時に送る
curl -s -b "$NOSESSION_JAR" -o /tmp/kp_test_out_1.json -w '%{http_code}' "$BASE/api.php?action=me" > /tmp/kp_test_out_status_1 &
CONC1=$!
curl -s -b "$NOSESSION_JAR" -o /tmp/kp_test_out_2.json -w '%{http_code}' "$BASE/api.php?action=me" > /tmp/kp_test_out_status_2 &
CONC2=$!
wait "$CONC1"
wait "$CONC2"

CODE1=$(cat /tmp/kp_test_out_status_1)
CODE2=$(cat /tmp/kp_test_out_status_2)
AUTH1=$(jq -r '.authenticated' /tmp/kp_test_out_1.json 2>/dev/null)
AUTH2=$(jq -r '.authenticated' /tmp/kp_test_out_2.json 2>/dev/null)
assert_true "M2: 同時アクセス1本目が200かつauthenticated=true" "$([ "$CODE1" = "200" ] && [ "$AUTH1" = "true" ] && echo true || echo false)" "code1=$CODE1 auth1=$AUTH1"
assert_true "M2: 同時アクセス2本目が200かつauthenticated=true" "$([ "$CODE2" = "200" ] && [ "$AUTH2" = "true" ] && echo true || echo false)" "code2=$CODE2 auth2=$AUTH2"

# ==================================================================
echo "== M3: 存在しないchild_id/login_idはIPバケットにのみ記録され、期限切れは掃除される =="
# ==================================================================

FAKE_JAR=/tmp/kp_test_jar_fake
rm -f "$FAKE_JAR"
http_get 'me' "$FAKE_JAR" >/dev/null
FAKE_CSRF=$(jf '.csrf')

st=$(http_post 'login_child' "$FAKE_JAR" "$FAKE_CSRF" '{"child_id":"no-such-child-xyz","pin":"0000"}')
assert_status "存在しないchild_idでログイン試行→401" 401 "$st"
FAKE_CHILD_BUCKET=$(jq -r '.login_failures | has("child:no-such-child-xyz")' "$DATA_DIR/kids-point.json")
assert_true "存在しないchild_id用のバケットは作られない" "$([ "$FAKE_CHILD_BUCKET" = "false" ] && echo true || echo false)" "has bucket=$FAKE_CHILD_BUCKET"

st=$(http_post 'login_parent' "$FAKE_JAR" "$FAKE_CSRF" '{"login_id":"no-such-parent-xyz","password":"whatever123"}')
assert_status "存在しないlogin_idでログイン試行→401" 401 "$st"
FAKE_PARENT_BUCKET=$(jq -r '.login_failures | has("parent:no-such-parent-xyz")' "$DATA_DIR/kids-point.json")
assert_true "存在しないlogin_id用のバケットは作られない" "$([ "$FAKE_PARENT_BUCKET" = "false" ] && echo true || echo false)" "has bucket=$FAKE_PARENT_BUCKET"

IP_BUCKET_EXISTS=$(jq -r '[.login_failures | keys[] | select(startswith("ip:"))] | length > 0' "$DATA_DIR/kids-point.json")
assert_true "IPバケットは記録されている" "$([ "$IP_BUCKET_EXISTS" = "true" ] && echo true || echo false)" "ip bucket exists=$IP_BUCKET_EXISTS"

# 期限切れバケット・保持トークンが実際に掃除されることを確認する（データファイルへ古いレコードを仕込んで検証）
python3 - "$DATA_DIR/kids-point.json" <<'PYEOF'
import json, sys, time
path = sys.argv[1]
with open(path) as f:
    data = json.load(f)
old_time = int(time.time()) - 3600  # 1時間前（15分の窓の外）
data['login_failures']['ip:203.0.113.99'] = {'attempts': [old_time], 'locked_until': None}
data['remember_tokens']['0' * 64] = {'user_id': 'nobody', 'expires_at': old_time, 'rotated_at': None}
with open(path, 'w') as f:
    json.dump(data, f)
PYEOF

# 何か1回ログイン処理を行わせ、pruneExpired が走ることを確認する
http_post 'login_child' "$FAKE_JAR" "$FAKE_CSRF" '{"child_id":"no-such-child-xyz","pin":"0000"}' >/dev/null

STALE_IP_BUCKET=$(jq -r '.login_failures | has("ip:203.0.113.99")' "$DATA_DIR/kids-point.json")
assert_true "1時間前の失敗記録(login_failures)は掃除される" "$([ "$STALE_IP_BUCKET" = "false" ] && echo true || echo false)" "has stale bucket=$STALE_IP_BUCKET"
STALE_TOKEN=$(jq -r --arg h "$(printf '0%.0s' {1..64})" '.remember_tokens | has($h)' "$DATA_DIR/kids-point.json")
assert_true "期限切れのremember_tokenは掃除される" "$([ "$STALE_TOKEN" = "false" ] && echo true || echo false)" "has stale token=$STALE_TOKEN"

# ==================================================================
echo "== M4: 無効化したアカウントのセッションは即座に401になる =="
# ==================================================================

st=$(http_post 'account_update_child' "$PARENT_JAR" "$PARENT_CSRF" "{\"id\":\"$CHILD2_ID\",\"active\":false}")
assert_status "子2を無効化" 200 "$st"

st=$(http_get 'state' "$CHILD2_JAR")
assert_status "無効化された子2のセッションでstate→401" 401 "$st"

# 後片付け: 子2を再有効化しておく
http_post 'account_update_child' "$PARENT_JAR" "$PARENT_CSRF" "{\"id\":\"$CHILD2_ID\",\"active\":true}" >/dev/null

# ==================================================================
echo "== 軽微指摘: requireInt/optionalIntの厳格化・却下理由の分離・色の検証 =="
# ==================================================================

# m1: "1e3" や 極端に大きい数値は拒否される
st=$(http_post 'items_update' "$PARENT_JAR" "$PARENT_CSRF" "{\"id\":\"$ITEM_PLUS_ID\",\"points\":\"1e3\"}")
assert_status "m1: points='1e3' は拒否される" 400 "$st"

st=$(http_post 'items_update' "$PARENT_JAR" "$PARENT_CSRF" "{\"id\":\"$ITEM_PLUS_ID\",\"points\":10.7}")
assert_status "m1: points=10.7(小数) は拒否される" 400 "$st"

st=$(http_post 'direct_add' "$PARENT_JAR" "$PARENT_CSRF" "{\"child_id\":\"$CHILD1_ID\",\"points\":9999999,\"memo\":\"toolarge\"}")
assert_status "m1: 絶対値が大きすぎるpointsは拒否される" 400 "$st"

st=$(http_post 'items_update' "$PARENT_JAR" "$PARENT_CSRF" "{\"id\":\"$ITEM_PLUS_ID\",\"points\":-5}")
assert_status "m1: 負の整数は正しく通る" 200 "$st"
# 元に戻す
http_post 'items_update' "$PARENT_JAR" "$PARENT_CSRF" "{\"id\":\"$ITEM_PLUS_ID\",\"points\":100}" >/dev/null

# m2: 却下理由は reject_reason に分離され、memo は変わらない
st=$(http_post 'request_earn' "$CHILD1_JAR" "$CHILD1_CSRF" "{\"item_id\":\"$ITEM_PLUS_ID\",\"done_date\":\"$(date +%F)\",\"memo\":\"元のメモ\"}")
assert_status "m2用: やったよ申請を作成" 200 "$st"
REJECT_TARGET_ID=$(jf '.entry.id')
st=$(http_post 'reject_entry' "$PARENT_JAR" "$PARENT_CSRF" "{\"entry_id\":\"$REJECT_TARGET_ID\",\"reason\":\"証拠写真がない\"}")
assert_status "m2用: 却下する" 200 "$st"

st=$(http_get 'entries' "$CHILD1_JAR")
REJECTED_MEMO=$(jf "[.entries[] | select(.id==\"$REJECT_TARGET_ID\")][0].memo")
REJECTED_REASON=$(jf "[.entries[] | select(.id==\"$REJECT_TARGET_ID\")][0].reject_reason")
assert_true "m2: memoは書き換えられていない" "$([ "$REJECTED_MEMO" = "元のメモ" ] && echo true || echo false)" "memo=$REJECTED_MEMO"
assert_true "m2: reject_reasonに理由が入る" "$([ "$REJECTED_REASON" = "証拠写真がない" ] && echo true || echo false)" "reject_reason=$REJECTED_REASON"

# m3: color は #rrggbb 形式以外拒否される
st=$(http_post 'account_create_child' "$PARENT_JAR" "$PARENT_CSRF" '{"name":"じろう","color":"not-a-color","pin":"1111"}')
assert_status "m3: 不正なcolorは拒否される" 400 "$st"

st=$(http_post 'account_create_child' "$PARENT_JAR" "$PARENT_CSRF" '{"name":"じろう","color":"#AbC123","pin":"1111"}')
assert_status "m3: 正しい形式のcolorは通る" 200 "$st"

# ==================================================================
echo "== 追加仕様: 繰越(carryover) =="
# ==================================================================

# 子の権限では carryover を作れない（direct_add 自体が親専用・403）
st=$(http_post 'direct_add' "$CHILD1_JAR" "$CHILD1_CSRF" "{\"child_id\":\"$CHILD1_ID\",\"points\":500,\"carryover\":true,\"memo\":\"x\"}")
assert_status "carryover: 子がdirect_add(carryover)しようとすると403" 403 "$st"

st=$(http_post 'account_create_child' "$CHILD1_JAR" "$CHILD1_CSRF" '{"name":"だめ","pin":"2222","carryover_points":100}')
assert_status "carryover: 子がaccount_create_child(carryover_points)しようとすると403" 403 "$st"

# 直接付けるでcarryoverを作る（親）
st=$(http_get 'state' "$CHILD1_JAR")
BAL_BEFORE_CARRY=$(jf '.balance')
YEAR_C=$(date +%Y)
MONTH_C=$(date +%-m)
st=$(http_get 'calendar' "$CHILD1_JAR" "&year=${YEAR_C}&month=${MONTH_C}")
EARNED_BEFORE_CARRY=$(jf '.month_summary.earned')
CARRY_BEFORE=$(jf '.month_summary.carryover')

st=$(http_post 'direct_add' "$PARENT_JAR" "$PARENT_CSRF" "{\"child_id\":\"$CHILD1_ID\",\"points\":1000,\"carryover\":true,\"memo\":\"他のアプリからの引き継ぎ\",\"done_date\":\"$(date +%F)\"}")
assert_status "carryover: 直接付ける(carryover)で作成" 200 "$st"
CARRYOVER_ENTRY_ID=$(jf '.entry.id')
CARRYOVER_TYPE=$(jf '.entry.type')
assert_true "carryover: entryのtypeがcarryover" "$([ "$CARRYOVER_TYPE" = "carryover" ] && echo true || echo false)" "type=$CARRYOVER_TYPE"

st=$(http_get 'state' "$CHILD1_JAR")
BAL_AFTER_CARRY=$(jf '.balance')
EXPECTED_BAL_AFTER_CARRY=$((BAL_BEFORE_CARRY + 1000))
assert_true "carryover: 残高に1000加算される" "$([ "$BAL_AFTER_CARRY" = "$EXPECTED_BAL_AFTER_CARRY" ] && echo true || echo false)" "before=$BAL_BEFORE_CARRY after=$BAL_AFTER_CARRY"

st=$(http_get 'calendar' "$CHILD1_JAR" "&year=${YEAR_C}&month=${MONTH_C}")
EARNED_AFTER_CARRY=$(jf '.month_summary.earned')
CARRY_AFTER=$(jf '.month_summary.carryover')
EXPECTED_CARRY_AFTER=$((CARRY_BEFORE + 1000))
assert_true "carryover: earnedには加算されない" "$([ "$EARNED_AFTER_CARRY" = "$EARNED_BEFORE_CARRY" ] && echo true || echo false)" "earned before=$EARNED_BEFORE_CARRY after=$EARNED_AFTER_CARRY"
assert_true "carryover: month_summary.carryoverに出る" "$([ "$CARRY_AFTER" = "$EXPECTED_CARRY_AFTER" ] && echo true || echo false)" "carryover before=$CARRY_BEFORE after=$CARRY_AFTER"

# 取り消すと残高から消える
st=$(http_post 'cancel_entry' "$PARENT_JAR" "$PARENT_CSRF" "{\"entry_id\":\"$CARRYOVER_ENTRY_ID\"}")
assert_status "carryover: 取り消し" 200 "$st"
st=$(http_get 'state' "$CHILD1_JAR")
BAL_AFTER_CANCEL=$(jf '.balance')
assert_true "carryover: 取り消すと残高から消える" "$([ "$BAL_AFTER_CANCEL" = "$BAL_BEFORE_CARRY" ] && echo true || echo false)" "before=$BAL_BEFORE_CARRY after_cancel=$BAL_AFTER_CANCEL"

# account_create_child の carryover_points から作られる場合
st=$(http_post 'account_create_child' "$PARENT_JAR" "$PARENT_CSRF" '{"name":"さぶろう","pin":"3333","carryover_points":-200}')
assert_status "carryover: account_create_childのcarryover_pointsで作成" 200 "$st"
SABURO_ID=$(jf '.account.id')
st=$(http_get 'state' "$PARENT_JAR" "&child_id=${SABURO_ID}")
SABURO_BAL=$(jf '.balance')
assert_true "carryover: account_create_childのcarryover_pointsが残高に反映(-200)" "$([ "$SABURO_BAL" = "-200" ] && echo true || echo false)" "balance=$SABURO_BAL"
st=$(http_get 'entries' "$PARENT_JAR" "&child_id=${SABURO_ID}")
SABURO_ENTRY_TYPE=$(jf '.entries[0].type')
SABURO_ENTRY_MEMO=$(jf '.entries[0].memo')
assert_true "carryover: account作成時のentryもcarryover型" "$([ "$SABURO_ENTRY_TYPE" = "carryover" ] && echo true || echo false)" "type=$SABURO_ENTRY_TYPE"
assert_true "carryover: account作成時のmemoは「引き継ぎ」" "$([ "$SABURO_ENTRY_MEMO" = "引き継ぎ" ] && echo true || echo false)" "memo=$SABURO_ENTRY_MEMO"

# ==================================================================
echo "== 強化1: 親のパスワードは12文字以上 =="
# ==================================================================

# account_create_parent: 11文字は拒否、12文字は通る
st=$(http_post 'account_create_parent' "$PARENT_JAR" "$PARENT_CSRF" '{"login_id":"tsuma11","password":"abcdefghijk"}')
assert_status "パスワード作成: 11文字は拒否される" 400 "$st"

st=$(http_post 'account_create_parent' "$PARENT_JAR" "$PARENT_CSRF" '{"login_id":"tsuma12","password":"abcdefghijkl"}')
assert_status "パスワード作成: 12文字は通る" 200 "$st"
TSUMA_ID=$(jf '.account.id')

# account_update_parent: 11文字は拒否、12文字は通る
st=$(http_post 'account_update_parent' "$PARENT_JAR" "$PARENT_CSRF" "{\"id\":\"$TSUMA_ID\",\"password\":\"bcdefghijkl\"}")
assert_status "パスワード変更: 11文字は拒否される" 400 "$st"

st=$(http_post 'account_update_parent' "$PARENT_JAR" "$PARENT_CSRF" "{\"id\":\"$TSUMA_ID\",\"password\":\"bcdefghijklm\"}")
assert_status "パスワード変更: 12文字は通る" 200 "$st"

# ==================================================================
echo "== 強化2: 検索エンジンに載せない =="
# ==================================================================

curl -s -o /tmp/kp_test_index.html "$BASE/index.html"
if grep -qi 'name="robots"' /tmp/kp_test_index.html && grep -qi 'noindex' /tmp/kp_test_index.html; then
  ok "index.htmlにrobots noindexのmetaタグがある"
else
  ng "index.htmlにrobots noindexのmetaタグがある" "見つからなかった"
fi

ROBOTS_HEADER=$(curl -s -D - -o /dev/null "$BASE/api.php?action=me" | grep -i '^X-Robots-Tag:')
if echo "$ROBOTS_HEADER" | grep -qi 'noindex' && echo "$ROBOTS_HEADER" | grep -qi 'nofollow'; then
  ok "APIのレスポンスにX-Robots-Tagヘッダーが付いている"
else
  ng "APIのレスポンスにX-Robots-Tagヘッダーが付いている" "header=$ROBOTS_HEADER"
fi

# ==================================================================
echo "== 検索機能(action=search、親のみ) =="
# ==================================================================

urlenc() {
  jq -rn --arg s "$1" '$s|@uri'
}

# 子のセッションでは403
st=$(http_get 'search' "$CHILD1_JAR" "&q=$(urlenc 'テスト')")
assert_status "検索: 子のセッションでは403" 403 "$st"

# qが空なら400
st=$(http_get 'search' "$PARENT_JAR" "&q=$(urlenc '   ')")
assert_status "検索: qが空(空白のみ)なら400" 400 "$st"
st=$(http_get 'search' "$PARENT_JAR" "&q=")
assert_status "検索: qが未指定なら400" 400 "$st"

# memoに含まれる語で見つかる
st=$(http_post 'direct_add' "$PARENT_JAR" "$PARENT_CSRF" "{\"child_id\":\"$CHILD1_ID\",\"points\":30,\"memo\":\"実力テストで100点\",\"done_date\":\"$(date +%F)\"}")
assert_status "検索用: memoに『実力テスト』を含む記録を作成" 200 "$st"
MEMO_ENTRY_ID=$(jf '.entry.id')

st=$(http_get 'search' "$PARENT_JAR" "&q=$(urlenc '実力テスト')&child_id=${CHILD1_ID}")
assert_status "検索: memoの語で検索" 200 "$st"
MEMO_HIT=$(jf "[.entries[] | select(.id==\"$MEMO_ENTRY_ID\")] | length")
assert_true "検索: memoの語でヒットする" "$([ "$MEMO_HIT" = "1" ] && echo true || echo false)" "hit=$MEMO_HIT"

# 項目名(item_name)に含まれる語で見つかる（既存項目「おてつだい」を使った記録があるはず）
st=$(http_get 'search' "$PARENT_JAR" "&q=$(urlenc 'おてつだい')&child_id=${CHILD1_ID}")
assert_status "検索: item_nameの語で検索" 200 "$st"
ITEMNAME_COUNT=$(jf '.count')
assert_true "検索: item_nameの語でヒットする" "$([ "$ITEMNAME_COUNT" -ge 1 ] 2>/dev/null && echo true || echo false)" "count=$ITEMNAME_COUNT"

# reject_reasonに含まれる語でも見つかる（既存のm2用テストで却下理由『証拠写真がない』を付けたentryがある）
st=$(http_get 'search' "$PARENT_JAR" "&q=$(urlenc '証拠写真')&child_id=${CHILD1_ID}")
REJREASON_COUNT=$(jf '.count')
assert_true "検索: reject_reasonの語でヒットする" "$([ "$REJREASON_COUNT" -ge 1 ] 2>/dev/null && echo true || echo false)" "count=$REJREASON_COUNT"

# 2語のAND（片方しか含まない記録は出ない）
http_post 'direct_add' "$PARENT_JAR" "$PARENT_CSRF" "{\"child_id\":\"$CHILD1_ID\",\"points\":5,\"memo\":\"アルファ ベータ\",\"done_date\":\"$(date +%F)\"}" >/dev/null
http_post 'direct_add' "$PARENT_JAR" "$PARENT_CSRF" "{\"child_id\":\"$CHILD1_ID\",\"points\":5,\"memo\":\"アルファ ガンマ\",\"done_date\":\"$(date +%F)\"}" >/dev/null

st=$(http_get 'search' "$PARENT_JAR" "&q=$(urlenc 'アルファ ベータ')&child_id=${CHILD1_ID}")
assert_status "検索: 2語AND" 200 "$st"
AND_HAS_BETA=$(jf '[.entries[] | select(.memo=="アルファ ベータ")] | length')
AND_HAS_GAMMA=$(jf '[.entries[] | select(.memo=="アルファ ガンマ")] | length')
assert_true "検索: ANDで両方含む記録だけヒット(ベータ)" "$([ "$AND_HAS_BETA" = "1" ] && echo true || echo false)" "count=$AND_HAS_BETA"
assert_true "検索: ANDで片方しか含まない記録は出ない(ガンマ)" "$([ "$AND_HAS_GAMMA" = "0" ] && echo true || echo false)" "count=$AND_HAS_GAMMA"

# 全角/半角の違いを区別しない
http_post 'direct_add' "$PARENT_JAR" "$PARENT_CSRF" "{\"child_id\":\"$CHILD1_ID\",\"points\":5,\"memo\":\"半角カナ確認用テスト\",\"done_date\":\"$(date +%F)\"}" >/dev/null
st=$(http_get 'search' "$PARENT_JAR" "&q=$(urlenc 'ﾃｽﾄ')&child_id=${CHILD1_ID}")
HALFKANA_COUNT=$(jf '.count')
assert_true "検索: 半角カナ「ﾃｽﾄ」で全角『てすと』を含む記録が見つかる" "$([ "$HALFKANA_COUNT" -ge 1 ] 2>/dev/null && echo true || echo false)" "count=$HALFKANA_COUNT"

http_post 'direct_add' "$PARENT_JAR" "$PARENT_CSRF" "{\"child_id\":\"$CHILD1_ID\",\"points\":5,\"memo\":\"abc practice\",\"done_date\":\"$(date +%F)\"}" >/dev/null
st=$(http_get 'search' "$PARENT_JAR" "&q=$(urlenc 'ＡＢＣ')&child_id=${CHILD1_ID}")
FULLWIDTH_COUNT=$(jf '.count')
assert_true "検索: 全角「ＡＢＣ」で半角小文字『abc』を含む記録が見つかる" "$([ "$FULLWIDTH_COUNT" -ge 1 ] 2>/dev/null && echo true || echo false)" "count=$FULLWIDTH_COUNT"

# child_idで絞り込める
http_post 'direct_add' "$PARENT_JAR" "$PARENT_CSRF" "{\"child_id\":\"$CHILD2_ID\",\"points\":5,\"memo\":\"絞り込みテスト用\",\"done_date\":\"$(date +%F)\"}" >/dev/null
http_post 'direct_add' "$PARENT_JAR" "$PARENT_CSRF" "{\"child_id\":\"$CHILD1_ID\",\"points\":5,\"memo\":\"絞り込みテスト用\",\"done_date\":\"$(date +%F)\"}" >/dev/null
st=$(http_get 'search' "$PARENT_JAR" "&q=$(urlenc '絞り込みテスト用')&child_id=${CHILD2_ID}")
FILTER_COUNT=$(jf '.count')
FILTER_OTHER=$(jf "[.entries[] | select(.child_id != \"$CHILD2_ID\")] | length")
assert_true "検索: child_idで絞り込んだ件数は1件" "$([ "$FILTER_COUNT" = "1" ] && echo true || echo false)" "count=$FILTER_COUNT"
assert_true "検索: child_idで絞り込むと他の子は含まれない" "$([ "$FILTER_OTHER" = "0" ] && echo true || echo false)" "other=$FILTER_OTHER"

# approved_totalは承認済みの記録だけを合計している
http_post 'direct_add' "$PARENT_JAR" "$PARENT_CSRF" "{\"child_id\":\"$CHILD1_ID\",\"points\":30,\"memo\":\"集計対象A\",\"done_date\":\"$(date +%F)\"}" >/dev/null
http_post 'direct_add' "$PARENT_JAR" "$PARENT_CSRF" "{\"child_id\":\"$CHILD1_ID\",\"points\":20,\"memo\":\"集計対象A\",\"done_date\":\"$(date +%F)\"}" >/dev/null
st=$(http_post 'request_earn' "$CHILD1_JAR" "$CHILD1_CSRF" "{\"item_id\":\"$ITEM_PLUS_ID\",\"done_date\":\"$(date +%F)\",\"memo\":\"集計対象A\"}")
assert_status "検索用: 承認されていない(pending)記録も作成" 200 "$st"

st=$(http_get 'search' "$PARENT_JAR" "&q=$(urlenc '集計対象A')&child_id=${CHILD1_ID}")
assert_status "検索: 集計対象の検索" 200 "$st"
AGG_COUNT=$(jf '.count')
AGG_TOTAL=$(jf '.approved_total')
assert_true "検索: 承認済み以外も件数には含まれる(3件)" "$([ "$AGG_COUNT" = "3" ] && echo true || echo false)" "count=$AGG_COUNT"
assert_true "検索: approved_totalは承認済み(30+20=50)のみ合計" "$([ "$AGG_TOTAL" = "50" ] && echo true || echo false)" "approved_total=$AGG_TOTAL"

# レスポンスにハッシュが含まれない
st=$(http_get 'search' "$PARENT_JAR" "&q=$(urlenc '集計対象A')&child_id=${CHILD1_ID}")
if grep -qi 'hash' "$RESP"; then
  ng "検索レスポンスにハッシュが含まれない" "$(cat "$RESP")"
else
  ok "検索レスポンスにハッシュが含まれない"
fi

# ==================================================================
echo ""
echo "===================================="
echo "PASS=$PASS FAIL=$FAIL"
if [ "$FAIL" -gt 0 ]; then
  echo "失敗したテスト:"
  for n in "${FAILED_NAMES[@]}"; do
    echo "  - $n"
  done
  exit 1
fi
echo "全テスト成功"
exit 0
