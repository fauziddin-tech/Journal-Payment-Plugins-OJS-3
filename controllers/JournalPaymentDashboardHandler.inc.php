<?php

/**
 * @file controllers/JournalPaymentDashboardHandler.inc.php
 * @brief AJAX component for the Website Settings payment tab.
 */

import('classes.handler.Handler');
import('lib.pkp.classes.core.JSONMessage');

class JournalPaymentDashboardHandler extends Handler {
	/** @var JournalPaymentPlugin */
	private static $plugin;

	public static function setPlugin($plugin) {
		self::$plugin = $plugin;
	}

	public function __construct() {
		parent::__construct();
		$this->addRoleAssignment(array(ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR, ROLE_ID_ASSISTANT), array('index', 'fetch'));
	}

	public function authorize($request, &$args, $roleAssignments) {
		import('lib.pkp.classes.security.authorization.ContextAccessPolicy');
		$this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
		return parent::authorize($request, $args, $roleAssignments);
	}

	public function index($args, $request) {
		return $this->fetch($args, $request);
	}

	public function fetch($args, $request) {
		$this->setupTemplate($request);
		$context = $request->getContext();
		if (!$context || !self::$plugin->getEnabled($context->getId())) {
			return new JSONMessage(false, 'Plugin Pembayaran tidak aktif pada jurnal ini.');
		}
		self::$plugin->import('JournalPaymentHandler');
		JournalPaymentHandler::setPlugin(self::$plugin);
		$handler = new JournalPaymentHandler();
		return $handler->fetchManageContent($args, $request);
	}
}
