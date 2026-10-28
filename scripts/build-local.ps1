param(
    [string]$SourceRoot = (Split-Path -Parent $PSScriptRoot)
)
$ErrorActionPreference = 'Stop'
$exitCode = 1
$originalLocation = Get-Location
$environmentNames = @('TEMP', 'TMP', 'TMPDIR', 'npm_config_cache', 'ASTRO_TELEMETRY_DISABLED')
$originalEnvironment = @{}
foreach ($name in $environmentNames) {
    $originalEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}
try {
    $source = (Resolve-Path -LiteralPath $SourceRoot).Path
    if (-not (Test-Path -LiteralPath (Join-Path $source 'package.json') -PathType Leaf)) { throw 'Source must contain package.json.' }
    $temporaryRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..\tep'))
    if ((Test-Path -LiteralPath $temporaryRoot) -and ((Get-Item -LiteralPath $temporaryRoot -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'tep must not be a link or junction.' }
    $npm = (Get-Command npm.cmd -ErrorAction Stop).Source
    $taskRoot = Join-Path $temporaryRoot ('build-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [guid]::NewGuid().ToString('N').Substring(0,8))
    $processTemp = Join-Path $taskRoot 'tmp'
    $npmCache = Join-Path $taskRoot 'npm-cache'
    New-Item -ItemType Directory -Path $processTemp,$npmCache -Force | Out-Null
    foreach ($name in @('TEMP','TMP','TMPDIR')) { [Environment]::SetEnvironmentVariable($name,$processTemp,'Process') }
    $env:npm_config_cache = $npmCache
    $env:ASTRO_TELEMETRY_DISABLED = '1'
    Set-Location -LiteralPath $source
    $buildLog = Join-Path $taskRoot 'build.log'
    Write-Host ('Building the original project: ' + $source)
    Write-Host ('Standard output: ' + (Join-Path $source 'dist'))
    Write-Host ('Build log: ' + $buildLog)
    $ErrorActionPreference = 'Continue'
    & $npm run build 2>&1 | Tee-Object -FilePath $buildLog
    $exitCode = $LASTEXITCODE
    $ErrorActionPreference = 'Stop'
    if ($null -eq $exitCode) { $exitCode = 1 }
    [ordered]@{source=$source; workspace=$source; mode='in-place'; output=(Join-Path $source 'dist'); log=$buildLog; exitCode=$exitCode} |
        ConvertTo-Json | Set-Content -LiteralPath (Join-Path $taskRoot 'result.json') -Encoding UTF8
    if ($exitCode -eq 0) { Write-Host ('Build succeeded. Output: ' + (Join-Path $source 'dist')) }
    else { Write-Host ('Build failed. See: ' + $buildLog) }
    Write-Host 'Standard generated files stay in the project. No Git restore, reset, commit, push or deployment was run.'
}
catch {
    $ErrorActionPreference = 'Continue'
    Write-Error $_
    $exitCode = 1
}
finally {
    Set-Location -LiteralPath $originalLocation.Path
    foreach ($name in $environmentNames) { [Environment]::SetEnvironmentVariable($name,$originalEnvironment[$name],'Process') }
}
exit $exitCode
