[CmdletBinding()]
param(
    [switch]$Preview
)

$ErrorActionPreference = 'Stop'

$codexHomePath = if ([string]::IsNullOrWhiteSpace($env:CODEX_HOME)) {
    Join-Path $env:USERPROFILE '.codex'
} else {
    $env:CODEX_HOME
}
$codexHomePath = [System.IO.Path]::GetFullPath($codexHomePath)
$normalizedHomePath = $codexHomePath.TrimEnd([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar)
$rootPath = [System.IO.Path]::GetPathRoot($codexHomePath).TrimEnd([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar)

if ([string]::Equals($normalizedHomePath, $rootPath, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "Menolak membersihkan root filesystem: $codexHomePath"
}
if (-not (Test-Path -LiteralPath $codexHomePath -PathType Container)) {
    throw "CODEX_HOME tidak ditemukan: $codexHomePath"
}
if (-not (Test-Path -LiteralPath (Join-Path $codexHomePath 'config.toml') -PathType Leaf)) {
    throw "config.toml tidak ditemukan di CODEX_HOME; target tidak akan dibersihkan."
}
$authFiles = @(Get-ChildItem -LiteralPath $codexHomePath -Filter 'auth*.json' -File -Force -ErrorAction SilentlyContinue)
if ($authFiles.Count -eq 0) {
    throw "File auth*.json tidak ditemukan di CODEX_HOME; target tidak akan dibersihkan."
}

$directoriesToClear = @(
    'cache',
    'sessions',
    'log',
    '.locks',
    '.tmp',
    'tmp',
    'thread-writer-locks',
    'tui-thread-reference-capabilities',
    'tui-luna-reserve'
)
$stateFilePattern = '^(history\.jsonl|session_index\.jsonl(?:\.lock)?|models_cache\.json|cap_sid|\.sqlite-maintenance\.lock|thread_history_[^\\/]+\.sqlite(?:-(?:shm|wal))?|logs_[^\\/]+\.sqlite(?:-(?:shm|wal))?|state_[^\\/]+\.sqlite(?:-(?:shm|wal))?|goals_[^\\/]+\.sqlite(?:-(?:shm|wal))?|memories_[^\\/]+\.sqlite(?:-(?:shm|wal))?|queue_[^\\/]+\.sqlite(?:-(?:shm|wal))?|sandbox\.\d{4}-\d{2}-\d{2}\.log)$'

function Get-CodexProcesses {
    @(Get-CimInstance -ClassName Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        $_.Name -match '(?i)^codex(?:[-_].*)?(?:\.exe)?$' -or
        $_.ExecutablePath -match '(?i)OpenAI[.]Codex|[\\/]@openai[\\/]codex[\\/]|[\\/]codex(?:[.]exe)?$' -or
        $_.CommandLine -match '(?i)[\\/]@openai[\\/]codex[\\/]|codex-code-mode-host|(?:^|[\\/])codex[.]ps1(?:\s|"|$)'
    })
}

function Get-SessionStateFiles {
    @(Get-ChildItem -LiteralPath $codexHomePath -File -Force | Where-Object { $_.Name -match $stateFilePattern })
}

Write-Output "CODEX_HOME: $codexHomePath"
Write-Output 'Akan dibersihkan: sesi, cache, file sementara, indeks/riwayat, dan database state Codex.'
Write-Output 'Akan dipertahankan: semua auth*.json, config.toml, AGENTS.md, rules, skills, plugins, attachments, generated_images, serta data sandbox.'

$codexProcesses = Get-CodexProcesses
if ($codexProcesses.Count -gt 0) {
    Write-Output "Proses Codex aktif: $($codexProcesses.Count)"
    $codexProcesses | Select-Object @{Name='PID'; Expression={$_.ProcessId}}, Name, ExecutablePath | Format-Table -AutoSize
} else {
    Write-Output 'Tidak ada proses Codex aktif.'
}

$existingDirectories = @()
foreach ($directoryName in $directoriesToClear) {
    $directoryPath = [System.IO.Path]::GetFullPath((Join-Path $codexHomePath $directoryName))
    $parentPath = [System.IO.Path]::GetDirectoryName($directoryPath).TrimEnd([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar)
    if (-not [string]::Equals($parentPath, $normalizedHomePath, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Target direktori tidak aman: $directoryPath"
    }
    if (Test-Path -LiteralPath $directoryPath -PathType Container) {
        $existingDirectories += $directoryPath
    }
}

$stateFiles = Get-SessionStateFiles
Write-Output 'Direktori yang akan dikosongkan:'
if ($existingDirectories.Count -gt 0) { $existingDirectories | ForEach-Object { Write-Output "  $_" } } else { Write-Output '  (tidak ada)' }
Write-Output 'File riwayat/state yang akan dihapus:'
if ($stateFiles.Count -gt 0) { $stateFiles | ForEach-Object { Write-Output "  $($_.Name)" } } else { Write-Output '  (tidak ada)' }

if ($Preview) {
    Write-Output 'Preview selesai; tidak ada proses dihentikan dan tidak ada file yang diubah.'
    return
}

$confirmation = Read-Host 'Untuk menghentikan proses Codex dan membersihkan data di atas, ketik RESET CODEX'
if ($confirmation -cne 'RESET CODEX') {
    Write-Output 'Dibatalkan; tidak ada perubahan.'
    return
}

foreach ($process in $codexProcesses) {
    try {
        Stop-Process -Id $process.ProcessId -Force -ErrorAction Stop
        Write-Output "Menghentikan proses $($process.Name) (PID $($process.ProcessId))."
    } catch {
        Write-Warning "Tidak dapat menghentikan PID $($process.ProcessId): $($_.Exception.Message)"
    }
}

Start-Sleep -Seconds 3
$remainingProcesses = Get-CodexProcesses
if ($remainingProcesses.Count -gt 0) {
    $remainingProcesses | Select-Object @{Name='PID'; Expression={$_.ProcessId}}, Name, ExecutablePath | Format-Table -AutoSize
    throw 'Masih ada proses Codex aktif. Tidak ada data yang dihapus; tutup proses tersebut lalu jalankan ulang helper.'
}

foreach ($directoryPath in $existingDirectories) {
    if ((Get-CodexProcesses).Count -gt 0) {
        throw 'Proses Codex muncul kembali. Pembersihan dihentikan sebelum target berikutnya.'
    }
    Get-ChildItem -LiteralPath $directoryPath -Force | Remove-Item -Recurse -Force
    Write-Output "Dikosongkan: $directoryPath"
}

foreach ($file in (Get-SessionStateFiles)) {
    if ((Get-CodexProcesses).Count -gt 0) {
        throw 'Proses Codex muncul kembali. Pembersihan dihentikan sebelum file state berikutnya.'
    }
    Remove-Item -LiteralPath $file.FullName -Force
    Write-Output "Dihapus: $($file.Name)"
}

if (-not (Test-Path -LiteralPath (Join-Path $codexHomePath 'config.toml') -PathType Leaf) -or
    @(Get-ChildItem -LiteralPath $codexHomePath -Filter 'auth*.json' -File -Force).Count -ne $authFiles.Count) {
    throw 'Pemeriksaan akhir gagal: file pengaturan atau autentikasi berubah.'
}

Write-Output 'Selesai. Autentikasi dan pengaturan tetap ada; Codex dapat dibuka kembali untuk membuat sesi baru.'
