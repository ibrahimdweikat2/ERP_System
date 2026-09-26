# Builds «دفتر» on this PC and deploys it to the Azure VM over SSH. Run from the project root:
#   powershell -ExecutionPolicy Bypass -File deploy\azure\deploy.ps1 -Server <VM IP or DNS name>
# Re-run it after every code change: the database and uploaded files on the server are kept.
param(
    [Parameter(Mandatory = $true)][string]$Server,
    [string]$User = 'azureuser',
    [string]$KeyFile = (Join-Path $env:USERPROFILE '.ssh\id_ed25519'),
    [switch]$SkipBuild
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
if (!(Test-Path $envFile)) { throw 'Missing deploy/azure/.env. Copy deploy/azure/.env.azure.example to deploy/azure/.env and fill it in.' }
foreach ($name in 'ERP_DOMAIN', 'ERP_ACME_EMAIL', 'APP_KEY', 'APP_URL', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'SANCTUM_STATEFUL_DOMAINS') {
    if (!(Read-EnvValue $envFile $name)) { throw "deploy/azure/.env: $name is empty." }
}
if ((Read-EnvValue $envFile 'APP_KEY') -ne (Read-EnvValue 'backend/.env' 'APP_KEY')) {
    throw 'APP_KEY in deploy/azure/.env differs from backend/.env. Copy it exactly: moved data only decrypts with the same key.'
}
$localDb = (Read-EnvValue $envFile 'DB_HOST') -eq 'db'
if ($localDb -and !(Read-EnvValue $envFile 'DB_ROOT_PASSWORD')) { throw 'DB_HOST=db needs DB_ROOT_PASSWORD.' }
$profileArg = if ($localDb) { '--profile localdb' } else { '' }
if (!(Test-Path $KeyFile)) { throw "SSH key not found: $KeyFile (use -KeyFile)." }
$target = "$User@$Server"
$sshOpts = @('-i', $KeyFile, '-o', 'StrictHostKeyChecking=accept-new')

if (!$SkipBuild) {
    # The free VMs (B2ats v2 / B1s) are x86-64; do not pick the ARM size B2pts v2 for these images.
    Write-Output '1/4 Building images (linux/amd64)...'
    docker build --platform linux/amd64 -f docker/php/Dockerfile -t daftar-api .; Invoke-Checked 'Building daftar-api'
    docker build --platform linux/amd64 -f docker/nginx/Dockerfile -t daftar-web .; Invoke-Checked 'Building daftar-web'
}

Write-Output '2/4 Sending images to the server (compressed; the first upload is the largest)...'
# cmd.exe pipes the image stream byte-for-byte; a PowerShell 5 pipe would corrupt it.
$ssh = "ssh -C -i `"$KeyFile`" -o StrictHostKeyChecking=accept-new $target docker load"
cmd /c "docker save daftar-api daftar-web | $ssh"; Invoke-Checked 'Sending images'

Write-Output '3/4 Copying settings...'
scp @sshOpts 'deploy/azure/docker-compose.yml' 'deploy/azure/Caddyfile' "${target}:/opt/daftar/"; Invoke-Checked 'Copying compose files'
scp @sshOpts $envFile "${target}:/opt/daftar/.env"; Invoke-Checked 'Copying .env'

Write-Output '4/4 Starting on the server...'
ssh @sshOpts $target "cd /opt/daftar && chmod 600 .env && docker compose $profileArg up -d --remove-orphans && docker image prune -f >/dev/null"; Invoke-Checked 'Starting containers'

$url = "https://$(Read-EnvValue $envFile 'ERP_DOMAIN')"
Write-Output "Waiting for $url (the first HTTPS certificate can take a minute)..."
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
for ($i = 0; $i -lt 30; $i++) {
    try {
        $r = Invoke-WebRequest -Uri "$url/api/v1/health" -UseBasicParsing -TimeoutSec 10
        if ($r.StatusCode -eq 200) { Write-Output "Ready: $url"; exit 0 }
    } catch { Start-Sleep -Seconds 6 }
}
Write-Output "Not reachable yet. Check on the server: ssh $target 'cd /opt/daftar && docker compose ps && docker compose logs --tail 50 app caddy'"
exit 1
