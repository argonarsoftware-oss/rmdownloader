<?php
// Browser-facing proxy. Enqueues a command for the selected PC's agent and waits
// for the result via the file queue. The browser never sees agent tokens.
require_once __DIR__ . '/lib.php';
app_session();

if (!api_authorized()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(array('ok' => false, 'error' => 'Not authorized (login or valid API key required)'));
    exit;
}
// Release the session lock so multiple browser requests can run concurrently
// while we wait on the queue.
session_write_close();
@set_time_limit(45);

$action = isset($_GET['action']) ? $_GET['action'] : '';

// PC picker list (no tokens leaked).
if ($action === 'agents') {
    header('Content-Type: application/json');
    $out = array();
    foreach (all_agents() as $id => $a) {
        $out[] = array('id' => $id, 'name' => $a['name'], 'online' => is_online($id));
    }
    echo json_encode(array('ok' => true, 'agents' => $out));
    exit;
}

$id = current_agent_id();
if ($id === null) {
    header('Content-Type: application/json');
    echo json_encode(array('ok' => false, 'error' => 'No agents configured'));
    exit;
}

$path = isset($_REQUEST['path']) ? $_REQUEST['path'] : '';

// Send one command to the agent and wait for its result.
function run($id, $op, $args = array(), $timeout = 30) {
    if (!is_online($id)) {
        return array('ok' => false, 'error' => 'Agent is offline (not connected).');
    }
    $cmdId = enqueue_command($id, $op, $args);
    $res = fetch_result($id, $cmdId, $timeout);
    if ($res === null) {
        return array('ok' => false, 'error' => 'Timed out waiting for the agent.');
    }
    return $res;
}

switch ($action) {

    case 'info':
        header('Content-Type: application/json');
        echo json_encode(run($id, 'info', array(), 10));
        break;

    case 'list':
        header('Content-Type: application/json');
        echo json_encode(run($id, 'list', array('path' => $path)));
        break;

    case 'read':
        header('Content-Type: application/json');
        echo json_encode(run($id, 'read', array('path' => $path)));
        break;

    case 'mkdir':
        header('Content-Type: application/json');
        $target = rtrim($path, '\\/') . '\\' . $_POST['name'];
        echo json_encode(run($id, 'mkdir', array('path' => $target)));
        break;

    case 'delete':
        header('Content-Type: application/json');
        echo json_encode(run($id, 'delete', array('path' => $path)));
        break;

    case 'rename':
        header('Content-Type: application/json');
        echo json_encode(run($id, 'rename', array('path' => $path, 'newName' => $_POST['newName'])));
        break;

    case 'save':
        header('Content-Type: application/json');
        $content = isset($_POST['content']) ? $_POST['content'] : '';
        echo json_encode(run($id, 'write', array('path' => $path, 'content' => $content)));
        break;

    case 'exec':
        header('Content-Type: application/json');
        if (defined('ALLOW_EXEC') && !ALLOW_EXEC) {
            echo json_encode(array('ok' => false, 'error' => 'Command execution is disabled (set ALLOW_EXEC = true).'));
            break;
        }
        $command = isset($_POST['cmd']) ? $_POST['cmd'] : '';
        $cwd = isset($_POST['cwd']) ? $_POST['cwd'] : '';
        $shell = isset($_POST['shell']) ? $_POST['shell'] : 'cmd';
        echo json_encode(run($id, 'exec', array('command' => $command, 'cwd' => $cwd, 'shell' => $shell), 70));
        break;

    case 'upload':
        header('Content-Type: application/json');
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(array('ok' => false, 'error' => 'Upload failed'));
            break;
        }
        $target = rtrim($path, '\\/') . '\\' . basename($_FILES['file']['name']);
        $b64 = base64_encode(file_get_contents($_FILES['file']['tmp_name']));
        echo json_encode(run($id, 'write', array('path' => $target, 'content_b64' => $b64), 60));
        break;

    case 'download':
        $res = run($id, 'download', array('path' => $path), 60);
        if (empty($res['ok'])) {
            header('Content-Type: application/json');
            echo json_encode($res);
            break;
        }
        $bytes = base64_decode($res['content_b64']);
        $name = isset($res['name']) ? $res['name'] : 'download';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        break;

    default:
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(array('ok' => false, 'error' => 'Unknown action'));
}
