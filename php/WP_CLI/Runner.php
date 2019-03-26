<?php

namespace WP_CLI;

use WP_CLI;
use WP_CLI\Utils;
use WP_CLI\Dispatcher;
use WP_CLI\Dispatcher\CompositeCommand;

/**
 * Performs the execution of a command.
 *
 * @package WP_CLI
 */
class Runner {

	private $global_config_path, $project_config_path;

	private $config, $extra_config;

	private $alias;

	private $aliases;

	private $arguments, $assoc_args, $runtime_config;

	private $colorize = false;

	private $_early_invoke = array();

	private $_global_config_path_debug;

	private $_project_config_path_debug;

	private $_required_files;

	public function __get( $key ) {
		if ( '_' === $key[0] ) {
			return null;
		}

		return $this->$key;
	}

	/**
	 * Register a command for early invocation, generally before WordPress loads.
	 *
	 * @param string $when Named execution hook
	 * @param WP_CLI\Dispatcher\Subcommand $command
	 */
	public function register_early_invoke( $when, $command ) {
		$this->_early_invoke[ $when ][] = array_slice( Dispatcher\get_path( $command ), 1 );
	}

	/**
	 * Perform the early invocation of a command.
	 *
	 * @param string $when Named execution hook
	 */
	

	/**
	 * Get the path to the global configuration YAML file.
	 *
	 * @return string|false
	 */
	public function get_global_config_path() {

		if ( getenv( 'WP_CLI_CONFIG_PATH' ) ) {
			$config_path                     = getenv( 'WP_CLI_CONFIG_PATH' );
			$this->_global_config_path_debug = 'Using global config from WP_CLI_CONFIG_PATH env var: ' . $config_path;
		} else {
			$config_path                     = Utils\get_home_dir() . '/.wp-cli/config.yml';
			$this->_global_config_path_debug = 'Using default global config: ' . $config_path;
		}

		if ( is_readable( $config_path ) ) {
			return $config_path;
		}

		$this->_global_config_path_debug = 'No readable global config found';

		return false;
	}

	/**
	 * Get the path to the project-specific configuration
	 * YAML file.
	 * wp-cli.local.yml takes priority over wp-cli.yml.
	 *
	 * @return string|false
	 */
	public function get_project_config_path() {
		$config_files = array(
			'wp-cli.local.yml',
			'wp-cli.yml',
		);

		// Stop looking upward when we find we have emerged from a subdirectory
		// installation into a parent installation
		$project_config_path = Utils\find_file_upward(
			$config_files,
			getcwd(),
			function ( $dir ) {
				static $wp_load_count = 0;
				$wp_load_path         = $dir . DIRECTORY_SEPARATOR . 'wp-load.php';
				if ( file_exists( $wp_load_path ) ) {
					++ $wp_load_count;
				}
				return $wp_load_count > 1;
			}
		);

		$this->_project_config_path_debug = 'No project config found';

		if ( ! empty( $project_config_path ) ) {
			$this->_project_config_path_debug = 'Using project config: ' . $project_config_path;
		}

		return $project_config_path;
	}

	/**
	 * Get the path to the packages directory
	 *
	 * @return string
	 */
	public function get_packages_dir_path() {
		if ( getenv( 'WP_CLI_PACKAGES_DIR' ) ) {
			$packages_dir = Utils\trailingslashit( getenv( 'WP_CLI_PACKAGES_DIR' ) );
		} else {
			$packages_dir = Utils\get_home_dir() . '/.wp-cli/packages/';
		}
		return $packages_dir;
	}

	/**
	 * Attempts to find the path to the WP installation inside index.php
	 *
	 * @param string $index_path
	 * @return string|false
	 */
	private static function extract_subdir_path( $index_path ) {
		$index_code = file_get_contents( $index_path );

		if ( ! preg_match( '|^\s*require\s*\(?\s*(.+?)/wp-blog-header\.php([\'"])|m', $index_code, $matches ) ) {
			return false;
		}

		$wp_path_src = $matches[1] . $matches[2];
		$wp_path_src = Utils\replace_path_consts( $wp_path_src, $index_path );

		$wp_path     = eval( "return $wp_path_src;" ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- @codingStandardsIgnoreLine

		if ( ! Utils\is_path_absolute( $wp_path ) ) {
			$wp_path = dirname( $index_path ) . "/$wp_path";
		}

		return $wp_path;
	}

	/**
	 * Find the directory that contains the WordPress files.
	 * Defaults to the current working dir.
	 *
	 * @return string An absolute path
	 */
	

	/**
	 * Set WordPress root as a given path.
	 *
	 * @param string $path
	 */
	private static function set_wp_root( $path ) {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', Utils\normalize_path( Utils\trailingslashit( $path ) ) );
		} elseif ( ! is_null( $path ) ) {
			WP_CLI::error_multi_line(
				array(
					'The --path parameter cannot be used when ABSPATH is already defined elsewhere',
					'ABSPATH is defined as: "' . ABSPATH . '"',
				)
			);
		}
		WP_CLI::debug( 'ABSPATH defined: ' . ABSPATH, 'bootstrap' );

		$_SERVER['DOCUMENT_ROOT'] = realpath( $path );
	}

	/**
	 * Guess which URL context WP-CLI has been invoked under.
	 *
	 * @param array $assoc_args
	 * @return string|false
	 */
	private static function guess_url( $assoc_args ) {
		if ( isset( $assoc_args['blog'] ) ) {
			$assoc_args['url'] = $assoc_args['blog'];
		}

		if ( isset( $assoc_args['url'] ) ) {
			$url = $assoc_args['url'];
			if ( true === $url ) {
				WP_CLI::warning( 'The --url parameter expects a value.' );
			}
		}

		if ( isset( $url ) ) {
			return $url;
		}

		return false;
	}

	

	/**
	 * Given positional arguments, find the command to execute.
	 *
	 * @param array $args
	 * @return array|string Command, args, and path on success; error message on failure
	 */
	public function find_command_to_run( $args ) {
		$command = \WP_CLI::get_root_command();

		WP_CLI::do_hook( 'find_command_to_run_pre' );

		$cmd_path = array();

		while ( ! empty( $args ) && $command->can_have_subcommands() ) {
			$cmd_path[] = $args[0];
			$full_name  = implode( ' ', $cmd_path );

			$subcommand = $command->find_subcommand( $args );

			if ( ! $subcommand ) {
				if ( count( $cmd_path ) > 1 ) {
					$child       = array_pop( $cmd_path );
					$parent_name = implode( ' ', $cmd_path );
					$suggestion  = $this->get_subcommand_suggestion( $child, $command );
					return sprintf(
						"'%s' is not a registered subcommand of '%s'. See 'wp help %s' for available subcommands.%s",
						$child,
						$parent_name,
						$parent_name,
						! empty( $suggestion ) ? PHP_EOL . "Did you mean '{$suggestion}'?" : ''
					);
				}

				$suggestion = $this->get_subcommand_suggestion( $full_name, $command );

				return sprintf(
					"'%s' is not a registered wp command. See 'wp help' for available commands.%s",
					$full_name,
					! empty( $suggestion ) ? PHP_EOL . "Did you mean '{$suggestion}'?" : ''
				);
			}

			if ( $this->is_command_disabled( $subcommand ) ) {
				return sprintf(
					"The '%s' command has been disabled from the config file.",
					$full_name
				);
			}

			$command = $subcommand;
		}

		return array( $command, $args, $cmd_path );
	}

	/**
	 * Find the WP-CLI command to run given arguments, and invoke it.
	 *
	 * @param array $args        Positional arguments including command name
	 * @param array $assoc_args  Associative arguments for the command.
	 * @param array $options     Configuration options for the function.
	 */
	public function run_command( $args, $assoc_args = array(), $options = array() ) {
		WP_CLI::do_hook( 'before_run_command' );

		if ( ! empty( $options['back_compat_conversions'] ) ) {
			list( $args, $assoc_args ) = self::back_compat_conversions( $args, $assoc_args );
		}
		$r = $this->find_command_to_run( $args );
		if ( is_string( $r ) ) {
			WP_CLI::error( $r );
		}

		list( $command, $final_args, $cmd_path ) = $r;

		$name = implode( ' ', $cmd_path );

		$extra_args = array();

		if ( isset( $this->extra_config[ $name ] ) ) {
			$extra_args = $this->extra_config[ $name ];
		}

		WP_CLI::debug( 'Running command: ' . $name, 'bootstrap' );
		try {
			$command->invoke( $final_args, $assoc_args, $extra_args );
		} catch ( WP_CLI\Iterators\Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Show synopsis if the called command is a composite command
	 */
	public function show_synopsis_if_composite_command() {
		$r = $this->find_command_to_run( $this->arguments );
		if ( is_array( $r ) ) {
			list( $command ) = $r;

			if ( $command->can_have_subcommands() ) {
				$command->show_usage();
				exit;
			}
		}
	}

	

	/**
	 * Perform a command against a remote server over SSH (or a container using
	 * scheme of "docker" or "docker-compose").
	 *
	 * @param string $connection_string Passed connection string.
	 * @return void
	 */
	

	/**
	 * Generate a shell command from the parsed connection string.
	 *
	 * @param array  $bits       Parsed connection string.
	 * @param string $wp_command WP-CLI command to run.
	 * @return string
	 */
	

	/**
	 * Check whether a given command is disabled by the config
	 *
	 * @return bool
	 */
	public function is_command_disabled( $command ) {
		$path = implode( ' ', array_slice( \WP_CLI\Dispatcher\get_path( $command ), 1 ) );
		return in_array( $path, $this->config['disabled_commands'], true );
	}

	/**
	 * Returns wp-config.php code, skipping the loading of wp-settings.php
	 *
	 * @return string
	 */
	public function get_wp_config_code() {
		$wp_config_path = Utils\locate_wp_config();

		$wp_config_code = explode( "\n", file_get_contents( $wp_config_path ) );

		$found_wp_settings = false;

		$lines_to_run = array();

		foreach ( $wp_config_code as $line ) {
			if ( preg_match( '/^\s*require.+wp-settings\.php/', $line ) ) {
				$found_wp_settings = true;
				continue;
			}

			$lines_to_run[] = $line;
		}

		if ( ! $found_wp_settings ) {
			WP_CLI::error( 'Strange wp-config.php file: wp-settings.php is not loaded directly.' );
		}

		$source = implode( "\n", $lines_to_run );
		$source = Utils\replace_path_consts( $source, $wp_config_path );
		return preg_replace( '|^\s*\<\?php\s*|', '', $source );
	}

	/**
	 * Transparently convert deprecated syntaxes
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return array
	 */
	private static function back_compat_conversions( $args, $assoc_args ) {
		$top_level_aliases = array(
			'sql'  => 'db',
			'blog' => 'site',
		);
		if ( count( $args ) > 0 ) {
			foreach ( $top_level_aliases as $old => $new ) {
				if ( $old === $args[0] ) {
					$args[0] = $new;
					break;
				}
			}
		}

		// *-meta  ->  * meta
		if ( ! empty( $args ) && preg_match( '/(post|comment|user|network)-meta/', $args[0], $matches ) ) {
			array_shift( $args );
			array_unshift( $args, 'meta' );
			array_unshift( $args, $matches[1] );
		}

		// core (multsite-)install --admin_name=  ->  --admin_user=
		if ( count( $args ) > 0 && 'core' === $args[0] && isset( $assoc_args['admin_name'] ) ) {
			$assoc_args['admin_user'] = $assoc_args['admin_name'];
			unset( $assoc_args['admin_name'] );
		}

		// core config  ->  config create
		if ( array( 'core', 'config' ) === array_slice( $args, 0, 2 ) ) {
			list( $args[0], $args[1] ) = array( 'config', 'create' );
		}
		// core language  ->  language core
		if ( array( 'core', 'language' ) === array_slice( $args, 0, 2 ) ) {
			list( $args[0], $args[1] ) = array( 'language', 'core' );
		}

		// checksum core  ->  core verify-checksums
		if ( array( 'checksum', 'core' ) === array_slice( $args, 0, 2 ) ) {
			list( $args[0], $args[1] ) = array( 'core', 'verify-checksums' );
		}

		// checksum plugin  ->  plugin verify-checksums
		if ( array( 'checksum', 'plugin' ) === array_slice( $args, 0, 2 ) ) {
			list( $args[0], $args[1] ) = array( 'plugin', 'verify-checksums' );
		}

		// site create --site_id=  ->  site create --network_id=
		if ( count( $args ) >= 2 && 'site' === $args[0] && 'create' === $args[1] && isset( $assoc_args['site_id'] ) ) {
			$assoc_args['network_id'] = $assoc_args['site_id'];
			unset( $assoc_args['site_id'] );
		}

		// {plugin|theme} update-all  ->  {plugin|theme} update --all
		if ( count( $args ) > 1 && in_array( $args[0], array( 'plugin', 'theme' ), true )
			&& 'update-all' === $args[1]
		) {
			$args[1]           = 'update';
			$assoc_args['all'] = true;
		}

		// transient delete-expired  ->  transient delete --expired
		if ( count( $args ) > 1 && 'transient' === $args[0] && 'delete-expired' === $args[1] ) {
			$args[1]               = 'delete';
			$assoc_args['expired'] = true;
		}

		// transient delete-all  ->  transient delete --all
		if ( count( $args ) > 1 && 'transient' === $args[0] && 'delete-all' === $args[1] ) {
			$args[1]           = 'delete';
			$assoc_args['all'] = true;
		}

		// plugin scaffold  ->  scaffold plugin
		if ( array( 'plugin', 'scaffold' ) === array_slice( $args, 0, 2 ) ) {
			list( $args[0], $args[1] ) = array( $args[1], $args[0] );
		}

		// foo --help  ->  help foo
		if ( isset( $assoc_args['help'] ) ) {
			array_unshift( $args, 'help' );
			unset( $assoc_args['help'] );
		}

		// {post|user} list --ids  ->  {post|user} list --format=ids
		if ( count( $args ) > 1 && in_array( $args[0], array( 'post', 'user' ), true )
			&& 'list' === $args[1]
			&& isset( $assoc_args['ids'] )
		) {
			$assoc_args['format'] = 'ids';
			unset( $assoc_args['ids'] );
		}

		// --json  ->  --format=json
		if ( isset( $assoc_args['json'] ) ) {
			$assoc_args['format'] = 'json';
			unset( $assoc_args['json'] );
		}

		// --{version|info}  ->  cli {version|info}
		if ( empty( $args ) ) {
			$special_flags = array( 'version', 'info' );
			foreach ( $special_flags as $key ) {
				if ( isset( $assoc_args[ $key ] ) ) {
					$args = array( 'cli', $key );
					unset( $assoc_args[ $key ] );
					break;
				}
			}
		}

		// (post|comment|site|term) url  --> (post|comment|site|term) list --*__in --field=url
		if ( count( $args ) >= 2 && in_array( $args[0], array( 'post', 'comment', 'site', 'term' ), true ) && 'url' === $args[1] ) {
			switch ( $args[0] ) {
				case 'post':
					$post_ids                = array_slice( $args, 2 );
					$args                    = array( 'post', 'list' );
					$assoc_args['post__in']  = implode( ',', $post_ids );
					$assoc_args['post_type'] = 'any';
					$assoc_args['orderby']   = 'post__in';
					$assoc_args['field']     = 'url';
					break;
				case 'comment':
					$comment_ids               = array_slice( $args, 2 );
					$args                      = array( 'comment', 'list' );
					$assoc_args['comment__in'] = implode( ',', $comment_ids );
					$assoc_args['orderby']     = 'comment__in';
					$assoc_args['field']       = 'url';
					break;
				case 'site':
					$site_ids               = array_slice( $args, 2 );
					$args                   = array( 'site', 'list' );
					$assoc_args['site__in'] = implode( ',', $site_ids );
					$assoc_args['field']    = 'url';
					break;
				case 'term':
					$taxonomy = '';
					if ( isset( $args[2] ) ) {
						$taxonomy = $args[2];
					}
					$term_ids              = array_slice( $args, 3 );
					$args                  = array( 'term', 'list', $taxonomy );
					$assoc_args['include'] = implode( ',', $term_ids );
					$assoc_args['orderby'] = 'include';
					$assoc_args['field']   = 'url';
					break;
			}
		}

		// config get --[global|constant]=<global|constant> --> config get <name> --type=constant|variable
		// config get --> config list
		if ( count( $args ) === 2
			&& 'config' === $args[0]
			&& 'get' === $args[1] ) {
			if ( isset( $assoc_args['global'] ) ) {
				$name = $assoc_args['global'];
				$type = 'variable';
				unset( $assoc_args['global'] );
			} elseif ( isset( $assoc_args['constant'] ) ) {
				$name = $assoc_args['constant'];
				$type = 'constant';
				unset( $assoc_args['constant'] );
			}
			if ( ! empty( $name ) && ! empty( $type ) ) {
				$args[]             = $name;
				$assoc_args['type'] = $type;
			} else {
				// We had a 'config get' without a '<name>', so assume 'list' was wanted.
				$args[1] = 'list';
			}
		}

		return array( $args, $assoc_args );
	}

	/**
	 * Whether or not the output should be rendered in color
	 *
	 * @return bool
	 */
	public function in_color() {
		return $this->colorize;
	}

	public function init_colorization() {
		if ( 'auto' === $this->config['color'] ) {
			$this->colorize = ( ! \WP_CLI\Utils\isPiped() && ! \WP_CLI\Utils\is_windows() );
		} else {
			$this->colorize = $this->config['color'];
		}
	}

	public function init_logger() {
		if ( $this->config['quiet'] ) {
			$logger = new \WP_CLI\Loggers\Quiet();
		} else {
			$logger = new \WP_CLI\Loggers\Regular( $this->in_color() );
		}

		WP_CLI::set_logger( $logger );
	}

	public function get_required_files() {
		return $this->_required_files;
	}

	/**
	 * Do WordPress core files exist?
	 *
	 * @return bool
	 */
	

	/**
	 * Are WordPress core files readable?
	 *
	 * @return bool
	 */
	

	

	public function init_config() {
		$configurator = \WP_CLI::get_configurator();

		$argv = array_slice( $GLOBALS['argv'], 1 );

		$this->alias = null;
		if ( ! empty( $argv[0] ) && preg_match( '#' . Configurator::ALIAS_REGEX . '#', $argv[0], $matches ) ) {
			$this->alias = array_shift( $argv );
		}

		// File config
		{
			$this->global_config_path  = $this->get_global_config_path();
			$this->project_config_path = $this->get_project_config_path();

			$configurator->merge_yml( $this->global_config_path, $this->alias );
			$config                          = $configurator->to_array();
			$this->_required_files['global'] = $config[0]['require'];
			$configurator->merge_yml( $this->project_config_path, $this->alias );
			$config                           = $configurator->to_array();
			$this->_required_files['project'] = $config[0]['require'];
		}

		// Runtime config and args
		{
			list( $args, $assoc_args, $this->runtime_config ) = $configurator->parse_args( $argv );

			list( $this->arguments, $this->assoc_args ) = self::back_compat_conversions(
				$args,
				$assoc_args
			);

			$configurator->merge_array( $this->runtime_config );
		}

		list( $this->config, $this->extra_config ) = $configurator->to_array();
		$this->aliases                             = $configurator->get_aliases();
		if ( count( $this->aliases ) && ! isset( $this->aliases['@all'] ) ) {
			$this->aliases         = array_reverse( $this->aliases );
			$this->aliases['@all'] = 'Run command against every registered alias.';
			$this->aliases         = array_reverse( $this->aliases );
		}
		$this->_required_files['runtime'] = $this->config['require'];
	}

	

	

	

	public function start() {
		// Enable PHP error reporting to stderr if testing. Will need to be re-enabled after WP loads.
		if ( getenv( 'BEHAT_RUN' ) ) {
			$this->enable_error_reporting();
		}

		WP_CLI::debug( $this->_global_config_path_debug, 'bootstrap' );
		WP_CLI::debug( $this->_project_config_path_debug, 'bootstrap' );
		WP_CLI::debug( 'argv: ' . implode( ' ', $GLOBALS['argv'] ), 'bootstrap' );

		$this->check_root();
		if ( $this->alias ) {
			if ( '@all' === $this->alias && ! isset( $this->aliases['@all'] ) ) {
				WP_CLI::error( "Cannot use '@all' when no aliases are registered." );
			}

			if ( '@all' === $this->alias && is_string( $this->aliases['@all'] ) ) {
				$aliases = array_keys( $this->aliases );
				$k       = array_search( '@all', $aliases, true );
				unset( $aliases[ $k ] );
				$this->run_alias_group( $aliases );
				exit;
			}

			if ( ! array_key_exists( $this->alias, $this->aliases ) ) {
				$error_msg  = "Alias '{$this->alias}' not found.";
				$suggestion = Utils\get_suggestion( $this->alias, array_keys( $this->aliases ), $threshold = 2 );
				if ( $suggestion ) {
					$error_msg .= PHP_EOL . "Did you mean '{$suggestion}'?";
				}
				WP_CLI::error( $error_msg );
			}
			// Numerically indexed means a group of aliases
			if ( isset( $this->aliases[ $this->alias ][0] ) ) {
				$group_aliases = $this->aliases[ $this->alias ];
				$all_aliases   = array_keys( $this->aliases );
				$diff          = array_diff( $group_aliases, $all_aliases );
				if ( ! empty( $diff ) ) {
					WP_CLI::error( "Group '{$this->alias}' contains one or more invalid aliases: " . implode( ', ', $diff ) );
				}
				$this->run_alias_group( $group_aliases );
				exit;
			}

			$this->set_alias( $this->alias );
		}

		if ( empty( $this->arguments ) ) {
			$this->arguments[] = 'help';
		}

		// Protect 'cli info' from most of the runtime,
		// except when the command will be run over SSH
		if ( 'cli' === $this->arguments[0] && ! empty( $this->arguments[1] ) && 'info' === $this->arguments[1] && ! $this->config['ssh'] ) {
			$this->_run_command_and_exit();
		}

		if ( isset( $this->config['http'] ) && ! class_exists( '\WP_REST_CLI\Runner' ) ) {
			WP_CLI::error( "RESTful WP-CLI needs to be installed. Try 'wp package install wp-cli/restful'." );
		}

		if ( $this->config['ssh'] ) {
			$this->run_ssh_command( $this->config['ssh'] );
			return;
		}

		// Handle --path parameter
		self::set_wp_root( $this->find_wp_root() );

		// First try at showing man page - if help command and either haven't found 'version.php' or 'wp-config.php' (so won't be loading WP & adding commands) or help on subcommand.
		if ( $this->cmd_starts_with( array( 'help' ) )
			&& ( ! $this->wp_exists()
				|| ! Utils\locate_wp_config()
				|| count( $this->arguments ) > 2
			) ) {
			$this->auto_check_update();
			$this->run_command( $this->arguments, $this->assoc_args );
			// Help didn't exit so failed to find the command at this stage.
		}

		// Handle --url parameter
		$url = self::guess_url( $this->config );
		if ( $url ) {
			\WP_CLI::set_url( $url );
		}

		$this->do_early_invoke( 'before_wp_load' );

		$this->check_wp_version();

		if ( $this->cmd_starts_with( array( 'config', 'create' ) ) ) {
			$this->_run_command_and_exit();
		}

		if ( ! Utils\locate_wp_config() ) {
			WP_CLI::error(
				"'wp-config.php' not found.\n" .
				'Either create one manually or use `wp config create`.'
			);
		}

		if ( $this->cmd_starts_with( array( 'core', 'is-installed' ) )
			|| $this->cmd_starts_with( array( 'core', 'update-db' ) ) ) {
			define( 'WP_INSTALLING', true );
		}

		if (
			count( $this->arguments ) >= 2 &&
			'core' === $this->arguments[0] &&
			in_array( $this->arguments[1], array( 'install', 'multisite-install' ), true )
		) {
			define( 'WP_INSTALLING', true );

			// We really need a URL here
			if ( ! isset( $_SERVER['HTTP_HOST'] ) ) {
				$url = 'http://example.com';
				\WP_CLI::set_url( $url );
			}

			if ( 'multisite-install' === $this->arguments[1] ) {
				// need to fake some globals to skip the checks in wp-includes/ms-settings.php
				$url_parts = Utils\parse_url( $url );
				self::fake_current_site_blog( $url_parts );

				if ( ! defined( 'COOKIEHASH' ) ) {
					define( 'COOKIEHASH', md5( $url_parts['host'] ) );
				}
			}
		}

		if ( $this->cmd_starts_with( array( 'import' ) ) ) {
			define( 'WP_LOAD_IMPORTERS', true );
			define( 'WP_IMPORTING', true );
		}

		if ( $this->cmd_starts_with( array( 'cron', 'event', 'run' ) ) ) {
			define( 'DOING_CRON', true );
		}

		$this->load_wordpress();

		$this->_run_command_and_exit();

	}

	/**
	 * Load WordPress, if it hasn't already been loaded
	 */
	public function load_wordpress() {
		static $wp_cli_is_loaded;
		// Globals not explicitly globalized in WordPress
		global $site_id, $wpdb, $public, $current_site, $current_blog, $path, $shortcode_tags;

		if ( ! empty( $wp_cli_is_loaded ) ) {
			return;
		}

		$wp_cli_is_loaded = true;

		WP_CLI::debug( 'Begin WordPress load', 'bootstrap' );
		WP_CLI::do_hook( 'before_wp_load' );

		$this->check_wp_version();

		$wp_config_path = Utils\locate_wp_config();
		if ( ! $wp_config_path ) {
			WP_CLI::error(
				"'wp-config.php' not found.\n" .
				'Either create one manually or use `wp config create`.'
			);
		}

		WP_CLI::debug( 'wp-config.php path: ' . $wp_config_path, 'bootstrap' );
		WP_CLI::do_hook( 'before_wp_config_load' );

		// Load wp-config.php code, in the global scope
		$wp_cli_original_defined_vars = get_defined_vars();

		eval( $this->get_wp_config_code() ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- @codingStandardsIgnoreLine

		foreach ( get_defined_vars() as $key => $var ) {
			if ( array_key_exists( $key, $wp_cli_original_defined_vars ) || 'wp_cli_original_defined_vars' === $key ) {
				continue;
			}
			// @codingStandardsIgnoreLine
			global ${$key};
			${$key} = $var;
		}

		$this->maybe_update_url_from_domain_constant();
		WP_CLI::do_hook( 'after_wp_config_load' );
		$this->do_early_invoke( 'after_wp_config_load' );

		// Prevent error notice from wp_guess_url() when core isn't installed
		if ( $this->cmd_starts_with( array( 'core', 'is-installed' ) )
			&& ! defined( 'COOKIEHASH' ) ) {
			define( 'COOKIEHASH', md5( 'wp-cli' ) );
		}

		// Load WP-CLI utilities
		require WP_CLI_ROOT . '/php/utils-wp.php';

		// Set up WordPress bootstrap actions and filters
		$this->setup_bootstrap_hooks();

		// Load Core, mu-plugins, plugins, themes etc.
		if ( Utils\wp_version_compare( '4.6-alpha-37575', '>=' ) ) {
			if ( $this->cmd_starts_with( array( 'help' ) ) ) {
				// Hack: define `WP_DEBUG` and `WP_DEBUG_DISPLAY` to get `wpdb::bail()` to `wp_die()`.
				if ( ! defined( 'WP_DEBUG' ) ) {
					define( 'WP_DEBUG', true );
				}
				if ( ! defined( 'WP_DEBUG_DISPLAY' ) ) {
					define( 'WP_DEBUG_DISPLAY', true );
				}
			}
			require ABSPATH . 'wp-settings.php';
		} else {
			require WP_CLI_ROOT . '/php/wp-settings-cli.php';
		}

		// Fix memory limit. See http://core.trac.wordpress.org/ticket/14889
		ini_set( 'memory_limit', -1 );

		// Load all the admin APIs, for convenience
		require ABSPATH . 'wp-admin/includes/admin.php';

		add_filter(
			'filesystem_method',
			function() {
				return 'direct';
			},
			99
		);

		// Re-enable PHP error reporting to stderr if testing.
		if ( getenv( 'BEHAT_RUN' ) ) {
			$this->enable_error_reporting();
		}

		WP_CLI::debug( 'Loaded WordPress', 'bootstrap' );
		WP_CLI::do_hook( 'after_wp_load' );

	}

	private static function fake_current_site_blog( $url_parts ) {
		global $current_site, $current_blog;

		if ( ! isset( $url_parts['path'] ) ) {
			$url_parts['path'] = '/';
		}

		$current_site = (object) array(
			'id'            => 1,
			'blog_id'       => 1,
			'domain'        => $url_parts['host'],
			'path'          => $url_parts['path'],
			'cookie_domain' => $url_parts['host'],
			'site_name'     => 'Fake Site',
		);

		$current_blog = (object) array(
			'blog_id'  => 1,
			'site_id'  => 1,
			'domain'   => $url_parts['host'],
			'path'     => $url_parts['path'],
			'public'   => '1',
			'archived' => '0',
			'mature'   => '0',
			'spam'     => '0',
			'deleted'  => '0',
			'lang_id'  => '0',
		);
	}

	/**
	 * Called after wp-config.php is eval'd, to potentially reset `--url`
	 */
	

	/**
	 * Set up hooks meant to run during the WordPress bootstrap process
	 */
	

	/**
	 * Set up the filters to skip the loaded plugins
	 */
	

	/**
	 * Set up the filters to skip the loaded theme
	 */
	public function action_setup_theme_wp_cli_skip_themes() {
		$wp_cli_filter_active_theme = function( $value ) {
			$skipped_themes = WP_CLI::get_runner()->config['skip-themes'];
			if ( true === $skipped_themes ) {
				return '';
			}
			if ( ! is_array( $skipped_themes ) ) {
				$skipped_themes = explode( ',', $skipped_themes );
			}

			$checked_value = $value;
			// Always check against the stylesheet value
			// This ensures a child theme can be skipped when template differs
			if ( false !== stripos( current_filter(), 'option_template' ) ) {
				$checked_value = get_option( 'stylesheet' );
			}

			if ( '' === $checked_value || in_array( $checked_value, $skipped_themes, true ) ) {
				return '';
			}
			return $value;
		};
		$hooks                      = array(
			'pre_option_template',
			'option_template',
			'pre_option_stylesheet',
			'option_stylesheet',
		);
		foreach ( $hooks as $hook ) {
			add_filter( $hook, $wp_cli_filter_active_theme, 999 );
		}
		// Clean up after the TEMPLATEPATH and STYLESHEETPATH constants are defined
		WP_CLI::add_wp_hook(
			'after_setup_theme',
			function() use ( $hooks, $wp_cli_filter_active_theme ) {
				foreach ( $hooks as $hook ) {
					remove_filter( $hook, $wp_cli_filter_active_theme, 999 );
				}
			},
			0
		);
	}

	/**
	 * Whether or not this WordPress installation is multisite.
	 *
	 * For use after wp-config.php has loaded, but before the rest of WordPress
	 * is loaded.
	 */
	

	/**
	 * Error handler for `wp_die()` when the command is help to try to trap errors (db connection failure in particular) during WordPress load.
	 */
	public function help_wp_die_handler( $message ) {
		$help_exit_warning = 'Error during WordPress load.';
		if ( $message instanceof \WP_Error ) {
			$help_exit_warning = WP_CLI\Utils\wp_clean_error_message( $message->get_error_message() );
		} elseif ( is_string( $message ) ) {
			$help_exit_warning = WP_CLI\Utils\wp_clean_error_message( $message );
		}
		$this->_run_command_and_exit( $help_exit_warning );
	}

	/**
	 * Check whether there's a WP-CLI update available, and suggest update if so.
	 */
	

	/**
	 * Get a suggestion on similar (sub)commands when the user entered an
	 * unknown (sub)command.
	 *
	 * @param string           $entry        User entry that didn't match an
	 *                                       existing command.
	 * @param CompositeCommand $root_command Root command to start search for
	 *                                       suggestions at.
	 *
	 * @return string Suggestion that fits the user entry, or an empty string.
	 */
	

	/**
	 * Recursive method to enumerate all known commands.
	 *
	 * @param CompositeCommand $command Composite command to recurse over.
	 * @param array            $list    Reference to list accumulating results.
	 * @param string           $parent  Parent command to use as prefix.
	 */
	

	/**
	 * Enables (almost) full PHP error reporting to stderr.
	 */
	
}
