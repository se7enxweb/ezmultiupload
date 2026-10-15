# ezmultiupload changelog

## Unreleased

- Added: `expMultiuploadZipImporter`, a ZIP import backend with `inspect()`, `extract()` and `cleanup()`: entries by content type and class, nested ZIP files to `MaxDepth`, unsafe paths, links, devices, encrypted entries, ZIP bombs and disallowed types skipped with a reason, extraction with a byte budget into a private directory per call; `[ZipSettings]` gains `MaxFileSize`, `AllowedMIMETypes[]` and `DeniedExtensions[]` (see `doc/upload-page.md`, "ZIP import").
