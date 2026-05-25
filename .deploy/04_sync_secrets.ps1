# Sync the full app .env (merged: local app config + prod DB/webhook) and
# the Google OAuth client_secret JSON to the VPS.

param(
    [string]$VpsTarget = 'guillermos-vps',
    [string]$VpsPassword
)

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSCommandPath -Parent | Split-Path -Parent
$secretsPath = Join-Path $root '.deploy\SECRETS.local.json'
$secrets = Get-Content $secretsPath -Raw | ConvertFrom-Json

if (-not $VpsPassword) {
    $envPath = Join-Path $root '.deploy\secrets.local.env'
    if (Test-Path $envPath) {
        foreach ($line in Get-Content $envPath) {
            if ($line -match '^\s*VPS_PASSWORD=(.*)$') { $VpsPassword = $matches[1].Trim().Trim('"').Trim("'") }
        }
    }
}
if (-not $VpsPassword) {
    throw "VPS_PASSWORD not found. Populate .deploy\secrets.local.env or pass -VpsPassword."
}

# 1) Build merged .env content
$localEnv = [System.IO.File]::ReadAllText((Join-Path $root '.env'))

# Parse local .env into a hashtable (preserve order via list of keys)
$keys = New-Object System.Collections.Specialized.OrderedDictionary
foreach ($line in $localEnv -split "(\r?\n)") {
    $line = $line.Trim()
    if ($line -eq '' -or $line.StartsWith('#')) { continue }
    $eq = $line.IndexOf('=')
    if ($eq -lt 1) { continue }
    $k = $line.Substring(0, $eq).Trim()
    $v = $line.Substring($eq + 1)
    $keys[$k] = $v
}

# Override with production values
$keys['APP_ENV']        = 'production'
$keys['APP_DEBUG']      = 'false'
$keys['APP_BASE_PATH']  = ''
$keys['APP_URL']        = 'https://guillermoscafe.shop'
$keys['DB_HOST']        = '127.0.0.1'
$keys['DB_PORT']        = '3306'
$keys['DB_DATABASE']    = $secrets.DbName
$keys['DB_USERNAME']    = $secrets.DbUser
$keys['DB_PASSWORD']    = $secrets.DbPassword
$keys['WEBHOOK_SECRET'] = $secrets.WebhookSecret

# Backfill any DDoS keys from .env.example defaults if missing in local .env
$envExample = [System.IO.File]::ReadAllText((Join-Path $root '.env.example'))
foreach ($line in $envExample -split "(\r?\n)") {
    $line = $line.Trim()
    if ($line -eq '' -or $line.StartsWith('#')) { continue }
    $eq = $line.IndexOf('=')
    if ($eq -lt 1) { continue }
    $k = $line.Substring(0, $eq).Trim()
    $v = $line.Substring($eq + 1)
    if (-not $keys.Contains($k)) { $keys[$k] = $v }
}

# Render with LF line endings
$sb = New-Object System.Text.StringBuilder
foreach ($k in $keys.Keys) {
    [void]$sb.Append($k); [void]$sb.Append('='); [void]$sb.Append($keys[$k]); [void]$sb.Append("`n")
}
$envOut = $sb.ToString()

$tmpEnv = [System.IO.Path]::GetTempFileName()
[System.IO.File]::WriteAllText($tmpEnv, $envOut, [System.Text.UTF8Encoding]::new($false))

# 2) Locate OAuth client secret JSON
$clientSecret = Get-ChildItem -Path $root -Filter 'client_secret_*.json' | Select-Object -First 1
if (-not $clientSecret) { throw "client_secret_*.json not found in $root" }
Write-Host "Using OAuth file: $($clientSecret.Name)"

# 3) Push files via scp to a staging dir, then move with sudo to set ownership
$sshOpts = @('-o', 'BatchMode=yes')
& scp.exe @sshOpts -q $tmpEnv "${VpsTarget}:/tmp/.env.new"
& scp.exe @sshOpts -q $clientSecret.FullName "${VpsTarget}:/tmp/$($clientSecret.Name)"
Remove-Item $tmpEnv -Force

# 4) Remote: install with proper perms; keep the OAuth file private
$remote = @"
set -e
ASKPASS=`$(mktemp); printf '#!/usr/bin/env bash\necho %q\n' "$VpsPassword" > `"`$ASKPASS`"; chmod 700 `"`$ASKPASS`"
export SUDO_ASKPASS=`"`$ASKPASS`"
APP_DIR=/var/www/guillermoscafe
sudo -A install -o BeaBunda -g www-data -m 0640 /tmp/.env.new `"`$APP_DIR/.env`"
rm -f /tmp/.env.new
sudo -A install -o BeaBunda -g www-data -m 0640 /tmp/$($clientSecret.Name) `"`$APP_DIR/$($clientSecret.Name)`"
rm -f /tmp/$($clientSecret.Name)
echo --- final .env keys ---
sudo -A awk -F= '/^[A-Z]/{print `$1}' `"`$APP_DIR/.env`" | sort
echo --- client secret file ---
sudo -A ls -la `"`$APP_DIR/$($clientSecret.Name)`"
echo --- reload php-mod-apache to clear opcache ---
sudo -A systemctl reload apache2
echo --- PHP getenv check ---
sudo -A -u www-data php -r 'require __DIR__."/Config.php"; echo "GOOGLE_API_KEY len=".strlen((string)getenv("GOOGLE_API_KEY")), PHP_EOL; echo "GEMINI_API_KEY len=".strlen((string)getenv("GEMINI_API_KEY")), PHP_EOL; echo "MAIL_USERNAME=".getenv("MAIL_USERNAME"), PHP_EOL; echo "DB_USERNAME=".getenv("DB_USERNAME"), PHP_EOL;' 2>&1 | head -20
cd `"`$APP_DIR`" && sudo -A -u www-data php -r 'require __DIR__."/Config.php"; echo "GOOGLE_API_KEY len=".strlen((string)getenv("GOOGLE_API_KEY")), PHP_EOL; echo "GEMINI_API_KEY len=".strlen((string)getenv("GEMINI_API_KEY")), PHP_EOL; echo "MAIL_USERNAME=".getenv("MAIL_USERNAME"), PHP_EOL; echo "DB_USERNAME=".getenv("DB_USERNAME"), PHP_EOL;'
rm -f `"`$ASKPASS`"
"@
$remote = $remote -replace "`r`n", "`n"
& ssh.exe @sshOpts $VpsTarget $remote
