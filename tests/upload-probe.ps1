# Upload-only probe: simulates the browser's resumable uploader.
#
# 1. Logs in with a generated admin cookie.
# 2. Asks uwp_upload_status how many bytes the server already has.
# 3. Uploads the remaining bytes in 8 MB slices (halving on 413/discard).
# 4. Retries gateway errors (502/503/504) instead of aborting.
# 5. Compares the assembled file's SHA-256 with the source.
#
# Usage: powershell -File tests/upload-probe.ps1 -Archive <file.uwp> [-Name <server-name.uwp>]

param(
	[string]$Archive,
	[string]$Name = ""
)

$ErrorActionPreference = "Stop"
Add-Type -AssemblyName System.Net.Http | Out-Null
$php  = "C:\Users\Achi\AppData\Local\Programs\local\resources\extraResources\lightning-services\php-8.1.23+0\bin\win32\php.exe"
$ini  = Join-Path $PSScriptRoot "php.ini"
$base = "http://thriftywedsites.local"
$ajax = "$base/wp-admin/admin-ajax.php"

if (-not $Archive -or -not (Test-Path $Archive)) {
	Write-Host "Archive not found: $Archive"
	exit 1
}
if ($Name -eq "") { $Name = [System.IO.Path]::GetFileName($Archive) }

function New-LoginClient {
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
	$status = [int]$r.StatusCode
	try {
		$j = $text | ConvertFrom-Json
		$j | Add-Member -NotePropertyName _status -NotePropertyValue $status -Force
		return $j
	} catch {
		return [pscustomobject]@{ success = $false; _status = $status; _html = $true; data = [pscustomobject]@{ message = $text.Substring(0, [Math]::Min(200, $text.Length)) } }
	}
}

$client = New-LoginClient
$page = $client.GetStringAsync("$base/wp-admin/admin.php?page=unboundwp").Result
$m = [regex]::Match($page, '"nonce":"([^"]+)"')
if (-not $m.Success) { Write-Host "Login/nonce failed."; exit 1 }
$nonce = $m.Groups[1].Value

$size = (Get-Item $Archive).Length
$sent = 0
$status = Post-Multipart @{ action = 'uwp_upload_status'; nonce = $nonce; file_name = $Name; total_size = [string]$size } $null
if ($status.success) {
	$sent = [int]$status.data.received
	Write-Host "Server already has $sent of $size bytes for $Name (archive_ok=$($status.data.archive_ok))."
	if ($sent -eq $size -and $status.data.archive_ok) {
		Write-Host "Upload already complete; nothing to send."
	}
} else {
	Write-Host "Status endpoint error: $($status.data.message)"
}

$chunk = 8 * 1024 * 1024
$minChunk = 256 * 1024
$fails = 0
$maxFails = 40
$startBytes = $sent

if ($sent -lt $size) {
	$fs = [System.IO.File]::OpenRead($Archive)
	$buffer = New-Object byte[] $chunk
	$started = Get-Date
	try {
		while ($sent -lt $size) {
			$want = [Math]::Min($chunk, $size - $sent)
			$fs.Position = $sent
			$read = $fs.Read($buffer, 0, $want)
			if ($read -le 0) { throw "read returned 0 at $sent" }
			$slice = New-Object byte[] $read
			[Array]::Copy($buffer, 0, $slice, 0, $read)

			$resp = Post-Multipart @{ action = 'uwp_upload_chunk'; nonce = $nonce; file_name = $Name; offset = [string]$sent; total_size = [string]$size } $slice
			if (-not $resp.success) {
				$fails++
				if ($resp._status -eq 413 -or ($resp._html -and $resp._status -lt 500)) {
					if ($chunk -le $minChunk) { throw "server keeps rejecting $chunk-byte slices: $($resp.data.message)" }
					$chunk = [Math]::Max($minChunk, [Math]::Floor($chunk / 2))
					$fails = 0
					Write-Host "Shrinking slices to $([Math]::Round($chunk/1KB)) KB"
					continue
				}
				if ($fails -gt $maxFails) { throw "too many failures: $($resp.data.message) (HTTP $($resp._status))" }
				Write-Host "Retry ${fails}/${maxFails} at $sent (HTTP $($resp._status)): $($resp.data.message)"
				Start-Sleep -Milliseconds ([Math]::Min(8000, 500 * $fails))
				continue
			}
			$fails = 0
			if ($null -ne $resp.data.received) { $sent = [int]$resp.data.received } else { $sent += $read }
			$pct = [Math]::Round(100 * $sent / $size, 1)
			$elapsed = ((Get-Date) - $started).TotalSeconds
			Write-Host -NoNewline ("`r  {0}% ({1:N0}/{2:N0} bytes, {3:N1} MB/s)   " -f $pct, $sent, $size, (($sent - $startBytes) / 1MB / [Math]::Max(0.1,$elapsed)))
			if ($resp.data.done) { break }
		}
	} finally {
		$fs.Close()
	}
	Write-Host ""
}

$uploaded = Join-Path (Join-Path (Join-Path (Join-Path (Join-Path "C:\Users\Achi\Local Sites\thriftywedsites" "app") "public") "wp-content") "uploads") "unboundwp-temp\uploads\$Name"
if (-not (Test-Path $uploaded)) { Write-Host "Uploaded file missing: $uploaded"; exit 1 }
$h1 = (Get-FileHash -Algorithm SHA256 $Archive).Hash
$h2 = (Get-FileHash -Algorithm SHA256 $uploaded).Hash
Write-Host "SHA256 match: $($h1 -eq $h2) ($($h1.Substring(0,16))...)"
if ($h1 -ne $h2) { exit 1 }
Write-Host "UPLOAD PROBE OK"
