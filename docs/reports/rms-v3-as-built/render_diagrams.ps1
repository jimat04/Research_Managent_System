param([Parameter(Mandatory=$true)][string]$ReportDirectory)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
$reportRoot = [System.IO.Path]::GetFullPath($ReportDirectory)
$diagramRoot = Join-Path $reportRoot 'diagrams'
$specs = Get-Content -LiteralPath (Join-Path $diagramRoot 'diagrams.json') -Raw -Encoding UTF8 | ConvertFrom-Json
foreach ($spec in $specs) {
    $bitmap = New-Object System.Drawing.Bitmap ([int]$spec.width * 2), ([int]$spec.height * 2)
    $canvas = [System.Drawing.Graphics]::FromImage($bitmap)
    $canvas.ScaleTransform(2, 2)
    $canvas.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $canvas.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
    $canvas.Clear([System.Drawing.Color]::White)
    $pen = New-Object System.Drawing.Pen ([System.Drawing.ColorTranslator]::FromHtml('#66758a')), 1.5
    $pen.CustomEndCap = New-Object System.Drawing.Drawing2D.AdjustableArrowCap 4, 5
    $font = New-Object System.Drawing.Font 'Arial', 13, ([System.Drawing.FontStyle]::Regular), ([System.Drawing.GraphicsUnit]::Pixel)
    $small = New-Object System.Drawing.Font 'Arial', 11, ([System.Drawing.FontStyle]::Regular), ([System.Drawing.GraphicsUnit]::Pixel)
    $brush = New-Object System.Drawing.SolidBrush ([System.Drawing.ColorTranslator]::FromHtml('#172033'))
    $wash = New-Object System.Drawing.SolidBrush ([System.Drawing.ColorTranslator]::FromHtml('#f2eefb'))
    $border = New-Object System.Drawing.Pen ([System.Drawing.ColorTranslator]::FromHtml('#7351ad')), 1.1
    $format = New-Object System.Drawing.StringFormat
    $format.Alignment = [System.Drawing.StringAlignment]::Center
    $format.LineAlignment = [System.Drawing.StringAlignment]::Center
    foreach ($lane in $spec.lanes) {
        $rect = New-Object System.Drawing.RectangleF ([single]$lane.x), 12, ([single]$lane.width), 35
        $canvas.DrawString([string]$lane.text, $font, $brush, $rect, $format)
    }
    foreach ($edge in $spec.edges) {
        $points = @($edge.points | ForEach-Object { New-Object System.Drawing.PointF ([single]$_[0]), ([single]$_[1]) })
        $canvas.DrawLines($pen, [System.Drawing.PointF[]]$points)
        if ($edge.label) {
            $canvas.DrawString([string]$edge.label, $small, $brush, [single]$edge.labelX, [single]$edge.labelY)
        }
    }
    foreach ($node in $spec.nodes) {
        $rect = New-Object System.Drawing.RectangleF ([single]$node.x), ([single]$node.y), ([single]$node.w), ([single]$node.h)
        $canvas.FillRectangle($wash, $rect)
        $canvas.DrawRectangle($border, $rect.X, $rect.Y, $rect.Width, $rect.Height)
        $rect.Inflate(-10, -5)
        $canvas.DrawString([string]$node.text, $font, $brush, $rect, $format)
    }
    $target = [System.IO.Path]::GetFullPath((Join-Path $diagramRoot ($spec.id + '.png')))
    if (-not $target.StartsWith($diagramRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) { throw 'Diagram target leaves report directory.' }
    $bitmap.Save($target, [System.Drawing.Imaging.ImageFormat]::Png)
    $format.Dispose(); $border.Dispose(); $wash.Dispose(); $brush.Dispose(); $small.Dispose(); $font.Dispose(); $pen.Dispose(); $canvas.Dispose(); $bitmap.Dispose()
}
