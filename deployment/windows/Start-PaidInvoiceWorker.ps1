param([string]$PhpPath = 'C:\xampp\php\php.exe')

$ErrorActionPreference = 'Stop'
$projectPath = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\..')).Path
$artisanPath = Join-Path $projectPath 'artisan'
$workerLog = Join-Path $projectPath 'storage\logs\paid-future-worker.log'

if (!(Test-Path -LiteralPath $PhpPath) -or !(Test-Path -LiteralPath $artisanPath)) {
    throw 'PHP or the IQRA artisan entry point was not found.'
}
Set-Location -LiteralPath $projectPath

# Task Scheduler runs this hidden with IgnoreNew. Recycle clean exits as well
# as failures; listen only to the portal paid queue, never the historical queue.
while ($true) {
    try {
        Add-Content -LiteralPath $workerLog -Value ('[' + (Get-Date -Format o) + '] Starting paid-future worker')
        & $PhpPath $artisanPath queue:work database --queue=paid-future --sleep=3 --timeout=300 --tries=3 --max-time=3600 --memory=256 --no-interaction >> $workerLog 2>&1
        Add-Content -LiteralPath $workerLog -Value ('[' + (Get-Date -Format o) + '] Worker exit code: ' + $LASTEXITCODE)
    } catch {
        Add-Content -LiteralPath $workerLog -Value ('Worker exited: ' + $_.Exception.GetType().Name)
    }
    Start-Sleep -Seconds 5
}
