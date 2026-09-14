<?php

/**
 * @file JournalPaymentV105Plugin.inc.php
 *
 * @class JournalPaymentV105Plugin
 * @brief Manual publication-payment management for OJS 3.3.
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class JournalPaymentV105Plugin extends GenericPlugin {

	// Preserve the existing plugin-settings identity across the class rename.
	public function getName() {
		return 'journalpaymentplugin';
	}

	public function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		if ($success) {
			// OJS file-based locale caches can remain stale after a plugin upgrade.
			// Supply this plugin's strings directly when OJS reports a missing key.
			HookRegistry::register('PKPLocale::translate', array($this, 'provideMissingTranslation'));
		}
		// Register the current locale explicitly. Some OJS 3.3 installations use
		// a non-standard locale code (for example "en" instead of "en_US").
		// getLocaleFilename() below provides a safe fallback for those systems.
		if ($success) {
			$this->addLocaleData(AppLocale::getLocale());
		}
		if ($success && $this->getEnabled($mainContextId)) {
			HookRegistry::register('LoadHandler', array($this, 'handleRequest'));
		}
		return $success;
	}

	public function getDisplayName() {
		return 'Journal Payment / Pembayaran Jurnal';
	}

	public function getDescription() {
		return 'Mengelola pembayaran publikasi, verifikasi bukti, status, kuitansi, LOA, dan Sertifikat Publikasi di dalam OJS.';
	}

	/**
	 * Resolve locale files robustly on OJS installations that use a short or
	 * custom English/Indonesian locale code.
	 */
	public function getLocaleFilename($locale) {
		$locale = preg_replace('/[^A-Za-z0-9_@.-]/', '', (string) $locale);
		$candidates = array($locale);
		if (stripos($locale, 'id') === 0 || stripos($locale, 'in') === 0) {
			$candidates[] = 'id_ID';
		}
		if (stripos($locale, 'en') === 0) {
			$candidates[] = 'en_US';
		}
		$candidates[] = 'en_US';
		foreach (array_unique($candidates) as $candidate) {
			$file = $this->getPluginPath() . '/locale/' . $candidate . '/locale.po';
			if (file_exists($file)) return array($file);
		}
		return array();
	}

	/**
	 * Bypass stale locale caches for this plugin only. Other plugins and core
	 * translations continue through the normal OJS translation pipeline.
	 */
	public function provideMissingTranslation($hookName, $args) {
		$key =& $args[0];
		$params =& $args[1];
		$locale =& $args[2];
		$value =& $args[4];
		if (strpos($key, 'plugins.generic.journalPayment.') !== 0) return false;

		static $catalogues = array();
		$files = $this->getLocaleFilename($locale);
		foreach ($files as $file) {
			if (!isset($catalogues[$file])) {
				$catalogues[$file] = LocaleFile::load($file);
			}
			if (!isset($catalogues[$file][$key])) continue;
			$value = $catalogues[$file][$key];
			foreach ((array) $params as $paramName => $paramValue) {
				$value = str_replace('{$' . $paramName . '}', $paramValue === null ? '' : $paramValue, $value);
			}
			return true;
		}
		return false;
	}

	public function getInstallMigration() {
		$this->import('JournalPaymentSchemaMigration');
		return new JournalPaymentSchemaMigration();
	}

	public function handleRequest($hookName, $args) {
		$page =& $args[0];
		if ($page !== 'journalPayment') {
			return false;
		}
		define('HANDLER_CLASS', 'JournalPaymentHandler');
		$this->import('JournalPaymentHandler');
		JournalPaymentHandler::setPlugin($this);
		return true;
	}

	public function getActions($request, $actionArgs) {
		$actions = parent::getActions($request, $actionArgs);
		if (!$this->getEnabled()) {
			return $actions;
		}

		$router = $request->getRouter();
		$dispatcher = $request->getDispatcher();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		import('lib.pkp.classes.linkAction.request.RedirectAction');

		array_unshift($actions, new LinkAction(
			'openDashboard',
			new RedirectAction($dispatcher->url($request, ROUTE_PAGE, null, 'journalPayment', 'manage')),
			'Kelola Pembayaran',
			null
		));
		array_unshift($actions, new LinkAction(
			'settings',
			new AjaxModal(
				$router->url($request, null, null, 'manage', null, array(
					'verb' => 'settings',
					'plugin' => $this->getName(),
					'category' => 'generic'
				)),
				$this->getDisplayName()
			),
			__('manager.plugins.settings'),
			null
		));
		return $actions;
	}

	public function manage($args, $request) {
		if ($request->getUserVar('verb') === 'settings') {
			$this->import('JournalPaymentSettingsForm');
			$form = new JournalPaymentSettingsForm($this);
			if (!$request->getUserVar('save')) {
				$form->initData();
				return new JSONMessage(true, $form->fetch($request));
			}
			$form->readInputData();
			if ($form->validate()) {
				$form->execute();
				return new JSONMessage(true);
			}
			return new JSONMessage(true, $form->fetch($request));
		}
		return parent::manage($args, $request);
	}

	public function getAssetUrl($request) {
		return $request->getBaseUrl() . '/' . $this->getPluginPath();
	}

	public function getPackages($contextId) {
		$raw = trim((string) $this->getSetting($contextId, 'packages'));
		if (!$raw) {
			$raw = "Article Processing Charge|750000\nFast Track Review|1000000\nLayout dan Proofreading|350000";
		}
		$packages = array();
		foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
			$parts = array_map('trim', explode('|', $line, 2));
			if (count($parts) !== 2 || $parts[0] === '') continue;
			$amount = preg_replace('/[^0-9]/', '', $parts[1]);
			if ($amount === '') continue;
			$packages[] = array('name' => $parts[0], 'amount' => (int) $amount);
		}
		return $packages;
	}
}
