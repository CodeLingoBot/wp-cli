<?php

namespace WP_CLI\Bootstrap;

/**
 * Class InitializeLogger.
 *
 * Initialize the logger through the `WP_CLI\Runner` object.
 *
 * @package WP_CLI\Bootstrap
 */
final class InitializeLogger implements BootstrapStep {

	/**
	 * Process this single bootstrapping step.
	 *
	 * @param BootstrapState $state Contextual state to pass into the step.
	 *
	 * @return BootstrapState Modified state to pass to the next step.
	 */
	public function process( BootstrapState $state ) {
		$this->declare_loggers();
		$runner = new RunnerInstance();
		$runner()->init_logger();

		return $state;
	}

	/**
	 * Load the class declarations for the loggers.
	 */
	
}
