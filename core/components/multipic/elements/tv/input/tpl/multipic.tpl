{if $multipic_error}
<input type="hidden" id="tv{$tv->id}" name="tv{$tv->id}" value="{$tv->value|escape}" />
<div class="multipic-error">{$multipic_error|escape}</div>
{else}
<input type="hidden" id="tv{$tv->id}" name="tv{$tv->id}" value="{$multipic_value|escape}" />
<div id="{$multipic_id}" class="multipic" data-multipic-config="{$multipic_config|escape}"></div>
<script>if (window.MultiPic) window.MultiPic.mountAll();</script>
{/if}
