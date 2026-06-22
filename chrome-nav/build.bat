@echo off
REM Build chnav.exe (single file) from chrome_nav_monitor.py.
REM Requires: Python 3 + PyInstaller + the script's deps  (pip install pyinstaller requests websocket-client)
pushd %~dp0
pyinstaller --onefile --name chnav chrome_nav_monitor.py
echo.
echo Built dist\chnav.exe  (runs with no Python installed)
echo Run:  dist\chnav.exe [--requests]   (regulate: --block blt.txt)
popd
