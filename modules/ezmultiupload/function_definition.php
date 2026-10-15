<?php
/**
 * The template fetch functions of ezmultiupload.
 *
 *   fetch( 'ezmultiupload', 'can_upload', hash( 'node_id', $node.node_id ) )
 *     true when the current user can upload files at that location (expMultiuploadService::canUploadAt()): the
 *     ezmultiupload/upload policy, the location exists and is read, content/create there, and it is a container or
 *     one of [MultiUploadSettings] AvailableClasses[] / AvailableSubtreeNode[]. For a link or button to
 *     ezmultiupload/upload/<node id>; the upload page and every upload check again.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezmultiupload
 */

$FunctionList = array();

$FunctionList['can_upload'] = array( 'name' => 'can_upload',
                                     'operation_types' => array( 'read' ),
                                     'call_method' => array( 'include_file' => 'extension/ezmultiupload/classes/expmultiuploadfunctioncollection.php',
                                                             'class' => 'expMultiuploadFunctionCollection',
                                                             'method' => 'fetchCanUpload' ),
                                     'parameter_type' => 'standard',
                                     'parameters' => array( array( 'name' => 'node_id',
                                                                   'type' => 'integer',
                                                                   'required' => true ) ) );

?>
