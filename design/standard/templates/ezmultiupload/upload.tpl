{ezcss_require( 'ezmultiupload.css' )}
{* The upload runs on Exponential UI (exp::io, exp::dialog, exp::upload, extension expui), in
   design/standard/javascript/ezmultiupload_upload.js; its configuration is the JSON of the drop zone's
   data-ezmultiupload-conf (no inline script). *}
{ezscript_require( array( 'exp::core', 'exp::io', 'exp::dialog', 'exp::upload', 'ezmultiupload_upload.js' ) )}
{ezcss_require( array( 'exp/core.css', 'exp/dialog.css', 'exp/upload.css' ) )}

<div class="border-box">
<div class="border-tl"><div class="border-tr"><div class="border-tc"></div></div></div>
<div class="border-ml"><div class="border-mr"><div class="border-mc float-break">

<div class="content-view-ezmultiupload">
    <div class="class-frontpage">

    <div class="attribute-header">
        <h1 class="long">{'Multiupload'|i18n('extension/ezmultiupload')}</h1>
    </div>
        <div class="attribute-description">
            <p>{'The files are uploaded to'|i18n('extension/ezmultiupload')} <a href={$parent_node.url_alias|ezurl}>{$parent_node.name|wash}</a></p>
            <div id="uploadButtonOverlay" data-ezmultiupload-conf="{json_encode( hash(
                'uploadURL', concat( 'ezmultiupload/upload/', $parent_node.node_id )|ezurl( 'no' ),
                'uploadVars', hash( $session_name, $session_id, 'UploadButton', 'Upload', 'ezxform_token', '@$ezxFormToken@' ),
                'allFilesRecived', 'All files received.'|i18n( 'extension/ezmultiupload' ),
                'uploadCanceled', 'Upload canceled.'|i18n( 'extension/ezmultiupload' ),
                'thumbnailCreated', 'Thumbnail created.'|i18n( 'extension/ezmultiupload' ),
                'invalidJSON', 'Invalid JSON data'|i18n( 'extension/ezmultiupload' ),
                'texts', hash(
                    'select', 'Select files'|i18n( 'extension/ezmultiupload' ),
                    'drop', 'or drop them here'|i18n( 'extension/ezmultiupload' ),
                    'cancel', 'Cancel'|i18n( 'extension/ezmultiupload' ),
                    'cancelFile', 'Cancel the upload of %name'|i18n( 'extension/ezmultiupload' ),
                    'waiting', 'Waiting'|i18n( 'extension/ezmultiupload' ),
                    'uploading', 'Uploading'|i18n( 'extension/ezmultiupload' ),
                    'done', 'Done'|i18n( 'extension/ezmultiupload' ),
                    'failed', 'Failed'|i18n( 'extension/ezmultiupload' ),
                    'canceled', 'Canceled'|i18n( 'extension/ezmultiupload' ) ) ) )|wash}"></div>
            <button id="cancelUploadButton" type="button">{'Cancel'|i18n('extension/ezmultiupload')}</button>
            <p><noscript><em style="color: red;">{'Javascript has been disabled, this is needed for multiupload!'|i18n('extension/ezmultiupload')}</em></noscript></p>
        </div>
        <div id="multiuploadProgress">
            <p><span id="multiuploadProgressFile">&nbsp;</span>&nbsp;
               <span id="multiuploadProgressFileName">&nbsp;</span></p>
            <p id="multiuploadProgressMessage">&nbsp;</p>
            <div id="multiuploadProgressBarOutline"><div id="multiuploadProgressBar"></div></div>
        </div>
        <div id="thumbnails"></div>
    </div>
</div>

</div></div></div>
<div class="border-bl"><div class="border-br"><div class="border-bc"></div></div></div>
</div>

