/**
 * ezmultiupload: the upload page of the classic designs (design/standard ezmultiupload/upload.tpl; admin4, admin4l and
 * adminui have their own page, expmultiupload.js).
 *
 * The configuration (upload address, the session and form token fields, the texts) is the JSON of #uploadButtonOverlay's
 * data-ezmultiupload-conf. Runs on Exponential UI (exp::io, exp::dialog, exp::upload, extension expui): files are
 * chosen or dropped, sent two at a time, the progress bar and counter follow, each thumbnail is added as it arrives,
 * Cancel stops the round. A file, so the page needs no inline script (Content Security Policy without 'unsafe-inline').
 */
(function () {
    if (!window.Exp || !window.Exp.ready || window.ezmultiuploadUploadReady) { return; }
    window.ezmultiuploadUploadReady = true;
    Exp.ready(function ($) {
        var $root = $('#uploadButtonOverlay'), cfg;
        if (!$.fn.expUpload || !$root.length) { return; }
        try { cfg = JSON.parse($root.attr('data-ezmultiupload-conf') || ''); } catch (err) { return; }
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
})();
