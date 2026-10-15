<?php
/**
 * expMultiuploadZipImporter: inspect(), extract() and cleanup() on archives the test makes with ZipArchive (no
 * binary fixtures), with the options given explicitly so no setting of the installation changes a result.
 *
 *  ZI-01 - a mixed archive (jpg, png, pdf, txt, svg): every entry ok, MIME by content, its class from classMap
 *  ZI-02 - ZIP files nested 3 levels deep are listed with their depth; a 4th level is skipped "too_deep"
 *  ZI-03 - "../x", "/abs" and "C:\x" are skipped "unsafe_path"; extract() writes nothing outside its directory
 *  ZI-04 - a symbolic link entry is skipped "symlink" and never extracted
 *  ZI-05 - an entry compressed more than MaxRatio times is skipped "ratio_exceeded"
 *  ZI-06 - entries over MaxEntries are skipped "too_many_entries"; files over MaxTotalBytes "total_size_exceeded"
 *  ZI-07 - CP437 and ISO-8859-1 names become UTF-8
 *  ZI-08 - an encrypted entry is skipped "encrypted" (when libzip here can write one)
 *  ZI-09 - .php, .exe and PHP code under a .txt name are skipped "type_not_allowed"
 *  ZI-10 - __MACOSX/, .DS_Store, Thumbs.db and hidden files are skipped "system_file"
 *  ZI-11 - extract() returns exactly the chosen entries (also one inside a nested ZIP file, and a nested ZIP file
 *          as a whole), files 0600 in a 0700 directory
 *  ZI-12 - cleanup() leaves nothing and can be called twice; inspect() leaves no temporary directory behind
 *  ZI-13 - a per-file size limit, a disabled importer, a file that is no ZIP file; utf8Name()/pathParts()/parseSize()
 *
 * Run: php vendor/bin/phpunit extension/ezmultiupload/tests/expMultiuploadZipImporterTest.php
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezmultiupload
 */

require_once dirname( __DIR__ ) . '/classes/expmultiuploadzipimporter.php';

class expMultiuploadZipImporterTest extends PHPUnit\Framework\TestCase
{
    /** A 1x1 PNG */
    const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** A 1x1 JPEG */
    const JPEG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    /** @var string the test's own directory */
    private $dir;

    /** @var expMultiuploadZipImporter */
    private $importer;

    protected function setUp(): void
    {
        if ( !class_exists( 'ZipArchive' ) )
            $this->markTestSkipped( 'ext-zip is not loaded' );
        $this->dir = dirname( __DIR__, 3 ) . '/var/tmp/expmultiupload-zip-importer-test-' . bin2hex( random_bytes( 6 ) );
        mkdir( $this->dir . '/var', 0700, true );
        $this->importer = new expMultiuploadZipImporter( $this->options() );
    }

    protected function tearDown(): void
    {
        if ( $this->dir && $this->importer )
            $this->importer->cleanup( $this->dir );
    }

    /** Fixed options: nothing of the installation's settings reaches the test */
    private function options( array $over = array() )
    {
        return $over + array( 'enabled' => true, 'maxDepth' => 3, 'maxEntries' => 1000, 'maxTotalBytes' => 50 * 1024 * 1024,
                              'maxRatio' => 100, 'ratioMinSize' => 1024 * 1024, 'maxFileSize' => 10 * 1024 * 1024,
                              'maxPathDepth' => 16, 'allowedMimeTypes' => expMultiuploadZipImporter::DEFAULT_ALLOWED_MIME_TYPES,
                              'deniedExtensions' => expMultiuploadZipImporter::DENIED_EXTENSIONS,
                              'skipNames' => array( '__macosx', '.ds_store', 'thumbs.db', 'desktop.ini' ), 'skipHidden' => true,
                              'classMap' => array( 'image' => 'image', 'application/pdf' => 'file', 'text/plain' => 'file',
                                                   'video' => 'video' ),
                              'varDir' => $this->dir . '/var' );
    }

    /**
     * A ZIP file of $files (name => content); a value that is an array is a nested ZIP file of those files.
     *
     * @param callable|null $tweak called with the open ZipArchive before it is closed
     */
    private function zip( $name, array $files, $tweak = null )
    {
        $path = $this->dir . '/' . $name;
        $zip = new ZipArchive();
        $this->assertTrue( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
        foreach ( $files as $entry => $content )
        {
            if ( is_array( $content ) )
                $content = file_get_contents( $this->zip( 'inner-' . bin2hex( random_bytes( 4 ) ) . '.zip', $content ) );
            $zip->addFromString( (string)$entry, $content );
        }
        if ( $tweak )
            $tweak( $zip );
        $zip->close();
        return $path;
    }

    private function mixed()
    {
        return array( 'photo.jpg' => base64_decode( self::JPEG ), 'graphic.png' => base64_decode( self::PNG ),
                      'docs/report.pdf' => "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n",
                      'docs/notes.txt' => "Some notes.\n",
                      'shape.svg' => '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"><rect width="4" height="4"/></svg>' );
    }

    /** @return array path => row */
    private function rows( array $result )
    {
        $this->assertTrue( $result['ok'], $result['message'] ?? '' );
        $rows = array();
        foreach ( $result['entries'] as $row )
            $rows[$row['path']] = $row;
        return $rows;
    }

    public function testMixedArchiveIsListedByContent()
    {
        $rows = $this->rows( $this->importer->inspect( $this->zip( 'mixed.zip', $this->mixed() ) ) );
        $this->assertSame( array( 'photo.jpg', 'graphic.png', 'docs/report.pdf', 'docs/notes.txt', 'shape.svg' ), array_keys( $rows ) );
        $expect = array( 'photo.jpg' => array( 'image/jpeg', 'image' ), 'graphic.png' => array( 'image/png', 'image' ),
                         'docs/report.pdf' => array( 'application/pdf', 'file' ), 'docs/notes.txt' => array( 'text/plain', 'file' ),
                         'shape.svg' => array( 'image/svg+xml', 'image' ) );
        foreach ( $expect as $path => list( $mime, $class ) )
        {
            $this->assertSame( 'ok', $rows[$path]['status'], $path . ': ' . $rows[$path]['reason'] );
            $this->assertSame( $mime, $rows[$path]['mime'], $path );
            $this->assertSame( $class, $rows[$path]['class'], $path );
            $this->assertSame( 1, $rows[$path]['depth'] );
            $this->assertGreaterThan( 0, $rows[$path]['size'] );
            $this->assertGreaterThan( 0, $rows[$path]['compressedSize'] );
        }
        $this->assertSame( 'report.pdf', $rows['docs/report.pdf']['name'] );
    }

    public function testNestingToThreeLevelsIsListedAndTheFourthIsRefused()
    {
        $three = array( 'top.txt' => 'top', 'l2.zip' => array( 'two.txt' => 'two', 'l3.zip' => array( 'three.txt' => 'three' ) ) );
        $rows = $this->rows( $this->importer->inspect( $this->zip( 'three.zip', $three ) ) );
        $this->assertSame( 'ok', $rows['l2.zip']['status'] );
        $this->assertTrue( $rows['l2.zip']['nested'] );
        $this->assertSame( 2, $rows['l2.zip/two.txt']['depth'] );
        $this->assertSame( 'ok', $rows['l2.zip/l3.zip']['status'] );
        $this->assertSame( 3, $rows['l2.zip/l3.zip/three.txt']['depth'] );
        $this->assertSame( 'ok', $rows['l2.zip/l3.zip/three.txt']['status'] );

        $four = array( 'l2.zip' => array( 'l3.zip' => array( 'three.txt' => 'three', 'l4.zip' => array( 'four.txt' => 'four' ) ) ) );
        $rows = $this->rows( $this->importer->inspect( $this->zip( 'four.zip', $four ) ) );
        $this->assertSame( 'ok', $rows['l2.zip/l3.zip/three.txt']['status'] );
        $this->assertSame( 'skipped', $rows['l2.zip/l3.zip/l4.zip']['status'] );
        $this->assertSame( 'too_deep', $rows['l2.zip/l3.zip/l4.zip']['reason'] );
        $this->assertArrayNotHasKey( 'l2.zip/l3.zip/l4.zip/four.txt', $rows );
    }

    public function testTraversalEntriesAreSkippedAndNeverWritten()
    {
        $zip = $this->zip( 'traversal.zip', array( '../x.txt' => 'up', '/abs.txt' => 'abs', 'C:\\x.txt' => 'drive',
                                                   'a/../../y.txt' => 'up2', 'fine.txt' => 'fine' ) );
        $result = $this->importer->inspect( $zip );
        $unsafe = array();
        foreach ( $result['entries'] as $row )
        {
            if ( $row['path'] !== 'fine.txt' )
            {
                $this->assertSame( 'skipped', $row['status'], $row['path'] );
                $this->assertSame( 'unsafe_path', $row['reason'], $row['path'] );
                $unsafe[] = $row['path'];
            }
        }
        $this->assertCount( 4, $unsafe );

        $out = $this->dir . '/out/deep';
        $extracted = $this->importer->extract( $zip, array_merge( $unsafe, array( '../x.txt', '/abs.txt', 'fine.txt' ) ), $out );
        $this->assertTrue( $extracted['ok'] );
        $this->assertSame( array( 'fine.txt' ), array_column( $extracted['files'], 'relativePath' ) );
        $this->assertFileDoesNotExist( $this->dir . '/out/x.txt' );
        $this->assertFileDoesNotExist( $this->dir . '/out/y.txt' );
        $this->assertFileDoesNotExist( '/abs.txt' );
        $this->assertSame( array( 'fine.txt' ), $this->filesUnder( $out ) );
    }

    public function testSymlinkEntryIsSkipped()
    {
        $zip = $this->zip( 'link.zip', array( 'passwd' => '/etc/passwd', 'ok.txt' => 'ok' ), function ( ZipArchive $z ) {
            $z->setExternalAttributesName( 'passwd', ZipArchive::OPSYS_UNIX, ( 0120777 << 16 ) );
        } );
        $rows = $this->rows( $this->importer->inspect( $zip ) );
        $this->assertSame( 'symlink', $rows['passwd']['reason'] );
        $this->assertSame( 'ok', $rows['ok.txt']['status'] );
        $extracted = $this->importer->extract( $zip, array( 'passwd', 'ok.txt' ), $this->dir . '/out' );
        $this->assertSame( array( 'ok.txt' ), array_column( $extracted['files'], 'relativePath' ) );
        $this->assertSame( 'symlink', $extracted['skipped']['passwd'] );
        $this->assertFalse( is_link( $this->dir . '/out/passwd' ) || file_exists( $this->dir . '/out/passwd' ) );
    }

    public function testRatioBombIsSkipped()
    {
        $zip = $this->zip( 'bomb.zip', array( 'zeros.txt' => str_repeat( "\0", 3 * 1024 * 1024 ), 'small.txt' => 'small' ) );
        $rows = $this->rows( $this->importer->inspect( $zip ) );
        $this->assertGreaterThan( 100, $rows['zeros.txt']['size'] / max( 1, $rows['zeros.txt']['compressedSize'] ) );
        $this->assertSame( 'ratio_exceeded', $rows['zeros.txt']['reason'] );
        $this->assertSame( 'ok', $rows['small.txt']['status'] );
        $extracted = $this->importer->extract( $zip, array( 'zeros.txt' ), $this->dir . '/out' );
        $this->assertSame( array(), $extracted['files'] );
        $this->assertSame( 'ratio_exceeded', $extracted['skipped']['zeros.txt'] );
    }

    public function testLyingSizeHeaderNeverWritesMoreThanDeclared()
    {
        $zip = $this->zip( 'lie.zip', array( 'lie.txt' => str_repeat( 'x', 5000 ) ), function ( ZipArchive $z ) {
            $z->setCompressionName( 'lie.txt', ZipArchive::CM_STORE );
        } );
        // the local and the central header both claim 10 bytes uncompressed
        $data = file_get_contents( $zip );
        $local = strpos( $data, "PK\x03\x04" );
        $central = strpos( $data, "PK\x01\x02" );
        $data = substr_replace( $data, pack( 'V', 10 ), $local + 22, 4 );
        $data = substr_replace( $data, pack( 'V', 10 ), $central + 24, 4 );
        file_put_contents( $zip, $data );

        $result = $this->importer->inspect( $zip );
        $this->assertSame( 10, $this->rows( $result )['lie.txt']['size'] );
        $extracted = $this->importer->extract( $zip, array( 'lie.txt' ), $this->dir . '/out' );
        foreach ( $extracted['files'] as $file )
            $this->assertLessThanOrEqual( 10, filesize( $file['path'] ) );
        foreach ( $this->filesUnder( $this->dir . '/out' ) as $file )
            $this->assertLessThanOrEqual( 10, filesize( $this->dir . '/out/' . $file ) );
    }

    public function testTooManyEntriesAndTooLargeInTotal()
    {
        $files = array();
        for ( $i = 1; $i <= 8; $i++ )
            $files["f$i.txt"] = "file $i";
        $result = $this->importer->inspect( $this->zip( 'many.zip', $files ), array( 'maxEntries' => 5 ) );
        $rows = $this->rows( $result );
        $this->assertSame( array( 'ok', 'ok', 'ok', 'ok', 'ok', 'skipped', 'skipped', 'skipped' ), array_column( $result['entries'], 'status' ) );
        $this->assertSame( 'too_many_entries', $rows['f6.txt']['reason'] );
        $this->assertContains( 'too_many_entries', $result['refused'] );

        $big = array( 'a.txt' => str_repeat( 'a', 1500 ), 'b.txt' => str_repeat( 'b', 1500 ), 'c.txt' => str_repeat( 'c', 1500 ) );
        $zip = $this->zip( 'total.zip', $big );
        $result = $this->importer->inspect( $zip, array( 'maxTotalBytes' => 3000 ) );
        $rows = $this->rows( $result );
        $this->assertSame( 'ok', $rows['b.txt']['status'] );
        $this->assertSame( 'total_size_exceeded', $rows['c.txt']['reason'] );
        $this->assertSame( 3000, $result['totalBytes'] );
        $extracted = $this->importer->extract( $zip, array( 'a.txt', 'b.txt', 'c.txt' ), $this->dir . '/out', array( 'maxTotalBytes' => 3000 ) );
        $this->assertSame( array( 'a.txt', 'b.txt' ), array_column( $extracted['files'], 'relativePath' ) );
    }

    public function testNonUtf8NamesAreConverted()
    {
        $zip = $this->zip( 'names.zip', array( "caf\x82.txt" => 'cp437', "na\xEFve.txt" => 'latin1' ) );
        $rows = $this->rows( $this->importer->inspect( $zip ) );
        $this->assertArrayHasKey( "caf\u{e9}.txt", $rows );
        $this->assertArrayHasKey( "na\u{ef}ve.txt", $rows );
        $extracted = $this->importer->extract( $zip, array( "caf\u{e9}.txt" ), $this->dir . '/out' );
        $this->assertCount( 1, $extracted['files'] );
        $this->assertSame( 'cp437', file_get_contents( $extracted['files'][0]['path'] ) );
        $this->assertSame( "caf\u{e9}.txt", basename( $extracted['files'][0]['path'] ) );
    }

    public function testEncryptedEntryIsRefused()
    {
        if ( !method_exists( 'ZipArchive', 'isEncryptionMethodSupported' ) || !ZipArchive::isEncryptionMethodSupported( ZipArchive::EM_AES_256 ) )
            $this->markTestSkipped( 'libzip here cannot write an encrypted entry' );
        $zip = $this->zip( 'secret.zip', array( 'secret.txt' => 'secret', 'open.txt' => 'open' ), function ( ZipArchive $z ) {
            $z->setEncryptionName( 'secret.txt', ZipArchive::EM_AES_256, 'password' );
        } );
        $result = $this->importer->inspect( $zip );
        $rows = $this->rows( $result );
        $this->assertSame( 'encrypted', $rows['secret.txt']['reason'] );
        $this->assertContains( 'encrypted', $result['refused'] );
        $extracted = $this->importer->extract( $zip, array( 'secret.txt' ), $this->dir . '/out' );
        $this->assertSame( array(), $extracted['files'] );
    }

    public function testDisallowedTypesAreSkipped()
    {
        $zip = $this->zip( 'types.zip', array( 'run.php' => '<?php echo 1;', 'tool.exe' => "MZ\x90\0\3\0\0\0\4\0\0\0\xff\xff",
                                               'shell.PHTML' => 'x', 'code.txt' => "<?php\nsystem( \$_GET['c'] );\n",
                                               'page.html' => '<html><body>x</body></html>', 'fine.txt' => 'fine' ) );
        $rows = $this->rows( $this->importer->inspect( $zip ) );
        foreach ( array( 'run.php', 'tool.exe', 'shell.PHTML', 'code.txt', 'page.html' ) as $path )
            $this->assertSame( 'type_not_allowed', $rows[$path]['reason'], $path . ' (' . $rows[$path]['mime'] . ')' );
        $this->assertSame( 'ok', $rows['fine.txt']['status'] );
    }

    public function testSystemAndHiddenFilesAreSkipped()
    {
        $zip = $this->zip( 'mac.zip', array( '__MACOSX/._photo.jpg' => 'x', '.DS_Store' => 'x', 'sub/Thumbs.db' => 'x',
                                             '.hidden/a.txt' => 'x', 'sub/.env' => 'x', 'photo.png' => base64_decode( self::PNG ) ) );
        $rows = $this->rows( $this->importer->inspect( $zip ) );
        foreach ( array( '__MACOSX/._photo.jpg', '.DS_Store', 'sub/Thumbs.db', '.hidden/a.txt', 'sub/.env' ) as $path )
            $this->assertSame( 'system_file', $rows[$path]['reason'], $path );
        $this->assertSame( 'ok', $rows['photo.png']['status'] );
        $extracted = $this->importer->extract( $zip, array_keys( $rows ), $this->dir . '/out' );
        $this->assertSame( array( 'photo.png' ), $this->filesUnder( $this->dir . '/out' ) );
    }

    public function testExtractReturnsExactlyTheSelectedEntries()
    {
        $files = $this->mixed() + array( 'l2.zip' => array( 'two.txt' => 'two', 'l3.zip' => array( 'three.txt' => 'three', 'more.txt' => 'more' ) ) );
        $zip = $this->zip( 'select.zip', $files );
        $extracted = $this->importer->extract( $zip, array( 'graphic.png', 'docs/report.pdf', 'l2.zip/l3.zip/three.txt', 'missing.txt' ) );
        $this->assertTrue( $extracted['ok'] );
        $tmp = $extracted['tmpDir'];
        $this->assertStringStartsWith( realpath( $this->dir . '/var' ) . '/expmultiupload-zip-import-', $tmp );
        $this->assertSame( 0700, fileperms( $tmp ) & 0777 );
        $this->assertSame( array( 'graphic.png', 'docs/report.pdf', 'l2.zip/l3.zip/three.txt' ), array_column( $extracted['files'], 'relativePath' ) );
        foreach ( $extracted['files'] as $file )
        {
            $this->assertSame( $tmp . '/' . $file['relativePath'], $file['path'] );
            $this->assertFileExists( $file['path'] );
            $this->assertSame( 0600, fileperms( $file['path'] ) & 0777 );
            $this->assertSame( $file['size'], filesize( $file['path'] ) );
        }
        $this->assertSame( 'three', file_get_contents( $tmp . '/l2.zip/l3.zip/three.txt' ) );
        $this->assertSame( 'not_found', $extracted['skipped']['missing.txt'] );
        $this->assertSame( array( 'docs/report.pdf', 'graphic.png', 'l2.zip/l3.zip/three.txt' ), $this->filesUnder( $tmp ) );

        // a nested ZIP file chosen as a whole brings everything in it
        $whole = $this->importer->extract( $zip, array( 'l2.zip/l3.zip' ) );
        $this->assertSame( array( 'l2.zip/l3.zip/three.txt', 'l2.zip/l3.zip/more.txt' ), array_column( $whole['files'], 'relativePath' ) );
        $this->assertNotSame( $tmp, $whole['tmpDir'] );
        $this->importer->cleanup( $whole['tmpDir'] );
        $this->importer->cleanup( $tmp );
    }

    public function testCleanupLeavesNothing()
    {
        $zip = $this->zip( 'clean.zip', $this->mixed() + array( 'l2.zip' => array( 'two.txt' => 'two' ) ) );
        $this->importer->inspect( $zip );
        $base = $this->dir . '/var/expmultiupload-zip-import-' . ( function_exists( 'posix_geteuid' ) ? posix_geteuid() : getmyuid() );
        $this->assertSame( array(), $this->filesUnder( $base ), 'inspect() leaves no temporary file' );
        $this->assertSame( array( '.', '..' ), scandir( $base ) );

        $extracted = $this->importer->extract( $zip, array( 'photo.jpg', 'l2.zip/two.txt' ) );
        $this->assertCount( 2, $extracted['files'] );
        $this->importer->cleanup( $extracted['tmpDir'] );
        $this->assertDirectoryDoesNotExist( $extracted['tmpDir'] );
        $this->importer->cleanup( $extracted['tmpDir'] );
        $this->importer->cleanup( '' );
        $this->assertSame( array( '.', '..' ), scandir( $base ) );
    }

    public function testLimitsAndHelpers()
    {
        $zip = $this->zip( 'size.zip', array( 'big.txt' => str_repeat( "line of text\n", 400 ), 'small.txt' => 'small' ) );
        $rows = $this->rows( $this->importer->inspect( $zip, array( 'maxFileSize' => 1000 ) ) );
        $this->assertSame( 'too_big', $rows['big.txt']['reason'] );
        $this->assertSame( 'ok', $rows['small.txt']['status'] );

        $off = $this->importer->inspect( $zip, array( 'enabled' => false ) );
        $this->assertFalse( $off['ok'] );
        $this->assertSame( 'disabled', $off['error'] );

        file_put_contents( $this->dir . '/not.zip', 'not a zip file' );
        $bad = $this->importer->inspect( $this->dir . '/not.zip' );
        $this->assertSame( 'invalid_zip', $bad['error'] );
        $this->assertSame( 'not_found', $this->importer->inspect( $this->dir . '/missing.zip' )['error'] );

        $this->assertSame( 'absolute', expMultiuploadZipImporter::pathParts( 'C:/x' )['unsafe'] );
        $this->assertSame( 'parent', expMultiuploadZipImporter::pathParts( 'a\\..\\b' )['unsafe'] );
        $this->assertSame( array( 'a', 'b' ), expMultiuploadZipImporter::pathParts( './a//b' )['parts'] );
        $this->assertSame( "\u{c4}rger.txt", expMultiuploadZipImporter::utf8Name( "\x8Erger.txt" ) );
        $this->assertSame( 'ab.txt', expMultiuploadZipImporter::utf8Name( "a\x01b.txt" ) );
        $this->assertSame( 64 * 1024 * 1024, expMultiuploadZipImporter::parseSize( '64M' ) );
        $this->assertSame( 0, expMultiuploadZipImporter::parseSize( 'lots' ) );
        $this->assertSame( 500 * 1024 * 1024, $this->importer->options( array( 'maxTotalBytes' => 0 ) )['maxTotalBytes'] );
    }

    /** The files below $dir, relative, sorted */
    private function filesUnder( $dir )
    {
        $list = array();
        if ( !is_dir( $dir ) )
            return $list;
        $items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $items as $item )
            $list[] = substr( $item->getPathname(), strlen( $dir ) + 1 );
        sort( $list );
        return $list;
    }
}
