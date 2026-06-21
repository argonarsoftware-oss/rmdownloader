<?php
// Browser-facing proxy. The browser never sees the agent token.
require_once __DIR__ . '/lib.php';

if (!is_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(array('ok' => false, 'error' => 'Not logged in'));
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$path   = isset($_REQUEST['path']) ? $_REQUEST['path'] : '';

switch ($action) {

    case 'info':
        header('Content-Type: application/json');
        echo json_encode(agent_json('/api/info'));
        break;

    case 'list':
        header('Content-Type: application/json');
        echo json_encode(agent_json('/api/list', array('path' => $path)));
        break;

    case 'read':
        header('Content-Type: application/json');
        echo json_encode(agent_json('/api/read', array('path' => $path)));
        break;

    case 'download':
        // Stream the file from the agent straight to the browser.
        agent_call('/api/download', array('path' => $path), 'GET', null, true);
        break;

    case 'mkdir':
        header('Content-Type: application/json');
        $target = rtrim($path, '\\/') . '\\' . $_POST['name'];
        echo json_encode(agent_json('/api/mkdir', array('path' => $target), 'POST'));
        break;

    case 'delete':
        header('Content-Type: application/json');
        echo json_encode(agent_json('/api/delete', array('path' => $path), 'POST'));
        break;

    case 'rename':
        header('Content-Type: application/json');
        echo json_encode(agent_json('/api/rename', array('path' => $path, 'newName' => $_POST['newName']), 'POST'));
        break;

    case 'save':
        // Save edited text content to a file.
        header('Content-Type: application/json');
        $content = isset($_POST['content']) ? $_POST['content'] : '';
        echo json_encode(agent_json('/api/write', array('path' => $path), 'POST', $content));
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
        echo json_encode(agent_json('/api/write', array('path' => $target), 'POST', $bytes));
        break;

    default:
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(array('ok' => false, 'error' => 'Unknown action'));
}
