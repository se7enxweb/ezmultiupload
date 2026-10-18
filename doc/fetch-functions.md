# Template fetch functions of ezmultiupload

`modules/ezmultiupload/function_definition.php`, answered by `classes/expmultiuploadfunctioncollection.php`.

## can_upload

```
{if fetch( 'ezmultiupload', 'can_upload', hash( 'node_id', $node.node_id ) )}
    <a href={concat( 'ezmultiupload/upload/', $node.node_id )|ezurl}>{'Upload'|i18n( 'design/admin/node/view/full' )}</a>
{/if}
```

True when the current user can upload files at the node, as `expMultiuploadService::canUploadAt()` decides:

- the user has the `ezmultiupload/upload` policy;
- the node exists and the user can read it and create content there (`content/create`);
- the node is a container (`[MultiUploadSettings] AnyContainer=enabled`, the default), or its class is one of
  `AvailableClasses[]`, or its node id one of `AvailableSubtreeNode[]`.

Anything but a positive node id is false. The upload page and every upload check again, including the create
permission for the class each file becomes, so the fetch is for showing a link or a button, not for granting access.

Guard the fetch with `ezmodule( 'ezmultiupload/upload' )` in a template that also runs where the extension may be
disabled: the fetch function exists only while the extension is active.

The administration's node view uses it for its Upload button (`design/admin/templates/node/view/upload_button.tpl`
in the kernel, and exp_adminui's `window_preview_toolbar.tpl`).

## quick_locations

```
{def $quick = fetch( 'ezmultiupload', 'quick_locations', hash( 'node_id', $parent_node.node_id ) )}
```

The quick location buttons of the upload page for the target at that node (`expMultiuploadService::quickLocations()`,
drawn by `design:ezmultiupload/quick_locations.tpl`): `presets` (Websites, Media) and `rows` (each with `key`, `label`,
`parent_id`, `items`, `more`: the locations right below a place). A button has `node_id`, `label`, `path`, `current`
(the target), `within` (the target lies below it) and `location` (the location as JSON for the page's script). Only
locations the user can read and upload files of an uploaded class to are listed. Without the kernel's
`expNodeQuickPick` (Exponential 6.0.15), with `[QuickLocationSettings] QuickLocations=disabled`, or for a node that is
not there or not readable, both lists are empty.

## dashboard_target

```
{def $upload_node = fetch( 'ezmultiupload', 'dashboard_target', hash() )}
```

The node id the dashboard's "Upload files" button opens: the first place of `ezmultiupload.ini [DashboardSettings]
UploadTargetList[]` (default Media > Images, then Media) the current user can read and upload files of an uploaded
class to, else the media root (content.ini `MediaRootNode`).
