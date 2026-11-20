/* ════════════════════════════════════════════════════════════════
   Africa GATES — dynamic favicon  (public/assets/js/favicon.js)
   One <link rel="icon" id="agFavicon">, redrawn on a 64px canvas.
   States, highest priority first:
     error   red dot                 a payment or vote failed
     live    pulsing red dot         voting/livestream on THIS page is live
     unread  green count badge 1–9+  notifications, Gee replies
     busy    green ring sweep        a submit is in flight (vote, pay, nominate)
     idle    the plain mark          (favicon.svg; follows light/dark)
   Hidden tab rule: when document.hidden and there is a NEW unread item,
   the title gets "(n) " prefixed. Nothing animates while hidden.
   API:  agFavicon.set('live'|'busy'|'error'|'idle')  agFavicon.unread(n)
   ════════════════════════════════════════════════════════════════ */
(function(){
  var link=document.getElementById('agFavicon'); if(!link) return;
  var IDLE=link.href, base=new Image(), ready=false, state='idle', count=0, raf=0, t0=0;
  var reduce=window.matchMedia('(prefers-reduced-motion: reduce)');
  var cv=document.createElement('canvas'); cv.width=cv.height=64; var x=cv.getContext('2d');
  base.onload=function(){ ready=true; draw(0); }; base.src=IDLE;
  var title=document.title.replace(/^\(\d+\+?\) /,'');

  function dot(color, r){ x.beginPath(); x.arc(50,14,r,0,7); x.fillStyle=color; x.fill();
    x.lineWidth=4; x.strokeStyle='#fff'; x.stroke(); }
  function draw(now){
    if(!ready) return;
    x.clearRect(0,0,64,64); x.drawImage(base,0,0,64,64);
    if(state==='busy'){
      var a=reduce.matches?0:((now-t0)/900)*Math.PI*2;
      x.beginPath(); x.arc(32,32,29,a,a+Math.PI*1.2); x.lineWidth=5; x.lineCap='round'; x.strokeStyle='#7fc87c'; x.stroke();
    }
    if(state==='error') dot('#b42318',12);
    else if(state==='live'){ var p=reduce.matches?1:(.75+.25*Math.sin((now-t0)/260)); dot('#e0245e',10+2*p); }
    else if(count>0){
      x.beginPath(); x.arc(46,18,16,0,7); x.fillStyle='#237b22'; x.fill(); x.lineWidth=4; x.strokeStyle='#fff'; x.stroke();
      x.fillStyle='#fff'; x.font='700 '+(count>9?17:21)+'px DM Sans, system-ui, sans-serif'; x.textAlign='center'; x.textBaseline='middle';
      x.fillText(count>9?'9+':String(count),46,19);
    }
    if(state==='idle' && !count){ link.href=IDLE; return; }
    link.href=cv.toDataURL('image/png');
  }
  function loop(now){ draw(now); if((state==='busy'||state==='live') && !document.hidden && !reduce.matches) raf=requestAnimationFrame(loop); }
  function restart(){ cancelAnimationFrame(raf); t0=performance.now(); loop(t0); }

  window.agFavicon={
    set:function(s){ state=s||'idle'; restart(); },
    unread:function(n){ var was=count; count=Math.max(0,n|0);
      document.title=(count && document.hidden && count>was ? '('+(count>9?'9+':count)+') ' : '')+title; restart(); }
  };
  document.addEventListener('visibilitychange',function(){ if(!document.hidden){ document.title=title; restart(); } else cancelAnimationFrame(raf); });
  /* Pages opt in declaratively: <body data-favicon="live"> on a live vote or stream. */
  var d=document.body && document.body.getAttribute('data-favicon'); if(d) agFavicon.set(d);
})();
