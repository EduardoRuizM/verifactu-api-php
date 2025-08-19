<?php
//
// =============== Veri*Factu API 1.0.5 ===============
//
// Copyright (c) 2025 Eduardo Ruiz <eruiz@dataclick.es>
// https://github.com/EduardoRuizM/verifactu-api-php
//

// MySQL/MariaDB class
class MySQLDB {

	private $conn;

	public function __construct($host, $port, $user, $password, $dbname) {

		$this->connect($host, $port, $user, $password, $dbname);
	}

	private function connect($host, $port, $user, $password, $dbname) {

		$this->conn = new mysqli($host, $user, $password, $dbname, $port);

		if ($this->conn->connect_error) {
			die('Error: ' . $this->conn->connect_error);
		}
	}

	public function query($sql, $params = []) {

		$stmt = $this->conn->prepare($sql);
		if (!$stmt)
			die('Error: ' . $this->conn->error);

		if (count($params)) {

			$types = '';
			foreach ($params as $param) {

				if (is_int($param))
					$types.= 'i';
				elseif (is_float($param))
					$types.= 'd';
				else
					$types.= 's';
			}
			$stmt->bind_param($types, ...$params);
		}

		$stmt->execute();
		$result = $stmt->get_result();

		if ($result)
			return $result->fetch_all(MYSQLI_ASSOC);

		return [];
	}
}

// Obtener valor del array
function getKey($array, $key, $default = '') {

	$value = (is_array($array) && array_key_exists($key, $array)) ? $array[$key] : $default;

	return is_string($value) ? trim($value) : $value;
}

// RESTful API
function apiPath($meth, $pattern) {

	global $method, $path;

	if ($method != $meth)
		return false;

	if (preg_match('#^' . preg_replace('/(:\w+)/', '([^/]+)', $pattern) . '$|\?#', $path, $matches)) {

		preg_match_all('/:\w+/', $pattern, $keys);

		return array_combine(array_map(fn($k) => ltrim($k, ':'), $keys[0]), array_slice($matches, 1)) ?: true;
	}

	return false;
}

// Respuesta Ok
function okResponse($ret, $status = 200) {

	http_response_code($status);
	header('Content-Type: application/json');
	die(json_encode($ret));
}

// Respuesta de error con estado
function errResponse($status, $err, $extra = array()) {

	http_response_code($status);
	header('Content-Type: application/json');
	die(json_encode(array_merge(array('error' => $err), $extra)));
}

// Obtener último Id insertado
function lastId(&$db) {

	$q = $db->query('SELECT LAST_INSERT_ID() AS id');

	return ($q && count($q)) ? $q[0]['id'] : 0;
}

// Obtener siguiente número de factura del año actual
function nextNum(&$db, &$company, $type) {

	$f = (substr($type, 0, 1) == 'R') ? 'R' : 'F';
	$invoice = $db->query('SELECT MAX(num) AS Mx FROM invoices WHERE company_id = ? AND YEAR(dt) = ? AND LEFT(verifactu_type, 1) = ?', [$company['id'], date('Y'), $f]);

	return ($invoice && count($invoice)) ? $invoice[0]['Mx'] + 1 : getKey($company, 'first_num', 1);
}

// Número de factura utilizando fórmula de la empresa
function numFmt(&$company, &$invoice) {

	$f = (substr($invoice['verifactu_type'], 0, 1) == 'F') ? 'formula' : 'formula_r';
	$s = ($company[$f]) ? $company[$f] : ((substr($invoice['verifactu_type'], 0, 1) == 'F') ? '%n%' : 'R-%n%');
	if (preg_match('/%n\.([0-9]+)%/', $s, $tmp))
		$s = str_replace('%n.' . $tmp[1] . '%', sprintf('%0' . $tmp[1] . 'd', $invoice['num']), $s);

	$y = strtotime($invoice['dt']);

	return strtr($s, array('%n%' => $invoice['num'], '%y%' => date('y', $y), '%Y%' => date('Y', $y)));
}

// URL código QR de la AEAT (pruebas/producción)
function getUrlAEAT(&$company) {

	return ($company['test'] == 1) ? 'https://prewww2.aeat.es/' : 'https://www2.agenciatributaria.gob.es/';
}

// Código QR de la factura
function getQR(&$company, &$invoice) {

	include 'qrcode.inc.php';
	$url =	getUrlAEAT($company) . 'wlpl/TIKE-CONT/ValidarQR?nif=' . rawurlencode($company['vat_id']) . '&numserie=' . rawurlencode($invoice['number_format']) .
		'&fecha=' . rawurlencode(date('d-m-Y', strtotime($invoice['dt']))) . '&importe=' . rawurlencode($invoice['total']);

	$qr = new QRCode($url, array('s' => 'qr-m', 'sf' => 6));
	$qr->output_image();
	$image = $qr->render_image();
	imagepng($image);
	imagedestroy($image);
	exit;
}
