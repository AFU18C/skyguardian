const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
const qs=(s,r=document)=>r.querySelector(s);
const qsa=(s,r=document)=>[...r.querySelectorAll(s)];
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const card=(title,value,state='ok')=>`<article class="card"><h3>${esc(title)}</h3><div class="value ${state}">${esc(value)}</div></article>`;

async function api(action,options={}){
  const response=await fetch(`/v1/admin/api.php?action=${encodeURIComponent(action)}`,{
    headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':csrf,...(options.headers||{})},
    credentials:'same-origin',
    ...options
  });
  const data=await response.json().catch(()=>({ok:false,error:'Некорректный ответ сервера'}));
  if(!response.ok||!data.ok) throw new Error(data.error||`HTTP ${response.status}`);
  return data.data??data;
}

function notify(message,type='ok'){
  let box=qs('#notice');
  if(!box){box=document.createElement('div');box.id='notice';document.body.append(box);}
  box.className=`notice ${type}`;box.textContent=message;box.hidden=false;
  clearTimeout(notify.timer);notify.timer=setTimeout(()=>box.hidden=true,3500);
}

function formData(form){
  const data=Object.fromEntries(new FormData(form));
  qsa('input[type="checkbox"]',form).forEach(el=>data[el.name]=el.checked);
  return data;
}

async function renderDashboard(){
  const root=qs('#dashboard'),status=qs('#app-status');
  const [health,overview]=await Promise.all([fetch('/v1/index.php').then(r=>r.json()),api('overview')]);
  const workers=Object.values(overview.workers||{});
  const active=workers.filter(w=>['running','idle','active','ok'].includes(String(w.status))).length;
  root.innerHTML=[card('Версия',health.version||'v1'),card('Приложение',health.ok?'Работает':'Ошибка',health.ok?'ok':'error'),card('Workers',`${active}/${workers.length}`,active===workers.length?'ok':'warn'),card('Каналы',overview.channels||0),card('Техаккаунты',overview.accounts||0)].join('');
  status.textContent='Система доступна';status.className='ok';
}

async function renderBot(){
  const root=qs('[data-module="bot"]'),data=await api('bot');
  root.innerHTML=`<form class="stack" data-save="bot">
    <label>Токен бота<input name="token" type="password" value="${esc(data.token||'')}" autocomplete="off" placeholder="Оставьте configured без изменения"></label>
    <label>Chat ID<input name="chat_id" value="${esc(data.chat_id||'')}"></label>
    <label>Режим<select name="mode"><option value="webhook" ${data.mode==='webhook'?'selected':''}>Webhook</option><option value="polling" ${data.mode==='polling'?'selected':''}>Polling</option></select></label>
    <label>Webhook secret<input name="webhook_secret" type="password" value="${esc(data.webhook_secret||'')}" autocomplete="off"></label>
    <label class="check"><input name="enabled" type="checkbox" ${data.enabled?'checked':''}> Бот включён</label>
    <button>Сохранить</button></form>`;
}

async function renderModeration(){
  const root=qs('[data-module="moderation"]'),data=await api('moderation');
  root.innerHTML=`<form class="stack" data-save="moderation">
    <label class="check"><input name="anti_spam" type="checkbox" ${data.anti_spam?'checked':''}> Антиспам</label>
    <label class="check"><input name="link_filter" type="checkbox" ${data.link_filter?'checked':''}> Удалять ссылки</label>
    <label class="check"><input name="admin_bypass" type="checkbox" ${data.admin_bypass?'checked':''}> Не проверять администраторов</label>
    <label>Запрещённые слова<textarea name="forbidden_words" placeholder="Одно слово на строку">${esc((data.forbidden_words||[]).join('\n'))}</textarea></label>
    <label>Мут, секунд<input name="mute_seconds" type="number" min="0" value="${esc(data.mute_seconds||0)}"></label>
    <button>Сохранить</button></form>`;
}

async function renderAccounts(){
  const root=qs('[data-module="accounts"]'),items=await api('accounts');
  root.innerHTML=`<form class="inline-form" data-save="account-save"><input name="id" placeholder="ID аккаунта" required><input name="api_id" type="number" placeholder="API ID" required><input name="api_hash" type="password" placeholder="API Hash" required><button>Добавить</button></form>
  <div class="table-wrap"><table><thead><tr><th>ID</th><th>Пользователь</th><th>Статус</th><th></th></tr></thead><tbody>${items.map(a=>`<tr><td>${esc(a.id)}</td><td>${esc(a.connected_user?.username||a.connected_user?.first_name||'—')}</td><td><span class="badge ${a.enabled?'ok':'warn'}">${a.enabled?'Включён':'Выключен'}</span></td><td><button class="danger" data-delete-account="${esc(a.id)}">Удалить</button></td></tr>`).join('')||'<tr><td colspan="4">Нет аккаунтов</td></tr>'}</tbody></table></div>`;
}

async function renderChannels(){
  const root=qs('[data-module="channels"]'),groups=await api('channels'),items=[...(groups.news||[]),...(groups.alerts||[])];
  root.innerHTML=`<form class="channel-form" data-save="channel-save">
    <input name="id" placeholder="ID" required><select name="scope"><option value="news">Новости</option><option value="alerts">Оповещения</option></select>
    <input name="source" placeholder="Источник @channel" required><input name="destination" placeholder="Назначение @channel" required><input name="account_id" placeholder="ID аккаунта" required>
    <select name="format"><option value="original">Оригинал</option><option value="text">Текст</option><option value="text_without_links">Текст без ссылок</option><option value="media">Медиа</option><option value="text_and_media">Текст и медиа</option></select>
    <select name="fetch_start"><option value="new">Только новые</option><option value="last_5">Последние 5</option><option value="last_10">Последние 10</option><option value="last_20">Последние 20</option></select>
    <input name="frequency" type="number" min="1" value="1"><select name="frequency_unit"><option value="minutes">Минуты</option><option value="hours">Часы</option></select>
    <label class="check"><input name="enabled" type="checkbox" checked> Включён</label><button>Сохранить канал</button></form>
    <div class="table-wrap"><table><thead><tr><th>ID</th><th>Тип</th><th>Источник</th><th>Назначение</th><th></th></tr></thead><tbody>${items.map(c=>`<tr><td>${esc(c.id)}</td><td>${esc(c.scope)}</td><td>${esc(c.source)}</td><td>${esc(c.destination)}</td><td><button class="danger" data-delete-channel="${esc(c.id)}">Удалить</button></td></tr>`).join('')||'<tr><td colspan="5">Нет каналов</td></tr>'}</tbody></table></div>`;
}

async function renderWorkers(){
  const root=qs('[data-module="workers"]'),data=await api('workers');
  root.innerHTML=`<div class="grid">${Object.entries(data).map(([name,w])=>card(name,`${w.status||'idle'} · ${w.published_count||0}`,w.status==='error'?'error':w.status==='active'?'ok':'warn')).join('')}</div>`;
}

async function loadAll(){
  try{await Promise.all([renderDashboard(),renderBot(),renderModeration(),renderAccounts(),renderChannels(),renderWorkers()]);}
  catch(error){qs('#app-status').textContent='Ошибка';qs('#app-status').className='error';notify(error.message,'error');}
}

document.addEventListener('submit',async event=>{
  const form=event.target.closest('[data-save]');if(!form)return;event.preventDefault();
  try{
    const action=form.dataset.save,data=formData(form);
    if(action==='moderation')data.forbidden_words=String(data.forbidden_words||'').split(/\r?\n/).map(v=>v.trim()).filter(Boolean);
    await api(action,{method:'POST',body:JSON.stringify(data)});notify('Сохранено');await loadAll();
  }catch(error){notify(error.message,'error');}
});

document.addEventListener('click',async event=>{
  const account=event.target.dataset.deleteAccount,channel=event.target.dataset.deleteChannel;
  if(!account&&!channel)return;
  if(!confirm('Подтвердить удаление?'))return;
  try{await api(account?'account-delete':'channel-delete',{method:'POST',body:JSON.stringify({id:account||channel})});notify('Удалено');await loadAll();}catch(error){notify(error.message,'error');}
});

document.addEventListener('DOMContentLoaded',loadAll);