<?php
//initialize civicrm
$rootPath = '/var/www/drupal7/sites/all/modules/civicrm/';
require_once $rootPath . 'civicrm.config.php';
require_once $rootPath . 'CRM/Core/Config.php';
$config = CRM_Core_Config::singleton();
CRM_Core_DAO::$_dbColumnValueCache = NULL;

//readfile
$fileName = 'MyImport2.csv';
CRM_Core_Error::debug( '$fileName', $fileName );
exit;
//$fileName = 'test1.csv';
$read  = fopen($fileName, 'r');
$rows = fgetcsv($read);
$contactSubType = array(
  'Associate Supplier' => 'Associate',
  'Foodservice Operator' => 'Food_Service_Operator',
);  
$stateProvince = CRM_Core_PseudoConstant::stateProvinceForCountry(1039, 'abbreviation');
$errors['org'] = $errors['ind'] = $errors['mem'] = array();
$totalImported = 0;
while ($rows = fgetcsv($read)) {;
  CRM_Core_Error::debug( '$rows', $rows[0] . '/n \n' );
  $contactResult = civicrm_api3('Contact', 'get', array(
    'return' => 'id',
    'external_identifier' => $rows[0],
  ));
  $membershipResult = array();
  if (empty($contactResult['id'])) {
    $contactParams = array(
      'external_identifier' => $rows[0],  
      'contact_type' => 'Organization',
      'sort_name' => $rows[3],
      'contact_sub_type' => $contactSubType[$rows[2]],
      'display_name' => $rows[3],
      'source' => 'Import via script',
      'organization_name' => $rows[5],
      'email' => $rows[19],
      'api.Address.create' => array(
        'location_type_id' => 3,
        'is_primary' => 1,
        'street_address' => $rows[7],
        'city' => $rows[13],
        'state_province_id' => array_search($rows[14], $stateProvince),
        'postal_code' => $rows[15],
        'country_id' => 1039,
      ),
      'api.Phone.create' => array(
        array(
          'location_type_id' => 3,
          'is_primary' => 1,
          'phone' => $rows[11],
          'phone_type_id' => 1,
        ),
        array(
          'location_type_id' => 3,
          'is_primary' => 1,
          'phone' => $rows[12],
          'phone_type_id' => 3,
        ),
      ),
    );
    $contactResult = civicrm_api3('Contact', 'create', $contactParams);
  }
  else {
    $errors['org'][] = $rows[0];
    // check for membership for CFRA ID     
    $membershipResult = civicrm_api3('Membership', 'get', array(
      'contact_id' => $contactResult['id'],
      'return' => 'id',
    ));
  }
  if (!empty($rows[9]) && !empty($rows[10])) {
    $contacts = array(
      'first_name' => $rows[9],
      'last_name' => $rows[10],
      'contact_type' => 'Individual',
      'source' => 'Import via script',
      'job_title' => $rows[4],
      'employer_id' => $contactResult['id'],
    );
    $dedupeParams = CRM_Dedupe_Finder::formatParams($contacts, 'Individual');
    $dedupeParams['check_permission'] = FALSE;
    $dupes = CRM_Dedupe_Finder::dupesByParams($dedupeParams, 'Individual', NULL, array(), 11);
    if (!CRM_Utils_Array::value('0', $dupes, NULL)) {
      $contacts['api.Address.create'] = array(
        'location_type_id' => 3,
        'is_primary' => 1,
        'street_address' => $rows[7],
        'city' => $rows[13],
        'state_province_id' => array_search($rows[14], $stateProvince),
        'postal_code' => $rows[15],
        'country_id' => 1039,
      );
      civicrm_api3('Contact', 'create', $contacts);
    }
    else {
      $errors['ind'][] = $rows[0];
    }
  }
  $membershipParams = array(
    'contact_id' => $contactResult['id'],
    'membership_type_id' => 7,
    'source' => 'Import From script',                        
  );
  if (!empty($rows[16])) {
    $membershipParams['join_date'] = date('Y-m-d', strtotime($rows[16]));
  }
  if (!empty($rows[17])) {
    $membershipParams['start_date'] = date('Y-m-d', strtotime($rows[17]));
  }
  else {
    $errors['mem'][] = $rows[0]; 
    continue;
  }
  if (!empty($rows[18])) {
    $membershipParams['end_date'] = date('Y-m-d', strtotime($rows[18]));
  }
  
  
  if (!empty($membershipResult['id'])) {
    $membershipParams['id'] = $membershipResult['id'];
  }
  $membership = civicrm_api3('Membership', 'create', $membershipParams);
  if (empty($membership['id'])) {;
    CRM_Core_Error::debug_var( '$membershipFailed', $membership );
  }
  else {
    $totalImported++;
  }
    CRM_Core_Error::debug( '$totalImported', $totalImported );
}

// print details in log
$logFile = 'Sync-' . date('Y-m-d-h-i-s');
if (!empty($errors['org'])) {
  CRM_Core_Error::debug_log_message('Organization already found in db for CFRA ID:', FALSE, $logFile);
  CRM_Core_Error::debug_var('', $errors['org'], TRUE, TRUE, $logFile);
}
if (!empty($errors['ind'])) {
  CRM_Core_Error::debug_log_message('Individual Contacts already found in db for CFRA ID:', FALSE, $logFile);
  CRM_Core_Error::debug_var('', $errors['ind'], TRUE, TRUE, $logFile);
}
if (!empty($errors['mem'])) {
  CRM_Core_Error::debug_log_message('Ignored membership for CFRA ID:', FALSE, $logFile);
  CRM_Core_Error::debug_var('', $errors['mem'], TRUE, TRUE, $logFile);
}
CRM_Core_Error::debug_log_message("Total imported: {$totalImported}", FALSE, $logFile);


?>