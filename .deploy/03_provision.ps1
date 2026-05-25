# Drive the remote provisioning: copy SQL + script to VPS, then run.
param(
    [string]$VpsHost = '20.189.74.35',
    [string]$VpsUser = 'BeaBunda',
    [string]$Domain = 'guillermoscafe.shop',
    [string]$SqlFile = 'c:\laragon\www\GuillermosWebSystemss\u435394025_guillermos_db (1).sql',
    [string]$DbName = 'u435394025_guillermos_db',
    [string]$DbUser = 'guillermos'
)

$ErrorActionPreference = 'Stop'
$keyPath = Join-Path $env:USERPROFILE '.ssh\guillermos_vps'
$target = "$VpsUser@$VpsHost"
$sshOpts = @('-i', $keyPath, '-o', 'StrictHostKeyChecking=accept-new', '-o', 'BatchMode=yes')

function Invoke-Remote {
    param([string]$Cmd)
    & ssh.exe @sshOpts $target $Cmd
    if ($LASTEXITCODE -ne 0) { throw "Remote command failed (exit $LASTEXITCODE): $Cmd" }
}

function Copy-ToRemote {
    param([string]$Local, [string]$Remote)
    & scp.exe @sshOpts $Local "${target}:$Remote"
    if ($LASTEXITCODE -ne 0) { throw "scp failed for $Local" }
}

# Generate random secrets
function New-Secret {
    param([int]$Length = 32)
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $sb = New-Object System.Text.StringBuilder
        while ($sb.Length -lt $Length) {
            $b = New-Object byte[] 32
            $rng.GetBytes($b)
            $chunk = ([Convert]::ToBase64String($b) -replace '[^A-Za-z0-9]', '')
            [void]$sb.Append($chunk)
        }
        return $sb.ToString().Substring(0, $Length)
    } finally { $rng.Dispose() }
}

$dbPass = New-Secret
$webhookSecret = New-Secret

Write-Host "Copying SQL dump (this is ~19MB)..."
Copy-ToRemote -Local $SqlFile -Remote '/tmp/guillermos_dump.sql'

Write-Host "Copying provision script..."
$localScript = Join-Path (Split-Path $PSCommandPath -Parent) 'remote_provision.sh'
# Convert CRLF -> LF for the remote script
$txt = [System.IO.File]::ReadAllText($localScript) -replace "`r`n", "`n"
$tmpLf = [System.IO.Path]::GetTempFileName()
[System.IO.File]::WriteAllText($tmpLf, $txt, [System.Text.UTF8Encoding]::new($false))
Copy-ToRemote -Local $tmpLf -Remote '/tmp/remote_provision.sh'
Remove-Item $tmpLf -Force

Invoke-Remote 'chmod +x /tmp/remote_provision.sh'

Write-Host "Running provision (this takes a few minutes)..."
$envInline = "APP_DOMAIN='$Domain' DB_NAME='$DbName' DB_USER='$DbUser' DB_PASS='$dbPass' WEBHOOK_SECRET='$webhookSecret'"
& ssh.exe @sshOpts $target "$envInline bash /tmp/remote_provision.sh"
if ($LASTEXITCODE -ne 0) { throw "Provision script failed" }

# Persist generated secrets locally for reference
$out = [pscustomobject]@{
    Domain          = $Domain
    DbName          = $DbName
    DbUser          = $DbUser
    DbPassword      = $dbPass
    WebhookSecret   = $webhookSecret
    WebhookUrl      = "http://$Domain/webhook.php"
    WebhookUrlByIp  = "http://$VpsHost/webhook.php"
}
$secretsPath = Join-Path (Split-Path $PSCommandPath -Parent) 'SECRETS.local.json'
$out | ConvertTo-Json | Set-Content -Path $secretsPath -Encoding UTF8
Write-Host "Wrote $secretsPath"
$out | Format-List
