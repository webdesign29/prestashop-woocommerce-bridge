(function(){
'use strict';var root=document.getElementById('wd29-admin');if(!root)return;
root.querySelectorAll('table').forEach(function(table){
 var rows=table.querySelectorAll('tr');if(rows.length<=1){var empty=document.createElement('p');empty.className='wd-empty';empty.textContent='Aucun élément à afficher pour le moment.';table.before(empty);table.hidden=true;return;}
 var wrap=document.createElement('div');wrap.className='wd-table-scroll';wrap.tabIndex=0;wrap.setAttribute('role','region');var section=table.closest('details');wrap.setAttribute('aria-label',section?section.querySelector('summary strong').textContent:'Tableau du connecteur');table.before(wrap);wrap.append(table);
 table.querySelectorAll('th').forEach(function(th){th.scope='col';});
 var heads=Array.from(table.querySelectorAll('tr:first-child th'));var state=heads.findIndex(function(h){return h.textContent.trim()==='state';});
 if(state>=0)Array.from(rows).slice(1).forEach(function(row){var cell=row.children[state];if(!cell)return;var value=cell.textContent.trim();var badge=document.createElement('span');badge.className='wd-state'+(['applied','delivered'].includes(value)?' wd-state-good':(['failed','conflict'].includes(value)?' wd-state-error':value==='pending'?' wd-state-warn':''));badge.textContent=value;cell.replaceChildren(badge);});
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
})();
