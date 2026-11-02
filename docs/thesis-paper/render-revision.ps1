param([switch]$Worker)
$ErrorActionPreference = 'Stop'
$taskFolder = [System.IO.Path]::GetFullPath($PSScriptRoot)
$taskInput = Join-Path $taskFolder 'rms-chapter_1&2-revised.docx'
$taskPdf = Join-Path $taskFolder 'rms-chapter_1&2-revised.pdf'
$taskReport = Join-Path $taskFolder 'word-layout-review.json'
$taskSession = Join-Path $taskFolder 'word-render-session.json'
if (-not $Worker) {
    $taskExistingWords = @(Get-Process WINWORD -ErrorAction SilentlyContinue | ForEach-Object { $_.Id })
    $taskOut = Join-Path $taskFolder 'word-render-output.log'
    $taskErr = Join-Path $taskFolder 'word-render-error.log'
    $taskWorkerProcess = Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile', '-File', ('"' + $PSCommandPath + '"'), '-Worker') -WindowStyle Hidden -PassThru -RedirectStandardOutput $taskOut -RedirectStandardError $taskErr
    if (-not $taskWorkerProcess.WaitForExit(45000)) {
        if (Test-Path -LiteralPath $taskSession) {
            $taskRecorded = Get-Content -LiteralPath $taskSession -Raw | ConvertFrom-Json
            if ($taskRecorded.worker_pid -eq $taskWorkerProcess.Id -and $taskRecorded.word_pid -notin $taskExistingWords) {
                $taskOwnedWord = Get-Process -Id $taskRecorded.word_pid -ErrorAction SilentlyContinue
                if ($null -ne $taskOwnedWord -and $taskOwnedWord.StartTime.ToString('o') -eq $taskRecorded.word_started) {
                    Stop-Process -Id $taskOwnedWord.Id -Force
                }
            }
        }
        if (-not $taskWorkerProcess.HasExited) { Stop-Process -Id $taskWorkerProcess.Id -Force }
        [ordered]@{status='incomplete'; result='Word automation exceeded 45 seconds; no layout result claimed'; visual_page_review='not completed'; macros_and_links_disabled=$true; original_modified=$false} | ConvertTo-Json | Set-Content -LiteralPath $taskReport -Encoding UTF8
    }
    Get-Content -LiteralPath $taskOut -ErrorAction SilentlyContinue
    Get-Content -LiteralPath $taskErr -ErrorAction SilentlyContinue
    Get-Content -LiteralPath $taskReport -ErrorAction SilentlyContinue
    exit
}
$taskWord = $null
$taskDocument = $null
try {
    Write-Output 'Creating hidden Word instance.'
    $taskWord = New-Object -ComObject Word.Application
    $taskWord.Visible = $false
    $taskWord.DisplayAlerts = 0
    $taskWord.AutomationSecurity = 3
    $taskWord.Options.UpdateLinksAtOpen = $false
    Add-Type -TypeDefinition 'using System; using System.Runtime.InteropServices; public class RMSRevisionWordWindow { [DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(IntPtr window, out uint process); }'
    [uint32]$taskWordPid = 0
    [void][RMSRevisionWordWindow]::GetWindowThreadProcessId([IntPtr]$taskWord.Hwnd, [ref]$taskWordPid)
    $taskWordProcess = Get-Process -Id $taskWordPid
    [ordered]@{worker_pid=$PID; word_pid=$taskWordPid; word_started=$taskWordProcess.StartTime.ToString('o')} | ConvertTo-Json | Set-Content -LiteralPath $taskSession -Encoding UTF8
    Write-Output 'Opening revised copy read-only.'
    $taskDocument = $taskWord.Documents.Open($taskInput, $false, $true, $false)
    Write-Output 'Repaginating revised copy.'
    $taskDocument.Repaginate()
    $taskPages = $taskDocument.ComputeStatistics(2)
    $taskTableRows = @()
    foreach ($taskTable in $taskDocument.Tables) {
        $taskTableRows += [ordered]@{
            rows = $taskTable.Rows.Count
            start_page = $taskTable.Range.Information(3)
            width_points = $taskTable.PreferredWidth
        }
    }
    Write-Output 'Exporting PDF.'
    $taskDocument.ExportAsFixedFormat($taskPdf, 17)
    $taskSummary = [ordered]@{
        opened_in_microsoft_word = $true
        source_opened_read_only = $true
        macros_disabled = $true
        links_update_disabled = $true
        pages = $taskPages
        tables = $taskTableRows
        pdf = [System.IO.Path]::GetFileName($taskPdf)
        visual_page_review = 'PDF rendered; image inspection pending'
    }
    $taskSummary | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $taskReport -Encoding UTF8
    Write-Output ($taskSummary | ConvertTo-Json -Depth 4)
} catch {
    [ordered]@{status='incomplete'; result=$_.Exception.Message; visual_page_review='not completed'; original_modified=$false} | ConvertTo-Json | Set-Content -LiteralPath $taskReport -Encoding UTF8
    Write-Error $_
} finally {
    if ($null -ne $taskDocument) {
        $taskDocument.Close(0)
        [void][System.Runtime.InteropServices.Marshal]::FinalReleaseComObject($taskDocument)
    }
    if ($null -ne $taskWord) {
        $taskWord.Quit()
        [void][System.Runtime.InteropServices.Marshal]::FinalReleaseComObject($taskWord)
    }
}
