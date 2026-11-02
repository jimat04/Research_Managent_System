#Requires -Version 5.1
<#
Reviewable ACL correction for the approved, fixed EARIST storage tree only.
Preview is read-only. Apply/Restore require a genuinely elevated Windows token
and separate user approval. No elevation, service changes, DB, HTTP or deletion.
#>
[CmdletBinding()]
param([ValidateSet('Preview', 'Apply', 'Restore')][string]$Mode = 'Preview')

$ErrorActionPreference = 'Stop'
$earistRoot = 'C:\xampp\htdocs\rms\storage\earist'
$earistBackupBase = 'C:\xampp\htdocs\rms\backups\earist-storage-acl-review-2026-10-30-a44d6073'
$earistSnapshotHash = '7fa8d2749b435f444eb57ab3ccc02834efc71018ad79f494d15f8a258ac21c0a'
$earistDaclHash = '0edebfa2ebfde95905173205081b557f8c3e82345fb6218e6c7ae7490f78093d'
$earistConfigHash = 'a188b0e4be8a846bcd44cae77edfc0a3959edd80dc58bbf77699ddfc0ae3e248'
$earistSections = [System.Security.AccessControl.AccessControlSections]'Access,Owner,Group'
$earistSids = @{
    System = 'S-1-5-18'
    Administrators = 'S-1-5-32-544'
    Operator = 'S-1-5-21-2966421904-103313263-3865652745-1002'
}

function Get-EaristAclState {
    # Do not traverse junctions, including an ancestor of the reviewed target.
    foreach ($anchor in @('C:\xampp', 'C:\xampp\htdocs', 'C:\xampp\htdocs\rms', 'C:\xampp\htdocs\rms\storage')) {
        if (((Get-Item -LiteralPath $anchor -Force).Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Storage ancestor is a reparse point; stop.' }
    }
    $queue = New-Object 'System.Collections.Generic.Queue[string]'
    $queue.Enqueue($earistRoot)
    while ($queue.Count -gt 0) {
        $item = Get-Item -LiteralPath $queue.Dequeue() -Force
        $path = [System.IO.Path]::GetFullPath($item.FullName)
        if (($path -ne $earistRoot -and -not $path.StartsWith($earistRoot + '\', [System.StringComparison]::OrdinalIgnoreCase)) -or
            ($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Unexpected storage path/reparse point; stop.' }
        $acl = Get-Acl -LiteralPath $path
        $relative = if ($path -eq $earistRoot) { '.' } else { $path.Substring($earistRoot.Length + 1) }
        [PSCustomObject]@{
            Path = $path; RelativePath = $relative; Directory = [bool]$item.PSIsContainer
            Owner = $acl.Owner; Group = $acl.Group; Protected = $acl.AreAccessRulesProtected
            Sddl = $acl.GetSecurityDescriptorSddlForm($earistSections)
            SHA256 = if ($item.PSIsContainer) { $null } else { (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() }
        }
        if ($item.PSIsContainer) { foreach ($child in Get-ChildItem -LiteralPath $path -Force) { $queue.Enqueue($child.FullName) } }
    }
}

function Assert-EaristInventory([object[]]$State, [bool]$CompareAcl) {
    if ($State.Count -ne $earistSnapshot.Objects.Count) { throw 'Storage inventory changed; obtain a new read-only review before applying/restoring.' }
    foreach ($item in $State) {
        $old = @($earistSnapshot.Objects | Where-Object { $_.RelativePath -ceq $item.RelativePath })
        if ($old.Count -ne 1 -or $old[0].Directory -ne $item.Directory -or $old[0].SHA256 -cne $item.SHA256 -or
            $old[0].Owner -cne $item.Owner -or $old[0].Group -cne $item.Group -or
            ($CompareAcl -and $old[0].Sddl -cne $item.Sddl)) { throw 'Storage contents/identity/access changed since the frozen review; stop.' }
    }
}

function New-EaristPolicy([string]$Path, [bool]$Directory, [string]$SystemRights) {
    $acl = Get-Acl -LiteralPath $Path
    # Change only DACL rules/protection. Preserve owner, primary group and SACL.
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($oldRule in @($acl.Access)) { [void]$acl.RemoveAccessRuleSpecific($oldRule) }
    $inherit = if ($Directory) { [System.Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit' } else { [System.Security.AccessControl.InheritanceFlags]::None }
    $rights = @{ System = $SystemRights; Administrators = 'FullControl'; Operator = 'Modify'}
    foreach ($key in @('Administrators', 'System', 'Operator')) {
        $sid = [System.Security.Principal.SecurityIdentifier]::new($earistSids[$key])
        $rule = [System.Security.AccessControl.FileSystemAccessRule]::new($sid, [System.Security.AccessControl.FileSystemRights]$rights[$key], $inherit,
            [System.Security.AccessControl.PropagationFlags]::None, [System.Security.AccessControl.AccessControlType]::Allow)
        [void]$acl.AddAccessRule($rule)
    }
    return $acl
}

function Assert-EaristPolicy([object[]]$State) {
    $guards = @('.htaccess', 'scratch\.htaccess', 'generated\.htaccess')
    foreach ($item in $State) {
        $expectedSystem = if ($item.RelativePath -eq '.') { 'ReadAndExecute' } elseif ($item.RelativePath -in $guards) { 'Read' } else { 'Modify' }
        $acl = Get-Acl -LiteralPath $item.Path
        $masks = @{}
        foreach ($rule in $acl.Access) {
            $sid = $rule.IdentityReference.Translate([System.Security.Principal.SecurityIdentifier]).Value
            if ($sid -notin $earistSids.Values -or $rule.AccessControlType -ne 'Allow' -or $rule.PropagationFlags -ne 'None') { throw 'Unexpected principal or access rule after correction.' }
            if (-not $masks.ContainsKey($sid)) { $masks[$sid] = [int64]0 }
            $masks[$sid] = $masks[$sid] -bor [int64]$rule.FileSystemRights
        }
        foreach ($key in @('System', 'Administrators', 'Operator')) {
            $rights = if ($key -eq 'System') { $expectedSystem } elseif ($key -eq 'Administrators') { 'FullControl' } else { 'Modify' }
            $expectedMask = [int64][System.Security.AccessControl.FileSystemRights]$rights -bor [int64][System.Security.AccessControl.FileSystemRights]::Synchronize
            if (-not $masks.ContainsKey($earistSids[$key]) -or $masks[$earistSids[$key]] -ne $expectedMask) { throw 'Required principal permissions do not match the approved policy.' }
        }
        $protected = $item.RelativePath -in @('.', 'scratch', 'generated', '.htaccess', 'scratch\.htaccess', 'generated\.htaccess')
        if ($acl.AreAccessRulesProtected -ne $protected) { throw 'Unexpected inheritance protection after correction.' }
    }
}

# Freeze and authenticate both local metadata backups before any privileged action.
if ((Get-FileHash -LiteralPath ($earistBackupBase + '.json') -Algorithm SHA256).Hash.ToLowerInvariant() -cne $earistSnapshotHash -or
    (Get-FileHash -LiteralPath ($earistBackupBase + '.acl') -Algorithm SHA256).Hash.ToLowerInvariant() -cne $earistDaclHash) { throw 'Frozen ACL evidence checksum differs; stop.' }
$earistSnapshot = Get-Content -LiteralPath ($earistBackupBase + '.json') -Raw -Encoding UTF8 | ConvertFrom-Json
if ($earistSnapshot.Target -cne $earistRoot -or $earistSnapshot.Version -ne 1 -or $earistSnapshot.Objects.Count -ne 10) { throw 'Unexpected ACL snapshot target/version/inventory.' }
$earistSavedLines = @([System.IO.File]::ReadAllLines($earistBackupBase + '.acl', [System.Text.Encoding]::Unicode) | Where-Object { $_ -ne '' })
if ($earistSavedLines.Count -ne 2 * $earistSnapshot.Objects.Count) { throw 'Saved DACL coverage differs; stop.' }
$earistSavedPaths = @()
for ($index = 0; $index -lt $earistSavedLines.Count; $index += 2) {
    $savedPath = $earistSavedLines[$index]
    if (($savedPath -cne 'earist' -and -not $savedPath.StartsWith('earist\', [System.StringComparison]::Ordinal)) -or
        $savedPath.Contains(':') -or $savedPath -match '(^|\\)\.\.?($|\\)' -or
        -not $earistSavedLines[$index + 1].StartsWith('D:', [System.StringComparison]::Ordinal)) { throw 'Saved DACL has an out-of-scope path or invalid descriptor.' }
    $earistSavedPaths += $savedPath
}
$earistExpectedPaths = @($earistSnapshot.Objects | ForEach-Object { if ($_.RelativePath -eq '.') { 'earist' } else { 'earist\' + $_.RelativePath } })
if (@(Compare-Object ($earistSavedPaths | Sort-Object) ($earistExpectedPaths | Sort-Object)).Count -ne 0) { throw 'Saved DACL paths do not match the frozen snapshot.' }
$earistConfigPath = 'C:\xampp\htdocs\rms\config\earist-document-storage.php'
if ((Get-FileHash -LiteralPath $earistConfigPath -Algorithm SHA256).Hash.ToLowerInvariant() -cne $earistConfigHash) { throw 'Reviewed disabled-generation configuration changed; stop.' }
$earistConfigText = Get-Content -LiteralPath $earistConfigPath -Raw
if ($earistConfigText -notmatch "'live_generation_enabled'\s*=>\s*false\s*,") { throw 'Keep live generation disabled before permission operations.' }
foreach ($guardRelative in @('.htaccess', 'scratch\.htaccess', 'generated\.htaccess')) {
    $guard = Join-Path $earistRoot $guardRelative
    if ((Get-Content -LiteralPath $guard -Raw).Trim() -cne 'Require all denied') { throw 'Storage HTTP guard differs; stop.' }
}
$earistService = Get-ItemProperty -LiteralPath 'HKLM:\SYSTEM\CurrentControlSet\Services\Apache2.4'
if ($earistService.ObjectName -cne 'LocalSystem' -or $earistService.ImagePath -cne '"C:\xampp\apache\bin\httpd.exe" -k runservice' -or
    ($Mode -ne 'Restore' -and (Get-Service -Name Apache2.4).Status -ne 'Running')) { throw 'Apache service configuration/state changed; obtain a fresh account review.' }
if (([System.Security.Principal.NTAccount]'LAPTOP-5V9LL1A2\Jim').Translate([System.Security.Principal.SecurityIdentifier]).Value -cne $earistSids.Operator) {
    throw 'Reviewed operator identity changed.'
}
$earistState = @(Get-EaristAclState)
Assert-EaristInventory $earistState ($Mode -ne 'Restore')
if ($Mode -eq 'Preview') {
    Write-Output 'READ-ONLY PREVIEW: 10 frozen objects; backup checksums, current ACLs, file hashes, service, identities, guards and disabled gate match.'
    Write-Output 'Proposed: six DACL updates (three directories and three guards); inherited descendants follow those directories.'
    Write-Output 'Allow only Administrators, SYSTEM and operator Jim. Remove broad groups and both unresolved SIDs from this tree.'
    Write-Output 'SYSTEM: root RX; scratch/generated Modify; .htaccess guards Read. No owner/group/service/content changes.'
    exit 0
}
$earistPrincipal = [System.Security.Principal.WindowsPrincipal]::new([System.Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $earistPrincipal.IsInRole([System.Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Apply/Restore needs an elevated Windows PowerShell token; no ACL changed.' }
try {
    if ($Mode -eq 'Apply') {
        Set-Acl -LiteralPath $earistRoot -AclObject (New-EaristPolicy $earistRoot $true 'ReadAndExecute')
        foreach ($branch in @('scratch', 'generated')) {
            $path = $earistRoot + '\' + $branch
            Set-Acl -LiteralPath $path -AclObject (New-EaristPolicy $path $true 'Modify')
        }
        foreach ($guardRelative in @('.htaccess', 'scratch\.htaccess', 'generated\.htaccess')) {
            $path = Join-Path $earistRoot $guardRelative
            Set-Acl -LiteralPath $path -AclObject (New-EaristPolicy $path $false 'Read')
        }
        $earistAfter = @(Get-EaristAclState)
        Assert-EaristInventory $earistAfter $false
        Assert-EaristPolicy $earistAfter
    } else {
        # Saved paths start with earist; this parent is the correct restore base.
        # Hash/path guards above prevent restoration to another workspace path.
        & 'C:\Windows\System32\icacls.exe' 'C:\xampp\htdocs\rms\storage' /restore ($earistBackupBase + '.acl') /Q
        if ($LASTEXITCODE -ne 0) { throw 'DACL restore failed; preserve partial state for review.' }
        $earistAfter = @(Get-EaristAclState)
        Assert-EaristInventory $earistAfter $true
    }
    $earistEvidencePath = 'C:\xampp\htdocs\rms\backups\earist-storage-acl-' + $Mode.ToLowerInvariant() + '-2026-10-30-' + [guid]::NewGuid().ToString('N').Substring(0, 8) + '.json'
    $earistEvidenceJson = [PSCustomObject]@{ Mode = $Mode; Target = $earistRoot; Date = '2026-10-30 Asia/Taipei'; Objects = $earistAfter; LiveGenerationEnabled = $false } | ConvertTo-Json -Depth 6
    $earistEvidenceBytes = (New-Object System.Text.UTF8Encoding($false)).GetBytes($earistEvidenceJson)
    $earistEvidenceStream = [System.IO.File]::Open($earistEvidencePath, [System.IO.FileMode]::CreateNew, [System.IO.FileAccess]::Write)
    try { $earistEvidenceStream.Write($earistEvidenceBytes, 0, $earistEvidenceBytes.Length); $earistEvidenceStream.Flush($true) }
    finally { $earistEvidenceStream.Dispose() }
    Write-Output ($Mode + ' verified. Live generation is still disabled. Evidence: ' + $earistEvidencePath)
} catch {
    Write-Warning 'Permission operation/verification did not complete. Keep generation disabled; preserve the current state. No automatic restore or cleanup ran.'
    throw
}
