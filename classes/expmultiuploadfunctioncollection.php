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

    /**
     * fetch( 'ezmultiupload', 'dashboard_target', hash() ): the node id the dashboard's "Upload files" button opens
     * (expMultiuploadService::dashboardTarget(): Media > Images where the user can upload there, else Media); the
     * media root with a service that has no such method yet.
     *
     * @return array { result: int }
     */
    public static function fetchDashboardTarget()
    {
        $service = static::$service;
        if ( class_exists( $service ) && method_exists( $service, 'dashboardTarget' ) )
            return array( 'result' => (int)call_user_func( array( $service, 'dashboardTarget' ) ) );
        return array( 'result' => (int)eZINI::instance( 'content.ini' )->variable( 'NodeSettings', 'MediaRootNode' ) );
    }

    /**
     * fetch( 'ezmultiupload', 'quick_locations', hash( 'node_id', <id> ) ): the quick location buttons of the upload
     * page for the target at the node (expMultiuploadService::quickLocations()). A node that is not there or not
     * readable, and an installation without them, give no buttons.
     *
     * @param mixed $nodeID
     * @return array { result: hash( presets, rows ) }
     */
    public static function fetchQuickLocations( $nodeID )
    {
        $none = array( 'result' => array( 'presets' => array(), 'rows' => array() ) );
        $service = static::$service;
        if ( !class_exists( $service ) || !method_exists( $service, 'quickLocations' ) )
            return $none;
        $id = is_int( $nodeID ) ? $nodeID : ( is_string( $nodeID ) && ctype_digit( $nodeID ) ? (int)$nodeID : 0 );
        $node = $id > 0 ? eZContentObjectTreeNode::fetch( $id ) : null;
        if ( !$node instanceof eZContentObjectTreeNode || !$node->canRead() )
            return $none;
        return array( 'result' => call_user_func( array( $service, 'quickLocations' ), $node ) );
    }
}

?>
