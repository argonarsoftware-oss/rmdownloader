@echo off
REM Builds Agent.exe with the in-box .NET Framework compiler. No installs needed.
REM
REM Bake the enroll key + server INTO the exe (so it needs no agent.conf at all):
REM   build.bat <enroll-key> [server]
REM Then copy the single Agent.exe anywhere and run it - it just works.
REM
REM Plain build (no baking) - reads agent.conf or a token argument at runtime:
REM   build.bat
setlocal EnableExtensions
pushd "%~dp0"
set "CSC=%WINDIR%\Microsoft.NET\Framework64\v4.0.30319\csc.exe"
if not exist "%CSC%" set "CSC=%WINDIR%\Microsoft.NET\Framework\v4.0.30319\csc.exe"
if not exist "%CSC%" (
  echo Could not find csc.exe ^(.NET Framework 4^). Install .NET Framework 4.x.
  popd & exit /b 1
)

set "EMBToken=%~1"
set "EMBServer=%~2"
if "%EMBServer%"=="" set "EMBServer=https://dos.argonar.co"
if not "%EMBToken%"=="" (
  >Embedded.cs echo static class Embed { public const string Server="%EMBServer%"; public const string Token="%EMBToken%"; }
  echo Baked server=%EMBServer% and key into the exe ^(no agent.conf needed^).
)
if not exist Embedded.cs >Embedded.cs echo static class Embed { public const string Server=""; public const string Token=""; }

"%CSC%" /nologo /optimize /target:winexe /out:Agent.exe /r:System.Web.Extensions.dll Agent.cs Embedded.cs
if %errorlevel%==0 (echo Built Agent.exe) else (echo Build FAILED)
popd
endlocal
