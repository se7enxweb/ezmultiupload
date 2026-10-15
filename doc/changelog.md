# ezmultiupload changelog

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
