# Run in an elevated Windows PowerShell. Does not change sleep/firewall settings.
param(
    [string]$PhpPath = ''
)

$ErrorActionPreference = 'Stop'
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Open PowerShell with Run as administrator, then run this installer again.'
}
if (-not $PhpPath) {
    $PhpPath = (Get-Command php.exe -CommandType Application -ErrorAction Stop).Source
}
$PhpPath = (Resolve-Path -LiteralPath $PhpPath).Path
$projectPath = Split-Path -Parent $PSScriptRoot
$runnerPath = Join-Path $PSScriptRoot 'run-attendance-bridge.ps1'
foreach ($relativePath in @('artisan', 'vendor\autoload.php', '.env')) {
    if (-not (Test-Path -LiteralPath (Join-Path $projectPath $relativePath) -PathType Leaf)) {
        throw "Missing $relativePath. Install BE dependencies and configure .env first."
    }
}

$taskName = 'A7A-Attendance-Bridge'
$powershellPath = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "{0}" -PhpPath "{1}"' -f $runnerPath, $PhpPath
$action = New-ScheduledTaskAction -Execute $powershellPath -Argument $arguments -WorkingDirectory $projectPath
$trigger = New-ScheduledTaskTrigger -AtStartup
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable `
    -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
# SYSTEM permits startup before login. Keep this checkout and .env restricted to trusted users.
$taskPrincipal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$existing = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if ($existing) {
    throw "Task $taskName already exists. No changes made. See docs/attendance-device-bridge.md for restarting it."
}
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger `
    -Settings $settings -Principal $taskPrincipal `
    -Description 'A7A LAN bridge: web requests and automatic attendance import for yesterday/today.' | Out-Null
Start-ScheduledTask -TaskName $taskName
Write-Host "Installed and started: $taskName"
Write-Host "Log directory: $projectPath\storage\logs"
Write-Host 'Keep this computer awake, connected to device LAN and host database. Stop any old manual bridge loop.'
