# Usage: .\build.ps1 -Registry <registry> [-ImageTag "latest"] [-Platforms "linux/amd64,linux/arm64"] [-NoPush]
# Example: .\build.ps1 -Registry "docker.io/myuser/lastfm" -ImageTag "v1.0"

param(
    [Parameter(Mandatory=$true)]
    [string]$Registry,
    [string]$ImageTag = "latest",
    [string]$Platforms = "linux/amd64,linux/arm64",
    [switch]$NoPush = $false
)

$ImageName = $Registry
$Push = -not $NoPush
$BuilderName = "lastfm-builder"

$ErrorActionPreference = "Stop"

Write-Host "========================================" -ForegroundColor Green
Write-Host " Lastfm Docker Build" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""
Write-Host "Registry: " -NoNewline
Write-Host "${Registry}" -ForegroundColor Yellow
Write-Host "Image: " -NoNewline
Write-Host "${ImageName}:${ImageTag}" -ForegroundColor Yellow
Write-Host "Platforms: " -NoNewline
Write-Host "${Platforms}" -ForegroundColor Yellow
Write-Host "Push: " -NoNewline
Write-Host "$Push" -ForegroundColor Yellow
Write-Host ""

try {
    $null = Get-Command docker -ErrorAction Stop
} catch {
    Write-Host "ERROR: Docker is not installed or not in PATH" -ForegroundColor Red
    exit 1
}

try {
    docker info 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Docker daemon not running"
    }
} catch {
    Write-Host "ERROR: Docker daemon is not running. Please start Docker Desktop." -ForegroundColor Red
    exit 1
}

# Check if buildx builder exists, create if not
$builderExists = docker buildx inspect $BuilderName 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Host "Creating buildx builder '${BuilderName}'..." -ForegroundColor Green
    docker buildx create --name $BuilderName --driver docker-container --bootstrap
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: Failed to create buildx builder" -ForegroundColor Red
        exit 1
    }
}

# Use the builder
docker buildx use $BuilderName
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Failed to use buildx builder" -ForegroundColor Red
    exit 1
}

Write-Host "Building Docker image for platforms: ${Platforms}..." -ForegroundColor Green
Write-Host ""

if ($Push) {
    docker buildx build `
        --platform "${Platforms}" `
        -t "${ImageName}:${ImageTag}" `
        -f Dockerfile `
        --push `
        .
    
    $BuildExitCode = $LASTEXITCODE
    
    if ($BuildExitCode -eq 0) {
        Write-Host ""
        Write-Host "========================================" -ForegroundColor Green
        Write-Host " Build & Push Successful!" -ForegroundColor Green
        Write-Host "========================================" -ForegroundColor Green
        Write-Host ""
        Write-Host "Image: " -NoNewline
        Write-Host "${ImageName}:${ImageTag}" -ForegroundColor Yellow
        Write-Host "Platforms: " -NoNewline
        Write-Host "${Platforms}" -ForegroundColor Yellow
    } else {
        Write-Host ""
        Write-Host "========================================" -ForegroundColor Red
        Write-Host " Build & Push Failed!" -ForegroundColor Red
        Write-Host "========================================" -ForegroundColor Red
        exit $BuildExitCode
    }
} else {
    docker buildx build `
        --platform "${Platforms}" `
        -t "${ImageName}:${ImageTag}" `
        -f Dockerfile `
        --load `
        .
    
    $BuildExitCode = $LASTEXITCODE
    
    if ($BuildExitCode -eq 0) {
        Write-Host ""
        Write-Host "========================================" -ForegroundColor Green
        Write-Host " Build Successful!" -ForegroundColor Green
        Write-Host "========================================" -ForegroundColor Green
        Write-Host ""
        Write-Host "Image: " -NoNewline
        Write-Host "${ImageName}:${ImageTag}" -ForegroundColor Yellow
        Write-Host "Platforms: " -NoNewline
        Write-Host "${Platforms}" -ForegroundColor Yellow
        Write-Host ""
        Write-Host "Note: Multi-platform images with --load only load the current platform's image." -ForegroundColor Yellow
        Write-Host "Use without -NoPush to push all platforms to the registry." -ForegroundColor Yellow
    } else {
        Write-Host ""
        Write-Host "========================================" -ForegroundColor Red
        Write-Host " Build Failed!" -ForegroundColor Red
        Write-Host "========================================" -ForegroundColor Red
        exit $BuildExitCode
    }
}