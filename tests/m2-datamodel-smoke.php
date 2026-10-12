<?php
/**
 * データモデルの宣言・依存の宣言・依存の検査・マニフェスト v5 のテスト（M2）。
 * WordPress を読み込んだ状態で実行する（他の *-smoke.php と同じ）。
 */

foreach ( array( 'Itmar_BTM_DataModel', 'Itmar_BTM_Dependencies', 'Itmar_BTM_Inspector' ) as $class ) {
	if ( ! class_exists( $class ) ) {
		throw new RuntimeException( $class . ' was not loaded.' );
	}
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

// ---- 使用箇所の索引（スキャンが、紐づけの出現をすべて残す） ----
$content = <<<'HTML'
<!-- wp:itmar/pickup-posts {"selectedSlug":"works","selectedRest":"works","dispTaxonomies":["creator","kind"],"choiceFields":["title","date","acf_gallery"],"blockMap":{"acf_gallery":"itmar/slide-mv","link":"itmar/design-button","relate_url":"itmar/design-title"}} /-->

<!-- wp:itmar/design-title {"linkKind":"archive","selectedSlug":"concept"} -->
<div data-attributes="{&quot;linkKind&quot;:&quot;archive&quot;,&quot;selectedSlug&quot;:&quot;concept&quot;}"><h2>t</h2></div>
<!-- /wp:itmar/design-title -->

<!-- wp:itmar/post-crumbs {"postTypeName":"作品"} /-->
HTML;
$items = array( array( 'kind' => 'template', 'slug' => 'archive-works', 'title' => 'archive-works', 'content' => $content ) );
$scan  = Itmar_BTM_Inspector::scan( $items );
$assert( ! empty( $scan['usage'] ), 'scan() did not return a usage index.' );
$kinds = array();
foreach ( $scan['usage'] as $u ) {
	$kinds[ $u['kind'] ][] = $u['value'];
}
$assert( in_array( 'works', $kinds['post-type'] ?? array(), true ), 'post-type usage (pickup-posts) is missing.' );
$assert( in_array( 'concept', $kinds['post-type'] ?? array(), true ), 'post-type usage (design-title archive) is missing.' );
$assert( in_array( 'works', $kinds['post-type-rest'] ?? array(), true ), 'post-type-rest usage is missing.' );
$assert( in_array( 'creator', $kinds['taxonomy'] ?? array(), true ) && in_array( 'kind', $kinds['taxonomy'] ?? array(), true ), 'taxonomy usage must be recorded per element.' );
$fields = $kinds['field'] ?? array();
$assert( in_array( 'acf_gallery', $fields, true ) && in_array( 'relate_url', $fields, true ), 'custom field usage is missing.' );
$assert( ! in_array( 'title', $fields, true ) && ! in_array( 'link', $fields, true ) && ! in_array( 'date', $fields, true ), 'Core fields must not be reported as custom fields.' );
$assert( in_array( '作品', $kinds['post-type-label'] ?? array(), true ), 'post-type-label usage is missing.' );
foreach ( $scan['findings'] as $finding ) {
	$assert( ! isset( $finding['occurrences'] ), 'occurrences must not stay in the findings.' );
}

// ---- データモデルの宣言 ----
$custom_type = null;
foreach ( get_post_types( array( 'public' => true, '_builtin' => false ), 'objects' ) as $type ) {
	$custom_type = $type;
	break;
}
$usage = array(
	array( 'item' => 'template:x', 'block' => 'itmar/pickup-posts', 'path' => 'selectedSlug', 'kind' => 'post-type', 'value' => $custom_type ? $custom_type->name : 'no_such_type' ),
	array( 'item' => 'template:x', 'block' => 'itmar/pickup-posts', 'path' => 'selectedSlug', 'kind' => 'post-type', 'value' => 'post' ),
	array( 'item' => 'template:x', 'block' => 'itmar/pickup-posts', 'path' => 'selectedSlug', 'kind' => 'post-type', 'value' => 'definitely_missing_type' ),
	array( 'item' => 'template:x', 'block' => 'itmar/pickup-posts', 'path' => 'dispTaxonomies', 'kind' => 'taxonomy', 'value' => 'category' ),
	array( 'item' => 'template:x', 'block' => 'itmar/pickup-posts', 'path' => 'choiceFields', 'kind' => 'field', 'value' => 'acf_definitely_missing_field' ),
	array( 'item' => 'template:x', 'block' => 'itmar/pickup-posts', 'path' => 'choiceFields', 'kind' => 'field', 'value' => 'meta_definitely_missing_meta' ),
);
$model = Itmar_BTM_DataModel::build( $usage, array( 'taxonomy-definitely_missing_tax' ) );
// meta_xxx は、カスタムフィールドではなく投稿メタ。見つからないフィールドには入れない
$assert( ! in_array( 'meta_definitely_missing_meta', $model['unmatchedFields'], true ), 'Post meta must not be reported as an unmatched custom field.' );
$meta_entries = array_column( $model['postMeta'], null, 'reference' );
$assert( isset( $meta_entries['meta_definitely_missing_meta'] ) && 'definitely_missing_meta' === $meta_entries['meta_definitely_missing_meta']['key'] && false === $meta_entries['meta_definitely_missing_meta']['registered'], 'Post meta must be declared with its key and registration state.' );
$slugs = array_column( $model['postTypes'], 'slug' );
$assert( ! in_array( 'post', $slugs, true ), 'Built-in post types must not be declared.' );
$assert( ! in_array( 'category', array_column( $model['taxonomies'], 'slug' ), true ), 'Built-in taxonomies must not be declared.' );
$assert( in_array( 'definitely_missing_type', $slugs, true ), 'A referenced but unregistered post type must still be declared.' );
foreach ( $model['postTypes'] as $entry ) {
	if ( 'definitely_missing_type' === $entry['slug'] ) {
		$assert( false === $entry['exists'], 'A missing post type must be marked exists=false.' );
	}
	if ( $custom_type && $custom_type->name === $entry['slug'] ) {
		$assert( true === $entry['exists'] && isset( $entry['hasArchive'], $entry['restBase'], $entry['taxonomies'] ), 'A registered post type must carry its definition.' );
	}
}
$assert( in_array( 'definitely_missing_tax', array_column( $model['taxonomies'], 'slug' ), true ), 'A taxonomy implied by a template name must be declared.' );
$assert( in_array( 'acf_definitely_missing_field', $model['unmatchedFields'], true ), 'An unknown field must be listed as unmatched.' );

// SCF／ACF が使えるサイトでは、実在するフィールドが、グループに結びつく
if ( Itmar_BTM_DataModel::fields_available() ) {
	$real = null;
	foreach ( acf_get_field_groups() as $group ) {
		$fields_of_group = acf_get_fields( $group );
		if ( ! empty( $fields_of_group ) ) {
			$real = array( 'group' => $group, 'name' => (string) $fields_of_group[0]['name'] );
			break;
		}
	}
	if ( $real ) {
		$model2 = Itmar_BTM_DataModel::build(
			array( array( 'item' => 'template:x', 'block' => 'itmar/pickup-posts', 'path' => 'choiceFields', 'kind' => 'field', 'value' => 'acf_' . $real['name'] ) ),
			array()
		);
		$assert( empty( $model2['unmatchedFields'] ), 'An existing field must not be unmatched.' );
		$assert( in_array( $real['group']['title'], array_column( $model2['fieldGroups'], 'title' ), true ), 'The field group of an existing field must be declared.' );
		$assert( ! empty( $model2['fieldGroups'][0]['fields'] ) && isset( $model2['fieldGroups'][0]['fields'][0]['type'] ), 'A field group must carry its fields with types.' );
	}
}

// ---- 依存の宣言（必要なプラグイン・ブロック） ----
$dep_content = <<<'HTML'
<!-- wp:itmar/design-button {"linkKind":"none"} /-->
<!-- wp:itmar/pickup-posts {"blocksAttributesArray":[{"name":"itmar/design-title","attributes":{},"innerBlocks":[]}]} /-->
<!-- wp:vendor/unknown-block /-->
<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->
HTML;
$requires = Itmar_BTM_DataModel::requires( array( array( 'kind' => 'template', 'slug' => 'x', 'title' => 'x', 'content' => $dep_content ) ), true );
$assert( Itmar_BTM_DataModel::MIN_WORDPRESS === $requires['wordpress']['minVersion'], 'The WordPress minimum version must be declared.' );
$assert( in_array( 'itmar/design-button', $requires['blocks'], true ) && in_array( 'itmar/design-title', $requires['blocks'], true ), 'Blocks (including embedded ones) must be collected.' );
$assert( ! in_array( 'core/paragraph', $requires['blocks'], true ), 'Core blocks must not be declared.' );
$assert( in_array( 'vendor/unknown-block', $requires['missingBlocks'], true ), 'An unregistered block must be reported as missing.' );
$slugs = array_column( $requires['plugins'], 'slug' );
$assert( in_array( 'block-collections', $slugs, true ), 'The provider of design-button (block-collections) must be declared.' );
$bc = array_values( array_filter( $requires['plugins'], static fn( $p ) => 'block-collections' === $p['slug'] ) )[0];
$assert( in_array( 'itmar/design-button', $bc['blocks'], true ) && '' !== $bc['version'], 'The provider must list its blocks and version.' );
if ( WP_Block_Type_Registry::get_instance()->is_registered( 'itmar/pickup-posts' ) ) {
	$assert( in_array( 'query-blocks', $slugs, true ), 'The provider of pickup-posts (query-blocks) must be declared.' );
}

// ---- 依存の検査（移植先） ----
$manifest = array(
	'requires'  => array(
		'wordpress' => array( 'minVersion' => '99.0' ),
		'plugins'   => array(
			array( 'slug' => 'block-collections', 'name' => 'Block Collections', 'minVersion' => $bc['version'] ),
			array( 'slug' => 'block-collections', 'name' => 'Newer than installed', 'minVersion' => '999.0.0' ),
			array( 'slug' => 'no-such-plugin', 'name' => 'Missing Plugin', 'minVersion' => '1.0' ),
			array( 'slug' => 'no-such-optional', 'name' => 'Missing Optional', 'minVersion' => '', 'optional' => true ),
		),
		'blocks'    => array( 'itmar/design-button', 'vendor/unknown-block' ),
	),
	'dataModel' => array(
		'postTypes'  => array( array( 'slug' => 'definitely_missing_type', 'label' => 'Missing' ), array( 'slug' => 'page' ) ),
		'taxonomies' => array( array( 'slug' => 'definitely_missing_tax' ) ),
		'usage'      => array( array( 'kind' => 'field', 'value' => 'acf_definitely_missing_field' ) ),
		'postMeta'   => array( array( 'reference' => 'meta_definitely_missing_meta', 'key' => 'definitely_missing_meta' ) ),
	),
);
$rows = Itmar_BTM_Dependencies::check( $manifest );
$find = static function ( string $label ) use ( $rows ): ?array {
	foreach ( $rows as $row ) {
		if ( false !== strpos( $row['label'], $label ) ) {
			return $row;
		}
	}
	return null;
};
$assert( 'error' === $rows[0]['status'], 'A WordPress version above the installed one must be an error.' );
$assert( 'ok' === $find( 'Block Collections' )['status'], 'An active plugin with a satisfied version must be ok.' );
$assert( 'warn' === $find( 'Newer than installed' )['status'], 'A plugin older than the built-with version must be a warning.' );
$assert( 'error' === $find( 'Missing Plugin' )['status'], 'A missing required plugin must be an error.' );
$assert( 'warn' === $find( 'Missing Optional' )['status'], 'A missing optional plugin must be a warning.' );
$blocks_row = null;
foreach ( $rows as $row ) {
	if ( 'Blocks' === $row['group'] ) {
		$blocks_row = $row;
	}
}
$assert( $blocks_row && 'error' === $blocks_row['status'] && false !== strpos( $blocks_row['detail'], 'vendor/unknown-block' ), 'An unregistered block must be reported as an error.' );
$assert( 'warn' === $find( 'definitely_missing_type' )['status'], 'A missing post type must be a warning (it can be bound).' );
$assert( 'warn' === $find( 'definitely_missing_tax' )['status'], 'A missing taxonomy must be a warning.' );
$assert( 'warn' === $find( 'acf_definitely_missing_field' )['status'], 'A missing field must be a warning.' );
$assert( 'warn' === $find( 'meta_definitely_missing_meta' )['status'], 'An unregistered post meta key must be a warning.' );
$assert( is_array( Itmar_BTM_Dependencies::check( array() ) ), 'An empty manifest must not break the check.' );

// ---- マニフェスト v5 ----
$filename = wp_generate_block_templates_export_file();
if ( is_wp_error( $filename ) ) {
	throw new RuntimeException( $filename->get_error_message() );
}
try {
	$method = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'enhance_export_zip' );
	$result = $method->invoke( null, $filename, array() );
	$assert( true === $result, 'enhance_export_zip failed.' );
	$zip = new ZipArchive();
	$assert( true === $zip->open( $filename ), 'Could not reopen the ZIP.' );
	$manifest = json_decode( (string) $zip->getFromName( 'block-theme-migration.json' ), true );
	$zip->close();
	$assert( 5 === ( $manifest['schemaVersion'] ?? 0 ), 'The manifest must be schema version 5.' );
	$assert( ! empty( $manifest['requires']['plugins'] ) && isset( $manifest['requires']['wordpress']['minVersion'] ), 'The manifest must carry requires.' );
	$assert( isset( $manifest['dataModel']['postTypes'], $manifest['dataModel']['taxonomies'], $manifest['dataModel']['fieldGroups'], $manifest['dataModel']['usage'] ), 'The manifest must carry the data model declaration.' );
	$assert( isset( $manifest['templates'], $manifest['templateParts'], $manifest['assignableTemplates'] ), 'The v4 keys must remain in the manifest.' );
} finally {
	@unlink( $filename ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

// ---- 実サイトの宣言（例外が出ないこと） ----
$declared = Itmar_BTM_Inspector::declarations();
$assert( isset( $declared['requires'], $declared['dataModel'] ), 'declarations() must return requires and dataModel.' );

echo 'm2-datamodel-smoke: ok (' . count( $declared['dataModel']['usage'] ) . ' usage entries, ' . count( $declared['requires']['plugins'] ) . " plugins declared on this site)\n";
