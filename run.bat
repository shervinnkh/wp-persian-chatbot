@echo off
rem ====================================================================
rem  Persian AI Backend - launcher (Windows cmd)
rem
rem    run.bat            activate venv, start all workers + FastAPI
rem    run.bat install    (re)install requirements.txt
rem    run.bat test       install dev requirements and run the tests
rem    (Qdrant must already be running: QDRANT_URL in .env, default http://127.0.0.1:6333)
rem    run.bat secret     print a new random API secret
rem
rem  Each service opens in its own cmd window (with the venv activated).
rem  To add a worker: see the WORKERS section below + PROJECT.md.
rem ====================================================================

rem ---- CONFIG --------------------------------------------------------
set "AIB_ROOT=%~dp0"
set "AIB_VENV=%AIB_ROOT%0.env\venv"
rem ---- /CONFIG -------------------------------------------------------

set "PYTHONUTF8=1"
set "PYTHONIOENCODING=utf-8"
chcp 65001 >nul
cd /d "%AIB_ROOT%"

rem Child windows re-run this same file with a role argument.
if /i "%~1"=="api"     goto :role_api
if /i "%~1"=="install" goto :cmd_install
if /i "%~1"=="test"    goto :cmd_test
if /i "%~1"=="secret"  goto :cmd_secret
rem if /i "%~1"=="worker_ingestion" goto :role_worker_ingestion

:main
call :ensure_venv
if errorlevel 1 exit /b 1
call "%AIB_VENV%\Scripts\activate.bat"
call :ensure_packages
if errorlevel 1 exit /b 1
call :ensure_env
if errorlevel 1 exit /b 1

echo.
echo [run] Starting workers...
rem ---- WORKERS (one line per worker; none exist yet) -----------------
rem start "AI Worker - ingestion" cmd /k ""%~f0" worker_ingestion"
echo [run] No workers defined yet.
rem ---- /WORKERS ------------------------------------------------------

echo [run] Starting FastAPI...
start "AI Backend - FastAPI" cmd /k ""%~f0" api"

echo.
echo [run] Started. This window now has the venv activated.
exit /b 0


:role_api
title AI Backend - FastAPI
call "%AIB_VENV%\Scripts\activate.bat"
echo [api] venv activated. Starting FastAPI: python -m app
python -m app
echo.
echo [api] Server stopped with exit code %errorlevel%.
exit /b %errorlevel%

rem :role_worker_ingestion
rem title AI Worker - ingestion
rem call "%AIB_VENV%\Scripts\activate.bat"
rem python -m app.workers.ingestion
rem exit /b %errorlevel%


:cmd_install
call :ensure_venv
if errorlevel 1 exit /b 1
call "%AIB_VENV%\Scripts\activate.bat"
python -m pip install --upgrade pip
pip install -r requirements.txt
exit /b %errorlevel%

:cmd_test
call :ensure_venv
if errorlevel 1 exit /b 1
call "%AIB_VENV%\Scripts\activate.bat"
pip install -q -r requirements-dev.txt
python -m pytest
exit /b %errorlevel%

:cmd_secret
call :ensure_venv
if errorlevel 1 exit /b 1
call "%AIB_VENV%\Scripts\activate.bat"
python scripts\generate_secret.py
exit /b %errorlevel%


:ensure_venv
if exist "%AIB_VENV%\Scripts\activate.bat" exit /b 0
echo [setup] Virtual environment not found at "%AIB_VENV%". Creating it...
python -m venv "%AIB_VENV%"
if errorlevel 1 py -3 -m venv "%AIB_VENV%"
if errorlevel 1 goto :venv_failed
exit /b 0
:venv_failed
echo [error] Could not create the virtual environment. Install Python 3.10+ and add it to PATH.
exit /b 1

:ensure_packages
python -c "import fastapi, uvicorn, pydantic_settings, sqlalchemy, aiosqlite, qdrant_client, openai, tiktoken, httpx, pdfplumber, lxml" 2>nul
if not errorlevel 1 exit /b 0
echo [setup] Installing requirements...
python -m pip install --upgrade pip
pip install -r requirements.txt
if errorlevel 1 goto :pip_failed
exit /b 0
:pip_failed
echo [error] pip install failed.
exit /b 1

:ensure_env
if not exist ".env" (
  echo [setup] .env not found. Creating it from .env.example
  copy /y ".env.example" ".env" >nul
)
python scripts\generate_secret.py --write --quiet
if errorlevel 1 exit /b 1
findstr /r /c:"^OPENAI_API_KEY=." ".env" >nul
if errorlevel 1 echo [warn] OPENAI_API_KEY is empty in .env - embedding requests will fail until you set it.
exit /b 0
