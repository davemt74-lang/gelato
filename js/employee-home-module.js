(() => {
'use strict';
if(window.GelatoEmployeeHomeNav)return;window.GelatoEmployeeHomeNav=true;
const Auth=window.RestaurantAuth;if(!Auth?.current?.())return;
const permissions=['employee.self','schedule.self','timeclock.self','training.self_view','tasks.self','agent.employee_view','employee.manage','staff.manage'];
if(!permissions.some(p=>Auth.has?.(p)))return;
function add(){if(document.querySelector('[data-employee-home-link]'))return;const link=document.createElement('a');link.href='employee-home.php';link.textContent='Employee Home';link.dataset.employeeHomeLink='1';link.style.cssText='display:flex;align-items:center;gap:8px;text-decoration:none;color:inherit;font-weight:700';
const nav=document.querySelector('[data-nav-group="admin"]')||document.querySelector('nav')||document.querySelector('aside nav');if(nav){link.className='nav-item';nav.prepend(link);return;}
const target=document.querySelector('header .actions,.top .actions,header nav');if(target){link.className='btn';target.prepend(link);}}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',add,{once:true});else add();
})();