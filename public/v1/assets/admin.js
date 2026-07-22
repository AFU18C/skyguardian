const card=(title,value,state='ok')=>`<article class="card"><h3>${title}</h3><div class="value ${state}">${value}</div></article>`;

async function load(){
  const root=document.querySelector('#dashboard');
  const status=document.querySelector('#app-status');
  try{
    const [healthResponse,workerResponse]=await Promise.all([
      fetch('/v1/index.php',{headers:{Accept:'application/json'}}),
      fetch('/v1/worker-status.php',{headers:{Accept:'application/json'}})
    ]);
    if(!healthResponse.ok||!workerResponse.ok) throw new Error('HTTP error');
    const health=await healthResponse.json();
    const workers=await workerResponse.json();
    const list=Array.isArray(workers.data)?workers.data:Object.values(workers.data||workers||{});
    const active=list.filter(item=>item&&['running','idle','ok'].includes(String(item.status))).length;
    root.innerHTML=[
      card('Версия',health.version||'v1'),
      card('Приложение',health.ok?'Работает':'Ошибка',health.ok?'ok':'error'),
      card('Workers',`${active}/${list.length}`,active===list.length?'ok':'warn'),
      card('Хранилище','JSON','ok')
    ].join('');
    status.textContent='Система доступна';
    status.className='ok';
  }catch(error){
    root.innerHTML=card('Ошибка','Нет данных','error');
    status.textContent='Ошибка соединения';
    status.className='error';
  }
}

document.addEventListener('DOMContentLoaded',load);
