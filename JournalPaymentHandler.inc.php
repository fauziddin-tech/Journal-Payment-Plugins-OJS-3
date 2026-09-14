<?php

/**
 * @file JournalPaymentHandler.inc.php
 * @brief Public and manager routes for Journal Payment.
 */

import('classes.handler.Handler');

use Illuminate\Database\Capsule\Manager as Capsule;

class JournalPaymentHandler extends Handler {
	/** @var JournalPaymentPlugin */
	private static $plugin;

	public static function setPlugin($plugin) {
		self::$plugin = $plugin;
	}

	public function index($args, $request) {
		return $this->submit($args, $request);
	}

	public function submit($args, $request) {
		$context = $this->requireContext($request);
		$contextId = $context->getId();
		$packages = self::$plugin->getPackages($contextId);
		$errors = array();
		$values = array(
			'articleId' => trim((string) $request->getUserVar('articleId')),
			'name' => trim((string) $request->getUserVar('name')),
			'email' => trim((string) $request->getUserVar('email')),
			'phone' => trim((string) $request->getUserVar('phone')),
			'title' => trim((string) $request->getUserVar('title')),
			'package' => trim((string) $request->getUserVar('package')),
			'paymentMethod' => trim((string) $request->getUserVar('paymentMethod')),
			'notes' => trim((string) $request->getUserVar('notes')),
		);

		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			if (!$request->checkCSRF()) {
				$errors[] = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba kembali.';
			} else {
				$submissionData = $this->getSubmissionData($context, $values['articleId']);
				$existingPayment = null;
				if (!$submissionData) {
					$errors[] = 'ID artikel tidak ditemukan pada jurnal ini.';
				} else {
					// Data OJS is authoritative. Keep a manually entered value only when
					// the corresponding metadata is empty in the submission.
					if ($submissionData['name'] !== '') $values['name'] = $submissionData['name'];
					if ($submissionData['email'] !== '') $values['email'] = $submissionData['email'];
					if ($submissionData['title'] !== '') $values['title'] = $submissionData['title'];
					$existingPayment = $this->getExistingPayment($contextId, $values['articleId']);
					if ($existingPayment) {
						$errors[] = 'Pembayaran untuk ID artikel ini sudah pernah dibuat dengan status ' . $this->paymentStatusLabel($existingPayment->status) . '. Silakan periksa melalui menu Cek Status.';
					}
				}
				$normalizedPhone = $this->normalizeWhatsApp($values['phone']);
				if ($normalizedPhone === null) {
					$errors[] = 'Nomor WhatsApp tidak valid. Gunakan format 08…, 628…, atau +628….';
				} else {
					$values['phone'] = $normalizedPhone;
				}

				if ($values['articleId'] === '' || !ctype_digit($values['articleId'])) $errors[] = 'ID artikel wajib berupa angka.';
				if ($values['name'] === '') $errors[] = 'Nama lengkap wajib diisi.';
				if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Alamat email tidak valid.';
				if ($values['title'] === '') $errors[] = 'Judul artikel tidak tersedia. Silakan isi judul artikel.';
				if ($values['package'] === '') $errors[] = 'Jenis pembayaran tidak valid.';

				$selectedPackage = null;
				foreach ($packages as $package) {
					if (hash_equals($package['name'], $values['package'])) $selectedPackage = $package;
				}
				if (!$selectedPackage) $errors[] = 'Jenis pembayaran tidak valid.';

				$proof = isset($_FILES['proof']) ? $_FILES['proof'] : null;
				$upload = null;
				if (!$existingPayment) {
					if (!$proof || $proof['error'] !== UPLOAD_ERR_OK) {
						$errors[] = 'Bukti pembayaran wajib diunggah.';
					} else {
						$upload = $this->validateUpload($proof, $contextId);
						if (!$upload['valid']) $errors[] = $upload['error'];
					}
				}

				if (!$errors) {
					$trackingCode = $this->newTrackingCode();
					$stored = $this->storeUpload($proof, $upload, $contextId);
					if (!$stored['success']) {
						$errors[] = $stored['error'];
					} else {
						$transactionStarted = false;
						try {
							$connection = Capsule::connection();
							$connection->beginTransaction();
							$transactionStarted = true;
							// Serialize submissions for the same article so two requests arriving
							// together can not both pass the duplicate check.
							Capsule::table('submissions')
								->where('submission_id', (int) $values['articleId'])
								->lockForUpdate()
								->first();
							if ($this->getExistingPayment($contextId, $values['articleId'])) {
								$connection->rollBack();
								$transactionStarted = false;
								@unlink($stored['path']);
								$errors[] = 'Pembayaran untuk ID artikel ini baru saja dibuat. Pengiriman kedua dibatalkan agar data tidak ganda.';
							} else {
								$createdAt = date('Y-m-d H:i:s');
								$publicationDate = date('Y-m-d', strtotime('+' . (int) $selectedPackage['duration_days'] . ' days', strtotime($createdAt)));
								$documentAccessToken = $this->newDocumentAccessToken();
								$paymentId = Capsule::table('journal_payment_records')->insertGetId(array(
									'context_id' => $contextId,
									'tracking_code' => $trackingCode,
									'document_access_token' => $documentAccessToken,
									'article_id' => mb_substr($values['articleId'], 0, 64),
									'payer_name' => mb_substr($values['name'], 0, 255),
									'payer_email' => mb_substr(strtolower($values['email']), 0, 255),
									'payer_phone' => mb_substr($values['phone'], 0, 40),
									'article_title' => mb_substr($values['title'], 0, 5000),
									'package_name' => mb_substr($selectedPackage['name'], 0, 255),
									'amount' => $selectedPackage['amount'],
									'publication_date' => $publicationDate,
									'payment_method' => mb_substr($values['paymentMethod'], 0, 100),
									'proof_file' => $stored['file'],
									'proof_name' => mb_substr(basename($proof['name']), 0, 255),
									'proof_mime' => $upload['mime'],
									'status' => 'pending',
									'payer_notes' => mb_substr($values['notes'], 0, 5000),
									'created_at' => $createdAt,
									'updated_at' => $createdAt,
								));
								$connection->commit();
								$transactionStarted = false;
								$this->auditLog($contextId, $request, 'payment_created', 'payment', $paymentId, $values['articleId'], null, array(
									'package_name' => $selectedPackage['name'],
									'amount' => (int) $selectedPackage['amount'],
									'publication_date' => $publicationDate,
									'status' => 'pending',
								), 'Bukti pembayaran diunggah oleh penulis.');
								$emailSent = false;
								try {
									$emailSent = $this->sendSubmissionAcknowledgement($request, $context, (object) array(
										'payment_id' => $paymentId,
										'tracking_code' => $trackingCode,
										'document_access_token' => $documentAccessToken,
										'article_id' => $values['articleId'],
										'payer_name' => $values['name'],
										'payer_email' => strtolower($values['email']),
										'article_title' => $values['title'],
										'package_name' => $selectedPackage['name'],
										'amount' => $selectedPackage['amount'],
										'publication_date' => $publicationDate,
									));
								} catch (Throwable $mailError) {
									error_log('Journal Payment acknowledgement email failed: ' . $mailError->getMessage());
								}
								$request->redirect(null, 'journalPayment', 'success', null, array(
									'code' => $trackingCode,
									'articleId' => $values['articleId'],
									'email' => $emailSent ? 'sent' : 'failed',
								));
								return;
							}
						} catch (Exception $e) {
							if ($transactionStarted) Capsule::connection()->rollBack();
							@unlink($stored['path']);
							$errors[] = 'Data pembayaran belum dapat disimpan. Silakan coba kembali.';
						}
					}
				}
			}
		}

		$templateMgr = $this->setupPublicTemplate($request, 'Pembayaran Publikasi');
		$templateMgr->assign(array(
			'packages' => $packages,
			'paymentMethods' => $this->linesSetting($contextId, 'paymentMethods', "Transfer Bank\nQRIS"),
			'bankInstructions' => self::$plugin->getSetting($contextId, 'bankInstructions'),
			'errors' => $errors,
			'values' => $values,
			'maxUploadMb' => (int) (self::$plugin->getSetting($contextId, 'maxUploadMb') ?: 5),
		));
		$templateMgr->display(self::$plugin->getTemplateResource('submit.tpl'));
	}

	/**
	 * Return the minimum submission metadata needed by the payment form.
	 * This is a CSRF-protected, journal-scoped endpoint with a session limit
	 * to reduce sequential-ID harvesting of author contact details.
	 */
	public function lookup($args, $request) {
		$context = $this->requireContext($request);
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			return $this->jsonResponse(false, 'Metode permintaan tidak diizinkan.', array(), 405);
		}
		if (!$request->checkCSRF()) {
			return $this->jsonResponse(false, 'Sesi formulir tidak valid. Muat ulang halaman.', array(), 403);
		}
		if (!$this->allowPublicSearch($request, $context->getId(), 'article_lookup', 30, 600)) {
			return $this->jsonResponse(false, 'Terlalu banyak pencarian. Tunggu beberapa menit lalu coba kembali.', array(), 429);
		}

		$articleId = trim((string) $request->getUserVar('articleId'));
		if ($articleId === '' || !ctype_digit($articleId) || strlen($articleId) > 20) {
			return $this->jsonResponse(false, 'ID artikel harus berupa angka.', array(), 422);
		}
		$data = $this->getSubmissionData($context, $articleId);
		if (!$data) {
			return $this->jsonResponse(false, 'ID artikel tidak ditemukan pada jurnal ini.', array(), 404);
		}
		$existingPayment = $this->getExistingPayment($context->getId(), $articleId);
		$data['existingPayment'] = $existingPayment ? array(
			'exists' => true,
			'status' => (string) $existingPayment->status,
			'statusLabel' => $this->paymentStatusLabel($existingPayment->status),
			'createdAt' => (string) $existingPayment->created_at,
		) : array('exists' => false);
		$message = $existingPayment
			? 'Pembayaran untuk ID artikel ini sudah pernah dibuat.'
			: 'Artikel ditemukan di OJS.';
		return $this->jsonResponse(true, $message, $data, 200);
	}

	public function success($args, $request) {
		$this->requireContext($request);
		$code = trim((string) $request->getUserVar('code'));
		$articleId = trim((string) $request->getUserVar('articleId'));
		$emailStatus = trim((string) $request->getUserVar('email'));
		$templateMgr = $this->setupPublicTemplate($request, 'Pembayaran Berhasil Dikirim');
		$templateMgr->assign(array(
			'trackingCode' => $code,
			'articleId' => $articleId,
			'emailStatus' => $emailStatus,
		));
		$templateMgr->display(self::$plugin->getTemplateResource('success.tpl'));
	}

	public function status($args, $request) {
		$context = $this->requireContext($request);
		$articleId = trim((string) $request->getUserVar('articleId'));
		$accessToken = trim((string) $request->getUserVar('access'));
		$record = null;
		$searched = ($articleId !== '');
		$rateLimited = false;
		if ($searched && !$this->allowPublicSearch($request, $context->getId(), 'status_search', 40, 600)) {
			$rateLimited = true;
		} elseif ($articleId !== '' && ctype_digit($articleId) && strlen($articleId) <= 20) {
			$record = Capsule::table('journal_payment_records')
				->where('context_id', $context->getId())
				->where('article_id', $articleId)
				->orderBy('created_at', 'desc')
				->first();
		}
		if ($record) {
			$this->ensureDocumentAccessToken($context->getId(), $record);
			$record->document_access_granted = $this->documentAccessAllowed($request, $context->getId(), $record, $accessToken);
			if (empty($record->publication_date)) $record->publication_date = $this->publicationDateForRecord($context->getId(), $record);
			$this->applyIssueMetadata($context->getId(), $record);
			$statusMap = $this->getEditorialStatusMap($context->getId(), array((int) $record->article_id));
			$editorialStatus = isset($statusMap[(int) $record->article_id])
				? $statusMap[(int) $record->article_id]
				: $this->editorialStatusOptions()['unknown'];
			$this->syncRecordActionDeadline($context->getId(), $record, $editorialStatus);
			$record->editorial_status_key = $editorialStatus['key'];
			$record->editorial_status_label = $editorialStatus['label'];
			$record->proofreading = $this->latestProofForPayment($context->getId(), (int) $record->payment_id);
			$record->proofreading_status_label = $this->proofreadingStatusLabel($record->proofreading ? $record->proofreading->status : 'not_uploaded');
			$record->proofreading_url = ($record->proofreading && $record->document_access_granted)
				? $request->getDispatcher()->url($request, ROUTE_PAGE, $context->getPath(), 'journalPayment', 'proofreading', null, array('access' => $record->proofreading->access_token)) : '';
			$urgencyData = $this->urgencyForRecord($record, $editorialStatus['key'], $context->getId());
			$record->urgency_key = $urgencyData['key'];
			$record->urgency_label = $urgencyData['label'];
			$record->urgency_note = $urgencyData['note'];
			$nextAction = $this->nextPublicationAction($record, $editorialStatus['key']);
			if ($record->status === 'verified' && $record->proofreading) {
				if ($record->proofreading->status === 'awaiting_author') $nextAction = array('actor' => 'Penulis', 'title' => 'Periksa galley akhir', 'description' => 'Buka pratinjau PDF, lalu setujui untuk diterbitkan atau ajukan koreksi.');
				elseif ($record->proofreading->status === 'corrections_requested') $nextAction = array('actor' => 'Editor', 'title' => 'Perbaiki galley akhir', 'description' => 'Koreksi penulis telah diterima. Editor perlu mengunggah versi galley terbaru.');
				elseif ($record->proofreading->status === 'approved' && !in_array($editorialStatus['key'], array('scheduled', 'published'), true)) $nextAction = array('actor' => 'Editor', 'title' => 'Siap masuk antrean publikasi', 'description' => 'Galley akhir telah disetujui penulis dan dapat dijadwalkan untuk diterbitkan.');
			}
			$record->next_action_actor = $nextAction['actor'];
			$record->next_action_title = $nextAction['title'];
			$record->next_action_description = $nextAction['description'];
			$record->journey = $this->publicationJourney($record, $editorialStatus['key']);
			$record->checklist = $this->publicationChecklist($record, $editorialStatus['key']);
		}
		$templateMgr = $this->setupPublicTemplate($request, 'Perjalanan Publikasi Artikel');
		$templateMgr->assign(array('record' => $record, 'searched' => $searched, 'articleId' => $articleId, 'accessToken' => $accessToken, 'rateLimited' => $rateLimited));
		$templateMgr->display(self::$plugin->getTemplateResource('status.tpl'));
	}

	/** Public document lookup using the OJS article ID. */
	public function documents($args, $request) {
		$context = $this->requireContext($request);
		$articleId = trim((string) $request->getUserVar('articleId'));
		$accessToken = trim((string) $request->getUserVar('access'));
		$record = null;
		$searched = ($articleId !== '');
		$rateLimited = false;
		if ($searched && !$this->allowPublicSearch($request, $context->getId(), 'document_search', 30, 600)) {
			$rateLimited = true;
		} elseif ($articleId !== '' && ctype_digit($articleId) && strlen($articleId) <= 20) {
			$record = Capsule::table('journal_payment_records')
				->where('context_id', $context->getId())
				->where('article_id', $articleId)
				->where('status', 'verified')
				->orderBy('verified_at', 'desc')
				->first();
		}
		if ($record) {
			$this->ensureDocumentAccessToken($context->getId(), $record);
			$record->document_access_granted = $this->documentAccessAllowed($request, $context->getId(), $record, $accessToken);
			if (empty($record->publication_date)) $record->publication_date = $this->publicationDateForRecord($context->getId(), $record);
			$this->applyIssueMetadata($context->getId(), $record);
		}
		$templateMgr = $this->setupPublicTemplate($request, 'Pencarian LOA dan Sertifikat');
		$templateMgr->assign(array(
			'record' => $record,
			'searched' => $searched,
			'articleId' => $articleId,
			'accessToken' => $accessToken,
			'rateLimited' => $rateLimited,
		));
		$templateMgr->display(self::$plugin->getTemplateResource('documents.tpl'));
	}

	public function receipt($args, $request) {
		$context = $this->requireContext($request);
		$articleId = trim((string) $request->getUserVar('articleId'));
		$code = strtoupper(trim((string) $request->getUserVar('code')));
		$email = strtolower(trim((string) $request->getUserVar('email')));
		$accessToken = trim((string) $request->getUserVar('access'));
		$query = Capsule::table('journal_payment_records')
			->where('context_id', $context->getId())
			->where('status', 'verified');
		if ($articleId !== '' && ctype_digit($articleId) && strlen($articleId) <= 20) {
			$query->where('article_id', $articleId);
		} else {
			// Keep previously-issued receipt links working after this upgrade.
			$query->where('tracking_code', $code)->where('payer_email', $email);
		}
		$record = $query->orderBy('verified_at', 'desc')->first();
		if ($record) $this->ensureDocumentAccessToken($context->getId(), $record);
		$legacyAccess = $code !== '' && $email !== '' && $record && hash_equals((string) $record->tracking_code, $code) && hash_equals(strtolower((string) $record->payer_email), $email);
		if (!$record || (!$legacyAccess && !$this->documentAccessAllowed($request, $context->getId(), $record, $accessToken))) {
			http_response_code(404);
			fatalError('Data pembayaran tidak ditemukan.');
		}
		$document = $this->ensureProtectedDocument($request, $context, $record, 'receipt');
		if (!$document['success']) { http_response_code(503); fatalError($document['error']); }
		$this->streamProtectedDocument($document['row'], $request);
	}

	public function manage($args, $request) {
		$context = $this->requirePaymentStaff($request);
		$contextId = $context->getId();
		$currentUser = $request->getUser();
		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			if (!$request->checkCSRF()) fatalError('Sesi formulir tidak valid. Muat ulang halaman lalu coba kembali.');
			$productionAction = trim((string) $request->getUserVar('productionAction'));
			if ($productionAction !== '') {
				$this->handleProductionFeeAction($request, $context, $productionAction);
				return;
			}
			$financeAction = trim((string) $request->getUserVar('financeAction'));
			if ($financeAction !== '') {
				if (!$this->canManagePaymentRecords($currentUser, $contextId)) fatalError('Tindakan keuangan tidak diizinkan.');
				$this->handleFinanceAction($request, $context, $financeAction);
				return;
			}
			$driveAction = trim((string) $request->getUserVar('driveAction'));
			if ($driveAction !== '') {
				if (!$this->isFullPaymentManager($currentUser, $contextId)) fatalError('Hanya pengelola pembayaran berakses penuh yang dapat mengelola Folder GD.');
				$this->handleGoogleDriveAction($request, $context, $driveAction);
				return;
			}
			$proofreadingAction = trim((string) $request->getUserVar('proofreadingAction'));
			if ($proofreadingAction !== '') {
				$this->handleProofreadingAction($request, $context, $proofreadingAction);
				return;
			}
			if (!$this->canManagePaymentRecords($currentUser, $contextId)) fatalError('Production Editor tidak memiliki hak untuk mengubah pembayaran penulis.');
			$id = (int) $request->getUserVar('paymentId');
			$recordAction = trim((string) $request->getUserVar('recordAction'));
			$status = trim((string) $request->getUserVar('newStatus'));
			$managerNotes = trim((string) $request->getUserVar('managerNotes'));
			$documentAction = trim((string) $request->getUserVar('documentAction'));
			if ($id && $recordAction === 'update') {
				$record = $this->findAccessiblePayment($contextId, $id, $currentUser);
				if ($record) $this->applyIssueMetadata($contextId, $record);
				$name = trim((string) $request->getUserVar('payerName'));
				$email = strtolower(trim((string) $request->getUserVar('payerEmail')));
				$phone = $this->normalizeWhatsApp((string) $request->getUserVar('payerPhone'));
				$title = trim((string) $request->getUserVar('articleTitle'));
				$publicationDate = trim((string) $request->getUserVar('publicationDate'));
				$actionDueDate = trim((string) $request->getUserVar('actionDueDate'));
				$issueVolume = trim((string) $request->getUserVar('issueVolume'));
				$issueNumber = trim((string) $request->getUserVar('issueNumber'));
				$issueYear = trim((string) $request->getUserVar('issueYear'));
				$dateObject = DateTime::createFromFormat('!Y-m-d', $publicationDate);
				$dateValid = $dateObject && $dateObject->format('Y-m-d') === $publicationDate;
				$dueDateObject = $actionDueDate === '' ? null : DateTime::createFromFormat('!Y-m-d', $actionDueDate);
				$dueDateValid = $actionDueDate === '' || ($dueDateObject && $dueDateObject->format('Y-m-d') === $actionDueDate);
				$issueEmpty = $issueVolume === '' && $issueNumber === '' && $issueYear === '';
				$issueValid = $issueEmpty || (
					preg_match('/^[0-9]{1,4}$/', $issueVolume)
					&& $issueNumber !== '' && mb_strlen($issueNumber) <= 40
					&& preg_match('/^[0-9]{4}$/', $issueYear)
				);
				if (!$record || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$phone || $title === '' || !$dateValid || !$dueDateValid || !$issueValid) {
					$this->redirectManagerDashboard($request, array('recordResult' => 'invalid'));
					return;
				}
				$currentStatusMap = $this->getEditorialStatusMap($contextId, array((int) $record->article_id));
				$currentStatusKey = isset($currentStatusMap[(int) $record->article_id]) ? $currentStatusMap[(int) $record->article_id]['key'] : 'unknown';
				Capsule::table('journal_payment_records')->where('context_id', $contextId)->where('payment_id', $id)->update(array(
					'payer_name' => mb_substr($name, 0, 255),
					'payer_email' => mb_substr($email, 0, 255),
					'payer_phone' => $phone,
					'article_title' => mb_substr($title, 0, 5000),
					'publication_date' => $publicationDate,
					'action_due_date' => $actionDueDate !== '' ? $actionDueDate : null,
					'deadline_manual' => $actionDueDate !== '' ? 1 : 0,
					'deadline_status_key' => $actionDueDate !== '' ? $currentStatusKey : null,
					'reminder_state' => null,
					'manager_notes' => mb_substr($managerNotes, 0, 5000),
					'updated_at' => date('Y-m-d H:i:s'),
				));
				$this->revokeProtectedDocuments($contextId, $id, $request, null, 'Data pembayaran atau metadata penerbitan diperbarui.');
				$this->saveIssueMetadata($contextId, $id, $issueEmpty ? array() : array(
					'volume' => $issueVolume,
					'number' => mb_substr($issueNumber, 0, 40),
					'year' => $issueYear,
				));
				$this->auditLog($contextId, $request, 'payment_updated', 'payment', $id, $record->article_id,
					$this->paymentAuditValues($record),
					array('payer_name' => $name, 'payer_email' => $email, 'payer_phone' => $phone, 'article_title' => $title, 'publication_date' => $publicationDate, 'action_due_date' => $actionDueDate ?: null, 'issue_volume' => $issueVolume, 'issue_number' => $issueNumber, 'issue_year' => $issueYear, 'manager_notes' => $managerNotes)
				);
				$this->redirectManagerDashboard($request, array('recordResult' => 'updated'));
				return;
			}
			if ($id && $recordAction === 'delete') {
				$record = $this->findAccessiblePayment($contextId, $id, $currentUser);
				if (!$record) {
					$this->redirectManagerDashboard($request, array('recordResult' => 'notFound'));
					return;
				}
				if (!empty($record->finance_locked_at) || ($record->status === 'verified' && $this->isSubmissionPublished($contextId, $record->article_id))) {
					$this->redirectManagerDashboard($request, array('recordResult' => 'financeLocked'));
					return;
				}
				$proofPath = $this->uploadDirectory($contextId) . DIRECTORY_SEPARATOR . basename((string) $record->proof_file);
				$documentRows = Capsule::table('journal_payment_documents')->where('context_id', $contextId)->where('payment_id', $id)->get();
				$this->auditLog($contextId, $request, 'payment_deleted', 'payment', $id, $record->article_id, $this->paymentAuditValues($record), null, 'Data pembayaran dihapus.');
				Capsule::table('journal_payment_records')->where('context_id', $contextId)->where('payment_id', $id)->delete();
				Capsule::table('journal_payment_documents')->where('context_id', $contextId)->where('payment_id', $id)->delete();
				$this->saveIssueMetadata($contextId, $id, array());
				if (is_file($proofPath)) @unlink($proofPath);
				foreach ($documentRows as $documentRow) {
					$documentPath = $this->protectedDocumentDirectory($contextId) . DIRECTORY_SEPARATOR . basename((string) $documentRow->stored_file);
					if (is_file($documentPath)) @unlink($documentPath);
				}
				$this->redirectManagerDashboard($request, array('recordResult' => 'deleted'));
				return;
			}
			if ($id && in_array($documentAction, array('sendLoaEmail', 'sendCertificateEmail'), true)) {
				$record = $this->findAccessiblePayment($contextId, $id, $currentUser);
				$isLoaEmail = $documentAction === 'sendLoaEmail';
				$issuedField = $isLoaEmail ? 'loa_issued_at' : 'certificate_issued_at';
				$mailType = $isLoaEmail ? 'loa' : 'certificate';
				if (!$record || $record->status !== 'verified' || empty($record->{$issuedField})) {
					$this->redirectManagerDashboard($request, array('documentMail' => 'unavailable'));
					return;
				}
				if (!filter_var($record->payer_email, FILTER_VALIDATE_EMAIL)) {
					$this->redirectManagerDashboard($request, array('documentMail' => 'invalidEmail'));
					return;
				}
				$sent = false;
				try {
					$sent = $this->sendDocumentEmail($request, $context, $record, $mailType);
				} catch (Throwable $e) {
					error_log('Journal Payment document email failed: ' . $e->getMessage());
				}
				$this->redirectManagerDashboard($request, array('documentMail' => $sent ? ($isLoaEmail ? 'loaSent' : 'certificateSent') : 'sendFailed'));
				return;
			}
			if ($id && in_array($documentAction, array('issueLoa', 'issueCertificate'), true)) {
				$record = $this->findAccessiblePayment($contextId, $id, $currentUser);
				$isCertificate = $documentAction === 'issueCertificate';
				$isPublished = $record ? $this->isSubmissionPublished($contextId, $record->article_id) : false;
				if ($record && $record->status === 'verified' && (!$isCertificate || $isPublished)) {
					$this->applyIssueMetadata($contextId, $record);
					$this->ensureDocumentAccessToken($contextId, $record);
					$prefix = $documentAction === 'issueLoa' ? 'LOA' : 'CERT';
					$column = $documentAction === 'issueLoa' ? 'loa' : 'certificate';
					$contextPath = preg_replace('/[^A-Za-z0-9]/', '', strtoupper((string) $context->getPath()));
					if ($contextPath === '') $contextPath = 'JOURNAL';
					Capsule::table('journal_payment_records')->where('context_id', $contextId)->where('payment_id', $id)->update(array(
						$column . '_no' => $prefix . '/' . $contextPath . '/' . date('Y') . '/' . str_pad($id, 6, '0', STR_PAD_LEFT),
						$column . '_issued_at' => date('Y-m-d H:i:s'),
						$column . '_issued_by' => $request->getUser()->getId(),
						'updated_at' => date('Y-m-d H:i:s')
					));
					$this->saveIssueMetadata($contextId, $id, array(
						'volume' => !empty($record->issue_volume) ? $record->issue_volume : '',
						'number' => !empty($record->issue_number) ? $record->issue_number : '',
						'year' => !empty($record->issue_year) ? $record->issue_year : '',
					));
					$this->auditLog($contextId, $request, 'document_issued', 'document', $id, $record->article_id, null, array(
						'document_type' => $column,
						'document_number' => $prefix . '/' . $contextPath . '/' . date('Y') . '/' . str_pad($id, 6, '0', STR_PAD_LEFT),
					), $isCertificate ? 'Sertifikat publikasi diterbitkan.' : 'LOA diterbitkan.');
				}
				$this->redirectManagerDashboard($request); return;
			}
			if ($id && in_array($status, array('pending', 'verified', 'rejected'), true)) {
				if ($status === 'rejected' && $managerNotes === '') {
					$this->redirectManagerDashboard($request, array('decisionError' => 'note'));
					return;
				}
				$record = $this->findAccessiblePayment($contextId, $id, $currentUser);
				if ($record && $status !== 'verified' && (!empty($record->finance_locked_at) || ($record->status === 'verified' && $this->isSubmissionPublished($contextId, $record->article_id)))) {
					$this->redirectManagerDashboard($request, array('recordResult' => 'financeLocked'));
					return;
				}
				if ($record) {
					$oldStatus = (string) $record->status;
					$data = array(
						'status' => $status,
						'updated_at' => date('Y-m-d H:i:s'),
					);
					if ($request->getUserVar('managerNotes') !== null) $data['manager_notes'] = mb_substr($managerNotes, 0, 5000);
					if ($status === 'verified') {
						$data['verified_at'] = date('Y-m-d H:i:s');
						$data['verified_by'] = $request->getUser()->getId();
						if (!$record->receipt_no) $data['receipt_no'] = $this->receiptNumber($context, $id);
					} else {
						$data['verified_at'] = null;
						$data['verified_by'] = null;
						$this->revokeProtectedDocuments($contextId, $id, $request, null, 'Status pembayaran tidak lagi terverifikasi.');
					}
					Capsule::table('journal_payment_records')->where('context_id', $contextId)->where('payment_id', $id)->update($data);
					$this->auditLog($contextId, $request, 'payment_status_changed', 'payment', $id, $record->article_id,
						array('status' => $oldStatus, 'manager_notes' => isset($record->manager_notes) ? $record->manager_notes : null),
						array('status' => $status, 'manager_notes' => array_key_exists('manager_notes', $data) ? $data['manager_notes'] : (isset($record->manager_notes) ? $record->manager_notes : null))
					);
				}
			}
			$this->redirectManagerDashboard($request);
			return;
		}

		$templateMgr = $this->setupPublicTemplate($request, 'Manajemen Pembayaran');
		$this->assignManageData($request, $context, $templateMgr);
		$templateMgr->assign('journalPaymentEmbedded', false);
		$templateMgr->display(self::$plugin->getTemplateResource('manage.tpl'));
	}

	/** Return the dashboard body for the native Website Settings tab. */
	public function fetchManageContent($args, $request) {
		$context = $this->requirePaymentStaff($request);
		$templateMgr = TemplateManager::getManager($request);
		$resultsOnly = (bool) $request->getUserVar('resultsOnly');
		$this->assignManageData($request, $context, $templateMgr, $resultsOnly);
		$templateMgr->assign(array(
			'journalPaymentEmbedded' => true,
			'journalPaymentPreviewModalTemplate' => self::$plugin->getTemplateResource('previewModal.tpl'),
			'jpAccentColor' => $this->getThemeAccent($context->getId()),
			'jpOrganization' => self::$plugin->getSetting($context->getId(), 'organizationName') ?: $context->getLocalizedName(),
			'jpThemeMode' => $this->getThemeMode($context->getId()),
		));
		return new JSONMessage(true, $templateMgr->fetch(self::$plugin->getTemplateResource('manage.tpl')));
	}

	/** Prepare records, filters, counters, and pagination for either dashboard view. */
	private function assignManageData($request, $context, $templateMgr, $resultsOnly = false) {
		$contextId = $context->getId();
		$currentUser = $request->getUser();
		$isFullManager = $this->isFullPaymentManager($currentUser, $contextId);
		$accessibleSubmissionIds = $this->getAccessibleSubmissionIds($contextId, $currentUser);
		$dispatcher = $request->getDispatcher();
		$status = trim((string) $request->getUserVar('filter'));
		$urgency = trim((string) $request->getUserVar('urgency'));
		$view = trim((string) $request->getUserVar('view'));
		$isProductionEditor = $this->isProductionEditor($currentUser, $contextId);
		$isProductionEditorOnly = $isProductionEditor && !$this->canManagePaymentRecords($currentUser, $contextId);
		if (!in_array($view, array('active', 'archive', 'finance', 'production', 'drive', 'audit'), true)) $view = $isProductionEditorOnly ? 'production' : 'active';
		if ($isProductionEditorOnly && !in_array($view, array('active', 'archive', 'production'), true)) $view = 'production';
		if (in_array($view, array('drive', 'audit'), true) && !$isFullManager) $view = 'active';
		$allEditorialStatusOptions = $this->editorialStatusOptions();
		foreach ($allEditorialStatusOptions as $statusKey => &$statusOption) {
			$statusOption['scope'] = in_array($statusKey, array('published', 'declined'), true) ? 'archive' : 'active';
		}
		unset($statusOption);
		if ($status !== '' && !isset($allEditorialStatusOptions[$status])) $status = '';
		$urgencyOptions = array(
			'late' => 'Terlambat',
			'waitingAuthor' => 'Menunggu Penulis',
			'attention' => 'Perlu Perhatian',
			'waitingEditor' => 'Menunggu Editor',
			'safe' => 'Aman',
		);
		if ($urgency !== '' && !isset($urgencyOptions[$urgency])) $urgency = '';
		$isArchiveStatus = in_array($status, array('published', 'declined'), true);
		if ($status !== '' && (in_array($view, array('drive', 'finance', 'production', 'audit'), true) || (($view === 'archive') !== $isArchiveStatus))) $status = '';
		$editorialStatusOptions = $allEditorialStatusOptions;
		$q = trim((string) $request->getUserVar('q'));
		$page = max(1, (int) $request->getUserVar('p'));
		$perPage = 25;
		$baseQuery = Capsule::table('journal_payment_records')->where('context_id', $contextId);
		if ($accessibleSubmissionIds !== null) $baseQuery->whereIn('article_id', $accessibleSubmissionIds ?: array('0'));
		$allArticleIds = array();
		foreach ((clone $baseQuery)->select('article_id')->distinct()->get() as $candidate) {
			if (ctype_digit((string) $candidate->article_id)) $allArticleIds[] = (int) $candidate->article_id;
		}
		$allEditorialStatusMap = in_array($view, array('drive', 'finance', 'production', 'audit'), true) ? array() : $this->getEditorialStatusMap($contextId, $allArticleIds);
		if (!in_array($view, array('drive', 'finance', 'production', 'audit'), true)) foreach ((clone $baseQuery)->select('payment_id', 'article_id', 'action_due_date', 'deadline_status_key', 'deadline_manual', 'reminder_state')->get() as $deadlineRecord) {
			$deadlineStatus = isset($allEditorialStatusMap[(int) $deadlineRecord->article_id])
				? $allEditorialStatusMap[(int) $deadlineRecord->article_id]
				: $allEditorialStatusOptions['unknown'];
			$this->syncRecordActionDeadline($contextId, $deadlineRecord, $deadlineStatus);
		}
		if (!in_array($view, array('drive', 'finance', 'production', 'audit'), true) && !$resultsOnly && $isFullManager) $this->processDueReminders($request, $context, $accessibleSubmissionIds, $allEditorialStatusMap);
		$archivedSubmissionIds = $this->getArchivedSubmissionIds($contextId, $allArticleIds);
		$archiveCount = $archivedSubmissionIds
			? (clone $baseQuery)->whereIn('article_id', $archivedSubmissionIds)->count()
			: 0;
		$activeCount = max(0, (int) (clone $baseQuery)->count() - (int) $archiveCount);
		$query = clone $baseQuery;
		if (in_array($view, array('drive', 'finance', 'production', 'audit'), true)) $query->whereIn('article_id', array('0'));
		elseif ($view === 'archive') $query->whereIn('article_id', $archivedSubmissionIds ?: array('0'));
		elseif ($archivedSubmissionIds) $query->whereNotIn('article_id', $archivedSubmissionIds);
		if ($q !== '') {
			$like = '%' . $q . '%';
			$query->where(function ($sub) use ($like) {
				$sub->where('tracking_code', 'like', $like)
					->orWhere('receipt_no', 'like', $like)
					->orWhere('article_id', 'like', $like)
					->orWhere('payer_name', 'like', $like)
					->orWhere('payer_email', 'like', $like);
			});
		}
		if ($status !== '') {
			$candidateArticleIds = array();
			foreach ((clone $query)->select('article_id')->distinct()->get() as $candidate) {
				if (ctype_digit((string) $candidate->article_id)) $candidateArticleIds[] = (int) $candidate->article_id;
			}
			$statusSubmissionIds = $this->getSubmissionIdsByEditorialStatus($contextId, $status, $candidateArticleIds);
			$query->whereIn('article_id', $statusSubmissionIds ?: array('0'));
		}
		$today = date('Y-m-d');
		$warningDays = max(1, min(60, (int) (self::$plugin->getSetting($contextId, 'publicationWarningDays') ?: 7)));
		$warningDate = date('Y-m-d', strtotime('+' . $warningDays . ' days'));
		$authorActionIds = array();
		$editorActionIds = array();
		$safeWorkflowIds = array();
		foreach ($allEditorialStatusMap as $submissionId => $workflowStatus) {
			if (in_array($workflowStatus['key'], array('revisionsRequired', 'resubmitForReview'), true)) $authorActionIds[] = (int) $submissionId;
			elseif (in_array($workflowStatus['key'], array('scheduled', 'published', 'declined'), true)) $safeWorkflowIds[] = (int) $submissionId;
			else $editorActionIds[] = (int) $submissionId;
		}
		if ($view === 'archive' && $urgency !== '') {
			// Published/declined records are always considered complete and safe.
			$query->whereIn('article_id', $urgency === 'safe' ? ($safeWorkflowIds ?: array('0')) : array('0'));
		} elseif ($urgency === 'late') {
			$query->where(function ($sub) use ($today) { $sub->where('action_due_date', '<', $today)->orWhere('publication_date', '<', $today); });
		} elseif ($urgency === 'waitingAuthor') {
			$query->whereIn('article_id', $authorActionIds ?: array('0'))
				->where(function ($sub) use ($today) { $sub->whereNull('action_due_date')->orWhere('action_due_date', '>=', $today); })
				->where(function ($sub) use ($today) { $sub->whereNull('publication_date')->orWhere('publication_date', '>=', $today); });
		} elseif ($urgency === 'attention') {
			$query->whereNotIn('article_id', $authorActionIds ?: array('0'))->where(function ($sub) use ($today, $warningDate) {
				$sub->whereBetween('action_due_date', array($today, $warningDate))->orWhereBetween('publication_date', array($today, $warningDate));
			});
		} elseif ($urgency === 'waitingEditor') {
			$query->whereIn('article_id', $editorActionIds ?: array('0'))
				->where(function ($sub) use ($today) { $sub->whereNull('action_due_date')->orWhere('action_due_date', '>=', $today); })
				->where(function ($sub) use ($warningDate) { $sub->whereNull('publication_date')->orWhere('publication_date', '>', $warningDate); });
		} elseif ($urgency === 'safe') {
			$query->whereIn('article_id', $safeWorkflowIds ?: array('0'))->where(function ($sub) use ($warningDate) { $sub->whereNull('publication_date')->orWhere('publication_date', '>', $warningDate); });
		}
		$total = (clone $query)->count();
		if ($view === 'archive') {
			$query->orderByRaw("CASE WHEN publication_date IS NULL OR publication_date = '' THEN 1 ELSE 0 END ASC")->orderBy('publication_date', 'desc')->orderBy('created_at', 'desc');
		} else {
			$query->orderByRaw("CASE WHEN action_due_date IS NOT NULL AND action_due_date < ? THEN 0 WHEN publication_date IS NOT NULL AND publication_date < ? THEN 0 WHEN action_due_date IS NOT NULL THEN 1 WHEN publication_date IS NOT NULL AND publication_date <= ? THEN 2 ELSE 3 END ASC", array($today, $today, $warningDate));
			$query->orderByRaw("CASE WHEN action_due_date IS NULL THEN 1 ELSE 0 END ASC")->orderBy('action_due_date', 'asc')->orderBy('publication_date', 'asc')->orderBy('created_at', 'asc');
		}
		$records = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();
		$articleIds = array();
		$paymentIds = array();
		foreach ($records as $record) {
			$articleIds[] = (int) $record->article_id;
			$paymentIds[] = (int) $record->payment_id;
		}
		$editorialStatusMap = array_intersect_key($allEditorialStatusMap, array_flip($articleIds));
		$assignedEditorLabels = $this->getAssignedEditorLabels($articleIds);
		$proofsByPayment = $this->latestProofsByPayment($contextId, $paymentIds);
		$this->applyIssueMetadataBatch($contextId, $records);
		foreach ($records as $record) {
			$this->ensureDocumentAccessToken($contextId, $record);
			$editorialStatus = isset($editorialStatusMap[(int) $record->article_id])
				? $editorialStatusMap[(int) $record->article_id]
				: $allEditorialStatusOptions['unknown'];
			$record->editorial_status_key = $editorialStatus['key'];
			$record->editorial_status_label = $editorialStatus['label'];
			$record->editorial_status_icon = $editorialStatus['icon'];
			if (empty($record->publication_date)) $record->publication_date = $this->publicationDateForRecord($contextId, $record);
			$urgencyData = $this->urgencyForRecord($record, $editorialStatus['key'], $contextId);
			$record->urgency_key = $urgencyData['key'];
			$record->urgency_label = $urgencyData['label'];
			$record->urgency_note = $urgencyData['note'];
			$record->assigned_editor_label = isset($assignedEditorLabels[(int) $record->article_id])
				? $assignedEditorLabels[(int) $record->article_id]
				: 'Belum ditugaskan';
			$record->workflow_url = $dispatcher->url($request, ROUTE_PAGE, $context->getPath(), 'workflow', 'access', array((int) $record->article_id));
			$waPhone = $this->normalizeWhatsApp((string) $record->payer_phone);
			$record->wa_link = $waPhone ? 'https://wa.me/' . $waPhone : '';
			$record->loa_send_link = '';
			$record->certificate_send_link = '';
			if ($waPhone && !empty($record->loa_issued_at)) {
				$loaUrl = $dispatcher->url($request, ROUTE_PAGE, $context->getPath(), 'journalPayment', 'loa', null, array('articleId' => $record->article_id, 'access' => $record->document_access_token));
				$loaMessage = 'Yth. ' . $record->payer_name . ",\n\nLOA untuk artikel ID " . $record->article_id . ' telah diterbitkan. Silakan buka atau simpan dokumen melalui tautan resmi berikut:' . "\n" . $loaUrl . "\n\n" . $context->getLocalizedName();
				$record->loa_send_link = 'https://wa.me/' . $waPhone . '?text=' . rawurlencode($loaMessage);
			}
			if ($waPhone && !empty($record->certificate_issued_at)) {
				$certificateUrl = $dispatcher->url($request, ROUTE_PAGE, $context->getPath(), 'journalPayment', 'certificate', null, array('articleId' => $record->article_id, 'access' => $record->document_access_token));
				$certificateMessage = 'Yth. ' . $record->payer_name . ",\n\nSertifikat Publikasi untuk artikel ID " . $record->article_id . ' telah diterbitkan. Silakan buka atau simpan dokumen melalui tautan resmi berikut:' . "\n" . $certificateUrl . "\n\n" . $context->getLocalizedName();
				$record->certificate_send_link = 'https://wa.me/' . $waPhone . '?text=' . rawurlencode($certificateMessage);
			}
			$record->is_published = $record->editorial_status_key === 'published';
			$record->proofreading = isset($proofsByPayment[(int) $record->payment_id]) ? $proofsByPayment[(int) $record->payment_id] : null;
			$record->proofreading_status_label = $this->proofreadingStatusLabel($record->proofreading ? $record->proofreading->status : 'not_uploaded');
			$record->proofreading_url = $record->proofreading ? $dispatcher->url($request, ROUTE_PAGE, $context->getPath(), 'journalPayment', 'proofreading', null, array('access' => $record->proofreading->access_token)) : '';
			$record->proofreading_file_url = $record->proofreading ? $dispatcher->url($request, ROUTE_PAGE, $context->getPath(), 'journalPayment', 'proofreadingFile', null, array('id' => $record->proofreading->proof_id)) : '';
			$record->proofreading_wa_link = '';
			if ($waPhone && $record->proofreading) {
				$proofMessage = 'Yth. ' . $record->payer_name . ",\n\nGalley akhir artikel ID " . $record->article_id . ' versi ' . $record->proofreading->version_number . " siap diperiksa. Silakan setujui atau ajukan koreksi melalui tautan resmi berikut:\n" . $record->proofreading_url . "\n\n" . $context->getLocalizedName();
				$record->proofreading_wa_link = 'https://wa.me/' . $waPhone . '?text=' . rawurlencode($proofMessage);
			}
		}
		$packageStatsByName = array();
		if (!$resultsOnly) {
			foreach (self::$plugin->getPackages($contextId) as $package) {
				$packageStatsByName[$package['name']] = array(
					'name' => $package['name'],
					'submission_count' => 0,
					'verified_count' => 0,
					'verified_total' => 0,
				);
			}
			$packageQuery = Capsule::table('journal_payment_records')
				->where('context_id', $contextId)
				->select('package_name')
				->selectRaw('COUNT(*) AS submission_count')
				->selectRaw("SUM(CASE WHEN status = 'verified' THEN 1 ELSE 0 END) AS verified_count")
				->selectRaw("SUM(CASE WHEN status = 'verified' THEN amount ELSE 0 END) AS verified_total")
				->groupBy('package_name');
			if ($accessibleSubmissionIds !== null) $packageQuery->whereIn('article_id', $accessibleSubmissionIds ?: array('0'));
			foreach ($packageQuery->get() as $packageRow) {
				$packageName = trim((string) $packageRow->package_name);
				if ($packageName === '') $packageName = 'Tanpa Paket';
				if (!isset($packageStatsByName[$packageName])) $packageStatsByName[$packageName] = array('name' => $packageName);
				$packageStatsByName[$packageName]['submission_count'] = (int) $packageRow->submission_count;
				$packageStatsByName[$packageName]['verified_count'] = (int) $packageRow->verified_count;
				$packageStatsByName[$packageName]['verified_total'] = (int) $packageRow->verified_total;
			}
		}
		$settingsUrl = function ($params = array()) use ($dispatcher, $request) {
			return $dispatcher->url($request, ROUTE_PAGE, null, 'management', 'settings', 'website', $params, 'journalPayment');
		};
		$fetchUrl = $dispatcher->url(
			$request,
			ROUTE_COMPONENT,
			null,
			'plugins.generic.journalPayment.controllers.JournalPaymentDashboardHandler',
			'fetch'
		);
		$pageLinks = array();
		foreach (range(1, max(1, (int) ceil($total / $perPage))) as $pageNumber) {
			$params = array('p' => $pageNumber, 'view' => $view);
			if ($q !== '') $params['q'] = $q;
			if ($status !== '') $params['filter'] = $status;
			if ($urgency !== '') $params['urgency'] = $urgency;
			$pageLinks[] = array('number' => $pageNumber, 'url' => $settingsUrl($params));
		}
		$driveData = array(
			'driveConfigured' => self::$plugin->hasGoogleDriveCredentials($contextId),
			'driveRootUrl' => $this->validatedGoogleDriveFolderUrl($contextId),
			'driveFolders' => array(),
			'driveFiles' => array(),
			'driveSelectedFolder' => '',
			'driveSelectedFolderName' => '',
			'driveSelectedFolderUrl' => '',
			'driveLastPage' => null,
			'driveLatestFile' => '',
			'driveError' => '',
		);
		if ($view === 'drive') $driveData = array_merge($driveData, $this->getGoogleDriveDashboardData($request, $contextId));
		$financeData = array(
			'financeGroups' => array(),
			'financeTotals' => array('articles' => 0, 'gross' => 0, 'editor_share' => 0, 'admin_share' => 0, 'verified' => 0, 'pending' => 0, 'outstanding' => 0),
			'financeRemittances' => array(),
			'financeUnassignedCount' => 0,
			'financeResult' => trim((string) $request->getUserVar('financeResult')),
		);
		if ($view === 'finance') $financeData = array_merge($financeData, $this->getFinanceDashboardData($contextId, $currentUser, $isFullManager));
		$productionData = array(
			'productionGroups' => array(),
			'productionTotals' => array('articles' => 0, 'fee' => 0, 'received' => 0, 'pending' => 0, 'outstanding' => 0),
			'productionPayouts' => array(),
			'productionResult' => trim((string) $request->getUserVar('productionResult')),
		);
		if ($view === 'production') {
			$this->syncProductionEditorFees($contextId);
			$productionData = array_merge($productionData, $this->getProductionFeeDashboardData($contextId, $currentUser, $isFullManager));
		}
		$auditData = array('auditLogs' => array(), 'emailLogs' => array());
		if ($view === 'audit' && $isFullManager) $auditData = $this->getAuditDashboardData($contextId);

		$templateMgr->assign(array_merge(array(
			'records' => $records,
			'packageStats' => array_values($packageStatsByName),
			'editorialStatusOptions' => $editorialStatusOptions,
			'filter' => $status,
			'urgency' => $urgency,
			'urgencyOptions' => $urgencyOptions,
			'view' => $view,
			'activeCount' => $activeCount,
			'archiveCount' => $archiveCount,
			'googleDriveFolderUrl' => $this->validatedGoogleDriveFolderUrl($contextId),
			'q' => $q,
			'page' => $page,
			'totalPages' => max(1, (int) ceil($total / $perPage)),
			'pageNumbers' => range(1, max(1, (int) ceil($total / $perPage))),
			'pageLinks' => $pageLinks,
			'journalPaymentSettingsUrl' => $settingsUrl(),
			'journalPaymentFetchUrl' => $fetchUrl,
			'journalPaymentProductionEditorTemplate' => self::$plugin->getTemplateResource('productionEditor.tpl'),
			'decisionError' => trim((string) $request->getUserVar('decisionError')),
			'documentMail' => trim((string) $request->getUserVar('documentMail')),
			'recordResult' => trim((string) $request->getUserVar('recordResult')),
			'proofResult' => trim((string) $request->getUserVar('proofResult')),
			'driveResult' => trim((string) $request->getUserVar('driveResult')),
			'isFullPaymentManager' => $isFullManager,
			'isProductionEditor' => $isProductionEditor,
			'isProductionEditorOnly' => $isProductionEditorOnly,
			'canManagePaymentRecords' => $this->canManagePaymentRecords($currentUser, $contextId),
			'journalPaymentResultsOnly' => $resultsOnly,
		), $driveData, $financeData, $productionData, $auditData));
	}

	/** Build the native Drive folder/file view without exposing OAuth material. */
	private function getGoogleDriveDashboardData($request, $contextId) {
		$data = array();
		$rootId = $this->googleDriveRootFolderId($contextId);
		if ($rootId === '') return array('driveError' => 'root');
		self::$plugin->import('JournalPaymentGoogleDrive');
		$drive = new JournalPaymentGoogleDrive(self::$plugin, $contextId);
		if (!$drive->isConfigured()) return array('driveError' => 'credentials');
		$rootMeta = $drive->getMetadata($rootId);
		if (!$rootMeta['success'] || empty($rootMeta['data']['id']) || (isset($rootMeta['data']['mimeType']) && $rootMeta['data']['mimeType'] !== JournalPaymentGoogleDrive::FOLDER_MIME)) {
			error_log('Journal Payment Drive root check failed: ' . $rootMeta['message']);
			return array('driveError' => 'connection');
		}
		$rootName = !empty($rootMeta['data']['name']) ? $rootMeta['data']['name'] : 'Folder Utama';
		$rootChildren = $drive->listChildren($rootId);
		if (!$rootChildren['success']) {
			error_log('Journal Payment Drive list failed: ' . $rootChildren['message']);
			return array('driveError' => 'connection');
		}
		$folders = array(array('id' => $rootId, 'name' => $rootName, 'is_root' => true));
		foreach ($rootChildren['files'] as $item) {
			if (isset($item['mimeType']) && $item['mimeType'] === JournalPaymentGoogleDrive::FOLDER_MIME) {
				$folders[] = array('id' => $item['id'], 'name' => $item['name'], 'is_root' => false);
			}
		}
		usort($folders, function ($a, $b) {
			if ($a['is_root'] !== $b['is_root']) return $a['is_root'] ? -1 : 1;
			return strnatcasecmp($a['name'], $b['name']);
		});
		$requestedFolder = trim((string) $request->getUserVar('driveFolder'));
		$selected = $rootId;
		$selectedName = $rootName;
		foreach ($folders as $folder) {
			if ($requestedFolder !== '' && hash_equals($folder['id'], $requestedFolder)) {
				$selected = $folder['id'];
				$selectedName = $folder['name'];
			}
		}
		$children = $selected === $rootId ? $rootChildren : $drive->listChildren($selected);
		if (!$children['success']) {
			error_log('Journal Payment Drive selected-folder list failed: ' . $children['message']);
			return array('driveFolders' => $folders, 'driveSelectedFolder' => $selected, 'driveSelectedFolderName' => $selectedName, 'driveError' => 'connection');
		}
		$files = array();
		foreach ($children['files'] as $file) {
			if (isset($file['mimeType']) && $file['mimeType'] === JournalPaymentGoogleDrive::FOLDER_MIME) continue;
			$file['last_page'] = $this->pageNumberFromDriveFileName(isset($file['name']) ? $file['name'] : '');
			$file['size_label'] = $this->formatDriveFileSize(isset($file['size']) ? (int) $file['size'] : 0);
			$file['view_url'] = !empty($file['webViewLink']) ? $file['webViewLink'] : 'https://drive.google.com/file/d/' . rawurlencode($file['id']) . '/view';
			$files[] = $file;
		}
		usort($files, function ($a, $b) {
			$ap = isset($a['last_page']) ? $a['last_page'] : null;
			$bp = isset($b['last_page']) ? $b['last_page'] : null;
			if ($ap !== null && $bp !== null && $ap !== $bp) return $bp <=> $ap;
			if ($ap !== null && $bp === null) return -1;
			if ($ap === null && $bp !== null) return 1;
			return strcmp(isset($b['modifiedTime']) ? $b['modifiedTime'] : '', isset($a['modifiedTime']) ? $a['modifiedTime'] : '');
		});
		$lastPage = null;
		$latestFile = '';
		foreach ($files as $file) {
			if ($file['last_page'] !== null && ($lastPage === null || $file['last_page'] > $lastPage)) {
				$lastPage = $file['last_page'];
				$latestFile = $file['name'];
			}
		}
		if ($latestFile === '' && $files) $latestFile = $files[0]['name'];
		return array(
			'driveFolders' => $folders,
			'driveFiles' => $files,
			'driveSelectedFolder' => $selected,
			'driveSelectedFolderName' => $selectedName,
			'driveSelectedFolderUrl' => 'https://drive.google.com/drive/folders/' . rawurlencode($selected),
			'driveLastPage' => $lastPage,
			'driveLatestFile' => $latestFile,
			'driveError' => '',
		);
	}

	/** Apply a Drive mutation and return to the same native dashboard tab. */
	private function handleGoogleDriveAction($request, $context, $action) {
		$contextId = $context->getId();
		$rootId = $this->googleDriveRootFolderId($contextId);
		$folderId = trim((string) $request->getUserVar('driveFolder'));
		$params = array('view' => 'drive');
		if ($folderId !== '') $params['driveFolder'] = $folderId;
		if ($rootId === '' || !self::$plugin->hasGoogleDriveCredentials($contextId)) {
			$params['driveResult'] = 'notConfigured';
			$this->redirectManagerDashboard($request, $params);
			return;
		}
		self::$plugin->import('JournalPaymentGoogleDrive');
		$drive = new JournalPaymentGoogleDrive(self::$plugin, $contextId);
		if (!$this->validDriveId($folderId) || !$this->driveFolderAllowed($drive, $rootId, $folderId)) {
			$params['driveResult'] = 'invalidFolder';
			$this->redirectManagerDashboard($request, $params);
			return;
		}
		$result = null;
		$successCode = '';
		if ($action === 'createFolder') {
			$name = $this->cleanDriveName((string) $request->getUserVar('folderName'), 150);
			if ($name === '') $result = array('success' => false, 'message' => 'Nama folder kosong.', 'validation' => true);
			else {
				$result = $drive->createFolder($rootId, $name);
				$successCode = 'folderCreated';
				if ($result['success'] && !empty($result['data']['id'])) $params['driveFolder'] = $result['data']['id'];
			}
		} elseif ($action === 'upload') {
			$file = isset($_FILES['driveFile']) ? $_FILES['driveFile'] : null;
			$configuredMax = (int) self::$plugin->getSetting($contextId, 'maxUploadMb');
			$maxBytes = max(1, min(20, $configuredMax > 0 ? $configuredMax : 5)) * 1024 * 1024;
			if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || (int) $file['size'] < 1 || (int) $file['size'] > $maxBytes) {
				$result = array('success' => false, 'message' => 'Berkas unggahan tidak valid.', 'validation' => true);
			} else {
				$name = $this->cleanDriveName(basename((string) $file['name']), 220);
				$finfo = new finfo(FILEINFO_MIME_TYPE);
				$mime = (string) $finfo->file($file['tmp_name']);
				$allowed = array('application/pdf', 'application/zip', 'application/x-zip-compressed', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'image/jpeg', 'image/png', 'image/webp', 'text/plain', 'application/epub+zip');
				$bytes = $name !== '' && in_array($mime, $allowed, true) ? file_get_contents($file['tmp_name']) : false;
				if ($bytes === false) $result = array('success' => false, 'message' => 'Jenis berkas tidak diizinkan.', 'validation' => true);
				else { $result = $drive->uploadFile($folderId, $name, $mime, $bytes); $successCode = 'uploaded'; }
			}
		} elseif (in_array($action, array('rename', 'trash'), true)) {
			$fileId = trim((string) $request->getUserVar('driveFileId'));
			if (!$this->validDriveId($fileId) || !$this->driveFileAllowed($drive, $folderId, $fileId)) {
				$result = array('success' => false, 'message' => 'File tidak berada dalam folder terpilih.', 'validation' => true);
			} elseif ($action === 'rename') {
				$name = $this->cleanDriveName((string) $request->getUserVar('driveFileName'), 220);
				if ($name === '') $result = array('success' => false, 'message' => 'Nama file kosong.', 'validation' => true);
				else { $result = $drive->rename($fileId, $name); $successCode = 'renamed'; }
			} else { $result = $drive->trash($fileId); $successCode = 'trashed'; }
		} else {
			$result = array('success' => false, 'message' => 'Aksi Drive tidak dikenal.', 'validation' => true);
		}
		if ($result && $result['success']) {
			$params['driveResult'] = $successCode;
			$actionMap = array('createFolder' => 'drive_folder_created', 'upload' => 'drive_file_uploaded', 'rename' => 'drive_file_renamed', 'trash' => 'drive_file_trashed');
			$this->auditLog($contextId, $request, isset($actionMap[$action]) ? $actionMap[$action] : 'drive_changed', 'google_drive', isset($result['data']['id']) ? $result['data']['id'] : null, null, null, array('folder_id' => $folderId, 'result' => $successCode), 'Perubahan Google Drive berhasil.');
		}
		else {
			$params['driveResult'] = !empty($result['validation']) ? 'invalid' : 'failed';
			if ($result && !empty($result['message'])) error_log('Journal Payment Drive action failed: ' . $result['message']);
		}
		$this->redirectManagerDashboard($request, $params);
	}

	private function driveFolderAllowed($drive, $rootId, $folderId) {
		if (hash_equals($rootId, $folderId)) return true;
		$meta = $drive->getMetadata($folderId);
		return $meta['success'] && !empty($meta['data']['id'])
			&& isset($meta['data']['mimeType']) && $meta['data']['mimeType'] === JournalPaymentGoogleDrive::FOLDER_MIME
			&& empty($meta['data']['trashed']) && in_array($rootId, isset($meta['data']['parents']) ? $meta['data']['parents'] : array(), true);
	}

	private function driveFileAllowed($drive, $folderId, $fileId) {
		$meta = $drive->getMetadata($fileId);
		return $meta['success'] && !empty($meta['data']['id']) && empty($meta['data']['trashed'])
			&& (!isset($meta['data']['mimeType']) || $meta['data']['mimeType'] !== JournalPaymentGoogleDrive::FOLDER_MIME)
			&& in_array($folderId, isset($meta['data']['parents']) ? $meta['data']['parents'] : array(), true);
	}

	private function googleDriveRootFolderId($contextId) {
		$url = $this->validatedGoogleDriveFolderUrl($contextId);
		if ($url !== '' && preg_match('#/folders/([A-Za-z0-9_-]{10,200})#', $url, $matches)) return $matches[1];
		return '';
	}

	private function validDriveId($id) { return (bool) preg_match('/^[A-Za-z0-9_-]{10,200}$/', (string) $id); }

	private function cleanDriveName($name, $maxLength) {
		$name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $name));
		return $name === '' ? '' : mb_substr($name, 0, $maxLength);
	}

	private function pageNumberFromDriveFileName($name) {
		if (preg_match('/^\D*(\d{1,6})\s*[-–]\s*(\d{1,6})/u', (string) $name, $match)) return max((int) $match[1], (int) $match[2]);
		if (preg_match('/^\D*(\d{1,6})/u', (string) $name, $match)) return (int) $match[1];
		return null;
	}

	private function formatDriveFileSize($bytes) {
		if ($bytes < 1024) return $bytes . ' B';
		if ($bytes < 1048576) return number_format($bytes / 1024, 1, ',', '.') . ' KB';
		return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
	}

	/** Return only an HTTPS Google Drive folder URL saved by the manager. */
	private function validatedGoogleDriveFolderUrl($contextId) {
		$url = trim((string) self::$plugin->getSetting((int) $contextId, 'googleDriveFolderUrl'));
		if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return '';
		$parts = parse_url($url);
		if (!$parts || strtolower(isset($parts['scheme']) ? $parts['scheme'] : '') !== 'https') return '';
		$host = strtolower(isset($parts['host']) ? $parts['host'] : '');
		return $host === 'drive.google.com' ? $url : '';
	}

	/** Resolve submissions that should leave the active work queue. */
	private function getArchivedSubmissionIds($contextId, $candidateArticleIds) {
		$candidateArticleIds = array_values(array_unique(array_filter(array_map('intval', $candidateArticleIds))));
		if (!$candidateArticleIds) return array();
		$query = Capsule::table('submissions as s')
			->leftJoin('edit_decisions as ed', function ($join) {
				$join->on('ed.submission_id', '=', 's.submission_id')
					->whereRaw('ed.edit_decision_id = (SELECT MAX(ed_archive.edit_decision_id) FROM edit_decisions ed_archive WHERE ed_archive.submission_id = s.submission_id)');
			})
			->where('s.context_id', (int) $contextId)
			->whereIn('s.submission_id', $candidateArticleIds)
			->whereRaw("(" . $this->editorialStatusSql() . ") IN ('published', 'declined')");
		$ids = array();
		foreach ($query->select('s.submission_id')->get() as $row) $ids[] = (int) $row->submission_id;
		return array_values(array_unique($ids));
	}

	/** Status labels exposed in the manager filter and list. */
	private function editorialStatusOptions() {
		return array(
			'submitted' => array('key' => 'submitted', 'label' => 'Submitted', 'icon' => 'submitted'),
			'internalReview' => array('key' => 'internalReview', 'label' => 'Internal Review', 'icon' => 'review'),
			'inReview' => array('key' => 'inReview', 'label' => 'In Review', 'icon' => 'review'),
			'revisionsRequired' => array('key' => 'revisionsRequired', 'label' => 'Revisions Required', 'icon' => 'revision'),
			'resubmitForReview' => array('key' => 'resubmitForReview', 'label' => 'Resubmit for Review', 'icon' => 'resubmit'),
			'accepted' => array('key' => 'accepted', 'label' => 'Accepted', 'icon' => 'accepted'),
			'copyediting' => array('key' => 'copyediting', 'label' => 'Copyediting', 'icon' => 'copyediting'),
			'production' => array('key' => 'production', 'label' => 'Production', 'icon' => 'production'),
			'scheduled' => array('key' => 'scheduled', 'label' => 'Scheduled', 'icon' => 'scheduled'),
			'published' => array('key' => 'published', 'label' => 'Published', 'icon' => 'published'),
			'declined' => array('key' => 'declined', 'label' => 'Declined', 'icon' => 'declined'),
			'unknown' => array('key' => 'unknown', 'label' => 'Status Unknown', 'icon' => 'unknown'),
		);
	}

	/** SQL expression mirroring the editorial status priority used in PHP. */
	private function editorialStatusSql() {
		return "CASE"
			. " WHEN s.status = 3 THEN 'published'"
			. " WHEN s.status = 5 THEN 'scheduled'"
			. " WHEN s.status = 4 OR ed.decision IN (4, 9) THEN 'declined'"
			. " WHEN s.stage_id = 5 OR ed.decision = 7 THEN 'production'"
			. " WHEN s.stage_id = 4 THEN 'copyediting'"
			. " WHEN ed.decision = 2 THEN 'revisionsRequired'"
			. " WHEN ed.decision = 3 THEN 'resubmitForReview'"
			. " WHEN ed.decision = 1 THEN 'accepted'"
			. " WHEN s.stage_id = 2 THEN 'internalReview'"
			. " WHEN s.stage_id = 3 OR ed.decision IN (8, 16) THEN 'inReview'"
			. " WHEN s.stage_id = 1 THEN 'submitted'"
			. " ELSE 'unknown' END";
	}

	/** Resolve only submission IDs matching one editorial status in the database. */
	private function getSubmissionIdsByEditorialStatus($contextId, $status, $candidateArticleIds) {
		$candidateArticleIds = array_values(array_unique(array_filter(array_map('intval', $candidateArticleIds))));
		if (!$candidateArticleIds) return array();
		$query = Capsule::table('submissions as s')
			->leftJoin('edit_decisions as ed', function ($join) {
				$join->on('ed.submission_id', '=', 's.submission_id')
					->whereRaw('ed.edit_decision_id = (SELECT MAX(ed_latest.edit_decision_id) FROM edit_decisions ed_latest WHERE ed_latest.submission_id = s.submission_id)');
			})
			->where('s.context_id', (int) $contextId)
			->whereIn('s.submission_id', $candidateArticleIds)
			->whereRaw('(' . $this->editorialStatusSql() . ') = ?', array($status));
		$ids = array();
		foreach ($query->select('s.submission_id')->get() as $row) $ids[] = (string) $row->submission_id;
		if ($status === 'unknown') {
			$knownIds = array();
			foreach (Capsule::table('submissions')
				->where('context_id', (int) $contextId)
				->whereIn('submission_id', $candidateArticleIds)
				->select('submission_id')->get() as $known) $knownIds[] = (int) $known->submission_id;
			foreach (array_diff($candidateArticleIds, $knownIds) as $missingId) $ids[] = (string) $missingId;
		}
		return $ids;
	}

	/** Read workflow stage and latest decision for a set of OJS submissions. */
	private function getEditorialStatusMap($contextId, $articleIds) {
		$options = $this->editorialStatusOptions();
		$ids = array_values(array_unique(array_filter(array_map('intval', $articleIds))));
		if (!$ids) return array();

		$latestDecisionIds = array();
		foreach (Capsule::table('edit_decisions')
			->whereIn('submission_id', $ids)
			->select('submission_id')
			->selectRaw('MAX(edit_decision_id) AS latest_decision_id')
			->groupBy('submission_id')
			->get() as $latestRow) {
			$latestDecisionIds[(int) $latestRow->latest_decision_id] = true;
		}
		$latestDecisions = array();
		$latestDecisionDates = array();
		if ($latestDecisionIds) foreach (Capsule::table('edit_decisions')
			->whereIn('edit_decision_id', array_keys($latestDecisionIds))
			->select('submission_id', 'decision', 'date_decided')
			->get() as $decisionRow) {
				$latestDecisions[(int) $decisionRow->submission_id] = (int) $decisionRow->decision;
				$latestDecisionDates[(int) $decisionRow->submission_id] = (string) $decisionRow->date_decided;
			}

		$map = array();
		$submissions = Capsule::table('submissions')
			->where('context_id', (int) $contextId)
			->whereIn('submission_id', $ids)
			->select('submission_id', 'status', 'stage_id')
			->get();
		foreach ($submissions as $submission) {
			$id = (int) $submission->submission_id;
			$submissionStatus = (int) $submission->status;
			$stageId = (int) $submission->stage_id;
			$decision = isset($latestDecisions[$id]) ? $latestDecisions[$id] : null;

			if ($submissionStatus === 3) $key = 'published';
			elseif ($submissionStatus === 5) $key = 'scheduled';
			elseif ($submissionStatus === 4 || in_array($decision, array(4, 9), true)) $key = 'declined';
			elseif ($stageId === 5 || $decision === 7) $key = 'production';
			elseif ($stageId === 4) $key = 'copyediting';
			elseif ($decision === 2) $key = 'revisionsRequired';
			elseif ($decision === 3) $key = 'resubmitForReview';
			elseif ($decision === 1) $key = 'accepted';
			elseif ($stageId === 2) $key = 'internalReview';
			elseif ($stageId === 3 || in_array($decision, array(8, 16), true)) $key = 'inReview';
			elseif ($stageId === 1) $key = 'submitted';
			else $key = 'unknown';
			$map[$id] = $options[$key];
			$map[$id]['decision_date'] = isset($latestDecisionDates[$id]) ? $latestDecisionDates[$id] : '';
		}
		return $map;
	}

	/** Keep the author-action deadline aligned with the latest OJS decision. */
	private function syncRecordActionDeadline($contextId, &$record, $editorialStatus) {
		$statusKey = isset($editorialStatus['key']) ? (string) $editorialStatus['key'] : 'unknown';
		$requiresAuthor = in_array($statusKey, array('revisionsRequired', 'resubmitForReview'), true);
		$currentDue = isset($record->action_due_date) ? trim((string) $record->action_due_date) : '';
		$currentKey = isset($record->deadline_status_key) ? trim((string) $record->deadline_status_key) : '';
		$isManual = !empty($record->deadline_manual);
		$decisionFingerprint = !empty($editorialStatus['decision_date']) && strtotime($editorialStatus['decision_date'])
			? date('Y-m-d H:i:s', strtotime($editorialStatus['decision_date']))
			: 'fallback';
		$deadlineKey = $statusKey . '|' . $decisionFingerprint;
		if (!$requiresAuthor) {
			if ($currentDue !== '' || $currentKey !== '' || $isManual) {
				Capsule::table('journal_payment_records')->where('context_id', (int) $contextId)->where('payment_id', (int) $record->payment_id)->update(array(
					'action_due_date' => null, 'deadline_status_key' => null, 'deadline_manual' => 0, 'reminder_state' => null,
				));
				$this->auditLog($contextId, null, 'schedule_changed', 'payment', $record->payment_id, isset($record->article_id) ? $record->article_id : null, array('action_due_date' => $currentDue ?: null), array('action_due_date' => null), 'Tenggat otomatis dihapus karena status OJS berubah.');
			}
			$record->action_due_date = null;
			$record->deadline_status_key = null;
			$record->deadline_manual = 0;
			$record->reminder_state = null;
			return;
		}
		if ($isManual && $currentDue !== '' && strpos($currentKey, $statusKey) === 0) return;
		if ($currentDue !== '' && $currentKey === $deadlineKey) return;
		$baseDate = !empty($editorialStatus['decision_date']) && strtotime($editorialStatus['decision_date'])
			? date('Y-m-d', strtotime($editorialStatus['decision_date']))
			: date('Y-m-d');
		$deadlineDays = max(1, min(90, (int) (self::$plugin->getSetting((int) $contextId, 'revisionDeadlineDays') ?: 14)));
		$dueDate = date('Y-m-d', strtotime('+' . $deadlineDays . ' days', strtotime($baseDate)));
		Capsule::table('journal_payment_records')->where('context_id', (int) $contextId)->where('payment_id', (int) $record->payment_id)->update(array(
			'action_due_date' => $dueDate,
			'deadline_status_key' => $deadlineKey,
			'deadline_manual' => 0,
			'reminder_state' => null,
		));
		$this->auditLog($contextId, null, 'schedule_changed', 'payment', $record->payment_id, isset($record->article_id) ? $record->article_id : null, array('action_due_date' => $currentDue ?: null), array('action_due_date' => $dueDate), 'Tenggat otomatis mengikuti keputusan editorial OJS.');
		$record->action_due_date = $dueDate;
		$record->deadline_status_key = $deadlineKey;
		$record->deadline_manual = 0;
		$record->reminder_state = null;
	}

	/** One concise operational classification for dashboard prioritisation. */
	private function urgencyForRecord($record, $statusKey, $contextId) {
		$today = strtotime(date('Y-m-d'));
		$publication = !empty($record->publication_date) ? strtotime((string) $record->publication_date) : false;
		$due = !empty($record->action_due_date) ? strtotime((string) $record->action_due_date) : false;
		$warningDays = max(1, min(60, (int) (self::$plugin->getSetting((int) $contextId, 'publicationWarningDays') ?: 7)));
		$reference = $due ?: $publication;
		$daysRemaining = $reference ? (int) floor(($reference - $today) / 86400) : null;
		if (!in_array($statusKey, array('published', 'declined'), true) && (($due && $due < $today) || ($publication && $publication < $today))) {
			return array('key' => 'late', 'label' => 'Terlambat', 'note' => $daysRemaining === null ? '' : abs($daysRemaining) . ' hari melewati tenggat', 'days' => $daysRemaining);
		}
		if (in_array($statusKey, array('revisionsRequired', 'resubmitForReview'), true)) {
			return array('key' => 'waitingAuthor', 'label' => 'Menunggu Penulis', 'note' => $daysRemaining === null ? 'Revisi diperlukan' : $daysRemaining . ' hari tersisa', 'days' => $daysRemaining);
		}
		if ($reference && $daysRemaining <= $warningDays && $daysRemaining >= 0 && !in_array($statusKey, array('published', 'declined'), true)) {
			return array('key' => 'attention', 'label' => 'Perlu Perhatian', 'note' => $daysRemaining . ' hari menuju target', 'days' => $daysRemaining);
		}
		if ($statusKey === 'scheduled' || in_array($statusKey, array('published', 'declined'), true)) {
			return array('key' => 'safe', 'label' => 'Aman', 'note' => $statusKey === 'scheduled' ? 'Sudah dijadwalkan' : 'Tahap selesai', 'days' => $daysRemaining);
		}
		if (!in_array($statusKey, array('published', 'declined'), true)) {
			return array('key' => 'waitingEditor', 'label' => 'Menunggu Editor', 'note' => 'Tindakan editorial berikutnya', 'days' => $daysRemaining);
		}
		return array('key' => 'safe', 'label' => 'Aman', 'note' => 'Tahap selesai', 'days' => $daysRemaining);
	}

	private function nextPublicationAction($record, $statusKey) {
		if ($record->status === 'pending') return array('actor' => 'Pengelola', 'title' => 'Pemeriksaan bukti pembayaran', 'description' => 'Bukti pembayaran telah diterima dan sedang diperiksa.');
		if ($record->status === 'rejected') return array('actor' => 'Penulis', 'title' => 'Perbaiki bukti pembayaran', 'description' => 'Silakan hubungi pengelola jurnal untuk memperbaiki data atau bukti pembayaran.');
		$actions = array(
			'submitted' => array('Pengelola', 'Pemeriksaan awal artikel', 'Pastikan metadata dan file submission pada OJS sudah lengkap.'),
			'internalReview' => array('Editor', 'Pemeriksaan internal', 'Artikel sedang diperiksa sebelum diteruskan ke proses review.'),
			'inReview' => array('Reviewer/Editor', 'Proses peer review', 'Pantau notifikasi dan menu submission di OJS.'),
			'revisionsRequired' => array('Penulis', 'Unggah naskah revisi', 'Unggah revisi dan tanggapan kepada reviewer melalui workflow OJS sebelum tenggat.'),
			'resubmitForReview' => array('Penulis', 'Kirim ulang untuk review', 'Selesaikan perbaikan utama lalu unggah kembali melalui workflow OJS sebelum tenggat.'),
			'accepted' => array('Editor', 'Persiapan copyediting', 'Artikel telah diterima dan akan masuk tahap penyuntingan naskah.'),
			'copyediting' => array('Penulis/Editor', 'Pemeriksaan copyediting', 'Tanggapi pertanyaan penyunting dan periksa hasil penyuntingan di OJS.'),
			'production' => array('Penulis/Editor', 'Pemeriksaan galley/proof', 'Periksa versi akhir artikel sebelum dijadwalkan terbit.'),
			'scheduled' => array('Pengelola', 'Menunggu tanggal publikasi', 'Artikel sudah ditempatkan pada terbitan dan menunggu dipublikasikan.'),
			'published' => array('Selesai', 'Artikel telah dipublikasikan', 'Dokumen publikasi tersedia sesuai kebijakan jurnal.'),
			'declined' => array('Selesai', 'Proses editorial dihentikan', 'Silakan merujuk keputusan resmi pada workflow OJS.'),
			'unknown' => array('Pengelola', 'Konfirmasi status artikel', 'Status artikel belum dapat dipetakan. Hubungi pengelola jurnal bila diperlukan.'),
		);
		$item = $actions[$statusKey];
		return array('actor' => $item[0], 'title' => $item[1], 'description' => $item[2]);
	}

	private function publicationJourney($record, $statusKey) {
		$steps = array(
			array('key' => 'payment', 'label' => 'Pembayaran'),
			array('key' => 'submitted', 'label' => 'Submitted'),
			array('key' => 'review', 'label' => 'Review'),
			array('key' => 'accepted', 'label' => 'Accepted'),
			array('key' => 'copyediting', 'label' => 'Copyediting'),
			array('key' => 'production', 'label' => 'Production'),
			array('key' => 'scheduled', 'label' => 'Scheduled'),
			array('key' => 'published', 'label' => 'Published'),
		);
		$positions = array('submitted' => 1, 'internalReview' => 2, 'inReview' => 2, 'revisionsRequired' => 2, 'resubmitForReview' => 2, 'accepted' => 3, 'copyediting' => 4, 'production' => 5, 'scheduled' => 6, 'published' => 7, 'declined' => 2, 'unknown' => 1);
		$current = $record->status === 'verified' ? (isset($positions[$statusKey]) ? $positions[$statusKey] : 1) : 0;
		foreach ($steps as $index => &$step) {
			$step['state'] = $index < $current ? 'complete' : ($index === $current ? 'current' : 'upcoming');
		}
		unset($step);
		if ($statusKey === 'declined') {
			$steps[$current]['label'] = 'Declined';
			$steps[$current]['state'] = 'stopped';
		}
		return $steps;
	}

	private function publicationChecklist($record, $statusKey) {
		$beyondReview = in_array($statusKey, array('accepted', 'copyediting', 'production', 'scheduled', 'published'), true);
		$hasIssue = !empty($record->issue_volume) && !empty($record->issue_number) && !empty($record->issue_year);
		$proofStatus = isset($record->proofreading) && $record->proofreading ? (string) $record->proofreading->status : 'not_uploaded';
		return array(
			array('label' => 'Pembayaran diverifikasi', 'state' => $record->status === 'verified' ? 'complete' : 'pending'),
			array('label' => 'Revisi penulis', 'state' => in_array($statusKey, array('revisionsRequired', 'resubmitForReview'), true) ? 'current' : ($beyondReview ? 'complete' : 'pending')),
			array('label' => 'Terbitan ditentukan', 'state' => $hasIssue ? 'complete' : 'pending'),
			array('label' => 'LOA tersedia', 'state' => !empty($record->loa_issued_at) ? 'complete' : 'pending'),
			array('label' => 'Proof disetujui', 'state' => $proofStatus === 'approved' ? 'complete' : ($proofStatus === 'awaiting_author' || $proofStatus === 'corrections_requested' ? 'current' : 'pending')),
			array('label' => 'Publikasi selesai', 'state' => $statusKey === 'published' ? 'complete' : ($statusKey === 'scheduled' ? 'current' : 'pending')),
		);
	}

	/** Send each 7/3/1-day reminder once. The sweep is rate-limited per journal. */
	private function processDueReminders($request, $context, $accessibleSubmissionIds, $statusMap) {
		$contextId = (int) $context->getId();
		if ((string) self::$plugin->getSetting($contextId, 'enableReminders') !== '1') return;
		$lastSweep = (int) self::$plugin->getSetting($contextId, 'reminderLastSweepAt');
		if ($lastSweep > 0 && time() - $lastSweep < 900) return;
		self::$plugin->updateSetting($contextId, 'reminderLastSweepAt', (string) time(), 'string');
		$today = date('Y-m-d');
		$limitDate = date('Y-m-d', strtotime('+7 days'));
		$query = Capsule::table('journal_payment_records')->where('context_id', $contextId)->whereBetween('action_due_date', array($today, $limitDate))->orderBy('action_due_date', 'asc')->limit(50);
		if ($accessibleSubmissionIds !== null) $query->whereIn('article_id', $accessibleSubmissionIds ?: array('0'));
		foreach ($query->get() as $record) {
			$id = (int) $record->article_id;
			$status = isset($statusMap[$id]) ? $statusMap[$id] : null;
			if (!$status || !in_array($status['key'], array('revisionsRequired', 'resubmitForReview'), true)) continue;
			$days = (int) floor((strtotime($record->action_due_date) - strtotime($today)) / 86400);
			if (!in_array($days, array(7, 3, 1), true)) continue;
			$state = json_decode((string) $record->reminder_state, true);
			if (!is_array($state)) $state = array();
			$key = $status['key'] . '|' . $record->action_due_date . '|' . $days;
			if (isset($state[$key])) continue;
			try {
				if ($this->sendDeadlineReminder($request, $context, $record, $status, $days)) {
					$state[$key] = date('c');
					Capsule::table('journal_payment_records')->where('context_id', $contextId)->where('payment_id', (int) $record->payment_id)->update(array('reminder_state' => json_encode($state), 'updated_at' => date('Y-m-d H:i:s')));
				}
			} catch (Throwable $e) { error_log('Journal Payment deadline reminder failed: ' . $e->getMessage()); }
		}
	}

	private function sendDeadlineReminder($request, $context, $record, $status, $days) {
		$contactEmail = trim((string) $context->getData('contactEmail'));
		$contactName = trim((string) $context->getData('contactName'));
		if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL) || !filter_var($record->payer_email, FILTER_VALIDATE_EMAIL)) return false;
		$this->ensureDocumentAccessToken($context->getId(), $record);
		$statusUrl = $request->getDispatcher()->url($request, ROUTE_PAGE, $context->getPath(), 'journalPayment', 'status', null, array('articleId' => $record->article_id, 'access' => $record->document_access_token));
		$escape = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
		$body = '<p>Yth. ' . $escape($record->payer_name) . ',</p>'
			. '<p>Artikel ID <strong>' . $escape($record->article_id) . '</strong> berstatus <strong>' . $escape($status['label']) . '</strong>. Tenggat pengiriman revisi adalah <strong>' . $escape(date('d-m-Y', strtotime($record->action_due_date))) . '</strong> (' . $days . ' hari lagi).</p>'
			. '<p>Silakan unggah naskah revisi dan tanggapan kepada reviewer melalui workflow OJS. Ringkasan perjalanan publikasi dapat dilihat di:<br><a href="' . $escape($statusUrl) . '">' . $escape($statusUrl) . '</a></p>'
			. '<p>Hormat kami,<br>' . $escape($context->getLocalizedName()) . '</p>';
		import('lib.pkp.classes.mail.Mail');
		$mail = new Mail();
		$mail->setFrom($contactEmail, $contactName !== '' ? $contactName : $context->getLocalizedName());
		$mail->addRecipient($record->payer_email, $record->payer_name);
		$subject = 'Pengingat Revisi ' . $days . ' Hari - ID Artikel ' . $record->article_id;
		$mail->setSubject($subject);
		$mail->setBody($body);
		return $this->sendAndLogEmail($mail, $context->getId(), $record, 'deadline_reminder', $subject, $request);
	}

	/** Send a non-financial acknowledgement after proof upload succeeds. */
	private function sendSubmissionAcknowledgement($request, $context, $record) {
		$contactEmail = trim((string) $context->getData('contactEmail'));
		$contactName = trim((string) $context->getData('contactName'));
		if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL) || !filter_var($record->payer_email, FILTER_VALIDATE_EMAIL)) return false;

		$this->ensureDocumentAccessToken($context->getId(), $record);
		$statusUrl = $request->getDispatcher()->url($request, ROUTE_PAGE, $context->getPath(), 'journalPayment', 'status', null, array('articleId' => $record->article_id, 'access' => $record->document_access_token));
		$receiptUrl = $request->getDispatcher()->url($request, ROUTE_PAGE, $context->getPath(), 'journalPayment', 'receipt', null, array('articleId' => $record->article_id, 'access' => $record->document_access_token));
		$escape = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
		$publicationDate = !empty($record->publication_date) ? date('d-m-Y', strtotime($record->publication_date)) : '-';
		$body = '<p>Yth. ' . $escape($record->payer_name) . ',</p>'
			. '<p>Terima kasih. Bukti pembayaran publikasi Anda telah berhasil diterima oleh <strong>' . $escape($context->getLocalizedName()) . '</strong> dan saat ini menunggu pemeriksaan pengelola.</p>'
			. '<p><strong>Ringkasan pembayaran</strong><br>'
			. 'Kode pelacakan: ' . $escape($record->tracking_code) . '<br>'
			. 'ID Artikel: ' . $escape($record->article_id) . '<br>'
			. 'Judul: ' . $escape($record->article_title) . '<br>'
			. 'Paket: ' . $escape($record->package_name) . '<br>'
			. 'Nominal: Rp ' . $escape(number_format((int) $record->amount, 0, ',', '.')) . '<br>'
			. 'Prediksi terbit: ' . $escape($publicationDate) . '</p>'
			. '<p><strong>Proses selanjutnya</strong></p>'
			. '<ol><li>Pengelola memeriksa bukti pembayaran.</li><li>Status pembayaran diperbarui menjadi terverifikasi atau perlu perbaikan.</li><li>Kuitansi resmi dapat dibuka setelah pembayaran terverifikasi.</li><li>LOA dan sertifikat tersedia sesuai keputusan editorial serta tahap publikasi artikel.</li></ol>'
			. '<p>Cek status pembayaran:<br><a href="' . $escape($statusUrl) . '">' . $escape($statusUrl) . '</a></p>'
			. '<p>Tautan kuitansi (aktif setelah pembayaran terverifikasi):<br><a href="' . $escape($receiptUrl) . '">' . $escape($receiptUrl) . '</a></p>'
			. '<p><em>Email ini merupakan konfirmasi penerimaan bukti pembayaran, bukan konfirmasi bahwa pembayaran telah terverifikasi.</em></p>'
			. '<p>Hormat kami,<br>' . $escape($context->getLocalizedName()) . '</p>';

		import('lib.pkp.classes.mail.Mail');
		$mail = new Mail();
		$mail->setFrom($contactEmail, $contactName !== '' ? $contactName : $context->getLocalizedName());
		$mail->addRecipient($record->payer_email, $record->payer_name);
		$subject = 'Bukti Pembayaran Diterima - ID Artikel ' . $record->article_id;
		$mail->setSubject($subject);
		$mail->setBody($body);
		return $this->sendAndLogEmail($mail, $context->getId(), $record, 'payment_acknowledgement', $subject, $request);
	}

	/** Return manager actions to the embedded payment tab. */
	private function redirectManagerDashboard($request, $params = array()) {
		$context = $request->getContext();
		$returnView = trim((string) $request->getUserVar('returnView'));
		if (in_array($returnView, array('active', 'archive', 'finance', 'production', 'drive', 'audit'), true) && !isset($params['view'])) $params['view'] = $returnView;
		if ($context && $this->isFullPaymentManager($request->getUser(), $context->getId())) {
			$url = $request->getDispatcher()->url($request, ROUTE_PAGE, null, 'management', 'settings', 'website', $params, 'journalPayment');
		} else {
			$url = $request->getDispatcher()->url($request, ROUTE_PAGE, $context ? $context->getPath() : null, 'journalPayment', 'manage', null, $params);
		}
		$request->redirectUrl($url);
	}

	/** Create immutable fee rows from Assistant assignments enabled for OJS Production. */
	private function syncProductionEditorFees($contextId) {
		$productionStage = defined('WORKFLOW_STAGE_ID_PRODUCTION') ? constant('WORKFLOW_STAGE_ID_PRODUCTION') : 5;
		$assistantRole = defined('ROLE_ID_ASSISTANT') ? constant('ROLE_ID_ASSISTANT') : 4097;
		$defaultFee = self::$plugin->getSetting((int) $contextId, 'productionEditorFee');
		$defaultFee = max(0, min(100000000, $defaultFee === null ? 100000 : (int) $defaultFee));
		$assignments = Capsule::table('journal_payment_records as p')
			->join('submissions as s', 's.submission_id', '=', 'p.article_id')
			->join('stage_assignments as sa', 'sa.submission_id', '=', 's.submission_id')
			->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')
			->join('user_group_settings as ugn', function ($join) {
				$join->on('ugn.user_group_id', '=', 'ug.user_group_id')->where('ugn.setting_name', '=', 'name');
			})
			->join('user_group_stage as ugs', function ($join) use ($contextId, $productionStage) {
				$join->on('ugs.user_group_id', '=', 'ug.user_group_id')
					->where('ugs.context_id', '=', (int) $contextId)
					->where('ugs.stage_id', '=', (int) $productionStage);
			})
			->where('p.context_id', (int) $contextId)
			->where('s.context_id', (int) $contextId)
			->where('ug.context_id', (int) $contextId)
			->where('ug.role_id', $assistantRole)
			->where(function ($name) { $name->whereRaw("LOWER(ugn.setting_value) LIKE '%production%editor%'")->orWhereRaw("LOWER(ugn.setting_value) LIKE '%editor%produksi%'"); })
			->select('p.payment_id', 'p.article_id', 'sa.stage_assignment_id', 'sa.user_id', 'sa.date_assigned')
			->orderBy('sa.stage_assignment_id')->get();
		$now = date('Y-m-d H:i:s');
		foreach ($assignments as $assignment) {
			$exists = Capsule::table('journal_payment_production_fees')
				->where('payment_id', (int) $assignment->payment_id)
				->where('production_editor_user_id', (int) $assignment->user_id)->exists();
			if ($exists) continue;
			try {
				$feeId = Capsule::table('journal_payment_production_fees')->insertGetId(array(
					'context_id' => (int) $contextId,
					'payment_id' => (int) $assignment->payment_id,
					'article_id' => (string) $assignment->article_id,
					'production_editor_user_id' => (int) $assignment->user_id,
					'stage_assignment_id' => (int) $assignment->stage_assignment_id,
					'amount' => $defaultFee,
					'assigned_at' => $assignment->date_assigned ?: $now,
					'created_at' => $now,
					'updated_at' => $now,
				));
				$this->auditLog($contextId, null, 'production_fee_created', 'production_fee', $feeId, $assignment->article_id, null, array('production_editor_user_id' => (int) $assignment->user_id, 'amount' => $defaultFee), 'Fee dibuat otomatis dari assignment tahap Production OJS.');
			} catch (Throwable $e) {
				// A concurrent dashboard request may have inserted the same unique row.
			}
		}
	}

	private function getProductionFeeDashboardData($contextId, $user, $isFullManager) {
		$query = Capsule::table('journal_payment_production_fees as f')
			->join('journal_payment_records as p', 'p.payment_id', '=', 'f.payment_id')
			->where('f.context_id', (int) $contextId)
			->select('f.*', 'p.article_title', 'p.publication_date');
		if (!$isFullManager) $query->where('f.production_editor_user_id', (int) $user->getId());
		$fees = $query->orderBy('f.assigned_at', 'desc')->get();
		$payoutQuery = Capsule::table('journal_payment_production_payouts')->where('context_id', (int) $contextId)->orderBy('created_at', 'desc');
		if (!$isFullManager) $payoutQuery->where('production_editor_user_id', (int) $user->getId());
		$payouts = $payoutQuery->get();
		$userIds = array();
		foreach ($fees as $fee) $userIds[(int) $fee->production_editor_user_id] = true;
		foreach ($payouts as $payout) $userIds[(int) $payout->production_editor_user_id] = true;
		$userDao = DAORegistry::getDAO('UserDAO');
		$names = array();
		foreach (array_keys($userIds) as $userId) {
			$productionEditor = $userDao->getById($userId);
			$names[$userId] = $productionEditor ? $productionEditor->getFullName() : 'Production Editor #' . $userId;
		}
		$groups = array();
		foreach ($fees as $fee) {
			$userId = (int) $fee->production_editor_user_id;
			if (!isset($groups[$userId])) $groups[$userId] = array('user_id' => $userId, 'name' => $names[$userId], 'articles' => 0, 'fee' => 0, 'received' => 0, 'pending' => 0, 'outstanding' => 0, 'available_to_pay' => 0, 'article_rows' => array());
			$groups[$userId]['articles']++;
			$groups[$userId]['fee'] += (int) $fee->amount;
			$groups[$userId]['article_rows'][] = array('fee_id' => (int) $fee->production_fee_id, 'article_id' => (string) $fee->article_id, 'title' => (string) $fee->article_title, 'amount' => (int) $fee->amount, 'assigned_at' => (string) $fee->assigned_at, 'publication_date' => (string) $fee->publication_date);
		}
		foreach ($payouts as $payout) {
			$userId = (int) $payout->production_editor_user_id;
			if (!isset($groups[$userId])) $groups[$userId] = array('user_id' => $userId, 'name' => $names[$userId], 'articles' => 0, 'fee' => 0, 'received' => 0, 'pending' => 0, 'outstanding' => 0, 'available_to_pay' => 0, 'article_rows' => array());
			if ($payout->status === 'received') $groups[$userId]['received'] += (int) $payout->amount;
			elseif ($payout->status === 'pending') $groups[$userId]['pending'] += (int) $payout->amount;
			$payout->production_editor_name = $names[$userId];
		}
		$totals = array('articles' => 0, 'fee' => 0, 'received' => 0, 'pending' => 0, 'outstanding' => 0);
		foreach ($groups as &$group) {
			$group['outstanding'] = max(0, $group['fee'] - $group['received']);
			$group['available_to_pay'] = max(0, $group['outstanding'] - $group['pending']);
			foreach ($totals as $key => $unused) $totals[$key] += (int) $group[$key];
		}
		unset($group);
		usort($groups, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
		return array('productionGroups' => array_values($groups), 'productionTotals' => $totals, 'productionPayouts' => $payouts);
	}

	private function handleProductionFeeAction($request, $context, $action) {
		$contextId = (int) $context->getId();
		$user = $request->getUser();
		$isFullManager = $this->isFullPaymentManager($user, $contextId);
		$this->syncProductionEditorFees($contextId);
		if ($action === 'confirmReceived') {
			$payoutId = (int) $request->getUserVar('productionPayoutId');
			$notes = mb_substr(trim((string) $request->getUserVar('productionEditorNotes')), 0, 5000);
			$payout = Capsule::table('journal_payment_production_payouts')->where('context_id', $contextId)->where('production_payout_id', $payoutId)->where('production_editor_user_id', (int) $user->getId())->where('status', 'pending')->first();
			if (!$payout) { $this->redirectManagerDashboard($request, array('view' => 'production', 'productionResult' => 'invalid')); return; }
			Capsule::table('journal_payment_production_payouts')->where('production_payout_id', $payoutId)->where('status', 'pending')->update(array('status' => 'received', 'production_editor_notes' => $notes ?: null, 'received_at' => date('Y-m-d H:i:s'), 'received_by' => (int) $user->getId(), 'updated_at' => date('Y-m-d H:i:s')));
			$this->auditLog($contextId, $request, 'production_payout_received', 'production_payout', $payoutId, null, array('status' => 'pending'), array('status' => 'received', 'amount' => (int) $payout->amount), 'Production Editor mengonfirmasi dana telah diterima.');
			$this->redirectManagerDashboard($request, array('view' => 'production', 'productionResult' => 'received')); return;
		}
		if (!$isFullManager) fatalError('Hanya pengelola pembayaran berakses penuh yang dapat mengatur dan membayar fee Production Editor.');
		if ($action === 'updateFee') {
			$feeId = (int) $request->getUserVar('productionFeeId');
			$amount = (int) preg_replace('/[^0-9]/', '', (string) $request->getUserVar('productionFeeAmount'));
			$fee = Capsule::table('journal_payment_production_fees')->where('context_id', $contextId)->where('production_fee_id', $feeId)->first();
			if (!$fee || $amount < 0 || $amount > 100000000) { $this->redirectManagerDashboard($request, array('view' => 'production', 'productionResult' => 'invalid')); return; }
			$otherFees = (int) Capsule::table('journal_payment_production_fees')->where('context_id', $contextId)->where('production_editor_user_id', (int) $fee->production_editor_user_id)->where('production_fee_id', '<>', $feeId)->sum('amount');
			$committedPayouts = (int) Capsule::table('journal_payment_production_payouts')->where('context_id', $contextId)->where('production_editor_user_id', (int) $fee->production_editor_user_id)->whereIn('status', array('pending', 'received'))->sum('amount');
			if ($otherFees + $amount < $committedPayouts) { $this->redirectManagerDashboard($request, array('view' => 'production', 'productionResult' => 'invalid')); return; }
			Capsule::table('journal_payment_production_fees')->where('production_fee_id', $feeId)->update(array('amount' => $amount, 'updated_at' => date('Y-m-d H:i:s')));
			$this->auditLog($contextId, $request, 'production_fee_updated', 'production_fee', $feeId, $fee->article_id, array('amount' => (int) $fee->amount), array('amount' => $amount), 'Nominal fee per artikel disesuaikan.');
			$this->redirectManagerDashboard($request, array('view' => 'production', 'productionResult' => 'feeUpdated')); return;
		}
		if ($action !== 'createPayout') fatalError('Tindakan fee Production Editor tidak dikenal.');
		$productionEditorId = (int) $request->getUserVar('productionEditorId');
		$amount = (int) preg_replace('/[^0-9]/', '', (string) $request->getUserVar('productionPayoutAmount'));
		$notes = mb_substr(trim((string) $request->getUserVar('productionPayoutNotes')), 0, 5000);
		$data = $this->getProductionFeeDashboardData($contextId, $user, true);
		$group = null;
		foreach ($data['productionGroups'] as $candidate) if ((int) $candidate['user_id'] === $productionEditorId) $group = $candidate;
		$file = isset($_FILES['productionPayoutProof']) ? $_FILES['productionPayoutProof'] : null;
		if (!$group || $amount < 1 || $amount > (int) $group['available_to_pay'] || !$file || (int) $file['error'] !== UPLOAD_ERR_OK) { $this->redirectManagerDashboard($request, array('view' => 'production', 'productionResult' => 'invalid')); return; }
		$validated = $this->validateUpload($file, $contextId);
		if (!$validated['valid']) { $this->redirectManagerDashboard($request, array('view' => 'production', 'productionResult' => 'invalidFile')); return; }
		$stored = $this->storeProductionPayoutUpload($file, $validated, $contextId);
		if (!$stored['success']) { $this->redirectManagerDashboard($request, array('view' => 'production', 'productionResult' => 'failed')); return; }
		$connection = Capsule::connection();
		$started = false;
		try {
			$connection->beginTransaction(); $started = true;
			$feeRows = Capsule::table('journal_payment_production_fees')->where('context_id', $contextId)->where('production_editor_user_id', $productionEditorId)->select('amount')->lockForUpdate()->get();
			$payoutRows = Capsule::table('journal_payment_production_payouts')->where('context_id', $contextId)->where('production_editor_user_id', $productionEditorId)->whereIn('status', array('pending', 'received'))->select('amount')->lockForUpdate()->get();
			$feeTotal = 0; foreach ($feeRows as $feeRow) $feeTotal += (int) $feeRow->amount;
			$committed = 0; foreach ($payoutRows as $payoutRow) $committed += (int) $payoutRow->amount;
			if ($amount > max(0, $feeTotal - $committed)) {
				$connection->rollBack(); $started = false; @unlink($stored['path']);
				$this->redirectManagerDashboard($request, array('view' => 'production', 'productionResult' => 'invalid')); return;
			}
			$payoutId = Capsule::table('journal_payment_production_payouts')->insertGetId(array('context_id' => $contextId, 'production_editor_user_id' => $productionEditorId, 'amount' => $amount, 'proof_file' => $stored['file'], 'proof_name' => mb_substr(basename((string) $file['name']), 0, 255), 'proof_mime' => $validated['mime'], 'status' => 'pending', 'manager_notes' => $notes ?: null, 'created_by' => (int) $user->getId(), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')));
			$connection->commit(); $started = false;
		} catch (Throwable $e) {
			if ($started) $connection->rollBack(); @unlink($stored['path']);
			error_log('Journal Payment Production Editor payout failed: ' . $e->getMessage());
			$this->redirectManagerDashboard($request, array('view' => 'production', 'productionResult' => 'failed')); return;
		}
		$this->auditLog($contextId, $request, 'production_payout_created', 'production_payout', $payoutId, null, null, array('production_editor_user_id' => $productionEditorId, 'amount' => $amount, 'status' => 'pending'), 'Bukti pembayaran fee Production Editor diunggah.');
		$this->redirectManagerDashboard($request, array('view' => 'production', 'productionResult' => 'paid'));
	}

	private function storeProductionPayoutUpload($file, $validated, $contextId) {
		$dir = $this->uploadDirectory($contextId) . DIRECTORY_SEPARATOR . 'productionPayouts';
		if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) return array('success' => false);
		$name = bin2hex(random_bytes(24)) . '.' . $validated['extension'];
		$path = $dir . DIRECTORY_SEPARATOR . $name;
		if (!move_uploaded_file($file['tmp_name'], $path)) return array('success' => false);
		@chmod($path, 0640);
		return array('success' => true, 'file' => $name, 'path' => $path);
	}

	public function productionPayoutProof($args, $request) {
		$context = $this->requirePaymentStaff($request);
		$row = Capsule::table('journal_payment_production_payouts')->where('context_id', (int) $context->getId())->where('production_payout_id', (int) $request->getUserVar('id'))->first();
		if (!$row || (!$this->isFullPaymentManager($request->getUser(), $context->getId()) && (int) $row->production_editor_user_id !== (int) $request->getUser()->getId())) { http_response_code(404); exit; }
		$path = $this->uploadDirectory($context->getId()) . DIRECTORY_SEPARATOR . 'productionPayouts' . DIRECTORY_SEPARATOR . basename((string) $row->proof_file);
		if (!is_file($path)) { http_response_code(404); exit; }
		header('Content-Type: ' . $row->proof_mime);
		header('Content-Disposition: inline; filename="' . str_replace('"', '', basename((string) $row->proof_name)) . '"');
		header('Content-Length: ' . filesize($path));
		header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
		readfile($path); exit;
	}

	/** Build issue/editor finance groups from verified payments whose OJS submission is published. */
	private function getFinanceDashboardData($contextId, $user, $isFullManager) {
		$publishedStatus = defined('STATUS_PUBLISHED') ? constant('STATUS_PUBLISHED') : 3;
		$query = Capsule::table('journal_payment_records as p')
			->join('submissions as s', 's.submission_id', '=', 'p.article_id')
			->where('p.context_id', (int) $contextId)
			->where('s.context_id', (int) $contextId)
			->where('p.status', 'verified')
			->where(function ($financiallyRecognized) use ($publishedStatus) {
				$financiallyRecognized->where('s.status', (int) $publishedStatus)->orWhereNotNull('p.finance_locked_at');
			})
			->select('p.*');
		$accessibleIds = $this->getAccessibleSubmissionIds($contextId, $user);
		if (!$isFullManager && $accessibleIds !== null) $query->whereIn('p.article_id', $accessibleIds ?: array('0'));
		$records = $query->orderBy('p.payment_id')->get();
		$this->applyIssueMetadataBatch($contextId, $records);
		$articleIds = array();
		foreach ($records as $record) $articleIds[] = (int) $record->article_id;
		$primaryEditors = $this->getPrimaryFinanceEditors($articleIds);
		$issueIds = $this->getCurrentIssueIds($contextId, $articleIds);
		$editorPercent = max(0, min(100, (int) (self::$plugin->getSetting($contextId, 'editorFeePercent') !== null ? self::$plugin->getSetting($contextId, 'editorFeePercent') : 60)));
		$adminPercent = 100 - $editorPercent;
		$now = date('Y-m-d H:i:s');

		foreach ($records as $record) {
			$updates = array();
			if (empty($record->finance_editor_id) && isset($primaryEditors[(int) $record->article_id])) {
				$record->finance_editor_id = (int) $primaryEditors[(int) $record->article_id];
				$updates['finance_editor_id'] = $record->finance_editor_id;
			}
			if (empty($record->finance_issue_key)) {
				$issueId = isset($issueIds[(int) $record->article_id]) ? (int) $issueIds[(int) $record->article_id] : 0;
				$record->finance_issue_key = $issueId > 0
					? 'issue:' . $issueId
					: 'meta:' . sha1(strtolower(trim((string) $record->issue_volume)) . '|' . strtolower(trim((string) $record->issue_number)) . '|' . strtolower(trim((string) $record->issue_year)));
				$record->finance_issue_label = $this->issueLabel($record) ?: 'Terbitan belum ditentukan';
				$updates['finance_issue_key'] = $record->finance_issue_key;
				$updates['finance_issue_label'] = $record->finance_issue_label;
			}
			if ($record->finance_editor_percent === null || $record->finance_admin_percent === null) {
				$record->finance_editor_percent = $editorPercent;
				$record->finance_admin_percent = $adminPercent;
				$updates['finance_editor_percent'] = $editorPercent;
				$updates['finance_admin_percent'] = $adminPercent;
			}
			if ($updates) {
				$record->finance_locked_at = $now;
				$updates['finance_locked_at'] = $now;
				Capsule::table('journal_payment_records')->where('context_id', (int) $contextId)->where('payment_id', (int) $record->payment_id)->update($updates);
			}
		}

		$editorIds = array();
		foreach ($records as $record) {
			if (!$isFullManager && (int) $record->finance_editor_id !== (int) $user->getId()) continue;
			if (!empty($record->finance_editor_id)) $editorIds[(int) $record->finance_editor_id] = true;
		}
		$userDao = DAORegistry::getDAO('UserDAO');
		$editorNames = array();
		foreach (array_keys($editorIds) as $editorId) {
			$editor = $userDao->getById($editorId);
			$editorNames[$editorId] = $editor ? $editor->getFullName() : 'Editor #' . $editorId;
		}

		$groups = array();
		$unassignedCount = 0;
		foreach ($records as $record) {
			if (!$isFullManager && (int) $record->finance_editor_id !== (int) $user->getId()) continue;
			$assignedEditorId = (int) $record->finance_editor_id;
			if ($assignedEditorId < 1) { $unassignedCount++; continue; }
			$key = $record->finance_issue_key . '|' . $assignedEditorId;
			if (!isset($groups[$key])) $groups[$key] = array(
				'issue_key' => (string) $record->finance_issue_key,
				'issue_label' => (string) ($record->finance_issue_label ?: 'Terbitan belum ditentukan'),
				'editor_id' => $assignedEditorId,
				'editor_name' => isset($editorNames[$assignedEditorId]) ? $editorNames[$assignedEditorId] : 'Editor #' . $assignedEditorId,
				'articles' => 0, 'gross' => 0, 'editor_share' => 0, 'admin_share' => 0,
				'verified' => 0, 'pending' => 0, 'outstanding' => 0, 'available_to_remit' => 0,
				'can_upload' => $assignedEditorId === (int) $user->getId(),
				'article_rows' => array(),
			);
			$editorShare = (int) round(((int) $record->amount) * ((int) $record->finance_editor_percent) / 100);
			$adminShare = (int) $record->amount - $editorShare;
			$groups[$key]['articles']++;
			$groups[$key]['gross'] += (int) $record->amount;
			$groups[$key]['editor_share'] += $editorShare;
			$groups[$key]['admin_share'] += $adminShare;
			$groups[$key]['article_rows'][] = array('article_id' => (string) $record->article_id, 'title' => (string) $record->article_title, 'amount' => (int) $record->amount, 'admin_share' => $adminShare);
		}

		$remittanceQuery = Capsule::table('journal_payment_remittances')->where('context_id', (int) $contextId)->orderBy('created_at', 'desc');
		if (!$isFullManager) $remittanceQuery->where('editor_user_id', (int) $user->getId());
		$remittances = $remittanceQuery->get();
		foreach ($remittances as $remittance) {
			$key = $remittance->issue_key . '|' . (int) $remittance->editor_user_id;
			if (isset($groups[$key])) {
				if ($remittance->status === 'verified') $groups[$key]['verified'] += (int) $remittance->amount;
				elseif ($remittance->status === 'pending') $groups[$key]['pending'] += (int) $remittance->amount;
			}
			$editorId = (int) $remittance->editor_user_id;
			if (!isset($editorNames[$editorId])) {
				$editor = $userDao->getById($editorId);
				if ($editor) $editorNames[$editorId] = $editor->getFullName();
			}
			$remittance->editor_name = isset($editorNames[$editorId]) ? $editorNames[$editorId] : 'Editor #' . $editorId;
		}
		$totals = array('articles' => 0, 'gross' => 0, 'editor_share' => 0, 'admin_share' => 0, 'verified' => 0, 'pending' => 0, 'outstanding' => 0);
		foreach ($groups as &$group) {
			$group['outstanding'] = max(0, $group['admin_share'] - $group['verified']);
			$group['available_to_remit'] = max(0, $group['outstanding'] - $group['pending']);
			foreach ($totals as $field => $unused) if (isset($group[$field])) $totals[$field] += (int) $group[$field];
		}
		unset($group);
		usort($groups, function ($a, $b) {
			$issueCompare = strnatcasecmp($b['issue_label'], $a['issue_label']);
			return $issueCompare !== 0 ? $issueCompare : strcasecmp($a['editor_name'], $b['editor_name']);
		});
		return array('financeGroups' => array_values($groups), 'financeTotals' => $totals, 'financeRemittances' => $remittances, 'financeUnassignedCount' => $unassignedCount);
	}

	/** Pick one financial handler deterministically: newest Section Editor, then newest Journal Manager. */
	private function getPrimaryFinanceEditors($submissionIds) {
		$ids = array_values(array_unique(array_filter(array_map('intval', $submissionIds))));
		if (!$ids) return array();
		$rows = Capsule::table('stage_assignments as sa')
			->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')
			->leftJoin('user_group_settings as ugn', function ($join) {
				$join->on('ugn.user_group_id', '=', 'ug.user_group_id')->where('ugn.setting_name', '=', 'name');
			})
			->whereIn('sa.submission_id', $ids)
			->whereIn('ug.role_id', array(ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR))
			->select('sa.submission_id', 'sa.user_id', 'sa.date_assigned', 'ug.role_id')
			->orderBy('sa.date_assigned', 'desc')->orderBy('sa.stage_assignment_id', 'desc')->get();
		$chosen = array();
		foreach ($rows as $row) {
			$submissionId = (int) $row->submission_id;
			$priority = (int) $row->role_id === (int) ROLE_ID_SUB_EDITOR ? 2 : 1;
			if (!isset($chosen[$submissionId]) || $priority > $chosen[$submissionId]['priority']) {
				$chosen[$submissionId] = array('user_id' => (int) $row->user_id, 'priority' => $priority);
			}
		}
		$result = array();
		foreach ($chosen as $submissionId => $choice) $result[$submissionId] = $choice['user_id'];
		return $result;
	}

	/** Resolve stable OJS issue IDs for published submissions in one batch. */
	private function getCurrentIssueIds($contextId, $submissionIds) {
		$ids = array_values(array_unique(array_filter(array_map('intval', $submissionIds))));
		if (!$ids) return array();
		$publications = array();
		foreach (Capsule::table('submissions')->where('context_id', (int) $contextId)->whereIn('submission_id', $ids)->select('submission_id', 'current_publication_id')->get() as $submission) {
			if ($submission->current_publication_id) $publications[(int) $submission->current_publication_id] = (int) $submission->submission_id;
		}
		if (!$publications) return array();
		$result = array();
		foreach (Capsule::table('publication_settings')->whereIn('publication_id', array_keys($publications))->where('setting_name', 'issueId')->select('publication_id', 'setting_value')->get() as $setting) {
			if ((int) $setting->setting_value > 0) $result[$publications[(int) $setting->publication_id]] = (int) $setting->setting_value;
		}
		return $result;
	}

	private function handleFinanceAction($request, $context, $action) {
		$contextId = (int) $context->getId();
		$user = $request->getUser();
		$isFullManager = $this->isFullPaymentManager($user, $contextId);
		if ($action === 'uploadRemittance') {
			$issueKey = mb_substr(trim((string) $request->getUserVar('issueKey')), 0, 190);
			$amount = (int) preg_replace('/[^0-9]/', '', (string) $request->getUserVar('remittanceAmount'));
			$notes = mb_substr(trim((string) $request->getUserVar('remittanceNotes')), 0, 5000);
			$ownFinance = $this->getFinanceDashboardData($contextId, $user, false);
			$group = null;
			foreach ($ownFinance['financeGroups'] as $candidate) if ($candidate['issue_key'] === $issueKey && $candidate['can_upload']) $group = $candidate;
			$file = isset($_FILES['remittanceProof']) ? $_FILES['remittanceProof'] : null;
			if (!$group || $amount < 1 || $amount > (int) $group['available_to_remit'] || !$file || $file['error'] !== UPLOAD_ERR_OK) {
				$this->redirectManagerDashboard($request, array('view' => 'finance', 'financeResult' => 'invalid'));
				return;
			}
			$validated = $this->validateUpload($file, $contextId);
			if (!$validated['valid']) {
				$this->redirectManagerDashboard($request, array('view' => 'finance', 'financeResult' => 'invalidFile'));
				return;
			}
			$stored = $this->storeRemittanceUpload($file, $validated, $contextId);
			if (!$stored['success']) {
				$this->redirectManagerDashboard($request, array('view' => 'finance', 'financeResult' => 'failed'));
				return;
			}
			$connection = Capsule::connection();
			$transactionStarted = false;
			try {
				$connection->beginTransaction();
				$transactionStarted = true;
				$existingRows = Capsule::table('journal_payment_remittances')
					->where('context_id', $contextId)->where('editor_user_id', (int) $user->getId())->where('issue_key', $issueKey)
					->whereIn('status', array('pending', 'verified'))->select('amount')->lockForUpdate()->get();
				$existingTotal = 0;
				foreach ($existingRows as $existingRow) $existingTotal += (int) $existingRow->amount;
				if ($amount > max(0, (int) $group['admin_share'] - $existingTotal)) {
					$connection->rollBack();
					$transactionStarted = false;
					@unlink($stored['path']);
					$this->redirectManagerDashboard($request, array('view' => 'finance', 'financeResult' => 'invalid')); return;
				}
				$remittanceId = Capsule::table('journal_payment_remittances')->insertGetId(array(
					'context_id' => $contextId, 'editor_user_id' => (int) $user->getId(),
					'issue_key' => $issueKey, 'issue_label' => mb_substr($group['issue_label'], 0, 255),
					'amount' => $amount, 'proof_file' => $stored['file'], 'proof_name' => mb_substr(basename($file['name']), 0, 255),
					'proof_mime' => $validated['mime'], 'status' => 'pending', 'editor_notes' => $notes ?: null,
					'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
				));
				$connection->commit();
				$transactionStarted = false;
				$this->auditLog($contextId, $request, 'remittance_uploaded', 'remittance', $remittanceId, null, null, array('issue' => $group['issue_label'], 'amount' => $amount, 'status' => 'pending'), 'Bukti setoran administrator diunggah.');
			} catch (Throwable $e) {
				if ($transactionStarted) $connection->rollBack();
				@unlink($stored['path']);
				error_log('Journal Payment remittance insert failed: ' . $e->getMessage());
				$this->redirectManagerDashboard($request, array('view' => 'finance', 'financeResult' => 'failed')); return;
			}
			$this->redirectManagerDashboard($request, array('view' => 'finance', 'financeResult' => 'submitted'));
			return;
		}
		if (!$isFullManager || !in_array($action, array('verifyRemittance', 'rejectRemittance'), true)) fatalError('Tindakan keuangan tidak diizinkan.');
		$remittanceId = (int) $request->getUserVar('remittanceId');
		$notes = mb_substr(trim((string) $request->getUserVar('remittanceManagerNotes')), 0, 5000);
		$remittance = Capsule::table('journal_payment_remittances')->where('context_id', $contextId)->where('remittance_id', $remittanceId)->first();
		if (!$remittance || $remittance->status !== 'pending' || ($action === 'rejectRemittance' && $notes === '')) {
			$this->redirectManagerDashboard($request, array('view' => 'finance', 'financeResult' => 'invalidDecision'));
			return;
		}
		$isVerified = $action === 'verifyRemittance';
		$data = array(
			'status' => $isVerified ? 'verified' : 'rejected',
			'manager_notes' => $notes ?: null,
			'updated_at' => date('Y-m-d H:i:s'),
			'verified_at' => $isVerified ? date('Y-m-d H:i:s') : null,
			'verified_by' => $isVerified ? (int) $user->getId() : null,
		);
		Capsule::table('journal_payment_remittances')->where('context_id', $contextId)->where('remittance_id', $remittanceId)->where('status', 'pending')->update($data);
		$this->auditLog($contextId, $request, $isVerified ? 'remittance_verified' : 'remittance_rejected', 'remittance', $remittanceId, null,
			array('status' => 'pending'), array('status' => $data['status'], 'manager_notes' => $data['manager_notes'], 'amount' => (int) $remittance->amount, 'issue' => $remittance->issue_label));
		$this->redirectManagerDashboard($request, array('view' => 'finance', 'financeResult' => $action === 'verifyRemittance' ? 'verified' : 'rejected'));
	}

	/** Serve a remittance proof only to its editor owner or a full payment manager. */
	public function remittanceProof($args, $request) {
		$context = $this->requirePaymentStaff($request);
		$id = (int) $request->getUserVar('id');
		$row = Capsule::table('journal_payment_remittances')->where('context_id', (int) $context->getId())->where('remittance_id', $id)->first();
		if (!$row || (!$this->isFullPaymentManager($request->getUser(), $context->getId()) && (int) $row->editor_user_id !== (int) $request->getUser()->getId())) { http_response_code(404); exit; }
		$path = $this->remittanceUploadDirectory($context->getId()) . DIRECTORY_SEPARATOR . basename((string) $row->proof_file);
		if (!is_file($path)) { http_response_code(404); exit; }
		header('Content-Type: ' . $row->proof_mime);
		header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($row->proof_name)) . '"');
		header('Content-Length: ' . filesize($path));
		header('X-Content-Type-Options: nosniff');
		header('Cache-Control: private, no-store');
		readfile($path); exit;
	}

	private function storeRemittanceUpload($file, $validated, $contextId) {
		$dir = $this->remittanceUploadDirectory($contextId);
		if (!is_dir($dir) && !mkdir($dir, 0750, true)) return array('success' => false, 'error' => 'Direktori setoran tidak dapat dibuat.');
		$name = bin2hex(random_bytes(24)) . '.' . $validated['extension'];
		$path = $dir . DIRECTORY_SEPARATOR . $name;
		if (!move_uploaded_file($file['tmp_name'], $path)) return array('success' => false, 'error' => 'Bukti setoran tidak dapat disimpan.');
		@chmod($path, 0640);
		return array('success' => true, 'file' => $name, 'path' => $path);
	}

	private function remittanceUploadDirectory($contextId) {
		return $this->uploadDirectory($contextId) . DIRECTORY_SEPARATOR . 'remittances';
	}

	public function loa($args, $request) { return $this->document($request, 'loa'); }
	public function certificate($args, $request) { return $this->document($request, 'certificate'); }

	/** Permanent public verification target used by every document QR code. */
	public function verifyDocument($args, $request) {
		$context = $this->requireContext($request);
		$token = trim((string) $request->getUserVar('access'));
		$type = trim((string) $request->getUserVar('type'));
		$version = (int) $request->getUserVar('version');
		$valid = false;
		$record = null;
		$documentRow = null;
		$verificationFound = false;
		if (in_array($type, array('receipt', 'loa', 'certificate'), true) && preg_match('/^[a-f0-9]{64}$/', $token)
			&& $this->allowPublicSearch($request, $context->getId(), 'document_verify', 60, 600)) {
			$record = Capsule::table('journal_payment_records')->where('context_id', $context->getId())->where('document_access_token', $token)->where('status', 'verified')->first();
			if ($record) {
				$field = $type === 'receipt' ? 'verified_at' : ($type === 'loa' ? 'loa_issued_at' : 'certificate_issued_at');
				$verificationFound = !empty($record->{$field});
				if ($verificationFound) {
					$this->applyIssueMetadata($context->getId(), $record);
					$query = Capsule::table('journal_payment_documents')->where('context_id', (int) $context->getId())->where('payment_id', (int) $record->payment_id)->where('document_type', $type);
					if ($version > 0) $query->where('version_number', $version);
					else $query->where('status', 'active')->orderBy('version_number', 'desc');
					$documentRow = $query->first();
					if (!$documentRow && $version < 1) {
						$generated = $this->ensureProtectedDocument($request, $context, $record, $type);
						if ($generated['success']) $documentRow = $generated['row'];
					}
					if ($documentRow && $documentRow->status === 'active') {
						$path = $this->protectedDocumentDirectory($context->getId()) . DIRECTORY_SEPARATOR . basename((string) $documentRow->stored_file);
						$valid = is_file($path) && hash_equals((string) $documentRow->sha256, (string) hash_file('sha256', $path));
					}
				}
			}
		}
		$this->sendSecureDocumentHeaders();
		$templateMgr = $this->setupPublicTemplate($request, 'Verifikasi Dokumen');
		$templateMgr->assign(array('valid' => $valid, 'verificationFound' => $verificationFound, 'record' => $verificationFound ? $record : null, 'documentRow' => $documentRow, 'documentType' => $type));
		$templateMgr->display(self::$plugin->getTemplateResource('verifyDocument.tpl'));
	}

	/** Send a published document link through the journal's configured OJS mailer. */
	private function sendDocumentEmail($request, $context, $record, $type) {
		$isLoa = $type === 'loa';
		$this->ensureDocumentAccessToken($context->getId(), $record);
		if (empty($record->publication_date)) $record->publication_date = $this->publicationDateForRecord($context->getId(), $record);
		$this->applyIssueMetadata($context->getId(), $record);
		$protected = $this->ensureProtectedDocument($request, $context, $record, $type);
		if (!$protected['success']) return false;
		$documentLabel = $isLoa ? 'Letter of Acceptance (LOA)' : 'Sertifikat Publikasi';
		$numberField = $isLoa ? 'loa_no' : 'certificate_no';
		$documentNumber = trim((string) $record->{$numberField});
		$documentUrl = $request->getDispatcher()->url(
			$request,
			ROUTE_PAGE,
			$context->getPath(),
			'journalPayment',
			$type,
			null,
			array('articleId' => $record->article_id, 'access' => $record->document_access_token)
		);
		$escape = function ($value) {
			return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
		};
		$subject = $documentLabel . ' - ID Artikel ' . $record->article_id;
		$body = '<p>Yth. ' . $escape($record->payer_name) . ',</p>'
			. '<p>' . $escape($documentLabel) . ' untuk artikel ID <strong>' . $escape($record->article_id) . '</strong> telah diterbitkan.</p>'
			. ($documentNumber !== '' ? '<p>Nomor dokumen: <strong>' . $escape($documentNumber) . '</strong></p>' : '')
			. ($this->issueLabel($record) !== '' ? '<p>Terbitan: <strong>' . $escape($this->issueLabel($record)) . '</strong></p>' : '')
			. (!empty($record->publication_date) ? '<p>' . ($isLoa ? 'Prediksi terbit' : 'Tanggal terbit') . ': <strong>' . $escape(date('d-m-Y', strtotime($record->publication_date))) . '</strong></p>' : '')
			. '<p>Versi PDF: <strong>' . (int) $protected['row']->version_number . '</strong><br>Fingerprint SHA-256: <code>' . $escape($protected['row']->sha256) . '</code></p>'
			. '<p>Silakan membuka atau menyimpan dokumen melalui tautan resmi berikut:<br><a href="' . $escape($documentUrl) . '">' . $escape($documentUrl) . '</a></p>'
			. '<p>Hormat kami,<br>' . $escape($context->getLocalizedName()) . '</p>';

		import('lib.pkp.classes.mail.Mail');
		$mail = new Mail();
		$contactEmail = trim((string) $context->getData('contactEmail'));
		$contactName = trim((string) $context->getData('contactName'));
		if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) return false;
		$mail->setFrom($contactEmail, $contactName !== '' ? $contactName : $context->getLocalizedName());
		$mail->addRecipient($record->payer_email, $record->payer_name);
		$mail->setSubject($subject);
		$mail->setBody($body);
		return $this->sendAndLogEmail($mail, $context->getId(), $record, $isLoa ? 'loa_delivery' : 'certificate_delivery', $subject, $request);
	}

	private function document($request, $type) {
		$context = $this->requireContext($request);
		$articleId = trim((string) $request->getUserVar('articleId'));
		if (!ctype_digit($articleId)) fatalError('Dokumen tidak ditemukan.');
		$record = Capsule::table('journal_payment_records')->where('context_id', $context->getId())->where('article_id', $articleId)->where('status', 'verified')->orderBy('verified_at', 'desc')->first();
		if ($record) $this->ensureDocumentAccessToken($context->getId(), $record);
		$accessToken = trim((string) $request->getUserVar('access'));
		$field = $type === 'loa' ? 'loa_issued_at' : 'certificate_issued_at';
		$issuedAt = $record && isset($record->{$field}) ? (string) $record->{$field} : '';
		if (!$record || $issuedAt === '' || !$this->documentAccessAllowed($request, $context->getId(), $record, $accessToken)) { http_response_code(404); fatalError('Dokumen tidak ditemukan atau tautan akses tidak valid.'); }
		$document = $this->ensureProtectedDocument($request, $context, $record, $type);
		if (!$document['success']) { http_response_code(503); fatalError($document['error']); }
		$this->streamProtectedDocument($document['row'], $request);
	}

	/** Create one immutable, encrypted PDF version and retain its SHA-256 fingerprint. */
	private function ensureProtectedDocument($request, $context, $record, $type) {
		$contextId = (int) $context->getId();
		$paymentId = (int) $record->payment_id;
		if (!in_array($type, array('receipt', 'loa', 'certificate'), true)) return array('success' => false, 'error' => 'Jenis dokumen tidak dikenal.');
		$active = Capsule::table('journal_payment_documents')->where('context_id', $contextId)->where('payment_id', $paymentId)->where('document_type', $type)->where('status', 'active')->orderBy('version_number', 'desc')->first();
		if ($active) {
			$activePath = $this->protectedDocumentDirectory($contextId) . DIRECTORY_SEPARATOR . basename((string) $active->stored_file);
			if ($this->isValidStoredPdf($activePath, $active->sha256)) return array('success' => true, 'row' => $active);
			Capsule::table('journal_payment_documents')->where('document_id', (int) $active->document_id)->update(array('status' => 'revoked', 'revoked_at' => date('Y-m-d H:i:s')));
			$this->auditLog($contextId, $request, 'document_integrity_failed', 'protected_document', $active->document_id, $record->article_id, array('sha256' => $active->sha256), array('status' => 'revoked'), 'Fingerprint berkas tidak cocok atau berkas privat hilang.');
		}

		self::$plugin->import('JournalPaymentPdfGenerator');
		$generator = new JournalPaymentPdfGenerator();
		if (!$generator->isAvailable()) return array('success' => false, 'error' => $generator->getUnavailableMessage());
		$this->ensureDocumentAccessToken($contextId, $record);
		if (empty($record->publication_date)) $record->publication_date = $this->publicationDateForRecord($contextId, $record);
		$this->applyIssueMetadata($contextId, $record);
		$numberField = $type === 'receipt' ? 'receipt_no' : ($type === 'loa' ? 'loa_no' : 'certificate_no');
		$issuedField = $type === 'receipt' ? 'verified_at' : ($type === 'loa' ? 'loa_issued_at' : 'certificate_issued_at');
		$issuedByField = $type === 'receipt' ? 'verified_by' : ($type === 'loa' ? 'loa_issued_by' : 'certificate_issued_by');
		$documentNumber = trim((string) $record->{$numberField});
		$issuedAt = trim((string) $record->{$issuedField});
		if ($documentNumber === '' || $issuedAt === '') return array('success' => false, 'error' => 'Dokumen belum diterbitkan.');

		$connection = Capsule::connection();
		$started = false;
		$path = null;
		try {
			$connection->beginTransaction(); $started = true;
			Capsule::table('journal_payment_records')->where('context_id', $contextId)->where('payment_id', $paymentId)->lockForUpdate()->first();
			$existing = Capsule::table('journal_payment_documents')->where('context_id', $contextId)->where('payment_id', $paymentId)->where('document_type', $type)->where('status', 'active')->orderBy('version_number', 'desc')->first();
			if ($existing) {
				$existingPath = $this->protectedDocumentDirectory($contextId) . DIRECTORY_SEPARATOR . basename((string) $existing->stored_file);
				if ($this->isValidStoredPdf($existingPath, $existing->sha256)) {
					$connection->commit(); return array('success' => true, 'row' => $existing);
				}
				Capsule::table('journal_payment_documents')->where('document_id', (int) $existing->document_id)->update(array('status' => 'revoked', 'revoked_at' => date('Y-m-d H:i:s')));
			}
			$version = (int) Capsule::table('journal_payment_documents')->where('payment_id', $paymentId)->where('document_type', $type)->max('version_number') + 1;
			$dir = $this->protectedDocumentDirectory($contextId);
			if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new Exception('Direktori PDF privat tidak dapat dibuat.');
			$fileName = $type . '-' . bin2hex(random_bytes(24)) . '.pdf';
			$path = $dir . DIRECTORY_SEPARATOR . $fileName;
			$verificationUrl = $request->getDispatcher()->url($request, ROUTE_PAGE, $context->getPath(), 'journalPayment', 'verifyDocument', null, array('type' => $type, 'version' => $version, 'access' => $record->document_access_token));
			$data = $this->protectedDocumentData($context, $record, $type, $version, $verificationUrl);
			$result = $generator->generate($type, $data, $path);
			if (!$result['success']) throw new Exception($result['error']);
			$documentId = Capsule::table('journal_payment_documents')->insertGetId(array(
				'context_id' => $contextId, 'payment_id' => $paymentId, 'article_id' => (string) $record->article_id,
				'document_type' => $type, 'document_number' => mb_substr($documentNumber, 0, 96), 'version_number' => $version,
				'stored_file' => $fileName, 'sha256' => $result['sha256'], 'byte_size' => (int) $result['byteSize'],
				'protection' => $result['encryption'], 'status' => 'active',
				'issued_by' => !empty($record->{$issuedByField}) ? (int) $record->{$issuedByField} : null,
				'issued_at' => $issuedAt, 'created_at' => date('Y-m-d H:i:s'),
			));
			$connection->commit(); $started = false;
			$row = Capsule::table('journal_payment_documents')->where('document_id', $documentId)->first();
			$this->auditLog($contextId, $request, 'protected_pdf_generated', 'protected_document', $documentId, $record->article_id, null, array('type' => $type, 'version' => $version, 'sha256' => $result['sha256'], 'protection' => $result['encryption']), 'PDF server-side dibuat dan dikunci untuk cetak saja.');
			return array('success' => true, 'row' => $row);
		} catch (Throwable $e) {
			if ($started) $connection->rollBack();
			if ($path && is_file($path)) @unlink($path);
			error_log('Journal Payment protected PDF failed: ' . $e->getMessage());
			return array('success' => false, 'error' => 'PDF terlindungi belum dapat dibuat: ' . $e->getMessage());
		}
	}

	private function protectedDocumentData($context, $record, $type, $version, $verificationUrl) {
		$contextId = (int) $context->getId();
		$journalName = trim((string) $context->getLocalizedName());
		$organizationName = trim((string) self::$plugin->getSetting($contextId, 'organizationName'));
		if ($organizationName === '') $organizationName = $journalName;
		$isLoa = $type === 'loa';
		$isReceipt = $type === 'receipt';
		$numberField = $isReceipt ? 'receipt_no' : ($isLoa ? 'loa_no' : 'certificate_no');
		$issuedField = $isReceipt ? 'verified_at' : ($isLoa ? 'loa_issued_at' : 'certificate_issued_at');
		$documentText = trim((string) self::$plugin->getSetting($contextId, $isLoa ? 'loaText' : 'certificateText'));
		if (!$isReceipt && $documentText === '') $documentText = $isLoa ? 'Dengan ini menyatakan bahwa artikel tersebut telah diterima untuk diterbitkan pada jurnal kami.' : 'Sertifikat ini diberikan sebagai bukti kontribusi penulis pada publikasi ilmiah di jurnal kami.';
		$signerName = trim((string) self::$plugin->getSetting($contextId, 'signerName')) ?: 'Journal Manager';
		$signerTitle = trim((string) self::$plugin->getSetting($contextId, 'signerTitle')) ?: 'Editor in Chief';
		return array(
			'journalName' => $journalName, 'organizationName' => $organizationName,
			'documentTitle' => $isReceipt ? 'Kuitansi Pembayaran' : ($isLoa ? 'Letter of Acceptance' : 'Sertifikat Publikasi'),
			'documentNumber' => (string) $record->{$numberField}, 'documentPublicId' => (string) $record->{$numberField} . '-V' . (int) $version,
			'payerName' => (string) $record->payer_name, 'articleId' => (string) $record->article_id,
			'articleTitle' => (string) $record->article_title, 'packageName' => (string) $record->package_name,
			'paymentMethod' => (string) $record->payment_method, 'trackingCode' => (string) $record->tracking_code,
			'amountLabel' => 'Rp ' . number_format((int) $record->amount, 0, ',', '.'),
			'verifiedDate' => $this->displayDate($record->verified_at), 'issuedDate' => $this->displayDate($record->{$issuedField}),
			'publicationDate' => $this->displayDate($record->publication_date), 'issueLabel' => $this->issueLabel($record),
			'documentText' => $documentText, 'footerText' => trim((string) self::$plugin->getSetting($contextId, 'footerText')),
			'signerName' => $signerName, 'signerTitle' => $signerTitle,
			'accentColor' => $this->getThemeAccent($contextId), 'verificationUrl' => $verificationUrl,
			'documentLogo' => self::$plugin->getDocumentAssetDataUri($contextId, 'documentLogoFile'),
			'signatureImage' => self::$plugin->getDocumentAssetDataUri($contextId, 'signatureFile'),
			'stampImage' => self::$plugin->getDocumentAssetDataUri($contextId, 'stampFile'),
		);
	}

	private function displayDate($value) {
		$timestamp = $value ? strtotime((string) $value) : false;
		return $timestamp ? date('d-m-Y', $timestamp) : '-';
	}

	private function protectedDocumentDirectory($contextId) {
		return $this->uploadDirectory($contextId) . DIRECTORY_SEPARATOR . 'documents';
	}

	private function streamProtectedDocument($row, $request) {
		$path = $this->protectedDocumentDirectory($row->context_id) . DIRECTORY_SEPARATOR . basename((string) $row->stored_file);
		if (!$this->isValidStoredPdf($path, $row->sha256)) { http_response_code(409); fatalError('Integritas atau struktur PDF gagal diverifikasi. Dokumen tidak dikirim.'); }
		// OJS and hosting-level output buffering may prepend whitespace, HTML, or
		// compressed bytes. Any byte before %PDF corrupts the browser preview.
		while (ob_get_level() > 0) @ob_end_clean();
		@ini_set('zlib.output_compression', '0');
		$this->sendSecureDocumentHeaders();
		$safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $row->document_number) . '-v' . (int) $row->version_number . '.pdf';
		header('Content-Type: application/pdf', true);
		$disposition = $request->getUserVar('download') ? 'attachment' : 'inline';
		header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
		header('Content-Transfer-Encoding: binary');
		header('Content-Length: ' . filesize($path));
		header('Accept-Ranges: none');
		header('X-Accel-Buffering: no');
		$sent = readfile($path);
		if ($sent === false) error_log('Journal Payment PDF stream failed: ' . basename($path));
		exit;
	}

	/** Validate both the recorded digest and the minimum PDF file structure. */
	private function isValidStoredPdf($path, $expectedHash = '') {
		if (!is_file($path) || !is_readable($path) || filesize($path) < 500) return false;
		$handle = @fopen($path, 'rb');
		if (!$handle) return false;
		$head = fread($handle, 5);
		$tailLength = min(4096, (int) filesize($path));
		fseek($handle, -$tailLength, SEEK_END);
		$tail = fread($handle, $tailLength);
		fclose($handle);
		if ($head !== '%PDF-' || strpos($tail, '%%EOF') === false) return false;
		if ($expectedHash !== '') {
			$actualHash = @hash_file('sha256', $path);
			if (!is_string($actualHash) || !hash_equals((string) $expectedHash, $actualHash)) return false;
		}
		return true;
	}

	/** Revisions never overwrite issued bytes; the previous version remains auditable. */
	private function revokeProtectedDocuments($contextId, $paymentId, $request, $types = null, $reason = '') {
		$query = Capsule::table('journal_payment_documents')->where('context_id', (int) $contextId)->where('payment_id', (int) $paymentId)->where('status', 'active');
		if (is_array($types) && $types) $query->whereIn('document_type', $types);
		$rows = $query->get();
		if (count($rows) < 1) return;
		$user = $request ? $request->getUser() : null;
		foreach ($rows as $row) {
			Capsule::table('journal_payment_documents')->where('document_id', (int) $row->document_id)->where('status', 'active')->update(array('status' => 'revoked', 'revoked_by' => $user ? (int) $user->getId() : null, 'revoked_at' => date('Y-m-d H:i:s')));
			$this->auditLog($contextId, $request, 'protected_pdf_revoked', 'protected_document', $row->document_id, $row->article_id, array('status' => 'active', 'sha256' => $row->sha256), array('status' => 'revoked'), $reason ?: 'Data sumber dokumen berubah.');
		}
	}

	public function proof($args, $request) {
		$context = $this->requirePaymentStaff($request);
		if (!$this->canManagePaymentRecords($request->getUser(), $context->getId())) { http_response_code(403); exit; }
		$id = (int) $request->getUserVar('id');
		$record = $this->findAccessiblePayment($context->getId(), $id, $request->getUser());
		if (!$record) { http_response_code(404); exit; }
		$path = $this->uploadDirectory($context->getId()) . DIRECTORY_SEPARATOR . basename($record->proof_file);
		if (!is_file($path)) { http_response_code(404); exit; }
		if ($request->getUserVar('jpPreview') === 'fit' && !$request->getUserVar('raw')) {
			$rawUrl = $request->getDispatcher()->url(
				$request,
				ROUTE_PAGE,
				$context->getPath(),
				'journalPayment',
				'proof',
				null,
				array('id' => $id, 'raw' => 1)
			);
			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign(array(
				'proofUrl' => $rawUrl,
				'proofName' => $record->proof_name,
				'proofIsPdf' => strtolower((string) $record->proof_mime) === 'application/pdf',
			));
			$templateMgr->display(self::$plugin->getTemplateResource('proofPreview.tpl'));
			return;
		}
		header('Content-Type: ' . $record->proof_mime);
		header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($record->proof_name)) . '"');
		header('Content-Length: ' . filesize($path));
		header('X-Content-Type-Options: nosniff');
		header('Cache-Control: private, no-store');
		readfile($path);
		exit;
	}

	/** Author-facing final-galley review protected by an unguessable per-version token. */
	public function proofreading($args, $request) {
		$context = $this->requireContext($request);
		$contextId = (int) $context->getId();
		$token = trim((string) $request->getUserVar('access'));
		$proof = null;
		$payment = null;
		if (preg_match('/^[a-f0-9]{64}$/', $token) && $this->allowPublicSearch($request, $contextId, 'proofreading_access', 60, 600)) {
			$proof = Capsule::table('journal_payment_proofs')->where('context_id', $contextId)->where('access_token', $token)->first();
			if ($proof) $payment = Capsule::table('journal_payment_records')->where('context_id', $contextId)->where('payment_id', (int) $proof->payment_id)->first();
		}
		if (!$proof || !$payment) { http_response_code(404); fatalError('Halaman proofreading tidak ditemukan atau tautan tidak valid.'); }

		$result = trim((string) $request->getUserVar('result'));
		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			if (!$request->checkCSRF()) fatalError('Sesi formulir tidak valid. Muat ulang halaman lalu coba kembali.');
			$decision = trim((string) $request->getUserVar('decision'));
			$notes = trim((string) $request->getUserVar('authorNotes'));
			if (!in_array($decision, array('approved', 'corrections_requested'), true) || ($decision === 'corrections_requested' && $notes === '')) {
				$result = 'invalid';
			} else {
				$connection = Capsule::connection();
				$started = false;
				try {
					$connection->beginTransaction();
					$started = true;
					$current = Capsule::table('journal_payment_proofs')->where('context_id', $contextId)->where('proof_id', (int) $proof->proof_id)->lockForUpdate()->first();
					if (!$current || $current->status !== 'awaiting_author') {
						$connection->rollBack();
						$started = false;
						$result = 'closed';
					} else {
						$user = $request->getUser();
						$responderName = $user ? $user->getFullName() : $payment->payer_name;
						$now = date('Y-m-d H:i:s');
						Capsule::table('journal_payment_proofs')->where('proof_id', (int) $proof->proof_id)->update(array(
							'status' => $decision,
							'author_notes' => mb_substr($notes, 0, 10000),
							'responded_by' => $user ? (int) $user->getId() : null,
							'responded_name' => mb_substr((string) $responderName, 0, 255),
							'responded_at' => $now,
							'updated_at' => $now,
						));
						$connection->commit();
						$started = false;
						$this->auditLog($contextId, $request, $decision === 'approved' ? 'proof_approved' : 'proof_correction_requested', 'proofreading', $proof->proof_id, $payment->article_id,
							array('version' => (int) $proof->version_number, 'status' => 'awaiting_author'),
							array('version' => (int) $proof->version_number, 'status' => $decision, 'responded_name' => $responderName, 'author_notes' => $notes),
							$decision === 'approved' ? 'Galley akhir disetujui penulis untuk diterbitkan.' : 'Penulis mengajukan koreksi galley akhir.'
						);
						$request->redirect(null, 'journalPayment', 'proofreading', null, array('access' => $token, 'result' => $decision));
						return;
					}
				} catch (Throwable $e) {
					if ($started) $connection->rollBack();
					error_log('Journal Payment proofreading response failed: ' . $e->getMessage());
					$result = 'failed';
				}
			}
			$proof = Capsule::table('journal_payment_proofs')->where('proof_id', (int) $proof->proof_id)->first();
		}

		$this->sendSecureDocumentHeaders();
		$templateMgr = $this->setupPublicTemplate($request, 'Proofreading Galley Akhir');
		$templateMgr->assign(array(
			'proofreading' => $proof,
			'record' => $payment,
			'proofreadingStatusLabel' => $this->proofreadingStatusLabel($proof->status),
			'proofreadingResult' => $result,
			'proofreadingFileUrl' => $request->getDispatcher()->url($request, ROUTE_PAGE, $context->getPath(), 'journalPayment', 'proofreadingFile', null, array('access' => $token)),
		));
		$templateMgr->display(self::$plugin->getTemplateResource('proofreading.tpl'));
	}

	/** Stream a private final galley to either its token holder or assigned editor. */
	public function proofreadingFile($args, $request) {
		$context = $this->requireContext($request);
		$contextId = (int) $context->getId();
		$token = trim((string) $request->getUserVar('access'));
		$id = (int) $request->getUserVar('id');
		$proof = null;
		if (preg_match('/^[a-f0-9]{64}$/', $token)) {
			$proof = Capsule::table('journal_payment_proofs')->where('context_id', $contextId)->where('access_token', $token)->first();
		} elseif ($id > 0) {
			$user = $request->getUser();
			if ($user) {
				$candidate = Capsule::table('journal_payment_proofs')->where('context_id', $contextId)->where('proof_id', $id)->first();
				$payment = $candidate ? $this->findAccessiblePayment($contextId, (int) $candidate->payment_id, $user) : null;
				if ($payment) $proof = $candidate;
			}
		}
		if (!$proof) { http_response_code(404); exit; }
		$path = $this->proofreadingUploadDirectory($contextId) . DIRECTORY_SEPARATOR . basename((string) $proof->stored_file);
		if (!is_file($path)) { http_response_code(404); exit; }
		$this->sendSecureDocumentHeaders();
		header('Content-Type: application/pdf');
		header('Content-Disposition: inline; filename="' . str_replace('"', '', basename((string) $proof->file_name)) . '"');
		header('Content-Length: ' . filesize($path));
		readfile($path);
		exit;
	}

	private function requireContext($request) {
		$context = $request->getContext();
		if (!$context) fatalError('Halaman pembayaran harus dibuka dari konteks sebuah jurnal.');
		$this->ensureSchema($context->getId());
		return $context;
	}

	/**
	 * Some manually-upgraded OJS installations do not execute a plugin's
	 * install migration. Create the table lazily on first use when necessary.
	 */
	private function ensureSchema($contextId) {
		static $ready = false;
		if ($ready) return;
		if ((string) self::$plugin->getSetting((int) $contextId, 'schemaReady') === '1.28.0') {
			$ready = true;
			return;
		}
		self::$plugin->import('JournalPaymentSchemaMigration');
		$migration = new JournalPaymentSchemaMigration();
		$migration->up();
		self::$plugin->updateSetting((int) $contextId, 'schemaReady', '1.28.0', 'string');
		$ready = true;
	}

	private function requirePaymentStaff($request) {
		$context = $this->requireContext($request);
		$user = $request->getUser();
		if (!$user) {
			Validation::redirectLogin();
			exit;
		}
		$isPaymentStaff = $this->canManagePaymentRecords($user, $context->getId()) || $this->isProductionEditor($user, $context->getId());
		if (!$isPaymentStaff) fatalError('Anda tidak memiliki hak untuk mengelola pembayaran jurnal ini.');
		return $context;
	}

	private function isFullPaymentManager($user, $contextId) {
		return self::$plugin->userHasFullPaymentAccess($user, (int) $contextId);
	}

	private function canManagePaymentRecords($user, $contextId) {
		return $user && ($this->isFullPaymentManager($user, $contextId) || $user->hasRole(array(ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR), (int) $contextId));
	}

	/** Production Editor is an Assistant user group enabled for OJS Production stage. */
	private function isProductionEditor($user, $contextId) {
		if (!$user) return false;
		if (Capsule::schema()->hasTable('journal_payment_production_fees') && Capsule::table('journal_payment_production_fees')->where('context_id', (int) $contextId)->where('production_editor_user_id', (int) $user->getId())->exists()) return true;
		$productionStage = defined('WORKFLOW_STAGE_ID_PRODUCTION') ? constant('WORKFLOW_STAGE_ID_PRODUCTION') : 5;
		return Capsule::table('stage_assignments as sa')
			->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')
			->join('user_group_settings as ugn', function ($join) {
				$join->on('ugn.user_group_id', '=', 'ug.user_group_id')->where('ugn.setting_name', '=', 'name');
			})
			->join('user_group_stage as ugs', function ($join) use ($contextId, $productionStage) {
				$join->on('ugs.user_group_id', '=', 'ug.user_group_id')
					->where('ugs.context_id', '=', (int) $contextId)
					->where('ugs.stage_id', '=', (int) $productionStage);
			})
			->join('submissions as s', 's.submission_id', '=', 'sa.submission_id')
			->where('sa.user_id', (int) $user->getId())
			->where('ug.context_id', (int) $contextId)
			->where('ug.role_id', defined('ROLE_ID_ASSISTANT') ? constant('ROLE_ID_ASSISTANT') : 4097)
			->where(function ($name) { $name->whereRaw("LOWER(ugn.setting_value) LIKE '%production%editor%'")->orWhereRaw("LOWER(ugn.setting_value) LIKE '%editor%produksi%'"); })
			->where('s.context_id', (int) $contextId)
			->exists();
	}

	/** Null means unrestricted manager access; an array restricts an editor to OJS assignments. */
	private function getAccessibleSubmissionIds($contextId, $user) {
		if ($this->isFullPaymentManager($user, $contextId)) return null;
		$productionStage = defined('WORKFLOW_STAGE_ID_PRODUCTION') ? constant('WORKFLOW_STAGE_ID_PRODUCTION') : 5;
		$assistantRole = defined('ROLE_ID_ASSISTANT') ? constant('ROLE_ID_ASSISTANT') : 4097;
		$rows = Capsule::table('stage_assignments as sa')
			->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')
			->leftJoin('user_group_settings as ugn', function ($join) {
				$join->on('ugn.user_group_id', '=', 'ug.user_group_id')
					->where('ugn.setting_name', '=', 'name');
			})
			->leftJoin('user_group_stage as ugs', function ($join) use ($contextId, $productionStage) {
				$join->on('ugs.user_group_id', '=', 'ug.user_group_id')
					->where('ugs.context_id', '=', (int) $contextId)
					->where('ugs.stage_id', '=', (int) $productionStage);
			})
			->join('submissions as s', 's.submission_id', '=', 'sa.submission_id')
			->where('sa.user_id', (int) $user->getId())
			->where('ug.context_id', (int) $contextId)
			->where('s.context_id', (int) $contextId)
			->where(function ($roles) use ($assistantRole) {
				$roles->whereIn('ug.role_id', array(ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR))
					->orWhere(function ($production) use ($assistantRole) {
						$production->where('ug.role_id', $assistantRole)->whereNotNull('ugs.user_group_id')
							->where(function ($name) { $name->whereRaw("LOWER(ugn.setting_value) LIKE '%production%editor%'")->orWhereRaw("LOWER(ugn.setting_value) LIKE '%editor%produksi%'"); });
					});
			})
			->select('sa.submission_id')
			->distinct()
			->get();
		$ids = array();
		foreach ($rows as $row) $ids[] = (string) $row->submission_id;
		return array_values(array_unique($ids));
	}

	private function findAccessiblePayment($contextId, $paymentId, $user) {
		$record = Capsule::table('journal_payment_records')
			->where('context_id', (int) $contextId)
			->where('payment_id', (int) $paymentId)
			->first();
		if (!$record || $this->isFullPaymentManager($user, $contextId)) return $record;
		$ids = $this->getAccessibleSubmissionIds($contextId, $user);
		return in_array((string) $record->article_id, $ids, true) ? $record : null;
	}

	private function getAssignedEditorLabel($submissionId) {
		$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
		$userDao = DAORegistry::getDAO('UserDAO');
		$names = array();
		foreach (array(ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR) as $roleId) {
			$assignments = $stageAssignmentDao->getBySubmissionAndRoleId((int) $submissionId, $roleId);
			while ($assignment = $assignments->next()) {
				$user = $userDao->getById($assignment->getUserId());
				if ($user) $names[$user->getId()] = $user->getFullName();
			}
		}
		return $names ? implode(', ', array_values($names)) : 'Belum ditugaskan';
	}

	/** Resolve editor labels for one dashboard page without per-submission assignment queries. */
	private function getAssignedEditorLabels($submissionIds) {
		$ids = array_values(array_unique(array_filter(array_map('intval', $submissionIds))));
		if (!$ids) return array();
		$rows = Capsule::table('stage_assignments as sa')
			->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')
			->whereIn('sa.submission_id', $ids)
			->whereIn('ug.role_id', array(ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR))
			->select('sa.submission_id', 'sa.user_id')
			->distinct()
			->get();

		$userIds = array();
		foreach ($rows as $row) $userIds[(int) $row->user_id] = true;
		$userDao = DAORegistry::getDAO('UserDAO');
		$userNames = array();
		foreach (array_keys($userIds) as $userId) {
			$user = $userDao->getById($userId);
			if ($user) $userNames[$userId] = $user->getFullName();
		}

		$labels = array();
		foreach ($rows as $row) {
			$submissionId = (int) $row->submission_id;
			$userId = (int) $row->user_id;
			if (!isset($userNames[$userId])) continue;
			if (!isset($labels[$submissionId])) $labels[$submissionId] = array();
			$labels[$submissionId][$userId] = $userNames[$userId];
		}
		foreach ($labels as $submissionId => $names) $labels[$submissionId] = implode(', ', array_values($names));
		return $labels;
	}

	private function setupPublicTemplate($request, $title) {
		$this->setupTemplate($request);
		$context = $request->getContext();
		$templateMgr = TemplateManager::getManager($request);
		$assetVersion = self::$plugin->getAssetVersion();
		$templateMgr->addStyleSheet('journalPayment', self::$plugin->getAssetUrl($request) . '/styles/payment.css?v=' . $assetVersion);
		$templateMgr->addJavaScript('journalPayment', self::$plugin->getAssetUrl($request) . '/js/payment.js?v=' . $assetVersion, array('contexts' => array('frontend')));
		$templateMgr->addJavaScript('journalPaymentManager', self::$plugin->getAssetUrl($request) . '/js/manage.js?v=' . $assetVersion, array('contexts' => array('frontend')));
		$templateMgr->assign(array(
			'pageTitleTranslated' => $title,
			'journalPaymentPreviewModalTemplate' => self::$plugin->getTemplateResource('previewModal.tpl'),
			'jpAccentColor' => $this->getThemeAccent($context->getId()),
			'jpOrganization' => self::$plugin->getSetting($context->getId(), 'organizationName') ?: $context->getLocalizedName(),
			'jpThemeMode' => $this->getThemeMode($context->getId()),
		));
		return $templateMgr;
	}

	/** Resolve the selected display preset while remaining compatible with older settings. */
	private function getThemeMode($contextId) {
		$mode = (string) self::$plugin->getSetting((int) $contextId, 'themeMode');
		return in_array($mode, array('auto', 'manual', 'standalone'), true) ? $mode : 'auto';
	}

	/** Use a stable print and interface accent when the independent palette is selected. */
	private function getThemeAccent($contextId) {
		if ($this->getThemeMode($contextId) === 'standalone') return '#1F6C53';
		$accent = trim((string) self::$plugin->getSetting((int) $contextId, 'accentColor'));
		return preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? $accent : '#1f6f5f';
	}

	private function getSubmissionData($context, $articleId) {
		$articleId = trim((string) $articleId);
		if ($articleId === '' || !ctype_digit($articleId) || strlen($articleId) > 20) return null;
		try {
			$submission = Services::get('submission')->get((int) $articleId);
		} catch (Exception $e) {
			return null;
		}
		if (!$submission || (int) $submission->getData('contextId') !== (int) $context->getId()) return null;
		$publication = $submission->getCurrentPublication();
		if (!$publication) return null;

		$author = $publication->getPrimaryAuthor();
		$authors = (array) $publication->getData('authors');
		if (!$author && !empty($authors)) $author = reset($authors);

		return array(
			'articleId' => (string) $submission->getId(),
			'name' => $author ? trim((string) $author->getFullName()) : '',
			'email' => $author ? trim((string) $author->getEmail()) : '',
			'title' => trim(strip_tags((string) $publication->getLocalizedFullTitle())),
		);
	}

	private function normalizeWhatsApp($phone) {
		$digits = preg_replace('/[^0-9]/', '', (string) $phone);
		if (strpos($digits, '0062') === 0) $digits = substr($digits, 2);
		if (strpos($digits, '0') === 0) {
			$digits = '62' . substr($digits, 1);
		} elseif (strpos($digits, '8') === 0) {
			$digits = '62' . $digits;
		}
		return preg_match('/^628[0-9]{7,12}$/', $digits) ? $digits : null;
	}

	private function publicationDateForRecord($contextId, $record) {
		$createdAt = !empty($record->created_at) ? strtotime((string) $record->created_at) : time();
		if (!$createdAt) $createdAt = time();
		$days = self::$plugin->getPackageDurationDays($contextId, isset($record->package_name) ? $record->package_name : '');
		return date('Y-m-d', strtotime('+' . max(1, (int) $days) . ' days', $createdAt));
	}

	/** Apply manual or OJS issue metadata to a dashboard page using batched queries. */
	private function applyIssueMetadataBatch($contextId, $records) {
		$metadataMap = json_decode((string) self::$plugin->getSetting($contextId, 'issueMetadata'), true);
		if (!is_array($metadataMap)) $metadataMap = array();
		$pendingByArticle = array();
		foreach ($records as $record) {
			$record->issue_volume = '';
			$record->issue_number = '';
			$record->issue_year = '';
			$metadataKey = !empty($record->payment_id) ? (string) $record->payment_id : '';
			if ($metadataKey !== '' && isset($metadataMap[$metadataKey]) && is_array($metadataMap[$metadataKey])) {
				$stored = $metadataMap[$metadataKey];
				$record->issue_volume = isset($stored['volume']) ? (string) $stored['volume'] : '';
				$record->issue_number = isset($stored['number']) ? (string) $stored['number'] : '';
				$record->issue_year = isset($stored['year']) ? (string) $stored['year'] : '';
			}
			if (empty($record->issue_volume) || empty($record->issue_number) || empty($record->issue_year)) {
				$pendingByArticle[(int) $record->article_id][] = $record;
			}
		}
		if (!$pendingByArticle) return;

		$publicationByArticle = array();
		$publicationIds = array();
		foreach (Capsule::table('submissions')
			->where('context_id', (int) $contextId)
			->whereIn('submission_id', array_keys($pendingByArticle))
			->select('submission_id', 'current_publication_id')
			->get() as $submission) {
			$publicationId = (int) $submission->current_publication_id;
			if (!$publicationId) continue;
			$publicationByArticle[(int) $submission->submission_id] = $publicationId;
			$publicationIds[$publicationId] = true;
		}
		if (!$publicationIds) return;

		$issueByPublication = array();
		$issueIds = array();
		foreach (Capsule::table('publication_settings')
			->whereIn('publication_id', array_keys($publicationIds))
			->where('setting_name', 'issueId')
			->select('publication_id', 'setting_value')
			->get() as $setting) {
			$issueId = (int) $setting->setting_value;
			if (!$issueId) continue;
			$issueByPublication[(int) $setting->publication_id] = $issueId;
			$issueIds[$issueId] = true;
		}
		if (!$issueIds) return;

		$issues = array();
		foreach (Capsule::table('issues')
			->where('journal_id', (int) $contextId)
			->whereIn('issue_id', array_keys($issueIds))
			->select('issue_id', 'volume', 'number', 'year')
			->get() as $issue) $issues[(int) $issue->issue_id] = $issue;

		foreach ($pendingByArticle as $articleId => $articleRecords) {
			if (!isset($publicationByArticle[$articleId])) continue;
			$publicationId = $publicationByArticle[$articleId];
			if (!isset($issueByPublication[$publicationId]) || !isset($issues[$issueByPublication[$publicationId]])) continue;
			$issue = $issues[$issueByPublication[$publicationId]];
			foreach ($articleRecords as $record) {
				if (empty($record->issue_volume) && $issue->volume !== null) $record->issue_volume = (string) $issue->volume;
				if (empty($record->issue_number) && $issue->number !== null) $record->issue_number = (string) $issue->number;
				if (empty($record->issue_year) && $issue->year !== null) $record->issue_year = (string) $issue->year;
			}
		}
	}

	/** Fill empty issue fields from the submission's current publication in OJS. */
	private function applyIssueMetadata($contextId, $record) {
		if (!$record) return;
		if (!isset($record->issue_volume)) $record->issue_volume = '';
		if (!isset($record->issue_number)) $record->issue_number = '';
		if (!isset($record->issue_year)) $record->issue_year = '';
		$metadataMap = json_decode((string) self::$plugin->getSetting($contextId, 'issueMetadata'), true);
		$metadataKey = !empty($record->payment_id) ? (string) $record->payment_id : '';
		if ($metadataKey !== '' && is_array($metadataMap) && isset($metadataMap[$metadataKey]) && is_array($metadataMap[$metadataKey])) {
			$stored = $metadataMap[$metadataKey];
			$record->issue_volume = isset($stored['volume']) ? (string) $stored['volume'] : '';
			$record->issue_number = isset($stored['number']) ? (string) $stored['number'] : '';
			$record->issue_year = isset($stored['year']) ? (string) $stored['year'] : '';
		}
		if (!empty($record->issue_volume) && !empty($record->issue_number) && !empty($record->issue_year)) return;
		if (!ctype_digit((string) $record->article_id)) return;
		try {
			$publicationId = Capsule::table('submissions')
				->where('context_id', (int) $contextId)
				->where('submission_id', (int) $record->article_id)
				->value('current_publication_id');
			if (!$publicationId) return;
			$issueId = Capsule::table('publication_settings')
				->where('publication_id', (int) $publicationId)
				->where('setting_name', 'issueId')
				->value('setting_value');
			if (!$issueId) return;
			$issue = Capsule::table('issues')
				->where('journal_id', (int) $contextId)
				->where('issue_id', (int) $issueId)
				->first();
			if (!$issue) return;
			if (empty($record->issue_volume) && $issue->volume !== null) $record->issue_volume = (string) $issue->volume;
			if (empty($record->issue_number) && $issue->number !== null) $record->issue_number = (string) $issue->number;
			if (empty($record->issue_year) && $issue->year !== null) $record->issue_year = (string) $issue->year;
		} catch (Throwable $e) {
			error_log('Journal Payment issue metadata lookup failed: ' . $e->getMessage());
		}
	}

	/** Store manual/snapshotted issue metadata without altering the payment table. */
	private function saveIssueMetadata($contextId, $paymentId, $metadata) {
		$metadataMap = json_decode((string) self::$plugin->getSetting($contextId, 'issueMetadata'), true);
		if (!is_array($metadataMap)) $metadataMap = array();
		$key = (string) ((int) $paymentId);
		if (!$metadata || empty($metadata['volume']) || empty($metadata['number']) || empty($metadata['year'])) {
			unset($metadataMap[$key]);
		} else {
			$metadataMap[$key] = array(
				'volume' => mb_substr((string) $metadata['volume'], 0, 32),
				'number' => mb_substr((string) $metadata['number'], 0, 40),
				'year' => mb_substr((string) $metadata['year'], 0, 8),
			);
		}
		self::$plugin->updateSetting($contextId, 'issueMetadata', json_encode($metadataMap), 'string');
	}

	private function issueLabel($record) {
		if (empty($record->issue_volume) || empty($record->issue_number) || empty($record->issue_year)) return '';
		return 'Volume ' . $record->issue_volume . ', Nomor ' . $record->issue_number . ', Tahun ' . $record->issue_year;
	}

	private function getExistingPayment($contextId, $articleId) {
		$articleId = trim((string) $articleId);
		if ($articleId === '' || !ctype_digit($articleId)) return null;
		return Capsule::table('journal_payment_records')
			->where('context_id', (int) $contextId)
			->where('article_id', $articleId)
			->orderBy('created_at', 'desc')
			->first();
	}

	private function isSubmissionPublished($contextId, $articleId) {
		if (!ctype_digit((string) $articleId)) return false;
		$status = Capsule::table('submissions')
			->where('context_id', (int) $contextId)
			->where('submission_id', (int) $articleId)
			->value('status');
		$publishedStatus = defined('STATUS_PUBLISHED') ? constant('STATUS_PUBLISHED') : 3;
		return $status !== null && (int) $status === (int) $publishedStatus;
	}

	private function paymentStatusLabel($status) {
		if ($status === 'verified') return 'Terverifikasi';
		if ($status === 'rejected') return 'Perlu Perbaikan';
		return 'Menunggu Verifikasi';
	}

	/** Persistent, privacy-preserving limiter shared across browser sessions. */
	private function allowPublicSearch($request, $contextId, $scope, $limit, $windowSeconds) {
		$user = $request->getUser();
		if ($user && ($user->hasRole(array(ROLE_ID_SITE_ADMIN), CONTEXT_SITE) || $user->hasRole(array(ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR), (int) $contextId))) return true;
		$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		$agent = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500) : '';
		$key = (string) Config::getVar('security', 'salt');
		if ($key === '') $key = (string) Config::getVar('general', 'base_url');
		$clientHash = hash_hmac('sha256', $ip . '|' . $agent, $key !== '' ? $key : 'journal-payment');
		$now = time();
		$connection = Capsule::connection();
		$started = false;
		try {
			$connection->beginTransaction();
			$started = true;
			$row = Capsule::table('journal_payment_rate_limits')->where('context_id', (int) $contextId)->where('scope', $scope)->where('client_hash', $clientHash)->lockForUpdate()->first();
			if ($row && !empty($row->blocked_until) && strtotime($row->blocked_until) > $now) {
				$connection->commit();
				return false;
			}
			$windowStart = $row ? strtotime($row->window_started_at) : 0;
			$attempts = ($row && $windowStart > $now - $windowSeconds) ? (int) $row->attempts + 1 : 1;
			$blocked = $attempts > (int) $limit;
			$data = array(
				'window_started_at' => ($row && $windowStart > $now - $windowSeconds) ? $row->window_started_at : date('Y-m-d H:i:s', $now),
				'attempts' => $attempts,
				'blocked_until' => $blocked ? date('Y-m-d H:i:s', $now + $windowSeconds) : null,
				'updated_at' => date('Y-m-d H:i:s', $now),
			);
			if ($row) Capsule::table('journal_payment_rate_limits')->where('rate_limit_id', (int) $row->rate_limit_id)->update($data);
			else Capsule::table('journal_payment_rate_limits')->insert(array_merge($data, array('context_id' => (int) $contextId, 'scope' => mb_substr($scope, 0, 40), 'client_hash' => $clientHash)));
			$connection->commit();
			return !$blocked;
		} catch (Throwable $e) {
			if ($started) $connection->rollBack();
			error_log('Journal Payment rate limiter failed: ' . $e->getMessage());
			return true;
		}
	}

	private function newDocumentAccessToken() {
		do { $token = bin2hex(random_bytes(32)); }
		while (Capsule::table('journal_payment_records')->where('document_access_token', $token)->exists());
		return $token;
	}

	private function sendSecureDocumentHeaders() {
		header('Cache-Control: private, no-store, max-age=0');
		header('Pragma: no-cache');
		header('Referrer-Policy: no-referrer');
		header('X-Robots-Tag: noindex, nofollow, noarchive');
		header('X-Content-Type-Options: nosniff');
	}

	/** Backfill tokens lazily so documents issued before this upgrade remain usable. */
	private function ensureDocumentAccessToken($contextId, $record) {
		if (!$record || !empty($record->document_access_token)) return;
		$token = $this->newDocumentAccessToken();
		Capsule::table('journal_payment_records')->where('context_id', (int) $contextId)->where('payment_id', (int) $record->payment_id)->whereNull('document_access_token')->update(array('document_access_token' => $token));
		$stored = Capsule::table('journal_payment_records')->where('context_id', (int) $contextId)->where('payment_id', (int) $record->payment_id)->value('document_access_token');
		$record->document_access_token = $stored ?: $token;
	}

	private function documentAccessAllowed($request, $contextId, $record, $token) {
		if (!$record) return false;
		$stored = isset($record->document_access_token) ? (string) $record->document_access_token : '';
		if ($stored !== '' && strlen($stored) === 64 && strlen((string) $token) === 64 && hash_equals($stored, (string) $token)) return true;
		$user = $request->getUser();
		if (!$user || !$user->hasRole(array(ROLE_ID_SITE_ADMIN), CONTEXT_SITE) && !$user->hasRole(array(ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR), (int) $contextId)) return false;
		return (bool) $this->findAccessiblePayment($contextId, (int) $record->payment_id, $user);
	}

	private function paymentAuditValues($record) {
		$fields = array('payer_name', 'payer_email', 'payer_phone', 'article_title', 'package_name', 'amount', 'status', 'publication_date', 'action_due_date', 'manager_notes', 'issue_volume', 'issue_number', 'issue_year');
		$values = array();
		foreach ($fields as $field) $values[$field] = isset($record->{$field}) ? $record->{$field} : null;
		return $values;
	}

	private function auditLog($contextId, $request, $action, $entityType, $entityId, $articleId, $oldValues = null, $newValues = null, $notes = null) {
		try {
			$user = $request ? $request->getUser() : null;
			Capsule::table('journal_payment_audit_logs')->insert(array(
				'context_id' => (int) $contextId,
				'actor_user_id' => $user ? (int) $user->getId() : null,
				'actor_name' => mb_substr($user ? $user->getFullName() : ($request ? 'Publik/Penulis' : 'Sistem Otomatis'), 0, 255),
				'action' => mb_substr((string) $action, 0, 80),
				'entity_type' => mb_substr((string) $entityType, 0, 40),
				'entity_id' => $entityId === null ? null : mb_substr((string) $entityId, 0, 190),
				'article_id' => $articleId === null ? null : mb_substr((string) $articleId, 0, 64),
				'old_values' => $oldValues === null ? null : json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				'new_values' => $newValues === null ? null : json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				'notes' => $notes === null ? null : mb_substr((string) $notes, 0, 5000),
				'created_at' => date('Y-m-d H:i:s'),
			));
		} catch (Throwable $e) { error_log('Journal Payment audit log failed: ' . $e->getMessage()); }
	}

	private function sendAndLogEmail($mail, $contextId, $record, $emailType, $subject, $request) {
		$sent = false;
		$error = null;
		try { $sent = (bool) $mail->send(); }
		catch (Throwable $e) { $error = $e->getMessage(); error_log('Journal Payment email failed: ' . $error); }
		try {
			$user = $request ? $request->getUser() : null;
			Capsule::table('journal_payment_email_logs')->insert(array(
				'context_id' => (int) $contextId,
				'payment_id' => isset($record->payment_id) ? (int) $record->payment_id : null,
				'article_id' => isset($record->article_id) ? mb_substr((string) $record->article_id, 0, 64) : null,
				'email_type' => mb_substr((string) $emailType, 0, 64),
				'recipient' => mb_substr((string) $record->payer_email, 0, 255),
				'subject' => mb_substr((string) $subject, 0, 500),
				'status' => $sent ? 'sent' : 'failed',
				'error_message' => $error ? mb_substr($error, 0, 5000) : null,
				'sent_by' => $user ? (int) $user->getId() : null,
				'created_at' => date('Y-m-d H:i:s'),
			));
		} catch (Throwable $e) { error_log('Journal Payment email log failed: ' . $e->getMessage()); }
		return $sent;
	}

	private function getAuditDashboardData($contextId) {
		$audits = Capsule::table('journal_payment_audit_logs')->where('context_id', (int) $contextId)->orderBy('created_at', 'desc')->orderBy('audit_id', 'desc')->limit(150)->get();
		$labels = array('payment_created' => 'Pembayaran dibuat', 'schedule_changed' => 'Jadwal berubah', 'payment_updated' => 'Data diubah', 'payment_deleted' => 'Pembayaran dihapus', 'payment_status_changed' => 'Status pembayaran', 'document_issued' => 'Dokumen diterbitkan', 'protected_pdf_generated' => 'PDF terlindungi dibuat', 'protected_pdf_revoked' => 'Versi PDF dicabut', 'document_integrity_failed' => 'Integritas PDF gagal', 'remittance_uploaded' => 'Setoran diunggah', 'remittance_verified' => 'Setoran diverifikasi', 'remittance_rejected' => 'Setoran ditolak', 'drive_folder_created' => 'Folder GD dibuat', 'drive_file_uploaded' => 'File GD diunggah', 'drive_file_renamed' => 'File GD diubah', 'drive_file_trashed' => 'File GD dihapus', 'proof_uploaded' => 'Galley akhir diunggah', 'proof_approved' => 'Galley disetujui penulis', 'proof_correction_requested' => 'Koreksi galley diajukan', 'production_fee_created' => 'Fee Production Editor dibuat', 'production_fee_updated' => 'Fee Production Editor diubah', 'production_payout_created' => 'Fee Production Editor dibayar', 'production_payout_received' => 'Fee dikonfirmasi diterima');
		foreach ($audits as $row) {
			$row->action_label = isset($labels[$row->action]) ? $labels[$row->action] : ucwords(str_replace('_', ' ', $row->action));
			$row->old_display = $this->formatAuditValues($row->old_values);
			$row->new_display = $this->formatAuditValues($row->new_values);
		}
		$emails = Capsule::table('journal_payment_email_logs')->where('context_id', (int) $contextId)->orderBy('created_at', 'desc')->orderBy('email_log_id', 'desc')->limit(150)->get();
		return array('auditLogs' => $audits, 'emailLogs' => $emails);
	}

	private function formatAuditValues($json) {
		$values = json_decode((string) $json, true);
		if (!is_array($values)) return '';
		$parts = array();
		foreach ($values as $key => $value) {
			if ($value === null || $value === '') continue;
			if (is_bool($value)) $value = $value ? 'Ya' : 'Tidak';
			if (is_array($value)) $value = implode(', ', $value);
			$parts[] = str_replace('_', ' ', $key) . ': ' . $value;
		}
		return implode(' · ', $parts);
	}

	private function jsonResponse($success, $message, $data, $status) {
		http_response_code((int) $status);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store, private');
		header('X-Content-Type-Options: nosniff');
		echo json_encode(array('success' => (bool) $success, 'message' => $message, 'data' => $data));
		exit;
	}

	private function linesSetting($contextId, $name, $default) {
		$raw = trim((string) self::$plugin->getSetting($contextId, $name));
		if (!$raw) $raw = $default;
		return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw))));
	}

	/** Upload or resend the latest final galley without creating a second editor assignment. */
	private function handleProofreadingAction($request, $context, $action) {
		$contextId = (int) $context->getId();
		$user = $request->getUser();
		$paymentId = (int) $request->getUserVar('paymentId');
		$record = $this->findAccessiblePayment($contextId, $paymentId, $user);
		if (!$record || $record->status !== 'verified') {
			$this->redirectManagerDashboard($request, array('proofResult' => 'unavailable'));
			return;
		}
		if ($action === 'sendEmail') {
			$latest = $this->latestProofForPayment($contextId, $paymentId);
			$sent = $latest ? $this->sendProofreadingInvitation($request, $context, $record, $latest) : false;
			$this->redirectManagerDashboard($request, array('proofResult' => $sent ? 'emailSent' : ($latest ? 'emailFailed' : 'unavailable')));
			return;
		}
		if ($action !== 'upload') {
			$this->redirectManagerDashboard($request, array('proofResult' => 'invalid'));
			return;
		}
		if ($this->isSubmissionPublished($contextId, $record->article_id)) {
			$this->redirectManagerDashboard($request, array('proofResult' => 'published'));
			return;
		}
		$file = isset($_FILES['proofreadingFile']) ? $_FILES['proofreadingFile'] : null;
		$notes = trim((string) $request->getUserVar('proofreadingNotes'));
		$validated = $this->validateProofreadingUpload($file, $contextId);
		if (!$validated['valid']) {
			$this->redirectManagerDashboard($request, array('proofResult' => 'invalidFile'));
			return;
		}
		$stored = $this->storeProofreadingUpload($file, $contextId);
		if (!$stored['success']) {
			$this->redirectManagerDashboard($request, array('proofResult' => 'failed'));
			return;
		}
		$connection = Capsule::connection();
		$started = false;
		try {
			$connection->beginTransaction();
			$started = true;
			$latest = Capsule::table('journal_payment_proofs')->where('context_id', $contextId)->where('payment_id', $paymentId)->orderBy('version_number', 'desc')->lockForUpdate()->first();
			$version = $latest ? (int) $latest->version_number + 1 : 1;
			Capsule::table('journal_payment_proofs')->where('context_id', $contextId)->where('payment_id', $paymentId)->whereIn('status', array('awaiting_author', 'corrections_requested'))->update(array('status' => 'superseded', 'updated_at' => date('Y-m-d H:i:s')));
			$token = $this->newProofreadingAccessToken();
			$now = date('Y-m-d H:i:s');
			$proofId = Capsule::table('journal_payment_proofs')->insertGetId(array(
				'context_id' => $contextId,
				'payment_id' => $paymentId,
				'article_id' => mb_substr((string) $record->article_id, 0, 64),
				'version_number' => $version,
				'access_token' => $token,
				'file_name' => mb_substr(basename((string) $file['name']), 0, 255),
				'stored_file' => $stored['file'],
				'file_mime' => 'application/pdf',
				'file_size' => (int) $file['size'],
				'status' => 'awaiting_author',
				'editor_notes' => mb_substr($notes, 0, 10000),
				'uploaded_by' => (int) $user->getId(),
				'uploaded_at' => $now,
				'updated_at' => $now,
			));
			$connection->commit();
			$started = false;
			$proof = Capsule::table('journal_payment_proofs')->where('proof_id', $proofId)->first();
			$this->auditLog($contextId, $request, 'proof_uploaded', 'proofreading', $proofId, $record->article_id, $latest ? array('version' => (int) $latest->version_number, 'status' => $latest->status) : null,
				array('version' => $version, 'status' => 'awaiting_author', 'file_name' => basename((string) $file['name']), 'editor_notes' => $notes), 'Galley akhir diunggah dan dikirim untuk persetujuan penulis.');
			$sent = $this->sendProofreadingInvitation($request, $context, $record, $proof);
			$this->redirectManagerDashboard($request, array('proofResult' => $sent ? 'uploaded' : 'uploadedEmailFailed'));
		} catch (Throwable $e) {
			if ($started) {
				$connection->rollBack();
				@unlink($stored['path']);
			}
			error_log('Journal Payment proof upload failed: ' . $e->getMessage());
			$this->redirectManagerDashboard($request, array('proofResult' => $started ? 'failed' : 'uploadedEmailFailed'));
		}
	}

	private function validateProofreadingUpload($file, $contextId) {
		$maxMb = max(5, min(50, (int) (self::$plugin->getSetting($contextId, 'maxUploadMb') ?: 5)));
		if (!$file || !isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK || (int) $file['size'] < 1 || (int) $file['size'] > $maxMb * 1024 * 1024) return array('valid' => false);
		$finfo = new finfo(FILEINFO_MIME_TYPE);
		return array('valid' => (string) $finfo->file($file['tmp_name']) === 'application/pdf');
	}

	private function storeProofreadingUpload($file, $contextId) {
		$dir = $this->proofreadingUploadDirectory($contextId);
		if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) return array('success' => false);
		$name = bin2hex(random_bytes(24)) . '.pdf';
		$path = $dir . DIRECTORY_SEPARATOR . $name;
		if (!move_uploaded_file($file['tmp_name'], $path)) return array('success' => false);
		@chmod($path, 0640);
		return array('success' => true, 'file' => $name, 'path' => $path);
	}

	private function proofreadingUploadDirectory($contextId) {
		return $this->uploadDirectory($contextId) . DIRECTORY_SEPARATOR . 'proofreading';
	}

	private function newProofreadingAccessToken() {
		do { $token = bin2hex(random_bytes(32)); }
		while (Capsule::table('journal_payment_proofs')->where('access_token', $token)->exists());
		return $token;
	}

	private function latestProofForPayment($contextId, $paymentId) {
		return Capsule::table('journal_payment_proofs')->where('context_id', (int) $contextId)->where('payment_id', (int) $paymentId)->orderBy('version_number', 'desc')->first();
	}

	private function latestProofsByPayment($contextId, $paymentIds) {
		if ($paymentIds instanceof Traversable) $paymentIds = iterator_to_array($paymentIds, false);
		if (!is_array($paymentIds)) return array();
		$ids = array_values(array_unique(array_filter(array_map('intval', $paymentIds))));
		if (!$ids) return array();
		$map = array();
		foreach (Capsule::table('journal_payment_proofs')->where('context_id', (int) $contextId)->whereIn('payment_id', $ids)->orderBy('version_number', 'desc')->get() as $proof) {
			if (!isset($map[(int) $proof->payment_id])) $map[(int) $proof->payment_id] = $proof;
		}
		return $map;
	}

	private function proofreadingStatusLabel($status) {
		$labels = array('not_uploaded' => 'Belum ada galley', 'awaiting_author' => 'Menunggu persetujuan penulis', 'corrections_requested' => 'Koreksi diajukan', 'approved' => 'Siap dipublikasikan', 'superseded' => 'Versi diganti');
		return isset($labels[$status]) ? $labels[$status] : 'Status tidak diketahui';
	}

	private function sendProofreadingInvitation($request, $context, $record, $proof) {
		if (!$proof || !filter_var($record->payer_email, FILTER_VALIDATE_EMAIL)) return false;
		$contactEmail = trim((string) $context->getData('contactEmail'));
		$contactName = trim((string) $context->getData('contactName'));
		if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) return false;
		$url = $request->getDispatcher()->url($request, ROUTE_PAGE, $context->getPath(), 'journalPayment', 'proofreading', null, array('access' => $proof->access_token));
		$escape = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
		$subject = 'Persetujuan Galley Akhir - ID Artikel ' . $record->article_id . ' - Versi ' . $proof->version_number;
		$body = '<p>Yth. ' . $escape($record->payer_name) . ',</p>'
			. '<p>Galley akhir artikel ID <strong>' . $escape($record->article_id) . '</strong> versi <strong>' . (int) $proof->version_number . '</strong> telah siap diperiksa.</p>'
			. (!empty($proof->editor_notes) ? '<p>Catatan editor: ' . nl2br($escape($proof->editor_notes)) . '</p>' : '')
			. '<p>Silakan periksa seluruh PDF, kemudian pilih <strong>Setujui untuk Diterbitkan</strong> atau <strong>Ajukan Koreksi</strong> melalui tautan aman berikut:<br><a href="' . $escape($url) . '">' . $escape($url) . '</a></p>'
			. '<p>Jangan membagikan tautan ini karena berfungsi sebagai akses pribadi ke galley artikel.</p><p>Hormat kami,<br>' . $escape($context->getLocalizedName()) . '</p>';
		import('lib.pkp.classes.mail.Mail');
		$mail = new Mail();
		$mail->setFrom($contactEmail, $contactName !== '' ? $contactName : $context->getLocalizedName());
		$mail->addRecipient($record->payer_email, $record->payer_name);
		$mail->setSubject($subject);
		$mail->setBody($body);
		return $this->sendAndLogEmail($mail, (int) $context->getId(), $record, 'proofreading_invitation', $subject, $request);
	}

	private function validateUpload($file, $contextId) {
		$maxMb = (int) (self::$plugin->getSetting($contextId, 'maxUploadMb') ?: 5);
		if ((int) $file['size'] < 1 || (int) $file['size'] > $maxMb * 1024 * 1024) {
			return array('valid' => false, 'error' => 'Ukuran bukti pembayaran harus antara 1 byte dan ' . $maxMb . ' MB.');
		}
		$finfo = new finfo(FILEINFO_MIME_TYPE);
		$mime = $finfo->file($file['tmp_name']);
		$allowed = array(
			'application/pdf' => 'pdf',
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/webp' => 'webp',
		);
		if (!isset($allowed[$mime])) {
			return array('valid' => false, 'error' => 'Jenis file tidak diizinkan. Gunakan PDF, JPG, PNG, atau WebP.');
		}
		return array('valid' => true, 'mime' => $mime, 'extension' => $allowed[$mime]);
	}

	private function storeUpload($file, $validated, $contextId) {
		$dir = $this->uploadDirectory($contextId);
		if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
			return array('success' => false, 'error' => 'Bukti pembayaran tidak dapat disimpan. Periksa izin direktori files OJS.');
		}
		$name = bin2hex(random_bytes(24)) . '.' . $validated['extension'];
		$path = $dir . DIRECTORY_SEPARATOR . $name;
		if (!move_uploaded_file($file['tmp_name'], $path)) {
			return array('success' => false, 'error' => 'Bukti pembayaran tidak dapat disimpan. Periksa izin direktori files OJS.');
		}
		@chmod($path, 0640);
		return array('success' => true, 'file' => $name, 'path' => $path);
	}

	private function uploadDirectory($contextId) {
		return rtrim(Config::getVar('files', 'files_dir'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'journalPayment' . DIRECTORY_SEPARATOR . (int) $contextId;
	}

	private function newTrackingCode() {
		do {
			$code = 'JP-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
		} while (Capsule::table('journal_payment_records')->where('tracking_code', $code)->exists());
		return $code;
	}

	private function receiptNumber($context, $id) {
		$abbr = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $context->getPath()));
		return 'JP/' . ($abbr ?: 'OJS') . '/' . date('Y') . '/' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
	}
}
