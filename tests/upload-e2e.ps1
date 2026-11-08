# Browser-equivalent end-to-end test over HTTP.
#
# 1. Logs in with a generated admin cookie.
# 2. Uploads a real backup in 2 MB slices via uwp_upload_chunk.
# 3. Starts a restore, then lets server-side chaining finish it.
# 4. Verifies the uploaded archive was adopted by the Backups page.
#
# Usage: powershell -ExecutionPolicy Bypass -File tests/upload-e2e.ps1 [archive-path]

param(
	[string]$Archive = ""
)

$ErrorActionPreference = "Stop"
Add-Type -AssemblyName System.Net.Http | Out-Null
$root = Split-Path -Parent $PSScriptRoot
$php  = "C:\Users\Achi\AppData\Local\Programs\local\resources\extraResources\lightning-services\php-8.1.23+0\bin\win32\php.exe"
$ini  = Join-Path $PSScriptRoot "php.ini"
$base = "http://thriftywedsites.local"
$ajax = "$base/wp-admin/admin-ajax.php"

if ($Archive -eq "") {
	$line = Get-Content (Join-Path $PSScriptRoot "e2e-last-archive.txt") -First 1 -ErrorAction SilentlyContinue
	if ($line) { $Archive = ([string]$line).Trim() }
}
if ($Archive -eq "" -or -not (Test-Path $Archive)) {
	Write-Host "No archive to upload. Run: php tests/e2e-local.php export"
	exit 1
}

# --- Login cookie ---------------------------------------------------------
$cookieLines = & $php -c $ini (Join-Path $PSScriptRoot "make-cookie.php") 2>&1
$container = New-Object System.Net.CookieContainer
foreach ($line in $cookieLines) {
	if ($line -match '^wordpress_') {
		$p = $line.Trim().Split('=', 2)
		$container.Add((New-Object System.Net.Cookie($p[0], $p[1], "/", "thriftywedsites.local")))
	}
}
$handler = New-Object System.Net.Http.HttpClientHandler
$handler.CookieContainer = $container
$client = New-Object System.Net.Http.HttpClient($handler)
$client.Timeout = [TimeSpan]::FromMinutes(10)

function Get-Page([string]$url) {
	$r = $client.GetAsync($url).Result
	return $r.Content.ReadAsStringAsync().Result
}

function Post-Multipart([hashtable]$fields, [byte[]]$slice) {
	$form = New-Object System.Net.Http.MultipartFormDataContent
	foreach ($k in $fields.Keys) {
		$form.Add((New-Object System.Net.Http.StringContent([string]$fields[$k])), $k)
	}
	if ($null -ne $slice) {
		$bytes = New-Object System.Net.Http.ByteArrayContent($slice, 0, $slice.Length)
		$bytes.Headers.ContentType = New-Object System.Net.Http.Headers.MediaTypeHeaderValue("application/octet-stream")
		$form.Add($bytes, "slice", "slice.bin")
	}
	$r = $client.PostAsync($ajax, $form).Result
	$text = $r.Content.ReadAsStringAsync().Result
	try { return $text | ConvertFrom-Json } catch { return [pscustomobject]@{ success = $false; data = [pscustomobject]@{ message = $text.Substring(0, [Math]::Min(300, $text.Length)) } } }
}

Write-Host "Logged in, loading admin page for a nonce..."
$page = Get-Page "$base/wp-admin/admin.php?page=unboundwp"
$m = [regex]::Match($page, '"nonce":"([^"]+)"')
if (-not $m.Success) { Write-Host "Could not find the nonce (login failed?)"; exit 1 }
$nonce = $m.Groups[1].Value

# --- Upload in 2 MB slices ------------------------------------------------
$name  = "e2e-upload-probe-" + (Get-Date -Format "HHmmss") + ".uwp"
$size  = (Get-Item $Archive).Length
$chunk = 2097152
$sent  = 0
$fails = 0
Write-Host ("Uploading {0} ({1:N1} MB) as {2} in {3} slices..." -f $Archive, ($size / 1MB), $name, [Math]::Ceiling($size / $chunk))
$fs = [System.IO.File]::OpenRead($Archive)
$buffer = New-Object byte[] $chunk
try {
	while ($sent -lt $size) {
		$want = [Math]::Min($chunk, $size - $sent)
		$read = $fs.Read($buffer, 0, $want)
		if ($read -le 0) { throw "read returned 0 at $sent" }
		$slice = New-Object byte[] $read
		[Array]::Copy($buffer, 0, $slice, 0, $read)
		$resp = Post-Multipart @{ action = 'uwp_upload_chunk'; nonce = $nonce; file_name = $name; offset = [string]$sent; total_size = [string]$size } $slice
		if (-not $resp.success) {
			$fails++
			if ($resp.data.shrink) { $chunk = [Math]::Max(262144, [Math]::Floor($chunk / 2)); Write-Host "Server asked for smaller slices -> $chunk"; $sent = 0; $fs.Position = 0; continue }
			if ($fails -gt 8) { throw "upload failed: $($resp.data.message)" }
			Write-Host "Retry ($fails): $($resp.data.message)"
			Start-Sleep -Milliseconds 800
			continue
		}
		$fails = 0
		if ($null -ne $resp.data.received) { $sent = [int]$resp.data.received }
		else { $sent += $read }
		$pct = [Math]::Round(100 * $sent / $size, 1)
		Write-Host -NoNewline ("`r  {0}% ({1:N0}/{2:N0} bytes)" -f $pct, $sent, $size)
	}
} finally {
	$fs.Close()
}
Write-Host ""

if (-not $resp.data.done) { Write-Host "Upload did not report done."; exit 1 }

$uploaded = Join-Path (Join-Path (Join-Path (Join-Path (Join-Path "C:\Users\Achi\Local Sites\thriftywedsites" "app") "public") "wp-content") "uploads") "unboundwp-temp\uploads\$name"
if (Test-Path $uploaded) {
	$h1 = (Get-FileHash -Algorithm SHA256 $Archive).Hash
	$h2 = (Get-FileHash -Algorithm SHA256 $uploaded).Hash
	Write-Host "Assembled file SHA256 match: $($h1 -eq $h2) ($($h1.Substring(0,16))...)"
	if ($h1 -ne $h2) { exit 1 }
} else {
	Write-Host "WARNING: uploaded file not found at $uploaded (cannot hash-compare)"
}

# --- Start restore, let the server chain it to completion -----------------
Write-Host "Starting restore of the uploaded archive..."
$start = Post-Multipart @{ action = 'uwp_import_start'; nonce = $nonce; archive = $name; password = ''; keep_core = ''; skip_plugins = ''; skip_replace = '' } $null
if (-not $start.success) { Write-Host "import_start failed: $($start.data.message)"; exit 1 }
$secret = $start.data.secret
Write-Host "job=$($start.data.job_id)"

$tick = Post-Multipart @{ action = 'uwp_import_tick'; nonce = ''; job_secret = $secret } $null
Write-Host "first tick: $($tick.data.message) (chained=$($tick.data.chained))"

$deadline = (Get-Date).AddMinutes(8)
$done = $null
while ((Get-Date) -lt $deadline) {
	Start-Sleep -Seconds 3
	$status = Post-Multipart @{ action = 'uwp_job_status'; job = 'import'; job_secret = $secret } $null
	if (-not $status.success) { Write-Host "status error: $($status.data.message)"; continue }
	if ($status.data.done) { $done = $status.data; break }
	if ($status.data.active) {
		Write-Host -NoNewline ("`r  {0}% {1}                    " -f $status.data.percent, $status.data.message)
	}
}
Write-Host ""
if ($null -eq $done) { Write-Host "Restore did not finish in time."; exit 1 }
Write-Host "DONE: $($done.message)"

# --- Adoption check -------------------------------------------------------
# A restore may replace the session table, so refresh the login cookie the
# same way a human would (log in again) before checking the admin page.
function New-Client {
	$cookieLines = & $php -c $ini (Join-Path $PSScriptRoot "make-cookie.php") 2>&1
	$container = New-Object System.Net.CookieContainer
	foreach ($line in $cookieLines) {
		if ($line -match '^wordpress_') {
			$p = $line.Trim().Split('=', 2)
			$container.Add((New-Object System.Net.Cookie($p[0], $p[1], "/", "thriftywedsites.local")))
		}
	}
	$handler = New-Object System.Net.Http.HttpClientHandler
	$handler.CookieContainer = $container
	$c = New-Object System.Net.Http.HttpClient($handler)
	$c.Timeout = [TimeSpan]::FromMinutes(10)
	return $c
}

$client = New-Client
$resp = $client.GetAsync("$base/wp-admin/admin.php?page=unboundwp-backups").Result
$backups = $resp.Content.ReadAsStringAsync().Result
$rendered = if ($backups -match 'UnboundWP: Backups') { 'rendered' } else { 'NOT rendered (login lost?)' }
Write-Host "Backups page: HTTP $([int]$resp.StatusCode), $rendered"
if ($backups -match [regex]::Escape($name)) {
	Write-Host "ADOPTED: the uploaded archive is listed on the Backups page."
} else {
	Write-Host "NOT ADOPTED: $name is missing from the Backups page."
	exit 1
}
Write-Host "UPLOAD E2E OK"
