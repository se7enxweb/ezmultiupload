{ezcss_require( 'ezmultiupload.css' )}
{* The upload runs on Exponential UI (exp::io, exp::dialog, exp::upload, extension expui). *}
{ezscript_require( array( 'exp::core', 'exp::io', 'exp::dialog', 'exp::upload' ) )}
{ezcss_require( array( 'exp/core.css', 'exp/dialog.css', 'exp/upload.css' ) )}
<script type="text/javascript">
(function () {ldelim}
    if (!window.Exp || !window.Exp.ready) {ldelim} return; {rdelim}
    var cfg = {ldelim}
        uploadURL: "{concat( 'ezmultiupload/upload/', $parent_node.node_id )|ezurl( 'no' )}",
        uploadVars: {ldelim}
            '{$session_name}': '{$session_id}',
            'UploadButton': 'Upload',
            'ezxform_token': '@$ezxFormToken@'
        {rdelim},
        allFilesRecived:  "{'All files received.'|i18n('extension/ezmultiupload')|wash(javascript)}",
        uploadCanceled:   "{'Upload canceled.'|i18n('extension/ezmultiupload')|wash(javascript)}",
        thumbnailCreated: "{'Thumbnail created.'|i18n('extension/ezmultiupload')|wash(javascript)}",
        invalidJSON:      "{'Invalid JSON data'|i18n('extension/ezmultiupload')|wash(javascript)}",
        texts: {ldelim}
            select:     "{'Select files'|i18n('extension/ezmultiupload')|wash(javascript)}",
            drop:       "{'or drop them here'|i18n('extension/ezmultiupload')|wash(javascript)}",
            cancel:     "{'Cancel'|i18n('extension/ezmultiupload')|wash(javascript)}",
            cancelFile: "{'Cancel the upload of %name'|i18n('extension/ezmultiupload')|wash(javascript)}",
            waiting:    "{'Waiting'|i18n('extension/ezmultiupload')|wash(javascript)}",
            uploading:  "{'Uploading'|i18n('extension/ezmultiupload')|wash(javascript)}",
            done:       "{'Done'|i18n('extension/ezmultiupload')|wash(javascript)}",
            failed:     "{'Failed'|i18n('extension/ezmultiupload')|wash(javascript)}",
            canceled:   "{'Canceled'|i18n('extension/ezmultiupload')|wash(javascript)}"
        {rdelim}
    {rdelim};
{literal}
    Exp.ready(function ($) {
        if (!$.fn.expUpload) { return; }
        var $progress = $('#multiuploadProgress'), $bar = $('#multiuploadProgressBar'),
            $counter = $('#multiuploadProgressFile'), $name = $('#multiuploadProgressFileName'),
            $message = $('#multiuploadProgressMessage'), $cancel = $('#cancelUploadButton'), $thumbnails = $('#thumbnails');
        var round = [], canceled = false, speed = Exp.reducedMotion ? 0 : 1;

        // the server sends the thumbnail's HTML as HTML entities: decoded with an inert parser
        function unescapeHTML(s) {
            var text = new window.DOMParser().parseFromString(s.replace(/<\/?[^>]+>/gi, ''), 'text/html').body.textContent;
            return text || s;
        }
        function width(percent) { $bar.stop(true).animate({ width: percent + '%' }, 500 * speed); }

        var $root = $('#uploadButtonOverlay');
        $root.expUpload({
            url: cfg.uploadURL,
            name: 'Filedata',
            multiple: true,
            drop: true,
            parallel: 2,                     // two files at a time
            data: cfg.uploadVars,
            token: false,                    // in uploadVars already
            responseType: 'text',
            texts: cfg.texts
        });
        var up = $root.data('expUpload');

        // files chosen (or dropped): a new round
        $root.on('exp:upload:add', function (e, d) {
            if (!round.length || round.every(function (f) { return f.status !== 'queued' && f.status !== 'uploading'; })) {
                round = [];
                canceled = false;
                $progress.css('display', 'block');
                if (!parseFloat($progress.css('opacity'))) { $progress.css('opacity', 0).animate({ opacity: 1 }, 200 * speed); }
                $bar.stop(true).css('width', 0);
                $message.html('');
                $name.text('');
                $cancel.css('visibility', 'visible');
            }
            round.push(d.file);
            $counter.text('0/' + round.length);
        });
        $root.on('exp:upload:start', function () { up.disable(); });
        $root.on('exp:upload:progress', function (e, d) {
            $counter.text((round.indexOf(d.file) + 1) + '/' + round.length);
            $name.text(d.file.name);
            width(up.progress().percent);
        });
        $root.on('exp:upload:done', function (e, d) {
            var response;
            $message.text(cfg.thumbnailCreated);
            width(up.progress().percent);
            try { response = JSON.parse(d.response); } catch (err) {
                if (Exp.dialog) { Exp.dialog.alert(cfg.invalidJSON); }
                return;
            }
            var thumbnail = $('<div class="thumbnail-block"></div>').attr('id', 'thumbnail_' + response.id).css('opacity', 0);
            thumbnail[0].innerHTML = unescapeHTML(String(response.data));   // no script is run
            $thumbnails.append(thumbnail);
            thumbnail.animate({ opacity: 1 }, 200 * speed);
        });
        $root.on('exp:upload:fail', function (e, d) {
            if (Exp.dialog) { Exp.dialog.alert(d.error && d.error.message ? d.error.message : String(d.error)); }
        });
        // every file of the round is finished
        $root.on('exp:upload:complete', function (e, summary) {
            up.enable();
            if (canceled) { return; }
            // every file of the round canceled with its own Cancel button in the list: said as with the Cancel button
            if (!summary.done && !summary.failed && summary.canceled) { $message.text(cfg.uploadCanceled); $cancel.css('visibility', 'hidden'); return; }
            $bar.stop(true).css('width', '100%');
            $message.text(cfg.allFilesRecived);
            $counter.text(round.length + '/' + round.length);
            $name.text('');
            $cancel.css('visibility', 'hidden');
        });
        $cancel.on('click', function (e) {
            e.preventDefault();
            canceled = true;
            up.cancel();
            up.enable();
            $message.text(cfg.uploadCanceled);
        });
    });
{/literal}
{rdelim})();
</script>

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
            <div id="uploadButtonOverlay"></div>
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

