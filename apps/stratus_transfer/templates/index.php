<?php
script('stratus_transfer','transfer');style('stratus_transfer','transfer');
?>
<main id="stratus-transfer">
  <header><p class="eyebrow">STRATUS-2</p><h1>Transfers</h1><p>Download large files straight from storage, with progress and the option to pause.</p></header>
  <p id="transfer-notice" role="status" aria-live="polite">Loading your files…</p>
  <section><h2>Your files</h2><p><button id="transfer-up">Up a folder</button> <span id="transfer-path">Home</span></p><div class="table-scroll"><table><thead><tr><th>Name</th><th>Size</th><th>Action</th></tr></thead><tbody id="transfer-files"></tbody></table></div>
    <p><a id="transfer-upload" href="#">Upload or manage files in Nextcloud</a></p><p class="muted">Uploads use Nextcloud to keep your quotas, versions and metadata consistent. Desktop, mobile and WebDAV transfers continue to work there.</p>
  </section>
  <section><h2>Downloads</h2><ul id="transfer-jobs"></ul><p class="muted">Resumable saving requires a supported browser and your permission to save a file. Other browsers use Nextcloud downloads.</p></section>
  <section><h2>Transfer savings</h2><p id="transfer-metrics"></p><p class="muted">These are browser-reported saved bytes from this page only. They exclude other Nextcloud traffic. Cost estimates use a configurable rate before free allowances and discounts.</p></section>
</main>
