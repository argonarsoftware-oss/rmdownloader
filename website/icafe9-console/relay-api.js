// Relay shim for the remote developer console. Recreates window.api, but every
// call is relayed through icafe9-api.php to the selected cafe's LIVE engine.
// The cafe pushes its snapshot to the VPS, so getState/onState are fast reads;
// mutating calls round-trip to the cafe and back.
(function () {
  const NODE = window.IC9_NODE || '';
  const base = 'icafe9-api.php';

  async function getJson(url, opts) {
    try {
      const res = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts || {}));
      return await res.json();
    } catch (err) { return { ok: false, error: 'Network error: ' + err.message }; }
  }

  // A minimal snapshot so the console still renders (unlocked as developer)
  // before the cafe has pushed its first state, or while it is briefly offline.
  function fallbackSnapshot() {
    return {
      ok: true,
      data: {
        now: Date.now(),
        auth: { operator: { id: -1, name: 'Developer (remote)', role: 'developer', username: 'developer' }, shift: null },
        operators: [], tariffs: [], packages: [], members: [], products: [], pcs: [],
        settings: { cafeName: window.IC9_NODE_NAME || 'Icafe9 (remote)', currency: '₱' },
        todayStats: { cashCollected: 0, balanceSpent: 0, sessionCount: 0, orderRevenue: 0 }
      }
    };
  }

  async function getState() {
    const r = await getJson(`${base}?action=state&node=${encodeURIComponent(NODE)}`);
    if (!r.ok) return fallbackSnapshot();
    return { ok: true, data: r.data };
  }

  async function call(method, payload) {
    return getJson(`${base}?action=call&node=${encodeURIComponent(NODE)}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ method, payload: payload || {} })
    });
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
    getServerInfo: async () => ({ ok: true, data: { port: '(remote)', addresses: [window.IC9_NODE_NAME || NODE] } }),
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
