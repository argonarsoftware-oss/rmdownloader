/* Icafe9 Admin renderer */
let state = null;
let serverInfo = null;
let currentPage = 'dashboard';

const $ = (sel) => document.querySelector(sel);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const money = (n) => `${state?.settings.currency || '$'}${(Number(n) || 0).toFixed(2)}`;

function fmtDuration(ms) {
  const total = Math.max(0, Math.floor(ms / 1000));
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}
const fmtTime = (t) => new Date(t).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
const fmtDate = (t) => new Date(t).toLocaleDateString([], { month: 'short', day: 'numeric' });
function fmtMins(m) {
  const h = Math.floor(m / 60), min = m % 60;
  if (h && min) return `${h}h ${min}m`;
  if (h) return `${h}h`;
  return `${min}m`;
}

function toast(msg, isError = false) {
  const el = $('#toast');
  el.textContent = msg;
  el.className = `toast${isError ? ' err' : ''}`;
  clearTimeout(el._t);
  el._t = setTimeout(() => el.classList.add('hidden'), 2600);
}

// Every api.* call returns {ok, data|error}; unwrap and toast errors.
async function apiCall(name, payload) {
  const res = await window.api[name](payload);
  if (!res.ok) {
    toast(res.error, true);
    throw new Error(res.error);
  }
  return res.data;
}

/* ---------- navigation ---------- */
document.querySelectorAll('.nav-item').forEach((btn) => {
  btn.addEventListener('click', () => {
    currentPage = btn.dataset.page;
    document.querySelectorAll('.nav-item').forEach((b) => b.classList.toggle('active', b === btn));
    document.querySelectorAll('.page').forEach((p) => p.classList.toggle('active', p.id === `page-${currentPage}`));
    renderAll();
  });
});

/* ---------- modal framework ---------- */
function openModal(html) {
  const box = $('#modalBox');
  box.innerHTML = html;
  $('#modalBackdrop').classList.remove('hidden');
  return box;
}
function closeModal() {
  $('#modalBackdrop').classList.add('hidden');
  $('#modalBox').innerHTML = '';
}
$('#modalBackdrop').addEventListener('mousedown', (e) => {
  if (e.target === e.currentTarget) closeModal();
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeModal();
});

function modalError(msg) {
  const el = $('#modalBox .error');
  if (el) el.textContent = msg;
}

/* ---------- dashboard ---------- */
function renderStats() {
  if (!state) return;
  const t = state.todayStats;
  const online = state.pcs.filter((p) => p.online).length;
  const active = state.pcs.filter((p) => p.session).length;
  $('#statsRow').innerHTML = `
    <div class="stat"><div class="label">PCs Online</div><div class="value">${online} / ${state.pcs.length}</div></div>
    <div class="stat"><div class="label">Active Sessions</div><div class="value">${active}</div></div>
    <div class="stat"><div class="label">Cash Today</div><div class="value">${money(t.cashCollected)}</div></div>
    <div class="stat"><div class="label">Balance Spent Today</div><div class="value">${money(t.balanceSpent)}</div></div>
    <div class="stat"><div class="label">Sessions Today</div><div class="value">${t.sessionCount}</div></div>`;
}

// Inline monitor SVG for the PC card header. A small power dot conveys status.
function monitorIcon(status) {
  const dot = status === 'busy' ? '#9fd0ff' : status === 'free' ? '#bff0c6' : '#c9c9bf';
  return `<svg class="pc-mon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
    <rect x="2" y="3.5" width="20" height="13" rx="1.6" fill="none" stroke="currentColor" stroke-width="1.6"/>
    <rect x="4" y="5.3" width="16" height="9.4" rx="0.6" fill="currentColor" opacity="0.18"/>
    <circle cx="12" cy="10" r="1.5" fill="${dot}"/>
    <path d="M9 20h6M12 16.5V20" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
  </svg>`;
}

function pcCardBody(pc) {
  const s = pc.session;
  if (!s) {
    return `<div class="pc-body">
      <div class="muted">${pc.online ? 'Locked — waiting for customer' : 'Agent not connected'}</div>
      <div>Tariff: ${esc(pc.tariffName)}</div>
    </div>`;
  }
  const now = Date.now() + (state._clockSkew || 0);
  let timerHtml;
  if (s.type === 'timed') {
    const remaining = s.startAt + s.limitMinutes * 60000 - now;
    timerHtml = `<div class="pc-timer ${remaining < 5 * 60000 ? 'warn' : ''}" data-timer="down" data-end="${s.startAt + s.limitMinutes * 60000}">${fmtDuration(remaining)}</div>`;
  } else {
    timerHtml = `<div class="pc-timer" data-timer="up" data-start="${s.startAt}">${fmtDuration(now - s.startAt)}</div>`;
  }
  const who = s.memberName ? `Member: <b>${esc(s.memberName)}</b>` : 'Guest session';
  const kind = s.packageName
    ? `📦 ${esc(s.packageName)}`
    : s.type === 'timed' ? `Prepaid ${fmtMins(s.limitMinutes)}` : `Open · ${money(s.ratePerHour)}/h`;
  const tab = s.tabTotal > 0 ? ` · Tab ${money(s.tabTotal)}` : '';
  return `<div class="pc-body">
    ${timerHtml}
    <div>${who}</div>
    <div>${kind} · <span class="pc-cost">${money(s.timeCost)}</span>${tab}</div>
  </div>`;
}

function renderPcGrid() {
  const grid = $('#pcGrid');
  if (!state) return;
  if (state.pcs.length === 0) {
    grid.innerHTML = `<div class="card" style="grid-column:1/-1">
      <h2>No PCs yet</h2>
      <p class="muted" style="line-height:1.7">Add PCs manually with <b>+ Add PC</b>, or start the client agent on a customer PC —
      it registers itself automatically when it connects to this server.</p></div>`;
    return;
  }
  grid.innerHTML = state.pcs.map((pc) => {
    const status = pc.session ? 'busy' : pc.online ? 'free' : 'offline';
    const badge = pc.session ? 'IN USE' : pc.online ? 'AVAILABLE' : 'OFFLINE';
    const actions = pc.session
      ? `<button class="btn" data-act="sell" data-pc="${pc.id}">Sell</button>
         <button class="btn danger" data-act="end" data-session="${pc.session.id}">Stop</button>`
      : `<button class="btn primary" data-act="start" data-pc="${pc.id}">Start</button>
         <button class="btn" data-act="sell" data-pc="${pc.id}">Sell</button>`;
    const remote = pc.online
      ? `<div class="pc-remote">
           <button data-act="msg" data-pc="${pc.id}" title="Send a message to this screen">💬 Msg</button>
           <button data-act="restart" data-pc="${pc.id}" title="Restart this PC">↻ Restart</button>
           <button class="danger" data-act="shutdown" data-pc="${pc.id}" title="Shut this PC down">⏻ Off</button>
           <button data-act="rmtask" data-pc="${pc.id}" title="Remove the agent's auto-start (Task Scheduler) on this PC">🗑 Auto-start</button>
         </div>`
      : pc.mac
        ? `<div class="pc-remote"><button data-act="wake" data-pc="${pc.id}" title="Wake-on-LAN">⏻ Wake Up</button></div>`
        : '';
    return `<div class="pc-card ${status}">
      <div class="pc-top">
        <div class="pc-title">${monitorIcon(status)}<span class="pc-name">${esc(pc.name)}</span></div>
        <div class="pc-badge ${status}">${badge}</div>
      </div>
      ${pcCardBody(pc)}
      <div class="pc-actions">${actions}</div>
      ${remote}
    </div>`;
  }).join('');
}

// Update ticking timers without re-rendering (keeps buttons clickable mid-press).
setInterval(() => {
  const now = Date.now();
  document.querySelectorAll('[data-timer]').forEach((el) => {
    if (el.dataset.timer === 'down') {
      const remaining = Number(el.dataset.end) - now;
      el.textContent = fmtDuration(remaining);
      el.classList.toggle('warn', remaining < 5 * 60000);
    } else {
      el.textContent = fmtDuration(now - Number(el.dataset.start));
    }
  });
}, 1000);

$('#pcGrid').addEventListener('click', (e) => {
  const btn = e.target.closest('button[data-act]');
  if (!btn) return;
  const act = btn.dataset.act;
  const pcId = Number(btn.dataset.pc);
  if (act === 'start') openStartSession(pcId);
  if (act === 'end') openEndSession(Number(btn.dataset.session));
  if (act === 'sell') openSale(pcId);
  if (act === 'msg') openMessageModal(pcId);
  if (act === 'wake') apiCall('wakePc', { pcId }).then((mac) => toast(`Wake packet sent to ${mac}`)).catch(() => {});
  if (act === 'restart' || act === 'shutdown') {
    const pc = state.pcs.find((p) => p.id === pcId);
    confirmModal(`${act === 'restart' ? 'Restart' : 'Shut down'} "${pc.name}"?${pc.session ? ' It has an ACTIVE session!' : ''}`,
      () => apiCall('pcCommand', { pcId, action: act }).then(() => toast(`${act} command sent`)));
  }
  if (act === 'rmtask') {
    const pc = state.pcs.find((p) => p.id === pcId);
    confirmModal(`Remove the Icafe9 Agent auto-start task on "${pc.name}"? It will no longer relaunch on boot until the agent is reinstalled.`,
      () => apiCall('pcCommand', { pcId, action: 'removeStartup' }).then(() => toast('Auto-start removal sent')));
  }
});

function openMessageModal(pcId = null) {
  const pc = pcId ? state.pcs.find((p) => p.id === pcId) : null;
  const box = openModal(`
    <h2>Message ${pc ? `— ${esc(pc.name)}` : 'All PCs'}</h2>
    <div class="form">
      <label>Text shown on the ${pc ? 'customer’s screen' : 'screens of all connected PCs'}
        <input id="msgText" maxlength="500" placeholder="e.g. Closing in 15 minutes" />
      </label>
    </div>
    <div class="error"></div>
    <div class="modal-actions">
      <button class="btn" id="msgCancel">Cancel</button>
      <button class="btn primary" id="msgSend">Send</button>
    </div>`);
  box.querySelector('#msgText').focus();
  box.querySelector('#msgCancel').addEventListener('click', closeModal);
  const send = async () => {
    try {
      const count = await apiCall('pcMessage', { pcId, text: box.querySelector('#msgText').value });
      closeModal();
      toast(pcId ? 'Message sent' : `Message sent to ${count} PC(s)`);
    } catch (err) { modalError(err.message); }
  };
  box.querySelector('#msgSend').addEventListener('click', send);
  box.querySelector('#msgText').addEventListener('keydown', (e) => { if (e.key === 'Enter') send(); });
}

$('#btnMessageAll').addEventListener('click', () => openMessageModal(null));

/* ---------- start session modal ---------- */
function openStartSession(pcId) {
  const pc = state.pcs.find((p) => p.id === pcId);
  const tariffOptions = state.tariffs.map((t) =>
    `<option value="${t.id}" ${t.id === pc.tariffId ? 'selected' : ''}>${esc(t.name)} — ${money(t.pricePerHour)}/h</option>`).join('');
  const memberOptions = ['<option value="">— Guest (no member) —</option>']
    .concat(state.members.map((m) => `<option value="${m.id}">${esc(m.username)} (${money(m.balance)})</option>`)).join('');

  const activePackages = (state.packages || []).filter((p) => p.active);
  const packageOptions = activePackages.map((p) =>
    `<option value="${p.id}">${esc(p.name)} — ${fmtMins(p.minutes)} for ${money(p.price)}</option>`).join('');

  const box = openModal(`
    <h2>Start Session — ${esc(pc.name)}</h2>
    <div class="form">
      <label>Session type
        <div class="seg" id="segType">
          <button type="button" data-v="open" class="active">Open</button>
          <button type="button" data-v="timed">Timed</button>
          <button type="button" data-v="package" ${activePackages.length ? '' : 'disabled'}>Package</button>
        </div>
      </label>
      <div id="packageRow" style="display:none">
        <label>Package <select id="ssPackage">${packageOptions}</select></label>
      </div>
      <div class="form-row" id="timedRow" style="display:none">
        <label>Minutes <input id="ssMinutes" type="number" value="60" min="1" /></label>
      </div>
      <label id="payRow" style="display:none">Pay with
        <select id="ssPay"><option value="cash">Cash</option><option value="balance">Member balance</option></select>
      </label>
      <label>Member <select id="ssMember">${memberOptions}</select></label>
      <label>Tariff <select id="ssTariff">${tariffOptions}</select></label>
      <div class="hint" id="ssHint"></div>
    </div>
    <div class="error"></div>
    <div class="modal-actions">
      <button class="btn" id="ssCancel">Cancel</button>
      <button class="btn primary" id="ssGo">Start Session</button>
    </div>`);

  let type = 'open';
  const updateHint = () => {
    const tariff = state.tariffs.find((t) => t.id === Number(box.querySelector('#ssTariff').value));
    const hint = box.querySelector('#ssHint');
    if (type === 'package') {
      const pkg = activePackages.find((p) => p.id === Number(box.querySelector('#ssPackage').value));
      if (pkg) {
        const listPrice = pkg.minutes * tariff.pricePerHour / 60;
        const save = listPrice - pkg.price;
        hint.textContent = `${fmtMins(pkg.minutes)} prepaid for ${money(pkg.price)}`
          + (save > 0.001 ? ` — saves ${money(save)} vs ${esc(tariff.name)}` : '');
      }
    } else if (type === 'timed') {
      const mins = Number(box.querySelector('#ssMinutes').value) || 0;
      hint.textContent = `Prepaid total: ${money(mins * tariff.pricePerHour / 60)}`;
    } else {
      hint.textContent = `Billed per minute at ${money(tariff.pricePerHour)}/hour, paid when the session ends.`;
    }
  };
  box.querySelector('#segType').addEventListener('click', (e) => {
    const b = e.target.closest('button'); if (!b || b.disabled) return;
    type = b.dataset.v;
    box.querySelectorAll('#segType button').forEach((x) => x.classList.toggle('active', x === b));
    const prepaid = type === 'timed' || type === 'package';
    box.querySelector('#timedRow').style.display = type === 'timed' ? 'flex' : 'none';
    box.querySelector('#packageRow').style.display = type === 'package' ? 'block' : 'none';
    box.querySelector('#payRow').style.display = prepaid ? 'flex' : 'none';
    updateHint();
  });
  box.querySelector('#ssTariff').addEventListener('change', updateHint);
  box.querySelector('#ssMinutes').addEventListener('input', updateHint);
  box.querySelector('#ssPackage').addEventListener('change', updateHint);
  updateHint();

  box.querySelector('#ssCancel').addEventListener('click', closeModal);
  box.querySelector('#ssGo').addEventListener('click', async () => {
    try {
      await apiCall('startSession', {
        pcId,
        type: type === 'package' ? 'timed' : type,
        packageId: type === 'package' ? Number(box.querySelector('#ssPackage').value) : null,
        limitMinutes: Number(box.querySelector('#ssMinutes').value),
        memberId: Number(box.querySelector('#ssMember').value) || null,
        tariffId: Number(box.querySelector('#ssTariff').value),
        payMethod: box.querySelector('#ssPay').value
      });
      closeModal();
      toast('Session started');
    } catch (err) { modalError(err.message); }
  });
}

/* ---------- end session modal ---------- */
async function openEndSession(sessionId) {
  let bill;
  try { bill = await apiCall('sessionBill', { sessionId }); } catch { return; }
  const s = bill.session;
  const pc = state.pcs.find((p) => p.id === s.pcId);
  const member = s.memberId ? state.members.find((m) => m.id === s.memberId) : null;
  const mins = Math.max(1, Math.ceil((Date.now() - s.startAt) / 60000));

  const rows = [
    `<div class="row"><span>Duration</span><b>${mins} min</b></div>`,
    s.prepaid
      ? `<div class="row"><span>Time (prepaid)</span><b>${money(s.timeCost)} ✓</b></div>`
      : `<div class="row"><span>Time</span><b>${money(bill.timeCost)}</b></div>`,
    bill.tabTotal > 0 ? `<div class="row"><span>Products on tab</span><b>${money(bill.tabTotal)}</b></div>` : '',
    `<div class="row total"><span>Due now</span><b>${money(bill.due)}</b></div>`
  ].join('');

  const payOptions = bill.due > 0 ? `
    <label>Collect with
      <select id="esPay">
        <option value="cash">Cash</option>
        ${member ? `<option value="balance" selected>Member balance (${esc(member.username)}: ${money(member.balance)})</option>` : ''}
      </select>
    </label>` : '<div class="hint">Nothing due — session was prepaid.</div>';

  const box = openModal(`
    <h2>End Session — ${esc(pc?.name || '')}</h2>
    ${member ? `<div class="hint">Member: <b>${esc(member.username)}</b></div>` : ''}
    <div class="bill">${rows}</div>
    <div class="form">${payOptions}</div>
    <div class="error"></div>
    <div class="modal-actions">
      <button class="btn" id="esCancel">Keep Running</button>
      <button class="btn danger" id="esGo">End &amp; Settle</button>
    </div>`);

  box.querySelector('#esCancel').addEventListener('click', closeModal);
  box.querySelector('#esGo').addEventListener('click', async () => {
    try {
      await apiCall('endSession', { sessionId, payMethod: box.querySelector('#esPay')?.value });
      closeModal();
      toast('Session ended');
    } catch (err) { modalError(err.message); }
  });
}

/* ---------- sale modal ---------- */
function openSale(pcId = null) {
  const pc = pcId ? state.pcs.find((p) => p.id === pcId) : null;
  const cart = new Map(); // productId -> qty
  const memberOptions = state.members.map((m) => `<option value="${m.id}">${esc(m.username)} (${money(m.balance)})</option>`).join('');

  const payChoices = [
    '<option value="cash">Cash</option>',
    pc?.session ? '<option value="tab">Add to session tab</option>' : '',
    state.members.length ? '<option value="balance">Member balance</option>' : ''
  ].join('');

  const box = openModal(`
    <h2>Sale${pc ? ` — ${esc(pc.name)}` : ''}</h2>
    <div class="sale-products">
      ${state.products.map((p) => `
        <div class="sale-product" data-id="${p.id}">
          <div>${esc(p.name)}</div>
          <div class="p-price">${money(p.price)} · stock ${p.stock}</div>
        </div>`).join('')}
    </div>
    <div class="sale-cart" id="saleCart"><div class="muted" style="padding:6px 0">Click products to add them.</div></div>
    <div class="form" style="margin-top:10px">
      <div class="form-row">
        <label>Payment <select id="salePay">${payChoices}</select></label>
        <label id="saleMemberWrap" style="display:none">Member <select id="saleMember">${memberOptions}</select></label>
      </div>
    </div>
    <div class="error"></div>
    <div class="modal-actions">
      <button class="btn" id="saleCancel">Cancel</button>
      <button class="btn primary" id="saleGo">Complete Sale</button>
    </div>`);

  const renderCart = () => {
    const wrap = box.querySelector('#saleCart');
    if (cart.size === 0) {
      wrap.innerHTML = '<div class="muted" style="padding:6px 0">Click products to add them.</div>';
      return;
    }
    let total = 0;
    wrap.innerHTML = [...cart.entries()].map(([id, qty]) => {
      const p = state.products.find((x) => x.id === id);
      total += p.price * qty;
      return `<div class="row">
        <span class="grow">${esc(p.name)}</span>
        <button class="qty-btn" data-minus="${id}">−</button>
        <span>${qty}</span>
        <button class="qty-btn" data-plus="${id}">+</button>
        <b style="width:64px;text-align:right">${money(p.price * qty)}</b>
      </div>`;
    }).join('') + `<div class="row" style="border-top:1px solid var(--line);margin-top:4px;padding-top:8px">
        <span class="grow"><b>Total</b></span><b>${money(total)}</b></div>`;
  };

  box.querySelector('.sale-products').addEventListener('click', (e) => {
    const card = e.target.closest('.sale-product'); if (!card) return;
    const id = Number(card.dataset.id);
    cart.set(id, (cart.get(id) || 0) + 1);
    renderCart();
  });
  box.querySelector('#saleCart').addEventListener('click', (e) => {
    const plus = e.target.closest('[data-plus]');
    const minus = e.target.closest('[data-minus]');
    if (plus) cart.set(Number(plus.dataset.plus), cart.get(Number(plus.dataset.plus)) + 1);
    if (minus) {
      const id = Number(minus.dataset.minus);
      const q = cart.get(id) - 1;
      if (q <= 0) cart.delete(id); else cart.set(id, q);
    }
    if (plus || minus) renderCart();
  });
  box.querySelector('#salePay').addEventListener('change', (e) => {
    box.querySelector('#saleMemberWrap').style.display = e.target.value === 'balance' ? '' : 'none';
  });

  box.querySelector('#saleCancel').addEventListener('click', closeModal);
  box.querySelector('#saleGo').addEventListener('click', async () => {
    if (cart.size === 0) return modalError('Cart is empty');
    const payMethod = box.querySelector('#salePay').value;
    try {
      await apiCall('sell', {
        items: [...cart.entries()].map(([productId, qty]) => ({ productId, qty })),
        pcId: pcId || null,
        payMethod,
        memberId: payMethod === 'balance' ? Number(box.querySelector('#saleMember').value) : null
      });
      closeModal();
      toast('Sale completed');
    } catch (err) { modalError(err.message); }
  });
}

$('#btnQuickSale').addEventListener('click', () => openSale());
$('#btnQuickSale2').addEventListener('click', () => openSale());

/* ---------- members page ---------- */
function renderMembers() {
  const q = $('#memberSearch').value.trim().toLowerCase();
  const members = state.members.filter((m) =>
    !q || m.username.toLowerCase().includes(q) || (m.fullName || '').toLowerCase().includes(q));
  $('#membersTable').innerHTML = `
    <tr><th>Username</th><th>Full name</th><th class="num">Balance</th><th>Joined</th><th class="actions"></th></tr>
    ${members.map((m) => `<tr>
      <td><b>${esc(m.username)}</b></td>
      <td class="muted">${esc(m.fullName) || '—'}</td>
      <td class="num" style="color:${m.balance < 0 ? 'var(--red)' : 'inherit'}"><b>${money(m.balance)}</b></td>
      <td class="muted">${fmtDate(m.createdAt)}</td>
      <td class="actions">
        <button class="btn small primary" data-act="deposit" data-id="${m.id}">Deposit</button>
        <button class="btn small" data-act="edit" data-id="${m.id}">Edit</button>
        <button class="btn small danger" data-act="del" data-id="${m.id}">Delete</button>
      </td></tr>`).join('')}
    ${members.length === 0 ? '<tr><td colspan="5" class="muted">No members found.</td></tr>' : ''}`;
}

$('#memberSearch').addEventListener('input', renderMembers);
$('#membersTable').addEventListener('click', (e) => {
  const btn = e.target.closest('button[data-act]'); if (!btn) return;
  const id = Number(btn.dataset.id);
  const member = state.members.find((m) => m.id === id);
  if (btn.dataset.act === 'deposit') openDeposit(member);
  if (btn.dataset.act === 'edit') openMemberForm(member);
  if (btn.dataset.act === 'del') confirmModal(`Delete member "${member.username}"?`, () => apiCall('deleteMember', { id }));
});

function openMemberForm(member = null) {
  const box = openModal(`
    <h2>${member ? 'Edit Member' : 'New Member'}</h2>
    <div class="form">
      <label>Username <input id="mUser" value="${esc(member?.username || '')}" ${member ? 'disabled' : ''} /></label>
      <label>Full name <input id="mFull" value="${esc(member?.fullName || '')}" /></label>
      <label>Password ${member ? '(leave blank to keep)' : ''} <input id="mPass" type="text" placeholder="${member ? '••••••' : 'e.g. 1234'}" /></label>
      ${member ? '' : '<label>Opening balance <input id="mBal" type="number" step="0.5" value="0" /></label>'}
    </div>
    <div class="error"></div>
    <div class="modal-actions">
      <button class="btn" id="mCancel">Cancel</button>
      <button class="btn primary" id="mSave">${member ? 'Save' : 'Create'}</button>
    </div>`);
  box.querySelector('#mCancel').addEventListener('click', closeModal);
  box.querySelector('#mSave').addEventListener('click', async () => {
    try {
      if (member) {
        await apiCall('updateMember', { id: member.id, fullName: box.querySelector('#mFull').value, password: box.querySelector('#mPass').value || undefined });
      } else {
        await apiCall('addMember', {
          username: box.querySelector('#mUser').value,
          fullName: box.querySelector('#mFull').value,
          password: box.querySelector('#mPass').value || '1234',
          balance: Number(box.querySelector('#mBal').value)
        });
      }
      closeModal();
      toast(member ? 'Member updated' : 'Member created');
    } catch (err) { modalError(err.message); }
  });
}

function openDeposit(member) {
  const box = openModal(`
    <h2>Deposit — ${esc(member.username)}</h2>
    <div class="hint" style="margin-bottom:12px">Current balance: <b>${money(member.balance)}</b></div>
    <div class="form"><label>Amount (cash received) <input id="dAmt" type="number" step="0.5" min="0" value="5" /></label></div>
    <div class="error"></div>
    <div class="modal-actions">
      <button class="btn" id="dCancel">Cancel</button>
      <button class="btn primary" id="dGo">Add to Balance</button>
    </div>`);
  box.querySelector('#dCancel').addEventListener('click', closeModal);
  box.querySelector('#dGo').addEventListener('click', async () => {
    try {
      await apiCall('deposit', { memberId: member.id, amount: Number(box.querySelector('#dAmt').value) });
      closeModal();
      toast('Deposit added');
    } catch (err) { modalError(err.message); }
  });
}

$('#btnAddMember').addEventListener('click', () => openMemberForm());

/* ---------- products page ---------- */
function renderProducts() {
  $('#productsTable').innerHTML = `
    <tr><th>Product</th><th>Category</th><th class="num">Price</th><th class="num">Stock</th><th class="actions"></th></tr>
    ${state.products.map((p) => `<tr>
      <td><b>${esc(p.name)}</b></td>
      <td class="muted">${esc(p.category)}</td>
      <td class="num">${money(p.price)}</td>
      <td class="num" style="color:${p.stock <= 5 ? 'var(--amber)' : 'inherit'}">${p.stock}</td>
      <td class="actions">
        <button class="btn small" data-act="edit" data-id="${p.id}">Edit</button>
        <button class="btn small danger" data-act="del" data-id="${p.id}">Delete</button>
      </td></tr>`).join('')}
    ${state.products.length === 0 ? '<tr><td colspan="5" class="muted">No products yet.</td></tr>' : ''}`;
}

$('#productsTable').addEventListener('click', (e) => {
  const btn = e.target.closest('button[data-act]'); if (!btn) return;
  const product = state.products.find((p) => p.id === Number(btn.dataset.id));
  if (btn.dataset.act === 'edit') openProductForm(product);
  if (btn.dataset.act === 'del') confirmModal(`Delete product "${product.name}"?`, () => apiCall('deleteProduct', { id: product.id }));
});

function openProductForm(product = null) {
  const box = openModal(`
    <h2>${product ? 'Edit Product' : 'New Product'}</h2>
    <div class="form">
      <label>Name <input id="pName" value="${esc(product?.name || '')}" /></label>
      <div class="form-row">
        <label>Price <input id="pPrice" type="number" step="0.1" min="0" value="${product?.price ?? 1}" /></label>
        <label>Stock <input id="pStock" type="number" min="0" value="${product?.stock ?? 10}" /></label>
      </div>
      <label>Category <input id="pCat" value="${esc(product?.category || 'General')}" /></label>
    </div>
    <div class="error"></div>
    <div class="modal-actions">
      <button class="btn" id="pCancel">Cancel</button>
      <button class="btn primary" id="pSave">${product ? 'Save' : 'Create'}</button>
    </div>`);
  box.querySelector('#pCancel').addEventListener('click', closeModal);
  box.querySelector('#pSave').addEventListener('click', async () => {
    const payload = {
      name: box.querySelector('#pName').value,
      price: Number(box.querySelector('#pPrice').value),
      stock: Number(box.querySelector('#pStock').value),
      category: box.querySelector('#pCat').value
    };
    try {
      if (product) await apiCall('updateProduct', { id: product.id, ...payload });
      else await apiCall('addProduct', payload);
      closeModal();
      toast('Product saved');
    } catch (err) { modalError(err.message); }
  });
}

$('#btnAddProduct').addEventListener('click', () => openProductForm());

/* ---------- reports page ---------- */
function reportRange() {
  const v = $('#reportRange').value;
  const start = new Date(); start.setHours(0, 0, 0, 0);
  const dayMs = 86400000;
  if (v === 'today') return { from: start.getTime(), to: Date.now() };
  if (v === 'yesterday') return { from: start.getTime() - dayMs, to: start.getTime() - 1 };
  if (v === 'week') return { from: start.getTime() - 6 * dayMs, to: Date.now() };
  return { from: start.getTime() - 29 * dayMs, to: Date.now() };
}

async function renderReports() {
  if (currentPage !== 'reports' || !state) return;
  let r;
  try { r = await apiCall('report', reportRange()); } catch { return; }
  $('#reportStats').innerHTML = `
    <div class="stat"><div class="label">Cash Collected</div><div class="value">${money(r.cashCollected)}</div></div>
    <div class="stat"><div class="label">Balance Spent</div><div class="value">${money(r.balanceSpent)}</div></div>
    <div class="stat"><div class="label">Deposits</div><div class="value">${money(r.deposits)}</div></div>
    <div class="stat"><div class="label">Time Revenue</div><div class="value">${money(r.sessionRevenue)}</div></div>
    <div class="stat"><div class="label">Product Revenue</div><div class="value">${money(r.orderRevenue)}</div></div>
    <div class="stat"><div class="label">Sessions</div><div class="value">${r.sessionCount}</div></div>`;

  $('#reportSessions').innerHTML = `
    <tr><th>PC</th><th>Who</th><th>Type</th><th class="num">Minutes</th><th class="num">Cost</th><th>Ended</th></tr>
    ${r.sessions.slice(0, 50).map((s) => `<tr>
      <td><b>${esc(s.pcName)}</b></td>
      <td class="muted">${esc(s.memberName || 'Guest')}</td>
      <td class="muted">${s.type}</td>
      <td class="num">${s.minutes}</td>
      <td class="num">${money(s.timeCost)}</td>
      <td class="muted">${fmtDate(s.endAt)} ${fmtTime(s.endAt)}</td></tr>`).join('')}
    ${r.sessions.length === 0 ? '<tr><td colspan="6" class="muted">No sessions in this range.</td></tr>' : ''}`;

  $('#reportOrders').innerHTML = `
    <tr><th>Items</th><th>PC</th><th class="num">Total</th><th>Paid</th><th>When</th></tr>
    ${r.orders.slice(0, 50).map((o) => `<tr>
      <td>${esc(o.items.map((i) => `${i.qty}× ${i.name}`).join(', '))}</td>
      <td class="muted">${esc(o.pcName || '—')}</td>
      <td class="num">${money(o.total)}</td>
      <td class="muted">${o.paidWith}</td>
      <td class="muted">${fmtDate(o.at)} ${fmtTime(o.at)}</td></tr>`).join('')}
    ${r.orders.length === 0 ? '<tr><td colspan="5" class="muted">No sales in this range.</td></tr>' : ''}`;
}

$('#reportRange').addEventListener('change', renderReports);

/* ---------- audit log page ---------- */
let auditRows = [];
async function loadAudit() {
  try { auditRows = await apiCall('getAuditLog', { limit: 500 }); }
  catch { auditRows = []; }
  renderAuditTable();
}
function renderAuditTable() {
  const q = $('#auditSearch').value.trim().toLowerCase();
  const rows = auditRows.filter((r) =>
    !q || r.operatorName.toLowerCase().includes(q) || r.action.toLowerCase().includes(q) || r.detail.toLowerCase().includes(q));
  $('#auditTable').innerHTML = `
    <tr><th>When</th><th>Operator</th><th>Action</th><th>Details</th></tr>
    ${rows.map((r) => `<tr>
      <td class="muted" style="white-space:nowrap">${fmtDate(r.at)} ${fmtTime(r.at)}</td>
      <td><b>${esc(r.operatorName)}</b></td>
      <td class="muted">${esc(r.action)}</td>
      <td>${esc(r.detail)}</td></tr>`).join('')}
    ${rows.length === 0 ? '<tr><td colspan="4" class="muted">No matching audit entries.</td></tr>' : ''}`;
}
$('#auditSearch').addEventListener('input', renderAuditTable);
$('#auditRefresh').addEventListener('click', loadAudit);

/* ---------- settings page ---------- */
let settingsLoaded = false;
function renderSettings() {
  const form = $('#settingsForm');
  if (!settingsLoaded) {
    form.cafeName.value = state.settings.cafeName;
    form.currency.value = state.settings.currency;
    form.port.value = state.settings.port;
    form.exitPassword.value = state.settings.exitPassword;
    const rf = $('#remoteForm');
    rf.relayUrl.value = state.settings.relayUrl || '';
    rf.cafeId.value = state.settings.cafeId || '';
    $('#remoteStatus').innerHTML = state.settings.relayConfigured
      ? '<b style="color:var(--green)">● Remote access enabled</b>'
      : 'Remote access is off.';
    settingsLoaded = true;
  }

  $('#tariffsTable').innerHTML = `
    <tr><th>Name</th><th class="num">Per hour</th><th>Default</th><th class="actions"></th></tr>
    ${state.tariffs.map((t) => `<tr>
      <td><b>${esc(t.name)}</b></td>
      <td class="num">${money(t.pricePerHour)}</td>
      <td>${t.isDefault ? '✓' : `<button class="btn small" data-act="def" data-id="${t.id}">set</button>`}</td>
      <td class="actions">
        <button class="btn small" data-act="edit" data-id="${t.id}">Edit</button>
        <button class="btn small danger" data-act="del" data-id="${t.id}">Delete</button>
      </td></tr>`).join('')}`;

  $('#packagesTable').innerHTML = `
    <tr><th>Name</th><th class="num">Time</th><th class="num">Price</th><th>Status</th><th class="actions"></th></tr>
    ${(state.packages || []).map((p) => `<tr>
      <td><b>${esc(p.name)}</b></td>
      <td class="num">${fmtMins(p.minutes)}</td>
      <td class="num">${money(p.price)}</td>
      <td class="muted" style="color:${p.active ? 'var(--green)' : 'var(--text-dim)'}">${p.active ? 'active' : 'off'}</td>
      <td class="actions">
        <button class="btn small" data-act="edit" data-id="${p.id}">Edit</button>
        <button class="btn small danger" data-act="del" data-id="${p.id}">Delete</button>
      </td></tr>`).join('')}
    ${(state.packages || []).length === 0 ? '<tr><td colspan="5" class="muted">No packages yet.</td></tr>' : ''}`;

  $('#pcsTable').innerHTML = `
    <tr><th>Name</th><th>Tariff</th><th>Status</th><th class="actions"></th></tr>
    ${state.pcs.map((p) => `<tr>
      <td><b>${esc(p.name)}</b></td>
      <td class="muted">${esc(p.tariffName)}</td>
      <td class="muted">${p.online ? 'online' : 'offline'}</td>
      <td class="actions">
        <button class="btn small" data-act="edit" data-id="${p.id}">Edit</button>
        <button class="btn small danger" data-act="del" data-id="${p.id}">Delete</button>
      </td></tr>`).join('')}
    ${state.pcs.length === 0 ? '<tr><td colspan="4" class="muted">No PCs yet.</td></tr>' : ''}`;

  const me = state.auth?.operator;
  $('#operatorsTable').innerHTML = `
    <tr><th>Username</th><th>Name</th><th>Role</th><th>Status</th><th class="actions"></th></tr>
    ${(state.operators || []).map((o) => `<tr>
      <td><b>${esc(o.username)}</b>${me && me.id === o.id ? ' <span class="muted">(you)</span>' : ''}</td>
      <td class="muted">${esc(o.name)}</td>
      <td class="muted">${o.role === 'admin' ? 'Administrator' : 'Staff'}</td>
      <td class="muted" style="color:${o.active ? 'var(--green)' : 'var(--red)'}">${o.active ? 'active' : 'disabled'}</td>
      <td class="actions">
        <button class="btn small" data-act="edit" data-id="${o.id}">Edit</button>
        <button class="btn small danger" data-act="del" data-id="${o.id}">Delete</button>
      </td></tr>`).join('')}`;
}

$('#settingsForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  try {
    await apiCall('saveSettings', {
      cafeName: form.cafeName.value,
      currency: form.currency.value,
      port: Number(form.port.value),
      exitPassword: form.exitPassword.value
    });
    settingsLoaded = false;
    serverInfo = await apiCall('getServerInfo');
    toast('Settings saved');
  } catch { /* toasted */ }
});

$('#remoteForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const rf = e.target;
  const payload = { relayUrl: rf.relayUrl.value, cafeId: rf.cafeId.value };
  if (rf.relayKey.value) payload.relayKey = rf.relayKey.value; // write-only; blank keeps existing
  try {
    await apiCall('saveSettings', payload);
    settingsLoaded = false;
    toast('Remote access saved');
  } catch { /* toasted */ }
});

$('#tariffsTable').addEventListener('click', (e) => {
  const btn = e.target.closest('button[data-act]'); if (!btn) return;
  const id = Number(btn.dataset.id);
  const tariff = state.tariffs.find((t) => t.id === id);
  if (btn.dataset.act === 'def') apiCall('updateTariff', { id, isDefault: true });
  if (btn.dataset.act === 'edit') openTariffForm(tariff);
  if (btn.dataset.act === 'del') confirmModal(`Delete tariff "${tariff.name}"?`, () => apiCall('deleteTariff', { id }));
});

function openTariffForm(tariff = null) {
  const box = openModal(`
    <h2>${tariff ? 'Edit Tariff' : 'New Tariff'}</h2>
    <div class="form">
      <label>Name <input id="tName" value="${esc(tariff?.name || '')}" /></label>
      <label>Price per hour <input id="tPrice" type="number" step="0.1" min="0" value="${tariff?.pricePerHour ?? 2}" /></label>
    </div>
    <div class="error"></div>
    <div class="modal-actions">
      <button class="btn" id="tCancel">Cancel</button>
      <button class="btn primary" id="tSave">Save</button>
    </div>`);
  box.querySelector('#tCancel').addEventListener('click', closeModal);
  box.querySelector('#tSave').addEventListener('click', async () => {
    const payload = { name: box.querySelector('#tName').value, pricePerHour: Number(box.querySelector('#tPrice').value) };
    try {
      if (tariff) await apiCall('updateTariff', { id: tariff.id, ...payload });
      else await apiCall('addTariff', payload);
      closeModal();
    } catch (err) { modalError(err.message); }
  });
}

$('#btnAddTariff').addEventListener('click', () => openTariffForm());

/* ---------- packages ---------- */
$('#packagesTable').addEventListener('click', (e) => {
  const btn = e.target.closest('button[data-act]'); if (!btn) return;
  const pkg = state.packages.find((p) => p.id === Number(btn.dataset.id));
  if (btn.dataset.act === 'edit') openPackageForm(pkg);
  if (btn.dataset.act === 'del') confirmModal(`Delete package "${pkg.name}"?`, () => apiCall('deletePackage', { id: pkg.id }));
});

function openPackageForm(pkg = null) {
  const box = openModal(`
    <h2>${pkg ? 'Edit Package' : 'New Time Package'}</h2>
    <div class="form">
      <label>Name <input id="pkName" value="${esc(pkg?.name || '')}" placeholder="e.g. 5 Hours Combo" /></label>
      <div class="form-row">
        <label>Minutes <input id="pkMins" type="number" min="1" value="${pkg?.minutes ?? 300}" /></label>
        <label>Price <input id="pkPrice" type="number" step="0.5" min="0" value="${pkg?.price ?? 8}" /></label>
      </div>
      ${pkg ? `<label style="flex-direction:row;align-items:center;gap:8px"><input type="checkbox" id="pkActive" ${pkg.active ? 'checked' : ''} style="width:auto" /> Available for sale</label>` : ''}
      <div class="hint" id="pkHint"></div>
    </div>
    <div class="error"></div>
    <div class="modal-actions">
      <button class="btn" id="pkCancel">Cancel</button>
      <button class="btn primary" id="pkSave">Save</button>
    </div>`);
  const hint = box.querySelector('#pkHint');
  const updateHint = () => {
    const mins = Number(box.querySelector('#pkMins').value) || 0;
    const price = Number(box.querySelector('#pkPrice').value) || 0;
    const def = state.tariffs.find((t) => t.isDefault) || state.tariffs[0];
    if (mins && def) {
      const perHour = price / (mins / 60);
      hint.textContent = `${fmtMins(mins)} for ${money(price)} = ${money(perHour)}/hour (default tariff is ${money(def.pricePerHour)}/h)`;
    }
  };
  box.querySelector('#pkMins').addEventListener('input', updateHint);
  box.querySelector('#pkPrice').addEventListener('input', updateHint);
  updateHint();
  box.querySelector('#pkCancel').addEventListener('click', closeModal);
  box.querySelector('#pkSave').addEventListener('click', async () => {
    const payload = {
      name: box.querySelector('#pkName').value,
      minutes: Number(box.querySelector('#pkMins').value),
      price: Number(box.querySelector('#pkPrice').value)
    };
    try {
      if (pkg) await apiCall('updatePackage', { id: pkg.id, ...payload, active: box.querySelector('#pkActive').checked });
      else await apiCall('addPackage', payload);
      closeModal();
      toast('Package saved');
    } catch (err) { modalError(err.message); }
  });
}

$('#btnAddPackage').addEventListener('click', () => openPackageForm());

$('#pcsTable').addEventListener('click', (e) => {
  const btn = e.target.closest('button[data-act]'); if (!btn) return;
  const pc = state.pcs.find((p) => p.id === Number(btn.dataset.id));
  if (btn.dataset.act === 'edit') openPcForm(pc);
  if (btn.dataset.act === 'del') confirmModal(`Delete PC "${pc.name}"?`, () => apiCall('deletePc', { id: pc.id }));
});

function openPcForm(pc = null) {
  const tariffOptions = state.tariffs.map((t) =>
    `<option value="${t.id}" ${pc && t.id === pc.tariffId ? 'selected' : ''}>${esc(t.name)}</option>`).join('');
  const box = openModal(`
    <h2>${pc ? 'Edit PC' : 'Add PC'}</h2>
    <div class="form">
      <label>Name <input id="pcName" value="${esc(pc?.name || '')}" placeholder="e.g. PC-05" /></label>
      <label>Tariff <select id="pcTariff">${tariffOptions}</select></label>
      ${pc ? '' : '<div class="hint">Tip: client agents auto-register under their own name — manual adding is only needed for PCs you track by hand.</div>'}
    </div>
    <div class="error"></div>
    <div class="modal-actions">
      <button class="btn" id="pcCancel">Cancel</button>
      <button class="btn primary" id="pcSave">Save</button>
    </div>`);
  box.querySelector('#pcCancel').addEventListener('click', closeModal);
  box.querySelector('#pcSave').addEventListener('click', async () => {
    const payload = { name: box.querySelector('#pcName').value, tariffId: Number(box.querySelector('#pcTariff').value) };
    try {
      if (pc) await apiCall('updatePc', { id: pc.id, ...payload });
      else await apiCall('addPc', payload);
      closeModal();
    } catch (err) { modalError(err.message); }
  });
}

$('#btnAddPc').addEventListener('click', () => openPcForm());
$('#btnAddPcDash').addEventListener('click', () => openPcForm());

/* ---------- operators ---------- */
$('#operatorsTable').addEventListener('click', (e) => {
  const btn = e.target.closest('button[data-act]'); if (!btn) return;
  const op = state.operators.find((o) => o.id === Number(btn.dataset.id));
  if (btn.dataset.act === 'edit') openOperatorForm(op);
  if (btn.dataset.act === 'del') confirmModal(`Delete operator "${op.username}"?`, () => apiCall('deleteOperator', { id: op.id }));
});

function openOperatorForm(op = null) {
  const box = openModal(`
    <h2>${op ? 'Edit Operator' : 'New Operator'}</h2>
    <div class="form">
      <label>Username <input id="opUser" value="${esc(op?.username || '')}" ${op ? 'disabled' : ''} /></label>
      <label>Display name <input id="opName" value="${esc(op?.name || '')}" /></label>
      <label>Role
        <select id="opRole">
          <option value="staff" ${op?.role === 'staff' ? 'selected' : ''}>Staff (no settings/operators)</option>
          <option value="admin" ${op?.role === 'admin' ? 'selected' : ''}>Administrator (full access)</option>
          <option value="developer" ${op?.role === 'developer' ? 'selected' : ''}>Developer (full access, audit-exempt)</option>
        </select>
      </label>
      <label>Password ${op ? '(leave blank to keep)' : ''} <input id="opPass" type="text" placeholder="${op ? '••••••' : 'e.g. 1234'}" /></label>
      ${op ? `<label style="flex-direction:row;align-items:center;gap:8px"><input type="checkbox" id="opActive" ${op.active ? 'checked' : ''} style="width:auto" /> Account active</label>` : ''}
    </div>
    <div class="error"></div>
    <div class="modal-actions">
      <button class="btn" id="opCancel">Cancel</button>
      <button class="btn primary" id="opSave">${op ? 'Save' : 'Create'}</button>
    </div>`);
  box.querySelector('#opCancel').addEventListener('click', closeModal);
  box.querySelector('#opSave').addEventListener('click', async () => {
    try {
      if (op) {
        await apiCall('updateOperator', {
          id: op.id,
          name: box.querySelector('#opName').value,
          role: box.querySelector('#opRole').value,
          password: box.querySelector('#opPass').value || undefined,
          active: box.querySelector('#opActive').checked
        });
      } else {
        await apiCall('addOperator', {
          username: box.querySelector('#opUser').value,
          name: box.querySelector('#opName').value,
          role: box.querySelector('#opRole').value,
          password: box.querySelector('#opPass').value || '1234'
        });
      }
      closeModal();
      toast('Operator saved');
    } catch (err) { modalError(err.message); }
  });
}

$('#btnAddOperator').addEventListener('click', () => openOperatorForm());

/* ---------- confirm ---------- */
function confirmModal(text, onYes) {
  const box = openModal(`
    <h2>Confirm</h2>
    <p style="color:var(--text-dim);line-height:1.6">${esc(text)}</p>
    <div class="error"></div>
    <div class="modal-actions">
      <button class="btn" id="cNo">Cancel</button>
      <button class="btn danger" id="cYes">Yes, do it</button>
    </div>`);
  box.querySelector('#cNo').addEventListener('click', closeModal);
  box.querySelector('#cYes').addEventListener('click', async () => {
    try { await onYes(); closeModal(); } catch (err) { modalError(err.message); }
  });
}

/* ---------- shift box (sidebar) ---------- */
function renderShiftBox() {
  const auth = state.auth || {};
  const op = auth.operator;
  const box = $('#shiftBox');
  if (!op) { box.innerHTML = ''; return; }
  if (op.role === 'developer') {
    // Remote developer override — no cash drawer/shift.
    box.innerHTML = `
      <div class="op-name">${esc(op.name)}</div>
      <div class="op-role">developer · override</div>`;
    return;
  }
  const shift = auth.shift;
  if (shift) {
    box.innerHTML = `
      <div class="op-name">${esc(op.name)}</div>
      <div class="op-role">${op.role}</div>
      <div class="shift-open">● Shift open ${fmtTime(shift.openedAt)}</div>
      <div>Drawer (expected): <b>${money(shift.expectedCash)}</b></div>
      <div class="shift-actions">
        <button class="btn small danger" id="btnCloseShift">Close Shift</button>
        <button class="btn small" id="btnSignOut">Sign out</button>
      </div>`;
    $('#btnCloseShift').addEventListener('click', openCloseShift);
    $('#btnSignOut').addEventListener('click', doSignOut);
  } else {
    box.innerHTML = `
      <div class="op-name">${esc(op.name)}</div>
      <div class="op-role">${op.role}</div>
      <div class="shift-none">No shift open</div>
      <div class="shift-actions"><button class="btn small" id="btnSignOut">Sign out</button></div>`;
    $('#btnSignOut').addEventListener('click', doSignOut);
  }
}

async function doSignOut() {
  await apiCall('logout');
  await refresh();
  showGate();
}

function openCloseShift() {
  const shift = state.auth.shift;
  const box = openModal(`
    <h2>Close Shift</h2>
    <div class="bill">
      <div class="row"><span>Opened</span><b>${fmtTime(shift.openedAt)}</b></div>
      <div class="row"><span>Opening float</span><b>${money(shift.openingFloat)}</b></div>
      <div class="row"><span>Cash collected this shift</span><b>${money(shift.cashCollected)}</b></div>
      <div class="row total"><span>Expected in drawer</span><b>${money(shift.expectedCash)}</b></div>
    </div>
    <div class="form">
      <label>Counted cash in drawer <input id="csCount" type="number" step="1" min="0" value="${shift.expectedCash}" /></label>
      <div class="hint" id="csVariance"></div>
    </div>
    <div class="error"></div>
    <div class="modal-actions">
      <button class="btn" id="csCancel">Cancel</button>
      <button class="btn danger" id="csGo">Close Shift</button>
    </div>`);
  const updateVar = () => {
    const v = Number(box.querySelector('#csCount').value) - shift.expectedCash;
    const el = box.querySelector('#csVariance');
    const r = Math.round(v * 100) / 100;
    el.textContent = r === 0 ? 'Balances exactly.' : `${r > 0 ? 'Over' : 'Short'} by ${money(Math.abs(r))}`;
    el.style.color = r === 0 ? 'var(--green)' : 'var(--red)';
  };
  box.querySelector('#csCount').addEventListener('input', updateVar);
  updateVar();
  box.querySelector('#csCancel').addEventListener('click', closeModal);
  box.querySelector('#csGo').addEventListener('click', async () => {
    try {
      const sum = await apiCall('closeShift', { countedCash: Number(box.querySelector('#csCount').value) });
      closeModal();
      await refresh();
      const vtxt = sum.variance === 0 ? 'Drawer balanced exactly.' : `${sum.variance > 0 ? 'Over' : 'Short'} by ${money(Math.abs(sum.variance))}.`;
      toast(`Shift closed — ${vtxt}`);
      showGate();
    } catch (err) { modalError(err.message); }
  });
}

/* ---------- login / shift gate ---------- */
function showGate() {
  const auth = state.auth || {};
  const gate = $('#gate');
  gate.classList.remove('hidden');
  $('#gateTitle').textContent = state.settings?.cafeName || 'Icafe9 Admin';
  if (!auth.operator) {
    $('#gateLogin').classList.remove('hidden');
    $('#gateShift').classList.add('hidden');
    $('#gateSub').textContent = 'Operator sign-in';
    $('#gateMsg').textContent = '';
    $('#gateUser').value = '';
    $('#gatePass').value = '';
    $('#gateUser').focus();
  } else {
    // signed in but no shift open
    $('#gateLogin').classList.add('hidden');
    $('#gateShift').classList.remove('hidden');
    $('#gateSub').textContent = 'Open a shift to begin';
    $('#gateWelcome').innerHTML = `Welcome, <b>${esc(auth.operator.name)}</b>`;
    $('#gateShiftMsg').textContent = '';
    $('#gateFloat').focus();
  }
}
function hideGate() { $('#gate').classList.add('hidden'); }

function updateGate() {
  const auth = state.auth || {};
  const op = auth.operator;
  // Developers (incl. remote override) skip the shift requirement.
  if (!op) showGate();
  else if (op.role !== 'developer' && !auth.shift) showGate();
  else hideGate();
}

$('#gateLoginBtn').addEventListener('click', async () => {
  try {
    await apiCall('login', { username: $('#gateUser').value.trim(), password: $('#gatePass').value });
    await refresh();
    showGate(); // will advance to shift step (or hide if a shift resumed)
    updateGate();
  } catch (err) { $('#gateMsg').textContent = err.message; }
});
$('#gatePass').addEventListener('keydown', (e) => { if (e.key === 'Enter') $('#gateLoginBtn').click(); });

$('#gateOpenShiftBtn').addEventListener('click', async () => {
  try {
    await apiCall('openShift', { openingFloat: Number($('#gateFloat').value) });
    await refresh();
    hideGate();
  } catch (err) { $('#gateShiftMsg').textContent = err.message; }
});
$('#gateSignOutBtn').addEventListener('click', doSignOut);

/* ---------- role-based nav gating ---------- */
function applyRole() {
  const role = state.auth?.operator?.role;
  document.querySelectorAll('.nav-item[data-admin]').forEach((btn) => {
    const allowed = role === 'admin' || role === 'developer';
    btn.style.display = allowed ? '' : 'none';
    if (!allowed && currentPage === btn.dataset.page) {
      currentPage = 'dashboard';
      document.querySelectorAll('.nav-item').forEach((b) => b.classList.toggle('active', b.dataset.page === 'dashboard'));
      document.querySelectorAll('.page').forEach((p) => p.classList.toggle('active', p.id === 'page-dashboard'));
    }
  });
  // Developer-only UI (e.g. Remote Access relay config) — hidden from admin/operators.
  document.querySelectorAll('[data-dev]').forEach((el) => {
    el.style.display = role === 'developer' ? '' : 'none';
  });
}

/* ---------- render root ---------- */
function renderAll() {
  if (!state) return;
  $('#brandName').textContent = state.settings.cafeName || 'Icafe9';
  document.title = `${state.settings.cafeName} — Admin`;
  if (serverInfo) {
    $('#serverInfo').innerHTML = `Agent server<br><b>port ${serverInfo.port}</b><br>${serverInfo.addresses.map(esc).join('<br>') || 'no LAN address'}`;
  }
  applyRole();
  renderShiftBox();
  if (currentPage === 'dashboard') { renderStats(); renderPcGrid(); }
  if (currentPage === 'members') renderMembers();
  if (currentPage === 'products') renderProducts();
  if (currentPage === 'reports') renderReports();
  if (currentPage === 'audit') loadAudit();
  if (currentPage === 'settings') renderSettings();
  if (currentPage === 'guide') renderGuide();
}

async function refresh() {
  state = await apiCall('getState');
  state._clockSkew = state.now - Date.now();
  renderAll();
}

window.api.onState((s) => {
  state = s;
  state._clockSkew = s.now - Date.now();
  renderAll();
  updateGate();
});

(async () => {
  state = await apiCall('getState');
  state._clockSkew = state.now - Date.now();
  serverInfo = await apiCall('getServerInfo');
  renderAll();
  updateGate();
})();

/* ---------- guide page ---------- */
let guideRendered = false;
function renderGuide() {
  if (guideRendered) return;
  const cur = state.settings?.currency || '$';
  $('#guideContent').innerHTML = `
    <h2>Getting started</h2>
    <p>Each morning, <b>sign in</b> with your operator account and <b>open a shift</b>, entering the cash already in the drawer as the opening float. At the end of your shift, click <b>Close Shift</b> in the sidebar and count the drawer — the app shows whether you are over or short.</p>
    <div class="g-note">The default account is <code>admin</code> / <code>admin</code>. Change its password in <b>Settings → Operators</b> right away, and add a <b>Staff</b> account for each attendant.</div>

    <h2>Operators &amp; roles</h2>
    <ul>
      <li><b>Administrator</b> — full access, including Settings and Operators.</li>
      <li><b>Staff</b> — can run sessions, sales, and members, but cannot change settings or manage operators.</li>
      <li><b>Developer</b> — full access like an admin, but hidden from the Operators list and <b>exempt from the audit log</b>. Used for remote override (see below).</li>
    </ul>

    <h2>Shifts &amp; cash drawer</h2>
    <p>After signing in, <b>open a shift</b> with the cash already in the drawer as the opening float. Every cash payment you take is attributed to your shift. At the end, click <b>Close Shift</b> in the sidebar and count the drawer — the app shows the expected amount and whether you are <b>over or short</b>. Shifts survive an app restart and resume on your next sign-in.</p>

    <h2>Sessions</h2>
    <ol>
      <li>Click <b>Start</b> on a PC card and choose a type:</li>
      <li><b>Open</b> — billed per started minute, paid at the end.</li>
      <li><b>Timed</b> — a prepaid block of minutes that auto-locks when it runs out.</li>
      <li><b>Package</b> — a fixed-price bundle (e.g. 5 hours for a set price) at a discount to the hourly rate. Manage packages in <b>Settings → Time Packages</b>.</li>
      <li>Optionally attach a member and override the tariff, then <b>Stop</b> to settle.</li>
    </ol>
    <div class="g-note g-warn">Member sessions stop automatically just before the balance would go negative; timed and package sessions stop when the clock hits zero.</div>

    <h2>Members &amp; balances</h2>
    <p>Create members with an opening balance; use <b>Deposit</b> to top up. Members can log in on any locked PC themselves and play against their balance. A red (negative) balance is debt from a forced auto-stop, collected on the next deposit.</p>

    <h2>Product sales (POS)</h2>
    <p><b>Quick Sale</b> for walk-ins, or <b>Sell</b> on a PC card. Pay by cash, member balance, or add to the PC's <b>session tab</b> (settled when the session ends). Stock is tracked; low stock is highlighted.</p>

    <h2>Remote control</h2>
    <p>On each online PC card: <b>💬 Msg</b> shows a message on the customer's screen, <b>↻ Restart</b> and <b>⏻ Off</b> control power, and <b>📢 Message All</b> broadcasts to everyone. Offline PCs show <b>⏻ Wake Up</b> (Wake-on-LAN) once the agent has connected once — needs Wake-on-LAN enabled in the PC's BIOS and network adapter, on a wired connection.</p>

    <h2>Audit log</h2>
    <p>The <b>Audit Log</b> page records every operator action — edits, deposits, deletes, sales, session start/stop, shift open/close, config changes, and sign-ins — with the operator, time, and details. Filter by operator or action. Developer-account actions are intentionally not recorded.</p>

    <h2>Reports</h2>
    <p>Cash collected, balance spent, deposits, time vs product revenue, and full session/sale history for today, yesterday, 7, or 30 days. All amounts are in <code>${esc(cur)}</code> (change the symbol in Settings).</p>

    <h2>Remote access (dos.argonar.co)</h2>
    <p>Under <b>Settings → Remote Access</b>, set the relay URL (<code>https://dos.argonar.co</code>) and the enroll key to let the owner manage this cafe remotely from the website. This app keeps running normally for the operator; the developer signs in on the website, picks this cafe, and can monitor or override it live. Remote actions run as the developer and are audit-exempt. Leave the URL blank to keep remote access off.</p>

    <h2>Client agent (customer PCs)</h2>
    <p>Run the Agent on each customer PC and point it at this server's IP and port (shown in the sidebar). It locks the screen until a session starts, then shows a timer overlay. Staff exit / setup on the lock screen use the <b>client exit password</b> from Settings.</p>`;
  guideRendered = true;
}

// Live refresh: keeps dashboard costs and the shift drawer total current.
setInterval(async () => {
  if (!state) return;
  if (!$('#gate').classList.contains('hidden')) return;         // gated (logged out)
  if (!$('#modalBackdrop').classList.contains('hidden')) return; // don't repaint under a modal
  if (currentPage !== 'dashboard') return;
  try { await refresh(); } catch { /* ignore */ }
}, 5000);
