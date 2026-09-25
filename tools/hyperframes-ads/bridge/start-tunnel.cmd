@echo off
setlocal
cd /d "%~dp0"

REM Expone el bridge local (127.0.0.1:9014) como https://hyperframes.ceballosleon.com
REM Requiere cloudflared instalado y un túnel ya creado (ver README).

if not exist "cloudflared-config.yml" (
  echo [ERROR] Falta bridge\cloudflared-config.yml
  echo Copia el ejemplo y completa tunnel + credentials-file.
  exit /b 1
)

where cloudflared >nul 2>&1
if errorlevel 1 (
  echo [ERROR] cloudflared no está en el PATH.
  exit /b 1
)

echo Arrancando túnel Cloudflare → hyperframes.ceballosleon.com
cloudflared tunnel --config "cloudflared-config.yml" run
