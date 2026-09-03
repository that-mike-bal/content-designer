<?php
/**
 * The style editor on Settings → Launchy Illustrator.
 *
 * Renders below the main settings form (its own form, its own POST handlers)
 * so the Settings API keeps owning the simple fields while styles get the
 * richer editing they need — duplicate, rename, delete, and a media-library
 * picker for reference images.
 *
 * @package Launchy_Illustrator
 */

namespace Launchy_Illustrator\Admin;

use Launchy_Illustrator\Settings;
use Launchy_Illustrator\Style_Presets;
use Launchy_Illustrator\User_Styles;

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI for creating and editing user styles.
 */
class Styles_UI {

	/**
	 * Settings page slug (matches Settings::register_menu()).
	 */
	const PAGE = 'launchy-illustrator-settings';

	/**
	 * Hook up rendering, assets, and form handlers.
	 */
	public function __construct() {
		add_action( 'launchy_illustrator_after_settings_form', array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_launchy_save_style', array( $this, 'handle_save' ) );
		add_action( 'admin_post_launchy_delete_style', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_launchy_duplicate_style', array( $this, 'handle_duplicate' ) );
	}

	/**
	 * The media picker only loads on our settings screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style(
			'launchy-illustrator-admin',
			LAUNCHY_ILLUSTRATOR_URL . 'admin/assets/admin.css',
			array(),
			LAUNCHY_ILLUSTRATOR_VERSION
		);
		wp_enqueue_script(
			'launchy-illustrator-styles',
			LAUNCHY_ILLUSTRATOR_URL . 'admin/assets/styles-admin.js',
			array(),
			LAUNCHY_ILLUSTRATOR_VERSION,
			true
		);
		wp_localize_script(
			'launchy-illustrator-styles',
			'launchyStyles',
			array(
				'title'       => __( 'Choose reference images', 'launchy-illustrator' ),
				'button'      => __( 'Use these references', 'launchy-illustrator' ),
				'confirmText' => __( 'Delete this style? This cannot be undone.', 'launchy-illustrator' ),
			)
		);
	}

	/**
	 * Which style is being edited: ?edit_style=<id>, else the active one if it
	 * is editable, else nothing (the form acts as "create new").
	 *
	 * @return array {id, style}
	 */
	private function editing() {
		$id = isset( $_GET['edit_style'] ) ? sanitize_key( wp_unslash( $_GET['edit_style'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $id ) {
			$current = (string) Settings::get( 'style_preset' );
			$id      = User_Styles::is_custom( $current ) ? $current : '';
		}

		$style = $id ? User_Styles::get( $id ) : null;
		if ( ! $style ) {
			$id    = '';
			$style = array(
				'label'       => '',
				'description' => '',
				'prompt'      => '',
				'guidance'    => '',
				'references'  => array(),
			);
		}

		return array( $id, $style );
	}

	/**
	 * Render the style editor.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		list( $editing_id, $style ) = $this->editing();
		$saved                      = User_Styles::all();
		$current                    = (string) Settings::get( 'style_preset' );
		$presets                    = Style_Presets::all();
		$action                     = esc_url( admin_url( 'admin-post.php' ) );

		$this->notice();
		?>
		<hr class="wp-header-end" />
		<h2><?php esc_html_e( 'Your styles', 'launchy-illustrator' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'A style bundles the art direction, the planner guidance, and the reference images that keep your characters consistent. Duplicate any built-in look to start from it, then edit freely.', 'launchy-illustrator' ); ?>
		</p>

		<?php if ( $saved ) : ?>
			<table class="widefat striped" style="max-width:60em;margin-bottom:1em;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Style', 'launchy-illustrator' ); ?></th>
						<th><?php esc_html_e( 'References', 'launchy-illustrator' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'launchy-illustrator' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $saved as $id => $item ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $item['label'] ); ?></strong>
							<?php if ( $id === $current ) : ?>
								<span class="dashicons dashicons-yes" title="<?php esc_attr_e( 'Currently selected', 'launchy-illustrator' ); ?>"></span>
							<?php endif; ?>
							<?php if ( ! empty( $item['description'] ) ) : ?>
								<br /><span class="description"><?php echo esc_html( $item['description'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo (int) count( (array) ( $item['references'] ?? array() ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'edit_style' => $id ), admin_url( 'options-general.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'launchy-illustrator' ); ?></a>
							|
							<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput ?>" style="display:inline">
								<?php wp_nonce_field( 'launchy_style_' . $id ); ?>
								<input type="hidden" name="action" value="launchy_delete_style" />
								<input type="hidden" name="style_id" value="<?php echo esc_attr( $id ); ?>" />
								<button type="submit" class="button-link delete js-launchy-confirm"><?php esc_html_e( 'Delete', 'launchy-illustrator' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput ?>" style="margin-bottom:2em;">
			<?php wp_nonce_field( 'launchy_duplicate_style' ); ?>
			<input type="hidden" name="action" value="launchy_duplicate_style" />
			<label for="launchy_duplicate_source"><?php esc_html_e( 'Start from an existing look:', 'launchy-illustrator' ); ?></label>
			<select id="launchy_duplicate_source" name="source_id">
				<?php foreach ( $presets as $preset_id => $preset ) : ?>
					<option value="<?php echo esc_attr( $preset_id ); ?>" <?php selected( $current, $preset_id ); ?>>
						<?php echo esc_html( $preset['label'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Duplicate to my styles', 'launchy-illustrator' ); ?></button>
		</form>

		<h3><?php echo $editing_id ? esc_html__( 'Edit style', 'launchy-illustrator' ) : esc_html__( 'New style', 'launchy-illustrator' ); ?></h3>
		<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput ?>">
			<?php wp_nonce_field( 'launchy_style_' . $editing_id ); ?>
			<input type="hidden" name="action" value="launchy_save_style" />
			<input type="hidden" name="style_id" value="<?php echo esc_attr( $editing_id ); ?>" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="launchy_style_label"><?php esc_html_e( 'Name', 'launchy-illustrator' ); ?></label></th>
					<td><input type="text" id="launchy_style_label" name="label" class="regular-text" value="<?php echo esc_attr( $style['label'] ); ?>" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="launchy_style_description"><?php esc_html_e( 'Description', 'launchy-illustrator' ); ?></label></th>
					<td>
						<input type="text" id="launchy_style_description" name="description" class="regular-text" value="<?php echo esc_attr( $style['description'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Shown next to the name in the style picker.', 'launchy-illustrator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="launchy_style_prompt"><?php esc_html_e( 'Art direction', 'launchy-illustrator' ); ?></label></th>
					<td>
						<textarea id="launchy_style_prompt" name="prompt" rows="14" class="large-text code"><?php echo esc_textarea( $style['prompt'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'The master style block prepended to every image prompt — the artist you want, the palette, the rules, and what to avoid.', 'launchy-illustrator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="launchy_style_guidance"><?php esc_html_e( 'Planner guidance', 'launchy-illustrator' ); ?></label></th>
					<td>
						<textarea id="launchy_style_guidance" name="guidance" rows="5" class="large-text code"><?php echo esc_textarea( $style['guidance'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Tells the editorial pass what kind of scenes suit this style — describe your character and how it should act out concepts.', 'launchy-illustrator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Reference images', 'launchy-illustrator' ); ?></th>
					<td>
						<div id="launchy-refs" class="launchy-refs"></div>
						<input type="hidden" id="launchy_style_references" name="references" value="<?php echo esc_attr( implode( "\n", (array) $style['references'] ) ); ?>" />
						<p>
							<button type="button" class="button" id="launchy-refs-add"><?php esc_html_e( 'Add from media library', 'launchy-illustrator' ); ?></button>
							<button type="button" class="button-link" id="launchy-refs-clear"><?php esc_html_e( 'Clear all', 'launchy-illustrator' ); ?></button>
						</p>
						<p class="description"><?php esc_html_e( 'Passed to the image model so your character and palette stay consistent. Up to 14 are used.', 'launchy-illustrator' ); ?></p>
					</td>
				</tr>
			</table>

			<p>
				<button type="submit" class="button button-primary"><?php echo $editing_id ? esc_html__( 'Save style', 'launchy-illustrator' ) : esc_html__( 'Create style', 'launchy-illustrator' ); ?></button>
				<label style="margin-left:1em;">
					<input type="checkbox" name="select_after_save" value="1" <?php checked( ! $editing_id || $editing_id === $current ); ?> />
					<?php esc_html_e( 'Use this style after saving', 'launchy-illustrator' ); ?>
				</label>
				<?php if ( $editing_id ) : ?>
					<a class="button-link" style="margin-left:1em;" href="<?php echo esc_url( add_query_arg( 'page', self::PAGE, admin_url( 'options-general.php' ) ) ); ?>"><?php esc_html_e( 'Start a new style instead', 'launchy-illustrator' ); ?></a>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	/**
	 * Print the result of the last style action, if any.
	 */
	private function notice() {
		$notice = isset( $_GET['launchy_notice'] ) ? sanitize_key( wp_unslash( $_GET['launchy_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $notice ) {
			return;
		}

		$messages = array(
			'style_saved'      => array( 'success', __( 'Style saved.', 'launchy-illustrator' ) ),
			'style_deleted'    => array( 'success', __( 'Style deleted.', 'launchy-illustrator' ) ),
			'style_duplicated' => array( 'success', __( 'Style duplicated — edit it below.', 'launchy-illustrator' ) ),
			'style_error'      => array( 'error', __( 'That style could not be found.', 'launchy-illustrator' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}

	/**
	 * Bail unless the current user may manage settings and the nonce checks out.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage styles.', 'launchy-illustrator' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Back to the settings page with a notice.
	 *
	 * @param string $notice Notice slug.
	 * @param string $edit   Style id to open for editing, if any.
	 */
	private function redirect( $notice, $edit = '' ) {
		$args = array(
			'page'           => self::PAGE,
			'launchy_notice' => $notice,
		);
		if ( $edit ) {
			$args['edit_style'] = $edit;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Create or update a style.
	 */
	public function handle_save() {
		$id = isset( $_POST['style_id'] ) ? sanitize_key( wp_unslash( $_POST['style_id'] ) ) : '';
		$this->guard( 'launchy_style_' . $id );

		$saved_id = User_Styles::save(
			$id,
			array(
				'label'       => wp_unslash( $_POST['label'] ?? '' ),
				'description' => wp_unslash( $_POST['description'] ?? '' ),
				'prompt'      => wp_unslash( $_POST['prompt'] ?? '' ),
				'guidance'    => wp_unslash( $_POST['guidance'] ?? '' ),
				'references'  => wp_unslash( $_POST['references'] ?? '' ),
			)
		);

		if ( ! empty( $_POST['select_after_save'] ) ) {
			Settings::update( 'style_preset', $saved_id );
		}

		$this->redirect( 'style_saved', $saved_id );
	}

	/**
	 * Delete a style, falling back to the default if it was selected.
	 */
	public function handle_delete() {
		$id = isset( $_POST['style_id'] ) ? sanitize_key( wp_unslash( $_POST['style_id'] ) ) : '';
		$this->guard( 'launchy_style_' . $id );

		if ( User_Styles::delete( $id ) && (string) Settings::get( 'style_preset' ) === $id ) {
			Settings::update( 'style_preset', Style_Presets::DEFAULT_PRESET );
		}

		$this->redirect( 'style_deleted' );
	}

	/**
	 * Duplicate any preset into an editable user style.
	 */
	public function handle_duplicate() {
		$this->guard( 'launchy_duplicate_style' );

		$source = isset( $_POST['source_id'] ) ? sanitize_key( wp_unslash( $_POST['source_id'] ) ) : '';
		$new_id = User_Styles::duplicate( $source );

		if ( ! $new_id ) {
			$this->redirect( 'style_error' );
		}

		$this->redirect( 'style_duplicated', $new_id );
	}
}
