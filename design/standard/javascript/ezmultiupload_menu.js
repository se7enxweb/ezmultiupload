/**
 * ezmultiupload: the "Upload multiple files" entry of the content tree and sub-items context menus
 * (node/ezmucontextmenu.tpl, node/ezmusubitemscontextmenu.tpl).
 *
 * The entries declare their address in data-menu-url, which the declared menus of ezpopupmenu.js read (Exponential
 * 6.0.15 and later). For an older ezpopupmenu.js, which reads the addresses from menuArray only, this file adds the
 * entry to menuArray (to the menu that is already there, never assigning menuArray anew) and lets pointing at the
 * entry close an open submenu (ezpopmenu_mouseOver), as the inline script and onmouseover attribute did before. Both
 * templates print this file: it sets itself up once and adds each entry once. No inline code, so the menus work
 * under a Content Security Policy without 'unsafe-inline'.
 */
(function (window, document) {
    var ENTRIES = { 'menu-multiupload': 'ContextMenu', 'child-menu-multiupload': 'SubitemsContextMenu' };

    function register() {
        var menus = window.menuArray;
        Object.keys(ENTRIES).forEach(function (id) {
            var link = document.getElementById(id), menu = ENTRIES[id];
            if (!link || !menus || !menus[menu] || !menus[menu].elements || menus[menu].elements[id]) { return; }
            menus[menu].elements[id] = { url: link.getAttribute('data-menu-url') };
        });
    }

    register();
    if (window.ezmultiuploadMenuReady) { return; }
    window.ezmultiuploadMenuReady = true;
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', register); }

    document.addEventListener('mouseover', function (e) {
        var link = e.target && e.target.closest ? e.target.closest('#menu-multiupload, #child-menu-multiupload') : null;
        if (link && typeof window.ezpopmenu_mouseOver === 'function') { window.ezpopmenu_mouseOver(ENTRIES[link.id]); }
    });
}(window, document));
