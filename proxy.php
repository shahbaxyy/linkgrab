<?php
/**
 * Proxy: streams a Pinterest video file to the browser as a download.
 * This avoids CORS issues and gives the browser a proper filename.
 */

$url     = trim((string) ($_GET['url'] ?? ''));
$quality = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($_GET['quality'] ?? 'video'));

// ── Validate ──────────────────────────────────────────────────────────────────
if ($url === '') {
    http_response_code(400);
    exit('Missing url parameter.');
}

// Only allow Pinterest CDN URLs
if (!preg_match('#^https://v(?:ideo)?\.pinimg\.com/#i', $url)) {
    http_response_code(403);
    exit('Only Pinterest CDN URLs are allowed.');
}

// ── Determine filename ────────────────────────────────────────────────────────
$ext      = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'mp4';
$ext      = in_array(strtolower($ext), ['mp4', 'm3u8'], true) ? $ext : 'mp4';
$filename = 'pinterest-' . $quality . '-linkgrab.' . $ext;

// ── Stream ────────────────────────────────────────────────────────────────────
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 0,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                            . 'AppleWebKit/537.36 (KHTML, like Gecko) '
                            . 'Chrome/124.0.0.0 Safari/537.36',
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_WRITEFUNCTION  => function ($ch, $data) {
        echo $data;
        return strlen($data);
    },
    CURLOPT_HEADERFUNCTION => function ($ch, $header) {
        // Forward only content-related headers
        $lower = strtolower($header);
        if (str_starts_with($lower, 'content-type:')
            || str_starts_with($lower, 'content-length:')
        ) {
            header(rtrim($header));
        }
        return strlen($header);
    },
]);

header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

curl_exec($ch);

if (curl_errno($ch)) {
    // Headers may already be sent; log and exit
    error_log('LinkGrab proxy cURL error: ' . curl_error($ch));
}

curl_close($ch);
