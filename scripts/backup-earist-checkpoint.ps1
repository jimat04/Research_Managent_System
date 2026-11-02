param([switch]$QuietWindowConfirmed)
$ErrorActionPreference = 'Stop'
if (-not $QuietWindowConfirmed) { throw 'Confirm a quiet window before running this backup.' }
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$storageRoot = Join-Path $projectRoot 'storage\earist'
$jimSid = ([Security.Principal.NTAccount]::new($env:COMPUTERNAME, 'Jim')).Translate([Security.Principal.SecurityIdentifier]).Value
$approvedSids = @('S-1-5-18', 'S-1-5-32-544', $jimSid)

function Get-PrivateDescriptor([string]$LiteralPath) {
    $item = Get-Item -LiteralPath $LiteralPath -Force
    if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Reparse point is not a backup source/destination.' }
    $acl = Get-Acl -LiteralPath $LiteralPath
    $grants = @()
    foreach ($rule in $acl.Access) {
        $sid = $rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
        if ($rule.AccessControlType -eq 'Allow') {
            if ($sid -notin $approvedSids) { throw 'Unapproved principal has access; no permissions were modified.' }
            $grants += $sid
        }
    }
    if ($jimSid -notin $grants -or 'S-1-5-32-544' -notin $grants) { throw 'Required operator/administrator access is absent.' }
    return $acl.Sddl
}

# Read descriptors only. No Set-Acl, grants, ownership change, service or policy edit.
$sourceItems = @((Get-Item -LiteralPath $storageRoot))
foreach ($name in @('.htaccess','scratch','generated')) {
    $path = Join-Path $storageRoot $name
    $sourceItems += Get-Item -LiteralPath $path -Force
    if (Test-Path -LiteralPath $path -PathType Container) {
        $sourceItems += Get-ChildItem -LiteralPath $path -Recurse -Force
    }
}
$descriptors = @{}
foreach ($item in $sourceItems) { $descriptors[$item.FullName] = Get-PrivateDescriptor $item.FullName }
$recoveryRoot = Join-Path $storageRoot 'recovery'
if (-not (Test-Path -LiteralPath $recoveryRoot)) { New-Item -ItemType Directory -Path $recoveryRoot | Out-Null }
$null = Get-PrivateDescriptor $recoveryRoot
$backupId = 'b2-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [Guid]::NewGuid().ToString('N')
$backupRoot = Join-Path $recoveryRoot $backupId
if (Test-Path -LiteralPath $backupRoot) { throw 'Backup directory collision; do not reuse.' }
New-Item -ItemType Directory -Path $backupRoot | Out-Null
$null = Get-PrivateDescriptor $backupRoot
$descriptors | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $backupRoot 'source-acl-before.json') -Encoding UTF8
Write-Output ('Backup ID: ' + $backupId)
& 'C:\xampp\php\php.exe' (Join-Path $PSScriptRoot 'backup-earist-checkpoint.php') $backupRoot '--quiet-window-confirmed'
if ($LASTEXITCODE -ne 0) { throw 'Backup did not verify. STOP; leave generation disabled and preserve this directory.' }
foreach ($entry in $descriptors.GetEnumerator()) {
    if ((Get-PrivateDescriptor $entry.Key) -cne $entry.Value) { throw 'Source ACL changed during backup. STOP; leave generation disabled.' }
}
$backupItems = @((Get-Item -LiteralPath $backupRoot)) + @(Get-ChildItem -LiteralPath $backupRoot -Recurse -Force)
foreach ($item in $backupItems) { $null = Get-PrivateDescriptor $item.FullName }
if (@(Get-ChildItem -LiteralPath $backupRoot -Filter 'client-*.cnf').Count -ne 0) { throw 'Credential file retained; STOP for operator review.' }
@{ source_objects_unchanged = $descriptors.Count; private_backup_objects = $backupItems.Count; status = 'passed'; permissions_modified = $false } |
    ConvertTo-Json | Set-Content -LiteralPath (Join-Path $backupRoot 'acl-verification.json') -Encoding UTF8
Write-Output ('ACL verification passed; unchanged source objects: ' + $descriptors.Count)
Write-Output 'No activation performed. Independent backup verification is still required.'
