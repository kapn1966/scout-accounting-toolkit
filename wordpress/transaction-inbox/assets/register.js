(function(){
  function boot(){

      'use strict';
      function moneyToCents(value){
        var text=String(value||'').trim().replace(/[$\s]/g,'').replace(',','.');
        if(!/^\d+(?:\.\d{1,2})?$/.test(text))return null;
        var cents=Math.round(Number(text)*100);
        return Number.isFinite(cents)&&cents>0?cents:null;
      }
      function updateSplit(form){
        if(!form)return;
        var total=moneyToCents(form.dataset.total),allocated=0,valid=true,used=0;
        form.querySelectorAll('.line').forEach(function(row){
          var account=row.querySelector('select').value;
          var amountText=row.querySelector('[name="split_amount[]"]').value.trim();
          var note=row.querySelector('[name="split_note[]"]').value.trim();
          if(!account&&!amountText&&!note)return;
          used++;
          var cents=moneyToCents(amountText);
          if(!account||cents===null){valid=false;return;}
          allocated+=cents;
        });
        var remaining=total===null?0:total-allocated;
        var allocatedEl=form.querySelector('.allocated');
        var remainingEl=form.querySelector('.remaining');
        if(!allocatedEl||!remainingEl){console.error('Transaction Inbox malformed split form',form);return;}
        allocatedEl.textContent=(allocated/100).toFixed(2);
        remainingEl.textContent=(remaining/100).toFixed(2);
        var ready=total!==null&&used>0&&valid&&remaining===0;
        var state=form.querySelector('.split-state');
        if(state){state.textContent=ready?'Balanced and ready to save':(!valid?'Complete every used line':(used===0?'Add at least one split line':'Split must balance'));state.className='split-state '+(ready?'valid':'invalid');}
        var save=form.querySelector('button.save');if(save)save.disabled=!ready;
      }
      function showMessage(text,type){
        var root=document.querySelector('.to403-register');if(!root)return;
        var old=root.querySelector('.ajax-message');if(old)old.remove();
        var box=document.createElement('div');box.className='msg ajax-message '+(type||'success');box.textContent=text;
        var heading=root.querySelector('h2');if(heading)heading.insertAdjacentElement('afterend',box);
      }
      function setBusy(form,busy){var button=form.querySelector('button[type="submit"]');if(button){button.disabled=busy;button.setAttribute('aria-busy',busy?'true':'false');}}
      function markReady(bankId){var row=document.querySelector('tr.bank[data-bank-id="'+bankId+'"]');if(!row)return;row.classList.add('ready');var pill=row.querySelector('.pill');if(pill)pill.textContent='READY';}
      function removeCandidateRows(bankId){document.querySelectorAll('.alternative-'+bankId).forEach(function(row){row.remove();});var toggle=document.querySelector('.candidates-toggle[data-target="alternative-'+bankId+'"]');if(toggle)toggle.remove();}
      async function postForm(form,action){
        var data=new FormData(form);data.set('action',action);data.set('nonce',data.get('_wpnonce')||'');
        var response=await fetch(to403TransactionInbox.ajaxUrl,{method:'POST',credentials:'same-origin',body:data});
        var result=await response.json();if(!response.ok||!result.success)throw new Error(result&&result.data&&result.data.message?result.data.message:'The change could not be saved.');return result.data;
      }
      document.querySelectorAll('form.split').forEach(function(form){try{updateSplit(form);}catch(e){console.error(e);}});
      document.querySelectorAll('.candidates-toggle').forEach(function(b){b.dataset.closed=b.textContent;b.dataset.open='Hide alternatives';});
      document.addEventListener('click',function(e){
        if(e.target.matches('.all'))document.querySelectorAll('.check').forEach(function(x){x.checked=e.target.checked;});
        var open=e.target.closest('.split-open');if(open){var modal=document.getElementById(open.dataset.id);if(modal){modal.hidden=false;updateSplit(modal.querySelector('.split'));}return;}
        var close=e.target.closest('[data-close]');if(close){close.closest('.modal').hidden=true;return;}
        if(e.target.matches('.add')){var form=e.target.closest('.split'),lines=form.querySelector('.lines'),row=lines.querySelector('.line').cloneNode(true);row.querySelectorAll('input,select').forEach(function(x){x.value='';});lines.appendChild(row);updateSplit(form);}
        if(e.target.matches('.remove')){var form=e.target.closest('.split'),lines=form.querySelector('.lines');if(lines.children.length>1){e.target.closest('.line').remove();updateSplit(form);}}
        var toggle=e.target.closest('.candidates-toggle');if(toggle){var rows=document.querySelectorAll('.'+toggle.dataset.target),expanded=toggle.getAttribute('aria-expanded')==='true';toggle.setAttribute('aria-expanded',expanded?'false':'true');rows.forEach(function(x){x.hidden=expanded;});toggle.textContent=expanded?toggle.dataset.closed:toggle.dataset.open;}
      });
      document.addEventListener('input',function(e){var form=e.target.closest('form.split');if(form)updateSplit(form);});
      document.addEventListener('change',function(e){var form=e.target.closest('form.split');if(form)updateSplit(form);});
      document.addEventListener('submit',async function(e){
        var form=e.target;
        if(!window.to403TransactionInbox||!window.fetch)return;
        if(form.matches('#to403-bulk-form')){
          e.preventDefault();
          form.querySelectorAll('input.bulk-category-value').forEach(function(x){x.remove();});
          var selected=[];document.querySelectorAll('.check:checked').forEach(function(check){selected.push(check.value);var row=check.closest('tr.bank'),select=row?row.querySelector('form.category select[name="category"]'):null;if(select){var hidden=document.createElement('input');hidden.type='hidden';hidden.className='bulk-category-value';hidden.name='displayed_category['+check.value+']';hidden.value=select.value;form.appendChild(hidden);}});
          if(!selected.length){showMessage('Select at least one transaction.','error');return;}
          setBusy(form,true);try{var bulkResult=await postForm(form,to403TransactionInbox.actionAction);(bulkResult.processed||[]).forEach(function(item){if(item.changed)markReady(item.bank_id);removeCandidateRows(item.bank_id);var check=document.querySelector('.check[value="'+item.bank_id+'"]');if(check)check.checked=false;});showMessage(bulkResult.message,'success');}catch(error){showMessage(error.message,'error');}finally{setBusy(form,false);}return;
        }
        if(form.matches('form.category')){
          e.preventDefault();setBusy(form,true);try{var categoryResult=await postForm(form,to403TransactionInbox.actionAction);markReady(categoryResult.bank_id);showMessage(categoryResult.message,'success');}catch(error){showMessage(error.message,'error');}finally{setBusy(form,false);}return;
        }
        if(form.matches('form.split')){
          e.preventDefault();updateSplit(form);var save=form.querySelector('button.save');if(save.disabled)return;setBusy(form,true);try{var splitResult=await postForm(form,to403TransactionInbox.actionAction);markReady(splitResult.bank_id);var row=document.querySelector('tr.bank[data-bank-id="'+splitResult.bank_id+'"]');if(row){var splitButton=row.querySelector('.split-open');if(splitButton)splitButton.textContent=splitResult.line_count>1?'Edit split':'Split';}form.closest('.modal').hidden=true;showMessage(splitResult.message,'success');}catch(error){showMessage(error.message,'error');}finally{updateSplit(form);}return;
        }
        if(form.matches('form.ajax-accept, form[data-to403-operation="accept"]')){
          e.preventDefault();setBusy(form,true);try{var result=await postForm(form,to403TransactionInbox.acceptAction),evidenceRow=form.closest('tr.evidence'),bankId=result.bank_id;removeCandidateRows(bankId);if(evidenceRow){var cell=evidenceRow.querySelector('td[colspan]');evidenceRow.className='evidence matched';if(cell){cell.textContent='└─ MATCHED · Evidence';if(result.payer)cell.textContent+=' · '+result.payer;if(result.description)cell.textContent+=' · '+result.description;if(result.section)cell.textContent+=' · '+result.section;}}showMessage(result.message||'Match accepted.','success');}catch(error){setBusy(form,false);showMessage(error.message,'error');}return;
        }
      });
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
