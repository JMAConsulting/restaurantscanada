<?php
//initialize civicrm
$rootPath = '/home/24074-14898.cloudwaysapps.com/qnwmfhbcjk/public_html/wp-content/plugins/civicrm/civicrm/';
$rootPath = '/var/www/drupal7/sites/all/modules/civicrm/';
require_once $rootPath . 'civicrm.config.php';
require_once $rootPath . 'CRM/Core/Config.php';
$config = CRM_Core_Config::singleton();

//readfile
$fileName = 'RC.csv';
$read  = fopen($fileName, 'r');
$rows = fgetcsv($read);

//read rows
$errors['contact'] = $errors['membership'] = array();
$totalPayments = 0;
$totalMemberships = array();
$data = array();
while ($rows = fgetcsv($read)) {
  // donot import payments of product type GROUPEX
  if (strtolower($rows[5]) == 'groupex') {
    continue;
  }

  if ($rows[12] =='FALSE') {
    $rows[12] = $rows[11];
  }
  $rows[11] = str_replace(array('(', '$', ')'), '', $rows[11]);
  $rows[12] = str_replace(array('(', '$', ')'), '', $rows[12]);

  $key = $rows[1] . '_' . date('Y-m-d', strtotime($rows[3])) . '_' . $rows[11];

  if (array_key_exists($key, $data) && strtolower($rows[5]) == 'as') {
    continue;
  }
  $data[$key] = array(
    1 => $rows[1],
    3 => $rows[3],
    11 => $rows[11],
    12 => $rows[12],
    14 => $rows[14],
  );
}
foreach ($data as $key => $values) {
  //get contact id using CFRA ID 
  if (CRM_Core_DAO::singleValueQuery('SELECT keyid FROM pay_import WHERE keyid = "' . $key . '"')) {
    continue;
  }
  $contactResult = civicrm_api3('Contact', 'get', array(
    'return' => 'id',
    'external_identifier' => $values[1],
  ));
  // record error if CFRA ID not present in db
  if (empty($contactResult['id'])) {
    $errors['contact'][] = $values[1];
    continue;
  }
  // check for membership for CFRA ID     
  $membershipResult = civicrm_api3('Membership', 'get', array(
    'contact_id' => $contactResult['id'],
    'return' => 'id',
  ));
  // record error if membership not present
  if (empty($membershipResult['id'])) {
    $errors['membership'][] = $values[1];
    continue;
  }
  $taxAmount = number_format(($values[11] - $values[12]), 2, '.', '');
  $totalMemberships[$membershipResult['id']][] =$values[1]; 
  // create contribution with tax
  $contributionParams = array(
    'financial_type_id' => 5,
    'contact_id' => $contactResult['id'],
    'total_amount' => $values[11],              
    'receive_date' => date('Y-m-d H:i:s', strtotime($values[3])),           
    'net_amount' => $values[11],
    'fee_amount' => '0.00',
    'currency' => 'CAD',
    'source' => 'Syncd payment imported membership through script',
    'contribution_status_id' => 1,
    'tax_amount' => $taxAmount,
    'receipt_date' => date('Y-m-d H:i:s', strtotime($values[14])),
    'line_item' => array(
      2 => array(
        8 => array(
          'entity_id' => $membershipResult['id'],
          'entity_table' => 'civicrm_membership',
          'price_field_id' => 2,
          'label' => 'RC Member',
          'qty' => 1,
          'unit_price' => $values[12],
          'line_total' => $values[12],
          'membership_type_id' => 7,
          'financial_type_id' => 5,
          'tax_amount' => $taxAmount,
          'price_field_value_id' => 8,
          //'tax_rate' => 0.00000000,
        )   
      ),                  
    ), 
  );
  CRM_Core_DAO::executeQuery("DELETE FROM civicrm_line_item WHERE entity_table = 'civicrm_membership' AND entity_id = {$membershipResult['id']} AND contribution_id IS NULL");
  $contribution = CRM_Contribute_BAO_Contribution::create($contributionParams);
  
  //link membership and membership payment
  civicrm_api3('MembershipPayment', 'create', array(
    'sequential' => 1,
    'membership_id' => $membershipResult['id'],
    'contribution_id' => $contribution->id,
  ));
  $totalPayments++;
  CRM_Core_DAO::executeQuery("INSERT INTO pay_import VALUES ('{$key}')");
}
// print details in log
$logFile = 'Sync-' . date('Y-m-d-h-i-s');
if (!empty($errors['contact'])) {
  CRM_Core_Error::debug_log_message('Contacts not found in db for CFRA ID:', FALSE, $logFile);
  CRM_Core_Error::debug_var('', array_unique($errors['contact']), TRUE, TRUE, $logFile);
  
}
if (!empty($errors['membership'])) {
  CRM_Core_Error::debug_log_message('Membership not found for CFRA ID:', FALSE, $logFile);
  CRM_Core_Error::debug_var('', array_unique($errors['membership']), TRUE, TRUE, $logFile);
}
CRM_Core_Error::debug_log_message("Total Payments sync'd : {$totalPayments}", FALSE, $logFile);
CRM_Core_Error::debug_var('', $totalMemberships, TRUE, TRUE, $logFile);
?>