<?php
/**
 * 書き出し時の除去（Itmar_BTM_Sanitizer）のテスト（M4）。
 * 1) 合成した本文での除去。2) 実際に書き出した ZIP に、サイト固有のものが残らないこと（AC-2）。
 * WordPress を読み込んだ状態で実行する（他の *-smoke.php と同じ）。
 */

foreach ( array( 'Itmar_BTM_Sanitizer', 'Itmar_BTM_Binder', 'Itmar_BTM_Inspector' ) as $class ) {
	if ( ! class_exists( $class ) ) {
		throw new RuntimeException( $class . ' was not loaded.' );
	}
}
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$home = untrailingslashit( home_url() );

// ---- 1) 合成した本文 ----
$content = <<<HTML
<!-- wp:itmar/design-title {"linkKind":"fixed","selectedSlug":"about","selectedPageUrl":"{$home}/about/"} -->
<div data-attributes="{&quot;linkKind&quot;:&quot;fixed&quot;,&quot;selectedSlug&quot;:&quot;about&quot;,&quot;selectedPageUrl&quot;:&quot;{$home}/about/&quot;}"><a href="{$home}/about/"><h2>About</h2></a></div>
<!-- /wp:itmar/design-title -->

<!-- wp:itmar/design-title {"selectedPageUrl":"https://twitter.com/example"} -->
<div data-attributes="{&quot;selectedPageUrl&quot;:&quot;https://twitter.com/example&quot;}"><a href="https://twitter.com/example"><h2>X</h2></a></div>
<!-- /wp:itmar/design-title -->

<!-- wp:navigation {"ref":5} /-->

<!-- wp:paragraph -->
<p>Contact: owner@example.com or <a href="{$home}/contact/">the form</a> or <a href="https://example.org/">elsewhere</a></p>
<!-- /wp:paragraph -->

<!-- wp:image {"id":12,"sizeSlug":"full","linkDestination":"none"} -->
<figure class="wp-block-image size-full"><img src="{$home}/wp-content/uploads/2026/01/a.png" alt="" class="wp-image-12"/></figure>
<!-- /wp:image -->

<!-- wp:image {"sizeSlug":"full","linkDestination":"none"} -->
<figure class="wp-block-image size-full"><img src="{$home}/wp-content/plugins/some-plugin/assets/a.png" alt=""/></figure>
<!-- /wp:image -->
HTML;

$report = array();
$out    = Itmar_BTM_Sanitizer::sanitize_content( $content, $report );
$assert( $out !== $content, 'Sanitized content must differ.' );

$blocks = array_values( array_filter( parse_blocks( $out ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
$assert( '' === ( $blocks[0]['attrs']['selectedPageUrl'] ?? '' ), 'Internal selectedPageUrl must be emptied.' );
$assert( '' === ( $blocks[0]['attrs']['selectedSlug'] ?? '' ), 'Fixed-page selectedSlug must be emptied.' );
$assert( false === strpos( $blocks[0]['innerHTML'], 'href="' . $home ), 'The anchor href of an internal link must be removed from the saved HTML.' );
$assert( false === strpos( $blocks[0]['innerHTML'], '&quot;selectedPageUrl&quot;:&quot;' . $home ), 'data-attributes must follow the attribute.' );
$assert( 'https://twitter.com/example' === $blocks[1]['attrs']['selectedPageUrl'] && false !== strpos( $blocks[1]['innerHTML'], 'href="https://twitter.com/example"' ), 'External links must be kept.' );
$assert( 'core/navigation' === $blocks[2]['blockName'] && ! isset( $blocks[2]['attrs']['ref'] ), 'Navigation ref must be removed.' );
$assert( false === strpos( $out, 'owner@example.com' ) && false !== strpos( $out, '[master_mail]' ), 'Email must be replaced by the placeholder.' );
$assert( false !== strpos( $blocks[3]['innerHTML'], 'href="#"' ) && false !== strpos( $blocks[3]['innerHTML'], 'href="https://example.org/"' ), 'Internal body links become #; external stay.' );
$assert( ! isset( $blocks[4]['attrs']['id'] ) && false === strpos( $blocks[4]['innerHTML'], 'wp-content/uploads' ) && false === strpos( $blocks[4]['innerHTML'], 'wp-image-12' ), 'Uploaded image must be detached.' );
$assert( false !== strpos( $blocks[4]['innerHTML'], '<figure class="wp-block-image size-full">' ), 'The image block keeps its figure (valid placeholder).' );
$assert( false !== strpos( $out, '/wp-content/plugins/some-plugin/assets/a.png' ) && false === strpos( $out, $home . '/wp-content/plugins' ), 'Plugin assets become root-relative and are kept.' );

$summary = Itmar_BTM_Sanitizer::summarize( $report );
foreach ( array( 'internal-link', 'navigation-ref', 'email', 'media', 'asset-url' ) as $kind ) {
	$assert( ! empty( $summary['counts'][ $kind ] ), 'Summary missing kind ' . $kind );
}
$assert( false === strpos( wp_json_encode( $summary ), 'owner@example.com' ), 'The report must not carry email addresses.' );

// 2回目は、何も変わらない（冪等）
$again = array();
$assert( Itmar_BTM_Sanitizer::sanitize_content( $out, $again ) === $out && empty( $again ), 'Sanitizing must be idempotent.' );

// 変更の無い本文は、そのまま返す
$plain = "<!-- wp:paragraph -->\n<p>Nothing to remove</p>\n<!-- /wp:paragraph -->";
$none  = array();
$assert( Itmar_BTM_Sanitizer::sanitize_content( $plain, $none ) === $plain, 'Untouched content must be returned as it is.' );

// ---- 1b) デモ用の書き出し: リンクとメディアを残し、サイトの URL を相対にする ----
$demo_content = <<<HTML
<!-- wp:itmar/design-title {"linkKind":"fixed","selectedSlug":"about","selectedPageUrl":"{$home}/about/"} -->
<div data-attributes="{&quot;linkKind&quot;:&quot;fixed&quot;,&quot;selectedSlug&quot;:&quot;about&quot;,&quot;selectedPageUrl&quot;:&quot;{$home}/about/&quot;}"><a href="{$home}/about/"><h2>About</h2></a></div>
<!-- /wp:itmar/design-title -->

<!-- wp:itmar/design-button {"selectedPageUrl":"{$home}"} -->
<div data-attributes="{&quot;selectedPageUrl&quot;:&quot;{$home}&quot;,&quot;other&quot;:&quot;{$home}/shop/&quot;}"><button>Home</button></div>
<!-- /wp:itmar/design-button -->

<!-- wp:navigation {"ref":5} /-->

<!-- wp:paragraph -->
<p>Mail owner@example.com <a href="{$home}/contact/">the form</a></p>
<!-- /wp:paragraph -->

<!-- wp:image {"id":12,"sizeSlug":"full"} -->
<figure class="wp-block-image size-full"><img src="{$home}/wp-content/uploads/2026/01/a.png" alt="" class="wp-image-12"/></figure>
<!-- /wp:image -->
HTML;
Itmar_BTM_Sanitizer::set_demo(true);
$demo_report = array();
$demo_out    = Itmar_BTM_Sanitizer::sanitize_content($demo_content, $demo_report);
Itmar_BTM_Sanitizer::set_demo(false);
$demo_blocks = array_values(array_filter(parse_blocks($demo_out), static fn($b) => ! empty($b['blockName'])));
$assert(false === strpos($demo_out, $home), 'Demo export: the site address must be made relative everywhere.');
$assert('/about/' === $demo_blocks[0]['attrs']['selectedPageUrl'] && 'about' === $demo_blocks[0]['attrs']['selectedSlug'], 'Demo export: the page link and slug must be kept.');
$assert(false !== strpos($demo_blocks[0]['innerHTML'], 'href="/about/"') && false !== strpos($demo_blocks[0]['innerHTML'], '&quot;selectedPageUrl&quot;:&quot;/about/&quot;'), 'Demo export: HTML and data-attributes must follow.');
$assert('/' === $demo_blocks[1]['attrs']['selectedPageUrl'] && false !== strpos($demo_blocks[1]['innerHTML'], '&quot;other&quot;:&quot;/shop/&quot;'), 'Demo export: the bare home URL becomes / and later URLs in the same attribute are handled too.');
$assert(! isset($demo_blocks[2]['attrs']['ref']), 'Demo export: the navigation ref must still be removed.');
$assert(false === strpos($demo_out, 'owner@example.com') && false !== strpos($demo_out, '[master_mail]'), 'Demo export: e-mail addresses must still be removed.');
$assert('/wp-content/uploads/2026/01/a.png' === (preg_match('#src="([^"]+)"#', $demo_blocks[4]['innerHTML'], $dm) ? $dm[1] : ''), 'Demo export: uploaded media must be kept, with a relative URL.');
$assert(12 === ($demo_blocks[4]['attrs']['id'] ?? null), 'Demo export: the image id is kept.');
$again_demo = array();
Itmar_BTM_Sanitizer::set_demo(true);
$assert(Itmar_BTM_Sanitizer::sanitize_content($demo_out, $again_demo) === $demo_out, 'Demo export must be idempotent.');
Itmar_BTM_Sanitizer::set_demo(false);

// ---- 2) 実際の書き出し（AC-2） ----
$filename = wp_generate_block_templates_export_file();
if ( is_wp_error( $filename ) ) {
	throw new RuntimeException( $filename->get_error_message() );
}
try {
	$method = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'enhance_export_zip' );
	$result = $method->invoke( null, $filename, array() );
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_message() );
	}
	$zip = new ZipArchive();
	$assert( true === $zip->open( $filename ), 'Could not reopen the exported ZIP.' );
	$items    = array();
	$raw_text = '';
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = (string) $zip->getNameIndex( $i );
		if ( ! preg_match( '#^(templates|parts)/[^/]+\.html$#', $name ) ) {
			continue;
		}
		$body       = (string) $zip->getFromIndex( $i );
		$items[]    = array( 'kind' => 'template', 'slug' => $name, 'title' => $name, 'content' => $body );
		$raw_text  .= $body;
	}
	$manifest = json_decode( (string) $zip->getFromName( 'block-theme-migration.json' ), true );
	$zip->close();

	$assert( ! empty( $items ), 'The export contained no templates or parts.' );
	$assert( isset( $manifest['removals']['counts'] ) && is_array( $manifest['removals']['counts'] ), 'The manifest must carry a removals summary.' );
	$scan     = Itmar_BTM_Inspector::scan( $items );
	$residual = array();
	foreach ( $scan['findings'] as $finding ) {
		if ( in_array( $finding['kind'], array( 'internal-link', 'email', 'media', 'uploads-url', 'local-url', 'navigation-ref', 'content', 'service-setting' ), true ) ) {
			$residual[] = $finding['kind'] . ' ' . $finding['block'] . ' ' . $finding['path'] . ' x' . $finding['count'];
		}
	}
	$assert( empty( $residual ), 'Site-specific values remain in the export: ' . implode( '; ', $residual ) );
	$assert( false === strpos( $raw_text, $home ), 'The source site URL remains in the exported templates.' );

	echo 'm4-sanitizer-smoke: OK (' . count( $items ) . ' files; removals ' . wp_json_encode( $manifest['removals']['counts'] ) . ")\n";
} finally {
	if ( is_string( $filename ) && file_exists( $filename ) ) {
		unlink( $filename );
	}
}
