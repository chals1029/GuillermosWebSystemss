# Re-install our public key cleanly and write a proper SSH config alias.
# Reads VPS_PASSWORD from .deploy\secrets.local.env if -VpsPassword not supplied.
param(
    [string]$VpsHost,
    [string]$VpsUser,
    [string]$VpsPassword
)

$ErrorActionPreference = 'Stop'
Import-Module Posh-SSH -ErrorAction Stop

$secretsPath = Join-Path (Split-Path $PSCommandPath -Parent) 'secrets.local.env'
if ((-not $VpsPassword -or -not $VpsHost -or -not $VpsUser) -and (Test-Path $secretsPath)) {
    foreach ($line in Get-Content $secretsPath) {
        if ($line -match '^\s*([^#=][^=]*)=(.*)$') {
            $k = $matches[1].Trim(); $v = $matches[2].Trim().Trim('"').Trim("'")
            switch ($k) {
                'VPS_HOST'     { if (-not $VpsHost)     { $VpsHost     = $v } }
                'VPS_USER'     { if (-not $VpsUser)     { $VpsUser     = $v } }
                'VPS_PASSWORD' { if (-not $VpsPassword) { $VpsPassword = $v } }
            }
        }
    }
}
if (-not $VpsHost -or -not $VpsUser -or -not $VpsPassword) {
    throw "Missing VPS credentials. Populate .deploy\secrets.local.env or pass -VpsHost/-VpsUser/-VpsPassword."
}

$keyDir = Join-Path $env:USERPROFILE '.ssh'
$keyPath = Join-Path $keyDir 'guillermos_vps'
$pubKeyPath = "$keyPath.pub"

# Read pubkey, strip ALL whitespace/newlines except the single space inside, then build a single-line key
$rawPub = [System.IO.File]::ReadAllText($pubKeyPath)
$pubKey = ($rawPub -replace "\s+", ' ').Trim()
Write-Host "Cleaned pubkey: $pubKey"

$sec = ConvertTo-SecureString $VpsPassword -AsPlainText -Force
$cred = New-Object System.Management.Automation.PSCredential($VpsUser, $sec)

$session = New-SSHSession -ComputerName $VpsHost -Credential $cred -AcceptKey -ConnectionTimeout 30
if (-not $session) { throw "Failed to open SSH session" }

# Replace authorized_keys with only this key (clean slate, since prior attempt left a malformed line)
# Use base64 to avoid any quoting/escaping issues.
$bytes = [System.Text.Encoding]::ASCII.GetBytes("$pubKey`n")
$b64 = [Convert]::ToBase64String($bytes)
$cmd = @"
set -e
mkdir -p ~/.ssh && chmod 700 ~/.ssh
# Preserve any non-malformed prior entries (filter to lines starting with ssh-/ecdsa- and matching one-line keys)
TMP=`$(mktemp)
if [ -f ~/.ssh/authorized_keys ]; then
  awk '/^(ssh-(rsa|ed25519|dss)|ecdsa-) /{print}' ~/.ssh/authorized_keys > "`$TMP" || true
fi
echo '$b64' | base64 -d >> "`$TMP"
# Dedupe
sort -u "`$TMP" -o ~/.ssh/authorized_keys
rm -f "`$TMP"
chmod 600 ~/.ssh/authorized_keys
echo '--- authorized_keys ---'
cat ~/.ssh/authorized_keys
echo '--- end ---'
"@
$cmd = $cmd -replace "`r`n", "`n"
$res = Invoke-SSHCommand -SessionId $session.SessionId -Command $cmd
Write-Host ($res.Output -join "`n")
if ($res.ExitStatus -ne 0) { Write-Host "STDERR: $($res.Error -join "`n")"; throw "Remote command failed" }

Remove-SSHSession -SessionId $session.SessionId | Out-Null

# Rewrite SSH config alias cleanly (UTF8, no BOM)
$cfgPath = Join-Path $keyDir 'config'
$existing = ''
if (Test-Path $cfgPath) { $existing = [System.IO.File]::ReadAllText($cfgPath) }
# Strip any prior guillermos-vps block
$existing = [regex]::Replace($existing, "(?ms)^\s*Host\s+guillermos-vps.*?(?=^\s*Host\s|\Z)", '')
$entry = "Host guillermos-vps`n    HostName $VpsHost`n    User $VpsUser`n    IdentityFile $keyPath`n    IdentitiesOnly yes`n    StrictHostKeyChecking accept-new`n"
if ($existing.Length -gt 0 -and -not $existing.EndsWith("`n")) { $existing += "`n" }
$final = $existing + "`n" + $entry
[System.IO.File]::WriteAllText($cfgPath, $final, [System.Text.UTF8Encoding]::new($false))
Write-Host "Wrote SSH config at $cfgPath"

# Smoke test using key auth via the alias
Write-Host "`nTest 1: direct key auth..."
& ssh.exe -o BatchMode=yes -o StrictHostKeyChecking=accept-new -i $keyPath "$VpsUser@$VpsHost" 'echo KEY_OK; id; hostname'

Write-Host "`nTest 2: alias..."
& ssh.exe -o BatchMode=yes guillermos-vps 'echo ALIAS_OK; whoami'

