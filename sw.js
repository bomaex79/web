var CACHE='bm-browser-v10';
var FILES=['./','./index.html','./manifest.webmanifest','./icon.svg','./icon-192.png','./icon-512.png','./logo.png','./impressum.html','./datenschutz.html'];
self.addEventListener('install',function(e){
  e.waitUntil(caches.open(CACHE).then(function(c){return c.addAll(FILES)}).then(function(){return self.skipWaiting()}));
});
self.addEventListener('activate',function(e){
  e.waitUntil(caches.keys().then(function(ks){return Promise.all(ks.filter(function(k){return k!==CACHE}).map(function(k){return caches.delete(k)}))}).then(function(){return self.clients.claim()}));
});
self.addEventListener('fetch',function(e){
  var r=e.request;
  if(r.method!=='GET'||r.url.indexOf('suche.php')>-1||r.url.indexOf('proxy.php')>-1||r.url.indexOf('api.php')>-1||r.url.indexOf('ai.php')>-1||new URL(r.url).origin!==location.origin)return;
  e.respondWith(fetch(r).then(function(res){
    var cp=res.clone();caches.open(CACHE).then(function(c){c.put(r,cp)});return res;
  }).catch(function(){return caches.match(r).then(function(m){return m||caches.match('./index.html')})}));
});
