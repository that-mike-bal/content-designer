<?php
/**
 * Admin app: "Illustrate" screen under Tools.
 *
 * The interactive surface is built on the WordPress Interactivity API — the
 * server renders the initial markup with directives (data-wp-*) and an
 * interactivity store handles scan → review plan → generate → choose → place
 * entirely client-side against the launchy/v1 REST endpoints.
 *
 * Notes on the markup:
 * - Repeated lists use data-wp-each on <template> elements (the directive is a
 *   no-op on anything else).
 * - Steps 2–4, the busy row, and the error box carry a hard-coded `hidden`
 *   attribute because admin screens get no server-side directive processing;
 *   the runtime takes ownership of `hidden` via data-wp-bind once hydrated.
 *
 * @package Launchy_Illustrator
 */

namespace Launchy_Illustrator\Admin;

use Launchy_Illustrator\Settings;
use Launchy_Illustrator\Style_Presets;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the Illustrate screen.
 */
class Page {

	const SLUG  = 'launchy-illustrator';
	const STORE = 'launchy-illustrator';

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
			__( 'Launchy Illustrator', 'launchy-illustrator' ),
			__( 'Illustrate', 'launchy-illustrator' ),
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
			'launchy-illustrator-admin',
			LAUNCHY_ILLUSTRATOR_URL . 'admin/assets/admin.css',
			array(),
			LAUNCHY_ILLUSTRATOR_VERSION
		);

		// The Interactivity API view module. Depends on @wordpress/interactivity,
		// which core registers as an importable script module.
		wp_register_script_module(
			'launchy-illustrator-view',
			LAUNCHY_ILLUSTRATOR_URL . 'admin/assets/view.js',
			array( '@wordpress/interactivity' ),
			LAUNCHY_ILLUSTRATOR_VERSION
		);
		wp_enqueue_script_module( 'launchy-illustrator-view' );
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
				'restRoot'  => esc_url_raw( rest_url( 'launchy/v1' ) ),
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

		$settings_url = admin_url( 'options-general.php?page=launchy-illustrator-settings' );
		?>
		<div
			class="wrap launchy-app"
			data-wp-interactive="<?php echo esc_attr( self::STORE ); ?>"
			<?php echo wp_kses_data( wp_interactivity_data_wp_context( array( 'search' => '' ) ) ); ?>
			data-wp-init="callbacks.init"
		>
			<h1 class="launchy-title">
				<span class="launchy-badge">✈︎</span>
				<?php esc_html_e( 'Launchy Illustrator', 'launchy-illustrator' ); ?>
			</h1>
			<p class="launchy-sub">
				<?php esc_html_e( 'Scan a draft, review where supporting media earns its place, then generate art in your configured style and drop it into the post.', 'launchy-illustrator' ); ?>
			</p>

			<?php if ( ! Settings::api_key() ) : ?>
				<div class="notice notice-error inline">
					<p>
						<?php echo esc_html( Settings::api_key_source_label() ); ?>
						<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Add one in settings', 'launchy-illustrator' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<?php
			$custom_style = '' !== trim( (string) Settings::get( 'style_prompt' ) );
			$active_style = $custom_style
				? __( 'Custom style prompt', 'launchy-illustrator' )
				: Style_Presets::current()['label'];
			?>
			<p class="launchy-stylebar">
				<?php esc_html_e( 'Image style:', 'launchy-illustrator' ); ?>
				<strong><?php echo esc_html( $active_style ); ?></strong>
				· <a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Change', 'launchy-illustrator' ); ?></a>
			</p>

			<div class="launchy-error" hidden data-wp-bind--hidden="!state.error">
				<p data-wp-text="state.error"></p>
			</div>

			<div class="launchy-busy" hidden data-wp-bind--hidden="!state.busy">
				<span class="launchy-spinner" aria-hidden="true"></span>
				<span data-wp-text="state.busyLabel"></span>
			</div>

			<!-- STEP 1: pick a draft -->
			<section class="launchy-card" data-wp-bind--hidden="!state.isPick">
				<h2><?php esc_html_e( '1 · Choose a draft', 'launchy-illustrator' ); ?></h2>
				<input
					type="search"
					class="launchy-input"
					placeholder="<?php esc_attr_e( 'Filter drafts…', 'launchy-illustrator' ); ?>"
					data-wp-bind--value="context.search"
					data-wp-on--input="actions.setSearch"
				/>
				<ul class="launchy-drafts">
					<template data-wp-each--draft="state.visibleDrafts" data-wp-each-key="context.draft.id">
						<li class="launchy-draft">
							<button type="button" data-wp-on--click="actions.scan" data-wp-bind--data-id="context.draft.id">
								<strong data-wp-text="context.draft.title"></strong>
								<span class="launchy-meta">
									<span data-wp-text="context.draft.words"></span> <?php esc_html_e( 'words', 'launchy-illustrator' ); ?>
									· <span data-wp-text="context.draft.status"></span>
								</span>
							</button>
						</li>
					</template>
				</ul>
				<p class="launchy-empty" hidden data-wp-bind--hidden="state.hasDrafts">
					<?php esc_html_e( 'No drafts found. Create a draft post, then come back.', 'launchy-illustrator' ); ?>
				</p>
			</section>

			<!-- STEP 2: review the plan -->
			<section class="launchy-card" hidden data-wp-bind--hidden="!state.isPlan">
				<h2>
					<?php esc_html_e( '2 · Review the placement plan', 'launchy-illustrator' ); ?>
					— <span data-wp-text="state.postTitle"></span>
				</h2>
				<p class="launchy-sub"><?php esc_html_e( 'Uncheck anything you don’t want. Edit scenes before generating — cheap on paper, expensive as pixels.', 'launchy-illustrator' ); ?></p>

				<ul class="launchy-plan">
					<template data-wp-each--item="state.plan" data-wp-each-key="context.item.id">
						<li class="launchy-place" data-wp-bind--data-approved="context.item.approved">
							<label class="launchy-place-head">
								<input type="checkbox" data-wp-bind--checked="context.item.approved" data-wp-on--change="actions.toggleApproved" data-wp-bind--data-id="context.item.id" />
								<span class="launchy-role" data-wp-text="context.item.role"></span>
								<span class="launchy-ratio" data-wp-text="context.item.aspect_ratio"></span>
								<span class="launchy-anchor" data-wp-text="context.item.anchor_label"></span>
							</label>
							<textarea class="launchy-scene" rows="3" data-wp-bind--value="context.item.scene" data-wp-on--input="actions.editScene" data-wp-bind--data-id="context.item.id"></textarea>
							<p class="launchy-rationale" data-wp-text="context.item.rationale"></p>
						</li>
					</template>
				</ul>

				<div class="launchy-actions">
					<button class="button" data-wp-on--click="actions.reset"><?php esc_html_e( '← Back', 'launchy-illustrator' ); ?></button>
					<button class="button button-primary" data-wp-on--click="actions.generateAll"><?php esc_html_e( 'Generate approved images', 'launchy-illustrator' ); ?></button>
				</div>
			</section>

			<!-- STEP 3: choose variations -->
			<section class="launchy-card" hidden data-wp-bind--hidden="!state.isGenerate">
				<h2><?php esc_html_e( '3 · Choose the finalists', 'launchy-illustrator' ); ?></h2>
				<ul class="launchy-gallery">
					<template data-wp-each--item="state.approvedPlan" data-wp-each-key="context.item.id">
						<li class="launchy-gallery-row">
							<div class="launchy-gallery-head">
								<span class="launchy-role" data-wp-text="context.item.role"></span>
								<span class="launchy-anchor" data-wp-text="context.item.anchor_label"></span>
							</div>
							<div class="launchy-variations">
								<template data-wp-each--shot="state.shotsFor" data-wp-each-key="context.shot.attachment_id">
									<button type="button" class="launchy-shot" data-wp-bind--data-chosen="state.isChosen" data-wp-on--click="actions.choose" data-wp-bind--data-pid="context.item.id" data-wp-bind--data-aid="context.shot.attachment_id">
										<img data-wp-bind--src="context.shot.url" alt="" />
									</button>
								</template>
							</div>
							<button type="button" class="button-link launchy-regen" data-wp-on--click="actions.regenerate" data-wp-bind--data-id="context.item.id">
								<?php esc_html_e( '↻ Regenerate', 'launchy-illustrator' ); ?>
							</button>
						</li>
					</template>
				</ul>

				<div class="launchy-actions">
					<button class="button" data-wp-on--click="actions.backToPlan"><?php esc_html_e( '← Plan', 'launchy-illustrator' ); ?></button>
					<button class="button button-primary" data-wp-on--click="actions.place"><?php esc_html_e( 'Place into draft', 'launchy-illustrator' ); ?></button>
				</div>
			</section>

			<!-- STEP 4: done -->
			<section class="launchy-card launchy-done" hidden data-wp-bind--hidden="!state.isDone">
				<h2><?php esc_html_e( '✓ Placed', 'launchy-illustrator' ); ?></h2>
				<p>
					<span data-wp-text="state.summary.placed"></span> <?php esc_html_e( 'image(s) inserted into the draft.', 'launchy-illustrator' ); ?>
				</p>
				<div class="launchy-actions">
					<a class="button button-primary" data-wp-bind--href="state.summary.edit_link" target="_blank" rel="noopener"><?php esc_html_e( 'Open the draft', 'launchy-illustrator' ); ?></a>
					<button class="button" data-wp-on--click="actions.reset"><?php esc_html_e( 'Illustrate another', 'launchy-illustrator' ); ?></button>
				</div>
			</section>
		</div>
		<?php
	}
}
