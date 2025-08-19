<?php
//
// =============== Veri*Factu API 1.0.5 ===============
//
// Copyright (c) 2025 Eduardo Ruiz <eruiz@dataclick.es>
// https://github.com/EduardoRuizM/verifactu-api-php
//

class verifactuXML {

	private $db, $cert_file, $cert_passwd, $log_file, $save_responses;
	private $url_prod = 'https://www1.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';
	private $url_test = 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';
	private $software_company_name, $software_company_nif, $software_name, $software_id, $software_version, $software_install_number;

	function __construct(&$db, $cert_file, $cert_passwd, $log_file, $save_responses,
				$software_company_name, $software_company_nif, $software_name, $software_id, $software_version, $software_install_number) {

		$this->db = $db;
		$this->cert_file = $cert_file;
		$this->cert_passwd = $cert_passwd;
		$this->log_file = $log_file;
		$this->save_responses = $save_responses;
		$this->software_company_name = $software_company_name;
		$this->software_company_nif = $this->cod($software_company_nif);
		$this->software_name = $software_name;
		$this->software_id = substr($software_id, 0, 2);
		$this->software_version = $software_version;
		$this->software_install_number = $software_install_number;
	}

	// Formato moneda
	function cur($num) {

		return number_format($num, 2, '.', '');
	}

	// Formato fecha
	function dt(&$invoice) {

		return date('d-m-Y', strtotime($invoice['dt']));
	}

	// Formato letras y números
	function cod($str) {

		return trim(strtoupper(preg_replace('/\W/u', '', $str)));
	}

	// Log
	function Log($str) {

		if ($this->log_file)
			file_put_contents($this->log_file, date('Y-m-d H:i:s') . ' ' . $str . "\n", FILE_APPEND | LOCK_EX);
	}

	// Última factura enviada a la AEAT
	function LastInvoice(&$company) {

		$invoice = $this->db->query('SELECT * FROM invoices WHERE company_id = ? AND fingerprint IS NOT NULL ORDER BY verifactu_dt DESC, id DESC', [$company['id']]);
		return ($invoice && count($invoice)) ? $invoice[0] : null;
	}

	// Obtener huella de la factura
	function Fingerprint(&$company, &$invoice, &$last, $dt, $voided = false) {

		$last_fp = ($last) ? $last['fingerprint'] : '';

		if ($voided) {

			return strtoupper(hash('sha256', 'IDEmisorFacturaAnulada=' . $this->cod($company['vat_id']) . '&NumSerieFacturaAnulada=' . numFmt($company, $invoice) .
						'&FechaExpedicionFacturaAnulada=' . $this->dt($invoice) . '&Huella=' . $last_fp . '&FechaHoraHusoGenRegistro=' . $dt));

		} else {

			return strtoupper(hash('sha256', 'IDEmisorFactura=' . $this->cod($company['vat_id']) . '&NumSerieFactura=' . numFmt($company, $invoice) .
						'&FechaExpedicionFactura=' . $this->dt($invoice) . '&TipoFactura=' . $invoice['verifactu_type'] .
						'&CuotaTotal=' . $this->cur($invoice['tvat']) . '&ImporteTotal=' . $this->cur($invoice['total']) .
						'&Huella=' . $last_fp . '&FechaHoraHusoGenRegistro=' . $dt));
		}
	}

	function RegistroAlta(&$company, &$invoice, $last, $dt) {

		if (!($descr = $invoice['comments'])) {

			$lines = $this->db->query('SELECT * FROM invoice_lines WHERE invoice_id = ?', [$invoice['id']]);
			$descr = $lines[0]['descr'];
		}

		$xml = '<sum:RegistroFactura>
                          <RegistroAlta>
                            <IDVersion>1.0</IDVersion>
                            <IDFactura>
                              <IDEmisorFactura>' . $this->cod($company['vat_id']) . '</IDEmisorFactura>
                              <NumSerieFactura>' . numFmt($company, $invoice) . '</NumSerieFactura>
                              <FechaExpedicionFactura>' . $this->dt($invoice) . '</FechaExpedicionFactura>
                            </IDFactura>
                            <NombreRazonEmisor>' . $company['name'] . '</NombreRazonEmisor>
                          ' . (($invoice['verifactu_err'] !== null) ? '<Subsanacion>S</Subsanacion>' : '') . '
                          ' . (($invoice['verifactu_err'] !== null) ? '<RechazoPrevio>X</RechazoPrevio>' : '') . '
                            <TipoFactura>' . $invoice['verifactu_type'] . '</TipoFactura>';

		if (substr($invoice['verifactu_type'], 0, 1) == 'R' || $invoice['verifactu_type'] == 'F3') {

			if ($invoice['verifactu_stype'])
				$xml.= '<TipoRectificativa>' . (($invoice['verifactu_stype'] == 'S') ? 'S' : 'I') . '</TipoRectificativa>';

			$tag1 = ($invoice['verifactu_type'] == 'F3') ? '<FacturasSustituidas><IDFacturaSustituida>' : '<FacturasRectificadas><IDFacturaRectificada>';
			$tag2 = ($invoice['verifactu_type'] == 'F3') ? '</IDFacturaSustituida></FacturasSustituidas>' : '</IDFacturaRectificada></FacturasRectificadas>';

			$rinvoices = $this->db->query('SELECT * FROM invoices WHERE invoice_ref_id = ? ORDER BY dt', [$invoice['id']]);
			foreach ($rinvoices as $rinvoice) {

				$xml.=	$tag1 . '<IDEmisorFactura>' . $this->cod($company['vat_id']) . '</IDEmisorFactura>' .
					'<NumSerieFactura>' . numFmt($company, $rinvoice) . '</NumSerieFactura>' .
					'<FechaExpedicionFactura>' . $this->dt($rinvoice) . '</FechaExpedicionFactura>' . $tag2;
			}

			if ($invoice['verifactu_stype'] == 'S') {

				$bi_total = 0.0;
				$tvat_total = 0.0;
				foreach ($rinvoices as $rinvoice) {

					$lines = $this->db->query('SELECT vat, SUM(bi) AS bi, SUM(tvat) AS tvat FROM invoice_lines WHERE invoice_id = ? GROUP BY vat', [$rinvoice['id']]);
					foreach ($lines as $line) {

						$bi_total+= floatval($line['bi']);
						$tvat_total+= floatval($line['tvat']);
					}
				}

				$xml.=	'<ImporteRectificacion><BaseRectificada>' . $this->cur($bi_total) . '</BaseRectificada>' .
					'<CuotaRectificada>' . $this->cur($tvat_total) . '</CuotaRectificada></ImporteRectificacion>';
			}
		}

		$xml.= '    <DescripcionOperacion>' . $descr . '</DescripcionOperacion>';
		if ($invoice['verifactu_type'] == 'F2')
			$xml.= '    <FacturaSimplificadaArt7273>S</FacturaSimplificadaArt7273>';

		if (!$invoice['vat_id'])
			$xml.= '    <FacturaSinIdentifDestinatarioArt61d>S</FacturaSinIdentifDestinatarioArt61d>';
		else {

			$xml.= '<Destinatarios>
                                  <IDDestinatario>
                                    <NombreRazon>' . $invoice['name'] . '</NombreRazon>
                                    <NIF>' . $invoice['vat_id'] . '</NIF>
                                  </IDDestinatario>
                               </Destinatarios>';
		}

		$xml.= '<Desglose>';

		$lines = $this->db->query('SELECT vat, SUM(bi) AS bi, SUM(tvat) AS tvat FROM invoice_lines WHERE invoice_id = ? GROUP BY vat', [$invoice['id']]);
		foreach ($lines as $line) {

			$xml.= '<DetalleDesglose><Impuesto>01</Impuesto>' .
				(($line['vat']) ?
				  '<ClaveRegimen>01</ClaveRegimen><CalificacionOperacion>S1</CalificacionOperacion><TipoImpositivo>' .
					$line['vat'] . '</TipoImpositivo><BaseImponibleOimporteNoSujeto>' . $this->cur($line['bi']) .
					'</BaseImponibleOimporteNoSujeto><CuotaRepercutida>' . $this->cur($line['tvat']) . '</CuotaRepercutida>'
				:
				  '<CalificacionOperacion>N1</CalificacionOperacion><BaseImponibleOimporteNoSujeto>' . $this->cur($line['bi']) . '</BaseImponibleOimporteNoSujeto>') .
				'</DetalleDesglose>';
		}

		$xml.= '</Desglose><CuotaTotal>' . $this->cur($invoice['tvat']) . '</CuotaTotal><ImporteTotal>' . $this->cur($invoice['total']) . '</ImporteTotal>';

		$xml.=	'<Encadenamiento>' .
			(($last) ?
			  '<RegistroAnterior><IDEmisorFactura>' . $this->cod($company['vat_id']) . '</IDEmisorFactura><NumSerieFactura>' . $last['numFmt'] .
				'</NumSerieFactura><FechaExpedicionFactura>' . $this->dt($last) . '</FechaExpedicionFactura><Huella>' . $last['fingerprint'] .
				'</Huella></RegistroAnterior>'
			:
			  '<PrimerRegistro>S</PrimerRegistro>') .
			'</Encadenamiento>' . $this->SistemaInformatico() . '<FechaHoraHusoGenRegistro>' . $dt . '</FechaHoraHusoGenRegistro><TipoHuella>01</TipoHuella>' .
			'<Huella>' . $this->Fingerprint($company, $invoice, $last, $dt, false) . '</Huella></RegistroAlta></sum:RegistroFactura>';

		return $xml;
	}

	function RegistroAnulacion(&$company, &$invoice, $last, $dt) {

		$xml = '<sum:RegistroFactura>
                          <RegistroAnulacion>
                            <IDVersion>1.0</IDVersion>
                            <IDFactura>
                              <IDEmisorFacturaAnulada>' . $this->cod($company['vat_id']) . '</IDEmisorFacturaAnulada>
                              <NumSerieFacturaAnulada>' . numFmt($company, $invoice) . '</NumSerieFacturaAnulada>
                              <FechaExpedicionFacturaAnulada>' . $this->dt($invoice) . '</FechaExpedicionFacturaAnulada>
                            </IDFactura>' .
                       (($invoice['verifactu_err'] !== null && $invoice['verifactu_err'] > 0) ? '<RechazoPrevio>S</RechazoPrevio>' : '');

		$xml.=	'<Encadenamiento>' .
			(($last) ?
			  '<RegistroAnterior><IDEmisorFactura>' . $this->cod($company['vat_id']) . '</IDEmisorFactura><NumSerieFactura>' . $last['numFmt'] .
				'</NumSerieFactura><FechaExpedicionFactura>' . $this->dt($last) . '</FechaExpedicionFactura><Huella>' . $last['fingerprint'] .
				'</Huella></RegistroAnterior>'
			:
			  '<PrimerRegistro>S</PrimerRegistro>') .
			'</Encadenamiento>' . $this->SistemaInformatico() . '<FechaHoraHusoGenRegistro>' . $dt . '</FechaHoraHusoGenRegistro><TipoHuella>01</TipoHuella>' .
			'<Huella>' . $this->Fingerprint($company, $invoice, $last, $dt, true) . '</Huella></RegistroAnulacion></sum:RegistroFactura>';

		return $xml;
	}

	function SistemaInformatico() {

		return	'<SistemaInformatico>
                           <NombreRazon>' . $this->software_company_name . '</NombreRazon>
                           <NIF>' . $this->software_company_nif . '</NIF>
                           <NombreSistemaInformatico>' . $this->software_name . '</NombreSistemaInformatico>
                           <IdSistemaInformatico>' . $this->software_id . '</IdSistemaInformatico>
                           <Version>' . $this->software_version . '</Version>
                           <NumeroInstalacion>' . $this->software_install_number . '</NumeroInstalacion>
                           <TipoUsoPosibleSoloVerifactu>N</TipoUsoPosibleSoloVerifactu>
                           <TipoUsoPosibleMultiOT>S</TipoUsoPosibleMultiOT>
                           <IndicadorMultiplesOT>S</IndicadorMultiplesOT>
                         </SistemaInformatico>';
	}

	function Pending() {

		$resp = array('companies' => array());
		$companies = $this->db->query('SELECT *, UNIX_TIMESTAMP(next_send)-UNIX_TIMESTAMP(NOW()) AS nxSend FROM companies');
		foreach ($companies as $company) {

			$resp['companies'][$company['id']] = array();
			if ($company['nxSend'] && $company['nxSend'] > 0)
				$resp['companies'][$company['id']]['message'] = "Next send in {$company['nxSend']} seconds";
			else {

				$invoices = $this->db->query('SELECT * FROM invoices WHERE company_id = ? AND verifactu_dt IS NULL ORDER BY dt LIMIT 1000', [$company['id']]);
				$resp['companies'][$company['id']] = $this->Send($company, $invoices);
			}
		}

		return $resp;
	}

	function Voided(&$company, &$invoices) {

		return $this->Send($company, $invoices, true);
	}

	function Send(&$company, &$invoices, $voided = false) {

		if (!$invoices)
			return array('message' => 'No invoices to send');

		$ikeys = array();
		foreach ($invoices as $key => $invoice)
			$ikeys[numFmt($company, $invoice)] = $key;

		$dt = (new DateTime())->format('c');

		$xml = '<?xml version="1.0" encoding="UTF-8"?>
                        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                          xmlns:sum="https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroLR.xsd"
                          xmlns="https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd">
                          <soapenv:Header/>
                          <soapenv:Body>
                            <sum:RegFactuSistemaFacturacion>
                              <sum:Cabecera>
                                <ObligadoEmision>
                                  <NombreRazon>' . $company['name'] . '</NombreRazon>
                                  <NIF>' . $this->cod($company['vat_id']) . '</NIF>
                                </ObligadoEmision>
                              </sum:Cabecera>';

		$chain = $this->LastInvoice($company);
		if($chain)
			$chain = ['numFmt' => numFmt($company, $chain), 'dt' => $chain['dt'], 'fingerprint' => $chain['fingerprint']];
		else
			$chain = null;

		foreach ($invoices as $invoice) {

			$fp = $this->Fingerprint($company, $invoice, $chain, $dt, $voided);
			$invoice['_prev'] = $chain;
			$chain = ['numFmt' => numFmt($company, $invoice), 'dt' => $invoice['dt'], 'fingerprint' => $fp];

			if ($voided)
				$xml.= $this->RegistroAnulacion($company, $invoice, $invoice['_prev'], $dt);
			else
				$xml.= $this->RegistroAlta($company, $invoice, $invoice['_prev'], $dt);
		}

		$xml.= '    </sum:RegFactuSistemaFacturacion>
                          </soapenv:Body>
                        </soapenv:Envelope>';

		if ($this->save_responses && is_dir($this->save_responses))
			file_put_contents(rtrim($this->save_responses, '/') . '/send_' . date('YmdHis') . '.xml', $xml);

		$ret = $this->SendXML($company, $xml);

		if ($ret['status'] == 200 && !getKey($ret, 'error')) {

			$xml = simplexml_load_string($ret['response']);
			$namespaces = $xml->getNamespaces(true);
			if (isset($namespaces['tik']) && isset($namespaces['tikR'])) {

				$ret = array('ok' => array(), 'ko' => array());
				$xml->registerXPathNamespace('tik', $namespaces['tik']);
				$xml->registerXPathNamespace('tikR', $namespaces['tikR']);
				$csv = ($v = $xml->xpath('//tikR:CSV')) ? (string) $v[0] : null;
				$tiempoEsperaEnvio = ($v = $xml->xpath('//tikR:TiempoEsperaEnvio')) ? (string) $v[0] : 0;
				$timestampPresentacion = ($v = $xml->xpath('//tikR:DatosPresentacion/tik:TimestampPresentacion')) ? (string) $v[0] : 0;

				$dtutc = (new DateTime(($timestampPresentacion) ? $timestampPresentacion : $dt))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

				$this->db->query('UPDATE companies SET next_send = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?', [$tiempoEsperaEnvio, $company['id']]);

				$lines = $xml->xpath('//tikR:RespuestaLinea');
				foreach ($lines as $line) {

					$numSerieFactura = ($v = $line->xpath('tikR:IDFactura/tik:NumSerieFactura')) ? (string) $v[0] : '';
					$tipoOperacion = ($v = $line->xpath('tikR:Operacion/tik:TipoOperacion')) ? (string) $v[0] : '';
					$estadoRegistro = ($v = $line->xpath('tikR:EstadoRegistro')) ? (string) $v[0] : '';
					$codError = ($v = $line->xpath('tikR:CodigoErrorRegistro')) ? (string) $v[0] : 0;
					$descrError = ($v = $line->xpath('tikR:DescripcionErrorRegistro')) ? (string) $v[0] : '';

					$invoice = $invoices[$ikeys[$numSerieFactura]];

					$sql = 'UPDATE invoices SET verifactu_dt = "' . $dtutc . '", verifactu_err = ' . intval($codError);
					if ($csv)
						$sql.= ', verifactu_csv = "' . addslashes(trim($invoice['verifactu_csv'] . "\n" . $csv)) . '"';

					if ($timestampPresentacion)
						$sql.= ', fingerprint = "' . $this->Fingerprint($company, $invoice, $last, $timestampPresentacion, $voided) . '"';

					if (!$codError && $voided)
						$sql.= ', voided = 1';

					$this->db->query($sql . ' WHERE id = ' . $invoice['id']);

					if($codError)
						array_push($ret['ko'], array('id' => $invoice['id'], 'num' => $numSerieFactura, 'codError' => $codError, 'descrError' => $descrError));
					else
						array_push($ret['ok'], array('id' => $invoice['id'], 'num' => $numSerieFactura));

					$log = '';
					$items = explode(' ', 'tikR:Operacion/tik:TipoOperacion tikR:EstadoRegistro tikR:CodigoErrorRegistro tikR:DescripcionErrorRegistro tikR:IDFactura/tik:NumSerieFactura tikR:IDFactura/tik:IDEmisorFactura');
					foreach ($items as $item) {

						if ($v = $line->xpath($item))
							$log.= (($log) ? ' ' : '') . ((preg_match('/:([^:]+)$/', $item, $itm)) ? $itm[1] : $item) . '=' . (string) $v[0];
					}

					$this->Log($log);
				}

				return $ret;

			} else {

				$fault = $xml->xpath('//env:Fault/faultstring')[0] ?: '';
				preg_match('/Codigo\[(\d+)\]/', $fault, $matches);
				$callstack = explode("\n", $xml->xpath('//env:Fault/detail/callstack')[0] ?: '')[0] ?: '';
				$err = 'XML error=' . ($matches[1] ?: 0) . ' ' . $callstack;
				$this->Log($err);

				return array('error' => $err);
			}

		} else {

			$this->Log('Unable to connect, status=' . $ret['status'] . ', error=' . $ret['error']);

			return $ret;
		}
	}

	function domToArray($domNode) {

		$output = [];

		if ($domNode->hasChildNodes()) {

			foreach ($domNode->childNodes as $childNode) {

				if ($childNode->nodeType === XML_TEXT_NODE) {

					$text = trim($childNode->nodeValue);
					if ($text !== '')
						return $text;

					continue;
				}

				$childName = strpos($childNode->nodeName, ':') !== false ? explode(':', $childNode->nodeName)[1] : $childNode->nodeName;
				$childValue = $this->domToArray($childNode);
				if (isset($output[$childName])) {

					if (!is_array($output[$childName]) || !isset($output[$childName][0]))
						$output[$childName] = [$output[$childName]];

					$output[$childName][] = $childValue;

				} else
					$output[$childName] = $childValue;
			}
		}

		return $output ?: trim($domNode->nodeValue);
	}

	function Consulta(&$company, $year = 0, $month = 0) {

		$year = max(2025, min(2200, $year)) ?: date('Y');
		$month = sprintf('%02d', max(1, min(12, $month ?: date('m'))));

		$xml = '<?xml version="1.0" encoding="UTF-8"?>
                        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                          xmlns:con="https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/ConsultaLR.xsd"
                          xmlns:sum="https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd">
                          <soapenv:Header/>
                          <soapenv:Body>
                            <con:ConsultaFactuSistemaFacturacion>
                              <con:Cabecera>
                                <sum:IDVersion>1.0</sum:IDVersion>
                                <sum:ObligadoEmision>
                                  <sum:NombreRazon>' . $company['name'] . '</sum:NombreRazon>
                                  <sum:NIF>' . $this->cod($company['vat_id']) . '</sum:NIF>
                                </sum:ObligadoEmision>
                              </con:Cabecera>
                              <con:FiltroConsulta>
                                <con:PeriodoImputacion>
                                  <sum:Ejercicio>' . $year . '</sum:Ejercicio>
                                  <sum:Periodo>' . $month . '</sum:Periodo>
                                </con:PeriodoImputacion>
                              </con:FiltroConsulta>
                            </con:ConsultaFactuSistemaFacturacion>
                          </soapenv:Body>
                        </soapenv:Envelope>';

		$ret = $this->SendXML($company, $xml, false);
		if ($ret['status'] != 200 || $ret['error'])
			return $ret;

		$dom = new DOMDocument();
		$dom->loadXML($ret['response']);
		$nodes = $dom->getElementsByTagName('RegistroRespuestaConsultaFactuSistemaFacturacion');

		$regs = [];
		foreach ($nodes as $node)
			$regs[] = $this->domToArray($node);

		return array('data' => $regs);
	}

	// Enviar a la AEAT (pruebas o producción)
	function SendXML(&$company, &$xml, $log = true) {

		$error = '';
		$xml = preg_replace('/>\s+</', '><', preg_replace('/\s*xmlns/', ' xmlns', $xml));

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, ($company['test'] == 1) ? $this->url_test : $this->url_prod);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'P12');
		curl_setopt($ch, CURLOPT_SSLCERT, $this->cert_file);
		curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $this->cert_passwd);
		$response = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if ($log && $this->save_responses && is_dir($this->save_responses))
			file_put_contents(rtrim($this->save_responses, '/') . '/resp_' . date('YmdHis') . '.xml', $response);

		if (curl_errno($ch))
			$error = curl_error($ch);

		curl_close($ch);

		return ($error === '') ? array('status' => $status, 'response' => $response, 'error' => '') : array('status' => $status, 'error' => $error);
	}
}
