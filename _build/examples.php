<?php
// Installation data only. Examples appear as editable chunks in category MultiPic.
return [
'multipicItem'=>['description'=>'Шаблон одной фотографии. Данные уже экранированы сниппетом.','content'=><<<'TPL'
<figure class="gallery-item">
    <a href="[[+url]]"><img src="[[+url]]" width="[[+width]]" height="[[+height]]" alt="[[+alt]]" loading="lazy"></a>
    <figcaption>[[+title]]</figcaption>
</figure>
TPL
],
'multipicWrapper'=>['description'=>'Обёртка. output — готовые карточки; count — число выведенных фото.','content'=>'<div class="gallery">[[+output]]</div>'],
'multipicExampleSnippet'=>['description'=>'Примеры вызова. Замените gallery на имя вашего TV, 123 на ID источника.','content'=><<<'TPL'
[[- Вся галерея текущего ресурса. Вставьте нужный вызов в шаблон или чанк. ]]
[[MultiPic? &tv=`gallery` &tpl=`multipicItem` &tplWrapper=`multipicWrapper`]]

[[- Только первая включённая фотография другого ресурса. ]]
[[MultiPic? &tv=`gallery` &resource=`123` &limit=`1` &tpl=`multipicItem`]]

[[- Два блока: позиции определяются после исключения скрытых фото. ]]
<div class="column">[[MultiPic? &tv=`gallery` &parity=`odd` &tpl=`multipicItem`]]</div>
<div class="column">[[MultiPic? &tv=`gallery` &parity=`even` &tpl=`multipicItem`]]</div>

[[- В чанке строки MIGX: photos — поле с inputTV MultiPic. ]]
[[MultiPic? &value=`[[+photos]]` &tpl=`multipicItem` &tplWrapper=`multipicWrapper`]]
TPL
],
'multipicExampleFenom'=>['description'=>'Требуется настроенный Fenom/pdoTools. Примеры альтернативны: используйте один.','content'=><<<'TPL'
{* Массив от сниппета. Скрытые уже исключены. *}
{set $photos = $_modx->runSnippet('MultiPic', ['tv'=>'gallery', 'output'=>'array'])}
{foreach $photos as $photo}
    <img src="{$photo.url | escape}" alt="{$photo.alt | escape}" width="{$photo.width}" height="{$photo.height}" loading="lazy">
{/foreach}

{* Прямой foreach: gallery — имя TV. Учитываем структуру items и active. *}
{set $gallery = $_modx->resource.gallery | fromJSON}
{if $gallery.type == 'multipic'}
    {foreach $gallery.items as $photo}
        {if $photo.active}
            <img src="{$photo.url | escape}" alt="{$photo.alt | escape}" loading="lazy">
        {/if}
    {/foreach}
{/if}

{* Внутри foreach строк MIGX можно передать значение поля photos: *}
{* $_modx->runSnippet('MultiPic', ['value'=>$row.photos, 'tpl'=>'multipicItem']) *}
TPL
],
'multipicExampleMigx'=>['description'=>'Fenom: галерея внутри строки MIGX, phpThumbOn по желанию. URL уже включает источник.','content'=><<<'TPL'
{* $child — строка MIGX, gallery — поле с inputTV=multipic.
   $userfile к $photo.url добавлять не нужно: источник файлов уже учтён.
   Пример phpThumbOn требует установленного phpThumbOn; MultiPic работает и без него. *}
{set $photos = $_modx->runSnippet('MultiPic', ['value'=>$child.gallery, 'output'=>'array'])}
{foreach $photos as $photo}
    {set $img_child = 'phpthumbon' | snippet : ['input'=>$photo.url, 'options'=>'w=1600&q=90']}
    <img src="{$img_child | escape}" alt="{$photo.alt | escape}" loading="lazy">
{/foreach}
TPL
]
];
