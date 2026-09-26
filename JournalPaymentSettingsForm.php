<?php

/**
 * @file JournalPaymentSettingsForm.php
 * @brief Journal-level settings for Journal Payment (OJS 3.5).
 */

namespace APP\plugins\generic\journalPayment;

use APP\core\Application;
use APP\facades\Repo;
use APP\notification\NotificationManager;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;
use PKP\notification\Notification;
use PKP\security\Role;

class JournalPaymentSettingsForm extends Form {
	public $plugin;
	private $pendingAssets = array();
	private $pendingDriveCredentials = array();
	private $settings = array(
		'organizationName', 'bankInstructions', 'packages', 'paymentMethods',
		'maxUploadMb', 'accentColor', 'footerText', 'themeMode',
		'signerName', 'signerTitle', 'loaText', 'certificateText',
		'googleDriveFolderUrl', 'enableReminders', 'revisionDeadlineDays',
		'publicationWarningDays', 'editorFeePercent', 'productionEditorFee'
	);

	public function __construct($plugin) {
		parent::__construct($plugin->getTemplateResource('settings.tpl'));
		$this->plugin = $plugin;
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	public function initData() {
		$context = Application::get()->getRequest()->getContext();
		$defaults = array(
			'organizationName' => $context ? $context->getLocalizedName() : '',
			'bankInstructions' => "Silakan transfer ke rekening resmi jurnal, lalu unggah bukti pembayaran.",
			'packages' => "Article Processing Charge|750000|60\nFast Track Review|1000000|30\nLayout dan Proofreading|350000|60",
			'paymentMethods' => "Transfer Bank\nQRIS",
			'maxUploadMb' => '5',
			'accentColor' => '#1f6f5f',
			'footerText' => 'Pembayaran publikasi dikelola secara aman melalui sistem jurnal.',
			'themeMode' => 'auto',
			'signerName' => '',
			'signerTitle' => 'Editor in Chief',
			'loaText' => 'Dengan ini menyatakan bahwa artikel tersebut telah diterima untuk diterbitkan pada jurnal kami.',
			'certificateText' => 'Sertifikat ini diberikan sebagai bukti kontribusi penulis pada publikasi ilmiah di jurnal kami.',
			'googleDriveFolderUrl' => '',
			'enableReminders' => '0',
			'revisionDeadlineDays' => '14',
			'publicationWarningDays' => '7',
			'editorFeePercent' => '60',
			'productionEditorFee' => '100000'
		);
		foreach ($this->settings as $name) {
			$value = $this->plugin->getSetting($context->getId(), $name);
			$this->setData($name, $value === null ? $defaults[$name] : $value);
		}
		$this->setData('documentLogoPreview', $this->plugin->getDocumentAssetDataUri($context->getId(), 'documentLogoFile'));
		$this->setData('signaturePreview', $this->plugin->getDocumentAssetDataUri($context->getId(), 'signatureFile'));
		$this->setData('stampPreview', $this->plugin->getDocumentAssetDataUri($context->getId(), 'stampFile'));
		$this->setData('googleDriveConfigured', $this->plugin->hasGoogleDriveCredentials($context->getId()));
		$this->setData('fullAccessUserIds', $this->plugin->getFullPaymentManagerIds($context->getId()));
		parent::initData();
	}

	public function readInputData() {
		$this->readUserVars(array_merge($this->settings, array(
			'fullAccessUserIds',
			'documentLogoData', 'signatureData', 'stampData',
			'removeDocumentLogo', 'removeSignature', 'removeStamp',
			'googleDriveClientId', 'googleDriveClientSecret', 'googleDriveRefreshToken', 'removeGoogleDriveCredentials'
		)));
		parent::readInputData();
	}

	public function validate($callHooks = true) {
		$valid = parent::validate($callHooks);
		$max = (int) $this->getData('maxUploadMb');
		if ($max < 1 || $max > 20) {
			$this->addError('maxUploadMb', 'Batas unggahan harus antara 1 dan 20 MB.');
			$valid = false;
		}
		if (!preg_match('/^#[0-9a-fA-F]{6}$/', trim((string) $this->getData('accentColor')))) {
			$this->addError('accentColor', 'Warna harus menggunakan format #RRGGBB.');
			$valid = false;
		}
		if (!in_array($this->getData('themeMode'), array('auto', 'manual', 'standalone'), true)) {
			$this->addError('themeMode', 'Mode tampilan tidak valid.');
			$valid = false;
		}
		$revisionDays = (int) $this->getData('revisionDeadlineDays');
		if ($revisionDays < 1 || $revisionDays > 90) {
			$this->addError('revisionDeadlineDays', 'Batas waktu revisi harus antara 1 dan 90 hari.');
			$valid = false;
		}
		$warningDays = (int) $this->getData('publicationWarningDays');
		if ($warningDays < 1 || $warningDays > 60) {
			$this->addError('publicationWarningDays', 'Rentang peringatan publikasi harus antara 1 dan 60 hari.');
			$valid = false;
		}
		$editorFeePercent = (int) $this->getData('editorFeePercent');
		if ($editorFeePercent < 0 || $editorFeePercent > 100) {
			$this->addError('editorFeePercent', 'Persentase fee editor harus antara 0 dan 100.');
			$valid = false;
		}
		$productionEditorFee = (int) preg_replace('/[^0-9]/', '', (string) $this->getData('productionEditorFee'));
		if ($productionEditorFee < 0 || $productionEditorFee > 100000000) {
			$this->addError('productionEditorFee', 'Fee Production Editor harus antara Rp 0 dan Rp 100.000.000 per artikel.');
			$valid = false;
		}
		$this->setData('productionEditorFee', $productionEditorFee);
		$requestedFullAccess = $this->normalizeUserIds($this->getData('fullAccessUserIds'));
		$eligibleUserIds = array();
		foreach ($this->paymentStaffOptions() as $staff) $eligibleUserIds[(int) $staff['id']] = true;
		foreach ($requestedFullAccess as $userId) {
			if (!isset($eligibleUserIds[$userId])) {
				$this->addError('fullAccessUserIds', 'Akun berakses penuh harus masih memiliki peran pengelola/editor pada jurnal ini.');
				$valid = false;
				break;
			}
		}
		$this->setData('fullAccessUserIds', $requestedFullAccess);
		$googleDriveFolderUrl = trim((string) $this->getData('googleDriveFolderUrl'));
		if ($googleDriveFolderUrl !== '') {
			$parts = filter_var($googleDriveFolderUrl, FILTER_VALIDATE_URL) ? parse_url($googleDriveFolderUrl) : false;
			if (!$parts || strtolower(isset($parts['scheme']) ? $parts['scheme'] : '') !== 'https' || strtolower(isset($parts['host']) ? $parts['host'] : '') !== 'drive.google.com') {
				$this->addError('googleDriveFolderUrl', 'Gunakan tautan folder HTTPS dari drive.google.com.');
				$valid = false;
			}
		}
		$driveFields = array(
			'client_id' => trim((string) $this->getData('googleDriveClientId')),
			'client_secret' => trim((string) $this->getData('googleDriveClientSecret')),
			'refresh_token' => trim((string) $this->getData('googleDriveRefreshToken')),
		);
		$filledDriveFields = count(array_filter($driveFields, function ($value) { return $value !== ''; }));
		if ($filledDriveFields !== 0 && $filledDriveFields !== 3) {
			$this->addError('googleDriveClientId', 'Isi Client ID, Client Secret, dan Refresh Token secara lengkap.');
			$valid = false;
		} elseif ($filledDriveFields === 3) {
			foreach ($driveFields as $value) {
				if (strlen($value) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
					$this->addError('googleDriveClientId', 'Kredensial OAuth Google Drive tidak valid.');
					$valid = false;
					break;
				}
			}
			if ($valid) $this->pendingDriveCredentials = $driveFields;
		}
		$assetFields = array(
			'documentLogoData' => 'Logo jurnal',
			'signatureData' => 'Tanda tangan',
			'stampData' => 'Stempel',
		);
		foreach ($assetFields as $field => $label) {
			$data = trim((string) $this->getData($field));
			if ($data === '') continue;
			if (!preg_match('#^data:(image/(?:png|jpeg|webp));base64,([A-Za-z0-9+/=\r\n]+)$#', $data, $matches)) {
				$this->addError($field, $label . ' harus berupa PNG, JPG, atau WebP.');
				$valid = false;
				continue;
			}
			$bytes = base64_decode(preg_replace('/\s+/', '', $matches[2]), true);
			$imageInfo = $bytes === false ? false : @getimagesizefromstring($bytes);
			$detectedMime = $imageInfo && isset($imageInfo['mime']) ? (string) $imageInfo['mime'] : '';
			if ($bytes === false || strlen($bytes) > 2097152 || !$imageInfo || $detectedMime !== $matches[1] || $imageInfo[0] < 1 || $imageInfo[1] < 1 || $imageInfo[0] > 4000 || $imageInfo[1] > 4000) {
				$this->addError($field, $label . ' tidak valid, melebihi 2 MB, atau dimensinya terlalu besar.');
				$valid = false;
				continue;
			}
			$this->pendingAssets[$field] = array('bytes' => $bytes, 'mime' => $matches[1]);
		}
		return $valid;
	}

	public function fetch($request, $template = null, $display = false) {
		$context = $request->getContext();
		$selectedFullAccess = $this->normalizeUserIds($this->getData('fullAccessUserIds'));
		if (!$selectedFullAccess && $context && $_SERVER['REQUEST_METHOD'] !== 'POST') $selectedFullAccess = $this->plugin->getFullPaymentManagerIds($context->getId());
		$selectedMap = array_fill_keys($selectedFullAccess, true);
		$paymentStaffOptions = $context ? $this->paymentStaffOptions() : array();
		foreach ($paymentStaffOptions as &$staff) $staff['selected'] = isset($selectedMap[(int) $staff['id']]);
		unset($staff);
		TemplateManager::getManager($request)->assign(array(
			'pluginName' => $this->plugin->getName(),
			'journalPaymentVersion' => JournalPaymentPlugin::getPluginVersion(),
			'documentLogoPreview' => $context ? $this->plugin->getDocumentAssetDataUri($context->getId(), 'documentLogoFile') : '',
			'signaturePreview' => $context ? $this->plugin->getDocumentAssetDataUri($context->getId(), 'signatureFile') : '',
			'stampPreview' => $context ? $this->plugin->getDocumentAssetDataUri($context->getId(), 'stampFile') : '',
			'googleDriveConfigured' => $context ? $this->plugin->hasGoogleDriveCredentials($context->getId()) : false,
			'paymentStaffOptions' => $paymentStaffOptions,
		));
		return parent::fetch($request, $template, $display);
	}

	public function execute(...$functionArgs) {
		$contextId = Application::get()->getRequest()->getContext()->getId();
		foreach ($this->settings as $name) {
			$value = trim((string) $this->getData($name));
			if ($name === 'maxUploadMb') $value = max(1, min(20, (int) $value));
			if ($name === 'revisionDeadlineDays') $value = max(1, min(90, (int) $value));
			if ($name === 'publicationWarningDays') $value = max(1, min(60, (int) $value));
			if ($name === 'editorFeePercent') $value = max(0, min(100, (int) $value));
			if ($name === 'productionEditorFee') $value = max(0, min(100000000, (int) preg_replace('/[^0-9]/', '', (string) $value)));
			if ($name === 'enableReminders') $value = $value === '1' ? '1' : '0';
			$this->plugin->updateSetting($contextId, $name, $value);
		}
		$this->plugin->updateSetting($contextId, 'fullAccessUserIds', implode(',', $this->normalizeUserIds($this->getData('fullAccessUserIds'))), 'string');
		$assets = array(
			'documentLogoData' => array('setting' => 'documentLogoFile', 'remove' => 'removeDocumentLogo'),
			'signatureData' => array('setting' => 'signatureFile', 'remove' => 'removeSignature'),
			'stampData' => array('setting' => 'stampFile', 'remove' => 'removeStamp'),
		);
		foreach ($assets as $field => $asset) {
			if (isset($this->pendingAssets[$field])) {
				$this->plugin->saveDocumentAsset($contextId, $asset['setting'], $this->pendingAssets[$field]['bytes'], $this->pendingAssets[$field]['mime']);
			} elseif ($this->getData($asset['remove'])) {
				$this->plugin->deleteDocumentAsset($contextId, $asset['setting']);
			}
		}
		if ($this->getData('removeGoogleDriveCredentials')) {
			$this->plugin->deleteGoogleDriveCredentials($contextId);
		} elseif ($this->pendingDriveCredentials) {
			$this->plugin->saveGoogleDriveCredentials($contextId, $this->pendingDriveCredentials);
		}
		$notificationMgr = new NotificationManager();
		$notificationMgr->createTrivialNotification(
			Application::get()->getRequest()->getUser()->getId(),
			Notification::NOTIFICATION_TYPE_SUCCESS,
			array('contents' => __('common.changesSaved'))
		);
		return parent::execute(...$functionArgs);
	}

	private function normalizeUserIds($value) {
		if (!is_array($value)) $value = preg_split('/[\s,;]+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY);
		$ids = array();
		foreach ((array) $value as $part) {
			$id = (int) $part;
			if ($id > 0) $ids[$id] = $id;
		}
		return array_values($ids);
	}

	/** Users eligible to receive unrestricted payment and Drive access. */
	private function paymentStaffOptions() {
		$context = Application::get()->getRequest()->getContext();
		if (!$context) return array();
		$rows = DB::table('user_user_groups as uug')
			->join('user_groups as ug', 'ug.user_group_id', '=', 'uug.user_group_id')
			->where('ug.context_id', (int) $context->getId())
			->whereIn('ug.role_id', array(Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR))
			// OJS 3.5 keeps ended role memberships; only current ones are eligible.
			->where(function ($active) {
				$active->whereNull('uug.date_end')->orWhere('uug.date_end', '>', date('Y-m-d H:i:s'));
			})
			->select('uug.user_id')->distinct()->orderBy('uug.user_id')->get();
		$options = array();
		foreach ($rows as $row) {
			$user = Repo::user()->get((int) $row->user_id);
			if (!$user) continue;
			$options[] = array(
				'id' => (int) $user->getId(),
				'name' => $user->getFullName(),
				'username' => $user->getUsername(),
				'email' => $user->getEmail(),
			);
		}
		usort($options, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
		return $options;
	}
}
