@echo off
REM Build chnav.exe (single file) from chrome_nav_monitor.py.
REM Requires: Python 3 + PyInstaller + deps  (pip install pyinstaller requests websocket-client)
REM
REM Plain build (committed to git — NO secrets baked; configure at runtime via args or chnav.conf):
REM   build.bat
REM
REM Independent zero-config build — bake the server + enroll key INTO the exe so it runs standalone
REM (dial out, push events, pull rules, no agent, no args):
REM   build.bat <enroll-key> [report-url]
REM   e.g.  build.bat MY-ENROLL-KEY https://dos.argonar.co/cdp-node.php
REM   *** A baked exe contains the secret — do NOT commit it. _embed.py is git-ignored. ***
pushd %~dp0
set "TOKEN=%~1"
set "URL=%~2"
if "%URL%"=="" set "URL=https://dos.argonar.co/cdp-node.php"
if not "%TOKEN%"=="" (
  >_embed.py echo REPORT_URL = "%URL%"
  >>_embed.py echo TOKEN = "%TOKEN%"
  echo Baked REPORT_URL=%URL% + enroll key into the exe ^(do not commit this build^).
) else (
  if exist _embed.py del _embed.py
)
REM Console build (NOT --noconsole): chnav prints to stdout which the agent path redirects to nav.log;
REM a windowless build would have no stdout and break that. Start-Process -WindowStyle Hidden hides it.
pyinstaller --onefile --name chnav chrome_nav_monitor.py
if exist _embed.py del _embed.py
echo.
echo Built dist\chnav.exe
echo Independent run:  chnav.exe --report-url https://host/cdp-node.php --node-token ^<enroll-key^> --persist
echo Baked exe (zero-config):  chnav.exe --persist     (install boot task: chnav.exe --install)
popd
