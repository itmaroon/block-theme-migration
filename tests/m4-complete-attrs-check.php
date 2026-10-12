<?php
/**
 * 書き出し時の「属性の書き足し」のテスト（M4）。
 * data-attributes にあって、ブロックコメントに無い属性が、コメントに書き足されること。
 * 初期値が翻訳される属性（フォーム系の stage_info など）が、対象になる。データベースは変えない。
 */

foreach ( array( 'Itmar_BTM_Sanitizer', 'Itmar_Block_Theme_Migration' ) as $class ) {
	if ( ! class_exists( $class ) ) {
		throw new RuntimeException( $class . ' was not loaded.' );
	}
}
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

// 1) 合成: input-figure-block。stage_info は、コメントに無く、data-attributes にだけある（初期値と同じ値だったので省かれた形）
$data    = array( 'form_type' => 'inquiry', 'form_name' => 'inquiry_form', 'stage_info' => '情報入力', 'not_an_attribute' => 'x' );
$markup  = '<!-- wp:itmar/input-figure-block {"form_type":"inquiry","form_name":"inquiry_form"} -->' . "\n"
	. '<div class="wp-block-itmar-input-figure-block" data-attributes="' . esc_attr( wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) ) . '"></div>' . "\n"
	. '<!-- /wp:itmar/input-figure-block -->';
$report = array();
$out    = Itmar_BTM_Sanitizer::sanitize_content( $markup, $report );
$blocks = parse_blocks( $out );
$assert( 'itmar/input-figure-block' === ( $blocks[0]['blockName'] ?? '' ), 'the block must stay' );
$attrs = $blocks[0]['attrs'] ?? array();
$assert( '情報入力' === ( $attrs['stage_info'] ?? null ), 'stage_info must be written to the block comment: ' . wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE ) );
$assert( ! array_key_exists( 'not_an_attribute', $attrs ), 'a key that is not a block attribute must not be added' );
$assert( 'inquiry' === ( $attrs['form_type'] ?? null ), 'existing attributes must stay' );
$assert( empty( $report ), 'completion is not a removal, so it must not be in the report' );

// 2) すでにコメントにあるものは、触らない（同じ入力を、もう一度かけても、変わらない）
$again = Itmar_BTM_Sanitizer::sanitize_content( $out, $report );
$assert( $again === $out, 'a second pass must not change anything' );

// 3) ほかの名前空間のブロックは、触らない
$core = "<!-- wp:paragraph -->\n<p data-attributes=\"{&quot;a&quot;:&quot;b&quot;}\">x</p>\n<!-- /wp:paragraph -->";
$assert( Itmar_BTM_Sanitizer::sanitize_content( $core, $report ) === $core, 'core blocks must not be touched' );

// 4) 翻訳されたラベルは、英語に戻す（移植元の言語で訳された文言。block-collections の翻訳ファイルがあるときだけ）
if ( function_exists( 'switch_to_locale' ) && switch_to_locale( 'ja' ) ) {
	$clear = __( 'Clear', 'block-collections' ); // phpcs:ignore WordPress.WP.I18n
	if ( 'Clear' !== $clear ) {
		$radio = '<!-- wp:itmar/design-radio {"inputName":"x"} -->' . "\n"
			. '<div class="wp-block-itmar-design-radio"><label class="itmar_radio"><button>' . $clear . '</button></label></div>' . "\n"
			. '<!-- /wp:itmar/design-radio -->';
		$out = Itmar_BTM_Sanitizer::sanitize_content( $radio, $report );
		$assert( false !== strpos( $out, '<button>Clear</button>' ) && false === strpos( $out, $clear . '</button>' ), 'a translated label must be put back to English' );

		// design-select: 選択肢の先頭の項目だけ英語に戻す。選択前の文言（folder_val）が出る場所は、触らない
		$pick   = __( 'Please Select.', 'block-collections' ); // phpcs:ignore WordPress.WP.I18n
		$select = '<!-- wp:itmar/design-select {"inputName":"s"} -->' . "\n"
			. '<div class="wp-block-itmar-design-select"><div><span>' . $pick . '</span><select data-placeholder="' . $pick . '"><option value="">' . $pick . '</option></select></div></div>' . "\n"
			. '<!-- /wp:itmar/design-select -->';
		$out    = Itmar_BTM_Sanitizer::sanitize_content( $select, $report );
		$assert( false !== strpos( $out, '<option value="">Please Select.</option>' ), 'the first option must be put back to English' );
		$assert( false !== strpos( $out, '<span>' . $pick . '</span>' ) && false !== strpos( $out, 'data-placeholder="' . $pick . '"' ), 'the places that show folder_val must stay as they are' );
		$sblock = parse_blocks( $out );
		$assert( $pick === ( $sblock[0]['attrs']['folder_val'] ?? null ), 'folder_val must be written to the block comment' );
	}
	restore_previous_locale();
}

echo "m4-complete-attrs-check: OK\n";
