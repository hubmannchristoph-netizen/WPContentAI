# Build and package WPContentAI plugin into a clean, installable ZIP file

# 1. Run npm build to ensure latest Gutenberg assets are compiled
Write-Host "Running npm run build to compile Gutenberg assets..." -ForegroundColor Cyan
npm run build
if ($LASTEXITCODE -ne 0) {
    Write-Error "Error: npm run build failed."
    exit 1
}

# 2. Setup paths
$PluginDir = Get-Location
$DistDir = Join-Path $PluginDir "dist"
$StagingDir = Join-Path $DistDir "wpcontentai"
$ZipPath = Join-Path $PluginDir "wpcontentai.zip"

Write-Host "Preparing staging folder..." -ForegroundColor Cyan

# 3. Clean old zip and dist folders
if (Test-Path $ZipPath) {
    Remove-Item $ZipPath -Force
}
if (Test-Path $DistDir) {
    Remove-Item $DistDir -Recurse -Force
}

# 4. Create staging structure
New-Item -ItemType Directory -Path $StagingDir -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $StagingDir "includes") -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $StagingDir "build") -Force | Out-Null

# 5. Copy plugin files
Write-Host "Copying plugin files to staging..." -ForegroundColor Cyan
Copy-Item "wpcontentai.php" $StagingDir -Force
Copy-Item "includes\*" (Join-Path $StagingDir "includes") -Recurse -Force
Copy-Item "build\*" (Join-Path $StagingDir "build") -Recurse -Force

# 6. Compress staging folder into wpcontentai.zip
Write-Host "Compressing files into wpcontentai.zip..." -ForegroundColor Cyan
Compress-Archive -Path $StagingDir -DestinationPath $ZipPath -Force

# 7. Clean up staging
Write-Host "Cleaning up staging directory..." -ForegroundColor Cyan
Remove-Item $DistDir -Recurse -Force

Write-Host "`n========================================================" -ForegroundColor Green
Write-Host "WPContentAI Plugin successfully packaged!" -ForegroundColor Green
Write-Host "ZIP Path: $ZipPath" -ForegroundColor Green
Write-Host "========================================================" -ForegroundColor Green
Write-Host "Note: If you are testing locally on this XAMPP site, the plugin is already installed." -ForegroundColor Yellow
Write-Host "Do NOT try to install the ZIP locally, as it will conflict with the active folder." -ForegroundColor Yellow
Write-Host "Instead, go to WP-Admin -> Plugins -> Installed Plugins and activate WPContentAI." -ForegroundColor Yellow
Write-Host "========================================================`n" -ForegroundColor Green
