<?php
/**
 * データモデルの宣言と、依存の宣言（マニフェスト v5）。
 *
 *  - データモデル  投稿タイプ・タクソノミー・カスタムフィールドの定義を、情報として書き出す（読み取り専用）。
 *                  移植先で作るためではない。移植先に既にあるものへ紐づけるための手がかり（D3）。
 *  - 使用箇所の索引 どのテンプレート／パーツ／パターンの、どのブロックの、どの属性が何を参照しているか
 *  - 依存          必要なプラグイン・ブロック・WordPress のバージョン
 *
 * 要件: docs/requirements-v0.2.md §6.2・§9（M2）
 *
 * @package block-theme-migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Itmar_BTM_DataModel {
	/** WordPress 本体の組み込みの投稿タイプ（宣言の対象にしない） */
	private const BUILTIN_TYPES = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles' );

	/** 組み込みのタクソノミー */
	private const BUILTIN_TAXONOMIES = array( 'category', 'post_tag', 'post_format', 'nav_menu', 'link_category', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' );

	/** 移植先の最低バージョン（D9） */
	public const MIN_WORDPRESS = '6.6';

	/* ------------------------------------------------------------------ */
	/* 使用箇所 → データモデル                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * 使用箇所の索引から、データモデルの宣言を作る。
	 *
	 * @param array<int, array<string,string>> $usage          Itmar_BTM_Inspector::scan() の usage
	 * @param string[]                         $template_slugs パッケージに入るテンプレートの slug（single-{type} などから投稿タイプを導く）
	 */
	public static function build( array $usage, array $template_slugs = array() ): array {
		$types      = array(); // slug => 参照元の数
		$taxonomies = array();
		$fields     = array(); // フィールド名 => 参照元の数
		$meta       = array(); // 投稿メタの参照名（meta_xxx） => 参照元の数
$labels     = array();

		foreach ( $usage as $u ) {
			$value = (string) ( $u['value'] ?? '' );
			if ( '' === $value ) {
				continue;
			}
			switch ( $u['kind'] ) {
				case 'post-type':
					$types[ $value ] = ( $types[ $value ] ?? 0 ) + 1;
					break;
				case 'post-type-rest':
					$slug = self::post_type_from_rest_base( $value );
					$types[ $slug ] = ( $types[ $slug ] ?? 0 ) + 1;
					break;
				case 'post-type-label':
					$labels[ $value ] = true;
					break;
				case 'taxonomy':
					$taxonomies[ $value ] = ( $taxonomies[ $value ] ?? 0 ) + 1;
					break;
				case 'field':
					// meta_xxx は、カスタムフィールド（SCF／ACF）ではなく、投稿メタ（連携プラグインなどが保存する値）
					if ( 0 === strpos( $value, 'meta_' ) ) {
						$meta[ $value ] = ( $meta[ $value ] ?? 0 ) + 1;
					} else {
						$fields[ $value ] = ( $fields[ $value ] ?? 0 ) + 1;
					}
					break;
			}
		}

		// テンプレートの名前から導かれるもの（archive-works → works など）
		foreach ( $template_slugs as $slug ) {
			if ( preg_match( '/^(?:single|archive)-(.+)$/', $slug, $m ) ) {
				$types[ $m[1] ] = $types[ $m[1] ] ?? 0;
			} elseif ( preg_match( '/^taxonomy-(.+)$/', $slug, $m ) ) {
				$taxonomies[ $m[1] ] = $taxonomies[ $m[1] ] ?? 0;
			}
		}

		$post_types = array();
		foreach ( array_keys( $types ) as $slug ) {
			if ( in_array( $slug, self::BUILTIN_TYPES, true ) ) {
				continue;
			}
			$post_types[] = self::describe_post_type( $slug, $types[ $slug ] );
		}
		$tax_list = array();
		foreach ( array_keys( $taxonomies ) as $slug ) {
			if ( in_array( $slug, self::BUILTIN_TAXONOMIES, true ) ) {
				continue;
			}
			$tax_list[] = self::describe_taxonomy( $slug, $taxonomies[ $slug ] );
		}

		$groups    = self::field_groups( array_keys( $fields ), array_column( $post_types, 'slug' ) );
		$post_meta = self::post_meta( array_keys( $meta ), array_column( $post_types, 'slug' ) );

		return array(
			'postTypes'   => $post_types,
			'taxonomies'  => $tax_list,
			'fieldGroups' => $groups['groups'],
			'unmatchedFields' => $groups['unmatched'],
			'postMeta'    => $post_meta,
'postTypeLabels'  => array_keys( $labels ),
			'usage'       => array_values( $usage ),
		);
	}

	/** REST の基底名から投稿タイプの slug を引く（見つからなければ、そのまま） */
	private static function post_type_from_rest_base( string $rest_base ): string {
		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			if ( ( $type->rest_base ?: $type->name ) === $rest_base || $type->name === $rest_base ) {
				return $type->name;
			}
		}
		return $rest_base;
	}

	private static function describe_post_type( string $slug, int $uses ): array {
		$object = get_post_type_object( $slug );
		if ( ! $object ) {
			return array( 'slug' => $slug, 'exists' => false, 'references' => $uses );
		}
		return array(
			'slug'       => $slug,
			'exists'     => true,
			'label'      => (string) $object->label,
			'public'     => (bool) $object->public,
			'hasArchive' => (bool) $object->has_archive,
			'hierarchical' => (bool) $object->hierarchical,
			'restBase'   => (string) ( $object->rest_base ?: $slug ),
			'supports'   => array_keys( array_filter( get_all_post_type_supports( $slug ) ) ),
			'taxonomies' => array_values( get_object_taxonomies( $slug ) ),
			'references' => $uses,
		);
	}

	private static function describe_taxonomy( string $slug, int $uses ): array {
		$object = get_taxonomy( $slug );
		if ( ! $object ) {
			return array( 'slug' => $slug, 'exists' => false, 'references' => $uses );
		}
		return array(
			'slug'         => $slug,
			'exists'       => true,
			'label'        => (string) $object->label,
			'hierarchical' => (bool) $object->hierarchical,
			'postTypes'    => array_values( (array) $object->object_type ),
			'restBase'     => (string) ( $object->rest_base ?: $slug ),
			'references'   => $uses,
		);
	}

	/* ------------------------------------------------------------------ */
	/* カスタムフィールド（SCF／ACF の読み取り専用の取得）                       */
	/* ------------------------------------------------------------------ */

	/** SCF／ACF が使えるか */
	public static function fields_available(): bool {
		return function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' );
	}

	/**
	 * フィールドを平らにする（グループ型・リピーター型の子は「親.子」の名前にする）
	 *
	 * @param array<int, array> $fields
	 * @return array<int, array{name:string,type:string,label:string,choices?:string[]}>
	 */
	private static function flatten_fields( array $fields, string $prefix = '' ): array {
		$out = array();
		foreach ( $fields as $field ) {
			$name  = $prefix . (string) ( $field['name'] ?? '' );
			$entry = array(
				'name'  => $name,
				'type'  => (string) ( $field['type'] ?? '' ),
				'label' => (string) ( $field['label'] ?? '' ),
			);
			if ( ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
				$entry['choices'] = array_map( 'strval', array_keys( $field['choices'] ) );
			}
			$out[] = $entry;
			if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				$out = array_merge( $out, self::flatten_fields( $field['sub_fields'], $name . '.' ) );
			}
		}
		return $out;
	}

	/** ブロックの参照名（acf_gallery、gallery）から、フィールドの名前にする */
	public static function field_name_of( string $reference ): string {
		return 0 === strpos( $reference, 'acf_' ) ? substr( $reference, 4 ) : $reference;
	}

	/**
	 * 参照されているフィールドの属するグループを宣言する。
	 *
	 * @param string[] $references 参照名（acf_gallery など）
	 * @param string[] $post_types 宣言する投稿タイプ（location の条件で、これらを対象にするグループも入れる）
	 * @return array{groups: array<int,array>, unmatched: string[]}
	 */
	private static function field_groups( array $references, array $post_types ): array {
		$names     = array_map( array( __CLASS__, 'field_name_of' ), $references );
		$by_name   = array_combine( $references, $names ) ?: array();
		$groups    = array();
		$found     = array();
		if ( self::fields_available() ) {
			foreach ( acf_get_field_groups() as $group ) {
				if ( isset( $group['active'] ) && ! $group['active'] ) {
					continue;
				}
				$flat   = self::flatten_fields( (array) acf_get_fields( $group ) );
				$gnames = array_column( $flat, 'name' );

				// 対象の投稿タイプ（location の param=post_type の条件）
				$targets = array();
				foreach ( (array) ( $group['location'] ?? array() ) as $rule_set ) {
					foreach ( (array) $rule_set as $rule ) {
						if ( 'post_type' === ( $rule['param'] ?? '' ) && '==' === ( $rule['operator'] ?? '' ) ) {
							$targets[] = (string) $rule['value'];
						}
					}
				}
				$targets = array_values( array_unique( $targets ) );

				$matched = array();
				foreach ( $by_name as $reference => $name ) {
					if ( in_array( $name, $gnames, true ) ) {
						$matched[]      = $reference;
						$found[ $reference ] = true;
					}
				}
				if ( empty( $matched ) && empty( array_intersect( $targets, $post_types ) ) ) {
					continue;
				}
				$groups[] = array(
					'title'      => (string) ( $group['title'] ?? '' ),
					'key'        => (string) ( $group['key'] ?? '' ),
					'postTypes'  => $targets,
					'fields'     => $flat,
					'referenced' => $matched,
				);
			}
		}
		$unmatched = array();
		foreach ( $references as $reference ) {
			if ( empty( $found[ $reference ] ) ) {
				$unmatched[] = $reference;
			}
		}
		return array( 'groups' => $groups, 'unmatched' => $unmatched );
	}

	/**
	 * 投稿メタ（meta_xxx）の参照。登録されたメタキーか（register_post_meta）を、情報として添える。
	 *
	 * @param string[] $references 参照名（meta_shopify_product_id など）
	 * @param string[] $post_types 宣言する投稿タイプ
	 * @return array<int, array{reference:string,key:string,registered:bool,postTypes:string[]}>
	 */
	public static function post_meta( array $references, array $post_types ): array {
		$out = array();
		foreach ( $references as $reference ) {
			$key     = substr( $reference, strlen( 'meta_' ) );
			$holders = array();
			foreach ( array_merge( array( 'post' ), $post_types ) as $type ) {
				if ( array_key_exists( $key, get_registered_meta_keys( 'post', $type ) ) ) {
					$holders[] = $type;
				}
			}
			$out[] = array(
				'reference'  => $reference,
				'key'        => $key,
				'registered' => ! empty( $holders ),
				'postTypes'  => array_values( array_unique( $holders ) ),
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* 依存（必要なプラグイン・ブロック）                                      */
	/* ------------------------------------------------------------------ */

	/** ブロックの木から、コア以外のブロック名を集める（属性に埋め込まれたブロックも含む） */
	private static function collect_block_names( array $blocks, array &$names ): void {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			if ( $name && 0 !== strpos( $name, 'core/' ) ) {
				$names[ $name ] = true;
			}
			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				self::collect_embedded_names( $block['attrs'], $names, 0 );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::collect_block_names( $block['innerBlocks'], $names );
			}
		}
	}

	/** 属性の中の、{ name: "itmar/design-title", attributes: {…} } の形（埋め込まれたブロック）を拾う */
	private static function collect_embedded_names( array $node, array &$names, int $depth ): void {
		if ( $depth > 10 ) {
			return;
		}
		if ( isset( $node['name'], $node['attributes'] ) && is_string( $node['name'] ) && preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $node['name'] ) && 0 !== strpos( $node['name'], 'core/' ) ) {
			$names[ $node['name'] ] = true;
		}
		foreach ( $node as $child ) {
			if ( is_array( $child ) ) {
				self::collect_embedded_names( $child, $names, $depth + 1 );
			}
		}
	}

	/** 登録されたブロックを提供しているプラグインのフォルダ名（スクリプトの場所から判定） */
	private static function provider_slug( string $block_name ): ?string {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
		if ( ! $type ) {
			return null;
		}
		$handles = array_merge(
			(array) $type->editor_script_handles,
			(array) $type->script_handles,
			(array) $type->view_script_handles
		);
		$scripts = wp_scripts();
		foreach ( $handles as $handle ) {
			$src = isset( $scripts->registered[ $handle ] ) ? (string) $scripts->registered[ $handle ]->src : '';
			if ( preg_match( '#/plugins/([^/]+)/#', $src, $m ) ) {
				return $m[1];
			}
		}
		return null;
	}

	/**
	 * 必要なプラグイン・ブロック。
	 *
	 * @param array<int, array<string, mixed>> $items 書き出しに入る項目
	 * @param bool                             $needs_fields カスタムフィールドの参照があるか
	 */
	public static function requires( array $items, bool $needs_fields = false ): array {
		$names = array();
		foreach ( $items as $item ) {
			self::collect_block_names( parse_blocks( (string) $item['content'] ), $names );
		}
		ksort( $names );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = get_plugins();
		$by_dir    = array();
		foreach ( $installed as $file => $data ) {
			$by_dir[ strtok( $file, '/' ) ] = array( 'file' => $file, 'data' => $data );
		}

		$plugins = array(); // slug => 宣言
		$missing = array();
		foreach ( array_keys( $names ) as $block_name ) {
			$slug = self::provider_slug( $block_name );
			if ( null === $slug || ! isset( $by_dir[ $slug ] ) ) {
				$missing[] = $block_name;
				continue;
			}
			if ( ! isset( $plugins[ $slug ] ) ) {
				$data            = $by_dir[ $slug ]['data'];
				$plugins[ $slug ] = array(
					'slug'       => $slug,
					'name'       => (string) $data['Name'],
					'version'    => (string) $data['Version'],
					'minVersion' => (string) $data['Version'],
					'reason'     => 'blocks',
					'blocks'     => array(),
				);
			}
			$plugins[ $slug ]['blocks'][] = $block_name;
		}

		// プラグインが宣言している依存（Requires Plugins）も、再帰的に足す
		$queue = array_keys( $plugins );
		while ( $queue ) {
			$slug = array_shift( $queue );
			if ( ! isset( $by_dir[ $slug ] ) ) {
				continue;
			}
			$requires = (string) ( $by_dir[ $slug ]['data']['RequiresPlugins'] ?? '' );
			foreach ( array_filter( array_map( 'trim', explode( ',', $requires ) ) ) as $dependency ) {
				if ( isset( $plugins[ $dependency ] ) || ! isset( $by_dir[ $dependency ] ) ) {
					continue;
				}
				$data                  = $by_dir[ $dependency ]['data'];
				$plugins[ $dependency ] = array(
					'slug'       => $dependency,
					'name'       => (string) $data['Name'],
					'version'    => (string) $data['Version'],
					'minVersion' => (string) $data['Version'],
					'reason'     => 'dependency',
					'blocks'     => array(),
				);
				$queue[] = $dependency;
			}
		}

		// カスタムフィールドの参照があれば、SCF／ACF を任意の依存として宣言する（紐づけの相手の定義を読むため）
		if ( $needs_fields ) {
			foreach ( array( 'secure-custom-fields', 'advanced-custom-fields', 'advanced-custom-fields-pro', 'smart-custom-fields' ) as $candidate ) {
				if ( isset( $by_dir[ $candidate ] ) && ! isset( $plugins[ $candidate ] ) ) {
					$data                  = $by_dir[ $candidate ]['data'];
					$plugins[ $candidate ] = array(
						'slug'       => $candidate,
						'name'       => (string) $data['Name'],
						'version'    => (string) $data['Version'],
						'minVersion' => '',
						'reason'     => 'custom-fields',
						'optional'   => true,
						'blocks'     => array(),
					);
					break;
				}
			}
		}

		foreach ( $plugins as &$plugin ) {
			sort( $plugin['blocks'] );
		}
		unset( $plugin );

		return array(
			'wordpress'     => array( 'minVersion' => self::MIN_WORDPRESS ),
			'plugins'       => array_values( $plugins ),
			'blocks'        => array_keys( $names ),
			'missingBlocks' => $missing, // この（書き出し側の）サイトで、提供元が分からない・登録されていないブロック
		);
	}
}
