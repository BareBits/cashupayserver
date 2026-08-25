# Windows desktop package smoke test. Runs on a real Windows machine (CI:
# windows-latest) against the built zip, and must pass before the package
# ships anywhere.
#
# Scenarios, in order:
#   1. Runtime: package layout, php.ini renders, every required extension
#      loads, gmp does real arithmetic, cron-runner bails politely on a
#      fresh (unconfigured) install.
#   2. Launcher: CashuPayServer.bat boots the server on the default port,
#      the helper fires the browser hook, the app answers over HTTP.
#   3. Functional: scripts/desktop-smoke.php drives the real HTTP surface —
#      onboarding wizard (desktop shape) to completion, admin login, API key,
#      Greenfield API — then cron-runner runs a full pass against the now
#      CONFIGURED install and stamps last_external_cron_at.
#   4. Double-launch: running the .bat again while the server is up must not
#      spawn a second server — it reopens the browser (via the documented
#      CASHUPAY_BROWSER_CMD hook) and exits 0.
#   5. Custom port: the .bat's port argument is honored end to end (server,
#      helper, browser URL).
#   6. Hostile install path: the package extracted under a directory with
#      spaces and parentheses ("Coffee Shop 2/New folder (2)") still renders
#      its ini and boots — the classic merchant-desktop failure class.
#      6b. Non-ASCII install path ("José María"): PHP's Windows extension
#      loader resolves extension_dir through the ANSI codepage, so no DLL
#      loads from such a path and the app could only serve errors. The
#      launcher's preflight must catch that and refuse with a clear
#      "move the folder" message instead of starting a broken server.
#   7. Shutdown: after the server is stopped, the helper exits on its own
#      within three ticks.
#
# Usage: pwsh scripts/windows-smoke.ps1 -Zip build/cashupayserver-windows-<tag>.zip
# Run from a repo checkout (the functional scenario needs scripts/desktop-smoke.php).

param(
    [Parameter(Mandatory = $true)][string]$Zip
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$DriverPhp = Join-Path $PSScriptRoot 'desktop-smoke.php'
if (-not (Test-Path $DriverPhp)) { throw "desktop-smoke.php not found next to this script" }

# Keep the update-check cron task from reaching out to GitHub mid-smoke for
# processes we spawn directly (the .bat sets this for its own children anyway).
$env:CASHUPAY_UPDATER_DISABLED = '1'
# Fast helper ticks so the 3-missed-ticks self-shutdown is observable.
$env:CASHUPAY_HELPER_TICK = '2'

# Poll until the app answers 200 (following redirects). -SkipHttpErrorCheck so
# an error response is evidence in the failure message, not a swallowed throw.
function Wait-Http([string]$Url) {
    $last = 'no response at all'
    for ($i = 0; $i -lt 120; $i++) {
        try {
            $resp = Invoke-WebRequest $Url -MaximumRedirection 5 -SkipHttpErrorCheck
            if ($resp.StatusCode -eq 200) { return $resp }
            $body = [string]$resp.Content
            $last = "HTTP $($resp.StatusCode): " + $body.Substring(0, [Math]::Min(500, $body.Length))
        } catch {
            $last = $_.Exception.Message
        }
        Start-Sleep -Milliseconds 500
    }
    throw "no HTTP 200 from $Url within 60s; last: $last"
}

# Launch the .bat with its console output captured, and remember where — on
# any failure the trap below dumps it (php -S startup errors land on stderr).
$script:DiagRoot = $null
$script:DiagOut = $null
$script:DiagErr = $null
function Start-Launcher([string]$Root, [string[]]$BatArgs = @(), [switch]$Wait, [string]$StdIn = '') {
    $tag = [IO.Path]::GetRandomFileName().Substring(0, 8)
    $script:DiagRoot = $Root
    $script:DiagOut = Join-Path (Get-Location).Path "launcher-$tag.out.log"
    $script:DiagErr = Join-Path (Get-Location).Path "launcher-$tag.err.log"
    $params = @{
        FilePath = (Join-Path $Root 'CashuPayServer.bat')
        WindowStyle = 'Hidden'
        RedirectStandardOutput = $script:DiagOut
        RedirectStandardError = $script:DiagErr
        PassThru = $true
    }
    if ($BatArgs.Count -gt 0) { $params.ArgumentList = $BatArgs }
    if ($Wait) { $params.Wait = $true }
    if ($StdIn) { $params.RedirectStandardInput = $StdIn }
    Start-Process @params
}

function Show-Diagnostics {
    # Every read is best-effort: the launcher may still hold its log files
    # open, and a broken state must not mask the original error.
    foreach ($pair in @(@('launcher stdout', $script:DiagOut), @('launcher stderr', $script:DiagErr))) {
        Write-Host "---- $($pair[0]) ----"
        try {
            if ($pair[1] -and (Test-Path $pair[1])) { Get-Content $pair[1] | Write-Host }
        } catch { Write-Host "(unreadable: $_)" }
    }
    Write-Host "---- php processes ----"
    try {
        Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
            ForEach-Object { Write-Host $_.CommandLine }
    } catch { Write-Host "(unreadable: $_)" }
    if ($script:DiagRoot) {
        $ini = Join-Path $script:DiagRoot 'php/php.ini'
        Write-Host "---- php.ini rendered: $(Test-Path $ini) ----"
        $errLog = Join-Path $script:DiagRoot 'app/data/php-error.log'
        try {
            if (Test-Path $errLog) {
                Write-Host "---- app php-error.log (tail) ----"
                Get-Content $errLog -Tail 40 | Write-Host
            }
        } catch { Write-Host "(unreadable: $_)" }
    }
}

trap {
    Write-Host "SMOKE FAILED: $_"
    Show-Diagnostics
    break
}

function Wait-File([string]$Path, [string]$What) {
    for ($i = 0; $i -lt 40 -and -not (Test-Path $Path); $i++) {
        Start-Sleep -Milliseconds 500
    }
    if (-not (Test-Path $Path)) { throw $What }
}

function Get-ServerProcs([string]$Root) {
    $esc = [regex]::Escape($Root)
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
        Where-Object { $_.CommandLine -match '-S 127\.0\.0\.1' -and $_.CommandLine -match $esc }
}

function Get-HelperProcs([string]$Root) {
    $esc = [regex]::Escape($Root)
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
        Where-Object { $_.CommandLine -match 'desktop-helper\.php' -and $_.CommandLine -match $esc }
}

# Stop only the web server; the helper must then exit on its own within
# 3 ticks (i.e. closing the server window doesn't strand a background process).
function Stop-ServerAndAwaitHelperExit([string]$Root) {
    $server = Get-ServerProcs $Root
    if (-not $server) { throw "server process not found under $Root" }
    $server | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
    for ($i = 0; $i -lt 30; $i++) {
        Start-Sleep -Seconds 1
        if (-not (Get-HelperProcs $Root)) { return }
    }
    throw "helper did not shut itself down after server stop under $Root"
}

# --- Extract -----------------------------------------------------------------
Expand-Archive $Zip -DestinationPath smoke
$root = (Resolve-Path 'smoke/CashuPayServer').Path
$php = Join-Path $root 'php/php.exe'
if (-not (Test-Path $php)) { throw "package layout wrong: php/php.exe missing" }

# --- 1. Runtime smoke (ini render, extensions, gmp, cron-runner) -------------
& $php -n (Join-Path $root 'php/render-ini.php')
if ($LASTEXITCODE -ne 0) { throw "render-ini.php failed" }
$ini = Join-Path $root 'php/php.ini'

$mods = & $php -c $ini -m
foreach ($ext in 'gmp','curl','mbstring','openssl','pdo_sqlite','sqlite3','zip','fileinfo') {
    if ($mods -notcontains $ext) { throw "extension not loaded: $ext" }
}

$pow = & $php -c $ini -r "echo gmp_strval(gmp_pow(2, 64));"
if ($pow -ne '18446744073709551616') { throw "gmp arithmetic broken: got '$pow'" }

# Fresh (unconfigured) install: the cron runner must bail politely.
& $php -c $ini (Join-Path $root 'cron-runner.php')
if ($LASTEXITCODE -ne 0) { throw "cron-runner.php failed on fresh install" }
Write-Host "runtime smoke: OK"

# --- 2. Launcher boot on the default port ------------------------------------
$env:CASHUPAY_BROWSER_CMD = "cmd /c echo {url} > `"$root\browser-opened.txt`""
Start-Launcher $root | Out-Null

Wait-Http 'http://127.0.0.1:8737/' | Out-Null
Wait-File "$root\browser-opened.txt" "helper never opened the browser"
Write-Host "launcher boot: OK"

# --- 3. Functional smoke + configured cron pass -------------------------------
& $php -c $ini $DriverPhp 'http://127.0.0.1:8737'
if ($LASTEXITCODE -ne 0) { throw "functional smoke (desktop-smoke.php) failed" }

# The install is configured now; a cron pass must run and stamp
# last_external_cron_at (the "cron wired up" signal on desktop installs).
& $php -c $ini (Join-Path $root 'cron-runner.php')
if ($LASTEXITCODE -ne 0) { throw "cron-runner.php failed on configured install" }
$stampQuery = '$db = new PDO("sqlite:" . $argv[1]); $v = $db->query("SELECT value FROM config WHERE key = ''last_external_cron_at''")->fetchColumn(); echo $v === false ? "" : $v;'
$stamp = & $php -c $ini -r $stampQuery (Join-Path $root 'app/data/cashupay.sqlite')
if (-not $stamp) { throw "configured cron pass did not stamp last_external_cron_at" }
Write-Host "functional smoke + configured cron pass: OK (stamp: $stamp)"

# --- 4. Double-launch: reopen, don't respawn ---------------------------------
$env:CASHUPAY_BROWSER_CMD = "cmd /c echo {url} > `"$root\browser-reopened.txt`""
$second = Start-Launcher $root -Wait
if ($second.ExitCode -ne 0) { throw "double-launch exited $($second.ExitCode), expected 0" }
if (-not (Test-Path "$root\browser-reopened.txt")) {
    throw "double-launch did not reopen the browser via the hook"
}
$servers = @(Get-ServerProcs $root)
if ($servers.Count -ne 1) {
    throw "double-launch must not spawn a second server (found $($servers.Count))"
}
Write-Host "double-launch: OK"

# --- 7a. Shutdown of the default-port instance --------------------------------
Stop-ServerAndAwaitHelperExit $root
Write-Host "self-shutdown (default port): OK"

# --- 5. Custom port via the .bat's port argument ------------------------------
$env:CASHUPAY_BROWSER_CMD = "cmd /c echo {url} > `"$root\browser-customport.txt`""
Start-Launcher $root @('9251') | Out-Null
Wait-Http 'http://127.0.0.1:9251/' | Out-Null
Wait-File "$root\browser-customport.txt" "custom port: helper never opened the browser"
if ((Get-Content "$root\browser-customport.txt" -Raw) -notmatch '127\.0\.0\.1:9251') {
    throw "custom port: browser URL does not carry the requested port"
}
Stop-ServerAndAwaitHelperExit $root
Write-Host "custom port: OK"

# --- 6. Hostile install path --------------------------------------------------
$hostileBase = Join-Path (Get-Location).Path 'smoke-hostile/Coffee Shop 2/New folder (2)'
New-Item -ItemType Directory -Path $hostileBase -Force | Out-Null
Expand-Archive $Zip -DestinationPath $hostileBase
$hroot = Join-Path $hostileBase 'CashuPayServer'
# Marker lands on an ASCII path — the accented part under test is the package
# location, not where cmd's redirection writes.
$hostileMarker = Join-Path (Get-Location).Path 'hostile-browser-opened.txt'
$env:CASHUPAY_BROWSER_CMD = "cmd /c echo {url} > `"$hostileMarker`""
Start-Launcher $hroot @('9253') | Out-Null

Wait-Http 'http://127.0.0.1:9253/' | Out-Null
Wait-File $hostileMarker "hostile path: helper never opened the browser"
# The rendered ini must carry the absolute (spaces-and-parens) install path.
if (-not (Select-String -Path (Join-Path $hroot 'php/php.ini') -Pattern 'New folder (2)' -SimpleMatch -Quiet)) {
    throw "hostile path: rendered php.ini does not carry the install path"
}
Stop-ServerAndAwaitHelperExit $hroot
Write-Host "hostile install path: OK"

# --- 6b. Non-ASCII install path: the preflight refuses gracefully -------------
$accentBase = Join-Path (Get-Location).Path 'smoke-hostile/José María'
New-Item -ItemType Directory -Path $accentBase -Force | Out-Null
Expand-Archive $Zip -DestinationPath $accentBase
$aroot = Join-Path $accentBase 'CashuPayServer'
# The refusal path ends in `pause`; feed stdin a newline so the .bat can exit.
$stdinFile = Join-Path (Get-Location).Path 'preflight-stdin.txt'
Set-Content -Path $stdinFile -Value "`r`n`r`n"
$refused = Start-Launcher $aroot @('9255') -Wait -StdIn $stdinFile
if ($refused.ExitCode -ne 1) {
    throw "non-ASCII path: launcher exited $($refused.ExitCode), expected the preflight refusal (1)"
}
if (-not (Select-String -Path $script:DiagOut -Pattern 'move the whole CashuPayServer folder' -SimpleMatch -Quiet)) {
    throw "non-ASCII path: refusal message missing from launcher output"
}
if (@(Get-ServerProcs $aroot).Count -ne 0) { throw "non-ASCII path: a server was started despite the refusal" }
if (@(Get-HelperProcs $aroot).Count -ne 0) { throw "non-ASCII path: a helper was started despite the refusal" }
Write-Host "non-ASCII path refusal: OK"

Write-Host "windows-smoke: all scenarios passed"
