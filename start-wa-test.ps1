# start-wa-test.ps1
# Jalankan server BE (port 8001) + cloudflared tunnel untuk testing webhook Wablas.
# Cara pakai:  klik kanan > Run with PowerShell,  atau di terminal:  .\start-wa-test.ps1

$ErrorActionPreference = 'Stop'
$projectDir = $PSScriptRoot
$cloudflared = "C:\Program Files (x86)\cloudflared\cloudflared.exe"

Write-Host "== Larashop WA test ==" -ForegroundColor Cyan

# 1) Backend di jendela terpisah
Start-Process powershell -ArgumentList @(
    '-NoExit', '-Command',
    "Set-Location '$projectDir'; php artisan serve --port=8001"
)
Write-Host "[1] Backend dimulai di port 8001 (jendela baru)." -ForegroundColor Green

if (-not (Test-Path $cloudflared)) {
    Write-Host "cloudflared tidak ditemukan di: $cloudflared" -ForegroundColor Red
    Write-Host "Install dulu: winget install --id Cloudflare.cloudflared" -ForegroundColor Yellow
    return
}

# 2) Tunnel di jendela terpisah
Start-Process powershell -ArgumentList @(
    '-NoExit', '-Command',
    "& '$cloudflared' tunnel --url http://localhost:8001"
)
Write-Host "[2] Cloudflared tunnel dimulai (jendela baru)." -ForegroundColor Green

Write-Host ""
Write-Host "Langkah selanjutnya:" -ForegroundColor Cyan
Write-Host " - Lihat jendela cloudflared, salin URL https://xxxx.trycloudflare.com"
Write-Host " - Pasang di dashboard Wablas (Webhook URL for Inbound Message):"
Write-Host "   https://xxxx.trycloudflare.com/api/v1/webhooks/wablas?secret=lrshp_wh_k7Qm2Xb9PdR4" -ForegroundColor Yellow
Write-Host ""
Write-Host "Catatan: URL trycloudflare berubah tiap kali dijalankan -> update lagi di Wablas." -ForegroundColor DarkGray
