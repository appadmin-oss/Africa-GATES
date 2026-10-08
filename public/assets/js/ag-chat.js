/* Shared admin chat client for the console copilot — used by both the full-page
 * assistant (templates/admin/assistant.twig) and the ambient floating copilot
 * (templates/admin/partials/copilot.twig). One implementation of the
 * persist/scroll/send loop, parameterised per surface. Registered as an Alpine
 * component factory on window so templates use x-data="agChat({...})". */
window.agChat = function (opts) {
  opts = opts || {};
  // No default key. Both callers name theirs (`ag-copilot`, `ag-asst`), and a fallback
  // nobody uses was a storage key the published cookie list would have to carry for a
  // write that never happens (Support\CookieRegistry, CookieRegistryTest). Unnamed, a
  // conversation is simply not kept between pages.
  var STORAGE  = opts.storageKey || '';
  var LOG_ID   = opts.logId || 'agChatLog';
  var FOCUS_ID = opts.focusId || null;
  var CSRF     = opts.csrf || '';
  var ENDPOINT = opts.endpoint || '/admin/assistant/chat';

  return {
    open: !!opts.startOpen,
    msgs: [], draft: '', busy: false, error: '',

    // Full-page surface calls init() via x-init; the floating one loads on open.
    init() { this.load(); this.$nextTick(() => this.scroll()); },
    load() { if (!STORAGE) return; try { this.msgs = JSON.parse(sessionStorage.getItem(STORAGE) || '[]').slice(-30); } catch (e) {} },
    toggle() {
      this.open = !this.open;
      if (this.open) {
        this.load();
        this.$nextTick(() => { this.scroll(); if (FOCUS_ID) { var el = document.getElementById(FOCUS_ID); if (el) el.focus(); } });
      }
    },
    persist() { if (!STORAGE) return; try { sessionStorage.setItem(STORAGE, JSON.stringify(this.msgs.slice(-30))); } catch (e) {} },
    scroll() { var el = document.getElementById(LOG_ID); if (el) el.scrollTop = el.scrollHeight; },

    async send() {
      var text = (this.draft || '').trim();
      if (!text || this.busy) return;
      this.error = ''; this.msgs.push({ role: 'user', text }); this.draft = ''; this.busy = true;
      this.$nextTick(() => this.scroll());
      try {
        var r = await fetch(ENDPOINT, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': ((document.querySelector('meta[name="csrf-token"], meta[name="ag-csrf"]') || {}).content || CSRF) },
          body: JSON.stringify({ message: text, history: this.msgs.slice(0, -1).slice(-10) })
        });
        var j = await r.json();
        /* `ran`: the checks the assistant ran to answer — shown, so an answer can be traced
           to its evidence. `actions`: console pages it offers, held to /admin paths here as
           well as on the server. */
        if (j && j.ok && j.reply) {
          var acts = (j.actions || []).filter(function (a) { return a && /^\/admin(?:[\/?#]|$)/.test(String(a.url || '')) && a.label; }).slice(0, 2);
          this.msgs.push({ role: 'assistant', text: j.reply, ran: (j.ran || []).slice(0, 6), actions: acts });
        }
        else { this.error = (j && j.error) || 'The assistant did not answer — try again.'; }
      } catch (e) { this.error = 'Network error — try again.'; }
      finally { this.busy = false; this.persist(); this.$nextTick(() => this.scroll()); }
    }
  };
};
