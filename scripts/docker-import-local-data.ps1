# Copies the current local store (MySQL on 3307 + uploaded files) into the Docker stack.
# Run from the project root after "docker compose up -d" has started once:
#   powershell -ExecutionPolicy Bypass -File scripts\docker-import-local-data.ps1
# The Docker database is REPLACED by the local one. The local store is only read.
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

function Read-EnvValue([string]$file, [string]$name) {
    $line = Get-Content -LiteralPath $file -Encoding UTF8 | Where-Object { $_ -match "^$name=" } | Select-Object -First 1
    if (!$line) { return '' }
    return ($line -replace "^$name=", '').Trim().Trim('"')
}

if (!(Test-Path '.env')) { throw 'Missing .env next to docker-compose.yml. Copy .env.docker.example to .env first.' }
# Encrypted columns (bank accounts) and sessions only decrypt with the key that wrote them.
$localKey = Read-EnvValue 'backend/.env' 'APP_KEY'
$dockerKey = Read-EnvValue '.env' 'APP_KEY'
if ($localKey -ne $dockerKey) { throw 'APP_KEY in .env differs from backend/.env. Copy it exactly, then run "docker compose up -d" again.' }

$database = Read-EnvValue 'backend/.env' 'DB_DATABASE'
$mysqldump = Join-Path $root '.runtime/mysql-8.4.11-winx64/bin/mysqldump.exe'
$credentials = Join-Path $root '.runtime/mysql-admin.ini'
if (!(Get-NetTCPConnection -LocalPort 3307 -State Listen -ErrorAction SilentlyContinue)) { throw 'Local MySQL (port 3307) is not running. Start it with scripts\start-development.ps1.' }

$work = Join-Path $root '.runtime/docker-migration'
New-Item -ItemType Directory -Force -Path $work | Out-Null
$dump = Join-Path $work 'local.sql'

Write-Output "1/5 Dumping local database '$database'..."
& $mysqldump "--defaults-extra-file=$credentials" --single-transaction --routines --triggers --hex-blob --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 "--result-file=$dump" $database
if ($LASTEXITCODE -ne 0) { throw 'mysqldump failed.' }

# Triggers carry the local account as DEFINER, which does not exist in the container.
$utf8 = New-Object System.Text.UTF8Encoding($false)
$sql = [IO.File]::ReadAllText($dump, $utf8)
$sql = [regex]::Replace($sql, 'DEFINER=`[^`]*`@`[^`]*`', '')
[IO.File]::WriteAllText($dump, $sql, $utf8)

Write-Output '2/5 Stopping workers and scheduler during the import...'
docker compose stop queue maintenance scheduler | Out-Null

Write-Output '3/5 Loading the database into Docker (replaces its current data)...'
docker compose cp $dump db:/tmp/import.sql
if ($LASTEXITCODE -ne 0) { throw 'Copy into the db container failed. Is the stack running?' }
# No double quotes inside the argument: Windows PowerShell 5 would mangle them.
docker compose exec -T db sh -c 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql --default-character-set=utf8mb4 -uroot $MYSQL_DATABASE < /tmp/import.sql && rm -f /tmp/import.sql'
if ($LASTEXITCODE -ne 0) { throw 'Import into Docker MySQL failed.' }

Write-Output '4/5 Copying uploaded files (product images, attachments)...'
docker compose cp 'backend/storage/app/.' app:/var/www/html/storage/app/
docker compose exec -T app chown -R www-data:www-data /var/www/html/storage

Write-Output '5/5 Applying any newer migrations and restarting...'
docker compose exec -T --user www-data app php artisan migrate --force
docker compose restart app queue maintenance scheduler web | Out-Null
Remove-Item -LiteralPath $dump -Force
Write-Output 'Done. Open the address from APP_URL and sign in with your usual account.'
