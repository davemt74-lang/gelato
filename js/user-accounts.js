(() => {
  'use strict';
  if (window.RESTAURANT_SERVER_SESSION !== true || !window.RestaurantAuth || !window.RestaurantAdmin) return;
  const Auth = window.RestaurantAuth, Admin = window.RestaurantAdmin;
  const $ = id => document.getElementById(id);
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  let assignableRoles = [], loadPromise = null;
  const pending = new Set();

  async function request(input = null, optionsOnly = false) {
    const response = await fetch('api/users.php' + (optionsOnly ? '?action=options' : ''), {
      method: input ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
      headers: input ? {'Content-Type':'application/json','X-CSRF-Token':window.RESTAURANT_CSRF_TOKEN || ''} : {},
      ...(input ? {body:JSON.stringify(input)} : {}),
    });
    let data;
    try { data = await response.json(); } catch { throw new Error('The server did not confirm this change. Refresh the account list before retrying.'); }
    if (!response.ok || data.ok !== true) throw new Error(data.message || 'The account action failed.');
    return data;
  }

  async function refresh() {
    if (loadPromise) return loadPromise;
    loadPromise = request(null, !Auth.has('users.view')).then(data => {
      if (Array.isArray(data.users)) Auth.replaceServerUsers(data.users);
      assignableRoles = data.assignableRoles || [];
      Admin.renderUsers();
      return data;
    }).finally(() => { loadPromise = null; });
    return loadPromise;
  }

  function applyResult(data) {
    if (data.user) Auth.applyServerAccount(data.user);
    Admin.renderUsers();
    Admin.setupProfile();
    Admin.renderOwner();
    if (data.reauthenticate) window.location.replace('login.php?return=workspace.php');
    window.dispatchEvent(new CustomEvent('gelato:accounts-updated'));
  }

  async function mutate(key, input) {
    if (pending.has(key)) return null;
    pending.add(key);
    try {
      const data = await request(input);
      applyResult(data);
      Admin.toast(data.message, data.invitation?.delivery === 'failed');
      return data;
    } catch (error) {
      Admin.toast(error.message, true);
      throw error;
    } finally { pending.delete(key); }
  }

  async function openEditor(id = null) {
    if (!Auth.has(id ? 'users.edit' : 'users.create')) return Admin.toast('You do not have permission to manage this account.', true);
    try { await refresh(); } catch (error) { return Admin.toast(error.message, true); }
    const user = id ? Auth.users().find(item => item.id === id) : null;
    if (id && !user) return Admin.toast('This account is no longer available.', true);
    const profileReadonly = user?.profileEditable === false;
    const selected = user?.roleIds || [];
    const canChangeRoles = Auth.has('users.assign_roles') && !user?.protected && user?.id !== Auth.current()?.id && user?.status !== 'invited';
    const options = assignableRoles.map(role => '<option value="' + esc(role.id) + '" ' +
      ((user ? selected.includes(role.id) : role.slug === 'employee') ? 'selected' : '') + '>' + esc(role.name) + '</option>').join('');
    const roles = user
      ? (canChangeRoles ? '<label for="accountRoles">Account types</label><select class="field" id="accountRoles" name="roleIds" multiple size="4">' + options + '</select><small>Select every account type this employee should keep.</small>'
        : '<label>Account types</label><p>' + esc(user.roleNames?.join(', ') || 'Invitation pending') + '</p>')
      : '<label for="accountRole">Account type</label><select class="field" id="accountRole" name="roleId" required>' + options + '</select>';
    if (!user && !assignableRoles.length) return Admin.toast('No account type is available for you to assign. Contact the owner.', true);
    const field = (name, label, value, readonly = false, type = 'text') =>
      '<div><label for="account-' + name + '">' + label + '</label><input class="field" id="account-' + name +
      '" name="' + name + '" type="' + type + '" value="' + esc(value) + '" ' +
      (['firstName','lastName','email'].includes(name) ? 'required ' : '') + (readonly ? 'readonly' : '') + '></div>';
    const html = '<form id="userEditor"><div class="form-grid">' +
      field('firstName','First name',user?.firstName || '',profileReadonly) +
      field('lastName','Last name',user?.lastName || '',profileReadonly) +
      field('displayName','Display name',user?.displayName || '',profileReadonly) +
      field('email','Email / login',user?.email || '',!!user,'email') +
      field('phone','Phone',user?.phone || '',profileReadonly,'tel') +
      field('position','Restaurant position',user?.position || 'Employee') +
      '<div class="wide">' + roles + '</div></div><p class="muted">' +
      (user ? 'Personal changes are saved to the account. Login email changes require verified account support; passwords use the sign-in recovery flow.'
        : 'The recipient receives an email invitation and chooses their own password. Access starts after acceptance.') +
      (profileReadonly ? ' This identity belongs to multiple restaurants; its holder manages personal details.' : '') +
      '</p><p id="userEditorError" role="alert" class="form-message bad" hidden></p>' +
      '<div class="form-actions"><button type="button" class="btn btn-light" id="cancelUserEditor">Cancel</button>' +
      '<button class="btn btn-primary" type="submit">' + (user ? 'Save changes' : 'Send invitation') + '</button></div></form>';
    Admin.openModal(user ? 'Edit user account' : 'Invite team member','Account administration',html,() => {
      $('cancelUserEditor').onclick = Admin.closeModal;
      $('userEditor').onsubmit = async event => {
        event.preventDefault();
        const form = event.currentTarget;
        if (form.dataset.saving === 'true') return;
        const values = new FormData(form), input = Object.fromEntries(values);
        input.action = user ? 'update' : 'invite';
        if (user) {
          input.id = user.id;
          if (canChangeRoles) input.roleIds = values.getAll('roleIds');
          else delete input.roleIds;
        }
        form.dataset.saving = 'true';
        form.querySelectorAll('button').forEach(button => { button.disabled = true; });
        $('userEditorError').hidden = true;
        try {
          const result = await mutate('editor',input);
          if (result) Admin.closeModal();
        } catch(error) {
          $('userEditorError').textContent = error.message;
          $('userEditorError').hidden = false;
        } finally {
          form.dataset.saving = 'false';
          form.querySelectorAll('button').forEach(button => { button.disabled = false; });
        }
      };
      $('account-firstName')?.focus();
    });
  }

  async function toggle(id) {
    const user=Auth.users().find(item=>item.id===id);
    if(!user||!Auth.has('users.suspend')||user.protected||user.id===Auth.current()?.id)return;
    const status=user.status==='suspended'?'active':'suspended';
    const prompt=user.pendingMembership?'Cancel the invitation for '+user.displayName+'?':
      (status==='suspended'?'Suspend':'Reactivate')+' restaurant access for '+user.displayName+'?';
    if(!window.confirm(prompt))return;
    try { await mutate('status:'+id,{action:'status',id,status}); } catch {}
  }
  async function resend(id) {
    try { await mutate('resend:'+id,{action:'resend',id}); } catch {}
  }
  async function convertApplication(applicationId) {
    try { return await mutate('application:'+applicationId,{action:'invite',applicationId}); } catch { return null; }
  }
  async function saveProfile() {
    const input={action:'profile'};
    for(const [key,id] of [['firstName','profileFirstName'],['lastName','profileLastName'],['displayName','profileDisplayName'],['phone','profilePhone']])
      input[key]=$(id)?.value || '';
    const button=$('saveProfile');if(button)button.disabled=true;
    try { await mutate('profile',input); } catch {} finally { if(button)button.disabled=false; }
  }
  window.GelatoAccounts={refresh,openEditor,toggle,resend,convertApplication,saveProfile};
  if(Auth.has('users.view'))refresh().catch(error=>Admin.toast(error.message,true));
})();
