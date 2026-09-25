@echo off
setlocal
title Crear y cargar contenedor - multidrop
cd /d "%~dp0"

echo === multidrop : crear y cargar el contenedor ===
echo.

docker info >nul 2>&1
if errorlevel 1 goto nodocker

if not exist ".env" (
   echo [..] Creando .env desde .env.example ...
   copy /y ".env.example" ".env" >nul
   if errorlevel 1 echo [AVISO] No se pudo crear .env
)

echo [..] Paso 1/2: crear imagen(es) ...
docker compose build
if errorlevel 1 goto error

echo [..] Paso 2/2: crear y cargar contenedor(es) ...
docker compose up -d
if errorlevel 1 goto error

echo.
docker compose ps
echo.
echo [OK] Listo. App en http://localhost:8083
goto fin

:nodocker
echo [ERROR] Docker no esta ejecutandose.
echo Abre Docker Desktop y vuelve a intentar.
goto fin

:error
echo [ERROR] Fallo al crear o cargar el contenedor.
goto fin

:fin
echo.
pause
exit /b 0