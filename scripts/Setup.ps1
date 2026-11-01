param([switch]$Verify, [switch]$InstallLivewire)
. (Join-Path $PSScriptRoot 'Environment.ps1')
Push-Location $projectRoot
try {
    $phpExecutable = Find-NexaPhp
    $envPath = Join-Path $projectRoot '.env'
    if (!(Test-Path -LiteralPath $envPath)) { Copy-Item -LiteralPath '.env.example' -Destination $envPath }
    if (!(Get-NexaEnvValue 'SANDBOX_WEBHOOK_SECRET')) {
        $secret = [guid]::NewGuid().ToString('N') + [guid]::NewGuid().ToString('N')
        Add-Content -LiteralPath $envPath -Value ("`nSANDBOX_WEBHOOK_SECRET=" + $secret)
    }
    if (!(Test-Path -LiteralPath 'vendor/autoload.php')) { Invoke-NexaCommand 'composer.bat' @('install','--no-interaction','--prefer-dist') }
    if (!(Get-NexaEnvValue 'APP_KEY')) { Invoke-NexaCommand $phpExecutable @('artisan','key:generate','--no-interaction') }
    Start-NexaDatabase
    Invoke-NexaCommand $phpExecutable @('artisan','config:clear')
    Invoke-NexaCommand $phpExecutable @('artisan','nexavest:prepare-database')
    Invoke-NexaCommand $phpExecutable @('artisan','migrate','--force','--no-interaction')
    Invoke-NexaCommand $phpExecutable @('artisan','db:seed','--force','--no-interaction')
    Invoke-NexaCommand $phpExecutable @('artisan','nexavest:recognize-liabilities')
    if (!(Test-Path -LiteralPath 'public/build/manifest.json')) {
        if (!(Test-Path -LiteralPath 'node_modules/.bin/vite.cmd')) { Invoke-NexaCommand 'npm.cmd' @('ci') }
        Invoke-NexaCommand 'npm.cmd' @('run','build')
    }
    if ($InstallLivewire) { Invoke-NexaCommand 'composer.bat' @('require','livewire/livewire:~4.0','--no-interaction') }
    if ($Verify) {
        Invoke-NexaCommand $phpExecutable @('artisan','nexavest:verify-ledger')
        Invoke-NexaCommand 'npm.cmd' @('run','build')
    }
    Write-Host ''
    Write-Host 'NexaVest siap. Jalankan JALANKAN.cmd, lalu buka http://127.0.0.1:8000' -ForegroundColor Green
    Write-Host 'Akun demo: storage/app/private/demo-accounts.txt'
} finally { Pop-Location }
