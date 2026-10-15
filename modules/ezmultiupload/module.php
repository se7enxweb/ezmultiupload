<?php
/**
 * File containing the eZ Publish module definition.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 * @version 1.0.0
 * @package ezmultiupload
 */

$Module = array( 'name' => 'eZ Multiupload', 'variable_params' => true );

$ViewList = array();
$ViewList['upload'] = array( 'script' => 'upload.php',
                             'single_post_actions' => array( 'UploadButton' => 'Upload' ),
                             'params' => array( 'ParentNodeID' ) );

// the checks and actions of the upload page (POST only, JSON): extension/ezmultiupload/classes/runnable/views/ezmultiupload/service.php
$ViewList['service'] = array( 'script' => 'service.php',
                              'params' => array() );

?>
