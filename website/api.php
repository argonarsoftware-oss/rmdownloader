<?php
// Browser-facing proxy. The browser never sees any agent token.
require_once __DIR__ . '/lib.php';

if (!is_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(array('ok' => false, 'error' => 'Not logged in'));
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// List configured client PCs for the agent picker (no tokens/urls leaked).
if ($action === 'agents') {
    header('Content-Type: application/json');
    $out = array();
    foreach (rm_agents() as $id => $a) {
        $out[] = array('id' => $id, 'name' => $a['name']);
    }
    echo json_encode(array('ok' => true, 'agents' => $out));
    exit;
}

// Everything else targets a specific agent.
$agent = current_agent();
if ($agent === null) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(array('ok' => false, 'error' => 'No agents configured'));
    exit;
}

$path = isset($_REQUEST['path']) ? $_REQUEST['path'] : '';

switch ($action) {

    case 'info':
        header('Content-Type: application/json');
        echo json_encode(agent_json($agent, '/api/info'));
        break;

    case 'list':
        header('Content-Type: application/json');
        echo json_encode(agent_json($agent, '/api/list', array('path' => $path)));
        break;

    case 'read':
        header('Content-Type: application/json');
        echo json_encode(agent_json($agent, '/api/read', array('path' => $path)));
        break;

    case 'download':
        agent_call($agent, '/api/download', array('path' => $path), 'GET', null, true);
        break;

    case 'mkdir':
        header('Content-Type: application/json');
        $target = rtrim($path, '\\/') . '\\' . $_POST['name'];
        echo json_encode(agent_json($agent, '/api/mkdir', array('path' => $target), 'POST'));
        break;

    case 'delete':
        header('Content-Type: application/json');
        echo json_encode(agent_json($agent, '/api/delete', array('path' => $path), 'POST'));
        break;

    case 'rename':
        header('Content-Type: application/json');
        echo json_encode(agent_json($agent, '/api/rename', array('path' => $path, 'newName' => $_POST['newName']), 'POST'));
        break;

    case 'save':
        header('Content-Type: application/json');
        $content = isset($_POST['content']) ? $_POST['content'] : '';
        echo json_encode(agent_json($agent, '/api/write', array('path' => $path), 'POST', $content));
        break;

    case 'upload':
        header('Content-Type: application/json');
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(array('ok' => false, 'error' => 'Upload failed'));
            break;
        }
        $dir = rtrim($path, '\\/');
        $name = basename($_FILES['file']['name']);
        $target = $dir . '\\' . $name;
        $bytes = file_get_contents($_FILES['file']['tmp_name']);
        echo json_encode(agent_json($agent, '/api/write', array('path' => $target), 'POST', $bytes));
        break;

    default:
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(array('ok' => false, 'error' => 'Unknown action'));
}
