# EARIST private-storage permission correction — historical proposal

Superseded on 2026-10-30: the user independently applied and confirmed a stricter three-principal policy (Administrators, SYSTEM, Jim), explicitly removing CodexSandboxUsers as well as broad/unresolved grants. The user also confirmed Incognito 403 for the synthetic primary DOCX. Preserve the proposal/backups as history; do not run its old four-principal Apply/Restore procedure against the corrected state without a fresh scoped review. Commands used and script-policy details were not reported and are not inferred. The current pending action is the protected Admin Settings Apache scratch write check described in [operations](EARIST_DOCUMENT_OPERATIONS.md).

Date: 2026-10-30, Asia/Taipei. This is a proposed correction and recovery procedure. No elevated command, permission correction, script-policy change or service change has been executed during this review. Live generation remains disabled.

## Confirmed current state

- Exact target: `C:\xampp\htdocs\rms\storage\earist` and its descendants only. Excludes the parent `storage`, the rest of RMS, Apache configuration and database directories.
- Running Windows service: `Apache2.4`, PID **4172**, executable `C:\xampp\apache\bin\httpd.exe`, service account **LocalSystem / NT AUTHORITY\SYSTEM**, SID `S-1-5-18`. Confirmed without elevation using service registry metadata, `Get-Service`, `sc.exe queryex` and `sc.exe qc`. No service/account privileges will change.
- All **10** current storage objects (four directories, six files) are owned by `LAPTOP-5V9LL1A2\CodexSandboxOffline`, with group `LAPTOP-5V9LL1A2\None`. All access rules are inherited; no explicit child rules or reparse points were found.
- Current grants include SYSTEM and Administrators FullControl, CodexSandboxUsers Modify, Users read/execute, Authenticated Users Modify and two unresolved Modify SIDs. The current inspection token belongs to CodexSandboxUsers; it does not contain the unresolved SIDs.
- The two unresolved grants are `S-1-5-21-1322580635-1503846929-3079419853-2713417747` and `S-1-5-21-1631463459-510365215-163797865-2023301889`. Do not assume they are necessary tooling accounts. The proposal removes their grants from this subtree while preserving the confirmed development group.
- This is a privacy blocker, not evidence that Apache currently lacks write permission: SYSTEM currently has FullControl. The earlier normal-operator `Set-Acl` failure is consistent with the directory's different owner and missing unelevated permission-management rights. An unsandboxed process is not necessarily elevated.
- The user explicitly confirmed the current Admin Settings Apache prerequisite check passed. This establishes read-only DOCX/DOM support, not private-file writes or HTTP isolation.

## Preserved access-control information

Created and verified without changing any ACL:

| Protected file | Purpose | SHA-256 |
|---|---|---|
| `backups/earist-storage-acl-review-2026-10-30-a44d6073.json` | All 10 object paths, owner/group/DACL SDDL, inheritance flags, rule metadata and six file hashes | `7fa8d2749b435f444eb57ab3ccc02834efc71018ad79f494d15f8a258ac21c0a` |
| `backups/earist-storage-acl-review-2026-10-30-a44d6073.acl` | Native `icacls /save` DACL backup, 10 relative path/descriptor pairs | `0edebfa2ebfde95905173205081b557f8c3e82345fb6218e6c7ae7490f78093d` |

The native save printed an unhelpful zero-file success summary. Independently inspected the 6,890-byte UTF-16 file: 20 nonempty lines, 10 complete pairs, matching all JSON snapshot paths, including root and guards. Do not infer coverage from the summary alone. SACL/audit information was not collected; the proposal changes DACLs only and preserves owner/group. A preserved ACL backup is not a tested restore. Microsoft's [`icacls` reference](https://learn.microsoft.com/en-us/windows-server/administration/windows-commands/icacls) documents DACL save/restore and the relative restore base.

## Proposed minimum grants

| Principal | Storage root | `scratch`, `generated` and their data descendants | Three `.htaccess` guards |
|---|---|---|---|
| SYSTEM — existing Apache service | Read/execute (list/traverse/read) | Modify (create/read/write; release own scratch) | Read |
| BUILTIN\Administrators | FullControl for permission management/recovery | FullControl | FullControl |
| `LAPTOP-5V9LL1A2\Jim` — confirmed operator | Modify for file maintenance | Modify | Modify |
| `LAPTOP-5V9LL1A2\CodexSandboxUsers` — existing development group | Modify during development | Modify during development | Modify during development |

The SYSTEM row states runtime needs as explicit grants, not effective-token isolation. The other rows provide maintenance/development access. Modify grants do not add permission-management/take-ownership rights; standard Windows Synchronize rights accompany allow rules. SYSTEM and Administrators remain trusted privileged machine principals, including applicable group rights. The correction removes ordinary local-user access; it does not isolate files from SYSTEM or administrators.

Block inheritance at the three fixed directories and three guard files, replacing their access rules with the four listed principals. Existing data descendants inherit from the restricted runtime directories. Root/guard ownership and primary groups stay unchanged. No Everyone, Users, Authenticated Users, unresolved SID, new group membership, deny ACE or service-account change is proposed. All changes stay inside the exact target. The confirmed development group is retained for this local development project; real deployment access should later be reviewed separately.

## Prepared commands and what they do

The complete reviewed source is [scripts/earist-storage-permissions.ps1](../scripts/earist-storage-permissions.ps1). It defaults to read-only Preview. Apply and Restore additionally require a genuine elevated Administrators token; the script does not launch a UAC prompt or elevate itself.

Prepared source SHA-256: `3169c20148b6aa9caab7d02091874bf1e456f581a00a5ad8e2ffc4bc7c2c227b`. Verify this reviewed version before execution. Windows PowerShell parsing and 15 independent in-memory descriptor/mask checks passed; no Apply/Restore or successful full Preview execution is claimed. The unchanged disabled-generation config and both before-backups are also hash-pinned in the script.

The ordinary preview attempt was blocked by the current script execution policy. All policy scopes are Undefined, which gives the Windows client's default Restricted policy. Syntax validation and in-memory permission-descriptor checks passed; full Preview has **not** run. No policy has been changed. The proposed commands therefore explicitly include **RemoteSigned for the one child process only**, subject to the user's approval. They do not persist a machine/user policy change or use Bypass. Group Policy still takes precedence. See Microsoft's [execution-policy documentation](https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.core/about/about_execution_policies?view=powershell-5.1).

After approval, first run this read-only preview in an ordinary PowerShell window:

```powershell
& 'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe' -NoProfile -ExecutionPolicy RemoteSigned -File 'C:\xampp\htdocs\rms\scripts\earist-storage-permissions.ps1' -Mode Preview
```

`-NoProfile` avoids profile side effects. Preview validates the frozen backup hashes and path coverage; all current object ACLs, ownership and file hashes; absence of junction/reparse redirects; exact service/operator/development identities; unchanged deny guards; and the disabled live-generation gate. It makes no permission or content changes. If anything changed since review, stop and obtain a fresh snapshot/proposal. Do not remove preconditions merely to make it run.

Only after Preview passes, run the following in **PowerShell opened with Run as administrator**:

```powershell
& 'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe' -NoProfile -ExecutionPolicy RemoteSigned -File 'C:\xampp\htdocs\rms\scripts\earist-storage-permissions.ps1' -Mode Apply
```

Apply repeats the preconditions and checks elevation. It executes six fixed-target `Set-Acl -LiteralPath` operations: root gets SYSTEM ReadAndExecute; scratch and generated get SYSTEM Modify; the three guards get SYSTEM Read. Each includes the other three approved principals. It does not apply an unrelated ACL with `/reset`, take ownership, change service settings, delete files or enable generation. The script then checks every current object for the exact allowed principals/rights/inheritance and unchanged owner/group/file hashes, and saves an exclusive after-state JSON under protected `backups/`. Failure stops the operation; no automatic restore or cleanup runs. Partial DACL changes must be reviewed while generation remains disabled.

## Prepared recovery — separate authorization required

Keep both before-snapshot files intact and hash-check them before recovery. If correction fails or legitimate maintenance access is lost, an authorized elevated administrator can request the reviewed Restore action:

```powershell
& 'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe' -NoProfile -ExecutionPolicy RemoteSigned -File 'C:\xampp\htdocs\rms\scripts\earist-storage-permissions.ps1' -Mode Restore
```

Restore validates the backups and all 10 current paths/content/owner/group identities, then runs this fixed, narrowly bound native operation internally:

```powershell
& 'C:\Windows\System32\icacls.exe' 'C:\xampp\htdocs\rms\storage' /restore 'C:\xampp\htdocs\rms\backups\earist-storage-acl-review-2026-10-30-a44d6073.acl' /Q
```

The restore **base** is `storage` because every saved path begins with `earist`; the validated backup lists only those 10 `earist` objects, so the parent's ACL is not a restore target. Both backup hashes and all saved paths are checked before this operation. Owners/groups were never changed; they are rechecked against JSON. If inventory or ownership has changed, stop for a new scoped recovery review rather than broadening the restore. Restoring the original DACLs reintroduces the original broad grants, so live generation must remain disabled. Do not use recovery as a privacy fix, run `/reset /T`, or change the whole RMS tree.

Neither Apply nor Restore has been executed. Restore is not implicitly authorized by approval of Apply.

## Required verification after correction

1. Review the actual after-state and confirm all current descendants have the approved ACLs, no broad/unresolved grants, and unchanged file hashes/owner/group. Administrative read access alone does not prove an unrelated ordinary user is denied.
2. Verify authorized Apache create/write/read access using only synthetic content through an authorized, supported, user-operated browser action. The existing read-only prerequisite panel cannot establish this. No public test endpoint, fabricated session, SYSTEM impersonation or CLI substitution is authorized; the actual write check remains Pending until an appropriate supported action is available.
3. Guide the user through logged-in and logged-out HTTP checks one action at a time against the existing synthetic DOCX and private-directory paths. Expect denial and no DOCX bytes. Directory-listing denial alone is insufficient. Do not repeat the already-confirmed Apache read-only check unless the runtime changes.
4. Keep `live_generation_enabled=false` until ACL correction, authorized web-service writes and unauthorized HTTP denial are confirmed. No database records, PDF conversion, software, Apache privileges or unrelated features change during this permission work.

When these gates pass, report the actual results. The next approved implementation checkpoint is **saved document-specific answers and extension of the Faculty Mapping Review page**, with source/draft revision checks. It has not begun.

## Historical approval request — superseded

Approve the four-principal DACL policy, removal of the two unresolved SID grants from this subtree, guarded Preview then Apply, and the process-only RemoteSigned allowance needed to run this local reviewed script. This requests no blanket elevation, service change, automatic recovery or live-generation enablement. The approval gate comes directly from the user's instruction to present the correction before executing any elevated commands.
