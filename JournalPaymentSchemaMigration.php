<?php

/**
 * @file JournalPaymentSchemaMigration.php
 * @brief Idempotent schema for Journal Payment (OJS 3.5).
 */

namespace APP\plugins\generic\journalPayment;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class JournalPaymentSchemaMigration extends Migration {
	public function up() {
		if (Schema::hasTable('journal_payment_records')) {
			$this->addDocumentColumns();
			$this->addFinanceColumns();
			$this->addSecurityColumns();
			$this->createRemittanceTable();
			$this->createAuditTable();
			$this->createEmailLogTable();
			$this->createRateLimitTable();
			$this->createProofreadingTable();
			$this->createProductionFeeTables();
			$this->createProtectedDocumentTable();
			return;
		}
		Schema::create('journal_payment_records', function (Blueprint $table) {
			$table->bigIncrements('payment_id');
			$table->bigInteger('context_id');
			$table->string('tracking_code', 40)->unique();
			$table->string('receipt_no', 64)->nullable()->unique();
			$table->string('article_id', 64);
			$table->string('payer_name', 255);
			$table->string('payer_email', 255);
			$table->string('payer_phone', 40)->nullable();
			$table->text('article_title')->nullable();
			$table->string('package_name', 255);
			$table->bigInteger('amount');
			$table->string('payment_method', 100)->nullable();
			$table->string('proof_file', 255);
			$table->string('proof_name', 255);
			$table->string('proof_mime', 100);
			$table->string('status', 24)->default('pending');
			$table->text('payer_notes')->nullable();
			$table->text('manager_notes')->nullable();
			$table->dateTime('created_at');
			$table->dateTime('updated_at');
			$table->dateTime('verified_at')->nullable();
			$table->bigInteger('verified_by')->nullable();
			$table->index(array('context_id', 'status'), 'jp_context_status');
			$table->index(array('context_id', 'article_id'), 'jp_context_article');
			$table->index(array('context_id', 'created_at'), 'jp_context_created');
		});
		$this->addDocumentColumns();
		$this->addFinanceColumns();
		$this->addSecurityColumns();
		$this->createRemittanceTable();
		$this->createAuditTable();
		$this->createEmailLogTable();
		$this->createRateLimitTable();
		$this->createProofreadingTable();
		$this->createProductionFeeTables();
		$this->createProtectedDocumentTable();
	}

	private function addDocumentColumns() {
		$schema = Schema::getFacadeRoot();
		if (!$schema->hasColumn('journal_payment_records', 'publication_date')) {
			$schema->table('journal_payment_records', function (Blueprint $table) { $table->date('publication_date')->nullable(); });
		}
		foreach (array('loa_no' => 96, 'certificate_no' => 96) as $column => $length) {
			if (!$schema->hasColumn('journal_payment_records', $column)) $schema->table('journal_payment_records', function (Blueprint $table) use ($column, $length) { $table->string($column, $length)->nullable(); });
		}
		foreach (array('loa_issued_at', 'certificate_issued_at') as $column) {
			if (!$schema->hasColumn('journal_payment_records', $column)) $schema->table('journal_payment_records', function (Blueprint $table) use ($column) { $table->dateTime($column)->nullable(); });
		}
		foreach (array('loa_issued_by', 'certificate_issued_by') as $column) {
			if (!$schema->hasColumn('journal_payment_records', $column)) $schema->table('journal_payment_records', function (Blueprint $table) use ($column) { $table->bigInteger($column)->nullable(); });
		}
		if (!$schema->hasColumn('journal_payment_records', 'action_due_date')) {
			$schema->table('journal_payment_records', function (Blueprint $table) { $table->date('action_due_date')->nullable(); });
		}
		if (!$schema->hasColumn('journal_payment_records', 'deadline_status_key')) {
			$schema->table('journal_payment_records', function (Blueprint $table) { $table->string('deadline_status_key', 48)->nullable(); });
		}
		if (!$schema->hasColumn('journal_payment_records', 'deadline_manual')) {
			$schema->table('journal_payment_records', function (Blueprint $table) { $table->boolean('deadline_manual')->default(false); });
		}
		if (!$schema->hasColumn('journal_payment_records', 'reminder_state')) {
			$schema->table('journal_payment_records', function (Blueprint $table) { $table->text('reminder_state')->nullable(); });
		}
	}

	/** Freeze the handler and issue used by finance once a published article is recognized. */
	private function addFinanceColumns() {
		$schema = Schema::getFacadeRoot();
		if (!$schema->hasColumn('journal_payment_records', 'finance_editor_id')) {
			$schema->table('journal_payment_records', function (Blueprint $table) { $table->bigInteger('finance_editor_id')->nullable(); });
		}
		if (!$schema->hasColumn('journal_payment_records', 'finance_issue_key')) {
			$schema->table('journal_payment_records', function (Blueprint $table) { $table->string('finance_issue_key', 190)->nullable(); });
		}
		if (!$schema->hasColumn('journal_payment_records', 'finance_issue_label')) {
			$schema->table('journal_payment_records', function (Blueprint $table) { $table->string('finance_issue_label', 255)->nullable(); });
		}
		if (!$schema->hasColumn('journal_payment_records', 'finance_editor_percent')) {
			$schema->table('journal_payment_records', function (Blueprint $table) { $table->smallInteger('finance_editor_percent')->nullable(); });
		}
		if (!$schema->hasColumn('journal_payment_records', 'finance_admin_percent')) {
			$schema->table('journal_payment_records', function (Blueprint $table) { $table->smallInteger('finance_admin_percent')->nullable(); });
		}
		if (!$schema->hasColumn('journal_payment_records', 'finance_locked_at')) {
			$schema->table('journal_payment_records', function (Blueprint $table) { $table->dateTime('finance_locked_at')->nullable(); });
		}
	}

	/** One permanent high-entropy token protects every public document for a payment. */
	private function addSecurityColumns() {
		$schema = Schema::getFacadeRoot();
		if (!$schema->hasColumn('journal_payment_records', 'document_access_token')) {
			$schema->table('journal_payment_records', function (Blueprint $table) {
				$table->string('document_access_token', 64)->nullable();
				$table->index('document_access_token', 'jp_document_access_token');
			});
		}
	}

	/** Immutable encrypted document versions; only one active row per type/payment. */
	private function createProtectedDocumentTable() {
		if (Schema::hasTable('journal_payment_documents')) return;
		Schema::create('journal_payment_documents', function (Blueprint $table) {
			$table->bigIncrements('document_id');
			$table->bigInteger('context_id');
			$table->bigInteger('payment_id');
			$table->string('article_id', 64);
			$table->string('document_type', 24);
			$table->string('document_number', 96);
			$table->integer('version_number')->default(1);
			$table->string('stored_file', 255);
			$table->string('sha256', 64);
			$table->bigInteger('byte_size');
			$table->string('protection', 80)->default('AES-256; print-only');
			$table->string('status', 20)->default('active');
			$table->bigInteger('issued_by')->nullable();
			$table->dateTime('issued_at');
			$table->bigInteger('revoked_by')->nullable();
			$table->dateTime('revoked_at')->nullable();
			$table->dateTime('created_at');
			$table->unique(array('payment_id', 'document_type', 'version_number'), 'jp_document_payment_type_version');
			$table->index(array('context_id', 'payment_id', 'document_type', 'status'), 'jp_document_active_lookup');
			$table->index(array('context_id', 'sha256'), 'jp_document_hash_lookup');
		});
	}

	/** Multiple deposits are allowed because an issue can receive published articles gradually. */
	private function createRemittanceTable() {
		if (Schema::hasTable('journal_payment_remittances')) return;
		Schema::create('journal_payment_remittances', function (Blueprint $table) {
			$table->bigIncrements('remittance_id');
			$table->bigInteger('context_id');
			$table->bigInteger('editor_user_id');
			$table->string('issue_key', 190);
			$table->string('issue_label', 255);
			$table->bigInteger('amount');
			$table->string('proof_file', 255);
			$table->string('proof_name', 255);
			$table->string('proof_mime', 100);
			$table->string('status', 24)->default('pending');
			$table->text('editor_notes')->nullable();
			$table->text('manager_notes')->nullable();
			$table->dateTime('created_at');
			$table->dateTime('updated_at');
			$table->dateTime('verified_at')->nullable();
			$table->bigInteger('verified_by')->nullable();
			$table->index(array('context_id', 'editor_user_id', 'issue_key'), 'jp_remit_context_editor_issue');
			$table->index(array('context_id', 'status'), 'jp_remit_context_status');
		});
	}

	private function createAuditTable() {
		if (Schema::hasTable('journal_payment_audit_logs')) return;
		Schema::create('journal_payment_audit_logs', function (Blueprint $table) {
			$table->bigIncrements('audit_id');
			$table->bigInteger('context_id');
			$table->bigInteger('actor_user_id')->nullable();
			$table->string('actor_name', 255);
			$table->string('action', 80);
			$table->string('entity_type', 40);
			$table->string('entity_id', 190)->nullable();
			$table->string('article_id', 64)->nullable();
			$table->text('old_values')->nullable();
			$table->text('new_values')->nullable();
			$table->text('notes')->nullable();
			$table->dateTime('created_at');
			$table->index(array('context_id', 'created_at'), 'jp_audit_context_created');
			$table->index(array('context_id', 'article_id'), 'jp_audit_context_article');
		});
	}

	private function createEmailLogTable() {
		if (Schema::hasTable('journal_payment_email_logs')) return;
		Schema::create('journal_payment_email_logs', function (Blueprint $table) {
			$table->bigIncrements('email_log_id');
			$table->bigInteger('context_id');
			$table->bigInteger('payment_id')->nullable();
			$table->string('article_id', 64)->nullable();
			$table->string('email_type', 64);
			$table->string('recipient', 255);
			$table->string('subject', 500);
			$table->string('status', 20);
			$table->text('error_message')->nullable();
			$table->bigInteger('sent_by')->nullable();
			$table->dateTime('created_at');
			$table->index(array('context_id', 'created_at'), 'jp_email_context_created');
			$table->index(array('context_id', 'article_id'), 'jp_email_context_article');
		});
	}

	private function createRateLimitTable() {
		if (Schema::hasTable('journal_payment_rate_limits')) return;
		Schema::create('journal_payment_rate_limits', function (Blueprint $table) {
			$table->bigIncrements('rate_limit_id');
			$table->bigInteger('context_id');
			$table->string('scope', 40);
			$table->string('client_hash', 64);
			$table->dateTime('window_started_at');
			$table->integer('attempts')->default(0);
			$table->dateTime('blocked_until')->nullable();
			$table->dateTime('updated_at');
			$table->unique(array('context_id', 'scope', 'client_hash'), 'jp_rate_context_scope_client');
			$table->index('updated_at', 'jp_rate_updated');
		});
	}

	/** Keep every uploaded final proof as an immutable version with one author decision. */
	private function createProofreadingTable() {
		if (Schema::hasTable('journal_payment_proofs')) return;
		Schema::create('journal_payment_proofs', function (Blueprint $table) {
			$table->bigIncrements('proof_id');
			$table->bigInteger('context_id');
			$table->bigInteger('payment_id');
			$table->string('article_id', 64);
			$table->integer('version_number')->default(1);
			$table->string('access_token', 64)->unique();
			$table->string('file_name', 255);
			$table->string('stored_file', 255);
			$table->string('file_mime', 100)->default('application/pdf');
			$table->bigInteger('file_size')->default(0);
			$table->string('status', 32)->default('awaiting_author');
			$table->text('editor_notes')->nullable();
			$table->text('author_notes')->nullable();
			$table->bigInteger('uploaded_by');
			$table->dateTime('uploaded_at');
			$table->bigInteger('responded_by')->nullable();
			$table->string('responded_name', 255)->nullable();
			$table->dateTime('responded_at')->nullable();
			$table->dateTime('updated_at');
			$table->unique(array('payment_id', 'version_number'), 'jp_proof_payment_version');
			$table->index(array('context_id', 'article_id'), 'jp_proof_context_article');
			$table->index(array('context_id', 'status'), 'jp_proof_context_status');
		});
	}

	/** Snapshot every OJS Production Editor assignment and its payout history. */
	private function createProductionFeeTables() {
		if (!Schema::hasTable('journal_payment_production_fees')) {
			Schema::create('journal_payment_production_fees', function (Blueprint $table) {
				$table->bigIncrements('production_fee_id');
				$table->bigInteger('context_id');
				$table->bigInteger('payment_id');
				$table->string('article_id', 64);
				$table->bigInteger('production_editor_user_id');
				$table->bigInteger('stage_assignment_id')->nullable();
				$table->bigInteger('amount');
				$table->dateTime('assigned_at')->nullable();
				$table->dateTime('created_at');
				$table->dateTime('updated_at');
				$table->unique(array('payment_id', 'production_editor_user_id'), 'jp_prod_fee_payment_user');
				$table->index(array('context_id', 'production_editor_user_id'), 'jp_prod_fee_context_user');
				$table->index(array('context_id', 'article_id'), 'jp_prod_fee_context_article');
			});
		}
		if (!Schema::hasTable('journal_payment_production_payouts')) {
			Schema::create('journal_payment_production_payouts', function (Blueprint $table) {
				$table->bigIncrements('production_payout_id');
				$table->bigInteger('context_id');
				$table->bigInteger('production_editor_user_id');
				$table->bigInteger('amount');
				$table->string('proof_file', 255);
				$table->string('proof_name', 255);
				$table->string('proof_mime', 100);
				$table->string('status', 24)->default('pending');
				$table->text('manager_notes')->nullable();
				$table->text('production_editor_notes')->nullable();
				$table->bigInteger('created_by');
				$table->dateTime('created_at');
				$table->dateTime('updated_at');
				$table->dateTime('received_at')->nullable();
				$table->bigInteger('received_by')->nullable();
				$table->index(array('context_id', 'production_editor_user_id'), 'jp_prod_pay_context_user');
				$table->index(array('context_id', 'status'), 'jp_prod_pay_context_status');
			});
		}
	}

	/** Data is intentionally kept when the plugin is removed. */
	public function down() {
	}
}
