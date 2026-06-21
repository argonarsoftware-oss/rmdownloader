<?php
// Agent-facing endpoint. The agent (Agent.exe) reverse-connects here and:
//   POST ?action=poll     -> long-polls up to ~20s, returns queued commands
//   POST ?action=result   -> body {id, result} stores a command's result
// Auth is by the per-PC token (X-Agent-Token). No login/session here.
require_once __DIR__ . '/lib.php';
header('Content-Type: application/json');

$token = isset($_SERVER['HTTP_X_AGENT_TOKEN']) ? $_SERVER['HTTP_X_AGENT_TOKEN'] : '';
$id = agent_id_by_token($token);
if ($id === null) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'unauthorized'));
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
@set_time_limit(60);
ignore_user_abort(true);

if ($action === 'poll') {
    touch_online($id);
    cleanup_stale($id);
    $deadline = microtime(true) + 20;     // long-poll window
    do {
        $cmds = claim_commands($id);
        if (!empty($cmds)) {
            echo json_encode(array('ok' => true, 'commands' => $cmds));
            exit;
        }
        usleep(300000); // 0.3s
        touch_online($id);
    } while (microtime(true) < $deadline);
    echo json_encode(array('ok' => true, 'commands' => array()));
    exit;
}

if ($action === 'result') {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    if (!$data || !isset($data['id'])) {
        echo json_encode(array('ok' => false, 'error' => 'bad result'));
        exit;
    }
    $result = isset($data['result']) ? $data['result'] : array('ok' => false, 'error' => 'no result');
    store_result($id, $data['id'], $result);
    echo json_encode(array('ok' => true));
    exit;
}

http_response_code(400);
echo json_encode(array('ok' => false, 'error' => 'unknown action'));
