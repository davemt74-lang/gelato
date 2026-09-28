(function(){
    'use strict';

    var boot=window.GELATO_CALIBRATION_STUDIO||{};
    var G=window.GelatoCalibrationGeometry;
    if(!G)throw new Error('Calibration geometry module is required.');

    var catalog=boot.catalog||{};
    var locations=Array.isArray(catalog.locations)?catalog.locations:[];
    var stationsByLocation=catalog.stationsByLocation||{};
    var ingredients=Array.isArray(catalog.ingredients)?catalog.ingredients:[];

    var el={
        location:document.getElementById('gcsLocation'),
        station:document.getElementById('gcsStation'),
        platform:document.getElementById('gcsPlatform'),
        pixelFormat:document.getElementById('gcsPixelFormat'),
        frameWidth:document.getElementById('gcsFrameWidth'),
        frameHeight:document.getElementById('gcsFrameHeight'),
        referenceFile:document.getElementById('gcsReferenceFile'),
        referenceImage:document.getElementById('gcsReferenceImage'),
        emptyFrame:document.getElementById('gcsEmptyFrame'),
        canvas:document.getElementById('gcsCanvas'),
        overlay:document.getElementById('gcsOverlay'),
        drawHint:document.getElementById('gcsDrawHint'),
        ingredient:document.getElementById('gcsIngredient'),
        drawIngredient:document.getElementById('gcsDrawIngredient'),
        shapeList:document.getElementById('gcsShapeList'),
        shapeCount:document.getElementById('gcsShapeCount'),
        editorTitle:document.getElementById('gcsEditorTitle'),
        notes:document.getElementById('gcsNotes'),
        validation:document.getElementById('gcsValidation'),
        save:document.getElementById('gcsSave'),
        versions:document.getElementById('gcsVersions'),
        refreshVersions:document.getElementById('gcsRefreshVersions'),
        clear:document.getElementById('gcsClear'),
        toast:document.getElementById('gcsToast')
    };

    var state={
        shapes:[],
        selectedId:null,
        activeTool:null,
        drawing:null,
        drag:null,
        dirty:false,
        loadingVersions:false,
        saving:false,
        referenceUrl:null,
        versions:[]
    };

    function escapeHtml(value){
        return String(value==null?'':value)
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;')
            .replace(/'/g,'&#039;');
    }

    function clamp(value,min,max){return Math.max(min,Math.min(max,value));}

    function markDirty(value){
        state.dirty=value!==false;
    }

    function currentLocationId(){
        return Number(el.location.value||0);
    }

    function currentStation(){
        var publicId=String(el.station.value||'');
        var list=stationsByLocation[String(currentLocationId())]||[];
        return list.find(function(station){return station.publicId===publicId;})||null;
    }

    function ingredientById(id){
        id=Number(id);
        return ingredients.find(function(ingredient){return Number(ingredient.id)===id;})||null;
    }

    function toast(message,isError){
        if(!el.toast)return;
        el.toast.textContent=String(message||'');
        el.toast.classList.toggle('error',!!isError);
        el.toast.hidden=false;
        window.clearTimeout(toast.timer);
        toast.timer=window.setTimeout(function(){el.toast.hidden=true;},3200);
    }

    function applyAspectRatio(){
        var width=Math.max(16,Number(el.frameWidth.value||640));
        var height=Math.max(16,Number(el.frameHeight.value||480));
        el.canvas.style.aspectRatio=width+' / '+height;
        validateAndRenderSummary();
    }

    function setSelectOptions(select,items,valueKey,labelKey,placeholder){
        var html='<option value="">'+escapeHtml(placeholder)+'</option>';
        items.forEach(function(item){
            html+='<option value="'+escapeHtml(item[valueKey])+'">'+escapeHtml(item[labelKey])+'</option>';
        });
        select.innerHTML=html;
    }

    function initCatalog(){
        setSelectOptions(el.location,locations,'id','name','Choose location');
        if(locations.length){
            var primary=locations.find(function(location){return location.primary;})||locations[0];
            el.location.value=String(primary.id);
        }

        var ingredientOptions='<option value="">Choose ingredient</option>';
        ingredients.forEach(function(ingredient){
            ingredientOptions+='<option value="'+Number(ingredient.id)+'">'+escapeHtml(ingredient.name)+'</option>';
        });
        el.ingredient.innerHTML=ingredientOptions;

        populateStations();
        applyAspectRatio();
        renderAll();
        loadVersions();
    }

    function populateStations(){
        var list=stationsByLocation[String(currentLocationId())]||[];
        setSelectOptions(el.station,list,'publicId','name','Choose station');
        if(list.length)el.station.value=String(list[0].publicId);
        updateEditorTitle();
    }

    function updateEditorTitle(){
        var station=currentStation();
        var location=locations.find(function(item){return Number(item.id)===currentLocationId();});
        el.editorTitle.textContent=station
            ? station.name+(location?' · '+location.name:'')
            : 'Choose a station';
    }

    function pointFromEvent(event){
        var rect=el.canvas.getBoundingClientRect();
        return {
            x:clamp((event.clientX-rect.left)/Math.max(1,rect.width),0,1),
            y:clamp((event.clientY-rect.top)/Math.max(1,rect.height),0,1)
        };
    }

    function shapeById(id){
        return state.shapes.find(function(shape){return shape.id===id;})||null;
    }

    function selectShape(id){
        state.selectedId=id||null;
        renderOverlay();
        renderShapeList();
    }

    function activateTool(tool){
        state.activeTool=tool;
        state.selectedId=null;
        el.canvas.classList.add('drawing');
        el.drawHint.hidden=false;

        document.querySelectorAll('[data-region-tool],#gcsDrawIngredient').forEach(function(button){
            button.classList.remove('active');
        });
        if(tool.kind==='ingredient')el.drawIngredient.classList.add('active');
        else{
            var button=document.querySelector('[data-region-tool="'+CSS.escape(tool.regionType)+'"]');
            if(button)button.classList.add('active');
        }
        renderOverlay();
    }

    function cancelTool(){
        state.activeTool=null;
        el.canvas.classList.remove('drawing');
        el.drawHint.hidden=true;
        document.querySelectorAll('[data-region-tool],#gcsDrawIngredient').forEach(function(button){
            button.classList.remove('active');
        });
    }

    function createShapeFromTool(rect){
        if(!state.activeTool)return null;
        if(rect.width<0.005||rect.height<0.005)return null;

        if(state.activeTool.kind==='ingredient'){
            var ingredient=ingredientById(state.activeTool.ingredientId);
            if(!ingredient)throw new Error('Choose an ingredient before drawing.');
            return G.ingredientShape(ingredient,rect,state.shapes);
        }
        return G.regionShape(state.activeTool.regionType,rect,state.shapes);
    }

    function beginDraw(event){
        if(!state.activeTool||event.button!==0)return false;
        event.preventDefault();
        var start=pointFromEvent(event);
        state.drawing={pointerId:event.pointerId,start:start,current:start};
        el.canvas.setPointerCapture(event.pointerId);
        renderOverlay();
        return true;
    }

    function beginShapeDrag(event,shapeId,resize){
        if(state.activeTool||event.button!==0)return false;
        var shape=shapeById(shapeId);
        if(!shape)return false;
        event.preventDefault();
        event.stopPropagation();
        selectShape(shapeId);
        var start=pointFromEvent(event);
        state.drag={
            pointerId:event.pointerId,
            shapeId:shapeId,
            resize:!!resize,
            start:start,
            original:{x:shape.x,y:shape.y,width:shape.width,height:shape.height}
        };
        el.canvas.setPointerCapture(event.pointerId);
        return true;
    }

    function pointerMove(event){
        if(state.drawing&&state.drawing.pointerId===event.pointerId){
            state.drawing.current=pointFromEvent(event);
            renderOverlay();
            return;
        }
        if(!state.drag||state.drag.pointerId!==event.pointerId)return;
        var shape=shapeById(state.drag.shapeId);
        if(!shape)return;
        var point=pointFromEvent(event);
        var dx=point.x-state.drag.start.x;
        var dy=point.y-state.drag.start.y;
        var original=state.drag.original;

        if(state.drag.resize){
            shape.width=Math.max(0.005,Math.min(1-original.x,original.width+dx));
            shape.height=Math.max(0.005,Math.min(1-original.y,original.height+dy));
        }else{
            shape.x=clamp(original.x+dx,0,1-original.width);
            shape.y=clamp(original.y+dy,0,1-original.height);
        }
        Object.assign(shape,G.normalizeRect(shape));
        markDirty();
        renderOverlay();
        renderShapeList();
        validateAndRenderSummary();
    }

    function pointerUp(event){
        if(state.drawing&&state.drawing.pointerId===event.pointerId){
            var drawing=state.drawing;
            state.drawing=null;
            try{el.canvas.releasePointerCapture(event.pointerId);}catch(ignore){}
            var rect=G.rectFromPoints(drawing.start.x,drawing.start.y,drawing.current.x,drawing.current.y);
            try{
                var shape=createShapeFromTool(rect);
                if(shape){
                    state.shapes.push(shape);
                    state.selectedId=shape.id;
                    markDirty();
                }
            }catch(error){
                toast(error.message,true);
            }
            cancelTool();
            renderAll();
            return;
        }
        if(state.drag&&state.drag.pointerId===event.pointerId){
            state.drag=null;
            try{el.canvas.releasePointerCapture(event.pointerId);}catch(ignore){}
            renderAll();
        }
    }

    function deleteSelected(){
        if(!state.selectedId)return;
        var before=state.shapes.length;
        state.shapes=state.shapes.filter(function(shape){return shape.id!==state.selectedId;});
        if(state.shapes.length!==before)markDirty();
        state.selectedId=null;
        renderAll();
    }

    function shapeLabel(shape){
        if(shape.kind==='ingredient')return shape.displayName+' source';
        return shape.displayName||String(shape.regionType||'').replace(/_/g,' ');
    }

    function renderOverlay(){
        var html='';
        state.shapes.forEach(function(shape){
            var classes=['gcs-shape',shape.kind==='ingredient'?'ingredient':'region'];
            if(shape.kind==='region')classes.push('region-'+shape.regionType);
            if(shape.id===state.selectedId)classes.push('selected');
            html+='<div class="'+classes.join(' ')+'" data-shape-id="'+escapeHtml(shape.id)+'" style="left:'+(shape.x*100)+'%;top:'+(shape.y*100)+'%;width:'+(shape.width*100)+'%;height:'+(shape.height*100)+'%">';
            html+='<span>'+escapeHtml(shapeLabel(shape))+'</span>';
            html+='<button type="button" class="gcs-resize" data-resize="1" tabindex="-1" aria-label="Resize"></button>';
            html+='</div>';
        });

        if(state.drawing){
            var rect=G.rectFromPoints(
                state.drawing.start.x,state.drawing.start.y,
                state.drawing.current.x,state.drawing.current.y
            );
            html+='<div class="gcs-shape draft" style="left:'+(rect.x*100)+'%;top:'+(rect.y*100)+'%;width:'+(rect.width*100)+'%;height:'+(rect.height*100)+'%"><span>New area</span></div>';
        }
        el.overlay.innerHTML=html;
        el.shapeCount.textContent=state.shapes.length+' shape'+(state.shapes.length===1?'':'s');
    }

    function numberInput(name,value,min,max,step){
        return '<input type="number" data-field="'+name+'" value="'+Number(value).toFixed(3)+'" min="'+min+'" max="'+max+'" step="'+step+'">';
    }

    function renderShapeList(){
        if(!state.shapes.length){
            el.shapeList.innerHTML='<div class="gcs-empty-list">Draw ingredient source zones and a build surface on the reference view.</div>';
            return;
        }

        var html='';
        state.shapes.forEach(function(shape){
            var selected=shape.id===state.selectedId?' selected':'';
            html+='<article class="gcs-shape-row'+selected+'" data-list-shape="'+escapeHtml(shape.id)+'">';
            html+='<div class="gcs-shape-row-head">';
            html+='<button type="button" class="gcs-shape-select" data-select-shape="'+escapeHtml(shape.id)+'"><strong>'+escapeHtml(shapeLabel(shape))+'</strong><small>'+escapeHtml(shape.kind==='ingredient'?'Ingredient source':shape.regionType)+'</small></button>';
            html+='<button type="button" class="gcs-icon-button" data-delete-shape="'+escapeHtml(shape.id)+'" aria-label="Delete">×</button>';
            html+='</div>';

            if(shape.kind==='ingredient'){
                html+='<label><span>Zone key</span><input data-field="zoneKey" value="'+escapeHtml(shape.zoneKey)+'" maxlength="160"></label>';
            }else{
                html+='<label><span>Region key</span><input data-field="regionKey" value="'+escapeHtml(shape.regionKey)+'" maxlength="160"></label>';
            }
            html+='<label><span>Display name</span><input data-field="displayName" value="'+escapeHtml(shape.displayName)+'" maxlength="180"></label>';
            html+='<label><span>Priority</span><input type="number" data-field="priority" value="'+Number(shape.priority||0)+'" min="-1000" max="1000" step="1"></label>';
            html+='<div class="gcs-geometry-grid">';
            html+='<label><span>X</span>'+numberInput('x',shape.x,0,1,.001)+'</label>';
            html+='<label><span>Y</span>'+numberInput('y',shape.y,0,1,.001)+'</label>';
            html+='<label><span>W</span>'+numberInput('width',shape.width,.005,1,.001)+'</label>';
            html+='<label><span>H</span>'+numberInput('height',shape.height,.005,1,.001)+'</label>';
            html+='</div>';
            html+='</article>';
        });
        el.shapeList.innerHTML=html;
    }

    function draft(){
        return {
            locationId:currentLocationId(),
            stationPublicId:String(el.station.value||''),
            platform:String(el.platform.value||''),
            frameWidth:Number(el.frameWidth.value||0),
            frameHeight:Number(el.frameHeight.value||0),
            pixelFormat:String(el.pixelFormat.value||''),
            notes:String(el.notes.value||''),
            shapes:state.shapes
        };
    }

    function validateAndRenderSummary(){
        var validation=G.validateDraft(draft());
        var html='';
        if(validation.valid){
            html='<div class="gcs-validation-ready"><strong>Ready to publish</strong><span>'+validation.ingredientCount+' ingredient zone'+(validation.ingredientCount===1?'':'s')+' · '+validation.buildSurfaceCount+' build surface'+(validation.buildSurfaceCount===1?'':'s')+'</span></div>';
        }else{
            html='<div class="gcs-validation-errors"><strong>Needs attention</strong><ul>';
            validation.errors.slice(0,6).forEach(function(error){html+='<li>'+escapeHtml(error)+'</li>';});
            html+='</ul></div>';
        }
        el.validation.innerHTML=html;
        el.save.disabled=!catalog.canManage||!catalog.schemaReady||!validation.valid||state.saving;
        return validation;
    }

    function renderAll(){
        updateEditorTitle();
        renderOverlay();
        renderShapeList();
        validateAndRenderSummary();
    }

    function updateShapeField(row,input){
        var id=row.getAttribute('data-list-shape');
        var shape=shapeById(id);
        if(!shape)return;
        var field=input.getAttribute('data-field');
        if(!field)return;

        if(['x','y','width','height'].includes(field)){
            var copy=Object.assign({},shape);
            copy[field]=Number(input.value);
            Object.assign(shape,G.normalizeRect(copy));
        }else if(field==='priority'){
            shape.priority=Math.max(-1000,Math.min(1000,Math.trunc(Number(input.value||0))));
        }else{
            shape[field]=String(input.value||'').trim();
        }
        markDirty();
        renderOverlay();
        validateAndRenderSummary();
    }

    async function loadVersions(options){
        options=options||{};
        var locationId=currentLocationId();
        var stationPublicId=String(el.station.value||'');
        if(!locationId||!stationPublicId){
            state.versions=[];
            renderVersions();
            return;
        }
        if(state.loadingVersions)return;
        state.loadingVersions=true;
        el.refreshVersions.disabled=true;
        try{
            var url=boot.apiUrl+'?locationId='+encodeURIComponent(locationId)+'&stationPublicId='+encodeURIComponent(stationPublicId);
            var response=await fetch(url,{credentials:'same-origin',headers:{'Accept':'application/json'}});
            var data=await response.json();
            if(!response.ok||!data.ok)throw new Error(data.message||'Could not load calibration history.');
            state.versions=Array.isArray(data.calibrations)?data.calibrations:[];
            renderVersions();
            if(options.autoLoadActive&&!state.dirty){
                var active=state.versions.find(function(version){return version.status==='active';});
                if(active)loadCalibration(active,false);
            }
        }catch(error){
            state.versions=[];
            renderVersions();
            toast(error.message||'Could not load calibration history.',true);
        }finally{
            state.loadingVersions=false;
            el.refreshVersions.disabled=false;
        }
    }

    function renderVersions(){
        if(!state.versions.length){
            el.versions.innerHTML='<div class="gcs-empty-list">No saved calibration versions for this station yet.</div>';
            return;
        }
        var html='';
        state.versions.forEach(function(version,index){
            var status=String(version.status||'');
            html+='<article class="gcs-version '+escapeHtml(status)+'">';
            html+='<div><strong>v'+Number(version.version||0)+'</strong><span>'+escapeHtml(status)+'</span></div>';
            html+='<small>'+escapeHtml((version.frame&&version.frame.width)||'')+'×'+escapeHtml((version.frame&&version.frame.height)||'')+' · '+escapeHtml((version.frame&&version.frame.pixelFormat)||'')+'</small>';
            html+='<small>'+Number((version.zones||[]).length)+' zones · '+Number((version.regions||[]).length)+' regions</small>';
            html+='<button type="button" class="admin-button quiet" data-load-version="'+index+'">Load geometry</button>';
            html+='</article>';
        });
        el.versions.innerHTML=html;
    }

    function loadCalibration(calibration,dirty){
        if(!calibration)return;
        state.shapes=G.shapesFromCalibration(calibration);
        state.selectedId=null;
        el.platform.value=calibration.platform||'inmo_air3';
        if(calibration.frame){
            el.frameWidth.value=calibration.frame.width||640;
            el.frameHeight.value=calibration.frame.height||480;
            if(calibration.frame.pixelFormat)el.pixelFormat.value=calibration.frame.pixelFormat;
        }
        el.notes.value=calibration.notes||'';
        applyAspectRatio();
        state.dirty=!!dirty;
        renderAll();
        toast('Loaded calibration v'+Number(calibration.version||0)+'.');
    }

    async function save(){
        var validation=validateAndRenderSummary();
        if(!validation.valid)return;
        if(state.saving)return;
        state.saving=true;
        validateAndRenderSummary();
        var originalText=el.save.textContent;
        el.save.textContent='Saving…';
        try{
            var payload=G.buildPayload(draft(),boot.csrfToken||'');
            var response=await fetch(boot.apiUrl,{
                method:'POST',
                credentials:'same-origin',
                headers:{
                    'Accept':'application/json',
                    'Content-Type':'application/json',
                    'X-CSRF-Token':String(boot.csrfToken||'')
                },
                body:JSON.stringify(payload)
            });
            var data=await response.json();
            if(!response.ok||!data.ok)throw new Error(data.message||'Calibration could not be saved.');
            state.dirty=false;
            toast('Saved station calibration v'+Number(data.calibration&&data.calibration.version||0)+'.');
            await loadVersions();
            if(data.calibration)loadCalibration(data.calibration,false);
        }catch(error){
            toast(error.message||'Calibration could not be saved.',true);
        }finally{
            state.saving=false;
            el.save.textContent=originalText;
            validateAndRenderSummary();
        }
    }

    function clearDrawing(){
        state.shapes=[];
        state.selectedId=null;
        markDirty();
        cancelTool();
        renderAll();
    }

    function resetForStationChange(){
        state.shapes=[];
        state.selectedId=null;
        state.dirty=false;
        cancelTool();
        renderAll();
        loadVersions({autoLoadActive:true});
    }

    el.location.addEventListener('change',function(){
        populateStations();
        resetForStationChange();
    });
    el.station.addEventListener('change',resetForStationChange);

    [el.platform,el.pixelFormat,el.frameWidth,el.frameHeight,el.notes].forEach(function(input){
        input.addEventListener('change',function(){
            markDirty();
            if(input===el.frameWidth||input===el.frameHeight)applyAspectRatio();
            renderAll();
        });
        input.addEventListener('input',function(){
            markDirty();
            if(input===el.frameWidth||input===el.frameHeight)applyAspectRatio();
            validateAndRenderSummary();
        });
    });

    el.referenceFile.addEventListener('change',function(){
        var file=el.referenceFile.files&&el.referenceFile.files[0];
        if(!file)return;
        if(!/^image\/(jpeg|png|webp)$/i.test(file.type||'')){
            toast('Choose a JPEG, PNG, or WebP reference image.',true);
            el.referenceFile.value='';
            return;
        }
        if(file.size>20*1024*1024){
            toast('Reference image must be 20 MB or smaller.',true);
            el.referenceFile.value='';
            return;
        }
        if(state.referenceUrl)URL.revokeObjectURL(state.referenceUrl);
        state.referenceUrl=URL.createObjectURL(file);
        el.referenceImage.onload=function(){
            el.referenceImage.hidden=false;
            el.emptyFrame.hidden=true;
            if(el.referenceImage.naturalWidth>=16&&el.referenceImage.naturalHeight>=16){
                el.frameWidth.value=el.referenceImage.naturalWidth;
                el.frameHeight.value=el.referenceImage.naturalHeight;
                applyAspectRatio();
                markDirty();
            }
        };
        el.referenceImage.src=state.referenceUrl;
    });

    el.drawIngredient.addEventListener('click',function(){
        var ingredient=ingredientById(el.ingredient.value);
        if(!ingredient){toast('Choose an ingredient first.',true);return;}
        activateTool({kind:'ingredient',ingredientId:Number(ingredient.id)});
    });

    document.querySelectorAll('[data-region-tool]').forEach(function(button){
        button.addEventListener('click',function(){
            activateTool({kind:'region',regionType:button.getAttribute('data-region-tool')});
        });
    });

    el.canvas.addEventListener('pointerdown',function(event){
        var shapeElement=event.target.closest&&event.target.closest('[data-shape-id]');
        if(shapeElement&&!state.activeTool){
            beginShapeDrag(event,shapeElement.getAttribute('data-shape-id'),!!(event.target.closest&&event.target.closest('[data-resize]')));
            return;
        }
        beginDraw(event);
    });
    el.canvas.addEventListener('pointermove',pointerMove);
    el.canvas.addEventListener('pointerup',pointerUp);
    el.canvas.addEventListener('pointercancel',pointerUp);

    el.shapeList.addEventListener('click',function(event){
        var select=event.target.closest&&event.target.closest('[data-select-shape]');
        if(select){selectShape(select.getAttribute('data-select-shape'));return;}
        var del=event.target.closest&&event.target.closest('[data-delete-shape]');
        if(del){
            state.selectedId=del.getAttribute('data-delete-shape');
            deleteSelected();
        }
    });
    el.shapeList.addEventListener('change',function(event){
        var row=event.target.closest&&event.target.closest('[data-list-shape]');
        if(row)updateShapeField(row,event.target);
    });
    el.shapeList.addEventListener('input',function(event){
        if(!event.target.matches('input'))return;
        var row=event.target.closest('[data-list-shape]');
        if(row)updateShapeField(row,event.target);
    });

    el.versions.addEventListener('click',function(event){
        var button=event.target.closest&&event.target.closest('[data-load-version]');
        if(!button)return;
        var index=Number(button.getAttribute('data-load-version'));
        if(Number.isInteger(index)&&state.versions[index])loadCalibration(state.versions[index],true);
    });

    el.refreshVersions.addEventListener('click',function(){loadVersions();});
    el.clear.addEventListener('click',clearDrawing);
    el.save.addEventListener('click',save);

    window.addEventListener('keydown',function(event){
        if(event.key==='Escape'){cancelTool();renderOverlay();return;}
        var tag=(document.activeElement&&document.activeElement.tagName||'').toLowerCase();
        if((event.key==='Delete'||event.key==='Backspace')&&tag!=='input'&&tag!=='textarea'&&tag!=='select'){
            if(state.selectedId){
                event.preventDefault();
                deleteSelected();
            }
        }
    });

    window.addEventListener('beforeunload',function(event){
        if(!state.dirty)return;
        event.preventDefault();
        event.returnValue='';
    });

    window.addEventListener('unload',function(){
        if(state.referenceUrl)URL.revokeObjectURL(state.referenceUrl);
    });

    initCatalog();
})();
