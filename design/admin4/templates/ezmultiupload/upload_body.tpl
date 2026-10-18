{* The upload page (admin4, admin4l, adminui), in the frame of the content action pages (content/multiedit, the
   move and remove confirmations): a context block with its title in the box header, the content in the box, and
   the buttons in the control bar under it, the main action first. Two blocks: "Upload files" (target location,
   files, queue) and "Uploaded" (what was made, with Move, Add location and Remove).
   Controls are the shared ones: input.button and input.defaultbutton in the control bars (drawn like .a4-btn by
   admin4.css), the edit form's field markup (.a4-content-attr with .a4-content-attr-label) for title and
   alternative text, .a4-check, .a4-radios, .a4-card, .a4-table, .a4-badge, .a4-meter, .a4-notice, .a4-empty.
   The rows are drawn by expmultiupload.js from the configuration in #exp-mu-config (JSON from the view, escaped
   for HTML: expMultiuploadService::json()). Only the drop zone has CSS of its own (expmultiupload.css,
   BackendCSSFileList). No CSS in this template.
   The quick location buttons (ezmultiupload/quick_locations.tpl) sit under the target; the hidden field
   exp-mu-location-id holds the target node, set by them, the picker and "Use the last location" alike.
   Needs: $parent_node, $upload_config_json, $max_size_text, $file_types. *}
{ezscript_require( array( 'exp::core', 'exp::dialog', 'expmultiupload.js' ) )}
{ezcss_require( array( 'exp/core.css', 'exp/dialog.css' ) )}
<div class="exp-mu" id="exp-mu" data-exp-mu>
<script type="application/json" id="exp-mu-config">{$upload_config_json}</script>

<div class="context-block" id="exp-mu-upload">
{* DESIGN: Header START *}<div class="box-header"><div class="box-ml">
<h2 class="context-title">{'Upload files'|i18n( 'extension/ezmultiupload' )}</h2>
{* DESIGN: Mainline *}<div class="header-mainline"></div>
{* DESIGN: Header END *}</div></div>

{* DESIGN: Content START *}<div class="box-ml"><div class="box-mr"><div class="box-content">
    <p class="a4-intro">{'Several files at once: each becomes an item of the content class its type is mapped to.'|i18n( 'extension/ezmultiupload' )}</p>
    <noscript><p class="a4-notice is-warn">{'JavaScript is needed to upload several files at once.'|i18n( 'extension/ezmultiupload' )}</p></noscript>

    <section class="a4-card" aria-labelledby="exp-mu-location-title">
        <div class="a4-card-head">
            <h3 class="a4-h3" id="exp-mu-location-title">{'Target location'|i18n( 'extension/ezmultiupload' )}</h3>
            <p>{'The files are uploaded to this location. A row of the queue can have a location of its own.'|i18n( 'extension/ezmultiupload' )}</p>
        </div>
        <div class="a4-card-body">
            <p>
                <span class="a4-icon a4-bi-folder" aria-hidden="true"></span>
                <a id="exp-mu-location-link" href={concat( 'content/view/full/', $parent_node.node_id )|ezurl}>{$parent_node.name|wash}</a>
                <span class="a4-badge is-ok" id="exp-mu-location-state">{'Uploads allowed'|i18n( 'extension/ezmultiupload' )}</span>
            </p>
            <p class="a4-notice is-bad" id="exp-mu-location-reason" role="alert" hidden></p>
            <input type="hidden" id="exp-mu-location-id" name="ParentNodeID" value="{$parent_node.node_id|wash}" />
            {include uri='design:ezmultiupload/quick_locations.tpl' parent_node=$parent_node}
        </div>
        <div class="a4-card-foot">
            <button type="button" class="button" id="exp-mu-change-location">{'Change location...'|i18n( 'extension/ezmultiupload' )}</button>
            <button type="button" class="button" id="exp-mu-last-location" hidden></button>
        </div>
    </section>

    <section class="a4-card" aria-labelledby="exp-mu-add-title">
        <div class="a4-card-head">
            <h3 class="a4-h3" id="exp-mu-add-title">{'Add files'|i18n( 'extension/ezmultiupload' )}</h3>
        </div>
        <div class="a4-card-body">
            <div class="exp-mu-drop" id="exp-mu-drop" role="group" aria-labelledby="exp-mu-add-title" aria-describedby="exp-mu-drop-hint exp-mu-limits">
                <p id="exp-mu-drop-hint">{'Drop files or folders here, or paste images.'|i18n( 'extension/ezmultiupload' )}</p>
                <p>
                    <button type="button" class="button" id="exp-mu-pick-files">{'Choose files...'|i18n( 'extension/ezmultiupload' )}</button>
                    <button type="button" class="button" id="exp-mu-pick-folder" hidden>{'Choose a folder...'|i18n( 'extension/ezmultiupload' )}</button>
                </p>
                <input type="file" id="exp-mu-files" multiple hidden tabindex="-1" aria-hidden="true" />
                <input type="file" id="exp-mu-folder" multiple hidden tabindex="-1" aria-hidden="true" />
                <p class="a4-help" id="exp-mu-limits">
                    {if $max_size_text}{'At most %size per file.'|i18n( 'extension/ezmultiupload', '', hash( '%size', $max_size_text ) )|wash}{/if}
                    {if and( $file_types, ne( $file_types, '*.*' ) )}{'Types: %types'|i18n( 'extension/ezmultiupload', '', hash( '%types', $file_types|explode( ';' )|implode( ', ' ) ) )|wash}{/if}
                </p>
            </div>
            <div class="a4-content-attr">
                <label class="a4-check">
                    <input type="checkbox" id="exp-mu-draft" />
                    <span><strong>{'Keep as drafts'|i18n( 'extension/ezmultiupload' )}</strong>
                        <span class="a4-help">{'The new items are not published; they wait in My drafts until they are edited and published.'|i18n( 'extension/ezmultiupload' )}</span></span>
                </label>
                <label class="a4-check">
                    <input type="checkbox" id="exp-mu-zip-import" />
                    <span><strong>{'Import compressed zip file(s) contents'|i18n( 'extension/ezmultiupload' )}</strong>
                        <span class="a4-help">{'Off: a ZIP file is uploaded as one file. On: the files in it are uploaded, also from ZIP files inside it.'|i18n( 'extension/ezmultiupload' )}</span></span>
                </label>
                <fieldset class="a4-content-attr-group" id="exp-mu-zip-mode">
                    <legend class="a4-content-attr-label">{'Folders in ZIP files'|i18n( 'extension/ezmultiupload' )}</legend>
                    <div class="a4-radios">
                        <label class="a4-check"><input type="radio" name="ExpMuZipMode" value="keep" checked="checked" /> <span>{'Keep the folder structure: a folder item for each folder of the ZIP file'|i18n( 'extension/ezmultiupload' )}</span></label>
                        <label class="a4-check"><input type="radio" name="ExpMuZipMode" value="flat" /> <span>{'Flatten: every file into the location'|i18n( 'extension/ezmultiupload' )}</span></label>
                    </div>
                </fieldset>
            </div>
        </div>
    </section>

    <section class="a4-card is-flush" aria-labelledby="exp-mu-queue-title">
        <div class="a4-card-head">
            <h3 class="a4-h3" id="exp-mu-queue-title">{'Queue'|i18n( 'extension/ezmultiupload' )} <span class="a4-count" id="exp-mu-queue-count">0</span></h3>
        </div>
        <div class="a4-card-body" id="exp-mu-overall" hidden>
            <span class="a4-meter a4-pct-0" id="exp-mu-overall-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-labelledby="exp-mu-queue-title"><span></span></span>
        </div>
        <div class="a4-table-wrap">
            <table class="a4-table" id="exp-mu-queue">
                <caption class="a4-sr-only">{'Files waiting to be uploaded'|i18n( 'extension/ezmultiupload' )}</caption>
                <thead>
                    <tr>
                        <th scope="col"><span class="a4-sr-only">{'Preview'|i18n( 'extension/ezmultiupload' )}</span></th>
                        <th scope="col">{'File'|i18n( 'extension/ezmultiupload' )}</th>
                        <th scope="col">{'Title and alternative text'|i18n( 'extension/ezmultiupload' )}</th>
                        <th scope="col">{'Status'|i18n( 'extension/ezmultiupload' )}</th>
                        <th scope="col"><span class="a4-sr-only">{'Actions'|i18n( 'extension/ezmultiupload' )}</span></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <p class="a4-empty" id="exp-mu-queue-empty">{'No files in the queue.'|i18n( 'extension/ezmultiupload' )}</p>
    </section>
{* DESIGN: Content END *}</div></div></div>

<div class="controlbar">
{* DESIGN: Control bar START *}<div class="box-bc"><div class="box-ml">
<div class="block">
    <input type="button" class="button-disabled" id="exp-mu-start" data-exp-mu-primary="1" value="{'Upload all'|i18n( 'extension/ezmultiupload' )}" disabled="disabled" />
    <input type="button" class="button" id="exp-mu-cancel-all" value="{'Cancel all'|i18n( 'extension/ezmultiupload' )}" disabled="disabled" />
    <input type="button" class="button" id="exp-mu-retry-all" value="{'Retry failed'|i18n( 'extension/ezmultiupload' )}" disabled="disabled" />
    <input type="button" class="button" id="exp-mu-clear" value="{'Clear finished'|i18n( 'extension/ezmultiupload' )}" disabled="disabled" />
</div>
{* DESIGN: Control bar END *}</div></div>
</div>
</div>

<div class="context-block" id="exp-mu-done">
{* DESIGN: Header START *}<div class="box-header"><div class="box-ml">
<h2 class="context-title" id="exp-mu-results-title" tabindex="-1">{'Uploaded'|i18n( 'extension/ezmultiupload' )} <span class="a4-count" id="exp-mu-results-count">0</span></h2>
{* DESIGN: Mainline *}<div class="header-mainline"></div>
{* DESIGN: Header END *}</div></div>

{* DESIGN: Content START *}<div class="box-ml"><div class="box-mr"><div class="box-content">
    <div class="a4-table-wrap">
        <table class="a4-table" id="exp-mu-results">
            <caption class="a4-sr-only">{'Uploaded items'|i18n( 'extension/ezmultiupload' )}</caption>
            <thead>
                <tr>
                    <th scope="col"><label class="a4-check"><input type="checkbox" id="exp-mu-select-all" disabled="disabled" /><span class="a4-sr-only">{'Select all uploaded items'|i18n( 'extension/ezmultiupload' )}</span></label></th>
                    <th scope="col"><span class="a4-sr-only">{'Preview'|i18n( 'extension/ezmultiupload' )}</span></th>
                    <th scope="col">{'Name'|i18n( 'extension/ezmultiupload' )}</th>
                    <th scope="col">{'Type'|i18n( 'extension/ezmultiupload' )}</th>
                    <th scope="col">{'Locations'|i18n( 'extension/ezmultiupload' )}</th>
                    <th scope="col"><span class="a4-sr-only">{'Actions'|i18n( 'extension/ezmultiupload' )}</span></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
    <p class="a4-empty" id="exp-mu-results-empty">{'Nothing uploaded yet.'|i18n( 'extension/ezmultiupload' )}</p>
{* DESIGN: Content END *}</div></div></div>

<div class="controlbar">
{* DESIGN: Control bar START *}<div class="box-bc"><div class="box-ml">
<div class="block">
    <input type="button" class="button" id="exp-mu-move" value="{'Move to...'|i18n( 'extension/ezmultiupload' )}" disabled="disabled" />
    <input type="button" class="button" id="exp-mu-place" value="{'Add location...'|i18n( 'extension/ezmultiupload' )}" disabled="disabled" />
    <input type="button" class="button" id="exp-mu-remove" value="{'Remove...'|i18n( 'extension/ezmultiupload' )}" disabled="disabled" />
    <span class="a4-meta" id="exp-mu-selected" aria-live="polite"></span>
</div>
{* DESIGN: Control bar END *}</div></div>
</div>
</div>

<p class="a4-sr-only" id="exp-mu-status" role="status" aria-live="polite"></p>
</div>
