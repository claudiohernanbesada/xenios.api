param(
    [int] $LookbackHours = 2,
    [int] $Limit = 100
)

$ErrorActionPreference = 'Stop'

$apiRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$script = Join-Path $apiRoot 'tools\procesar_fichadas_push.php'
$logDir = Join-Path $apiRoot 'logs'
$logFile = Join-Path $logDir 'fichadas_push.log'

if (-not (Test-Path -LiteralPath $logDir)) {
    New-Item -ItemType Directory -Path $logDir | Out-Null
}

$php = $env:SGI_PHP_EXE
if ([string]::IsNullOrWhiteSpace($php)) {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCommand) {
        $php = $phpCommand.Source
    }
}

if ([string]::IsNullOrWhiteSpace($php) -or -not (Test-Path -LiteralPath $php)) {
    $knownPhp = 'C:\wamp64\bin\php\php8.3.14\php.exe'
    if (Test-Path -LiteralPath $knownPhp) {
        $php = $knownPhp
    }
}

if ([string]::IsNullOrWhiteSpace($php) -or -not (Test-Path -LiteralPath $php)) {
    throw 'No se encontró php.exe. Configurá SGI_PHP_EXE con la ruta del PHP CLI del servidor.'
}

$timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
Add-Content -LiteralPath $logFile -Encoding UTF8 -Value "[$timestamp] Ejecutando push fichadas"

& $php $script $LookbackHours $Limit 2>&1 |
    ForEach-Object { Add-Content -LiteralPath $logFile -Encoding UTF8 -Value $_ }
$exitCode = $LASTEXITCODE

$timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
Add-Content -LiteralPath $logFile -Encoding UTF8 -Value "[$timestamp] Fin push fichadas exitCode=$exitCode"

exit $exitCode
