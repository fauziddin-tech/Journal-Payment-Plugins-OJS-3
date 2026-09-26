<?php

namespace APP\plugins\generic\journalPayment;

/**
 * Create protected, server-side PDFs with the TCPDF copy bundled by OJS/PKP.
 * No shell command, Composer install, or external PDF service is required.
 */
class JournalPaymentPdfGenerator {
	private $tcpdfPath;

	public function __construct() {
		$this->tcpdfPath = $this->findTcpdf();
	}

	public function isAvailable() {
		return $this->tcpdfPath !== null;
	}

	public function getUnavailableMessage() {
		return 'Mesin TCPDF plugin tidak ditemukan atau tidak dapat dimuat. Unggah ulang paket plugin lengkap sebelum menerbitkan PDF terlindungi.';
	}

	/** Return metadata for the exact encrypted bytes written to disk. */
	public function generate($type, $data, $outputPath) {
		if (!$this->isAvailable()) return array('success' => false, 'error' => $this->getUnavailableMessage());
		if (!class_exists('TCPDF', false)) require_once($this->tcpdfPath);
		if (!class_exists('TCPDF', false)) return array('success' => false, 'error' => $this->getUnavailableMessage());

		try {
			$pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
			$pdf->SetCreator('OJS Journal Payment');
			$pdf->SetAuthor($data['journalName']);
			$pdf->SetTitle($data['documentTitle']);
			$pdf->SetSubject('Dokumen resmi jurnal dengan verifikasi QR');
			$pdf->SetKeywords('OJS, verification, journal, ' . $type);
			$pdf->setPrintHeader(false);
			$pdf->setPrintFooter(false);
			$pdf->SetMargins(18, 16, 18);
			$pdf->SetAutoPageBreak(false, 15);
			$pdf->setImageScale(1.25);
			// Empty user password opens normally. A random, discarded owner password
			// prevents routine editors from changing the permission dictionary.
			$ownerPassword = bin2hex(random_bytes(24));
			// TCPDF expects the permissions to block. Printing (including high
			// quality) is intentionally omitted, while every editing path is denied.
			$pdf->SetProtection(array('modify', 'copy', 'annot-forms', 'fill-forms', 'extract', 'assemble'), '', $ownerPassword, 3);
			$pdf->AddPage();
			if ($type === 'receipt') $this->renderReceipt($pdf, $data);
			else $this->renderDocument($pdf, $data, $type === 'loa');
			$pdf->Output($outputPath, 'F');
			@chmod($outputPath, 0640);
			if (!is_file($outputPath) || filesize($outputPath) < 500) throw new \Exception('Berkas PDF tidak berhasil ditulis.');
			$check = @fopen($outputPath, 'rb');
			$header = $check ? fread($check, 5) : '';
			if ($check) fclose($check);
			if ($header !== '%PDF-') throw new \Exception('Keluaran generator bukan berkas PDF yang valid.');
			return array(
				'success' => true,
				'sha256' => hash_file('sha256', $outputPath),
				'byteSize' => filesize($outputPath),
				'encryption' => 'AES-256; print-only',
			);
		} catch (\Throwable $e) {
			if (is_file($outputPath)) @unlink($outputPath);
			return array('success' => false, 'error' => $e->getMessage());
		}
	}

	private function renderReceipt($pdf, $d) {
		$accent = $this->hexColor($d['accentColor']);
		$pdf->SetFillColor($accent[0], $accent[1], $accent[2]);
		$pdf->Rect(0, 0, 210, 7, 'F');
		$pdf->SetTextColor($accent[0], $accent[1], $accent[2]);
		$pdf->SetFont('dejavusans', 'B', 23);
		$pdf->SetXY(18, 25);
		$pdf->Cell(118, 12, 'KUITANSI PEMBAYARAN', 0, 0, 'L');
		$pdf->SetFont('dejavusans', '', 9);
		$pdf->SetTextColor(86, 101, 96);
		$pdf->SetXY(18, 17);
		$pdf->Cell(118, 7, $d['organizationName'], 0, 0, 'L');
		$pdf->SetXY(142, 18);
		$pdf->Cell(50, 6, 'Nomor Kuitansi', 0, 2, 'L');
		$pdf->SetFont('dejavusans', 'B', 10);
		$pdf->Cell(50, 6, $d['documentNumber'], 0, 2, 'L');
		$pdf->SetTextColor(18, 112, 74);
		$pdf->Cell(50, 6, 'LUNAS / TERVERIFIKASI', 0, 0, 'L');
		$pdf->SetDrawColor(216, 226, 222);
		$pdf->Line(18, 42, 192, 42);

		$rows = array(
			array('Nama Lengkap', $d['payerName'], 'Tanggal Verifikasi', $d['verifiedDate']),
			array('ID Artikel', $d['articleId'], 'Metode Pembayaran', $d['paymentMethod']),
			array('Jenis Pembayaran', $d['packageName'], 'Kode Pelacakan', $d['trackingCode']),
		);
		$y = 53;
		foreach ($rows as $row) {
			$this->labelValue($pdf, 18, $y, 80, $row[0], $row[1]);
			$this->labelValue($pdf, 108, $y, 84, $row[2], $row[3]);
			$y += 23;
		}
		$this->labelValue($pdf, 18, $y, 174, 'Judul Artikel', $d['articleTitle'], 17);
		$y += 27;
		$pdf->SetTextColor($accent[0], $accent[1], $accent[2]);
		$pdf->SetFont('dejavusans', 'B', 20);
		$pdf->SetXY(18, $y);
		$pdf->Cell(100, 12, $d['amountLabel'], 0, 0, 'L');
		$this->verificationBlock($pdf, $d, 148, 211, 28);
		$pdf->SetTextColor(91, 106, 101);
		$pdf->SetFont('dejavusans', '', 8);
		$pdf->SetXY(18, 253);
		$pdf->MultiCell(115, 10, $d['footerText'], 0, 'L');
		$this->securityFooter($pdf, $d);
	}

	private function renderDocument($pdf, $d, $isLoa) {
		$accent = $this->hexColor($d['accentColor']);
		$pdf->SetDrawColor($accent[0], $accent[1], $accent[2]);
		$pdf->SetLineWidth(0.8);
		$pdf->Rect(7, 7, 196, 283);
		$pdf->SetLineWidth(0.25);
		$pdf->Rect(9, 9, 192, 279);
		if (!empty($d['documentLogo'])) $this->dataImage($pdf, $d['documentLogo'], 55, 18, 22, 22);
		$pdf->SetTextColor($accent[0], $accent[1], $accent[2]);
		$pdf->SetFont('dejavusans', 'B', 10);
		$pdf->SetXY(80, 20);
		$pdf->MultiCell(76, 6, mb_strtoupper($d['organizationName']), 0, 'L');
		$pdf->SetTextColor(42, 54, 50);
		$pdf->SetFont('dejavusans', '', 8);
		$pdf->SetX(80);
		$pdf->MultiCell(76, 5, $d['journalName'], 0, 'L');
		$pdf->SetTextColor($accent[0], $accent[1], $accent[2]);
		$pdf->SetFont('dejavusans', 'B', 24);
		$pdf->SetXY(18, 53);
		$pdf->Cell(174, 12, $isLoa ? 'LETTER OF ACCEPTANCE' : 'SERTIFIKAT PUBLIKASI', 0, 1, 'C');
		$pdf->SetTextColor(96, 111, 106);
		$pdf->SetFont('dejavusans', '', 8);
		$pdf->Cell(174, 5, 'Nomor: ' . $d['documentNumber'], 0, 1, 'C');
		$pdf->SetTextColor(32, 43, 39);
		$pdf->SetFont('dejavuserif', '', 11);
		$pdf->SetXY(25, 87);
		$pdf->Cell(160, 7, $isLoa ? 'Kepada Yth.' : 'Diberikan kepada', 0, 1, 'C');
		$pdf->SetFont('dejavuserif', 'B', 18);
		$pdf->SetXY(25, 102);
		$pdf->MultiCell(160, 10, $d['payerName'], 0, 'C');
		$pdf->SetFont('dejavuserif', '', 10);
		$pdf->SetXY(30, 125);
		$pdf->MultiCell(150, 7, $d['documentText'], 0, 'C');
		$pdf->SetDrawColor(216, 226, 222);
		$pdf->Line(24, 151, 186, 151);
		$pdf->SetTextColor(30, 42, 38);
		$pdf->SetFont('dejavuserif', 'B', 10);
		$pdf->SetXY(28, 158);
		$pdf->Cell(154, 6, 'Judul Artikel', 0, 1, 'L');
		$pdf->SetFont('dejavuserif', 'I', 9);
		$pdf->SetX(28);
		$pdf->MultiCell(154, 6, $d['articleTitle'], 0, 'L');
		$pdf->SetFont('dejavuserif', '', 9);
		$pdf->SetX(28);
		$pdf->MultiCell(154, 6, 'ID Artikel: ' . $d['articleId'] . "\n" . ($d['issueLabel'] !== '' ? ($isLoa ? 'Rencana Terbit: ' : 'Terbit Pada: ') . $d['issueLabel'] . "\n" : '') . ($isLoa ? 'Prediksi Terbit: ' : 'Tanggal Terbit: ') . $d['publicationDate'], 0, 'L');
		$pdf->Line(24, 207, 186, 207);
		$this->verificationBlock($pdf, $d, 27, 222, 28);
		$pdf->SetFont('dejavusans', '', 8);
		$pdf->SetTextColor(55, 67, 63);
		$pdf->SetXY(115, 218);
		$pdf->Cell(70, 6, ($isLoa ? 'Diterbitkan' : 'Ditetapkan') . ' pada ' . $d['issuedDate'], 0, 1, 'C');
		if (!empty($d['stampImage'])) $this->dataImage($pdf, $d['stampImage'], 118, 227, 27, 27);
		if (!empty($d['signatureImage'])) $this->dataImage($pdf, $d['signatureImage'], 140, 226, 35, 24);
		$pdf->SetFont('dejavuserif', 'B', 10);
		$pdf->SetXY(108, 253);
		$pdf->Cell(82, 6, $d['signerName'], 'B', 1, 'C');
		$pdf->SetFont('dejavusans', '', 8);
		$pdf->SetX(108);
		$pdf->Cell(82, 5, $d['signerTitle'], 0, 1, 'C');
		$this->securityFooter($pdf, $d);
	}

	private function labelValue($pdf, $x, $y, $width, $label, $value, $height = 11) {
		$pdf->SetTextColor(92, 108, 102);
		$pdf->SetFont('dejavusans', '', 8);
		$pdf->SetXY($x, $y);
		$pdf->Cell($width, 5, $label, 0, 1, 'L');
		$pdf->SetTextColor(26, 37, 33);
		$pdf->SetFont('dejavusans', 'B', 10);
		$pdf->SetXY($x, $y + 5);
		$pdf->MultiCell($width, $height, $value, 'B', 'L');
	}

	private function verificationBlock($pdf, $d, $x, $y, $size) {
		$style = array('border' => 0, 'padding' => 0, 'fgcolor' => array(0, 0, 0), 'bgcolor' => false);
		$pdf->write2DBarcode($d['verificationUrl'], 'QRCODE,M', $x, $y, $size, $size, $style, 'N');
		$pdf->SetTextColor(94, 108, 103);
		$pdf->SetFont('dejavusans', '', 6.7);
		$pdf->SetXY($x - 4, $y + $size + 2);
		$pdf->MultiCell($size + 8, 8, 'Pindai untuk memverifikasi data dokumen', 0, 'C');
	}

	private function securityFooter($pdf, $d) {
		$pdf->SetTextColor(103, 116, 112);
		$pdf->SetFont('dejavusans', '', 6.5);
		$pdf->SetXY(18, 279);
		$pdf->MultiCell(174, 7, 'Dokumen PDF dilindungi AES-256 (cetak saja). Keaslian wajib diperiksa melalui QR. ID dokumen: ' . $d['documentPublicId'], 0, 'C');
	}

	private function dataImage($pdf, $dataUri, $x, $y, $width, $height) {
		if (!preg_match('#^data:image/[a-zA-Z0-9.+-]+;base64,(.+)$#s', (string) $dataUri, $matches)) return;
		$binary = base64_decode($matches[1], true);
		if ($binary === false || strlen($binary) > 8 * 1024 * 1024) return;
		$pdf->Image('@' . $binary, $x, $y, $width, $height, '', '', '', false, 300, '', false, false, 0, true, false, false);
	}

	private function hexColor($hex) {
		$hex = ltrim((string) $hex, '#');
		if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) $hex = '176b52';
		return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
	}

	private function findTcpdf() {
		$candidates = array(__DIR__ . '/vendor/tcpdf/tcpdf.php');
		if (defined('PKP_LIB_PATH')) $candidates[] = rtrim(PKP_LIB_PATH, DIRECTORY_SEPARATOR) . '/lib/vendor/tecnickcom/tcpdf/tcpdf.php';
		if (defined('BASE_SYS_DIR')) $candidates[] = rtrim(BASE_SYS_DIR, DIRECTORY_SEPARATOR) . '/lib/pkp/lib/vendor/tecnickcom/tcpdf/tcpdf.php';
		$candidates[] = dirname(dirname(dirname(__DIR__))) . '/lib/pkp/lib/vendor/tecnickcom/tcpdf/tcpdf.php';
		foreach (array_unique($candidates) as $candidate) if (is_file($candidate)) return $candidate;
		return null;
	}
}
