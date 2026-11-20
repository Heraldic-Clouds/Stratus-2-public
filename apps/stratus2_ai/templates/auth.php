<?php script('stratus2_ai',\OCA\Stratus2AI\Service\Assets::name('js','auth')); style('stratus2_ai',\OCA\Stratus2AI\Service\Assets::name('css','main')); ?>
<main id="stratus2-auth"><span class="eyebrow">STRATUS-2</span><h1><?php p($_['slogan']); ?></h1>
  <div class="auth-layout">
    <aside class="auth-summary" aria-labelledby="auth-summary-title">
      <h2 id="auth-summary-title">Finally, the cloud that doesn't steal your files!</h2>
      <p>Stratus-2 stores your data across drives around the world on Storj's decentralized storage network.</p>
      <p>Armed with AI for file organization.</p>
      <p>Open-source and built without third-party analytics trackers. This is cloud storage without cross-site tracking.</p>
    </aside>
    <section class="auth-access" aria-label="Sign in">
      <p>Sign in to your Stratus-2 account.</p>
      <form id="auth-form"><label for="email">Email</label><input id="email" type="email" autocomplete="email" required><label for="password">Password</label><input id="password" type="password" minlength="8" autocomplete="current-password" required><button class="primary" type="submit">Sign in</button><button id="signup" type="button">Create account</button><button id="reset" type="button">Reset password</button></form>
      <section id="oauth-consent" hidden><h2>Connect your account</h2><p id="consent-details"></p><button id="allow" class="primary">Allow Stratus-2 to sign me in</button><button id="deny">Decline</button></section>
      <button id="signout" hidden>Sign out of identity account</button><p id="auth-notice" role="status" aria-live="polite"></p>
      <a id="restart-signin" hidden>Start a fresh sign-in</a>
    </section>
  </div>
  <section class="auth-details" aria-label="About Stratus-2">
    <ul>
      <li>Decentralized storage powered by Storj</li>
      <li>Source code is available on <a href="https://github.com/Heraldic-Clouds/Stratus-2-public" target="_blank" rel="noopener noreferrer">GitHub</a></li>
      <li>Made by <a href="https://www.heraldic.cloud" target="_blank" rel="noopener noreferrer">Heraldic</a></li>
    </ul>
  </section>
</main>
