param([int]$Port=8000)
. (Join-Path $PSScriptRoot 'Environment.ps1')
Push-Location $projectRoot
$ownedProcesses = @()
$stopOwnedWorkers = $false
try {
    & (Join-Path $PSScriptRoot 'Setup.ps1')
    $phpExecutable = Find-NexaPhp
    $runtimePath = Join-Path $projectRoot '.runtime'
    foreach ($workerType in @('queue','scheduler')) {
        $worker = Start-NexaWorker -PhpExecutable $phpExecutable -WorkerType $workerType
        if ($worker) { $ownedProcesses += $worker }
    }
    if (Test-NexaPort $Port) {
        Write-Host "Server sudah berjalan. Queue dan scheduler diperiksa. Buka http://127.0.0.1:$Port"
        exit 0
    }
    $stopOwnedWorkers = $true
    Write-Host "Buka http://127.0.0.1:$Port -- tekan Ctrl+C untuk menghentikan aplikasi." -ForegroundColor Green
    Write-Host 'Queue dan scheduler berjalan di latar belakang. Mode riset lokal tanpa transfer bank.'
    Invoke-NexaCommand $phpExecutable @('artisan','serve','--host=127.0.0.1',('--port='+$Port),'--no-reload')
} finally {
    if ($stopOwnedWorkers) { foreach ($ownedProcess in $ownedProcesses) { if (!$ownedProcess.HasExited) { Stop-Process -Id $ownedProcess.Id -ErrorAction SilentlyContinue } } }
    Pop-Location
}
