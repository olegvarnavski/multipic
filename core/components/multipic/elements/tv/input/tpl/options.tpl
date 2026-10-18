<div id="tv-input-properties-form{$tv|default}"></div>
<script>
{literal}
var multipicChanged=function(){var p=Ext.getCmp('modx-panel-tv');if(p)p.markDirty();};
MODx.load({xtype:'panel',layout:'form',renderTo:'tv-input-properties-form{/literal}{$tv|default}{literal}',labelWidth:220,
 items:[
 {xtype:'numberfield',fieldLabel:'Максимум изображений',name:'inopt_maxFiles',id:'inopt_maxFiles',allowDecimals:false,minValue:1,maxValue:1000,listeners:{change:multipicChanged},value:{/literal}{$params.maxFiles|default:30}{literal}},
 {xtype:'textfield',fieldLabel:'Папка в медиаисточнике',name:'inopt_folder',id:'inopt_folder',width:350,listeners:{change:multipicChanged},value:{/literal}'{$params.folder|default:"multipic/"|escape:"javascript"}'{literal}},
 {xtype:'displayfield',value:'Источник выберите на стандартной вкладке «Источники файлов» этого TV.'}
 ]});
{/literal}
</script>
