param(
    [Parameter(Mandatory = $true)]
    [string]$PhpPath
)

$ErrorActionPreference = 'Stop'
$projectPath = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $projectPath
if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
    throw "PHP executable not found: $PhpPath"
}
$logDirectory = Join-Path $projectPath 'storage\logs'
New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null
$env:ATTENDANCE_DEVICE_BRIDGE = '1'

# The command runs serially; a slow import must finish before the next poll.
while ($true) {
    $logPath = Join-Path $logDirectory ("attendance-bridge-{0}.log" -f (Get-Date -Format 'yyyy-MM-dd'))
    try {
        & $PhpPath artisan attendance:device-bridge --once --auto-import --no-interaction *>> $logPath
        if ($LASTEXITCODE -ne 0) {
            Add-Content -LiteralPath $logPath -Value ("[{0}] Bridge exited with code {1}; retrying in 15 seconds." -f (Get-Date -Format o), $LASTEXITCODE)
        }
    } catch {
        Add-Content -LiteralPath $logPath -Value ("[{0}] {1}" -f (Get-Date -Format o), $_.Exception.Message)
    }
    Start-Sleep -Seconds 15
}
