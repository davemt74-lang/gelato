<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/daily-quicklist-receipts.php';
$user=app_require_auth();
if(!daily_quicklist_receipt_permission($user)){http_response_code(403);exit('Manager and Inventory View permissions required.');}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Need More Purchasing · Gelato</title>
<link rel="stylesheet" href="assets/css/daily-prep.css?v=3k2h">
<style>
.board-head{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
.board-head h1{font-size:clamp(30px,5vw,48px);margin:6px 0}
.board-head p{max-width:720px;line-height:1.5}
.board-date{padding:10px;border:1px solid #c6d8c8;border-radius:10px}
.board-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:24px 0}
.board-metric{padding:20px;border-radius:15px;background:#e7efe6;border:1px solid #d2e2d1}
.board-metric strong{display:block;font-size:30px}.board-metric span{font-size:12px;font-weight:800;color:#45624c}
.board-list{display:grid;gap:15px;margin:20px 0 35px}
.board-card{background:white;border:1px solid #d8e4d9;border-radius:16px;padding:20px}
.board-top{display:flex;align-items:start;justify-content:space-between;gap:10px;flex-wrap:wrap}
.board-top h2{margin:0 0 5px}.board-chip{background:#e8f1e7;color:#25543b;border-radius:8px;padding:6px 10px;font-size:12px;font-weight:800}
.board-evidence{display:grid;grid-template-columns:repeat(auto-fit,minmax(245px,1fr));gap:14px;margin-top:15px}
.board-evidence section{padding:14px;border-radius:12px;background:#f5f8f3}
.board-evidence h3{font-size:13px;margin:0 0 8px}.board-evidence ul{padding-left:18px;margin:8px 0}
.board-evidence li{font-size:13px;line-height:1.5;margin:6px 0}.board-muted{font-size:12px;color:#647568}
.board-actions{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0}.board-actions a{display:inline-flex;padding:10px 13px;background:#183a2a;color:white;text-decoration:none;border-radius:8px;font-weight:800;font-size:12px}
.board-actions a.secondary{background:#dbe9da;color:#25442b}
.board-draft{border:0;border-radius:8px;background:#245c43;color:#fff;font-weight:800;padding:10px 13px;cursor:pointer}
.board-draft:disabled{opacity:.5;cursor:wait}
.board-dialog{width:min(95vw,540px);border:1px solid #d4e1d5;border-radius:17px;padding:22px;background:white;color:#18392a;box-shadow:0 18px 65px #0004}
.board-dialog::backdrop{background:#10221abb}
.board-dialog .board-actions button{font-size:13px}
.board-dialog h2{margin-top:0}
.board-dialog dt{font-weight:bold}.board-dialog dd{margin:0 0 9px}
.board-dialog .warning{color:#86501c}

@media(max-width:640px){.board-card{padding:14px}.board-evidence{grid-template-columns:1fr}}
</style></head><body><div class="workspace">
<header class="masthead"><a href="daily-prep.php" class="back-link">← Daily Prep</a>
<div class="brand"><span class="brand-mark">g.</span><span>GELATO <small>/ PURCHASING</small></span></div>
<a class="agent-link" href="agent-canvas.php">✦ Ask Gelato AI</a></header>
<main><section class="board-head"><div><div class="eyebrow dark">SECTION 3K2I-A · MANAGER WORKSPACE</div>
<h1>Need More / Purchasing</h1>
<p>Review what the kitchen needs, open purchase orders and recorded vendor deliveries. Review quantities and create a purchase-order draft; submitting orders and recording physical inventory remain separate actions.</p></div>
<label>Working date <input id="board-date" class="board-date" type="date"></label></section>
<div class="board-actions"><a href="purchasing.php">Open Purchasing &amp; Receiving</a><a href="daily-prep.php" class="secondary">Manage Need More</a><a href="operations.php" class="secondary">Operations Inventory</a></div>
<div id="board-alert" role="status" aria-live="polite"></div>
<div id="board-summary" class="board-summary"></div>
<div id="board-list" class="board-list">Loading current records…</div>
<dialog id="draft-dialog" class="board-dialog" aria-labelledby="draft-title">
<h2 id="draft-title">Review purchase-order draft</h2><div id="draft-quote"></div>
<div class="board-actions"><button id="draft-create" class="board-draft" type="button">Confirm · Create draft only</button><button id="draft-cancel" class="board-draft" type="button">Cancel</button></div>
<div id="draft-error" role="alert" aria-live="polite"></div></dialog>
<p class="board-muted">Evidence is linked by the saved inventory item, not by inferred vendor text. Orders and goods receipts do not themselves count as Need More allocations. Use the existing receipt confirmation workflow to reconcile an actual recorded stock transaction.</p>
</main></div>
<script>
(function(){
'use strict';
const date=document.getElementById('board-date'),list=document.getElementById('board-list'),summary=document.getElementById('board-summary'),alert=document.getElementById('board-alert');
const dialog=document.getElementById('draft-dialog'),quoteBody=document.getElementById('draft-quote'),draftError=document.getElementById('draft-error'),create=document.getElementById('draft-create');
let currentQuote=null;
const esc=v=>String(v==null?'':v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const qty=v=>v==null?'Not set':Number(v).toLocaleString(undefined,{maximumFractionDigits:3});
const local=new Date();date.value=local.getFullYear()+'-'+String(local.getMonth()+1).padStart(2,'0')+'-'+String(local.getDate()).padStart(2,'0');
async function load(){
list.textContent='Loading current records…';summary.replaceChildren();alert.textContent='';
try{
 const res=await fetch('api/daily-prep.php?action=purchasing_board&date='+encodeURIComponent(date.value),{credentials:'same-origin',cache:'no-store'});
 const data=await res.json();if(!res.ok||!data.ok)throw Error(data.message||'Could not load purchasing board.');
 const board=data.board,tot=board.totals;
 summary.innerHTML=[['Requests',tot.requests],['Numeric outstanding',tot.numericOutstanding],['With open orders',tot.withOpenOrders],['With recent deliveries',tot.withRecentDeliveries]].map(([label,value])=>'<div class="board-metric"><strong>'+esc(value)+'</strong><span>'+esc(label)+'</span></div>').join('');
 list.innerHTML=board.requests.map(item=>{
 const p=item.progress;
 const progress=p?'<strong>'+esc(qty(p.receivedQuantity))+' / '+esc(qty(p.requestedQuantity))+' '+esc(p.unit)+'</strong><p>'+esc(qty(p.remainingQuantity))+' '+esc(p.unit)+' remaining</p>':'<p>No numeric target or linked stock item.</p>';
 const orders=item.openOrders.length?'<ul>'+item.openOrders.map(o=>'<li><b>'+esc(o.orderNumber)+'</b> · '+esc(o.vendor)+' · '+esc(o.status)+'<div class="board-muted">'+esc(qty(o.receivedQuantity))+' / '+esc(qty(o.orderedQuantity))+' '+esc(o.unit)+' on PO · Expected '+esc(o.expectedDate||'not set')+'</div></li>').join('')+'</ul>':'<p class="board-muted">No open order for this saved stock item.</p>';
 const receipts=item.recentDeliveries.length?'<ul>'+item.recentDeliveries.map(r=>'<li><b>'+esc(r.receiptNumber)+'</b> · '+esc(r.vendor)+'<div class="board-muted">'+esc(qty(r.quantity))+' '+esc(r.unit)+' · '+esc(r.receivedAt)+' · '+esc(r.orderNumber)+'</div></li>').join('')+'</ul>':'<p class="board-muted">No recent purchasing delivery in the displayed history.</p>';
 return '<article class="board-card"><div class="board-top"><div><h2>'+esc(item.title)+'</h2><span class="board-muted">'+esc(item.quantityNote||'No staff note')+'</span></div><span class="board-chip">'+esc(item.status.replace('_',' '))+'</span></div><div class="board-evidence"><section><h3>Need More balance</h3>'+progress+'</section><section><h3>Open purchase orders</h3>'+orders+'</section><section><h3>Recent vendor deliveries</h3>'+receipts+'</section></div><div class="board-actions"><button type="button" class="board-draft" data-draft="'+esc(item.id)+'">Preview PO draft</button></div></article>';
 }).join('')||'<div class="board-card">No outstanding Need More requests for this date.</div>';
}catch(e){list.textContent='Unable to load the board.';alert.textContent=e.message;}
}
async function showDraft(id){
 currentQuote=null;draftError.textContent='';create.disabled=true;quoteBody.textContent='Loading current Need More quantities and vendor terms…';
 dialog.showModal();
 try{
  const res=await fetch('api/daily-prep.php?action=po_draft_preview&id='+encodeURIComponent(id),{credentials:'same-origin',cache:'no-store'});
  const body=await res.json();if(!res.ok||!body.ok)throw Error(body.message||'PO draft unavailable.');
  currentQuote=body.quote;
  quoteBody.innerHTML='<p><strong>'+esc(currentQuote.name)+'</strong></p>'+
   '<dl><dt>Outstanding request</dt><dd>'+esc(qty(currentQuote.remainingQuantity))+' '+esc(currentQuote.unit)+'</dd>'+
   '<dt>Supplier</dt><dd>'+esc(currentQuote.vendor)+'</dd>'+
   '<dt>Proposed vendor packs</dt><dd>'+esc(currentQuote.packs)+' × '+esc(qty(currentQuote.packSize))+' '+esc(currentQuote.unit)+'</dd>'+
   '<dt>Order quantity</dt><dd>'+esc(qty(currentQuote.orderedQuantity))+' '+esc(currentQuote.unit)+'</dd>'+
   '<dt>Pack rounding overage</dt><dd>'+esc(qty(currentQuote.overageQuantity))+' '+esc(currentQuote.unit)+'</dd>'+
   '<dt>Estimated subtotal</dt><dd>'+(currentQuote.estimatedSubtotal===null?'Not priced':'
})();
</script></body></html>
+esc(Number(currentQuote.estimatedSubtotal).toFixed(2)))+'</dd></dl>'+
   '<p class="board-muted">This only creates a draft. Review the order in Purchasing before submission. No inventory quantity is changed.</p>'+
   (currentQuote.warning?'<p class="warning">'+esc(currentQuote.warning)+'</p>':'');
  create.disabled=false;
 }catch(e){quoteBody.textContent='Cannot create a PO draft for this request.';draftError.textContent=e.message;}
}
list.addEventListener('click',e=>{
 const btn=e.target.closest('button[data-draft]');if(btn)showDraft(btn.dataset.draft);
});
document.getElementById('draft-cancel').addEventListener('click',()=>dialog.close());
dialog.addEventListener('close',()=>{currentQuote=null;create.disabled=true;});
create.addEventListener('click',async()=>{
 if(!currentQuote||create.disabled)return;
 create.disabled=true;draftError.textContent='';
 try{
  const res=await fetch('api/daily-prep.php',{method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
    body:JSON.stringify({action:'po_draft_create',id:currentQuote.requestId,quoteHash:currentQuote.quoteHash})});
  const body=await res.json();if(!res.ok||!body.ok)throw Error(body.message||'Draft creation failed.');
  dialog.close();await load();alert.textContent='Created '+body.order.orderNumber+' as a draft. Open Purchasing to review before submission.';
 }catch(e){draftError.textContent=e.message;}finally{create.disabled=false;}
});
date.addEventListener('change',load);load();
})();
</script></body></html>
