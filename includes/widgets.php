<?php
/**
 * Widgets
 *
 * Widgets related funtions and widget registration.
 *
 * @package     EDD
 * @subpackage  Widgets
 * @copyright   Copyright (c) 2015, Pippin Williamson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.0
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/*
|--------------------------------------------------------------------------
| FRONT-END WIDGETS
|--------------------------------------------------------------------------
|
| - Cart Widget
| - Categories / Tags Widget
|
*/

/**
 * Cart Widget.
 *
 * Downloads cart widget class.
 *
 * @since 1.0
 * @return void
*/
class edd_cart_widget extends WP_Widget {
	/** Constructor */
	function __construct() {
		parent::__construct( 'edd_cart_widget', __( 'Downloads Cart', 'easy-digital-downloads' ), array( 'description' => __( 'Display the downloads shopping cart', 'easy-digital-downloads' ) ) );
	}

	/** @see WP_Widget::widget */
	function widget( $args, $instance ) {

		if ( ! empty( $instance['hide_on_checkout'] ) && edd_is_checkout() ) {
			return;
		}

		$args['id']        = ( isset( $args['id'] ) ) ? $args['id'] : 'edd_cart_widget';
		$instance['title'] = ( isset( $instance['title'] ) ) ? $instance['title'] : '';

		$title = apply_filters( 'widget_title', $instance['title'], $instance, $args['id'] );

		echo $args['before_widget'];

		if ( $title ) {
			echo $args['before_title'] . $title . $args['after_title'];
		}

		do_action( 'edd_before_cart_widget' );

		edd_shopping_cart( true );

		do_action( 'edd_after_cart_widget' );

		echo $args['after_widget'];
	}

	/** @see WP_Widget::update */
	function update( $new_instance, $old_instance ) {
		$instance = $old_instance;

		$instance['title']            = strip_tags( $new_instance['title'] );
		$instance['hide_on_checkout'] = isset( $new_instance['hide_on_checkout'] );

		return $instance;
	}

	/** @see WP_Widget::form */
	function form( $instance ) {

		$defaults = array(
			'title'            => '',
			'hide_on_checkout' => false,
		);

		$instance = wp_parse_args( (array) $instance, $defaults ); ?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php _e( 'Title:', 'easy-digital-downloads' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo $instance['title']; ?>"/>
		</p>

		<!-- Hide on Checkout Page -->
		<p>
			<input <?php checked( $instance['hide_on_checkout'], true ); ?> id="<?php echo esc_attr( $this->get_field_id( 'hide_on_checkout' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'hide_on_checkout' ) ); ?>" type="checkbox" />
			<label for="<?php echo esc_attr( $this->get_field_id( 'hide_on_checkout' ) ); ?>"><?php _e( 'Hide on Checkout Page', 'easy-digital-downloads' ); ?></label>
		</p>

		<?php
	}
}

/**
 * Categories / Tags Widget.
 *
 * Downloads categories / tags widget class.
 *
 * @since 1.0
 * @return void
*/
class edd_categories_tags_widget extends WP_Widget {
	/** Constructor */
	function __construct() {
		parent::__construct( 'edd_categories_tags_widget', __( 'Downloads Categories / Tags', 'easy-digital-downloads' ), array( 'description' => __( 'Display the downloads categories or tags', 'easy-digital-downloads' ) ) );
	}

	/** @see WP_Widget::widget */
	function widget( $args, $instance ) {
		// Set defaults.
		$args['id']           = ( isset( $args['id'] ) ) ? $args['id'] : 'edd_categories_tags_widget';
		$instance['title']    = ( isset( $instance['title'] ) ) ? $instance['title'] : '';
		$instance['taxonomy'] = ( isset( $instance['taxonomy'] ) ) ? $instance['taxonomy'] : 'download_category';

		$title      = apply_filters( 'widget_title', $instance['title'], $instance, $args['id'] );
		$tax        = $instance['taxonomy'];
		$count      = isset( $instance['count'] ) && $instance['count'] == 'on' ? 1 : 0;
		$hide_empty = isset( $instance['hide_empty'] ) && $instance['hide_empty'] == 'on' ? 1 : 0;

		echo $args['before_widget'];

		if ( $title ) {
			echo $args['before_title'] . $title . $args['after_title'];
		}

		do_action( 'edd_before_taxonomy_widget' );

		echo "<ul class=\"edd-taxonomy-widget\">\n";
			wp_list_categories( 'title_li=&taxonomy=' . $tax . '&show_count=' . $count . '&hide_empty=' . $hide_empty );
		echo "</ul>\n";

		do_action( 'edd_after_taxonomy_widget' );

		echo $args['after_widget'];
	}

	/** @see WP_Widget::update */
	function update( $new_instance, $old_instance ) {
		$instance               = $old_instance;
		$instance['title']      = strip_tags( $new_instance['title'] );
		$instance['taxonomy']   = strip_tags( $new_instance['taxonomy'] );
		$instance['count']      = isset( $new_instance['count'] ) ? $new_instance['count'] : '';
		$instance['hide_empty'] = isset( $new_instance['hide_empty'] ) ? $new_instance['hide_empty'] : '';
		return $instance;
	}

	/** @see WP_Widget::form */
	function form( $instance ) {
		// Set up some default widget settings.
		$defaults = array(
			'title'         => '',
			'taxonomy'      => 'download_category',
			'count'         => 'off',
			'hide_empty'    => 'off',
		);

		$instance = wp_parse_args( (array) $instance, $defaults ); ?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php _e( 'Title:', 'easy-digital-downloads' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo $instance['title']; ?>"/>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'taxonomy' ) ); ?>"><?php _e( 'Taxonomy:', 'easy-digital-downloads' ); ?></label>
			<select name="<?php echo esc_attr( $this->get_field_name( 'taxonomy' ) ); ?>" id="<?php echo esc_attr( $this->get_field_id( 'taxonomy' ) ); ?>">
				<?php
				$category_labels = edd_get_taxonomy_labels( 'download_category' );
				$tag_labels      = edd_get_taxonomy_labels( 'download_tag' );
				?>
				<option value="download_category" <?php selected( 'download_category', $instance['taxonomy'] ); ?>><?php echo $category_labels['name']; ?></option>
				<option value="download_tag" <?php selected( 'download_tag', $instance['taxonomy'] ); ?>><?php echo $tag_labels['name']; ?></option>
			</select>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'count' ); ?>"><?php _e( 'Show Count:', 'easy-digital-downloads' ); ?></label>
			<input <?php checked( $instance['count'], 'on' ); ?> id="<?php echo $this->get_field_id( 'count' ); ?>" name="<?php echo $this->get_field_name( 'count' ); ?>" type="checkbox" />
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'hide_empty' ); ?>"><?php _e( 'Hide Empty Categories:', 'easy-digital-downloads' ); ?></label>
			<input <?php checked( $instance['hide_empty'], 'on' ); ?> id="<?php echo $this->get_field_id( 'hide_empty' ); ?>" name="<?php echo $this->get_field_name( 'hide_empty' ); ?>" type="checkbox" />
		</p>
	<?php
	}
}


/**
 * Register Widgets.
 *
 * Registers the EDD Widgets.
 *
 * @since 1.0
 * @return void
 */
function edd_register_widgets() {
	register_widget( 'edd_cart_widget' );
	register_widget( 'edd_categories_tags_widget' );
}
add_action( 'widgets_init', 'edd_register_widgets' );
