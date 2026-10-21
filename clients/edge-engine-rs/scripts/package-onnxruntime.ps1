<#
.SYNOPSIS
    Packages official Microsoft ONNX Runtime shared libraries for x86_64 and aarch64.
.DESCRIPTION
    Downloads official release tarballs matching ort = "=2.0.0-rc.13" (v1.24.4),
    verifies SHA256 hashes, extracts libonnxruntime.so.1.24.4, and packages them into dist/onnxruntime/.
.PARAMETER Arch
    Target architecture: 'x86_64', 'aarch64', or 'all' (default).
.PARAMETER OutputDir
    Custom output directory for packaged assets.
#>

param(
    [ValidateSet('x86_64', 'aarch64', 'all')]
    [string]$Arch = 'all',

    [string]$OutputDir = "$PSScriptRoot\..\dist\onnxruntime"
)

$ErrorActionPreference = 'Stop'

$OrtVersion = "1.24.4"
$Hashes = @{
    'x86_64'  = '3a211fbea252c1e66290658f1b735b772056149f28321e71c308942cdb54b747'
    'aarch64' = '866109a9248d057671a039b9d725be4bd86888e3754140e6701ec621be9d4d7e'
}

$TarballNames = @{
    'x86_64'  = "onnxruntime-linux-x64-$OrtVersion.tgz"
    'aarch64' = "onnxruntime-linux-aarch64-$OrtVersion.tgz"
}

$TempDir = "$PSScriptRoot\..\target\ort-download-tmp"
if (-not (Test-Path $TempDir)) {
    New-Item -ItemType Directory -Path $TempDir -Force | Out-Null
}

function Package-Arch([string]$Target) {
    $tarName = $TarballNames[$Target]
    $expectedHash = $Hashes[$Target]
    $downloadUrl = "https://github.com/microsoft/onnxruntime/releases/download/v$OrtVersion/$tarName"
    $tarPath = Join-Path $TempDir $tarName
    $archOutDir = Join-Path $OutputDir $Target

    if (-not (Test-Path $archOutDir)) {
        New-Item -ItemType Directory -Path $archOutDir -Force | Out-Null
    }

    Write-Host "===> Packaging ONNX Runtime v$OrtVersion for [$Target]..." -ForegroundColor Cyan

    if (-not (Test-Path $tarPath)) {
        Write-Host "Downloading $downloadUrl..."
        Invoke-WebRequest -Uri $downloadUrl -OutFile $tarPath
    } else {
        Write-Host "Using cached $tarPath"
    }

    Write-Host "Verifying SHA256 checksum..."
    $actualHash = (Get-FileHash -Path $tarPath -Algorithm SHA256).Hash.ToLower()
    if ($actualHash -ne $expectedHash) {
        Remove-Item $tarPath -Force -ErrorAction SilentlyContinue
        throw "Checksum mismatch for $tarName! Expected $expectedHash, got $actualHash"
    }
    Write-Host "Checksum verified: OK" -ForegroundColor Green

    Write-Host "Extracting archive..."
    & tar.exe -xzf $tarPath -C $TempDir

    $extractName = if ($Target -eq 'x86_64') { "onnxruntime-linux-x64-$OrtVersion" } else { "onnxruntime-linux-aarch64-$OrtVersion" }
    $libSource = Join-Path $TempDir "$extractName\lib\libonnxruntime.so.$OrtVersion"

    if (-not (Test-Path $libSource)) {
        throw "Extracted library not found at: $libSource"
    }

    $destFile = Join-Path $archOutDir "libonnxruntime.so.$OrtVersion"
    Copy-Item -Path $libSource -Destination $destFile -Force

    # Also copy as libonnxruntime.so for direct symlink equivalence
    $destSymlink = Join-Path $archOutDir "libonnxruntime.so"
    Copy-Item -Path $libSource -Destination $destSymlink -Force

    # Generate SHA256SUMS file
    $fileHash = (Get-FileHash -Path $destFile -Algorithm SHA256).Hash.ToLower()
    "$fileHash  libonnxruntime.so.$OrtVersion" | Out-File -FilePath (Join-Path $archOutDir "SHA256SUMS") -Encoding ascii

    Write-Host "Packaged [$Target] into: $archOutDir" -ForegroundColor Green
}

try {
    if ($Arch -eq 'all') {
        Package-Arch 'x86_64'
        Package-Arch 'aarch64'
    } else {
        Package-Arch $Arch
    }
} finally {
    if (Test-Path $TempDir) {
        Remove-Item -Recurse -Force $TempDir -ErrorAction SilentlyContinue
    }
}

Write-Host "=== ONNX Runtime Packaging Finished Successfully ===" -ForegroundColor Cyan
