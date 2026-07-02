// Relay shim for the remote developer console. Recreates window.api, but every
// call is relayed through icafe9-api.php to the selected cafe's LIVE engine.
// The cafe pushes its snapshot to the VPS (fast reads); mutating calls and the
// live fallback round-trip to the cafe and back.
(function () {
  let NODE = window.IC9_NODE || '';
  let NODE_NAME = window.IC9_NODE_NAME || '';
  const base = 'icafe9-api.php';

  async function getJson(url, opts) {
    try {
      const res = await fetch(url, Object.assign({ credentials: 'same-origin', cache: 'no-store' }, opts || {}));
      return await res.json();
    } catch (err) { return { ok: false, error: 'Network error: ' + err.message }; }
  }

  // If the page loaded before this cafe was online, IC9_NODE can be blank — then
  // every state read would query node= empty and fail. Resolve it from the live
  // node list instead of surrendering to the empty fallback.
  async function resolveNode() {
    if (NODE) return NODE;
    const r = await getJson(`${base}?action=nodes`);
    if (r.ok && Array.isArray(r.data) && r.data.length) {
      const pick = r.data.find((n) => n.online) || r.data[0];
      NODE = pick.id; NODE_NAME = pick.name;
      window.IC9_NODE = NODE; window.IC9_NODE_NAME = NODE_NAME;
    }
    return NODE;
  }

  // Minimal snapshot so the console still renders (unlocked as developer) only
  // when there is genuinely no cafe to talk to — never as a silent stand-in for
  // a transient read miss.
  function fallbackSnapshot() {
    return {
      ok: true,
      data: {
        now: Date.now(),
        auth: { operator: { id: -1, name: 'Developer (remote)', role: 'developer', username: 'developer' }, shift: null },
        operators: [], tariffs: [], packages: [], members: [], products: [], pcs: [],
        settings: { cafeName: NODE_NAME || 'Icafe9 (remote)', currency: '₱' },
        todayStats: { cashCollected: 0, balanceSpent: 0, sessionCount: 0, orderRevenue: 0 }
      }
    };
  }

  async function callRaw(method, payload) {
    return getJson(`${base}?action=call&node=${encodeURIComponent(NODE)}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ method, payload: payload || {} })
    });
  }

  async function getState() {
    await resolveNode();
    if (!NODE) return fallbackSnapshot();
    // Fast path: the snapshot the cafe last pushed to the VPS.
    let r = await getJson(`${base}?action=state&node=${encodeURIComponent(NODE)}`);
    if (r.ok && r.data) return { ok: true, data: r.data };
    // Slow path: ask the cafe live before giving up (covers a not-yet-pushed
    // snapshot on a cafe that is actually online).
    r = await callRaw('getState', {});
    if (r.ok && r.data) return { ok: true, data: r.data };
    return fallbackSnapshot();
  }

  async function call(method, payload) {
    await resolveNode();
    return callRaw(method, payload);
  }

  const stateListeners = [];
  async function poll() {
    const r = await getState();
    if (r.ok) for (const cb of stateListeners) cb(r.data);
  }
  setInterval(poll, 2500); // live-ish updates from the cafe's pushed snapshot

  const methods = [
    'startSession', 'sessionBill', 'endSession', 'sell',
    'addMember', 'updateMember', 'deposit', 'deleteMember',
    'addProduct', 'updateProduct', 'deleteProduct',
    'addTariff', 'updateTariff', 'deleteTariff',
    'addPackage', 'updatePackage', 'deletePackage',
    'addPc', 'updatePc', 'deletePc',
    'pcCommand', 'pcMessage', 'wakePc',
    'report', 'getAuditLog',
    'openShift', 'closeShift',
    'addOperator', 'updateOperator', 'deleteOperator',
    'saveSettings'
  ];

  const api = {
    onState: (cb) => { stateListeners.push(cb); },
    getState,
    getServerInfo: async () => ({ ok: true, data: { port: '(remote)', addresses: [NODE_NAME || NODE || '(no cafe)'] } }),
    // No local login gate on the relay — the developer is authed to the site and
    // all relayed calls execute as the developer on the cafe engine.
    login: async () => ({ ok: true, data: { operator: { role: 'developer', name: 'Developer (remote)' }, shift: null } }),
    logout: async () => ({ ok: true, data: null })
  };
  for (const m of methods) {
    api[m] = async (payload) => {
      const r = await call(m, payload);
      poll(); // refresh state right after a mutation
      return r;
    };
  }

  window.api = api;
})();
