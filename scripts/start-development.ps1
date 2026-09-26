$ErrorActionPreference = 'Stop'
$erpRoot = Split-Path -Parent $PSScriptRoot
$erpPhp = (Get-Command php).Source
$erpIni = Join-Path $erpRoot '.runtime/php.ini'
$erpRuntime = Join-Path $erpRoot '.runtime'
New-Item -ItemType Directory -Force -Path $erpRuntime | Out-Null
$erpMysql = Join-Path $erpRuntime 'mysql-8.4.11-winx64/bin/mysqld.exe'
if ((Test-Path -LiteralPath $erpMysql) -and !(Get-NetTCPConnection -LocalPort 3307 -State Listen -ErrorAction SilentlyContinue)) {
    $erpBase = (Join-Path $erpRuntime 'mysql-8.4.11-winx64').Replace('\','/')
    $erpData = (Join-Path $erpRuntime 'mysql-data').Replace('\','/')
    Start-Process -FilePath $erpMysql -ArgumentList '--no-defaults',"--basedir=$erpBase","--datadir=$erpData",'--port=3307','--bind-address=127.0.0.1','--mysqlx=OFF','--log-bin-trust-function-creators=1' -WindowStyle Hidden | Out-Null
}
if (!(Get-NetTCPConnection -LocalPort 8188 -State Listen -ErrorAction SilentlyContinue)) {
    $erpPhpArgs = @()
    if (Test-Path -LiteralPath $erpIni) { $erpPhpArgs += @('-c',$erpIni) }
    $erpPhpArgs += @('-S','127.0.0.1:8188',(Join-Path $erpRoot 'backend/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'))
    Start-Process -FilePath $erpPhp -ArgumentList $erpPhpArgs -WorkingDirectory (Join-Path $erpRoot 'backend/public') -WindowStyle Hidden -RedirectStandardOutput (Join-Path $erpRuntime 'api.out.log') -RedirectStandardError (Join-Path $erpRuntime 'api.err.log') | Out-Null
}
if (!(Get-NetTCPConnection -LocalPort 5173 -LocalAddress 127.0.0.1 -State Listen -ErrorAction SilentlyContinue)) {
    $erpNode = (Get-Command node).Source
    Start-Process -FilePath $erpNode -ArgumentList (Join-Path $erpRoot 'frontend/node_modules/vite/bin/vite.js') -WorkingDirectory (Join-Path $erpRoot 'frontend') -WindowStyle Hidden -RedirectStandardOutput (Join-Path $erpRuntime 'ui.out.log') -RedirectStandardError (Join-Path $erpRuntime 'ui.err.log') | Out-Null
}
& (Join-Path $PSScriptRoot 'start-background-workers.ps1')
Write-Output 'UI: http://127.0.0.1:5173 | API: http://127.0.0.1:8188'
Write-Output 'Local credentials: .runtime/local-access.txt'
