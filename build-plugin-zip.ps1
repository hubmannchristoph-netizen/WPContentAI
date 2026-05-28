# Build and package WPContentAI plugin into a clean, installable ZIP file
# Uses .NET ZipArchive to ensure forward-slash paths (required for WordPress ZIP installer)

# 1. Run npm build to ensure latest Gutenberg assets are compiled
Write-Host "Running npm run build to compile Gutenberg assets..." -ForegroundColor Cyan
npm run build
if ($LASTEXITCODE -ne 0) {
    Write-Error "Error: npm run build failed."
    exit 1
}

# 2. Setup paths
$PluginDir  = (Get-Location).Path
$ZipPath    = Join-Path $PluginDir "wpcontentai.zip"
$FolderName = "wpcontentai"  # top-level folder inside ZIP

Write-Host "Preparing ZIP..." -ForegroundColor Cyan

# 3. Remove old ZIP
if (Test-Path $ZipPath) { Remove-Item $ZipPath -Force }

# 4. Define files to include: local path -> ZIP entry name
$files = @(
    @{ Src = "wpcontentai.php";               Zip = "$FolderName/wpcontentai.php" },
    @{ Src = "includes\class-claude.php";     Zip = "$FolderName/includes/class-claude.php" },
    @{ Src = "includes\class-image.php";      Zip = "$FolderName/includes/class-image.php" },
    @{ Src = "includes\class-rest.php";       Zip = "$FolderName/includes/class-rest.php" },
    @{ Src = "build\index.js";                Zip = "$FolderName/build/index.js" },
    @{ Src = "build\index.asset.php";         Zip = "$FolderName/build/index.asset.php" }
)

# 5. Create ZIP using .NET ZipArchive (guarantees forward-slash entry names)
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$stream  = [System.IO.File]::Open($ZipPath, [System.IO.FileMode]::Create)
$archive = New-Object System.IO.Compression.ZipArchive($stream, [System.IO.Compression.ZipArchiveMode]::Create)

foreach ($f in $files) {
    $srcPath = Join-Path $PluginDir $f.Src
    if (-not (Test-Path $srcPath)) {
        Write-Warning "Skipping missing file: $($f.Src)"
        continue
    }
    $entry   = $archive.CreateEntry($f.Zip, [System.IO.Compression.CompressionLevel]::Optimal)
    $entryStream = $entry.Open()
    $srcStream   = [System.IO.File]::OpenRead($srcPath)
    $srcStream.CopyTo($entryStream)
    $srcStream.Close()
    $entryStream.Close()
    Write-Host "  Added: $($f.Zip)" -ForegroundColor DarkGray
}

$archive.Dispose()
$stream.Dispose()

# 6. Verify
Write-Host "`n========================================================" -ForegroundColor Green
Write-Host "WPContentAI Plugin successfully packaged!" -ForegroundColor Green
Write-Host "ZIP Path: $ZipPath" -ForegroundColor Green
Write-Host "========================================================" -ForegroundColor Green

# Show ZIP contents with forward-slash paths
$verify = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
Write-Host "ZIP contents:" -ForegroundColor Cyan
$verify.Entries | ForEach-Object { Write-Host "  $($_.FullName)" }
$verify.Dispose()

Write-Host "========================================================`n" -ForegroundColor Green
