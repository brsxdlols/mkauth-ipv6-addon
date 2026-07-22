(function(){
  var input=document.querySelector('input[name="busca"]');
  var status=document.querySelector('select[name="status"]');
  var limit=document.querySelector('select[name="limit"]');
  var body=document.getElementById('ipv6-results');
  var form=document.querySelector('.ipv6-filters');
  var timer=0;
  if(!input||!status||!limit||!body)return;
  function esc(value){var node=document.createElement('div');node.textContent=value==null?'':value;return node.innerHTML}
  function update(){
    clearTimeout(timer);
    timer=setTimeout(function(){
      var query=new URLSearchParams({q:input.value,status:status.value,limit:limit.value});
      fetch('search.php?'+query.toString(),{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(response){if(!response.ok)throw new Error('HTTP '+response.status);return response.json()})
        .then(function(data){
          if(!Array.isArray(data.rows))throw new Error('Resposta invalida');
          body.innerHTML=data.rows.map(function(row){
            return '<tr><td><span class="'+(row.online?'online':'offline')+'">&#9679; '+(row.online?'Online':'Offline')+'</span></td><td>'+esc(row.username)+'</td><td>'+esc(row.ipv6||'—')+'</td><td>'+esc(row.framedipaddress||'—')+'</td><td>'+esc(row.callingstationid||'—')+'</td><td>'+esc(row.acctstarttime||'')+'</td><td>'+esc(row.acctstoptime||'')+'</td><td>'+esc(row.duration)+'</td><td><a href="?busca='+encodeURIComponent(row.username)+'">&#128269;</a></td></tr>';
          }).join('')||'<tr><td colspan="9">Nenhum registro encontrado.</td></tr>';
        })
        .catch(function(error){console.error('Busca IPv6:',error)});
    },250);
  }
  input.addEventListener('input',update);
  status.addEventListener('change',update);
  limit.addEventListener('change',update);
  if(form)form.addEventListener('submit',function(event){event.preventDefault();update()});
})();
