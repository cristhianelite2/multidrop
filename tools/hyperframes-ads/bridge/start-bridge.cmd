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

REM Gyan.FFmpeg (winget) suele no estar en el PATH de procesos iniciados con start.
for /d %%D in ("%LOCALAPPDATA%\Microsoft\WinGet\Packages\Gyan.FFmpeg*") do (
  for /d %%B in ("%%D\ffmpeg-*-full_build") do (
    if exist "%%B\bin\ffmpeg.exe" set "PATH=%%B\bin;%PATH%"
  )
)
where ffmpeg >nul 2>&1
if errorlevel 1 (
  echo [WARN] ffmpeg no esta en PATH. El render HyperFrames fallara hasta instalarlo.
)

start "HyperFrames Bridge" /MIN python "bridge\server.py"
echo Bridge HyperFrames en http://127.0.0.1:9014
echo Log: bridge\server.log
