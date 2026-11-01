$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot

function Invoke-NexaCommand {
    param([string]$Program, [string[]]$CommandArguments)
    & $Program @CommandArguments
    if ($LASTEXITCODE -ne 0) { throw "Perintah gagal: $Program $($CommandArguments -join ' ')" }
}

function Get-NexaEnvValue {
    param([string]$Name)
    $envPath = Join-Path $projectRoot '.env'
    if (!(Test-Path -LiteralPath $envPath)) { return '' }
    $line = Get-Content -LiteralPath $envPath | Where-Object { $_ -match ('^' + [regex]::Escape($Name) + '=') } | Select-Object -Last 1
    if ($null -eq $line) { return '' }
    return ($line.Substring($Name.Length + 1)).Trim().Trim('"')
}

function Start-NexaDatabase {
    $runtime = Join-Path $projectRoot '.runtime'
    New-Item -ItemType Directory -Path $runtime -Force | Out-Null
    $dbPort = Get-NexaEnvValue 'DB_PORT'
    $dbHost = Get-NexaEnvValue 'DB_HOST'
    if ($dbPort -ne '3307' -or $dbHost -ne '127.0.0.1') { Write-Host 'Menggunakan server database sesuai .env.'; return }
    if (Test-NexaPort 3307) { Write-Host 'Database lokal pada port 3307 sudah berjalan.'; return }
    $serverPath = 'C:\xampp\mysql\bin\mysqld.exe'
    if (!(Test-Path -LiteralPath $serverPath)) { throw 'XAMPP MySQL tidak ditemukan. Jalankan MySQL sendiri dan sesuaikan DB_HOST/DB_PORT pada .env.' }
    $dataPath = Join-Path $runtime 'mysql-data'
    $tempPath = Join-Path $runtime 'tmp'
    New-Item -ItemType Directory -Path $tempPath -Force | Out-Null
    if (!(Test-Path -LiteralPath (Join-Path $dataPath 'mysql'))) {
        New-Item -ItemType Directory -Path $dataPath -Force | Out-Null
        Copy-Item -Path 'C:\xampp\mysql\backup\*' -Destination $dataPath -Recurse
    }
    $arguments = @('--no-defaults','--basedir=C:/xampp/mysql',('--datadir="' + $dataPath + '"'),('--tmpdir="' + $tempPath + '"'),'--port=3307','--bind-address=127.0.0.1',('--log-error="' + (Join-Path $runtime 'mysql.log') + '"'))
    $dbProcess = Start-Process -FilePath $serverPath -ArgumentList $arguments -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru
    Set-Content -LiteralPath (Join-Path $runtime 'mysql.pid') -Value $dbProcess.Id
    for ($attempt=0; $attempt -lt 60; $attempt++) {
        Start-Sleep -Milliseconds 250
        if (Test-NexaPort 3307) { Write-Host 'Database proyek siap pada 127.0.0.1:3307.'; return }
        if ($dbProcess.HasExited) { throw ('Database gagal dijalankan. Periksa ' + (Join-Path $runtime 'mysql.log')) }
    }
    throw 'Database belum siap setelah 15 detik.'
}

function Test-NexaPort {
    param([int]$Port)
    $client = New-Object System.Net.Sockets.TcpClient
    try { $client.Connect('127.0.0.1',$Port); return $client.Connected } catch { return $false } finally { $client.Dispose() }
}

function Find-NexaPhp {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCommand) { return $phpCommand.Source }
    if (Test-Path -LiteralPath 'C:\xampp\php\php.exe') { return 'C:\xampp\php\php.exe' }
    throw 'PHP tidak ditemukan. Instal PHP 8.5 sesuai composer.lock atau XAMPP dan tambahkan PHP ke PATH.'
}

function Start-NexaWorker {
    param([string]$PhpExecutable,[ValidateSet('queue','scheduler')][string]$WorkerType)
    $runtime = Join-Path $projectRoot '.runtime'
    $pidPath = Join-Path $runtime ('managed-' + $WorkerType + '.pid')
    if (Test-Path -LiteralPath $pidPath) {
        $savedPid = Get-Content -LiteralPath $pidPath -Raw
        if ($savedPid.Trim() -match '^\d+$') {
            $runningWorker = Get-Process -Id ([int]$savedPid.Trim()) -ErrorAction SilentlyContinue
            if ($runningWorker -and $runningWorker.Path -eq $PhpExecutable) { return $null }
        }
    }
    if ($WorkerType -eq 'scheduler') {
        $previousLog = Get-Item -LiteralPath (Join-Path $runtime 'scheduler.out.log') -ErrorAction SilentlyContinue
        if ($previousLog -and ((Get-Date)-$previousLog.LastWriteTime).TotalSeconds -lt 90) { return $null }
    }
    $workerArguments = if ($WorkerType -eq 'queue') { @('artisan','queue:work','--sleep=1','--tries=3','--timeout=120') } else { @('artisan','schedule:work') }
    $process = Start-Process -FilePath $PhpExecutable -ArgumentList $workerArguments -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtime ('managed-'+$WorkerType+'.out.log')) -RedirectStandardError (Join-Path $runtime ('managed-'+$WorkerType+'.error.log'))
    Set-Content -LiteralPath $pidPath -Value $process.Id
    return $process
}
