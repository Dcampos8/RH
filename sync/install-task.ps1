$ErrorActionPreference = 'Stop'
$syncDir = $PSScriptRoot
$python = Join-Path $syncDir '.venv\Scripts\python.exe'
$program = Join-Path $syncDir 'sync_device.py'
$taskName = 'RH_F19_Synchronization'

if (-not (Test-Path -LiteralPath $python)) {
    throw "No encuentro Python del entorno virtual: $python. Sigue primero la instalación de sync en README.md."
}

$credential = Get-Credential -Message 'Cuenta de Windows que ejecutará la sincronización. Debe tener acceso a SQL Server y al F19.'
$action = New-ScheduledTaskAction -Execute $python -Argument ('"' + $program + '" --scheduled') -WorkingDirectory $syncDir
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -StartWhenAvailable

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings `
    -User $credential.UserName -Password $credential.GetNetworkCredential().Password `
    -RunLevel Limited -Description 'Consulta la configuración de CHECADOR y sincroniza el F19 cuando vence el intervalo configurado por admin.' -Force | Out-Null

Write-Host "Tarea $taskName instalada. La tarea revisa la configuración cada minuto; la frecuencia efectiva se elige desde settings.php."
