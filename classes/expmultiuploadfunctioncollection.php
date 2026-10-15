<?php
/**
 * The template fetch functions of ezmultiupload (modules/ezmultiupload/function_definition.php).
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezmultiupload
 */

class expMultiuploadFunctionCollection
{
    /**
     * The class that answers the check (a test puts a stand-in here).
     *
     * @var string
     */
    public static $service = 'expMultiuploadService';

    /**
     * fetch( 'ezmultiupload', 'can_upload', hash( 'node_id', <id> ) ): whether the current user can upload files at
     * the node, as expMultiuploadService::canUploadAt() decides. Anything but a positive node id is false, and so is
     * an installation whose service has no such check yet.
     *
     * @param mixed $nodeID
     * @return array { result: bool }
     */
    public static function fetchCanUpload( $nodeID )
    {
        $service = static::$service;
        if ( !class_exists( $service ) || !method_exists( $service, 'canUploadAt' ) )
            return array( 'result' => false );
        $id = is_int( $nodeID ) ? $nodeID : ( is_string( $nodeID ) && ctype_digit( $nodeID ) ? (int)$nodeID : 0 );
        if ( $id < 1 )
            return array( 'result' => false );
        return array( 'result' => (bool)call_user_func( array( $service, 'canUploadAt' ), $id ) );
    }
}

?>
