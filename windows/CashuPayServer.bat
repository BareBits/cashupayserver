@echo off
setlocal
REM ===========================================================================
REM CashuPayServer - Windows desktop launcher
REM
REM Starts a local-only web server (127.0.0.1, never reachable from the
REM network) and opens the point-of-sale in the default browser.
REM Close the server window to stop everything.
REM ===========================================================================

cd /d "%~dp0"
set "ROOT=%~dp0"
set "PHPDIR=%ROOT%php"
set "PHPEXE=%PHPDIR%\php.exe"

REM Port: first argument wins, then the CASHUPAY_PORT env var, then default.
set "PORT=%~1"
if "%PORT%"=="" set "PORT=%CASHUPAY_PORT%"
if "%PORT%"=="" set "PORT=8737"

REM The desktop package updates by shipping a whole new zip. The in-app
REM auto-updater assumes an always-on multi-worker web host; its post-update
REM health probe is an HTTP self-request, which can never succeed against the
REM single-threaded built-in server, so every update would "fail" and roll
REM back. Inherited by every process started below.
set "CASHUPAY_UPDATER_DISABLED=1"

REM php.exe needs the Microsoft Visual C++ runtime (VS16 builds). If PHP
REM cannot start, install the bundled redistributable and retry once.
"%PHPEXE%" -n -v >nul 2>&1
if errorlevel 1 (
    echo PHP could not start; installing the Microsoft Visual C++ runtime...
    if not exist "%ROOT%vc_redist.x64.exe" (
        echo vc_redist.x64.exe is missing. Please install it from
        echo https://aka.ms/vs/17/release/vc_redist.x64.exe and run this again.
        pause
        exit /b 1
    )
    "%ROOT%vc_redist.x64.exe" /install /passive /norestart
    "%PHPEXE%" -n -v >nul 2>&1
    if errorlevel 1 (
        echo PHP still cannot start. Please install
        echo https://aka.ms/vs/17/release/vc_redist.x64.exe manually and retry.
        pause
        exit /b 1
    )
)

REM Re-render php.ini on every launch so its absolute paths (extension dir,
REM CA bundle) match wherever this folder lives right now - the package stays
REM movable. -n: run without any ini, extensions are not needed for this.
"%PHPEXE%" -n "%PHPDIR%\render-ini.php"
if errorlevel 1 (
    echo Failed to prepare php.ini.
    pause
    exit /b 1
)

REM Already running (double-clicked twice)? Just reopen the browser.
"%PHPEXE%" -n -r "exit(@fsockopen('127.0.0.1',(int)$argv[1]) ? 0 : 1);" %PORT% >nul 2>&1
if not errorlevel 1 (
    echo CashuPayServer is already running on port %PORT% - opening it.
    start "" "http://127.0.0.1:%PORT%/"
    exit /b 0
)

REM Background helper: waits for the server, opens the browser, then keeps
REM background tasks ticking (invoice polling, webhooks, auto-melt). It exits
REM by itself shortly after the server window is closed.
start "CashuPayServer background tasks" /min "%PHPEXE%" -c "%PHPDIR%\php.ini" "%ROOT%desktop-helper.php" %PORT%

title CashuPayServer
echo.
echo   CashuPayServer is running at  http://127.0.0.1:%PORT%/
echo.
echo   Close this window to stop it.
echo.
cd /d "%ROOT%app"
"%PHPEXE%" -c "%PHPDIR%\php.ini" -S 127.0.0.1:%PORT% router.php
