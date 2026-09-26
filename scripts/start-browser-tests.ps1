param([switch]$ResetData)
$ErrorActionPreference = 'Stop'
$erpRoot = Split-Path -Parent $PSScriptRoot
$erpBackend = Join-Path $erpRoot 'backend'
$erpRuntime = Join-Path $erpRoot '.runtime'
& (Join-Path $PSScriptRoot 'start-test-database.ps1')
$erpEnvPath = Join-Path $erpBackend '.env.e2e'
$erpTestEnv = Get-Content -LiteralPath $erpEnvPath -Raw
if ($erpTestEnv -notmatch '(?m)^DB_DATABASE=[a-zA-Z0-9_]+_test\r?$' -or $erpTestEnv -notmatch '(?m)^DB_PORT=3308\r?$') {
    throw 'Browser tests require an explicitly named _test database on isolated MySQL 3308.'
}
$erpPhp = (Get-Command php).Source
$env:PHPRC = Join-Path $erpRuntime 'php.ini'
Push-Location $erpBackend
try {
    if ($ResetData) { & $erpPhp artisan migrate:fresh --env=e2e --force }
    else { & $erpPhp artisan migrate --env=e2e --force }
    if ($LASTEXITCODE -ne 0) { throw 'Browser test migration failed.' }
    & $erpPhp artisan db:seed --env=e2e --class=BrowserTestSeeder --force
    if ($LASTEXITCODE -ne 0) { throw 'Browser test seeding failed.' }
} finally { Pop-Location }
if (!(Get-NetTCPConnection -LocalPort 8199 -State Listen -ErrorAction SilentlyContinue)) {
    $env:APP_ENV = 'e2e'
    Start-Process -FilePath $erpPhp -ArgumentList '-c',(Join-Path $erpRuntime 'php.ini'),'-S','127.0.0.1:8199',(Join-Path $erpBackend 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php') -WorkingDirectory (Join-Path $erpBackend 'public') -WindowStyle Hidden -RedirectStandardOutput (Join-Path $erpRuntime 'e2e-api.out.log') -RedirectStandardError (Join-Path $erpRuntime 'e2e-api.err.log') | Out-Null
    Remove-Item Env:APP_ENV
}
if (!(Get-NetTCPConnection -LocalPort 5199 -State Listen -ErrorAction SilentlyContinue)) {
    $env:ERP_API_URL = 'http://127.0.0.1:8199'
    $env:ERP_UI_PORT = '5199'
    Start-Process -FilePath (Get-Command node).Source -ArgumentList (Join-Path $erpRoot 'frontend/node_modules/vite/bin/vite.js') -WorkingDirectory (Join-Path $erpRoot 'frontend') -WindowStyle Hidden -RedirectStandardOutput (Join-Path $erpRuntime 'e2e-ui.out.log') -RedirectStandardError (Join-Path $erpRuntime 'e2e-ui.err.log') | Out-Null
    Remove-Item Env:ERP_API_URL,Env:ERP_UI_PORT
}
Write-Output 'Browser test UI: http://127.0.0.1:5199 | API: http://127.0.0.1:8199'
