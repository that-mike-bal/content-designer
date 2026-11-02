<?php
/**
 * Admin app: "Illustrate" screen under Tools.
 *
 * The interactive surface is built on the WordPress Interactivity API — the
 * server renders the initial markup with directives (data-wp-*) and an
 * interactivity store handles scan → review plan → generate → choose → place
 * entirely client-side against the content-designer/v1 REST endpoints.
 *
 * Notes on the markup:
 * - Repeated lists use data-wp-each on <template> elements (the directive is a
 *   no-op on anything else).
 * - Steps 2–4, the busy row, and the error box carry a hard-coded `hidden`
 *   attribute because admin screens get no server-side directive processing;
 *   the runtime takes ownership of `hidden` via data-wp-bind once hydrated.
 *
 * @package Content_Designer
 */

namespace Content_Designer\Admin;

use Content_Designer\Settings;
use Content_Designer\Style_Presets;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the Illustrate screen.
 */
class Page {

	const SLUG  = 'content-designer';
	const STORE = 'content-designer';

	/**
	 * Hook in.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the Tools → Illustrate screen.
	 */
	public function register() {
		add_management_page(
			__( 'Content Designer', 'content-designer' ),
			__( 'Illustrate', 'content-designer' ),
			'edit_posts',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue the Interactivity API module + styles on our screen only.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue( $hook ) {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'content-designer-admin',
			CONTENT_DESIGNER_URL . 'admin/assets/admin.css',
			array(),
			CONTENT_DESIGNER_VERSION
		);

		// The Interactivity API view module. Depends on @wordpress/interactivity,
		// which core registers as an importable script module.
		wp_register_script_module(
			'content-designer-view',
			CONTENT_DESIGNER_URL . 'admin/assets/view.js',
			array( '@wordpress/interactivity' ),
			CONTENT_DESIGNER_VERSION
		);
		wp_enqueue_script_module( 'content-designer-view' );
	}

	/**
	 * Render the screen. Initial state is serialized into the interactivity
	 * store; everything after first paint is driven by view.js.
	 */
	public function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_interactivity_state(
			self::STORE,
			array(
				'restRoot'  => esc_url_raw( rest_url( 'content-designer/v1' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'step'      => 'pick', // pick | plan | generate | done.
				'drafts'    => array(),
				'postId'    => 0,
				'postTitle' => '',
				'plan'      => array(),
				'results'   => (object) array(), // placementId -> [{attachment_id, url}].
				'chosen'    => (object) array(), // placementId -> attachment_id.
				'orphans'   => array(),          // Superseded attachment ids to discard on place.
				'busy'      => false,
				'busyLabel' => '',
				'error'     => '',
				'summary'   => (object) array(),
			)
		);

		$settings_url = admin_url( 'options-general.php?page=content-designer-settings' );
		?>
		<div
			class="wrap content-designer-app"
			data-wp-interactive="<?php echo esc_attr( self::STORE ); ?>"
			<?php echo wp_kses_data( wp_interactivity_data_wp_context( array( 'search' => '' ) ) ); ?>
			data-wp-init="callbacks.init"
		>
			<h1 class="content-designer-title">
				<span class="content-designer-badge">✈︎</span>
				<?php esc_html_e( 'Content Designer', 'content-designer' ); ?>
			</h1>
			<p class="content-designer-sub">
				<?php esc_html_e( 'Scan a draft, review where supporting media earns its place, then generate art in your configured style and drop it into the post.', 'content-designer' ); ?>
			</p>

			<?php if ( ! Settings::api_key() ) : ?>
				<div class="notice notice-error inline">
					<p>
						<?php echo esc_html( Settings::api_key_source_label() ); ?>
						<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Add one in settings', 'content-designer' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<?php
			$custom_style = '' !== trim( (string) Settings::get( 'style_prompt' ) );
			$active_style = $custom_style
				? __( 'Custom style prompt', 'content-designer' )
				: Style_Presets::current()['label'];
			?>
			<p class="content-designer-stylebar">
				<?php esc_html_e( 'Image style:', 'content-designer' ); ?>
				<strong><?php echo esc_html( $active_style ); ?></strong>
				· <a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Change', 'content-designer' ); ?></a>
			</p>

			<div class="content-designer-error" hidden data-wp-bind--hidden="!state.error">
				<p data-wp-text="state.error"></p>
			</div>

			<div class="content-designer-busy" hidden data-wp-bind--hidden="!state.busy">
				<span class="content-designer-spinner" aria-hidden="true"></span>
				<span data-wp-text="state.busyLabel"></span>
			</div>

			<!-- STEP 1: pick a draft -->
			<section class="content-designer-card" data-wp-bind--hidden="!state.isPick">
				<h2><?php esc_html_e( '1 · Choose a draft', 'content-designer' ); ?></h2>
				<input
					type="search"
					class="content-designer-input"
					placeholder="<?php esc_attr_e( 'Filter drafts…', 'content-designer' ); ?>"
					data-wp-bind--value="context.search"
					data-wp-on--input="actions.setSearch"
				/>
				<ul class="content-designer-drafts">
					<template data-wp-each--draft="state.visibleDrafts" data-wp-each-key="context.draft.id">
						<li class="content-designer-draft">
							<button type="button" data-wp-on--click="actions.scan" data-wp-bind--data-id="context.draft.id">
								<strong data-wp-text="context.draft.title"></strong>
								<span class="content-designer-meta">
									<span data-wp-text="context.draft.words"></span> <?php esc_html_e( 'words', 'content-designer' ); ?>
									· <span data-wp-text="context.draft.status"></span>
								</span>
							</button>
						</li>
					</template>
				</ul>
				<p class="content-designer-empty" hidden data-wp-bind--hidden="state.hasDrafts">
					<?php esc_html_e( 'No drafts found. Create a draft post, then come back.', 'content-designer' ); ?>
				</p>
			</section>

			<!-- STEP 2: review the plan -->
			<section class="content-designer-card" hidden data-wp-bind--hidden="!state.isPlan">
				<h2>
					<?php esc_html_e( '2 · Review the placement plan', 'content-designer' ); ?>
					— <span data-wp-text="state.postTitle"></span>
				</h2>
				<p class="content-designer-sub"><?php esc_html_e( 'Uncheck anything you don’t want. Edit scenes before generating — cheap on paper, expensive as pixels.', 'content-designer' ); ?></p>

				<ul class="content-designer-plan">
					<template data-wp-each--item="state.plan" data-wp-each-key="context.item.id">
						<li class="content-designer-place" data-wp-bind--data-approved="context.item.approved">
							<label class="content-designer-place-head">
								<input type="checkbox" data-wp-bind--checked="context.item.approved" data-wp-on--change="actions.toggleApproved" data-wp-bind--data-id="context.item.id" />
								<span class="content-designer-role" data-wp-text="context.item.role"></span>
								<span class="content-designer-ratio" data-wp-text="context.item.aspect_ratio"></span>
								<span class="content-designer-anchor" data-wp-text="context.item.anchor_label"></span>
							</label>
							<textarea class="content-designer-scene" rows="3" data-wp-bind--value="context.item.scene" data-wp-on--input="actions.editScene" data-wp-bind--data-id="context.item.id"></textarea>
							<p class="content-designer-rationale" data-wp-text="context.item.rationale"></p>
						</li>
					</template>
				</ul>

				<div class="content-designer-actions">
					<button class="button" data-wp-on--click="actions.reset"><?php esc_html_e( '← Back', 'content-designer' ); ?></button>
					<button class="button button-primary" data-wp-on--click="actions.generateAll"><?php esc_html_e( 'Generate approved images', 'content-designer' ); ?></button>
				</div>
			</section>

			<!-- STEP 3: choose variations -->
			<section class="content-designer-card" hidden data-wp-bind--hidden="!state.isGenerate">
				<h2><?php esc_html_e( '3 · Choose the finalists', 'content-designer' ); ?></h2>
				<ul class="content-designer-gallery">
					<template data-wp-each--item="state.approvedPlan" data-wp-each-key="context.item.id">
						<li class="content-designer-gallery-row">
							<div class="content-designer-gallery-head">
								<span class="content-designer-role" data-wp-text="context.item.role"></span>
								<span class="content-designer-anchor" data-wp-text="context.item.anchor_label"></span>
							</div>
							<div class="content-designer-variations">
								<template data-wp-each--shot="state.shotsFor" data-wp-each-key="context.shot.attachment_id">
									<button type="button" class="content-designer-shot" data-wp-bind--data-chosen="state.isChosen" data-wp-on--click="actions.choose" data-wp-bind--data-pid="context.item.id" data-wp-bind--data-aid="context.shot.attachment_id">
										<img data-wp-bind--src="context.shot.url" alt="" />
									</button>
								</template>
							</div>
							<button type="button" class="button-link content-designer-regen" data-wp-on--click="actions.regenerate" data-wp-bind--data-id="context.item.id">
								<?php esc_html_e( '↻ Regenerate', 'content-designer' ); ?>
							</button>
						</li>
					</template>
				</ul>

				<div class="content-designer-actions">
					<button class="button" data-wp-on--click="actions.backToPlan"><?php esc_html_e( '← Plan', 'content-designer' ); ?></button>
					<button class="button button-primary" data-wp-on--click="actions.place"><?php esc_html_e( 'Place into draft', 'content-designer' ); ?></button>
				</div>
			</section>

			<!-- STEP 4: done -->
			<section class="content-designer-card content-designer-done" hidden data-wp-bind--hidden="!state.isDone">
				<h2><?php esc_html_e( '✓ Placed', 'content-designer' ); ?></h2>
				<p>
					<span data-wp-text="state.summary.placed"></span> <?php esc_html_e( 'image(s) inserted into the draft.', 'content-designer' ); ?>
				</p>
				<div class="content-designer-actions">
					<a class="button button-primary" data-wp-bind--href="state.summary.edit_link" target="_blank" rel="noopener"><?php esc_html_e( 'Open the draft', 'content-designer' ); ?></a>
					<button class="button" data-wp-on--click="actions.reset"><?php esc_html_e( 'Illustrate another', 'content-designer' ); ?></button>
				</div>
			</section>
		</div>
		<?php
	}
}
