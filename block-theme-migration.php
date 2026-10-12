<?php
/**
 * Plugin Name:       BLOCK THEME MIGRATION
 * Description:       Exports distributable block themes and maps their templates to destination-site content.
 * Version:           0.5.8
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Web Creator ITmaroon
 * License:           GPL-2.0-or-later
 * Text Domain:       block-theme-migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-datamodel.php';
require_once __DIR__ . '/includes/class-dependencies.php';
require_once __DIR__ . '/includes/class-inspector.php';
require_once __DIR__ . '/includes/class-binder.php';
require_once __DIR__ . '/includes/class-binding.php';
require_once __DIR__ . '/includes/class-sanitizer.php';

final class Itmar_Block_Theme_Migration {
	public const MENU_SLUG         = 'itmar-block-theme-migration';
	private const RESULT_TRANSIENT = 'itmar_btm_result_';
	/** Legacy per-user transient prefix retained for one-time migration. */
	private const MAP_TRANSIENT    = 'itmar_btm_map_';
	private const MAP_OPTION       = 'itmar_btm_saved_mapping_';
	private const DELETE_OPTION    = 'itmar_btm_deleted_sources_';
	private const MANIFEST_FILE    = 'block-theme-migration.json';
	private const PATTERN_SOURCE_META = '_itmar_btm_pattern_source';
	private const TEMPLATE_SOURCE_META = '_itmar_btm_template_source';
	private const TEMPLATE_TARGET_META = '_itmar_btm_template_target';
	private const TEMPLATE_THEME_META  = '_itmar_btm_template_theme';
	private const TEMPLATE_PACKAGE_META = '_itmar_btm_template_package';
	private const TARGET_TYPES     = array( 'page', 'single', 'archive' );
	private const AUTOMATIC_TEMPLATES = array(
		'index',
		'home',
		'front-page',
		'singular',
		'single',
		'page',
		'archive',
		'author',
		'category',
		'taxonomy',
		'date',
		'tag',
		'search',
		'404',
		'attachment',
		'embed',
		'privacy-policy',
	);

	/** 書き出しから外す項目（今回の書き出しだけ。保存しない） */
	private static array $export_excluded = array( 'templates' => array(), 'parts' => array() );

	/** デモ用の書き出し（リンクとメディアを残す。配布には使わない） */
	private static bool $demo_export = false;

	public static function init(): void {
		Itmar_BTM_Inspector::init();
		Itmar_BTM_Binding::init();
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'after_setup_theme', array( __CLASS__, 'maybe_refresh_pattern_cache' ), 1 );
		add_action( 'admin_post_itmar_btm_export', array( __CLASS__, 'download_export' ) );
		add_action( 'admin_post_itmar_btm_import_patterns', array( __CLASS__, 'handle_pattern_import' ) );
		add_action( 'admin_post_itmar_btm_map', array( __CLASS__, 'handle_mapping' ) );
		add_action( 'admin_post_itmar_btm_reset_templates', array( __CLASS__, 'handle_template_reset' ) );
	}

	public static function register_menu(): void {
		add_management_page(
			__( 'Theme Migration', 'block-theme-migration' ),
			__( 'Theme Migration', 'block-theme-migration' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'block-theme-migration' ) );
		}

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'export';
		if ( ! in_array( $view, array( 'export', 'mapping', Itmar_BTM_Inspector::VIEW, Itmar_BTM_Binding::VIEW ), true ) ) {
			$view = 'export';
		}
		$base_url = admin_url( 'tools.php?page=' . self::MENU_SLUG );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Theme Migration', 'block-theme-migration' ); ?></h1>
			<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'Theme Migration sections', 'block-theme-migration' ); ?>">
				<a href="<?php echo esc_url( add_query_arg( 'view', 'export', $base_url ) ); ?>" class="nav-tab <?php echo 'export' === $view ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Distribution Export', 'block-theme-migration' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'view', Itmar_BTM_Inspector::VIEW, $base_url ) ); ?>" class="nav-tab <?php echo Itmar_BTM_Inspector::VIEW === $view ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Inspection', 'block-theme-migration' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'view', Itmar_BTM_Binding::VIEW, $base_url ) ); ?>" class="nav-tab <?php echo Itmar_BTM_Binding::VIEW === $view ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Binding', 'block-theme-migration' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'view', 'mapping', $base_url ) ); ?>" class="nav-tab <?php echo 'mapping' === $view ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Template Mapping', 'block-theme-migration' ); ?></a>
			</nav>
			<?php
			if ( Itmar_BTM_Inspector::VIEW === $view ) {
				Itmar_BTM_Inspector::render();
			} elseif ( Itmar_BTM_Binding::VIEW === $view ) {
				Itmar_BTM_Binding::render();
			} elseif ( 'mapping' === $view ) {
				self::render_mapping_page();
			} else {
				self::render_export_page();
			}
			?>
		</div>
		<?php
	}

	public static function render_export_page(): void {
		if ( ! current_user_can( 'export' ) ) {
			wp_die( esc_html__( 'You do not have permission to export this theme.', 'block-theme-migration' ) );
		}

		$templates = get_block_templates();
		?>
		<div>
			<h2><?php echo esc_html__( 'Distribution Export', 'block-theme-migration' ); ?></h2>
			<p><?php echo esc_html__( 'Use this screen on the development site. Select only templates that users should be able to assign after installing the exported theme.', 'block-theme-migration' ); ?></p>
			<?php if ( ! wp_is_block_theme() ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html__( 'The active theme is not a block theme.', 'block-theme-migration' ); ?></p></div>
			<?php elseif ( empty( $templates ) ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html__( 'No block templates were found.', 'block-theme-migration' ); ?></p></div>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="itmar_btm_export">
					<?php wp_nonce_field( 'itmar_btm_export' ); ?>
					<table class="widefat striped" style="max-width: 1100px">
						<thead><tr>
							<th><?php echo esc_html__( 'Exported template', 'block-theme-migration' ); ?></th>
							<th><?php echo esc_html__( 'Make assignable', 'block-theme-migration' ); ?></th>
							<th><?php echo esc_html__( 'Allowed destination types', 'block-theme-migration' ); ?></th>
							<th><?php echo esc_html__( 'Leave out of the ZIP', 'block-theme-migration' ); ?></th>
							</tr></thead>
						<tbody>
						<?php foreach ( $templates as $template ) : ?>
							<?php $automatic = self::is_automatically_assigned_template( $template->slug ); ?>
							<tr>
								<td><strong><?php echo esc_html( $template->title ?: $template->slug ); ?></strong><br><code><?php echo esc_html( $template->slug ); ?></code></td>
								<td><label><input type="checkbox" name="assignable[<?php echo esc_attr( $template->slug ); ?>]" value="1" <?php disabled( $automatic ); ?>> <?php echo esc_html__( 'Include', 'block-theme-migration' ); ?></label></td>
								<td>
									<label><input type="radio" name="allowed_targets[<?php echo esc_attr( $template->slug ); ?>]" value="page" <?php disabled( $automatic ); ?>> <?php echo esc_html__( 'Fixed page', 'block-theme-migration' ); ?></label>&nbsp;&nbsp;
									<label><input type="radio" name="allowed_targets[<?php echo esc_attr( $template->slug ); ?>]" value="single" <?php disabled( $automatic ); ?>> <?php echo esc_html__( 'Post type single', 'block-theme-migration' ); ?></label>&nbsp;&nbsp;
									<label><input type="radio" name="allowed_targets[<?php echo esc_attr( $template->slug ); ?>]" value="archive" <?php disabled( $automatic ); ?>> <?php echo esc_html__( 'Post type archive', 'block-theme-migration' ); ?></label>
									<?php if ( $automatic ) : ?><br><span class="description"><?php echo esc_html__( 'Automatically applied by the WordPress template hierarchy.', 'block-theme-migration' ); ?></span><?php endif; ?>
									</td>
									<td><label><input type="checkbox" name="exclude_templates[<?php echo esc_attr( $template->slug ); ?>]" value="1"> <?php echo esc_html__( 'Leave out', 'block-theme-migration' ); ?></label></td>
									</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description"><?php echo esc_html__( 'Unmarked templates remain in the ZIP but do not appear on the destination mapping screen. Templates marked “Leave out” are not put in the ZIP at all.', 'block-theme-migration' ); ?></p>

					<?php self::render_export_parts_table(); ?>
					<p>
						<label><input type="checkbox" name="demo_mode" value="1"> <strong><?php echo esc_html__( 'Demo export', 'block-theme-migration' ); ?></strong></label><br>
						<span class="description"><?php echo esc_html__( 'For a demo site (for example WordPress Playground). Links to pages and uploaded media are kept, and the site address is made relative, so the pages link to each other once pages with the same slugs exist. E-mail addresses, IDs and settings of outside services are still removed. Do not use this ZIP for distribution.', 'block-theme-migration' ); ?></span>
					</p>
					<?php submit_button( __( 'Download distribution ZIP', 'block-theme-migration' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_mapping_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to map templates.', 'block-theme-migration' ) );
		}

		$manifest      = self::get_installed_manifest();
		$user_id       = get_current_user_id();
		$result        = get_transient( self::RESULT_TRANSIENT . $user_id );
		$saved_mapping = self::get_saved_mapping();
		$deleted_sources = self::get_saved_source_deletions();
		delete_transient( self::RESULT_TRANSIENT . $user_id );
		?>
		<div>
			<h2><?php echo esc_html__( 'Template Mapping', 'block-theme-migration' ); ?></h2>
			<p><?php echo esc_html__( 'Use this screen on the destination site. Destination choices are read from this site, not from the development site.', 'block-theme-migration' ); ?></p>
			<?php self::render_result( $result ); ?>
			<?php if ( is_wp_error( $manifest ) ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $manifest->get_error_message() ); ?></p></div>
			<?php else : ?>
				<?php if ( ! empty( $manifest['theme']['slugMismatch'] ) ) : ?>
					<div class="notice notice-warning"><p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: package theme slug, 2: installed theme slug. */
								__( 'The package was created as "%1$s", but the active theme folder is "%2$s". Mapping will use the active theme folder.', 'block-theme-migration' ),
								$manifest['theme']['slug'] ?? '',
								$manifest['theme']['installedSlug'] ?? ''
							)
						);
						?>
					</p></div>
				<?php endif; ?>
				<?php Itmar_BTM_Dependencies::render( $manifest ); ?>
				<?php self::render_pattern_import_form( $manifest ); ?>
				<?php self::render_mapping_form( $manifest, $saved_mapping, $deleted_sources ); ?>
				<?php self::render_managed_templates(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_pattern_import_form( array $manifest ): void {
		$patterns = $manifest['editablePatterns'] ?? array();
		if ( empty( $patterns ) ) {
			return;
		}

		$synced   = count( array_filter( $patterns, static fn( array $pattern ): bool => 'synced' === $pattern['syncStatus'] ) );
		$unsynced = count( $patterns ) - $synced;
		$imported = count( self::get_imported_pattern_ids( $patterns ) );
		?>
		<h3><?php echo esc_html__( 'Editable pattern import', 'block-theme-migration' ); ?></h3>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: total patterns, 2: synced patterns, 3: unsynced patterns, 4: already imported patterns. */
					__( '%1$d patterns in package — synced: %2$d, unsynced: %3$d, already imported: %4$d.', 'block-theme-migration' ),
					count( $patterns ),
					$synced,
					$unsynced,
					$imported
				)
			);
			?>
		</p>
		<p><?php echo esc_html__( 'Import these first. Synced references in theme templates will be changed to the new pattern IDs on this site.', 'block-theme-migration' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="itmar_btm_import_patterns">
			<?php wp_nonce_field( 'itmar_btm_import_patterns' ); ?>
			<p><label><input type="checkbox" name="overwrite_templates" value="1"> <?php echo esc_html__( 'Also overwrite existing customized templates when reconnecting synced patterns', 'block-theme-migration' ); ?></label></p>
			<p class="submit">
				<button type="submit" class="button" name="mode" value="preview"><?php echo esc_html__( 'Preview pattern import', 'block-theme-migration' ); ?></button>
				<button type="submit" class="button button-primary" name="mode" value="apply" onclick="return confirm('<?php echo esc_js( __( 'Import or update editable patterns on this site?', 'block-theme-migration' ) ); ?>');"><?php echo esc_html__( 'Import patterns', 'block-theme-migration' ); ?></button>
			</p>
	</form>
	<hr>
		<?php
	}

	private static function render_mapping_form( array $manifest, array $saved_mapping, array $deleted_sources ): void {
		$templates = $manifest['assignableTemplates'] ?? array();
		$pages      = get_pages( array( 'post_status' => array( 'publish', 'draft', 'private' ) ) );
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $post_types['attachment'], $post_types['page'] );

		if ( empty( $templates ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'The active theme does not declare any assignable templates.', 'block-theme-migration' ) . '</p></div>';
			return;
		}
		?>
		<p><?php echo esc_html( sprintf( __( 'Theme package: %s', 'block-theme-migration' ), $manifest['theme']['name'] ?? get_stylesheet() ) ); ?></p>
		<p class="description"><?php echo esc_html__( 'Mapping selections are saved for the active theme and remain selected after previewing or returning to this screen. Select "Do not map" and preview or apply to clear a saved selection.', 'block-theme-migration' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="itmar_btm_map">
			<?php wp_nonce_field( 'itmar_btm_map' ); ?>
			<table class="widefat striped" style="max-width: 1200px">
				<thead><tr>
					<th><?php echo esc_html__( 'Distributed template', 'block-theme-migration' ); ?></th>
					<th><?php echo esc_html__( 'Destination on this site', 'block-theme-migration' ); ?></th>
					<th><?php echo esc_html__( 'Next distribution export', 'block-theme-migration' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $templates as $template ) : ?>
					<?php
					$source_slug = sanitize_title( $template['slug'] ?? '' );
					$allowed     = self::sanitize_target_types( $template['allowedTargets'] ?? array() );
					if ( '' === $source_slug || empty( $allowed ) ) {
						continue;
					}
					?>
					<tr>
						<td><strong><?php echo esc_html( $template['title'] ?? $source_slug ); ?></strong><br><code><?php echo esc_html( $source_slug ); ?></code></td>
						<td>
							<select name="mapping[<?php echo esc_attr( $source_slug ); ?>]" style="min-width: 420px">
								<option value=""><?php echo esc_html__( 'Do not map', 'block-theme-migration' ); ?></option>
								<?php if ( in_array( 'page', $allowed, true ) ) : ?>
									<optgroup label="<?php echo esc_attr__( 'Fixed pages on this site', 'block-theme-migration' ); ?>">
									<?php foreach ( $pages as $page ) : $value = 'page|' . $page->ID; ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $saved_mapping[ $source_slug ] ?? '', $value ); ?>><?php echo esc_html( $page->post_title . ' (' . $page->post_name . ')' ); ?></option>
									<?php endforeach; ?>
									</optgroup>
								<?php endif; ?>
								<?php if ( in_array( 'single', $allowed, true ) ) : ?>
									<optgroup label="<?php echo esc_attr__( 'Post types on this site (single)', 'block-theme-migration' ); ?>">
									<?php foreach ( $post_types as $post_type ) : $value = 'single|' . $post_type->name; ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $saved_mapping[ $source_slug ] ?? '', $value ); ?>><?php echo esc_html( $post_type->labels->singular_name . ' (single-' . $post_type->name . ')' ); ?></option>
									<?php endforeach; ?>
									</optgroup>
								<?php endif; ?>
								<?php if ( in_array( 'archive', $allowed, true ) ) : ?>
									<optgroup label="<?php echo esc_attr__( 'Post types on this site (archive)', 'block-theme-migration' ); ?>">
									<?php foreach ( $post_types as $post_type ) : ?>
										<?php if ( ! $post_type->has_archive ) { continue; } $value = 'archive|' . $post_type->name; ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $saved_mapping[ $source_slug ] ?? '', $value ); ?>><?php echo esc_html( $post_type->labels->name . ' (archive-' . $post_type->name . ')' ); ?></option>
									<?php endforeach; ?>
									</optgroup>
								<?php endif; ?>
							</select>
						</td>
						<td>
							<label><input type="checkbox" name="delete_source[<?php echo esc_attr( $source_slug ); ?>]" value="1" <?php checked( in_array( $source_slug, $deleted_sources, true ) ); ?>> <?php echo esc_html__( 'Remove the mapped source template file from the exported ZIP', 'block-theme-migration' ); ?></label>
							<br><span class="description"><code><?php echo esc_html( 'templates/' . $source_slug . '.html' ); ?></code></span>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php echo esc_html__( 'Source-file removal never edits the active theme. It is applied only when a new ZIP is downloaded from Distribution Export, and is ignored when the source and destination slugs are the same.', 'block-theme-migration' ); ?></p>
			<p><label><input type="checkbox" name="overwrite_existing" value="1"> <?php echo esc_html__( 'Allow existing destination template and template-part customizations to be overwritten', 'block-theme-migration' ); ?></label></p>
			<p class="submit">
				<button type="submit" class="button" name="mode" value="preview"><?php echo esc_html__( 'Preview mapping', 'block-theme-migration' ); ?></button>
				<button type="submit" class="button button-primary" name="mode" value="apply" onclick="return confirm('<?php echo esc_js( __( 'Apply these mappings to this site?', 'block-theme-migration' ) ); ?>');"><?php echo esc_html__( 'Apply mapping', 'block-theme-migration' ); ?></button>
			</p>
		</form>
		<?php
	}

	private static function render_managed_templates(): void {
		$templates = self::get_managed_templates();
		?>
		<hr>
		<h3><?php echo esc_html__( 'Theme Migration-created theme content', 'block-theme-migration' ); ?></h3>
		<p><?php echo esc_html__( 'These template and template-part database customizations were created or adopted by Theme Migration. Removing one restores the matching item from the active theme when a theme file exists.', 'block-theme-migration' ); ?></p>
		<?php if ( empty( $templates ) ) : ?>
			<p><?php echo esc_html__( 'No managed templates were found for the active theme.', 'block-theme-migration' ); ?></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="itmar_btm_reset_templates">
				<?php wp_nonce_field( 'itmar_btm_reset_templates' ); ?>
				<table class="widefat striped" style="max-width: 1200px">
					<thead><tr>
						<td class="check-column"><span class="screen-reader-text"><?php echo esc_html__( 'Select', 'block-theme-migration' ); ?></span></td>
						<th><?php echo esc_html__( 'Type', 'block-theme-migration' ); ?></th>
						<th><?php echo esc_html__( 'Destination template', 'block-theme-migration' ); ?></th>
						<th><?php echo esc_html__( 'Migration source', 'block-theme-migration' ); ?></th>
						<th><?php echo esc_html__( 'Last updated', 'block-theme-migration' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $templates as $template ) : ?>
						<tr>
							<th class="check-column"><input type="checkbox" name="template_ids[]" value="<?php echo esc_attr( $template->ID ); ?>"></th>
							<td><?php echo esc_html( 'wp_template_part' === $template->post_type ? __( 'Template part', 'block-theme-migration' ) : __( 'Template', 'block-theme-migration' ) ); ?></td>
							<td><strong><?php echo esc_html( $template->post_title ?: $template->post_name ); ?></strong><br><code><?php echo esc_html( $template->post_name ); ?></code></td>
							<td><code><?php echo esc_html( get_post_meta( $template->ID, self::TEMPLATE_SOURCE_META, true ) ); ?></code><br><?php echo esc_html( get_post_meta( $template->ID, self::TEMPLATE_TARGET_META, true ) ); ?></td>
							<td><?php echo esc_html( get_date_from_gmt( $template->post_modified_gmt, 'Y-m-d H:i' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php echo esc_html__( 'Managed items are moved to Trash; unrelated user customizations are never included in this list.', 'block-theme-migration' ); ?></p>
				<p class="submit">
					<button type="submit" class="button" name="reset_mode" value="selected" onclick="return confirm('<?php echo esc_js( __( 'Move the selected Theme Migration templates to Trash?', 'block-theme-migration' ) ); ?>');"><?php echo esc_html__( 'Reset selected templates', 'block-theme-migration' ); ?></button>
					<button type="submit" class="button" name="reset_mode" value="all" onclick="return confirm('<?php echo esc_js( __( 'Move all Theme Migration templates for the active theme to Trash?', 'block-theme-migration' ) ); ?>');"><?php echo esc_html__( 'Reset all managed templates', 'block-theme-migration' ); ?></button>
				</p>
			</form>
		<?php endif; ?>
		<?php
	}

	private static function render_result( $result ): void {
		if ( ! is_array( $result ) || empty( $result['items'] ) ) {
			return;
		}
		$class = ! empty( $result['has_error'] ) ? 'notice notice-error' : 'notice notice-info';
		?>
		<div class="<?php echo esc_attr( $class ); ?>">
			<p><strong><?php echo esc_html( $result['title'] ?? '' ); ?></strong></p>
			<ul style="list-style: disc; padding-left: 1.5em">
			<?php foreach ( $result['items'] as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	public static function download_export(): void {
		if ( ! current_user_can( 'export' ) ) {
			wp_die( esc_html__( 'You do not have permission to export this theme.', 'block-theme-migration' ) );
		}
		check_admin_referer( 'itmar_btm_export' );
		if ( ! wp_is_block_theme() ) {
			wp_die( esc_html__( 'The active theme is not a block theme.', 'block-theme-migration' ) );
		}

		$assignable = self::get_requested_assignable_templates();
		$excluded   = self::get_requested_exclusions();
		$conflicts  = Itmar_BTM_Inspector::excluded_part_conflicts( $excluded['parts'], $excluded['templates'] );
		if ( ! empty( $conflicts ) ) {
			$lines = array();
			foreach ( $conflicts as $part => $users ) {
				$lines[] = sprintf( /* translators: 1: part slug, 2: items that still use it. */ __( '"%1$s" is still used by: %2$s', 'block-theme-migration' ), $part, implode( ', ', $users ) );
			}
			wp_die( esc_html( __( 'These template parts cannot be left out because items that stay in the ZIP use them.', 'block-theme-migration' ) . ' ' . implode( ' / ', $lines ) ) );
		}
		self::$export_excluded = $excluded;
		self::$demo_export     = ! empty( $_POST['demo_mode'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$filename   = wp_generate_block_templates_export_file();
		if ( is_wp_error( $filename ) ) {
			wp_die( esc_html( $filename->get_error_message() ) );
		}
		$result = self::enhance_export_zip( $filename, $assignable, self::get_saved_source_deletions() );
		if ( is_wp_error( $result ) ) {
			@unlink( $filename );
			wp_die( esc_html( $result->get_error_message() ) );
		}

		$download_name = sanitize_file_name( get_stylesheet() . ( self::$demo_export ? '-demo' : '' ) . '.zip' );
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $download_name . '"' );
		header( 'Content-Length: ' . filesize( $filename ) );
		readfile( $filename );
		@unlink( $filename );
		exit;
	}

	/** 書き出しから外す、テンプレートとパーツの slug */
	private static function get_requested_exclusions(): array {
		$out = array( 'templates' => array(), 'parts' => array() );
		foreach ( array( 'templates' => 'exclude_templates', 'parts' => 'exclude_parts' ) as $key => $field ) {
			$raw = isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() in the caller.
			foreach ( array_keys( $raw ) as $slug ) {
				$slug = sanitize_title( $slug );
				if ( '' !== $slug ) {
					$out[ $key ][] = $slug;
				}
			}
			$out[ $key ] = array_values( array_unique( $out[ $key ] ) );
		}
		return $out;
	}

	/** 書き出しの画面に、パーツの除外のチェックの表を出す */
	private static function render_export_parts_table(): void {
		$parts = get_block_templates( array(), 'wp_template_part' );
		if ( empty( $parts ) ) {
			return;
		}
		$inventory = Itmar_BTM_Inspector::inventory( Itmar_BTM_Inspector::collect_items() );
		$unused    = array();
		foreach ( $inventory['parts'] as $part ) {
			$unused[ $part['slug'] ] = $part['unused'];
		}
		?>
		<h3 style="margin-top:2em"><?php echo esc_html__( 'Template parts', 'block-theme-migration' ); ?></h3>
		<table class="widefat striped" style="max-width: 1100px">
			<thead><tr>
				<th><?php echo esc_html__( 'Template part', 'block-theme-migration' ); ?></th>
				<th><?php echo esc_html__( 'Leave out of the ZIP', 'block-theme-migration' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $parts as $part ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $part->title ?: $part->slug ); ?></strong><br><code><?php echo esc_html( $part->slug ); ?></code>
						<?php if ( ! empty( $unused[ $part->slug ] ) ) : ?> <span class="description">— <?php echo esc_html__( 'not used by any template, part or pattern', 'block-theme-migration' ); ?></span><?php endif; ?></td>
					<td><label><input type="checkbox" name="exclude_parts[<?php echo esc_attr( $part->slug ); ?>]" value="1"> <?php echo esc_html__( 'Leave out', 'block-theme-migration' ); ?></label></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php echo esc_html__( 'A part that other items still use cannot be left out. See the Inspection tab for what is unused.', 'block-theme-migration' ); ?></p>
		<?php
	}

	private static function get_requested_assignable_templates(): array {
		$selected    = isset( $_POST['assignable'] ) && is_array( $_POST['assignable'] ) ? wp_unslash( $_POST['assignable'] ) : array();
		$raw_targets = isset( $_POST['allowed_targets'] ) && is_array( $_POST['allowed_targets'] ) ? wp_unslash( $_POST['allowed_targets'] ) : array();
		$available   = array();
		foreach ( get_block_templates() as $template ) {
			$available[ $template->slug ] = $template;
		}

		$items = array();
		foreach ( array_keys( $selected ) as $raw_slug ) {
			$slug    = sanitize_title( $raw_slug );
			$allowed = self::sanitize_target_types( $raw_targets[ $raw_slug ] ?? '' );
			if ( ! isset( $available[ $slug ] ) || empty( $allowed ) || self::is_automatically_assigned_template( $slug ) ) {
				continue;
			}
			$template = $available[ $slug ];
			$items[]  = array(
				'slug'           => $slug,
				'title'          => $template->title ?: $slug,
				'allowedTargets' => $allowed,
			);
		}
		return $items;
	}

	private static function sanitize_target_types( $types ): array {
		if ( is_string( $types ) ) {
			$types = array( $types );
		} elseif ( ! is_array( $types ) ) {
			$types = array();
		}
		$types = array_map( 'sanitize_key', $types );
		$allowed = array_values( array_intersect( self::TARGET_TYPES, array_unique( $types ) ) );
		return empty( $allowed ) ? array() : array( $allowed[0] );
	}

	private static function is_automatically_assigned_template( string $slug ): bool {
		return in_array( sanitize_title( $slug ), self::AUTOMATIC_TEMPLATES, true );
	}

	private static function enhance_export_zip( string $filename, ?array $assignable = null, array $deleted_sources = array() ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'itmar_btm_zip_missing', __( 'ZipArchive is required.', 'block-theme-migration' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $filename ) ) {
			return new WP_Error( 'itmar_btm_zip_open', __( 'Could not open the exported ZIP.', 'block-theme-migration' ) );
		}

		$deleted_sources = self::sanitize_source_deletions( $deleted_sources );
		// 今回の書き出しだけ外すテンプレート（保存される「移植元の削除」とは別）。manifest・customTemplates からも消える
		$deleted_sources = array_values( array_unique( array_merge( $deleted_sources, self::$export_excluded['templates'] ) ) );
		foreach ( $deleted_sources as $source_slug ) {
			$zip->deleteName( 'templates/' . $source_slug . '.html' );
		}
		foreach ( self::$export_excluded['parts'] as $part_slug ) {
			$zip->deleteName( 'parts/' . $part_slug . '.html' );
		}

		$theme_slug = sanitize_key( get_stylesheet() );
		$patterns   = array();
		$checks     = array();
		$removals   = array(); // 書き出し時の除去の記録（Itmar_BTM_Sanitizer）
		Itmar_BTM_Sanitizer::set_demo( self::$demo_export );
		wp_raise_memory_limit( 'admin' );
		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$entry_name = $zip->getNameIndex( $index );
			if ( ! is_string( $entry_name ) || ! preg_match( '#^(templates|parts)/[^/]+\.html$#', $entry_name ) ) {
				continue;
			}
			$content = $zip->getFromIndex( $index );
			if ( false === $content ) {
				continue;
			}
			$converted = self::convert_synced_patterns( parse_blocks( $content ), $patterns, $theme_slug, $checks );
			$new_html  = Itmar_BTM_Sanitizer::sanitize_content( self::stable_serialize_blocks( $converted ), $removals );
			$zip->addFromString( $entry_name, $new_html );
			self::scan_content( $entry_name, $new_html, $checks );
		}

		if ( ! empty( $patterns ) ) {
			// 同期パターンから変換したパターンも、同じ除去を通す
			foreach ( $patterns as $key => $pattern ) {
				$patterns[ $key ]['content'] = Itmar_BTM_Sanitizer::sanitize_content( (string) $pattern['content'], $removals );
			}
			$zip->addEmptyDir( 'patterns' );
			foreach ( $patterns as $pattern ) {
				$header  = "<?php\n/**\n";
				$header .= ' * Title: ' . self::safe_header_value( $pattern['title'] ) . "\n";
				$header .= ' * Slug: ' . $pattern['slug'] . "\n";
				$header .= " * Inserter: no\n */\n?>\n";
				$zip->addFromString( 'patterns/' . $pattern['file'] . '.php', $header . $pattern['content'] );
			}
		}

		$theme_json_text = $zip->getFromName( 'theme.json' );
		$theme_json      = is_string( $theme_json_text ) ? json_decode( $theme_json_text, true ) : array();
		if ( ! is_array( $theme_json ) ) {
			$theme_json = array( 'version' => 3 );
		}
		if ( ! empty( $theme_json['customTemplates'] ) && is_array( $theme_json['customTemplates'] ) ) {
			$theme_json['customTemplates'] = array_values(
				array_filter(
					$theme_json['customTemplates'],
					static fn( $template ): bool => ! is_array( $template ) || ! in_array( sanitize_title( $template['name'] ?? '' ), $deleted_sources, true )
				)
			);
		}
		$parts                       = self::get_template_part_manifest();
		$theme_json['templateParts'] = array_values(
			array_filter(
				self::merge_template_parts( $theme_json['templateParts'] ?? array(), $parts ),
				static fn( $part ): bool => ! is_array( $part ) || ! in_array( $part['name'] ?? '', self::$export_excluded['parts'], true )
			)
		);
		$fonts = self::localize_font_files( $zip, $theme_json );
		$zip->addFromString( 'theme.json', self::stable_wp_json_encode( $theme_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		if ( null === $assignable ) {
			$assignable = array_map(
				static function ( array $template ): array {
					return array( 'slug' => $template['slug'], 'title' => $template['title'], 'allowedTargets' => self::TARGET_TYPES );
				},
				self::get_template_manifest()
			);
		}
		$assignable = array_values(
			array_filter(
				$assignable,
				static fn( array $template ): bool => ! in_array( sanitize_title( $template['slug'] ?? '' ), $deleted_sources, true )
			)
		);
		$template_manifest = self::get_template_manifest( $deleted_sources );
		$declarations = Itmar_BTM_Inspector::declarations( self::$export_excluded );
		$manifest = array(
			'schemaVersion'       => 5,
			'generatedAt'         => gmdate( 'c' ),
			'theme'               => array( 'slug' => get_stylesheet(), 'name' => wp_get_theme()->get( 'Name' ) ),
			'sourceSite'          => array( 'homeUrl' => home_url( '/' ) ),
			'templates'           => $template_manifest,
			'assignableTemplates' => array_values( $assignable ),
			'editablePatterns'    => self::get_editable_pattern_manifest( $patterns ),
			'templateParts'       => $parts,
			'patterns'            => array_values(
				array_map(
					static function ( array $pattern ): array {
						return array( 'slug' => $pattern['slug'], 'title' => $pattern['title'], 'file' => 'patterns/' . $pattern['file'] . '.php' );
					},
					$patterns
				)
			),
			'checks'              => array_values( array_unique( $checks ) ),
			'removedSourceTemplates' => $deleted_sources,
			'excluded'            => self::$export_excluded,
			'removals'            => Itmar_BTM_Sanitizer::summarize( $removals ),
			'fonts'               => $fonts,
			'demo'                => self::$demo_export,
			'requires'            => $declarations['requires'],
			'dataModel'           => $declarations['dataModel'],
		);
		$zip->addFromString( self::MANIFEST_FILE, self::stable_wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$zip->close();
		return true;
	}

	/**
	 * Font Library で入れたフォント（サイトの uploads にあるファイル）を、テーマの assets/fonts/<スラッグ>/ へコピーして、
	 * theme.json の fontFace の src を、file:./assets/fonts/… に書き換える。
	 * 書き出される theme.json には、サイトの Font Library のフォントも入っていて、src が、移植元のアドレス（https://…/uploads/fonts/…）のままだと、
	 * 移植先で、フォントが読めない。テーマにすでに同じ内容のファイルがあるときは、それを指す（同じファイルを、2 度入れない）。
	 * 外部のアドレス、見つからないファイルは、そのまま残し、記録に挙げる。
	 *
	 * @return array{localized:array,skipped:array}
	 */
	private static function localize_font_files( ZipArchive $zip, array &$theme_json ): array {
		$report   = array( 'localized' => array(), 'skipped' => array() );
		$families = $theme_json['settings']['typography']['fontFamilies'] ?? null;
		if ( ! is_array( $families ) || empty( $families ) ) {
			return $report;
		}
		$uploads  = wp_get_upload_dir();
		$base_url = preg_replace( '#^https?:#', '', untrailingslashit( (string) $uploads['baseurl'] ) );
		$base_dir = untrailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) );
		$base_dir_real = realpath( $base_dir );
		// すでに ZIP にあるフォントファイルの内容（同じものは、もう一度入れない）
		$existing = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( is_string( $name ) && preg_match( '#^assets/fonts/.+\.(?:woff2?|ttf|otf)$#i', $name ) ) {
				$data = $zip->getFromIndex( $i );
				if ( false !== $data ) {
					$existing[ md5( $data ) ] = $name;
				}
			}
		}
		$is_list = array_is_list( $families );
		$groups  = $is_list ? array( $families ) : $families; // 一覧そのもの、または、{theme: […], custom: […]}
		foreach ( $groups as $group_key => $list ) {
			if ( ! is_array( $list ) ) {
				continue;
			}
			foreach ( $list as $family_index => $family ) {
				if ( ! is_array( $family ) || empty( $family['fontFace'] ) || ! is_array( $family['fontFace'] ) ) {
					continue;
				}
				$slug = sanitize_title( (string) ( $family['slug'] ?? ( $family['name'] ?? 'font' ) ) );
				foreach ( $family['fontFace'] as $face_index => $face ) {
					if ( ! is_array( $face ) || empty( $face['src'] ) ) {
						continue;
					}
					$sources = is_array( $face['src'] ) ? $face['src'] : array( $face['src'] );
					$changed = false;
					foreach ( $sources as $source_index => $src ) {
						if ( ! is_string( $src ) || 0 === strpos( $src, 'file:' ) ) {
							continue;
						}
						$path_part = (string) wp_parse_url( $src, PHP_URL_PATH );
						$no_scheme = preg_replace( '#^https?:#', '', strtok( $src, '?#' ) );
						$file      = '';
						if ( 0 === strpos( $no_scheme, $base_url . '/' ) ) {
							$file = $base_dir . '/' . rawurldecode( substr( $no_scheme, strlen( $base_url ) + 1 ) );
						} elseif ( 0 === strpos( $path_part, wp_parse_url( (string) $uploads['baseurl'], PHP_URL_PATH ) . '/' ) && 0 === strpos( $src, '/' ) ) {
							$file = $base_dir . '/' . rawurldecode( substr( $path_part, strlen( (string) wp_parse_url( (string) $uploads['baseurl'], PHP_URL_PATH ) ) + 1 ) );
						}
						$real = '' !== $file ? realpath( $file ) : false;
						if ( false === $real || false === $base_dir_real || 0 !== strpos( wp_normalize_path( $real ), wp_normalize_path( $base_dir_real ) . '/' ) || ! is_file( $real ) ) {
							$report['skipped'][] = array( 'slug' => $slug, 'src' => $src, 'reason' => '' === $file ? 'not a file in uploads' : 'file not found' );
							continue;
						}
						$data = file_get_contents( $real );
						if ( false === $data ) {
							$report['skipped'][] = array( 'slug' => $slug, 'src' => $src, 'reason' => 'could not be read' );
							continue;
						}
						$hash = md5( $data );
						if ( isset( $existing[ $hash ] ) ) {
							$dest = $existing[ $hash ];
						} else {
							$dest = 'assets/fonts/' . $slug . '/' . sanitize_file_name( basename( $real ) );
							if ( false !== $zip->locateName( $dest ) ) {
								$dest = 'assets/fonts/' . $slug . '/' . substr( $hash, 0, 8 ) . '-' . sanitize_file_name( basename( $real ) );
							}
							$zip->addFromString( $dest, $data );
							$existing[ $hash ] = $dest;
						}
						$sources[ $source_index ] = 'file:./' . $dest;
						$changed                  = true;
						$report['localized'][]    = array( 'slug' => $slug, 'file' => $dest );
					}
					if ( $changed ) {
						$face['src'] = is_array( $face['src'] ) ? array_values( $sources ) : $sources[0];
						$list[ $family_index ]['fontFace'][ $face_index ] = $face;
					}
				}
			}
			$groups[ $group_key ] = $list;
		}
		$theme_json['settings']['typography']['fontFamilies'] = $is_list ? $groups[0] : $groups;
		return $report;
	}

	private static function convert_synced_patterns( array $blocks, array &$patterns, string $theme_slug, array &$checks ): array {
		foreach ( $blocks as &$block ) {
			if ( 'core/block' === ( $block['blockName'] ?? null ) && ! empty( $block['attrs']['ref'] ) ) {
				$ref  = absint( $block['attrs']['ref'] );
				$post = get_post( $ref );
				if ( $post && 'wp_block' === $post->post_type ) {
					$file = sanitize_title( $post->post_name ?: $post->post_title );
					if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $file ) ) {
						$file = 'synced-pattern-' . $ref;
					}
					$key  = $theme_slug . '/' . $file;
					$patterns[ $ref ] = array( 'file' => $file, 'slug' => $key, 'title' => $post->post_title ?: $file, 'content' => $post->post_content );
					$block = array( 'blockName' => 'core/pattern', 'attrs' => array( 'slug' => $key ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() );
				} else {
					$checks[] = sprintf( 'Unresolved synced pattern reference: %d', $ref );
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::convert_synced_patterns( $block['innerBlocks'], $patterns, $theme_slug, $checks );
			}
		}
		unset( $block );
		return $blocks;
	}

	private static function get_template_part_manifest(): array {
		$items = array();
		foreach ( get_block_templates( array(), 'wp_template_part' ) as $part ) {
			if ( in_array( $part->slug, self::$export_excluded['parts'], true ) ) {
				continue;
			}
			$items[] = array( 'name' => $part->slug, 'title' => $part->title ?: $part->slug, 'area' => $part->area ?: 'uncategorized' );
		}
		return $items;
	}

	private static function get_editable_pattern_manifest( array $theme_patterns ): array {
		$items = array();
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
			$sync_status = get_post_meta( $post->ID, 'wp_pattern_sync_status', true );
			$categories  = array();
			if ( taxonomy_exists( 'wp_pattern_category' ) ) {
				$terms = wp_get_object_terms( $post->ID, 'wp_pattern_category', array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $terms ) ) {
					$categories = array_values( array_map( 'sanitize_text_field', $terms ) );
				}
			}

			$items[] = array(
				'sourceId'         => (int) $post->ID,
				'sourceKey'        => get_stylesheet() . ':' . $post->ID,
				'title'            => $post->post_title ?: sprintf( 'Pattern %d', $post->ID ),
				'slug'             => $post->post_name,
				'postStatus'       => $post->post_status,
				'syncStatus'       => 'unsynced' === $sync_status ? 'unsynced' : 'synced',
				'content'          => $post->post_content,
				'categories'       => $categories,
				'themePatternSlug' => $theme_patterns[ $post->ID ]['slug'] ?? '',
			);
		}

		return $items;
	}

	private static function get_template_manifest( array $excluded_slugs = array() ): array {
		$items = array();
		foreach ( get_block_templates() as $template ) {
			if ( in_array( $template->slug, $excluded_slugs, true ) ) {
				continue;
			}
			$items[] = array(
				'slug'      => $template->slug,
				'title'     => $template->title ?: $template->slug,
				'source'    => $template->source,
				'isCustom'  => (bool) $template->is_custom,
				'postTypes' => $template->post_types ?: array(),
			);
		}
		return $items;
	}

	private static function merge_template_parts( array $existing, array $parts ): array {
		$merged = array();
		foreach ( $existing as $part ) {
			if ( ! empty( $part['name'] ) ) {
				$merged[ $part['name'] ] = $part;
			}
		}
		foreach ( $parts as $part ) {
			$merged[ $part['name'] ] = $part;
		}
		return array_values( $merged );
	}

	private static function scan_content( string $entry, string $content, array &$checks ): void {
		if ( preg_match( '#https?://[^\s"\']+\.local(?:[/:][^\s"\']*)?#i', $content ) ) {
			$checks[] = $entry . ': local development URL remains.';
		}
		if ( str_contains( $content, '<!-- wp:block ' ) ) {
			$checks[] = $entry . ': unresolved core/block reference remains.';
		}
	}

	private static function safe_header_value( string $value ): string {
		return trim( str_replace( array( "\r", "\n", '*/' ), array( ' ', ' ', '* /' ), wp_strip_all_tags( $value ) ) );
	}

	public static function maybe_refresh_pattern_cache(): void {
		$manifest_path = trailingslashit( get_stylesheet_directory() ) . self::MANIFEST_FILE;
		if ( ! is_readable( $manifest_path ) ) {
			return;
		}

		$signature = md5_file( $manifest_path );
		if ( ! is_string( $signature ) || '' === $signature ) {
			return;
		}

		$option_name = 'itmar_btm_pattern_manifest_' . md5( get_stylesheet() );
		if ( get_option( $option_name ) === $signature ) {
			return;
		}

		wp_get_theme()->delete_pattern_cache();
		update_option( $option_name, $signature, false );
	}

	private static function get_installed_manifest() {
		$path = trailingslashit( get_stylesheet_directory() ) . self::MANIFEST_FILE;
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'itmar_btm_manifest_missing', __( 'The active theme does not contain block-theme-migration.json. Install and activate a theme created by Distribution Export.', 'block-theme-migration' ) );
		}
		$manifest = json_decode( (string) file_get_contents( $path ), true );
		$schema_version = is_array( $manifest ) ? absint( $manifest['schemaVersion'] ?? 0 ) : 0;
		if ( ! is_array( $manifest ) || ! in_array( $schema_version, array( 2, 3, 4, 5 ), true ) ) {
			return new WP_Error( 'itmar_btm_manifest_invalid', __( 'The active theme migration manifest is missing or unsupported.', 'block-theme-migration' ) );
		}
		$manifest['theme']['installedSlug'] = get_stylesheet();
		$manifest['theme']['slugMismatch']  = ( $manifest['theme']['slug'] ?? '' ) !== get_stylesheet();
		$manifest['assignableTemplates'] = self::sanitize_manifest_templates( $manifest['assignableTemplates'] ?? array() );
		$manifest['editablePatterns']    = self::sanitize_editable_patterns( $manifest['editablePatterns'] ?? array(), $manifest['theme']['slug'] ?? '' );
		$source_home = esc_url_raw( $manifest['sourceSite']['homeUrl'] ?? '' );
		$manifest['sourceSite'] = array( 'homeUrl' => $source_home );
		return $manifest;
	}

	private static function sanitize_manifest_templates( $templates ): array {
		if ( ! is_array( $templates ) ) {
			return array();
		}
		$items = array();
		foreach ( $templates as $template ) {
			if ( ! is_array( $template ) ) {
				continue;
			}
			$slug    = sanitize_title( $template['slug'] ?? '' );
			$allowed = self::sanitize_target_types( $template['allowedTargets'] ?? array() );
			if ( '' === $slug || empty( $allowed ) || self::is_automatically_assigned_template( $slug ) ) {
				continue;
			}
			$items[ $slug ] = array( 'slug' => $slug, 'title' => sanitize_text_field( $template['title'] ?? $slug ), 'allowedTargets' => $allowed );
		}
		return array_values( $items );
	}

	private static function sanitize_editable_patterns( $patterns, string $theme_slug ): array {
		if ( ! is_array( $patterns ) ) {
			return array();
		}

		$items = array();
		foreach ( $patterns as $pattern ) {
			if ( ! is_array( $pattern ) ) {
				continue;
			}
			$source_id = absint( $pattern['sourceId'] ?? 0 );
			$content   = $pattern['content'] ?? null;
			if ( 0 === $source_id || ! is_string( $content ) ) {
				continue;
			}

			$source_key = sanitize_text_field( $pattern['sourceKey'] ?? '' );
			if ( '' === $source_key ) {
				$source_key = sanitize_key( $theme_slug ) . ':' . $source_id;
			}
			$theme_pattern_slug = sanitize_text_field( $pattern['themePatternSlug'] ?? '' );
			if ( '' !== $theme_pattern_slug && ! preg_match( '/^[A-Za-z0-9\/_-]+$/', $theme_pattern_slug ) ) {
				$theme_pattern_slug = '';
			}
			$post_status = sanitize_key( $pattern['postStatus'] ?? 'publish' );
			if ( ! in_array( $post_status, array( 'publish', 'draft', 'private' ), true ) ) {
				$post_status = 'publish';
			}

			$items[ $source_key ] = array(
				'sourceId'         => $source_id,
				'sourceKey'        => $source_key,
				'title'            => sanitize_text_field( $pattern['title'] ?? sprintf( 'Pattern %d', $source_id ) ),
				'slug'             => sanitize_title( $pattern['slug'] ?? '' ),
				'postStatus'       => $post_status,
				'syncStatus'       => 'unsynced' === ( $pattern['syncStatus'] ?? '' ) ? 'unsynced' : 'synced',
				'content'          => $content,
				'categories'       => array_values( array_filter( array_map( 'sanitize_text_field', is_array( $pattern['categories'] ?? null ) ? $pattern['categories'] : array() ) ) ),
				'themePatternSlug' => $theme_pattern_slug,
			);
		}

		return array_values( $items );
	}

	private static function get_imported_pattern_ids( array $patterns ): array {
		$source_keys = array_values( array_filter( wp_list_pluck( $patterns, 'sourceKey' ) ) );
		if ( empty( $source_keys ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'     => self::PATTERN_SOURCE_META,
						'value'   => $source_keys,
						'compare' => 'IN',
					),
				),
			)
		);

		$ids = array();
		foreach ( $posts as $post ) {
			$source_key = get_post_meta( $post->ID, self::PATTERN_SOURCE_META, true );
			if ( in_array( $source_key, $source_keys, true ) ) {
				$ids[ $source_key ] = (int) $post->ID;
			}
		}
		return $ids;
	}

	public static function handle_pattern_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import patterns.', 'block-theme-migration' ) );
		}
		check_admin_referer( 'itmar_btm_import_patterns' );

		$manifest = self::get_installed_manifest();
		if ( is_wp_error( $manifest ) ) {
			wp_die( esc_html( $manifest->get_error_message() ) );
		}
		$patterns  = $manifest['editablePatterns'] ?? array();
		$apply     = isset( $_POST['mode'] ) && 'apply' === $_POST['mode'];
		$overwrite = ! empty( $_POST['overwrite_templates'] );
		$items     = array();
		$has_error = false;

		if ( empty( $patterns ) ) {
			$items[] = __( 'The package does not contain editable patterns.', 'block-theme-migration' );
		} elseif ( ! $apply ) {
			$existing = self::get_imported_pattern_ids( $patterns );
			foreach ( $patterns as $pattern ) {
				$items[] = sprintf(
					'%1$s — %2$s (%3$s)',
					$pattern['title'],
					isset( $existing[ $pattern['sourceKey'] ] ) ? __( 'update', 'block-theme-migration' ) : __( 'create', 'block-theme-migration' ),
					$pattern['syncStatus']
				);
			}
			$template_count = self::count_templates_with_pattern_references( $patterns );
			$items[] = sprintf( __( '%d theme templates contain synced pattern references to reconnect.', 'block-theme-migration' ), $template_count );
		} else {
			$import = self::import_editable_patterns( $patterns );
			$items = array_merge( $items, $import['items'] );
			$has_error = $import['hasError'];
			if ( ! empty( $import['themeSlugMap'] ) ) {
				$reconnect = self::reconnect_theme_templates( $import['themeSlugMap'], $overwrite, $manifest['theme']['slug'] ?? '' );
				$items = array_merge( $items, $reconnect['items'] );
				$has_error = $has_error || $reconnect['hasError'];
			}
		}

		set_transient(
			self::RESULT_TRANSIENT . get_current_user_id(),
			array(
				'title'     => $apply ? __( 'Pattern import result', 'block-theme-migration' ) : __( 'Pattern import preview', 'block-theme-migration' ),
				'items'     => $items,
				'has_error' => $has_error,
			),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( add_query_arg( 'view', 'mapping', admin_url( 'tools.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	private static function import_editable_patterns( array $patterns ): array {
		$existing       = self::get_imported_pattern_ids( $patterns );
		$source_id_map  = array();
		$source_key_map = array();
		$items          = array();
		$has_error      = false;

		foreach ( $patterns as $pattern ) {
			$postarr = array(
				'post_title'   => $pattern['title'],
				'post_name'    => $pattern['slug'],
				'post_content' => $pattern['content'],
				'post_status'  => $pattern['postStatus'],
				'post_type'    => 'wp_block',
				'post_author'  => get_current_user_id(),
			);
			$is_update = isset( $existing[ $pattern['sourceKey'] ] );
			if ( $is_update ) {
				$postarr['ID'] = $existing[ $pattern['sourceKey'] ];
			}

			$post_id = wp_insert_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $post_id ) ) {
				$has_error = true;
				$items[] = $pattern['title'] . ': ' . $post_id->get_error_message();
				continue;
			}

			update_post_meta( $post_id, self::PATTERN_SOURCE_META, $pattern['sourceKey'] );
			if ( 'unsynced' === $pattern['syncStatus'] ) {
				update_post_meta( $post_id, 'wp_pattern_sync_status', 'unsynced' );
			} else {
				delete_post_meta( $post_id, 'wp_pattern_sync_status' );
			}
			if ( taxonomy_exists( 'wp_pattern_category' ) && ! empty( $pattern['categories'] ) ) {
				$terms = wp_set_object_terms( $post_id, $pattern['categories'], 'wp_pattern_category', false );
				if ( is_wp_error( $terms ) ) {
					$has_error = true;
					$items[] = $pattern['title'] . ': ' . $terms->get_error_message();
				}
			}

			$source_id_map[ $pattern['sourceId'] ]   = (int) $post_id;
			$source_key_map[ $pattern['sourceKey'] ] = (int) $post_id;
			$items[] = sprintf( '%1$s — %2$s #%3$d', $pattern['title'], $is_update ? __( 'updated', 'block-theme-migration' ) : __( 'created', 'block-theme-migration' ), $post_id );
		}

		$theme_slug_map = array();
		foreach ( $patterns as $pattern ) {
			$post_id = $source_key_map[ $pattern['sourceKey'] ] ?? 0;
			if ( ! $post_id ) {
				continue;
			}
			$rewritten = self::replace_pattern_references( $pattern['content'], $source_id_map, array() );
			$current   = get_post( $post_id );
			if ( $current && $current->post_content !== $rewritten ) {
				$result = wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $rewritten ) ), true );
				if ( is_wp_error( $result ) ) {
					$has_error = true;
					$items[] = $pattern['title'] . ': ' . $result->get_error_message();
				}
			}
			if ( 'synced' === $pattern['syncStatus'] && '' !== $pattern['themePatternSlug'] ) {
				$theme_slug_map[ $pattern['themePatternSlug'] ] = $post_id;
			}
		}

		return array(
			'items'        => $items,
			'hasError'     => $has_error,
			'sourceIdMap'  => $source_id_map,
			'themeSlugMap' => $theme_slug_map,
		);
	}

	private static function count_templates_with_pattern_references( array $patterns ): int {
		$slugs = array_values( array_filter( wp_list_pluck( $patterns, 'themePatternSlug' ) ) );
		$count = 0;
		foreach ( get_block_templates() as $template ) {
			foreach ( $slugs as $slug ) {
				if ( str_contains( $template->content, '"slug":"' . $slug . '"' ) || str_contains( $template->content, '"slug": "' . $slug . '"' ) ) {
					++$count;
					break;
				}
			}
		}
		return $count;
	}

	private static function reconnect_theme_templates( array $theme_slug_map, bool $overwrite, string $package_theme = '' ): array {
		$items     = array();
		$has_error = false;
		foreach ( get_block_templates() as $template ) {
			$rewritten = self::replace_pattern_references( $template->content, array(), $theme_slug_map );
			if ( $rewritten === $template->content ) {
				continue;
			}
			$result = self::save_template_override(
				$template->slug,
				$template->title ?: $template->slug,
				$rewritten,
				$overwrite,
				array(
					'source'  => 'theme-template:' . $template->slug,
					'target'  => 'template|' . $template->slug,
					'package' => $package_theme,
				)
			);
			if ( is_wp_error( $result ) ) {
				$has_error = true;
				$items[] = $template->slug . ': ' . $result->get_error_message();
			} else {
				$items[] = sprintf( __( '%s — synced pattern references reconnected.', 'block-theme-migration' ), $template->slug );
			}
		}
		return array( 'items' => $items, 'hasError' => $has_error );
	}

	private static function replace_pattern_references( string $content, array $source_id_map, array $theme_slug_map ): string {
		$has_reference = false;
		foreach ( array_keys( $source_id_map ) as $source_id ) {
			if ( preg_match( '/"ref"\s*:\s*' . absint( $source_id ) . '\b/', $content ) ) {
				$has_reference = true;
				break;
			}
		}
		if ( ! $has_reference ) {
			foreach ( array_keys( $theme_slug_map ) as $slug ) {
				if ( '' !== $slug && str_contains( $content, $slug ) ) {
					$has_reference = true;
					break;
				}
			}
		}
		if ( ! $has_reference ) {
			return $content;
		}

		$blocks = self::replace_pattern_references_in_blocks( parse_blocks( $content ), $source_id_map, $theme_slug_map );
		return self::stable_serialize_blocks( $blocks );
	}

	private static function replace_pattern_references_in_blocks( array $blocks, array $source_id_map, array $theme_slug_map ): array {
		foreach ( $blocks as &$block ) {
			if ( 'core/block' === ( $block['blockName'] ?? null ) ) {
				$source_id = absint( $block['attrs']['ref'] ?? 0 );
				if ( isset( $source_id_map[ $source_id ] ) ) {
					$block['attrs']['ref'] = $source_id_map[ $source_id ];
				}
			} elseif ( 'core/pattern' === ( $block['blockName'] ?? null ) ) {
				$slug = $block['attrs']['slug'] ?? '';
				if ( isset( $theme_slug_map[ $slug ] ) ) {
					$block = array(
						'blockName'    => 'core/block',
						'attrs'        => array( 'ref' => $theme_slug_map[ $slug ] ),
						'innerBlocks'  => array(),
						'innerHTML'    => '',
						'innerContent' => array(),
					);
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::replace_pattern_references_in_blocks( $block['innerBlocks'], $source_id_map, $theme_slug_map );
			}
		}
		unset( $block );
		return $blocks;
	}

	private static function get_theme_pattern_id_map( array $patterns ): array {
		$imported = self::get_imported_pattern_ids( $patterns );
		$map      = array();
		foreach ( $patterns as $pattern ) {
			if ( 'synced' !== $pattern['syncStatus'] || '' === $pattern['themePatternSlug'] ) {
				continue;
			}
			if ( isset( $imported[ $pattern['sourceKey'] ] ) ) {
				$map[ $pattern['themePatternSlug'] ] = $imported[ $pattern['sourceKey'] ];
			}
		}
		return $map;
	}

	public static function handle_mapping(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to map templates.', 'block-theme-migration' ) );
		}
		check_admin_referer( 'itmar_btm_map' );
		$manifest = self::get_installed_manifest();
		if ( is_wp_error( $manifest ) ) {
			wp_die( esc_html( $manifest->get_error_message() ) );
		}

		$allowed_sources = array();
		foreach ( $manifest['assignableTemplates'] as $template ) {
			$allowed_sources[ $template['slug'] ] = $template['allowedTargets'];
		}
		$mode        = isset( $_POST['mode'] ) && 'apply' === $_POST['mode'] ? 'apply' : 'preview';
		$overwrite   = ! empty( $_POST['overwrite_existing'] );
		$raw_mapping = isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? wp_unslash( $_POST['mapping'] ) : array();
		$raw_deletions = isset( $_POST['delete_source'] ) && is_array( $_POST['delete_source'] ) ? wp_unslash( $_POST['delete_source'] ) : array();
		$mapping         = array();
		$items           = array();
		$has_error       = false;

		foreach ( $raw_mapping as $raw_source => $raw_target ) {
			$source = sanitize_title( $raw_source );
			$target = sanitize_text_field( $raw_target );
			if ( '' === $source || '' === $target ) {
				continue;
			}
			$type = sanitize_key( explode( '|', $target, 2 )[0] ?? '' );
			if ( ! isset( $allowed_sources[ $source ] ) || ! in_array( $type, $allowed_sources[ $source ], true ) ) {
				$has_error = true;
				$items[]   = $source . ': ' . __( 'This destination type is not allowed by the theme package.', 'block-theme-migration' );
				continue;
			}
			$mapping[ $source ] = $target;
		}
		// 同じ宛先に、2つ以上の元が割り当てられたとき、最初の1件だけを使う（あとのものが、先のものを黙って上書きしない）
		$mapping = self::resolve_mapping_collisions( $mapping, $items, $has_error );
		$pattern_refs = self::get_theme_pattern_id_map( $manifest['editablePatterns'] ?? array() );
		self::save_mapping( $mapping );
		$deleted_sources = self::sanitize_requested_source_deletions( $raw_deletions, $mapping );
		self::save_source_deletions( $deleted_sources );
		foreach ( $mapping as $source_slug => $target_spec ) {
			$result = self::map_one_template( $source_slug, $target_spec, 'apply' === $mode, $overwrite, $pattern_refs, $manifest['theme']['slug'] ?? '' );
			if ( is_wp_error( $result ) ) {
				$has_error = true;
				$items[]   = $source_slug . ': ' . $result->get_error_message();
			} else {
				$items[] = $result;
				if ( in_array( $source_slug, $deleted_sources, true ) ) {
					$items[] = sprintf( __( 'templates/%s.html — will be removed from the next Distribution Export ZIP.', 'block-theme-migration' ), $source_slug );
				}
			}
		}
		$shared = self::process_shared_mapping_content(
			$manifest,
			$mapping,
			'apply' === $mode,
			$overwrite,
			$pattern_refs
		);
		$items     = array_merge( $items, $shared['items'] );
		$has_error = $has_error || $shared['hasError'];
		if ( empty( $items ) ) {
			$items[] = __( 'No mappings were selected.', 'block-theme-migration' );
		}
		set_transient(
			self::RESULT_TRANSIENT . get_current_user_id(),
			array( 'title' => 'apply' === $mode ? __( 'Mapping result', 'block-theme-migration' ) : __( 'Mapping preview', 'block-theme-migration' ), 'items' => $items, 'has_error' => $has_error ),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( add_query_arg( 'view', 'mapping', admin_url( 'tools.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	private static function get_mapping_option_name(): string {
		return self::MAP_OPTION . md5( get_stylesheet() );
	}

	private static function get_saved_mapping(): array {
		$saved = get_option( self::get_mapping_option_name(), null );
		if ( is_array( $saved ) ) {
			return $saved;
		}

		// Preserve a recent selection made before mapping storage became persistent.
		$legacy_key = self::MAP_TRANSIENT . get_current_user_id();
		$legacy     = get_transient( $legacy_key );
		if ( is_array( $legacy ) ) {
			self::save_mapping( $legacy );
			delete_transient( $legacy_key );
			return $legacy;
		}

		return array();
	}

	private static function save_mapping( array $mapping ): void {
		update_option( self::get_mapping_option_name(), $mapping, false );
	}

	private static function get_source_deletion_option_name(): string {
		return self::DELETE_OPTION . md5( get_stylesheet() );
	}

	private static function get_saved_source_deletions(): array {
		return self::sanitize_source_deletions( get_option( self::get_source_deletion_option_name(), array() ) );
	}

	private static function save_source_deletions( array $source_slugs ): void {
		update_option( self::get_source_deletion_option_name(), self::sanitize_source_deletions( $source_slugs ), false );
	}

	private static function sanitize_source_deletions( $source_slugs ): array {
		if ( ! is_array( $source_slugs ) ) {
			return array();
		}
		$source_slugs = array_values( array_unique( array_filter( array_map( 'sanitize_title', $source_slugs ) ) ) );
		return array_values(
			array_filter(
				$source_slugs,
				static fn( string $slug ): bool => ! self::is_automatically_assigned_template( $slug )
			)
		);
	}

	private static function sanitize_requested_source_deletions( array $requested, array $mapping ): array {
		$deleted = array();
		foreach ( array_keys( $requested ) as $raw_source ) {
			$source = sanitize_title( $raw_source );
			if ( '' === $source || ! isset( $mapping[ $source ] ) || self::is_automatically_assigned_template( $source ) ) {
				continue;
			}
			$destination = self::resolve_mapping_target( $mapping[ $source ] );
			if ( is_wp_error( $destination ) || $source === $destination['templateSlug'] ) {
				continue;
			}
			$deleted[] = $source;
		}
		return self::sanitize_source_deletions( $deleted );
	}

	/**
	 * 同じ宛先（作られるテンプレートの名前が同じ）に、2つ以上の元が割り当てられたとき、最初の1件だけを残す。
	 * （そのままだと、あとのものが、先のものを黙って上書きする。たとえば、作業動画のテンプレートを、作品の宛先に割り当てると、
	 * 作品のテンプレートが、作業動画の中身に置き換わる）
	 * 宛先と同じ名前の元（single-works → single-works）が先に場所を取り、そのあとは、並びの順。
	 *
	 * @param array<string,string> $mapping   元の slug => 宛先（type|値）
	 * @param array                $items     画面に出す結果（参照渡し）
	 * @param bool                 $has_error エラーの有無（参照渡し）
	 * @return array<string,string> 衝突したものを除いた割り当て
	 */
	public static function resolve_mapping_collisions( array $mapping, array &$items, bool &$has_error ): array {
		$slug_of = array();
		foreach ( $mapping as $source => $spec ) {
			$destination = self::resolve_mapping_target( $spec );
			if ( ! is_wp_error( $destination ) ) {
				$slug_of[ $source ] = (string) $destination['templateSlug'];
			}
		}
		$claimed = array();
		foreach ( array( true, false ) as $same_name_pass ) {
			foreach ( $mapping as $source => $spec ) {
				if ( ! isset( $slug_of[ $source ] ) || ( ( $slug_of[ $source ] === $source ) !== $same_name_pass ) ) {
					continue;
				}
				$key = $slug_of[ $source ];
				if ( ! isset( $claimed[ $key ] ) ) {
					$claimed[ $key ] = $source;
					continue;
				}
				unset( $mapping[ $source ] );
				$has_error = true;
				$items[]   = sprintf(
					/* translators: 1: template slug that was not applied, 2: template slug that is applied, 3: the shared destination template name. */
					__( '%1$s: "%2$s" is already mapped to the same destination ("%3$s"), so this one was not applied. Choose a different destination, or "Do not map".', 'block-theme-migration' ),
					$source,
					$claimed[ $key ],
					$key
				);
			}
		}
		return $mapping;
	}

	/**
	 * そのテンプレートが、テーマの theme.json の customTemplates に、固定ページ用として登録されているか。
	 * 登録されていれば、ページ編集画面の「テンプレート」で選べるので、ページのテンプレートの指定に、そのまま使える。
	 */
	public static function is_page_custom_template( string $slug ): bool {
		$custom = WP_Theme_JSON_Resolver::get_theme_data()->get_custom_templates();
		if ( ! is_array( $custom ) || empty( $custom[ $slug ] ) ) {
			return false;
		}
		$post_types = $custom[ $slug ]['postTypes'] ?? array( 'page' );
		return in_array( 'page', (array) $post_types, true );
	}

	private static function map_one_template( string $source_slug, string $target_spec, bool $apply, bool $overwrite, array $pattern_refs = array(), string $package_theme = '' ) {
		$source = function_exists( 'get_block_file_template' ) ? get_block_file_template( get_stylesheet() . '//' . $source_slug, 'wp_template' ) : null;
		if ( ! $source ) {
			$source = get_block_template( get_stylesheet() . '//' . $source_slug, 'wp_template' );
		}
		if ( ! $source ) {
			return new WP_Error( 'itmar_btm_source_missing', __( 'Source template was not found in the active theme.', 'block-theme-migration' ) );
		}
		$destination = self::resolve_mapping_target( $target_spec );
		if ( is_wp_error( $destination ) ) {
			return $destination;
		}
		$type         = $destination['type'];
		$target_slug  = $destination['templateSlug'];
		$target_label = $destination['label'];
		$title        = $destination['title'];
		$page         = $destination['page'] ?? null;

		// 固定ページへの割り当ては、テーマの customTemplates に、そのテンプレートがあれば、コピーを作らず、ページのテンプレートの指定を、そのテンプレートにする。
		// コピーは、テーマを更新しても、古いまま残る（データベースが優先される）。直接指定なら、テーマのファイルの更新が、そのまま反映される。
		if ( 'page' === $type && $page instanceof WP_Post && self::is_page_custom_template( $source_slug ) ) {
			$direct = sprintf( '%1$s -> %2$s', $source_slug, $target_label ) . ' — ' . __( 'the page will use the theme template directly (no copy is made, so theme updates are reflected).', 'block-theme-migration' );
			$old_copy = get_block_template( get_stylesheet() . '//' . $target_slug, 'wp_template' );
			if ( $old_copy && ! empty( $old_copy->wp_id ) && self::template_matches_mapping( (int) $old_copy->wp_id, $source_slug, $target_spec ) ) {
				$direct .= ' — ' . sprintf(
					/* translators: %s: template slug. */
					__( 'An older copy named "%s" remains in the list below. It is not used while the page names the theme template; move it to Trash.', 'block-theme-migration' ),
					$target_slug
				);
			}
			if ( ! $apply ) {
				return $direct;
			}
			update_post_meta( $page->ID, '_wp_page_template', $source_slug );
			return $direct . ' — ' . __( 'applied.', 'block-theme-migration' );
		}

		$source_content  = self::replace_pattern_references( $source->content, array(), $pattern_refs );
		$refs_rewritten  = $source_content !== $source->content;
		$message         = sprintf( '%1$s -> %2$s', $source_slug, $target_label );
		if ( $refs_rewritten ) {
			$message .= ' — ' . __( 'synced pattern references will be reconnected.', 'block-theme-migration' );
		}
		$existing_target = get_block_template( get_stylesheet() . '//' . $target_slug, 'wp_template' );
		$managed_same = $existing_target && ! empty( $existing_target->wp_id ) && self::template_matches_mapping( (int) $existing_target->wp_id, $source_slug, $target_spec );
		if ( $existing_target && ! empty( $existing_target->wp_id ) ) {
			$message .= ' — ' . ( $managed_same ? __( 'the existing Theme Migration template will be updated.', 'block-theme-migration' ) : __( 'an existing destination customization was found.', 'block-theme-migration' ) );
		}
		$stale = self::get_other_managed_targets( $source_slug, $target_spec );
		if ( ! empty( $stale ) ) {
			$message .= ' — ' . sprintf( __( '%d previous destination mapping(s) remain; review the managed template list.', 'block-theme-migration' ), count( $stale ) );
		}
		if ( ! $apply ) {
			return $message;
		}
		if ( $source_slug === $target_slug && ! $refs_rewritten ) {
			return $message . ' — ' . __( 'already matches the WordPress template hierarchy.', 'block-theme-migration' );
		}
		$saved = self::save_template_override(
			$target_slug,
			$title,
			$source_content,
			$overwrite,
			array( 'source' => $source_slug, 'target' => $target_spec, 'package' => $package_theme )
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		if ( 'page' === $type ) {
			update_post_meta( $page->ID, '_wp_page_template', 'default' );
		}
		return $message . ' — ' . __( 'applied.', 'block-theme-migration' );
	}

	private static function process_shared_mapping_content( array $manifest, array $mapping, bool $apply, bool $overwrite, array $pattern_refs ): array {
		$items     = array();
		$has_error = false;

		foreach ( get_block_templates( array(), 'wp_template_part' ) as $part ) {
			$file_part = function_exists( 'get_block_file_template' ) ? get_block_file_template( get_stylesheet() . '//' . $part->slug, 'wp_template_part' ) : null;
			$source    = $file_part ?: $part;
			$content   = self::replace_pattern_references( $source->content, array(), $pattern_refs );
			if ( $content === $source->content ) {
				continue;
			}
			$items[] = sprintf( __( 'Template part "%1$s" — synced pattern reference(s) %2$s.', 'block-theme-migration' ), $source->title ?: $source->slug, $apply ? __( 'reconnected', 'block-theme-migration' ) : __( 'will be reconnected', 'block-theme-migration' ) );
			if ( $apply ) {
				$result = self::save_template_part_override(
					$source->slug,
					$source->title ?: $source->slug,
					$content,
					$source->area ?? 'uncategorized',
					$overwrite,
					array(
						'source'  => 'theme-part:' . $source->slug,
						'target'  => 'part|' . $source->slug,
						'package' => $manifest['theme']['slug'] ?? '',
					)
				);
				if ( is_wp_error( $result ) ) {
					$has_error = true;
					$items[]   = $source->slug . ': ' . $result->get_error_message();
				}
			}
		}

		foreach ( $manifest['templates'] ?? array() as $template_data ) {
			$slug = sanitize_title( $template_data['slug'] ?? '' );
			if ( '' === $slug || isset( $mapping[ $slug ] ) || ! self::is_automatically_assigned_template( $slug ) ) {
				continue;
			}
			$source = function_exists( 'get_block_file_template' ) ? get_block_file_template( get_stylesheet() . '//' . $slug, 'wp_template' ) : null;
			if ( ! $source ) {
				continue;
			}
			$content = self::replace_pattern_references( $source->content, array(), $pattern_refs );
			if ( $content === $source->content ) {
				continue;
			}
			$items[] = sprintf( __( 'Automatic template "%1$s" — synced pattern reference(s) %2$s.', 'block-theme-migration' ), $source->title ?: $slug, $apply ? __( 'reconnected', 'block-theme-migration' ) : __( 'will be reconnected', 'block-theme-migration' ) );
			if ( $apply ) {
				$result = self::save_template_override(
					$slug,
					$source->title ?: $slug,
					$content,
					$overwrite,
					array(
						'source'  => 'theme-template:' . $slug,
						'target'  => 'template|' . $slug,
						'package' => $manifest['theme']['slug'] ?? '',
					)
				);
				if ( is_wp_error( $result ) ) {
					$has_error = true;
					$items[]   = $slug . ': ' . $result->get_error_message();
				}
			}
		}

		return array( 'items' => $items, 'hasError' => $has_error );
	}

	public static function save_template_override( string $slug, string $title, string $content, bool $overwrite, array $migration = array() ) {
		$existing = get_block_template( get_stylesheet() . '//' . $slug, 'wp_template' );
		$existing_id = $existing && ! empty( $existing->wp_id ) ? (int) $existing->wp_id : 0;
		$managed_same = $existing_id && ! empty( $migration ) && self::template_matches_mapping( $existing_id, $migration['source'] ?? '', $migration['target'] ?? '' );
		if ( ! $existing_id && ! empty( $migration ) ) {
			$existing_id = self::find_managed_template_id( $migration['source'] ?? '', $migration['target'] ?? '' );
			$managed_same = $existing_id > 0;
		}
		if ( $existing_id && ! $overwrite && ! $managed_same ) {
			return new WP_Error( 'itmar_btm_destination_exists', __( 'An existing destination customization was found. Enable overwrite only after reviewing the preview.', 'block-theme-migration' ) );
		}
		$postarr = array(
			'post_name'    => $slug,
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_type'    => 'wp_template',
			'post_author'  => get_current_user_id(),
		);
		if ( $existing_id ) {
			$postarr['ID'] = $existing_id;
		}
		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$terms = wp_set_object_terms( $post_id, get_stylesheet(), 'wp_theme', false );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		if ( ! empty( $migration ) ) {
			update_post_meta( $post_id, self::TEMPLATE_SOURCE_META, sanitize_title( $migration['source'] ?? '' ) );
			update_post_meta( $post_id, self::TEMPLATE_TARGET_META, sanitize_text_field( $migration['target'] ?? '' ) );
			update_post_meta( $post_id, self::TEMPLATE_THEME_META, get_stylesheet() );
			update_post_meta( $post_id, self::TEMPLATE_PACKAGE_META, sanitize_key( $migration['package'] ?? '' ) );
		}
		clean_post_cache( $post_id );
		WP_Theme_JSON_Resolver::clean_cached_data();
		return $post_id;
	}

	public static function save_template_part_override( string $slug, string $title, string $content, string $area, bool $overwrite, array $migration = array() ) {
		$existing     = get_block_template( get_stylesheet() . '//' . $slug, 'wp_template_part' );
		$existing_id  = $existing && ! empty( $existing->wp_id ) ? (int) $existing->wp_id : 0;
		$managed_same = $existing_id && ! empty( $migration ) && self::template_matches_mapping( $existing_id, $migration['source'] ?? '', $migration['target'] ?? '' );
		if ( ! $existing_id && ! empty( $migration ) ) {
			$existing_id  = self::find_managed_template_id( $migration['source'] ?? '', $migration['target'] ?? '', 'wp_template_part' );
			$managed_same = $existing_id > 0;
		}
		if ( $existing_id && ! $overwrite && ! $managed_same ) {
			return new WP_Error( 'itmar_btm_destination_part_exists', __( 'An existing destination template-part customization was found. Enable overwrite only after reviewing the preview.', 'block-theme-migration' ) );
		}
		$postarr = array(
			'post_name'    => $slug,
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_type'    => 'wp_template_part',
			'post_author'  => get_current_user_id(),
		);
		if ( $existing_id ) {
			$postarr['ID'] = $existing_id;
		}
		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$theme_terms = wp_set_object_terms( $post_id, get_stylesheet(), 'wp_theme', false );
		if ( is_wp_error( $theme_terms ) ) {
			return $theme_terms;
		}
		$area_terms = wp_set_object_terms( $post_id, sanitize_key( $area ) ?: 'uncategorized', 'wp_template_part_area', false );
		if ( is_wp_error( $area_terms ) ) {
			return $area_terms;
		}
		if ( ! empty( $migration ) ) {
			update_post_meta( $post_id, self::TEMPLATE_SOURCE_META, sanitize_title( $migration['source'] ?? '' ) );
			update_post_meta( $post_id, self::TEMPLATE_TARGET_META, sanitize_text_field( $migration['target'] ?? '' ) );
			update_post_meta( $post_id, self::TEMPLATE_THEME_META, get_stylesheet() );
			update_post_meta( $post_id, self::TEMPLATE_PACKAGE_META, sanitize_key( $migration['package'] ?? '' ) );
		}
		clean_post_cache( $post_id );
		WP_Theme_JSON_Resolver::clean_cached_data();
		return $post_id;
	}

	private static function resolve_mapping_target( string $target_spec ) {
		$parts = explode( '|', $target_spec, 2 );
		if ( 2 !== count( $parts ) ) {
			return new WP_Error( 'itmar_btm_target_invalid', __( 'Destination is invalid.', 'block-theme-migration' ) );
		}
		$type   = sanitize_key( $parts[0] );
		$target = sanitize_text_field( $parts[1] );
		if ( 'page' === $type ) {
			$page = get_post( absint( $target ) );
			if ( ! $page || 'page' !== $page->post_type || '' === $page->post_name ) {
				return new WP_Error( 'itmar_btm_page_invalid', __( 'Destination page is invalid or has no slug.', 'block-theme-migration' ) );
			}
			return array(
				'type'         => $type,
				'templateSlug' => 'page-' . $page->post_name,
				'label'        => sprintf( '%s (page-%s)', $page->post_title, $page->post_name ),
				'title'        => sprintf( __( 'Page: %s', 'block-theme-migration' ), $page->post_title ),
				'page'         => $page,
			);
		}
		if ( 'single' !== $type && 'archive' !== $type ) {
			return new WP_Error( 'itmar_btm_type_invalid', __( 'Destination type is unsupported.', 'block-theme-migration' ) );
		}
		$post_type = get_post_type_object( sanitize_key( $target ) );
		if ( ! $post_type || ! $post_type->public || in_array( $post_type->name, array( 'attachment', 'page' ), true ) ) {
			return new WP_Error( 'itmar_btm_post_type_invalid', __( 'Destination post type is invalid.', 'block-theme-migration' ) );
		}
		if ( 'archive' === $type && ! $post_type->has_archive ) {
			return new WP_Error( 'itmar_btm_archive_unavailable', __( 'The destination post type does not have an archive.', 'block-theme-migration' ) );
		}
		$template_slug = $type . '-' . $post_type->name;
		return array(
			'type'         => $type,
			'templateSlug' => $template_slug,
			'label'        => $post_type->labels->name . ' (' . $template_slug . ')',
			'title'        => ( 'single' === $type ? $post_type->labels->singular_name : $post_type->labels->name ) . ' (' . $type . ')',
		);
	}

	public static function stable_serialize_blocks( array $blocks ): string {
		$blocks   = self::prepare_blocks_for_migration( $blocks, 'serialization' );
		$blocks   = self::restore_registered_object_attribute_shapes( $blocks );
		$previous = ini_get( 'serialize_precision' );
		$changed  = false !== $previous && '-1' !== (string) $previous && false !== ini_set( 'serialize_precision', '-1' );
		try {
			return serialize_blocks( $blocks );
		} finally {
			if ( $changed ) {
				ini_set( 'serialize_precision', (string) $previous );
			}
		}
	}

	private static function prepare_blocks_for_migration( array $blocks, string $context ): array {
		/**
		 * Lets block providers normalize their own legacy attributes before Theme Migration serializes content.
		 *
		 * @param array  $blocks  Parsed blocks.
		 * @param string $context Current operation: mapped-links or serialization.
		 */
		$prepared = apply_filters( 'itmar_btm_prepare_blocks', $blocks, $context );
		return is_array( $prepared ) ? $prepared : $blocks;
	}

	private static function restore_registered_object_attribute_shapes( array $blocks ): array {
		$registry = WP_Block_Type_Registry::get_instance();
		foreach ( $blocks as &$block ) {
			$block_name = $block['blockName'] ?? null;
			if ( is_string( $block_name ) && ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				$block_type = $registry->get_registered( $block_name );
				if ( $block_type && is_array( $block_type->attributes ) ) {
					foreach ( $block['attrs'] as $key => &$value ) {
						$schema = $block_type->attributes[ $key ] ?? null;
						if ( is_array( $schema ) && 'object' === ( $schema['type'] ?? null ) && is_array( $value ) && array() === $value ) {
							$value = (object) array();
						}
					}
					unset( $value );
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::restore_registered_object_attribute_shapes( $block['innerBlocks'] );
			}
		}
		unset( $block );
		return $blocks;
	}

	public static function stable_wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		$previous = ini_get( 'serialize_precision' );
		$changed  = false !== $previous && '-1' !== (string) $previous && false !== ini_set( 'serialize_precision', '-1' );
		try {
			return wp_json_encode( $value, $flags, $depth );
		} finally {
			if ( $changed ) {
				ini_set( 'serialize_precision', (string) $previous );
			}
		}
	}

	private static function template_matches_mapping( int $post_id, string $source, string $target ): bool {
		return get_post_meta( $post_id, self::TEMPLATE_SOURCE_META, true ) === sanitize_title( $source )
			&& get_post_meta( $post_id, self::TEMPLATE_TARGET_META, true ) === sanitize_text_field( $target )
			&& get_post_meta( $post_id, self::TEMPLATE_THEME_META, true ) === get_stylesheet();
	}

	public static function find_managed_template_id( string $source, string $target, string $post_type = 'wp_template' ): int {
		$posts = get_posts(
			array(
				'post_type'      => in_array( $post_type, array( 'wp_template', 'wp_template_part' ), true ) ? $post_type : 'wp_template',
				'post_status'    => array( 'publish', 'draft', 'private', 'trash' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => self::TEMPLATE_SOURCE_META, 'value' => sanitize_title( $source ) ),
					array( 'key' => self::TEMPLATE_TARGET_META, 'value' => sanitize_text_field( $target ) ),
					array( 'key' => self::TEMPLATE_THEME_META, 'value' => get_stylesheet() ),
				),
			)
		);
		return empty( $posts ) ? 0 : (int) $posts[0];
	}

	private static function get_other_managed_targets( string $source, string $target ): array {
		$posts = self::get_managed_templates( $source, 'wp_template' );
		return array_values(
			array_filter(
				$posts,
				static fn( WP_Post $post ): bool => get_post_meta( $post->ID, self::TEMPLATE_TARGET_META, true ) !== sanitize_text_field( $target )
			)
		);
	}

	public static function get_managed_templates( string $source = '', string $post_type = '' ): array {
		$meta_query = array(
			array( 'key' => self::TEMPLATE_THEME_META, 'value' => get_stylesheet() ),
			array( 'key' => self::TEMPLATE_SOURCE_META, 'compare' => 'EXISTS' ),
		);
		if ( '' !== $source ) {
			$meta_query[] = array( 'key' => self::TEMPLATE_SOURCE_META, 'value' => sanitize_title( $source ) );
		}
		return get_posts(
			array(
				'post_type'      => in_array( $post_type, array( 'wp_template', 'wp_template_part' ), true ) ? $post_type : array( 'wp_template', 'wp_template_part' ),
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => $meta_query,
			)
		);
	}

	public static function handle_template_reset(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to reset templates.', 'block-theme-migration' ) );
		}
		check_admin_referer( 'itmar_btm_reset_templates' );
		$mode      = isset( $_POST['reset_mode'] ) ? sanitize_key( wp_unslash( $_POST['reset_mode'] ) ) : 'selected';
		$requested = isset( $_POST['template_ids'] ) && is_array( $_POST['template_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['template_ids'] ) ) : array();
		$managed   = self::get_managed_templates();
		$allowed   = wp_list_pluck( $managed, 'ID' );
		$targets   = 'all' === $mode ? $allowed : array_values( array_intersect( $allowed, $requested ) );
		$items     = array();
		$has_error = false;
		foreach ( $targets as $post_id ) {
			$post = get_post( $post_id );
			$result = $post ? wp_trash_post( $post_id ) : false;
			if ( ! $result ) {
				$has_error = true;
				$items[] = sprintf( __( 'Template #%d could not be moved to Trash.', 'block-theme-migration' ), $post_id );
			} else {
				$items[] = sprintf( __( '%s was moved to Trash; the theme version can now become active.', 'block-theme-migration' ), $post->post_name );
			}
		}
		if ( empty( $items ) ) {
			$items[] = __( 'No managed templates were selected.', 'block-theme-migration' );
		}
		WP_Theme_JSON_Resolver::clean_cached_data();
		set_transient(
			self::RESULT_TRANSIENT . get_current_user_id(),
			array( 'title' => __( 'Template reset result', 'block-theme-migration' ), 'items' => $items, 'has_error' => $has_error ),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( add_query_arg( 'view', 'mapping', admin_url( 'tools.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}
}

Itmar_Block_Theme_Migration::init();
