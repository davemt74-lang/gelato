(() => {
  'use strict';
  const keys={users:'restaurant-admin-users-v1',roles:'restaurant-admin-roles-v1',session:'restaurant-admin-session-v1',permissions:'restaurant-admin-permissions-v1'};
  const permissionCatalog=[
    ['dashboard.view','Dashboard','View training dashboard'],['agent.owner_view','Agent','View owner-level system agent'],['agent.employee_view','Agent','Use employee training agent'],
    ['users.view','Accounts','View user accounts'],['users.create','Accounts','Create user accounts'],['users.edit','Accounts','Edit user accounts'],['users.suspend','Accounts','Suspend and reactivate accounts'],['users.assign_roles','Accounts','Assign account types'],
    ['roles.view','Permissions','View account types and permissions'],['roles.create','Permissions','Create custom account types'],['roles.edit','Permissions','Edit account types and permission grants'],['roles.assign_permissions','Permissions','Assign permissions to account types'],
    ['training.self_view','Training','View own training'],['training.assign','Training','Assign training'],['training.view_employee_progress','Training','View employee progress'],['training.view_all_progress','Training','View organization-wide progress'],['training.issue_certifications','Training','Issue certifications'],
    ['resumes.view','Hiring','View resume submissions'],['jobs.view','Hiring','View job openings'],['jobs.create','Hiring','Create job openings'],['jobs.edit','Hiring','Edit job openings'],['jobs.publish','Hiring','Publish and pause job openings'],['resumes.review','Hiring','Review and change resume status'],['resumes.add_notes','Hiring','Add internal resume notes'],['resumes.convert_to_employee','Hiring','Convert applicants to employee accounts'],
    ['forms.view','Content','View forms'],['forms.create','Content','Create forms'],['forms.edit','Content','Edit and publish forms'],['public_pages.view','Content','View public page settings'],['public_pages.edit','Content','Edit and publish public pages'],
    ['brand.view','Organization','View brand settings'],['brand.edit','Organization','Edit brand settings'],['reports.view','Organization','View reports'],['settings.self_edit','Organization','Edit own profile'],['settings.organization_edit','Organization','Edit organization settings'],['audit.view','Organization','View audit history']
  ].map(([key,group,name])=>({key,group,name}));
  const defaultRoles=[
    {id:'role-owner',name:'Super Admin',slug:'super_admin',description:'Store owner with unrestricted organization access.',system:true,owner:true,permissions:['*']},
    {id:'role-manager',name:'Manager',slug:'manager',description:'Manages employees, training progress, certifications, and hiring workflow.',system:true,owner:false,permissions:['dashboard.view','agent.employee_view','users.view','users.create','users.edit','users.suspend','users.assign_roles','roles.view','training.self_view','training.assign','training.view_employee_progress','training.issue_certifications','resumes.view','resumes.review','resumes.add_notes','resumes.convert_to_employee','jobs.view','jobs.create','jobs.edit','jobs.publish','forms.view','public_pages.view','brand.view','reports.view','settings.self_edit']},
    {id:'role-employee',name:'Employee',slug:'employee',description:'Completes assigned training and reviews personal progress.',system:true,owner:false,permissions:['dashboard.view','agent.employee_view','training.self_view','settings.self_edit']}
  ];
  const defaultUsers=[];
  const read=(key,fallback)=>{try{return JSON.parse(localStorage.getItem(key)||JSON.stringify(fallback));}catch{return fallback;}};
  const write=(key,value)=>localStorage.setItem(key,JSON.stringify(value));
  function seed(){
    const storedPermissions=read(keys.permissions,[]),permissionMap=new Map(storedPermissions.map(item=>[item.key,item]));permissionCatalog.forEach(item=>permissionMap.set(item.key,{...permissionMap.get(item.key),...item}));write(keys.permissions,[...permissionMap.values()]);
    const storedRoles=read(keys.roles,[]);if(!storedRoles.length)write(keys.roles,defaultRoles);else{const manager=storedRoles.find(role=>role.slug==='manager');if(manager){manager.permissions=[...new Set([...(manager.permissions||[]),'jobs.view','jobs.create','jobs.edit','jobs.publish'])]}write(keys.roles,storedRoles)}
    if(!localStorage.getItem(keys.users))write(keys.users,defaultUsers);
  }
  function users(){seed();return read(keys.users,defaultUsers)}
  function roles(){seed();return read(keys.roles,defaultRoles)}
  function current(){seed();const session=read(keys.session,null);if(!session)return null;const user=users().find(item=>item.id===session.userId&&item.status==='active');if(!user)return null;return {...user,role:roles().find(role=>role.id===user.roleId)||null};}
  function login(email,password){seed();const list=users();const user=list.find(item=>item.email.toLowerCase()===String(email).trim().toLowerCase());if(!user||user.password!==password)return {ok:false,message:'Use the secure database login at login.php.'};if(user.status!=='active')return {ok:false,message:`This account is ${user.status}. Contact the store owner.`};user.lastLogin=new Date().toISOString();write(keys.users,list);write(keys.session,{userId:user.id,createdAt:new Date().toISOString()});return {ok:true,user:{...user,role:roles().find(role=>role.id===user.roleId)}};}
  function logout(){localStorage.removeItem(keys.session)}
  function has(permission,user=current()){if(!user||!user.role)return false;return user.role.permissions.includes('*')||user.role.permissions.includes(permission)}
  function saveUsers(value){write(keys.users,value)}
  function saveRoles(value){write(keys.roles,value)}
  function installPosHeaderAction(){
    if(!window.RESTAURANT_SERVER_SESSION||!has('pos.use'))return;
    const actions=document.querySelector('.top-actions');
    if(!actions||actions.querySelector('[data-pos-header]'))return;
    const link=document.createElement('a');
    link.className='header-link';
    link.href='pos.php';
    link.textContent='POS';
    link.setAttribute('data-pos-header','true');
    link.setAttribute('aria-label','Open native POS');
    actions.prepend(link);
  }
  window.RestaurantAuth={keys,permissionCatalog,seed,users,roles,current,login,logout,has,saveUsers,saveRoles,read,write};
  seed();
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',installPosHeaderAction,{once:true});else installPosHeaderAction();
})();
