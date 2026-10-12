<?php
/**
 * 書き出し時の、フォントの取り込みのテスト（M4）。
 * Font Library のフォント（uploads にあるファイル）が、ZIP の assets/fonts/ に入り、theme.json の src が、file:./ になること。
 * 実際の書き出しを動かす。ユーザーのスタイルは、フィルターで一時的に足すだけで、データベースは変えない。uploads に、テスト用のファイルを 1 つ作り、最後に消す。
 */

foreach ( array( 'Itmar_Block_Theme_Migration', 'Itmar_BTM_Sanitizer' ) as $class ) {
	if ( ! class_exists( $class ) ) {
		throw new RuntimeException( $class . ' was not loaded.' );
	}
}
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
wp_raise_memory_limit( 'admin' );

$uploads = wp_get_upload_dir();
$dir     = $uploads['basedir'] . '/fonts';
wp_mkdir_p( $dir );
$bytes   = 'wOF2' . str_repeat( 'btm-test-font', 50 );
$file    = $dir . '/btm-test-font.woff2';
file_put_contents( $file, $bytes );
$url     = $uploads['baseurl'] . '/fonts/btm-test-font.woff2';

$face = static fn( string $family, $src ): array => array( 'fontFamily' => $family, 'fontWeight' => '400', 'fontStyle' => 'normal', 'src' => $src );
$inject = static function ( $data ) use ( $face, $url, $uploads ) {
	return $data->update_with(
		array(
			'version'  => 3,
			'settings' => array(
				'typography' => array(
					'fontFamilies' => array(
						'custom' => array(
							array( 'name' => 'BTM Test', 'slug' => 'btm-test', 'fontFamily' => '"BTM Test", serif', 'fontFace' => array( $face( 'BTM Test', $url ) ) ),
							// 同じ内容のファイルを、別のスラッグでも使う（ZIP には、1 つだけ入る）
							array( 'name' => 'BTM Twin', 'slug' => 'btm-twin', 'fontFamily' => '"BTM Twin", serif', 'fontFace' => array( $face( 'BTM Twin', $url ) ) ),
							// 外部のアドレス（そのまま残し、記録に挙げる）
							array( 'name' => 'BTM Remote', 'slug' => 'btm-remote', 'fontFamily' => '"BTM Remote", serif', 'fontFace' => array( $face( 'BTM Remote', 'https://fonts.gstatic.com/s/example/v1/x.woff2' ) ) ),
							// uploads にあるはずのファイルが、無い
							array( 'name' => 'BTM Missing', 'slug' => 'btm-missing', 'fontFamily' => '"BTM Missing", serif', 'fontFace' => array( $face( 'BTM Missing', $uploads['baseurl'] . '/fonts/btm-no-such-file.woff2' ) ) ),
						),
					),
				),
			),
		)
	);
};
add_filter( 'wp_theme_json_data_user', $inject );
WP_Theme_JSON_Resolver::clean_cached_data();

try {
	$zipfile = wp_generate_block_templates_export_file();
	$assert( ! is_wp_error( $zipfile ), 'export failed' );
	$method = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'enhance_export_zip' );
	$method->setAccessible( true );
	$result = $method->invoke( null, $zipfile, array() );
	$assert( true === $result, 'enhance failed' );

	$zip = new ZipArchive();
	$zip->open( $zipfile );
	$theme_json = json_decode( (string) $zip->getFromName( 'theme.json' ), true );
	$families   = array();
	foreach ( (array) ( $theme_json['settings']['typography']['fontFamilies'] ?? array() ) as $family ) {
		if ( isset( $family['slug'] ) ) {
			$families[ $family['slug'] ] = $family;
		}
	}
	$src = static fn( string $slug ) => $families[ $slug ]['fontFace'][0]['src'] ?? null;

	$assert( isset( $families['btm-test'] ), 'the custom font must be in the exported theme.json' );
	$assert( 'file:./assets/fonts/btm-test/btm-test-font.woff2' === $src( 'btm-test' ), 'src must point into the theme: ' . wp_json_encode( $src( 'btm-test' ) ) );
	$assert( $bytes === $zip->getFromName( 'assets/fonts/btm-test/btm-test-font.woff2' ), 'the font file must be in the ZIP with the same bytes' );
	$assert( 'file:./assets/fonts/btm-test/btm-test-font.woff2' === $src( 'btm-twin' ), 'a font with the same file must point to the same copy: ' . wp_json_encode( $src( 'btm-twin' ) ) );
	$copies = 0;
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		if ( preg_match( '#^assets/fonts/btm-[^/]+/#', (string) $zip->getNameIndex( $i ) ) ) {
			++$copies;
		}
	}
	$assert( 1 === $copies, 'only one copy of the file must be in the ZIP, found ' . $copies );
	$assert( 'https://fonts.gstatic.com/s/example/v1/x.woff2' === $src( 'btm-remote' ), 'an external URL must be left alone' );
	$assert( 0 === strpos( (string) $src( 'btm-missing' ), 'http' ), 'a missing file must be left alone' );

	$manifest = json_decode( (string) $zip->getFromName( 'block-theme-migration.json' ), true );
	$slugs    = array_column( $manifest['fonts']['localized'] ?? array(), 'slug' );
	$skipped  = array_column( $manifest['fonts']['skipped'] ?? array(), 'slug' );
	$assert( in_array( 'btm-test', $slugs, true ) && in_array( 'btm-twin', $slugs, true ), 'the manifest must list the localized fonts: ' . wp_json_encode( $manifest['fonts'] ?? null ) );
	$assert( in_array( 'btm-remote', $skipped, true ) && in_array( 'btm-missing', $skipped, true ), 'the manifest must list the skipped fonts: ' . wp_json_encode( $skipped ) );
	// ZIP のどこにも、移植元のアドレスのフォントが残らない（外部と、見つからないものを除く）
	$text = (string) $zip->getFromName( 'theme.json' );
	$assert( false === strpos( $text, $url ), 'the source site URL of the font must not remain in theme.json' );
	$zip->close();
	@unlink( $zipfile );
} finally {
	remove_filter( 'wp_theme_json_data_user', $inject );
	WP_Theme_JSON_Resolver::clean_cached_data();
	@unlink( $file );
}

echo "m4-font-localize-check: OK\n";
