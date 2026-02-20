param(
  [string]$Config = "$PSScriptRoot\launcher_config.json",
  [int]$GamePort = 8081,
  [int]$DbPort = 24876
)

if (!(Test-Path $Config)) {
  Write-Host "config not found: $Config" -ForegroundColor Red
  exit 1
}

$json = Get-Content $Config -Raw | ConvertFrom-Json
$php = $json.php_exe
$bindHost = if ($json.bind_host) { [string]$json.bind_host } else { "127.0.0.1" }
$docRoot = $json.doc_root
$routerPhp = $json.router_php

if (!(Test-Path $php)) { Write-Host "PHP not found: $php" -ForegroundColor Red; exit 1 }
if (!(Test-Path $docRoot)) { Write-Host "Doc root not found: $docRoot" -ForegroundColor Red; exit 1 }
if (!(Test-Path $routerPhp)) { Write-Host "router.php not found: $routerPhp" -ForegroundColor Red; exit 1 }

function Start-PvzPhpServer {
  param(
    [string]$PhpExe,
    [string]$BindHost,
    [int]$Port,
    [string]$DocRoot,
    [string]$RouterPhp
  )
  $exist = if ($BindHost -eq "0.0.0.0") {
    Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
  } else {
    Get-NetTCPConnection -LocalAddress $BindHost -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
  }
  if ($exist) {
    Write-Host "Already listening: http://${BindHost}:$Port" -ForegroundColor Yellow
    return
  }
  $phpArgs = "-S ${BindHost}`:$Port -t `"$DocRoot`" `"$RouterPhp`""
  $proc = Start-Process -FilePath $PhpExe -ArgumentList $phpArgs -PassThru
  Start-Sleep -Milliseconds 500
  Write-Host "Started: http://${BindHost}:$Port (PID=$($proc.Id))"
}

function Resolve-PublicHost {
  param([string]$BindHost)
  if ($BindHost -ne "0.0.0.0") { return $BindHost }
  $lanIp = (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object {
    $_.IPAddress -ne '127.0.0.1' -and $_.PrefixOrigin -ne 'WellKnown'
  } | Select-Object -First 1 -ExpandProperty IPAddress)
  if ($lanIp) { return $lanIp }
  return "127.0.0.1"
}

Start-PvzPhpServer -PhpExe $php -BindHost $bindHost -Port $GamePort -DocRoot $docRoot -RouterPhp $routerPhp
Start-PvzPhpServer -PhpExe $php -BindHost $bindHost -Port $DbPort -DocRoot $docRoot -RouterPhp $routerPhp

$publicHost = Resolve-PublicHost -BindHost $bindHost
$gameUrl = "http://${publicHost}:$GamePort/pvz/index.php/"
$dbUrl = "http://${publicHost}:$DbPort/pvz/admin/db"
Write-Host "Services started. No browser opened."
Write-Host "Public game URL: $gameUrl"
Write-Host "Public DB URL:   $dbUrl"
