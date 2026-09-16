(() => {
  'use strict';
  const page=(location.pathname.split('/').pop()||'').toLowerCase();
  if(!['packages-admin.php','public-site-settings.php'].includes(page)) return;

  function selectedPackageId(){
    const input=document.getElementById('packageId');
    return String(input?.value||'').replace(/[^A-Za-z0-9_.:-]/g,'').slice(0,160);
  }
  function snapshot(){
    return {
      module:'marketing',
      page,
      selectedPackagePublicId:selectedPackageId(),
    };
  }
  function transportSnapshot(){
    const value=snapshot();
    return {
      module:value.module,
      page:value.page,
      selectedPackagePublicId:value.selectedPackagePublicId,
    };
  }
  function description(context){
    if(context.selectedPackagePublicId) return 'Marketing · selected Package Deal';
    return page==='public-site-settings.php'?'Marketing · Public Site Settings':'Marketing · Package Deals';
  }
  function placeholder(context){
    if(context.selectedPackagePublicId) return 'Ask about this package, its performance, or propose a publish/feature change…';
    return page==='public-site-settings.php'?'Ask about the public site, tagline, packages, or promotion opportunities…':'Ask about packages, promotions, sales, or customer opportunities…';
  }
  function register(){
    if(!window.GelatoAgentPageContext?.register)return false;
    window.GelatoAgentPageContext.register({module:'marketing',snapshot,transportSnapshot,description,placeholder});
    return true;
  }
  if(!register()){
    let attempts=0;
    const timer=setInterval(()=>{attempts++;if(register()||attempts>80)clearInterval(timer);},50);
  }
  document.addEventListener('click',(event)=>{
    if(event.target.closest?.('[data-id],#newPackage,#newPackageTop'))setTimeout(()=>window.GelatoAgentPageContext?.publish?.(),0);
  });
  window.addEventListener('popstate',()=>window.GelatoAgentPageContext?.publish?.());
})();
