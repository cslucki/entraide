(function(){
  var REDUCE=window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* rising stars (box-shadow field) */
  (function(){
    var host=document.getElementById('bp-stars');
    if(!host) return;
    var W=Math.max(1920,window.innerWidth),H=1000;
    var layers=[{n:260,s:1,d:62,o:1},{n:150,s:1.7,d:96,o:.72},{n:90,s:2.4,d:150,o:.5}];
    layers.forEach(function(L){
      var el=document.createElement('i');
      el.style.width=L.s+'px';el.style.height=L.s+'px';el.style.opacity=L.o;
      el.style.animationDuration=L.d+'s';
      var sh=[];
      for(var i=0;i<L.n;i++){var x=(Math.random()*W)|0,y=(Math.random()*H)|0;
        sh.push(x+'px '+y+'px #fff');sh.push(x+'px '+(y+H)+'px #fff');}
      el.style.boxShadow=sh.join(',');
      host.appendChild(el);
    });
  })();

  /* mouse light + per-card cursor glow */
  (function(){
    var ml=document.getElementById('bp-mlight');
    if(!ml) return;
    var shown=false;
    window.addEventListener('pointermove',function(e){
      ml.style.setProperty('--mx',e.clientX+'px');
      ml.style.setProperty('--my',e.clientY+'px');
      if(!shown){ml.style.opacity='1';shown=true;}
    });
    window.addEventListener('pointerleave',function(){ml.style.opacity='0';shown=false;});
    document.querySelectorAll('.bp-artscilab .ocard').forEach(function(card){
      card.addEventListener('pointermove',function(e){
        var r=card.getBoundingClientRect();
        card.style.setProperty('--mx',(e.clientX-r.left)+'px');
        card.style.setProperty('--my',(e.clientY-r.top)+'px');
      });
    });
  })();

  /* staggered entrance */
  (function(){
    if(REDUCE) return;
    var els=[].slice.call(document.querySelectorAll('.bp-artscilab [data-anim]'));
    els.forEach(function(el,i){
      try{el.animate(
        [{opacity:0,transform:'translateY(20px)'},{opacity:1,transform:'translateY(0)'}],
        {duration:680,delay:120+i*120,easing:'cubic-bezier(.22,.72,.24,1)',fill:'backwards'}
      );}catch(e){}
    });
    setTimeout(function(){
      els.forEach(function(el){
        try{el.getAnimations().forEach(function(a){
          try{if(a.effect.getComputedTiming().iterations!==Infinity)a.finish();}catch(e){}
        });}catch(e){}
      });
    },2200);
  })();
})();
