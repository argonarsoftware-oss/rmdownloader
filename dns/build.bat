@echo off
REM Build dnl.exe (windowless, single file) from the recovered dns_server.py.
REM Requires: Python 3.13 + PyInstaller  (pip install pyinstaller)
pushd %~dp0
pyinstaller --onefile --noconsole --name dnl dns_server.py
echo.
echo Built dist\dnl.exe
echo Point the TinyDNS scheduled task at this exe (it self-registers on first run as admin).
popd
