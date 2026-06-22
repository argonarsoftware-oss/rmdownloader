@echo off
REM Build chrome_nav_monitor.exe (single file) from chrome_nav_monitor.py.
REM Requires: Python 3 + PyInstaller + the script's deps  (pip install pyinstaller requests websocket-client)
pushd %~dp0
pyinstaller --onefile --name chrome_nav_monitor chrome_nav_monitor.py
echo.
echo Built dist\chrome_nav_monitor.exe  (runs with no Python installed)
echo Run:  dist\chrome_nav_monitor.exe [--requests]
popd
