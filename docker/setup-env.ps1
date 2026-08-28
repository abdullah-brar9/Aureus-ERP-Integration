[CmdletBinding()]
param(
    [string] $Path
)

if ([string]::IsNullOrWhiteSpace($Path)) {
    $repoRoot = Split-Path -Parent $PSScriptRoot
    $Path = Join-Path $repoRoot '.env'
}

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repositoryRoot = Split-Path -Parent $PSScriptRoot
$templatePath = Join-Path $repositoryRoot '.env.example'

if (-not (Test-Path -LiteralPath $Path)) {
    Copy-Item -LiteralPath $templatePath -Destination $Path
}

$lines = [System.Collections.Generic.List[string]]::new()
foreach ($line in [System.IO.File]::ReadAllLines($Path)) {
    $lines.Add($line)
}

function New-RandomBytes {
    param([int] $Length)

    $bytes = New-Object byte[] $Length
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()

    try {
        $generator.GetBytes($bytes)
    } finally {
        $generator.Dispose()
    }

    return $bytes
}

function Set-EnvironmentValueIfEmpty {
    param(
        [string] $Name,
        [string] $Value
    )

    for ($index = 0; $index -lt $lines.Count; $index++) {
        if ($lines[$index] -notmatch "^$([regex]::Escape($Name))=(.*)$") {
            continue
        }

        if ([string]::IsNullOrWhiteSpace($Matches[1])) {
            $lines[$index] = "$Name=$Value"
        }

        return
    }

    $lines.Add("$Name=$Value")
}

function Set-EnvironmentValueIfDefault {
    param(
        [string] $Name,
        [string] $DefaultValue,
        [string] $Value
    )

    for ($index = 0; $index -lt $lines.Count; $index++) {
        if ($lines[$index] -notmatch "^$([regex]::Escape($Name))=(.*)$") {
            continue
        }

        if ([string]::IsNullOrWhiteSpace($Matches[1]) -or $Matches[1] -eq $DefaultValue) {
            $lines[$index] = "$Name=$Value"
        }

        return
    }

    $lines.Add("$Name=$Value")
}

$appKey = 'base64:' + [Convert]::ToBase64String((New-RandomBytes -Length 32))
$databasePassword = ([BitConverter]::ToString((New-RandomBytes -Length 24))).Replace('-', '').ToLowerInvariant()
$rootPassword = ([BitConverter]::ToString((New-RandomBytes -Length 24))).Replace('-', '').ToLowerInvariant()

Set-EnvironmentValueIfEmpty -Name 'APP_KEY' -Value $appKey
Set-EnvironmentValueIfEmpty -Name 'DB_PASSWORD' -Value $databasePassword
Set-EnvironmentValueIfEmpty -Name 'MYSQL_ROOT_PASSWORD' -Value $rootPassword
Set-EnvironmentValueIfDefault -Name 'APP_URL' -DefaultValue 'http://localhost' -Value 'http://localhost:8080'
Set-EnvironmentValueIfEmpty -Name 'DOCKER_APP_PORT' -Value '8080'
Set-EnvironmentValueIfEmpty -Name 'DOCKER_DB_PORT' -Value '3307'
Set-EnvironmentValueIfEmpty -Name 'DOCKER_IMAGE_TAG' -Value 'local'
Set-EnvironmentValueIfEmpty -Name 'DOCKER_PHP_VERSION' -Value '8.3'
Set-EnvironmentValueIfEmpty -Name 'DOCKER_NODE_VERSION' -Value '22'

$utf8WithoutBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllLines($Path, $lines, $utf8WithoutBom)

Write-Host "Docker environment is ready at $Path. Existing non-empty values were preserved."
