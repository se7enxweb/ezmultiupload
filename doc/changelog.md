# ezmultiupload changelog

## 6.1.7 (2026-10-17)

- Updated: The upload page of the classic designs (design/standard `ezmultiupload/upload.tpl`) runs from a file,
  `ezmultiupload_upload.js`, with its configuration in the JSON of the drop zone's `data-ezmultiupload-conf`, without
  an inline script, so it works under a Content Security Policy without `unsafe-inline`.
- Updated: The "Upload multiple files" entry of the context menus works with an older `ezpopupmenu.js` again:
  `ezmultiupload_menu.js` adds it to `menuArray` (to the menu that is there, never assigning `menuArray` anew) and
  closes an open submenu when pointed at, without inline code.
- Added: `tests/expMultiuploadClassicUploadTemplateTest.php` (CU-01 to CU-03), without a database.

## 6.1.6 (2026-10-16)

- Updated: The "Upload multiple files" entry of the content tree and sub-items context menus is declared in
  `data-menu-url`, without an inline script or event handler attributes (pull request #6), so it works on an
  administration page under a Content Security Policy without `unsafe-inline`. It needs the declared menus of
  `ezpopupmenu.js` in the administration design (Exponential 6.0.15 with the content security policy change, or
  exp_adminui 1.0.0.36); with an older `ezpopupmenu.js` the entry does not lead anywhere.
- Added: `tests/expMultiuploadContextMenuTemplatesTest.php` (CM-01, CM-02), without a database.

## 6.1.4 (2026-10-15)

- Fixed: A ZIP import with *Keep the folder structure* makes the folder items of an entry only when the entry passes
  the checks that do not depend on the location (type list, size, image content, alternative text); an entry
  refused by one of them left an empty folder behind.

## 6.1.3 (2026-10-15)

The service and the ZIP importer check access and limits more strictly (pull request #5), with two further checks
of the location actions.

- Fixed: *Remove* asks, as the kernel's remove view does, whether the user may remove every location of the item
  with everything below it (`can_remove_all`), and refuses an item with items below it; it checked the item's own
  locations only.
- Fixed: *Move*, *Add location* and *Remove* refuse an item with a top-level location, and the root node as a
  target.

- Fixed: A location the user cannot read is answered by the service like one that does not exist: no name, no path
  and the same message (`check`, and the reason of `uploadTarget()`).
- Fixed: Paths and the location picker's path leave out the locations above that the user cannot read; an
  uploaded item lists only the locations the user can read.
- Fixed: *Move*, *Add location* and *Remove* answer an item the user cannot read as one that does not exist.
- Fixed: Without a form token in use, the service and the upload take a request only when the browser says it
  comes from the same origin (`Sec-Fetch-Site`, or the `Origin` header where the browser sends no
  `Sec-Fetch-Site`).
- Fixed: The upload page answers a location the user cannot read as one that does not exist.
- Fixed: The ZIP files inside an archive are unpacked within `MaxTotalSize` together, over all levels; past
  `MaxEntries` the walk stops on every level; `extract()` removes the directory it made when it fails, and a
  directory left behind by a request that died is removed by a later import after an hour.

## 6.1.2 (2026-10-15)

- Added: A template fetch function, `fetch( 'ezmultiupload', 'can_upload', hash( 'node_id', $node_id ) )`, true
  when the current user can upload files at the node (`expMultiuploadService::canUploadAt()`). The
  administration's node view uses it for its Upload button. Documented in `doc/fetch-functions.md`.

## 6.1.1 (2026-10-15)

- Updated: The create permission checks read a node's class id from its object (6.1.0 logged "Attribute
  'contentclass_id' does not exist" on the upload page and checked the parent class as 0).
- Updated: The options under the drop zone have the inner padding of a card body and space above them.

## 6.1.0 (2026-10-15)

The upload page is new in admin4, admin4l and adminui; the classic `design/admin` keeps the 6.0 page, which works
with the same server. Guide: `doc/upload-page.md`.

- Added: The page in the frame of the content action pages (box header, box content, control bar with the main
  action first) with the administration's shared controls; light and dark mode.
- Added: The target location with its path; *Change location...* opens a location picker; a queue row can have a
  location of its own; the last location is the eZPreference `exp_multiupload_location`.
- Added: Files, folders, drag and drop and pasted images; per file a preview, the class it becomes, an editable
  title, the alternative text of an image (required where the class asks), progress and status; cancel, retry and
  remove per row and for all; three uploads at a time; a summary toast.
- Added: Checks before anything is sent (`ezmultiupload/service`, Action=check): the create permission for the class
  in the location, the type list, the size limit (new `MaxFileSize=64M` and PHP's limits), the alternative text, and
  a file of the same name and size already there (skip, rename or upload anyway).
- Added: *Keep as drafts*; after the upload view and edit links, and *Move to...*, *Add location...* and
  *Remove...* (to the trash, confirmed) through the kernel's content operations and their permission checks.
- Added: *Import compressed zip file(s) contents* (off by default, eZPreference `exp_multiupload_zip_import`; off, a
  ZIP file is one File item): the entries are listed, can be unticked, and are imported with the folders kept as
  folder items or flattened (eZPreference `exp_multiupload_zip_mode`).
- Added: `expMultiuploadZipImporter`, a ZIP import backend with `inspect()`, `extract()` and `cleanup()`: entries by
  content type and class, nested ZIP files to `MaxDepth`, unsafe paths, links, devices, encrypted entries, ZIP bombs
  and disallowed types skipped with a reason, extraction with a byte budget into a private directory per call;
  `[ZipSettings]` gains `MaxFileSize`, `AllowedMIMETypes[]` and `DeniedExtensions[]` (see `doc/upload-page.md`,
  "ZIP import").
- Added: SVG images in galleries (`*.svg`, `*.svgz`), sanitized by the kernel, previewed from the stored file.
- Added: `expMultiuploadService::canUploadAt()`, one check whether the current user can upload at a node.
- Updated: "Upload multiple files" in the content tree works on every container (new `AnyContainer=enabled`); it used
  to end in the kernel's "object is not available" error on any location that is not a folder or gallery. A location
  that cannot take files shows the reason and offers the nearest one above. `AnyContainer=disabled` keeps the 6.0 rule.
- Updated: Every request that changes something is a POST with the session's form token (checked again in constant
  time), never accepted from another site; node ids are positive integers only.
- Updated: English and German texts for the new page.
