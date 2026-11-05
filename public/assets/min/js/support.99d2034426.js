(function(){"use strict";var t=document.querySelector("[data-sp-compose]");if(t){var n=document.querySelector("[data-sp-hint]");t.addEventListener("change",function(r){var e=r.target;e&&e.name==="reason"&&n&&(n.textContent=e.getAttribute("data-hint")||"")}),t.addEventListener("submit",function(r){if(r.preventDefault(),!!t.reportValidity()){var e=function(d){var o=t.elements[d];return o?String(o.value||"").trim():""},a=(t.querySelector('input[name="reason"]:checked')||{}).value||"",i=t.getAttribute("data-l-reason")+": "+a+`\r
`+t.getAttribute("data-l-name")+": "+e("name")+" <"+e("email")+`>\r
`+t.getAttribute("data-l-ref")+": "+e("ref")+`\r
\r
`+e("body"),u=t.getAttribute("data-subject").replace("%r%",a);window.location.href="mailto:"+t.getAttribute("data-to")+"?subject="+encodeURIComponent(u)+"&body="+encodeURIComponent(i)}})}})();
