# Bootstrap SSH key auth onto the VPS using Posh-SSH (one-time, password-based)
# After this runs, plain ssh.exe + key auth works for all subsequent steps.
#
# Reads credentials from .deploy\secrets.local.env (gitignored). Create it from
# secrets.local.env.example. NEVER commit a populated secrets file.

param(
    [string]$VpsHost,
    [string]$VpsUser,
    [string]$VpsPassword
)

$ErrorActionPreference = 'Stop'
Import-Module Posh-SSH -ErrorAction Stop

# Load defaults from .deploy\secrets.local.env if any of the params are missing.
$secretsPath = Join-Path (Split-Path $PSCommandPath -Parent) 'secrets.local.env'
if ((-not $VpsPassword -or -not $VpsHost -or -not $VpsUser) -and (Test-Path $secretsPath)) {
    foreach ($line in Get-Content $secretsPath) {
        if ($line -match '^\s*([^#=][^=]*)=(.*)$') {
            $k = $matches[1].Trim()
            $v = $matches[2].Trim().Trim('"').Trim("'")
            switch ($k) {
                'VPS_HOST'     { if (-not $VpsHost)     { $VpsHost     = $v } }
                'VPS_USER'     { if (-not $VpsUser)     { $VpsUser     = $v } }
                'VPS_PASSWORD' { if (-not $VpsPassword) { $VpsPassword = $v } }
            }
        }
    }
}

if (-not $VpsHost -or -not $VpsUser -or -not $VpsPassword) {
    throw "Missing VPS credentials. Populate .deploy\secrets.local.env or pass -VpsHost / -VpsUser / -VpsPassword."
}

$keyDir = Join-Path $env:USERPROFILE '.ssh'
$keyPath = Join-Path $keyDir 'guillermos_vps'
$pubKeyPath = "$keyPath.pub"

if (-not (Test-Path $keyDir)) { New-Item -ItemType Directory -Path $keyDir | Out-Null }

if (-not (Test-Path $keyPath)) {
    Write-Host "Generating SSH keypair at $keyPath..."
    & ssh-keygen.exe -t ed25519 -N '""' -f $keyPath -C "kiro-deploy-guillermos"
} else {
    Write-Host "Reusing existing key at $keyPath"
}

$pubKey = (Get-Content $pubKeyPath -Raw).Trim()

$sec = ConvertTo-SecureString $VpsPassword -AsPlainText -Force
$cred = New-Object System.Management.Automation.PSCredential($VpsUser, $sec)

Write-Host "Connecting to $VpsUser@$VpsHost ..."
$session = New-SSHSession -ComputerName $VpsHost -Credential $cred -AcceptKey -ConnectionTimeout 30
if (-not $session) { throw "Failed to open SSH session" }

# Verify reachable
$verify = Invoke-SSHCommand -SessionId $session.SessionId -Command 'whoami; uname -a; lsb_release -ds 2>/dev/null || cat /etc/os-release | head -2'
Write-Host "Remote info:`n$($verify.Output -join "`n")"

# Install pubkey into authorized_keys
$bashEscaped = $pubKey -replace "'", "'\''"
$installCmd = @"
mkdir -p ~/.ssh && chmod 700 ~/.ssh
touch ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys
grep -qxF '$bashEscaped' ~/.ssh/authorized_keys || echo '$bashEscaped' >> ~/.ssh/authorized_keys
echo OK
"@
$res = Invoke-SSHCommand -SessionId $session.SessionId -Command $installCmd
Write-Host "Key install: $($res.Output -join "`n")"

Remove-SSHSession -SessionId $session.SessionId | Out-Null

# Update local SSH config so we can just `ssh guillermos-vps`
$cfgPath = Join-Path $keyDir 'config'
$cfgEntry = @"

Host guillermos-vps
    HostName $VpsHost
    User $VpsUser
    IdentityFile $keyPath
    IdentitiesOnly yes
    StrictHostKeyChecking accept-new

"@
if (-not (Test-Path $cfgPath)) { New-Item -ItemType File -Path $cfgPath | Out-Null }
$existing = Get-Content $cfgPath -Raw -ErrorAction SilentlyContinue
if ($existing -notmatch 'Host\s+guillermos-vps') {
    Add-Content -Path $cfgPath -Value $cfgEntry
    Write-Host "Added 'guillermos-vps' alias to $cfgPath"
} else {
    Write-Host "SSH config alias already present"
}

# Final smoke test using key auth
Write-Host "Smoke test via key auth..."
& ssh.exe -o StrictHostKeyChecking=accept-new -i $keyPath "$VpsUser@$VpsHost" 'echo ssh-key-auth-ok && id'
