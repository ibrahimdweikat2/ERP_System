# Moves the store's data from the Docker stack on this PC (http://localhost) to the Azure server:
# the database and the uploaded files (product images, attachments). Run from the project root
# AFTER deploy.ps1 has started the server once:
#   powershell -ExecutionPolicy Bypass -File deploy\azure\migrate-data.ps1 -Server <VM IP or DNS name>
# The server's database is REPLACED. The local copy is only read.
param(
    [Parameter(Mandatory = $true)][string]$Server,
    [string]$User = 'azureuser',
    [string]$KeyFile = (Join-Path $env:USERPROFILE '.ssh\id_ed25519')
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location $root

function Read-EnvValue([string]$file, [string]$name) {
    $line = Get-Content -LiteralPath $file -Encoding UTF8 | Where-Object { $_ -match "^$name=" } | Select-Object -First 1
    if (!$line) { return '' }
    return ($line -replace "^$name=", '').Trim().Trim('"')
}
function Invoke-Checked([string]$what) {
    if ($LASTEXITCODE -ne 0) { throw "$what failed (exit $LASTEXITCODE)." }
}

$envFile = Join-Path $root 'deploy/azure/.env'
if ((Read-EnvValue $envFile 'APP_KEY') -ne (Read-EnvValue '.env' 'APP_KEY')) { throw 'APP_KEY differs between .env (local Docker) and deploy/azure/.env.' }
$profileArg = if ((Read-EnvValue $envFile 'DB_HOST') -eq 'db') { '--profile localdb' } else { '' }
$target = "$User@$Server"
$sshOpts = @('-i', $KeyFile, '-o', 'StrictHostKeyChecking=accept-new')
$utf8 = New-Object System.Text.UTF8Encoding($false)
$work = Join-Path $root '.runtime/azure-migration'
if (Test-Path $work) { Remove-Item -LiteralPath $work -Recurse -Force }
New-Item -ItemType Directory -Force -Path $work | Out-Null

try {
    Write-Output '1/5 Dumping the local Docker database...'
    # No double quotes inside these arguments: Windows PowerShell 5 would mangle them.
    docker compose exec -T db sh -c 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysqldump -uroot --single-transaction --routines --triggers --hex-blob --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 $MYSQL_DATABASE > /tmp/erp.sql'
    Invoke-Checked 'mysqldump'
    $dump = Join-Path $work 'erp.sql'
    docker compose cp db:/tmp/erp.sql $dump; Invoke-Checked 'Copying the dump'
    docker compose exec -T db rm -f /tmp/erp.sql | Out-Null
    # Azure MySQL grants no SUPER, so triggers cannot keep another account as DEFINER.
    [IO.File]::WriteAllText($dump, [regex]::Replace([IO.File]::ReadAllText($dump, $utf8), 'DEFINER=`[^`]*`@`[^`]*`', ''), $utf8)

    Write-Output '2/5 Packing uploaded files (without old backups)...'
    docker compose cp app:/var/www/html/storage/app (Join-Path $work 'storage-app'); Invoke-Checked 'Copying files'
    tar -czf (Join-Path $work 'storage.tgz') -C $work --exclude 'storage-app/backups' storage-app; Invoke-Checked 'tar'

    # The server-side steps travel as a script file, so no shell quoting passes through PowerShell.
    $remote = @'
#!/bin/sh
set -e
cd /opt/daftar
docker compose __PROFILE__ stop queue maintenance scheduler
docker compose __PROFILE__ run --rm --no-deps -v /opt/daftar/import:/import --entrypoint sh app -c 'MYSQL_PWD="$DB_PASSWORD" mysql --default-character-set=utf8mb4 -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" < /import/erp.sql'
docker compose __PROFILE__ run --rm --no-deps -v /opt/daftar/import:/import --entrypoint sh app -c 'tar -xzf /import/storage.tgz -C /tmp && cp -a /tmp/storage-app/. /var/www/html/storage/app/ && chown -R www-data:www-data /var/www/html/storage'
rm -f /opt/daftar/import/erp.sql /opt/daftar/import/storage.tgz /opt/daftar/import/migrate.sh
docker compose __PROFILE__ up -d --force-recreate app queue maintenance scheduler web
'@
    [IO.File]::WriteAllText((Join-Path $work 'migrate.sh'), ($remote -replace "`r", '' -replace '__PROFILE__', $profileArg), $utf8)

    Write-Output '3/5 Uploading to the server...'
    scp @sshOpts $dump (Join-Path $work 'storage.tgz') (Join-Path $work 'migrate.sh') "${target}:/opt/daftar/import/"; Invoke-Checked 'Upload'

    Write-Output '4/5 Importing on the server (workers paused)...'
    ssh @sshOpts $target 'sh /opt/daftar/import/migrate.sh'; Invoke-Checked 'Server import'

    Write-Output '5/5 Done. Sign in with your usual account on the server address.'
}
finally {
    # The dump holds customer data; do not leave copies lying around.
    if (Test-Path $work) { Remove-Item -LiteralPath $work -Recurse -Force }
}
