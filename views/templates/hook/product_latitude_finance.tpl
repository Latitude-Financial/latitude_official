<div style="display: inline-block; padding: 5px;" class="{$container_class}">
    {if $currency_code === 'NZD'}
        <a style="text-decoration: none;display: flex;" href="javascript: void(0)" id="{$gateway_name}-popup">
            <img src="{$payment_logo}" style="float: left;padding-right: 5px; max-width: 110px;"/>

            <span style="font-size: 15px;padding-right: 5px;color: rgb(46, 46, 46);">
                {$payment_info}
            </span>

            <span style="color: {$color}; font-weight: bold; font-size:13px;">learn more</span>
        </a>
        {include file="../front/genoapay_payment_modal.tpl"}
    {else if $currency_code === 'AUD'}
        {include file="../images_api/snippet.tpl"}
        {include file="../images_api/modal.tpl"}
    {/if}
</div>