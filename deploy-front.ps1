$ErrorActionPreference = "Stop"

$RemoteUser = "host89998"
$RemoteHost = "h3.iqhs.eu"
$RemotePublicHtml = "/home/host89998/domains/coach.host89998.iqhs.pl/public_html"
$FrontendUrl = "https://coach.host89998.iqhs.pl"
$ApiBaseUrl = "https://api.coach.host89998.iqhs.pl/api"
$PreviousApiBaseUrl = $env:VITE_API_BASE_URL

Write-Host "1/4 Build frontend..." -ForegroundColor Cyan
Write-Host "Using VITE_API_BASE_URL=${ApiBaseUrl}" -ForegroundColor DarkCyan
$env:VITE_API_BASE_URL = $ApiBaseUrl
try {
    npm run build
}
finally {
    if ($null -eq $PreviousApiBaseUrl) {
        Remove-Item Env:\VITE_API_BASE_URL -ErrorAction SilentlyContinue
    }
    else {
        $env:VITE_API_BASE_URL = $PreviousApiBaseUrl
    }
}

Write-Host "2/4 Upload dist/* to IQHost..." -ForegroundColor Cyan
scp -r .\dist\* "${RemoteUser}@${RemoteHost}:${RemotePublicHtml}/"

Write-Host "3/4 Set file permissions..." -ForegroundColor Cyan
ssh "${RemoteUser}@${RemoteHost}" "chmod 755 ${RemotePublicHtml}/assets && chmod 644 ${RemotePublicHtml}/assets/* && chmod 644 ${RemotePublicHtml}/index.html"

Write-Host "4/4 Check deployed files..." -ForegroundColor Cyan
curl.exe --ssl-no-revoke -I "${FrontendUrl}/"
curl.exe --ssl-no-revoke -I "${FrontendUrl}/assets/$(Get-ChildItem .\dist\assets\*.js | Select-Object -First 1 | Split-Path -Leaf)"

Write-Host "Frontend deploy complete." -ForegroundColor Green
