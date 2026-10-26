const assert=require('node:assert/strict');
const {File}=require('node:buffer');
const {fitImage,prepareImage}=require('../assets/components/multipic/js/multipic.js');
assert.deepEqual(fitImage(6000,4000,2560),{width:2560,height:1707});
assert.deepEqual(fitImage(4000,6000,2560),{width:1707,height:2560});
assert.deepEqual(fitImage(100,50,2560),{width:100,height:50});
let released=0,quality,drawn;
const canvas={getContext:()=>({drawImage:(image,x,y,w,h)=>{drawn=[w,h];}}),toBlob:(cb,type,q)=>{quality=q;cb(new Blob(['reduced'],{type}));}};
class TestImage {constructor(){this.naturalWidth=6000;this.naturalHeight=4000;}set src(value){Promise.resolve().then(()=>this.onload());}}
const env={File,Image:TestImage,URL:{createObjectURL:()=> 'blob:test',revokeObjectURL:()=>released++},document:{createElement:()=>canvas}};
(async()=>{
 const file=new File(['source'],'photo.jpg',{type:'image/jpeg'});
 const result=await prepareImage(file,2560,95,env);
 assert.notEqual(result,file);assert.equal(result.name,file.name);assert.equal(result.type,'image/jpeg');
 assert.deepEqual(drawn,[2560,1707]);assert.equal(quality,0.95);assert.equal(released,1);assert.equal(canvas.width,1);
 assert.equal(await prepareImage(file,0,95,env),file);
 console.log('PASS: browser resize dimensions, format/quality, no upscaling and canvas/URL disposal');
})().catch(e=>{console.error(e);process.exitCode=1;});
