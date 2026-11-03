'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = name => fs.readFileSync(`${__dirname}/../js/${name}`, 'utf8');
function storage(entries = []) {
  const values = new Map(entries);
  return {get length(){return values.size;},key:i=>[...values.keys()][i] ?? null,
    getItem:k=>values.get(k) ?? null,setItem:(k,v)=>values.set(k,String(v)),removeItem:k=>values.delete(k),values};
}
function bootstrap(userId, organizationId = 1, identity = 'session-one', permissions = ['dashboard.view']) {
  const role = {id:'db-role-1',name:'Employee',slug:'employee',owner:false,permissions};
  const currentUser = {id:`db-user-${userId}`,displayName:`Staff ${userId}`,status:'active',roleId:role.id,role};
  return {organizationId,currentUser,users:[currentUser],roles:[role],permissions:[],jobs:[],session:{userId:currentUser.id,identity}};
}
function browser(data, local = storage(), session = storage(), logoutTarget = '') {
  const events = {}, redirects = [];
  const window = {RESTAURANT_SERVER_SESSION:Boolean(data),RESTAURANT_WORKSPACE_BOOTSTRAP:data,localStorage:local,sessionStorage:session,
    location:{replace:value=>redirects.push(value),reload:()=>redirects.push('reload')},
    addEventListener:(key,fn)=>(events[key] ||= []).push(fn),dispatchEvent:e=>(events[e.type] || []).forEach(fn=>fn(e))};
  const document = {currentScript:{dataset:{logoutTarget}},readyState:'loading',addEventListener(){},querySelector(){return null;},getElementById(){return null;}};
  const context = {window,document,localStorage:local,sessionStorage:session,CustomEvent:class{constructor(type){this.type=type;}},console,Date,Math};
  vm.runInNewContext(source('private-browser-state.js'),context);
  if(data)vm.runInNewContext(source('auth.js'),context);
  return {context,window,auth:window.RestaurantAuth,state:window.GelatoPrivateState,local,session,redirects,
    event:(name,event)=>(events[name] || []).forEach(fn=>fn(event))};
}
const local = storage([
  ['restaurant-admin-users-v1',JSON.stringify([{id:'attacker',roleId:'owner',password:'secret'}])],
  ['restaurant-admin-roles-v1',JSON.stringify([{id:'owner',permissions:['*']}])],
  ['restaurant-admin-session-v1',JSON.stringify({userId:'attacker'})],
  ['restaurant-owner-agent-chat-v1','private old conversation'],
  ['restaurant-quiz-history','private old score'],
  ['gelato-online-cart-v3','keep-cart'],['gelato.training.replies.v1','1'],
]);
const tab = browser(bootstrap(10),local);
assert.equal(tab.auth.current().id,'db-user-10','current account comes only from the server bootstrap');
assert.equal(tab.auth.has('users.view'),false,'legacy owner data cannot create permissions');
assert.equal(tab.auth.has('jobs.publish'),false,'seed does not add manager permissions to a server session');
for(const key of ['restaurant-admin-users-v1','restaurant-admin-roles-v1','restaurant-admin-session-v1','restaurant-owner-agent-chat-v1','restaurant-quiz-history'])assert.equal(local.getItem(key),null,`${key} is removed`);
assert.equal(local.getItem('gelato-online-cart-v3'),'keep-cart','anonymous cart survives cleanup');
assert.equal(local.getItem('gelato.training.replies.v1'),'1','nonprivate device preference survives cleanup');
tab.auth.saveRoles([{id:'db-role-1',permissions:['*']}]);
assert.equal(tab.auth.has('users.view'),false,'mutable view data cannot replace current effective grants');
assert.equal(local.getItem('restaurant-admin-roles-v1'),null,'server roles are not written to persistent storage');
tab.auth.write('restaurant-owner-agent-chat-v1',[{text:'private conversation'}]);
assert.equal(local.getItem('restaurant-owner-agent-chat-v1'),null);
assert.equal(tab.auth.read('restaurant-owner-agent-chat-v1',[])[0].text,'private conversation');
const same = browser(bootstrap(10),local,tab.session);
assert.equal(same.auth.read('restaurant-owner-agent-chat-v1',[])[0].text,'private conversation','same-session reload preserves its draft');
const otherOrganization = browser(bootstrap(10,2),local,tab.session);
assert.equal(otherOrganization.auth.read('restaurant-owner-agent-chat-v1',[]).length,0,'organization switch cannot read a previous draft');
assert.ok(![...tab.session.values.values()].some(value=>value.includes('private conversation')),'previous principal bytes removed from this tab');
otherOrganization.auth.write('restaurant-owner-agent-chat-v1',[{text:'different organization'}]);
const freshLogin = browser(bootstrap(10,2,'new-login'),local,tab.session);
assert.equal(freshLogin.auth.read('restaurant-owner-agent-chat-v1',[]).length,0,'a new login cannot restore the same user old-session draft');
freshLogin.auth.write('restaurant-owner-agent-chat-v1',[{text:'current'}]);
freshLogin.auth.logout();
assert.equal(freshLogin.auth.current(),null);
assert.equal(freshLogin.session.length,0,'logout removes private session storage');
assert.equal(local.getItem('gelato-online-cart-v3'),'keep-cart');

const sibling = browser(bootstrap(11),local);
sibling.auth.write('restaurant-owner-agent-chat-v1',[{text:'other tab'}]);
sibling.event('storage',{key:'gelato-workspace-logout-v1',newValue:'logout-event'});
assert.equal(sibling.auth.current(),null,'another-tab signout clears in-memory identity');
assert.equal(sibling.session.length,0,'another-tab signout clears its private drafts');
assert.deepEqual(sibling.redirects,['workspace.php'],'another tab re-enters server authorization');
const switched = browser(bootstrap(12),local);
switched.event('storage',{key:'gelato-workspace-principal-v1',newValue:'different-principal'});
assert.equal(switched.auth.current(),null,'another-tab account switch clears identity');
assert.deepEqual(switched.redirects,['workspace.php']);

for(const target of ['login.php','customer-login.php']){
  const before = browser(bootstrap(20),local);
  before.auth.write('restaurant-owner-agent-chat-v1',[{text:'signout'}]);
  const signedOut = browser(null,local,before.session,target);
  assert.equal(signedOut.session.length,0,`${target} clears private drafts`);
  assert.deepEqual(signedOut.redirects,[target]);
  assert.equal(local.getItem('gelato-online-cart-v3'),'keep-cart');
}
const deniedStorage = {get length(){throw Error('disabled');},getItem(){throw Error('disabled');},setItem(){throw Error('disabled');},removeItem(){throw Error('disabled');}};
const restricted = browser(bootstrap(30),deniedStorage,deniedStorage);
restricted.auth.write('private-note',{text:'memory only'});
assert.equal(restricted.auth.read('private-note',{}).text,'memory only','blocked storage has an in-memory fallback');
restricted.auth.logout();
assert.equal(restricted.auth.current(),null);
const missingBootstrap = browser(null);
assert.equal(missingBootstrap.state.storage.getItem('anything'),null,'unscoped storage fails closed');
(async()=>{
  const bridge = browser(bootstrap(40,1,'bridge-login',['inventory.view']),local);
  bridge.context.fetch = async()=>({ok:true,json:async()=>({ok:true,answer:'Private stock result',data:{}})});
  vm.runInNewContext(source('restaurant-agent-bridge.js'),bridge.context);
  await bridge.window.GelatoRestaurantAgent.submit('show current inventory');
  const history=bridge.auth.read('restaurant-owner-agent-chat-v1',[]);
  assert.equal(history.length,2,'actual agent bridge uses scoped conversation storage');
  assert.equal(history[1].text,'Private stock result');
  assert.equal(local.getItem('restaurant-owner-agent-chat-v1'),null,'actual bridge does not recreate the shared chat key');
  const nextUser=browser(bootstrap(41,1,'next-login'),local,bridge.session);
  assert.equal(nextUser.auth.read('restaurant-owner-agent-chat-v1',[]).length,0,'actual bridge conversation does not cross account switch');
  console.log('hardening browser-state behavioral tests passed');
})().catch(error=>{console.error(error);process.exitCode=1;});
