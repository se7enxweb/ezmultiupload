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
- **Quick locations** (6.1.8): under the target, one-click buttons for the well-known places: **Websites** and
  **Media** first, then a row with the locations right below each (the sites; Images, Files, Multimedia and the
  others under Media), in the tree's order. A click makes the place the target at once, as the picker does (the
  hidden field `exp-mu-location-id`, the shown path, the remembered location); the target's button is pressed
  (`aria-pressed`), the place it lies in is outlined and says so to a screen reader. They are found from the content
  tree and content.ini (the kernel's `expNodeQuickPick`, Exponential 6.0.15), never from fixed node ids, and only
  locations where the user can read, create and make at least one of the classes uploaded files become
  (`upload.ini MimeClassMap[]`, `DefaultClass`) are offered. *More...* opens the picker at a place with more
  locations than the row shows. *Change location...* is unchanged. The classic page of `design/admin` has the same
  buttons (without *More...*); its next files go to the location chosen.
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
field for it). The page reads and unpacks archives through the ZIP import backend described below
(`expMultiuploadZipImporter`): its private directory is removed when the request ends, also after a failure, and
nothing in an archive is run.

## ZIP import

`expMultiuploadZipImporter` (`classes/expmultiuploadzipimporter.php`) is the import backend: a class with no
database, no session and no state between calls, so it can be called from a view, a cronjob or a test alike.

```php
$importer = new expMultiuploadZipImporter();               // options over ezmultiupload.ini [ZipSettings]
$list = $importer->inspect( $zipPath );                    // ok, error, message, refused[], totalBytes, entries[]
$out = $importer->extract( $zipPath, $chosenPaths, '' );   // '' = a new private directory under var/<site>/
try
{
    foreach ( $out['files'] as $file )                     // path (absolute), relativePath, size, mime, class
    {
        // create the content object from $file['path']
    }
}
finally
{
    $importer->cleanup( $out['tmpDir'] );                  // safe to call twice
}
```

- `inspect()` lists every entry, also those of ZIP files inside, with `name`, `path` (UTF-8, inside the archive;
  `inner.zip/a.jpg` for an entry of a nested ZIP file), `size`, `compressedSize`, `mime` (by content: libmagic on the
  entry's first 64 KB), `class` (upload.ini `MimeClassMap[]`, as the upload page), `depth` (1 for the uploaded
  archive), `nested`, `status` (`ok` or `skipped`) and `reason`: `type_not_allowed`, `too_big`, `unsafe_path`,
  `symlink`, `device`, `too_deep`, `encrypted`, `ratio_exceeded`, `too_many_entries`, `total_size_exceeded`,
  `system_file`, `unreadable`. An archive that cannot be used at all has `ok` false and `error`: `disabled`,
  `zip_extension_missing` (ext-zip is required), `not_found`, `invalid_zip`, `temp_dir`.
- `extract()` writes the chosen entries that pass every check (checked again, never taken from the caller) under
  their path into the directory and returns them; a nested ZIP file's path chooses everything in it. A chosen path
  that is skipped or not in the archive is in `skipped` with its reason.
- `cleanup()` removes the directory recursively, links without following them.

Limits (options: `maxDepth`, `maxEntries`, `maxTotalBytes`, `maxRatio`, `ratioMinSize`, `maxFileSize`,
`maxPathDepth`, `allowedMimeTypes`, `deniedExtensions`, `skipNames`, `skipHidden`, `classMap`, `enabled`):

| Setting | Default | |
|---|---|---|
| `ZipImport` | enabled | disabled: `inspect()` and `extract()` answer `disabled` |
| `MaxDepth` | 3 | the uploaded archive is 1; a ZIP file that would open level 4 is skipped `too_deep` |
| `MaxEntries` | 1000 | over all levels; the entries over it are skipped `too_many_entries`, and the walk stops after as many again |
| `MaxTotalSize` | 500M | uncompressed, over all levels, lowered to PHP's `upload_max_filesize`/`post_max_size`; files over it are skipped `total_size_exceeded`. The ZIP files inside the archive are unpacked within a budget of the same size, over all levels; one past it is skipped `total_size_exceeded` and not opened |
| `MaxCompressionRatio`, `CompressionRatioMinSize` | 100, 1M | an entry of at least 1 MB compressed more than 100 times is skipped `ratio_exceeded` |
| `MaxFileSize` | empty: `[MultiUploadSettings] MaxFileSize` | per file, with PHP's upload limits; skipped `too_big` |
| `AllowedMIMETypes[]` | image.ini's image types (with SVG) and PDF, text, CSV, RTF, Office, OpenDocument, audio, video | by content; `group/*` for a group |
| `DeniedExtensions[]` | php, phtml, phar, exe, sh, js, html and more | never taken, whatever the content |

Safety rules:

- Nothing is written outside the directory: paths with `..`, an absolute path, a drive letter (`C:\`) or a NUL are
  skipped; every parent directory is checked with `realpath()` against the directory before a file is written, and
  files are opened with `x`, so an existing file or link is never written through.
- Symbolic links and devices (Unix mode bits of the entry) are never extracted; encrypted entries are refused
  (`statIndex()` `encryption_method`).
- `__MACOSX/`, `.DS_Store`, `Thumbs.db`, `desktop.ini` and hidden files are skipped as `system_file`.
- The type is found from the content, not the name; an extension can only refine what content cannot tell apart
  (a `.docx` that libmagic calls a ZIP file), never to an image type. PHP code in a `.txt` file is `text/x-php` and
  skipped.
- Entries are copied from their stream in 64 KB chunks with a running byte budget: the file stops at the size the
  archive declares, the per-file limit and what is left of the total, so a lying size header cannot write more.
- Names that are not UTF-8 are converted (CP437 when a byte 0x80-0xA5 is in them, else ISO-8859-1) and NFC
  normalized; control characters are removed.
- Directories are 0700 and files 0600, under `var/<site>/expmultiupload-zip-import-<system user id>/<random>`: one
  base directory per system user, as Velocity's workers (root) and PHP-FPM (the site user) may differ, and a new
  directory per call, so persistent workers share nothing between requests. Nested ZIP files are unpacked into a
  scratch directory of the call and removed with it.

Tests: `php vendor/bin/phpunit extension/ezmultiupload/tests/expMultiuploadZipImporterTest.php` (from Exponential's
root; the archives are made by the test, no database).

## Settings

`ezmultiupload.ini [MultiUploadSettings]`:

- `AnyContainer=enabled` (new): every container the user may create content in takes uploads. Sites whose
  containers are not folders (the media site's `ng_frontpage`, `ng_category`, `ng_landing_page`) work out of the
  box. `disabled` keeps the 6.0 rule: only `AvailableClasses[]` (folder, gallery) and `AvailableSubtreeNode[]`.
- `MaxFileSize=64M` (new): the largest file; PHP's `upload_max_filesize` and `post_max_size` apply as well, the
  smallest is the limit. The page refuses a larger file before it is sent and the server again.

`ezmultiupload.ini [QuickLocationSettings]` (6.1.8):

| Setting | Default | |
|---|---|---|
| `QuickLocations` | enabled | disabled: no quick location buttons |
| `PresetList[]` | content, media | the first row; a key is `content` (content.ini `RootNode`), `media` (`MediaRootNode`), `users`, `setup`, `design`, a node id, `remote:<node remote id>` or a URL alias path |
| `ChildRowList[]` | content, media | the places that get a row of the locations right below them |
| `ChildRowLimit` | 12 | buttons in a row (1 to 50); with more, *More...* |

The buttons are `design:ezmultiupload/quick_locations.tpl` (design/admin, used by admin, admin4 and admin4l; an empty
file in design/standard) from the fetch function `quick_locations` (`doc/fetch-functions.md`), drawn with the shared
component `.a4-quickpick` of the administration's `components.css`.

## For other pages

`expMultiuploadService::canUploadAt( $nodeIdOrNode )` says whether the current user can upload at a node (the module
policy, read and create access, an upload location); `uploadTarget( $node )` gives the reason when not. Use it for a
link or button to the upload page.

## Security

- Every request that changes something is a POST with the session's form token (`ezxform_token`, checked by the
  form token extension and again by `expMultiuploadService::requestAllowed()`, constant-time), never one sent from
  another site (`Sec-Fetch-Site`). The token is the session's, so it is the same on every server and worker.
  Where no form token is in use (the form token extension is not active, or the user is anonymous), only a request
  whose browser sends `Sec-Fetch-Site: same-origin` (or `none`) is taken; a browser that sends no `Sec-Fetch-Site`
  (plain HTTP, older browsers) must send an `Origin` of this host. A client that sends neither is refused.
- A location or an item the user cannot read is answered as one that does not exist: no name, no path, the same
  message. Paths and the picker's path leave out the locations above that the user cannot read, and an uploaded
  item lists only the locations the user can read. `ezmultiupload/upload/<node>` answers a location the user cannot
  read with "not found", as one that does not exist.
- A request that ends without its own cleanup (time or memory limit) leaves its ZIP directory behind; the next ZIP
  import removes such directories once they are older than an hour.
- *Remove* asks what the kernel's remove view asks: every location of the item, with everything below it, must be
  removable (`subtreeRemovalInformation()`, `can_remove_all`). An item with anything below it is refused ("This item
  has items below it"): the upload page manages files, and a subtree is removed in the content structure, with its
  confirmation.
- *Move*, *Add location* and *Remove* refuse an item with a top-level location (its parent is the root node: the
  content root, Media, Users ...), and the root node is no target.
- Node ids are positive integers (`positiveId()`, `idList()`, at most 100 per request); anything else is refused.
- The checks and actions are the view `ezmultiupload/service` (POST only, JSON): `check`, `browse`, `move`,
  `addlocation`, `remove`. Uploads stay `ezmultiupload/upload/<node>`; the location, the class's create permission,
  the size and type and the alternative text are checked on the server before the kernel's own upload checks.
- All text from the server is set as text in the page, never as markup; the configuration is JSON escaped for HTML.

## Code

- `classes/expmultiuploadservice.php` — the checks and actions (`expMultiuploadService`).
- `classes/expmultiuploadzipimporter.php` — the ZIP import backend (`expMultiuploadZipImporter`), tested by
  `tests/expMultiuploadZipImporterTest.php` in this extension; `tests/expMultiuploadServiceAccessTest.php` tests
  the answers of the service for locations and items the user cannot read (both run without the kernel).
- `classes/runnable/views/ezmultiupload/upload.php`, `service.php` — the views.
- `design/admin4/templates/ezmultiupload/upload.tpl`, `upload_body.tpl` — the page, in the frame of the content
  action pages (box header, box content, control bar with the main action first) with the shared controls.
- `design/standard/javascript/expmultiupload.js`, `design/standard/stylesheets/expmultiupload.css` (the drop zone).
- `design/admin/templates/ezmultiupload/quick_locations.tpl` — the quick location buttons (both pages).
- Tests (Exponential's root): `tests/tests/extension/ezmultiupload/` (PHPUnit, no database), the quick locations in
  `expMultiuploadQuickLocationsTest.php` (QL-01 to QL-05).
