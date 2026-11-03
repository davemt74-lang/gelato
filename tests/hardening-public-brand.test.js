'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
function node(tagName) {
  const value = {tagName,children:[],textContent:'',replaceChildren(...children){this.children=children;}};
  Object.defineProperty(value,'innerHTML',{set(){throw new Error('Brand text must not be parsed as HTML');}});
  return value;
}
const document = {baseURI:'https://restaurant.test/app/',createElement:node};
const window = {};
vm.runInNewContext(fs.readFileSync(`${__dirname}/../js/public-brand-render.js`,'utf8'),{window,document,URL});
const renderer=window.GelatoPublicBrand;
const hostile='\"><img src=x onerror=alert(1)><script>bad()</script>';
const logo=node('span');
renderer.renderLogo(logo,{logoUrl:'uploads/brand/1/logo.png',restaurantName:hostile},'Restaurant','FR');
assert.equal(logo.children.length,1);
assert.equal(logo.children[0].tagName,'img');
assert.equal(logo.children[0].src,'https://restaurant.test/app/uploads/brand/1/logo.png');
assert.equal(logo.children[0].alt,hostile+' logo','hostile restaurant name remains one inert attribute value');
assert.equal(logo.children[0].onerror,undefined);
for(const url of ['javascript:alert(1)','data:image/svg+xml,<svg onload=alert(1)>','file:///private','https://user:pass@example.test/a.png']) {
  assert.equal(renderer.imageUrl(url),null,`reject unsafe image URL ${url}`);
  const fallback=node('span');
  renderer.renderLogo(fallback,{logoUrl:url,logoText:hostile},'Restaurant','FR');
  assert.equal(fallback.textContent,hostile);
  assert.equal(fallback.children.length,0);
}
assert.equal(renderer.imageUrl('https://cdn.example.test/logo.png'),'https://cdn.example.test/logo.png');
assert.equal(renderer.imageUrl(null),null);
const checks = [];
for(const [file,id,name] of [['apply.html','applyLogo','Stonefellows'],['landing.html','publicLogo','Restaurant']]) {
  const html=fs.readFileSync(`${__dirname}/../${file}`,'utf8');
  const scripts=[...html.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)].map(match=>match[1]);
  const brandScript=scripts.find(script=>script.includes("fetch('api/public-brand.php'"));
  assert.ok(html.includes('js/public-brand-render.js'),'each page loads the shared safe renderer');
  const elements=new Map();
  const getElementById=id=>{if(!elements.has(id))elements.set(id,node('span'));return elements.get(id);};
  const pageDocument={...document,getElementById,documentElement:{style:{setProperty(){}}},querySelector(){return null;}};
  const response={ok:true,json:async()=>({brand:{restaurantName:hostile,logoUrl:'uploads/brand/logo.png'}})};
  const context={window,document:pageDocument,fetch:async()=>response};
  // The actual page consumer is executed; then its asynchronous fetch is drained.
  const promise=vm.runInNewContext(brandScript,context);
  checks.push(Promise.resolve(promise).then(()=>{
    const actual=elements.get(id);
    assert.equal(actual.children.length,1,`${file} renders one logo node`);
    assert.equal(actual.children[0].alt,hostile+' logo',`${file} preserves inert brand text`);
  }));
}
Promise.all(checks).then(()=>console.log('hardening public-brand rendering tests passed')).catch(error=>{console.error(error);process.exitCode=1;});
