<?php
script('stratus2_ai',\OCA\Stratus2AI\Service\Assets::name('js','organizer'));
script('stratus2_ai',\OCA\Stratus2AI\Service\Assets::name('js','photos'));
style('stratus2_ai',\OCA\Stratus2AI\Service\Assets::name('css','main'));
?>
<main id="stratus2-organizer">
  <header><span class="eyebrow">STRATUS-2</span><h1>A little order. A lot more space.</h1><p>Turn a folder of files into a collection you can find your way around.</p></header>
  <nav class="actions" aria-label="AI tools"><button id="mode-organize" type="button" aria-pressed="true">Organize files</button><button id="mode-photo" type="button" aria-pressed="false">Photo enhancement</button></nav>
  <section id="photo-panel" hidden><h2>Improve and organize a folder of photos</h2><p>Describe which photos to change, how to change them, and where to put the results and originals.</p><p id="photo-access" role="status">Checking your subscription…</p></section>
  <div id="organize-panel"><form id="analyze-form">
    <label id="folder-label" for="folder">Folder to organize</label><div class="folder-row"><input id="folder" placeholder="Photos/Camera Uploads" maxlength="1000"><button type="button" id="browse">Browse folders</button></div>
    <label id="prompt-label" for="prompt">How would you like things organized?</label><textarea id="prompt" rows="10" maxlength="4000">Organize my files intelligently.
Put photographs in Photos, videos in Videos, documents in Documents, graphics in Graphics, memes in Memes and miscellaneous files in Other.
Detect blurry photographs and exact duplicates. Move them to to_delete/Blurry or to_delete/Duplicates. Flag similar photographs for review.
Give every photograph a descriptive lowercase snake_case filename, such as brown_dog_sleeping_on_sofa.jpg. Use recognizable subjects, actions, objects and locations. Do not invent people's identities or locations. Preserve extensions and metadata. Avoid filename collisions. Never permanently delete files.</textarea>
    <p class="cost-note">Review the proposed actions before applying them.</p>
    <label class="consent"><input id="consent" type="checkbox" required> I agree to send image previews, and for photo edits the full photos, with these instructions to OpenAI.</label>
    <button id="analyze-submit" class="primary" type="submit">Start analysis</button>
  </form>
  <section id="job-section" hidden><h2>Your organization preview</h2><p id="job-status" role="status" aria-live="polite"></p><progress id="progress" value="0" max="1"></progress>
    <div class="table-scroll"><table><thead><tr><th>Current location</th><th>Proposed location</th><th>Reason</th></tr></thead><tbody id="changes"></tbody></table></div>
    <div class="actions"><button id="apply" class="primary" disabled>Approve and apply</button><button id="cancel">Cancel</button><button id="undo" disabled>Undo organization</button></div>
    <p>Review blur and similarity suggestions carefully. Moves keep your files; nothing is permanently deleted.</p>
  </section>
  <p id="notice" role="alert"></p><section><h2>Job history</h2><ul id="history"></ul></section>
  </div>
  <footer><h2>Make room for more.</h2><p>100 GB for $10/month. Add 100 GB for $2/month, up to 10 TB total. Photo improvement is included for paid accounts, with no AI usage caps.</p>
    <p id="billing-status" role="status"></p><label for="storage-blocks">Additional 100 GB blocks</label><input id="storage-blocks" type="number" min="0" max="99" step="1" value="0"><p id="storage-summary">100 GB · $10/month</p>
    <div class="actions"><button id="checkout">Upgrade storage</button><button id="storage-change" hidden>Preview storage change</button><button id="portal">Manage subscription</button></div>
    <p>Storage sizes use decimal GB/TB. Lowering your allowance keeps your files, but blocks uploads while over quota. Storage changes are prorated; review the charge before confirming.</p>
  </footer>
</main>
