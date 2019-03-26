<?php

namespace WP_CLI;

use Mustangostang\Spyc;

/**
 * Output one or more items in a given format (e.g. table, JSON).
 */
class Formatter {

	/**
	 * @var array $args How the items should be output.
	 */
	private $args;

	/**
	 * @var string $prefix Standard prefix for object fields.
	 */
	private $prefix;

	/**
	 * @param array $assoc_args Output format arguments.
	 * @param array $fields Fields to display of each item.
	 * @param string|bool $prefix Check if fields have a standard prefix.
	 * False indicates empty prefix.
	 */
	public function __construct( &$assoc_args, $fields = null, $prefix = false ) {
		$format_args = array(
			'format' => 'table',
			'fields' => $fields,
			'field'  => null,
		);

		foreach ( array( 'format', 'fields', 'field' ) as $key ) {
			if ( isset( $assoc_args[ $key ] ) ) {
				$format_args[ $key ] = $assoc_args[ $key ];
				unset( $assoc_args[ $key ] );
			}
		}

		if ( ! is_array( $format_args['fields'] ) ) {
			$format_args['fields'] = explode( ',', $format_args['fields'] );
		}

		$format_args['fields'] = array_map( 'trim', $format_args['fields'] );

		$this->args   = $format_args;
		$this->prefix = $prefix;
	}

	/**
	 * Magic getter for arguments.
	 *
	 * @param string $key
	 * @return mixed
	 */
	public function __get( $key ) {
		return $this->args[ $key ];
	}

	/**
	 * Display multiple items according to the output arguments.
	 *
	 * @param array      $items
	 * @param bool|array $ascii_pre_colorized Optional. A boolean or an array of booleans to pass to `format()` if items in the table are pre-colorized. Default false.
	 */
	public function display_items( $items, $ascii_pre_colorized = false ) {
		if ( $this->args['field'] ) {
			$this->show_single_field( $items, $this->args['field'] );
		} else {
			if ( in_array( $this->args['format'], array( 'csv', 'json', 'table' ), true ) ) {
				$item = is_array( $items ) && ! empty( $items ) ? array_shift( $items ) : false;
				if ( $item && ! empty( $this->args['fields'] ) ) {
					foreach ( $this->args['fields'] as &$field ) {
						$field = $this->find_item_key( $item, $field );
					}
					array_unshift( $items, $item );
				}
			}

			if ( in_array( $this->args['format'], array( 'table', 'csv' ), true ) ) {
				if ( is_object( $items ) && is_a( $items, 'Iterator' ) ) {
					$items = \WP_CLI\Utils\iterator_map( $items, array( $this, 'transform_item_values_to_json' ) );
				} else {
					$items = array_map( array( $this, 'transform_item_values_to_json' ), $items );
				}
			}

			$this->format( $items, $ascii_pre_colorized );
		}
	}

	/**
	 * Display a single item according to the output arguments.
	 *
	 * @param mixed      $item
	 * @param bool|array $ascii_pre_colorized Optional. A boolean or an array of booleans to pass to `show_multiple_fields()` if the item in the table is pre-colorized. Default false.
	 */
	public function display_item( $item, $ascii_pre_colorized = false ) {
		if ( isset( $this->args['field'] ) ) {
			$item  = (object) $item;
			$key   = $this->find_item_key( $item, $this->args['field'] );
			$value = $item->$key;
			if ( in_array( $this->args['format'], array( 'table', 'csv' ), true ) && ( is_object( $value ) || is_array( $value ) ) ) {
				$value = json_encode( $value );
			}
			\WP_CLI::print_value(
				$value,
				array(
					'format' => $this->args['format'],
				)
			);
		} else {
			$this->show_multiple_fields( $item, $this->args['format'], $ascii_pre_colorized );
		}
	}

	/**
	 * Format items according to arguments.
	 *
	 * @param array      $items
	 * @param bool|array $ascii_pre_colorized Optional. A boolean or an array of booleans to pass to `show_table()` if items in the table are pre-colorized. Default false.
	 */
	

	/**
	 * Show a single field from a list of items.
	 *
	 * @param array Array of objects to show fields from
	 * @param string The field to show
	 */
	

	/**
	 * Find an object's key.
	 * If $prefix is set, a key with that prefix will be prioritized.
	 *
	 * @param object $item
	 * @param string $field
	 * @return string $key
	 */
	

	/**
	 * Show multiple fields of an object.
	 *
	 * @param object|array $data                Data to display
	 * @param string       $format              Format to display the data in
	 * @param bool|array   $ascii_pre_colorized Optional. A boolean or an array of booleans to pass to `show_table()` if the item in the table is pre-colorized. Default false.
	 */
	

	/**
	 * Show items in a \cli\Table.
	 *
	 * @param array      $items
	 * @param array      $fields
	 * @param bool|array $ascii_pre_colorized Optional. A boolean or an array of booleans to pass to `Table::setAsciiPreColorized()` if items in the table are pre-colorized. Default false.
	 */
	private static function show_table( $items, $fields, $ascii_pre_colorized = false ) {
		$table = new \cli\Table();

		$enabled = \cli\Colors::shouldColorize();
		if ( $enabled ) {
			\cli\Colors::disable( true );
		}

		$table->setAsciiPreColorized( $ascii_pre_colorized );
		$table->setHeaders( $fields );

		foreach ( $items as $item ) {
			$table->addRow( array_values( \WP_CLI\Utils\pick_fields( $item, $fields ) ) );
		}

		foreach ( $table->getDisplayLines() as $line ) {
			\WP_CLI::line( $line );
		}

		if ( $enabled ) {
			\cli\Colors::enable( true );
		}
	}

	/**
	 * Format an associative array as a table.
	 *
	 * @param array     $fields    Fields and values to format
	 * @return array    $rows
	 */
	

	/**
	 * Transforms objects and arrays to JSON as necessary
	 *
	 * @param mixed $item
	 * @return mixed
	 */
	public function transform_item_values_to_json( $item ) {
		foreach ( $this->args['fields'] as $field ) {
			$true_field = $this->find_item_key( $item, $field );
			$value      = is_object( $item ) ? $item->$true_field : $item[ $true_field ];
			if ( is_array( $value ) || is_object( $value ) ) {
				if ( is_object( $item ) ) {
					$item->$true_field = json_encode( $value );
				} elseif ( is_array( $item ) ) {
					$item[ $true_field ] = json_encode( $value );
				}
			}
		}
		return $item;
	}

}
