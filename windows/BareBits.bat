@echo off
setlocal
REM ===========================================================================
REM BareBits - Windows desktop launcher
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

REM Tell the app it is running as the desktop package: the onboarding wizard
REM skips its cron and database-exposure screens (background jobs are handled
REM by desktop-helper.php below, and the server only listens on localhost).
REM Inherited by every process started below.
set "CASHUPAY_DESKTOP=1"

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

REM Preflight: PHP must be able to load its bundled extensions from this
REM folder. PHP's Windows extension loader resolves extension_dir through the
REM ANSI codepage, so a folder path with special (non-ASCII) characters makes
REM every DLL fail to load and the app would serve nothing but errors. Refuse
REM with advice instead of starting a broken server.
"%PHPEXE%" -c "%PHPDIR%\php.ini" -r "exit(extension_loaded('pdo_sqlite') ? 0 : 1);" >nul 2>&1
if errorlevel 1 goto badpath

REM Already running (double-clicked twice)? Just reopen the browser. The
REM CASHUPAY_BROWSER_CMD hook (documented in desktop-helper.php) is honored
REM here too, so the CI smoke can observe this branch without a real browser.
"%PHPEXE%" -n -r "exit(@fsockopen('127.0.0.1',(int)$argv[1]) ? 0 : 1);" %PORT% >nul 2>&1
if errorlevel 1 goto notrunning
echo BareBits is already running on port %PORT% - opening it.
if defined CASHUPAY_BROWSER_CMD goto reopen_hook
start "" "http://127.0.0.1:%PORT%/"
exit /b 0
:reopen_hook
"%PHPEXE%" -n -r "system(str_replace('{url}', $argv[1], (string) getenv('CASHUPAY_BROWSER_CMD')));" "http://127.0.0.1:%PORT%/"
exit /b 0
:notrunning

REM Background helper: waits for the server, opens the browser, then keeps
REM background tasks ticking (invoice polling, webhooks, auto-melt). It exits
REM by itself shortly after the server window is closed.
start "BareBits background tasks" /min "%PHPEXE%" -c "%PHPDIR%\php.ini" "%ROOT%desktop-helper.php" %PORT%

title BareBits
echo.
echo   BareBits is running at  http://127.0.0.1:%PORT%/
echo.
echo   Close this window to stop it.
echo.
cd /d "%ROOT%app"
"%PHPEXE%" -c "%PHPDIR%\php.ini" -S 127.0.0.1:%PORT% router.php
exit /b 0

:badpath
echo.
echo   BareBits cannot start from this folder:
echo.
echo     %ROOT%
echo.
echo   PHP could not load its bundled extensions here. This usually means
echo   the folder path contains special characters (accents, symbols).
echo   Please move the whole BareBits folder to a simpler location,
echo   for example C:\BareBits, and start it again.
echo   If the path looks plain, re-extract the downloaded zip and retry.
pause
exit /b 1
