# Builds dist\boxing-fee-manager.zip ready to upload to Hostinger / cPanel.
# Run from the project folder:  powershell -ExecutionPolicy Bypass -File .\build-zip.ps1
$ErrorActionPreference = 'Stop'
$root    = $PSScriptRoot
$staging = Join-Path $env:TEMP ('bfm-build-' + [guid]::NewGuid().ToString('N'))
$dist    = Join-Path $root 'dist'
$zip     = Join-Path $dist 'boxing-fee-manager.zip'

New-Item -ItemType Directory -Force $staging | Out-Null
New-Item -ItemType Directory -Force $dist | Out-Null

# Files and folders that must NOT ship
$exclude = @('config.php', 'dist', 'build-zip.ps1', '.git', '.gitignore', 'storage')

Get-ChildItem -Path $root -Force | Where-Object { $exclude -notcontains $_.Name } | ForEach-Object {
    Copy-Item -Path $_.FullName -Destination (Join-Path $staging $_.Name) -Recurse -Force
}

# Empty storage folder (logs are written here) with its deny rule
$st = Join-Path $staging 'storage'
New-Item -ItemType Directory -Force $st | Out-Null
Copy-Item (Join-Path $root 'storage\.htaccess') (Join-Path $st '.htaccess')
New-Item -ItemType File -Force (Join-Path $st '.gitkeep') | Out-Null

# Hosting-ready config.php template (placeholders, debug off)
$config = @'
<?php
/**
 * Boxing Fee Manager - configuration
 * Fill in the database details from your hosting panel (hPanel -> Databases).
 * See INSTALL-HOSTINGER.txt for step-by-step instructions.
 */
return [
    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'u123456789_fee',        // database name from hPanel
        'user'    => 'u123456789_feeuser',    // database user from hPanel
        'pass'    => 'CHANGE_ME',             // database password
        'charset' => 'utf8mb4',
    ],

    // Public URL of this app WITHOUT trailing slash, e.g. https://yourgym.com/fee
    // It must be the same address you open in the browser (www vs non-www matters),
    // otherwise you will be logged out after every form submit.
    'app_url'  => 'https://yourdomain.com/fee',

    'app_name' => 'Boxing Fee Manager',
    'timezone' => 'Asia/Kolkata',
    'session_name' => 'bfm_session',

    // Show PHP errors on screen (keep OFF on a live server)
    'debug' => false,
];
'@
[System.IO.File]::WriteAllText((Join-Path $staging 'config.php'), $config, (New-Object System.Text.UTF8Encoding $false))

if (Test-Path $zip) { Remove-Item $zip -Force }
# .NET ZipFile writes forward-slash entry names, which Linux hosting panels extract correctly
# (Compress-Archive on Windows PowerShell 5.1 writes backslashes and breaks folders on Linux).
Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory($staging, $zip, [System.IO.Compression.CompressionLevel]::Optimal, $false)
Remove-Item $staging -Recurse -Force

$size = [math]::Round((Get-Item $zip).Length / 1KB)
Write-Host "Built $zip ($size KB)"
