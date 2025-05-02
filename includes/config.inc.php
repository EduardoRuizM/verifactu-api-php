<?php
//
// =============== Veri*Factu API 1.0.0 ===============
//
// Copyright (c) 2025 Eduardo Ruiz <eruiz@dataclick.es>
// https://github.com/EduardoRuizM/verifactu-api-php
//

$mysql_host = 'localhost';
$mysql_port = 3306;
$mysql_user = '';
$mysql_password = '';
$mysql_database = '';

// Certificado PKCS#12 (FNMT)
$cert_file = '.p12';
$cert_passwd = '';

// SistemaInformatico
$software_company_name = '';
$software_company_nif = '';
$software_name = 'verifactu';
$software_id = 'vf';					# Solo 2 caracteres, no se podrá cambiar
$software_version = '1.0';
$software_install_number = '00001';

// Permitir IPs o sin restricción
$allow_ip = '';

// Utilizar rutas sin acceso público por motivos de seguridad
$verifactu_token_file = 'includes/verifactu.token.php';
$verifactu_log_file = 'includes/verifactu.log';
$verifactu_save_responses = 'includes/responses';
