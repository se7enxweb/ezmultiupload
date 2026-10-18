{* The quick location buttons of the upload page (6.1.8): one click makes a well-known place the target, beside
   "Change location...". The first row has the places of ezmultiupload.ini [QuickLocationSettings] PresetList[]
   (Websites, Media), then a row per place of ChildRowList[] with the locations right below it (the sites; Images,
   Files, Multimedia ...). From fetch( 'ezmultiupload', 'quick_locations' ) (expMultiuploadService::quickLocations(),
   on the kernel's expNodeQuickPick): only locations the user can upload to, found from the tree and the settings.
   One file for the classic page (design/standard ezmultiupload/upload.tpl, with more=false(): it has no picker) and
   the new page of admin4 and admin4l (upload_body.tpl). The look is the shared component .a4-quickpick
   (components.css); no CSS or script here. Each button carries its location as JSON (data-exp-mu-quick-location);
   expmultiupload.js or ezmultiupload_upload.js make it the target, update the shown path and aria-pressed.
   The current target has aria-pressed="true", the place it lies in .is-within with a text for screen readers.
   Needs: parent_node. Optional: more (default true: "More..." opens the location picker at that place). *}
{def $mu_quick = fetch( 'ezmultiupload', 'quick_locations', hash( 'node_id', $parent_node.node_id ) )
     $mu_quick_more = cond( is_set( $more ), $more, true() )
     $mu_within_text = '(contains the target location)'|i18n( 'extension/ezmultiupload' )}
{if or( $mu_quick.presets|count|gt( 0 ), $mu_quick.rows|count|gt( 0 ) )}
<div class="a4-toolbar a4-quickpick" id="exp-mu-quick" data-exp-mu-within-text="{$mu_within_text|wash}">
{if $mu_quick.presets|count|gt( 0 )}
    <div class="a4-row" role="group" aria-labelledby="exp-mu-quick-presets">
        <span class="a4-quickpick-label" id="exp-mu-quick-presets">{'Quick locations'|i18n( 'extension/ezmultiupload' )}</span>
    {foreach $mu_quick.presets as $mu_button}
        <button type="button" class="a4-btn a4-btn-small{if $mu_button.within} is-within{/if}" data-exp-mu-quick-id="{$mu_button.node_id|wash}" data-exp-mu-quick-location="{$mu_button.location|wash}" aria-pressed="{if $mu_button.current}true{else}false{/if}"{if $mu_button.path|ne( '' )} title="{$mu_button.path|wash}"{/if}>{$mu_button.label|wash}<span class="a4-sr-only" data-exp-mu-within>{if $mu_button.within} {$mu_within_text|wash}{/if}</span></button>
    {/foreach}
    </div>
{/if}
{foreach $mu_quick.rows as $mu_row}
    <div class="a4-row" role="group" aria-labelledby="exp-mu-quick-row-{$mu_row.parent_id|wash}">
        <span class="a4-quickpick-label" id="exp-mu-quick-row-{$mu_row.parent_id|wash}">{$mu_row.label|wash}</span>
    {foreach $mu_row.items as $mu_button}
        <button type="button" class="a4-btn a4-btn-small{if $mu_button.within} is-within{/if}" data-exp-mu-quick-id="{$mu_button.node_id|wash}" data-exp-mu-quick-location="{$mu_button.location|wash}" aria-pressed="{if $mu_button.current}true{else}false{/if}"{if $mu_button.path|ne( '' )} title="{$mu_button.path|wash}"{/if}>{$mu_button.label|wash}<span class="a4-sr-only" data-exp-mu-within>{if $mu_button.within} {$mu_within_text|wash}{/if}</span></button>
    {/foreach}
    {if and( $mu_row.more, $mu_quick_more )}
        <button type="button" class="a4-btn a4-btn-small a4-btn-ghost" data-exp-mu-quick-more="{$mu_row.parent_id|wash}" aria-label="{'Choose another location in %name'|i18n( 'extension/ezmultiupload', '', hash( '%name', $mu_row.label ) )|wash}">{'More...'|i18n( 'extension/ezmultiupload' )}</button>
    {/if}
    </div>
{/foreach}
</div>
{/if}
{undef $mu_quick $mu_quick_more $mu_within_text}
