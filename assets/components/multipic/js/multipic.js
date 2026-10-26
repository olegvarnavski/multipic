(function (root) {
    'use strict';
    const natural = (a, b) => a.name.localeCompare(b.name, undefined, {numeric:true, sensitivity:'base'});
    function select(items, parity='all', offset=0, limit=0) {
        const rows = items.filter(x=>x.active).map((x,i)=>({...x,idx:i+1})).filter(x=>parity==='all'||(parity==='odd'?x.idx%2:!(x.idx%2)));
        return rows.slice(offset,limit>0?offset+limit:undefined);
    }
    function fitImage(width,height,edge) {
        const scale=edge>0?Math.min(1,edge/Math.max(width,height)):1;
        return {width:Math.max(1,Math.round(width*scale)),height:Math.max(1,Math.round(height*scale))};
    }
    async function prepareImage(file,edge,quality,env=root) {
        if(!(edge>0))return file;
        const url=env.URL.createObjectURL(file);
        try {
            const img=new env.Image();
            await new Promise((resolve,reject)=>{img.onload=resolve;img.onerror=()=>reject(Error('Браузер не смог прочитать изображение'));img.src=url;});
            const size=fitImage(img.naturalWidth,img.naturalHeight,edge);
            if(size.width===img.naturalWidth&&size.height===img.naturalHeight)return file;
            const canvas=env.document.createElement('canvas');canvas.width=size.width;canvas.height=size.height;
            try {
                const ctx=canvas.getContext('2d');if(!ctx)throw Error('Обработка изображений в браузере недоступна');
                ctx.drawImage(img,0,0,size.width,size.height);
                const blob=await new Promise(resolve=>canvas.toBlob(resolve,file.type,quality/100));
                if(!blob || blob.type!==file.type)throw Error('Браузер не поддерживает сохранение этого формата');
                return new env.File([blob],file.name,{type:blob.type,lastModified:file.lastModified});
            }finally{canvas.width=1;canvas.height=1;}
        }finally{env.URL.revokeObjectURL(url);}
    }
    if (typeof module !== 'undefined') module.exports={natural,select,fitImage,prepareImage};
    if (!root.document || root.MultiPic) return;
    const instances=new Set();
    const icons={eye:'Показано',hidden:'Скрыто',edit:'✎',remove:'×',drag:'⠿'};
    const tokens=new Set();
    function pendingWithin(container){return [...instances].some(x=>document.contains(x.node)&&(!container||container.contains(x.node))&&(x.running||x.queue.length));}
    function waitMessage(){if(root.Ext&&Ext.Msg)Ext.Msg.alert('MultiPic','Дождитесь завершения загрузки изображений.');else alert('Дождитесь завершения загрузки изображений.');}
    function installFormHooks(){
        if(!root.Ext)return;
        const panel=Ext.getCmp('modx-panel-resource');
        if(panel&&!panel._multipicHooks){
            panel._multipicHooks=true;
            panel.on('beforeSubmit',function(o){
                if(pendingWithin()){waitMessage();return false;}
                const form=o.form||panel.getForm();
                form.baseParams=form.baseParams||{};
                form.baseParams.multipic_tokens=JSON.stringify([...tokens]);
            });
        }
        // MIGX saves through doSubmit(), not the resource panel beforeSubmit event.
        // Wrap only existing window instances containing our editor; do not modify global MIGX prototypes.
        for(const editor of instances){
            const node=editor.node.closest('.x-window');
            const win=node&&Ext.getCmp(node.id);
            if(!win||win._multipicHooks||typeof win.doSubmit!=='function')continue;
            win._multipicHooks=true;
            const original=win.doSubmit;
            win.doSubmit=function(){if(pendingWithin(node)){waitMessage();return false;}return original.apply(this,arguments);};
        }
    }
    const el=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=text;return n;};
    function button(label, callback, cls='') {const b=el('button',cls,label);b.type='button';b.addEventListener('click',callback);return b;}
    class Editor {
        constructor(node,cfg) {
            this.node=node;this.cfg=cfg;this.items=cfg.data.items;this.selected=new Set();this.queue=[];this.running=0;this.editing=null;
            this.field=node.previousElementSibling;
            if (!this.field || this.field.name!==cfg.field) this.field=document.getElementById(cfg.field);
            this.node.addEventListener('dragover',e=>{if(e.dataTransfer.types.includes('Files')){e.preventDefault();this.node.classList.add('mp-over');}});
            this.node.addEventListener('dragleave',()=>this.node.classList.remove('mp-over'));
            this.node.addEventListener('drop',e=>{if(e.dataTransfer.files.length){e.preventDefault();this.node.classList.remove('mp-over');this.addFiles([...e.dataTransfer.files]);}});
            this.render(); this.registerToken();
            this.timer=setInterval(()=>{if(!document.contains(this.node)){clearInterval(this.timer);instances.delete(this);tokens.delete(this.cfg.token);const f=document.querySelector('input[name="multipic_tokens"]');if(f)f.value=JSON.stringify([...tokens]);return;}this.request('heartbeat').then(()=>{if(this.sessionError){this.sessionError='';this.render();}}).catch(e=>{this.sessionError=e.message;this.render();});},60000);
            instances.add(this);installFormHooks();
        }
        registerToken() {
            // One top-level hidden field, included in normal MODX resource form submission.
            tokens.add(this.cfg.token);
            const form=document.getElementById('modx-resource-form') || this.node.closest('form');
            if(!form)return;
            let field=form.querySelector('input[name="multipic_tokens"]');
            if(!field){field=el('input');field.type='hidden';field.name='multipic_tokens';field.value='[]';form.append(field);}
            const values=JSON.parse(field.value);if(!values.includes(this.cfg.token)){values.push(this.cfg.token);field.value=JSON.stringify(values);}
        }
        async request(action,extra={}) {
            const form=new FormData();Object.entries({action,token:this.cfg.token,HTTP_MODAUTH:this.cfg.auth,...extra}).forEach(([k,v])=>form.append(k,v));
            const r=await fetch(this.cfg.connector,{method:'POST',body:form,credentials:'same-origin'});
            let data;try{data=await r.json();}catch(e){throw Error('Сервер вернул некорректный ответ');}
            if(!r.ok||!data.success){const error=Error((data.message||'Ошибка запроса')+(data.request?' (код '+data.request+')':''));error.code=data.code;throw error;}return data.object;
        }
        save() {
            this.cfg.data.items=this.items.filter(x=>!x._pending&&!x._error).map(({_file,_path,_url,_prepared,_pending,_error,_missing,_missingThumb,name,...x})=>x);
            this.field.value=JSON.stringify(this.cfg.data);
            this.field.dispatchEvent(new Event('change',{bubbles:true}));
            installFormHooks();
            if(root.Ext){const f=Ext.getCmp(this.cfg.field);if(f){f.setValue(this.field.value);f.fireEvent('change',f,this.field.value);}}
            if(root.MODx&&MODx.fireResourceFormChange)MODx.fireResourceFormChange();
        }
        addFiles(files) {
            files.sort(natural);
            // Keep all candidates in queue; duplicates/errors do not consume a free slot.
            for(const file of files)this.queue.push({_file:file,name:file.name});
            this.pump();
        }
        pump() {
            while(this.running<2&&this.queue.length) {
                const count=this.items.filter(x=>!x._error).length;
                if(count>=this.cfg.max){
                    if(this.running)return;
                    this.notice='Не добавлены из-за лимита: '+this.queue.map(x=>x.name).join(', ');this.queue=[];this.render();return;
                }
                const candidate=this.queue.shift();const item={id:'pending-'+Math.random().toString(36).slice(2),active:true,_pending:true,...candidate};
                this.items.push(item);this.running++;this.render();this.run(item);
            }
        }
        async run(item) {
            try {
                let uploaded;
                try{uploaded=await this.request(item._prepared?'upload':(item._path?'import':'upload'),item._prepared?{file:item._prepared}:(item._path?{path:item._path}:{file:item._file}));}
                catch(error){
                    if(item._prepared || !['multipic_memory','multipic_pixels'].includes(error.code) || !(this.cfg.edge>0))throw error;
                    let original=item._file;
                    if(!original && item._url && root.location){
                        const url=new URL(item._url,root.location.href);
                        if(url.origin!==root.location.origin)throw error;
                        const response=await fetch(url.href,{credentials:'same-origin'});if(!response.ok)throw error;
                        const blob=await response.blob();original=new root.File([blob],item.name,{type:blob.type});
                    }
                    if(!original)throw error;
                    const prepared=await prepareImage(original,this.cfg.edge,this.cfg.quality||95);
                    if(prepared===original)throw error;
                    if(!this.items.includes(item))return;
                    item._prepared=prepared;
                    this.notice='Большое изображение уменьшено в браузере до '+this.cfg.edge+' px. Исходный файл сохранён.';
                    uploaded=await this.request('upload',{file:prepared});
                }
                if(!this.items.includes(item))return;
                if(this.items.some(x=>x!==item&&x.file===uploaded.file)){this.items.splice(this.items.indexOf(item),1);this.notice='Уже добавлено: '+item.name;}
                else {Object.assign(item,uploaded,{_pending:false,_error:null});delete item._file;delete item._path;delete item._url;delete item._prepared;}
                this.save();
            }catch(e){if(this.items.includes(item)){item._pending=false;item._error=e.message;}}
            finally{this.running--;this.render();this.pump();}
        }
        importFile() {
            if(!root.MODx||!MODx.load){this.notice='Файловый менеджер MODX недоступен';this.render();return;}
            const browser=MODx.load({xtype:'modx-browser',source:this.cfg.source,allowedFileTypes:'jpg,jpeg,png,webp',multiple:false,
                listeners:{select:{fn:data=>{
                    const view=browser.win&&browser.win.view;
                    const records=view&&view.getSelectedRecords?view.getSelectedRecords():[];
                    const files=records.length?records.map(r=>r.data):[data];
                    for(const f of files)if(f&&!f.disabled)this.queue.push({_path:f.relativeUrl||f.url,_url:f.url,name:f.name||f.relativeUrl||'image'});
                    this.pump();
                },scope:this}}});
            const view=browser.win&&browser.win.view;
            if(view){view.multiSelect=true;view.singleSelect=false;}
            browser.show();
        }
        remove(ids){this.items=this.items.filter(x=>!ids.has(x.id));this.selected.clear();this.editing=null;this.save();this.render();}
        render() {
            this.node.replaceChildren();
            const toolbar=el('div','mp-toolbar');
            const input=el('input');input.type='file';input.multiple=true;input.accept='image/jpeg,image/png,image/webp';input.hidden=true;
            input.addEventListener('change',()=>{this.addFiles([...input.files]);input.value='';});
            toolbar.append(button('Загрузить изображения',()=>input.click()),button('Выбрать на сервере',()=>this.importFile()),el('span','mp-count',this.items.filter(x=>!x._error).length+' / '+this.cfg.max),input);
            this.node.append(toolbar,el('div','mp-drop','Перетащите изображения сюда'));
            const grid=el('div','mp-grid');this.node.append(grid);
            const cover=this.items.find(x=>x.active&&!x._pending&&!x._error);
            this.items.forEach((item,index)=>{
                const card=el('div','mp-card'+(!item.active?' mp-hidden':'')+(this.selected.has(item.id)?' mp-selected':''));card.dataset.id=item.id;
                const pic=el('div','mp-pic');
                if(!item._missing&&(item.thumb||item.url)){const image=el('img');image.src=item._missingThumb?item.url:(item.thumb||item.url);image.alt=item.alt||'';image.loading='lazy';image.addEventListener('click',()=>this.preview(item));pic.append(image);}
                const check=el('input','mp-check');check.type='checkbox';check.checked=this.selected.has(item.id);check.setAttribute('aria-label','Выбрать изображение '+(index+1));check.addEventListener('change',()=>{check.checked?this.selected.add(item.id):this.selected.delete(item.id);this.render();});pic.append(check);
                const eye=button(item.active?icons.eye:icons.hidden,()=>{item.active=!item.active;this.save();this.render();},'mp-eye');eye.innerHTML='<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>'+(item.active?'':'<path d="m3 3 18 18"/>')+'</svg>';eye.title=item.active?'Скрыть':'Показать';eye.setAttribute('aria-label',eye.title);pic.append(eye);
                if(item===cover)pic.append(el('span','mp-cover','Обложка'));
                if(item._missing)pic.append(el('div','mp-error',item._missing));
                if(item._pending)pic.append(el('span','mp-state','Загрузка…'));
                if(item._error){const error=el('div','mp-error',item._error);error.append(button('Повторить',()=>{if(this.running>=2)return;if(this.items.filter(x=>!x._error).length>=this.cfg.max){this.notice='Достигнут лимит изображений';this.render();return;}item._error=null;item._pending=true;this.running++;this.render();this.run(item);}));pic.append(error);}
                const footer=el('div','mp-footer');footer.append(el('span','',String(index+1).padStart(2,'0')));
                const drag=button(icons.drag,()=>{},'mp-drag');drag.title='Перетащить; стрелки влево/вправо меняют порядок';drag.setAttribute('aria-label',drag.title);drag.draggable=true;
                drag.addEventListener('keydown',e=>{if(e.key==='ArrowLeft'||e.key==='ArrowRight'){e.preventDefault();const target=this.items[index+(e.key==='ArrowLeft'?-1:1)];if(target)this.move(item.id,target.id);}});
                drag.addEventListener('dragend',()=>{if(this.dropMarker)this.dropMarker.remove();});
                drag.addEventListener('dragstart',e=>{e.dataTransfer.setData('text/multipic',item.id);e.dataTransfer.effectAllowed='move';});
                // Native touch scroll stays intact; pointer sorting starts only on this handle.
                drag.addEventListener('pointerdown',e=>{if(e.pointerType==='mouse')return;e.preventDefault();drag.setPointerCapture(e.pointerId);this.touchId=item.id;});
                drag.addEventListener('pointerup',e=>{if(!this.touchId)return;const target=document.elementFromPoint(e.clientX,e.clientY);const dest=target&&target.closest('.mp-card');if(dest&&this.node.contains(dest))this.move(this.touchId,dest.dataset.id);this.touchId=null;});
                drag.addEventListener('pointercancel',()=>{this.touchId=null;});
                card.addEventListener('dragover',e=>{if(e.dataTransfer.types.includes('text/multipic')){e.preventDefault();if(!this.dropMarker){this.dropMarker=el('div','mp-insert');this.dropMarker.addEventListener('dragover',e=>e.preventDefault());this.dropMarker.addEventListener('drop',e=>{e.preventDefault();this.moveBefore(e.dataTransfer.getData('text/multipic'),this.dropMarker.dataset.before);});}const after=e.clientX>card.getBoundingClientRect().left+card.getBoundingClientRect().width/2;this.dropMarker.dataset.before=after?(this.items[index+1]?.id||''):item.id;grid.insertBefore(this.dropMarker,after?card.nextSibling:card);}});
                card.addEventListener('drop',e=>{const from=e.dataTransfer.getData('text/multipic');if(from){e.preventDefault();this.moveBefore(from,item.id);}});
                const edit=button(icons.edit,()=>{this.editing=this.editing===item.id?null:item.id;this.render();});edit.title='Название и ALT';edit.setAttribute('aria-label',edit.title);
                const del=button(icons.remove,()=>this.remove(new Set([item.id])));del.title='Удалить';del.setAttribute('aria-label',del.title);
                footer.append(drag,edit,del);card.append(pic,footer);grid.append(card);
            });
            const current=this.items.find(x=>x.id===this.editing);
            if(current){const panel=el('div','mp-meta');for(const [key,label]of [['title','Название'],['alt','ALT']]){const wrap=el('label','',label);const field=el('input');field.type='text';field.value=current[key]||'';field.placeholder='Необязательно';field.maxLength=1000;field.addEventListener('input',()=>{current[key]=field.value;this.save();});wrap.append(field);panel.append(wrap);}panel.append(button('Закрыть',()=>{this.editing=null;this.render();}));this.node.append(panel);}
            if(this.selected.size){const bulk=el('div','mp-bulk');bulk.append(el('span','','Выбрано: '+this.selected.size));for(const [label,active]of [['Показать',true],['Скрыть',false]])bulk.append(button(label,()=>{this.items.filter(x=>this.selected.has(x.id)).forEach(x=>x.active=active);this.save();this.render();}));bulk.append(button('Удалить',()=>this.remove(this.selected)));this.node.append(bulk);}
            if(this.sessionError)this.node.append(el('div','mp-notice',this.sessionError));
            if(this.notice)this.node.append(el('div','mp-notice',this.notice));
            this.node.append(el('div','mp-help','Перетащите миниатюры для сортировки. На сервере: Ctrl/⌘ или Shift для выбора нескольких файлов. Изменения сохраняются вместе с ресурсом.'));
        }
        moveBefore(from,to){if(this.dropMarker)this.dropMarker.remove();if(from===to)return;const a=this.items.findIndex(x=>x.id===from);let b=to?this.items.findIndex(x=>x.id===to):this.items.length;if(a<0||b<0)return;const [item]=this.items.splice(a,1);if(a<b)b--;this.items.splice(b,0,item);this.save();this.render();}
        move(from,to){if(from===to)return;const a=this.items.findIndex(x=>x.id===from),b=this.items.findIndex(x=>x.id===to);if(a<0||b<0)return;const [item]=this.items.splice(a,1);this.items.splice(b,0,item);this.save();this.render();}
        preview(item){const dialog=el('dialog','mp-preview');const image=el('img');image.src=item.url;image.alt=item.alt||'';dialog.append(button('Закрыть',()=>dialog.close()),image);dialog.addEventListener('close',()=>dialog.remove());document.body.append(dialog);dialog.showModal();}
    }
    root.MultiPic={mountAll(){installFormHooks();document.querySelectorAll('[data-multipic-config]').forEach(node=>{if(node.dataset.mounted)return;node.dataset.mounted='1';try{new Editor(node,JSON.parse(node.dataset.multipicConfig));}catch(e){node.textContent='MultiPic: '+e.message;}});}};
    document.addEventListener('DOMContentLoaded',()=>root.MultiPic.mountAll());
    new MutationObserver(()=>root.MultiPic.mountAll()).observe(document.documentElement,{childList:true,subtree:true});
    // Capture save clicks, including Ext buttons, before the form can be submitted.
    document.addEventListener('click',e=>{if(!pendingWithin())return;const target=e.target.closest('button,a,.x-btn');if(target&&(/сохран|save/i.test(target.textContent)||/save/i.test(target.id))){e.preventDefault();e.stopImmediatePropagation();waitMessage();}},true);
    root.addEventListener('beforeunload',e=>{if(pendingWithin()){e.preventDefault();e.returnValue='';}});
})(typeof window!=='undefined'?window:globalThis);
