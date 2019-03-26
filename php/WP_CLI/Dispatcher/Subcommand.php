<?php

namespace WP_CLI\Dispatcher;

use WP_CLI;
use WP_CLI\Utils;

/**
 * A leaf node in the command tree.
 *
 * @package WP_CLI
 */
class Subcommand extends CompositeCommand {

	private $alias;

	private $when_invoked;

	public function __construct( $parent, $name, $docparser, $when_invoked ) {
		parent::__construct( $parent, $name, $docparser );

		$this->when_invoked = $when_invoked;

		$this->alias = $docparser->get_tag( 'alias' );

		$this->synopsis = $docparser->get_synopsis();
		if ( ! $this->synopsis && $this->longdesc ) {
			$this->synopsis = self::extract_synopsis( $this->longdesc );
		}
	}

	/**
	 * Extract the synopsis from PHPdoc string.
	 *
	 * @param string $longdesc Command docs via PHPdoc
	 * @return string
	 */
	private static function extract_synopsis( $longdesc ) {
		preg_match_all( '/(.+?)[\r\n]+:/', $longdesc, $matches );
		return implode( ' ', $matches[1] );
	}

	/**
	 * Subcommands can't have subcommands because they
	 * represent code to be executed.
	 *
	 * @return bool
	 */
	public function can_have_subcommands() {
		return false;
	}

	/**
	 * Get the synopsis string for this subcommand.
	 * A synopsis defines what runtime arguments are
	 * expected, useful to humans and argument validation.
	 *
	 * @return string
	 */
	public function get_synopsis() {
		return $this->synopsis;
	}

	/**
	 * Set the synopsis string for this subcommand.
	 *
	 * @param string
	 */
	public function set_synopsis( $synopsis ) {
		$this->synopsis = $synopsis;
	}

	/**
	 * If an alias is set, grant access to it.
	 * Aliases permit subcommands to be instantiated
	 * with a secondary identity.
	 *
	 * @return string
	 */
	public function get_alias() {
		return $this->alias;
	}

	/**
	 * Print the usage details to the end user.
	 *
	 * @param string $prefix
	 */
	public function show_usage( $prefix = 'usage: ' ) {
		\WP_CLI::line( $this->get_usage( $prefix ) );
	}

	/**
	 * Get the usage of the subcommand as a formatted string.
	 *
	 * @param string $prefix
	 * @return string
	 */
	public function get_usage( $prefix ) {
		return sprintf(
			'%s%s %s',
			$prefix,
			implode( ' ', get_path( $this ) ),
			$this->get_synopsis()
		);
	}

	/**
	 * Wrapper for CLI Tools' prompt() method.
	 *
	 * @param string $question
	 * @param string $default
	 * @return string|false
	 */
	

	/**
	 * Interactively prompt the user for input
	 * based on defined synopsis and passed arguments.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return array
	 */
	

	/**
	 * Validate the supplied arguments to the command.
	 * Throws warnings or errors if arguments are missing
	 * or invalid.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @param array $extra_args
	 * @return array list of invalid $assoc_args keys to unset
	 */
	

	/**
	 * Invoke the subcommand with the supplied arguments.
	 * Given a --prompt argument, interactively request input
	 * from the end user.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function invoke( $args, $assoc_args, $extra_args ) {

		if ( 'help' !== $this->name ) {
			static $prompted_once = false;

			if ( \WP_CLI::get_config( 'prompt' ) && ! $prompted_once ) {
				list( $_args, $assoc_args ) = $this->prompt_args( $args, $assoc_args );
				$args                       = array_merge( $args, $_args );
				$prompted_once              = true;
			}
		}

		$extra_positionals = array();
		foreach ( $extra_args as $k => $v ) {
			if ( is_numeric( $k ) ) {
				if ( ! isset( $args[ $k ] ) ) {
					$extra_positionals[ $k ] = $v;
				}
				unset( $extra_args[ $k ] );
			}
		}
		$args += $extra_positionals;

		list( $to_unset, $args, $assoc_args, $extra_args ) = $this->validate_args( $args, $assoc_args, $extra_args );

		foreach ( $to_unset as $key ) {
			unset( $assoc_args[ $key ] );
		}

		$path   = get_path( $this->get_parent() );
		$parent = implode( ' ', array_slice( $path, 1 ) );
		$cmd    = $this->name;
		if ( $parent ) {
			WP_CLI::do_hook( "before_invoke:{$parent}" );
			$cmd = $parent . ' ' . $cmd;
		}
		WP_CLI::do_hook( "before_invoke:{$cmd}" );

		call_user_func( $this->when_invoked, $args, array_merge( $extra_args, $assoc_args ) );

		if ( $parent ) {
			WP_CLI::do_hook( "after_invoke:{$parent}" );
		}
		WP_CLI::do_hook( "after_invoke:{$cmd}" );
	}

	/**
	 * Get an array of parameter names, by merging the command-specific and the
	 * global parameters.
	 *
	 * @param array  $spec Optional. Specification of the current command.
	 *
	 * @return array Array of parameter names
	 */
	
}
