<?php
//
// =============== Veri*Factu API 1.0.1 ===============
//
// Copyright (c) 2025 Eduardo Ruiz <eruiz@dataclick.es>
// https://github.com/EduardoRuizM/verifactu-api-php
//
// Permission is hereby granted, free of charge, to any person obtaining a copy
// of this software and associated documentation files (the "Software"), to deal
// in the Software without restriction, including without limitation the rights
// to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
// copies of the Software, and to permit persons to whom the Software is
// furnished to do so, subject to the following conditions:
//
// The above copyright notice and this permission notice shall be included in
// all copies or substantial portions of the Software.
//
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
// AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
// OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
// THE SOFTWARE

include 'includes/config.inc.php';
include 'includes/verifactu.xml.php';

// Crear y leer token
if (!is_file($verifactu_token_file))
	file_put_contents($verifactu_token_file, '<?php $backend_token = "' . bin2hex(random_bytes(30)) . '";');

include $verifactu_token_file;

// Comprobaciones y conectar a la base de datos
include 'includes/utils.inc.php';

if (!$cert_file || !$cert_passwd)
	errResponse(401, 'Certificate not found');

if (!$software_company_name || !$software_company_nif || !$software_name || !$software_id || !$software_version || !$software_install_number)
	errResponse(401, 'Software info not found');

if ($allow_ip && $allow_ip !== getKey($_SERVER, 'REMOTE_ADDR'))
	errResponse(401, 'Client not allowed');

if (!$mysql_host || !$mysql_port || !$mysql_user || !$mysql_password || !$mysql_database)
	errResponse(401, 'No database config');

// Iniciar base de datos y variables para la API
$db = new MySQLDB($mysql_host, $mysql_port, $mysql_user, $mysql_password, $mysql_database);
$path = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
$method = strtoupper(getKey($_SERVER, 'REQUEST_METHOD', 'GET'));
$verifactu = new verifactuXML($db, $cert_file, $cert_passwd, $verifactu_log_file, $verifactu_save_responses, $software_company_name, $software_company_nif, $software_name, $software_id, $software_version, $software_install_number);

// Comprobar token
$company_id = 0;
if (getKey($path, 1) !== 'api' || getKey($path, 2) !== $backend_token)
	errResponse(401, 'Missing or wrong token');

// Procesar facturas para la AEAT
if ($method === 'GET' && getKey($path, 3) == 'process')
	okResponse($verifactu->Pending());

// Localizar empresa
if (!($company_id = intval(getKey($path, 3))) || !($company = $db->query('SELECT * FROM companies WHERE id = ?', [$company_id])) || !count($company))
	errResponse(404, 'Company not found');

$company = $company[0];
$path = implode('/', array_slice($path, 4));
$timezone = isset($timezone) ? $timezone : 'Europe/Madrid';

if (apiPath('GET', 'query'))
	okResponse($verifactu->Consulta($company, intval(getKey($_GET, 'year')), intval(getKey($_GET, 'month'))));

if (apiPath('GET', 'invoices')) {

	$invoices = $db->query("SELECT *, CONVERT_TZ(verifactu_dt, 'UTC', ?) AS verifactu_dt_local FROM invoices WHERE company_id = ? ORDER BY dt", [$timezone, $company_id]);
	$ret = array('data' => $invoices);
	foreach ($ret['data'] as &$invoice)
		$invoice['number_format'] = numFmt($company, $invoice);

	okResponse($ret);
}

if (($vars = apiPath('GET', 'invoices/:id'))) {

	$invoice = $db->query("SELECT *, CONVERT_TZ(verifactu_dt, 'UTC', ?) AS verifactu_dt_local FROM invoices WHERE id = ? AND company_id = ?", [$timezone, intval(getKey($vars, 'id')), $company_id]);
	if ($invoice) {

		$invoice = $invoice[0];
		$invoice['number_format'] = numFmt($company, $invoice);
		$invoice['lines'] = $db->query('SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY num', [$invoice['id']]);
		okResponse($invoice);

	} else
		errResponse(404, 'Not found');
}

if (($vars = apiPath('GET', 'invoices/:id/qr'))) {

	$invoice = $db->query('SELECT * FROM invoices WHERE id = ? AND company_id = ?', [intval(getKey($vars, 'id')), $company_id]);
	if ($invoice) {

		$invoice = $invoice[0];
		$invoice['number_format'] = numFmt($company, $invoice);
		getQR($company, $invoice);

	} else
		errResponse(404, 'Not found');
}

function getData() {

	$data = json_decode(file_get_contents('php://input'), true);
	$fields = explode(' ', 'name');
	foreach ($fields as $field) {

		if (getKey($data, $field) === '')
			errResponse(400, 'Missing required field: ' . $field, array('field' => $field));
	}

	return $data;
}

function insertInvoice(&$db, &$verifactu, &$company, &$data, $type, $refs = null, $stype = null) {

	if (strtolower(getKey($_SERVER, 'CONTENT_TYPE')) !== 'application/json')
		errResponse(415, 'Unsupported Media Type');

	try {

		$tvat = $bi = $total = 0;
		if (array_key_exists('lines', $data) && is_array($data['lines'])) {

			foreach ($data['lines'] as $line) {

				$price = round(getKey($line, 'units', 1) * $line['price'], 2);
				$bi = round($bi + $price, 2);
				if (array_key_exists('vat', $line) && $line['vat']) {

					$line_vat = round($price * ($line['vat'] / 100), 2);
					$tvat = round($tvat + $line_vat, 2);
					$price = round($price + $line_vat, 2);
				}

				$total = round($total + $price, 2);
			}

		} else
			errResponse(400, 'No invoice lines');

		$invoice = $db->query('INSERT INTO invoices SET company_id = ?, dt = CURRENT_TIMESTAMP, num = ?, name = ?, vat_id = ?, address = ?, postal_code = ?, ' .
			'city = ?, state = ?, country = ?, tvat = ?, bi = ?, total = ?, email = ?, ref = ?, comments = ?, verifactu_type = ?, verifactu_stype = ?',
			[$company['id'], nextNum($db, $company, $type), trim($data['name']), isset($data['vat_id']) ? $verifactu->cod($data['vat_id']) : null,
			getKey($data, 'address', null), getKey($data, 'postal_code', null), getKey($data, 'city', null), getKey($data, 'state', null),
			getKey($data, 'country', null), $tvat, $bi, $total, getKey($data, 'email', null), getKey($data, 'ref', null),
			getKey($data, 'comments', null), $type, $stype]);

		$id = lastId($db);

		$num = 0;
		foreach ($data['lines'] as $line) {

			$num++;
			$tvat = $total = 0;
			$bi = round(($line['units'] ?: 1) * $line['price'], 2);
			if (array_key_exists('vat', $line) && $line['vat']) {

				$tvat = round($bi * ($line['vat'] / 100), 2);
				$total = round($bi + $tvat, 2);
			}

			$db->query('INSERT INTO invoice_lines SET invoice_id = ?, num = ?, descr = ?, units = ?, price = ?, vat = ?, tvat = ?, bi = ?, total = ?',
			[$id, $num, $line['descr'], $line['units'], $line['price'], $line['vat'], $tvat, $bi, $total]);
		}

		if ($refs) {

			foreach($refs as $ref)
				$db->query('UPDATE invoices SET invoice_ref_id = ? WHERE id = ?', [$id, $ref['id']]);
		}

		okResponse(array('id' => $id), 201);

	} catch(mysqli_sql_exception $e) {

		errResponse(400, $e->getMessage());
	}
}

if (apiPath('POST', 'invoices')) {

	$data = getData();
	insertInvoice($db, $verifactu, $company, $data, isset($data['vat_id']) ? 'F1' : 'F2');
}

if (($vars = apiPath('POST', 'invoices/:id/rect'))) {

	if (!($id = getKey($vars, 'id')) || !preg_match('/^\d+(,\d+)*$/', $id))
		errResponse(404, 'Not found id(s)');

	$invoices = $db->query('SELECT * FROM invoices WHERE id IN(?) AND company_id = ?', [$id, $company_id]);
	foreach ($invoices as $invoice) {

		if (!($invoice['verifactu_type'] == 'F1' || $invoice['verifactu_type'] == 'F2') || $invoice['invoice_ref_id'] || $invoice['voided'])
			errResponse(401, 'Not type F1/F2, already referenced or voided: ' . numFmt($company, $invoice));
	}

	if ($invoices) {

		$data = getData();
		insertInvoice($db, $verifactu, $company, $data, isset($data['vat_id']) ? 'R1' : 'R5', $invoices, 'I');

	} else
		errResponse(404, 'Not found');
}

if (($vars = apiPath('POST', 'invoices/:id/rect2'))) {

	if (!($id = getKey($vars, 'id')) || !preg_match('/^\d+(,\d+)*$/', $id))
		errResponse(404, 'Not found id(s)');

	$invoices = $db->query('SELECT * FROM invoices WHERE id IN(?) AND company_id = ?', [$id, $company_id]);
	foreach ($invoices as $invoice) {

		if ($invoice['verifactu_type'] != 'F1' || $invoice['invoice_ref_id'] || $invoice['voided'])
			errResponse(401, 'Not type F1, already referenced or voided: ' . numFmt($company, $invoice));
	}

	if ($invoices) {

		$data = getData();
		insertInvoice($db, $verifactu, $company, $data, 'R2', $invoices, 'I');

	} else
		errResponse(404, 'Not found');
}

if (($vars = apiPath('POST', 'invoices/:id/rectsust'))) {

	if (!($id = getKey($vars, 'id')) || !preg_match('/^\d+(,\d+)*$/', $id))
		errResponse(404, 'Not found id(s)');

	$invoices = $db->query('SELECT * FROM invoices WHERE id IN(?) AND company_id = ?', [$id, $company_id]);
	foreach ($invoices as $invoice) {

		if (!($invoice['verifactu_type'] == 'F1' || $invoice['verifactu_type'] == 'F2') || $invoice['invoice_ref_id'] || $invoice['voided'])
			errResponse(401, 'Not type F1/F2, already referenced or voided: ' . numFmt($company, $invoice));
	}

	if ($invoices) {

		$data = getData();
		insertInvoice($db, $verifactu, $company, $data, isset($data['vat_id']) ? 'R1' : 'R5', $invoices, 'S');

	} else
		errResponse(404, 'Not found');
}

if (($vars = apiPath('POST', 'invoices/:id/sust'))) {

	if (!($id = getKey($vars, 'id')) || !preg_match('/^\d+(,\d+)*$/', $id))
		errResponse(404, 'Not found id(s)');

	$invoices = $db->query('SELECT * FROM invoices WHERE id IN(?) AND company_id = ?', [$id, $company_id]);
	foreach ($invoices as $invoice) {

		if ($invoice['verifactu_type'] != 'F2' || $invoice['invoice_ref_id'] || $invoice['voided'])
			errResponse(401, 'Not type F2, already referenced or voided: ' . numFmt($company, $invoice));
	}

	if ($invoices) {

		$data = getData();
		insertInvoice($db, $verifactu, $company, $data, 'F3', $invoices);

	} else
		errResponse(404, 'Not found');
}

if (($vars = apiPath('PUT', 'invoices/:id/voided'))) {

	if (!($id = getKey($vars, 'id')) || !preg_match('/^\d+(,\d+)*$/', $id))
		errResponse(404, 'Not found id(s)');

	$invoices = $db->query('SELECT * FROM invoices WHERE id = ? AND company_id = ?', [$id, $company_id]);
	foreach ($invoices as $invoice) {

		if ($invoice['voided'] || !$invoice['verifactu_dt'] || $invoice['invoice_ref_id'])
			errResponse(401, 'Already voided, not sent or referenced: ' . numFmt($company, $invoice));
	}

	if ($invoices)
		okResponse($verifactu->Voided($company, $invoices));
	else
		errResponse(404, 'Not found');
}

errResponse(405, 'Method Not Allowed');
