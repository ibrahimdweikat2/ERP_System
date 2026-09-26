$ErrorActionPreference = 'Stop'
$erpRoot = Split-Path -Parent $PSScriptRoot
$erpRuntime = Join-Path $erpRoot '.runtime'
$erpBase = Join-Path $erpRuntime 'mysql-8.4.11-winx64'
$erpMysql = Join-Path $erpBase 'bin/mysqld.exe'
$erpTestRoot = Join-Path $env:LOCALAPPDATA 'ERP-MySQL-Tests'
$erpTestData = Join-Path $erpTestRoot 'data'
if (!(Test-Path -LiteralPath $erpMysql)) { throw 'The workspace MySQL 8 runtime is required.' }
if (!(Test-Path -LiteralPath (Join-Path $erpTestData 'mysql'))) {
    if ((Test-Path -LiteralPath $erpTestData) -and (Get-ChildItem -LiteralPath $erpTestData -Force | Select-Object -First 1)) { throw 'Refusing to initialize a nonempty test data directory.' }
    New-Item -ItemType Directory -Force -Path $erpTestData | Out-Null
    $erpInit = Start-Process -FilePath $erpMysql -ArgumentList '--no-defaults','--initialize-insecure',"--basedir=$($erpBase.Replace('\','/'))","--datadir=$($erpTestData.Replace('\','/'))" -WindowStyle Hidden -Wait -PassThru
    if ($erpInit.ExitCode -ne 0) { throw 'Test MySQL initialization failed.' }
}
$erpListener = Get-NetTCPConnection -LocalPort 3308 -State Listen -ErrorAction SilentlyContinue
if ($erpListener) {
    $erpProcess = Get-CimInstance Win32_Process -Filter "ProcessId = $($erpListener[0].OwningProcess)"
    if ($erpProcess.CommandLine -notlike "*$($erpTestData.Replace('\','/'))*") { throw 'Port 3308 belongs to a different process.' }
} else {
    Start-Process -FilePath $erpMysql -ArgumentList '--no-defaults',"--basedir=$($erpBase.Replace('\','/'))","--datadir=$($erpTestData.Replace('\','/'))",'--port=3308','--bind-address=127.0.0.1','--mysqlx=OFF','--skip-log-bin','--innodb-buffer-pool-size=134217728','--innodb-redo-log-capacity=104857600' -WindowStyle Hidden | Out-Null
}
Write-Output 'Isolated test MySQL: 127.0.0.1:3308; test data on the local C: drive.'
