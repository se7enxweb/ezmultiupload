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
