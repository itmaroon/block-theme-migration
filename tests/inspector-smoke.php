<?php
/**
 * 点検（Itmar_BTM_Inspector）と、書き出しの除外のテスト。
 * WordPress を読み込んだ状態で実行する（他の *-smoke.php と同じ）。
 */

if ( ! class_exists( 'Itmar_BTM_Inspector' ) ) {
	throw new RuntimeException( 'Inspector class was not loaded.' );
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

// ---- 規則データ ----
$rules = Itmar_BTM_Inspector::rules();
$assert( ! empty( $rules['itmar/design-button'] ), 'design-button rules are missing.' );
$assert( ! empty( $rules['itmar/pickup-posts'] ), 'pickup-posts rules are missing.' );
$assert( ! isset( $rules['_comment'] ), 'The comment key must not become a rule.' );

// ---- 内部リンクの判定 ----
$home = home_url();
$cases = array(
	array( '[home_url]/contact', true ),
	array( '[home_url]', true ),
	array( $home . '/shop/', true ),
	array( '/shop/', true ),
	array( 'https://block-test.local/x', true ),   // 過去の開発サイト（.local）
	array( 'http://equip-backup.local', true ),
	array( 'https://twitter.com/itmaroon2014', false ),
	array( 'mailto:a@example.com', false ),
	array( '//example.com/a', false ),
	array( '', false ),
);
foreach ( $cases as $case ) {
	$assert( Itmar_BTM_Inspector::is_internal_url( $case[0] ) === $case[1], 'is_internal_url failed for: ' . $case[0] );
}

// ---- スキャン（合成した項目） ----
$content = <<<'HTML'
<!-- wp:itmar/design-button {"linkKind":"fixed","selectedSlug":"contact","selectedPageUrl":"[home_url]/contact"} -->
<div data-attributes="{&quot;linkKind&quot;:&quot;fixed&quot;,&quot;selectedSlug&quot;:&quot;contact&quot;,&quot;selectedPageUrl&quot;:&quot;[home_url]/contact&quot;}"><button>a</button></div>
<!-- /wp:itmar/design-button -->

<!-- wp:itmar/design-button {"selectedPageUrl":"https://twitter.com/itmaroon2014"} -->
<div data-attributes="{&quot;linkKind&quot;:&quot;none&quot;,&quot;selectedPageUrl&quot;:&quot;https://twitter.com/itmaroon2014&quot;}"><button>b</button></div>
<!-- /wp:itmar/design-button -->

<!-- wp:itmar/design-title {"linkKind":"archive","selectedSlug":"works"} -->
<div data-attributes="{&quot;linkKind&quot;:&quot;archive&quot;,&quot;selectedSlug&quot;:&quot;works&quot;}"><h2>t</h2></div>
<!-- /wp:itmar/design-title -->

<!-- wp:itmar/design-title {"linkKind":"none","selectedSlug":"custom-login-page"} -->
<div data-attributes="{&quot;linkKind&quot;:&quot;none&quot;,&quot;selectedSlug&quot;:&quot;custom-login-page&quot;}"><h2>u</h2></div>
<!-- /wp:itmar/design-title -->

<!-- wp:itmar/pickup-posts {"selectedSlug":"works","selectedRest":"works","dispTaxonomies":["creator","kind"],"choiceFields":["title","date","acf_gallery"],"blockMap":{"acf_gallery":"itmar/slide-mv","link":"itmar/design-button"},"posts":[{"id":1,"link":"https://x.local/a"}],"blocksAttributesArray":[{"name":"itmar/design-group","attributes":{},"innerBlocks":[{"name":"itmar/design-button","attributes":{"selectedPageUrl":"https://sazanka.local","linkKind":"fixed"},"innerBlocks":[]}]}]} /-->

<!-- wp:itmar/post-pagenation {"forwardBlockAttributes":{"linkKind":"fixed","selectedPageUrl":"http://equip-backup.local"}} /-->

<!-- wp:itmar/reservation-block {"resourceId":2025,"resourceSlug":"abc","closedWeekdays":[3],"selectedSlug":"lesson","selectedRest":"lesson"} /-->

<!-- wp:navigation {"ref":5} /-->

<!-- wp:paragraph -->
<p>see <a href="[home_url]/about">about</a> and <a href="https://example.com/">ext</a>, mail master@itmaroon.net, img https://x.local/wp-content/uploads/2026/09/a.jpg</p>
<!-- /wp:paragraph -->
HTML;

$items = array(
	array( 'kind' => 'template', 'slug' => 'synthetic', 'title' => 'synthetic', 'content' => $content ),
);
$scan = Itmar_BTM_Inspector::scan( $items );
$by   = array();
foreach ( $scan['findings'] as $row ) {
	$by[ $row['kind'] . '|' . $row['block'] . '|' . $row['path'] ] = $row;
}
$has = static function ( string $key ) use ( $by ): bool {
	return isset( $by[ $key ] );
};

// 内部リンク（linkKind=fixed のとき、slug とURLの両方）
$assert( $has( 'internal-link|itmar/design-button|selectedPageUrl' ), 'Internal button URL was not detected.' );
$assert( $has( 'internal-link|itmar/design-button|selectedSlug' ), 'fixed selectedSlug was not detected as a page link.' );
// 外部リンクは残す
$assert( $has( 'external-link|itmar/design-button|selectedPageUrl' ), 'External button URL should be reported as kept.' );
$assert( 'keep' === $by['external-link|itmar/design-button|selectedPageUrl']['action'], 'External link action must be keep.' );
// linkKind=archive の selectedSlug は、リンクではなく投稿タイプの紐づけ
$assert( $has( 'binding-post-type|itmar/design-title|selectedSlug' ), 'archive selectedSlug must be a post-type binding.' );
// linkKind=none の selectedSlug は何でもない
$assert( ! isset( $by['internal-link|itmar/design-title|selectedSlug'] ), 'selectedSlug with linkKind none must not be treated as a page link.' );
// pickup-posts の紐づけ・中身・埋め込み
$assert( $has( 'binding-post-type|itmar/pickup-posts|selectedSlug' ), 'pickup-posts post type binding is missing.' );
$assert( $has( 'binding-taxonomy|itmar/pickup-posts|dispTaxonomies' ), 'pickup-posts taxonomy binding is missing.' );
$assert( $has( 'binding-field|itmar/pickup-posts|choiceFields' ), 'pickup-posts field binding is missing.' );
$assert( ! in_array( 'title', $by['binding-field|itmar/pickup-posts|choiceFields']['examples'], true ), 'Core fields must be skipped.' );
$assert( $has( 'binding-field|itmar/pickup-posts|blockMap' ), 'blockMap keys must be field bindings.' );
$assert( $has( 'content|itmar/pickup-posts|posts' ), 'Stored posts must be detected as content data.' );
$assert( $has( 'internal-link|itmar/design-button|blocksAttributesArray→selectedPageUrl' ), 'Embedded block link was not detected.' );
$assert( $has( 'internal-link|itmar/design-button|forwardBlockAttributes→selectedPageUrl' ), 'Embedded attribute link was not detected.' );
// 予約ブロック
$assert( $has( 'content|itmar/reservation-block|resourceId' ), 'reservation resourceId was not detected.' );
$assert( $has( 'binding-post-type|itmar/reservation-block|selectedSlug' ), 'reservation post type binding is missing.' );
// ナビゲーション
$assert( $has( 'navigation-ref|core/navigation|ref' ), 'Navigation ref was not detected.' );
// 本文
$assert( $has( 'internal-link|core/paragraph (text)|a[href]' ), 'Text link was not detected.' );
$assert( $has( 'external-link|core/paragraph (text)|a[href]' ), 'External text link should be reported as kept.' );
$assert( $has( 'email|(text)|email' ), 'E-mail address was not detected.' );
$assert( $has( 'uploads-url|(text)|wp-content/uploads' ), 'Uploads URL was not detected.' );
$assert( $has( 'local-url|(text)|*.local' ), '.local URL was not detected.' );

// ---- 棚卸し（実サイト） ----
$real      = Itmar_BTM_Inspector::collect_items();
$assert( ! empty( $real ), 'No items were collected.' );
$inventory = Itmar_BTM_Inspector::inventory( $real );
foreach ( array( 'templates', 'parts', 'patterns', 'synced', 'unresolved', 'missing' ) as $key ) {
	$assert( array_key_exists( $key, $inventory ), 'inventory is missing the key: ' . $key );
}
$package = Itmar_BTM_Inspector::package_check();
$assert( ! empty( $package ), 'package_check returned nothing.' );

// ---- 除外: 参照されているパーツは外せない ----
$referenced = null;
foreach ( $inventory['parts'] as $part ) {
	if ( ! $part['unused'] ) {
		$referenced = $part['slug'];
		break;
	}
}
if ( $referenced ) {
	$conflicts = Itmar_BTM_Inspector::excluded_part_conflicts( array( $referenced ), array() );
	$assert( ! empty( $conflicts[ $referenced ] ), 'Excluding a used part must report a conflict.' );
}

// ---- 除外: ZIP から外れる ----
$filename = wp_generate_block_templates_export_file();
if ( is_wp_error( $filename ) ) {
	throw new RuntimeException( $filename->get_error_message() );
}
try {
	$templates = array_values( array_filter( get_block_templates(), static fn( $t ): bool => ! in_array( $t->slug, array( 'index', 'front-page', 'home', '404', 'page', 'single', 'archive', 'search' ), true ) ) );
	$parts     = get_block_templates( array(), 'wp_template_part' );
	$assert( ! empty( $templates ) && ! empty( $parts ), 'The export test needs a template and a part.' );
	$drop_template = $templates[0]->slug;
	$drop_part     = $parts[0]->slug;

	$prop = new ReflectionProperty( 'Itmar_Block_Theme_Migration', 'export_excluded' );
	$prop->setAccessible( true );
	$prop->setValue( null, array( 'templates' => array( $drop_template ), 'parts' => array( $drop_part ) ) );
	$method = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'enhance_export_zip' );
	$result = $method->invoke( null, $filename, array() );
	$prop->setValue( null, array( 'templates' => array(), 'parts' => array() ) );
	$assert( true === $result, 'enhance_export_zip failed.' );

	$zip = new ZipArchive();
	$assert( true === $zip->open( $filename ), 'Could not reopen the ZIP.' );
	$assert( false === $zip->locateName( 'templates/' . $drop_template . '.html' ), 'The excluded template is still in the ZIP.' );
	$assert( false === $zip->locateName( 'parts/' . $drop_part . '.html' ), 'The excluded part is still in the ZIP.' );
	$manifest = json_decode( (string) $zip->getFromName( 'block-theme-migration.json' ), true );
	$theme    = json_decode( (string) $zip->getFromName( 'theme.json' ), true );
	$names    = array_column( $manifest['templateParts'] ?? array(), 'name' );
	$assert( ! in_array( $drop_part, $names, true ), 'The excluded part is still in the manifest.' );
	$tj_names = array_column( $theme['templateParts'] ?? array(), 'name' );
	$assert( ! in_array( $drop_part, $tj_names, true ), 'The excluded part is still in theme.json.' );
	$tpl_slugs = array_column( $manifest['templates'] ?? array(), 'slug' );
	$assert( ! in_array( $drop_template, $tpl_slugs, true ), 'The excluded template is still in the manifest.' );
	$assert( ! empty( $manifest['excluded']['parts'] ) && ! empty( $manifest['excluded']['templates'] ), 'The manifest must record what was excluded.' );
	$zip->close();
} finally {
	@unlink( $filename ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

echo 'inspector-smoke: ok (' . count( $scan['findings'] ) . ' findings on synthetic content; ' . count( $real ) . " real items)\n";
