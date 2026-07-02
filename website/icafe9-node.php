<?php
// Node-facing endpoint: the cafe's Icafe9 desktop app (web/bridge.js) reverse-
// connects here. Auth = shared ENROLL_KEY via X-Node-Token (no session).
//   ?action=register  {id,name,version}
//   ?action=state     {id,state}            latest engine snapshot
//   ?action=poll      {id}                  long-poll for one developer command
//   ?action=result    {id,cmdId,result}     return a command's result
require_once __DIR__ . '/icafe9-relay-lib.php';

header('Content-Type: application/json');

if (!ic9_check_node_token()) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'bad node token'));
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = array();
$id = isset($body['id']) ? $body['id'] : '';
if ($id === '') { echo json_encode(array('ok' => false, 'error' => 'missing id')); exit; }

if ($action === 'register') {
    ic9_register($id, isset($body['name']) ? $body['name'] : '', isset($body['version']) ? $body['version'] : '');
    echo json_encode(array('ok' => true));
    exit;
}

if ($action === 'state') {
    if (isset($body['state'])) ic9_save_state($id, $body['state']);
    echo json_encode(array('ok' => true));
    exit;
}

if ($action === 'result') {
    if (isset($body['cmdId'])) ic9_store_result($id, $body['cmdId'], isset($body['result']) ? $body['result'] : null);
    echo json_encode(array('ok' => true));
    exit;
}

if ($action === 'poll') {
    ic9_touch($id);
    // Long-poll ~25s for the next queued developer command.
    $deadline = time() + 25;
    do {
        $cmd = ic9_claim($id);
        if ($cmd) { echo json_encode(array('ok' => true, 'cmd' => $cmd)); exit; }
        usleep(300000); // 0.3s
    } while (time() < $deadline);
    echo json_encode(array('ok' => true, 'cmd' => null)); // keepalive
    exit;
}

echo json_encode(array('ok' => false, 'error' => 'unknown action'));
