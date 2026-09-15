(() => {
'use strict';
if(window.GelatoEmployeeHomeNav)return;window.GelatoEmployeeHomeNav=true;
const Auth=window.RestaurantAuth;if(!Auth?.current?.())return;
const permissions=['employee.self','schedule.self','timeclock.self','training.self_view','tasks.self','agent.employee_view','employee.manage','staff.manage'];
if(!permissions.some(p=>Auth.has?.(p)))return;
function add(){
  if(document.querySelector('[data-employee-home-link]'))return;
  const nav=document.querySelector('[data-nav-group="admin"]');
  if(!nav)return;
  const link=document.createElement('a');
  link.href='employee-home.php';
  link.textContent='Employee Home';
  link.dataset.employeeHomeLink='1';
  link.className='nav-item';
  link.style.cssText='display:flex;align-items:center;gap:8px;text-decoration:none;color:inherit;font-weight:700';
  nav.prepend(link);
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',add,{once:true});else add();
})();
