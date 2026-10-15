<?php
/**
 * The code of extension/ezmultiupload/modules/ezmultiupload/upload.php, moved into a class (#207 stage 1). The file extension/ezmultiupload/modules/ezmultiupload/upload.php is one call to it.
 * Guide: doc/bc/6.0/cli_cronjob_view_abstractions.md
 */
/*
 * The original header of extension/ezmultiupload/modules/ezmultiupload/upload.php:
 *
 *
 * File containing the eZ Publish upload view implementation.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 * @version 1.0.0
 * @package ezmultiupload
 *
 */

namespace Exponential\View\Extension\Ezmultiupload\Ezmultiupload
{

class Upload extends \Exponential\Runnable\ModuleView
{
    public function run( array $scope )
    {
        // the including function's variables ($Params, $Module, $cli, ...)
        foreach ( array_keys( $scope ) as $__name )
            if ( $__name !== 'this' && $__name !== 'scope' )
                ${$__name} = &$scope[$__name];
        unset( $__name );

        $http = \eZHTTPTool::instance();
        $tpl = \eZTemplate::factory();
        $module = $Params['Module'];
        $parentNodeID = \expMultiuploadService::positiveId( (string)$Params['ParentNodeID'] );

        // Check if current action is an real upload action
        if( $module->isCurrentAction( 'Upload' ) )
        {
            $result = array( 'errors' => array() );
            $title = \expMultiuploadService::cleanText( $http->hasPostVariable( 'UploadTitle' ) ? $http->postVariable( 'UploadTitle' ) : '' );
            $altText = \expMultiuploadService::cleanText( $http->hasPostVariable( 'UploadAltText' ) ? $http->postVariable( 'UploadAltText' ) : '' );
            $keepDraft = $http->hasPostVariable( 'UploadKeepDraft' ) && $http->postVariable( 'UploadKeepDraft' ) === '1';

            // The location and the file are checked before anything is stored: a POST with the session's form
            // token, a location files can be uploaded to, the size limit, the type list, the create permission
            // for the class the file becomes, and the alternative text a class may require.
            $allowed = \expMultiuploadService::requestAllowed( \eZSys::serverVariable( 'REQUEST_METHOD', true ),
                                                              \eZSys::serverVariable( 'HTTP_SEC_FETCH_SITE', true ),
                                                              $http->hasPostVariable( 'ezxform_token' ) ? $http->postVariable( 'ezxform_token' ) : null,
                                                              \eZSys::serverVariable( 'HTTP_ORIGIN', true ),
                                                              \eZSys::serverVariable( 'HTTP_HOST', true ) );
            if ( $allowed !== true )
                $result['errors'][] = array( 'description' => $allowed );

            $parentNode = $parentNodeID ? \eZContentObjectTreeNode::fetch( $parentNodeID ) : null;
            if ( empty( $result['errors'] ) )
            {
                $target = \expMultiuploadService::uploadTarget( $parentNode instanceof \eZContentObjectTreeNode ? $parentNode : null );
                if ( $target !== true )
                    $result['errors'][] = array( 'description' => $target );
            }

            // A ZIP file posted with UploadZip=inspect (list its entries) or UploadZip=import (make them)
            $zipAction = $http->hasPostVariable( 'UploadZip' ) ? $http->postVariable( 'UploadZip' ) : '';
            if ( $zipAction === 'inspect' || $zipAction === 'import' )
            {
                $answer = empty( $result['errors'] )
                    ? self::zip( $zipAction, $parentNode, $http, $keepDraft )
                    : array( 'ok' => false, 'errors' => array( $result['errors'][0]['description'] ) );
                header( 'Content-Type: application/json; charset=utf-8' );
                echo \expMultiuploadService::json( $answer );
                \eZExecution::cleanExit();
            }

            if ( empty( $result['errors'] ) )
            {
                $file = isset( $_FILES['Filedata'] ) && is_array( $_FILES['Filedata'] ) ? $_FILES['Filedata'] : null;
                $name = $file && isset( $file['name'] ) && is_string( $file['name'] ) ? $file['name'] : '';
                $size = $file && isset( $file['size'] ) ? (int)$file['size'] : 0;
                $check = \expMultiuploadService::check( $parentNodeID, array( array( 'name' => $name, 'size' => $size ) ) );
                $row = $check['ok'] && isset( $check['files'][0] ) ? $check['files'][0] : null;
                if ( $file && isset( $file['error'] ) && in_array( (int)$file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) )
                    $result['errors'][] = array( 'description' => \ezpI18n::tr( 'extension/ezmultiupload', 'The file is larger than %max.', null,
                                                                                array( '%max' => \expMultiuploadService::sizeText( \expMultiuploadService::maxFileSize() ) ) ) );
                else if ( $row && !$row['allowed'] )
                    $result['errors'][] = array( 'description' => $row['reason'] );
                else if ( $row && $row['altRequired'] && !\eZImageType::validateImageAltText( $altText ) )
                    $result['errors'][] = array( 'description' => \ezpI18n::tr( 'kernel/classes/datatypes', 'An alternative text is required for this image.' ) );
            }

            // Exec multiupload handlers preUpload method
            if ( empty( $result['errors'] ) )
                \eZMultiuploadHandler::exec( 'preUpload', $result );

            $created = null;
            // Handle file upload only if there was no errors
            if( empty( $result['errors'] ) )
            {
                $behaviour = new \ezpContentPublishingBehaviour();
                $behaviour->isTemporary = true;
                $behaviour->disableAsynchronousPublishing = false;
                \ezpContentPublishingBehaviour::setBehaviour( $behaviour );

                // The kernel's upload makes the object (all its own checks apply) as a draft; the alternative text
                // is stored on the draft, which is then published unless the draft is kept.
                $upload = new \eZContentUpload();
                $made = $upload->handleUpload( $result, 'Filedata', $parentNodeID, false, $title, false, false );
                if ( is_array( $made ) && isset( $made['contentobject'] ) && $made['contentobject'] instanceof \eZContentObject
                     && empty( $result['contentobject'] ) )
                {
                    $object = $made['contentobject'];
                    $version = (int)$made['contentobject_version'];
                    if ( $altText !== '' )
                        self::storeAlternativeText( $object, $version, $altText );
                    if ( !$keepDraft )
                    {
                        $upload->publishObject( $result, $result['errors'], $result['notices'], $object, $version, $object->contentClass(), false, false );
                        $object = \eZContentObject::fetch( (int)$object->attribute( 'id' ) );
                    }
                    else
                    {
                        $result['contentobject'] = $object;
                        $result['contentobject_version'] = $version;
                    }
                    $created = array( 'object' => $object, 'version' => $version, 'draft' => $keepDraft );
                }
                else if ( isset( $result['contentobject'] ) && $result['contentobject'] instanceof \eZContentObject )
                {
                    // an upload handler (MimeUploadHandlerMap) made and published the object itself
                    $created = array( 'object' => $result['contentobject'], 'version' => (int)$result['contentobject']->attribute( 'current_version' ), 'draft' => false );
                }
            }

            // Exec multiupload handlers postUpload method
            \eZMultiuploadHandler::exec( 'postUpload', $result );

            // Pass result to template and process it
            $tpl->setVariable( 'result', $result );
            $templateOutput = $tpl->fetch( 'design:ezmultiupload/thumbnail.tpl' );

            // Strip all new lines from processed template and convert all applicable characters to
            // HTML entities output. Create upload ID
            $httpCharset = \eZTextCodec::httpCharset();
            $data = htmlentities( str_replace( array( "\r\n", "\r", "\n" ), array(""), $templateOutput ) , ENT_QUOTES, $httpCharset );
            $id = md5( (string)mt_rand() . (string)microtime() );

            // 'data' and 'id' as before (the classic page shows the thumbnail); the rest for the new page
            $errors = array();
            foreach ( $result['errors'] as $error )
                $errors[] = is_array( $error ) && isset( $error['description'] ) ? (string)$error['description'] : (string)$error;
            $response = array( 'data' => $data, 'id' => $id, 'ok' => empty( $errors ) && $created !== null, 'errors' => $errors,
                               'item' => $created ? \expMultiuploadService::createdItem( $created['object'], $created['version'], $created['draft'] ) : null );

            // Return server response in JSON format
            header( 'Content-Type: application/json; charset=utf-8' );
            echo \expMultiuploadService::json( $response );

            // Stop execution
            \eZExecution::cleanExit();
        }
        else
        {
            // Check if parent node ID provided in URL exists and is an integer
            if ( !$parentNodeID )
                return $this->viewResult( isset( $Result ) ? $Result : null,  $module->handleError( \eZError::KERNEL_NOT_AVAILABLE, 'kernel' ) );

            // Fetch parent node
            $parentNode = \eZContentObjectTreeNode::fetch( $parentNodeID );

            // A location that does not exist and one the user cannot read get the same answer. One the user cannot
            // upload to (no create access, or a class that is not an upload target) shows the page with the reason,
            // so another location can be chosen.
            if( !$parentNode || !$parentNode->attribute( 'can_read' ) )
                return $this->viewResult( isset( $Result ) ? $Result : null,  $module->handleError( \eZError::KERNEL_NOT_FOUND, 'kernel' ) );

            // Create string with available file types for GUI uploader
            $availableFileTypes = \expMultiuploadService::fileTypes( $parentNode );
            $availableFileTypesStr = implode( ';', $availableFileTypes );

            // Pass variables to upload.tpl template
            $tpl->setVariable( 'file_types', $availableFileTypesStr );
            $tpl->setVariable( 'session_id', session_id() );
            $tpl->setVariable( 'session_name', session_name() );
            $tpl->setVariable( 'parent_node', $parentNode );
            // the new page (admin4, admin4l, adminui): its script's configuration as JSON, escaped for HTML
            $tpl->setVariable( 'upload_config_json', \expMultiuploadService::json( \expMultiuploadService::pageConfig( $parentNode ) ) );
            $tpl->setVariable( 'max_size_text', \expMultiuploadService::maxFileSize() ? \expMultiuploadService::sizeText( \expMultiuploadService::maxFileSize() ) : '' );

            // Process template and set path data
            $Result = array();
            $Result['content'] = $tpl->fetch( 'design:ezmultiupload/upload.tpl' );
            $Result['path'] = array( array( 'url' => false,
                                            'text' => \ezpI18n::tr( 'extension/ezmultiupload', 'Multiupload' ) ) );
        }

        return $this->viewResult( isset( $Result ) ? $Result : null, null );
    }

    /**
     * A ZIP file's inspection or import through expMultiuploadZipImporter: inspect() lists the entries (nothing is
     * written that stays), extract() writes the ticked ones that pass every check to a private directory of this
     * request, which cleanup() removes afterwards, also after a failure. Each extracted file is then checked as an
     * upload (expMultiuploadService::zipEntries()) and made by the kernel's upload of a local file.
     *
     * @param string $action 'inspect' or 'import'
     * @param \eZContentObjectTreeNode $node the target
     * @param \eZHTTPTool $http
     * @param bool $keepDraft
     * @return array the answer
     */
    private static function zip( $action, $node, $http, $keepDraft )
    {
        $post = function ( $name ) use ( $http ) { return $http->hasPostVariable( $name ) ? $http->postVariable( $name ) : null; };
        if ( !\expMultiuploadService::zipEnabled() )
            return array( 'ok' => false, 'errors' => array( \ezpI18n::tr( 'extension/ezmultiupload', 'ZIP files are not taken on this site.' ) ) );
        $canFetch = \eZHTTPFile::canFetch( 'Filedata', 0 );
        $file = ( $canFetch === true || $canFetch === \eZHTTPFile::UPLOADEDFILE_OK ) ? \eZHTTPFile::fetch( 'Filedata' ) : null;
        $max = \expMultiuploadService::maxFileSize();
        if ( !$file instanceof \eZHTTPFile || ( $max && (int)$file->attribute( 'filesize' ) > $max ) )
            return array( 'ok' => false, 'errors' => array( \ezpI18n::tr( 'extension/ezmultiupload', 'The file is larger than %max.', null,
                                                                          array( '%max' => \expMultiuploadService::sizeText( $max ) ) ) ) );
        $path = $file->attribute( 'filename' );
        if ( !\expMultiuploadService::isZipFile( (string)$file->attribute( 'original_filename' ), $path ) )
            return array( 'ok' => false, 'errors' => array( \ezpI18n::tr( 'extension/ezmultiupload', 'The file is not a ZIP file that can be read.' ) ) );
        $mode = $post( 'UploadZipMode' ) === 'flat' ? 'flat' : 'keep';
        $policy = in_array( $post( 'UploadZipDuplicates' ), array( 'skip', 'rename', 'upload' ), true ) ? $post( 'UploadZipDuplicates' ) : 'skip';

        $importer = new \expMultiuploadZipImporter();
        $inspect = $importer->inspect( $path );
        if ( empty( $inspect['ok'] ) )
            return array( 'ok' => false, 'refused' => $inspect['error'],
                          'errors' => array( \expMultiuploadService::zipReason( $inspect['error'] ) ?: (string)$inspect['message'] ) );
        if ( !empty( $inspect['refused'] ) && in_array( 'encrypted', $inspect['refused'], true ) )
            return array( 'ok' => false, 'refused' => 'encrypted', 'errors' => array( \expMultiuploadService::zipReason( 'encrypted' ) ) );

        $cache = array();
        if ( $action === 'inspect' )
        {
            $rows = \expMultiuploadService::zipEntries( $node, \expMultiuploadService::zipImporterEntries( $inspect ), $mode, false, $cache );
            return array( 'ok' => true, 'mode' => $mode, 'zip' => \expMultiuploadService::zipSummary( $rows ) );
        }

        // the ticked entries (their paths in the archive), checked again by extract(), never taken as they are
        $selected = array();
        if ( is_string( $post( 'UploadZipEntries' ) ) && $post( 'UploadZipEntries' ) !== '' )
        {
            $keys = json_decode( $post( 'UploadZipEntries' ), true );
            $selected = is_array( $keys ) ? array_values( array_filter( $keys, 'is_string' ) ) : array();
        }
        $extract = $importer->extract( $path, $selected );
        try
        {
            if ( empty( $extract['ok'] ) )
                return array( 'ok' => false, 'errors' => array( \expMultiuploadService::zipReason( $extract['error'] ) ?: (string)$extract['message'] ) );
            $rows = \expMultiuploadService::zipEntries( $node, \expMultiuploadService::zipImporterEntries( $inspect, $extract ), $mode, true, $cache );
            $results = \expMultiuploadService::zipImport( $rows, $selected, $policy, $keepDraft );
            $counts = array( 'done' => 0, 'draft' => 0, 'skipped' => 0, 'failed' => 0, 'unselected' => 0, 'ok' => 0 );
            foreach ( $results as $r )
                $counts[$r['status']] = isset( $counts[$r['status']] ) ? $counts[$r['status']] + 1 : 1;
            return array( 'ok' => true, 'mode' => $mode, 'results' => $results, 'counts' => $counts );
        }
        finally
        {
            $importer->cleanup( isset( $extract['tmpDir'] ) ? (string)$extract['tmpDir'] : '' );
        }
    }

    /**
     * Gives the image attributes of the draft $version of $object the alternative text $altText.
     *
     * @param \eZContentObject $object
     * @param int $version
     * @param string $altText
     */
    private static function storeAlternativeText( $object, $version, $altText )
    {
        foreach ( $object->fetchDataMap( $version ) as $attribute )
        {
            if ( $attribute->attribute( 'data_type_string' ) !== \eZImageType::DATA_TYPE_STRING || !$attribute->hasContent() )
                continue;
            $content = $attribute->attribute( 'content' );
            $content->setAttribute( 'alternative_text', $altText );
            $content->store( $attribute );
            $attribute->store();
        }
    }

}

}
