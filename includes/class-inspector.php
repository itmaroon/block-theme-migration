<?php
/**
 * 点検（Inspection）: 書き出しの前に、テーマの状態を見える化する。
 *
 *  - パッケージの確認   テーマフォルダに、書き出される物が揃っているか
 *  - 棚卸し             テンプレート・パーツ・パターンの、使用・未使用・DB の上書き・参照の途切れ
 *  - サイト固有値のスキャン  内部リンク、メール、メディア、投稿データなどの検出（検出のみ。書き換えない）
 *  - ブロックの有効性の検査  ブラウザー内（assets/inspect.js）で行う
 *
 * 要件: docs/requirements-v0.2.md（M1）。規則データ: includes/link-rules.json
 *
 * @package block-theme-migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Itmar_BTM_Inspector {
	public const VIEW = 'inspect';

	/** WordPress の階層で自動適用されるテンプレートと、その派生（フォールバック先の判定に使う） */
	private const CORE_TYPES = array( 'post', 'page', 'attachment' );

	/** WordPress のコアの項目。フィールドの紐づけの対象にしない */
	public const CORE_FIELDS = array( 'title', 'date', 'content', 'excerpt', 'featured_media', 'link', 'author', 'slug', 'id', 'modified', 'categories', 'tags' );

	/** @var array<string, array>|null */
	private static $rules = null;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/* ------------------------------------------------------------------ */
	/* 規則                                                                */
	/* ------------------------------------------------------------------ */

	public static function rules(): array {
		if ( null === self::$rules ) {
			$file        = dirname( __DIR__ ) . '/includes/link-rules.json';
			$data        = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP_Filesystem.FileSystemReadsNotAllowed -- Reads a bundled data file.
			$data        = is_array( $data ) ? $data : array();
			$data        = array_filter( $data, static fn( $key ): bool => 0 !== strpos( (string) $key, '_' ), ARRAY_FILTER_USE_KEY );
			/**
			 * ブロック規則（ブロック名 => 規則の配列）。ブロックを提供するプラグインが追加できる。
			 */
			self::$rules = (array) apply_filters( 'itmar_btm_block_rules', $data );
		}
		return self::$rules;
	}

	/* ------------------------------------------------------------------ */
	/* 項目の収集                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * テンプレート、パーツ、テーマのパターン、同期パターンを集める。
	 *
	 * @return array<int, array<string, mixed>>
	 */
	/**
	 * テンプレート・パーツの、保存されたままの本文。
	 * get_block_template() の content は、WordPress がブロックを解析し直して出力し直すので、空のオブジェクト {} が [] になる。
	 * データベースのものは post_content、ファイルのものはファイルを、そのまま読む。
	 */
	public static function raw_content( WP_Block_Template $template, string $folder ): string {
		if ( ! empty( $template->wp_id ) ) {
			$post = get_post( (int) $template->wp_id );
			if ( $post && '' !== (string) $post->post_content ) {
				return (string) $post->post_content;
			}
		}
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $dir ) {
			$file = $dir . '/' . $folder . '/' . $template->slug . '.html';
			if ( is_readable( $file ) ) {
				return (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP_Filesystem.FileSystemReadsNotAllowed -- Reads a theme file.
			}
		}
		return (string) $template->content;
	}

	public static function collect_items(): array {
		$items = array();

		foreach ( get_block_templates( array(), 'wp_template' ) as $template ) {
			$items[] = array(
				'kind'         => 'template',
				'slug'         => $template->slug,
				'title'        => $template->title ?: $template->slug,
				'source'       => $template->source,
				'hasThemeFile' => (bool) $template->has_theme_file,
				'dbOverride'   => 'custom' === $template->source,
				'isCustom'     => (bool) $template->is_custom,
				'postTypes'    => $template->post_types ?: array(),
				'content'      => self::raw_content( $template, 'templates' ),
			);
		}

		foreach ( get_block_templates( array(), 'wp_template_part' ) as $part ) {
			$items[] = array(
				'kind'         => 'part',
				'slug'         => $part->slug,
				'title'        => $part->title ?: $part->slug,
				'area'         => $part->area ?: 'uncategorized',
				'source'       => $part->source,
				'hasThemeFile' => (bool) $part->has_theme_file,
				'dbOverride'   => 'custom' === $part->source,
				'content'      => self::raw_content( $part, 'parts' ),
			);
		}

		$prefix = get_stylesheet() . '/';
		foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			$name = (string) ( $pattern['name'] ?? '' );
			if ( 0 !== strpos( $name, $prefix ) ) {
				continue;
			}
			$items[] = array(
				'kind'       => 'pattern',
				'slug'       => $name,
				'title'      => (string) ( $pattern['title'] ?? $name ),
				'inserter'   => ! isset( $pattern['inserter'] ) || (bool) $pattern['inserter'],
				'categories' => (array) ( $pattern['categories'] ?? array() ),
				'content'    => (string) ( $pattern['content'] ?? '' ),
			);
		}

		$posts = get_posts(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( $posts as $post ) {
			$items[] = array(
				'kind'    => 'synced',
				'slug'    => $post->post_name ?: (string) $post->ID,
				'id'      => (int) $post->ID,
				'title'   => $post->post_title ?: sprintf( 'Pattern %d', $post->ID ),
				'content' => (string) $post->post_content,
			);
		}

		return $items;
	}

	/** 項目の識別子（"kind:slug"） */
	private static function item_key( array $item ): string {
		return $item['kind'] . ':' . $item['slug'];
	}

	/* ------------------------------------------------------------------ */
	/* パッケージの確認                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * 書き出しの土台になるテーマフォルダに、何が揃っているか。
	 * WordPress 標準のエクスポートは、テーマフォルダ全体をコピーし、templates/・parts/・theme.json だけを置き換える。
	 */
	public static function package_check(): array {
		$dir    = wp_normalize_path( get_stylesheet_directory() );
		$checks = array();
		$add    = static function ( string $id, string $status, string $label, string $detail = '' ) use ( &$checks ): void {
			$checks[] = compact( 'id', 'status', 'label', 'detail' );
		};

		// style.css
		$style = $dir . '/style.css';
		if ( is_readable( $style ) ) {
			$headers = get_file_data( $style, array( 'name' => 'Theme Name', 'version' => 'Version', 'requires' => 'Requires at least' ) );
			$add( 'style', ! empty( $headers['name'] ) ? 'ok' : 'warn', 'style.css', sprintf( 'Theme Name: %s / Version: %s / Requires at least: %s', $headers['name'] ?: '-', $headers['version'] ?: '-', $headers['requires'] ?: '-' ) );
		} else {
			$add( 'style', 'error', 'style.css', __( 'Not found. A theme ZIP needs style.css.', 'block-theme-migration' ) );
		}

		// theme.json
		$tj = $dir . '/theme.json';
		if ( is_readable( $tj ) ) {
			$data = json_decode( (string) file_get_contents( $tj ), true ); // phpcs:ignore WordPress.WP_Filesystem.FileSystemReadsNotAllowed
			if ( is_array( $data ) ) {
				$version = (int) ( $data['version'] ?? 0 );
				$add( 'theme-json', $version >= 3 ? 'ok' : 'warn', 'theme.json', sprintf( 'version %d%s', $version, $version >= 3 ? '' : ' — ' . __( 'The destination needs WordPress 6.6 or later only for version 3.', 'block-theme-migration' ) ) );
			} else {
				$add( 'theme-json', 'error', 'theme.json', __( 'It is not valid JSON.', 'block-theme-migration' ) );
			}
		} else {
			$add( 'theme-json', 'warn', 'theme.json', __( 'Not found in the theme folder. The export builds one from the site settings.', 'block-theme-migration' ) );
		}

		// screenshot
		$shot = glob( $dir . '/screenshot.{png,jpg,jpeg,gif,webp}', GLOB_BRACE ) ?: array();
		$add( 'screenshot', $shot ? 'ok' : 'warn', 'screenshot', $shot ? basename( $shot[0] ) : __( 'Not found. The theme will have no thumbnail.', 'block-theme-migration' ) );

		// styles/ (style variations)
		$styles = glob( $dir . '/styles/*.json' ) ?: array();
		$add( 'styles', 'info', 'styles/', sprintf( /* translators: %d: number of style variations. */ _n( '%d style variation', '%d style variations', count( $styles ), 'block-theme-migration' ), count( $styles ) ) . ( $styles ? ': ' . implode( ', ', array_map( 'basename', $styles ) ) : '' ) );

		// patterns/
		$patterns = glob( $dir . '/patterns/*.php' ) ?: array();
		$add( 'patterns', 'info', 'patterns/', sprintf( /* translators: %d: number of pattern files. */ _n( '%d pattern file', '%d pattern files', count( $patterns ), 'block-theme-migration' ), count( $patterns ) ) );

		// templates/ parts/ (the export replaces them with the database version)
		$tpl   = glob( $dir . '/templates/*.html' ) ?: array();
		$parts = glob( $dir . '/parts/*.html' ) ?: array();
		$add( 'files', 'info', 'templates/ parts/', sprintf( /* translators: 1: number of template files, 2: number of part files. */ __( '%1$d template files, %2$d part files (the export replaces them with the version on this site)', 'block-theme-migration' ), count( $tpl ), count( $parts ) ) );

		// 大きなファイル（ZIP が重くなる）
		$large    = array();
		$total    = 0;
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$relative = ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( $dir ) ), '/' );
			if ( wp_is_theme_directory_ignored( $relative ) ) {
				continue;
			}
			$total += $file->getSize();
			if ( $file->getSize() > 1024 * 1024 ) {
				$large[] = $relative . ' (' . size_format( $file->getSize() ) . ')';
			}
		}
		$add( 'size', $large ? 'warn' : 'ok', __( 'Files copied to the ZIP', 'block-theme-migration' ), size_format( $total ) . ( $large ? ' — ' . __( 'large files:', 'block-theme-migration' ) . ' ' . implode( ', ', $large ) : '' ) );

		return $checks;
	}

	/* ------------------------------------------------------------------ */
	/* 棚卸し                                                               */
	/* ------------------------------------------------------------------ */

	/** ブロックの木から、パーツ・パターン・同期パターン・ナビゲーションへの参照を集める */
	private static function collect_refs( array $blocks, array &$refs ): void {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			$attr = $block['attrs'] ?? array();
			if ( 'core/template-part' === $name && ! empty( $attr['slug'] ) ) {
				$refs['part'][] = (string) $attr['slug'];
			} elseif ( 'core/pattern' === $name && ! empty( $attr['slug'] ) ) {
				$refs['pattern'][] = (string) $attr['slug'];
			} elseif ( 'core/block' === $name && ! empty( $attr['ref'] ) ) {
				$refs['synced'][] = (int) $attr['ref'];
			} elseif ( 'core/navigation' === $name && ! empty( $attr['ref'] ) ) {
				$refs['navigation'][] = (int) $attr['ref'];
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::collect_refs( $block['innerBlocks'], $refs );
			}
		}
	}

	/**
	 * テンプレートが、どういう経路で表示されるか。
	 *
	 * @return array{0:string,1:string} [種類, 説明]
	 */
	private static function template_reach( string $slug, array $custom_templates ): array {
		$automatic = array( 'index', 'home', 'front-page', 'singular', 'single', 'page', 'archive', 'author', 'category', 'taxonomy', 'date', 'tag', 'search', '404', 'attachment', 'embed', 'privacy-policy' );
		if ( in_array( $slug, $automatic, true ) ) {
			return array( 'automatic', __( 'Applied automatically by the template hierarchy', 'block-theme-migration' ) );
		}
		if ( in_array( $slug, $custom_templates, true ) ) {
			return array( 'custom', __( 'A custom template that editors choose on a page (theme.json customTemplates)', 'block-theme-migration' ) );
		}
		if ( preg_match( '/^single-(.+)$/', $slug, $m ) ) {
			return post_type_exists( $m[1] )
				? array( 'automatic', sprintf( /* translators: %s: post type. */ __( 'Single view of the post type "%s"', 'block-theme-migration' ), $m[1] ) )
				: array( 'unreachable', sprintf( /* translators: %s: post type. */ __( 'The post type "%s" is not registered on this site', 'block-theme-migration' ), $m[1] ) );
		}
		if ( preg_match( '/^archive-(.+)$/', $slug, $m ) ) {
			return post_type_exists( $m[1] )
				? array( 'automatic', sprintf( /* translators: %s: post type. */ __( 'Archive of the post type "%s"', 'block-theme-migration' ), $m[1] ) )
				: array( 'unreachable', sprintf( /* translators: %s: post type. */ __( 'The post type "%s" is not registered on this site', 'block-theme-migration' ), $m[1] ) );
		}
		if ( preg_match( '/^taxonomy-(.+)$/', $slug, $m ) ) {
			return taxonomy_exists( $m[1] )
				? array( 'automatic', sprintf( /* translators: %s: taxonomy. */ __( 'Term archives of the taxonomy "%s"', 'block-theme-migration' ), $m[1] ) )
				: array( 'unreachable', sprintf( /* translators: %s: taxonomy. */ __( 'The taxonomy "%s" is not registered on this site', 'block-theme-migration' ), $m[1] ) );
		}
		if ( preg_match( '/^page-(.+)$/', $slug, $m ) ) {
			return get_page_by_path( $m[1] )
				? array( 'automatic', sprintf( /* translators: %s: page slug. */ __( 'Applied to the page "%s"', 'block-theme-migration' ), $m[1] ) )
				: array( 'unreachable', sprintf( /* translators: %s: page slug. */ __( 'No page with the slug "%s" exists (it can still be assigned manually)', 'block-theme-migration' ), $m[1] ) );
		}
		if ( preg_match( '/^(category|tag)-(.+)$/', $slug ) ) {
			return array( 'automatic', __( 'Applied to a specific category or tag', 'block-theme-migration' ) );
		}
		return array( 'unknown', __( 'Not reachable from the template hierarchy or customTemplates', 'block-theme-migration' ) );
	}

	/**
	 * 棚卸し。
	 *
	 * @param array<int, array<string, mixed>> $items collect_items() の結果
	 */
	public static function inventory( array $items ): array {
		$theme_json       = self::read_theme_json();
		$custom_templates = array();
		foreach ( (array) ( $theme_json['customTemplates'] ?? array() ) as $custom ) {
			if ( ! empty( $custom['name'] ) ) {
				$custom_templates[] = (string) $custom['name'];
			}
		}
		$registered_parts = array();
		foreach ( (array) ( $theme_json['templateParts'] ?? array() ) as $registered ) {
			if ( ! empty( $registered['name'] ) ) {
				$registered_parts[] = (string) $registered['name'];
			}
		}

		$parts_by_slug = array();
		$pats_by_slug  = array();
		$synced_by_id  = array();
		foreach ( $items as $item ) {
			if ( 'part' === $item['kind'] ) {
				$parts_by_slug[ $item['slug'] ] = $item;
			} elseif ( 'pattern' === $item['kind'] ) {
				$pats_by_slug[ $item['slug'] ] = $item;
			} elseif ( 'synced' === $item['kind'] ) {
				$synced_by_id[ $item['id'] ] = $item;
			}
		}

		// 参照の関係
		$used_by    = array( 'part' => array(), 'pattern' => array(), 'synced' => array() );
		$unresolved = array();
		$navigation = array();
		foreach ( $items as $item ) {
			$refs = array( 'part' => array(), 'pattern' => array(), 'synced' => array(), 'navigation' => array() );
			self::collect_refs( parse_blocks( $item['content'] ), $refs );
			$from = self::item_key( $item );
			foreach ( array_unique( $refs['part'] ) as $slug ) {
				$used_by['part'][ $slug ][] = $from;
				if ( ! isset( $parts_by_slug[ $slug ] ) ) {
					$unresolved[] = array( 'from' => $from, 'type' => 'part', 'ref' => $slug );
				}
			}
			foreach ( array_unique( $refs['pattern'] ) as $slug ) {
				$used_by['pattern'][ $slug ][] = $from;
				if ( ! isset( $pats_by_slug[ $slug ] ) && ! WP_Block_Patterns_Registry::get_instance()->is_registered( $slug ) ) {
					$unresolved[] = array( 'from' => $from, 'type' => 'pattern', 'ref' => $slug );
				}
			}
			foreach ( array_unique( $refs['synced'] ) as $id ) {
				$used_by['synced'][ $id ][] = $from;
				if ( ! isset( $synced_by_id[ $id ] ) ) {
					$unresolved[] = array( 'from' => $from, 'type' => 'synced', 'ref' => (string) $id );
				}
			}
			foreach ( array_unique( $refs['navigation'] ) as $id ) {
				$navigation[] = array( 'from' => $from, 'ref' => $id );
			}
		}

		$templates = array();
		$parts     = array();
		$patterns  = array();
		$synced    = array();
		$template_slugs = array();
		foreach ( $items as $item ) {
			if ( 'template' === $item['kind'] ) {
				$template_slugs[] = $item['slug'];
				list( $reach, $reach_note ) = self::template_reach( $item['slug'], $custom_templates );
				$templates[] = array(
					'slug'       => $item['slug'],
					'title'      => $item['title'],
					'source'     => $item['source'],
					'dbOverride' => $item['dbOverride'],
					'hasFile'    => $item['hasThemeFile'],
					'reach'      => $reach,
					'reachNote'  => $reach_note,
					'size'       => strlen( $item['content'] ),
				);
			} elseif ( 'part' === $item['kind'] ) {
				$users   = $used_by['part'][ $item['slug'] ] ?? array();
				$parts[] = array(
					'slug'       => $item['slug'],
					'title'      => $item['title'],
					'area'       => $item['area'],
					'dbOverride' => $item['dbOverride'],
					'hasFile'    => $item['hasThemeFile'],
					'registered' => in_array( $item['slug'], $registered_parts, true ),
					'usedBy'     => $users,
					'unused'     => empty( $users ),
					'size'       => strlen( $item['content'] ),
				);
			} elseif ( 'pattern' === $item['kind'] ) {
				$users      = $used_by['pattern'][ $item['slug'] ] ?? array();
				$patterns[] = array(
					'slug'     => $item['slug'],
					'title'    => $item['title'],
					'inserter' => $item['inserter'],
					'usedBy'   => $users,
					// 挿入できないパターンで、どこからも呼ばれないものは不要。挿入できるものは、サンプルとして意図されうる
					'unused'   => empty( $users ) && ! $item['inserter'],
					'sample'   => empty( $users ) && $item['inserter'],
					'size'     => strlen( $item['content'] ),
				);
			} elseif ( 'synced' === $item['kind'] ) {
				$users    = $used_by['synced'][ $item['id'] ] ?? array();
				$synced[] = array( 'id' => $item['id'], 'slug' => $item['slug'], 'title' => $item['title'], 'usedBy' => $users, 'unused' => empty( $users ) );
			}
		}

		return array(
			'templates'  => $templates,
			'parts'      => $parts,
			'patterns'   => $patterns,
			'synced'     => $synced,
			'unresolved' => $unresolved,
			'navigation' => $navigation,
			'missing'    => self::missing_templates( $template_slugs ),
		);
	}

	/**
	 * この サイトの投稿タイプ・タクソノミーに対して、専用のテンプレートが無いもの。
	 * 汎用のテンプレート（archive、single、index）が使われるので、エラーではなく、情報として出す。
	 */
	private static function missing_templates( array $template_slugs ): array {
		$missing = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( in_array( $pt->name, array( 'attachment', 'page' ), true ) ) {
				continue;
			}
			if ( 'post' !== $pt->name && $pt->has_archive && ! in_array( 'archive-' . $pt->name, $template_slugs, true ) ) {
				$missing[] = array( 'template' => 'archive-' . $pt->name, 'for' => $pt->label . ' (' . $pt->name . ')', 'fallback' => 'archive' );
			}
			if ( 'post' !== $pt->name && ! in_array( 'single-' . $pt->name, $template_slugs, true ) ) {
				$missing[] = array( 'template' => 'single-' . $pt->name, 'for' => $pt->label . ' (' . $pt->name . ')', 'fallback' => 'single' );
			}
		}
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			if ( in_array( $tax->name, array( 'category', 'post_tag', 'post_format' ), true ) ) {
				continue;
			}
			if ( ! in_array( 'taxonomy-' . $tax->name, $template_slugs, true ) && ! in_array( 'taxonomy', $template_slugs, true ) ) {
				$missing[] = array( 'template' => 'taxonomy-' . $tax->name, 'for' => $tax->label . ' (' . $tax->name . ')', 'fallback' => 'archive' );
			}
		}
		return $missing;
	}

	private static function read_theme_json(): array {
		$file = get_stylesheet_directory() . '/theme.json';
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP_Filesystem.FileSystemReadsNotAllowed
		return is_array( $data ) ? $data : array();
	}

	/**
	 * 指定したパーツを書き出しから外したとき、外さない項目から参照されて困るもの。
	 *
	 * @param string[] $excluded_parts 外すパーツの slug
	 * @param string[] $excluded_templates 外すテンプレートの slug
	 * @return array<string, string[]> パーツ slug => それを参照する（残る）項目
	 */
	public static function excluded_part_conflicts( array $excluded_parts, array $excluded_templates ): array {
		if ( empty( $excluded_parts ) ) {
			return array();
		}
		$items     = self::collect_items();
		$conflicts = array();
		foreach ( $items as $item ) {
			if ( 'synced' === $item['kind'] ) {
				continue; // 同期パターンは、参照されたものがテーマのパターンに変換される
			}
			if ( 'template' === $item['kind'] && in_array( $item['slug'], $excluded_templates, true ) ) {
				continue;
			}
			if ( 'part' === $item['kind'] && in_array( $item['slug'], $excluded_parts, true ) ) {
				continue;
			}
			$refs = array( 'part' => array(), 'pattern' => array(), 'synced' => array(), 'navigation' => array() );
			self::collect_refs( parse_blocks( $item['content'] ), $refs );
			foreach ( array_unique( $refs['part'] ) as $slug ) {
				if ( in_array( $slug, $excluded_parts, true ) ) {
					$conflicts[ $slug ][] = self::item_key( $item );
				}
			}
		}
		return $conflicts;
	}

	/* ------------------------------------------------------------------ */
	/* サイト固有値のスキャン（検出のみ）                                      */
	/* ------------------------------------------------------------------ */

	/** 内部リンクか。ホーム URL、[home_url]、相対パス、.local のホスト（過去の開発サイトを含む） */
	public static function is_internal_url( string $value ): bool {
		$v = trim( $value );
		if ( '' === $v ) {
			return false;
		}
		if ( 0 === strpos( $v, '[home_url]' ) ) {
			return true;
		}
		if ( '/' === $v[0] && ( ! isset( $v[1] ) || '/' !== $v[1] ) ) {
			return true;
		}
		$host = wp_parse_url( $v, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		return ( is_string( $home ) && 0 === strcasecmp( $host, $home ) ) || preg_match( '/\.local$/i', $host );
	}

	/** 値が空か（0、''、[]、{}、null、false） */
	private static function is_empty_value( $value ): bool {
		return null === $value || false === $value || '' === $value || 0 === $value || '0' === $value || array() === $value;
	}

	/** パスで値を取り出す。最後が * なら、子の全部 */
	private static function get_path( array $attrs, string $path ): array {
		$parts = explode( '.', $path );
		$nodes = array( $attrs );
		foreach ( $parts as $index => $part ) {
			$next = array();
			foreach ( $nodes as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				if ( '*' === $part ) {
					foreach ( $node as $child ) {
						$next[] = $child;
					}
				} elseif ( array_key_exists( $part, $node ) ) {
					$next[] = $node[ $part ];
				}
			}
			$nodes = $next;
		}
		return $nodes;
	}

	private static function rule_applies( array $rule, array $attrs ): bool {
		if ( empty( $rule['when'] ) ) {
			return true;
		}
		foreach ( $rule['when'] as $key => $expected ) {
			$actual = $attrs[ $key ] ?? null;
			if ( is_array( $expected ) ? ! in_array( $actual, $expected, true ) : $actual !== $expected ) {
				return false;
			}
		}
		return true;
	}

	/** ブロックの保存 HTML にある data-attributes を、属性（既定値を含む完全な形）として取り出す */
	private static function data_attributes( string $inner_html ): array {
		if ( preg_match( '/\sdata-attributes="([^"]*)"/', $inner_html, $m ) ) {
			$decoded = json_decode( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 ), true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return array();
	}

	private static function record( array &$findings, string $kind, string $block, string $path, string $action, $value, string $item_key ): void {
		$key = $kind . '|' . $block . '|' . $path;
		if ( ! isset( $findings[ $key ] ) ) {
			$findings[ $key ] = array( 'kind' => $kind, 'block' => $block, 'path' => $path, 'action' => $action, 'count' => 0, 'examples' => array(), 'items' => array() );
		}
		++$findings[ $key ]['count'];
		$example = is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$example = mb_strimwidth( (string) $example, 0, 70, '…' );
		if ( count( $findings[ $key ]['examples'] ) < 3 && ! in_array( $example, $findings[ $key ]['examples'], true ) ) {
			$findings[ $key ]['examples'][] = $example;
		}
		if ( count( $findings[ $key ]['items'] ) < 8 && ! in_array( $item_key, $findings[ $key ]['items'], true ) ) {
			$findings[ $key ]['items'][] = $item_key;
		}
		// 紐づけの対象は、使用箇所（項目 × 値）をすべて残す（データモデルの宣言の索引になる）
		if ( 0 === strpos( $kind, 'binding-' ) ) {
			$text = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$findings[ $key ]['occurrences'][ $item_key . '|' . $text ] = array( 'item' => $item_key, 'value' => $text );
		}
	}

	/** 1つのブロック（またはブロック相当の属性の組）に、規則を当てる */
	private static function apply_rules( string $block_name, array $attrs, string $path_prefix, string $item_key, array &$findings, int $depth = 0 ): void {
		$rules = self::rules();
		if ( empty( $rules[ $block_name ] ) || $depth > 40 ) {
			return;
		}
		foreach ( $rules[ $block_name ] as $rule ) {
			if ( ! self::rule_applies( $rule, $attrs ) ) {
				continue;
			}
			$kind   = (string) ( $rule['kind'] ?? '' );
			$path   = (string) ( $rule['path'] ?? '' );
			$label  = $path_prefix . $path;
			$values = self::get_path( $attrs, $path );
			foreach ( $values as $value ) {
				switch ( $kind ) {
					case 'page-url':
						if ( is_string( $value ) && '' !== $value ) {
							if ( self::is_internal_url( $value ) ) {
								self::record( $findings, 'internal-link', $block_name, $label, 'strip', $value, $item_key );
							} else {
								self::record( $findings, 'external-link', $block_name, $label, 'keep', $value, $item_key );
							}
						}
						break;
					case 'page':
						if ( is_string( $value ) && '' !== $value ) {
							self::record( $findings, 'internal-link', $block_name, $label, 'strip', $value, $item_key );
						}
						break;
					case 'navigation':
						if ( ! self::is_empty_value( $value ) ) {
							self::record( $findings, 'navigation-ref', $block_name, $label, 'strip', $value, $item_key );
						}
						break;
					case 'media':
						// 移植元のアップロードだけが対象。プラグイン・テーマ同梱や外部の画像は、そのまま運ぶ
						if ( ! self::is_empty_value( $value ) && ( is_numeric( $value ) || false !== strpos( (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES ), 'wp-content/uploads' ) ) ) {
							self::record( $findings, 'media', $block_name, $label, 'remove', is_array( $value ) ? ( $value['url'] ?? $value[0]['url'] ?? $value ) : $value, $item_key );
						}
						break;
					case 'content-data':
					case 'content-id':
					case 'content-ref':
						if ( ! self::is_empty_value( $value ) ) {
							self::record( $findings, 'content', $block_name, $label, 'reset', $value, $item_key );
						}
						break;
					case 'service-setting':
						if ( ! self::is_empty_value( $value ) ) {
							self::record( $findings, 'service-setting', $block_name, $label, 'reset', $value, $item_key );
						}
						break;
					case 'post-type':
					case 'post-type-rest':
					case 'post-type-label':
					case 'taxonomy':
					case 'term':
						if ( ! self::is_empty_value( $value ) ) {
							// dispTaxonomies のように配列で持つ値は、要素ごとに記録する
							foreach ( is_array( $value ) ? $value : array( $value ) as $single ) {
								if ( is_scalar( $single ) && '' !== (string) $single ) {
									self::record( $findings, 'binding-' . $kind, $block_name, $label, 'bind', $single, $item_key );
								}
							}
						}
						break;
					case 'field':
						$skip = (array) ( $rule['skip'] ?? self::CORE_FIELDS );
						foreach ( (array) $value as $field ) {
							if ( is_string( $field ) && '' !== $field && ! in_array( $field, $skip, true ) ) {
								self::record( $findings, 'binding-field', $block_name, $label, 'bind', $field, $item_key );
							}
						}
						break;
					case 'field-keys':
						if ( is_array( $value ) ) {
							$skip = (array) ( $rule['skip'] ?? self::CORE_FIELDS );
							foreach ( array_keys( $value ) as $field ) {
								if ( ! in_array( (string) $field, $skip, true ) ) {
									self::record( $findings, 'binding-field', $block_name, $label, 'bind', (string) $field, $item_key );
								}
							}
						}
						break;
					case 'embedded-attributes':
						if ( is_array( $value ) && ! empty( $rule['block'] ) ) {
							self::apply_rules( (string) $rule['block'], $value, $label . '→', $item_key, $findings, $depth + 1 );
						}
						break;
					case 'embedded-blocks':
						if ( is_array( $value ) ) {
							self::apply_embedded( $value, $label . '→', $item_key, $findings, $depth + 1 );
						}
						break;
				}
			}
		}
	}

	/** 属性の中に埋め込まれたブロックの木（name・attributes・innerBlocks）を走査する */
	private static function apply_embedded( array $node, string $path_prefix, string $item_key, array &$findings, int $depth ): void {
		if ( $depth > 60 ) {
			return;
		}
		// 埋め込まれたブロックは、name か blockName（parse_blocks と同じ形）で名前を持つ
		$embedded_name = isset( $node['name'] ) && is_string( $node['name'] ) ? $node['name'] : ( isset( $node['blockName'] ) && is_string( $node['blockName'] ) ? $node['blockName'] : null );
		if ( null !== $embedded_name ) {
			$attrs = isset( $node['attributes'] ) && is_array( $node['attributes'] ) ? $node['attributes'] : array();
			self::apply_rules( $embedded_name, $attrs, $path_prefix, $item_key, $findings, $depth );
			if ( ! empty( $node['innerBlocks'] ) && is_array( $node['innerBlocks'] ) ) {
				self::apply_embedded( $node['innerBlocks'], $path_prefix, $item_key, $findings, $depth + 1 );
			}
			return;
		}
		foreach ( $node as $child ) {
			if ( is_array( $child ) ) {
				self::apply_embedded( $child, $path_prefix, $item_key, $findings, $depth + 1 );
			}
		}
	}

	private static function scan_blocks( array $blocks, string $item_key, array &$findings ): void {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			if ( $name ) {
				$comment = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				$data    = self::data_attributes( (string) ( $block['innerHTML'] ?? '' ) );
				// data-attributes は、既定値を含む完全な属性。ブロックコメントの値で上書きして、実効の属性とする
				$effective = array_merge( $data, $comment );
				self::apply_rules( $name, $effective, '', $item_key, $findings );

				// コアの画像のメディア（アップロード）
				if ( 'core/image' === $name && ! empty( $comment['id'] ) ) {
					self::record( $findings, 'media', 'core/image', 'id', 'remove', $comment['id'], $item_key );
				}
				// コアの本文のリンク
				if ( 0 === strpos( $name, 'core/' ) && ! empty( $block['innerHTML'] ) && preg_match_all( '/<a\s[^>]*href=(?:"([^"]*)"|\'([^\']*)\')/i', $block['innerHTML'], $m, PREG_SET_ORDER ) ) {
					foreach ( $m as $match ) {
						$href = '' !== $match[1] ? $match[1] : ( $match[2] ?? '' );
						if ( '' === $href || '#' === $href[0] ) {
							continue;
						}
						if ( self::is_internal_url( $href ) ) {
							self::record( $findings, 'internal-link', $name . ' (text)', 'a[href]', 'strip', $href, $item_key );
						} else {
							self::record( $findings, 'external-link', $name . ' (text)', 'a[href]', 'keep', $href, $item_key );
						}
					}
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::scan_blocks( $block['innerBlocks'], $item_key, $findings );
			}
		}
	}

	private static function scan_text( string $content, string $item_key, array &$findings ): void {
		if ( preg_match_all( '/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/', $content, $m ) ) {
			foreach ( $m[0] as $email ) {
				if ( preg_match( '/\.(png|jpg|jpeg|gif|webp|svg)$/i', $email ) ) {
					continue;
				}
				self::record( $findings, 'email', '(text)', 'email', 'remove', $email, $item_key );
			}
		}
		if ( preg_match_all( '#https?://[^\s"\'<>\\\\]*wp-content/uploads/[^\s"\'<>\\\\]*#', $content, $m ) ) {
			foreach ( $m[0] as $url ) {
				self::record( $findings, 'uploads-url', '(text)', 'wp-content/uploads', 'remove', $url, $item_key );
			}
		}
		if ( preg_match_all( '#https?://[^/\s"\'<>\\\\]+\.local(?::\d+)?#i', $content, $m ) ) {
			foreach ( $m[0] as $url ) {
				self::record( $findings, 'local-url', '(text)', '*.local', 'strip', $url, $item_key );
			}
		}
	}

	/**
	 * サイト固有値のスキャン。書き換えない。
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return array{findings: array<int, array>, byKind: array<string,int>}
	 */
	public static function scan( array $items ): array {
		$findings = array();
		foreach ( $items as $item ) {
			$key = self::item_key( $item );
			self::scan_blocks( parse_blocks( $item['content'] ), $key, $findings );
			self::scan_text( $item['content'], $key, $findings );
		}
		// 使用箇所の索引（データモデルの宣言用）。findings 本体には載せない
		$usage = array();
		foreach ( $findings as &$finding ) {
			if ( isset( $finding['occurrences'] ) ) {
				foreach ( $finding['occurrences'] as $occurrence ) {
					$usage[] = array(
						'item'  => $occurrence['item'],
						'block' => $finding['block'],
						'path'  => $finding['path'],
						'kind'  => substr( $finding['kind'], strlen( 'binding-' ) ),
						'value' => $occurrence['value'],
					);
				}
				unset( $finding['occurrences'] );
			}
		}
		unset( $finding );
		$list = array_values( $findings );
		usort(
			$list,
			static function ( array $a, array $b ): int {
				return array( $a['kind'], -$a['count'] ) <=> array( $b['kind'], -$b['count'] );
			}
		);
		$by_kind = array();
		foreach ( $list as $row ) {
			$by_kind[ $row['kind'] ] = ( $by_kind[ $row['kind'] ] ?? 0 ) + $row['count'];
		}
		return array( 'findings' => $list, 'byKind' => $by_kind, 'usage' => $usage );
	}

	/* ------------------------------------------------------------------ */
	/* レポート                                                             */
	/* ------------------------------------------------------------------ */

	/** 書き出しから外す項目を除いた、項目の一覧 */
	public static function without_excluded( array $items, array $excluded ): array {
		$templates = (array) ( $excluded['templates'] ?? array() );
		$parts     = (array) ( $excluded['parts'] ?? array() );
		return array_values(
			array_filter(
				$items,
				static function ( array $item ) use ( $templates, $parts ): bool {
					if ( 'template' === $item['kind'] && in_array( $item['slug'], $templates, true ) ) {
						return false;
					}
					return ! ( 'part' === $item['kind'] && in_array( $item['slug'], $parts, true ) );
				}
			)
		);
	}

	/**
	 * データモデルの宣言と、依存の宣言（マニフェスト v5 の dataModel・requires）。
	 *
	 * @param array<int, array<string, mixed>> $items 書き出しに入る項目
	 * @param array                            $scan  self::scan( $items ) の結果
	 */
	public static function declare_model( array $items, array $scan ): array {
		$template_slugs = array();
		foreach ( $items as $item ) {
			if ( 'template' === $item['kind'] ) {
				$template_slugs[] = $item['slug'];
			}
		}
		$model        = Itmar_BTM_DataModel::build( $scan['usage'] ?? array(), $template_slugs );
		$needs_fields = ! empty( $model['fieldGroups'] ) || ! empty( $model['unmatchedFields'] );
		return array(
			'requires'  => Itmar_BTM_DataModel::requires( $items, $needs_fields ),
			'dataModel' => $model,
		);
	}

	/** 書き出し用の宣言（外す項目を除く） */
	public static function declarations( array $excluded = array() ): array {
		$items = self::without_excluded( self::collect_items(), $excluded );
		return self::declare_model( $items, self::scan( $items ) );
	}

	public static function build_report(): array {
		$items = self::collect_items();
		$scan  = self::scan( $items );
		$inv   = self::inventory( $items );
		$decl  = self::declare_model( $items, $scan );
		return array(
			'schema'       => 1,
			'generatedAt'  => gmdate( 'c' ),
			'site'         => array(
				'homeUrl'   => home_url( '/' ),
				'theme'     => get_stylesheet(),
				'wordpress' => get_bloginfo( 'version' ),
				'blockTheme' => wp_is_block_theme(),
			),
			'package'      => self::package_check(),
			'inventory'    => $inv,
			'scan'         => $scan,
			'requires'     => $decl['requires'],
			'dataModel'    => $decl['dataModel'],
			'counts'       => array(
				'templates' => count( $inv['templates'] ),
				'parts'     => count( $inv['parts'] ),
				'patterns'  => count( $inv['patterns'] ),
				'synced'    => count( $inv['synced'] ),
			),
		);
	}

	/* ------------------------------------------------------------------ */
	/* REST（読み取り専用）                                                  */
	/* ------------------------------------------------------------------ */

	public static function register_rest(): void {
		register_rest_route(
			'itmar-btm/v1',
			'/items',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_items' ),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_theme_options' ),
			)
		);
		register_rest_route(
			'itmar-btm/v1',
			'/report',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static fn() => rest_ensure_response( self::build_report() ),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_theme_options' ),
			)
		);
	}

	public static function rest_items(): WP_REST_Response {
		$out = array();
		foreach ( self::collect_items() as $item ) {
			$out[] = array(
				'key'     => self::item_key( $item ),
				'kind'    => $item['kind'],
				'slug'    => $item['slug'],
				'title'   => $item['title'],
				'content' => $item['content'],
			);
		}
		return rest_ensure_response( $out );
	}

	/* ------------------------------------------------------------------ */
	/* 画面                                                                 */
	/* ------------------------------------------------------------------ */

	public static function is_inspect_screen( string $hook = '' ): bool {
		$screen_ok = '' === $hook || false !== strpos( $hook, 'itmar-block-theme-migration' );
		return $screen_ok && isset( $_GET['view'] ) && self::VIEW === sanitize_key( wp_unslash( $_GET['view'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen selection.
	}

	public static function enqueue( string $hook ): void {
		if ( ! self::is_inspect_screen( $hook ) || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		$base = plugin_dir_url( dirname( __DIR__ ) . '/block-theme-migration.php' );
		$path = dirname( __DIR__ );

		// ブロックの有効性の検査には、登録されたブロック（editor のスクリプト）が要る。
		// ブロックエディターは起動時に、サーバー側で登録されたブロックの定義（title、category、属性など）を渡す。
		// ブロックによっては、これが無いと registerBlockType が成立しないので、この画面でも同じ受け渡しをする。
		wp_enqueue_script( 'wp-block-library' );
		if ( function_exists( 'get_block_editor_server_block_settings' ) ) {
			wp_add_inline_script(
				'wp-blocks',
				sprintf(
					'wp.blocks.unstable__bootstrapServerSideBlockDefinitions( %s );',
					wp_json_encode( get_block_editor_server_block_settings(), JSON_HEX_TAG | JSON_HEX_AMP )
				),
				'after'
			);
		}
		foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $type ) {
			foreach ( (array) $type->editor_script_handles as $handle ) {
				wp_enqueue_script( $handle );
			}
		}
		wp_enqueue_style( 'wp-edit-blocks' );
		// エディターと同じ条件で検査するため、コアのブロックを拡張するスクリプト（保存内容に style を足すなど）も読み込む。
		// プラグインは、これを enqueue_block_editor_assets で読み込む
		do_action( 'enqueue_block_editor_assets' );

		wp_enqueue_script(
			'itmar-btm-inspect',
			$base . 'assets/inspect.js',
			array( 'wp-api-fetch', 'wp-blocks', 'wp-block-library', 'wp-data', 'wp-i18n' ),
			(string) filemtime( $path . '/assets/inspect.js' ),
			true
		);
		wp_localize_script(
			'itmar-btm-inspect',
			'itmarBtmInspect',
			array(
				'i18n' => array(
					'run'         => __( 'Check that all blocks are valid', 'block-theme-migration' ),
					'running'     => __( 'Checking…', 'block-theme-migration' ),
					'progress'    => __( 'Checked %1$d of %2$d: %3$s', 'block-theme-migration' ),
					'done'        => __( 'Checked %1$d items, %2$d blocks. Invalid blocks: %3$d. Unregistered blocks: %4$d.', 'block-theme-migration' ),
					'allValid'    => __( 'All blocks are valid.', 'block-theme-migration' ),
					'item'        => __( 'Item', 'block-theme-migration' ),
					'blocks'      => __( 'Blocks', 'block-theme-migration' ),
					'invalid'     => __( 'Invalid', 'block-theme-migration' ),
					'unregistered' => __( 'Unregistered', 'block-theme-migration' ),
					'details'     => __( 'Details', 'block-theme-migration' ),
					'download'    => __( 'Download the report (JSON)', 'block-theme-migration' ),
					'noEditor'    => __( 'The block editor scripts could not be loaded on this screen.', 'block-theme-migration' ),
				),
			)
		);
		wp_add_inline_style(
			'wp-edit-blocks',
			'.itmar-btm-status{display:inline-block;padding:0 .5em;border-radius:3px;font-size:12px;line-height:20px}.itmar-btm-status.ok{background:#d7f0dd}.itmar-btm-status.warn{background:#fcf0c8}.itmar-btm-status.error{background:#f6d4d4}.itmar-btm-status.info{background:#e5e5e5}.itmar-btm-spin{display:inline-block;width:14px;height:14px;border:2px solid #c3c4c7;border-top-color:#2271b1;border-radius:50%;animation:itmar-btm-spin .8s linear infinite;vertical-align:-2px;margin-right:6px}@keyframes itmar-btm-spin{to{transform:rotate(360deg)}}.itmar-btm-table td,.itmar-btm-table th{vertical-align:top}.itmar-btm-muted{color:#646970}'
		);
	}

	/** 点検の画面 */
	public static function render(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to inspect this theme.', 'block-theme-migration' ) );
		}
		$report = self::build_report();
		$status = static function ( string $status ): string {
			return '<span class="itmar-btm-status ' . esc_attr( $status ) . '">' . esc_html( strtoupper( $status ) ) . '</span>';
		};
		?>
		<div>
			<h2><?php echo esc_html__( 'Inspection', 'block-theme-migration' ); ?></h2>
			<p><?php echo esc_html__( 'Run this on the development site before exporting. It only reads; nothing is changed.', 'block-theme-migration' ); ?></p>
			<?php if ( ! $report['site']['blockTheme'] ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html__( 'The active theme is not a block theme.', 'block-theme-migration' ); ?></p></div>
			<?php endif; ?>

			<h3><?php echo esc_html__( '1. What the export will contain', 'block-theme-migration' ); ?></h3>
			<table class="widefat striped itmar-btm-table" style="max-width:1100px"><tbody>
				<?php foreach ( $report['package'] as $check ) : ?>
					<tr><td style="width:7em"><?php echo $status( $check['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts. ?></td><td style="width:14em"><strong><?php echo esc_html( $check['label'] ); ?></strong></td><td><?php echo esc_html( $check['detail'] ); ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>

			<h3><?php echo esc_html__( '2. Inventory', 'block-theme-migration' ); ?></h3>
			<p class="itmar-btm-muted"><?php echo esc_html( sprintf( /* translators: 1: templates, 2: parts, 3: patterns, 4: synced patterns. */ __( '%1$d templates, %2$d template parts, %3$d theme patterns, %4$d synced patterns', 'block-theme-migration' ), $report['counts']['templates'], $report['counts']['parts'], $report['counts']['patterns'], $report['counts']['synced'] ) ); ?></p>

			<?php if ( ! empty( $report['inventory']['unresolved'] ) ) : ?>
				<div class="notice notice-error inline"><p><strong><?php echo esc_html__( 'Broken references', 'block-theme-migration' ); ?></strong></p><ul style="list-style:disc;margin-left:2em">
					<?php foreach ( $report['inventory']['unresolved'] as $u ) : ?>
						<li><code><?php echo esc_html( $u['from'] ); ?></code> → <?php echo esc_html( $u['type'] . ': ' . $u['ref'] ); ?></li>
					<?php endforeach; ?>
				</ul></div>
			<?php endif; ?>

			<h4><?php echo esc_html__( 'Templates', 'block-theme-migration' ); ?></h4>
			<table class="widefat striped itmar-btm-table" style="max-width:1100px">
				<thead><tr><th><?php echo esc_html__( 'Template', 'block-theme-migration' ); ?></th><th><?php echo esc_html__( 'State', 'block-theme-migration' ); ?></th><th><?php echo esc_html__( 'Reached by', 'block-theme-migration' ); ?></th><th><?php echo esc_html__( 'Size', 'block-theme-migration' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $report['inventory']['templates'] as $t ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $t['title'] ); ?></strong><br><code><?php echo esc_html( $t['slug'] ); ?></code></td>
						<td>
							<?php if ( $t['dbOverride'] && $t['hasFile'] ) : ?><?php echo $status( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html__( 'database overrides the file', 'block-theme-migration' ); ?>
							<?php elseif ( $t['dbOverride'] ) : ?><?php echo $status( 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html__( 'database only (no theme file)', 'block-theme-migration' ); ?>
							<?php else : ?><span class="itmar-btm-muted"><?php echo esc_html__( 'theme file', 'block-theme-migration' ); ?></span><?php endif; ?>
						</td>
						<td><?php echo ( 'unreachable' === $t['reach'] || 'unknown' === $t['reach'] ) ? $status( 'warn' ) . ' ' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $t['reachNote'] ); ?></td>
						<td><?php echo esc_html( size_format( $t['size'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h4><?php echo esc_html__( 'Template parts', 'block-theme-migration' ); ?></h4>
			<table class="widefat striped itmar-btm-table" style="max-width:1100px">
				<thead><tr><th><?php echo esc_html__( 'Part', 'block-theme-migration' ); ?></th><th><?php echo esc_html__( 'State', 'block-theme-migration' ); ?></th><th><?php echo esc_html__( 'Used by', 'block-theme-migration' ); ?></th><th><?php echo esc_html__( 'Size', 'block-theme-migration' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $report['inventory']['parts'] as $p ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $p['title'] ); ?></strong><br><code><?php echo esc_html( $p['slug'] ); ?></code></td>
						<td>
							<?php echo $p['dbOverride'] ? $status( 'info' ) . ' ' . esc_html__( 'database overrides the file', 'block-theme-migration' ) : '<span class="itmar-btm-muted">' . esc_html__( 'theme file', 'block-theme-migration' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php if ( ! $p['registered'] ) : ?><br><?php echo $status( 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html__( 'not in theme.json templateParts', 'block-theme-migration' ); ?><?php endif; ?>
						</td>
						<td><?php echo $p['unused'] ? $status( 'warn' ) . ' ' . esc_html__( 'not used anywhere', 'block-theme-migration' ) : esc_html( implode( ', ', array_slice( $p['usedBy'], 0, 4 ) ) . ( count( $p['usedBy'] ) > 4 ? ' …' : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo esc_html( size_format( $p['size'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h4><?php echo esc_html__( 'Patterns', 'block-theme-migration' ); ?></h4>
			<table class="widefat striped itmar-btm-table" style="max-width:1100px">
				<thead><tr><th><?php echo esc_html__( 'Pattern', 'block-theme-migration' ); ?></th><th><?php echo esc_html__( 'Insertable', 'block-theme-migration' ); ?></th><th><?php echo esc_html__( 'Used by', 'block-theme-migration' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $report['inventory']['patterns'] as $p ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $p['title'] ); ?></strong><br><code><?php echo esc_html( $p['slug'] ); ?></code></td>
						<td><?php echo $p['inserter'] ? esc_html__( 'yes', 'block-theme-migration' ) : esc_html__( 'no (used only by other items)', 'block-theme-migration' ); ?></td>
						<td><?php
						if ( $p['unused'] ) {
							echo $status( 'warn' ) . ' ' . esc_html__( 'not insertable and not used anywhere', 'block-theme-migration' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} elseif ( $p['sample'] ) {
							echo '<span class="itmar-btm-muted">' . esc_html__( 'not referenced (a sample for editors)', 'block-theme-migration' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} else {
							echo esc_html( implode( ', ', array_slice( $p['usedBy'], 0, 4 ) ) . ( count( $p['usedBy'] ) > 4 ? ' …' : '' ) );
						}
						?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( ! empty( $report['inventory']['synced'] ) ) : ?>
				<h4><?php echo esc_html__( 'Synced patterns (database)', 'block-theme-migration' ); ?></h4>
				<ul style="list-style:disc;margin-left:2em">
					<?php foreach ( $report['inventory']['synced'] as $s ) : ?>
						<li><?php echo esc_html( $s['title'] ); ?> <?php echo $s['unused'] ? '<span class="itmar-btm-muted">(' . esc_html__( 'not used', 'block-theme-migration' ) . ')</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( ! empty( $report['inventory']['missing'] ) ) : ?>
				<h4><?php echo esc_html__( 'Templates that do not exist for this site’s post types and taxonomies', 'block-theme-migration' ); ?></h4>
				<p class="itmar-btm-muted"><?php echo esc_html__( 'A general template is used instead, so this is information, not an error.', 'block-theme-migration' ); ?></p>
				<table class="widefat striped itmar-btm-table" style="max-width:1100px"><tbody>
					<?php foreach ( $report['inventory']['missing'] as $m ) : ?>
						<tr><td><code><?php echo esc_html( $m['template'] ); ?></code></td><td><?php echo esc_html( $m['for'] ); ?></td><td class="itmar-btm-muted"><?php echo esc_html( sprintf( /* translators: %s: fallback template. */ __( 'falls back to “%s”', 'block-theme-migration' ), $m['fallback'] ) ); ?></td></tr>
					<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>

			<h3><?php echo esc_html__( '3. Site-specific values', 'block-theme-migration' ); ?></h3>
			<p class="itmar-btm-muted"><?php echo esc_html__( 'These are what a distribution package should not carry. This page only detects them; the export removes them (the summary follows the table).', 'block-theme-migration' ); ?></p>
			<?php if ( empty( $report['scan']['findings'] ) ) : ?>
				<p><?php echo esc_html__( 'Nothing detected.', 'block-theme-migration' ); ?></p>
			<?php else : ?>
				<table class="widefat striped itmar-btm-table" style="max-width:1100px">
					<thead><tr><th><?php echo esc_html__( 'Kind', 'block-theme-migration' ); ?></th><th><?php echo esc_html__( 'Block / attribute', 'block-theme-migration' ); ?></th><th><?php echo esc_html__( 'Action', 'block-theme-migration' ); ?></th><th><?php echo esc_html__( 'Count', 'block-theme-migration' ); ?></th><th><?php echo esc_html__( 'Examples', 'block-theme-migration' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $report['scan']['findings'] as $f ) : ?>
						<tr>
							<td><code><?php echo esc_html( $f['kind'] ); ?></code></td>
							<td><?php echo esc_html( $f['block'] ); ?><br><code><?php echo esc_html( $f['path'] ); ?></code></td>
							<td><?php echo esc_html( $f['action'] ); ?></td>
							<td><?php echo (int) $f['count']; ?></td>
							<td class="itmar-btm-muted"><?php echo esc_html( implode( ' | ', $f['examples'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php echo esc_html__( 'strip: remove an internal link. remove: delete the value (media, e-mail). reset: back to the default. bind: tied to a post type, taxonomy or field on the destination. keep: left as it is (external).', 'block-theme-migration' ); ?></p>
				<?php
				$removal_changes = array();
				foreach ( self::collect_items() as $removal_item ) {
					if ( in_array( $removal_item['kind'], array( 'template', 'part' ), true ) ) {
						Itmar_BTM_Sanitizer::sanitize_content( (string) $removal_item['content'], $removal_changes );
					}
				}
				$removal = Itmar_BTM_Sanitizer::summarize( $removal_changes );
				?>
				<p><strong><?php echo esc_html__( 'The export will remove:', 'block-theme-migration' ); ?></strong>
					<?php echo esc_html( empty( $removal['counts'] ) ? __( 'nothing', 'block-theme-migration' ) : implode( ', ', array_map( static fn( $k, $v ) => $k . ' ×' . $v, array_keys( $removal['counts'] ), $removal['counts'] ) ) ); ?>
				</p>
			<?php endif; ?>

			<h3><?php echo esc_html__( '4. Requirements the package will declare', 'block-theme-migration' ); ?></h3>
			<p class="itmar-btm-muted"><?php echo esc_html__( 'Plugins and blocks the templates need. The destination site is checked against this list.', 'block-theme-migration' ); ?></p>
			<table class="widefat striped itmar-btm-table" style="max-width:1100px"><tbody>
				<tr><td style="width:12em"><strong>WordPress</strong></td><td><?php echo esc_html( $report['requires']['wordpress']['minVersion'] . '+' ); ?></td></tr>
				<?php foreach ( $report['requires']['plugins'] as $plugin ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $plugin['name'] ); ?></strong><br><code><?php echo esc_html( $plugin['slug'] ); ?></code></td>
						<td>
							<?php echo esc_html( 'v' . $plugin['version'] ); ?>
							<?php if ( ! empty( $plugin['optional'] ) ) : ?> — <?php echo esc_html__( 'optional (to bind custom fields)', 'block-theme-migration' ); ?><?php elseif ( 'dependency' === $plugin['reason'] ) : ?> — <?php echo esc_html__( 'required by another plugin', 'block-theme-migration' ); ?><?php endif; ?>
							<?php if ( ! empty( $plugin['blocks'] ) ) : ?><br><span class="itmar-btm-muted"><?php echo esc_html( implode( ', ', $plugin['blocks'] ) ); ?></span><?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody></table>
			<?php if ( ! empty( $report['requires']['missingBlocks'] ) ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html__( 'Blocks whose plugin could not be identified on this site:', 'block-theme-migration' ); ?> <?php echo esc_html( implode( ', ', $report['requires']['missingBlocks'] ) ); ?></p></div>
			<?php endif; ?>

			<h3><?php echo esc_html__( '5. Data model the package will declare', 'block-theme-migration' ); ?></h3>
			<p class="itmar-btm-muted"><?php echo esc_html__( 'Declared for information only. The destination binds to its own post types, taxonomies and fields; nothing is created there.', 'block-theme-migration' ); ?></p>
			<?php $model = $report['dataModel']; ?>
			<table class="widefat striped itmar-btm-table" style="max-width:1100px">
				<thead><tr><th><?php echo esc_html__( 'Kind', 'block-theme-migration' ); ?></th><th><?php echo esc_html__( 'Name', 'block-theme-migration' ); ?></th><th><?php echo esc_html__( 'Details', 'block-theme-migration' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $model['postTypes'] as $type ) : ?>
					<tr><td><?php echo esc_html__( 'Post type', 'block-theme-migration' ); ?></td><td><code><?php echo esc_html( $type['slug'] ); ?></code><?php echo ! empty( $type['label'] ) ? ' ' . esc_html( $type['label'] ) : ''; ?></td>
						<td><?php echo ! empty( $type['exists'] ) ? esc_html( sprintf( /* translators: 1: archive yes/no, 2: taxonomies. */ __( 'archive: %1$s / taxonomies: %2$s / referenced %3$d times', 'block-theme-migration' ), ! empty( $type['hasArchive'] ) ? __( 'yes', 'block-theme-migration' ) : __( 'no', 'block-theme-migration' ), implode( ', ', $type['taxonomies'] ) ?: '-', (int) $type['references'] ) ) : $status( 'warn' ) . ' ' . esc_html__( 'referenced but not registered on this site', 'block-theme-migration' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
				<?php endforeach; ?>
				<?php foreach ( $model['taxonomies'] as $tax ) : ?>
					<tr><td><?php echo esc_html__( 'Taxonomy', 'block-theme-migration' ); ?></td><td><code><?php echo esc_html( $tax['slug'] ); ?></code><?php echo ! empty( $tax['label'] ) ? ' ' . esc_html( $tax['label'] ) : ''; ?></td>
						<td><?php echo ! empty( $tax['exists'] ) ? esc_html( sprintf( /* translators: 1: post types. */ __( 'post types: %1$s / referenced %2$d times', 'block-theme-migration' ), implode( ', ', $tax['postTypes'] ) ?: '-', (int) $tax['references'] ) ) : $status( 'warn' ) . ' ' . esc_html__( 'referenced but not registered on this site', 'block-theme-migration' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
				<?php endforeach; ?>
				<?php foreach ( $model['fieldGroups'] as $group ) : ?>
					<tr><td><?php echo esc_html__( 'Field group', 'block-theme-migration' ); ?></td><td><?php echo esc_html( $group['title'] ); ?><br><code><?php echo esc_html( implode( ', ', $group['postTypes'] ) ); ?></code></td>
						<td><?php echo esc_html( implode( ', ', array_map( static fn( $f ) => $f['name'] . ' (' . $f['type'] . ')', $group['fields'] ) ) ); ?>
							<?php if ( ! empty( $group['referenced'] ) ) : ?><br><span class="itmar-btm-muted"><?php echo esc_html__( 'referenced:', 'block-theme-migration' ); ?> <?php echo esc_html( implode( ', ', $group['referenced'] ) ); ?></span><?php endif; ?></td></tr>
				<?php endforeach; ?>
				<?php if ( ! empty( $model['postMeta'] ) ) : ?>
					<tr><td><?php echo esc_html__( 'Post meta', 'block-theme-migration' ); ?></td><td><code>meta_*</code></td>
						<td><?php echo esc_html( implode( ', ', array_map( static fn( $m ) => $m['reference'] . ( $m['registered'] ? '' : ' (not registered)' ), $model['postMeta'] ) ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( ! empty( $model['unmatchedFields'] ) ) : ?>
					<tr><td><?php echo esc_html__( 'Fields', 'block-theme-migration' ); ?></td><td><?php echo $status( 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td><?php echo esc_html__( 'referenced, but no field with this name was found on this site:', 'block-theme-migration' ); ?> <?php echo esc_html( implode( ', ', $model['unmatchedFields'] ) ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
			<p class="itmar-btm-muted"><?php echo esc_html( sprintf( /* translators: %d: number of usage entries. */ __( 'Usage index: %d references (which block and attribute refers to what). It is included in the report and the package.', 'block-theme-migration' ), count( $model['usage'] ) ) ); ?></p>

			<h3><?php echo esc_html__( '6. Block validity', 'block-theme-migration' ); ?></h3>
			<p class="itmar-btm-muted"><?php echo esc_html__( 'Opens every template, part and pattern with the block editor’s own check. This takes a while for a large theme.', 'block-theme-migration' ); ?></p>
			<p>
				<button type="button" class="button button-primary" id="itmar-btm-run"><?php echo esc_html__( 'Check that all blocks are valid', 'block-theme-migration' ); ?></button>
				<button type="button" class="button" id="itmar-btm-download"><?php echo esc_html__( 'Download the report (JSON)', 'block-theme-migration' ); ?></button>
				<span id="itmar-btm-progress" class="itmar-btm-muted" style="margin-left:.75em"></span>
			</p>
			<div id="itmar-btm-result"></div>
			<script type="application/json" id="itmar-btm-report"><?php echo wp_json_encode( $report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with HEX flags. ?></script>
		</div>
		<?php
	}
}
