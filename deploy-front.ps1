$ErrorActionPreference = "Stop"

$RemoteUser = "host89998"
$RemoteHost = "h3.iqhs.eu"
$RemotePublicHtml = "/home/host89998/domains/coach.host89998.iqhs.pl/public_html"

Write-Host "1/4 Build frontend..." -ForegroundColor Cyan
npm run build

Write-Host "2/4 Upload dist/* to IQHost..." -ForegroundColor Cyan
scp -r .\dist\* "${RemoteUser}@${RemoteHost}:${RemotePublicHtml}/"

Write-Host "3/4 Set file permissions..." -ForegroundColor Cyan
ssh "${RemoteUser}@${RemoteHost}" "chmod 755 ${RemotePublicHtml}/assets && chmod 644 ${RemotePublicHtml}/assets/* && chmod 644 ${RemotePublicHtml}/index.html"

Write-Host "4/4 Check deployed files..." -ForegroundColor Cyan
curl.exe -I "https://coach.host89998.iqhs.pl/"
curl.exe -I "https://coach.host89998.iqhs.pl/assets/$(Get-ChildItem .\dist\assets\*.js | Select-Object -First 1 | Split-Path -Leaf)"

Write-Host "Frontend deploy complete." -ForegroundColor Green
