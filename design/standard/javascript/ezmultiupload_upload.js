/**
 * ezmultiupload: the upload page of the classic designs (design/standard ezmultiupload/upload.tpl; admin4, admin4l and
 * adminui have their own page, expmultiupload.js).
 *
 * The configuration (upload address, the session and form token fields, the texts) is the JSON of #uploadButtonOverlay's
 * data-ezmultiupload-conf. Runs on Exponential UI (exp::io, exp::dialog, exp::upload, extension expui) in plain
 * JavaScript, with or without jQuery on the page: files are chosen or dropped, sent two at a time, the progress bar
 * and counter follow, each thumbnail is added as it arrives, Cancel stops the round. A file, so the page needs no
 * inline script (Content Security Policy without 'unsafe-inline').
 * The quick location buttons (#exp-mu-quick, ezmultiupload/quick_locations.tpl) change the target: the address files
 * are sent to, the shown location and the pressed button; they wait while files are being sent.
 */
(function () {
    if (!window.Exp || !window.Exp.ready || window.ezmultiuploadUploadReady) { return; }
    window.ezmultiuploadUploadReady = true;
    Exp.ready(function () {
        var root = document.getElementById('uploadButtonOverlay'), cfg;
        if (!Exp.upload || !Exp.upload.create || !root) { return; }
        try { cfg = JSON.parse(root.getAttribute('data-ezmultiupload-conf') || ''); } catch (err) { return; }
        function byId(id) { return document.getElementById(id); }
        // as jQuery's .text() and .css() on what may be missing: undefined changes nothing
        function text(el, value) { if (el && value !== undefined) { el.textContent = value; } }
        function css(el, prop, value) { if (el) { Exp.util.css(el, prop, value); } }
        var progressBox = byId('multiuploadProgress'), bar = byId('multiuploadProgressBar'),
            counter = byId('multiuploadProgressFile'), name = byId('multiuploadProgressFileName'),
            message = byId('multiuploadProgressMessage'), cancelButton = byId('cancelUploadButton'), thumbnails = byId('thumbnails');
        var round = [], canceled = false, speed = Exp.reducedMotion ? 0 : 1;

        // the server sends the thumbnail's HTML as HTML entities: decoded with an inert parser
        function unescapeHTML(s) {
            var decoded = new window.DOMParser().parseFromString(s.replace(/<\/?[^>]+>/gi, ''), 'text/html').body.textContent;
            return decoded || s;
        }
        function stopBar() { if (bar) { Exp.util.stop(bar); } }
        function width(percent) { if (bar) { stopBar(); Exp.util.animate(bar, { width: percent + '%' }, 500 * speed); } }

        var up = Exp.upload.create(root, {
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

        // the quick location buttons: each carries its location (JSON); a click sends the next files there
        var quick = byId('exp-mu-quick'), uploadBase = String(cfg.uploadURL).replace(/\/[0-9]+$/, '');
        function quickAll(selector) { return quick ? Array.prototype.slice.call(quick.querySelectorAll(selector)) : []; }
        function markQuick(loc) {
            var ids = loc.pathIds || [], withinText = (quick && quick.getAttribute('data-exp-mu-within-text')) || '';
            quickAll('[data-exp-mu-quick-location]').forEach(function (b) {
                var id = Number(b.getAttribute('data-exp-mu-quick-id')), current = id === loc.id,
                    within = !current && ids.indexOf(id) !== -1;
                b.setAttribute('aria-pressed', current ? 'true' : 'false');
                b.classList.toggle('is-within', within);
                Array.prototype.forEach.call(b.querySelectorAll('[data-exp-mu-within]'), function (s) { s.textContent = within ? ' ' + withinText : ''; });
            });
        }
        function disableQuick(on) { quickAll('button').forEach(function (b) { b.disabled = on; }); }
        if (quick) {
            quick.addEventListener('click', function (e) {
                var b = e.target && e.target.closest ? e.target.closest('[data-exp-mu-quick-location]') : null, loc;
                if (!b || !quick.contains(b)) { return; }
                try { loc = JSON.parse(b.getAttribute('data-exp-mu-quick-location') || ''); } catch (err) { return; }
                if (!loc || !loc.id || !up.enabled) { return; }
                up.options.url = uploadBase + '/' + encodeURIComponent(String(loc.id));
                var link = byId('ezmultiupload-location');
                if (link) { link.textContent = loc.pathText || loc.name; link.setAttribute('href', loc.url || '#'); }
                markQuick(loc);
                text(byId('ezmultiupload-status'), String(cfg.locationChanged || '').split('%location').join(loc.pathText || loc.name));
            });
        }

        // files chosen (or dropped): a new round
        root.addEventListener('exp:upload:add', function (e) {
            var d = e.detail;
            if (!round.length || round.every(function (f) { return f.status !== 'queued' && f.status !== 'uploading'; })) {
                round = [];
                canceled = false;
                css(progressBox, 'display', 'block');
                // its computed opacity, read as jQuery's .css('opacity') reads it (the box is in the page)
                var computed = progressBox ? window.getComputedStyle(progressBox) : null,
                    opacity = computed ? computed.getPropertyValue('opacity') || computed.opacity : '1';
                if (progressBox && !parseFloat(opacity)) {
                    Exp.util.css(progressBox, 'opacity', 0);
                    Exp.util.animate(progressBox, { opacity: 1 }, 200 * speed);
                }
                stopBar();
                css(bar, 'width', 0);
                if (message) { message.innerHTML = ''; }
                text(name, '');
                css(cancelButton, 'visibility', 'visible');
            }
            round.push(d.file);
            text(counter, '0/' + round.length);
        });
        root.addEventListener('exp:upload:start', function () { up.disable(); disableQuick(true); });
        root.addEventListener('exp:upload:progress', function (e) {
            var d = e.detail;
            text(counter, (round.indexOf(d.file) + 1) + '/' + round.length);
            text(name, d.file.name);
            width(up.progress().percent);
        });
        root.addEventListener('exp:upload:done', function (e) {
            var d = e.detail, response;
            text(message, cfg.thumbnailCreated);
            width(up.progress().percent);
            try { response = JSON.parse(d.response); } catch (err) {
                if (Exp.dialog) { Exp.dialog.alert(cfg.invalidJSON); }
                return;
            }
            var thumbnail = document.createElement('div');
            thumbnail.className = 'thumbnail-block';
            thumbnail.setAttribute('id', 'thumbnail_' + response.id);
            Exp.util.css(thumbnail, 'opacity', 0);
            thumbnail.innerHTML = unescapeHTML(String(response.data));   // no script is run
            if (thumbnails) { thumbnails.appendChild(thumbnail); }
            Exp.util.animate(thumbnail, { opacity: 1 }, 200 * speed);
        });
        root.addEventListener('exp:upload:fail', function (e) {
            var d = e.detail;
            if (Exp.dialog) { Exp.dialog.alert(d.error && d.error.message ? d.error.message : String(d.error)); }
        });
        // every file of the round is finished
        root.addEventListener('exp:upload:complete', function (e) {
            var summary = e.detail;
            up.enable();
            disableQuick(false);
            if (canceled) { return; }
            // every file of the round canceled with its own Cancel button in the list: said as with the Cancel button
            if (!summary.done && !summary.failed && summary.canceled) { text(message, cfg.uploadCanceled); css(cancelButton, 'visibility', 'hidden'); return; }
            stopBar();
            css(bar, 'width', '100%');
            text(message, cfg.allFilesRecived);
            text(counter, round.length + '/' + round.length);
            text(name, '');
            css(cancelButton, 'visibility', 'hidden');
        });
        if (cancelButton) {
            cancelButton.addEventListener('click', function (e) {
                e.preventDefault();
                canceled = true;
                up.cancel();
                up.enable();
                disableQuick(false);
                text(message, cfg.uploadCanceled);
            });
        }
    });
})();
