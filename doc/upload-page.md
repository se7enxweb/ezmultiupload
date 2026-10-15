# The upload page (ezmultiupload 6.1)

`/<admin siteaccess>/ezmultiupload/upload/<node id>` uploads several files at once. Each file becomes an item of the
content class its type is mapped to in `upload.ini [CreateSettings] MimeClassMap[]` (images: `image`; anything
else: `DefaultClass`, usually `file`). The administration designs admin4, admin4l and adminui draw the new page; the
classic `design/admin` keeps the lighter page of 6.0, which works with the same server.

The content tree's context menu and the sub-items menu open it with "Upload multiple files" on any node.

## What the page does

- **Target location**: the node of the address, shown with its path and whether files can be uploaded there.
  *Change location...* opens a location picker (breadcrumbs, a filter, the containers below, each with the reason
  when it cannot take files). The last location chosen is the eZPreference `exp_multiupload_location` and is offered
  again as *Use the last location*. A location that cannot take files (an item that is not a container, a location
  without create access) shows the reason and offers the nearest location above that can. Each queue row can have
  a location of its own.
- **Adding files**: *Choose files...*, *Choose a folder...* (where the browser supports it), drag and drop of files
  and folders, and images pasted from the clipboard.
- **The queue**: per file the name, size and type, a preview of an image, the content class it becomes, its
  location, an editable title (the file name by default) and, for images, the alternative text (required where the
  class requires it, #425 L16), a progress bar and the status (checking, queued, uploading, done, failed with the
  reason, not allowed with the reason). Cancel per file and for all, retry the failed ones, remove a row before the
  upload. Three files are sent at a time; one round ends with a toast: "5 files uploaded." or the counts.
- **Before anything is sent** the server checks every row (the content class, the create permission for that
  class there, the type list `[FileTypeSettings_*]`, the size limits, the alternative text): a row that would fail
  says why and is not sent. A file with the same name and size already in the location is marked; *If it exists*
  offers Ask, Skip, Rename (to the first free `name-2.ext`) or Upload anyway, and *Upload all* asks once for the
  rows still on Ask.
- **SVG images** (with the kernel's SVG support, 6.0.15): an SVG becomes an Image through `MimeClassMap[image]`, the
  kernel sanitizes it when it is stored, galleries take `*.svg` and `*.svgz`, and its preview is an `<img>` of the
  stored file after the upload (never of the uploaded file, and never inline markup).
- **Keep as drafts**: the new items are not published; they wait in My drafts (the kernel's upload with
  `publish = false`).
- **After the upload**: the list of what was made with View and Edit. Selected items can be moved (*Move to...*),
  placed in a further location (*Add location...*) or moved to the trash (*Remove...*, after a confirmation), through
  the kernel's content operations `move`, `addlocation` and `delete` and their permission checks.

## ZIP files

*Import compressed zip file(s) contents* is off by default (eZPreference `exp_multiupload_zip_import`): a ZIP file is
then uploaded as one File item, unchanged, after the normal type and size checks. Turned on (for the whole queue,
or per ZIP row), the ZIP file is read on the server and its row lists the entries: name, size, the class each
becomes, or why it is skipped. Entries can be unticked before the import. *Folders in ZIP files* (eZPreference
`exp_multiupload_zip_mode`): **Keep the folder structure** makes a folder item (`[ZipSettings] FolderClass`) for each
folder of the archive below the location, after the create permission check per level, and a ZIP file inside the
archive is a folder of its name; **Flatten** puts every file into the location.

Limits and safety (`ezmultiupload.ini [ZipSettings]`):

| Setting | Default | |
|---|---|---|
| `ZipImport` | enabled | disabled: ZIP files are always uploaded as files |
| `MaxDepth` | 3 | ZIP files inside ZIP files; a deeper one is skipped |
| `MaxEntries` | 1000 | over all levels; more refuses the archive |
| `MaxTotalSize` | 500M | uncompressed, over all levels; more refuses the archive |
| `MaxCompressionRatio`, `CompressionRatioMinSize` | 100, 1M | an entry of at least 1 MB compressed more than 100 times refuses the archive (a ZIP bomb) |
| `MaxPathDepth` | 16 | folders inside an archive |
| `SkipNames[]`, `SkipHidden` | `__MACOSX`, `.DS_Store`, `Thumbs.db`, `desktop.ini`; enabled | left out silently |

An encrypted archive is refused with a message. Entries with an absolute path, a `..` part or a NUL, symbolic links
and devices are skipped as unsafe. Names that are not UTF-8 are read as CP437 (the ZIP default) or Latin-1. Every
entry passes the checks of a single upload: the type list, the size limit, an image by its content (L05), the
create permission where it goes; an image whose class requires an alternative text is skipped (a ZIP file has no
field for it). The archive is unpacked into a private directory of the request,
`var/<site>/ezmultiupload-zip-<user id>/<random>` (mode 0700, one base directory per system user, since Velocity and
PHP-FPM may run as different users), under names made by the server, and removed when the request ends, also after
a failure. Nothing in an archive is run.

## Settings

`ezmultiupload.ini [MultiUploadSettings]`:

- `AnyContainer=enabled` (new): every container the user may create content in takes uploads. Sites whose
  containers are not folders (the media site's `ng_frontpage`, `ng_category`, `ng_landing_page`) work out of the
  box. `disabled` keeps the 6.0 rule: only `AvailableClasses[]` (folder, gallery) and `AvailableSubtreeNode[]`.
- `MaxFileSize=64M` (new): the largest file; PHP's `upload_max_filesize` and `post_max_size` apply as well, the
  smallest is the limit. The page refuses a larger file before it is sent and the server again.

## Security

- Every request that changes something is a POST with the session's form token (`ezxform_token`, checked by the
  form token extension and again by `expMultiuploadService::requestAllowed()`, constant-time), never one sent from
  another site (`Sec-Fetch-Site`). The token is the session's, so it is the same on every server and worker.
- Node ids are positive integers (`positiveId()`, `idList()`, at most 100 per request); anything else is refused.
- The checks and actions are the view `ezmultiupload/service` (POST only, JSON): `check`, `browse`, `move`,
  `addlocation`, `remove`. Uploads stay `ezmultiupload/upload/<node>`; the location, the class's create permission,
  the size and type and the alternative text are checked on the server before the kernel's own upload checks.
- All text from the server is set as text in the page, never as markup; the configuration is JSON escaped for HTML.

## Code

- `classes/expmultiuploadservice.php` — the checks and actions (`expMultiuploadService`).
- `classes/expmultiuploadzip.php` — reading ZIP files (`expMultiuploadZip`).
- `classes/runnable/views/ezmultiupload/upload.php`, `service.php` — the views.
- `design/admin4/templates/ezmultiupload/upload.tpl`, `upload_body.tpl` — the page, in the frame of the content
  action pages (box header, box content, control bar with the main action first) with the shared controls.
- `design/standard/javascript/expmultiupload.js`, `design/standard/stylesheets/expmultiupload.css` (the drop zone).
- Tests (Exponential's root): `tests/tests/extension/ezmultiupload/` (PHPUnit, no database).
