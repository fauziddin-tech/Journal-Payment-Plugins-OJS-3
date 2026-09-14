<?php

/**
 * Small Google Drive v3 client for hosts where Composer/exec is unavailable.
 * Authentication uses an OAuth refresh token owned by the journal account.
 */
class JournalPaymentGoogleDrive {
	const FOLDER_MIME = 'application/vnd.google-apps.folder';

	private $plugin;
	private $contextId;
	private $credentials;

	public function __construct($plugin, $contextId) {
		$this->plugin = $plugin;
		$this->contextId = (int) $contextId;
		$this->credentials = $plugin->getGoogleDriveCredentials($this->contextId);
	}

	public function isConfigured() {
		return !empty($this->credentials['client_id'])
			&& !empty($this->credentials['client_secret'])
			&& !empty($this->credentials['refresh_token']);
	}

	public function listChildren($folderId) {
		$query = "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), $folderId) . "' in parents and trashed = false";
		$files = array();
		$pageToken = '';
		do {
			$params = array(
				'q' => $query,
				'fields' => 'nextPageToken,files(id,name,mimeType,size,modifiedTime,webViewLink,parents)',
				'pageSize' => '1000',
				'orderBy' => 'folder,name_natural',
				'spaces' => 'drive',
				'supportsAllDrives' => 'true',
				'includeItemsFromAllDrives' => 'true',
			);
			if ($pageToken !== '') $params['pageToken'] = $pageToken;
			$result = $this->request('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
			if (!$result['success']) return $result + array('files' => array());
			$batch = isset($result['data']['files']) && is_array($result['data']['files']) ? $result['data']['files'] : array();
			$files = array_merge($files, $batch);
			$pageToken = isset($result['data']['nextPageToken']) ? (string) $result['data']['nextPageToken'] : '';
		} while ($pageToken !== '');
		return array('success' => true, 'message' => 'OK', 'files' => $files);
	}

	public function getMetadata($fileId) {
		$params = http_build_query(array('fields' => 'id,name,mimeType,parents,trashed,webViewLink', 'supportsAllDrives' => 'true'), '', '&', PHP_QUERY_RFC3986);
		return $this->request('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?' . $params);
	}

	public function createFolder($parentId, $name) {
		return $this->request(
			'POST',
			'https://www.googleapis.com/drive/v3/files?fields=id,name,webViewLink&supportsAllDrives=true',
			json_encode(array('name' => $name, 'mimeType' => self::FOLDER_MIME, 'parents' => array($parentId))),
			'application/json; charset=UTF-8'
		);
	}

	public function uploadFile($folderId, $name, $mime, $bytes) {
		try {
			$boundary = 'jpdrive-' . bin2hex(random_bytes(10));
		} catch (Exception $e) {
			$boundary = 'jpdrive-' . str_replace('.', '', uniqid('', true));
		}
		$metadata = json_encode(array('name' => $name, 'parents' => array($folderId)));
		$body = '--' . $boundary . "\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
			. $metadata . "\r\n--" . $boundary . "\r\nContent-Type: " . $mime . "\r\n\r\n"
			. $bytes . "\r\n--" . $boundary . '--';
		return $this->request(
			'POST',
			'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,mimeType,size,modifiedTime,webViewLink&supportsAllDrives=true',
			$body,
			'multipart/related; boundary=' . $boundary,
			120
		);
	}

	public function rename($fileId, $name) {
		return $this->request(
			'PATCH',
			'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?fields=id,name&supportsAllDrives=true',
			json_encode(array('name' => $name)),
			'application/json; charset=UTF-8'
		);
	}

	/** Move an item to Drive Trash so an accidental deletion remains recoverable. */
	public function trash($fileId) {
		return $this->request(
			'PATCH',
			'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?fields=id,trashed&supportsAllDrives=true',
			json_encode(array('trashed' => true)),
			'application/json; charset=UTF-8'
		);
	}

	private function request($method, $url, $body = null, $contentType = '', $timeout = 35) {
		$token = $this->accessToken();
		if (!$token['success']) return $token;
		if (!function_exists('curl_init')) return array('success' => false, 'message' => 'Ekstensi cURL PHP belum aktif.');
		$headers = array('Authorization: Bearer ' . $token['token'], 'Accept: application/json');
		if ($contentType !== '') $headers[] = 'Content-Type: ' . $contentType;
		$ch = curl_init($url);
		$options = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 12,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_CUSTOMREQUEST => $method,
		);
		if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;
		curl_setopt_array($ch, $options);
		$response = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);
		$data = json_decode($response === false ? '' : $response, true);
		if ($code < 200 || $code >= 300) {
			$message = isset($data['error']['message']) ? $data['error']['message'] : ($error ?: 'Google Drive menolak permintaan.');
			return array('success' => false, 'message' => 'Google Drive HTTP ' . $code . ': ' . $message, 'code' => $code);
		}
		return array('success' => true, 'message' => 'OK', 'data' => is_array($data) ? $data : array());
	}

	private function accessToken() {
		if (!$this->isConfigured()) return array('success' => false, 'message' => 'Kredensial OAuth Google Drive belum dikonfigurasi.');
		$now = time();
		if (!empty($this->credentials['access_token']) && !empty($this->credentials['expires_at']) && (int) $this->credentials['expires_at'] > $now + 90) {
			return array('success' => true, 'message' => 'OK', 'token' => $this->credentials['access_token']);
		}
		if (!function_exists('curl_init')) return array('success' => false, 'message' => 'Ekstensi cURL PHP belum aktif.');
		$ch = curl_init('https://oauth2.googleapis.com/token');
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_CONNECTTIMEOUT => 12,
			CURLOPT_TIMEOUT => 25,
			CURLOPT_POSTFIELDS => http_build_query(array(
				'client_id' => $this->credentials['client_id'],
				'client_secret' => $this->credentials['client_secret'],
				'refresh_token' => $this->credentials['refresh_token'],
				'grant_type' => 'refresh_token',
			)),
		));
		$response = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);
		$data = json_decode($response === false ? '' : $response, true);
		if ($code !== 200 || empty($data['access_token'])) {
			$message = isset($data['error_description']) ? $data['error_description'] : ($error ?: 'Token tidak diterima.');
			return array('success' => false, 'message' => 'OAuth Google HTTP ' . $code . ': ' . $message);
		}
		$this->credentials['access_token'] = (string) $data['access_token'];
		$this->credentials['expires_at'] = $now + max(300, (int) (isset($data['expires_in']) ? $data['expires_in'] : 3600));
		$this->plugin->saveGoogleDriveCredentials($this->contextId, $this->credentials);
		return array('success' => true, 'message' => 'OK', 'token' => $this->credentials['access_token']);
	}
}
