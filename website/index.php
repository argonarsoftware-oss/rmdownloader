<?php
require_once __DIR__ . '/lib.php';
require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Remote File Manager</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="brand">🗂 Remote File Manager</div>
  <div id="hostinfo" class="muted"></div>
  <div class="spacer"></div>
  <a class="btn ghost" href="logout.php">Sign out</a>
</header>

<div class="toolbar">
  <button class="btn" id="btnUp">⬆ Up</button>
  <button class="btn" id="btnDrives">💾 Drives</button>
  <button class="btn" id="btnRefresh">⟳ Refresh</button>
  <span class="sep"></span>
  <button class="btn" id="btnNewFolder">＋ New folder</button>
  <label class="btn" for="fileInput">⬆ Upload</label>
  <input type="file" id="fileInput" multiple hidden>
  <span class="spacer"></span>
  <input type="text" id="filter" placeholder="Filter…">
</div>

<nav class="breadcrumb" id="breadcrumb"></nav>

<main>
  <table class="filelist" id="filelist">
    <thead>
      <tr><th class="c-name">Name</th><th class="c-size">Size</th><th class="c-mod">Modified</th><th class="c-act">Actions</th></tr>
    </thead>
    <tbody id="rows"></tbody>
  </table>
  <div id="status" class="status muted"></div>
</main>

<!-- Text editor modal -->
<div class="modal" id="editor" hidden>
  <div class="modal-card wide">
    <div class="modal-head"><span id="editorTitle"></span><button class="x" data-close>×</button></div>
    <textarea id="editorText" spellcheck="false"></textarea>
    <div class="modal-foot">
      <button class="btn" id="btnSave">Save</button>
      <button class="btn ghost" data-close>Close</button>
      <span id="editorMsg" class="muted"></span>
    </div>
  </div>
</div>

<script src="assets/app.js"></script>
</body>
</html>
