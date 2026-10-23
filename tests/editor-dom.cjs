/* npm install; node tests/editor-dom.cjs. DOM simulation, not a real MODX/browser test. */
const assert=require('node:assert/strict');
const fs=require('node:fs');const vm=require('node:vm');const {File}=require('node:buffer');
const {parseHTML}=require(process.env.MULTIPIC_DOM_MODULE||'linkedom');
const {window}=parseHTML('<html><body><form id="modx-resource-form"><input id="tv7" name="tv7" type="hidden"><div id="editor"></div></form><div class="x-window" id="migx-window"><form><input id="tvnested" name="tvnested" type="hidden"><div id="nested"></div></form></div></body></html>');
const {document}=window;let dirty=0,alerts=0,submitted=0;const handlers={};
const panel={on:(event,callback)=>handlers[event]=callback};const win={doSubmit:()=>submitted++};
window.Ext={getCmp:id=>id==='modx-panel-resource'?panel:id==='migx-window'?win:null,Msg:{alert:()=>alerts++}};
let browserConfig;const selectedFiles=[];window.MODx={fireResourceFormChange:()=>dirty++,load:cfg=>{browserConfig=cfg;return {show(){},win:{view:{getSelectedRecords:()=>selectedFiles.map(data=>({data}))}}};}};
const requests=[];
window.fetch=(url,options)=>new Promise(resolve=>requests.push({form:options.body,resolve}));
window.FormData=globalThis.FormData;window.setInterval=()=>1;window.clearInterval=()=>{};
window.alert=()=>alerts++;window.console=console;
function configure(id,field){const node=document.getElementById(id);node.dataset.multipicConfig=JSON.stringify({field,token:'a'.repeat(64),data:{type:'multipic',version:1,items:[]},max:4,source:1,connector:'/connector',auth:'test'});}
configure('editor','tv7');configure('nested','tvnested');
vm.runInNewContext(fs.readFileSync(__dirname+'/../assets/components/multipic/js/multipic.js','utf8'),window);
window.MultiPic.mountAll();
function upload(id,names){const input=document.querySelector('#'+id+' input[type=file]');Object.defineProperty(input,'files',{configurable:true,value:names.map(n=>new File(['test'],n,{type:'image/png'}))});input.dispatchEvent(new window.Event('change'));}
function result(file){return {id:file,file,url:'/images/'+file+'.png',thumb:'/thumb/'+file+'.png',width:20,height:10,title:'',alt:'',active:true};}
function finish(req,item){req.resolve({ok:true,json:async()=>({success:true,object:item})});}
const tick=()=>new Promise(r=>setImmediate(r));
(async()=>{
 upload('editor',['010.png','002.png']);assert.equal(requests.length,2);
 assert.equal(requests[0].form.get('file').name,'002.png');
 assert.equal(handlers.beforeSubmit({form:{baseParams:{}}}),false);
 finish(requests[1],result('ten'));await tick();finish(requests[0],result('two'));await tick();
 let stored=JSON.parse(document.getElementById('tv7').value);assert.deepEqual(stored.items.map(x=>x.file),['two','ten']);
 document.querySelector('#editor .mp-eye').click();stored=JSON.parse(document.getElementById('tv7').value);assert.equal(stored.items[0].active,false);assert.ok(document.querySelector('#editor .mp-hidden'));
 document.querySelector('#editor button[title="Название и ALT"]').click();const title=document.querySelector('#editor .mp-meta input');title.value='Подпись';title.dispatchEvent(new window.Event('input'));assert.equal(JSON.parse(document.getElementById('tv7').value).items[0].title,'Подпись');
 const form={baseParams:{}};handlers.beforeSubmit({form});assert.ok(JSON.parse(form.baseParams.multipic_tokens).length);
 upload('nested',['001.png']);win.doSubmit();assert.equal(submitted,0);assert.ok(alerts>=2);
 finish(requests[2],result('nested'));await tick();win.doSubmit();assert.equal(submitted,1);
 assert.equal(JSON.parse(document.getElementById('tvnested').value).items.length,1);
 assert.equal(JSON.parse(document.getElementById('tv7').value).items.length,2);assert.ok(dirty>=4);
 selectedFiles.push({name:'server-a.png',relativeUrl:'server-a.png'},{name:'server-b.png',relativeUrl:'server-b.png'});
 document.querySelectorAll('#editor .mp-toolbar button')[1].click();
 browserConfig.listeners.select.fn(selectedFiles[0]);assert.equal(requests.length,5);
 finish(requests[3],result('server-a'));finish(requests[4],result('server-b'));await tick();
 assert.equal(JSON.parse(document.getElementById('tv7').value).items.length,4);
 const card=document.querySelector('#editor .mp-card');card.getBoundingClientRect=()=>({left:0,width:120});
 const transfer={types:['text/multipic'],getData:()=> 'server-b',files:[]};
 const over=new window.Event('dragover',{bubbles:true,cancelable:true});over.dataTransfer=transfer;over.clientX=10;card.dispatchEvent(over);
 const marker=document.querySelector('#editor .mp-insert');assert.ok(marker,'insertion slot must be visible');
 const drop=new window.Event('drop',{bubbles:true,cancelable:true});drop.dataTransfer=transfer;marker.dispatchEvent(drop);
 assert.equal(JSON.parse(document.getElementById('tv7').value).items[0].file,'server-b');
 console.log('PASS: multiple server selections and drag insertion placeholder');
 console.log('PASS: DOM editor, queue completion order, hidden/ALT data, independent fields and resource/MIGX pending guards');
})().catch(e=>{console.error(e);process.exitCode=1;});
