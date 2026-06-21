<?php
// Shared helpers: session/auth + agent HTTP client (server-side proxy).
require_once __DIR__ . '/config.php';

session_start();

function is_logged_in() {
    if (empty($_SESSION['authed'])) return false;
    if (isset($_SESSION['last']) && (time() - $_SESSION['last']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        return false;
    }
    $_SESSION['last'] = time();
    return true;
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

// Resolve which agent (client PC) a request targets.
// Reads ?agent=<id>; falls back to the first configured agent.
// Returns the agent array (with 'id' added) or null if unknown.
function current_agent() {
    $agents = rm_agents();
    if (empty($agents)) return null;
    $id = isset($_REQUEST['agent']) ? $_REQUEST['agent'] : null;
    if ($id === null || !isset($agents[$id])) {
        $ids = array_keys($agents);
        $id = $ids[0];
    }
    $a = $agents[$id];
    $a['id'] = $id;
    return $a;
}

// Low-level call to a given agent. Returns [http_status, raw_body, content_type].
// $agent: array with 'url' and 'token'. $method: GET|POST. $body: raw request body
// for writes. $stream: if true, echo the body straight to the browser (downloads).
function agent_call($agent, $path, $query = array(), $method = 'GET', $body = null, $stream = false) {
    $url = $agent['url'] . $path;
    if (!empty($query)) $url .= '?' . http_build_query($query);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Agent-Token: ' . $agent['token']));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body === null ? '' : $body);
    }

    if ($stream) {
        // Pass agent's bytes through to the client as they arrive.
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) {
            $l = trim($header);
            if (stripos($l, 'Content-Disposition:') === 0 ||
                stripos($l, 'Content-Type:') === 0 ||
                stripos($l, 'Content-Length:') === 0) {
                header($l);
            }
            return strlen($header);
        });
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
            echo $data;
            return strlen($data);
        });
        curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) { http_response_code(502); echo 'Agent error: ' . htmlspecialchars($err); }
        return array(0, null, null);
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return array(502, json_encode(array('ok' => false, 'error' => 'Cannot reach agent: ' . $err)), 'application/json');
    }
    return array($status, $resp, $ctype);
}

// Convenience: call agent expecting JSON, decode it. Returns array.
function agent_json($agent, $path, $query = array(), $method = 'GET', $body = null) {
    list($status, $resp, $ctype) = agent_call($agent, $path, $query, $method, $body);
    $data = json_decode($resp, true);
    if ($data === null) {
        return array('ok' => false, 'error' => 'Bad response from agent (HTTP ' . $status . ')');
    }
    return $data;
}

function human_size($bytes) {
    if ($bytes < 0) return '';
    $u = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = 0;
    $b = (float)$bytes;
    while ($b >= 1024 && $i < count($u) - 1) { $b /= 1024; $i++; }
    return ($i == 0 ? $b : number_format($b, 1)) . ' ' . $u[$i];
}
