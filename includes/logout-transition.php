<?php
/**
 * Shared, branded logout transition used by every authenticated shell.
 */
function renderLogoutTransition(): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $rendered = true;
    $logo_url = defined('SITE_URL') ? SITE_URL . 'photos/rms-logo.png' : '../../photos/rms-logo.png';
    ?>
<style>
  /* Layer scale: shells < mobile sidebar < logout transition (120). */
  .rms-logout-transition{position:fixed;inset:0;z-index:120;display:grid;place-items:center;padding:24px;overflow:hidden;background:#131d2e;color:#f5f7fa;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .32s ease,visibility .32s ease}.rms-logout-transition.is-active{opacity:1;visibility:visible;pointer-events:auto}.rms-logout-transition::before{content:'';position:absolute;inset:-18%;background:radial-gradient(circle at 72% 22%,rgba(210,162,72,.18),transparent 27%),radial-gradient(circle at 20% 82%,rgba(255,255,255,.055),transparent 30%);transform:scale(1.04);transition:transform 1s cubic-bezier(.16,1,.3,1)}.rms-logout-transition.is-active::before{transform:scale(1)}.rms-logout-grid{position:absolute;inset:0;opacity:.12;background-image:linear-gradient(rgba(255,255,255,.08) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.08) 1px,transparent 1px);background-size:72px 72px;mask-image:linear-gradient(to bottom,transparent,rgba(12,18,28,1) 24%,rgba(12,18,28,1) 76%,transparent)}.rms-logout-panel{position:relative;width:min(100%,470px);padding:42px 42px 38px;border:1px solid rgba(255,255,255,.1);border-radius:20px;background:rgba(25,36,55,.86);box-shadow:0 30px 90px rgba(5,12,23,.36),inset 0 1px 0 rgba(255,255,255,.06);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);transform:translateY(14px) scale(.985);opacity:0;transition:transform .45s cubic-bezier(.16,1,.3,1),opacity .3s ease}.rms-logout-transition.is-active .rms-logout-panel{transform:translateY(0) scale(1);opacity:1}.rms-logout-brand{display:flex;align-items:center;gap:13px;color:#c8d1df;font-size:11px;font-weight:650;letter-spacing:.08em;text-transform:uppercase}.rms-logout-logo-frame{position:relative;display:grid;place-items:center;width:62px;height:62px;margin:38px auto 27px;border-radius:18px;background:#f7f8fa;box-shadow:0 0 0 1px rgba(255,255,255,.16),0 13px 34px rgba(3,9,18,.28)}.rms-logout-logo-frame::before{content:'';position:absolute;inset:-12px;border:1px solid rgba(210,162,72,.55);border-top-color:transparent;border-radius:25px;animation:rmsLogoutOrbit 1.1s linear infinite}.rms-logout-logo-frame::after{content:'';position:absolute;inset:-21px;border:1px solid rgba(255,255,255,.09);border-radius:31px;animation:rmsLogoutPulse 1.4s ease-in-out infinite}.rms-logout-logo-frame img{display:block;width:42px;height:42px;object-fit:cover}.rms-logout-copy{text-align:center}.rms-logout-copy h2{margin:0;color:#f7f8fb;font-size:30px;line-height:1.05;letter-spacing:-.04em}.rms-logout-copy p{max-width:330px;margin:12px auto 0;color:#aeb9c9;font-size:13px;line-height:1.6}.rms-logout-progress{height:2px;margin-top:31px;overflow:hidden;background:rgba(255,255,255,.09)}.rms-logout-progress span{display:block;width:100%;height:100%;background:#d2a248;transform:translateX(-100%)}.rms-logout-transition.is-active .rms-logout-progress span{animation:rmsLogoutProgress .8s cubic-bezier(.4,0,.2,1) forwards}.rms-logout-caption{display:flex;justify-content:space-between;gap:16px;margin-top:10px;color:#7f8ba0;font:650 9px/1.4 ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.1em;text-transform:uppercase}.is-logging-out{overflow:hidden!important;cursor:wait}
  .rms-logout-page-exit{animation:rmsLogoutPageExit 1.4s cubic-bezier(.16,1,.3,1) both;pointer-events:none}.rms-logout-transition.is-completing{background:#0d1522}.rms-logout-transition.is-completing .rms-logout-panel{opacity:0;transform:translateY(-12px) scale(.975);transition-duration:.65s}.rms-logout-transition.is-completing .rms-logout-grid{opacity:0;transition:opacity .65s ease}
  @keyframes rmsLogoutOrbit{to{transform:rotate(360deg)}}@keyframes rmsLogoutPulse{0%,100%{opacity:.35;transform:scale(.96)}50%{opacity:1;transform:scale(1.04)}}@keyframes rmsLogoutProgress{to{transform:translateX(0)}}@keyframes rmsLogoutPageExit{to{opacity:.28;filter:blur(7px);transform:scale(.985)}}
  /* One exact five-second handoff: entrance, confirmation sequence, then redirect. */
  .rms-logout-transition{transition-duration:.55s}.rms-logout-transition::before{transition-duration:3.2s}.rms-logout-panel{overflow:hidden;transition-duration:.8s,.6s}.rms-logout-logo-frame::before{animation-duration:2.2s}.rms-logout-logo-frame::after{animation-duration:2.6s}.rms-logout-transition.is-active .rms-logout-progress span{animation-duration:4.8s}
  .rms-logout-panel::before{content:'';position:absolute;inset:0;pointer-events:none;background:linear-gradient(110deg,transparent 25%,rgba(255,255,255,.075) 48%,transparent 70%);transform:translateX(-115%)}.rms-logout-transition.is-active .rms-logout-panel::before{animation:rmsLogoutSweep 2.6s .45s cubic-bezier(.16,1,.3,1) forwards}.rms-logout-brand,.rms-logout-logo-frame,.rms-logout-copy h2,.rms-logout-copy p,.rms-logout-caption{opacity:0;transform:translateY(10px)}.rms-logout-transition.is-active .rms-logout-brand{animation:rmsLogoutReveal .45s .16s ease forwards}.rms-logout-transition.is-active .rms-logout-logo-frame{animation:rmsLogoutMarkReveal .78s .3s cubic-bezier(.16,1,.3,1) forwards}.rms-logout-transition.is-active .rms-logout-copy h2{animation:rmsLogoutReveal .5s .7s ease forwards}.rms-logout-transition.is-active .rms-logout-copy p{animation:rmsLogoutReveal .5s .88s ease forwards}.rms-logout-transition.is-active .rms-logout-caption{animation:rmsLogoutReveal .45s 1.04s ease forwards}.rms-logout-transition.is-active .rms-logout-logo-frame img{animation:rmsLogoutLogoBreath 2.2s 1s ease-in-out infinite}.rms-logout-transition.is-active .rms-logout-grid{animation:rmsLogoutGridDrift 5s linear forwards}.rms-logout-progress span{position:relative;overflow:hidden}.rms-logout-progress span::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.72),transparent);transform:translateX(-120%)}.rms-logout-transition.is-active .rms-logout-progress span::after{animation:rmsLogoutShimmer 1.25s 1.1s ease-in-out 3}.rms-logout-dots{display:inline-flex;width:20px;justify-content:flex-start}.rms-logout-dots i{font-style:normal;animation:rmsLogoutDot 1.2s ease-in-out infinite}.rms-logout-dots i:nth-child(2){animation-delay:.16s}.rms-logout-dots i:nth-child(3){animation-delay:.32s}
  .rms-logout-transition:not(.is-active) .rms-logout-logo-frame::before,.rms-logout-transition:not(.is-active) .rms-logout-logo-frame::after,.rms-logout-transition:not(.is-active) .rms-logout-dots i{animation-play-state:paused}
  @keyframes rmsLogoutReveal{to{opacity:1;transform:translateY(0)}}@keyframes rmsLogoutMarkReveal{0%{opacity:0;transform:translateY(15px) scale(.88)}65%{opacity:1;transform:translateY(-2px) scale(1.035)}100%{opacity:1;transform:translateY(0) scale(1)}}@keyframes rmsLogoutSweep{to{transform:translateX(115%)}}@keyframes rmsLogoutLogoBreath{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(.94);opacity:.78}}@keyframes rmsLogoutGridDrift{to{transform:translate3d(28px,18px,0)}}@keyframes rmsLogoutShimmer{to{transform:translateX(120%)}}@keyframes rmsLogoutDot{0%,65%,100%{opacity:.25;transform:translateY(0)}30%{opacity:1;transform:translateY(-2px)}}
  @media(max-width:520px){.rms-logout-panel{padding:30px 24px 27px}.rms-logout-brand{font-size:9px}.rms-logout-logo-frame{margin-top:34px}.rms-logout-copy h2{font-size:27px}}
  @media(prefers-reduced-transparency:reduce){.rms-logout-panel{background:#192437;backdrop-filter:none;-webkit-backdrop-filter:none}}
  @media(prefers-reduced-motion:reduce){.rms-logout-transition,.rms-logout-transition::before,.rms-logout-panel{transition-duration:.01ms}.rms-logout-logo-frame::before,.rms-logout-logo-frame::after,.rms-logout-progress span,.rms-logout-panel::before,.rms-logout-brand,.rms-logout-logo-frame,.rms-logout-copy h2,.rms-logout-copy p,.rms-logout-caption,.rms-logout-logo-frame img,.rms-logout-grid,.rms-logout-dots i,.rms-logout-page-exit{animation:none!important}.rms-logout-progress span{transform:translateX(0)}.rms-logout-brand,.rms-logout-logo-frame,.rms-logout-copy h2,.rms-logout-copy p,.rms-logout-caption{opacity:1;transform:none}}
</style>
<div class="rms-logout-transition" id="rms-logout-transition" aria-hidden="true">
  <div class="rms-logout-grid" aria-hidden="true"></div>
  <section class="rms-logout-panel" role="status" aria-live="polite" aria-label="Signing out">
    <div class="rms-logout-brand"><span>RMS</span><span>Research Management System</span></div>
    <div class="rms-logout-logo-frame"><img src="<?php echo htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8'); ?>" alt="RMS logo"></div>
    <div class="rms-logout-copy"><h2>Signing you out<span class="rms-logout-dots" aria-hidden="true"><i>.</i><i>.</i><i>.</i></span></h2><p>Protecting your account before returning to the login page.</p></div>
    <div class="rms-logout-progress" aria-hidden="true"><span></span></div>
    <div class="rms-logout-caption"><span id="rms-logout-status">Closing session</span><span>Please wait</span></div>
  </section>
</div>
<script>
(() => {
  const overlay = document.getElementById('rms-logout-transition');
  const status = document.getElementById('rms-logout-status');
  if (!overlay) return;
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let leaving = false;
  let timers = [];
  const pageElements = Array.from(document.body.children).filter((element) =>
    element !== overlay && !['SCRIPT', 'STYLE'].includes(element.tagName)
  );
  document.querySelectorAll('a[href]').forEach((link) => {
    let destination;
    try {
      destination = new URL(link.href, window.location.href);
    } catch (error) {
      return;
    }
    if (!destination.pathname.endsWith('/logout.php')) return;
    link.addEventListener('click', (event) => {
      if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      if (leaving) return;
      leaving = true;
      overlay.classList.add('is-active');
      overlay.setAttribute('aria-hidden', 'false');
      document.body.classList.add('is-logging-out');
      pageElements.forEach((element) => element.classList.add('rms-logout-page-exit'));
      if (!reducedMotion && status) {
        timers.push(window.setTimeout(() => { status.textContent = 'Protecting account'; }, 1650));
        timers.push(window.setTimeout(() => { status.textContent = 'Returning to login'; }, 3500));
        timers.push(window.setTimeout(() => { overlay.classList.add('is-completing'); }, 4250));
      }
      timers.push(window.setTimeout(() => window.location.assign(destination.href), reducedMotion ? 80 : 5000));
    });
  });
  window.addEventListener('pageshow', () => {
    timers.forEach((timer) => window.clearTimeout(timer));
    timers = [];
    leaving = false;
    overlay.classList.remove('is-active', 'is-completing');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('is-logging-out');
    pageElements.forEach((element) => element.classList.remove('rms-logout-page-exit'));
    if (status) status.textContent = 'Closing session';
  });
})();
</script>
    <?php
}
