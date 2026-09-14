(() => {
  'use strict';

  const nativeFetch = window.fetch.bind(window);
  const planPayloads = new Map();
  const cfg = window.FLOOR_PLANNER_CONFIG || {};
  const csrf = String(cfg.csrf || '');

  function routeFloorPlanUrl(value) {
    const text = String(value || '');
    return text.replace(/(?:\.\/)?api\/floor-plans\.php/g, 'floor-plan-api.php');
  }

  function enrichSaveBody(body) {
    if (typeof body !== 'string') return body;
    try {
      const parsed = JSON.parse(body);
      if (parsed?.action !== 'save' || !Array.isArray(parsed?.plan?.data?.items)) return body;
      const byId = new Map([...document.querySelectorAll('#stage .structure[data-id]')].map(el => [el.dataset.id, el]));
      parsed.plan.data.version = Math.max(6, Number(parsed.plan.data.version) || 0);
      parsed.plan.data.items = parsed.plan.data.items.map(item => {
        const el = byId.get(String(item.id || ''));
        if (!el || el.dataset.type !== 'aisle') return item;
        let points = null;
        try { points = JSON.parse(el.dataset.counterPoints || 'null'); } catch {}
        return {...item, semanticType:'counter', counterPoints:Array.isArray(points) ? points : undefined};
      });
      return JSON.stringify(parsed);
    } catch { return body; }
  }

  window.fetch = async (input, init = {}) => {
    const raw = typeof input === 'string' || input instanceof URL ? String(input) : String(input?.url || '');
    const isFloorPlan = /(?:^|\/)api\/floor-plans\.php(?:\?|$)/.test(raw);
    if (!isFloorPlan) return nativeFetch(input, init);
    const nextUrl = routeFloorPlanUrl(raw);
    const nextInit = {...init};
    if (String(nextInit.method || 'GET').toUpperCase() === 'POST') nextInit.body = enrichSaveBody(nextInit.body);
    const response = await nativeFetch(nextUrl, nextInit);
    if (response.ok && String(nextInit.method || 'GET').toUpperCase() === 'GET' && /[?&]id=/.test(nextUrl)) {
      response.clone().json().then(data => {
        if (data?.plan?.id) {
          planPayloads.set(String(data.plan.id), data.plan);
          setTimeout(applyCachedCounters, 0);
          setTimeout(applyCachedCounters, 120);
        }
      }).catch(() => {});
    }
    return response;
  };

  function init() {
    const stage = document.getElementById('stage');
    const holder = document.getElementById('holder');
    const inspector = document.querySelector('.inspect');
    const canvas = document.querySelector('.canvas-wrap');
    if (!stage || !holder || !inspector || !canvas) return;

    injectStyles();
    normalizeLabels();
    installInspector(inspector);
    installEquipmentPalette();
    installHistoryButtons();

    const selection = new Set();
    let primary = null;
    let marquee = null;
    let groupDrag = null;
    let counterContext = null;
    let restoring = false;
    const history = [];
    const future = [];
    let historyTimer = 0;

    function editableTarget(target) {
      return target instanceof HTMLElement && !!target.closest('input,textarea,select,[contenteditable="true"]');
    }

    function itemFrom(target) {
      return target instanceof Element ? target.closest('#stage .structure,#stage .equipment') : null;
    }

    function scale() { return Math.max(8, Number(document.getElementById('scale')?.value) || 24); }
    function zoom() { return Math.max(.1, Number(document.getElementById('zoom')?.value) / 100 || 1); }
    function planId() {
      return new URLSearchParams(location.search).get('plan') || document.getElementById('planSelect')?.value || '';
    }

    async function api(url, options = {}) {
      const request = {...options, headers:{Accept:'application/json', ...(options.headers || {})}};
      if (request.method && request.method !== 'GET') request.headers['X-CSRF-Token'] = csrf;
      if (request.body && typeof request.body !== 'string') {
        request.headers['Content-Type'] = 'application/json';
        request.body = JSON.stringify(request.body);
      }
      const response = await nativeFetch(url, request);
      let data = null;
      try { data = await response.json(); } catch { throw new Error(`Invalid server response (${response.status}).`); }
      if (!response.ok || data?.ok === false) throw new Error(data?.message || `Request failed (${response.status}).`);
      return data;
    }

    function setStatus(message, bad = false) {
      const status = document.getElementById('status');
      if (!status) return;
      status.textContent = message;
      status.style.color = bad ? '#ffb4ad' : '#c5cad0';
      clearTimeout(setStatus.timer);
      setStatus.timer = setTimeout(() => { status.textContent = 'Ready'; status.style.color = '#c5cad0'; }, 2400);
    }

    function syncSelection() {
      stage.querySelectorAll('.structure,.equipment').forEach(el => {
        const active = selection.has(el);
        el.classList.toggle('selected', active);
        el.classList.toggle('fp-selected', active);
      });
      if (primary && !selection.has(primary)) primary = selection.values().next().value || null;
      stage.querySelectorAll('.fp-primary').forEach(el => el.classList.remove('fp-primary'));
      if (primary) primary.classList.add('fp-primary');
      updateStructureInspector();
    }

    function replaceSelection(items, preferred = null) {
      selection.clear();
      items.filter(Boolean).forEach(item => selection.add(item));
      primary = preferred && selection.has(preferred) ? preferred : (items[0] || null);
      syncSelection();
    }

    function toggleSelection(item) {
      if (selection.has(item)) selection.delete(item); else selection.add(item);
      primary = selection.has(item) ? item : (selection.values().next().value || null);
      syncSelection();
    }

    function openInspector(item = primary) {
      if (!item) return;
      inspector.classList.add('fp-open');
      primary = item;
      if (!selection.has(item)) selection.add(item);
      syncSelection();
      if (item.classList.contains('equipment')) loadEquipmentInspector(item.dataset.assetId);
    }

    function closeInspector() { inspector.classList.remove('fp-open'); }

    function localPoint(event) {
      const bounds = stage.getBoundingClientRect();
      return {x:(event.clientX - bounds.left) / zoom(), y:(event.clientY - bounds.top) / zoom()};
    }

    function itemRect(el) {
      return {
        x:parseFloat(el.style.left) || 0,
        y:parseFloat(el.style.top) || 0,
        w:parseFloat(el.style.width) || el.offsetWidth || 0,
        h:parseFloat(el.style.height) || el.offsetHeight || 0,
      };
    }

    function intersects(a, b) {
      return a.x <= b.x + b.w && a.x + a.w >= b.x && a.y <= b.y + b.h && a.y + a.h >= b.y;
    }

    function startMarquee(event) {
      if (!cfg.canEdit || event.button !== 0) return;
      const start = localPoint(event);
      const add = event.shiftKey || event.ctrlKey || event.metaKey;
      marquee = document.createElement('div');
      marquee.className = 'fp-marquee';
      marquee.dataset.startX = String(start.x);
      marquee.dataset.startY = String(start.y);
      marquee.dataset.add = add ? '1' : '0';
      stage.appendChild(marquee);
      event.preventDefault();
      event.stopImmediatePropagation();

      const move = current => {
        const point = localPoint(current);
        const x = Math.min(start.x, point.x), y = Math.min(start.y, point.y);
        marquee.style.left = x + 'px'; marquee.style.top = y + 'px';
        marquee.style.width = Math.abs(point.x - start.x) + 'px';
        marquee.style.height = Math.abs(point.y - start.y) + 'px';
      };
      const up = current => {
        window.removeEventListener('pointermove', move, true);
        window.removeEventListener('pointerup', up, true);
        const point = localPoint(current);
        const box = {x:Math.min(start.x,point.x), y:Math.min(start.y,point.y), w:Math.abs(point.x-start.x), h:Math.abs(point.y-start.y)};
        const captured = [...stage.querySelectorAll('.structure,.equipment')].filter(el => intersects(box, itemRect(el)));
        if (marquee.dataset.add !== '1') selection.clear();
        captured.forEach(el => selection.add(el));
        primary = captured[0] || selection.values().next().value || null;
        marquee.remove(); marquee = null;
        syncSelection();
      };
      window.addEventListener('pointermove', move, true);
      window.addEventListener('pointerup', up, true);
    }

    function startGroupDrag(event, anchor) {
      if (!cfg.canEdit || event.button !== 0 || selection.size < 2 || !selection.has(anchor)) return false;
      const startX = event.clientX, startY = event.clientY;
      const origins = [...selection].map(el => ({el, left:parseFloat(el.style.left)||0, top:parseFloat(el.style.top)||0}));
      groupDrag = {origins};
      event.preventDefault();
      event.stopImmediatePropagation();
      const move = current => {
        const dx = (current.clientX - startX) / zoom();
        const dy = (current.clientY - startY) / zoom();
        origins.forEach(({el,left,top}) => {
          el.style.left = Math.max(0, left + dx) + 'px';
          el.style.top = Math.max(0, top + dy) + 'px';
        });
      };
      const up = async () => {
        window.removeEventListener('pointermove', move, true);
        window.removeEventListener('pointerup', up, true);
        groupDrag = null;
        await persistSelectedEquipment();
        recordHistory();
      };
      window.addEventListener('pointermove', move, true);
      window.addEventListener('pointerup', up, true);
      return true;
    }

    async function persistSelectedEquipment() {
      const id = planId();
      if (!id) return;
      const jobs = [...selection].filter(el => el.classList.contains('equipment')).map(el => api('api/equipment-placement.php', {
        method:'POST', body:{
          action:'move', assetId:el.dataset.assetId, planId:id,
          xFt:(parseFloat(el.style.left)||0)/scale(), yFt:(parseFloat(el.style.top)||0)/scale(),
          rotationDeg:Number(el.dataset.rotation)||0, zIndex:Number(el.style.zIndex)||10,
        }
      }));
      if (!jobs.length) return;
      try { await Promise.all(jobs); setStatus(`Moved ${selection.size} selected item${selection.size===1?'':'s'}`); }
      catch (error) { setStatus(error.message, true); }
    }

    function nudge(dx, dy) {
      if (!cfg.canEdit || !selection.size) return;
      selection.forEach(el => {
        el.style.left = Math.max(0,(parseFloat(el.style.left)||0)+dx) + 'px';
        el.style.top = Math.max(0,(parseFloat(el.style.top)||0)+dy) + 'px';
      });
      clearTimeout(nudge.timer);
      nudge.timer = setTimeout(async () => { await persistSelectedEquipment(); recordHistory(); }, 160);
    }

    function structureState(el) {
      return {
        id:el.dataset.id || '', type:el.dataset.type || '', className:el.className.replace(/\b(selected|fp-selected|fp-primary)\b/g,'').replace(/\s+/g,' ').trim(),
        left:el.style.left, top:el.style.top, width:el.style.width, height:el.style.height, transform:el.style.transform, z:el.style.zIndex,
        rotation:el.dataset.rotation || '0', seats:el.dataset.seats || '0', seatZone:el.dataset.seatZone || 'none',
        label:el.querySelector('.label')?.textContent || '', counterPoints:el.dataset.counterPoints || '',
      };
    }

    function captureSnapshot() {
      return {
        structures:[...stage.querySelectorAll('.structure')].map(structureState),
        equipment:[...stage.querySelectorAll('.equipment')].map(el => ({
          id:el.dataset.assetId || '',left:el.style.left,top:el.style.top,width:el.style.width,height:el.style.height,transform:el.style.transform,z:el.style.zIndex,rotation:el.dataset.rotation||'0'
        }))
      };
    }

    function snapshotKey(snapshot) { return JSON.stringify(snapshot); }

    function recordHistory() {
      if (restoring) return;
      clearTimeout(historyTimer);
      historyTimer = setTimeout(() => {
        const snap = captureSnapshot();
        if (history.length && snapshotKey(history[history.length-1]) === snapshotKey(snap)) return;
        history.push(snap);
        if (history.length > 80) history.shift();
        future.length = 0;
        updateHistoryButtons();
      }, 20);
    }

    function rebuildStructure(state) {
      const el = document.createElement('div');
      el.className = state.className || 'structure';
      el.dataset.id = state.id; el.dataset.type = state.type; el.dataset.rotation = state.rotation;
      el.dataset.seats = state.seats; el.dataset.seatZone = state.seatZone;
      if (state.counterPoints) el.dataset.counterPoints = state.counterPoints;
      el.style.left=state.left; el.style.top=state.top; el.style.width=state.width; el.style.height=state.height; el.style.transform=state.transform; el.style.zIndex=state.z;
      el.innerHTML='<span class="label"></span><span class="dim"></span><span class="resize"></span>';
      el.querySelector('.label').textContent=state.label;
      stage.appendChild(el);
      if (state.type === 'aisle') renderCounter(el);
      return el;
    }

    async function restoreSnapshot(snapshot) {
      restoring = true;
      selection.clear(); primary = null;
      const structureMap = new Map([...stage.querySelectorAll('.structure')].map(el => [el.dataset.id,el]));
      const wanted = new Set(snapshot.structures.map(item => item.id));
      structureMap.forEach((el,id) => { if (!wanted.has(id)) el.remove(); });
      snapshot.structures.forEach(state => {
        let el = structureMap.get(state.id);
        if (!el || !el.isConnected) el = rebuildStructure(state);
        el.className=state.className; el.dataset.type=state.type; el.dataset.rotation=state.rotation; el.dataset.seats=state.seats; el.dataset.seatZone=state.seatZone;
        el.style.left=state.left; el.style.top=state.top; el.style.width=state.width; el.style.height=state.height; el.style.transform=state.transform; el.style.zIndex=state.z;
        if (el.querySelector('.label')) el.querySelector('.label').textContent=state.label;
        if (state.counterPoints) el.dataset.counterPoints=state.counterPoints; else delete el.dataset.counterPoints;
        if (state.type==='aisle') renderCounter(el);
      });
      const equipmentMap = new Map([...stage.querySelectorAll('.equipment')].map(el => [el.dataset.assetId,el]));
      snapshot.equipment.forEach(state => {
        const el=equipmentMap.get(state.id); if(!el)return;
        el.style.left=state.left; el.style.top=state.top; el.style.width=state.width; el.style.height=state.height; el.style.transform=state.transform; el.style.zIndex=state.z; el.dataset.rotation=state.rotation;
      });
      syncSelection();
      await persistAllEquipment(snapshot.equipment);
      restoring = false;
    }

    async function persistAllEquipment(states) {
      const id=planId(); if(!id)return;
      const jobs=states.map(state=>api('api/equipment-placement.php',{method:'POST',body:{action:'move',assetId:state.id,planId:id,xFt:(parseFloat(state.left)||0)/scale(),yFt:(parseFloat(state.top)||0)/scale(),rotationDeg:Number(state.rotation)||0,zIndex:Number(state.z)||10}}));
      try{await Promise.all(jobs);}catch(error){setStatus(error.message,true);}
    }

    async function undo() {
      if (history.length < 2) return;
      const current=history.pop(); future.push(current);
      await restoreSnapshot(history[history.length-1]);
      updateHistoryButtons(); setStatus('Undo');
    }
    async function redo() {
      if (!future.length) return;
      const next=future.pop(); history.push(next); await restoreSnapshot(next); updateHistoryButtons(); setStatus('Redo');
    }
    function updateHistoryButtons(){
      const u=document.getElementById('fpUndoBtn'),r=document.getElementById('fpRedoBtn'); if(u)u.disabled=history.length<2;if(r)r.disabled=!future.length;
    }

    function counterPoints(el) {
      try { const points=JSON.parse(el.dataset.counterPoints||'null'); if(Array.isArray(points)&&points.length>=3)return points; } catch {}
      return [{x:0,y:0},{x:1,y:0},{x:1,y:1},{x:0,y:1}];
    }

    function renderCounter(el) {
      if (!el || el.dataset.type !== 'aisle') return;
      el.classList.add('fp-counter');
      if (!el.dataset.counterPoints) el.dataset.counterPoints=JSON.stringify(counterPoints(el));
      let svg=el.querySelector('.fp-counter-shape');
      if(!svg){svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.classList.add('fp-counter-shape');svg.setAttribute('viewBox','0 0 1000 1000');svg.setAttribute('preserveAspectRatio','none');svg.innerHTML='<polygon></polygon>';el.prepend(svg);}
      const points=counterPoints(el);
      svg.querySelector('polygon').setAttribute('points',points.map(p=>`${Math.round(p.x*1000)},${Math.round(p.y*1000)}`).join(' '));
      el.querySelectorAll('.fp-counter-node').forEach(node=>node.remove());
      if(el.dataset.counterTransform==='1') points.forEach((p,index)=>{
        const node=document.createElement('span');node.className='fp-counter-node';node.dataset.index=String(index);node.style.left=(p.x*100)+'%';node.style.top=(p.y*100)+'%';el.appendChild(node);
      });
      const label=el.querySelector('.label'); if(label && (!label.textContent || /aisle/i.test(label.textContent))) label.textContent='COUNTER';
    }

    function addCounterNode(el, point) {
      const points=counterPoints(el);
      let best=0,bestDistance=Infinity;
      for(let i=0;i<points.length;i++){
        const a=points[i],b=points[(i+1)%points.length];
        const vx=b.x-a.x,vy=b.y-a.y,wx=point.x-a.x,wy=point.y-a.y;
        const t=Math.max(0,Math.min(1,(wx*vx+wy*vy)/Math.max(.000001,vx*vx+vy*vy)));
        const px=a.x+t*vx,py=a.y+t*vy,d=(point.x-px)**2+(point.y-py)**2;
        if(d<bestDistance){bestDistance=d;best=i+1;}
      }
      points.splice(best,0,{x:Math.max(0,Math.min(1,point.x)),y:Math.max(0,Math.min(1,point.y))});
      el.dataset.counterPoints=JSON.stringify(points); el.dataset.counterTransform='1'; renderCounter(el); recordHistory();
    }

    function showCounterMenu(el,event) {
      hideCounterMenu();
      const rect=el.getBoundingClientRect();
      counterContext={el,point:{x:Math.max(0,Math.min(1,(event.clientX-rect.left)/Math.max(1,rect.width))),y:Math.max(0,Math.min(1,(event.clientY-rect.top)/Math.max(1,rect.height)))}};
      const menu=document.createElement('div');menu.id='fpCounterMenu';menu.className='fp-context-menu';menu.style.left=event.clientX+'px';menu.style.top=event.clientY+'px';
      menu.innerHTML='<button data-counter-action="add">Add Node</button><button data-counter-action="transform">Transform Shape</button><button data-counter-action="reset">Reset Shape</button>';
      document.body.appendChild(menu);
      menu.addEventListener('click',e=>{
        const action=e.target.closest('button')?.dataset.counterAction;if(!action)return;
        if(action==='add')addCounterNode(el,counterContext.point);
        if(action==='transform'){el.dataset.counterTransform=el.dataset.counterTransform==='1'?'0':'1';renderCounter(el);}
        if(action==='reset'){el.dataset.counterPoints=JSON.stringify([{x:0,y:0},{x:1,y:0},{x:1,y:1},{x:0,y:1}]);el.dataset.counterTransform='1';renderCounter(el);recordHistory();}
        hideCounterMenu();
      });
    }
    function hideCounterMenu(){document.getElementById('fpCounterMenu')?.remove();counterContext=null;}

    function applyCachedCountersLocal() {
      const id=planId(); const payload=planPayloads.get(id); const items=Array.isArray(payload?.data?.items)?payload.data.items:[];
      const rawById=new Map(items.map(item=>[String(item.id||''),item]));
      stage.querySelectorAll('.structure[data-type="aisle"]').forEach(el=>{
        const raw=rawById.get(el.dataset.id); if(raw?.counterPoints)el.dataset.counterPoints=JSON.stringify(raw.counterPoints);
        renderCounter(el);
      });
    }

    async function loadEquipmentInspector(assetId) {
      if(!assetId || !cfg.canEquipment)return;
      const detail=document.getElementById('assetDetail'); if(!detail)return;
      detail.innerHTML='<div class="empty">Loading complete equipment record…</div>';
      try{
        const data=await api('api/equipment.php?asset='+encodeURIComponent(assetId),{method:'GET'});
        const asset=(data.assets||[]).find(item=>item.id===assetId); if(!asset)throw new Error('Equipment asset not found.');
        const linkedContacts=(asset.contacts||[]).map(link=>({...link,...((data.contacts||[]).find(c=>c.id===link.id)||{})}));
        const events=(data.events||[]).filter(event=>event.assetId===assetId);
        const row=(key,value)=>`<div class="detail-row"><span>${esc(key)}</span><strong>${esc(value||'—')}</strong></div>`;
        detail.innerHTML=`<div class="fp-equipment-title">${esc(asset.name)}</div>${row('Type',asset.assetType)}${row('Brand / model',[asset.brand,asset.model].filter(Boolean).join(' '))}${row('Serial / tag',[asset.serialNumber,asset.assetTag].filter(Boolean).join(' · '))}${row('Status',String(asset.operationalStatus||'').replaceAll('_',' '))}${row('Criticality',asset.criticality)}${row('Purpose',asset.purpose)}${row('Dimensions',[asset.widthInches&&asset.widthInches+' in W',asset.depthInches&&asset.depthInches+' in D',asset.heightInches&&asset.heightInches+' in H'].filter(Boolean).join(' × '))}${row('Utilities',[asset.utilityType,asset.voltage,asset.phase,asset.amperage].filter(Boolean).join(' · '))}${row('Water',asset.waterRequirement)}${row('Drain',asset.drainRequirement)}${row('Ventilation',asset.ventilationRequirement)}${row('Warranty expires',asset.warrantyExpiresOn)}${row('Last service',asset.lastServiceOn)}${row('Next service',asset.nextServiceOn)}${row('Maintenance interval',asset.maintenanceIntervalDays?asset.maintenanceIntervalDays+' days':'')}${row('Replacement cost',asset.replacementCost!=null?'$'+Number(asset.replacementCost).toLocaleString(): '')}<div class="fp-detail-heading">Service contracts & contacts</div>${linkedContacts.length?linkedContacts.map(c=>`<div class="fp-service-card"><b>${esc(c.companyName||'Service provider')}</b><span>${esc(c.contactName||c.specialty||c.role||'')}</span><span>${esc(c.phone||c.emergencyPhone||'')}</span><span>${c.contractNumber?'Contract '+esc(c.contractNumber):''}${c.accountNumber?' · Acct '+esc(c.accountNumber):''}</span></div>`).join(''):'<div class="empty">No linked service contacts.</div>'}<div class="fp-detail-heading">Service history</div>${events.length?events.slice(0,8).map(e=>`<div class="fp-service-card"><b>${esc(e.servicedOn)} · ${esc(e.eventType)}</b><span>${esc(e.description)}</span><span>${esc(e.companyName||e.technicianName||'')}</span>${e.cost!=null?`<span>$${Number(e.cost).toFixed(2)}</span>`:''}</div>`).join(''):'<div class="empty">No service history recorded.</div>'}<div class="fp-detail-heading">Operating knowledge</div>${row('Maintenance',asset.maintenanceNotes)}${row('Cleaning',asset.cleaningNotes)}${row('Operating',asset.operatingNotes)}${row('Safety',asset.safetyNotes)}${row('Parts / consumables',asset.partsConsumables)}${asset.manualUrl?`<a class="fp-manual" target="_blank" rel="noopener" href="${escAttr(asset.manualUrl)}">Open manual / manufacturer link ↗</a>`:''}`;
      }catch(error){detail.innerHTML=`<div class="alert bad">${esc(error.message)}</div>`;}
    }

    function updateStructureInspector() {
      if(!primary || !primary.classList.contains('structure'))return;
      const label=document.getElementById('sLabel'),w=document.getElementById('sW'),h=document.getElementById('sH'),seats=document.getElementById('sSeats'),zone=document.getElementById('sSeatZone');
      if(label)label.value=primary.querySelector('.label')?.textContent||'';
      if(w)w.value=((parseFloat(primary.style.width)||0)/scale()).toFixed(2);
      if(h)h.value=((parseFloat(primary.style.height)||0)/scale()).toFixed(2);
      if(seats)seats.value=primary.dataset.seats||'0';if(zone)zone.value=primary.dataset.seatZone||'none';
    }

    function applyInspectorInput(event) {
      if(!primary || !primary.classList.contains('structure') || !cfg.canEdit)return;
      const id=event.target.id;if(!['sLabel','sW','sH','sSeats','sSeatZone'].includes(id))return;
      event.stopImmediatePropagation();
      if(id==='sLabel'&&primary.querySelector('.label'))primary.querySelector('.label').textContent=event.target.value;
      if(id==='sW')primary.style.width=Math.max(scale()/2,(Number(event.target.value)||.5)*scale())+'px';
      if(id==='sH')primary.style.height=Math.max(scale()/2,(Number(event.target.value)||.5)*scale())+'px';
      if(id==='sSeats')primary.dataset.seats=String(Math.max(0,Number(event.target.value)||0));
      if(id==='sSeatZone')primary.dataset.seatZone=event.target.value;
      if(primary.dataset.type==='aisle')renderCounter(primary);recordHistory();
    }

    function deleteSelection(event) {
      if(!cfg.canEdit||!selection.size)return;
      event?.preventDefault();event?.stopImmediatePropagation();
      const equipment=[...selection].filter(el=>el.classList.contains('equipment'));
      const structures=[...selection].filter(el=>el.classList.contains('structure'));
      structures.forEach(el=>el.remove());
      Promise.all(equipment.map(el=>api('api/equipment-placement.php',{method:'POST',body:{action:'unplace',assetId:el.dataset.assetId}}).then(()=>el.remove()))).catch(error=>setStatus(error.message,true));
      selection.clear();primary=null;syncSelection();recordHistory();
    }

    function rotateSelection(event) {
      if(!cfg.canEdit||!selection.size)return;
      event?.preventDefault();event?.stopImmediatePropagation();
      selection.forEach(el=>{const r=((Number(el.dataset.rotation)||0)+90)%360;el.dataset.rotation=String(r);el.style.transform=`rotate(${r}deg)`;});
      persistSelectedEquipment();recordHistory();
    }

    stage.addEventListener('pointerdown',event=>{
      if(event.target.closest('.fp-counter-node')){
        const node=event.target.closest('.fp-counter-node'),el=node.closest('.fp-counter');if(!el||!cfg.canEdit)return;
        event.preventDefault();event.stopImmediatePropagation();
        const index=Number(node.dataset.index);const start=counterPoints(el);const bounds=el.getBoundingClientRect();
        const move=current=>{const points=counterPoints(el);points[index]={x:Math.max(0,Math.min(1,(current.clientX-bounds.left)/Math.max(1,bounds.width))),y:Math.max(0,Math.min(1,(current.clientY-bounds.top)/Math.max(1,bounds.height)))};el.dataset.counterPoints=JSON.stringify(points);renderCounter(el);};
        const up=()=>{window.removeEventListener('pointermove',move,true);window.removeEventListener('pointerup',up,true);recordHistory();};
        window.addEventListener('pointermove',move,true);window.addEventListener('pointerup',up,true);return;
      }
      const item=itemFrom(event.target);
      if(!item){if(event.target===stage)startMarquee(event);return;}
      const additive=event.shiftKey||event.ctrlKey||event.metaKey;
      if(additive){event.preventDefault();event.stopImmediatePropagation();toggleSelection(item);return;}
      if(selection.size>1&&selection.has(item)){primary=item;syncSelection();startGroupDrag(event,item);}
    },true);

    stage.addEventListener('click',event=>{
      const item=itemFrom(event.target);if(!item)return;
      if(event.detail>=3){event.preventDefault();event.stopImmediatePropagation();replaceSelection(selection.has(item)?[...selection]:[item],item);openInspector(item);return;}
      if(event.shiftKey||event.ctrlKey||event.metaKey){event.preventDefault();event.stopImmediatePropagation();return;}
      if(selection.size<=1||!selection.has(item))setTimeout(()=>replaceSelection([item],item),0);
    },true);
    stage.addEventListener('dblclick',event=>{if(itemFrom(event.target)){event.preventDefault();event.stopImmediatePropagation();}},true);
    stage.addEventListener('contextmenu',event=>{const item=itemFrom(event.target);if(item?.dataset.type==='aisle'){event.preventDefault();replaceSelection([item],item);showCounterMenu(item,event);openInspector(item);}else hideCounterMenu();},true);
    document.addEventListener('pointerdown',event=>{if(!event.target.closest('#fpCounterMenu'))hideCounterMenu();},true);
    document.addEventListener('input',applyInspectorInput,true);

    document.addEventListener('keydown',event=>{
      if(editableTarget(event.target))return;
      const mod=event.ctrlKey||event.metaKey;
      if(mod&&event.key.toLowerCase()==='z'){event.preventDefault();event.shiftKey?redo():undo();return;}
      if(mod&&event.key.toLowerCase()==='y'){event.preventDefault();redo();return;}
      if((event.key==='Delete'||event.key==='Backspace')&&selection.size){deleteSelection(event);return;}
      if(!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(event.key)||!selection.size)return;
      event.preventDefault();const step=scale()*(event.shiftKey?.5:1/12);
      if(event.key==='ArrowLeft')nudge(-step,0);if(event.key==='ArrowRight')nudge(step,0);if(event.key==='ArrowUp')nudge(0,-step);if(event.key==='ArrowDown')nudge(0,step);
    });

    document.getElementById('fpInspectorClose')?.addEventListener('click',closeInspector);
    document.getElementById('fpInspectorBtn')?.addEventListener('click',()=>primary?openInspector(primary):inspector.classList.toggle('fp-open'));
    document.getElementById('fpUndoBtn')?.addEventListener('click',undo);
    document.getElementById('fpRedoBtn')?.addEventListener('click',redo);
    document.getElementById('deleteBtn')?.addEventListener('click',event=>{if(selection.size>1)deleteSelection(event);},true);
    document.getElementById('rotateBtn')?.addEventListener('click',event=>{if(selection.size>1)rotateSelection(event);},true);

    const observer=new MutationObserver(()=>{applyCachedCountersLocal();clearTimeout(observer.timer);observer.timer=setTimeout(recordHistory,180);});
    observer.observe(stage,{childList:true,subtree:false});
    setTimeout(()=>{applyCachedCountersLocal();history.push(captureSnapshot());updateHistoryButtons();},450);
  }

  function injectStyles(){
    const style=document.createElement('style');style.id='floorPlannerV2Styles';style.textContent=`
      .workspace{grid-template-columns:285px minmax(500px,1fr)!important}.inspect{position:fixed!important;right:0!important;top:60px!important;bottom:0!important;width:min(430px,92vw)!important;z-index:120000!important;transform:translateX(105%);transition:transform .2s ease;box-shadow:-16px 0 40px rgba(0,0,0,.18);border-left:1px solid var(--line);background:#fff}.inspect.fp-open{transform:translateX(0)}.fp-inspector-head{position:sticky;top:0;z-index:3;display:flex;justify-content:space-between;align-items:center;padding:10px 12px;background:#111214;color:#fff}.fp-inspector-head button{border:1px solid #444;background:#222;color:#fff;border-radius:8px;padding:6px 9px}.fp-marquee{position:absolute!important;z-index:199999!important;border:1px solid #2563eb!important;background:rgba(37,99,235,.12)!important;pointer-events:none!important}.fp-selected{outline:3px solid #2563eb!important;outline-offset:2px}.fp-primary{outline-color:#111!important}.fp-selected:not(.fp-primary) .resize{display:none!important}.fp-counter{background:transparent!important;border:0!important;overflow:visible!important}.fp-counter-shape{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;overflow:visible}.fp-counter-shape polygon{fill:#b48a62;stroke:#654a34;stroke-width:18;vector-effect:non-scaling-stroke}.fp-counter-node{position:absolute;width:13px;height:13px;border-radius:50%;background:#fff;border:3px solid #2563eb;transform:translate(-50%,-50%);z-index:9;cursor:move}.fp-context-menu{position:fixed;z-index:250000;display:grid;min-width:150px;padding:5px;background:#fff;border:1px solid #d9dde3;border-radius:9px;box-shadow:0 12px 30px rgba(0,0,0,.18)}.fp-context-menu button{border:0;background:#fff;text-align:left;padding:8px;border-radius:6px}.fp-context-menu button:hover{background:#f1f3f5}.fp-equipment-title{font-size:16px;font-weight:900;margin-bottom:8px}.fp-detail-heading{margin:14px 0 7px;font-size:10px;text-transform:uppercase;letter-spacing:.08em;font-weight:900;color:#555}.fp-service-card{display:grid;gap:2px;padding:8px;border:1px solid #e3e6e8;border-radius:8px;margin-bottom:6px;font-size:10px}.fp-service-card span{color:#666}.fp-manual{display:block;margin-top:9px;font-size:10px;font-weight:800}.top .fp-toolbar{display:flex;gap:5px}.top .fp-toolbar button:disabled{opacity:.4}.structure.aisle .label{z-index:2;color:#fff;text-shadow:0 1px 2px #000}.structure.aisle .dim{z-index:3}
    `;document.head.appendChild(style);
  }

  function normalizeLabels(){
    const aisle=document.querySelector('[data-structure="aisle"]');if(aisle){aisle.innerHTML='<span class="ico">⌞</span>Counter';}
    const empty=document.getElementById('selectionEmpty');if(empty)empty.textContent='Triple-click a floor item to open its control panel. Drag empty canvas to box-select a group.';
    document.querySelectorAll('.mini-metric').forEach(el=>{if(/Narrowest marked aisle/i.test(el.textContent))el.childNodes[0].textContent='Minimum counter depth';});
    document.querySelectorAll('.sec').forEach(sec=>{if(/Tables, walls, aisles and seating remain layout objects/.test(sec.textContent))sec.innerHTML=sec.innerHTML.replace('Tables, walls, aisles and seating remain layout objects.','Tables, walls, counters and seating remain layout objects.');});
  }

  function installInspector(inspector){
    if(document.getElementById('fpInspectorClose'))return;
    const head=document.createElement('div');head.className='fp-inspector-head';head.innerHTML='<strong>Item Control Panel</strong><button id="fpInspectorClose" type="button">Close ×</button>';inspector.prepend(head);
    const top=document.querySelector('.top');if(top&&!document.getElementById('fpInspectorBtn')){const button=document.createElement('button');button.id='fpInspectorBtn';button.type='button';button.textContent='Inspector';top.insertBefore(button,top.querySelector('.spacer'));}
  }

  function installHistoryButtons(){
    const top=document.querySelector('.top');if(!top||document.getElementById('fpUndoBtn'))return;
    const wrap=document.createElement('span');wrap.className='fp-toolbar';wrap.innerHTML='<button id="fpUndoBtn" type="button" title="Undo (Ctrl/Cmd+Z)">↶ Undo</button><button id="fpRedoBtn" type="button" title="Redo (Ctrl/Cmd+Y)">↷ Redo</button>';top.insertBefore(wrap,top.querySelector('.spacer'));
  }

  function installEquipmentPalette(){
    const palette=document.querySelector('.palette');if(!palette||document.getElementById('fpEquipmentNode'))return;
    const node=document.createElement('div');node.className='pal';node.id='fpEquipmentNode';node.innerHTML='<span class="ico">E</span>Equipment';node.addEventListener('click',()=>{
      const catalog=document.getElementById('unplacedAssets');if(catalog&&catalog.querySelector('[data-asset-card]'))catalog.scrollIntoView({behavior:'smooth',block:'center'});else document.getElementById('assetModal')?.classList.add('open');
    });palette.appendChild(node);
  }

  function applyCachedCounters(){
    const stage=document.getElementById('stage');if(!stage)return;
    const id=new URLSearchParams(location.search).get('plan')||document.getElementById('planSelect')?.value||'';
    const payload=planPayloads.get(id);if(!payload)return;
    const items=Array.isArray(payload?.data?.items)?payload.data.items:[];const map=new Map(items.map(item=>[String(item.id||''),item]));
    stage.querySelectorAll('.structure[data-type="aisle"]').forEach(el=>{const raw=map.get(el.dataset.id);if(raw?.counterPoints)el.dataset.counterPoints=JSON.stringify(raw.counterPoints);});
  }

  function esc(value){return String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
  function escAttr(value){return esc(value).replace(/`/g,'&#96;');}

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else setTimeout(init,0);
})();
