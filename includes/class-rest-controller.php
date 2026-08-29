<?php
/**
 * REST API: launchy/v1. Drives the admin UI and mirrors the abilities.
 *
 * Endpoints are deliberately small so each HTTP request stays short:
 * /generate produces ONE image per call and the client loops variations.
 *
 * @package Launchy_Illustrator
 */

namespace Launchy_Illustrator;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * launchy/v1 routes.
 */
class REST_Controller {

	const NS = 'launchy/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/drafts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_drafts' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		$post_id_arg = array(
			'post_id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);

		register_rest_route(
			self::NS,
			'/scan',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => $post_id_arg,
			)
		);

		register_rest_route(
			self::NS,
			'/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => $post_id_arg + array(
					'placement' => array(
						'type'     => 'object',
						'required' => true,
					),
					'variation' => array(
						'type'    => 'integer',
						'default' => 1,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/place',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'place' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => $post_id_arg + array(
					'selections' => array(
						'type'     => 'array',
						'required' => true,
					),
					'discard'    => array(
						'type'    => 'array',
						'default' => array(),
					),
				),
			)
		);
	}

	/**
	 * Per-post permission check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_edit_post( WP_REST_Request $request ) {
		return current_user_can( 'edit_post', (int) $request['post_id'] )
			&& current_user_can( 'upload_files' );
	}

	/**
	 * List editable drafts (draft/pending/future).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function get_drafts( WP_REST_Request $request ) {
		$args = array(
			'post_type'      => 'post',
			'post_status'    => array( 'draft', 'pending', 'future' ),
			'posts_per_page' => 25,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			's'              => sanitize_text_field( (string) $request->get_param( 'search' ) ),
		);

		// Contributors etc. only see their own unpublished posts.
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$args['author'] = get_current_user_id();
		}

		$query = new \WP_Query( $args );

		$drafts = array();
		foreach ( $query->posts as $post ) {
			$drafts[] = array(
				'id'       => $post->ID,
				'title'    => $post->post_title ? $post->post_title : __( '(no title)', 'launchy-illustrator' ),
				'words'    => str_word_count( wp_strip_all_tags( $post->post_content ) ),
				'modified' => get_the_modified_date( '', $post ),
				'status'   => $post->post_status,
			);
		}

		return $drafts;
	}

	/**
	 * Scan a draft and produce the placement plan.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function scan( WP_REST_Request $request ) {
		$post = get_post( (int) $request['post_id'] );
		if ( ! $post ) {
			return new WP_Error( 'launchy_no_post', __( 'Post not found.', 'launchy-illustrator' ), array( 'status' => 404 ) );
		}

		$scanner = new Draft_Scanner();
		$scan    = $scanner->scan( $post );

		if ( empty( $scan['outline'] ) ) {
			return new WP_Error( 'launchy_empty_post', __( 'This draft has no content to scan yet.', 'launchy-illustrator' ), array( 'status' => 400 ) );
		}

		$planner = new Placement_Planner();
		$plan    = $planner->plan( $post, $scan );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		return array(
			'post'        => array(
				'id'    => $post->ID,
				'title' => $post->post_title,
			),
			'total_words' => $scan['total_words'],
			'plan'        => $plan,
		);
	}

	/**
	 * Generate ONE image for one placement.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function generate( WP_REST_Request $request ) {
		$post = get_post( (int) $request['post_id'] );
		if ( ! $post ) {
			return new WP_Error( 'launchy_no_post', __( 'Post not found.', 'launchy-illustrator' ), array( 'status' => 404 ) );
		}

		$placement = $this->sanitize_placement( (array) $request['placement'] );
		if ( is_wp_error( $placement ) ) {
			return $placement;
		}

		$type = Plugin::instance()->media_types->get( $placement['media_type'] );

		$image = $type->generate( $placement );
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		$manager       = new Media_Manager();
		$attachment_id = $manager->save( $image, $placement, $post, (int) $request['variation'] );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return array(
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_image_url( $attachment_id, 'large' ),
			'full'          => wp_get_attachment_image_url( $attachment_id, 'full' ),
		);
	}

	/**
	 * Place chosen images and discard the rest.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function place( WP_REST_Request $request ) {
		$post = get_post( (int) $request['post_id'] );
		if ( ! $post ) {
			return new WP_Error( 'launchy_no_post', __( 'Post not found.', 'launchy-illustrator' ), array( 'status' => 404 ) );
		}

		$selections = array();
		foreach ( (array) $request['selections'] as $selection ) {
			$placement = $this->sanitize_placement( (array) ( $selection['placement'] ?? array() ) );
			if ( is_wp_error( $placement ) || empty( $selection['attachment_id'] ) ) {
				continue;
			}
			$selections[] = array(
				'placement'     => $placement,
				'attachment_id' => absint( $selection['attachment_id'] ),
			);
		}

		if ( ! $selections ) {
			return new WP_Error( 'launchy_no_selections', __( 'Nothing selected to place.', 'launchy-illustrator' ), array( 'status' => 400 ) );
		}

		$placer = new Media_Placer();
		$result = $placer->place( $post, $selections );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$discarded = 0;
		if ( Settings::get( 'cleanup_variants' ) ) {
			$manager   = new Media_Manager();
			$discarded = $manager->discard( $post->ID, (array) $request['discard'] );
		}

		delete_post_meta( $post->ID, Placement_Planner::PLAN_META );

		return $result + array(
			'discarded' => $discarded,
			'edit_link' => get_edit_post_link( $post->ID, 'raw' ),
			'preview'   => get_preview_post_link( $post->ID ),
		);
	}

	/**
	 * Validate a placement object coming over the wire.
	 *
	 * @param array $raw Raw placement.
	 * @return array|WP_Error
	 */
	private function sanitize_placement( array $raw ) {
		$type = Plugin::instance()->media_types->get( sanitize_key( $raw['media_type'] ?? '' ) );
		if ( ! $type ) {
			return new WP_Error( 'launchy_bad_media_type', __( 'Unknown media type.', 'launchy-illustrator' ), array( 'status' => 400 ) );
		}

		$roles = $type->get_roles();
		$role  = sanitize_key( $raw['role'] ?? 'concept' );
		if ( ! isset( $roles[ $role ] ) ) {
			$role = 'concept';
		}

		$ratio = (string) ( $raw['aspect_ratio'] ?? '' );
		if ( ! in_array( $ratio, Placement_Planner::VALID_RATIOS, true ) ) {
			$ratio = $roles[ $role ]['ratio'];
		}

		$scene = sanitize_textarea_field( (string) ( $raw['scene'] ?? '' ) );
		if ( '' === $scene ) {
			return new WP_Error( 'launchy_no_scene', __( 'Placement has no scene description.', 'launchy-illustrator' ), array( 'status' => 400 ) );
		}

		return array(
			'id'           => sanitize_key( $raw['id'] ?? 'p0' ),
			'media_type'   => $type->get_slug(),
			'role'         => $role,
			'anchor_index' => absint( $raw['anchor_index'] ?? 0 ),
			'aspect_ratio' => $ratio,
			'resolution'   => in_array( $raw['resolution'] ?? '', array( '512', '1K', '2K', '4K' ), true ) ? $raw['resolution'] : $roles[ $role ]['resolution'],
			'scene'        => $scene,
			'composition'  => sanitize_textarea_field( (string) ( $raw['composition'] ?? '' ) ),
			'alt_text'     => sanitize_text_field( (string) ( $raw['alt_text'] ?? wp_trim_words( $scene, 20 ) ) ),
		);
	}
}
