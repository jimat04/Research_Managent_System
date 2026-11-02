# Render generated report pages through Windows' PDF API, without a browser or RMS.
param([Parameter(Mandatory=$true)][string]$ReportDirectory)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Runtime.WindowsRuntime
$null = [Windows.Storage.StorageFile, Windows.Storage, ContentType=WindowsRuntime]
$null = [Windows.Data.Pdf.PdfDocument, Windows.Data.Pdf, ContentType=WindowsRuntime]
$null = [Windows.Storage.Streams.InMemoryRandomAccessStream, Windows.Storage.Streams, ContentType=WindowsRuntime]
$operationMethod = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
    $_.Name -eq 'AsTask' -and $_.IsGenericMethod -and $_.GetParameters().Count -eq 1 -and $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncOperation`1'
} | Select-Object -First 1
$actionMethod = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
    $_.Name -eq 'AsTask' -and -not $_.IsGenericMethod -and $_.GetParameters().Count -eq 1 -and $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncAction'
} | Select-Object -First 1
function Get-OperationResult($Operation, $ResultType) {
    $task = $operationMethod.MakeGenericMethod($ResultType).Invoke($null, @($Operation))
    $task.Wait()
    return $task.Result
}
$root = [System.IO.Path]::GetFullPath($ReportDirectory)
$inputFile = Get-OperationResult ([Windows.Storage.StorageFile]::GetFileFromPathAsync((Join-Path $root 'RMS-v3-As-Built-Report.pdf'))) ([Windows.Storage.StorageFile])
$document = Get-OperationResult ([Windows.Data.Pdf.PdfDocument]::LoadFromFileAsync($inputFile)) ([Windows.Data.Pdf.PdfDocument])
$previewRoot = Join-Path $root 'document-previews'
[System.IO.Directory]::CreateDirectory($previewRoot) | Out-Null
for ($index = 0; $index -lt $document.PageCount; $index++) {
    $page = $document.GetPage([uint32]$index)
    $stream = New-Object Windows.Storage.Streams.InMemoryRandomAccessStream
    $action = $page.RenderToStreamAsync($stream)
    $renderTask = $actionMethod.Invoke($null, @($action))
    $renderTask.Wait()
    $stream.Seek(0)
    $reader = [System.IO.WindowsRuntimeStreamExtensions]::AsStreamForRead($stream)
    $destination = [System.IO.File]::Create((Join-Path $previewRoot ('page-{0:D2}.png' -f ($index + 1))))
    $reader.CopyTo($destination)
    $destination.Dispose(); $reader.Dispose(); $stream.Dispose(); $page.Dispose()
}
Write-Output ('Rendered {0} generated PDF pages for document review.' -f $document.PageCount)
exit 0
