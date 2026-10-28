/* ══════════════════════════════════════════════════════════════════════════════
   GOOGLE ANALYTICS 4 · Services\GoogleAnalytics · layout/shell.twig
   ══════════════════════════════════════════════════════════════════════════════

   Loaded only when the operator has switched Google Analytics on AND this visitor has said
   yes to Analytics (the server decides; this file never asks). So consent is granted for
   measurement and denied for everything advertising.

   What Google is told is cut down first. gtag.js reports the full address and referrer by
   default; links here carry passes and sign-in tokens, so the page is the server's redacted
   path (data-ga-path, utm tags kept) and the referrer is its origin only. The cookies are
   host-only (cookie_domain 'none') so that a "no" can delete them from the server.
   ══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var me = document.currentScript;
  var id = me && me.getAttribute('data-ga-id');
  if (!id) return;

  window.dataLayer = window.dataLayer || [];
  function gtag() { window.dataLayer.push(arguments); }

  gtag('consent', 'default', {
    analytics_storage: 'granted',
    ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied'
  });
  gtag('js', new Date());

  var ref = '';
  try { if (document.referrer) ref = new URL(document.referrer).origin + '/'; } catch (e) {}

  gtag('config', id, {
    page_location: location.origin + (me.getAttribute('data-ga-path') || '/'),
    page_referrer: ref,
    cookie_domain: 'none',
    cookie_flags: 'SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : ''),
    allow_google_signals: false,
    allow_ad_personalization_signals: false
  });
})();
