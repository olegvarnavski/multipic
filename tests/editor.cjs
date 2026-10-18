const assert=require('node:assert/strict');
const {natural,select}=require('../assets/components/multipic/js/multipic.js');
assert.deepEqual(['010.jpg','001.jpg','002.jpg'].map(name=>({name})).sort(natural).map(x=>x.name),['001.jpg','002.jpg','010.jpg']);
const items=[{file:'a',active:true},{file:'b',active:false},{file:'c',active:true},{file:'d',active:true}];
assert.deepEqual(select(items,'odd').map(x=>x.file),['a','d']);
assert.deepEqual(select(items,'even').map(x=>x.file),['c']);
assert.deepEqual(select(items,'odd',1,1).map(x=>x.file),['d']);
console.log('PASS: queue order and output parity model');
