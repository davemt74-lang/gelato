'use strict';

const assert=require('assert');
const G=require('../assets/js/glasses-calibration-geometry.js');

const rect=G.rectFromPoints(0.8,0.7,0.2,0.1);
assert.deepStrictEqual(rect,{x:0.2,y:0.1,width:0.6,height:0.6},'drag direction must normalize');

const ingredient={id:42,name:'Turkey'};
const source=G.ingredientShape(ingredient,{x:0.05,y:0.15,width:0.20,height:0.22},[]);
const build=G.regionShape('build_surface',{x:0.40,y:0.45,width:0.38,height:0.35},[source]);

const draft={
    locationId:7,
    stationPublicId:'station-sandwich',
    platform:'inmo_air3',
    frameWidth:640,
    frameHeight:480,
    pixelFormat:'grayscale8',
    notes:'Primary line',
    shapes:[source,build]
};

const validation=G.validateDraft(draft);
assert.strictEqual(validation.valid,true,'source + build surface must be publishable');
assert.strictEqual(validation.ingredientCount,1);
assert.strictEqual(validation.buildSurfaceCount,1);

const payload=G.buildPayload(draft,'csrf123');
assert.strictEqual(payload.action,'calibration.save');
assert.strictEqual(payload.csrf_token,'csrf123');
assert.strictEqual(payload.zones.length,1);
assert.strictEqual(payload.regions.length,1);
assert.strictEqual(payload.zones[0].ingredientId,42);
assert.strictEqual(payload.regions[0].regionType,'build_surface');
assert.strictEqual(payload.frameWidth,640);

const loaded=G.shapesFromCalibration({
    zones:[{
        zoneKey:'turkey-pan',
        ingredientId:42,
        displayName:'Turkey Pan',
        x:0.05,y:0.15,width:0.20,height:0.22,priority:20
    }],
    regions:[{
        regionKey:'build-main',
        regionType:'build_surface',
        displayName:'Main Build Surface',
        x:0.40,y:0.45,width:0.38,height:0.35,priority:20
    }]
});
assert.strictEqual(loaded.length,2,'saved calibration must rehydrate zones and regions');
assert.strictEqual(loaded[0].kind,'ingredient');
assert.strictEqual(loaded[1].kind,'region');

const duplicate=Object.assign({},source,{id:'duplicate',zoneKey:source.zoneKey,x:0.3});
const duplicateValidation=G.validateDraft(Object.assign({},draft,{shapes:[source,duplicate,build]}));
assert.strictEqual(duplicateValidation.valid,false,'duplicate zone keys must be rejected');
assert(duplicateValidation.errors.some(x=>x.includes('unique')));

const missingBuild=G.validateDraft(Object.assign({},draft,{shapes:[source]}));
assert.strictEqual(missingBuild.valid,false,'build surface must be required by Studio');

const missingIngredient=G.validateDraft(Object.assign({},draft,{shapes:[build]}));
assert.strictEqual(missingIngredient.valid,false,'ingredient source must be required by Studio');

const clamped=G.normalizeRect({x:-.2,y:.9,width:1.5,height:.4});
assert.deepStrictEqual(clamped,{x:0,y:0.9,width:1,height:0.1},'geometry must remain in frame');

console.log('glasses-calibration-geometry-ok');
