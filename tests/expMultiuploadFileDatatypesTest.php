<?php
/**
 * The file datatypes of ezmultiupload's upload page (expMultiuploadService::fileDatatypes(), ezmultiupload.ini
 * [FileDatatypeSettings]): which datatypes hold an uploaded file and where their name, size, size limit and preview
 * are read. Classes, attributes and contents are held in memory; no database.
 *
 *  FD-01 - the shipped settings give ezimage, ezbinaryfile and ezmedia with the values the service read before they
 *          were settings; without the group the built-in list applies
 *  FD-02 - a datatype added with FileDatatypes[] and its keys is read: the class's size limit from its field, the
 *          preview from its path; a key left out of a datatype of its own is not read
 *  FD-03 - contentValue() follows "attribute.key" paths and gives null for a missing part or a content that is no
 *          object
 *  FD-04 - a datatype that is not listed has no size limit and no preview
 *  FD-05 - a missing group, an empty list (only the reset entry) and a list of invalid names all fall back to the
 *          built-in list, and the size limit and preview of ezimage work again
 *  FD-06 - a setting that is no path of attribute names and keys is not read; neither is a field the attribute or
 *          content does not have
 *
 * Run: php vendor/bin/phpunit extension/ezmultiupload/tests/expMultiuploadFileDatatypesTest.php
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezmultiupload
 */

$expMultiuploadClass = dirname( __DIR__ ) . '/classes/expmultiuploadservice.php';
if ( is_file( $expMultiuploadClass ) )
    require_once $expMultiuploadClass;
unset( $expMultiuploadClass );

if ( class_exists( 'expMultiuploadService' ) && method_exists( 'expMultiuploadService', 'fileDatatypes' ) )
{
    /** An attribute or a content held in memory */
    class expMultiuploadFileDatatypesTestValue
    {
        public $values;
        public $content;

        public function __construct( array $values, $content = null )
        {
            $this->values = $values;
            $this->content = $content;
        }

        function attribute( $name )
        {
            return isset( $this->values[$name] ) ? $this->values[$name] : null;
        }

        function hasContent()
        {
            return $this->content !== null;
        }

        function content()
        {
            return $this->content;
        }
    }

    /** A content class or object held in memory */
    class expMultiuploadFileDatatypesTestClass extends eZContentClass
    {
        public $values;
        public $map;

        public function __construct( array $values, array $map )
        {
            $this->values = $values;
            $this->map = $map;
        }

        function attribute( $name, $noFunction = false )
        {
            return isset( $this->values[$name] ) ? $this->values[$name] : null;
        }

        function dataMap()
        {
            return $this->map;
        }
    }

    /** The service with its protected readers open */
    class expMultiuploadFileDatatypesTestService extends expMultiuploadService
    {
        public static function maxSize( $class )
        {
            return static::classMaxSize( $class );
        }

        public static function preview( $object )
        {
            return static::imageUrl( $object );
        }

        public static function value( $content, $path )
        {
            return static::contentValue( $content, $path );
        }
    }
}

class expMultiuploadFileDatatypesTest extends PHPUnit\Framework\TestCase
{
    /** @var string */
    private $cwd;

    protected function setUp(): void
    {
        if ( !class_exists( 'expMultiuploadService' ) || !method_exists( 'expMultiuploadService', 'fileDatatypes' ) )
            self::markTestSkipped( 'ezmultiupload with [FileDatatypeSettings] is not installed.' );
        $this->cwd = getcwd();
        chdir( dirname( __DIR__, 3 ) );
        eZINI::resetInstance( 'ezmultiupload.ini' );
        eZINI::resetInstance( 'upload.ini' );
    }

    protected function tearDown(): void
    {
        if ( $this->cwd !== null )
        {
            eZINI::resetInstance( 'ezmultiupload.ini' );
            eZINI::resetInstance( 'upload.ini' );
            chdir( $this->cwd );
        }
    }

    private static function ini( $file )
    {
        $ini = eZINI::instance( $file );
        $ini->setReadOnlySettingsCheck( false );
        return $ini;
    }

    /** FD-01 */
    public function testTheShippedSettingsAreTheBuiltInList()
    {
        $shipped = eZINI::instance( 'ezmultiupload.ini', 'extension/ezmultiupload/settings', null, null, null, true );
        $this->assertSame( array( 'ezimage', 'ezbinaryfile', 'ezmedia' ), $shipped->variable( 'FileDatatypeSettings', 'FileDatatypes' ) );

        $ini = self::ini( 'ezmultiupload.ini' );
        foreach ( array( 'FileDatatypes', 'NameAttribute', 'SizeAttribute', 'MaxSizeAttribute', 'PreviewAttribute' ) as $name )
            $ini->setVariable( 'FileDatatypeSettings', $name, $shipped->variable( 'FileDatatypeSettings', $name ) );
        $this->assertSame( expMultiuploadService::FILE_DATATYPES, expMultiuploadService::fileDatatypes() );

        $ini->removeGroup( 'FileDatatypeSettings' );
        $this->assertSame( expMultiuploadService::FILE_DATATYPES, expMultiuploadService::fileDatatypes() );
    }

    /** FD-02 */
    public function testADatatypeOfItsOwnIsRead()
    {
        $ini = self::ini( 'ezmultiupload.ini' );
        $ini->setVariable( 'FileDatatypeSettings', 'FileDatatypes', array( 'ezimage', 'ezbinaryfile', 'ezmedia', 'myfile' ) );
        $ini->setVariable( 'FileDatatypeSettings', 'NameAttribute', array( 'myfile' => 'filename' ) );
        $ini->setVariable( 'FileDatatypeSettings', 'SizeAttribute', array( 'myfile' => 'meta.bytes' ) );
        $ini->setVariable( 'FileDatatypeSettings', 'MaxSizeAttribute', array( 'myfile' => 'data_int3' ) );
        $ini->setVariable( 'FileDatatypeSettings', 'PreviewAttribute', array( 'myfile' => 'thumb.url' ) );

        $types = expMultiuploadService::fileDatatypes();
        $this->assertSame( array( 'name' => 'filename', 'size' => 'meta.bytes', 'maxsize' => 'data_int3', 'preview' => 'thumb.url' ), $types['myfile'] );
        $this->assertSame( expMultiuploadService::FILE_DATATYPES['ezimage'], $types['ezimage'], 'a kernel datatype keeps its built-in values' );

        self::ini( 'upload.ini' )->setVariable( 'document_ClassSettings', 'FileAttribute', 'file' );
        $class = new expMultiuploadFileDatatypesTestClass( array( 'identifier' => 'document' ), array(
            'file' => new expMultiuploadFileDatatypesTestValue( array( 'data_type_string' => 'myfile', 'data_int1' => 1, 'data_int3' => 5 ) ) ) );
        $this->assertSame( 5 * 1024 * 1024, expMultiuploadFileDatatypesTestService::maxSize( $class ) );

        $content = new expMultiuploadFileDatatypesTestValue( array( 'thumb' => array( 'url' => '/var/storage/thumb.png' ) ) );
        $object = new expMultiuploadFileDatatypesTestClass( array(), array(
            'file' => new expMultiuploadFileDatatypesTestValue( array( 'data_type_string' => 'myfile' ), $content ) ) );
        $this->assertStringEndsWith( '/var/storage/thumb.png', expMultiuploadFileDatatypesTestService::preview( $object ) );

        $ini->setVariable( 'FileDatatypeSettings', 'FileDatatypes', array( 'otherfile' ) );
        $ini->setVariable( 'FileDatatypeSettings', 'NameAttribute', array( 'otherfile' => 'filename' ) );
        $ini->setVariable( 'FileDatatypeSettings', 'SizeAttribute', array() );
        $ini->setVariable( 'FileDatatypeSettings', 'MaxSizeAttribute', array() );
        $ini->setVariable( 'FileDatatypeSettings', 'PreviewAttribute', array() );
        $this->assertSame( array( 'otherfile' => array( 'name' => 'filename', 'size' => '', 'maxsize' => '', 'preview' => '' ) ),
                           expMultiuploadService::fileDatatypes() );
    }

    /** FD-03 */
    public function testContentValueFollowsThePath()
    {
        $content = new expMultiuploadFileDatatypesTestValue( array( 'original_filename' => 'a.jpg', 'original' => array( 'filesize' => 42 ) ) );
        $this->assertSame( 'a.jpg', expMultiuploadFileDatatypesTestService::value( $content, 'original_filename' ) );
        $this->assertSame( 42, expMultiuploadFileDatatypesTestService::value( $content, 'original.filesize' ) );
        $this->assertNull( expMultiuploadFileDatatypesTestService::value( $content, 'original.url' ) );
        $this->assertNull( expMultiuploadFileDatatypesTestService::value( $content, 'original_filename.x' ) );
        $this->assertNull( expMultiuploadFileDatatypesTestService::value( $content, '' ) );
        $this->assertNull( expMultiuploadFileDatatypesTestService::value( 'text', 'original_filename' ) );
    }

    /** FD-04 */
    public function testADatatypeThatIsNotListedIsNotRead()
    {
        $ini = self::ini( 'ezmultiupload.ini' );
        $ini->setVariable( 'FileDatatypeSettings', 'FileDatatypes', array( 'ezbinaryfile' ) );
        self::ini( 'upload.ini' )->setVariable( 'image_ClassSettings', 'FileAttribute', 'image' );
        $content = new expMultiuploadFileDatatypesTestValue( array( 'original' => array( 'url' => '/var/storage/a.jpg' ) ) );
        $image = new expMultiuploadFileDatatypesTestValue( array( 'data_type_string' => 'ezimage', 'data_int1' => 2 ), $content );
        $class = new expMultiuploadFileDatatypesTestClass( array( 'identifier' => 'image' ), array( 'image' => $image ) );

        $this->assertSame( 0, expMultiuploadFileDatatypesTestService::maxSize( $class ) );
        $this->assertSame( '', expMultiuploadFileDatatypesTestService::preview( $class ) );

        $ini->removeGroup( 'FileDatatypeSettings' );
        $this->assertSame( 2 * 1024 * 1024, expMultiuploadFileDatatypesTestService::maxSize( $class ) );
        $this->assertStringEndsWith( '/var/storage/a.jpg', expMultiuploadFileDatatypesTestService::preview( $class ) );
    }

    /** FD-05 */
    public function testAnEmptyListFallsBackToTheBuiltInList()
    {
        $ini = self::ini( 'ezmultiupload.ini' );
        self::ini( 'upload.ini' )->setVariable( 'image_ClassSettings', 'FileAttribute', 'image' );
        $content = new expMultiuploadFileDatatypesTestValue( array( 'original' => array( 'url' => '/var/storage/a.jpg' ) ) );
        $image = new expMultiuploadFileDatatypesTestValue( array( 'data_type_string' => 'ezimage', 'data_int1' => 2 ), $content );
        $class = new expMultiuploadFileDatatypesTestClass( array( 'identifier' => 'image' ), array( 'image' => $image ) );

        $cases = array(
            'missing group' => null,
            'empty list' => array(),
            'invalid names only' => array( '', '  ', 'no space', '../x' ),
        );
        foreach ( $cases as $case => $list )
        {
            $ini->removeGroup( 'FileDatatypeSettings' );
            if ( $list !== null )
                $ini->setVariable( 'FileDatatypeSettings', 'FileDatatypes', $list );
            $this->assertSame( expMultiuploadService::FILE_DATATYPES, expMultiuploadService::fileDatatypes(), $case );
            $this->assertSame( 2 * 1024 * 1024, expMultiuploadFileDatatypesTestService::maxSize( $class ), $case );
            $this->assertStringEndsWith( '/var/storage/a.jpg', expMultiuploadFileDatatypesTestService::preview( $class ), $case );
        }
    }

    /** FD-06 */
    public function testAPathThatIsNoAttributePathIsNotRead()
    {
        $ini = self::ini( 'ezmultiupload.ini' );
        $ini->setVariable( 'FileDatatypeSettings', 'FileDatatypes', array( 'myfile' ) );
        $ini->setVariable( 'FileDatatypeSettings', 'NameAttribute', array( 'myfile' => 'file name' ) );
        $ini->setVariable( 'FileDatatypeSettings', 'SizeAttribute', array( 'myfile' => 'meta..bytes' ) );
        $ini->setVariable( 'FileDatatypeSettings', 'MaxSizeAttribute', array( 'myfile' => 'data_int1()' ) );
        $ini->setVariable( 'FileDatatypeSettings', 'PreviewAttribute', array( 'myfile' => ' thumb.url ' ) );
        $this->assertSame( array( 'myfile' => array( 'name' => '', 'size' => '', 'maxsize' => '', 'preview' => 'thumb.url' ) ),
                           expMultiuploadService::fileDatatypes() );
    }
}
