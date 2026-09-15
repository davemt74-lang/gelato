(() => {
'use strict';
if(window.GelatoEmployeeHomeNavCleanup)return;
window.GelatoEmployeeHomeNavCleanup=true;

function cleanNavigation(){
  document.querySelectorAll('[data-nav-group="admin"] [data-employee-home-link], [data-nav-group="admin"] a[href="employee-home.php"]')
    .forEach(node=>node.remove());

  const actions=document.querySelector('.topbar .top-actions');
  if(actions){
    actions.querySelectorAll('a[href="landing.html"], a[href="./landing.html"]').forEach(node=>node.remove());
    actions.querySelectorAll('a[href="pos.php"]:not(#gelatoHeaderPos), a[href="./pos.php"]:not(#gelatoHeaderPos)').forEach(node=>node.remove());
    Array.from(actions.querySelectorAll('button')).forEach(node=>{
      if(node.id==='gelatoHeaderPos')return;
      if(node.textContent.trim().toUpperCase()==='POS')node.remove();
    });
  }
}

function install(){
  cleanNavigation();
  const observer=new MutationObserver(()=>cleanNavigation());
  observer.observe(document.body,{childList:true,subtree:true});
  queueMicrotask(cleanNavigation);
  requestAnimationFrame(cleanNavigation);
}

if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install,{once:true});
else install();
})();
