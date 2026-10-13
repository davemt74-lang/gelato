(() => {
  'use strict';
  if(window.GelatoKdsSpeech)return;
  const clean=(x,max=180)=>String(x??'').replace(/[\u0000-\u001f\u007f]/g,' ').replace(/\s+/g,' ').trim().slice(0,max);
  const numberWords={1:'one',2:'two',3:'three',4:'four',5:'five',6:'six',7:'seven',8:'eight',9:'nine',10:'ten'};
  function amount(x){
    const n=Number(x);
    if(!Number.isFinite(n)||n<=0)return 'one';
    if(Number.isInteger(n))return numberWords[n]||String(n);
    return String(Number(n.toFixed(2)));
  }
  function item(row){
    const quantity=amount(row.quantity);
    const title=clean(row.item_name_snapshot||row.name||'item');
    const option=clean(row.option_name_snapshot||'');
    const instructions=clean(row.special_instructions||'',350);
    const modifier=option?' — '+option:'';
    return quantity+' '+title+modifier+(instructions?'. Note: '+instructions:'');
  }
  function ticket(ticket,reference,{update=false,items=null}={}){
    const rows=Array.isArray(items)?items:Array.isArray(ticket.items)?ticket.items:[];
    const prefix=Number(reference)>0?'Order '+reference:'Kitchen order';
    const ticketNo=clean(ticket.checkNumber||'');
    const heading=(update?'Update for ':'')+prefix+(ticketNo?', ticket '+ticketNo:'');
    const place=ticket.tableName?'Table '+clean(ticket.tableName):
      ticket.serviceMode?clean(String(ticket.serviceMode).replaceAll('_',' ')):'';
    const itemsText=rows.map(item);
    const parts=[heading+'.'];
    if(place)parts.push(place+'.');
    if(itemsText.length)parts.push(itemsText.join('. ')+'.');
    else parts.push('No routed items yet.');
    if(ticket.late)parts.push('This order is running late.');
    else if(ticket.readyToBump)parts.push('This ticket is ready for pickup.');
    return parts.join(' ').replace(/\s+/g,' ').trim();
  }
  function all(tickets){
    if(!tickets?.length)return 'There are no active kitchen orders.';
    const lines=tickets.map((row,index)=>ticket(row,index+1));
    return 'There '+(tickets.length===1?'is one active order':'are '+tickets.length+' active orders')+'. '+lines.join(' Next order. ');
  }
  window.GelatoKdsSpeech={ticket,item,all};
})();
