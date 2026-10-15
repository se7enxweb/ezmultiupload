/*!
 * ezmultiupload: the upload page of the administration designs (admin4, admin4l, adminui).
 * GNU General Public License v2.0 (or any later version). https://github.com/se7enxweb/ezmultiupload
 *
 * Files come from the choosers, a drop (files and folders) or the clipboard (images) into a queue. Each row shows
 * the file, a preview of an image, the title (and the alternative text of an image) to edit, the content class it
 * becomes, its location (the queue's, or one of its own), a progress bar and its status. Before anything is sent
 * the server checks every row (ezmultiupload/service, Action=check): the class, the create permission there, the
 * size and type limits, the alternative text a class requires and a file with the same name and size already
 * there. Uploads run three at a time (ezmultiupload/upload/<node>, one POST per file with the form token), each
 * can be canceled and a failed one retried. A ZIP file is inspected on the server (UploadZip=inspect) and shows
 * its entries under its row, each with the class it becomes or why it is skipped; the entries left ticked are
 * imported in one request (UploadZip=import), with the folder structure kept or flattened.
 * What was uploaded is listed with view and edit links and can be moved, placed in a further location or moved to
 * the trash (Action=move, addlocation, remove).
 *
 * The page is the frame of the content action pages: the control bars' input.button and input.defaultbutton, the
 * edit form's field markup, the shared .a4-* components. No CSS is written here: states are classes, progress is
 * an .a4-meter with an .a4-pct-<n> class. The configuration is the JSON of #exp-mu-config
 * (expMultiuploadService::pageConfig()). The location picker and the confirmations are Exp.dialog (expui). The last
 * location and the ZIP folder handling are eZPreferences, stored through window.a4Pref where the design has it
 * (admin4), else through user/preferences/set_and_exit. Feedback is a toast (window.a4Toast, or one of the same look).
 */
(function (window, document) {
    'use strict';

    function init() {
        var root = document.getElementById('exp-mu');
        var cfgEl = document.getElementById('exp-mu-config');
        if (!root || !cfgEl || root.getAttribute('data-exp-mu-ready')) { return; }
        root.setAttribute('data-exp-mu-ready', '1');
        var cfg;
        try { cfg = JSON.parse(cfgEl.textContent); } catch (e) { return; }
        var T = cfg.texts || {};

        function t(key, params) {
            var s = T[key] !== undefined ? String(T[key]) : key;
            Object.keys(params || {}).forEach(function (p) { s = s.split(p).join(String(params[p])); });
            return s;
        }
        function $(id) { return document.getElementById(id); }
        function el(tag, cls, text) {
            var e = document.createElement(tag);
            if (cls) { e.className = cls; }
            if (text !== undefined && text !== null) { e.textContent = String(text); }
            return e;
        }
        function icon(name) { var i = el('span', 'a4-icon a4-bi-' + name); i.setAttribute('aria-hidden', 'true'); return i; }
        function size(bytes) {
            var units = ['B', 'kB', 'MB', 'GB'], i = 0, n = Number(bytes) || 0;
            while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
            var d = i === 0 || n >= 10 ? 0 : 1;
            var text = n.toFixed(d);
            try { text = new Intl.NumberFormat(document.documentElement.lang || undefined, { maximumFractionDigits: d }).format(Number(text)); } catch (e) { /* plain */ }
            return text + ' ' + units[i];
        }
        function token() {
            if (window.Exp && typeof window.Exp.token === 'function' && window.Exp.token()) { return window.Exp.token(); }
            var tk = document.getElementById('ezxform_token_js');
            return tk ? (tk.getAttribute('title') || '') : '';
        }
        /** enables or disables a control bar button; the main one swaps defaultbutton and button-disabled as the kernel's pages do */
        function enable(button, on) {
            button.disabled = !on;
            if (button.getAttribute('data-exp-mu-primary')) { button.className = on ? 'defaultbutton' : 'button-disabled'; }
        }
        function status(text) {
            var s = $('exp-mu-status');
            if (!s) { return; }
            s.textContent = '';
            window.setTimeout(function () { s.textContent = text; }, 30);
        }
        /** A toast: admin4's window.a4Toast, else one of the same look (.a4-toasts, .a4-toast) in live regions of its own (adminui) */
        function toast(text, kind, opener) {
            if (typeof window.a4Toast === 'function' && document.getElementById('a4-toasts')) { window.a4Toast(text, kind, opener); return; }
            var id = kind === 'bad' ? 'exp-mu-toasts-alert' : 'exp-mu-toasts';
            if (!document.getElementById('exp-mu-toasts')) {
                var wrap = el('div', 'a4-toasts');
                [['exp-mu-toasts', 'polite'], ['exp-mu-toasts-alert', 'assertive']].forEach(function (r) {
                    var d = el('div');
                    d.id = r[0];
                    d.setAttribute('aria-live', r[1]);
                    d.setAttribute('aria-relevant', 'additions');
                    wrap.appendChild(d);
                });
                document.body.appendChild(wrap);
            }
            var region = document.getElementById(id);
            var box = el('div', 'a4-toast' + (/^(ok|bad|warn|info)$/.test(kind || '') ? ' is-' + kind : ''));
            box.appendChild(el('span', 'a4-toast-text', text));
            var close = el('button', 'a4-toast-close', '×');
            close.type = 'button';
            close.setAttribute('aria-label', t('dismiss'));
            var remove = function () { if (box.parentNode) { box.parentNode.removeChild(box); } };
            close.addEventListener('click', function () { remove(); if (opener && opener.focus && document.body.contains(opener)) { opener.focus(); } });
            box.appendChild(close);
            while (region.children.length >= 3) { region.removeChild(region.firstChild); }
            region.appendChild(box);
            if (kind !== 'bad') { window.setTimeout(remove, 6000); }
        }
        function dialogs() { return window.Exp && window.Exp.dialog ? window.Exp.dialog : null; }
        function pref(name, value) {
            if (!name) { return; }
            if (window.a4Pref && window.a4Pref.signedIn) { window.a4Pref.set(name, String(value)); return; }
            var body = new URLSearchParams();
            body.append('ezxform_token', token());
            window.fetch(cfg.preferenceUrl + '/' + encodeURIComponent(name) + '/' + encodeURIComponent(String(value)),
                         { method: 'POST', credentials: 'same-origin', body: body }).catch(function () { /* not remembered */ });
        }

        /** POST to the service; resolves with the JSON answer (ok: false with an error for every failure) */
        function service(action, fields) {
            var body = new URLSearchParams();
            body.append('Action', action);
            body.append('ezxform_token', token());
            Object.keys(fields || {}).forEach(function (k) { body.append(k, fields[k]); });
            return window.fetch(cfg.serviceUrl, {
                method: 'POST', credentials: 'same-origin', body: body,
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                if (/\/user\/login\b/.test(r.url || '')) { return { ok: false, error: t('signedOut') }; }
                return r.text().then(function (text) {
                    var data = null;
                    try { data = JSON.parse(text); } catch (e) { data = null; }
                    if (!data) { return { ok: false, error: r.ok ? t('invalid') : t('serverError', { '%status': r.status }) }; }
                    if (!r.ok && data.ok !== false) { data.ok = false; }
                    if (!data.ok && !data.error) { data.error = t('serverError', { '%status': r.status }); }
                    return data;
                });
            }, function () { return { ok: false, error: t('noNetwork') }; });
        }

        /** POST a file to ezmultiupload/upload/<node> with XHR (for the progress); resolves with { ok, data, error } */
        function postFile(row, fields, onProgress) {
            return new Promise(function (resolve) {
                var xhr = row.xhr = new XMLHttpRequest();
                var fd = new FormData();
                fd.append('UploadButton', 'Upload');
                fd.append('ezxform_token', token());
                Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
                fd.append('Filedata', row.file, row.name);
                xhr.open('POST', cfg.uploadUrl + '/' + encodeURIComponent(String(rowTarget(row).id)), true);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.upload.addEventListener('progress', function (e) {
                    if (e.lengthComputable && onProgress) { onProgress(Math.min(99, Math.floor(100 * e.loaded / Math.max(1, e.total)))); }
                });
                xhr.addEventListener('load', function () {
                    row.xhr = null;
                    if (/\/user\/login\b/.test(xhr.responseURL || '')) { resolve({ ok: false, error: t('signedOut') }); return; }
                    var data = null;
                    try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
                    if (!data) { resolve({ ok: false, error: xhr.status >= 200 && xhr.status < 300 ? t('invalid') : t('serverError', { '%status': xhr.status }) }); return; }
                    resolve({ ok: !!data.ok, data: data, error: (data.errors || []).join(' ') || data.error || '' });
                });
                xhr.addEventListener('error', function () { row.xhr = null; resolve({ ok: false, error: t('noNetwork') }); });
                xhr.addEventListener('abort', function () { row.xhr = null; resolve({ ok: false, aborted: true }); });
                xhr.send(fd);
            });
        }

        // ---- state --------------------------------------------------------------------------------------------

        var queueLocation = cfg.location;     // the queue's location
        var rows = [];                        // the queue
        var results = [];                     // what was uploaded: { item, preview, selected, tr }
        var nextId = 1;
        var parallel = Math.max(1, Number(cfg.parallel) || 3);
        var round = null;                     // counts of the uploads since Upload all was pressed
        var checkTimer = null;
        var startWhenChecked = false;
        var keepDraft = $('exp-mu-draft');
        var zipMode = cfg.zipMode === 'flat' ? 'flat' : 'keep';
        var zipImport = !!cfg.zipImport;          // "Import compressed zip file(s) contents", off for a new user

        var tbody = $('exp-mu-queue').tBodies[0];
        var rtbody = $('exp-mu-results').tBodies[0];

        function rowTarget(row) { return row.target || queueLocation; }
        function active(row) { return row.status === 'uploading' || row.status === 'inspecting'; }
        function waiting(row) { return row.status === 'queued'; }
        function finished(row) { return ['done', 'draft', 'skipped', 'canceled'].indexOf(row.status) !== -1; }

        // ---- the target location ---------------------------------------------------------------------------

        var stateBadge = $('exp-mu-location-state');
        var okText = stateBadge.textContent;
        function showLocation() {
            var link = $('exp-mu-location-link');
            link.textContent = queueLocation.pathText || queueLocation.name;
            if (queueLocation.url) { link.setAttribute('href', queueLocation.url); }
            var reason = $('exp-mu-location-reason');
            stateBadge.className = 'a4-badge ' + (queueLocation.allowed ? 'is-ok' : 'is-bad');
            stateBadge.textContent = queueLocation.allowed ? okText : t('blocked');
            reason.hidden = !!queueLocation.allowed;
            reason.textContent = queueLocation.allowed ? '' : t('notAllowedChoose', { '%reason': queueLocation.reason });
            // one more location to offer: the nearest one above that takes files (when this one does not), else the last one used
            var last = $('exp-mu-last-location');
            var s = !queueLocation.allowed && cfg.suggested && cfg.suggested.id !== queueLocation.id ? cfg.suggested : null;
            var r = s || cfg.remembered;
            last.hidden = !(r && r.id !== queueLocation.id);
            last.setAttribute('data-exp-mu-location', s ? 'suggested' : 'remembered');
            if (!last.hidden) { last.textContent = t(s ? 'useSuggested' : 'useLast', { '%location': r.pathText }); }
        }

        function setQueueLocation(location) {
            var before = queueLocation;
            queueLocation = location;
            if (before && before.id !== location.id && before.allowed) { cfg.remembered = before; }
            pref(cfg.preference, location.id);
            showLocation();
            rows.forEach(function (row) { if (!row.target && !active(row) && !finished(row)) { recheck(row); } });
            scheduleCheck();
            status(t('locationChanged', { '%location': location.pathText || location.name }));
        }

        // ---- the location picker -----------------------------------------------------------------------------

        /** Opens the picker in mode 'upload' (where files can be uploaded) or 'place' (where the user may create); a Promise of the location or null */
        function pick(mode, start) {
            var D = dialogs();
            if (!D) { return Promise.resolve(null); }
            var body = el('div');
            var crumbsNav = el('nav');
            crumbsNav.setAttribute('aria-label', t('pickCrumbs'));
            var crumbs = el('ol', 'a4-crumbs');
            crumbs.setAttribute('role', 'list');
            crumbsNav.appendChild(crumbs);
            var filterId = 'exp-mu-filter-' + (nextId++);
            var filterWrap = el('div', 'a4-field');
            var filterLabel = el('label', 'a4-label', t('pickFilter'));
            filterLabel.setAttribute('for', filterId);
            var filter = el('input', 'a4-input');
            filter.type = 'search';
            filter.id = filterId;
            filter.autocomplete = 'off';
            filterWrap.appendChild(filterLabel);
            filterWrap.appendChild(filter);
            var here = el('p');
            var wrap = el('div', 'a4-table-wrap');
            var table = el('table', 'a4-table');
            table.appendChild(el('caption', 'a4-sr-only', t('pickTitle')));
            var tb = el('tbody');
            table.appendChild(tb);
            wrap.appendChild(table);
            var empty = el('p', 'a4-empty', t('pickEmpty'));
            empty.hidden = true;
            var live = el('p', 'a4-sr-only');
            live.setAttribute('role', 'status');
            [crumbsNav, here, filterWrap, wrap, empty, live].forEach(function (n) { body.appendChild(n); });

            var current = null;
            var dlg = D.create({ title: t('pickTitle'), content: body, size: 'l', className: 'exp-mu-picker-dialog',
                                 buttons: [{ label: t('cancelDialog'), value: null, name: 'cancel' },
                                           { label: t('pickThis'), value: 'choose', primary: true, name: 'choose' }],
                                 initialFocus: 'input[type="search"]' });

            function render(data) {
                current = data.location;
                crumbs.innerHTML = '';
                (data.crumbs || []).forEach(function (c, i) {
                    var li = el('li');
                    if (i === data.crumbs.length - 1) {
                        var s = el('span', '', c.name);
                        s.setAttribute('aria-current', 'page');
                        li.appendChild(s);
                    } else {
                        var b = el('button', 'a4-link-button', c.name);
                        b.type = 'button';
                        b.addEventListener('click', function () { load(c.id, true); });
                        li.appendChild(b);
                    }
                    crumbs.appendChild(li);
                });
                here.textContent = '';
                if (current.id !== 1) {
                    here.appendChild(el('strong', '', current.pathText));
                    here.appendChild(document.createTextNode(' '));
                    here.appendChild(el('span', 'a4-badge ' + (current.allowed ? 'is-ok' : 'is-warn'), current.allowed ? okText : current.reason));
                }
                var cb = dlg.element.querySelector('button[name="choose"]');
                if (cb) { cb.disabled = !current.allowed; }
                tb.innerHTML = '';
                filter.value = '';
                (data.children || []).forEach(function (c) {
                    var tr = el('tr');
                    tr.setAttribute('data-name', String(c.name).toLowerCase());
                    var tdName = el('td');
                    var open = el('button', 'a4-link-button');
                    open.type = 'button';
                    open.appendChild(icon('folder'));
                    open.appendChild(document.createTextNode(' ' + c.name));
                    open.setAttribute('aria-label', t('pickOpen', { '%name': c.name }));
                    open.addEventListener('click', function () { load(c.id, true); });
                    tdName.appendChild(open);
                    tr.appendChild(tdName);
                    var tdState = el('td');
                    var whyId = '';
                    if (!c.allowed) {
                        var why = el('span', 'a4-help', t('pickNotAllowed', { '%reason': c.reason }));
                        whyId = why.id = 'exp-mu-why-' + (nextId++);
                        tdState.appendChild(why);
                    }
                    tr.appendChild(tdState);
                    var tdAct = el('td', 'a4-end');
                    var choose = el('button', 'a4-btn a4-btn-small', t('pickThis'));
                    choose.type = 'button';
                    choose.setAttribute('aria-label', t('pickChoose', { '%name': c.name }));
                    if (!c.allowed) {
                        choose.disabled = true;
                        choose.setAttribute('aria-describedby', whyId);
                    }
                    choose.addEventListener('click', function () {
                        service('browse', { Location: c.id, Mode: mode }).then(function (d) {
                            if (d.ok && d.location.allowed) { dlg.close(d.location); } else { dlg.error(d.error || d.location.reason); }
                        });
                    });
                    tdAct.appendChild(choose);
                    tr.appendChild(tdAct);
                    tb.appendChild(tr);
                });
                wrap.hidden = !(data.children || []).length;
                empty.hidden = !!(data.children || []).length;
            }
            function load(id, focus) {
                body.setAttribute('aria-busy', 'true');
                live.textContent = t('pickLoading');
                return service('browse', { Location: id, Mode: mode }).then(function (data) {
                    body.removeAttribute('aria-busy');
                    if (!data.ok) { dlg.error(data.error); live.textContent = data.error; return; }
                    dlg.error('');
                    render(data);
                    live.textContent = data.location.pathText || data.location.name;
                    if (focus) { filter.focus(); }
                });
            }
            filter.addEventListener('input', function () {
                var q = filter.value.trim().toLowerCase();
                var shown = 0;
                Array.prototype.forEach.call(tb.rows, function (tr) {
                    var match = !q || tr.getAttribute('data-name').indexOf(q) !== -1;
                    tr.hidden = !match;
                    if (match) { shown++; }
                });
                empty.hidden = shown > 0;
            });
            var promise = dlg.open().then(function (value) {
                if (value === 'choose') { return current && current.allowed ? current : null; }
                return value && typeof value === 'object' ? value : null;
            });
            load(start || 1, false);
            return promise;
        }

        $('exp-mu-change-location').addEventListener('click', function () {
            pick('upload', queueLocation.id).then(function (loc) { if (loc) { setQueueLocation(loc); } });
        });
        $('exp-mu-last-location').addEventListener('click', function () {
            var which = this.getAttribute('data-exp-mu-location') === 'suggested' ? cfg.suggested : cfg.remembered;
            if (which) { setQueueLocation(which); }
        });

        // ---- the folder handling of ZIP files -------------------------------------------------------------------

        var modeBox = $('exp-mu-zip-mode'), importBox = $('exp-mu-zip-import');
        function showZipOptions() {
            if (importBox) { importBox.checked = zipImport; importBox.closest('label').hidden = !cfg.zipEnabled; }
            if (modeBox) { modeBox.hidden = !cfg.zipEnabled || !zipImport; }
        }
        showZipOptions();
        if (importBox) {
            importBox.addEventListener('change', function () {
                zipImport = importBox.checked;
                pref(cfg.zipImportPreference, zipImport ? '1' : '0');
                showZipOptions();
                rows.forEach(function (row) { if (row.zip && row.zipOverride === null && !active(row) && !finished(row)) { recheck(row); } });
                updateQueue();
                scheduleCheck();
            });
        }
        Array.prototype.forEach.call(document.querySelectorAll('input[name="ExpMuZipMode"]'), function (radio) {
            radio.checked = radio.value === zipMode;
            radio.addEventListener('change', function () {
                if (!radio.checked) { return; }
                zipMode = radio.value === 'flat' ? 'flat' : 'keep';
                pref(cfg.zipPreference, zipMode);
                rows.forEach(function (row) { if (expands(row) && !active(row) && !finished(row)) { recheck(row); } });
                scheduleCheck();
            });
        });

        // ---- adding files -------------------------------------------------------------------------------------

        function titleOf(name) {
            var dot = name.lastIndexOf('.');
            return dot > 0 ? name.slice(0, dot) : name;
        }
        // a preview is an <img> of the browser's own copy of the file: an SVG drawn as an image runs no script
        function isImage(file) { return /^image\/(png|jpe?g|gif|webp|bmp|avif|svg\+xml)$/i.test(file.type || ''); }
        function isZip(file) { return !!cfg.zipEnabled && /\.zip$/i.test(file.name || ''); }
        /** whether a ZIP row's contents are imported: the row's own choice, else the page's checkbox */
        function expands(row) { return !!row.zip && (row.zipOverride === null || row.zipOverride === undefined ? zipImport : row.zipOverride); }

        function add(files) {
            var list = Array.prototype.slice.call(files || []).filter(function (f) { return f && typeof f.size === 'number'; });
            if (!list.length) { return; }
            list.forEach(function (file) {
                var row = { id: 'exp-mu-row-' + (nextId++), file: file, name: file.name || 'file', size: file.size, type: file.type || '',
                            title: titleOf(file.name || 'file'), titleTouched: false, alt: '', target: null, check: null,
                            status: 'checking', reason: '', percent: 0, xhr: null, dup: 'ask', clientRefusal: '',
                            zip: isZip(file), entries: null, entryRows: [], counts: null, zipOverride: null,
                            preview: isImage(file) && window.URL && URL.createObjectURL ? URL.createObjectURL(file) : '' };
                if (cfg.maxSize && file.size > cfg.maxSize) {
                    row.clientRefusal = t('tooLarge', { '%max': cfg.maxSizeText || size(cfg.maxSize) });
                }
                rows.push(row);
                draw(row);
            });
            updateQueue();
            status(t('added', { '%count': list.length }));
            scheduleCheck();
        }

        var filesInput = $('exp-mu-files'), folderInput = $('exp-mu-folder');
        $('exp-mu-pick-files').addEventListener('click', function () { filesInput.click(); });
        filesInput.addEventListener('change', function () { add(filesInput.files); filesInput.value = ''; });
        if ('webkitdirectory' in folderInput) {
            folderInput.setAttribute('webkitdirectory', '');
            $('exp-mu-pick-folder').hidden = false;
            $('exp-mu-pick-folder').addEventListener('click', function () { folderInput.click(); });
            folderInput.addEventListener('change', function () { add(folderInput.files); folderInput.value = ''; });
        }

        // drop: files, and folders where the browser gives their entries
        var drop = $('exp-mu-drop'), depth = 0;
        function hasFiles(e) { var dt = e.dataTransfer; return !!dt && Array.prototype.indexOf.call(dt.types || [], 'Files') !== -1; }
        function readEntry(entry) {
            return new Promise(function (resolve) {
                if (!entry) { resolve([]); return; }
                if (entry.isFile) { entry.file(function (f) { resolve([f]); }, function () { resolve([]); }); return; }
                if (!entry.isDirectory) { resolve([]); return; }
                var reader = entry.createReader(), all = [];
                (function more() {
                    reader.readEntries(function (batch) {
                        if (!batch.length) {
                            Promise.all(all.map(readEntry)).then(function (lists) { resolve([].concat.apply([], lists)); });
                            return;
                        }
                        all = all.concat(Array.prototype.slice.call(batch));
                        more();
                    }, function () { resolve([]); });
                })();
            });
        }
        drop.addEventListener('dragenter', function (e) { if (!hasFiles(e)) { return; } e.preventDefault(); depth++; drop.classList.add('is-over'); });
        drop.addEventListener('dragover', function (e) { if (!hasFiles(e)) { return; } e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; });
        drop.addEventListener('dragleave', function () { depth = Math.max(0, depth - 1); if (!depth) { drop.classList.remove('is-over'); } });
        drop.addEventListener('drop', function (e) {
            if (!hasFiles(e)) { return; }
            e.preventDefault();
            depth = 0;
            drop.classList.remove('is-over');
            var items = e.dataTransfer.items;
            var entries = items ? Array.prototype.map.call(items, function (i) { return i.webkitGetAsEntry ? i.webkitGetAsEntry() : null; }) : [];
            if (entries.length && entries.every(Boolean) && entries.some(function (en) { return en.isDirectory; })) {
                Promise.all(entries.map(readEntry)).then(function (lists) { add([].concat.apply([], lists)); });
            } else {
                add(e.dataTransfer.files);
            }
        });
        // paste: images from the clipboard (text pastes into the fields as always)
        document.addEventListener('paste', function (e) {
            var cd = e.clipboardData;
            if (!cd || !cd.files || !cd.files.length) { return; }
            var images = Array.prototype.filter.call(cd.files, function (f) { return /^image\//.test(f.type || ''); });
            if (!images.length) { return; }
            e.preventDefault();
            var stamp = new Date().toISOString().replace(/[:T]/g, '-').slice(0, 19);
            add(images.map(function (f, i) {
                var ext = (f.type.split('/')[1] || 'png').replace('jpeg', 'jpg').replace(/[^a-z0-9]/gi, '');
                var name = 'pasted-' + stamp + (images.length > 1 ? '-' + (i + 1) : '') + '.' + ext;
                try { return new File([f], name, { type: f.type }); } catch (err) { return f; }
            }));
        });

        // ---- the queue's rows ---------------------------------------------------------------------------------

        function statusText(row) {
            switch (row.status) {
                case 'checking': return t('checking');
                case 'inspecting': return t('inspecting');
                case 'queued': return t('queued');
                case 'uploading': return t('uploading') + ' ' + row.percent + '%';
                case 'done': return expands(row) && row.counts ? t('zipDone', { '%done': row.counts.done + row.counts.draft, '%skipped': row.counts.skipped + row.counts.failed }) : t('done');
                case 'draft': return t('draft');
                case 'failed': return t('failed') + (row.reason ? ': ' + row.reason : '');
                case 'canceled': return t('canceled');
                case 'skipped': return t('skipped');
                case 'blocked': return t('blocked') + (row.reason ? ': ' + row.reason : '');
            }
            return '';
        }
        var BADGE = { checking: 'is-info', inspecting: 'is-info', queued: 'is-info', uploading: 'is-accent', done: 'is-ok', draft: 'is-ok',
                      failed: 'is-bad', canceled: 'is-warn', skipped: 'is-warn', blocked: 'is-bad' };

        /** a field in the edit form's markup: .a4-content-attr, its label, the input, the error */
        function field(row, which, labelText) {
            var box = el('div', 'a4-content-attr');
            var id = row.id + '-' + which;
            var lab = el('label', 'a4-content-attr-label', labelText);
            lab.setAttribute('for', id);
            var input = el('input', 'box');
            input.type = 'text';
            input.id = id;
            input.maxLength = 255;
            input.setAttribute('aria-describedby', row.id + '-name');
            var err = el('p', 'a4-field-error');
            err.id = id + '-error';
            err.hidden = true;
            box.appendChild(lab);
            box.appendChild(input);
            box.appendChild(err);
            return { box: box, label: lab, input: input, error: err };
        }

        function draw(row) {
            if (!row.tr) {
                var tr = row.tr = el('tr');
                tr.id = row.id;
                // preview
                var tdPrev = el('td');
                if (row.preview) {
                    var img = el('img', 'exp-mu-thumb');
                    img.src = row.preview;
                    img.alt = '';
                    tdPrev.appendChild(img);
                } else {
                    tdPrev.appendChild(icon(row.zip ? 'box-seam' : (/pdf$/i.test(row.name) ? 'file-earmark-pdf' : 'files')));
                }
                tr.appendChild(tdPrev);
                // file: name, size and type, the class, the location
                var tdFile = el('td');
                row.nameEl = el('strong', 'exp-mu-name', row.name);
                row.nameEl.id = row.id + '-name';
                tdFile.appendChild(row.nameEl);
                tdFile.appendChild(el('div', 'a4-meta', size(row.size) + (row.type ? ' · ' + row.type : '')));
                row.classEl = el('span', 'a4-tag');
                tdFile.appendChild(row.classEl);
                var loc = el('div', 'a4-meta');
                row.locText = el('span');
                var change = row.changeButton = el('button', 'a4-link-button', t('change'));
                change.type = 'button';
                change.setAttribute('aria-label', t('changeFor', { '%name': row.name }));
                change.addEventListener('click', function () {
                    pick('upload', rowTarget(row).id).then(function (l) {
                        if (!l) { return; }
                        row.target = l.id === queueLocation.id ? null : l;
                        recheck(row);
                        scheduleCheck();
                    });
                });
                row.resetButton = el('button', 'a4-link-button', t('reset'));
                row.resetButton.type = 'button';
                row.resetButton.addEventListener('click', function () { row.target = null; recheck(row); scheduleCheck(); });
                loc.appendChild(row.locText);
                loc.appendChild(document.createTextNode(' '));
                loc.appendChild(change);
                loc.appendChild(document.createTextNode(' '));
                loc.appendChild(row.resetButton);
                tdFile.appendChild(loc);
                tr.appendChild(tdFile);
                // title and alternative text (a ZIP file: what is in it and the duplicate choice)
                var tdText = el('td');
                if (row.zip) {
                    // a ZIP file: uploaded as one file, or its contents imported (the page's choice, or this row's own)
                    row.zipInfo = el('p', 'a4-meta');
                    row.zipInfo.id = row.id + '-zipinfo';
                    tdText.appendChild(row.zipInfo);
                    var zl = el('label', 'a4-check');
                    row.zipToggle = el('input');
                    row.zipToggle.type = 'checkbox';
                    row.zipToggle.addEventListener('change', function () {
                        row.zipOverride = row.zipToggle.checked === zipImport ? null : row.zipToggle.checked;
                        recheck(row);
                        updateQueue();
                        scheduleCheck();
                    });
                    zl.appendChild(row.zipToggle);
                    zl.appendChild(el('span', '', t('zipRowImport')));
                    tdText.appendChild(zl);
                }
                {
                    var f = field(row, 'title', t('title'));
                    row.titleBox = f.box;
                    row.titleInput = f.input;
                    row.titleError = f.error;
                    f.input.value = row.title;
                    f.input.addEventListener('input', function () { row.title = f.input.value; row.titleTouched = true; clearError(row, 'title'); });
                    tdText.appendChild(f.box);
                    if (row.preview || /^image\//.test(row.type)) {
                        var a = field(row, 'alt', t('alt'));
                        row.altLabel = a.label;
                        row.altInput = a.input;
                        row.altError = a.error;
                        a.input.addEventListener('input', function () { row.alt = a.input.value; clearError(row, 'alt'); });
                        tdText.appendChild(a.box);
                    }
                }
                row.dupBox = el('div', 'a4-content-attr');
                row.dupBox.id = row.id + '-dupbox';
                row.dupBox.hidden = true;
                tdText.appendChild(row.dupBox);
                tr.appendChild(tdText);
                // status
                var tdStatus = el('td');
                row.badge = el('span', 'a4-badge');
                row.meter = el('span', 'a4-meter a4-pct-0');
                row.meter.setAttribute('role', 'progressbar');
                row.meter.setAttribute('aria-valuemin', '0');
                row.meter.setAttribute('aria-valuemax', '100');
                row.meter.setAttribute('aria-labelledby', row.nameEl.id);
                row.meter.appendChild(el('span'));
                tdStatus.appendChild(row.badge);
                tdStatus.appendChild(row.meter);
                tr.appendChild(tdStatus);
                // actions
                var tdAct = el('td', 'a4-end a4-nowrap');
                row.cancelBtn = actionButton('x-circle-fill', t('cancelName', { '%name': row.name }), function () { cancel(row); });
                row.retryBtn = actionButton('arrow-clockwise', t('retryName', { '%name': row.name }), function () { retry(row); });
                row.removeBtn = actionButton('trash', t('removeName', { '%name': row.name }), function () { removeRow(row); });
                tdAct.appendChild(row.cancelBtn);
                tdAct.appendChild(row.retryBtn);
                tdAct.appendChild(row.removeBtn);
                tr.appendChild(tdAct);
                tbody.appendChild(tr);
            }
            var c = row.check;
            row.classEl.textContent = expands(row) ? t('zipFile') : (c && (c.className || c['class'])) || '…';
            var target = rowTarget(row);
            row.locText.textContent = row.target ? target.pathText : t('sameLocation');
            var locked = active(row) || finished(row);
            row.resetButton.hidden = !row.target || locked;
            row.changeButton.hidden = locked;
            if (row.titleInput) { row.titleInput.readOnly = locked; }
            if (row.altInput) {
                row.altInput.readOnly = locked;
                var req = !!(c && c.altRequired);
                row.altInput.setAttribute('aria-required', req ? 'true' : 'false');
                row.altInput.required = req;
                row.altLabel.textContent = t('alt');
                if (req) {
                    var mark = el('span', 'a4-required-mark', '*');
                    mark.setAttribute('aria-hidden', 'true');
                    row.altLabel.appendChild(mark);
                    row.altLabel.appendChild(el('span', 'a4-sr-only', ' (' + t('required') + ')'));
                }
            }
            if (row.zip) {
                var exp = expands(row);
                row.zipInfo.textContent = exp ? (row.entries ? t('zipCount', { '%ok': row.entries.ok, '%skipped': row.entries.skipped }) : t('zipWillImport')) : t('zipAsFile');
                row.zipToggle.checked = exp;
                row.zipToggle.disabled = locked;
                row.titleBox.hidden = exp;
            }
            row.tr.className = 'exp-mu-row is-' + row.status;
            row.badge.className = 'a4-badge ' + (BADGE[row.status] || '');
            row.badge.textContent = statusText(row);
            var pct = Math.max(0, Math.min(100, Math.round(row.percent)));
            row.meter.className = 'a4-meter a4-pct-' + pct + (row.status === 'failed' ? ' is-bad' : (row.status === 'done' || row.status === 'draft' ? ' is-ok' : ''));
            row.meter.setAttribute('aria-valuenow', String(pct));
            row.meter.setAttribute('aria-valuetext', statusText(row));
            row.meter.hidden = !(row.status === 'uploading' || row.status === 'done' || row.status === 'draft' || row.status === 'failed');
            row.cancelBtn.hidden = !(active(row) || waiting(row) || row.status === 'checking');
            row.retryBtn.hidden = !(row.status === 'failed' || row.status === 'canceled' || row.status === 'skipped');
            row.removeBtn.hidden = active(row);
            drawDuplicate(row);
            if (row.zip) { drawEntries(row); }
        }

        function actionButton(name, label, fn) {
            var b = el('button', 'a4-icon-btn');
            b.type = 'button';
            b.appendChild(icon(name));
            b.appendChild(el('span', 'a4-sr-only', label));
            b.title = label;
            b.addEventListener('click', fn);
            return b;
        }

        /** the duplicate choice of a row: a file of its name and size is there already (a ZIP file: some of its entries) */
        function dupInfo(row) {
            if (expands(row)) { return row.entries && row.entries.duplicates ? { zip: true, count: row.entries.duplicates } : null; }
            return row.check && row.check.duplicate ? row.check.duplicate : null;
        }
        function drawDuplicate(row) {
            var d = dupInfo(row);
            var show = !!d && (row.status === 'queued' || row.status === 'checking');
            row.dupBox.hidden = !show;
            if (!show) { row.dupBox.innerHTML = ''; row.dupKey = ''; return; }
            var key = d.zip ? 'zip' + d.count : d.nodeId + '|' + d.suggestion;
            if (row.dupKey === key) {
                if (row.dupSelect) { row.dupSelect.value = row.dup; }
                return;
            }
            row.dupKey = key;
            row.dupBox.innerHTML = '';
            var p = el('p', 'a4-help');
            if (d.zip) {
                p.textContent = t('zipDuplicates', { '%count': d.count });
            } else {
                var parts = t('duplicate').split('%link');
                p.appendChild(document.createTextNode(parts[0]));
                var a = el('a', '', d.title || d.name);
                a.href = d.url;
                p.appendChild(a);
                p.appendChild(document.createTextNode(parts.slice(1).join('')));
            }
            var sid = row.id + '-dup';
            var lab = el('label', 'a4-content-attr-label', t('ifExists'));
            lab.setAttribute('for', sid);
            var sel = row.dupSelect = el('select');
            sel.id = sid;
            sel.setAttribute('aria-describedby', row.nameEl.id);
            [['ask', t('dupAsk')], ['skip', t('dupSkip')], ['rename', d.zip ? t('dupRenameAll') : t('dupRename', { '%name': d.suggestion })], ['upload', t('dupUpload')]].forEach(function (o) {
                var opt = el('option', '', o[1]);
                opt.value = o[0];
                sel.appendChild(opt);
            });
            sel.value = row.dup;
            sel.addEventListener('change', function () { row.dup = sel.value; });
            row.dupBox.appendChild(p);
            row.dupBox.appendChild(lab);
            row.dupBox.appendChild(sel);
        }

        /** the entries of a ZIP file, as rows under its row: a checkbox for those that can be imported, the class or the reason */
        var ENTRY_BADGE = { ok: 'is-info', skipped: 'is-warn', done: 'is-ok', draft: 'is-ok', failed: 'is-bad', unselected: '' };
        function drawEntries(row) {
            var list = row.entries && expands(row) ? row.entries.entries : [];
            if (row.entryRows.length && (!row.entries || row.entryRows.length !== list.length)) {
                row.entryRows.forEach(function (e) { if (e.tr.parentNode) { e.tr.parentNode.removeChild(e.tr); } });
                row.entryRows = [];
            }
            if (!row.entryRows.length && list.length) {
                var after = row.tr;
                list.forEach(function (entry, i) {
                    var tr = el('tr', 'exp-mu-entry');
                    tr.setAttribute('data-exp-mu-entry', entry.key);
                    var tdPick = el('td');
                    var lab = el('label', 'a4-check');
                    var cb = el('input');
                    cb.type = 'checkbox';
                    cb.checked = entry.status === 'ok';
                    cb.disabled = entry.status !== 'ok';
                    var nameId = row.id + '-e' + i;
                    cb.setAttribute('aria-labelledby', nameId);
                    lab.appendChild(cb);
                    tdPick.appendChild(lab);
                    tr.appendChild(tdPick);
                    var tdName = el('td');
                    tdName.colSpan = 2;
                    var nm = el('span', '', entry.path);
                    nm.id = nameId;
                    tdName.appendChild(nm);
                    tdName.appendChild(el('div', 'a4-meta', size(entry.size) + (entry.className ? ' · ' + entry.className : '') +
                                                         (entry.folder && zipModeOf(row) === 'keep' ? ' · ' + entry.folder : '')));
                    tr.appendChild(tdName);
                    var tdState = el('td');
                    tdState.colSpan = 2;
                    var badge = el('span', 'a4-badge');
                    tdState.appendChild(badge);
                    tr.appendChild(tdState);
                    after.parentNode.insertBefore(tr, after.nextSibling);
                    after = tr;
                    row.entryRows.push({ entry: entry, tr: tr, checkbox: cb, badge: badge, result: null });
                });
            }
            var locked = active(row) || finished(row);
            row.entryRows.forEach(function (e) {
                var st = e.result ? e.result.status : e.entry.status;
                var reason = e.result ? e.result.reason : e.entry.reason;
                e.checkbox.disabled = locked || e.entry.status !== 'ok';
                e.badge.className = 'a4-badge ' + (ENTRY_BADGE[st] || '');
                e.badge.textContent = st === 'ok' ? (e.entry.duplicate ? t('dupNote') : t('queued'))
                    : (st === 'skipped' || st === 'failed') ? (reason || t(st))
                    : st === 'unselected' ? t('unselected') : t(st);
                e.tr.className = 'exp-mu-entry is-' + st;
            });
        }
        function zipModeOf(row) { return row.inspectedMode || zipMode; }

        function setError(row, which, text) {
            var input = which === 'alt' ? row.altInput : row.titleInput, box = which === 'alt' ? row.altError : row.titleError;
            if (!input || !box) { return; }
            box.textContent = text;
            box.hidden = false;
            input.setAttribute('aria-invalid', 'true');
            input.setAttribute('aria-describedby', row.nameEl.id + ' ' + box.id);
        }
        function clearError(row, which) {
            var input = which === 'alt' ? row.altInput : row.titleInput, box = which === 'alt' ? row.altError : row.titleError;
            if (!input || !box) { return; }
            box.hidden = true;
            box.textContent = '';
            input.removeAttribute('aria-invalid');
            input.setAttribute('aria-describedby', row.nameEl.id);
        }

        function updateQueue() {
            var count = rows.length;
            $('exp-mu-queue-count').textContent = String(count);
            $('exp-mu-queue-empty').hidden = count > 0;
            $('exp-mu-queue').parentNode.hidden = count === 0;
            enable($('exp-mu-start'), rows.some(function (r) { return waiting(r) || r.status === 'checking' || r.status === 'inspecting'; }));
            enable($('exp-mu-cancel-all'), rows.some(function (r) { return active(r) || waiting(r); }));
            enable($('exp-mu-retry-all'), rows.some(function (r) { return r.status === 'failed' || r.status === 'canceled'; }));
            enable($('exp-mu-clear'), rows.some(function (r) { return finished(r) || r.status === 'failed' || r.status === 'blocked'; }));
            // the bar of the round: bytes sent of the files sent and to be sent
            var overall = $('exp-mu-overall'), meter = $('exp-mu-overall-meter');
            var inRound = rows.filter(function (r) { return r.round && r.round === round; });
            overall.hidden = !inRound.length;
            if (inRound.length) {
                var total = 0, loaded = 0;
                inRound.forEach(function (r) { total += r.size || 1; loaded += (r.status === 'uploading' ? r.percent / 100 : (r.status === 'queued' ? 0 : 1)) * (r.size || 1); });
                var pct = total ? Math.round(100 * loaded / total) : 0;
                meter.className = 'a4-meter a4-pct-' + pct;
                meter.setAttribute('aria-valuenow', String(pct));
            }
        }

        // ---- checks on the server ------------------------------------------------------------------------------

        function recheck(row) {
            row.check = null;
            row.entries = null;
            if (row.status !== 'uploading' && row.status !== 'inspecting') { row.status = 'checking'; }
            draw(row);
        }

        function scheduleCheck() {
            if (checkTimer) { window.clearTimeout(checkTimer); }
            checkTimer = window.setTimeout(runCheck, 120);
        }

        function settle(row, loc, refusal) {
            if (!loc.allowed) { row.status = 'blocked'; row.reason = loc.reason; }
            else if (row.clientRefusal) { row.status = 'blocked'; row.reason = row.clientRefusal; }
            else if (refusal) { row.status = 'blocked'; row.reason = refusal; }
            else { row.status = 'queued'; row.reason = ''; }
            if (row.autoGo) {
                row.autoGo = false;
                if (row.status === 'queued') {
                    if (!round) { round = { done: 0, failed: 0, skipped: 0 }; }
                    row.round = round;
                    row.go = true;
                }
            }
            draw(row);
        }

        /** a ZIP file: inspected on the server (sent once for this; the entries are listed, nothing is made) */
        function inspect(row) {
            if (row.clientRefusal || !rowTarget(row).allowed) { settle(row, rowTarget(row), ''); updateQueue(); return; }
            row.status = 'inspecting';
            row.percent = 0;
            draw(row);
            postFile(row, { UploadZip: 'inspect', UploadZipMode: zipMode }).then(function (r) {
                if (r.aborted) { return; }
                if (!r.ok) { row.entries = null; settle(row, rowTarget(row), r.error || t('failed')); updateQueue(); pump(); return; }
                row.inspectedMode = r.data.mode;
                row.entryRows.forEach(function (e) { if (e.tr.parentNode) { e.tr.parentNode.removeChild(e.tr); } });
                row.entryRows = [];
                row.entries = r.data.zip;
                settle(row, rowTarget(row), row.entries.ok ? '' : t('zipNothing'));
                updateQueue();
                pump();
            });
        }

        function runCheck() {
            checkTimer = null;
            var groups = {};
            rows.forEach(function (row) {
                if (row.status !== 'checking') { return; }
                if (expands(row)) { inspect(row); return; }
                var id = rowTarget(row).id;
                (groups[id] = groups[id] || []).push(row);
            });
            // the queue's own location is asked about even without rows, so its state is shown
            if (!groups[queueLocation.id]) { groups[queueLocation.id] = []; }
            Object.keys(groups).forEach(function (id) {
                var group = groups[id];
                service('check', { Location: id, Files: JSON.stringify(group.map(function (r) { return { name: r.name, size: r.size }; })) }).then(function (data) {
                    if (!data.ok) {
                        group.forEach(function (row) { row.status = 'blocked'; row.reason = data.error; draw(row); });
                        updateQueue();
                        return;
                    }
                    var loc = data.location;
                    if (Number(id) === queueLocation.id) { queueLocation = loc; showLocation(); }
                    group.forEach(function (row, i) {
                        if (rowTarget(row).id !== Number(id) || row.status !== 'checking') { return; }
                        if (row.target) { row.target = loc; }
                        var c = (data.files || [])[i] || null;
                        row.check = c;
                        settle(row, loc, c && !c.allowed ? c.reason : '');
                    });
                    updateQueue();
                    pump();
                });
            });
            updateQueue();
        }

        // ---- uploading ------------------------------------------------------------------------------------------

        function validate(list) {
            var first = null;
            list.forEach(function (row) {
                if (expands(row)) { return; }
                clearError(row, 'title');
                clearError(row, 'alt');
                if (!row.title.trim()) { setError(row, 'title', t('titleRequired')); first = first || row.titleInput; }
                if (row.check && row.check.altRequired && row.altInput && !row.alt.trim()) { setError(row, 'alt', t('altRequired')); first = first || row.altInput; }
            });
            if (first) { first.focus(); }
            return !first;
        }

        /** Asks once what happens to the files that are there already (rows whose choice is still "Ask") */
        function resolveDuplicates(list) {
            var ask = list.filter(function (r) { return dupInfo(r) && r.dup === 'ask'; });
            if (!ask.length) { return Promise.resolve(true); }
            var D = dialogs();
            if (!D) { ask.forEach(function (r) { r.dup = 'skip'; }); return Promise.resolve(true); }
            var count = 0;
            var body = el('div');
            var ul = el('ul');
            ask.forEach(function (r) {
                var d = dupInfo(r);
                if (d.zip) {
                    count += d.count;
                    ul.appendChild(el('li', '', r.name + ': ' + t('zipDuplicates', { '%count': d.count })));
                    return;
                }
                count++;
                var li = el('li', '', r.name + ' → ');
                var a = el('a', '', d.title || d.name);
                a.href = d.url;
                a.target = '_blank';
                a.rel = 'noopener';
                li.appendChild(a);
                ul.appendChild(li);
            });
            body.appendChild(el('p', '', count === 1 ? t('dupTextOne') : t('dupText', { '%count': count })));
            body.appendChild(ul);
            return dialogs().open({ title: t('dupTitle'), content: body, size: 'm',
                            buttons: [{ label: t('cancelDialog'), value: null, name: 'cancel' },
                                      { label: t('dupSkipAll'), value: 'skip', name: 'skip' },
                                      { label: t('dupRenameAll'), value: 'rename', name: 'rename' },
                                      { label: t('dupUploadAll'), value: 'upload', name: 'upload', primary: true }] })
                .then(function (v) {
                    if (!v) { return false; }
                    ask.forEach(function (r) { r.dup = v; draw(r); });
                    return true;
                });
        }

        function start() {
            var list = rows.filter(waiting);
            if (!list.length) {
                if (rows.some(function (r) { return r.status === 'checking' || r.status === 'inspecting'; })) { startWhenChecked = true; } else { status(t('noFiles')); }
                return;
            }
            if (!validate(list)) { return; }
            resolveDuplicates(list).then(function (go) {
                if (!go) { return; }
                if (!round) { round = { done: 0, failed: 0, skipped: 0 }; }
                list.forEach(function (row) {
                    if (!expands(row) && row.check && row.check.duplicate) {
                        if (row.dup === 'skip') { row.status = 'skipped'; round.skipped++; draw(row); return; }
                        if (row.dup === 'rename') {
                            var newName = row.check.duplicate.suggestion;
                            if (!row.titleTouched || row.title === titleOf(row.name)) { row.title = titleOf(newName); row.titleInput.value = row.title; }
                            row.name = newName;
                            row.nameEl.textContent = newName;
                        }
                    }
                    row.round = round;
                    row.go = true;
                });
                updateQueue();
                pump();
            });
        }

        function pump() {
            if (startWhenChecked && !rows.some(function (r) { return r.status === 'checking' || r.status === 'inspecting'; })) { startWhenChecked = false; start(); return; }
            var running = rows.filter(function (r) { return r.status === 'uploading'; }).length;
            rows.forEach(function (row) {
                if (running >= parallel || !waiting(row) || !row.go) { return; }
                if (expands(row)) { sendZip(row); } else { send(row); }
                running++;
            });
            finishRound();
        }

        function progress(row) {
            return function (pct) {
                if (row.status !== 'uploading') { return; }
                row.percent = pct;
                draw(row);
                updateQueue();
            };
        }

        function send(row) {
            row.status = 'uploading';
            row.percent = 0;
            row.reason = '';
            draw(row);
            updateQueue();
            postFile(row, { UploadTitle: row.title.trim(), UploadAltText: row.alt.trim(), UploadKeepDraft: keepDraft.checked ? '1' : '0' }, progress(row)).then(function (r) {
                if (r.aborted || row.status !== 'uploading') { return; }
                if (!r.ok || !r.data.item) { fail(row, r.error || t('invalid')); return; }
                row.status = r.data.item.draft ? 'draft' : 'done';
                row.percent = 100;
                if (row.round) { row.round.done++; }
                draw(row);
                addResult(r.data.item, row.preview);
                updateQueue();
                pump();
            });
        }

        /** a ZIP file: the ticked entries are imported in one request; each entry's result is shown under it */
        function sendZip(row) {
            // the ticked entries, read before the row is locked (locked rows disable their checkboxes)
            var keys = row.entryRows.filter(function (e) { return e.checkbox.checked && !e.checkbox.disabled; }).map(function (e) { return e.entry.key; });
            row.status = 'uploading';
            row.percent = 0;
            row.reason = '';
            draw(row);
            updateQueue();
            if (!keys.length) { row.status = 'skipped'; if (row.round) { row.round.skipped++; } draw(row); updateQueue(); pump(); return; }
            var policy = row.dup === 'ask' ? 'skip' : row.dup;
            postFile(row, { UploadZip: 'import', UploadZipMode: zipModeOf(row), UploadZipEntries: JSON.stringify(keys),
                            UploadZipDuplicates: policy, UploadKeepDraft: keepDraft.checked ? '1' : '0' }, progress(row)).then(function (r) {
                if (r.aborted || row.status !== 'uploading') { return; }
                if (!r.ok) { fail(row, r.error); return; }
                var byKey = {};
                (r.data.results || []).forEach(function (res) { byKey[res.key] = res; });
                row.counts = { done: 0, draft: 0, skipped: 0, failed: 0 };
                row.entryRows.forEach(function (e) {
                    var res = byKey[e.entry.key];
                    if (!res) { return; }
                    e.result = res;
                    if (row.counts[res.status] !== undefined) { row.counts[res.status]++; }
                    if (res.item) { addResult(res.item, ''); }
                });
                if (row.round) {
                    row.round.done += row.counts.done + row.counts.draft;
                    row.round.skipped += row.counts.skipped;
                    row.round.failed += row.counts.failed;
                }
                row.status = 'done';
                row.percent = 100;
                draw(row);
                updateQueue();
                pump();
            });
        }

        function fail(row, reason) {
            row.status = 'failed';
            row.reason = reason;
            row.xhr = null;
            if (row.round) { row.round.failed++; }
            draw(row);
            updateQueue();
            pump();
        }

        function finishRound() {
            if (!round) { return; }
            if (rows.some(function (r) { return r.round === round && (active(r) || waiting(r)); })) { return; }
            var r = round;
            round = null;
            rows.forEach(function (row) { if (row.round === r) { row.round = null; } });
            updateQueue();
            if (!r.done && !r.failed && !r.skipped) { return; }
            var text = !r.failed && !r.skipped ? t('summaryOk', { '%done': r.done })
                : t('summary', { '%done': r.done, '%failed': r.failed, '%skipped': r.skipped });
            toast(text, r.failed ? 'warn' : 'ok');
        }

        function cancel(row) {
            if (row.xhr) { var x = row.xhr; row.xhr = null; row.status = 'canceled'; try { x.abort(); } catch (e) { /* gone */ } }
            else if (waiting(row) || row.status === 'checking') { row.status = 'canceled'; }
            row.go = false;
            draw(row);
            updateQueue();
            pump();
        }
        function retry(row) {
            row.percent = 0;
            row.reason = '';
            row.go = false;
            row.autoGo = true;
            row.counts = null;
            recheck(row);
            updateQueue();
            scheduleCheck();
        }
        function removeRow(row) {
            if (active(row)) { return; }
            var i = rows.indexOf(row);
            var next = rows[i + 1] || rows[i - 1] || null;
            if (i !== -1) { rows.splice(i, 1); }
            if (row.tr && row.tr.parentNode) { row.tr.parentNode.removeChild(row.tr); }
            row.entryRows.forEach(function (e) { if (e.tr.parentNode) { e.tr.parentNode.removeChild(e.tr); } });
            if (row.preview && !results.some(function (r) { return r.preview === row.preview; })) { try { URL.revokeObjectURL(row.preview); } catch (e) { /* fine */ } }
            updateQueue();
            // the focus stays in the list: on the next row's remove button, else the chooser
            if (next && next.removeBtn && !next.removeBtn.hidden) { next.removeBtn.focus(); } else { $('exp-mu-pick-files').focus(); }
            pump();
        }

        $('exp-mu-start').addEventListener('click', start);
        $('exp-mu-cancel-all').addEventListener('click', function () { rows.slice().forEach(function (r) { if (active(r) || waiting(r)) { cancel(r); } }); });
        $('exp-mu-retry-all').addEventListener('click', function () { rows.forEach(function (r) { if (r.status === 'failed' || r.status === 'canceled') { retry(r); } }); });
        $('exp-mu-clear').addEventListener('click', function () {
            rows.slice().forEach(function (r) { if (finished(r) || r.status === 'failed' || r.status === 'blocked') { removeRow(r); } });
        });

        // ---- the result list --------------------------------------------------------------------------------------

        function addResult(item, preview) {
            var r = { item: item, preview: preview || '', selected: false };
            results.push(r);
            drawResult(r);
            updateResults();
        }

        function drawResult(r) {
            var item = r.item;
            if (!r.tr) {
                r.tr = el('tr');
                rtbody.appendChild(r.tr);
            }
            var tr = r.tr;
            tr.innerHTML = '';
            var tdSel = el('td');
            var lab = el('label', 'a4-check');
            var cb = r.checkbox = el('input');
            cb.type = 'checkbox';
            cb.checked = r.selected;
            cb.disabled = !!item.draft;
            cb.addEventListener('change', function () { r.selected = cb.checked; updateResults(); });
            lab.appendChild(cb);
            lab.appendChild(el('span', 'a4-sr-only', t('select', { '%name': item.name })));
            tdSel.appendChild(lab);
            tr.appendChild(tdSel);
            var tdPrev = el('td');
            if (r.preview) { var img = el('img', 'exp-mu-thumb'); img.src = r.preview; img.alt = ''; tdPrev.appendChild(img); }
            else { tdPrev.appendChild(icon('files')); }
            tr.appendChild(tdPrev);
            var tdName = el('td');
            var name = item.url ? el('a', 'exp-mu-name', item.name) : el('span', 'exp-mu-name', item.name);
            if (item.url) { name.href = item.url; }
            tdName.appendChild(name);
            if (item.draft) { tdName.appendChild(document.createTextNode(' ')); tdName.appendChild(el('span', 'a4-badge is-warn', t('draftNote'))); }
            tr.appendChild(tdName);
            tr.appendChild(el('td', '', item.className || item['class'] || ''));
            var tdLoc = el('td');
            (item.locations || []).forEach(function (l, i) {
                if (i) { tdLoc.appendChild(el('br')); }
                var a = el('a', '', l.pathText);
                a.href = l.url;
                tdLoc.appendChild(a);
            });
            tr.appendChild(tdLoc);
            var tdAct = el('td', 'a4-end a4-nowrap');
            if (item.url) {
                var v = el('a', 'a4-btn a4-btn-small', t('view'));
                v.href = item.url;
                v.setAttribute('aria-label', t('viewName', { '%name': item.name }));
                tdAct.appendChild(v);
                tdAct.appendChild(document.createTextNode(' '));
            }
            var ed = el('a', 'a4-btn a4-btn-small', item.draft ? t('editDraft') : t('edit'));
            ed.href = item.editUrl;
            ed.setAttribute('aria-label', t('editName', { '%name': item.name }));
            tdAct.appendChild(ed);
            tr.appendChild(tdAct);
        }

        function selected() { return results.filter(function (r) { return r.selected && !r.item.draft; }); }

        function updateResults() {
            $('exp-mu-results-count').textContent = String(results.length);
            $('exp-mu-results-empty').hidden = results.length > 0;
            $('exp-mu-results').parentNode.hidden = results.length === 0;
            var sel = selected();
            ['exp-mu-move', 'exp-mu-place', 'exp-mu-remove'].forEach(function (id) { enable($(id), sel.length > 0); });
            $('exp-mu-selected').textContent = sel.length ? t('selectedCount', { '%count': sel.length }) : '';
            var all = $('exp-mu-select-all');
            var selectable = results.filter(function (r) { return !r.item.draft; });
            all.disabled = !selectable.length;
            all.checked = !!selectable.length && sel.length === selectable.length;
            all.indeterminate = sel.length > 0 && sel.length < selectable.length;
        }
        $('exp-mu-select-all').addEventListener('change', function () {
            var on = this.checked;
            results.forEach(function (r) { if (!r.item.draft) { r.selected = on; if (r.checkbox) { r.checkbox.checked = on; } } });
            updateResults();
        });

        function applyItems(data, verb, opener) {
            var okCount = 0, failed = [];
            (data.items || []).forEach(function (it) {
                var r = results.filter(function (x) { return x.item.nodeId === it.nodeId; })[0];
                if (!r) { return; }
                if (!it.ok) { failed.push(it.error); return; }
                okCount++;
                if (verb === 'remove') {
                    if (r.tr && r.tr.parentNode) { r.tr.parentNode.removeChild(r.tr); }
                    results.splice(results.indexOf(r), 1);
                } else if (it.item) {
                    it.item.className = r.item.className;
                    r.item = it.item;
                    drawResult(r);
                }
            });
            updateResults();
            var where = data.location ? (data.location.pathText || data.location.name) : '';
            if (okCount) {
                toast(t(verb === 'move' ? 'moved' : (verb === 'addlocation' ? 'placed' : 'removed'), { '%count': okCount, '%location': where }), 'ok', opener);
            }
            if (failed.length) { toast(t('actionFailed', { '%count': failed.length, '%reason': failed[0] }), 'bad', opener); }
        }

        function placeAction(verb, opener) {
            var sel = selected();
            if (!sel.length) { return; }
            pick('place', queueLocation.id).then(function (loc) {
                if (!loc) { return; }
                service(verb, { Location: loc.id, NodeIDList: sel.map(function (r) { return r.item.nodeId; }).join(',') }).then(function (data) {
                    if (!data.ok) { toast(data.error, 'bad', opener); return; }
                    applyItems(data, verb, opener);
                });
            });
        }
        $('exp-mu-move').addEventListener('click', function () { placeAction('move', this); });
        $('exp-mu-place').addEventListener('click', function () { placeAction('addlocation', this); });
        $('exp-mu-remove').addEventListener('click', function () {
            var opener = this, sel = selected(), D = dialogs();
            if (!sel.length || !D) { return; }
            var text = sel.length === 1 ? t('removeConfirmOne', { '%name': sel[0].item.name }) : t('removeConfirm', { '%count': sel.length });
            D.confirm(text, { okLabel: t('removeOk'), cancelLabel: t('cancelDialog'), danger: true }).then(function (ok) {
                if (!ok) { return; }
                service('remove', { NodeIDList: sel.map(function (r) { return r.item.nodeId; }).join(',') }).then(function (data) {
                    if (!data.ok) { toast(data.error, 'bad', opener); return; }
                    applyItems(data, 'remove', opener);
                    $('exp-mu-results-title').focus();
                });
            });
        });

        // the page's controls are input type=button like the kernel's control bars; Enter presses them as it presses a
        // <button> (Space already does)
        root.addEventListener('keydown', function (e) {
            var b = e.target;
            if (e.key === 'Enter' && b && b.tagName === 'INPUT' && b.type === 'button' && !b.disabled) { e.preventDefault(); b.click(); }
        });

        showLocation();
        updateQueue();
        updateResults();
        scheduleCheck();
        root.setAttribute('data-exp-mu-state', 'ready');
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}(window, document));
