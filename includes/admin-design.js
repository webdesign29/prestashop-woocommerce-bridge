(function(){
'use strict';var root=document.getElementById('wd29-admin');if(!root)return;
var destinations={'wd-tools':'activity','wd-reports':'reports','wd-settings':'settings','wd-licence':'licence','wd-orders-delta':'orders','wd-compare':'sync'};var desired=destinations[location.hash.slice(1)];if(desired&&desired!==root.dataset.wdView){var next=new URL(location.href);next.searchParams.set('wd_view',desired);next.searchParams.delete('inklura_demo_section');location.replace(next.href);return;}
root.querySelectorAll('table').forEach(function(table){
 var rows=table.querySelectorAll('tr');if(rows.length<=1){var empty=document.createElement('p');empty.className='wd-empty';empty.textContent='Aucun élément à afficher pour le moment.';table.before(empty);table.hidden=true;return;}
 var wrap=document.createElement('div');wrap.className='wd-table-scroll';wrap.tabIndex=0;wrap.setAttribute('role','region');var section=table.closest('details');wrap.setAttribute('aria-label',section?section.querySelector('summary strong').textContent:'Tableau du connecteur');table.before(wrap);wrap.append(table);
 table.querySelectorAll('th').forEach(function(th){th.scope='col';});
 var heads=Array.from(table.querySelectorAll('tr:first-child th'));var state=heads.findIndex(function(h){return ['state','État'].includes(h.textContent.trim());});
 if(state>=0)Array.from(rows).slice(1).forEach(function(row){var cell=row.children[state];if(!cell)return;var value=cell.textContent.trim();var badge=document.createElement('span');badge.className='wd-state'+(['applied','delivered'].includes(value)?' wd-state-good':(['failed','conflict'].includes(value)?' wd-state-error':value==='pending'?' wd-state-warn':''));badge.textContent=value;cell.replaceChildren(badge);});
});
root.querySelectorAll('[data-wd-origin-filter]').forEach(function(select){
 var section=select.closest('details'),table=section.querySelector('[data-wd-origin-table]');if(!table||!table.closest('.wd-table-scroll'))return;
 var count=document.createElement('span');count.className='wd-filter-count';count.setAttribute('aria-live','polite');select.after(count);
 var rows=Array.from(table.querySelectorAll('tr[data-wd-origin]'));
 var empty=document.createElement('p');empty.className='wd-empty';empty.textContent='Aucune fiche de cette origine dans ce rapport.';empty.hidden=true;table.closest('.wd-table-scroll').after(empty);
 function filter(){var visible=0;rows.forEach(function(row){row.hidden=select.value!=='all'&&row.dataset.wdOrigin!==select.value;if(!row.hidden)visible++;});count.textContent=visible+' / '+rows.length+' fiches affichées';empty.hidden=visible!==0||rows.length===0;}
 select.addEventListener('change',filter);filter();
});
var settings=root.querySelector('.wd-section-settings form');if(settings){
 settings.classList.add('wd-form-grid');
 Array.from(settings.children).forEach(function(el){
  if(el.tagName==='LABEL'&&!el.querySelector('input,select,textarea')){var control=el.nextElementSibling;if(control&&['INPUT','SELECT','TEXTAREA'].includes(control.tagName)){var field=document.createElement('div');field.className='wd-field';el.before(field);field.append(el,control);}}
 });
 settings.querySelectorAll('label').forEach(function(label,index){var control=label.querySelector('input,select,textarea')||(label.parentElement.classList.contains('wd-field')?label.nextElementSibling:null);if(!control)return;if(control.type==='checkbox'){label.classList.add('wd-option');}else{label.classList.add('wd-field');if(!control.id)control.id='wd-setting-'+index;label.htmlFor=control.id;}});
 var save=settings.querySelector('button[value="save"]');if(save){var footer=document.createElement('div');footer.className='wd-save-row';save.before(footer);footer.append(save);}
}
root.querySelectorAll('a[href^="#wd-"]').forEach(function(link){link.addEventListener('click',function(){var target=document.querySelector(link.getAttribute('href'));if(target&&target.tagName==='DETAILS')target.open=true;});});
// Restore the relevant panel after a form submission; no configuration data is stored.
try{var panel=sessionStorage.getItem('wd29-panel-'+location.pathname);if(panel){var el=document.getElementById(panel);if(el&&el.tagName==='DETAILS')el.open=true;sessionStorage.removeItem('wd29-panel-'+location.pathname);}root.querySelectorAll('form').forEach(function(form){form.addEventListener('submit',function(){var el=form.closest('details');if(el)sessionStorage.setItem('wd29-panel-'+location.pathname,el.id);});});}catch(e){}
root.querySelectorAll('[data-differences-only]').forEach(function(input){input.addEventListener('change',function(){root.querySelectorAll('[data-delta-state]').forEach(function(row){row.hidden=input.checked&&row.dataset.deltaState==='same';});});});
var running=false,stopping=false,lines=[],panel=root.querySelector('#wd-sync-progress');
if(panel){
 var state=panel.querySelector('[data-sync-status]'),log=panel.querySelector('[data-sync-log]'),stop=panel.querySelector('[data-sync-stop]');
 var dialog=document.createElement('dialog');dialog.className='wd-sync-confirm';dialog.innerHTML='<form method="dialog"><h2>Confirmer la synchronisation</h2><p data-confirm-label></p><p>Seules les fiches originales du sens choisi sont envoyées. Les conflits restent à examiner. Les modes automatiques sont conservés.</p><div><button value="cancel" class="button btn btn-default">Annuler</button><button value="confirm" class="button button-primary btn btn-primary">Synchroniser</button></div></form>';root.append(dialog);
 function append(message){lines.push(new Date().toISOString()+' '+message);if(lines.length>1000)lines.shift();log.textContent=lines.join('\n');log.scrollTop=log.scrollHeight;}
 function busy(value){running=value;root.querySelectorAll('[data-sync-start]').forEach(function(b){b.disabled=value;});root.querySelectorAll('.wd-compare-controls select,.wd-compare-controls button,button[value=manual_record_sync]').forEach(function(b){b.disabled=value;});stop.disabled=!value;panel.querySelector('progress').hidden=!value;}
 stop.onclick=function(){stopping=true;stop.disabled=true;state.textContent='Arrêt demandé : fin de la fiche en cours…';};
 panel.querySelector('[data-sync-copy]').onclick=async function(){var text='Inklura Sync — '+root.querySelector('h1').textContent+'\n'+state.textContent+'\n'+lines.join('\n');try{await navigator.clipboard.writeText(text);var copied=panel.querySelector('[data-sync-copy]');copied.textContent='Journal copié';setTimeout(function(){copied.textContent='Copier le journal';},2000);}catch(e){var area=document.createElement('textarea');area.value=text;panel.append(area);area.focus();area.select();}};
 window.addEventListener('beforeunload',function(e){if(running){e.preventDefault();e.returnValue='';}});
 var controls=root.querySelector('.wd-compare-controls');if(controls){controls.querySelectorAll('select').forEach(function(select){select.addEventListener('change',function(){root.querySelectorAll('[data-sync-start]').forEach(function(b){b.disabled=true;});var hint=root.querySelector('.wd-selection-hint');if(!hint){hint=document.createElement('p');hint.className='wd-selection-hint';hint.setAttribute('role','status');controls.after(hint);}hint.textContent='Actualisez la comparaison pour confirmer cette famille et ce sens.';});});}
 root.querySelectorAll('[data-full-sync]').forEach(function(form){var button=form.querySelector('[data-sync-start]');button.disabled=false;button.onclick=function(){if(running)return;dialog.querySelector('[data-confirm-label]').textContent=button.textContent;dialog.returnValue='';dialog.showModal();dialog.querySelector('button[value=cancel]').focus();dialog.onclose=function(){if(dialog.returnValue==='confirm')run(form,button.textContent);};};});
 async function run(form,label){
  busy(true);stopping=false;lines=[];panel.hidden=false;panel.scrollIntoView({block:'nearest'});var cursor={family:0,offset:0,index:0},counts={applied:0,same:0,review:0};append(label);append('Catalogue, contacts et commandes selon la sélection ; quantités existantes conservées.');
  try{while(!stopping){
   state.textContent=label+' · '+counts.applied+' appliquée(s), '+counts.same+' identique(s), '+counts.review+' à examiner';
   var data=new FormData(form);data.set('bridge_action','manual_records_batch');data.set('manual_family',cursor.family);data.set('manual_offset',cursor.offset);data.set('manual_index',cursor.index);
   var response=await fetch(form.dataset.syncEndpoint||location.href,{method:'POST',body:data,credentials:'same-origin',headers:{'Accept':'application/json'}});var result;try{result=await response.json();}catch(e){throw new Error('Réponse indisponible ou session expirée. Rechargez puis relancez : les fiches déjà appliquées seront reconnues.');}
   if(!response.ok||!result.ok)throw new Error(result.error||'Traitement indisponible.');
   var record=result.result;if(record.state==='applied')counts.applied++;else if(record.state==='same')counts.same++;else if(record.state!=='empty')counts.review++;
   if(record.key)append(record.key+' · '+record.state+(record.error?' · '+record.error:'')+(record.ack==='pending'?' · confirmation source en attente':''));
   if(result.done){state.textContent='Terminé · '+counts.applied+' appliquée(s), '+counts.same+' identique(s), '+counts.review+' à examiner. Actualisez la comparaison.';append(state.textContent);break;}
   cursor=result.cursor;
  }
  if(stopping){state.textContent='Arrêté · '+counts.applied+' appliquée(s), '+counts.same+' identique(s), '+counts.review+' à examiner.';append(state.textContent);}
  }catch(e){state.textContent='Interrompu · '+e.message;append(state.textContent);}finally{busy(false);}
 }
}
})();
