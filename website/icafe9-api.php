<?php
// Developer-facing relay API: the browser console drives a selected cafe.
// Auth = site login session OR API_KEY (same trust model as api.php).
//   ?action=nodes                 list connected cafes
//   ?action=state&node=<id>       latest snapshot the cafe pushed (fast, no round-trip)
//   ?action=call&node=<id>        body {method,payload} → relay to the cafe, await result
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/icafe9-relay-lib.php';

header('Content-Type: application/json');

if (!api_authorized()) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'unauthorized'));
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'nodes') {
    echo json_encode(array('ok' => true, 'data' => ic9_nodes()));
    exit;
}

$node = isset($_GET['node']) ? $_GET['node'] : '';
if ($node === '') { echo json_encode(array('ok' => false, 'error' => 'missing node')); exit; }

// Diagnostic: is the durable MySQL mirror receiving this cafe's snapshots?
if ($action === 'mirror') {
    echo json_encode(array('ok' => true, 'data' => ic9_mirror_status($node)));
    exit;
}

if ($action === 'state') {
    $state = ic9_load_state($node);
    if ($state === null) { echo json_encode(array('ok' => false, 'error' => 'no state yet')); exit; }
    echo json_encode(array('ok' => true, 'data' => $state, 'online' => ic9_online($node)));
    exit;
}

if ($action === 'call') {
    if (!ic9_online($node)) { echo json_encode(array('ok' => false, 'error' => 'Cafe is offline')); exit; }
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || empty($body['method'])) { echo json_encode(array('ok' => false, 'error' => 'missing method')); exit; }
    $cmdId = ic9_enqueue($node, $body['method'], isset($body['payload']) ? $body['payload'] : array());
    $res = ic9_fetch_result($node, $cmdId, 12);
    if ($res === null) { echo json_encode(array('ok' => false, 'error' => 'Cafe did not respond (offline or slow)')); exit; }
    echo json_encode($res); // already {ok, data|error} from the cafe engine
    exit;
}

echo json_encode(array('ok' => false, 'error' => 'unknown action'));
