# Exports the store's current data from the local Docker copy (http://localhost) so it can be
# moved to the EC2 server: a cleaned database dump and the uploaded files. Read-only locally.
#   powershell -ExecutionPolicy Bypass -File deploy\aws\export-data.ps1
# The output folder holds customer data: copy it to the server, then delete it.
param([string]$OutDir = (Join-Path $env:USERPROFILE 'Desktop\daftar-export'))
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location $root
function Invoke-Checked([string]$what) { if ($LASTEXITCODE -ne 0) { throw "$what failed (exit $LASTEXITCODE)." } }

if (Test-Path $OutDir) { throw "$OutDir already exists. Remove it or pass -OutDir." }
New-Item -ItemType Directory -Path $OutDir | Out-Null
$utf8 = New-Object System.Text.UTF8Encoding($false)

Write-Output '1/3 Dumping the database...'
# No double quotes inside the argument: Windows PowerShell 5 would mangle them.
docker compose exec -T db sh -c 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysqldump -uroot --single-transaction --routines --triggers --hex-blob --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 $MYSQL_DATABASE > /tmp/erp.sql'
Invoke-Checked 'mysqldump'
$dump = Join-Path $OutDir 'erp.sql'
docker compose cp db:/tmp/erp.sql $dump; Invoke-Checked 'Copying the dump'
docker compose exec -T db sh -c 'rm -f /tmp/erp.sql' | Out-Null
# The server's database account differs; triggers must not name the old one as DEFINER.
[IO.File]::WriteAllText($dump, [regex]::Replace([IO.File]::ReadAllText($dump, $utf8), 'DEFINER=`[^`]*`@`[^`]*`', ''), $utf8)

Write-Output '2/3 Packing uploaded files (without old backups)...'
$files = Join-Path $OutDir 'storage-app'
docker compose cp app:/var/www/html/storage/app $files; Invoke-Checked 'Copying files'
tar -czf (Join-Path $OutDir 'storage.tgz') -C $OutDir --exclude 'storage-app/backups' storage-app; Invoke-Checked 'tar'
Remove-Item -LiteralPath $files -Recurse -Force

Write-Output '3/3 APP_KEY to put in the server''s backend/.env (needed to read encrypted bank details):'
(Get-Content -LiteralPath '.env' -Encoding UTF8 | Where-Object { $_ -match '^APP_KEY=' }) -replace '^APP_KEY=', '  '
Write-Output "Done: $OutDir (erp.sql, storage.tgz). It contains customer data; delete it after the upload."
