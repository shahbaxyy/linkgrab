<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Free Pinterest video downloader – paste any Pinterest pin URL and download the video in the best available quality." />
  <title>Pinterest Video Downloader – LinkGrab</title>
  <link rel="stylesheet" href="assets/style.css" />
</head>
<body>

<!-- ── Header ──────────────────────────────────────────────────────────── -->
<header>
  <!-- Pinterest "P" logo (SVG, no external dependency) -->
  <svg width="36" height="36" viewBox="0 0 24 24" fill="white" aria-hidden="true">
    <path d="M12 0C5.373 0 0 5.373 0 12c0 5.084 3.163 9.426 7.627 11.174-.105-.949-.2-2.405.042-3.441.218-.937 1.407-5.965 1.407-5.965s-.359-.719-.359-1.782c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738a.36.36 0 0 1 .083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.632-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0z"/>
  </svg>
  <div>
    <h1>Pinterest Video Downloader</h1>
    <p>Download Pinterest videos in HD – free &amp; fast</p>
  </div>
</header>

<!-- ── Main ────────────────────────────────────────────────────────────── -->
<main>
  <div class="card">

    <!-- Input form -->
    <form id="dlForm" autocomplete="off">
      <label for="pinUrl" style="font-weight:600;font-size:.95rem;">
        Paste Pinterest pin URL
      </label>
      <div class="input-row" style="margin-top:10px;">
        <input
          type="url"
          id="pinUrl"
          name="url"
          placeholder="https://www.pinterest.com/pin/…"
          required
          aria-label="Pinterest pin URL"
        />
        <button type="submit" class="btn" id="submitBtn">Download</button>
      </div>
    </form>

    <!-- Status message -->
    <p id="status" role="status" aria-live="polite"></p>

    <!-- Results -->
    <div id="results" aria-live="polite"></div>

    <!-- How-to section -->
    <section class="how-to" aria-label="How to download Pinterest videos">
      <h2>How to download Pinterest videos</h2>
      <div class="steps">
        <div class="step">
          <div class="step-num" aria-hidden="true">1</div>
          <p>Open Pinterest and find the video pin you want to save.</p>
        </div>
        <div class="step">
          <div class="step-num" aria-hidden="true">2</div>
          <p>Copy the pin URL from your browser's address bar or tap <strong>Share → Copy link</strong> on mobile.</p>
        </div>
        <div class="step">
          <div class="step-num" aria-hidden="true">3</div>
          <p>Paste the URL above and click <strong>Download</strong>.</p>
        </div>
        <div class="step">
          <div class="step-num" aria-hidden="true">4</div>
          <p>Choose your preferred quality and save the video to your device.</p>
        </div>
      </div>
    </section>

  </div>
</main>

<!-- ── Footer ──────────────────────────────────────────────────────────── -->
<footer>
  &copy; <?php echo date('Y'); ?> <a href="https://linkgrab.io/" style="color:inherit;">LinkGrab.io</a> –
  This tool is for personal use only. Respect copyright and Pinterest's
  <a href="https://policy.pinterest.com/en/terms-of-service" target="_blank" rel="noopener" style="color:inherit;">Terms of Service</a>.
</footer>

<script>
(function () {
  'use strict';

  const form      = document.getElementById('dlForm');
  const urlInput  = document.getElementById('pinUrl');
  const submitBtn = document.getElementById('submitBtn');
  const statusEl  = document.getElementById('status');
  const resultsEl = document.getElementById('results');

  function setStatus(msg, isError = false) {
    statusEl.textContent  = msg;
    statusEl.className    = isError ? 'error' : '';
  }

  function clearResults() {
    resultsEl.innerHTML = '';
  }

  function renderResults(data) {
    clearResults();

    const titleEl = document.createElement('p');
    titleEl.className   = 'result-title';
    titleEl.textContent = data.title || 'Pinterest Video';
    resultsEl.appendChild(titleEl);

    const list = document.createElement('div');
    list.className = 'video-options';

    data.videos.forEach(function (video) {
      const item = document.createElement('div');
      item.className = 'video-item';

      const qual = document.createElement('span');
      qual.className   = 'quality';
      qual.textContent = video.quality;

      const urlSpan = document.createElement('span');
      urlSpan.className   = 'video-url';
      urlSpan.title       = video.url;
      urlSpan.textContent = video.url;

      const dlLink = document.createElement('a');
      dlLink.className  = 'dl-btn';
      dlLink.textContent = 'Download';
      // Route through proxy.php so the browser gets a proper download
      dlLink.href     = 'proxy.php?url=' + encodeURIComponent(video.url)
                      + '&quality=' + encodeURIComponent(video.quality);
      dlLink.download = '';
      dlLink.rel      = 'noopener';

      item.appendChild(qual);
      item.appendChild(urlSpan);
      item.appendChild(dlLink);
      list.appendChild(item);
    });

    resultsEl.appendChild(list);
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    const url = urlInput.value.trim();
    if (!url) return;

    clearResults();
    setStatus('Fetching video info…');
    submitBtn.disabled = true;

    fetch('downloader.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ url: url }),
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.success) {
          setStatus('');
          renderResults(data);
        } else {
          setStatus(data.error || 'Something went wrong.', true);
        }
      })
      .catch(function () {
        setStatus('Network error. Please check your connection and try again.', true);
      })
      .finally(function () {
        submitBtn.disabled = false;
      });
  });
})();
</script>
</body>
</html>
