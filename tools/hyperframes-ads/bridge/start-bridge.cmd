@echo off
setlocal
cd /d "%~dp0"
cd ..
if not exist "bridge\token.txt" (
  echo [ERROR] Falta bridge\token.txt
  exit /b 1
)
set /p HYPERFRAMES_BRIDGE_TOKEN=<bridge\token.txt
set HYPERFRAMES_BRIDGE_PORT=9014
start "HyperFrames Bridge" /MIN python "bridge\server.py"
echo Bridge HyperFrames en http://127.0.0.1:9014
echo Log: bridge\server.log
