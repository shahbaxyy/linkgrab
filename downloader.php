<?php
/**
 * Pinterest Video Downloader – backend handler
 * Accepts a Pinterest pin URL, fetches the page, extracts the video URL,
 * and returns a JSON response.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ── helpers ──────────────────────────────────────────────────────────────────

function json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function fetch_url(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                                . 'AppleWebKit/537.36 (KHTML, like Gecko) '
                                . 'Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept-Language: en-US,en;q=0.9',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        json_error('Failed to fetch the URL: ' . $error);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        json_error("Pinterest returned HTTP {$httpCode}. Make sure the pin is public.", 502);
    }

    return (string) $body;
}

/**
 * Extract all candidate video URLs from the Pinterest page HTML.
 * Pinterest embeds pin data in a JSON blob assigned to window.__PWS_DATA__
 * (or a <script id="__PWS_DATA__"> tag in newer layouts).
 * We also fall back to scanning for video.pinimg.com URLs directly.
 *
 * @return array{title:string, videos:array<array{quality:string,url:string}>}
 */
function extract_pin_data(string $html): array
{
    $title  = '';
    $videos = [];

    // ── 1. Try <script id="__PWS_DATA__"> JSON blob ──────────────────────────
    if (preg_match('/<script[^>]+id=["\']__PWS_DATA__["\'][^>]*>(.*?)<\/script>/s', $html, $m)) {
        $data = json_decode($m[1], true);
        if (is_array($data)) {
            collect_videos_from_array($data, $videos);
            $title = extract_title_from_array($data);
        }
    }

    // ── 2. Try window.__PWS_DATA__ = {...} assignment ─────────────────────────
    if (empty($videos) && preg_match('/window\.__PWS_DATA__\s*=\s*(\{.+?\});\s*<\/script>/s', $html, $m)) {
        $data = json_decode($m[1], true);
        if (is_array($data)) {
            collect_videos_from_array($data, $videos);
            if ($title === '') {
                $title = extract_title_from_array($data);
            }
        }
    }

    // ── 3. Fallback: regex scan for video.pinimg.com URLs ────────────────────
    if (empty($videos)) {
        preg_match_all(
            '#https://v(?:ideo)?\.pinimg\.com/[^\s"\'\\\\]+\.(?:mp4|m3u8)[^\s"\'\\\\]*#',
            $html,
            $matches
        );
        foreach (array_unique($matches[0]) as $url) {
            $url      = html_entity_decode($url);
            $quality  = guess_quality_from_url($url);
            $videos[] = ['quality' => $quality, 'url' => $url];
        }
    }

    // ── 4. Page <title> fallback ──────────────────────────────────────────────
    if ($title === '' && preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
        $title = html_entity_decode(trim($m[1]));
    }

    usort($videos, fn($a, $b) => quality_rank($b['quality']) - quality_rank($a['quality']));

    return ['title' => $title, 'videos' => $videos];
}

// ── recursive helpers ─────────────────────────────────────────────────────────

function collect_videos_from_array(array $data, array &$videos, int $depth = 0): void
{
    if ($depth > 30) {
        return;
    }

    foreach ($data as $key => $value) {
        // Pinterest stores video transcodes under keys like "video_list" or "videos"
        if (($key === 'video_list' || $key === 'videos') && is_array($value)) {
            foreach ($value as $qualityKey => $entry) {
                if (isset($entry['url'])) {
                    $url = $entry['url'];
                    if (is_pinterest_video_url($url)) {
                        $quality          = $entry['width'] ?? $qualityKey;
                        $videos[(string)$url] = [
                            'quality' => label_quality($quality, $entry),
                            'url'     => $url,
                        ];
                    }
                }
            }
        }

        // Direct URL field that looks like a pinimg video
        if ($key === 'url' && is_string($value) && is_pinterest_video_url($value)) {
            $videos[(string)$value] = ['quality' => 'SD', 'url' => $value];
        }

        if (is_array($value)) {
            collect_videos_from_array($value, $videos, $depth + 1);
        }
    }
}

function extract_title_from_array(array $data, int $depth = 0): string
{
    if ($depth > 20) {
        return '';
    }
    foreach ($data as $key => $value) {
        if (in_array($key, ['title', 'seo_title', 'grid_title', 'description'], true)
            && is_string($value)
            && $value !== ''
        ) {
            return $value;
        }
        if (is_array($value)) {
            $found = extract_title_from_array($value, $depth + 1);
            if ($found !== '') {
                return $found;
            }
        }
    }
    return '';
}

// ── quality helpers ───────────────────────────────────────────────────────────

function is_pinterest_video_url(string $url): bool
{
    return (bool) preg_match('#^https?://v(?:ideo)?\.pinimg\.com/.+\.(mp4|m3u8)#i', $url);
}

function label_quality($widthOrKey, array $entry): string
{
    $width = (int) ($entry['width'] ?? $widthOrKey ?? 0);

    if ($width >= 1920 || stripos((string)$widthOrKey, 'V_1080P') !== false) {
        return '1080p';
    }
    if ($width >= 1280 || stripos((string)$widthOrKey, 'V_720P') !== false) {
        return '720p';
    }
    if ($width >= 854  || stripos((string)$widthOrKey, 'V_480P') !== false) {
        return '480p';
    }
    if ($width >= 640  || stripos((string)$widthOrKey, 'V_360P') !== false) {
        return '360p';
    }
    if (stripos((string)$widthOrKey, 'HLS')  !== false) {
        return 'HLS';
    }
    return 'SD';
}

function guess_quality_from_url(string $url): string
{
    foreach (['1080', '720', '480', '360', '240'] as $p) {
        if (str_contains($url, $p)) {
            return $p . 'p';
        }
    }
    if (str_contains($url, '.m3u8')) {
        return 'HLS';
    }
    return 'SD';
}

function quality_rank(string $quality): int
{
    return match (true) {
        str_contains($quality, '1080') => 5,
        str_contains($quality, '720')  => 4,
        str_contains($quality, '480')  => 3,
        str_contains($quality, '360')  => 2,
        str_contains($quality, 'HLS')  => 1,
        default                        => 0,
    };
}

// ── main ──────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Only POST requests are accepted.', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$url   = trim((string) ($input['url'] ?? $_POST['url'] ?? ''));

if ($url === '') {
    json_error('Please provide a Pinterest URL.');
}

// Basic validation – accept pinterest.com and pin.it shortlinks
if (!preg_match('#^https?://(www\.)?(pinterest\.[a-z]{2,}|pin\.it)/#i', $url)) {
    json_error('Invalid URL. Please provide a valid Pinterest pin URL.');
}

$html    = fetch_url($url);
$pinData = extract_pin_data($html);

if (empty($pinData['videos'])) {
    json_error('No video found on this pin. The pin may contain only an image, or it may be private.', 404);
}

echo json_encode([
    'success' => true,
    'title'   => $pinData['title'],
    'videos'  => array_values($pinData['videos']),
]);
