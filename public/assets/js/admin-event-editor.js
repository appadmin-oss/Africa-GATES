
function evRepeater(seed, blank){
  let nextId = 1000;
  const rows = (seed && seed.length) ? seed.map(r => Object.assign({}, r)) : [Object.assign({id:1}, blank)];
  return {
    rows,
    add(){ this.rows.push(Object.assign({id: nextId++}, blank)); },
    rm(id){ this.rows = this.rows.filter(r => r.id !== id); if(!this.rows.length) this.add(); },

    /* ── MOVING A ROW IS THE WHOLE ORDERING FEATURE ───────────────────────────
       There is no reorder route and there does not need to be one: saveTiers() walks the
       submitted rows and writes `sort_order = $order++`, so the ORDER OF THE FIELDS IN
       THIS FORM is the order the ladder is stored in. Every reader — the public event
       page, the registration card, the admin list, the ticket service and the support
       context — selects `orderBy('sort_order')->orderBy('id')`. So moving the inputs
       moves the tier everywhere, and a row that never moves keeps the id tiebreak it
       already had.

       Splice rather than swap: a swap is only correct for a step of one, and writing the
       general form here means a future "move to top" cannot silently corrupt the ladder
       by reusing a helper that was never that. */
    move(id, delta){
      const i = this.rows.findIndex(r => r.id === id);
      const j = i + delta;
      if (i < 0 || j < 0 || j >= this.rows.length) return;
      this.rows.splice(j, 0, this.rows.splice(i, 1)[0]);
    }
  };
}

/* ── the ticket preview ─────────────────────────────────────────────────────
   Presentation only. Nothing here validates anything: the server refuses a non-colour on
   save and again on render (EventTicketDesign::colour), so a malformed value typed here
   shows as a broken preview and is then simply not stored. Doing the validation twice in
   two languages would mean two places to disagree.

   `ink()` mirrors EventTicketDesign::contrastInk so the preview picks the same readable
   text colour the real ticket will. Same relative-luminance formula and the same 0.36
   threshold — a naive channel average hands white text to a bright green header. */
function tkDesign(seed){
  return {
    accent: seed.accent || '#10292C',
    theme:  seed.theme  || 'dark',
    note:   seed.note   || '',
    image:  seed.image  || '',
    imageField: seed.imageField || '',
    title:  seed.title  || '',
    when:   (seed.when || '').slice(0, 16).replace('T', ' '),
    venue:  seed.venue  || '',
    showQr: seed.showQr !== false,

    /* ── THE ARTWORK EDITOR ──────────────────────────────────────────────────
       State is THREE numbers — a zoom and the centre of the frame, as fractions of the
       picture — and not a rectangle in pixels. Fractions survive the things pixels do not:
       the panel being narrower on a phone, the upload being scaled down to 2400px on the
       way to disk, and the picture being turned a quarter turn after the frame was set.

       Everything below derives from them, in percentages of a 3:2 box. That is why nothing
       here measures anything: the editor frame and the small preview are both 3:2, so the
       same offsets place the picture identically in both, at any width, with no resize
       observer to go stale. */
    art: {
      on:   (seed.art || {}).on !== false,
      src:  (seed.art || {}).src || '',
      ratio: (seed.art || {}).ratio || 1.5,
      nw: 0, nh: 0, ready: false, over: false, gone: false,
      zoom: 1, cx: 0.5, cy: 0.5,
      rotate: 0, flip: 'none', brightness: 0, contrast: 0, greyscale: false
    },

    init(){
      const e = (seed.art || {}).edit || {};
      const a = this.art;
      a.rotate     = [0, 90, 180, 270].indexOf(e.rotate | 0) >= 0 ? (e.rotate | 0) : 0;
      a.flip       = ['none', 'h', 'v'].indexOf(e.flip) >= 0 ? e.flip : 'none';
      a.brightness = this.artNum(e.brightness);
      a.contrast   = this.artNum(e.contrast);
      a.greyscale  = !!e.greyscale;
      /* The rectangle is turned back into a zoom and a centre once the picture's natural
         size is known — see artLoaded(). Until then the frame shows it centred, which is
         also what the whole-picture recipe means. */
      this.artSeedCrop = (e.crop && typeof e.crop === 'object') ? e.crop : null;
    },

    artNum(v){ v = Number(v); return isFinite(v) ? Math.max(-100, Math.min(100, Math.round(v))) : 0; },

    /* `05.09.26`, which is the form the real .tk__kick prints. The written-out date belongs
       on the stub, where it is read rather than recognised — and a preview that showed
       `2026-09-05` would be teaching the wrong line length for the space above the title. */
    artDay(){
      const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(this.when || '');
      return m ? m[3] + '.' + m[2] + '.' + m[1].slice(2) : '';
    },
    artSigned(v){ return (v > 0 ? '+' : '') + v; },

    /* What the small preview should show when there is no artwork: the address somebody
       typed, else the event's cover. Never the RESOLVED value in the address box itself —
       see the seed comment above the markup for the save-time bug that caused. */
    shot(){ return this.imageField || this.image || ''; },

    /* The picture as the frame sees it: a quarter turn swaps its sides. Every fraction
       below — and every one the server reads — is against THIS, not the file on disk. */
    artW(){ return this.art.rotate % 180 === 0 ? this.art.nw : this.art.nh; },
    artH(){ return this.art.rotate % 180 === 0 ? this.art.nh : this.art.nw; },

    /* The widest 3:2 rectangle that fits, as a fraction of each side. Zoom 1 is exactly
       this — i.e. `cover`, the thing the ticket did before — and everything above 1 is a
       tighter frame inside it. There is deliberately no way to zoom below 1: it would mean
       letting the band show something that is not the picture. */
    artBase(){
      const A = this.artW() / this.artH(), R = this.art.ratio;
      return A >= R ? { w: R / A, h: 1 } : { w: 1, h: A / R };
    },

    /* The crop, as the server will read it. Clamped here rather than trusted there — but
       TicketArtwork::recipe() clamps it again anyway, because this is a browser. */
    artCrop(){
      const a = this.art;
      if (!a.ready) return { x: 0, y: 0, w: 1, h: 1 };
      const b = this.artBase();
      const w = b.w / a.zoom, h = b.h / a.zoom;
      return {
        x: Math.min(Math.max(a.cx - w / 2, 0), 1 - w),
        y: Math.min(Math.max(a.cy - h / 2, 0), 1 - h),
        w: w, h: h
      };
    },

    artRecipe(){
      const a = this.art;
      return { crop: this.artCrop(), rotate: a.rotate, flip: a.flip,
               brightness: a.brightness, contrast: a.contrast, greyscale: a.greyscale };
    },
    /* The hidden field. Empty when there is nothing to cut, so a form with no artwork does
       not post a recipe the server would have to decide what to do with. */
    artJson(){ return (this.art.on && this.art.src && this.art.ready) ? JSON.stringify(this.artRecipe()) : ''; },

    /* Percentages, not pixels — see the state comment. A crop covering `w` of the picture
       is shown at 100/w per cent of a frame that is showing exactly that crop. */
    artStage(){
      if (!this.art.ready) return 'display:none';
      const c = this.artCrop();
      return 'left:' + (-c.x / c.w * 100) + '%;top:' + (-c.y / c.h * 100) + '%;'
           + 'width:' + (100 / c.w) + '%;height:' + (100 / c.h) + '%';
    },

    artImg(){
      const a = this.art;
      if (!a.ready) return 'display:none';
      /* Sized against the ROTATED box and then turned about its own centre: a rectangle
         rotated a quarter turn about its centre occupies exactly the transposed box, so the
         picture lands filling the stage without a single offset being worked out. */
      const t = ['translate(-50%,-50%)', 'rotate(' + a.rotate + 'deg)'];
      if (a.flip === 'h') t.push('scaleX(-1)');
      if (a.flip === 'v') t.push('scaleY(-1)');
      return 'width:' + (a.nw / this.artW() * 100) + '%;'
           + 'height:' + (a.nh / this.artH() * 100) + '%;'
           + 'transform:' + t.join(' ') + ';' + this.artFilter();
    },

    /* The preview filter, built to match what Intervention will do rather than to look
       roughly similar. Brightness and greyscale go through the SVG filter in the markup —
       GD adds a constant and CSS `brightness()` multiplies, which are not the same picture.
       Contrast is the one that maps exactly: GD computes (c − ½)·k + ½ with k = ((100+n)/100)²,
       and that is the definition of CSS `contrast(k)`. */
    artFilter(){
      const a = this.art, f = [];
      if (a.greyscale || a.brightness !== 0) f.push('url(#tka-adj)');
      if (a.contrast !== 0) f.push('contrast(' + Math.pow((100 + a.contrast) / 100, 2).toFixed(4) + ')');
      return f.length ? 'filter:' + f.join(' ') + ';' : '';
    },

    /* GD's greyscale weights, not CSS's. `grayscale()` uses Rec.709 (.2126 .7152 .0722) and
       imagefilter(IMG_FILTER_GRAYSCALE) uses luma (.299 .587 .114) — close, but visibly
       different on saturated reds, which is most of a stage-lit event photograph. */
    artMatrix(){
      return this.art.greyscale
        ? '0.299 0.587 0.114 0 0 0.299 0.587 0.114 0 0 0.299 0.587 0.114 0 0 0 0 0 1 0'
        : '1 0 0 0 0 0 1 0 0 0 0 0 1 0 0 0 0 0 1 0';
    },

    artLoaded(ev){
      const a = this.art;
      a.nw = ev.target.naturalWidth || 0;
      a.nh = ev.target.naturalHeight || 0;
      if (!a.nw || !a.nh) return;
      a.ready = true;
      /* Put the handles back where they were left. A stored recipe is a rectangle; the frame
         works in zoom-and-centre, so it is inverted here — and only here, once the picture's
         real proportions are known. The identity recipe (the whole picture) is not 3:2 and
         inverts to a zoom below 1, which clamps to 1: centred `cover`, which is what it means. */
      const c = this.artSeedCrop;
      this.artSeedCrop = null;
      if (c && c.w > 0 && c.h > 0) {
        a.zoom = Math.min(4, Math.max(1, this.artBase().w / Number(c.w)));
        a.cx = Number(c.x) + Number(c.w) / 2;
        a.cy = Number(c.y) + Number(c.h) / 2;
      }
      this.artClamp();
    },

    /* Keep the frame inside the picture. Called after everything that can move an edge —
       a zoom, a turn, a drag — because a crop that has slipped outside is a crop the server
       will quietly slide back, and then the saved ticket is not the one on screen. */
    artClamp(){
      const a = this.art;
      a.zoom = Math.min(4, Math.max(1, Number(a.zoom) || 1));
      if (!a.ready) return;
      const b = this.artBase(), w = b.w / a.zoom, h = b.h / a.zoom;
      a.cx = Math.min(Math.max(a.cx, w / 2), 1 - w / 2);
      a.cy = Math.min(Math.max(a.cy, h / 2), 1 - h / 2);
    },

    artZoom(by){ this.art.zoom = this.art.zoom + by; this.artClamp(); },

    artTurn(deg){
      const a = this.art;
      a.rotate = ((a.rotate + deg) % 360 + 360) % 360;
      /* The centre travels with the picture. Without this a quarter turn appears to throw
         the frame somewhere else, and the organiser re-drags it every single time. */
      const cx = a.cx, cy = a.cy;
      if (deg > 0) { a.cx = 1 - cy; a.cy = cx; } else { a.cx = cy; a.cy = 1 - cx; }
      this.artClamp();
    },

    artFlip(axis){
      const a = this.art;
      a.flip = (a.flip === axis) ? 'none' : axis;
      if (axis === 'h') a.cx = 1 - a.cx; else a.cy = 1 - a.cy;
      this.artClamp();
    },

    artUntouched(){
      const a = this.art;
      return a.zoom === 1 && Math.abs(a.cx - 0.5) < 1e-6 && Math.abs(a.cy - 0.5) < 1e-6
          && a.rotate === 0 && a.flip === 'none'
          && a.brightness === 0 && a.contrast === 0 && !a.greyscale;
    },

    artReset(){
      Object.assign(this.art, { zoom: 1, cx: 0.5, cy: 0.5, rotate: 0, flip: 'none',
                                brightness: 0, contrast: 0, greyscale: false });
    },

    /* Removing is a REQUEST, not a local tidy-up: `gone` is posted, and it is what makes the
       server clear all three columns. Without it the row would keep a source the form is no
       longer showing, and the picture would come back on the next save. */
    artRemove(){
      this.artReset();
      Object.assign(this.art, { src: '', nw: 0, nh: 0, ready: false, gone: true });
      this.imageField = '';
      /* Emptied, not just hidden. The input is outside the branch that just went away, so a
         file chosen and then removed would still be posted and quietly re-appear. */
      if (this.$refs.tkaFile) this.$refs.tkaFile.value = '';
    },

    artDrop(ev){
      const f = ev.dataTransfer && ev.dataTransfer.files ? ev.dataTransfer.files[0] : null;
      if (!f) return;
      /* A dropped file is not in the input, and the input is what gets posted. Assigning the
         FileList across is the one supported way to put it there. */
      try { this.$refs.tkaFile.files = ev.dataTransfer.files; } catch (e) { /* older browsers */ }
      this.artFile(f);
    },

    /* Shown from the file itself, not from a round trip. The server has not seen this
       picture yet and will scale it down to 2400px when it does — which changes nothing
       here, because the frame is expressed in fractions of the picture rather than pixels. */
    artFile(file){
      if (!file || !/^image\//.test(file.type)) return;
      const r = new FileReader();
      r.onload = () => {
        this.artReset();
        Object.assign(this.art, { src: r.result, ready: false, gone: false, nw: 0, nh: 0 });
      };
      r.readAsDataURL(file);
    },

    artGrab(ev){
      if (!this.art.ready || ev.button > 0) return;
      const box = ev.currentTarget.getBoundingClientRect();
      const c = this.artCrop();
      let px = ev.clientX, py = ev.clientY;
      const move = (e) => {
        /* One pixel of the frame is `crop width / frame width` of the picture, and the
           picture moves the opposite way to the frame. */
        this.art.cx -= (e.clientX - px) * c.w / box.width;
        this.art.cy -= (e.clientY - py) * c.h / box.height;
        px = e.clientX; py = e.clientY;
        this.artClamp();
      };
      // Through cnPage: a drag cut short by leaving the page must not leave a window listener
      // moving a picture that no longer exists.
      const offs = [];
      const up = () => { offs.splice(0).forEach(off => off()); };
      offs.push(cnPage.on(window, 'pointermove', move), cnPage.on(window, 'pointerup', up), cnPage.on(window, 'pointercancel', up));
    },

    /* Arrows nudge, +/− zoom. Not decoration: a pointer drag is the only way to set this
       frame otherwise, which would put the feature out of reach of anybody who does not use
       one — on the single control that decides what the printed ticket looks like. */
    artKey(ev){
      if (!this.art.ready) return;
      const step = ev.shiftKey ? 0.05 : 0.01, a = this.art;
      const at = {
        ArrowLeft:  () => a.cx -= step, ArrowRight: () => a.cx += step,
        ArrowUp:    () => a.cy -= step, ArrowDown:  () => a.cy += step,
        '+': () => this.artZoom(0.1), '=': () => this.artZoom(0.1),
        '-': () => this.artZoom(-0.1), '_': () => this.artZoom(-0.1)
      }[ev.key];
      if (!at) return;
      ev.preventDefault();
      at();
      this.artClamp();
    },

    ink(){
      const h = String(this.accent || '').trim().replace(/^#/, '');
      if (!/^[0-9a-f]{6}$/i.test(h)) return '#FFFFFF';
      const lin = c => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
      const l = 0.2126 * lin(parseInt(h.slice(0, 2), 16))
              + 0.7152 * lin(parseInt(h.slice(2, 4), 16))
              + 0.0722 * lin(parseInt(h.slice(4, 6), 16));
      return l > 0.36 ? '#10292C' : '#FFFFFF';
    }
  };
}

function evReview(){
  return { summary: {}, init(){
    var self = this, form = this.$el.closest('form');
    function update(){
      var value = function(name){ var field = form.elements.namedItem(name); return field ? field.value.trim() : ''; };
      self.summary = {title:value('title') || 'Not entered', starts:value('event_date').replace('T',' · ') || 'Not entered', timezone:value('timezone') || 'Platform default', venue:[value('venue'),value('location')].filter(Boolean).join(' · ') || 'Not entered', capacity:value('capacity') || 'Unlimited'};
    }
    form.addEventListener('input',update); form.addEventListener('change',update); update();
  }};
}
