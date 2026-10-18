<div id="tv-input-properties-form{$tv|default}"></div>
<script>
{literal}
var multipicChanged=function(){var p=Ext.getCmp('modx-panel-tv');if(p)p.markDirty();};
MODx.load({xtype:'panel',layout:'form',renderTo:'tv-input-properties-form{/literal}{$tv|default}{literal}',labelWidth:220,
 items:[
 {xtype:'numberfield',fieldLabel:'Максимум изображений',name:'inopt_maxFiles',id:'inopt_maxFiles',allowDecimals:false,minValue:1,maxValue:1000,listeners:{change:multipicChanged},value:{/literal}{$params.maxFiles|default:30}{literal}},
 {xtype:'modx-combo-source',fieldLabel:'Источник файлов',name:'inopt_mediaSource',hiddenName:'inopt_mediaSource',id:'inopt_mediaSource',width:350,editable:false,baseParams:{action:'source/getList',showNone:true},listeners:{select:multipicChanged,change:multipicChanged},value:{/literal}{$params.mediaSource|default:0}{literal}},
 {xtype:'displayfield',value:'Выберите существующий источник. «Нет» — использовать источник со вкладки «Источники файлов» для контекста ресурса.'},
 {xtype:'textfield',fieldLabel:'Папка в медиаисточнике',name:'inopt_folder',id:'inopt_folder',width:350,listeners:{change:multipicChanged},value:{/literal}'{$params.folder|default:"multipic/"|escape:"javascript"}'{literal}},
 {xtype:'displayfield',value:'Папка указывается относительно выбранного источника, например multipic/construction/. Настройки этого TV используются и внутри MIGX.'}
 ]});
{/literal}
</script>
