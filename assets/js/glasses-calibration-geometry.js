(function(root,factory){
    var api=factory();
    if(typeof module==='object'&&module.exports)module.exports=api;
    if(root)root.GelatoCalibrationGeometry=api;
})(typeof globalThis!=='undefined'?globalThis:this,function(){
    'use strict';

    var REGION_TYPES=['build_surface','plate_surface','handoff_surface','discard_surface'];

    function finite(value,fallback){
        var number=Number(value);
        return Number.isFinite(number)?number:fallback;
    }

    function clamp(value,min,max){
        return Math.max(min,Math.min(max,value));
    }

    function round6(value){
        return Math.round(value*1000000)/1000000;
    }

    function normalizeRect(rect){
        var x=clamp(finite(rect&&rect.x,0),0,1);
        var y=clamp(finite(rect&&rect.y,0),0,1);
        var width=clamp(finite(rect&&rect.width,0),0,1-x);
        var height=clamp(finite(rect&&rect.height,0),0,1-y);
        return {
            x:round6(x),
            y:round6(y),
            width:round6(width),
            height:round6(height)
        };
    }

    function rectFromPoints(x1,y1,x2,y2){
        var left=Math.min(finite(x1,0),finite(x2,0));
        var top=Math.min(finite(y1,0),finite(y2,0));
        var right=Math.max(finite(x1,0),finite(x2,0));
        var bottom=Math.max(finite(y1,0),finite(y2,0));
        return normalizeRect({
            x:left,
            y:top,
            width:right-left,
            height:bottom-top
        });
    }

    function slug(value){
        return String(value||'')
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9]+/g,'-')
            .replace(/^-+|-+$/g,'')||'shape';
    }

    function uniqueKey(base,shapes,field,excludeId){
        var stem=slug(base);
        var used=new Set();
        (shapes||[]).forEach(function(shape){
            if(excludeId&&shape.id===excludeId)return;
            var value=String(shape&&shape[field]||'').trim();
            if(value)used.add(value);
        });
        if(!used.has(stem))return stem;
        var n=2;
        while(used.has(stem+'-'+n))n++;
        return stem+'-'+n;
    }

    function ingredientShape(ingredient,rect,shapes){
        if(!ingredient||!Number.isInteger(Number(ingredient.id))||Number(ingredient.id)<1)
            throw new Error('Choose an ingredient before drawing a source zone.');
        var displayName=String(ingredient.name||'Ingredient').trim()||'Ingredient';
        return Object.assign({
            id:'shape-'+Date.now()+'-'+Math.random().toString(16).slice(2),
            kind:'ingredient',
            ingredientId:Number(ingredient.id),
            displayName:displayName,
            zoneKey:uniqueKey(slug(displayName)+'-source',shapes,'zoneKey'),
            priority:10
        },normalizeRect(rect));
    }

    function regionShape(regionType,rect,shapes){
        if(REGION_TYPES.indexOf(regionType)<0)throw new Error('Station region type is invalid.');
        var label={
            build_surface:'Build Surface',
            plate_surface:'Plate Surface',
            handoff_surface:'Handoff Surface',
            discard_surface:'Discard Surface'
        }[regionType];
        return Object.assign({
            id:'shape-'+Date.now()+'-'+Math.random().toString(16).slice(2),
            kind:'region',
            regionType:regionType,
            displayName:label,
            regionKey:uniqueKey(regionType,shapes,'regionKey'),
            priority:regionType==='build_surface'?20:10
        },normalizeRect(rect));
    }

    function validateShape(shape,index){
        var errors=[];
        var label='Shape '+(index+1);
        if(!shape||!['ingredient','region'].includes(shape.kind)){
            errors.push(label+' has an invalid type.');
            return errors;
        }
        var rect=normalizeRect(shape);
        if(rect.width<0.005||rect.height<0.005)
            errors.push(label+' is too small.');
        if(rect.x+rect.width>1.000001||rect.y+rect.height>1.000001)
            errors.push(label+' extends outside the frame.');

        if(shape.kind==='ingredient'){
            if(!Number.isInteger(Number(shape.ingredientId))||Number(shape.ingredientId)<1)
                errors.push(label+' needs an ingredient.');
            if(!String(shape.zoneKey||'').trim())
                errors.push(label+' needs a zone key.');
        }else{
            if(REGION_TYPES.indexOf(String(shape.regionType||''))<0)
                errors.push(label+' has an unsupported region type.');
            if(!String(shape.regionKey||'').trim())
                errors.push(label+' needs a region key.');
        }
        return errors;
    }

    function validateDraft(draft){
        draft=draft||{};
        var errors=[];
        var frameWidth=Math.trunc(finite(draft.frameWidth,0));
        var frameHeight=Math.trunc(finite(draft.frameHeight,0));
        var platform=String(draft.platform||'').trim();
        var pixelFormat=String(draft.pixelFormat||'').trim();
        var shapes=Array.isArray(draft.shapes)?draft.shapes:[];

        if(!Number.isInteger(Number(draft.locationId))||Number(draft.locationId)<1)
            errors.push('Choose a location.');
        if(!String(draft.stationPublicId||'').trim())
            errors.push('Choose a kitchen station.');
        if(!platform)errors.push('Platform is required.');
        if(frameWidth<16||frameWidth>8192||frameHeight<16||frameHeight>8192)
            errors.push('Frame dimensions must be between 16 and 8192 pixels.');
        if(!pixelFormat)errors.push('Pixel format is required.');
        if(shapes.length===0)errors.push('Draw at least one ingredient source zone and the build surface.');

        var ingredientCount=0;
        var buildCount=0;
        var zoneKeys=new Set();
        var regionKeys=new Set();

        shapes.forEach(function(shape,index){
            validateShape(shape,index).forEach(function(error){errors.push(error);});
            if(shape.kind==='ingredient'){
                ingredientCount++;
                var zoneKey=String(shape.zoneKey||'').trim();
                if(zoneKey){
                    if(zoneKeys.has(zoneKey))errors.push('Ingredient zone keys must be unique.');
                    zoneKeys.add(zoneKey);
                }
            }else if(shape.kind==='region'){
                if(shape.regionType==='build_surface')buildCount++;
                var regionKey=String(shape.regionKey||'').trim();
                if(regionKey){
                    if(regionKeys.has(regionKey))errors.push('Station region keys must be unique.');
                    regionKeys.add(regionKey);
                }
            }
        });

        if(ingredientCount===0)errors.push('Draw at least one ingredient source zone.');
        if(buildCount===0)errors.push('Draw at least one build surface.');

        return {
            valid:errors.length===0,
            errors:Array.from(new Set(errors)),
            ingredientCount:ingredientCount,
            buildSurfaceCount:buildCount,
            shapeCount:shapes.length
        };
    }

    function buildPayload(draft,csrfToken){
        var validation=validateDraft(draft);
        if(!validation.valid){
            var error=new Error(validation.errors.join(' '));
            error.validation=validation;
            throw error;
        }

        var payload={
            action:'calibration.save',
            csrf_token:String(csrfToken||''),
            locationId:Number(draft.locationId),
            stationPublicId:String(draft.stationPublicId).trim(),
            platform:String(draft.platform).trim(),
            frameWidth:Math.trunc(Number(draft.frameWidth)),
            frameHeight:Math.trunc(Number(draft.frameHeight)),
            pixelFormat:String(draft.pixelFormat).trim(),
            notes:String(draft.notes||'').trim().slice(0,1000),
            zones:[],
            regions:[]
        };

        draft.shapes.forEach(function(shape){
            var rect=normalizeRect(shape);
            if(shape.kind==='ingredient'){
                payload.zones.push({
                    zoneKey:String(shape.zoneKey).trim(),
                    ingredientId:Number(shape.ingredientId),
                    displayName:String(shape.displayName||'Ingredient').trim(),
                    x:rect.x,y:rect.y,width:rect.width,height:rect.height,
                    priority:Math.max(-1000,Math.min(1000,Math.trunc(finite(shape.priority,10))))
                });
            }else{
                payload.regions.push({
                    regionKey:String(shape.regionKey).trim(),
                    regionType:String(shape.regionType).trim(),
                    displayName:String(shape.displayName||'Region').trim(),
                    x:rect.x,y:rect.y,width:rect.width,height:rect.height,
                    priority:Math.max(-1000,Math.min(1000,Math.trunc(finite(shape.priority,10))))
                });
            }
        });

        return payload;
    }

    function shapesFromCalibration(calibration){
        if(!calibration)return[];
        var out=[];
        (calibration.zones||[]).forEach(function(zone,index){
            out.push(Object.assign({
                id:'loaded-zone-'+index+'-'+String(zone.zoneKey||''),
                kind:'ingredient',
                ingredientId:Number(zone.ingredientId),
                displayName:String(zone.displayName||zone.canonicalName||'Ingredient'),
                zoneKey:String(zone.zoneKey||'ingredient-zone-'+(index+1)),
                priority:Number(zone.priority||0)
            },normalizeRect(zone)));
        });
        (calibration.regions||[]).forEach(function(region,index){
            out.push(Object.assign({
                id:'loaded-region-'+index+'-'+String(region.regionKey||''),
                kind:'region',
                regionType:String(region.regionType||'build_surface'),
                displayName:String(region.displayName||'Region'),
                regionKey:String(region.regionKey||'region-'+(index+1)),
                priority:Number(region.priority||0)
            },normalizeRect(region)));
        });
        return out;
    }

    return {
        REGION_TYPES:REGION_TYPES.slice(),
        clamp:clamp,
        normalizeRect:normalizeRect,
        rectFromPoints:rectFromPoints,
        uniqueKey:uniqueKey,
        ingredientShape:ingredientShape,
        regionShape:regionShape,
        validateDraft:validateDraft,
        buildPayload:buildPayload,
        shapesFromCalibration:shapesFromCalibration
    };
});
