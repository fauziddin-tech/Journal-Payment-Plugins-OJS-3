<?php

/**
 * @file controllers/JournalPaymentDashboardHandler.php
 * @brief AJAX component for the Website Settings payment tab.
 */

namespace APP\plugins\generic\journalPayment\controllers;

use APP\handler\Handler;
use APP\plugins\generic\journalPayment\JournalPaymentHandler;
use PKP\core\JSONMessage;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\Role;

class JournalPaymentDashboardHandler extends Handler {
	/** @var \APP\plugins\generic\journalPayment\JournalPaymentPlugin */
	private static $plugin;

	public function __construct($plugin = null) {
		parent::__construct();
		if ($plugin) self::$plugin = $plugin;
		$this->addRoleAssignment(array(Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT), array('index', 'fetch'));
	}

	public function authorize($request, &$args, $roleAssignments) {
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
		$handler = new JournalPaymentHandler(self::$plugin);
		return $handler->fetchManageContent($args, $request);
	}
}
