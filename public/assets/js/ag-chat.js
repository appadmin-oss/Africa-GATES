/* Shared admin chat client for the console copilot — used by both the full-page
 * assistant (templates/admin/assistant.twig) and the ambient floating copilot
 * (templates/admin/partials/copilot.twig). One implementation of the
 * persist/scroll/send loop, parameterised per surface. Registered as an Alpine
 * component factory on window so templates use x-data="agChat({...})". */
window.agChat = function (opts) {
  opts = opts || {};
  var STORAGE  = opts.storageKey || 'ag-chat';
  var LOG_ID   = opts.logId || 'agChatLog';
  var FOCUS_ID = opts.focusId || null;
  var CSRF     = opts.csrf || '';
  var ENDPOINT = opts.endpoint || '/admin/assistant/chat';

  return {
    open: !!opts.startOpen,
    msgs: [], draft: '', busy: false, error: '',

    // Full-page surface calls init() via x-init; the floating one loads on open.
    init() { this.load(); this.$nextTick(() => this.scroll()); },
    load() { try { this.msgs = JSON.parse(sessionStorage.getItem(STORAGE) || '[]').slice(-30); } catch (e) {} },
    toggle() {
      this.open = !this.open;
      if (this.open) {
        this.load();
        this.$nextTick(() => { this.scroll(); if (FOCUS_ID) { var el = document.getElementById(FOCUS_ID); if (el) el.focus(); } });
      }
    },
    persist() { try { sessionStorage.setItem(STORAGE, JSON.stringify(this.msgs.slice(-30))); } catch (e) {} },
    scroll() { var el = document.getElementById(LOG_ID); if (el) el.scrollTop = el.scrollHeight; },

    async send() {
      var text = (this.draft || '').trim();
      if (!text || this.busy) return;
      this.error = ''; this.msgs.push({ role: 'user', text }); this.draft = ''; this.busy = true;
      this.$nextTick(() => this.scroll());
      try {
        var r = await fetch(ENDPOINT, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
          body: JSON.stringify({ message: text, history: this.msgs.slice(0, -1).slice(-10) })
        });
        var j = await r.json();
        if (j && j.ok && j.reply) { this.msgs.push({ role: 'assistant', text: j.reply }); }
        else { this.error = (j && j.error) || 'The assistant did not answer — try again.'; }
      } catch (e) { this.error = 'Network error — try again.'; }
      finally { this.busy = false; this.persist(); this.$nextTick(() => this.scroll()); }
    }
  };
};
