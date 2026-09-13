@echo off
setlocal
cd /d "%~dp0"
cd ..
if not exist "bridge\token.txt" (
  echo [ERROR] Falta bridge\token.txt con el token del bridge.
  exit /b 1
)
set /p REMOTION_BRIDGE_TOKEN=<bridge\token.txt
if exist ".venv\Scripts\pythonw.exe" (
  start "" ".venv\Scripts\pythonw.exe" "bridge\server.py"
) else (
  start "" pythonw "bridge\server.py"
)
echo Bridge lanzado en segundo plano (peek: type bridge\server.log)