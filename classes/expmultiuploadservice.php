<?php
/**
 * The server side of the upload page: checks before an upload (the target location, the content class each file
 * becomes, the create permission for it there, the size and type limits, files that are already there) and the
 * actions on uploaded items (move, add a location, remove to the trash). Every answer is an array the views send as
 * JSON; nothing here writes output.
 *
 * Every node id from a request goes through positiveId() or idList(); every permission is the kernel's own
 * (checkAccess( 'create' ), canMoveFrom(), canMoveTo(), canRemove(), canAddLocation()), and every change runs
 * through the kernel's content operations (move, addlocation, delete), so workflows and triggers still run.
 *
 * The parts that read the database are protected static methods (fetchNode(), fetchClassByIdentifier(),
 * childFiles(), ...), so a test can replace them with values held in memory.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezmultiupload
 */

class expMultiuploadService
{
    /** At most this many node ids in one request */
    const MAX_IDS = 100;

    /** At most this many files in one check */
    const MAX_FILES = 200;

    /** At most this many children are read for the duplicate check and listed by browse() */
    const MAX_CHILDREN = 500;

    /** The eZPreference that remembers the last location chosen on the upload page */
    const PREFERENCE = 'exp_multiupload_location';

    /** The longest title and alternative text taken from a request */
    const MAX_TEXT = 255;

    // ---- input ------------------------------------------------------------------------------------------------

    /**
     * $value as a positive integer node or object id, or 0 when it is not one: only digits, no sign, no spaces, no
     * leading zero, at most 18 digits. An int is taken as it is when it is positive.
     *
     * @param mixed $value
     * @return int
     */
    public static function positiveId( $value )
    {
        if ( is_int( $value ) )
            return $value > 0 ? $value : 0;
        if ( !is_string( $value ) || !preg_match( '/^[1-9][0-9]{0,17}\z/', $value ) )
            return 0;
        return (int)$value;
    }

    /**
     * A list of ids from an array or a comma-separated string: false when one of them is not a positive id, the
     * list is empty or longer than MAX_IDS. Duplicates are dropped, the order is kept.
     *
     * @param mixed $value
     * @return int[]|false
     */
    public static function idList( $value )
    {
        if ( is_string( $value ) )
            $value = $value === '' ? array() : explode( ',', $value );
        if ( !is_array( $value ) || !$value || count( $value ) > self::MAX_IDS )
            return false;
        $ids = array();
        foreach ( $value as $item )
        {
            $id = self::positiveId( is_string( $item ) ? trim( $item ) : $item );
            if ( !$id )
                return false;
            $ids[$id] = $id;
        }
        return array_values( $ids );
    }

    /**
     * A title or alternative text from a request: a string, control characters removed, trimmed, at most
     * MAX_TEXT characters. Anything that is not a string is ''.
     *
     * @param mixed $value
     * @return string
     */
    public static function cleanText( $value )
    {
        if ( !is_string( $value ) )
            return '';
        $value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value );
        if ( $value === null )
            return '';
        $value = trim( $value );
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::MAX_TEXT, 'UTF-8' ) : substr( $value, 0, self::MAX_TEXT );
    }

    /**
     * Whether the posted form token is the session's: true when the form token extension is not active (no token
     * to compare), else a constant-time compare with ezxFormToken::getToken(). The token is the session's
     * (sha1 of the secret, the intention and the session id), so it is the same on every server and worker.
     *
     * @param mixed $posted
     * @return bool
     */
    public static function tokenValid( $posted )
    {
        $expected = static::expectedToken();
        if ( $expected === null )
            return true;
        return is_string( $posted ) && $posted !== '' && hash_equals( $expected, $posted );
    }

    /**
     * The token the session expects, or null when no form token is in use (extension missing or disabled, or an
     * anonymous user, whom the form token does not protect either).
     *
     * @return string|null
     */
    protected static function expectedToken()
    {
        if ( !class_exists( 'ezxFormToken' ) || !ezxFormToken::isEnabled() )
            return null;
        if ( !eZUser::isCurrentUserRegistered() )
            return null;
        return ezxFormToken::getToken();
    }

    /**
     * Whether a request may change something: a POST, not sent from another site (Sec-Fetch-Site, where the
     * browser sends it), with the session's form token. Where no form token is in use (the form token extension is
     * not active, or the user is anonymous), the browser's Sec-Fetch-Site header must say "same-origin" or "none";
     * where the browser sends no Sec-Fetch-Site (plain HTTP, older browsers), its Origin header must name this host.
     * A request with neither is refused, since nothing else tells it from one sent by another site.
     *
     * @param string $method
     * @param string|null $secFetchSite
     * @param mixed $token
     * @param string|null $origin the Origin header
     * @param string|null $host the Host header
     * @return string|true true, or the reason it is refused
     */
    public static function requestAllowed( $method, $secFetchSite, $token, $origin = null, $host = null )
    {
        if ( strtoupper( (string)$method ) !== 'POST' )
            return ezpI18n::tr( 'extension/ezmultiupload', 'This address only takes POST requests.' );
        if ( $secFetchSite !== null && $secFetchSite !== '' && !in_array( $secFetchSite, array( 'same-origin', 'same-site', 'none' ), true ) )
            return ezpI18n::tr( 'extension/ezmultiupload', 'The request came from another site.' );
        if ( static::expectedToken() === null )
        {
            if ( $secFetchSite !== null && $secFetchSite !== '' )
                $sameOrigin = in_array( $secFetchSite, array( 'same-origin', 'none' ), true );
            else
                $sameOrigin = self::originMatchesHost( $origin, $host );
            if ( !$sameOrigin )
                return ezpI18n::tr( 'extension/ezmultiupload', 'The request came from another site.' );
            return true;
        }
        if ( !self::tokenValid( $token ) )
            return ezpI18n::tr( 'extension/ezmultiupload', 'The form token is missing or wrong. Reload the page and try again.' );
        return true;
    }

    /**
     * Whether an Origin header ("https://example.com:8443") names the host of the Host header ("example.com:8443"),
     * without regard to case; false when either is missing or the origin is "null".
     *
     * @param mixed $origin
     * @param mixed $host
     * @return bool
     */
    public static function originMatchesHost( $origin, $host )
    {
        if ( !is_string( $origin ) || !is_string( $host ) || $host === '' )
            return false;
        $parts = parse_url( $origin );
        if ( !is_array( $parts ) || empty( $parts['host'] ) || !isset( $parts['scheme'] )
             || !in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) )
            return false;
        $originHost = strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int)$parts['port'] : '' );
        $default = strtolower( $parts['scheme'] ) === 'https' ? ':443' : ':80';
        $host = strtolower( trim( $host ) );
        return $originHost === $host || $originHost . $default === $host || $originHost === $host . $default;
    }

    // ---- limits -------------------------------------------------------------------------------------------------

    /**
     * A size from an INI or php.ini value ("64M", "1G", "512k", "1048576") in bytes; 0 for empty, "0" or anything
     * that is not a size.
     *
     * @param mixed $value
     * @return int
     */
    public static function parseSize( $value )
    {
        $value = trim( (string)$value );
        if ( !preg_match( '/^([0-9]+)\s*([kmgt]?)b?$/i', $value, $m ) )
            return 0;
        $bytes = (int)$m[1];
        switch ( strtolower( $m[2] ) )
        {
            case 't': $bytes *= 1024;
            // no break
            case 'g': $bytes *= 1024;
            // no break
            case 'm': $bytes *= 1024;
            // no break
            case 'k': $bytes *= 1024;
        }
        return $bytes;
    }

    /**
     * The smallest of the sizes that are set (0 is "no limit"); 0 when none is set.
     *
     * @param int[] $sizes
     * @return int
     */
    public static function smallestLimit( array $sizes )
    {
        $set = array_filter( array_map( 'intval', $sizes ), function ( $s ) { return $s > 0; } );
        return $set ? min( $set ) : 0;
    }

    /**
     * The largest file this server takes: ezmultiupload.ini [MultiUploadSettings] MaxFileSize and PHP's
     * upload_max_filesize and post_max_size, the smallest that is set. 0: no limit known.
     *
     * @return int bytes
     */
    public static function maxFileSize()
    {
        $ini = eZINI::instance( 'ezmultiupload.ini' );
        $setting = $ini->hasVariable( 'MultiUploadSettings', 'MaxFileSize' ) ? $ini->variable( 'MultiUploadSettings', 'MaxFileSize' ) : '';
        return self::smallestLimit( array( self::parseSize( $setting ),
                                           self::parseSize( ini_get( 'upload_max_filesize' ) ),
                                           self::parseSize( ini_get( 'post_max_size' ) ) ) );
    }

    /**
     * The file types the location takes (ezmultiupload.ini [FileTypeSettings_<node id>] and
     * [FileTypeSettings_<class identifier>] FileType[], such as "*.jpg"); an empty list or "*.*" is anything.
     *
     * @param eZContentObjectTreeNode $node
     * @return string[]
     */
    public static function fileTypes( $node )
    {
        $ini = eZINI::instance( 'ezmultiupload.ini' );
        $types = array();
        foreach ( array( 'FileTypeSettings_' . $node->attribute( 'node_id' ), 'FileTypeSettings_' . $node->attribute( 'class_identifier' ) ) as $group )
        {
            if ( $ini->hasGroup( $group ) && $ini->hasVariable( $group, 'FileType' ) )
                $types = array_merge( $types, (array)$ini->variable( $group, 'FileType' ) );
        }
        return array_values( array_unique( array_filter( array_map( 'trim', $types ), 'strlen' ) ) );
    }

    /**
     * Whether a file name matches the type list ("*.*" or an empty list: anything; "*.jpg": that suffix, without
     * regard to case).
     *
     * @param string $name
     * @param string[] $types
     * @return bool
     */
    public static function typeAllowed( $name, array $types )
    {
        if ( !$types )
            return true;
        $name = strtolower( (string)$name );
        foreach ( $types as $type )
        {
            $type = strtolower( trim( $type ) );
            if ( $type === '*' || $type === '*.*' )
                return true;
            if ( strpos( $type, '*.' ) === 0 )
                $type = substr( $type, 1 );
            if ( $type !== '' && $type[0] === '.' && strlen( $name ) > strlen( $type ) && substr( $name, -strlen( $type ) ) === $type )
                return true;
        }
        return false;
    }

    // ---- locations ----------------------------------------------------------------------------------------------

    /**
     * @param int $nodeID
     * @return eZContentObjectTreeNode|null
     */
    protected static function fetchNode( $nodeID )
    {
        $node = eZContentObjectTreeNode::fetch( (int)$nodeID );
        return $node instanceof eZContentObjectTreeNode ? $node : null;
    }

    /**
     * @param string $identifier
     * @return eZContentClass|null
     */
    protected static function fetchClassByIdentifier( $identifier )
    {
        $class = eZContentClass::fetchByIdentifier( $identifier );
        return $class instanceof eZContentClass ? $class : null;
    }

    /**
     * Whether files can be uploaded below $node at all: it is read and created in by the user, and its class is
     * one of [MultiUploadSettings] AvailableClasses[] or its node id one of AvailableSubtreeNode[], as the upload
     * page has always asked. A location the user cannot read gets the same answer as one that does not exist.
     *
     * @param eZContentObjectTreeNode|null $node
     * @return string|true true, or why not
     */
    public static function uploadTarget( $node )
    {
        if ( !$node || !$node->canRead() )
            return ezpI18n::tr( 'extension/ezmultiupload', 'The location does not exist.' );
        if ( !$node->canCreate() )
            return ezpI18n::tr( 'extension/ezmultiupload', 'You cannot create content in this location.' );
        $ini = eZINI::instance( 'ezmultiupload.ini' );
        $classes = (array)$ini->variable( 'MultiUploadSettings', 'AvailableClasses' );
        $subtrees = array_map( 'intval', (array)$ini->variable( 'MultiUploadSettings', 'AvailableSubtreeNode' ) );
        $listed = in_array( $node->attribute( 'class_identifier' ), $classes, true ) || in_array( (int)$node->attribute( 'node_id' ), $subtrees, true );
        $anyContainer = !$ini->hasVariable( 'MultiUploadSettings', 'AnyContainer' ) || $ini->variable( 'MultiUploadSettings', 'AnyContainer' ) !== 'disabled';
        if ( !$listed && !( $anyContainer && static::isContainer( $node ) ) )
            return $anyContainer
                ? ezpI18n::tr( 'extension/ezmultiupload', 'Files cannot be uploaded here: %name is not a container. Choose a folder or another container.', null, array( '%name' => (string)$node->attribute( 'name' ) ) )
                : ezpI18n::tr( 'extension/ezmultiupload', 'Files cannot be uploaded to this type of location.' );
        return true;
    }

    /**
     * Whether the current user can upload files at $nodeID: access to the ezmultiupload module and uploadTarget()
     * (the location exists, is read and created in, and is a container or a configured upload class or node). The
     * one check for a link or button to the upload page; the page itself and every upload check again.
     *
     * @param int|eZContentObjectTreeNode $node
     * @return bool
     */
    public static function canUploadAt( $node )
    {
        if ( !$node instanceof eZContentObjectTreeNode )
            $node = static::fetchNode( self::positiveId( is_int( $node ) ? $node : (string)$node ) );
        if ( !$node )
            return false;
        $access = eZUser::currentUser()->hasAccessTo( 'ezmultiupload', 'upload' );
        if ( !isset( $access['accessWord'] ) || $access['accessWord'] === 'no' )
            return false;
        return static::uploadTarget( $node ) === true;
    }

    /**
     * Whether the node's class is a container (its class has "Container" set).
     *
     * @param eZContentObjectTreeNode $node
     * @return bool
     */
    protected static function isContainer( $node )
    {
        $object = $node->object();
        $class = $object ? $object->contentClass() : null;
        return $class && (bool)$class->attribute( 'is_container' );
    }

    /**
     * The path of $node as names, from the first level below the root to the node itself; a location above it the
     * user cannot read is left out (its name is not the user's to see).
     *
     * @param eZContentObjectTreeNode $node
     * @return string[]
     */
    protected static function pathNames( $node )
    {
        $names = array();
        foreach ( $node->fetchPath() as $parent )
        {
            if ( (int)$parent->attribute( 'node_id' ) > 1 && $parent->canRead() )
                $names[] = (string)$parent->attribute( 'name' );
        }
        $names[] = (string)$node->attribute( 'name' );
        return $names;
    }

    /**
     * An address of this siteaccess, as a string for links in JSON.
     *
     * @param string $path such as "content/view/full/2"
     * @return string
     */
    protected static function url( $path )
    {
        $url = $path;
        eZURI::transformURI( $url );
        return $url;
    }

    /**
     * A location as the page shows it: id, name, path, view address, and whether files can be uploaded there (with
     * the reason when not).
     *
     * @param eZContentObjectTreeNode $node
     * @param string $mode 'upload' (uploadTarget()) or 'place' (the user may create something there)
     * @return array
     */
    public static function locationInfo( $node, $mode = 'upload' )
    {
        $check = $mode === 'place'
            ? ( $node->canCreate() ? true : ezpI18n::tr( 'extension/ezmultiupload', 'You cannot create content in this location.' ) )
            : static::uploadTarget( $node );
        $path = static::pathNames( $node );
        return array( 'id' => (int)$node->attribute( 'node_id' ),
                      'name' => (string)$node->attribute( 'name' ),
                      'path' => $path,
                      'pathText' => implode( ' / ', $path ),
                      'url' => static::url( 'content/view/full/' . (int)$node->attribute( 'node_id' ) ),
                      'allowed' => $check === true,
                      'reason' => $check === true ? '' : $check );
    }

    /**
     * The containers below $nodeID the user can read, for the location picker, with the path to them.
     *
     * @param int $nodeID 1: the top level
     * @param string $mode 'upload' or 'place'
     * @return array
     */
    public static function browse( $nodeID, $mode = 'upload' )
    {
        $node = static::fetchNode( $nodeID );
        if ( !$node || !$node->canRead() )
            return array( 'ok' => false, 'error' => ezpI18n::tr( 'extension/ezmultiupload', 'The location does not exist.' ) );
        $children = array();
        $list = eZContentObjectTreeNode::subTreeByNodeID( array( 'Depth' => 1, 'Limit' => self::MAX_CHILDREN,
                                                                 'SortBy' => array( 'name', true ) ), $nodeID ) ?: array();
        foreach ( $list as $child )
        {
            $object = $child->object();
            $class = $object ? $object->contentClass() : null;
            if ( !$class || !$class->attribute( 'is_container' ) )
                continue;
            $info = static::locationInfo( $child, $mode );
            $info['hasChildren'] = (int)$child->attribute( 'children_count' ) > 0;
            unset( $info['path'], $info['pathText'] );
            $children[] = $info;
        }
        $here = (int)$nodeID === 1
            ? array( 'id' => 1, 'name' => ezpI18n::tr( 'extension/ezmultiupload', 'Top level' ), 'path' => array(), 'pathText' => '', 'url' => '',
                     'allowed' => false, 'reason' => '' )
            : static::locationInfo( $node, $mode );
        $crumbs = array( array( 'id' => 1, 'name' => ezpI18n::tr( 'extension/ezmultiupload', 'Top level' ) ) );
        if ( (int)$nodeID !== 1 )
        {
            foreach ( $node->fetchPath() as $parent )
            {
                if ( (int)$parent->attribute( 'node_id' ) > 1 && $parent->canRead() )
                    $crumbs[] = array( 'id' => (int)$parent->attribute( 'node_id' ), 'name' => (string)$parent->attribute( 'name' ) );
            }
            $crumbs[] = array( 'id' => (int)$nodeID, 'name' => (string)$node->attribute( 'name' ) );
        }
        return array( 'ok' => true, 'location' => $here, 'crumbs' => $crumbs, 'children' => $children );
    }

    // ---- files --------------------------------------------------------------------------------------------------

    /**
     * The MIME type of a file name, as the upload itself will find it (eZMimeType by the name).
     *
     * @param string $name
     * @return string
     */
    public static function mimeType( $name )
    {
        $mime = eZMimeType::findByURL( (string)$name );
        return is_array( $mime ) && !empty( $mime['name'] ) ? (string)$mime['name'] : 'application/octet-stream';
    }

    /**
     * The content class identifier a file becomes (upload.ini [CreateSettings] MimeClassMap[] by type, then by
     * group, else DefaultClass), and whether an upload handler (MimeUploadHandlerMap[]) imports it instead.
     *
     * @param string $name
     * @return array { identifier, mime, handler }
     */
    public static function classForFile( $name )
    {
        return self::classForMime( self::mimeType( $name ) );
    }

    /**
     * The content class identifier for a MIME type (upload.ini, as classForFile()).
     *
     * @param string $mime
     * @return array { identifier, mime, handler }
     */
    public static function classForMime( $mime )
    {
        $mime = (string)$mime !== '' ? (string)$mime : 'application/octet-stream';
        $ini = eZINI::instance( 'upload.ini' );
        $map = (array)$ini->variable( 'CreateSettings', 'MimeClassMap' );
        $handlers = $ini->hasVariable( 'CreateSettings', 'MimeUploadHandlerMap' ) ? (array)$ini->variable( 'CreateSettings', 'MimeUploadHandlerMap' ) : array();
        $parts = explode( '/', $mime );
        $group = $parts[0];
        if ( isset( $map[$mime] ) )
            $identifier = $map[$mime];
        else if ( isset( $map[$group] ) )
            $identifier = $map[$group];
        else
            $identifier = (string)$ini->variable( 'CreateSettings', 'DefaultClass' );
        return array( 'identifier' => $identifier, 'mime' => $mime,
                      'handler' => isset( $handlers[$mime] ) || isset( $handlers[$group] ) );
    }

    /**
     * Whether the user may create an object of $class below $node (content/create with the class and the parent's
     * class, as the kernel asks).
     *
     * @param eZContentObjectTreeNode $node
     * @param eZContentClass $class
     * @return bool
     */
    protected static function canCreateClass( $node, $class )
    {
        return $node->checkAccess( 'create', (int)$class->attribute( 'id' ), static::nodeClassID( $node ) ) == 1;
    }

    /**
     * The class id of the object at $node (a node has no class id of its own).
     *
     * @param eZContentObjectTreeNode $node
     * @return int
     */
    protected static function nodeClassID( $node )
    {
        $object = $node->object();
        return $object ? (int)$object->attribute( 'contentclass_id' ) : 0;
    }

    /**
     * The largest file the class takes in its file attribute (the attribute's "max file size" in MB, data_int1, of
     * ezimage, ezbinaryfile and ezmedia); 0 for no limit of its own.
     *
     * @param eZContentClass $class
     * @return int bytes
     */
    protected static function classMaxSize( $class )
    {
        $ini = eZINI::instance( 'upload.ini' );
        $group = $class->attribute( 'identifier' ) . '_ClassSettings';
        $attributeName = $ini->hasGroup( $group ) ? $ini->variable( $group, 'FileAttribute' ) : '';
        $dataMap = $class->dataMap();
        if ( !isset( $dataMap[$attributeName] ) )
            return 0;
        $attribute = $dataMap[$attributeName];
        if ( !in_array( $attribute->attribute( 'data_type_string' ), array( 'ezimage', 'ezbinaryfile', 'ezmedia' ), true ) )
            return 0;
        return 1024 * 1024 * (int)$attribute->attribute( 'data_int1' );
    }

    /**
     * Whether the class's image attribute requires an alternative text (#425, L16).
     *
     * @param eZContentClass $class
     * @return bool
     */
    protected static function altTextRequired( $class )
    {
        return class_exists( 'eZImageType' ) && method_exists( 'eZImageType', 'classAltTextRequired' )
            && eZImageType::classAltTextRequired( $class );
    }

    /**
     * The files stored below $node: "original file name|size" => the child, for the duplicate check. Images and
     * files (the classes' file attributes of upload.ini) are read; at most MAX_CHILDREN children.
     *
     * @param eZContentObjectTreeNode $node
     * @return array[] each { name, size, nodeId, title, url }
     */
    protected static function childFiles( $node )
    {
        $files = array();
        $children = eZContentObjectTreeNode::subTreeByNodeID( array( 'Depth' => 1, 'Limit' => self::MAX_CHILDREN ), $node->attribute( 'node_id' ) ) ?: array();
        foreach ( $children as $child )
        {
            $object = $child->object();
            if ( !$object )
                continue;
            foreach ( $object->dataMap() as $attribute )
            {
                $type = $attribute->attribute( 'data_type_string' );
                if ( !in_array( $type, array( 'ezimage', 'ezbinaryfile', 'ezmedia' ), true ) || !$attribute->hasContent() )
                    continue;
                $content = $attribute->content();
                $name = '';
                $size = 0;
                if ( $type === 'ezimage' )
                {
                    $name = (string)$content->attribute( 'original_filename' );
                    $original = $content->attribute( 'original' );
                    $size = is_array( $original ) && isset( $original['filesize'] ) ? (int)$original['filesize'] : 0;
                }
                else if ( is_object( $content ) )
                {
                    $name = (string)$content->attribute( 'original_filename' );
                    $size = (int)$content->attribute( 'filesize' );
                }
                if ( $name !== '' )
                    $files[] = array( 'name' => $name, 'size' => $size, 'nodeId' => (int)$child->attribute( 'node_id' ),
                                      'title' => (string)$child->attribute( 'name' ),
                                      'url' => static::url( 'content/view/full/' . (int)$child->attribute( 'node_id' ) ) );
            }
        }
        return $files;
    }

    /**
     * The first free name for $name among $taken: "photo.jpg" -> "photo-2.jpg", "photo-3.jpg" ...
     *
     * @param string $name
     * @param string[] $taken lower case
     * @return string
     */
    public static function freeName( $name, array $taken )
    {
        $dot = strrpos( $name, '.' );
        $base = $dot === false || $dot === 0 ? $name : substr( $name, 0, $dot );
        $suffix = $dot === false || $dot === 0 ? '' : substr( $name, $dot );
        $taken = array_flip( $taken );
        for ( $i = 2; $i < 1000; $i++ )
        {
            $candidate = $base . '-' . $i . $suffix;
            if ( !isset( $taken[strtolower( $candidate )] ) )
                return $candidate;
        }
        return $base . '-' . substr( md5( $name . microtime() ), 0, 6 ) . $suffix;
    }

    /**
     * The checks before an upload of $files to the location $nodeID: per file the content class it becomes, whether
     * the user may create it there, whether its type and size are taken, whether an alternative text is required,
     * and a file with the same name and size already there (with a free name to rename it to).
     *
     * @param int $nodeID
     * @param array $files each { name, size }
     * @return array
     */
    public static function check( $nodeID, $files )
    {
        $node = static::fetchNode( $nodeID );
        // a location the user cannot read is answered as one that does not exist: no name, no path
        if ( $node && !$node->canRead() )
            $node = null;
        $target = static::uploadTarget( $node );
        if ( $target !== true )
            return array( 'ok' => true, 'location' => $node ? static::locationInfo( $node ) :
                              array( 'id' => (int)$nodeID, 'name' => '', 'path' => array(), 'pathText' => '', 'url' => '', 'allowed' => false, 'reason' => $target ),
                          'files' => array() );
        if ( !is_array( $files ) || count( $files ) > self::MAX_FILES )
            return array( 'ok' => false, 'error' => ezpI18n::tr( 'extension/ezmultiupload', 'Too many files in one check.' ) );

        $types = self::fileTypes( $node );
        $maxSize = self::maxFileSize();
        $existing = static::childFiles( $node );
        $byKey = array();
        $taken = array();
        foreach ( $existing as $file )
        {
            $byKey[strtolower( $file['name'] ) . '|' . $file['size']] = $file;
            $taken[] = strtolower( $file['name'] );
        }
        $classes = array();
        $out = array();
        foreach ( array_values( $files ) as $i => $file )
        {
            $name = is_array( $file ) && isset( $file['name'] ) && is_string( $file['name'] ) ? self::cleanText( $file['name'] ) : '';
            $size = is_array( $file ) && isset( $file['size'] ) && is_numeric( $file['size'] ) ? max( 0, (int)$file['size'] ) : 0;
            $row = array( 'index' => $i, 'name' => $name, 'size' => $size, 'class' => '', 'className' => '', 'mime' => '',
                          'handler' => false, 'allowed' => true, 'reason' => '', 'altRequired' => false, 'maxSize' => $maxSize,
                          'duplicate' => null );
            if ( $name === '' )
            {
                $row['allowed'] = false;
                $row['reason'] = ezpI18n::tr( 'extension/ezmultiupload', 'The file has no name.' );
                $out[] = $row;
                continue;
            }
            $detected = self::classForFile( $name );
            $row['class'] = $detected['identifier'];
            $row['mime'] = $detected['mime'];
            $row['handler'] = $detected['handler'];
            if ( !array_key_exists( $detected['identifier'], $classes ) )
                $classes[$detected['identifier']] = static::fetchClassByIdentifier( $detected['identifier'] );
            $class = $classes[$detected['identifier']];
            if ( !$class )
            {
                $row['allowed'] = false;
                $row['reason'] = ezpI18n::tr( 'extension/ezmultiupload', 'The content class %class does not exist.', null, array( '%class' => $detected['identifier'] ) );
                $out[] = $row;
                continue;
            }
            $row['className'] = (string)$class->attribute( 'name' );
            $row['altRequired'] = static::altTextRequired( $class );
            $row['maxSize'] = self::smallestLimit( array( $maxSize, static::classMaxSize( $class ) ) );
            if ( !self::typeAllowed( $name, $types ) )
            {
                $row['allowed'] = false;
                $row['reason'] = ezpI18n::tr( 'extension/ezmultiupload', 'This type of file is not taken in this location.' );
            }
            else if ( $row['maxSize'] && $size > $row['maxSize'] )
            {
                $row['allowed'] = false;
                $row['reason'] = ezpI18n::tr( 'extension/ezmultiupload', 'The file is larger than %max.', null, array( '%max' => self::sizeText( $row['maxSize'] ) ) );
            }
            else if ( !static::canCreateClass( $node, $class ) )
            {
                $row['allowed'] = false;
                $row['reason'] = ezpI18n::tr( 'extension/ezmultiupload', 'You may not create %class content in this location.', null, array( '%class' => $row['className'] ) );
            }
            $key = strtolower( $name ) . '|' . $size;
            if ( isset( $byKey[$key] ) )
            {
                $row['duplicate'] = $byKey[$key];
                $row['duplicate']['suggestion'] = self::freeName( $name, $taken );
            }
            $out[] = $row;
        }
        return array( 'ok' => true, 'location' => static::locationInfo( $node ), 'files' => $out, 'fileTypes' => $types, 'maxSize' => $maxSize );
    }

    /**
     * "1.5 MB": a size for messages.
     *
     * @param int $bytes
     * @return string
     */
    public static function sizeText( $bytes )
    {
        $units = array( 'B', 'kB', 'MB', 'GB', 'TB' );
        $n = (float)$bytes;
        $i = 0;
        while ( $n >= 1024 && $i < count( $units ) - 1 )
        {
            $n /= 1024;
            $i++;
        }
        $text = $i === 0 || $n >= 10 ? (string)round( $n ) : rtrim( rtrim( number_format( $n, 1, '.', '' ), '0' ), '.' );
        return $text . ' ' . $units[$i];
    }

    // ---- the actions on uploaded items -----------------------------------------------------------------------

    /**
     * An uploaded item as the result list shows it.
     *
     * @param eZContentObjectTreeNode $node
     * @return array
     */
    public static function itemInfo( $node )
    {
        $object = $node->object();
        $locations = array();
        foreach ( $object->assignedNodes() as $assigned )
        {
            // the item's other locations are listed only where the user can read them
            if ( (int)$assigned->attribute( 'node_id' ) !== (int)$node->attribute( 'node_id' ) && !$assigned->canRead() )
                continue;
            $parent = $assigned->fetchParent();
            if ( $parent && !$parent->canRead() )
                $parent = null;
            $locations[] = array( 'nodeId' => (int)$assigned->attribute( 'node_id' ),
                                  'main' => (int)$assigned->attribute( 'node_id' ) === (int)$object->attribute( 'main_node_id' ),
                                  'parentId' => $parent ? (int)$assigned->attribute( 'parent_node_id' ) : 0,
                                  'pathText' => $parent ? implode( ' / ', static::pathNames( $parent ) ) : '',
                                  'url' => static::url( 'content/view/full/' . (int)$assigned->attribute( 'node_id' ) ) );
        }
        return array( 'nodeId' => (int)$node->attribute( 'node_id' ),
                      'objectId' => (int)$object->attribute( 'id' ),
                      'imageUrl' => static::imageUrl( $object ),
                      'name' => (string)$object->attribute( 'name' ),
                      'url' => static::url( 'content/view/full/' . (int)$node->attribute( 'node_id' ) ),
                      'editUrl' => static::url( 'content/edit/' . (int)$object->attribute( 'id' ) ),
                      'locations' => $locations );
    }

    /**
     * The address of the stored image of $object (its first image attribute with an image, the original as the kernel
     * stored it; an SVG is the sanitized file), for the preview of an uploaded item; '' without one.
     *
     * @param eZContentObject $object
     * @return string
     */
    protected static function imageUrl( $object )
    {
        foreach ( $object->dataMap() as $attribute )
        {
            if ( $attribute->attribute( 'data_type_string' ) !== 'ezimage' || !$attribute->hasContent() )
                continue;
            $original = $attribute->content()->attribute( 'original' );
            if ( is_array( $original ) && !empty( $original['url'] ) )
                return eZSys::wwwDir() . '/' . ltrim( (string)$original['url'], '/' );
        }
        return '';
    }

    /**
     * The node $nodeID when it exists, has an object and the user can read it; else null. An item the user cannot
     * read is answered as one that does not exist, so the answers of the actions tell nothing about it.
     *
     * @param int $nodeID
     * @return eZContentObjectTreeNode|null
     */
    protected static function readableItem( $nodeID )
    {
        $node = static::fetchNode( $nodeID );
        return $node && $node->object() && $node->canRead() ? $node : null;
    }

    /**
     * Whether one of the locations of the object at $node is a top-level location (its parent is the root node 1:
     * the content root, Media, Users and the like). Those are never moved, placed elsewhere or removed from the
     * upload page; the kernel's own location actions refuse them too.
     *
     * @param eZContentObjectTreeNode $node
     * @return bool
     */
    protected static function isTopLevel( $node )
    {
        $object = $node->object();
        $nodes = $object ? $object->assignedNodes() : array( $node );
        foreach ( $nodes ?: array( $node ) as $assigned )
        {
            if ( (int)$assigned->attribute( 'parent_node_id' ) <= 1 || (int)$assigned->attribute( 'node_id' ) <= 1 )
                return true;
        }
        return false;
    }

    /**
     * What the kernel's remove view asks before nodes go: whether the user may remove all of them with everything
     * below them (subtreeRemovalInformation(), can_remove_all) and how many items are below them.
     *
     * @param int[] $nodeIDs
     * @return array{canRemoveAll: bool, children: int}
     */
    protected static function removalInfo( array $nodeIDs )
    {
        $info = eZContentObjectTreeNode::subtreeRemovalInformation( $nodeIDs );
        return array( 'canRemoveAll' => is_array( $info ) && !empty( $info['can_remove_all'] ),
                      'children' => is_array( $info ) && isset( $info['total_child_count'] ) ? (int)$info['total_child_count'] : 0 );
    }

    /**
     * Moves the nodes $nodeIDs below $targetID, each with the kernel's checks of content/action MoveNode (move
     * from, create the class there, not into its own subtree) and the content move operation.
     *
     * @param int[] $nodeIDs
     * @param int $targetID
     * @return array { ok, items: [{ nodeId, ok, error, item }] }
     */
    public static function move( array $nodeIDs, $targetID )
    {
        $target = static::fetchNode( $targetID );
        if ( !$target || !$target->canRead() || (int)$target->attribute( 'node_id' ) <= 1 )
            return array( 'ok' => false, 'error' => ezpI18n::tr( 'extension/ezmultiupload', 'The location does not exist.' ) );
        $items = array();
        foreach ( $nodeIDs as $nodeID )
        {
            $node = static::readableItem( $nodeID );
            $error = '';
            if ( !$node )
                $error = ezpI18n::tr( 'extension/ezmultiupload', 'The item does not exist.' );
            else if ( static::isTopLevel( $node ) )
                $error = ezpI18n::tr( 'extension/ezmultiupload', 'A top-level location cannot be changed here.' );
            else if ( !$node->canMoveFrom() )
                $error = ezpI18n::tr( 'extension/ezmultiupload', 'You may not move this item.' );
            else if ( !$target->canMoveTo( (int)$node->object()->attribute( 'contentclass_id' ) ) )
                $error = ezpI18n::tr( 'extension/ezmultiupload', 'You may not create this type of content in that location.' );
            else if ( in_array( (int)$node->attribute( 'node_id' ), array_map( 'intval', $target->pathArray() ), true ) )
                $error = ezpI18n::tr( 'extension/ezmultiupload', 'An item cannot be moved below itself.' );
            if ( $error !== '' )
            {
                $items[] = array( 'nodeId' => (int)$nodeID, 'ok' => false, 'error' => $error );
                continue;
            }
            if ( (int)$node->attribute( 'parent_node_id' ) !== (int)$targetID )
            {
                $params = array( 'node_id' => (int)$nodeID, 'object_id' => (int)$node->attribute( 'contentobject_id' ),
                                 'new_parent_node_id' => (int)$targetID );
                if ( eZOperationHandler::operationIsAvailable( 'content_move' ) )
                    eZOperationHandler::execute( 'content', 'move', $params, null, true );
                else
                    eZContentOperationCollection::moveNode( $params['node_id'], $params['object_id'], $params['new_parent_node_id'] );
                eZContentObject::clearCache();
                $node = static::fetchNode( $nodeID );
            }
            $items[] = array( 'nodeId' => (int)$nodeID, 'ok' => true, 'error' => '', 'item' => $node ? static::itemInfo( $node ) : null );
        }
        return array( 'ok' => true, 'location' => static::locationInfo( $target, 'place' ), 'items' => $items );
    }

    /**
     * Adds $targetID as a further location of the objects of $nodeIDs, with the kernel's checks of content/action
     * AddAssignment (edit the object or manage locations; create the class there, or add a location there and read
     * the item) and the content addlocation operation.
     *
     * @param int[] $nodeIDs
     * @param int $targetID
     * @return array
     */
    public static function addLocation( array $nodeIDs, $targetID )
    {
        $target = static::fetchNode( $targetID );
        if ( !$target || !$target->canRead() || (int)$target->attribute( 'node_id' ) <= 1 )
            return array( 'ok' => false, 'error' => ezpI18n::tr( 'extension/ezmultiupload', 'The location does not exist.' ) );
        $user = eZUser::currentUser();
        $items = array();
        foreach ( $nodeIDs as $nodeID )
        {
            $node = static::readableItem( $nodeID );
            $object = $node ? $node->object() : null;
            $error = '';
            if ( !$object )
                $error = ezpI18n::tr( 'extension/ezmultiupload', 'The item does not exist.' );
            else if ( static::isTopLevel( $node ) )
                $error = ezpI18n::tr( 'extension/ezmultiupload', 'A top-level location cannot be changed here.' );
            else if ( !$object->checkAccess( 'edit' ) && !$user->hasManageLocations() )
                $error = ezpI18n::tr( 'extension/ezmultiupload', 'You may not change the locations of this item.' );
            else if ( !( $target->checkAccess( 'create', (int)$object->attribute( 'contentclass_id' ), static::nodeClassID( $target ) ) == 1
                         || $target->canAddLocation() ) )
                $error = ezpI18n::tr( 'extension/ezmultiupload', 'You may not create this type of content in that location.' );
            else
            {
                foreach ( $object->assignedNodes() as $assigned )
                {
                    if ( (int)$assigned->attribute( 'parent_node_id' ) === (int)$targetID )
                        $error = ezpI18n::tr( 'extension/ezmultiupload', 'The item is in that location already.' );
                }
            }
            if ( $error !== '' )
            {
                $items[] = array( 'nodeId' => (int)$nodeID, 'ok' => false, 'error' => $error );
                continue;
            }
            $params = array( 'node_id' => (int)$nodeID, 'object_id' => (int)$object->attribute( 'id' ), 'select_node_id_array' => array( (int)$targetID ) );
            if ( eZOperationHandler::operationIsAvailable( 'content_addlocation' ) )
                eZOperationHandler::execute( 'content', 'addlocation', $params, null, true );
            else
                eZContentOperationCollection::addAssignment( $params['node_id'], $params['object_id'], $params['select_node_id_array'] );
            eZContentObject::clearCache();
            $node = static::fetchNode( $nodeID );
            $items[] = array( 'nodeId' => (int)$nodeID, 'ok' => true, 'error' => '', 'item' => $node ? static::itemInfo( $node ) : null );
        }
        return array( 'ok' => true, 'location' => static::locationInfo( $target, 'place' ), 'items' => $items );
    }

    /**
     * Moves the objects of $nodeIDs to the trash: all their locations, each of which the user must be allowed to
     * remove (canRemove()), through the content delete operation with move_to_trash. As the kernel's remove view,
     * subtreeRemovalInformation() must allow all of them (can_remove_all); an item with anything below it and an
     * item with a top-level location are refused.
     *
     * @param int[] $nodeIDs
     * @return array
     */
    public static function remove( array $nodeIDs )
    {
        $items = array();
        foreach ( $nodeIDs as $nodeID )
        {
            $node = static::readableItem( $nodeID );
            $object = $node ? $node->object() : null;
            if ( !$object )
            {
                $items[] = array( 'nodeId' => (int)$nodeID, 'ok' => false, 'error' => ezpI18n::tr( 'extension/ezmultiupload', 'The item does not exist.' ) );
                continue;
            }
            $all = array();
            $visible = array();
            $allowed = true;
            foreach ( $object->assignedNodes() as $assigned )
            {
                $all[] = (int)$assigned->attribute( 'node_id' );
                if ( $assigned->canRead() )
                    $visible[] = (int)$assigned->attribute( 'node_id' );
                if ( !$assigned->canRemove() )
                    $allowed = false;
            }
            if ( $all && static::isTopLevel( $node ) )
            {
                $items[] = array( 'nodeId' => (int)$nodeID, 'ok' => false, 'error' => ezpI18n::tr( 'extension/ezmultiupload', 'A top-level location cannot be changed here.' ) );
                continue;
            }
            // as the kernel's remove view: every location with everything below it must be removable; an item with
            // anything below it is not an uploaded file and is removed in the content structure, with its confirmation
            $removal = $allowed && $all ? static::removalInfo( $all ) : array( 'canRemoveAll' => false, 'children' => 0 );
            if ( !$removal['canRemoveAll'] )
            {
                $items[] = array( 'nodeId' => (int)$nodeID, 'ok' => false, 'error' => ezpI18n::tr( 'extension/ezmultiupload', 'You may not remove this item.' ) );
                continue;
            }
            if ( $removal['children'] > 0 )
            {
                $items[] = array( 'nodeId' => (int)$nodeID, 'ok' => false, 'error' => ezpI18n::tr( 'extension/ezmultiupload', 'This item has items below it. Remove it in the content structure.' ) );
                continue;
            }
            if ( eZOperationHandler::operationIsAvailable( 'content_delete' ) )
                eZOperationHandler::execute( 'content', 'delete', array( 'node_id_list' => $all, 'move_to_trash' => 1 ), null, true );
            else
                eZContentOperationCollection::deleteObject( $all, true );
            eZContentObject::clearCache();
            $items[] = array( 'nodeId' => (int)$nodeID, 'ok' => true, 'error' => '', 'removed' => $visible );
        }
        return array( 'ok' => true, 'items' => $items );
    }

    // ---- ZIP files ----------------------------------------------------------------------------------------------

    /**
     * Whether ZIP files can be imported: the importer is there, the zip extension is loaded and
     * ezmultiupload.ini [ZipSettings] ZipImport is not disabled.
     *
     * @return bool
     */
    public static function zipEnabled()
    {
        if ( !class_exists( 'expMultiuploadZipImporter' ) || !class_exists( 'ZipArchive' ) )
            return false;
        $options = expMultiuploadZipImporter::iniOptions();
        return !isset( $options['enabled'] ) || (bool)$options['enabled'];
    }

    /**
     * Whether a file is a ZIP file: by its name (.zip) and its first bytes (a local file header or an empty
     * archive's end record), never by the name alone.
     *
     * @param string $name
     * @param string $path
     * @return bool
     */
    public static function isZipFile( $name, $path )
    {
        if ( !preg_match( '/\.zip\z/i', (string)$name ) || !is_file( $path ) )
            return false;
        $head = (string)file_get_contents( $path, false, null, 0, 4 );
        return $head === "PK\x03\x04" || $head === "PK\x05\x06";
    }

    /**
     * The importer's reason code as a translated message for the page.
     *
     * @param string $code
     * @return string
     */
    public static function zipReason( $code )
    {
        $t = function ( $s ) { return ezpI18n::tr( 'extension/ezmultiupload', $s ); };
        switch ( (string)$code )
        {
            case '': return '';
            case 'type_not_allowed': return $t( 'Skipped: this type of file is not taken.' );
            case 'too_big': return $t( 'Skipped: the file is too large.' );
            case 'unsafe_path': return $t( 'Skipped: the path is not safe.' );
            case 'symlink': return $t( 'Skipped: a link, not a file.' );
            case 'device': return $t( 'Skipped: a device, not a file.' );
            case 'too_deep': return $t( 'Skipped: nested too deeply.' );
            case 'encrypted': return $t( 'The ZIP file is encrypted. Encrypted ZIP files are not supported; unpack it and upload the files.' );
            case 'ratio_exceeded': return $t( 'Skipped: compressed suspiciously strongly (a ZIP bomb?).' );
            case 'too_many_entries': return $t( 'Skipped: the ZIP file has too many entries.' );
            case 'total_size_exceeded': return $t( 'Skipped: the files in the ZIP file are too large together.' );
            case 'system_file': return $t( 'Skipped: a file of the operating system or a hidden file.' );
            case 'unreadable': return $t( 'Skipped: the entry could not be read.' );
            case 'not_found': return $t( 'Skipped: the entry is not in the ZIP file.' );
            case 'invalid_zip': return $t( 'The file is not a ZIP file that can be read.' );
            case 'temp_dir': return $t( 'The ZIP file could not be unpacked on the server.' );
        }
        return $t( 'Skipped.' );
    }

    /**
     * The entries of expMultiuploadZipImporter::inspect() as the rows zipEntries() checks: the key is the entry's
     * path in the archive, the folders are its directories (a ZIP file inside the archive is a folder of its name
     * without ".zip"). A ZIP file inside that can be read is left out (its files are listed); one that is skipped is
     * listed with its reason. With extract()'s answer, each row gets its extracted file, or the reason it was skipped.
     *
     * @param array $inspect inspect()'s answer
     * @param array|null $extract extract()'s answer
     * @return array[]
     */
    public static function zipImporterEntries( array $inspect, $extract = null )
    {
        $files = array();
        $skipped = array();
        if ( is_array( $extract ) )
        {
            foreach ( isset( $extract['files'] ) ? $extract['files'] : array() as $file )
                $files[$file['relativePath']] = $file['path'];
            $skipped = isset( $extract['skipped'] ) ? $extract['skipped'] : array();
        }
        $rows = array();
        foreach ( isset( $inspect['entries'] ) ? $inspect['entries'] : array() as $entry )
        {
            if ( !empty( $entry['nested'] ) && $entry['status'] === 'ok' )
                continue;
            // the system's own files and hidden files are left out silently, as the page has always done
            if ( (string)$entry['reason'] === 'system_file' )
                continue;
            $parts = explode( '/', (string)$entry['path'] );
            array_pop( $parts );
            $dirs = array();
            foreach ( $parts as $part )
                if ( $part !== '' && $part !== '.' && $part !== '..' )
                    $dirs[] = preg_replace( '/\.zip\z/i', '', $part );
            $status = (string)$entry['status'];
            $code = (string)$entry['reason'];
            $file = null;
            if ( is_array( $extract ) && $status === 'ok' )
            {
                if ( isset( $files[$entry['path']] ) )
                    $file = $files[$entry['path']];
                else
                {
                    $status = 'skipped';
                    $code = isset( $skipped[$entry['path']] ) ? (string)$skipped[$entry['path']] : 'not_found';
                }
            }
            $rows[] = array( 'key' => (string)$entry['path'], 'name' => (string)$entry['name'], 'dirs' => $dirs,
                             'size' => (int)$entry['size'], 'mime' => (string)$entry['mime'], 'file' => $file,
                             'status' => $status === 'ok' ? 'ok' : 'skipped', 'reason' => self::zipReason( $code ), 'reasonCode' => $code );
        }
        return $rows;
    }

    /**
     * The class of the folder items "Keep the folder structure" makes (ezmultiupload.ini [ZipSettings] FolderClass).
     *
     * @return string
     */
    public static function folderClass()
    {
        $ini = eZINI::instance( 'ezmultiupload.ini' );
        return $ini->hasVariable( 'ZipSettings', 'FolderClass' ) ? (string)$ini->variable( 'ZipSettings', 'FolderClass' ) : 'folder';
    }

    /**
     * The existing folder item $name directly below $parent, or null.
     *
     * @param eZContentObjectTreeNode $parent
     * @param string $name
     * @return eZContentObjectTreeNode|null
     */
    protected static function childFolder( $parent, $name )
    {
        $list = eZContentObjectTreeNode::subTreeByNodeID( array( 'Depth' => 1, 'Limit' => 1, 'ClassFilterType' => 'include',
                                                                 'ClassFilterArray' => array( self::folderClass() ),
                                                                 'AttributeFilter' => array( array( 'name', '=', $name ) ) ),
                                                          $parent->attribute( 'node_id' ) );
        return $list ? $list[0] : null;
    }

    /**
     * Makes the folder item $name below $parent, after the create permission check for the folder class there.
     *
     * @param eZContentObjectTreeNode $parent
     * @param string $name
     * @return eZContentObjectTreeNode|string the folder, or why it was not made
     */
    protected static function makeFolder( $parent, $name )
    {
        $class = static::fetchClassByIdentifier( self::folderClass() );
        if ( !$class || !static::canCreateClass( $parent, $class ) )
            return ezpI18n::tr( 'extension/ezmultiupload', 'You may not create the folder %name in %location.', null,
                                array( '%name' => $name, '%location' => (string)$parent->attribute( 'name' ) ) );
        $object = eZContentFunctions::createAndPublishObject( array(
            'parent_node_id' => (int)$parent->attribute( 'node_id' ), 'class_identifier' => self::folderClass(),
            'creator_id' => (int)eZUser::currentUserID(), 'attributes' => array( 'name' => $name ) ) );
        $node = $object instanceof eZContentObject ? $object->mainNode() : null;
        return $node instanceof eZContentObjectTreeNode ? $node
            : ezpI18n::tr( 'extension/ezmultiupload', 'The folder %name could not be created.', null, array( '%name' => $name ) );
    }

    /**
     * The location an entry goes to: the target (flat), or the folder items of its folders below it (keep), made
     * when $create and missing. Without $create a missing folder gives the nearest existing one and 'missing'.
     *
     * @param eZContentObjectTreeNode $node the target
     * @param string[] $dirs
     * @param bool $create
     * @param array $cache path => node or reason, kept over the entries of one request
     * @return array{node: eZContentObjectTreeNode|null, missing: bool, error: string, path: string}
     */
    public static function entryLocation( $node, array $dirs, $create, array &$cache )
    {
        $current = $node;
        $path = '';
        $missing = false;
        foreach ( $dirs as $dir )
        {
            $name = self::cleanText( $dir );
            if ( $name === '' )
                continue;
            $path .= '/' . $name;
            if ( !array_key_exists( $path, $cache ) )
            {
                $found = $missing ? null : static::childFolder( $current, $name );
                if ( !$found && $create )
                    $found = static::makeFolder( $current, $name );
                $cache[$path] = $found;
            }
            $found = $cache[$path];
            if ( is_string( $found ) )
                return array( 'node' => null, 'missing' => false, 'error' => $found, 'path' => $path );
            if ( !$found )
            {
                $missing = true;
                continue;
            }
            $current = $found;
        }
        return array( 'node' => $current, 'missing' => $missing, 'error' => '', 'path' => $path );
    }

    /**
     * The entries of a walked archive as the page lists them, each with the class it becomes (by its content), and
     * the checks of a single file: the location's types, the size limits, an image by its content, the create
     * permission where it goes, a required alternative text (refused: no field for it in a ZIP file), and a file
     * with the same name and size there already (also twice in the archive).
     *
     * @param eZContentObjectTreeNode $node the target
     * @param array $entries zipImporterEntries()
     * @param string $mode 'keep' or 'flat'
     * @param bool $create make the folders (import) or only look (inspection)
     * @param array $folderCache
     * @return array[]
     */
    public static function zipEntries( $node, array $entries, $mode, $create, array &$folderCache )
    {
        $types = self::fileTypes( $node );
        $maxSize = self::maxFileSize();
        $classes = array();
        $existing = array();
        $seen = array();
        $out = array();
        foreach ( $entries as $entry )
        {
            $row = array( 'key' => $entry['key'], 'name' => $entry['name'], 'path' => $entry['key'],
                          'folder' => '', 'size' => (int)$entry['size'], 'class' => '', 'className' => '', 'status' => $entry['status'],
                          'reason' => $entry['reason'], 'duplicate' => null, 'file' => $entry['file'], 'location' => null );
            if ( $row['status'] !== 'ok' )
            {
                $out[] = $row;
                continue;
            }
            // the type the importer found by content; again from the extracted file when there is one
            $mime = !empty( $entry['file'] ) ? static::mimeOfFile( $entry['file'], $entry['name'] ) : ( isset( $entry['mime'] ) ? (string)$entry['mime'] : self::mimeType( $entry['name'] ) );
            $detected = self::classForMime( $mime );
            if ( !array_key_exists( $detected['identifier'], $classes ) )
                $classes[$detected['identifier']] = static::fetchClassByIdentifier( $detected['identifier'] );
            $class = $classes[$detected['identifier']];
            $row['class'] = $detected['identifier'];
            $row['className'] = $class ? (string)$class->attribute( 'name' ) : $detected['identifier'];
            $row['folder'] = $mode === 'keep' ? implode( ' / ', $entry['dirs'] ) : '';
            $limit = $class ? self::smallestLimit( array( $maxSize, static::classMaxSize( $class ) ) ) : $maxSize;
            $reason = '';
            $where = array( 'node' => $node, 'missing' => false, 'error' => '', 'path' => '' );
            // the checks that do not depend on the location come first: the folders of an entry are looked up (and,
            // on import, made) only for an entry that passes them, so a refused entry leaves no empty folder behind
            if ( !$class )
                $reason = ezpI18n::tr( 'extension/ezmultiupload', 'The content class %class does not exist.', null, array( '%class' => $detected['identifier'] ) );
            else if ( !self::typeAllowed( $entry['name'], $types ) )
                $reason = ezpI18n::tr( 'extension/ezmultiupload', 'Skipped: this type of file is not taken in this location.' );
            else if ( $limit && $row['size'] > $limit )
                $reason = ezpI18n::tr( 'extension/ezmultiupload', 'Skipped: the file is larger than %max.', null, array( '%max' => self::sizeText( $limit ) ) );
            else if ( !empty( $entry['file'] ) && !static::contentMatches( $class, $entry['file'], $entry['name'] ) )
                $reason = ezpI18n::tr( 'extension/ezmultiupload', 'Skipped: the file is not an image by its content.' );
            else if ( static::altTextRequired( $class ) )
                $reason = ezpI18n::tr( 'extension/ezmultiupload', 'Skipped: this image needs an alternative text; upload it on its own.' );
            else
            {
                if ( $mode === 'keep' )
                    $where = static::entryLocation( $node, $entry['dirs'], $create, $folderCache );
                if ( $where['error'] !== '' )
                    $reason = $where['error'];
                else if ( !static::canCreateClass( $where['node'], $class ) )
                    $reason = ezpI18n::tr( 'extension/ezmultiupload', 'You may not create %class content in this location.', null, array( '%class' => $row['className'] ) );
            }
            $dest = $where['node'];
            if ( $reason !== '' )
            {
                $row['status'] = 'skipped';
                $row['reason'] = $reason;
                $out[] = $row;
                continue;
            }
            $row['location'] = $dest;
            $destKey = $where['missing'] ? 'new:' . $where['path'] : (string)$dest->attribute( 'node_id' );
            if ( !$where['missing'] && !isset( $existing[$destKey] ) )
            {
                $existing[$destKey] = array();
                foreach ( static::childFiles( $dest ) as $file )
                    $existing[$destKey][strtolower( $file['name'] ) . '|' . $file['size']] = $file;
            }
            $key = strtolower( $entry['name'] ) . '|' . $row['size'];
            if ( isset( $existing[$destKey][$key] ) )
                $row['duplicate'] = $existing[$destKey][$key];
            else if ( isset( $seen[$destKey][strtolower( $entry['name'] )] ) )
                $row['duplicate'] = array( 'name' => $entry['name'], 'size' => $row['size'], 'nodeId' => 0, 'title' => $seen[$destKey][strtolower( $entry['name'] )], 'url' => '' );
            $seen[$destKey][strtolower( $entry['name'] )] = $row['path'];
            $out[] = $row;
        }
        return $out;
    }

    /**
     * The MIME type of a file by its content (as the kernel's local file upload finds it).
     *
     * @param string $path
     * @param string $name
     * @return string
     */
    protected static function mimeOfFile( $path, $name )
    {
        $mime = eZMimeType::findByFileContents( $path );
        $type = is_array( $mime ) && !empty( $mime['name'] ) ? (string)$mime['name'] : '';
        return $type !== '' && $type !== 'application/octet-stream' ? $type : self::mimeType( $name );
    }

    /**
     * Whether a file meant for an image class is an image by its content (expImageUploadValidator, L05); true for
     * other classes and on kernels without the validator (the kernel's upload checks again).
     *
     * @param eZContentClass $class
     * @param string $path
     * @param string $name
     * @return bool
     */
    protected static function contentMatches( $class, $path, $name )
    {
        if ( !class_exists( 'expImageUploadValidator' ) || $class->attribute( 'identifier' ) !== 'image' )
            return true;
        $check = expImageUploadValidator::validate( $path, $name );
        return !empty( $check['valid'] );
    }

    /**
     * The rows of an inspection for the page (the server's paths and nodes left out), and the counts.
     *
     * @param array $rows zipEntries()
     * @return array{entries: array[], ok: int, skipped: int, duplicates: int}
     */
    public static function zipSummary( array $rows )
    {
        $list = array();
        $ok = $skipped = $dups = 0;
        foreach ( $rows as $row )
        {
            unset( $row['file'], $row['location'] );
            if ( $row['status'] === 'ok' )
                $ok++;
            else
                $skipped++;
            if ( $row['duplicate'] )
                $dups++;
            $list[] = $row;
        }
        return array( 'entries' => $list, 'ok' => $ok, 'skipped' => $skipped, 'duplicates' => $dups );
    }

    /**
     * Imports the entries of an inspection: those in $selected (all when null) that passed, each through the
     * kernel's local file upload into its location, a file that is there already as $policy says (skip, rename or
     * upload), as drafts when $keepDraft.
     *
     * @param array $rows zipEntries() with $create = true
     * @param string[]|null $selected entry keys
     * @param string $policy 'skip', 'rename' or 'upload'
     * @param bool $keepDraft
     * @return array[] per entry { key, path, status, reason, item }
     */
    public static function zipImport( array $rows, $selected, $policy, $keepDraft )
    {
        $results = array();
        $taken = array();
        foreach ( $rows as $row )
        {
            $result = array( 'key' => $row['key'], 'path' => $row['path'], 'status' => $row['status'], 'reason' => $row['reason'], 'item' => null );
            if ( $selected !== null && !in_array( $row['key'], $selected, true ) )
            {
                $result['status'] = 'unselected';
                $result['reason'] = '';
                $results[] = $result;
                continue;
            }
            if ( $row['status'] !== 'ok' )
            {
                $results[] = $result;
                continue;
            }
            $dest = $row['location'];
            $destID = (int)$dest->attribute( 'node_id' );
            $name = $row['name'];
            if ( !isset( $taken[$destID] ) )
            {
                $taken[$destID] = array();
                foreach ( static::childFiles( $dest ) as $file )
                    $taken[$destID][] = strtolower( $file['name'] );
            }
            if ( $row['duplicate'] )
            {
                if ( $policy === 'skip' )
                {
                    $result['status'] = 'skipped';
                    $result['reason'] = ezpI18n::tr( 'extension/ezmultiupload', 'Skipped: a file with this name and size is there already.' );
                    $results[] = $result;
                    continue;
                }
                if ( $policy === 'rename' )
                    $name = self::freeName( $name, $taken[$destID] );
            }
            $taken[$destID][] = strtolower( $name );
            $file = $row['file'];
            if ( $name !== $row['name'] )
            {
                $renamed = dirname( $file ) . '/' . preg_replace( '/[\/\\\\]+/', '_', $name );
                if ( @rename( $file, $renamed ) )
                    $file = $renamed;
            }
            $dot = strrpos( $name, '.' );
            $title = $dot > 0 ? substr( $name, 0, $dot ) : $name;
            $upload = new eZContentUpload();
            $outcome = array();
            $made = $upload->handleLocalFile( $outcome, $file, $destID, false, $title, false, !$keepDraft );
            $object = is_array( $made ) && isset( $made['contentobject'] ) && $made['contentobject'] instanceof eZContentObject ? $made['contentobject']
                : ( isset( $outcome['contentobject'] ) && $outcome['contentobject'] instanceof eZContentObject ? $outcome['contentobject'] : null );
            if ( !$object )
            {
                $errors = array();
                foreach ( isset( $outcome['errors'] ) ? $outcome['errors'] : array() as $error )
                    $errors[] = is_array( $error ) && isset( $error['description'] ) ? (string)$error['description'] : (string)$error;
                $result['status'] = 'failed';
                $result['reason'] = $errors ? implode( ' ', $errors ) : ezpI18n::tr( 'extension/ezmultiupload', 'The file could not be stored.' );
                $results[] = $result;
                continue;
            }
            $version = is_array( $made ) && isset( $made['contentobject_version'] ) ? (int)$made['contentobject_version'] : (int)$object->attribute( 'current_version' );
            if ( !$keepDraft )
                $object = eZContentObject::fetch( (int)$object->attribute( 'id' ) );
            $result['status'] = $keepDraft ? 'draft' : 'done';
            $result['item'] = self::createdItem( $object, $version, $keepDraft );
            $results[] = $result;
        }
        return $results;
    }

    /**
     * A new object as the page lists it: published (its node, locations, view and edit addresses) or a draft (its
     * edit address only).
     *
     * @param eZContentObject $object
     * @param int $version
     * @param bool $draft
     * @return array
     */
    public static function createdItem( $object, $version, $draft )
    {
        $node = $draft ? null : $object->mainNode();
        if ( $node instanceof eZContentObjectTreeNode )
        {
            $info = static::itemInfo( $node );
            $info['draft'] = false;
        }
        else
        {
            $info = array( 'nodeId' => 0, 'objectId' => (int)$object->attribute( 'id' ), 'name' => (string)$object->attribute( 'name' ),
                           'url' => '', 'editUrl' => static::url( 'content/edit/' . (int)$object->attribute( 'id' ) . '/' . (int)$version ),
                           'locations' => array(), 'draft' => true );
        }
        $class = $object->contentClass();
        $info['className'] = $class ? (string)$class->attribute( 'name' ) : '';
        $info['class'] = (string)$object->attribute( 'class_identifier' );
        return $info;
    }

    // ---- the page -----------------------------------------------------------------------------------------------

    /**
     * The remembered location (the eZPreference PREFERENCE), when it is a location files can be uploaded to and
     * not $currentID; else null.
     *
     * @param int $currentID
     * @return array|null
     */
    public static function rememberedLocation( $currentID )
    {
        $id = self::positiveId( (string)eZPreferences::value( self::PREFERENCE ) );
        if ( !$id || $id === (int)$currentID )
            return null;
        $node = static::fetchNode( $id );
        if ( !$node || static::uploadTarget( $node ) !== true )
            return null;
        return static::locationInfo( $node );
    }

    /**
     * When files cannot be uploaded to $node: the nearest location above it that takes them (a page opened from the
     * tree menu on an article offers its folder), else null.
     *
     * @param eZContentObjectTreeNode $node
     * @return array|null
     */
    public static function suggestedLocation( $node )
    {
        if ( static::uploadTarget( $node ) === true )
            return null;
        $current = $node;
        for ( $i = 0; $i < 12; $i++ )
        {
            $parentID = (int)$current->attribute( 'parent_node_id' );
            if ( $parentID <= 1 )
                return null;
            $current = static::fetchNode( $parentID );
            if ( !$current )
                return null;
            if ( static::uploadTarget( $current ) === true )
                return static::locationInfo( $current );
        }
        return null;
    }

    /**
     * The texts of the page's script, translated.
     *
     * @return string[]
     */
    public static function clientTexts()
    {
        $t = function ( $s ) { return ezpI18n::tr( 'extension/ezmultiupload', $s ); };
        return array(
            'queued' => $t( 'Queued' ), 'checking' => $t( 'Checking' ), 'uploading' => $t( 'Uploading' ), 'done' => $t( 'Done' ),
            'draft' => $t( 'Saved as draft' ), 'failed' => $t( 'Failed' ), 'canceled' => $t( 'Canceled' ), 'blocked' => $t( 'Not allowed' ),
            'skipped' => $t( 'Skipped' ), 'remove' => $t( 'Remove from the queue' ), 'cancel' => $t( 'Cancel' ), 'retry' => $t( 'Retry' ),
            'removeName' => $t( 'Remove %name from the queue' ), 'cancelName' => $t( 'Cancel the upload of %name' ), 'retryName' => $t( 'Retry the upload of %name' ),
            'title' => $t( 'Title' ), 'titleOf' => $t( 'Title of %name' ), 'alt' => $t( 'Alternative text' ), 'altOf' => $t( 'Alternative text of %name' ),
            'altRequired' => $t( 'An alternative text is required for this image.' ), 'required' => $t( 'required' ),
            'titleRequired' => $t( 'A title is required.' ),
            'sameLocation' => $t( 'As above' ), 'change' => $t( 'Change' ), 'changeFor' => $t( 'Change the location of %name' ),
            'reset' => $t( 'Use the queue location' ), 'tooLarge' => $t( 'The file is larger than %max.' ), 'wrongType' => $t( 'This type of file is not taken in this location.' ),
            'duplicate' => $t( 'A file with this name and size is already in this location: %link' ),
            'ifExists' => $t( 'If it exists' ), 'ifExistsOf' => $t( 'If %name exists already' ), 'dupAsk' => $t( 'Ask' ), 'dupSkip' => $t( 'Skip' ),
            'dupRename' => $t( 'Rename to %name' ), 'dupUpload' => $t( 'Upload anyway' ),
            'dupTitle' => $t( 'Files that are there already' ),
            'dupText' => $t( '%count files have the same name and size as a file in their location. What should happen to them?' ),
            'dupTextOne' => $t( 'One file has the same name and size as a file in its location. What should happen to it?' ),
            'dupSkipAll' => $t( 'Skip them' ), 'dupRenameAll' => $t( 'Rename them' ), 'dupUploadAll' => $t( 'Upload anyway' ),
            'pickTitle' => $t( 'Choose a location' ), 'pickThis' => $t( 'Choose this location' ), 'pickOpen' => $t( 'Open %name' ),
            'pickChoose' => $t( 'Choose %name' ), 'pickFilter' => $t( 'Filter by name' ), 'pickEmpty' => $t( 'No locations below this one.' ),
            'pickLoading' => $t( 'Loading...' ), 'pickCrumbs' => $t( 'Path' ), 'pickNotAllowed' => $t( 'Not allowed: %reason' ),
            'cancelDialog' => $t( 'Cancel' ),
            'notAllowed' => $t( 'Files cannot be uploaded here: %reason' ),
            'summary' => $t( '%done uploaded, %failed failed, %skipped skipped.' ),
            'summaryOk' => $t( '%done files uploaded.' ),
            'moved' => $t( '%count moved to %location.' ), 'placed' => $t( '%count placed in %location as well.' ),
            'removed' => $t( '%count moved to the trash.' ), 'actionFailed' => $t( '%count could not be changed: %reason' ),
            'removeConfirm' => $t( 'Move %count items to the trash? Every location of them is removed.' ),
            'removeConfirmOne' => $t( 'Move %name to the trash? Every location of it is removed.' ),
            'removeOk' => $t( 'Move to the trash' ),
            'select' => $t( 'Select %name' ), 'view' => $t( 'View' ), 'edit' => $t( 'Edit' ), 'editDraft' => $t( 'Edit the draft' ),
            'viewName' => $t( 'View %name' ), 'editName' => $t( 'Edit %name' ),
            'draftNote' => $t( 'Draft: not published yet' ), 'noNetwork' => $t( 'No answer from the server.' ),
            'serverError' => $t( 'The server answered with an error (HTTP %status).' ), 'signedOut' => $t( 'You are no longer signed in. Sign in again and repeat this.' ),
            'invalid' => $t( 'The server sent an answer that could not be read.' ),
            'pasted' => $t( 'Pasted image' ), 'queuedCount' => $t( '%count files in the queue' ), 'queuedOne' => $t( 'One file in the queue' ),
            'queueEmpty' => $t( 'No files in the queue.' ), 'resultEmpty' => $t( 'Nothing uploaded yet.' ),
            'added' => $t( '%count files added to the queue.' ), 'locationChanged' => $t( 'The location is now %location.' ),
            'selectedCount' => $t( '%count selected' ),
            'noFiles' => $t( 'No files to upload.' ), 'dismiss' => $t( 'Dismiss' ), 'useLast' => $t( 'Use the last location: %location' ),
            'useSuggested' => $t( 'Upload to %location instead' ),
            'notAllowedChoose' => $t( '%reason Choose another location with Change location.' ),
            'inspecting' => $t( 'Reading the ZIP file' ), 'zipFile' => $t( 'ZIP file' ),
            'zipCount' => $t( '%ok files to upload, %skipped skipped' ), 'zipDone' => $t( '%done uploaded, %skipped skipped' ),
            'zipNothing' => $t( 'Nothing in the ZIP file can be uploaded here.' ),
            'zipDuplicates' => $t( '%count files of the ZIP file are there already (same name and size).' ),
            'zipAsFile' => $t( 'Will be uploaded as a file' ), 'zipWillImport' => $t( 'Contents will be imported' ),
            'zipRowImport' => $t( 'Import the contents of this ZIP file' ),
            'dupNote' => $t( 'There already' ), 'unselected' => $t( 'Not selected' )
        );
    }

    /**
     * The configuration of the page's script for the location $node.
     *
     * @param eZContentObjectTreeNode $node
     * @return array
     */
    public static function pageConfig( $node )
    {
        $nodeID = (int)$node->attribute( 'node_id' );
        return array(
            'location' => static::locationInfo( $node ),
            'remembered' => static::rememberedLocation( $nodeID ),
            'suggested' => static::suggestedLocation( $node ),
            'preference' => self::PREFERENCE,
            'preferenceUrl' => static::url( 'user/preferences/set_and_exit' ),
            'uploadUrl' => static::url( 'ezmultiupload/upload' ),
            'serviceUrl' => static::url( 'ezmultiupload/service' ),
            'maxSize' => self::maxFileSize(),
            'maxSizeText' => self::maxFileSize() ? self::sizeText( self::maxFileSize() ) : '',
            'fileTypes' => self::fileTypes( $node ),
            'parallel' => 3,
            'zipEnabled' => self::zipEnabled(),
            'zipPreference' => 'exp_multiupload_zip_mode',
            'zipMode' => eZPreferences::value( 'exp_multiupload_zip_mode' ) === 'flat' ? 'flat' : 'keep',
            // "Import compressed zip file(s) contents": off unless the user turned it on (eZPreference 1)
            'zipImport' => (string)eZPreferences::value( 'exp_multiupload_zip_import' ) === '1',
            'zipImportPreference' => 'exp_multiupload_zip_import',
            'texts' => self::clientTexts()
        );
    }

    /**
     * $data as JSON that is safe inside a <script type="application/json"> element and in HTML attributes: <, >,
     * &, ' and " are written as \u escapes.
     *
     * @param mixed $data
     * @return string
     */
    public static function json( $data )
    {
        $json = json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
        return $json === false ? 'null' : $json;
    }
}
