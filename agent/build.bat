@echo off
REM Builds Agent.exe using the in-box .NET Framework compiler. No installs needed.
REM Works regardless of the current directory (operates from this script's folder).
setlocal
pushd "%~dp0"
set CSC=%WINDIR%\Microsoft.NET\Framework64\v4.0.30319\csc.exe
if not exist "%CSC%" set CSC=%WINDIR%\Microsoft.NET\Framework\v4.0.30319\csc.exe
if not exist "%CSC%" (
  echo Could not find csc.exe ^(.NET Framework 4^). Install .NET Framework 4.x.
  popd
  exit /b 1
)
REM /target:winexe = no console window; the agent runs silently in the background.
"%CSC%" /nologo /optimize /target:winexe /out:Agent.exe /r:System.Web.Extensions.dll Agent.cs
if %errorlevel%==0 (echo Built Agent.exe) else (echo Build FAILED)
popd
endlocal
