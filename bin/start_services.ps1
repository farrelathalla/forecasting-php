# Starts all three services as detached Windows processes and returns immediately.
#
#   powershell -ExecutionPolicy Bypass -File bin\start_services.ps1
#   powershell -ExecutionPolicy Bypass -File bin\start_services.ps1 -Stop
#
# Detached on purpose: started this way the services survive the terminal that launched
# them, which is the difference between "running while I watch" and "running".
#
# Logs land in var\. The sidecar needs ~3 minutes on its first start to load the
# Chronos-2 weights; until then PHP falls back to Naive-Seasonal and flags the run
# degraded rather than failing.

param(
    [int]$WebPort = 8081,
    [int]$SidecarPort = 8008,
    [switch]$Stop
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$var = Join-Path $root 'var'
if (-not (Test-Path $var)) { New-Item -ItemType Directory -Path $var | Out-Null }

$pidFile = Join-Path $var 'services.pid'

function Stop-Services {
    if (-not (Test-Path $pidFile)) {
        Write-Host 'No services.pid found; nothing to stop.'
        return
    }
    foreach ($line in Get-Content $pidFile) {
        $parts = $line -split '='
        if ($parts.Count -ne 2) { continue }
        $name = $parts[0]
        $servicePid = [int]$parts[1]
        try {
            Stop-Process -Id $servicePid -Force -ErrorAction Stop
            Write-Host "stopped $name (pid $servicePid)"
        } catch {
            Write-Host "$name (pid $servicePid) was not running"
        }
    }
    Remove-Item $pidFile -Force
}

if ($Stop) {
    Stop-Services
    exit 0
}

$php = 'C:\xampp\php\php.exe'
if (-not (Test-Path $php)) {
    $php = (Get-Command php -ErrorAction SilentlyContinue).Source
}
if (-not $php) { throw 'php.exe not found. Install XAMPP or put php on PATH.' }

$venvPython = Join-Path $root 'service\.venv\Scripts\python.exe'
if (-not (Test-Path $venvPython)) {
    throw "sidecar venv missing: $venvPython`nRun: python -m venv --system-site-packages service\.venv"
}

if (Test-Path $pidFile) {
    Write-Host 'services.pid exists; stopping the previous run first.'
    Stop-Services
}

$pids = @()

$sidecar = Start-Process -FilePath $venvPython `
    -ArgumentList '-u', '-m', 'uvicorn', 'app:app', '--host', '127.0.0.1', '--port', "$SidecarPort", '--app-dir', 'service' `
    -WorkingDirectory $root -WindowStyle Hidden -PassThru `
    -RedirectStandardOutput (Join-Path $var 'sidecar.log') `
    -RedirectStandardError (Join-Path $var 'sidecar.err')
$pids += "sidecar=$($sidecar.Id)"
Write-Host "sidecar   pid $($sidecar.Id)  http://127.0.0.1:$SidecarPort/health"

$web = Start-Process -FilePath $php `
    -ArgumentList '-S', "127.0.0.1:$WebPort", '-t', (Join-Path $root 'public') `
    -WorkingDirectory $root -WindowStyle Hidden -PassThru `
    -RedirectStandardOutput (Join-Path $var 'web.log') `
    -RedirectStandardError (Join-Path $var 'web.err')
$pids += "web=$($web.Id)"
Write-Host "web       pid $($web.Id)  http://127.0.0.1:$WebPort/"

$pipeline = Start-Process -FilePath $php `
    -ArgumentList (Join-Path $root 'bin\run_all.php'), '--daemon' `
    -WorkingDirectory $root -WindowStyle Hidden -PassThru `
    -RedirectStandardOutput (Join-Path $var 'pipeline.log') `
    -RedirectStandardError (Join-Path $var 'pipeline.err')
$pids += "pipeline=$($pipeline.Id)"
Write-Host "pipeline  pid $($pipeline.Id)  poll 1 min, cycle 15 min"

Set-Content -Path $pidFile -Value $pids -Encoding utf8

Write-Host ''
Write-Host "pids written to $pidFile"
Write-Host 'logs: var\sidecar.log  var\web.log  var\pipeline.log'
Write-Host 'stop with: powershell -ExecutionPolicy Bypass -File bin\start_services.ps1 -Stop'
