{* Add an "Upload multiple files" item in Subitems context menu, declared in data-menu-url (no inline script);
   ezmultiupload_menu.js adds it to menuArray for an older ezpopupmenu.js *}
<hr/>
<a id="child-menu-multiupload" data-menu-url={"/ezmultiupload/upload/%nodeID%"|ezurl} href="#">{"Upload multiple files"|i18n("design/admin/popupmenu")}</a>

{ezscript( array( 'ezmultiupload_menu.js' ) )}