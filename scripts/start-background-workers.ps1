$ErrorActionPreference = 'Stop'
$erpRoot = Split-Path -Parent $PSScriptRoot
$erpPhp = (Get-Command php).Source
$erpIni = Join-Path $erpRoot '.runtime/php.ini'
$erpRuntime = Join-Path $erpRoot '.runtime'
$erpBackend = Join-Path $erpRoot 'backend'
$erpJobs = @{
    'queue-worker' = @('artisan','queue:work','database','--queue=default,exports,notifications','--sleep=3','--tries=2','--timeout=300')
    'maintenance-worker' = @('artisan','queue:work','database','--queue=maintenance','--sleep=10','--tries=1','--timeout=1800')
    'scheduler' = @('artisan','schedule:work')
}
foreach ($erpName in $erpJobs.Keys) {
    $erpPidFile = Join-Path $erpRuntime ($erpName + '.pid')
    $erpAlive = $false
    if (Test-Path -LiteralPath $erpPidFile) {
        $erpSavedPid = 0
        if ([int]::TryParse((Get-Content -LiteralPath $erpPidFile -Raw).Trim(), [ref]$erpSavedPid)) {
            $erpProcess = Get-CimInstance Win32_Process -Filter "ProcessId=$erpSavedPid" -ErrorAction SilentlyContinue
            $erpAlive = $null -ne $erpProcess -and $erpProcess.ExecutablePath -eq $erpPhp -and $erpProcess.CommandLine.Contains('artisan') -and $erpProcess.CommandLine.Contains($erpJobs[$erpName][1])
        }
    }
    if (!$erpAlive) {
        $erpArgs = @('-c',('"'+$erpIni+'"')) + $erpJobs[$erpName]
        $erpProcess = Start-Process -FilePath $erpPhp -ArgumentList $erpArgs -WorkingDirectory $erpBackend -WindowStyle Hidden -RedirectStandardOutput (Join-Path $erpRuntime ($erpName+'.out.log')) -RedirectStandardError (Join-Path $erpRuntime ($erpName+'.err.log')) -PassThru
        Set-Content -LiteralPath $erpPidFile -Value $erpProcess.Id
    }
}
Write-Output 'Started application queue workers and scheduler. No test jobs were submitted.'
