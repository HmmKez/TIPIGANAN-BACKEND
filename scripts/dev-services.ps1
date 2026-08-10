<#
  TIPIGANAN - local service starter.

      powershell -ExecutionPolicy Bypass -File scripts\dev-services.ps1
      powershell -ExecutionPolicy Bypass -File scripts\dev-services.ps1 -Status

  Brings up the three background services the app talks to, and reports what
  it could not do rather than failing silently:

    MySQL        REQUIRED. Laragon does not register it as a Windows service,
                 so nothing starts it on boot - this launches mysqld directly.
    Meilisearch  Optional. Search falls back to MySQL LIKE without it.
    Memurai      Optional (Redis). Caching/rate-limiting degrade without it.
                 Developer Edition SHUTS ITSELF DOWN after a few days by
                 design, so finding it stopped is expected, not a fault.
                 Starting a Windows service needs an elevated prompt, so this
                 script reports it instead of pretending it can.

  Paths are discovered where possible so a version bump doesn't break this.
#>

[CmdletBinding()]
param(
    # Report what's running and change nothing.
    [switch]$Status
)

$ErrorActionPreference = 'Continue'

function Test-Port {
    param([int]$Port)
    try {
        $c = New-Object System.Net.Sockets.TcpClient
        $c.Connect('127.0.0.1', $Port)
        $c.Close()
        return $true
    } catch { return $false }
}

# Poll rather than sleeping a fixed guess. A cold Meilisearch start has to
# reopen its on-disk index before it binds the port, which took longer than a
# flat 6-second wait - and reporting "did not come up" for a service that was
# in fact starting normally is worse than waiting a few more seconds.
function Wait-Port {
    param([int]$Port, [int]$TimeoutSeconds = 30)
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        if (Test-Port $Port) { return $true }
        Start-Sleep -Milliseconds 500
    }
    return $false
}

function Write-Line {
    param([string]$Name, [string]$State, [string]$Note = '')
    $colour = switch -Wildcard ($State) {
        'running*'  { 'Green' }
        'started*'  { 'Green' }
        'DOWN*'     { 'Red' }
        default     { 'Yellow' }
    }
    Write-Host ('  {0,-14}' -f $Name) -NoNewline
    Write-Host ('{0,-24}' -f $State) -ForegroundColor $colour -NoNewline
    Write-Host $Note -ForegroundColor DarkGray
}

$isAdmin = ([Security.Principal.WindowsPrincipal] `
    [Security.Principal.WindowsIdentity]::GetCurrent()
).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

Write-Host ''
Write-Host 'TIPIGANAN local services' -ForegroundColor Cyan
Write-Host ('-' * 62) -ForegroundColor DarkGray

# --- MySQL (required) ------------------------------------------------------
if (Test-Port 3306) {
    Write-Line 'MySQL' 'running' 'port 3306'
} elseif ($Status) {
    Write-Line 'MySQL' 'DOWN' 'the app will 500 on anything touching the DB'
} else {
    # Newest install wins, so upgrading Laragon's MySQL doesn't strand this.
    $mysqlDir = Get-ChildItem 'C:\laragon\bin\mysql' -Directory -ErrorAction SilentlyContinue |
                Sort-Object Name -Descending | Select-Object -First 1
    $mysqld = if ($mysqlDir) { Join-Path $mysqlDir.FullName 'bin\mysqld.exe' } else { $null }

    if ($mysqld -and (Test-Path $mysqld)) {
        # Not $args - that's a PowerShell automatic variable.
        $ini = Join-Path $mysqlDir.FullName 'my.ini'
        $mysqlArgs = if (Test-Path $ini) { @("--defaults-file=$ini") } else { @() }
        Start-Process -FilePath $mysqld -ArgumentList $mysqlArgs -WindowStyle Hidden
        if (Wait-Port 3306 -TimeoutSeconds 30) { Write-Line 'MySQL' 'started' $mysqlDir.Name }
        else { Write-Line 'MySQL' 'DOWN' 'mysqld launched but 3306 never opened - check Laragon' }
    } else {
        Write-Line 'MySQL' 'DOWN' 'mysqld.exe not found under C:\laragon\bin\mysql - start Laragon manually'
    }
}

# --- Meilisearch (optional) ------------------------------------------------
$meiliExe = 'C:\Users\conch\meilisearch\meilisearch.exe'
if (Test-Port 7700) {
    Write-Line 'Meilisearch' 'running' 'port 7700'
} elseif ($Status) {
    Write-Line 'Meilisearch' 'down' 'search still works via the MySQL fallback'
} elseif (Test-Path $meiliExe) {
    # Working directory matters: the index (data.ms) is resolved relative to it,
    # so launching from elsewhere silently creates a second, empty index.
    Start-Process -FilePath $meiliExe `
        -ArgumentList '--http-addr', '127.0.0.1:7700', '--no-analytics' `
        -WorkingDirectory (Split-Path $meiliExe) -WindowStyle Hidden
    if (Wait-Port 7700 -TimeoutSeconds 45) { Write-Line 'Meilisearch' 'started' 'port 7700' }
    else { Write-Line 'Meilisearch' 'down' 'did not come up - see meilisearch.log.err' }
} else {
    Write-Line 'Meilisearch' 'not installed' 'optional - MySQL fallback in use'
}

# --- Memurai / Redis (optional) --------------------------------------------
$memurai = Get-Service Memurai -ErrorAction SilentlyContinue
if (Test-Port 6379) {
    Write-Line 'Memurai' 'running' 'port 6379'
} elseif (-not $memurai) {
    Write-Line 'Memurai' 'not installed' 'optional - caching degrades gracefully'
} elseif ($Status) {
    Write-Line 'Memurai' 'down' 'caching + rate limiting inert; app still works'
} elseif ($isAdmin) {
    Start-Service Memurai -ErrorAction SilentlyContinue
    if (Wait-Port 6379 -TimeoutSeconds 20) { Write-Line 'Memurai' 'started' 'port 6379' }
    else { Write-Line 'Memurai' 'down' 'service would not start - check memurai-log.txt' }
} else {
    Write-Line 'Memurai' 'down' 'needs an ELEVATED prompt: Start-Service Memurai'
}

Write-Host ('-' * 62) -ForegroundColor DarkGray

if (-not $isAdmin -and -not (Test-Port 6379) -and $memurai) {
    Write-Host ''
    Write-Host 'Redis is optional - the app runs fine without it, just uncached.' -ForegroundColor DarkGray
    Write-Host 'To start it (and stop it dying every few days), run ONCE as admin:' -ForegroundColor DarkGray
    Write-Host '  Start-Service Memurai' -ForegroundColor Gray
    Write-Host '  sc.exe failure Memurai reset= 0 actions= restart/5000/restart/5000/restart/60000' -ForegroundColor Gray
}

Write-Host ''
Write-Host 'Then, in separate terminals:' -ForegroundColor DarkGray
Write-Host '  php artisan serve                 (backend  -> 127.0.0.1:8000)' -ForegroundColor Gray
Write-Host '  npm run dev  [in the frontend]    (frontend -> localhost:5173)' -ForegroundColor Gray
Write-Host ''
