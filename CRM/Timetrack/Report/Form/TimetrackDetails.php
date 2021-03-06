<?php

class CRM_Timetrack_Report_Form_TimetrackDetails extends CRM_Report_Form {
  protected $_addressField = FALSE;
  protected $_emailField = FALSE;
  protected $_summary = NULL;

  protected $_customGroupExtends = [];
  protected $_customGroupGroupBy = FALSE;

  public function __construct() {
    parent::__construct();

    $this->_groupFilter = FALSE;
    $this->_tagFilter = FALSE;

    parent::__construct();

    $all_projects = $this->getAllProjects();

    $this->_columns = [
      'civicrm_case' => [
        'dao' => 'CRM_Case_DAO_Case',
        'alias' => 'case',
        'fields' => [
          'id' => [
            'title' => ts('Project ID'),
            'default' => TRUE,
            'required' => TRUE,
            'type' => CRM_Utils_Type::T_INT,
          ],
          'subject' => [
            'title' => ts('Project'),
            'default' => TRUE,
            'type' => CRM_Utils_Type::T_STRING,
          ],
        ],
        'filters' => [
          // TODO: case type, case status?
          'id' => [
            'title' => ts('Project'),
            'operatorType' => CRM_Report_Form::OP_SELECT,
            'type' => CRM_Utils_Type::T_INT,
            'options' => $all_projects,
          ],
        ],
      ],
      'task' => [
        'dao' => 'CRM_Timetrack_DAO_Task',
        'alias' => 'task',
        'fields' => [
          'title' => [
            'title' => ts('Task'),
            'default' => TRUE,
            'type' => CRM_Utils_Type::T_STRING,
          ],
        ],
        'filters' => [
          // TODO: activity type?
        ],
      ],
      'punch' => [
        'dao' => 'CRM_Timetrack_DAO_Punch',
        'alias' => 'punch',
        'fields' => [
          'pid' => [
            'name' => 'id',
            'title' => ts('PID'),
            'default' => TRUE,
            'type' => CRM_Utils_Type::T_INT,
          ],
          'contact_id' => [
            'title' => ts('Contact'),
            'default' => TRUE,
            'type' => CRM_Utils_Type::T_STRING,
          ],
          'begin' => [
            'title' => ts('Begin'),
            'default' => TRUE,
            'type' => CRM_Utils_Type::T_TIME, // FIXME, currently a timestamp
          ],
          'duration' => [
            'title' => ts('Duration (h)'),
            'default' => TRUE,
            'type' => CRM_Utils_Type::T_FLOAT,
          ],
          'duration_rounded' => [
            'title' => ts('Duration (rounded, h)'),
            'default' => TRUE,
            'type' => CRM_Utils_Type::T_FLOAT,
            'dbAlias' => 'duration', // we round later
          ],
          'comment' => [
            'title' => ts('Comment'),
            'default' => TRUE,
            'type' => CRM_Utils_Type::T_STRING,
          ],
        ],
        'filters' => [
          'begin' => [
            'title' => ts('Period'),
            'operatorType' => CRM_Report_Form::OP_DATE,
            'type' => CRM_Utils_Type::T_DATE,
          ],
          'contact_id' => [
            'title' => ts('User'),
            'operatorType' => CRM_Report_Form::OP_SELECT,
            'type' => CRM_Utils_Type::T_INT,
            'options' => CRM_Timetrack_Utils::getUsers(),
          ],
        ],
        'order_bys' => [
          'begin' => [
            'title' => ts('Begin'),
            'default' => TRUE,
            'default_weight' => 1,
            'default_order' => 'ASC',
          ],
        ],
      ],
      'invoice' => [
        'dao' => 'CRM_Timetrack_DAO_Invoice',
        'alias' => 'invoice',
        'fields' => [
          'state' => [
            'title' => ts('Invoice status'),
            'default' => TRUE,
            'type' => CRM_Utils_Type::T_INT,
          ],
        ],
        'filters' => [
          'state' => [
            'title' => ts('Invoice status'),
            'operatorType' => CRM_Report_Form::OP_SELECT,
            'type' => CRM_Utils_Type::T_INT,
            'options' => array_merge(['' => ts('- select -')], CRM_Timetrack_PseudoConstant::getInvoiceStatuses()),
          ],
        ],
      ],
    ];
  }

  public function preProcess() {
    $this->assign('reportTitle', ts("Timetrack detailed report"));
    Civi::resources()->addStyleFile('coop.symbiotic.timetrack', 'css/crm-timetrack-report-timetrackdetails.css');

    parent::preProcess();
  }

  /**
   * Generic select function.
   * Most reports who declare columns implicitely will call this and also define more columnHeaders.
   */
  public function select() {
    $select = $this->_columnHeaders = [];

    foreach ($this->_columns as $tableName => $table) {
      if (array_key_exists('fields', $table)) {
        foreach ($table['fields'] as $fieldName => $field) {
          if (CRM_Utils_Array::value('required', $field) || CRM_Utils_Array::value($fieldName, $this->_params['fields'])) {
            $this->_columnHeaders["{$tableName}_{$fieldName}"]['title'] = $field['title'];
            $this->_columnHeaders["{$tableName}_{$fieldName}"]['type'] = CRM_Utils_Array::value('type', $field);
          }
        }
      }
    }

    parent::select();

    // FIXME: remove when field has been converted to mysql date.
    $this->_select = str_replace('punch_civireport.begin as punch_begin', 'FROM_UNIXTIME(punch_civireport.begin) as punch_begin', $this->_select);

    // Replace the drupal contact_id by the civicrm contact display_name
    $this->_select = str_replace('punch_civireport.contact_id as punch_contact_id', 'contact_civireport.display_name as punch_contact_id', $this->_select);

    // Concat project and task, so that inline edit works better.
    $this->_select = str_replace('task_civireport.title as task_title', "CONCAT(case_civireport.subject, ' > ', task_civireport.title) as task_title", $this->_select);
  }

  public function from() {
    $this->_from = 'FROM kpunch as punch_civireport
              LEFT JOIN ktask as task_civireport ON (task_civireport.id = punch_civireport.ktask_id)
              LEFT JOIN korder as invoice_civireport ON (invoice_civireport.id = punch_civireport.korder_id)
              LEFT JOIN civicrm_case as case_civireport ON (case_civireport.id = task_civireport.case_id)
              LEFT JOIN civicrm_contact as contact_civireport ON (contact_civireport.id = punch_civireport.contact_id)';
  }

  /**
   * This is only to apply the date filters. It is the template code from CiviCRM reports.
   * Child reports are expected to apply their own filters on the query as well.
   */
  public function where() {
    $clauses = [];
    foreach ($this->_columns as $tableName => $table) {
      if (array_key_exists('filters', $table)) {
        foreach ($table['filters'] as $fieldName => $field) {
          // [ML] added this, but there may be a better way already in core?
          $tableAlias = (isset($table['alias']) ? $table['alias'] : $tableName);

          $clause = NULL;
          if (CRM_Utils_Array::value('operatorType', $field) & CRM_Utils_Type::T_DATE) {
            $relative = CRM_Utils_Array::value("{$fieldName}_relative", $this->_params);
            $from     = CRM_Utils_Array::value("{$fieldName}_from", $this->_params);
            $to       = CRM_Utils_Array::value("{$fieldName}_to", $this->_params);

            $clause = $this->dateClause($tableAlias . '.' . $field['name'], $relative, $from, $to, $field['type']);
          }
          else {
            $op = CRM_Utils_Array::value("{$fieldName}_op", $this->_params);
            if ($op) {
              $clause = $this->whereClause($field,
                $op,
                CRM_Utils_Array::value("{$fieldName}_value", $this->_params),
                CRM_Utils_Array::value("{$fieldName}_min", $this->_params),
                CRM_Utils_Array::value("{$fieldName}_max", $this->_params)
              );
            }
          }

          // Handling exception for "punch status"
          if ($tableName == 'invoice' && $fieldName == 'state' && !empty($clause)) {
            $clause = str_replace(' = 0', ' is NULL', $clause);
          }

          if (!empty($clause)) {
            $clauses[] = $clause;
          }
        }
      }
    }
    if (empty($clauses)) {
      $this->_where = " WHERE ( 1 ) ";
    }
    else {
      $this->_where = " WHERE " . implode(' AND ', $clauses);
    }

    // FIXME: hacks because the where clause includes mysql date,
    // but our DB still uses unix timestamps.
    $this->_where = preg_replace('/(\d{8})/', 'UNIX_TIMESTAMP(\1)', $this->_where);
  }

  public function beginPostProcess() {
    parent::beginPostProcess();
  }

  public function setDefaultValues($freeze = TRUE) {
    parent::setDefaultValues($freeze);
    return $this->_defaults;
  }

  public function postProcess() {
    $this->beginPostProcess();

    $rows = [];

    $this->select();
    $this->from();
    $this->where();

    $sql = $this->_select . $this->_from . $this->_where;

    $this->buildRows($sql, $rows);

    $this->formatDisplay($rows);
    $this->doTemplateAssignment($rows);
    $this->endPostProcess($rows);
  }

  public function alterDisplay(&$rows) {
    $crmEditable = [
      'punch_contact_idd' => 'punch_contact_id',
      'punch_begin' => 'punch_begin',
      'punch_duration' => 'punch_duration',
      'punch_comment' => 'punch_comment',
      'task_title' => 'ktask_id',
     ];

    // TODO: in 4.6, see CRM-15759
    // and see also duplicate code in CRM/Timetrack/Form/Search/TimetrackPunches.php
    $optionsCache = [
      'ktask_id' => json_encode(CRM_Timetrack_Utils::getActivitiesForCase(), JSON_HEX_APOS),
      'punch_contact_id' => json_encode(CRM_Timetrack_Utils::getUsers(), JSON_HEX_APOS),
    ];

    foreach ($rows as &$row) {
      // Link the case subject to the case itself.
      if (!empty($row['civicrm_case_subject'])) {
        $contact_id = CRM_Timetrack_Utils::getCaseContact($row['civicrm_case_id']);

        $row['civicrm_case_subject'] = CRM_Utils_System::href($row['civicrm_case_subject'], 'civicrm/contact/view/case', [
          'reset' => 1,
          'id' => $row['civicrm_case_id'],
          'cid' => $contact_id,
          'action' => 'view',
          'context' => 'case',
        ]);
      }

      // Keep the plain/orig values for the statistics().
      if (!empty($row['punch_duration'])) {
        $row['punch_duration_rounded'] = sprintf('%.2f', CRM_Timetrack_Utils::roundUpSeconds($row['punch_duration']));
        $row['punch_duration'] = sprintf('%.2f', CRM_Timetrack_Utils::roundUpSeconds($row['punch_duration'], 1));
      }

      // TODO: This should only be allowed in certain circumstances (admins, punch owner?)
      foreach ($crmEditable as $displayed => $dbfield) {
        $row[$displayed . '_orig'] = $row[$displayed];

        if (isset($optionsCache[$dbfield])) {
          $row[$displayed] = "<div class='crm-entity' data-entity='Timetrackpunch' data-id='{$row['punch_pid']}'>"
            . "<div class='crm-editable' data-field='{$dbfield}' data-type='select' data-options='" . $optionsCache[$dbfield] . "'>" . $row[$displayed] . '</div>'
            . '</div>';
        }
        else {
          $row[$displayed] = "<div class='crm-entity' data-entity='Timetrackpunch' data-id='{$row['punch_pid']}'>"
            . "<div class='crm-editable' data-field='{$dbfield}'>" . $row[$displayed] . '</div>'
            . '</div>';
        }
      }

      // Make punch ID link to the punch edit form (and add the crm-popup class).
      $row['punch_pid'] = CRM_Utils_System::href($row['punch_pid'], 'civicrm/timetrack/punch', ['reset' => 1, 'pid' => $row['punch_pid'], 'action' => 'edit']);
      $row['punch_pid'] = str_replace('a href', 'a class="crm-popup" href', $row['punch_pid']);
    }
  }

  public function statistics(&$rows) {
    $statistics = parent::statistics($rows);

    $nb_weeks_worked = 0;
    $nb_days_worked = 0;

    // Days worked
    $sql = 'SELECT COUNT(DISTINCT DATE(FROM_UNIXTIME(punch_civireport.begin))) as cpt '
         . $this->_from
         . $this->_where;

    $dao = CRM_Core_DAO::executeQuery($sql);

    if ($dao->fetch()) {
      $nb_days_worked = $dao->cpt;
    }

    $nb_weeks_worked = round($nb_days_worked / 5, 2);

    // Total time (orig and rounded)
    $total_seconds_orig = 0;
    $total_hours_rounded = 0;

    foreach ($rows as $r) {
      $total_seconds_orig += $r['punch_duration_orig'];
      $total_hours_rounded += $r['punch_duration_rounded'];
    }

    $statistics['counts']['totaltime'] = [
      'title' => ts('Total Time'),
      'value' => ts('%1 hours', [1 => sprintf('%.2f', $total_seconds_orig)]),
      'type' => CRM_Utils_Type::T_STRING,
    ];

    $statistics['counts']['totalroundedtime'] = [
      'title' => ts('Total rounded time'),
      'value' => ts('%1 hours', [1 => sprintf('%.2f', $total_hours_rounded)]),
      'type' => CRM_Utils_Type::T_STRING,
    ];

    $statistics['counts']['weeksworked'] = [
      'title' => ts('Weeks worked'),
      'value' => ts('Aprox %1 weeks', [1 => $nb_weeks_worked]),
      'type' => CRM_Utils_Type::T_STRING,
    ];

    $statistics['counts']['avgperweek'] = [
      'title' => ts('Average per week worked'),
      'value' => ts('%1 hours', [1 => sprintf('%.2f', $total_hours_rounded / $nb_weeks_worked)]),
      'type' => CRM_Utils_Type::T_STRING,
    ];

    $statistics['counts']['daysworked'] = [
      'title' => ts('Days worked'),
      'value' => ts('%1 days', [1 => $nb_days_worked]),
      'type' => CRM_Utils_Type::T_STRING,
    ];

    $avg_per_day = ($nb_days_worked > 0 ? $total_seconds_orig / $nb_days_worked : 0);

    $statistics['counts']['avgperday'] = [
      'title' => ts('Average per day worked'),
      'value' => ts('%1 hours', [1 => sprintf('%.2f', $avg_per_day)]),
      'type' => CRM_Utils_Type::T_STRING,
    ];

    return $statistics;
  }

  public function getAllProjects() {
    $caseStatuses = CRM_Timetrack_Utils::getCaseOpenStatuses();

    $projects = [
      '' => ts('- select -'),
    ];

    $sql = 'SELECT c.id, cont.display_name, c.subject
              FROM civicrm_case as c
              INNER JOIN civicrm_case_contact as cc ON (cc.case_id = c.id)
              INNER JOIN civicrm_contact as cont ON (cont.id = cc.contact_id)
              WHERE c.status_id IN (' . implode(',', $caseStatuses) . ')
              ORDER BY cont.display_name ASC, c.subject ASC';

    $dao = CRM_Core_DAO::executeQuery($sql);

    while ($dao->fetch()) {
      $projects[$dao->id] = $dao->display_name . ': ' . $dao->subject;
    }

    return $projects;
  }

}
