(function(){
    'use strict';

    var boot=window.GELATO_GLASS_LEARNING||{};
    var catalog=boot.catalog||{};
    var locations=Array.isArray(catalog.locations)?catalog.locations:[];
    var stationsByLocation=catalog.stationsByLocation||{};

    var el={
        location:document.getElementById('glLocation'),
        station:document.getElementById('glStation'),
        days:document.getElementById('glDays'),
        refresh:document.getElementById('glRefresh'),
        exportButton:document.getElementById('glExport'),
        status:document.getElementById('glStatus'),
        cards:document.getElementById('glCards'),
        confidence:document.getElementById('glConfidenceBody'),
        evidence:document.getElementById('glEvidenceBody'),
        labels:document.getElementById('glLabelsBody'),
        components:document.getElementById('glComponentsBody'),
        toast:document.getElementById('glToast')
    };

    var loading=false;

    function escapeHtml(value){
        return String(value==null?'':value)
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;')
            .replace(/'/g,'&#039;');
    }

    function pct(value){
        var number=Number(value);
        if(!Number.isFinite(number))return '—';
        return (number*100).toFixed(number===0?0:1)+'%';
    }

    function number(value){
        var n=Number(value);
        return Number.isFinite(n)?n.toLocaleString():'—';
    }

    function confidence(value){
        if(value===null||value===undefined||value==='')return '—';
        var n=Number(value);
        return Number.isFinite(n)?n.toFixed(2):'—';
    }

    function toast(message,isError){
        if(!el.toast)return;
        el.toast.textContent=String(message||'');
        el.toast.classList.toggle('error',!!isError);
        el.toast.hidden=false;
        clearTimeout(toast.timer);
        toast.timer=setTimeout(function(){el.toast.hidden=true;},3200);
    }

    function setOptions(select,items,valueKey,labelKey,placeholder){
        var html='<option value="">'+escapeHtml(placeholder)+'</option>';
        items.forEach(function(item){
            html+='<option value="'+escapeHtml(item[valueKey])+'">'+escapeHtml(item[labelKey])+'</option>';
        });
        select.innerHTML=html;
    }

    function currentLocationId(){
        return Number(el.location.value||0);
    }

    function populateStations(){
        var stations=stationsByLocation[String(currentLocationId())]||[];
        var html='<option value="">All stations</option>';
        stations.forEach(function(station){
            html+='<option value="'+escapeHtml(station.publicId)+'">'+escapeHtml(station.name)+'</option>';
        });
        el.station.innerHTML=html;
    }

    function init(){
        setOptions(el.location,locations,'id','name','Choose location');
        if(locations.length){
            var primary=locations.find(function(location){return location.primary;})||locations[0];
            el.location.value=String(primary.id);
        }
        populateStations();
        if(boot.defaults&&boot.defaults.days)el.days.value=String(boot.defaults.days);

        el.location.addEventListener('change',function(){
            populateStations();
            load();
        });
        el.station.addEventListener('change',load);
        el.days.addEventListener('change',load);
        el.refresh.addEventListener('click',load);
        el.exportButton.addEventListener('click',download);

        if(currentLocationId())load();
    }

    function query(view){
        var params=new URLSearchParams();
        params.set('view',view||'summary');
        params.set('locationId',String(currentLocationId()));
        params.set('days',String(Number(el.days.value||30)));
        if(el.station.value)params.set('stationPublicId',String(el.station.value));
        return boot.apiUrl+'?'+params.toString();
    }

    function setLoading(value){
        loading=!!value;
        el.refresh.disabled=loading;
        el.location.disabled=loading;
        el.station.disabled=loading;
        el.days.disabled=loading;
        if(loading)el.status.textContent='Loading AR learning signals…';
    }

    function renderCards(totals){
        var values=[
            [number(totals.observations),'Observations','Vision evidence in scope'],
            [number(totals.humanCorrected),'Human corrected','Correction ledger entries'],
            [pct(totals.humanCorrectionRate),'Correction rate','Human intervention rate'],
            [number(totals.rejected),'Rejected','False-positive corrections'],
            [number(totals.reclassified),'Reclassified','Ingredient label corrections'],
            [confidence(totals.meanModelConfidence),'Mean confidence','Model confidence, not accuracy']
        ];
        var html='';
        values.forEach(function(item){
            html+='<article><span>'+escapeHtml(item[1])+'</span><strong>'+escapeHtml(item[0])+'</strong><small>'+escapeHtml(item[2])+'</small></article>';
        });
        el.cards.innerHTML=html;
    }

    function renderConfidence(rows){
        if(!rows||!rows.length){
            el.confidence.innerHTML='<tr><td colspan="6">No observations in this window.</td></tr>';
            return;
        }
        var html='';
        rows.forEach(function(row){
            html+='<tr><td><strong>'+escapeHtml(row.band)+'</strong></td><td>'+number(row.observations)+'</td><td>'+number(row.corrected)+'</td><td>'+number(row.rejected)+'</td><td>'+number(row.reclassified)+'</td><td>'+pct(row.correctionRate)+'</td></tr>';
        });
        el.confidence.innerHTML=html;
    }

    function renderEvidence(rows){
        if(!rows||!rows.length){
            el.evidence.innerHTML='<tr><td colspan="4">No evidence metadata in this window.</td></tr>';
            return;
        }
        var html='';
        rows.forEach(function(row){
            html+='<tr><td><strong>'+escapeHtml(row.evidenceKind)+'</strong></td><td>'+number(row.observations)+'</td><td>'+number(row.corrected)+'</td><td>'+pct(row.correctionRate)+'</td></tr>';
        });
        el.evidence.innerHTML=html;
    }

    function renderLabels(rows){
        if(!rows||!rows.length){
            el.labels.innerHTML='<tr><td colspan="8">No detector-label metadata in this window.</td></tr>';
            return;
        }
        var html='';
        rows.forEach(function(row){
            html+='<tr>';
            html+='<td><strong>'+escapeHtml(row.detectorLabel)+'</strong></td>';
            html+='<td>'+(row.profileMatched?'Profile':'Name fallback')+'</td>';
            html+='<td>'+confidence(row.minimumConfidence)+'</td>';
            html+='<td>'+number(row.observations)+'</td>';
            html+='<td>'+number(row.corrected)+'</td>';
            html+='<td>'+number(row.rejected)+'</td>';
            html+='<td>'+number(row.reclassified)+'</td>';
            html+='<td>'+pct(row.correctionRate)+'</td>';
            html+='</tr>';
        });
        el.labels.innerHTML=html;
    }

    function renderComponents(rows){
        if(!rows||!rows.length){
            el.components.innerHTML='<tr><td colspan="7">No component evidence in this window.</td></tr>';
            return;
        }
        var html='';
        rows.forEach(function(row){
            html+='<tr>';
            html+='<td><strong>'+escapeHtml(row.componentName)+'</strong><small>'+escapeHtml(row.componentKey)+'</small></td>';
            html+='<td>'+number(row.observations)+'</td>';
            html+='<td>'+number(row.corrected)+'</td>';
            html+='<td>'+number(row.rejected)+'</td>';
            html+='<td>'+number(row.reclassified)+'</td>';
            html+='<td>'+number(row.quantityCorrected)+'</td>';
            html+='<td>'+pct(row.correctionRate)+'</td>';
            html+='</tr>';
        });
        el.components.innerHTML=html;
    }

    async function load(){
        if(loading)return;
        if(!currentLocationId()){
            el.status.textContent='Choose a location to load AR learning signals.';
            return;
        }

        setLoading(true);
        try{
            var response=await fetch(query('summary'),{
                credentials:'same-origin',
                headers:{'Accept':'application/json'}
            });
            var data=await response.json();
            if(!response.ok||!data.ok)throw new Error(data.message||'Learning analytics could not be loaded.');
            var analytics=data.analytics||{};
            renderCards(analytics.totals||{});
            renderConfidence(analytics.confidenceBands||[]);
            renderEvidence(analytics.evidenceKinds||[]);
            renderLabels(analytics.modelLabels||[]);
            renderComponents(analytics.components||[]);
            var count=Number((analytics.totals||{}).observations||0);
            el.status.textContent=count
                ? number(count)+' observations analyzed. Human correction rate is an intervention signal, not a verified accuracy score.'
                : 'No AR observations found for this location/station in the selected window.';
        }catch(error){
            el.status.textContent=error.message||'Learning analytics could not be loaded.';
            toast(el.status.textContent,true);
        }finally{
            setLoading(false);
        }
    }

    function download(){
        if(!catalog.canExport){
            toast('AR glasses management permission is required to export learning data.',true);
            return;
        }
        if(!currentLocationId()){
            toast('Choose a location first.',true);
            return;
        }
        var url=query('dataset')+'&correctedOnly=1&download=1&limit=20000';
        window.location.assign(url);
    }

    init();
})();