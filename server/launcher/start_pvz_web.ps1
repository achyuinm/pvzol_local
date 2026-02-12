param(
  [string]$Config = "$PSScriptRoot\launcher_config.json"
)

if (!(Test-Path $Config)) {
  Write-Host "config not found: $Config" -ForegroundColor Red
  exit 1
}

$json = Get-Content $Config -Raw | ConvertFrom-Json
$php = $json.php_exe
$flash = $json.flash_player_path
$host = $json.bind_host
$port = [int]$json.port
$docRoot = $json.doc_root
$routerPhp = $json.router_php
$basePath = $json.base_path
$seed = $json.seed

if (!(Test-Path $php)) { Write-Host "PHP not found: $php" -ForegroundColor Red; exit 1 }
if (!(Test-Path $flash)) { Write-Host "Flash Player not found: $flash" -ForegroundColor Red; exit 1 }
if (!(Test-Path $docRoot)) { Write-Host "Doc root not found: $docRoot" -ForegroundColor Red; exit 1 }
if (!(Test-Path $routerPhp)) { Write-Host "router.php not found: $routerPhp" -ForegroundColor Red; exit 1 }

$phpArgs = "-S ${host}`:$port -t `"$docRoot`" `"$routerPhp`""
Start-Process -FilePath $php -ArgumentList $phpArgs
Start-Sleep -Milliseconds 600

$baseUrl = "http://${host}`:$port${basePath}index.php/"
$baseInfo = "http://${host}`:$port${basePath}"
$swfUrl = "http://${host}`:$port${basePath}main.swf?base_url=$([uri]::EscapeDataString($baseUrl))&base_url_info=$([uri]::EscapeDataString($baseInfo))&seed=$([uri]::EscapeDataString($seed))"

Start-Process -FilePath $flash -ArgumentList $swfUrl

Write-Host "Started PHP: http://${host}`:$port"
Write-Host "Opened SWF: $swfUrl"

