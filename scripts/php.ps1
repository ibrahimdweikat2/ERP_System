$ErrorActionPreference = 'Stop'
$erpRoot = Split-Path -Parent $PSScriptRoot
$erpIni = Join-Path $erpRoot '.runtime/php.ini'
if (Test-Path -LiteralPath $erpIni) { & php -c $erpIni @args } else { & php @args }
exit $LASTEXITCODE
