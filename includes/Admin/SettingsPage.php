<?php
/**
 * Meta MCP settings screen.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Admin;

use WP\MCP\Abilities\Support\ContentSupport;
use WP\MCP\Abilities\Support\ElementorSupport;
use WP\MCP\Abilities\Support\MenuSupport;
use WP\MCP\Abilities\Support\PolylangSupport;
use WP\MCP\Abilities\Support\Settings;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - SettingsPage
 *
 * Settings → Meta MCP. Lets an administrator choose which post types the MCP
 * content tools may touch, turn writing off entirely, and see at a glance what
 * the plugin has detected — which is otherwise only answerable by calling a tool
 * and reading the response.
 */
final class SettingsPage {

	/**
	 * Menu and page slug.
	 */
	private const SLUG = 'meta-mcp';

	/**
	 * Capability required to view and save the settings.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Hooks the settings screen into the admin.
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_filter(
			'plugin_action_links_meta-mcp/meta-mcp.php',
			array( self::class, 'add_action_link' )
		);
	}

	/**
	 * Registers the settings page under the Settings menu.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_options_page(
			__( 'Meta MCP', 'meta-mcp' ),
			__( 'Meta MCP', 'meta-mcp' ),
			self::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Registers the option and its sanitizer.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			'meta_mcp_settings_group',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 *
	 * @return array The links with Settings prepended.
	 */
	public static function add_action_link( $links ): array {
		$links = is_array( $links ) ? $links : array();

		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ),
				esc_html__( 'Settings', 'meta-mcp' )
			)
		);

		return $links;
	}

	/**
	 * Renders the settings screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'meta-mcp' ) );
		}

		$settings   = Settings::get();
		$expose_all = ! empty( $settings['expose_all_post_types'] );
		$selected   = is_array( $settings['post_types'] ) ? $settings['post_types'] : array();
		$active     = ContentSupport::allowed_post_types();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Meta MCP', 'meta-mcp' ); ?></h1>

			<?php self::render_status(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'meta_mcp_settings_group' ); ?>

				<h2><?php esc_html_e( 'Post types', 'meta-mcp' ); ?></h2>
				<p class="description" style="max-width:46em;">
					<?php esc_html_e( 'Choose which content the MCP tools may read and edit. This controls what is visible to an AI client; WordPress capability checks still apply to every action, so nobody gains permissions they did not already have.', 'meta-mcp' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Coverage', 'meta-mcp' ); ?></th>
						<td>
							<label>
								<input type="checkbox"
									id="meta-mcp-expose-all"
									name="<?php echo esc_attr( Settings::OPTION ); ?>[expose_all_post_types]"
									value="1" <?php checked( $expose_all ); ?> />
								<?php esc_html_e( 'Every post type, including ones registered later', 'meta-mcp' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Recommended. Untick to choose specific post types below.', 'meta-mcp' ); ?>
							</p>
						</td>
					</tr>

					<tr id="meta-mcp-post-type-row" class="<?php echo $expose_all ? 'hidden' : ''; ?>">
						<th scope="row"><?php esc_html_e( 'Enabled post types', 'meta-mcp' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( self::post_type_choices() as $slug => $label ) : ?>
									<label style="display:inline-block;min-width:22em;margin-bottom:.4em;">
										<input type="checkbox"
											name="<?php echo esc_attr( Settings::OPTION ); ?>[post_types][]"
											value="<?php echo esc_attr( $slug ); ?>"
											<?php checked( in_array( $slug, $selected, true ) ); ?> />
										<?php echo esc_html( $label ); ?>
										<code><?php echo esc_html( $slug ); ?></code>
									</label><br />
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Navigation menus', 'meta-mcp' ); ?></th>
						<td>
							<label>
								<input type="checkbox"
									name="<?php echo esc_attr( Settings::OPTION ); ?>[menus_enabled]"
									value="1" <?php checked( ! empty( $settings['menus_enabled'] ) ); ?> />
								<?php esc_html_e( 'Expose the tools for building and rearranging navigation menus', 'meta-mcp' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Menus are site-wide structure rather than content, so they have their own switch. Editing one still requires the "Edit Theme Options" capability, which by default only an administrator has.', 'meta-mcp' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Writing', 'meta-mcp' ); ?></th>
						<td>
							<label>
								<input type="checkbox"
									name="<?php echo esc_attr( Settings::OPTION ); ?>[writes_enabled]"
									value="1" <?php checked( ! empty( $settings['writes_enabled'] ) ); ?> />
								<?php esc_html_e( 'Allow MCP clients to create, edit, publish and delete content', 'meta-mcp' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Untick for a read-only connection. The write tools stay listed but refuse to run, so a client is told plainly rather than failing in a confusing way.', 'meta-mcp' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<p class="description">
				<?php
				printf(
					/* translators: %d: number of active post types */
					esc_html__( 'Currently exposing %d post types to MCP.', 'meta-mcp' ),
					count( $active )
				);
				?>
			</p>
		</div>

		<script>
		( function () {
			var all = document.getElementById( 'meta-mcp-expose-all' );
			var row = document.getElementById( 'meta-mcp-post-type-row' );
			if ( ! all || ! row ) { return; }
			all.addEventListener( 'change', function () {
				row.classList.toggle( 'hidden', all.checked );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Renders the detection summary panel.
	 *
	 * @return void
	 */
	private static function render_status(): void {
		$rows = array(
			__( 'Plugin version', 'meta-mcp' ) => defined( 'META_MCP_VERSION' ) ? constant( 'META_MCP_VERSION' ) : '—',
			__( 'Content endpoint', 'meta-mcp' ) => rest_url( 'mcp/wp-content' ),
			__( 'Default endpoint', 'meta-mcp' ) => rest_url( 'mcp/mcp-adapter-default-server' ),
			__( 'Navigation menus', 'meta-mcp' ) => sprintf(
				/* translators: 1: number of menus, 2: number of registered theme locations */
				__( '%1$d menus, %2$d theme locations', 'meta-mcp' ),
				count( MenuSupport::all_menus() ),
				count( MenuSupport::registered_locations() )
			) . ( MenuSupport::is_block_theme() ? __( ' — block theme, so the theme may use Navigation blocks instead', 'meta-mcp' ) : '' ),
			__( 'Elementor', 'meta-mcp' )        => ElementorSupport::is_active()
				? sprintf(
					/* translators: 1: version, 2: whether Pro is active */
					__( 'Active (%1$s)%2$s', 'meta-mcp' ),
					ElementorSupport::version(),
					ElementorSupport::is_pro_active() ? __( ' with Pro', 'meta-mcp' ) : ''
				)
				: __( 'Not active — the Elementor tools will report this', 'meta-mcp' ),
			__( 'Polylang', 'meta-mcp' )         => PolylangSupport::is_active()
				? sprintf(
					/* translators: 1: version, 2: whether Pro is active, 3: language list */
					__( 'Active (%1$s)%2$s — languages: %3$s', 'meta-mcp' ),
					PolylangSupport::version(),
					PolylangSupport::is_pro() ? __( ' Pro', 'meta-mcp' ) : '',
					implode( ', ', PolylangSupport::language_slugs() )
				)
				: __( 'Not active — the translation tools will report this', 'meta-mcp' ),
		);
		?>
		<div class="card" style="max-width:none;padding:.6em 1.2em;margin-top:1em;">
			<h2 style="margin-top:.6em;"><?php esc_html_e( 'Status', 'meta-mcp' ); ?></h2>
			<table class="widefat striped" style="margin-bottom:1em;">
				<tbody>
				<?php foreach ( $rows as $label => $value ) : ?>
					<tr>
						<td style="width:14em;"><strong><?php echo esc_html( $label ); ?></strong></td>
						<td><?php echo esc_html( (string) $value ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Builds the label list for the post type checkboxes.
	 *
	 * @return array<string, string> Labels keyed by post type slug.
	 */
	private static function post_type_choices(): array {
		$choices = array();

		foreach ( ContentSupport::registered_post_types() as $slug ) {
			$object = get_post_type_object( $slug );

			$choices[ $slug ] = $object && isset( $object->labels->name )
				? (string) $object->labels->name
				: $slug;
		}

		asort( $choices );

		return $choices;
	}
}
