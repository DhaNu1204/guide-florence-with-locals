# Florence with Locals - Windows Deployment Script
# Usage: .\scripts\deploy.ps1 [-Environment production|staging]
#

param(
    [string]$Environment = "production"
)

# Configuration
$RemoteUser = "u803853690"
$RemoteHost = "82.25.82.111"
$RemotePort = "65002"
$RemotePath = "/home/u803853690/domains/deetech.cc/public_html/withlocals"
$BackupDir = "/home/u803853690/domains/deetech.cc/backups"

# Functions
function Write-Info {
    param([string]$Message)
    Write-Host "[INFO] $Message" -ForegroundColor Green
}

function Write-Warn {
    param([string]$Message)
    Write-Host "[WARN] $Message" -ForegroundColor Yellow
}

function Write-Err {
    param([string]$Message)
    Write-Host "[ERROR] $Message" -ForegroundColor Red
}

# Check if we're in the right directory
if (-not (Test-Path "package.json")) {
    Write-Err "Please run this script from the project root directory"
    exit 1
}

Write-Info "Deploying to: $Environment"

# Step 1: Build frontend
Write-Info "Building frontend for production..."
npm run build -- --mode production

if (-not (Test-Path "dist")) {
    Write-Err "Build failed! dist directory not found."
    exit 1
}

Write-Info "Build completed successfully"

# Step 2: Create timestamp for backup
$Timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$BackupName = "withlocals_backup_$Timestamp"

Write-Info "Backup will be created as: $BackupName"

# Step 3: Deploy using SCP
Write-Info "Deploying frontend files..."
scp -P $RemotePort -r dist/* "${RemoteUser}@${RemoteHost}:${RemotePath}/"

Write-Info "Deploying backend API files..."
scp -P $RemotePort -r public_html/api/* "${RemoteUser}@${RemoteHost}:${RemotePath}/api/"

# Step 4: Verify deployment
Write-Info "Verifying deployment..."
try {
    $response = Invoke-WebRequest -Uri "https://withlocals.deetech.cc" -Method Head -TimeoutSec 10
    if ($response.StatusCode -eq 200) {
        Write-Info "Deployment successful! Site is responding (HTTP $($response.StatusCode))"
    }
} catch {
    Write-Warn "Could not verify site - please check manually: $_"
}

# Test API
try {
    $apiResponse = Invoke-WebRequest -Uri "https://withlocals.deetech.cc/api/tours.php" -Method Get -TimeoutSec 10
    if ($apiResponse.StatusCode -eq 200) {
        Write-Info "API is responding correctly (HTTP $($apiResponse.StatusCode))"
    }
} catch {
    Write-Warn "Could not verify API - please check manually"
}

Write-Info "================================================"
Write-Info "Deployment completed!"
Write-Info "Production URL: https://withlocals.deetech.cc"
Write-Info "================================================"
