<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */

/**
 * This class provides the functionality for batch entry for contributions/memberships.
 */
class CRM_Batch_Form_Entry extends CRM_Core_Form {

  /**
   * Maximum profile fields that will be displayed.
   *
   * @var int
   */
  protected $_rowCount = 1;

  /**
   * Batch id.
   *
   * @var int
   */
  protected $_batchId;

  /**
   * Batch information.
   *
   * @var array
   */
  protected $_batchInfo = [];

  /**
   * Store the profile id associated with the batch type.
   * @var int
   */
  protected $_profileId;

  public $_action;

  public $_mode;

  public $_params;

  /**
   * When not to reset sort_name.
   *
   * @var bool
   */
  protected $_preserveDefault = TRUE;

  /**
   * Contact fields.
   *
   * @var array
   */
  protected $_contactFields = [];

  /**
   * Fields array of fields in the batch profile.
   *
   * (based on the uf_field table data)
   * (this can't be protected as it is passed into the CRM_Contact_Form_Task_Batch::parseStreetAddress function
   * (although a future refactoring might hopefully change that so it uses the api & the function is not
   * required
   *
   * @var array
   */
  public $_fields = [];

  /**
   * Monetary fields that may be submitted.
   *
   * These should get a standardised format in the beginPostProcess function.
   *
   * These fields are common to many forms. Some may override this.
   * @var array
   */
  protected $submittableMoneyFields = ['total_amount', 'net_amount', 'non_deductible_amount', 'fee_amount'];

  /**
   * Build all the data structures needed to build the form.
   *
   * @throws \CRM_Core_Exception
   */
  public function preProcess() {
    $this->_batchId = CRM_Utils_Request::retrieve('id', 'Positive', $this, TRUE);

    $this->_action = CRM_Utils_Request::retrieve('action', 'String', $this, FALSE, 'browse');

    if (empty($this->_batchInfo)) {
      $params = ['id' => $this->_batchId];
      CRM_Batch_BAO_Batch::retrieve($params, $this->_batchInfo);

      $this->assign('batchTotal', !empty($this->_batchInfo['total']) ? $this->_batchInfo['total'] : NULL);
      $this->assign('batchType', $this->_batchInfo['type_id']);

      // get the profile id associted with this batch type
      $this->_profileId = CRM_Batch_BAO_Batch::getProfileId($this->_batchInfo['type_id']);
    }
    CRM_Core_Resources::singleton()
      ->addScriptFile('civicrm', 'templates/CRM/Batch/Form/Entry.js', 1, 'html-header')
      ->addSetting(['batch' => ['type_id' => $this->_batchInfo['type_id']]])
      ->addSetting(['setting' => ['monetaryThousandSeparator' => CRM_Core_Config::singleton()->monetaryThousandSeparator]])
      ->addSetting(['setting' => ['monetaryDecimalPoint' => CRM_Core_Config::singleton()->monetaryDecimalPoint]]);

    $this->assign('defaultCurrencySymbol', CRM_Core_BAO_Country::defaultCurrencySymbol());
  }

  /**
   * Set Batch ID.
   *
   * @param int $id
   */
  public function setBatchID($id) {
    $this->_batchId = $id;
  }

  /**
   * Build the form object.
   *
   * @throws \CRM_Core_Exception
   */
  public function buildQuickForm() {
    if (!$this->_profileId) {
      CRM_Core_Error::statusBounce(ts('Profile for bulk data entry is missing.'));
    }

    $this->addElement('hidden', 'batch_id', $this->_batchId);

    $batchTypes = CRM_Core_PseudoConstant::get('CRM_Batch_DAO_Batch', 'type_id', ['flip' => 1], 'validate');
    // get the profile information
    if ($this->_batchInfo['type_id'] == $batchTypes['Contribution']) {
      CRM_Utils_System::setTitle(ts('Batch Data Entry for Contributions'));
    }
    elseif ($this->_batchInfo['type_id'] == $batchTypes['Membership']) {
      CRM_Utils_System::setTitle(ts('Batch Data Entry for Memberships'));
    }
    elseif ($this->_batchInfo['type_id'] == $batchTypes['Pledge Payment']) {
      CRM_Utils_System::setTitle(ts('Batch Data Entry for Pledge Payments'));
    }

    $this->_fields = CRM_Core_BAO_UFGroup::getFields($this->_profileId, FALSE, CRM_Core_Action::VIEW);

    // remove file type field and then limit fields
    $suppressFields = FALSE;
    foreach ($this->_fields as $name => $field) {
      if ($cfID = CRM_Core_BAO_CustomField::getKeyID($name) && $this->_fields[$name]['html_type'] == 'Autocomplete-Select') {
        $suppressFields = TRUE;
        unset($this->_fields[$name]);
      }

      //fix to reduce size as we are using this field in grid
      if (is_array($field['attributes']) && $this->_fields[$name]['attributes']['size'] > 19) {
        //shrink class to "form-text-medium"
        $this->_fields[$name]['attributes']['size'] = 19;
      }
    }

    $this->addFormRule(['CRM_Batch_Form_Entry', 'formRule'], $this);

    // add the force save button
    $forceSave = $this->getButtonName('upload', 'force');

    $this->addElement('submit',
      $forceSave,
      ts('Ignore Mismatch & Process the Batch?')
    );

    $this->addButtons([
      [
        'type' => 'upload',
        'name' => ts('Validate & Process the Batch'),
        'isDefault' => TRUE,
      ],
      [
        'type' => 'cancel',
        'name' => ts('Save & Continue Later'),
      ],
    ]);

    $this->assign('rowCount', $this->_batchInfo['item_count'] + 1);

    $preserveDefaultsArray = [
      'first_name',
      'last_name',
      'middle_name',
      'organization_name',
      'household_name',
    ];

    $contactTypes = ['Contact', 'Individual', 'Household', 'Organization'];
    $contactReturnProperties = [];

    for ($rowNumber = 1; $rowNumber <= $this->_batchInfo['item_count']; $rowNumber++) {
      $this->addEntityRef("primary_contact_id[{$rowNumber}]", '', [
        'create' => TRUE,
        'placeholder' => ts('- select -'),
      ]);

      // special field specific to membership batch udpate
      if ($this->_batchInfo['type_id'] == 2) {
        $options = [
          1 => ts('Add Membership'),
          2 => ts('Renew Membership'),
        ];
        $this->add('select', "member_option[$rowNumber]", '', $options);
      }
      if ($this->_batchInfo['type_id'] == $batchTypes['Pledge Payment']) {
        $options = ['' => '-select-'];
        $optionTypes = [
          '1' => ts('Adjust Pledge Payment Schedule?'),
          '2' => ts('Adjust Total Pledge Amount?'),
        ];
        $this->add('select', "option_type[$rowNumber]", NULL, $optionTypes);
        if (!empty($this->_batchId) && !empty($this->_batchInfo['data']) && !empty($rowNumber)) {
          $dataValues = json_decode($this->_batchInfo['data'], TRUE);
          if (!empty($dataValues['values']['primary_contact_id'][$rowNumber])) {
            $pledgeIDs = CRM_Pledge_BAO_Pledge::getContactPledges($dataValues['values']['primary_contact_id'][$rowNumber]);
            foreach ($pledgeIDs as $pledgeID) {
              $pledgePayment = CRM_Pledge_BAO_PledgePayment::getOldestPledgePayment($pledgeID);
              $options += [$pledgeID => CRM_Utils_Date::customFormat($pledgePayment['schedule_date'], '%m/%d/%Y') . ', ' . $pledgePayment['amount'] . ' ' . $pledgePayment['currency']];
            }
          }
        }

        $this->add('select', "open_pledges[$rowNumber]", '', $options);
      }

      foreach ($this->_fields as $name => $field) {
        if (in_array($field['field_type'], $contactTypes)) {
          $fld = explode('-', $field['name']);
          $contactReturnProperties[$field['name']] = $fld[0];
        }
        CRM_Core_BAO_UFGroup::buildProfile($this, $field, NULL, NULL, FALSE, FALSE, $rowNumber);

        if (in_array($field['name'], $preserveDefaultsArray)) {
          $this->_preserveDefault = FALSE;
        }
      }
    }

    // CRM-19477: Display Error for Batch Sizes Exceeding php.ini max_input_vars
    // Notes: $this->_elementIndex gives an approximate count of the variables being sent
    // An offset value is set to deal with additional vars that are likely passed.
    // There may be a more accurate way to do this...
    // set an offset to account for other vars we are not counting
    $offset = 50;
    if ((count($this->_elementIndex) + $offset) > ini_get("max_input_vars")) {
      // Avoiding 'ts' for obscure messages.
      CRM_Core_Error::statusBounce('Batch size is too large. Increase value of php.ini setting "max_input_vars" (current val = ' . ini_get("max_input_vars") . ')');
    }

    $this->assign('fields', $this->_fields);
    CRM_Core_Resources::singleton()
      ->addSetting([
        'contact' => [
          'return' => implode(',', $contactReturnProperties),
          'fieldmap' => array_flip($contactReturnProperties),
        ],
      ]);

    // don't set the status message when form is submitted.
    $buttonName = $this->controller->getButtonName('submit');

    if ($suppressFields && $buttonName != '_qf_Entry_next') {
      CRM_Core_Session::setStatus(ts("File type field(s) in the selected profile are not supported for Update multiple records."), ts('Some Fields Excluded'), 'info');
    }
  }

  /**
   * Form validations.
   *
   * @param array $params
   *   Posted values of the form.
   * @param array $files
   *   List of errors to be posted back to the form.
   * @param \CRM_Batch_Form_Entry $self
   *   Form object.
   *
   * @return array
   *   list of errors to be posted back to the form
   */
  public static function formRule($params, $files, $self) {
    $errors = [];
    $batchTypes = CRM_Core_PseudoConstant::get('CRM_Batch_DAO_Batch', 'type_id', ['flip' => 1], 'validate');
    $fields = [
      'total_amount' => ts('Amount'),
      'financial_type' => ts('Financial Type'),
      'payment_instrument' => ts('Payment Method'),
    ];

    //CRM-16480 if contact is selected, validate financial type and amount field.
    foreach ($params['field'] as $key => $value) {
      if (isset($value['trxn_id'])) {
        if (0 < CRM_Core_DAO::singleValueQuery('SELECT id FROM civicrm_contribution WHERE trxn_id = %1', [1 => [$value['trxn_id'], 'String']])) {
          $errors["field[$key][trxn_id]"] = ts('Transaction ID must be unique within the database');
        }
      }
      foreach ($fields as $field => $label) {
        if (!empty($params['primary_contact_id'][$key]) && empty($value[$field])) {
          $errors["field[$key][$field]"] = ts('%1 is a required field.', [1 => $label]);
        }
      }
    }

    if (!empty($params['_qf_Entry_upload_force'])) {
      if (!empty($errors)) {
        return $errors;
      }
      return TRUE;
    }

    $batchTotal = 0;
    foreach ($params['field'] as $key => $value) {
      $batchTotal += $value['total_amount'];

      //validate for soft credit fields
      if (!empty($params['soft_credit_contact_id'][$key]) && empty($params['soft_credit_amount'][$key])) {
        $errors["soft_credit_amount[$key]"] = ts('Please enter the soft credit amount.');
      }
      if (!empty($params['soft_credit_amount']) && !empty($params['soft_credit_amount'][$key]) && CRM_Utils_Rule::cleanMoney(CRM_Utils_Array::value($key, $params['soft_credit_amount'])) > CRM_Utils_Rule::cleanMoney($value['total_amount'])) {
        $errors["soft_credit_amount[$key]"] = ts('Soft credit amount should not be greater than the total amount');
      }

      //membership type is required for membership batch entry
      if ($self->_batchInfo['type_id'] == $batchTypes['Membership']) {
        if (empty($value['membership_type'][1])) {
          $errors["field[$key][membership_type]"] = ts('Membership type is a required field.');
        }
      }
    }
    if ($self->_batchInfo['type_id'] == $batchTypes['Pledge Payment']) {
      foreach (array_unique($params["open_pledges"]) as $value) {
        if (!empty($value)) {
          $duplicateRows = array_keys($params["open_pledges"], $value);
        }
        if (!empty($duplicateRows) && count($duplicateRows) > 1) {
          foreach ($duplicateRows as $key) {
            $errors["open_pledges[$key]"] = ts('You can not record two payments for the same pledge in a single batch.');
          }
        }
      }
    }
    if ((string) $batchTotal != $self->_batchInfo['total']) {
      $self->assign('batchAmountMismatch', TRUE);
      $errors['_qf_defaults'] = ts('Total for amounts entered below does not match the expected batch total.');
    }

    if (!empty($errors)) {
      return $errors;
    }

    $self->assign('batchAmountMismatch', FALSE);
    return TRUE;
  }

  /**
   * Override default cancel action.
   */
  public function cancelAction() {
    // redirect to batch listing
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/batch', 'reset=1'));
    CRM_Utils_System::civiExit();
  }

  /**
   * Set default values for the form.
   *
   * @throws \CRM_Core_Exception
   */
  public function setDefaultValues() {
    if (empty($this->_fields)) {
      return;
    }

    // for add mode set smart defaults
    if ($this->_action & CRM_Core_Action::ADD) {
      $currentDate = date('Y-m-d H-i-s');

      $completeStatus = CRM_Contribute_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
      $specialFields = [
        'membership_join_date' => date('Y-m-d'),
        'receive_date' => $currentDate,
        'contribution_status_id' => $completeStatus,
      ];

      for ($rowNumber = 1; $rowNumber <= $this->_batchInfo['item_count']; $rowNumber++) {
        foreach ($specialFields as $key => $value) {
          $defaults['field'][$rowNumber][$key] = $value;
        }
      }
    }
    else {
      // get the cached info from data column of civicrm_batch
      $data = CRM_Core_DAO::getFieldValue('CRM_Batch_BAO_Batch', $this->_batchId, 'data');
      $defaults = json_decode($data, TRUE);
      $defaults = $defaults['values'];
    }

    return $defaults;
  }

  /**
   * Process the form after the input has been submitted and validated.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function postProcess() {
    $params = $this->controller->exportValues($this->_name);
    $params['actualBatchTotal'] = 0;

    // get the profile information
    $batchTypes = CRM_Core_PseudoConstant::get('CRM_Batch_DAO_Batch', 'type_id', ['flip' => 1], 'validate');
    if (in_array($this->_batchInfo['type_id'], [$batchTypes['Pledge Payment'], $batchTypes['Contribution']])) {
      $this->processContribution($params);
    }
    elseif ($this->_batchInfo['type_id'] == $batchTypes['Membership']) {
      $this->processMembership($params);
    }

    // update batch to close status
    $paramValues = [
      'id' => $this->_batchId,
      // close status
      'status_id' => CRM_Core_PseudoConstant::getKey('CRM_Batch_BAO_Batch', 'status_id', 'Closed'),
      'total' => $params['actualBatchTotal'],
    ];

    CRM_Batch_BAO_Batch::create($paramValues);

    // set success status
    CRM_Core_Session::setStatus("", ts("Batch Processed."), "success");

    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/batch', 'reset=1'));
  }

  /**
   * Process contribution records.
   *
   * @param array $params
   *   Associated array of submitted values.
   *
   * @return bool
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  private function processContribution(&$params) {

    foreach ($this->submittableMoneyFields as $moneyField) {
      foreach ($params['field'] as $index => $fieldValues) {
        if (isset($fieldValues[$moneyField])) {
          $params['field'][$index][$moneyField] = CRM_Utils_Rule::cleanMoney($params['field'][$index][$moneyField]);
        }
      }
    }
    $params['actualBatchTotal'] = CRM_Utils_Rule::cleanMoney($params['actualBatchTotal']);
    // get the price set associated with offline contribution record.
    $priceSetId = CRM_Core_DAO::getFieldValue('CRM_Price_DAO_PriceSet', 'default_contribution_amount', 'id', 'name');
    $this->_priceSet = current(CRM_Price_BAO_PriceSet::getSetDetail($priceSetId));
    $priceFieldID = CRM_Price_BAO_PriceSet::getOnlyPriceFieldID($this->_priceSet);
    $priceFieldValueID = CRM_Price_BAO_PriceSet::getOnlyPriceFieldValueID($this->_priceSet);

    if (isset($params['field'])) {
      foreach ($params['field'] as $key => $value) {
        // if contact is not selected we should skip the row
        if (empty($params['primary_contact_id'][$key])) {
          continue;
        }

        $value['contact_id'] = $params['primary_contact_id'][$key] ?? NULL;

        // update contact information
        $this->updateContactInfo($value);

        //build soft credit params
        if (!empty($params['soft_credit_contact_id'][$key]) && !empty($params['soft_credit_amount'][$key])) {
          $value['soft_credit'][$key]['contact_id'] = $params['soft_credit_contact_id'][$key];
          $value['soft_credit'][$key]['amount'] = CRM_Utils_Rule::cleanMoney($params['soft_credit_amount'][$key]);

          //CRM-15350: if soft-credit-type profile field is disabled or removed then
          //we choose configured SCT default value
          if (!empty($params['soft_credit_type'][$key])) {
            $value['soft_credit'][$key]['soft_credit_type_id'] = $params['soft_credit_type'][$key];
          }
          else {
            $value['soft_credit'][$key]['soft_credit_type_id'] = CRM_Core_OptionGroup::getDefaultValue("soft_credit_type");
          }
        }

        // Build PCP params
        if (!empty($params['pcp_made_through_id'][$key])) {
          $value['pcp']['pcp_made_through_id'] = $params['pcp_made_through_id'][$key];
          $value['pcp']['pcp_display_in_roll'] = !empty($params['pcp_display_in_roll'][$key]);
          if (!empty($params['pcp_roll_nickname'][$key])) {
            $value['pcp']['pcp_roll_nickname'] = $params['pcp_roll_nickname'][$key];
          }
          if (!empty($params['pcp_personal_note'][$key])) {
            $value['pcp']['pcp_personal_note'] = $params['pcp_personal_note'][$key];
          }
        }

        $value['custom'] = CRM_Core_BAO_CustomField::postProcess($value,
          NULL,
          'Contribution'
        );

        if (!empty($value['send_receipt'])) {
          $value['receipt_date'] = date('Y-m-d His');
        }
        // these translations & date handling are required because we are calling BAO directly rather than the api
        $fieldTranslations = [
          'financial_type' => 'financial_type_id',
          'payment_instrument' => 'payment_instrument_id',
          'contribution_source' => 'source',
          'contribution_note' => 'note',
          'contribution_check_number' => 'check_number',
        ];
        foreach ($fieldTranslations as $formField => $baoField) {
          if (isset($value[$formField])) {
            $value[$baoField] = $value[$formField];
          }
          unset($value[$formField]);
        }

        $params['actualBatchTotal'] += $value['total_amount'];
        $value['batch_id'] = $this->_batchId;
        $value['skipRecentView'] = TRUE;

        // build line item params
        $this->_priceSet['fields'][$priceFieldID]['options'][$priceFieldValueID]['amount'] = $value['total_amount'];
        $value['price_' . $priceFieldID] = 1;

        $lineItem = [];
        CRM_Price_BAO_PriceSet::processAmount($this->_priceSet['fields'], $value, $lineItem[$priceSetId]);

        // @todo - stop setting amount level in this function & call the CRM_Price_BAO_PriceSet::getAmountLevel
        // function to get correct amount level consistently. Remove setting of the amount level in
        // CRM_Price_BAO_PriceSet::processAmount. Extend the unit tests in CRM_Price_BAO_PriceSetTest
        // to cover all variants.
        unset($value['amount_level']);

        //CRM-11529 for back office transactions
        //when financial_type_id is passed in form, update the
        //line items with the financial type selected in form
        // @todo - create a price set or price field per financial type & simply choose the appropriate
        // price field rather than working around the fact that each price_field is supposed to have a financial
        // type & we are allowing that to be overridden.
        if (!empty($value['financial_type_id']) && !empty($lineItem[$priceSetId])) {
          foreach ($lineItem[$priceSetId] as &$values) {
            $values['financial_type_id'] = $value['financial_type_id'];
          }
        }
        $value['line_item'] = $lineItem;
        $value['skipCleanMoney'] = TRUE;
        //finally call contribution create for all the magic
        $contribution = CRM_Contribute_BAO_Contribution::create($value);
        $batchTypes = CRM_Core_PseudoConstant::get('CRM_Batch_DAO_Batch', 'type_id', ['flip' => 1], 'validate');
        if (!empty($this->_batchInfo['type_id']) && ($this->_batchInfo['type_id'] == $batchTypes['Pledge Payment'])) {
          $adjustTotalAmount = FALSE;
          if (isset($params['option_type'][$key])) {
            if ($params['option_type'][$key] == 2) {
              $adjustTotalAmount = TRUE;
            }
          }
          $pledgeId = $params['open_pledges'][$key];
          if (is_numeric($pledgeId)) {
            $result = CRM_Pledge_BAO_PledgePayment::getPledgePayments($pledgeId);
            $pledgePaymentId = 0;
            foreach ($result as $key => $values) {
              if ($values['status'] != 'Completed') {
                $pledgePaymentId = $values['id'];
                break;
              }
            }
            CRM_Core_DAO::setFieldValue('CRM_Pledge_DAO_PledgePayment', $pledgePaymentId, 'contribution_id', $contribution->id);
            CRM_Pledge_BAO_PledgePayment::updatePledgePaymentStatus($pledgeId,
              [$pledgePaymentId],
              $contribution->contribution_status_id,
              NULL,
              $contribution->total_amount,
              $adjustTotalAmount
            );
          }
        }

        //process premiums
        if (!empty($value['product_name'])) {
          if ($value['product_name'][0] > 0) {
            list($products, $options) = CRM_Contribute_BAO_Premium::getPremiumProductInfo();

            $value['hidden_Premium'] = 1;
            $value['product_option'] = CRM_Utils_Array::value(
              $value['product_name'][1],
              $options[$value['product_name'][0]]
            );

            $premiumParams = [
              'product_id' => $value['product_name'][0],
              'contribution_id' => $contribution->id,
              'product_option' => $value['product_option'],
              'quantity' => 1,
            ];
            CRM_Contribute_BAO_Contribution::addPremium($premiumParams);
          }
        }
        // end of premium

        //send receipt mail.
        if ($contribution->id && !empty($value['send_receipt'])) {
          // add the domain email id
          $domainEmail = CRM_Core_BAO_Domain::getNameAndEmail();
          $domainEmail = "$domainEmail[0] <$domainEmail[1]>";
          $value['from_email_address'] = $domainEmail;
          $value['contribution_id'] = $contribution->id;
          if (!empty($value['soft_credit'])) {
            $value = array_merge($value, CRM_Contribute_BAO_ContributionSoft::getSoftContribution($contribution->id));
          }
          CRM_Contribute_Form_AdditionalInfo::emailReceipt($this, $value);
        }
      }
    }
    return TRUE;
  }

  /**
   * Process membership records.
   *
   * @param array $params
   *   Associated array of submitted values.
   *
   *
   * @return bool
   */
  private function processMembership(&$params) {

    // get the price set associated with offline membership
    $priceSetId = CRM_Core_DAO::getFieldValue('CRM_Price_DAO_PriceSet', 'default_membership_type_amount', 'id', 'name');
    $this->_priceSet = $priceSets = current(CRM_Price_BAO_PriceSet::getSetDetail($priceSetId));

    if (isset($params['field'])) {
      // @todo - most of the wrangling in this function is because the api is not being used, especially date stuff.
      $customFields = [];
      foreach ($params['field'] as $key => $value) {
        foreach ($value as $fieldKey => $fieldValue) {
          if (isset($this->_fields[$fieldKey]) && $this->_fields[$fieldKey]['data_type'] === 'Money') {
            $value[$fieldKey] = CRM_Utils_Rule::cleanMoney($fieldValue);
          }
        }
        // if contact is not selected we should skip the row
        if (empty($params['primary_contact_id'][$key])) {
          continue;
        }

        $value['contact_id'] = $params['primary_contact_id'][$key] ?? NULL;

        // update contact information
        $this->updateContactInfo($value);

        $membershipTypeId = $value['membership_type_id'] = $value['membership_type'][1];

        if (!empty($value['send_receipt'])) {
          $value['receipt_date'] = date('Y-m-d His');
        }

        if (!empty($value['membership_source'])) {
          $value['source'] = $value['membership_source'];
        }

        unset($value['membership_source']);

        //Get the membership status
        if (!empty($value['membership_status'])) {
          $value['status_id'] = $value['membership_status'];
          unset($value['membership_status']);
        }

        if (empty($customFields)) {
          // membership type custom data
          $customFields = CRM_Core_BAO_CustomField::getFields('Membership', FALSE, FALSE, $membershipTypeId);

          $customFields = CRM_Utils_Array::crmArrayMerge($customFields,
            CRM_Core_BAO_CustomField::getFields('Membership',
              FALSE, FALSE, NULL, NULL, TRUE
            )
          );
        }

        //check for custom data
        $value['custom'] = CRM_Core_BAO_CustomField::postProcess($params['field'][$key],
          $key,
          'Membership',
          $membershipTypeId
        );

        if (!empty($value['financial_type'])) {
          $value['financial_type_id'] = $value['financial_type'];
        }

        if (!empty($value['payment_instrument'])) {
          $value['payment_instrument_id'] = $value['payment_instrument'];
        }

        // handle soft credit
        if (!empty($params['soft_credit_contact_id'][$key]) && !empty($params['soft_credit_amount'][$key])) {
          $value['soft_credit'][$key]['contact_id'] = $params['soft_credit_contact_id'][$key];
          $value['soft_credit'][$key]['amount'] = CRM_Utils_Rule::cleanMoney($params['soft_credit_amount'][$key]);

          //CRM-15350: if soft-credit-type profile field is disabled or removed then
          //we choose Gift as default value as per Gift Membership rule
          if (!empty($params['soft_credit_type'][$key])) {
            $value['soft_credit'][$key]['soft_credit_type_id'] = $params['soft_credit_type'][$key];
          }
          else {
            $value['soft_credit'][$key]['soft_credit_type_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionSoft', 'soft_credit_type_id', 'Gift');
          }
        }
        if (!empty($value['total_amount'])) {
          $value['total_amount'] = (float) $value['total_amount'];
        }

        $params['actualBatchTotal'] += $value['total_amount'];

        unset($value['financial_type']);
        unset($value['payment_instrument']);

        $value['batch_id'] = $this->_batchId;
        $value['skipRecentView'] = TRUE;

        // make entry in line item for contribution

        $editedFieldParams = [
          'price_set_id' => $priceSetId,
          'name' => $value['membership_type'][0],
        ];

        $editedResults = [];
        CRM_Price_BAO_PriceField::retrieve($editedFieldParams, $editedResults);

        if (!empty($editedResults)) {
          unset($this->_priceSet['fields']);
          $this->_priceSet['fields'][$editedResults['id']] = $priceSets['fields'][$editedResults['id']];
          unset($this->_priceSet['fields'][$editedResults['id']]['options']);
          $fid = $editedResults['id'];
          $editedFieldParams = [
            'price_field_id' => $editedResults['id'],
            'membership_type_id' => $value['membership_type_id'],
          ];

          $editedResults = [];
          CRM_Price_BAO_PriceFieldValue::retrieve($editedFieldParams, $editedResults);
          $this->_priceSet['fields'][$fid]['options'][$editedResults['id']] = $priceSets['fields'][$fid]['options'][$editedResults['id']];
          if (!empty($value['total_amount'])) {
            $this->_priceSet['fields'][$fid]['options'][$editedResults['id']]['amount'] = $value['total_amount'];
          }

          $fieldID = key($this->_priceSet['fields']);
          $value['price_' . $fieldID] = $editedResults['id'];

          $lineItem = [];
          CRM_Price_BAO_PriceSet::processAmount($this->_priceSet['fields'],
            $value, $lineItem[$priceSetId]
          );

          //CRM-11529 for backoffice transactions
          //when financial_type_id is passed in form, update the
          //lineitems with the financial type selected in form
          if (!empty($value['financial_type_id']) && !empty($lineItem[$priceSetId])) {
            foreach ($lineItem[$priceSetId] as &$values) {
              $values['financial_type_id'] = $value['financial_type_id'];
            }
          }

          $value['lineItems'] = $lineItem;
          $value['processPriceSet'] = TRUE;
        }
        // end of contribution related section

        unset($value['membership_type']);

        $value['is_renew'] = FALSE;
        if (!empty($params['member_option']) && CRM_Utils_Array::value($key, $params['member_option']) == 2) {

          // The following parameter setting may be obsolete.
          $this->_params = $params;
          $value['is_renew'] = TRUE;
          $isPayLater = $params['is_pay_later'] ?? NULL;
          $campaignId = NULL;
          if (isset($this->_values) && is_array($this->_values) && !empty($this->_values)) {
            $campaignId = $this->_params['campaign_id'] ?? NULL;
            if (!array_key_exists('campaign_id', $this->_params)) {
              $campaignId = $this->_values['campaign_id'] ?? NULL;
            }
          }

          $formDates = [
            'end_date' => $value['membership_end_date'] ?? NULL,
            'start_date' => $value['membership_start_date'] ?? NULL,
          ];
          $membershipSource = $value['source'] ?? NULL;
          list($membership) = CRM_Member_BAO_Membership::processMembership(
            $value['contact_id'], $value['membership_type_id'], FALSE,
            //$numTerms should be default to 1.
            NULL, NULL, $value['custom'], 1, NULL, FALSE,
            NULL, $membershipSource, $isPayLater, $campaignId, $formDates
          );

          // make contribution entry
          $contrbutionParams = array_merge($value, ['membership_id' => $membership->id]);
          $contrbutionParams['skipCleanMoney'] = TRUE;
          // @todo - calling this from here is pretty hacky since it is called from membership.create anyway
          // This form should set the correct params & not call this fn directly.
          CRM_Member_BAO_Membership::recordMembershipContribution($contrbutionParams);
        }
        else {
          $dateTypes = [
            'membership_join_date' => 'joinDate',
            'membership_start_date' => 'startDate',
            'membership_end_date' => 'endDate',
          ];

          $dates = [
            'join_date',
            'start_date',
            'end_date',
            'reminder_date',
          ];
          foreach ($dateTypes as $dateField => $dateVariable) {
            $$dateVariable = CRM_Utils_Date::processDate($value[$dateField]);
            $fDate[$dateField] = $value[$dateField] ?? NULL;
          }

          $calcDates = [];
          $calcDates[$membershipTypeId] = CRM_Member_BAO_MembershipType::getDatesForMembershipType($membershipTypeId,
            $joinDate, $startDate, $endDate
          );

          foreach ($calcDates as $memType => $calcDate) {
            foreach ($dates as $d) {
              //first give priority to form values then calDates.
              $date = $value[$d] ?? NULL;
              if (!$date) {
                $date = $calcDate[$d] ?? NULL;
              }

              $value[$d] = CRM_Utils_Date::processDate($date);
            }
          }

          unset($value['membership_start_date']);
          unset($value['membership_end_date']);
          $ids = [];
          // @todo stop passing empty $ids
          $membership = CRM_Member_BAO_Membership::create($value, $ids);
        }

        //process premiums
        if (!empty($value['product_name'])) {
          if ($value['product_name'][0] > 0) {
            list($products, $options) = CRM_Contribute_BAO_Premium::getPremiumProductInfo();

            $value['hidden_Premium'] = 1;
            $value['product_option'] = CRM_Utils_Array::value(
              $value['product_name'][1],
              $options[$value['product_name'][0]]
            );

            $premiumParams = [
              'product_id' => $value['product_name'][0],
              'contribution_id' => CRM_Core_DAO::getFieldValue('CRM_Member_DAO_MembershipPayment', $membership->id, 'contribution_id', 'membership_id'),
              'product_option' => $value['product_option'],
              'quantity' => 1,
            ];
            CRM_Contribute_BAO_Contribution::addPremium($premiumParams);
          }
        }
        // end of premium

        //send receipt mail.
        if ($membership->id && !empty($value['send_receipt'])) {

          // add the domain email id
          $domainEmail = CRM_Core_BAO_Domain::getNameAndEmail();
          $domainEmail = "$domainEmail[0] <$domainEmail[1]>";

          $value['from_email_address'] = $domainEmail;
          $value['membership_id'] = $membership->id;
          $value['contribution_id'] = CRM_Core_DAO::getFieldValue('CRM_Member_DAO_MembershipPayment', $membership->id, 'contribution_id', 'membership_id');
          CRM_Member_Form_Membership::emailReceipt($this, $value, $membership);
        }
      }
    }
    return TRUE;
  }

  /**
   * Update contact information.
   *
   * @param array $value
   *   Associated array of submitted values.
   */
  private function updateContactInfo(&$value) {
    $value['preserveDBName'] = $this->_preserveDefault;

    //parse street address, CRM-7768
    CRM_Contact_Form_Task_Batch::parseStreetAddress($value, $this);

    CRM_Contact_BAO_Contact::createProfileContact($value, $this->_fields,
      $value['contact_id']
    );
  }

  /**
   * Function exists purely for unit testing purposes.
   *
   * If you feel tempted to use this in live code then it probably means there is some functionality
   * that needs to be moved out of the form layer
   *
   * @param array $params
   *
   * @return bool
   */
  public function testProcessMembership($params) {
    return $this->processMembership($params);
  }

  /**
   * Function exists purely for unit testing purposes.
   *
   * If you feel tempted to use this in live code then it probably means there is some functionality
   * that needs to be moved out of the form layer.
   *
   * @param array $params
   *
   * @return bool
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function testProcessContribution($params) {
    return $this->processContribution($params);
  }

}
