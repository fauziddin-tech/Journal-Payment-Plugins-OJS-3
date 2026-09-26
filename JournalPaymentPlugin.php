<?php

/**
 * @file JournalPaymentPlugin.php
 *
 * @class JournalPaymentPlugin
 * @brief Manual publication-payment management for OJS 3.5.
 */

namespace APP\plugins\generic\journalPayment;

use APP\core\Application;
use APP\template\TemplateManager;
use APP\plugins\generic\journalPayment\controllers\JournalPaymentDashboardHandler;
use PKP\config\Config;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class JournalPaymentPlugin extends GenericPlugin {
	const ASSET_VERSION = '2.0.0';

	/** Preserve the plugin-settings identity used by every 1.x release. */
	public function getName() {
		return 'journalpaymentplugin';
	}

	public function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		// Locale files in locale/<code>/locale.po are registered by LazyLoadPlugin.
		// The page hook is always registered; handleRequest() checks the journal state.
		if ($success) {
			Hook::add('LoadHandler', $this->handleRequest(...));
			Hook::add('LoadComponentHandler', $this->setupDashboardHandler(...));
			Hook::add('Template::Settings::website', $this->showWebsiteSettingsTab(...));
			Hook::add('TemplateManager::display', $this->addBackendAssets(...));
		}
		return $success;
	}

	public function getDisplayName() {
		return 'Journal Payment / Pembayaran Jurnal';
	}

	public function getDescription() {
		return 'Mengelola pembayaran publikasi, verifikasi bukti, status, kuitansi, LOA, dan Sertifikat Publikasi di dalam OJS.';
	}

	public function getAssetVersion() {
		return self::ASSET_VERSION;
	}

	/** IDs explicitly trusted to manage every payment in a journal. */
	public function getFullPaymentManagerIds($contextId) {
		$value = $this->getSetting((int) $contextId, 'fullAccessUserIds');
		if (is_array($value)) $parts = $value;
		else $parts = preg_split('/[\s,;]+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY);
		$ids = array();
		foreach ((array) $parts as $part) {
			$id = (int) $part;
			if ($id > 0) $ids[$id] = $id;
		}
		return array_values($ids);
	}

	/** Site administrators are always trusted; journal users require explicit selection. */
	public function userHasFullPaymentAccess($user, $contextId) {
		if (!$user) return false;
		if ($user->hasRole(array(Role::ROLE_ID_SITE_ADMIN), Application::SITE_CONTEXT_ID)) return true;
		$userId = (int) $user->getId();
		if (!in_array($userId, $this->getFullPaymentManagerIds($contextId), true)) return false;
		return $user->hasRole(array(Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR), (int) $contextId);
	}

	/**
	 * Smarty 4 in OJS 3.5 only whitelists some PHP functions as modifiers.
	 * Register the two used by the payment templates explicitly.
	 */
	public static function registerTemplateModifiers($templateMgr) {
		foreach (array('number_format', 'nl2br') as $modifier) {
			if (empty($templateMgr->registered_plugins['modifier'][$modifier])) {
				$templateMgr->registerPlugin('modifier', $modifier, $modifier);
			}
		}
	}

	public function getInstallMigration() {
		return new JournalPaymentSchemaMigration();
	}

	/**
	 * OJS 3.5 no longer accepts HANDLER_CLASS; the handler instance is passed
	 * back through the fourth LoadHandler argument.
	 */
	public function handleRequest($hookName, $args) {
		$page = $args[0];
		$handler = &$args[3];
		if ($page !== 'journalPayment') return false;
		$context = Application::get()->getRequest()->getContext();
		if (!$context || !$this->getEnabled($context->getId())) return false;
		$handler = new JournalPaymentHandler($this);
		return true;
	}

	/**
	 * Add the payment dashboard as a native Website Settings tab, beside the
	 * Static Pages tab. The route remains available as a compatibility fallback.
	 */
	public function showWebsiteSettingsTab($hookName, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		if (!$context || !$this->getEnabled($context->getId())) return false;
		$templateMgr = $args[1];
		$output = &$args[2];
		$templateMgr->assign(array(
			'journalPaymentDocumentMail' => trim((string) $request->getUserVar('documentMail')),
			'journalPaymentRecordResult' => trim((string) $request->getUserVar('recordResult')),
			'journalPaymentFilter' => trim((string) $request->getUserVar('filter')),
			'journalPaymentQuery' => trim((string) $request->getUserVar('q')),
			'journalPaymentPage' => max(1, (int) $request->getUserVar('p')),
			'journalPaymentView' => trim((string) $request->getUserVar('view')),
			'journalPaymentDecisionError' => trim((string) $request->getUserVar('decisionError')),
			'journalPaymentDriveFolder' => trim((string) $request->getUserVar('driveFolder')),
			'journalPaymentDriveResult' => trim((string) $request->getUserVar('driveResult')),
			'journalPaymentFinanceResult' => trim((string) $request->getUserVar('financeResult')),
			'journalPaymentProductionResult' => trim((string) $request->getUserVar('productionResult')),
		));
		$output .= $templateMgr->fetch($this->getTemplateResource('dashboardTab.tpl'));
		return false;
	}

	/**
	 * The Vue 3 backend ignores <script>/<style> tags inside tab templates, so
	 * the dashboard assets are registered on the Website Settings page itself.
	 */
	public function addBackendAssets($hookName, $args) {
		$templateMgr = $args[0];
		$template = $args[1];
		if (!is_string($template) || strpos($template, 'management/website') === false) return false;
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		if (!$context || !$this->getEnabled($context->getId())) return false;
		$base = $this->getAssetUrl($request);
		$version = $this->getAssetVersion();
		$templateMgr->addStyleSheet('journalPaymentBackend', $base . '/styles/payment.css?v=' . $version, array('contexts' => array('backend')));
		$templateMgr->addJavaScript('journalPaymentBackend', $base . '/js/manage.js?v=' . $version, array('contexts' => array('backend')));
		return false;
	}

	/** Register the component that supplies the dashboard tab content. */
	public function setupDashboardHandler($hookName, $args) {
		$component = $args[0];
		$componentInstance = &$args[2];
		if ($component !== 'plugins.generic.journalPayment.controllers.JournalPaymentDashboardHandler') return false;
		$componentInstance = new JournalPaymentDashboardHandler($this);
		return true;
	}

	public function getActions($request, $actionArgs) {
		$actions = parent::getActions($request, $actionArgs);
		$context = $request->getContext();
		if (!$context || !$this->getEnabled($context->getId())) {
			return $actions;
		}
		if (!$this->userHasFullPaymentAccess($request->getUser(), $context->getId())) return $actions;

		$router = $request->getRouter();
		$dispatcher = $request->getDispatcher();

		array_unshift($actions, new LinkAction(
			'openDashboard',
			new RedirectAction($dispatcher->url(
				$request,
				Application::ROUTE_PAGE,
				null,
				'management',
				'settings',
				array('website'),
				array('uid' => uniqid()),
				'journalPayment'
			)),
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
			$context = $request->getContext();
			if (!$context || !$this->userHasFullPaymentAccess($request->getUser(), $context->getId())) {
				return new JSONMessage(false, 'Hanya pengelola pembayaran berakses penuh yang dapat membuka pengaturan plugin.');
			}
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

	/** Return a private document image as a browser-safe data URI. */
	public function getDocumentAssetDataUri($contextId, $settingName) {
		if (!in_array($settingName, array('documentLogoFile', 'signatureFile', 'stampFile'), true)) return '';
		$fileName = basename(trim((string) $this->getSetting($contextId, $settingName)));
		if ($fileName === '') return '';
		$path = $this->getDocumentAssetDirectory($contextId) . DIRECTORY_SEPARATOR . $fileName;
		if (!is_file($path) || filesize($path) > 2097152) return '';
		$finfo = new \finfo(FILEINFO_MIME_TYPE);
		$mime = (string) $finfo->file($path);
		if (!in_array($mime, array('image/png', 'image/jpeg', 'image/webp'), true)) return '';
		$bytes = file_get_contents($path);
		return $bytes === false ? '' : 'data:' . $mime . ';base64,' . base64_encode($bytes);
	}

	/** Keep Google OAuth secrets in OJS files_dir, outside the public plugin path. */
	public function getGoogleDriveCredentials($contextId) {
		$path = $this->getGoogleDriveCredentialPath($contextId);
		if (!is_file($path) || filesize($path) > 65536) return array();
		$data = json_decode((string) file_get_contents($path), true);
		return is_array($data) ? $data : array();
	}

	public function hasGoogleDriveCredentials($contextId) {
		$data = $this->getGoogleDriveCredentials($contextId);
		return !empty($data['client_id']) && !empty($data['client_secret']) && !empty($data['refresh_token']);
	}

	public function saveGoogleDriveCredentials($contextId, $credentials) {
		$required = array('client_id', 'client_secret', 'refresh_token');
		foreach ($required as $key) if (empty($credentials[$key]) || !is_string($credentials[$key])) return false;
		$directory = dirname($this->getGoogleDriveCredentialPath($contextId));
		if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) return false;
		$path = $this->getGoogleDriveCredentialPath($contextId);
		$tmp = $path . '.tmp-' . str_replace('.', '', uniqid('', true));
		$bytes = json_encode($credentials, JSON_UNESCAPED_SLASHES);
		if ($bytes === false || file_put_contents($tmp, $bytes, LOCK_EX) === false) return false;
		@chmod($tmp, 0600);
		if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
		@chmod($path, 0600);
		return true;
	}

	public function deleteGoogleDriveCredentials($contextId) {
		$path = $this->getGoogleDriveCredentialPath($contextId);
		if (is_file($path)) @unlink($path);
	}

	private function getGoogleDriveCredentialPath($contextId) {
		return rtrim(Config::getVar('files', 'files_dir'), DIRECTORY_SEPARATOR)
			. DIRECTORY_SEPARATOR . 'journalPayment' . DIRECTORY_SEPARATOR . 'google-drive'
			. DIRECTORY_SEPARATOR . (int) $contextId . DIRECTORY_SEPARATOR . 'oauth.json';
	}

	/** Save a validated document image outside the public web root. */
	public function saveDocumentAsset($contextId, $settingName, $bytes, $mime) {
		if (!in_array($settingName, array('documentLogoFile', 'signatureFile', 'stampFile'), true)) return false;
		$extensions = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp');
		if (!isset($extensions[$mime])) return false;
		$directory = $this->getDocumentAssetDirectory($contextId);
		if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) return false;
		try {
			$token = bin2hex(random_bytes(12));
		} catch (\Exception $e) {
			$token = str_replace('.', '', uniqid('', true));
		}
		$fileName = strtolower($settingName) . '-' . $token . '.' . $extensions[$mime];
		$path = $directory . DIRECTORY_SEPARATOR . $fileName;
		if (file_put_contents($path, $bytes, LOCK_EX) === false) return false;
		@chmod($path, 0640);
		$oldFile = basename(trim((string) $this->getSetting($contextId, $settingName)));
		$this->updateSetting($contextId, $settingName, $fileName);
		if ($oldFile !== '' && $oldFile !== $fileName) {
			$oldPath = $directory . DIRECTORY_SEPARATOR . $oldFile;
			if (is_file($oldPath)) @unlink($oldPath);
		}
		return true;
	}

	public function deleteDocumentAsset($contextId, $settingName) {
		if (!in_array($settingName, array('documentLogoFile', 'signatureFile', 'stampFile'), true)) return;
		$fileName = basename(trim((string) $this->getSetting($contextId, $settingName)));
		if ($fileName !== '') {
			$path = $this->getDocumentAssetDirectory($contextId) . DIRECTORY_SEPARATOR . $fileName;
			if (is_file($path)) @unlink($path);
		}
		$this->updateSetting($contextId, $settingName, '');
	}

	private function getDocumentAssetDirectory($contextId) {
		return rtrim(Config::getVar('files', 'files_dir'), DIRECTORY_SEPARATOR)
			. DIRECTORY_SEPARATOR . 'journalPayment' . DIRECTORY_SEPARATOR . 'document-assets'
			. DIRECTORY_SEPARATOR . (int) $contextId;
	}

	public function getPackages($contextId) {
		$raw = trim((string) $this->getSetting($contextId, 'packages'));
		if (!$raw) {
			$raw = "Article Processing Charge|750000|60\nFast Track Review|1000000|30\nLayout dan Proofreading|350000|60";
		}
		$packages = array();
		foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
			$parts = array_map('trim', explode('|', $line));
			if (count($parts) < 2 || $parts[0] === '') continue;
			$amount = preg_replace('/[^0-9]/', '', $parts[1]);
			if ($amount === '') continue;
			$durationDays = isset($parts[2]) ? (int) preg_replace('/[^0-9]/', '', $parts[2]) : 0;
			if ($durationDays < 1) $durationDays = $this->inferPublicationDays($parts[0]);
			$packages[] = array('name' => $parts[0], 'amount' => (int) $amount, 'duration_days' => min(730, $durationDays));
		}
		return $packages;
	}

	public function getPackageDurationDays($contextId, $packageName) {
		foreach ($this->getPackages($contextId) as $package) {
			if ($package['name'] === (string) $packageName) return (int) $package['duration_days'];
		}
		return min(730, $this->inferPublicationDays($packageName));
	}

	private function inferPublicationDays($packageName) {
		$name = strtolower((string) $packageName);
		if (preg_match('/(\d+)\s*(?:-|–|sampai|to)\s*\d+\s*(?:bulan|month)/i', $name, $matches)) return max(1, (int) $matches[1]) * 30;
		if (preg_match('/(\d+)\s*(?:bulan|month)/i', $name, $matches)) return max(1, (int) $matches[1]) * 30;
		if (preg_match('/prioritas|fast|express|ekspres/i', $name)) return 30;
		return 60;
	}
}

// Releases 1.0.3 through 1.0.13 stored version-specific class names in the
// OJS versions table. Keep namespaced aliases so an upgraded installation
// still passes PluginRegistry's class check before the versions row refreshes.
foreach (array(
	'JournalPaymentV103Plugin', 'JournalPaymentV104Plugin', 'JournalPaymentV105Plugin',
	'JournalPaymentV106Plugin', 'JournalPaymentV107Plugin', 'JournalPaymentV108Plugin',
	'JournalPaymentV109Plugin', 'JournalPaymentV110Plugin', 'JournalPaymentV111Plugin',
	'JournalPaymentV112Plugin', 'JournalPaymentV113Plugin',
) as $journalPaymentLegacyClass) {
	if (!class_exists(__NAMESPACE__ . '\\' . $journalPaymentLegacyClass, false)) {
		class_alias(JournalPaymentPlugin::class, __NAMESPACE__ . '\\' . $journalPaymentLegacyClass);
	}
}
unset($journalPaymentLegacyClass);
