<?php
/**
 * Media type registry.
 *
 * @package Content_Designer
 */

namespace Content_Designer\Media_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Holds all registered media types.
 */
class Registry {

	/**
	 * Registered types by slug.
	 *
	 * @var Media_Type[]
	 */
	private $types = array();

	/**
	 * Register a media type.
	 *
	 * @param Media_Type $type Type instance.
	 */
	public function register( Media_Type $type ) {
		$this->types[ $type->get_slug() ] = $type;
	}

	/**
	 * Get a type by slug.
	 *
	 * @param string $slug Type slug.
	 * @return Media_Type|null
	 */
	public function get( $slug ) {
		$types = $this->all();
		return $types[ $slug ] ?? null;
	}

	/**
	 * All registered types (filterable).
	 *
	 * @return Media_Type[]
	 */
	public function all() {
		/**
		 * Filter the registered media types.
		 *
		 * @param Media_Type[] $types Types keyed by slug.
		 */
		return apply_filters( 'content_designer_media_types', $this->types );
	}
}
