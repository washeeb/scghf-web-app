# Local development MySQL — start / stop / status.
#
# The winget MySQL package installs the binaries but never runs Oracle's
# configurator, so there is no Windows service and no data directory. This
# script runs mysqld directly, which needs no administrator rights.
#
#   .\scripts\dev-mysql.ps1 start
#   .\scripts\dev-mysql.ps1 status
#   .\scripts\dev-mysql.ps1 stop
#
# The server binds to 127.0.0.1 ONLY. root has no password, which is acceptable
# precisely because nothing outside this machine can reach it. Do not remove the
# bind-address argument — on a public network that would expose an
# unauthenticated database.
#
# To make it start automatically instead, register a service from an ELEVATED
# PowerShell (one-off):
#
#   & "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe" --install MySQL84 --datadir="$env:USERPROFILE\mysql-data"
#   Start-Service MySQL84
#
param(
    [Parameter(Position = 0)]
    [ValidateSet('start', 'stop', 'status', 'shell')]
    [string]$Action = 'status'
)

$ErrorActionPreference = 'Stop'

$Base = 'C:\Program Files\MySQL\MySQL Server 8.4'
$Data = "$env:USERPROFILE\mysql-data"
$Mysqld = Join-Path $Base 'bin\mysqld.exe'
$Mysql = Join-Path $Base 'bin\mysql.exe'

function Get-MysqlProcess { Get-Process mysqld -ErrorAction SilentlyContinue }

function Test-MysqlReady {
    try {
        $null = & $Mysql -u root --host=127.0.0.1 --port=3306 -e 'SELECT 1' 2>&1
        return $LASTEXITCODE -eq 0
    } catch { return $false }
}

switch ($Action) {

    'start' {
        if (Test-MysqlReady) { Write-Host 'Already running and accepting connections.' -ForegroundColor Green; break }

        if (-not (Test-Path $Mysqld)) { throw "mysqld not found at $Mysqld" }

        if (-not (Test-Path (Join-Path $Data 'mysql'))) {
            Write-Host "Initialising data directory at $Data ..." -ForegroundColor Cyan
            & $Mysqld --initialize-insecure --datadir="$Data" --basedir="$Base"
            if ($LASTEXITCODE -ne 0) { throw 'Initialisation failed.' }
        }

        Write-Host 'Starting mysqld (127.0.0.1:3306) ...' -ForegroundColor Cyan
        Start-Process -FilePath $Mysqld `
            -ArgumentList "--datadir=`"$Data`"", "--basedir=`"$Base`"", '--bind-address=127.0.0.1', '--port=3306' `
            -WindowStyle Hidden

        for ($i = 0; $i -lt 30; $i++) {
            Start-Sleep -Seconds 1
            if (Test-MysqlReady) { Write-Host 'Ready.' -ForegroundColor Green; break }
        }

        if (-not (Test-MysqlReady)) {
            throw "mysqld did not become ready. Check the error log in $Data for a *.err file."
        }
    }

    'stop' {
        $p = Get-MysqlProcess
        if (-not $p) { Write-Host 'Not running.' -ForegroundColor Yellow; break }
        # Ask it to shut down cleanly so InnoDB flushes rather than recovering on next boot.
        & $Mysql -u root --host=127.0.0.1 -e 'SHUTDOWN' 2>&1 | Out-Null
        Start-Sleep -Seconds 3
        if (Get-MysqlProcess) { Get-MysqlProcess | Stop-Process -Force }
        Write-Host 'Stopped.' -ForegroundColor Green
    }

    'status' {
        $p = Get-MysqlProcess
        if (-not $p) { Write-Host 'mysqld: not running' -ForegroundColor Yellow; break }
        Write-Host "mysqld: running (pid $($p[0].Id))" -ForegroundColor Green
        if (Test-MysqlReady) {
            & $Mysql -u root --host=127.0.0.1 -e `
                "SELECT VERSION() AS version, @@bind_address AS bind_address; SHOW DATABASES LIKE 'scghf%';"
        } else {
            Write-Host 'process is up but not accepting connections yet' -ForegroundColor Yellow
        }
    }

    'shell' {
        & $Mysql -u root --host=127.0.0.1 scghf_dev
    }
}
