<?php
/**
 * Abilities API integration.
 *
 * Exposes the plugin's pipeline as first-class WordPress "abilities" so the
 * same scan → plan → generate → place flow is callable by AI agents, the
 * MCP adapter, and other plugins — not just the admin UI. Mirrors the REST
 * controller but is the canonical, agent-facing surface.
 *
 * Registered on wp_abilities_api_init (no-op if the Abilities API isn't loaded).
 *
 * @package Launchy_Illustrator
 */

namespace Launchy_Illustrator;

defined( 'ABSPATH' ) || exit;

/**
 * Registers launchy-illustrator/* abilities.
 */
class Abilities {

	/**
	 * Register the ability category. Fires on wp_abilities_api_categories_init,
	 * which runs before wp_abilities_api_init — abilities can only reference a
	 * category that already exists.
	 */
	public function register_categories() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			'launchy-illustrator',
			array(
				'label'       => __( 'Launchy Illustrator', 'launchy-illustrator' ),
				'description' => __( 'Plan, generate, and place AI media in posts.', 'launchy-illustrator' ),
			)
		);
	}

	/**
	 * Register all abilities.
	 */
	public function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$this->register_plan_ability();
		$this->register_generate_ability();
		$this->register_place_ability();
	}

	/**
	 * Permission helper bound to a specific post param. Public so the Abilities
	 * registry (which validates via is_callable from its own scope) accepts it.
	 *
	 * @param array $input Ability input.
	 * @return bool
	 */
	public function can_edit( $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		return $post_id && current_user_can( 'edit_post', $post_id ) && current_user_can( 'upload_files' );
	}

	/**
	 * launchy-illustrator/plan-media — scan a draft and propose placements.
	 */
	private function register_plan_ability() {
		wp_register_ability(
			'launchy-illustrator/plan-media',
			array(
				'label'               => __( 'Plan media for a draft', 'launchy-illustrator' ),
				'description'         => __( 'Scans a post and returns an editorial plan of where supporting media (illustrations and other types) would genuinely help, with roles, aspect ratios, and scene direction.', 'launchy-illustrator' ),
				'category'            => 'launchy-illustrator',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'The post to analyze.', 'launchy-illustrator' ),
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'total_words' => array( 'type' => 'integer' ),
						'plan'        => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => function ( $input ) {
					$post = get_post( (int) $input['post_id'] );
					if ( ! $post ) {
						return new \WP_Error( 'launchy_no_post', __( 'Post not found.', 'launchy-illustrator' ) );
					}
					$scan = ( new Draft_Scanner() )->scan( $post );
					if ( empty( $scan['outline'] ) ) {
						return new \WP_Error( 'launchy_empty_post', __( 'This draft has no content to scan yet.', 'launchy-illustrator' ) );
					}
					$plan = ( new Placement_Planner() )->plan( $post, $scan );
					if ( is_wp_error( $plan ) ) {
						return $plan;
					}
					return array(
						'total_words' => $scan['total_words'],
						'plan'        => $plan,
					);
				},
				'permission_callback' => array( $this, 'can_edit' ),
				'meta'                => array(
					'annotations' => array(
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
					),
				),
			)
		);
	}

	/**
	 * launchy-illustrator/generate-media — generate one image for a placement.
	 */
	private function register_generate_ability() {
		wp_register_ability(
			'launchy-illustrator/generate-media',
			array(
				'label'               => __( 'Generate media for a placement', 'launchy-illustrator' ),
				'description'         => __( 'Generates a single on-brand media asset for one planned placement and saves it to the media library (without inserting it into the post).', 'launchy-illustrator' ),
				'category'            => 'launchy-illustrator',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'   => array( 'type' => 'integer' ),
						'placement' => array(
							'type'        => 'object',
							'description' => __( 'A single placement item from plan-media.', 'launchy-illustrator' ),
						),
						'variation' => array(
							'type'    => 'integer',
							'default' => 1,
						),
					),
					'required'             => array( 'post_id', 'placement' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id' => array( 'type' => 'integer' ),
						'url'           => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => function ( $input ) {
					$request = new \WP_REST_Request();
					$request->set_param( 'post_id', (int) $input['post_id'] );
					$request->set_param( 'placement', (array) $input['placement'] );
					$request->set_param( 'variation', (int) ( $input['variation'] ?? 1 ) );
					return ( new REST_Controller() )->generate( $request );
				},
				'permission_callback' => array( $this, 'can_edit' ),
				'meta'                => array(
					'annotations' => array(
						'readOnlyHint'    => false,
						'destructiveHint' => false,
						'idempotentHint'  => false,
					),
				),
			)
		);
	}

	/**
	 * launchy-illustrator/place-media — insert chosen media into the post.
	 */
	private function register_place_ability() {
		wp_register_ability(
			'launchy-illustrator/place-media',
			array(
				'label'               => __( 'Place media into a draft', 'launchy-illustrator' ),
				'description'         => __( 'Inserts chosen generated media into the post at the planned anchors and sets the hero as the featured image. Updates the draft body only — never changes post status or publishes.', 'launchy-illustrator' ),
				'category'            => 'launchy-illustrator',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'    => array( 'type' => 'integer' ),
						'selections' => array(
							'type'        => 'array',
							'description' => __( 'List of {placement, attachment_id} to place.', 'launchy-illustrator' ),
						),
						'discard'    => array(
							'type'    => 'array',
							'default' => array(),
						),
					),
					'required'             => array( 'post_id', 'selections' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'placed'   => array( 'type' => 'integer' ),
						'featured' => array( 'type' => array( 'integer', 'null' ) ),
					),
				),
				'execute_callback'    => function ( $input ) {
					$request = new \WP_REST_Request();
					$request->set_param( 'post_id', (int) $input['post_id'] );
					$request->set_param( 'selections', (array) $input['selections'] );
					$request->set_param( 'discard', (array) ( $input['discard'] ?? array() ) );
					return ( new REST_Controller() )->place( $request );
				},
				'permission_callback' => array( $this, 'can_edit' ),
				'meta'                => array(
					'annotations' => array(
						'readOnlyHint'    => false,
						'destructiveHint' => false,
						'idempotentHint'  => false,
					),
				),
			)
		);
	}
}
