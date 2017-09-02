<?php

class CRM_Timetrack_Case_Page_CaseView {
  /**
   * Implements hook_civicrm_caseSummary().
   */
  function caseSummary($case_id) {
    $summary = array();

    CRM_Core_Resources::singleton()->addStyleFile('coop.symbiotic.timetrack', 'css/crm-timetrack-case-page-caseview.css');

    $dao = CRM_Core_DAO::executeQuery('SELECT * FROM kcontract WHERE case_id = %1', array(
      1 => array($case_id, 'Positive'),
    ));

    if ($dao->fetch()) {
      $actions = array(
        array(
          'label' => ts('Add punch'),
          'url' => CRM_Utils_System::url('civicrm/timetrack/punch', array('reset' => 1, 'cid' => $case_id, 'action' => 'create')),
          'classes' => 'icon ui-icon-plus',
        ),
        array(
          'label' => ts('Add task'),
          'url' => CRM_Utils_System::url('civicrm/timetrack/task', array('reset' => 1, 'cid' => $case_id, 'action' => 'create')),
          'classes' => 'icon ui-icon-circle-plus',
        ),
      );

      // These actions should not open in a popup, otherwise actions buttons are broken.
      $actionsreg = array(
        array(
          'label' => ts('View/invoice punches'),
          'url' => CRM_Utils_System::url('civicrm/contact/search/custom', array('csid' => 16, 'case_id' => $case_id, 'force' => 1, 'crmSID' => '6_d')),
          'classes' => 'icon ui-icon-search',
        ),
        array(
          'label' => ts('Invoice other items'),
          'url' => CRM_Utils_System::url('civicrm/timetrack/invoice', array('case_id' => $case_id, 'reset' => 1)),
          'classes' => 'icon ui-icon-circle-plus',
        ),
      );

      $actions_html = '';

      foreach ($actions as $key => $action) {
        $actions_html .= "<a href='{$action['url']}' class='button'><span><div class='{$action['classes']}'></div>{$action['label']}</span></a>";
      }

      foreach ($actionsreg as $key => $action) {
        $actions_html .= "<a href='{$action['url']}' style='display: inline-block; padding-left: 1em;'><span><div class='{$action['classes']}'></div>{$action['label']}</span></a>";
      }

      $summary['timetrack_actions'] = array(
        'label' => ts('Time tracking:'),
        'value' => '<div>' . $actions_html . '</div>',
      );

      $summary['timetrack_billing_status'] = array(
        'label' => ts('Billing status:'),
        'value' => ts('%1 unbilled hour(s)', array(1 => CRM_Timetrack_Utils::roundUpSeconds($this->getUnbilledHours($case_id)))),
      );

      $summary['timetrack_irc_alias'] = array(
        'label' => ts('Chat alias:'),
        'value' => ($dao->alias ? $dao->alias : ts('n/a')),
      );
    }
    else {
      // TODO: we should probably have a way to enable/disable timetracking per case type.
      // so that if we don't find any info, it's perfectly normal to have an option to edit.
      $summary['timetrack_warning'] = array(
        'label' => ts('Timetrack:'),
        'value' => 'No timetracking information was found for this case.',
      );
    }

    $url = CRM_Utils_System::url('civicrm/timetrack/case', array('reset' => 1, 'cid' => $case_id));

    $summary['timetrack_edit'] = array(
      'label' => '',
      'value' => "<div><a href='{$url}' class='crm-popup'><span><div class='icon ui-icon-pencil'></div>" . ts('Edit') . "</span></a></div>",
    );

    $summary['timetrack_tasks'] = array(
      'label' => '',
      'value' => $this->getListOfTasks($case_id),
    );

    $summary['timetrack_invoices'] = array(
      'label' => '',
      'value' => $this->getListOfInvoice($case_id),
    );

    $summary['timetrack_invoice_task_overview'] = array(
      'label' => '',
      'value' => $this->getInvoiceTaskOverview($case_id),
    );

    return $summary;
  }

  function getListOfTasks($case_id) {
    $smarty = CRM_Core_Smarty::singleton();

    $smarty->assign('timetrack_header_idcss', 'caseview-tasks');
    $smarty->assign('timetrack_header_title', ts('Tasks', array('domain' => 'coop.symbiotic.timetrack')));

    $taskStatuses = CRM_Timetrack_PseudoConstant::getTaskStatuses();

    // FIXME ts() domain.
    $headers = array(
      'title' => ts('Task'),
      'estimate' => ts('Estimate'),
      'total_included' => ts('Punches'),
      'percent_done' => ts('% done'),
      'state' => ts('Status'),
      'begin' => ts('Begin'),
      'end' => ts('End'),
    );

    $smarty->assign('timetrack_headers', $headers);

    $rows = array();

    $total = array(
      'title' => ts('Total'),
      'estimate' => 0,
      'total_included' => 0,
      'percent' => '',
      'state' => '',
      'begin' => '',
      'end' => '',
    );

    $result = civicrm_api3('Timetracktask', 'get', array(
      'case_id' => $case_id,
      'skip_open_case_check' => 1,
      'option.limit' => 0,
    ));

    foreach ($result['values'] as $task) {
      $included_hours = CRM_Timetrack_Utils::roundUpSeconds($task['total_included'], 1);
      $percent_done = '';

      if ($task['estimate']) {
        $percent_done = round($included_hours / $task['estimate'] * 100) . '%';
      }

      $rows[] = array(
        'title' => CRM_Utils_System::href($task['title'], 'civicrm/timetrack/task', array('tid' => $task['task_id'])),
        'estimate' => $task['estimate'],
        'total_included' => $included_hours,
        'percent_done' => $percent_done,
        'state' => $taskStatuses[$task['state']],
        'begin' => substr($task['begin'], 0, 10), // TODO format date l10n
        'end' => substr($task['end'], 0, 10), // TODO format date l10n
      );

      $total['estimate'] += $task['estimate'];
      $total['total_included'] += $task['total_included'];
    }

    $total['total_included'] = CRM_Timetrack_Utils::roundUpSeconds($total['total_included'], 1);
    $total['percent_done'] = ($total['estimate'] ? round($total['total_included'] / $total['estimate'] * 100) : '');

    $rows[] = $total;

    $smarty->assign('timetrack_rows', $rows);
    return $smarty->fetch('CRM/Timetrack/Page/Snippets/AccordionTable.tpl');
  }

  function getListOfInvoice($case_id) {
    $smarty = CRM_Core_Smarty::singleton();

    $smarty->assign('timetrack_header_idcss', 'caseview-invoices');
    $smarty->assign('timetrack_header_title', ts('Invoices', array('domain' => 'coop.symbiotic.timetrack')));

    // FIXME ts() domain.
    $headers = array(
      'ledger_id' => ts('Ledger ID', array('domain' => 'coop.symbiotic.timetrack')),
      'created_date' => ts('Invoice date', array('domain' => 'coop.symbiotic.timetrack')),
      'total' => ts('Total punches', array('domain' => 'coop.symbiotic.timetrack')),
      'invoiced' => ts('Invoiced', array('domain' => 'coop.symbiotic.timetrack')),
      'invoiced_pct' => ts('% invoiced', array('domain' => 'coop.symbiotic.timetrack')),
      'state' => ts('Status', array('domain' => 'coop.symbiotic.timetrack')),
      'deposit_date' => ts('Deposit', array('domain' => 'coop.symbiotic.timetrack')),
      'deposit_reference' => ts('Reference', array('domain' => 'coop.symbiotic.timetrack')),
      'generate' => ts('Actions', array('domain' => 'coop.symbiotic.timetrack')),
    );

    $smarty->assign('timetrack_headers', $headers);

    $rows = array();

    $result = civicrm_api3('Timetrackinvoice', 'get', array(
      'case_id' => $case_id,
      'option.limit' => 0,
    ));

    $invoice_status_options = civicrm_api3('Timetrackinvoice', 'getoptions', array(
      'field' => 'state',
      'option.limit' => 0,
    ));

    foreach ($result['values'] as $invoice) {
      $included_hours = CRM_Timetrack_Utils::roundUpSeconds($invoice['total_included'], 1);

      $rows[] = array(
        'ledger_id' => CRM_Utils_System::href($invoice['ledger_bill_id'], 'civicrm/timetrack/invoice', array('invoice_id' => $invoice['invoice_id'])),
        'created_date' => substr($invoice['created_date'], 0, 10),
        'total' => $included_hours,
        'invoiced' => $invoice['hours_billed'], // already in hours
        'invoiced_pct' => ($included_hours > 0 ? round($invoice['hours_billed'] / $included_hours * 100, 2) : 0) . '%',
        'state' => "<div class='crm-entity' data-entity='Timetrackinvoice' data-id='{$invoice['id']}'>"
          . "<div class='crm-editable' data-type='select' data-field='state'>" . $invoice_status_options['values'][$invoice['state']] . '</div>'
          . '</div>',
        'deposit_date' => "<div class='crm-entity' data-entity='Timetrackinvoice' data-id='{$invoice['id']}'>"
          . "<div class='crm-editable' data-type='text' data-field='deposit_date'>" . substr($invoice['deposit_date'], 0, 10) . '</div>'
          . '</div>',
        'deposit_reference' => "<div class='crm-entity' data-entity='Timetrackinvoice' data-id='{$invoice['id']}'>"
          . "<div class='crm-editable' data-type='text' data-field='deposit_reference'>" . $invoice['deposit_reference'] . '</div>'
          . '</div>',
        'generate' => CRM_Utils_System::href('<i class="fa fa-pencil" aria-hidden="true" title="' . ts('Edit invoice', array('escape' => 'js', 'domain' => 'coop.symbiotic.timetrack')). '"></i>', 'civicrm/timetrack/invoice', array('invoice_id' => $invoice['invoice_id']))
          . ' ' . CRM_Utils_System::href('<i class="fa fa-cogs" aria-hidden="true" title="' . ts('Generate invoice', array('escape' => 'js', 'domain' => 'coop.symbiotic.timetrack')) . '"></i>', 'civicrm/timetrack/invoice/generate', array('invoice_id' => $invoice['invoice_id']))
          . ' ' . CRM_Utils_System::href('<i class="fa fa-files-o" aria-hidden="true" title="' . ts('Copy invoice as new', array('escape' => 'js', 'domain' => 'coop.symbiotic.timetrack')) . '"></i>', 'civicrm/timetrack/invoice', array('invoice_id' => $invoice['invoice_id'], 'action' => 'clone'))
      );
    }

    $smarty->assign('timetrack_rows', $rows);

    return $smarty->fetch('CRM/Timetrack/Page/Snippets/AccordionTable.tpl');
  }

  /**
   *
   */
  function getInvoiceTaskOverview($case_id) {
    $smarty = CRM_Core_Smarty::singleton();

    $smarty->assign('timetrack_header_idcss', 'caseview-invoice-task-recap');
    $smarty->assign('timetrack_header_title', ts('Invoicing, per task', array('domain' => 'ca.bidon.timetrack')));

    $rows = [];

    // FIXME ts() domain.
    $headers = array(
      'title' => ts('Task'),
      'estimate' => ts('Estimate'),
    );

    $rows = [];
    $tasks = [];

    // Fetch all tasks on the project, to make sure we list them all in the overview,
    // not just list tasks that have been invoiced already.
    $result = civicrm_api3('Timetracktask', 'get', array(
      'case_id' => $case_id,
      'skip_open_case_check' => 1,
      'option.limit' => 0,
    ));

    foreach ($result['values'] as $key => $val) {
      $rows[$key] = [
        'title' => $val['title'],
        'estimate' => $val['estimate'],
      ];
    }

    $total = array(
      'title' => ts('Total'),
      'estimate' => 0,
    );

    $dao = CRM_Core_DAO::executeQuery('SELECT o.ledger_bill_id, o.title, t.title as ktask_title, t.estimate, t.id as ktask_id, l.hours_billed
      FROM korder_line l
      LEFT JOIN korder o ON (o.id = l.order_id)
      LEFT JOIN ktask t ON (t.id = l.ktask_id)
      WHERE t.case_id = %1
      GROUP BY t.id, o.id
      ORDER BY o.id ASC', [
      1 => [$case_id, 'Positive'],
    ]);

    while ($dao->fetch()) {
      $headers[$dao->ledger_bill_id] = '#' . $dao->ledger_bill_id;

      if (!isset($total[$dao->ktask_id])) {
        $total[$dao->ktask_id] = 0;
      }

      if (!isset($tasks[$dao->ktask_id])) {
        $tasks[$dao->ktask_id] = 0;
      }

      $rows[$dao->ktask_id][$dao->ledger_bill_id] = $dao->hours_billed;
      $total[$dao->ledger_bill_id] += $dao->hours_billed;
      $tasks[$dao->ktask_id] += $dao->hours_billed;
    }

    $headers['total'] = ts('Total');
    $headers['available'] = ts('Available');

    // Calculate the total time invoiced, per task
    foreach ($tasks as $key => $val) {
      $rows[$key]['total'] = $val;
    }

    // Calculate the total estimates, per task
    // as well as the available budget left.
    foreach ($rows as $key => $val) {
      $total['estimate'] += $val['estimate'];
      $rows[$key]['available'] = $val['estimate'] - $val['total'];
    }

    // Now calculate the total of totals, and total available budget.
    $total['total'] = 0;
    $total['available'] = 0;

    foreach ($rows as $key => $val) {
      $total['total'] += $val['total'];
      $total['available'] += $val['available'];
    }

    $rows[] = $total;

    $smarty->assign('timetrack_headers', $headers);
    $smarty->assign('timetrack_rows', $rows);
    return $smarty->fetch('CRM/Timetrack/Page/Snippets/AccordionTable.tpl');
  }

  function getUnbilledHours($case_id) {
    // TODO: move to API ?
    $dao = CRM_Core_DAO::executeQuery('SELECT sum(duration) as total
      FROM kpunch
      INNER JOIN ktask on (ktask.id = kpunch.ktask_id AND ktask.case_id = %1)
      WHERE korder_id is NULL', array(
      1 => array($case_id, 'Positive'),
    ));

    $dao->fetch();
    return $dao->total;
  }
}
