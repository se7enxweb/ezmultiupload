<?php
/**
 * ezmultiupload/service: the checks and actions of the upload page, answered as JSON. POST only, with the
 * session's form token, never from another site. Action (a POST field):
 *
 *   check        Location, Files (JSON: [{ name, size }]): the target and per file the class, the create
 *                permission, size and type limits, the required alternative text and files that are there already
 *   browse       Location, Mode (upload|place): the containers below a location for the location picker
 *   move         NodeIDList (comma-separated), Location: moves the items there
 *   addlocation  NodeIDList, Location: adds the location to the items
 *   remove       NodeIDList: moves the items to the trash
 *
 * Node ids are positive integers (expMultiuploadService::positiveId(), idList()); everything else is refused.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezmultiupload
 */

namespace Exponential\View\Extension\Ezmultiupload\Ezmultiupload
{

class Service extends \Exponential\Runnable\ModuleView
{
    public function run( array $scope )
    {
        $http = \eZHTTPTool::instance();
        $post = function ( $name ) use ( $http ) { return $http->hasPostVariable( $name ) ? $http->postVariable( $name ) : null; };

        $allowed = \expMultiuploadService::requestAllowed( \eZSys::serverVariable( 'REQUEST_METHOD', true ),
                                                          \eZSys::serverVariable( 'HTTP_SEC_FETCH_SITE', true ),
                                                          $post( 'ezxform_token' ),
                                                          \eZSys::serverVariable( 'HTTP_ORIGIN', true ),
                                                          \eZSys::serverVariable( 'HTTP_HOST', true ) );
        if ( $allowed !== true )
            return self::answer( array( 'ok' => false, 'error' => $allowed ), strtoupper( (string)\eZSys::serverVariable( 'REQUEST_METHOD', true ) ) === 'POST' ? 403 : 405 );

        $action = is_string( $post( 'Action' ) ) ? $post( 'Action' ) : '';
        $badId = \ezpI18n::tr( 'extension/ezmultiupload', 'The request names no valid location or item.' );
        switch ( $action )
        {
            case 'check':
                $location = \expMultiuploadService::positiveId( (string)$post( 'Location' ) );
                $files = is_string( $post( 'Files' ) ) ? json_decode( $post( 'Files' ), true ) : null;
                if ( !$location || !is_array( $files ) )
                    return self::answer( array( 'ok' => false, 'error' => $badId ), 400 );
                return self::answer( \expMultiuploadService::check( $location, $files ) );

            case 'browse':
                $location = \expMultiuploadService::positiveId( (string)$post( 'Location' ) );
                if ( !$location )
                    return self::answer( array( 'ok' => false, 'error' => $badId ), 400 );
                return self::answer( \expMultiuploadService::browse( $location, $post( 'Mode' ) === 'place' ? 'place' : 'upload' ) );

            case 'move':
            case 'addlocation':
                $location = \expMultiuploadService::positiveId( (string)$post( 'Location' ) );
                $ids = \expMultiuploadService::idList( is_string( $post( 'NodeIDList' ) ) ? $post( 'NodeIDList' ) : '' );
                if ( !$location || !$ids )
                    return self::answer( array( 'ok' => false, 'error' => $badId ), 400 );
                return self::answer( $action === 'move' ? \expMultiuploadService::move( $ids, $location ) : \expMultiuploadService::addLocation( $ids, $location ) );

            case 'remove':
                $ids = \expMultiuploadService::idList( is_string( $post( 'NodeIDList' ) ) ? $post( 'NodeIDList' ) : '' );
                if ( !$ids )
                    return self::answer( array( 'ok' => false, 'error' => $badId ), 400 );
                return self::answer( \expMultiuploadService::remove( $ids ) );
        }
        return self::answer( array( 'ok' => false, 'error' => \ezpI18n::tr( 'extension/ezmultiupload', 'Unknown action.' ) ), 400 );
    }

    /**
     * Sends $data as JSON with $status and ends the request (not inside a try block: cleanExit() ends the request
     * on every server, also on Velocity, where it throws).
     *
     * @param array $data
     * @param int $status
     */
    private static function answer( array $data, $status = 200 )
    {
        if ( $status !== 200 )
            header( $status === 403 ? 'HTTP/1.1 403 Forbidden' : ( $status === 405 ? 'HTTP/1.1 405 Method Not Allowed' : 'HTTP/1.1 400 Bad Request' ), true, $status );
        if ( $status === 405 )
            header( 'Allow: POST' );
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Cache-Control: no-store' );
        echo \expMultiuploadService::json( $data );
        \eZDB::checkTransactionCounter();
        \eZExecution::cleanExit();
    }
}

}
