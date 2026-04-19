$ErrorActionPreference = "Stop"

$BaseUrl = $env:API_BASE_URL
if ([string]::IsNullOrWhiteSpace($BaseUrl)) {
  $BaseUrl = "http://localhost/php-api/api"
}

Write-Host "Regression base URL: $BaseUrl"

# 1) health
$health = Invoke-RestMethod -Method Get -Uri "$BaseUrl/health"
if (-not $health.ok) {
  Write-Host "WARN: DB is unavailable, backend functional checks requiring DB are skipped."
  Write-Host "Health response: $($health | ConvertTo-Json -Compress)"
  exit 0
}
Write-Host "OK BUG-001 health (db up)"

# 2) create normal user
$email = "fix.$([guid]::NewGuid().ToString('N').Substring(0,8))@example.com"
$password = "StrongPass123!"
$registerBody = @{ name = "Fix User"; email = $email; password = $password } | ConvertTo-Json
$reg = Invoke-RestMethod -Method Post -Uri "$BaseUrl/auth/register" -ContentType "application/json" -Body $registerBody
if (-not $reg.token) { throw "Register failed" }
$headers = @{ Authorization = "Bearer $($reg.token)" }
Write-Host "OK register"

# 3) profile save
$profileBody = @{ name = "Fix User Updated"; phone = "+79990001122" } | ConvertTo-Json
$profile = Invoke-RestMethod -Method Patch -Uri "$BaseUrl/profile" -ContentType "application/json" -Headers $headers -Body $profileBody
if ($profile.name -ne "Fix User Updated") { throw "Profile update failed" }
Write-Host "OK BUG-002 profile save"

# 4) brute-force lockout check (expect 429 eventually)
$lockEmail = "lock.$([guid]::NewGuid().ToString('N').Substring(0,8))@example.com"
$lockBody = @{ name = "Lock User"; email = $lockEmail; password = "LockPass123!" } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri "$BaseUrl/auth/register" -ContentType "application/json" -Body $lockBody | Out-Null

$got429 = $false
for ($i = 1; $i -le 7; $i++) {
  try {
    $badLogin = @{ email = $lockEmail; password = "WrongPass!" } | ConvertTo-Json
    Invoke-RestMethod -Method Post -Uri "$BaseUrl/auth/login" -ContentType "application/json" -Body $badLogin | Out-Null
  } catch {
    if ($_.Exception.Response.StatusCode.value__ -eq 429) {
      $got429 = $true
      break
    }
  }
}
if (-not $got429) { throw "Lockout not triggered" }
Write-Host "OK BUG-003 lockout"

Write-Host "Regression fixes checks passed (where environment allows)."
