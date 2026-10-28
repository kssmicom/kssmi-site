param(
    [string]$SourceRoot = (Split-Path -Parent $PSScriptRoot),
    [switch]$InspectOnly
)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName Microsoft.VisualBasic
$source = (Resolve-Path -LiteralPath $SourceRoot).Path
$workspace = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$tep = [IO.Path]::GetFullPath((Join-Path $workspace 'tep'))
if (-not $source.StartsWith($workspace + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Source must stay in this workspace.' }
$gitRoot = (& git -C $source rev-parse --show-toplevel 2>$null).Trim().Replace('/', '\')
if ($LASTEXITCODE -ne 0 -or -not $gitRoot.Equals($source, [StringComparison]::OrdinalIgnoreCase)) { throw 'Source must be a Git repository root.' }
function Assert-Safe([string]$target, [string]$root) {
    $absolute = [IO.Path]::GetFullPath($target)
    if (-not $absolute.StartsWith($root + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Cleanup target escaped its allowed directory.' }
    for ($cursor = $absolute; ; $cursor = [IO.Path]::GetDirectoryName($cursor)) {
        if ((Test-Path -LiteralPath $cursor) -and ((Get-Item -LiteralPath $cursor -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw ('Links are not cleanup candidates: ' + $cursor) }
        if ($cursor.Equals($root, [StringComparison]::OrdinalIgnoreCase)) { break }
    }
    return $absolute
}
function Fingerprint([string]$target) {
    $item = Get-Item -LiteralPath $target -Force
    $all = @($item)
    if ($item.PSIsContainer) { $all += @(Get-ChildItem -LiteralPath $target -Recurse -Force) }
    if (@($all | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint }).Count) { throw 'A candidate contains a link or junction.' }
    $metadata = ($all | Sort-Object FullName | ForEach-Object { $_.FullName + '|' + $_.Length + '|' + $_.LastWriteTimeUtc.Ticks }) -join [Environment]::NewLine
    $hasher = [Security.Cryptography.SHA256]::Create()
    try { return [BitConverter]::ToString($hasher.ComputeHash([Text.Encoding]::UTF8.GetBytes($metadata))) }
    finally { $hasher.Dispose() }
}
$logs = @('_build.log', 'action.log', 'astro-check.log', 'build.log', 'build2.log', 'build3.log', 'build4.log', 'build_error.log', 'build_trace.log', 'build_output.log', 'check.log', 'check2.log', 'check3.log', 'check4.log', 'check5.log', 'check_final.log', 'check_final_2.log', 'check_final_3.log', 'check_final_4.log', 'dev_error4.log', 'error_log.txt', 'server.log', 'tsc.log', 'validation.log', 'verbose_build_log.txt', 'build-output.txt', 'build2.txt', 'build_full.txt', 'build_log.txt', 'build_output.txt', 'check_out.txt', 'check_output.txt', 'check_result.txt', 'dev_log2.txt')
$candidates = New-Object Collections.Generic.List[object]
$skipped = New-Object Collections.Generic.List[object]
function Add-Candidate([string]$target, [string]$root, [string]$reason) {
    if (-not (Test-Path -LiteralPath $target)) { return }
    try {
        $target = Assert-Safe $target $root
        $relative = $target.Substring($root.Length + 1).Replace('\', '/')
        if ($root -eq $source) {
            $tracked = @(& git -C $source -c core.quotepath=false ls-files -- $relative)
            if ($LASTEXITCODE -ne 0) { throw 'Could not verify Git tracking.' }
            if ($tracked.Count) { throw 'Tracked by Git; not automatically deleted.' }
        }
        $item = Get-Item -LiteralPath $target -Force
        if (-not $item.PSIsContainer) {
            $handle = [IO.File]::Open($target, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::None)
            $handle.Dispose()
        }
        $hash = Fingerprint $target
        $files = if ($item.PSIsContainer) { @(Get-ChildItem -LiteralPath $target -Recurse -Force -File) } else { @($item) }
        $candidates.Add([pscustomobject]@{ Path=$target; Root=$root; Reason=$reason; Files=$files.Count; Bytes=($files | Measure-Object Length -Sum).Sum; Fingerprint=$hash; Directory=$item.PSIsContainer })
    } catch { $skipped.Add([pscustomobject]@{Path=$target; Reason=$_.Exception.Message}) }
}
foreach ($name in $logs) { Add-Candidate (Join-Path $source $name) $source 'Legacy untracked generated log' }
if (Test-Path -LiteralPath $tep) {
    $null = Assert-Safe (Join-Path $tep 'placeholder') $tep
    foreach ($folder in @(Get-ChildItem -LiteralPath $tep -Directory -Force)) {
        try {
            if ($folder.Name -like 'build-*' -and (Test-Path -LiteralPath (Join-Path $folder.FullName 'result.json'))) {
                $result = Get-Content -LiteralPath (Join-Path $folder.FullName 'result.json') -Raw | ConvertFrom-Json
                if ($result.source -eq $source -and $result.PSObject.Properties.Name -contains 'exitCode') {
                    Add-Candidate $folder.FullName $tep 'Completed build logs and temporary files for this project'
                }
            } elseif ($folder.Name -like 'preview-*' -and (Test-Path -LiteralPath (Join-Path $folder.FullName '.kssmi-local-task.json'))) {
                $owner = Get-Content -LiteralPath (Join-Path $folder.FullName '.kssmi-local-task.json') -Raw | ConvertFrom-Json
                if ($owner.source -eq $source -and $owner.kind -eq 'preview' -and $owner.state -in @('stopped', 'failed')) {
                    Add-Candidate $folder.FullName $tep 'Stopped preview logs and temporary files for this project'
                }
            }
        } catch { $skipped.Add([pscustomobject]@{Path=$folder.FullName; Reason='Task ownership/state could not be verified.'}) }
    }
}
if ($InspectOnly) {
    [pscustomobject]@{Candidates=@($candidates.ToArray()); Skipped=@($skipped.ToArray())} | ConvertTo-Json -Depth 5
    exit 0
}
Write-Host 'Only reviewed generated files are candidates. Source, credentials, JSON data and other tasks are preserved.'
for ($i=0; $i -lt $candidates.Count; $i++) {
    Write-Host (('{0}. {1} ({2} files, {3:N2} MB) - {4}' -f ($i+1), $candidates[$i].Path, $candidates[$i].Files, ($candidates[$i].Bytes/1MB), $candidates[$i].Reason))
}
foreach ($entry in $skipped) { Write-Host ('KEPT: ' + $entry.Path + ' - ' + $entry.Reason) }
if ($candidates.Count -eq 0) { Write-Host 'No safe cleanup candidates.'; exit 0 }
$choice = (Read-Host 'Select numbers separated by commas, or ALL; empty cancels').Trim()
if (-not $choice) { exit 0 }
$indexes = if ($choice -eq 'ALL') { @(0..($candidates.Count-1)) } else {
    @($choice.Split(',') | ForEach-Object {
        if ($_ -notmatch '^\s*\d+\s*$') { throw 'Invalid selection.' }
        [int]$_.Trim()-1
    } | Sort-Object -Unique)
}
if (@($indexes | Where-Object { $_ -lt 0 -or $_ -ge $candidates.Count }).Count) { throw 'Selection is out of range.' }
$selected = @($indexes | ForEach-Object { $candidates[$_] })
if ((Read-Host 'Type RECYCLE to move ONLY the selected candidates to the Windows Recycle Bin') -ne 'RECYCLE') { exit 0 }
foreach ($entry in $selected) {
    $null = Assert-Safe $entry.Path $entry.Root
    if ((Fingerprint $entry.Path) -ne $entry.Fingerprint) { throw 'A candidate changed after review. Nothing was cleaned.' }
}
$audit = Join-Path $tep ('cleanup-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [guid]::NewGuid().ToString('N').Substring(0,8))
New-Item -ItemType Directory -Path $audit -Force | Out-Null
$selected | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $audit 'reviewed.json') -Encoding UTF8
foreach ($entry in $selected) {
    $null = Assert-Safe $entry.Path $entry.Root
    if ((Fingerprint $entry.Path) -ne $entry.Fingerprint) { throw 'A remaining candidate changed; cleanup stopped.' }
    if ($entry.Directory) {
        [Microsoft.VisualBasic.FileIO.FileSystem]::DeleteDirectory($entry.Path,[Microsoft.VisualBasic.FileIO.UIOption]::OnlyErrorDialogs,[Microsoft.VisualBasic.FileIO.RecycleOption]::SendToRecycleBin,[Microsoft.VisualBasic.FileIO.UICancelOption]::ThrowException)
    } else {
        [Microsoft.VisualBasic.FileIO.FileSystem]::DeleteFile($entry.Path,[Microsoft.VisualBasic.FileIO.UIOption]::OnlyErrorDialogs,[Microsoft.VisualBasic.FileIO.RecycleOption]::SendToRecycleBin,[Microsoft.VisualBasic.FileIO.UICancelOption]::ThrowException)
    }
    Add-Content -LiteralPath (Join-Path $audit 'recycled.txt') -Value $entry.Path -Encoding UTF8
}
Write-Host ('Moved ' + $selected.Count + ' selected candidates to the Recycle Bin. Audit: ' + $audit)
