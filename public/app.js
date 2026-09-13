'use strict';

// きっずポイント: 画面遷移とAPI呼び出し。素のJavaScriptのみ（フレームワーク無し）。

const state = {
  csrf: null,
  authenticated: false,
  user: null,
  pinChildId: null,
  pinBuffer: '',
  childCalendar: null,
  parentCalendarChildId: null,
  parentCalendarCursor: null,
  useRate: { rate_x: 1, rate_y: 1 },
};

// ---------------- API ----------------

async function api(action, opts) {
  return apiInner(action, opts || {}, false);
}

// isRetry: セッション切れ→CSRF再取得での再送は1回だけに留める（無限ループ防止）
async function apiInner(action, opts, isRetry) {
  const method = opts.method || 'GET';
  let url = 'api.php?action=' + encodeURIComponent(action);
  const fetchOpts = { method: method, credentials: 'same-origin', headers: {} };
  if (method === 'GET') {
    if (opts.body) {
      const params = new URLSearchParams();
      Object.keys(opts.body).forEach(function (k) {
        const v = opts.body[k];
        if (v !== undefined && v !== null && v !== '') {
          params.set(k, v);
        }
      });
      const qs = params.toString();
      if (qs) {
        url += '&' + qs;
      }
    }
  } else {
    fetchOpts.headers['Content-Type'] = 'application/json';
    if (state.csrf) {
      fetchOpts.headers['X-CSRF-Token'] = state.csrf;
    }
    fetchOpts.body = JSON.stringify(opts.body || {});
  }
  const res = await fetch(url, fetchOpts);
  let json = null;
  try {
    json = await res.json();
  } catch (e) {
    json = null;
  }
  if (!res.ok || !json || json.ok === false) {
    const code = json && json.error;

    // セッションが切れた後の最初のPOSTは、サーバが保持cookieから再ログインして新しいCSRFトークンを
    // 発行しているが、画面側はまだ古いトークンを持っている。me で取り直して1回だけ再送する。
    if (method === 'POST' && res.status === 403 && code === 'csrf' && !isRetry) {
      const refreshed = await refreshSessionState();
      if (refreshed) {
        return apiInner(action, opts, true);
      }
      goToLoginScreen('セッションが切れました。もう一度ログインしてください');
      throw new Error('セッションが切れました。もう一度ログインしてください');
    }

    // ログインしていない（アカウント無効化なども含む）ときは、ログイン画面に戻す
    if (res.status === 401 && code === 'not_logged_in') {
      goToLoginScreen('ログインしてください');
      throw new Error('ログインしてください');
    }

    const message = (json && json.message) || ('エラーが発生しました (' + res.status + ')');
    const err = new Error(message);
    err.status = res.status;
    err.payload = json;
    throw err;
  }
  return json;
}

// me を取り直して state.csrf / 認証状態を更新する。ログインし直しが必要なら false を返す
async function refreshSessionState() {
  try {
    const me = await apiInner('me', {}, true);
    state.csrf = me.csrf;
    if (me.authenticated) {
      state.user = me.user;
      state.authenticated = true;
      return true;
    }
    state.authenticated = false;
    state.user = null;
    return false;
  } catch (e) {
    return false;
  }
}

let goingToLogin = false;
function goToLoginScreen(message) {
  state.authenticated = false;
  state.user = null;
  state.childCalendar = null;
  state.parentCalendarChildId = null;
  state.parentCalendarCursor = null;
  if (goingToLogin) {
    return;
  }
  goingToLogin = true;
  loadChildSelect()
    .catch(function () {
      // login_children の取得自体が失敗しても、最低限ログイン画面には切り替える
      showScreen('child-select');
    })
    .finally(function () {
      goingToLogin = false;
      if (message) {
        toast(message);
      }
    });
}

// ---------------- 共通ユーティリティ ----------------

function pad2(n) {
  return String(n).padStart(2, '0');
}
function isoDate(d) {
  return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
}
function todayStr() {
  return isoDate(new Date());
}

function showScreen(name) {
  document.querySelectorAll('.screen').forEach(function (s) {
    s.classList.remove('active');
  });
  const el = document.getElementById('screen-' + name);
  if (el) {
    el.classList.add('active');
  }
  window.scrollTo(0, 0);
}

let toastTimer = null;
function toast(msg) {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(function () {
    el.hidden = true;
  }, 2500);
}

function showError(id, msg) {
  const el = document.getElementById(id);
  el.textContent = msg;
  el.hidden = false;
}
function hideError(id) {
  const el = document.getElementById(id);
  el.hidden = true;
  el.textContent = '';
}

function setBadge(id, count) {
  const el = document.getElementById(id);
  if (count > 0) {
    el.textContent = String(count);
    el.hidden = false;
  } else {
    el.hidden = true;
    el.textContent = '';
  }
}

function statusLabel(s) {
  return { pending: '申請中', approved: '承認済み', rejected: '却下', cancelled: '取り消し' }[s] || s;
}

function entryTitle(e) {
  if (e.type === 'use') {
    return 'つかう ' + Math.abs(e.points) + 'P（' + e.yen + '円）';
  }
  if (e.type === 'carryover') {
    return '繰越';
  }
  return e.item_name ? e.item_name : '手入力';
}

// entry を表示する1枚のカードを作る（ユーザー入力は必ず textContent で出す）
function buildEntryCard(e, opts) {
  opts = opts || {};
  const card = document.createElement('div');
  card.className = 'entry-card';

  const top = document.createElement('div');
  top.className = 'entry-top';
  const title = document.createElement('span');
  title.textContent = entryTitle(e);
  const pts = document.createElement('span');
  pts.className = 'entry-points ' + (e.points >= 0 ? 'plus' : 'minus');
  pts.textContent = (e.points >= 0 ? '+' : '') + e.points + 'P';
  top.appendChild(title);
  top.appendChild(pts);
  card.appendChild(top);

  const meta = document.createElement('div');
  meta.className = 'entry-meta';
  const parts = [];
  if (opts.showChildName && e.child_name) {
    parts.push(e.child_name);
  }
  parts.push(e.done_date || '');
  const metaSpan = document.createElement('span');
  metaSpan.textContent = parts.filter(Boolean).join(' ・ ');
  const badge = document.createElement('span');
  badge.className = 'entry-status status-' + e.status;
  badge.textContent = statusLabel(e.status);
  meta.appendChild(metaSpan);
  meta.appendChild(document.createTextNode(' '));
  meta.appendChild(badge);
  card.appendChild(meta);

  if (e.memo) {
    const memo = document.createElement('div');
    memo.className = 'entry-meta';
    memo.textContent = e.memo;
    card.appendChild(memo);
  }

  if (e.status === 'rejected' && e.reject_reason) {
    const reason = document.createElement('div');
    reason.className = 'entry-meta';
    reason.textContent = '却下理由: ' + e.reject_reason;
    card.appendChild(reason);
  }

  const actions = document.createElement('div');
  actions.className = 'entry-actions';
  let hasActions = false;

  if (opts.allowCancelSelf && e.status === 'pending') {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'secondary-button';
    btn.textContent = '取り下げる';
    btn.addEventListener('click', function () {
      opts.onCancelSelf(e.id);
    });
    actions.appendChild(btn);
    hasActions = true;
  }
  if (opts.allowApprove && e.status === 'pending') {
    const approveBtn = document.createElement('button');
    approveBtn.type = 'button';
    approveBtn.className = 'primary-button';
    approveBtn.textContent = '承認';
    approveBtn.addEventListener('click', function () {
      opts.onApprove(e.id);
    });
    const rejectBtn = document.createElement('button');
    rejectBtn.type = 'button';
    rejectBtn.className = 'secondary-button';
    rejectBtn.textContent = '却下';
    rejectBtn.addEventListener('click', function () {
      opts.onReject(e.id);
    });
    actions.appendChild(approveBtn);
    actions.appendChild(rejectBtn);
    hasActions = true;
  }
  if (opts.allowCancelApproved && e.status === 'approved') {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'secondary-button';
    btn.textContent = '取り消す';
    btn.addEventListener('click', function () {
      opts.onCancelApproved(e.id);
    });
    actions.appendChild(btn);
    hasActions = true;
  }
  if (hasActions) {
    card.appendChild(actions);
  }
  return card;
}

// ---------------- カレンダー ----------------

function shiftCursor(cursor, delta) {
  cursor.month += delta;
  if (cursor.month > 12) {
    cursor.month = 1;
    cursor.year += 1;
  } else if (cursor.month < 1) {
    cursor.month = 12;
    cursor.year -= 1;
  }
}

async function renderCalendar(containerId, childId, cursor, reloadFn) {
  const data = await api('calendar', { body: { child_id: childId, year: cursor.year, month: cursor.month } });
  const container = document.getElementById(containerId);
  container.innerHTML = '';

  const header = document.createElement('div');
  header.className = 'calendar-header';
  const prevBtn = document.createElement('button');
  prevBtn.type = 'button';
  prevBtn.textContent = '＜';
  prevBtn.addEventListener('click', function () {
    shiftCursor(cursor, -1);
    reloadFn();
  });
  const label = document.createElement('span');
  label.className = 'calendar-month-label';
  label.textContent = cursor.year + '年' + cursor.month + '月';
  const nextBtn = document.createElement('button');
  nextBtn.type = 'button';
  nextBtn.textContent = '＞';
  nextBtn.addEventListener('click', function () {
    shiftCursor(cursor, 1);
    reloadFn();
  });
  header.appendChild(prevBtn);
  header.appendChild(label);
  header.appendChild(nextBtn);
  container.appendChild(header);

  const summary = document.createElement('div');
  summary.className = 'calendar-summary';
  summary.textContent =
    'もらった +' + data.month_summary.earned +
    ' ／ 減った -' + data.month_summary.reduced +
    ' ／ 使った -' + data.month_summary.used +
    ' ／ 申請中 ' + data.pending_count + '件';
  container.appendChild(summary);

  if (data.month_summary.carryover) {
    const carrySummary = document.createElement('div');
    carrySummary.className = 'calendar-summary';
    const sign = data.month_summary.carryover >= 0 ? '+' : '';
    carrySummary.textContent = '繰越 ' + sign + data.month_summary.carryover;
    container.appendChild(carrySummary);
  }

  const grid = document.createElement('div');
  grid.className = 'calendar-grid';
  ['日', '月', '火', '水', '木', '金', '土'].forEach(function (d) {
    const el = document.createElement('div');
    el.className = 'calendar-dow';
    el.textContent = d;
    grid.appendChild(el);
  });

  const firstDow = new Date(cursor.year, cursor.month - 1, 1).getDay();
  const daysInMonth = new Date(cursor.year, cursor.month, 0).getDate();
  for (let i = 0; i < firstDow; i++) {
    const el = document.createElement('div');
    el.className = 'calendar-cell empty';
    grid.appendChild(el);
  }
  for (let d = 1; d <= daysInMonth; d++) {
    const dateStr = cursor.year + '-' + pad2(cursor.month) + '-' + pad2(d);
    const cell = document.createElement('div');
    cell.className = 'calendar-cell';
    const num = document.createElement('div');
    num.className = 'day-num';
    num.textContent = String(d);
    cell.appendChild(num);
    const info = data.days[dateStr];
    if (info) {
      cell.classList.add('has-entries');
      if (info.earned > 0) {
        const p = document.createElement('div');
        p.className = 'day-plus';
        p.textContent = '+' + info.earned;
        cell.appendChild(p);
      }
      if (info.reduced > 0) {
        const m = document.createElement('div');
        m.className = 'day-minus';
        m.textContent = '-' + info.reduced;
        cell.appendChild(m);
      }
      if (info.used > 0) {
        const u = document.createElement('div');
        u.className = 'day-use';
        u.textContent = '使 -' + info.used;
        cell.appendChild(u);
      }
      if (info.carryover) {
        const c = document.createElement('div');
        c.className = 'day-carryover';
        const sign = info.carryover >= 0 ? '+' : '';
        c.textContent = '↺ 繰越 ' + sign + info.carryover;
        cell.appendChild(c);
      }
      cell.addEventListener('click', function () {
        openDayModal(childId, dateStr);
      });
    }
    grid.appendChild(cell);
  }
  container.appendChild(grid);
  return data;
}

async function openDayModal(childId, dateStr) {
  try {
    const res = await api('entries', { body: { child_id: childId, date: dateStr } });
    document.getElementById('day-modal-title').textContent = dateStr;
    const list = document.getElementById('day-modal-list');
    list.innerHTML = '';
    res.entries.forEach(function (e) {
      list.appendChild(buildEntryCard(e, { showChildName: state.user && state.user.role === 'parent' }));
    });
    if (!res.entries.length) {
      list.textContent = '記録はありません';
    }
    document.getElementById('day-modal').hidden = false;
  } catch (err) {
    toast(err.message);
  }
}
document.getElementById('day-modal-close').addEventListener('click', function () {
  document.getElementById('day-modal').hidden = true;
});

// ---------------- ログイン（子） ----------------

async function loadChildSelect() {
  const res = await api('login_children');
  const container = document.getElementById('child-buttons');
  container.innerHTML = '';
  res.children.forEach(function (c) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'child-button';
    btn.style.background = c.color || '#4a6fa5';
    btn.textContent = c.name;
    btn.addEventListener('click', function () {
      openPinScreen(c.id, c.name);
    });
    container.appendChild(btn);
  });
  showScreen('child-select');
}

function renderPinDots() {
  const dots = document.querySelectorAll('#pin-dots .pin-dot');
  dots.forEach(function (d, i) {
    d.classList.toggle('filled', i < state.pinBuffer.length);
  });
}

function openPinScreen(childId, childName) {
  state.pinChildId = childId;
  state.pinBuffer = '';
  document.getElementById('pin-child-name').textContent = childName;
  hideError('pin-error');
  renderPinDots();
  showScreen('pin');
}

async function submitPinLogin() {
  try {
    const res = await api('login_child', { method: 'POST', body: { child_id: state.pinChildId, pin: state.pinBuffer } });
    state.csrf = res.csrf;
    state.user = res.user;
    state.authenticated = true;
    state.pinBuffer = '';
    await loadChildHome();
  } catch (err) {
    showError('pin-error', err.message);
    state.pinBuffer = '';
    renderPinDots();
  }
}

document.getElementById('screen-pin').addEventListener('click', function (e) {
  const keyBtn = e.target.closest('.key[data-key]');
  if (!keyBtn) {
    return;
  }
  const key = keyBtn.dataset.key;
  if (key === 'back') {
    state.pinBuffer = state.pinBuffer.slice(0, -1);
  } else if (state.pinBuffer.length < 4) {
    state.pinBuffer += key;
  }
  renderPinDots();
  if (state.pinBuffer.length === 4) {
    submitPinLogin();
  }
});

// ---------------- ログイン（親） ----------------

document.getElementById('parent-login-form').addEventListener('submit', async function (e) {
  e.preventDefault();
  hideError('parent-login-error');
  try {
    const res = await api('login_parent', {
      method: 'POST',
      body: {
        login_id: document.getElementById('parent-login-id').value,
        password: document.getElementById('parent-login-password').value,
      },
    });
    state.csrf = res.csrf;
    state.user = res.user;
    state.authenticated = true;
    document.getElementById('parent-login-password').value = '';
    showScreen('parent-home');
    await refreshParentBadge();
  } catch (err) {
    showError('parent-login-error', err.message);
  }
});

// ---------------- 子: ホーム ----------------

async function refreshChildCalendar(childId) {
  await renderCalendar('child-calendar', childId, state.childCalendar, function () {
    refreshChildCalendar(childId);
  });
}

async function loadChildHome() {
  const st = await api('state');
  document.getElementById('child-home-name').textContent = st.name;
  document.getElementById('child-balance').textContent = st.balance;
  document.getElementById('child-balance-yen').textContent = st.balance_yen;
  setBadge('child-badge', st.pending_count);
  if (!state.childCalendar) {
    const now = new Date();
    state.childCalendar = { year: now.getFullYear(), month: now.getMonth() + 1 };
  }
  await refreshChildCalendar(st.child_id);
  showScreen('child-home');
}

async function refreshChildBadge() {
  const me = await api('me');
  setBadge('child-badge', me.pending_count || 0);
}

// ---------------- 子: やったよ申請 ----------------

async function loadEarnForm() {
  const res = await api('items');
  const select = document.getElementById('earn-item');
  select.innerHTML = '';
  res.items.forEach(function (it) {
    if (!it.active || it.points <= 0) {
      return;
    }
    const opt = document.createElement('option');
    opt.value = it.id;
    opt.textContent = it.name + '（+' + it.points + 'P）';
    select.appendChild(opt);
  });
  document.getElementById('earn-date').value = todayStr();
  document.getElementById('earn-date').max = todayStr();
  const min = new Date();
  min.setDate(min.getDate() - 60);
  document.getElementById('earn-date').min = isoDate(min);
  document.getElementById('earn-memo').value = '';
  hideError('earn-error');
  showScreen('child-earn');
}

document.getElementById('earn-form').addEventListener('submit', async function (e) {
  e.preventDefault();
  hideError('earn-error');
  try {
    await api('request_earn', {
      method: 'POST',
      body: {
        item_id: document.getElementById('earn-item').value,
        done_date: document.getElementById('earn-date').value,
        memo: document.getElementById('earn-memo').value,
      },
    });
    toast('申請しました');
    await loadChildHome();
  } catch (err) {
    showError('earn-error', err.message);
  }
});

// ---------------- 子: つかう申請 ----------------

async function loadUseForm() {
  const st = await api('state');
  state.useRate = st.settings;
  document.getElementById('use-available').textContent = st.available_for_use;
  document.getElementById('use-points').value = '';
  document.getElementById('use-points').step = st.settings.rate_x;
  document.getElementById('use-yen').textContent = '0';
  document.getElementById('use-memo').value = '';
  hideError('use-error');
  showScreen('child-use');
}

document.getElementById('use-points').addEventListener('input', function () {
  const v = parseInt(this.value || '0', 10);
  const rate = state.useRate || { rate_x: 1, rate_y: 1 };
  const yen = isNaN(v) ? 0 : Math.floor(v / rate.rate_x) * rate.rate_y;
  document.getElementById('use-yen').textContent = String(yen);
});

document.getElementById('use-form').addEventListener('submit', async function (e) {
  e.preventDefault();
  hideError('use-error');
  try {
    await api('request_use', {
      method: 'POST',
      body: {
        points: parseInt(document.getElementById('use-points').value, 10),
        memo: document.getElementById('use-memo').value,
      },
    });
    toast('申請しました');
    await loadChildHome();
  } catch (err) {
    showError('use-error', err.message);
  }
});

// ---------------- 子: 申請一覧 ----------------

async function loadChildRequests() {
  const res = await api('entries');
  const container = document.getElementById('child-requests-list');
  container.innerHTML = '';
  res.entries.forEach(function (e) {
    container.appendChild(
      buildEntryCard(e, {
        allowCancelSelf: true,
        onCancelSelf: async function (id) {
          try {
            await api('cancel_request', { method: 'POST', body: { entry_id: id } });
            toast('取り下げました');
            await loadChildRequests();
            await refreshChildBadge();
          } catch (err) {
            toast(err.message);
          }
        },
      })
    );
  });
  if (!res.entries.length) {
    container.textContent = '申請はありません';
  }
  showScreen('child-requests');
}

// ---------------- 親: 承認待ち ----------------

async function loadApprovals() {
  const res = await api('entries', { body: { status: 'pending' } });
  const container = document.getElementById('approvals-list');
  container.innerHTML = '';
  res.entries.forEach(function (e) {
    container.appendChild(
      buildEntryCard(e, {
        showChildName: true,
        allowApprove: true,
        onApprove: async function (id) {
          try {
            await api('approve_entry', { method: 'POST', body: { entry_id: id } });
            toast('承認しました');
            await loadApprovals();
            await refreshParentBadge();
          } catch (err) {
            toast(err.message);
          }
        },
        onReject: async function (id) {
          try {
            await api('reject_entry', { method: 'POST', body: { entry_id: id } });
            toast('却下しました');
            await loadApprovals();
            await refreshParentBadge();
          } catch (err) {
            toast(err.message);
          }
        },
      })
    );
  });
  if (!res.entries.length) {
    container.textContent = '承認待ちはありません';
  }
  showScreen('parent-approvals');
}

async function refreshParentBadge() {
  const me = await api('me');
  setBadge('parent-badge', me.pending_count || 0);
}

// ---------------- 親: 検索 ----------------

async function loadSearchScreen() {
  const accountsRes = await api('accounts');
  const children = accountsRes.accounts.filter(function (a) {
    return a.role === 'child';
  });
  const select = document.getElementById('search-child-select');
  select.innerHTML = '<option value="">全員</option>';
  children.forEach(function (c) {
    const opt = document.createElement('option');
    opt.value = c.id;
    opt.textContent = c.name + (c.active ? '' : '（無効）');
    select.appendChild(opt);
  });
  hideError('search-error');
  document.getElementById('search-summary').textContent = '';
  document.getElementById('search-results').innerHTML = '';
  showScreen('parent-search');
  document.getElementById('search-query').focus();
}

async function runSearch() {
  hideError('search-error');
  const q = document.getElementById('search-query').value;
  const childId = document.getElementById('search-child-select').value;
  if (q.trim() === '') {
    showError('search-error', 'キーワードを入れてください');
    return;
  }
  try {
    const res = await api('search', { body: { q: q, child_id: childId } });
    renderSearchResults(res);
  } catch (err) {
    showError('search-error', err.message);
  }
}

function renderSearchResults(res) {
  const summary = document.getElementById('search-summary');
  const sign = res.approved_total >= 0 ? '+' : '';
  let summaryText = res.count + '件・承認済み合計 ' + sign + res.approved_total + 'P';
  if (res.truncated) {
    summaryText += '（先頭200件を表示）';
  }
  summary.textContent = summaryText;

  const container = document.getElementById('search-results');
  container.innerHTML = '';
  res.entries.forEach(function (e) {
    container.appendChild(
      buildEntryCard(e, {
        showChildName: true,
        allowCancelApproved: true,
        onCancelApproved: async function (id) {
          try {
            await api('cancel_entry', { method: 'POST', body: { entry_id: id } });
            toast('取り消しました');
            await runSearch();
          } catch (err) {
            toast(err.message);
          }
        },
      })
    );
  });
  if (!res.entries.length) {
    container.textContent = '見つかりませんでした';
  }
}

document.getElementById('search-form').addEventListener('submit', function (e) {
  e.preventDefault();
  runSearch();
});

// ---------------- 親: カレンダー ----------------

async function refreshParentCalendar() {
  const childId = state.parentCalendarChildId;
  const data = await renderCalendar('parent-calendar', childId, state.parentCalendarCursor, refreshParentCalendar);
  document.getElementById('parent-cal-balance').textContent = data.balance;
  const rate = state.useRate || { rate_x: 1, rate_y: 1 };
  document.getElementById('parent-cal-balance-yen').textContent = Math.round((data.balance / rate.rate_x) * rate.rate_y);
}

async function loadParentCalendar() {
  const settingsRes = await api('settings');
  state.useRate = settingsRes.settings;
  const accountsRes = await api('accounts');
  const children = accountsRes.accounts.filter(function (a) {
    return a.role === 'child';
  });
  const select = document.getElementById('calendar-child-select');
  select.innerHTML = '';
  children.forEach(function (c) {
    const opt = document.createElement('option');
    opt.value = c.id;
    opt.textContent = c.name + (c.active ? '' : '（無効）');
    select.appendChild(opt);
  });
  if (!state.parentCalendarChildId && children.length) {
    state.parentCalendarChildId = children[0].id;
  }
  select.value = state.parentCalendarChildId || '';
  select.onchange = function () {
    state.parentCalendarChildId = select.value;
    refreshParentCalendar();
  };
  if (!state.parentCalendarCursor) {
    const now = new Date();
    state.parentCalendarCursor = { year: now.getFullYear(), month: now.getMonth() + 1 };
  }
  if (state.parentCalendarChildId) {
    await refreshParentCalendar();
  }
  showScreen('parent-calendar');
}

// ---------------- 親: 直接付ける ----------------

async function loadDirectAddForm() {
  const accountsRes = await api('accounts');
  const children = accountsRes.accounts.filter(function (a) {
    return a.role === 'child' && a.active;
  });
  const childSelect = document.getElementById('direct-child-select');
  childSelect.innerHTML = '';
  children.forEach(function (c) {
    const opt = document.createElement('option');
    opt.value = c.id;
    opt.textContent = c.name;
    childSelect.appendChild(opt);
  });

  const itemsRes = await api('items');
  const itemSelect = document.getElementById('direct-item-select');
  itemSelect.innerHTML = '<option value="">－ 手入力する －</option>';
  itemsRes.items.forEach(function (it) {
    if (!it.active) {
      return;
    }
    const opt = document.createElement('option');
    opt.value = it.id;
    opt.textContent = it.name + '（' + (it.points >= 0 ? '+' : '') + it.points + 'P）';
    itemSelect.appendChild(opt);
  });
  itemSelect.value = '';
  document.getElementById('direct-points').value = '';
  document.getElementById('direct-points').disabled = false;
  document.getElementById('direct-carryover').checked = false;
  document.getElementById('direct-carryover').disabled = false;
  document.getElementById('direct-memo').value = '';
  document.getElementById('direct-date').value = todayStr();
  document.getElementById('direct-date').max = todayStr();
  hideError('direct-error');
  showScreen('parent-direct-add');
}

document.getElementById('direct-item-select').addEventListener('change', function () {
  const isItemMode = !!this.value;
  document.getElementById('direct-points').disabled = isItemMode;
  document.getElementById('direct-carryover').disabled = isItemMode;
  if (isItemMode) {
    document.getElementById('direct-points').value = '';
    document.getElementById('direct-carryover').checked = false;
  }
});

document.getElementById('direct-add-form').addEventListener('submit', async function (e) {
  e.preventDefault();
  hideError('direct-error');
  const itemId = document.getElementById('direct-item-select').value;
  const body = {
    child_id: document.getElementById('direct-child-select').value,
    memo: document.getElementById('direct-memo').value,
    done_date: document.getElementById('direct-date').value,
  };
  if (itemId) {
    body.item_id = itemId;
  } else {
    body.points = parseInt(document.getElementById('direct-points').value, 10);
    body.carryover = document.getElementById('direct-carryover').checked;
  }
  try {
    await api('direct_add', { method: 'POST', body: body });
    toast('付けました');
    await loadDirectAddForm();
  } catch (err) {
    showError('direct-error', err.message);
  }
});

// ---------------- 親: 項目の管理 ----------------

async function loadItemsManage() {
  const res = await api('items');
  const container = document.getElementById('items-list');
  container.innerHTML = '';
  res.items.forEach(function (it, idx) {
    const card = document.createElement('div');
    card.className = 'entry-card';
    const top = document.createElement('div');
    top.className = 'entry-top';
    const name = document.createElement('span');
    name.textContent = it.name + (it.active ? '' : '（無効）');
    const pts = document.createElement('span');
    pts.className = 'entry-points ' + (it.points >= 0 ? 'plus' : 'minus');
    pts.textContent = (it.points >= 0 ? '+' : '') + it.points + 'P';
    top.appendChild(name);
    top.appendChild(pts);
    card.appendChild(top);

    const actions = document.createElement('div');
    actions.className = 'entry-actions';

    const moveUp = document.createElement('button');
    moveUp.type = 'button';
    moveUp.className = 'secondary-button';
    moveUp.textContent = '↑';
    moveUp.disabled = idx === 0;
    moveUp.addEventListener('click', async function () {
      const ids = res.items.map(function (i) { return i.id; });
      const tmp = ids[idx];
      ids[idx] = ids[idx - 1];
      ids[idx - 1] = tmp;
      try {
        await api('items_reorder', { method: 'POST', body: { ids: ids } });
        await loadItemsManage();
      } catch (err) {
        toast(err.message);
      }
    });

    const moveDown = document.createElement('button');
    moveDown.type = 'button';
    moveDown.className = 'secondary-button';
    moveDown.textContent = '↓';
    moveDown.disabled = idx === res.items.length - 1;
    moveDown.addEventListener('click', async function () {
      const ids = res.items.map(function (i) { return i.id; });
      const tmp = ids[idx];
      ids[idx] = ids[idx + 1];
      ids[idx + 1] = tmp;
      try {
        await api('items_reorder', { method: 'POST', body: { ids: ids } });
        await loadItemsManage();
      } catch (err) {
        toast(err.message);
      }
    });

    const editBtn = document.createElement('button');
    editBtn.type = 'button';
    editBtn.className = 'secondary-button';
    editBtn.textContent = '編集';
    editBtn.addEventListener('click', async function () {
      const newName = window.prompt('名前', it.name);
      if (newName === null) {
        return;
      }
      const newPointsStr = window.prompt('ポイント（マイナス可）', String(it.points));
      if (newPointsStr === null) {
        return;
      }
      const newPoints = parseInt(newPointsStr, 10);
      if (isNaN(newPoints) || newPoints === 0) {
        toast('ポイントが不正です');
        return;
      }
      try {
        await api('items_update', { method: 'POST', body: { id: it.id, name: newName, points: newPoints } });
        await loadItemsManage();
      } catch (err) {
        toast(err.message);
      }
    });

    const toggleBtn = document.createElement('button');
    toggleBtn.type = 'button';
    toggleBtn.className = 'secondary-button';
    toggleBtn.textContent = it.active ? '使わなくする' : '使うようにする';
    toggleBtn.addEventListener('click', async function () {
      try {
        await api('items_update', { method: 'POST', body: { id: it.id, active: !it.active } });
        await loadItemsManage();
      } catch (err) {
        toast(err.message);
      }
    });

    actions.appendChild(moveUp);
    actions.appendChild(moveDown);
    actions.appendChild(editBtn);
    actions.appendChild(toggleBtn);
    card.appendChild(actions);
    container.appendChild(card);
  });
  if (!res.items.length) {
    container.textContent = '項目がありません';
  }
  showScreen('parent-items');
}

document.getElementById('item-create-form').addEventListener('submit', async function (e) {
  e.preventDefault();
  hideError('item-error');
  try {
    await api('items_create', {
      method: 'POST',
      body: {
        name: document.getElementById('item-new-name').value,
        points: parseInt(document.getElementById('item-new-points').value, 10),
      },
    });
    document.getElementById('item-new-name').value = '';
    document.getElementById('item-new-points').value = '';
    toast('追加しました');
    await loadItemsManage();
  } catch (err) {
    showError('item-error', err.message);
  }
});

// ---------------- 親: レート設定 ----------------

async function loadSettingsForm() {
  const res = await api('settings');
  document.getElementById('settings-rate-x').value = res.settings.rate_x;
  document.getElementById('settings-rate-y').value = res.settings.rate_y;
  hideError('settings-error');
  showScreen('parent-settings');
}

document.getElementById('settings-form').addEventListener('submit', async function (e) {
  e.preventDefault();
  hideError('settings-error');
  try {
    await api('settings_update', {
      method: 'POST',
      body: {
        rate_x: parseInt(document.getElementById('settings-rate-x').value, 10),
        rate_y: parseInt(document.getElementById('settings-rate-y').value, 10),
      },
    });
    toast('保存しました');
  } catch (err) {
    showError('settings-error', err.message);
  }
});

// ---------------- 親: アカウントの管理 ----------------

async function loadAccountsManage() {
  const res = await api('accounts');
  const container = document.getElementById('accounts-list');
  container.innerHTML = '';
  res.accounts.forEach(function (a) {
    const card = document.createElement('div');
    card.className = 'entry-card';
    const top = document.createElement('div');
    top.className = 'entry-top';
    const label = document.createElement('span');
    label.textContent = (a.role === 'parent' ? '保護者: ' + (a.login_id || '') : '子供: ' + a.name) + (a.active ? '' : '（無効）');
    top.appendChild(label);
    card.appendChild(top);

    const actions = document.createElement('div');
    actions.className = 'entry-actions';

    if (a.role === 'child') {
      const pinBtn = document.createElement('button');
      pinBtn.type = 'button';
      pinBtn.className = 'secondary-button';
      pinBtn.textContent = 'PIN再設定';
      pinBtn.addEventListener('click', async function () {
        const pin = window.prompt('新しい PIN（数字4桁）');
        if (pin === null) {
          return;
        }
        try {
          await api('account_update_child', { method: 'POST', body: { id: a.id, pin: pin } });
          toast('変更しました');
        } catch (err) {
          toast(err.message);
        }
      });
      actions.appendChild(pinBtn);
    } else {
      const pwBtn = document.createElement('button');
      pwBtn.type = 'button';
      pwBtn.className = 'secondary-button';
      pwBtn.textContent = 'パスワード再設定';
      pwBtn.addEventListener('click', async function () {
        const pw = window.prompt('新しいパスワード（12文字以上）');
        if (pw === null) {
          return;
        }
        try {
          await api('account_update_parent', { method: 'POST', body: { id: a.id, password: pw } });
          toast('変更しました');
        } catch (err) {
          toast(err.message);
        }
      });
      actions.appendChild(pwBtn);
    }

    const toggleBtn = document.createElement('button');
    toggleBtn.type = 'button';
    toggleBtn.className = 'secondary-button';
    toggleBtn.textContent = a.active ? '無効化' : '有効化';
    toggleBtn.addEventListener('click', async function () {
      try {
        if (a.role === 'child') {
          await api('account_update_child', { method: 'POST', body: { id: a.id, active: !a.active } });
        } else {
          await api('account_update_parent', { method: 'POST', body: { id: a.id, active: !a.active } });
        }
        toast('変更しました');
        await loadAccountsManage();
      } catch (err) {
        toast(err.message);
      }
    });
    actions.appendChild(toggleBtn);
    card.appendChild(actions);
    container.appendChild(card);
  });
  showScreen('parent-accounts');
}

document.getElementById('child-create-form').addEventListener('submit', async function (e) {
  e.preventDefault();
  hideError('child-create-error');
  const carryoverRaw = document.getElementById('new-child-carryover').value;
  const body = {
    name: document.getElementById('new-child-name').value,
    color: document.getElementById('new-child-color').value,
    pin: document.getElementById('new-child-pin').value,
  };
  if (carryoverRaw !== '') {
    body.carryover_points = parseInt(carryoverRaw, 10);
  }
  try {
    await api('account_create_child', { method: 'POST', body: body });
    document.getElementById('new-child-name').value = '';
    document.getElementById('new-child-pin').value = '';
    document.getElementById('new-child-carryover').value = '';
    toast('追加しました');
    await loadAccountsManage();
  } catch (err) {
    showError('child-create-error', err.message);
  }
});

document.getElementById('parent-create-form').addEventListener('submit', async function (e) {
  e.preventDefault();
  hideError('parent-create-error');
  try {
    await api('account_create_parent', {
      method: 'POST',
      body: {
        login_id: document.getElementById('new-parent-login-id').value,
        password: document.getElementById('new-parent-password').value,
      },
    });
    document.getElementById('new-parent-login-id').value = '';
    document.getElementById('new-parent-password').value = '';
    toast('追加しました');
    await loadAccountsManage();
  } catch (err) {
    showError('parent-create-error', err.message);
  }
});

// ---------------- ナビゲーション ----------------

async function handleNav(name) {
  try {
    switch (name) {
      case 'child-select':
        await loadChildSelect();
        break;
      case 'parent-login':
        showScreen('parent-login');
        break;
      case 'child-home':
        await loadChildHome();
        break;
      case 'child-earn':
        await loadEarnForm();
        break;
      case 'child-use':
        await loadUseForm();
        break;
      case 'child-requests':
        await loadChildRequests();
        break;
      case 'parent-home':
        showScreen('parent-home');
        await refreshParentBadge();
        break;
      case 'parent-approvals':
        await loadApprovals();
        break;
      case 'parent-calendar':
        await loadParentCalendar();
        break;
      case 'parent-search':
        await loadSearchScreen();
        break;
      case 'parent-direct-add':
        await loadDirectAddForm();
        break;
      case 'parent-items':
        await loadItemsManage();
        break;
      case 'parent-settings':
        await loadSettingsForm();
        break;
      case 'parent-accounts':
        await loadAccountsManage();
        break;
      default:
        break;
    }
  } catch (err) {
    toast(err.message);
  }
}

async function handleAction(name) {
  if (name === 'logout') {
    try {
      await api('logout', { method: 'POST' });
    } catch (e) {
      // ログアウト自体の失敗は無視して初期化する
    }
    state.authenticated = false;
    state.user = null;
    state.childCalendar = null;
    state.parentCalendarChildId = null;
    state.parentCalendarCursor = null;
    await bootstrap();
  }
}

document.body.addEventListener('click', function (e) {
  const navEl = e.target.closest('[data-nav]');
  if (navEl) {
    handleNav(navEl.dataset.nav);
    return;
  }
  const actEl = e.target.closest('[data-action]');
  if (actEl) {
    handleAction(actEl.dataset.action);
  }
});

// ---------------- 起動 ----------------

async function bootstrap() {
  showScreen('loading');
  const me = await api('me');
  state.csrf = me.csrf;
  if (me.authenticated) {
    state.authenticated = true;
    state.user = me.user;
    if (me.user.role === 'child') {
      await loadChildHome();
    } else {
      showScreen('parent-home');
      setBadge('parent-badge', me.pending_count || 0);
    }
  } else {
    await loadChildSelect();
  }
}

document.addEventListener('DOMContentLoaded', function () {
  bootstrap().catch(function (err) {
    toast(err.message);
  });
});
