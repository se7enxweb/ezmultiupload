<?php
/**
 * expMultiuploadService: what its answers tell about locations and items the user cannot read, and when a request
 * without a form token is taken. Nodes, objects and the kernel's few static helpers are held in memory; no database,
 * no kernel. The kernel's classes are stood in for only when they are not loaded; with the kernel loaded the test is
 * skipped (it runs on its own: php vendor/bin/phpunit --no-configuration extension/ezmultiupload/tests/...).
 *
 *  SA-01 - check() on a location the user cannot read gives no name and no path, and the same reason as one that
 *          does not exist; uploadTarget() answers both alike
 *  SA-02 - a location's path and the picker's path leave out the locations above it the user cannot read
 *  SA-03 - move, addlocation and remove answer an item the user cannot read as one that does not exist
 *  SA-04 - an item's other locations are listed only where the user can read them
 *  SA-05 - without a form token in use, a request is taken only with Sec-Fetch-Site same-origin or none, or, where
 *          the browser sends no Sec-Fetch-Site, with an Origin of this host
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezmultiupload
 */

namespace
{
    if ( !class_exists( 'eZINI', false ) && !class_exists( 'ezpI18n', false ) )
    {
        define( 'EXP_MULTIUPLOAD_SERVICE_ACCESS_STUBS', true );

        class ezpI18n
        {
            public static function tr( $context, $source, $comment = null, $arguments = null )
            {
                return is_array( $arguments ) ? strtr( $source, $arguments ) : $source;
            }
        }

        class eZINI
        {
            public static $values = array( 'ezmultiupload.ini' => array( 'MultiUploadSettings' => array(
                'AvailableClasses' => array( 'folder' ), 'AvailableSubtreeNode' => array(), 'AnyContainer' => 'enabled' ) ) );
            private $file;
            public static function instance( $file = 'site.ini' ) { $ini = new self(); $ini->file = $file; return $ini; }
            public function hasGroup( $group ) { return isset( self::$values[$this->file][$group] ); }
            public function hasVariable( $group, $name ) { return isset( self::$values[$this->file][$group][$name] ); }
            public function variable( $group, $name ) { return $this->hasVariable( $group, $name ) ? self::$values[$this->file][$group][$name] : false; }
        }

        class eZURI
        {
            public static function transformURI( &$url ) { $url = '/' . ltrim( $url, '/' ); return true; }
        }

        class eZUser
        {
            public static function currentUser() { return new self(); }
            public function hasManageLocations() { return false; }
            public function hasAccessTo( $module, $function = false ) { return array( 'accessWord' => 'yes' ); }
            public static function isCurrentUserRegistered() { return true; }
        }

        class eZContentObjectTreeNode
        {
            public static function subTreeByNodeID( $params = array(), $nodeID = 0 ) { return array(); }
        }
    }

    require_once dirname( __DIR__ ) . '/classes/expmultiuploadservice.php';

    /** A node in memory */
    class expMultiuploadAccessNode extends eZContentObjectTreeNode
    {
        public $values;
        public $readable = true;
        public $creatable = true;
        public $path = array();
        public $parent = null;
        public $contentObject = null;
        public $movable = false;
        public $removable = false;

        public function __construct( array $values ) { $this->values = $values; }
        public function attribute( $name, $noFunction = false ) { return isset( $this->values[$name] ) ? $this->values[$name] : null; }
        public function canRead() { return $this->readable; }
        public function canCreate() { return $this->creatable; }
        public function fetchPath() { return $this->path; }
        public function fetchParent() { return $this->parent; }
        public function object() { return $this->contentObject; }
        public function canMoveFrom() { return $this->movable; }
        public function canMoveTo( $classID = false ) { return true; }
        public function canRemove() { return $this->removable; }
        public function canAddLocation() { return false; }
        public function checkAccess( $function, $classID = false, $parentClassID = false ) { return 0; }
        public function pathArray() { return array( 1, (int)$this->attribute( 'node_id' ) ); }
    }

    /** An object in memory */
    class expMultiuploadAccessObject
    {
        public $values;
        public $nodes = array();
        public function __construct( array $values ) { $this->values = $values; }
        public function attribute( $name ) { return isset( $this->values[$name] ) ? $this->values[$name] : null; }
        public function assignedNodes() { return $this->nodes; }
        public function checkAccess( $function ) { return 0; }
        public function dataMap() { return array(); }
        public function contentClass() { return null; }
    }

    /** The service with its nodes in memory and no form token in use unless set */
    class expMultiuploadAccessService extends expMultiuploadService
    {
        public static $nodes = array();
        public static $token = null;
        protected static function fetchNode( $nodeID ) { return isset( self::$nodes[$nodeID] ) ? self::$nodes[$nodeID] : null; }
        protected static function expectedToken() { return self::$token; }
        protected static function isContainer( $node ) { return true; }
        protected static function imageUrl( $object ) { return ''; }
    }

    class expMultiuploadServiceAccessTest extends PHPUnit\Framework\TestCase
    {
        protected function setUp(): void
        {
            if ( !defined( 'EXP_MULTIUPLOAD_SERVICE_ACCESS_STUBS' ) )
                $this->markTestSkipped( 'Runs without the kernel: php vendor/bin/phpunit --no-configuration ' . __FILE__ );
            expMultiuploadAccessService::$nodes = array();
            expMultiuploadAccessService::$token = null;
        }

        private function node( $id, $name, $readable = true, $parentID = 1 )
        {
            $node = new expMultiuploadAccessNode( array( 'node_id' => $id, 'name' => $name, 'parent_node_id' => $parentID,
                                                         'class_identifier' => 'folder', 'contentobject_id' => $id * 10 ) );
            $node->readable = $readable;
            return expMultiuploadAccessService::$nodes[$id] = $node;
        }

        /** An item with an object, at $id */
        private function item( $id, $name, $readable = true )
        {
            $node = $this->node( $id, $name, $readable );
            $object = new expMultiuploadAccessObject( array( 'id' => $id * 10, 'name' => $name, 'main_node_id' => $id,
                                                             'contentclass_id' => 5 ) );
            $object->nodes = array( $node );
            $node->contentObject = $object;
            return $node;
        }

        public function testCheckTellsNothingAboutAnUnreadableLocation()
        {
            $secret = $this->node( 60, 'Secret board minutes', false );
            $secret->path = array( $this->node( 59, 'Board', false ) );

            $hidden = expMultiuploadAccessService::check( 60, array( array( 'name' => 'a.jpg', 'size' => 1 ) ) );
            $missing = expMultiuploadAccessService::check( 61, array( array( 'name' => 'a.jpg', 'size' => 1 ) ) );
            $this->assertSame( '', $hidden['location']['name'] );
            $this->assertSame( array(), $hidden['location']['path'] );
            $this->assertSame( '', $hidden['location']['url'] );
            $this->assertFalse( $hidden['location']['allowed'] );
            unset( $hidden['location']['id'], $missing['location']['id'] );
            $this->assertSame( $missing, $hidden, 'an unreadable location is answered as a missing one' );
            $this->assertSame( expMultiuploadAccessService::uploadTarget( null ), expMultiuploadAccessService::uploadTarget( $secret ) );
        }

        public function testPathsLeaveOutUnreadableLocationsAbove()
        {
            $top = $this->node( 70, 'Hidden department', false );
            $mid = $this->node( 71, 'Team', true, 70 );
            $here = $this->node( 72, 'Images', true, 71 );
            $here->path = array( $this->node( 1, 'root' ), $top, $mid );

            $info = expMultiuploadAccessService::locationInfo( $here );
            $this->assertSame( array( 'Team', 'Images' ), $info['path'] );
            $this->assertStringNotContainsString( 'Hidden department', $info['pathText'] );

            $browse = expMultiuploadAccessService::browse( 72 );
            $this->assertTrue( $browse['ok'] );
            $this->assertSame( array( 1, 71, 72 ), array_column( $browse['crumbs'], 'id' ) );
        }

        public function testActionsAnswerAnUnreadableItemAsMissing()
        {
            $this->node( 80, 'Target' );
            $hidden = $this->item( 81, 'Hidden item', false );
            $hidden->movable = false;
            $hidden->removable = false;

            $missing = 'The item does not exist.';
            $moved = expMultiuploadAccessService::move( array( 81, 82 ), 80 );
            $this->assertSame( array( $missing, $missing ), array_column( $moved['items'], 'error' ) );
            $placed = expMultiuploadAccessService::addLocation( array( 81, 82 ), 80 );
            $this->assertSame( array( $missing, $missing ), array_column( $placed['items'], 'error' ) );
            $removed = expMultiuploadAccessService::remove( array( 81, 82 ) );
            $this->assertSame( array( $missing, $missing ), array_column( $removed['items'], 'error' ) );
        }

        public function testItemInfoListsReadableLocationsOnly()
        {
            $item = $this->item( 90, 'Photo' );
            $item->parent = $this->node( 91, 'Public folder' );
            $item->values['parent_node_id'] = 91;
            $item->parent->path = array();
            $other = $this->node( 92, 'Photo', false, 93 );
            $other->parent = $this->node( 93, 'Hidden folder', false );
            $third = $this->node( 94, 'Photo', true, 95 );
            $third->parent = $this->node( 95, 'Hidden parent', false );
            $item->object()->nodes = array( $item, $other, $third );

            $info = expMultiuploadAccessService::itemInfo( $item );
            $this->assertSame( array( 90, 94 ), array_column( $info['locations'], 'nodeId' ) );
            $this->assertSame( array( 'Public folder', '' ), array_column( $info['locations'], 'pathText' ) );
            $this->assertSame( array( 91, 0 ), array_column( $info['locations'], 'parentId' ) );
        }

        public function testWithoutFormTokenOnlySameOriginIsTaken()
        {
            expMultiuploadAccessService::$token = null;
            $this->assertIsString( expMultiuploadAccessService::requestAllowed( 'POST', null, null ) );
            $this->assertIsString( expMultiuploadAccessService::requestAllowed( 'POST', '', null ) );
            $this->assertIsString( expMultiuploadAccessService::requestAllowed( 'POST', 'same-site', null ) );
            $this->assertIsString( expMultiuploadAccessService::requestAllowed( 'POST', 'cross-site', null ) );
            $this->assertTrue( expMultiuploadAccessService::requestAllowed( 'POST', 'same-origin', null ) );
            $this->assertTrue( expMultiuploadAccessService::requestAllowed( 'POST', 'none', null ) );

            // no Sec-Fetch-Site (plain HTTP, older browsers): the Origin must name this host
            $this->assertTrue( expMultiuploadAccessService::requestAllowed( 'POST', null, null, 'https://cms.example.org', 'cms.example.org' ) );
            $this->assertTrue( expMultiuploadAccessService::requestAllowed( 'POST', null, null, 'http://cms.example.org:8080', 'CMS.example.org:8080' ) );
            $this->assertTrue( expMultiuploadAccessService::requestAllowed( 'POST', null, null, 'https://cms.example.org:443', 'cms.example.org' ) );
            $this->assertIsString( expMultiuploadAccessService::requestAllowed( 'POST', null, null, 'https://evil.example.net', 'cms.example.org' ) );
            $this->assertIsString( expMultiuploadAccessService::requestAllowed( 'POST', null, null, 'https://cms.example.org.evil.net', 'cms.example.org' ) );
            $this->assertIsString( expMultiuploadAccessService::requestAllowed( 'POST', null, null, 'null', 'cms.example.org' ) );
            $this->assertIsString( expMultiuploadAccessService::requestAllowed( 'POST', null, null, null, 'cms.example.org' ) );
            // a Sec-Fetch-Site that is sent decides, whatever the Origin says
            $this->assertIsString( expMultiuploadAccessService::requestAllowed( 'POST', 'cross-site', null, 'https://cms.example.org', 'cms.example.org' ) );

            expMultiuploadAccessService::$token = 'session-token';
            $this->assertTrue( expMultiuploadAccessService::requestAllowed( 'POST', null, 'session-token' ) );
            $this->assertIsString( expMultiuploadAccessService::requestAllowed( 'POST', null, 'other' ) );
        }
    }
}
