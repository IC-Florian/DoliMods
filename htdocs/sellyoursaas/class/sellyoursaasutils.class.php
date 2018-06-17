<?php
/* Copyright (C) 2007-2012 Laurent Destailleur  <eldy@users.sourceforge.net>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *  \file       sellyoursaas/class/sellyoursaasutils.class.php
 *  \ingroup    sellyoursaas
 *  \brief      Class with utilities
 */

// Put here all includes required by your class file
//require_once(DOL_DOCUMENT_ROOT."/core/class/commonobject.class.php");
//require_once(DOL_DOCUMENT_ROOT."/societe/class/societe.class.php");
//require_once(DOL_DOCUMENT_ROOT."/product/class/product.class.php");
require_once DOL_DOCUMENT_ROOT.'/contrat/class/contrat.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
dol_include_once('sellyoursaas/lib/sellyoursaas.lib.php');


/**
 *	Class with cron tasks of SellYourSaas module
 */
class SellYourSaasUtils
{
	var $db;							//!< To store db handler
	var $error;							//!< To return error code (or message)
	var $errors=array();				//!< To return several error codes (or messages)

    /**
     *  Constructor
     *
     *  @param	DoliDb		$db      Database handler
     */
    function __construct($db)
    {
        $this->db = $db;
        return 1;
    }


    /**
     * Action executed by scheduler for job SellYourSaasValidateDraftInvoices
     * Search draft invoices on sellyoursaas customers and check they are linked to a not closed contract. Validate it if not, do nothing if closed.
     * CAN BE A CRON TASK
     *
     * @param	int		$restrictonthirdpartyid		0=All qualified draft invoices, >0 = Restrict on qualified draft invoice of thirdparty.
     * @return	int									0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
     */
    public function doValidateDraftInvoices($restrictonthirdpartyid=0)
    {
    	global $conf, $langs, $user;

		include_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		$invoice = new Facture($this->db);

		$savlog = $conf->global->SYSLOG_FILE;
		$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_doValidateDraftInvoices.log';

		$now = dol_now();

		dol_syslog(__METHOD__." search and validate draft invoices", LOG_DEBUG);

    	$error = 0;
    	$this->output = '';
    	$this->error='';

    	$draftinvoiceprocessed = array();

    	$this->db->begin();

		$sql = 'SELECT f.rowid FROM '.MAIN_DB_PREFIX.'facture as f,';
		$sql.= ' '.MAIN_DB_PREFIX.'societe_extrafields as se';
		$sql.= ' WHERE f.fk_statut = '.Facture::STATUS_DRAFT;
		$sql.= " AND se.fk_object = f.fk_soc AND se.dolicloud = 'yesv2'";
		if ($restrictonthirdpartyid > 0) $sql.=" AND f.fk_soc = ".$restrictonthirdpartyid;
		$resql = $this->db->query($sql);
		if ($resql)
		{
			$num_rows = $this->db->num_rows($resql);
			$i= 0;
			while($i < $num_rows)
			{
				$obj = $this->db->fetch_object($resql);
				if ($obj && $invoice->fetch($obj->rowid) > 0)
				{
					dol_syslog("* Process invoice id=".$invoice->id." ref=".$invoice->ref);

					// Search contract linked to invoice
					$invoice->fetchObjectLinked();

					if (is_array($invoice->linkedObjects['contrat']) && count($invoice->linkedObjects['contrat']) > 0)
					{
						//dol_sort_array($object->linkedObjects['facture'], 'date');
						foreach($invoice->linkedObjects['contrat'] as $idcontract => $contract)
						{
							if (! empty($draftinvoiceprocessed[$invoice->id])) continue;	// If already processed, do nothing more

							// We ignore $contract->nbofserviceswait +  and $contract->nbofservicesclosed
							$nbservice = $contract->nbofservicesopened + $contract->nbofservicesexpired;
							// If contract not undeployed and not suspended ?
							// Note: If suspended, when unsuspened, the remaining draft invoice will be generated
							// Note: if undeployed, this should not happen, because templates invoice should be disabled when an instance is undeployed
							if ($nbservice && $contract->array_options['options_deployment_status'] != 'undeployed')
							{
								$result = $invoice->validate($user);
								if ($result > 0)
								{
									$draftinvoiceprocessed[$invoice->id]=$invoice->ref;

									// Now we build the invoice
									$hidedetails = (GETPOST('hidedetails', 'int') ? GETPOST('hidedetails', 'int') : (! empty($conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_DETAILS) ? 1 : 0));
									$hidedesc = (GETPOST('hidedesc', 'int') ? GETPOST('hidedesc', 'int') : (! empty($conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_DESC) ? 1 : 0));
									$hideref = (GETPOST('hideref', 'int') ? GETPOST('hideref', 'int') : (! empty($conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_REF) ? 1 : 0));

									// Define output language
									$outputlangs = $langs;
									$newlang = '';
									if ($conf->global->MAIN_MULTILANGS && empty($newlang) && GETPOST('lang_id','aZ09')) $newlang = GETPOST('lang_id','aZ09');
									if ($conf->global->MAIN_MULTILANGS && empty($newlang))	$newlang = $invoice->thirdparty->default_lang;
									if (! empty($newlang)) {
										$outputlangs = new Translate("", $conf);
										$outputlangs->setDefaultLang($newlang);
										$outputlangs->load('products');
									}
									$model=$invoice->modelpdf;
									$ret = $invoice->fetch($id); // Reload to get new records

									$result = $invoice->generateDocument($model, $outputlangs, $hidedetails, $hidedesc, $hideref);
								}
								else
								{
									$error++;
									$this->error = $invoice->error;
									$this->errors = $invoice->errors;
									break;
								}
							}
							else
							{
								// Do nothing
								dol_syslog("Number of open services (".$nbservice.") is zero or contract is undeployed, so we do nothing.");
							}
						}
					}
					else
					{
						dol_syslog("No linked contract found on this invoice");
					}
				}
				else
				{
					$error++;
					$this->errors[] = 'Failed to get invoice with id '.$obj->rowid;
				}

				$i++;
			}
		}
		else
		{
			$error++;
			$this->error = $this->db->lasterror();
		}

		$this->output = count($draftinvoiceprocessed).' invoice(s) validated on '.$num_rows.' draft invoice found'.(count($draftinvoiceprocessed)>0 ? ' : '.join(',', $draftinvoiceprocessed) : '').' (search done on invoices of SellYourSaas customers only)';

		$this->db->commit();

		$conf->global->SYSLOG_FILE = $savlog;

		return ($error ? 1: 0);
    }

    /**
     * Action executed by scheduler for job SellYourSaasAlertSoftEndTrial
     * Search contracts of sellyoursaas customers that are about to expired (date = end date - SELLYOURSAAS_NBDAYS_BEFORE_TRIAL_END_FOR_SOFT_ALERT) and send email remind
     * CAN BE A CRON TASK
     *
     * @return	int			0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
     */
    public function doAlertSoftEndTrial()
    {
    	global $conf, $langs, $user;

    	$mode = 'test';

    	$savlog = $conf->global->SYSLOG_FILE;
    	$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_doAlertSoftEndTrial.log';

    	$contractprocessed = array();
    	$contractok = array();
    	$contractko = array();

    	$now = dol_now();

    	$error = 0;
    	$this->output = '';
    	$this->error='';

    	$delayindaysshort = $conf->global->SELLYOURSAAS_NBDAYS_BEFORE_TRIAL_END_FOR_SOFT_ALERT;
    	$delayindayshard = $conf->global->SELLYOURSAAS_NBDAYS_BEFORE_TRIAL_END_FOR_HARD_ALERT;
    	if ($delayindaysshort <= 0 || $delayindayshard <= 0)
    	{
    		$this->error='BadValueForDelayBeforeTrialEndForAlert';
    		return -1;
    	}
    	dol_syslog(__METHOD__." we send email warning on contract that will expire in ".$delayindaysshort." days or before and not yet reminded", LOG_DEBUG);

    	$this->db->begin();

    	$date_limit_expiration = dol_time_plus_duree($now, abs($delayindaysshort), 'd');

    	$sql = 'SELECT c.rowid, c.ref_customer, cd.rowid as lid, cd.date_fin_validite';
    	$sql.= ' FROM '.MAIN_DB_PREFIX.'contrat as c, '.MAIN_DB_PREFIX.'contratdet as cd, '.MAIN_DB_PREFIX.'contrat_extrafields as ce,';
    	$sql.= ' '.MAIN_DB_PREFIX.'societe_extrafields as se';
    	$sql.= ' WHERE cd.fk_contrat = c.rowid AND ce.fk_object = c.rowid';
    	$sql.= " AND ce.deployment_status = 'done'";
    	$sql.= " AND ce.date_softalert_endfreeperiod IS NULL";
    	$sql.= " AND cd.date_fin_validite <= '".$this->db->idate($date_limit_expiration)."'";
    	$sql.= " AND cd.date_fin_validite >= '".$this->db->idate($date_limit_expiration - 7 * 24 * 3600)."'";	// Protection: We dont' go higher than 5 days late to avoid to resend to much warning when update of date_softalert_endfreeperiod fails
    	$sql.= " AND cd.statut = 4";
    	$sql.= " AND se.fk_object = c.fk_soc AND se.dolicloud = 'yesv2'";
    	//print $sql;

    	$resql = $this->db->query($sql);
    	if ($resql)
    	{
    		$num = $this->db->num_rows($resql);

    		include_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
    		include_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
    		$formmail=new FormMail($this->db);

    		$MAXPERCALL=5;
    		$nbsending = 0;

    		$i=0;
    		while ($i < $num)
    		{
    			$obj = $this->db->fetch_object($resql);
    			if ($obj)
    			{
    				if (! empty($contractprocessed[$obj->rowid])) continue;

    				// Test if this is a paid or not instance
    				$object = new Contrat($this->db);
    				$result = $object->fetch($obj->rowid);
    				$object->fetch_thirdparty();

    				if ($result <= 0)
    				{
    					$error++;
    					$this->errors[] = 'Failed to load contract with id='.$obj->rowid;
    					continue;
    				}

    				dol_syslog("* Process contract id=".$object->id." ref=".$object->ref." ref_customer=".$object->ref_customer);

    				$outputlangs = new Translate('', $conf);
    				$outputlangs->setDefaultLang($object->thirdparty->default_lang);

    				$arraydefaultmessage=$formmail->getEMailTemplate($this->db, 'contract', $user, $outputlangs, 0, 1, 'GentleTrialExpiringReminder');

    				dol_syslog('Call sellyoursaasIsPaidInstance', LOG_DEBUG, 1);
    				$isAPayingContract = sellyoursaasIsPaidInstance($object);
    				dol_syslog('', 0, -1);
    				if ($mode == 'test' && $isAPayingContract) continue;											// Discard if this is a paid instance when we are in test mode
    				//if ($mode == 'paid' && ! $isAPayingContract) continue;											// Discard if this is a test instance when we are in paid mode

    				// Suspend instance
    				dol_syslog('Call sellyoursaasGetExpirationDate', LOG_DEBUG, 1);
    				$tmparray = sellyoursaasGetExpirationDate($object);
    				dol_syslog('', 0, -1);
    				$expirationdate = $tmparray['expirationdate'];

    				if ($expirationdate && $expirationdate < $date_limit_expiration)
    				{
    					$nbsending++;
    					if ($nbsending <= $MAXPERCALL)
    					{
	    					$substitutionarray=getCommonSubstitutionArray($outputlangs, 0, null, $object);
	    					$substitutionarray['__SELLYOURSAAS_EXPIRY_DATE__']=dol_print_date($expirationdate, 'day', $outputlangs, 'tzserver');
	    					complete_substitutions_array($substitutionarray, $outputlangs, $object);

	    					//$object->array_options['options_deployment_status'] = 'suspended';
	    					$subject = make_substitutions($arraydefaultmessage->topic, $substitutionarray);
	    					$msg     = make_substitutions($arraydefaultmessage->content, $substitutionarray);
	    					$from = $conf->global->SELLYOURSAAS_NOREPLY_EMAIL;
	    					$to = $object->thirdparty->email;
	    					$trackid = 'thi'.$object->thirdparty->id;

	    					$cmail = new CMailFile($subject, $to, $from, $msg, array(), array(), array(), '', '', 0, 1, '', '', $trackid);
	    					$result = $cmail->sendfile();
	    					if (! $result)
	    					{
	    						$error++;
	    						$this->error = $cmail->error;
	    						$this->errors = $cmail->errors;
	    						dol_syslog("Failed to send email to ".$to." ".$this->error, LOG_WARNING);
	    						$contractko[$object->id]=$object->ref;
	    					}
	    					else
	    					{
	    						dol_syslog("Email sent to ".$to, LOG_DEBUG);
	    						$contractok[$object->id]=$object->ref;

	    						$sqlupdatedate = 'UPDATE '.MAIN_DB_PREFIX."contrat_extrafields SET date_softalert_endfreeperiod = '".$this->db->idate($now)."' WHERE fk_object = ".$object->id;
	    						$resqlupdatedate = $this->db->query($sqlupdatedate);
	    					}

	    					$contractprocessed[$object->id]=$object->ref;
    					}
    				}
    			}
    			$i++;
    		}
    	}
    	else
    	{
    		$error++;
    		$this->error = $this->db->lasterror();
    	}

    	$this->output = count($contractprocessed).' contract(s) processed (search done on contracts of SellYourSaas customers only).';
    	if (count($contractok)>0)
    	{
    		$this->output .= ' '.count($contractok).' email(s) sent for '.join(',', $contractok).'.';
    	}
    	if (count($contractko)>0)
    	{
    		$this->output .= ' '.count($contractko).' email(s) in error for '.join(',', $contractko).'.';
    	}

    	$this->db->commit();

    	$conf->global->SYSLOG_FILE = $savlog;

    	return ($error ? 1: 0);
    }


    /**
     * Action executed by scheduler. To run every day.
     * Send warning when credit card will expire to sellyoursaas customers.
     * CAN BE A CRON TASK
     *
     * @param	int			$day1	Day1 in month to launch warnings (1st)
     * @param	int			$day2	Day2 in month to launch warnings (20th)
     * @return	int					0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
     */
    public function doAlertCreditCardExpiration($day1='',$day2='')
    {
    	global $conf, $langs, $user;

    	$savlog = $conf->global->SYSLOG_FILE;
    	$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_doAlertCreditCardExpiration.log';

    	$now = dol_now();

    	$error = 0;
    	$this->output = '';
    	$this->error='';

    	dol_syslog(__METHOD__.' - Search card that expire at end of month and send remind. Test is done the day '.$day1.' and '.$day2.' of month', LOG_DEBUG);

    	if (empty($day1) ||empty($day2))
    	{
    		$this->error = 'Bad value for parameter day1 and day2. Set param to "1, 20" for example';
    		$error++;
    		return 1;
    	}

    	$servicestatus = 0;
    	if (! empty($conf->stripe->enabled))
    	{
    		$service = 'StripeTest';
    		$servicestatus = 0;
    		if (! empty($conf->global->STRIPE_LIVE) && ! GETPOST('forcesandbox','alpha') && empty($conf->global->SELLYOURSAAS_FORCE_STRIPE_TEST))
    		{
    			$service = 'StripeLive';
    			$servicestatus = 1;
    		}
    	}

    	$currentdate = dol_getdate($now);
    	$currentday = $currentdate['mday'];
    	$currentmonth = $currentdate['mon'];
    	$currentyear = $currentdate['year'];

    	if ($currentday != $day1 && $currentday != $day2) {
    		$this->output = 'Nothing to do. We are not the day '.$day1.', neither the day '.$day2.' of the month';
    		return 0;
    	}

    	$this->db->begin();

    	// Get warning email template
    	include_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
    	include_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
    	include_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture-rec.class.php';
    	$formmail=new FormMail($db);

    	$nextyear = $currentyear;
    	$nextmonth = $currentmonth + 1;
    	if ($nextmonth > 12) { $nextmonth = 1; $nextyear++; }

    	// Search payment modes on companies that has an active invoice template
    	$sql = 'SELECT DISTINCT sr.rowid, sr.fk_soc, sr.exp_date_month, sr.exp_date_year, sr.last_four, sr.status';
    	$sql.= ' FROM '.MAIN_DB_PREFIX.'societe_rib as sr, '.MAIN_DB_PREFIX.'societe_extrafields as se,';
    	$sql.= ' '.MAIN_DB_PREFIX.'facture_rec as fr';
    	$sql.= " WHERE sr.fk_soc = fr.fk_soc AND sr.default_rib = 1 AND sr.type = 'card' AND sr.status = ".$servicestatus;
    	$sql.= " AND se.fk_object = fr.fk_soc AND se.dolicloud = 'yesv2'";
    	$sql.= " AND sr.exp_date_month = ".$currentmonth." AND sr.exp_date_year = ".$currentyear;
    	$sql.= " AND fr.suspended = ".FactureRec::STATUS_NOTSUSPENDED;
    	$sql.= " AND fr.frequency > 0";

    	$resql = $this->db->query($sql);
    	if ($resql)
    	{
    		$num_rows = $this->db->num_rows($resql);
    		$i = 0;
    		while ($i < $num_rows)
    		{
    			$obj = $this->db->fetch_object($resql);

    			$thirdparty = new Societe($this->db);
    			$thirdparty->fetch($obj->fk_soc);

    			if ($thirdparty->id > 0)
    			{
    				dol_syslog("* Process thirdparty id=".$thirdparty->id." name=".$thirdparty->nom);

    				$langstouse = new Translate('', $conf);
    				$langstouse->setDefaultLang($thirdparty->default_lang ? $thirdparty->default_lang : $langs->defaultlang);

    				$arraydefaultmessage=$formmail->getEMailTemplate($this->db, 'thirdparty', $user, $langstouse, -2, 1, '(AlertCreditCardExpiration)');		// Templates are init into data.sql

    				if (is_object($arraydefaultmessage) && ! empty($arraydefaultmessage->topic))
    				{
    					$substitutionarray=getCommonSubstitutionArray($langstouse, 0, null, $thirdparty);
    					$substitutionarray['__CARD_EXP_DATE_MONTH__']=$obj->exp_date_month;
    					$substitutionarray['__CARD_EXP_DATE_YEAR__']=$obj->exp_date_year;
    					$substitutionarray['__CARD_LAST4__']=$obj->last_four;

    					complete_substitutions_array($substitutionarray, $langstouse, $contract);

    					$subject = make_substitutions($arraydefaultmessage->topic, $substitutionarray, $langstouse);
    					$msg     = make_substitutions($arraydefaultmessage->content, $substitutionarray, $langstouse);
    					$from = $conf->global->SELLYOURSAAS_NOREPLY_EMAIL;
    					$to = $thirdparty->email;

    					$cmail = new CMailFile($subject, $to, $from, $msg, array(), array(), array(), '', '', 0, 1);
    					$result = $cmail->sendfile();
    					if (! $result)
    					{
    						$error++;
    						$this->error = 'Failed to send email to thirdparty id = '.$thirdparty->id.' : '.$cmail->error;
    						$this->errors[] = 'Failed to send email to thirdparty id = '.$thirdparty->id.' : '.$cmail->error;
    					}
    				}
    				else
    				{
    					$error++;
    					$this->error = 'Failed to get email a valid template (AlertCreditCardExpiration)';
    					$this->errors[] = 'Failed to get email a valid template (AlertCreditCardExpiration)';
    				}
    			}

    			$i++;
    		}
    	}
    	else
    	{
    		$error++;
    		$this->error = $this->db->lasterror();
    	}

    	if (! $error)
    	{
    		$this->output = 'Found '.$num_rows.' payment mode for credit card that will expire soon (ran in mode '.$service.') (search done on SellYourSaas customers with active template invoice only)';
    	}

    	$this->db->commit();

    	$conf->global->SYSLOG_FILE = $savlog;

    	return $error;
    }


    /**
     * Action executed by scheduler.
     * Send warning when paypal preapproval will expire to sellyoursaas customers.
     * CAN BE A CRON TASK
     *
     * @param	int			$day1	Day1 in month to launch warnings (1st)
     * @param	int			$day2	Day2 in month to launch warnings (20th)
     * @return	int			0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
     */
    public function doAlertPaypalExpiration($day1='', $day2='')
    {
    	global $conf, $langs, $user;

    	$savlog = $conf->global->SYSLOG_FILE;
    	$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_doAlertPaypalExpiration.log';

    	$now = dol_now();

    	$error = 0;
    	$this->output = '';
    	$this->error='';

    	dol_syslog(__METHOD__.' - Search paypal approval that expire at end of month and send remind. Test is done the day '.$day1.' and '.$day2.' of month', LOG_DEBUG);

    	if (empty($day1) ||empty($day2))
    	{
    		$this->error = 'Bad value for parameter day1 and day2. Set param to "1, 20" for example';
    		$error++;
    		return 1;
    	}

    	$servicestatus = 1;
    	if (! empty($conf->paypal->enabled))
    	{
    		//$service = 'PaypalTest';
    		$servicestatus = 0;
    		if (! empty($conf->global->PAYPAL_LIVE) && ! GETPOST('forcesandbox','alpha') && empty($conf->global->SELLYOURSAAS_FORCE_PAYPAL_TEST))
    		{
    			//$service = 'PaypalLive';
    			$servicestatus = 1;
    		}
    	}

    	$currentdate = dol_getdate($now);
    	$currentday = $currentdate['mday'];
    	$currentmonth = $currentdate['mon'];
    	$currentyear = $currentdate['year'];

    	if ($currentday != $day1 && $currentday != $day2) {
    		$this->output = 'Nothing to do. We are not the day '.$day1.', neither the day '.$day2.' of the month';
    		return 0;
    	}

    	$this->db->begin();

    	// Get warning email template
    	include_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
    	include_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
    	$formmail=new FormMail($db);

    	$nextyear = $currentyear;
    	$nextmonth = $currentmonth + 1;
    	if ($nextmonth > 12) { $nextmonth = 1; $nextyear++; }
    	$timelessonemonth = dol_time_plus_duree($now, -1, 'm');

    	if ($timelessonemonth)
    	{
    		// Search payment modes on companies that has an active invoice template
    		$sql = 'SELECT DISTINCT sr.rowid, sr.fk_soc, sr.exp_date_month, sr.exp_date_year, sr.last_four, sr.status';
    		$sql.= ' FROM '.MAIN_DB_PREFIX.'societe_rib as sr, '.MAIN_DB_PREFIX.'societe_extrafields as se,';
    		$sql.= ' '.MAIN_DB_PREFIX.'facture_rec as fr';
    		$sql.= " WHERE sr.fk_soc = fr.fk_soc AND sr.default_rib = 1 AND sr.type = 'paypal' AND sr.status = ".$servicestatus;
    		$sql.= " AND sr.exp_date_month = ".$currentmonth." AND sr.exp_date_year = ".$currentyear;
    		$sql.= " AND se.fk_object = fr.fk_soc AND se.dolicloud = 'yesv2'";
    		$sql.= " AND fr.suspended = ".FactureRec::STATUS_NOTSUSPENDED;
    		$sql.= " AND fr.frequency > 0";

	    	$resql = $this->db->query($sql);
	    	if ($resql)
	    	{
	    		$num_rows = $this->db->num_rows($resql);
	    		$i = 0;
	    		while ($i < $num_rows)
	    		{
	    			$obj = $this->db->fetch_object($resql);

	    			$thirdparty = new Societe($this->db);
	    			$thirdparty->fetch($obj->fk_soc);
	    			if ($thirdparty->id > 0)
	    			{
	    				dol_syslog("* Process thirdparty id=".$thirdparty->id." name=".$thirdparty->nom);

	    				$langstouse = new Translate('', $conf);
	    				$langstouse->setDefaultLang($thirdparty->default_lang ? $thirdparty->default_lang : $langs->defaultlang);

	    				$arraydefaultmessage=$formmail->getEMailTemplate($this->db, 'thirdparty', $user, $langstouse, -2, 1, 'AlertPaypalApprovalExpiration');		// Templates are init into data.sql

	    				if (is_object($arraydefaultmessage) && ! empty($arraydefaultmessage->topic))
	    				{
	    					$substitutionarray=getCommonSubstitutionArray($langstouse, 0, null, $thirdparty);
	    					$substitutionarray['__PAYPAL_EXP_DATE__']=dol_print_date($obj->ending_date, 'day', $langstouse);

	    					complete_substitutions_array($substitutionarray, $langstouse, $contract);

	    					$subject = make_substitutions($arraydefaultmessage->topic, $substitutionarray, $langstouse);
	    					$msg     = make_substitutions($arraydefaultmessage->content, $substitutionarray, $langstouse);
	    					$from = $conf->global->SELLYOURSAAS_NOREPLY_EMAIL;
	    					$to = $thirdparty->email;

	    					$cmail = new CMailFile($subject, $to, $from, $msg, array(), array(), array(), '', '', 0, 1);
	    					$result = $cmail->sendfile();
	    					if (! $result)
	    					{
	    						$error++;
	    						$this->error = 'Failed to send email to thirdparty id = '.$thirdparty->id.' : '.$cmail->error;
	    						$this->errors[] = 'Failed to send email to thirdparty id = '.$thirdparty->id.' : '.$cmail->error;
	    					}
	    				}
	    				else
	    				{
	    					$error++;
	    					$this->error = 'Failed to get email a valid template AlertPaypalApprovalExpiration';
	    					$this->errors[] = 'Failed to get email a valid template AlertPaypalApprovalExpiration';
	    				}
	    			}

	    			$i++;
	    		}
	    	}
	    	else
	    	{
	    		$error++;
	    		$this->error = $this->db->lasterror();
	    	}
    	}

    	if (! $error)
    	{
    		$this->output = 'Found '.$num_rows.' record with paypal approval that will expire soon (ran in mode '.$servicestatus.')';
    	}

    	$this->db->commit();

    	$conf->global->SYSLOG_FILE = $savlog;

    	return $error;
    }


    /**
     * Action executed by scheduler
     * Loop on invoice for customer with default payment mode Stripe and take payment/send email. Unsuspend if it was suspended (done by trigger BILL_CANCEL or BILL_PAYED).
     * CAN BE A CRON TASK
     *
     * @return	int			0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
     */
    public function doTakePaymentStripe()
    {
    	global $conf, $langs, $mysoc;

    	$savlog = $conf->global->SYSLOG_FILE;
    	$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_doTakePaymentStripe.log';

    	include_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
    	include_once DOL_DOCUMENT_ROOT.'/societe/class/companypaymentmode.class.php';

    	$error = 0;
    	$this->output = '';
    	$this->error='';

    	$invoiceprocessed = array();
    	$invoiceprocessedok = array();
    	$invoiceprocessedko = array();

    	if (empty($conf->stripe->enabled))
    	{
    		$this->error='Error, stripe module not enabled';
    		return 1;
    	}

   		$service = 'StripeTest';
   		$servicestatus = 0;
   		if (! empty($conf->global->STRIPE_LIVE) && ! GETPOST('forcesandbox','alpha') && empty($conf->global->SELLYOURSAAS_FORCE_STRIPE_TEST))
   		{
   			$service = 'StripeLive';
   			$servicestatus = 1;
    	}

    	dol_syslog(__METHOD__, LOG_DEBUG);

    	$this->db->begin();

    	$sql = 'SELECT f.rowid, se.fk_object as socid, sr.rowid as companypaymentmodeid';
    	$sql.= ' FROM '.MAIN_DB_PREFIX.'facture as f, '.MAIN_DB_PREFIX.'societe_extrafields as se, '.MAIN_DB_PREFIX.'societe_rib as sr';
    	$sql.= ' WHERE sr.fk_soc = f.fk_soc';
    	$sql.= " AND f.paye = 0 AND f.type = 0 AND f.fk_statut = ".Facture::STATUS_VALIDATED;
    	$sql.= " AND sr.status = ".$servicestatus;
    	$sql.= " AND f.fk_soc = se.fk_object AND se.dolicloud = 'yesv2'";
    	$sql.= " ORDER BY f.datef ASC, sr.default_rib DESC, sr.tms DESC";		// Lines may be duplicated. Never mind, we wil exclude duplicated invoice later.
    	//print $sql;exit;

    	$resql = $this->db->query($sql);
    	if ($resql)
    	{
    		$num = $this->db->num_rows($resql);

    		$i=0;
    		while ($i < $num)
    		{
    			$obj = $this->db->fetch_object($resql);
    			if ($obj)
    			{
    				if (! empty($invoiceprocessed[$obj->rowid])) continue;		// Invoice already processed

    				$invoice = new Facture($this->db);
    				$result1 = $invoice->fetch($obj->rowid);

    				$companypaymentmode = new CompanyPaymentMode($this->db);
    				$result2 = $companypaymentmode->fetch($obj->companypaymentmodeid);

	    			if ($result1 <= 0 || $result2 <= 0)
	    			{
	    				$error++;
	    				dol_syslog('Failed to get invoice id = '.$invoice_id.' or companypaymentmode id ='.$companypaymentmodeid, LOG_ERR);
	    				$this->errors[] = 'Failed to get invoice id = '.$invoice_id.' or companypaymentmode id ='.$companypaymentmodeid;
	    			}
    				else
    				{
    					dol_syslog("* Process invoice id=".$invoice->id." ref=".$invoice->ref);

    					$result = $this->doTakePaymentStripeForThirdparty($service, $servicestatus, $obj->socid, $companypaymentmode, $invoice, 0);
						if ($result == 0)	// No error
						{
							$invoiceprocessedok[$obj->rowid]=$invoice->ref;
						}
						else
						{
							$invoiceprocessedko[$obj->rowid]=$invoice->ref;
						}
    				}

    				$invoiceprocessed[$obj->rowid]=$invoice->ref;
    			}

    			$i++;
    		}
    	}
    	else
    	{
    		$error++;
    		$this->error = $this->db->lasterror();
    	}

    	$this->output = count($invoiceprocessedok).' invoice(s) paid among '.count($invoiceprocessed).' qualified invoice(s) with a valid default payment mode processed'.(count($invoiceprocessed)>0 ? ' : '.join(',', $invoiceprocessed) : '').' (ran in mode '.$servicestatus.') (search done on SellYourSaas customers only)';
    	$this->output .= ' - '.count($invoiceprocessedko).' discarded (missing stripe customer/card id or other reason)';

    	$this->db->commit();

    	$conf->global->SYSLOG_FILE = $savlog;

    	return $error;
    }


    /**
     * doTakePaymentStripeForThirdparty
     * Take payment/send email. Unsuspend if it was suspended (done by trigger BILL_CANCEL or BILL_PAYED).
     *
     * @param	int		$service					'StripeTest' or 'StripeLive'
     * @param	int		$servicestatus				Service 0 or 1
     * @param	int		$thirdparty_id				Thirdparty id
     * @param	int		$companypaymentmode			Company payment mode id
     * @param	int		$invoice					null=All invoices of thirdparty, Invoice=Only this invoice
     * @param	int		$includedraft				Include draft invoices
     * @param	int		$noemailtocustomeriferror	No email sent to customer if there is a payment error (can be used when error is already reported on screen)
     * @return	int									0 if no error, >0 if error
     */
    function doTakePaymentStripeForThirdparty($service, $servicestatus, $thirdparty_id, $companypaymentmode, $invoice=null, $includedraft=0, $noemailtocustomeriferror=0)
    {
    	global $conf, $mysoc, $user, $langs;

    	$error = 0;

    	dol_syslog("doTakePaymentStripeForThirdparty thirdparty_id=".$thirdparty_id);

    	$this->stripechargedone = 0;
    	$now = dol_now();

    	// Check parameters
    	if (empty($thirdparty_id))
    	{
    		$this->errors[]='Empty parameter thirdparty_id when calling doTakePaymentStripeForThirdparty';
    		return 1;
    	}

    	$currency = $conf->currency;
    	$cardstripe = $companypaymentmode->stripe_ref_card;

    	$invoices=array();
    	if (empty($invoice))
    	{
    		$sql = 'SELECT f.rowid, f.fk_statut';
    		$sql.= ' FROM '.MAIN_DB_PREFIX.'facture as f, '.MAIN_DB_PREFIX.'societe as s';
    		$sql.= ' WHERE f.fk_soc = s.rowid';
    		$sql.= " AND f.paye = 0 AND f.type = 0";
    		if ($includedraft)
    		{
    			$sql.= " AND f.fk_statut in (".Facture::STATUS_DRAFT.", ".Facture::STATUS_VALIDATED.")";
    		}
    		else
    		{
    			$sql.= " AND f.fk_statut = ".Facture::STATUS_VALIDATED;
    		}
    		$sql.= " AND s.rowid = ".$thirdparty_id;
    		$sql.= " ORDER BY f.datef ASC";
    		//print $sql;

    		$resql = $this->db->query($sql);
    		if ($resql)
    		{
    			$num = $this->db->num_rows($resql);

    			$i=0;
    			while ($i < $num)
    			{
    				$obj = $this->db->fetch_object($resql);
    				if ($obj)
    				{
    					$invoice = new Facture($this->db);
    					$result = $invoice->fetch($obj->rowid);
    					if ($result > 0)
    					{
    						if ($invoice->statut == Facture::STATUS_DRAFT)
    						{
    							$user->rights->facture->creer = 1;		// Force permission to user to validate invoices
    							$user->rights->facture->invoice_advance->validate = 1;

    							$result = $invoice->validate($user);

    							// TODO Build PDF

    						}
    					}
    					else
    					{
   							$error++;
   							$this->errors[] = 'Failed to load invoice with id='.$obj->rowid;
    					}
    					if ($result > 0)
    					{
    						$invoices[] = $invoice;
    					}
    				}
    				$i++;
    			}
    		}
    	}
    	else
    	{
    		$invoices[] = $invoice;
    	}
		if (count($invoices) == 0)
		{
			dol_syslog("No qualified invoices found for thirdparty_id = ".$thirdparty_id);
		}

		dol_syslog("We found ".count($invoices).' qualified invoices to process payment on (ran in mode '.$servicestatus.').');

		// Loop on each invoice
		foreach($invoices as $invoice)
		{
			dol_syslog("--- Process invoice thirdparty_id = ".$thirdparty_id.", id=".$invoice->id.", ref=".$invoice->ref, LOG_DEBUG);
			$invoice->fetch_thirdparty();

			$alreadypayed = $invoice->getSommePaiement();
    		$amount_credit_notes_included = $invoice->getSumCreditNotesUsed();
    		$amounttopay = $invoice->total_ttc - $alreadypayed - $amount_credit_notes_included;

    		// Correct the amount according to unit of currency
    		// See https://support.stripe.com/questions/which-zero-decimal-currencies-does-stripe-support
    		$arrayzerounitcurrency=array('BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF');
    		$amountstripe=$amounttopay;
    		if (! in_array($currency, $arrayzerounitcurrency)) $amountstripe=$amountstripe * 100;

    		if ($amountstripe > 0)
    		{
    			try {
//    				var_dump($companypaymentmode);
    				dol_syslog("Search existing Stripe card for companypaymentmodeid=".$companypaymentmode->id." stripe_card_ref=".$companypaymentmode->stripe_card_ref." mode of payment mode=".$companypaymentmode->status, LOG_DEBUG);

    				$thirdparty = new Societe($this->db);
    				$resultthirdparty = $thirdparty->fetch($thirdparty_id);

    				include_once DOL_DOCUMENT_ROOT.'/stripe/class/stripe.class.php';
    				$stripe = new Stripe($this->db);
    				$stripeacc = $stripe->getStripeAccount($service);								// Get Stripe OAuth connect account if it exists (no network access here)

    				$customer = $stripe->customerStripe($thirdparty, $stripeacc, $servicestatus, 0);

    				if ($resultthirdparty > 0 && ! empty($customer))
    				{
    					$stripecard = $stripe->cardStripe($customer, $companypaymentmode, $stripeacc, $servicestatus, 0);
    					if ($stripecard)
    					{
    						$FULLTAG='INV='.$invoice->id.'-CUS='.$thirdparty->id;
    						$description='Stripe payment from doTakePaymentStripeForThirdparty: '.$FULLTAG;

    						$stripefailurecode='';
    						$stripefailuremessage='';

    						dol_syslog("Create charge on card ".$stripecard->id, LOG_DEBUG);
    						try {
	    						$charge = \Stripe\Charge::create(array(
		    						'amount'   => price2num($amountstripe, 'MU'),
		    						'currency' => $currency,
		    						'capture'  => true,							// Charge immediatly
		    						'description' => $description,
		    						'metadata' => array("FULLTAG" => $FULLTAG, 'Recipient' => $mysoc->name, 'dol_version'=>DOL_VERSION, 'dol_entity'=>$conf->entity, 'ipaddress'=>(empty($_SERVER['REMOTE_ADDR'])?'':$_SERVER['REMOTE_ADDR'])),
	    							'customer' => $customer->id,
	    							//'customer' => 'bidon_to_force_error',		// To use to force a stripe error
		    						'source' => $stripecard,
		    						'statement_descriptor' => dol_trunc(dol_trunc(dol_string_unaccent($mysoc->name), 6, 'right', 'UTF-8', 1).' '.$FULLTAG, 22, 'right', 'UTF-8', 1)     // 22 chars that appears on bank receipt
	    						));
    						}
    						catch(\Stripe\Error\Card $e) {
    							// Since it's a decline, Stripe_CardError will be caught
    							$body = $e->getJsonBody();
    							$err  = $body['error'];

    							$stripefailurecode = $err['code'];
    							$stripefailuremessage = $err['message'];
    						}
    						catch(Exception $e)
    						{
    							$stripefailurecode='UnknownChargeError';
    							$stripefailuremessage=$e->getMessage();
    						}

    						// Return $charge = array('id'=>'ch_XXXX', 'status'=>'succeeded|pending|failed', 'failure_code'=>, 'failure_message'=>...)
    						if (empty($charge) || $charge->status == 'failed')
    						{
    							dol_syslog('Failed to charge card '.$stripecard->id.' stripefailurecode='.$stripefailurecode.' stripefailuremessage='.$stripefailuremessage, LOG_WARNING);

    							$error++;
    							$errmsg='Failed to charge card';
    							if (! empty($charge))
    							{
    								$errmsg.=': failure_code='.$charge->failure_code;
    								$errmsg.=($charge->failure_message?' - ':'').' failure_message='.$charge->failure_message;
    								if (empty($stripefailurecode))    $stripefailurecode = $charge->failure_code;
    								if (empty($stripefailuremessage)) $stripefailuremessage = $charge->failure_message;
    							}
    							else
    							{
    								$errmsg.=': '.$stripefailurecode.' - '.$stripefailuremessage;
    							}

    							$description='Stripe payment ERROR from doTakePaymentStripeForThirdparty: '.$FULLTAG;
    							$postactionmessages[]=$errmsg;
    							$this->errors[]=$errmsg;
    						}
    						else
    						{
    							dol_syslog('Successfuly charge card '.$stripecard->id);

    							// Save a stripe payment was done in realy life so later we will be able to force a commit on recorded payments
    							// even if in batch mode (method doTakePaymentStripe), we will always make all action in one transaction with a forced commit.
    							$this->stripechargedone++;

    							$description='Stripe payment OK from doTakePaymentStripeForThirdparty: '.$FULLTAG;
    							$postactionmessages=array();

    							$db=$this->db;
    							$ipaddress = (empty($_SERVER['REMOTE_ADDR'])?'':$_SERVER['REMOTE_ADDR']);
    							$TRANSACTIONID = $charge->id;
    							$currency=$conf->currency;
    							$paymentmethod='stripe';
    							$emetteur_name = $charge->customer;

    							// Same code than into paymentok.php...

    							$paymentTypeId = 0;
    							if ($paymentmethod == 'paybox') $paymentTypeId = $conf->global->PAYBOX_PAYMENT_MODE_FOR_PAYMENTS;
    							if ($paymentmethod == 'paypal') $paymentTypeId = $conf->global->PAYPAL_PAYMENT_MODE_FOR_PAYMENTS;
    							if ($paymentmethod == 'stripe') $paymentTypeId = $conf->global->STRIPE_PAYMENT_MODE_FOR_PAYMENTS;
    							if (empty($paymentTypeId))
    							{
    								$paymentType = $_SESSION["paymentType"];
    								if (empty($paymentType)) $paymentType = 'CB';
    								$paymentTypeId = dol_getIdFromCode($this->db, $paymentType, 'c_paiement', 'code', 'id', 1);
    							}

    							$currencyCodeType = $currency;

    							$ispostactionok = 1;

    							// Creation of payment line
    							include_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
    							$paiement = new Paiement($this->db);
    							$paiement->datepaye     = $now;
    							$paiement->date         = $now;
    							if ($currencyCodeType == $conf->currency)
    							{
    								$paiement->amounts      = array($invoice->id => $amounttopay);   // Array with all payments dispatching with invoice id
    							}
    							else
    							{
    								$paiement->multicurrency_amounts = array($invoice->id => $amounttopay);   // Array with all payments dispatching

    								$postactionmessages[] = 'Payment was done in a different currency that currency expected of company';
    								$ispostactionok = -1;
    								$error++;	// Not yet supported
    							}
    							$paiement->paiementid   = $paymentTypeId;
    							$paiement->num_paiement = '';
    							$paiement->note_public  = 'Online payment '.dol_print_date($now, 'standard').' using '.$paymentmethod.' from '.$ipaddress.' - Transaction ID = '.$TRANSACTIONID;

    							if (! $error)
    							{
    								dol_syslog('Create payment');

    								$paiement_id = $paiement->create($user, 1);    // This include closing invoices and regenerating documents
    								if ($paiement_id < 0)
    								{
    									$postactionmessages[] = $paiement->error.' '.join("<br>\n", $paiement->errors);
    									$ispostactionok = -1;
    									$error++;
    								}
    								else
    								{
    									$postactionmessages[] = 'Payment created';
    								}
    							}

    							if (! $error && ! empty($conf->banque->enabled))
    							{
    								dol_syslog('addPaymentToBank');

    								$bankaccountid = 0;
    								if ($paymentmethod == 'paybox') $bankaccountid = $conf->global->PAYBOX_BANK_ACCOUNT_FOR_PAYMENTS;
    								if ($paymentmethod == 'paypal') $bankaccountid = $conf->global->PAYPAL_BANK_ACCOUNT_FOR_PAYMENTS;
    								if ($paymentmethod == 'stripe') $bankaccountid = $conf->global->STRIPE_BANK_ACCOUNT_FOR_PAYMENTS;

    								if ($bankaccountid > 0)
    								{
    									$label='(CustomerInvoicePayment)';
    									if ($invoice->type == Facture::TYPE_CREDIT_NOTE) $label='(CustomerInvoicePaymentBack)';  // Refund of a credit note
    									$result=$paiement->addPaymentToBank($user, 'payment', $label, $bankaccountid, $emetteur_name, '');
    									if ($result < 0)
    									{
    										$postactionmessages[] = $paiement->error.' '.joint("<br>\n", $paiement->errors);
    										$ispostactionok = -1;
    										$error++;
    									}
    									else
    									{
    										$postactionmessages[] = 'Bank entry of payment created';
    									}
    								}
    								else
    								{
    									$postactionmessages[] = 'Setup of bank account to use in module '.$paymentmethod.' was not set. Not way to record the payment.';
    									$ispostactionok = -1;
    									$error++;
    								}
    							}

    							if ($ispostactionok < 1)
    							{
    								$description='Stripe payment OK but post action KO from doTakePaymentStripeForThirdparty: '.$FULLTAG;
    							}
    							else
    							{
    								$description='Stripe payment+post action OK from doTakePaymentStripeForThirdparty: '.$FULLTAG;
    							}
    						}

    						$object = $invoice;

    						dol_syslog("Send email with result of payment");

    						// Send email
    						include_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
    						$formmail=new FormMail($this->db);
    						// Set output language
    						$outputlangs = new Translate('', $conf);
    						$outputlangs->setDefaultLang(empty($object->thirdparty->default_lang) ? $mysoc->default_lang : $object->thirdparty->default_lang);
    						$outputlangs->loadLangs(array("main", "members", "bills"));
    						// Get email content from templae
    						$arraydefaultmessage=null;

    						$sendemailtocustomer = 1;

    						if (empty($charge) || $charge->status == 'failed')
    						{
    							$labeltouse = 'InvoicePaymentFailure';
    							if ($noemailtocustomeriferror) $sendemailtocustomer = 0;
    						}
    						else
    						{
    							$labeltouse = 'InvoicePaymentSuccess';
    						}

    						if ($sendemailtocustomer)
    						{
	    						if (! empty($labeltouse)) $arraydefaultmessage=$formmail->getEMailTemplate($this->db, 'facture_send', $user, $outputlangs, 0, 1, $labeltouse);

	    						if (! empty($labeltouse) && is_object($arraydefaultmessage) && $arraydefaultmessage->id > 0)
	    						{
	    							$subject = $arraydefaultmessage->topic;
	    							$msg     = $arraydefaultmessage->content;
	    						}

	    						$substitutionarray=getCommonSubstitutionArray($outputlangs, 0, null, $object);
	    						$substitutionarray['__SELLYOURSAAS_PAYMENT_ERROR_DESC__']=$stripefailurecode.' '.$stripefailuremessage;
	    						complete_substitutions_array($substitutionarray, $outputlangs, $object);
	    						$subjecttosend = make_substitutions($subject, $substitutionarray, $outputlangs);
	    						$texttosend = make_substitutions($msg, $substitutionarray, $outputlangs);

	    						// Attach a file ?
	    						$file='';
	    						$listofpaths=array();
	    						$listofnames=array();
	    						$listofmimes=array();
	    						if (is_object($invoice))
	    						{
	    							$invoicediroutput = $conf->facture->dir_output;
	    							$fileparams = dol_most_recent_file($invoicediroutput . '/' . $invoice->ref, preg_quote($invoice->ref, '/').'[^\-]+');
	    							$file = $fileparams['fullname'];

	    							$listofpaths=array($file);
	    							$listofnames=array(basename($file));
	    							$listofmimes=array(dol_mimetype($file));
	    						}
	    						$from = $conf->global->SELLYOURSAAS_NOREPLY_EMAIL;

	    						// Send email (substitutionarray must be done just before this)
	    						include_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
	    						$mailfile = new CMailFile($subjecttosend, $invoice->thirdparty->email, $from, $texttosend, $filename_list, $mimetype_list, $mimefilename_list, '', '', 0, -1);
	    						if ($mailfile->sendfile())
	    						{
	    							$result = 1;
	    						}
	    						else
	    						{
	    							$this->error=$langs->trans("ErrorFailedToSendMail", $from, $invoice->thirdparty->email).'. '.$mailfile->error;
	    							$result = -1;
	    						}

	    						if ($result < 0)
	    						{
	    							$errmsg=$this->error;
	    							$postactionmessages[] = $errmsg;
	    							$ispostactionok = -1;
	    						}
	    						else
	    						{
	    							if ($file) $postactionmessages[] = 'Email sent to thirdparty (with invoice document attached)';
	    							else $postactionmessages[] = 'Email sent to thirdparty (without any attached document)';
	    						}
    						}

    						// Track an event
    						if (empty($charge) || $charge->status == 'failed')
    						{
    							$actioncode='PAYMENT_STRIPE_KO';
    							$extraparams=$stripefailurecode.' '.$stripefailuremessage;
    						}
    						else
    						{
    							$actioncode='PAYMENT_STRIPE_OK';
    							$extraparams='';
    						}

    						// Insert record of payment error
    						$actioncomm = new ActionComm($this->db);

    						$actioncomm->type_code   = 'AC_OTH_AUTO';		// Type of event ('AC_OTH', 'AC_OTH_AUTO', 'AC_XXX'...)
    						$actioncomm->code        = 'AC_'.$actioncode;
    						$actioncomm->label       = $description;
    						$actioncomm->note        = join(',', $postactionmessages);
    						$actioncomm->fk_project  = $invoice->fk_project;
    						$actioncomm->datep       = $now;
    						$actioncomm->datef       = $now;
    						$actioncomm->percentage  = -1;   // Not applicable
    						$actioncomm->socid       = $thirdparty->id;
    						$actioncomm->contactid   = 0;
    						$actioncomm->authorid    = $user->id;   // User saving action
    						$actioncomm->userownerid = $user->id;	// Owner of action
    						// Fields when action is en email (content should be added into note)
    						/*$actioncomm->email_msgid = $object->email_msgid;
    						 $actioncomm->email_from  = $object->email_from;
    						 $actioncomm->email_sender= $object->email_sender;
    						 $actioncomm->email_to    = $object->email_to;
    						 $actioncomm->email_tocc  = $object->email_tocc;
    						 $actioncomm->email_tobcc = $object->email_tobcc;
    						 $actioncomm->email_subject = $object->email_subject;
    						 $actioncomm->errors_to   = $object->errors_to;*/
    						$actioncomm->fk_element  = $invoice->id;
    						$actioncomm->elementtype = $invoice->element;
    						$actioncomm->extraparams = $extraparams;

    						$actioncomm->create($user);
    					}
    					else
    					{
    						$error++;
    						dol_syslog("No card found for this stripe customer ".$customer->id, LOG_WARNING);
    						$this->errors[]='Failed to get card for stripe customer = '.$customer->id;
    					}
    				} else {
    					if ($resultthirdparty <= 0)
    					{
    						dol_syslog('Failed to load customer for thirdparty_id = '.$thirdparty->id, LOG_WARNING);
    						$this->errors[]='Failed to load customer for thirdparty_id = '.$thirdparty->id;
    					}
    					else 		// $customer stripe not found
    					{
    						dol_syslog('Failed to get Stripe customer id for thirdparty_id = '.$thirdparty->id." in mode ".$servicestatus, LOG_WARNING);
    						$this->errors[]='Failed to get Stripe customer id for thirdparty_id = '.$thirdparty->id." in mode ".$servicestatus;
    					}
    					$error++;
    				}
    			}
    			catch(Exception $e)
    			{
    				$error++;
    				dol_syslog('Error '.$e->getMessage(), LOG_ERR);
    				$this->errors[]='Error '.$e->getMessage();
    			}
    		}
    		else
    		{
    			$error++;
    			dol_syslog("Remain to pay is null for this invoice".$customer->id.". Why is the invoice not classified 'Paid' ?", LOG_WARNING);
    			$this->errors[]="Remain to pay is null for this invoice = ".$customer->id.". Why is the invoice not classified 'Paid' ?";
    		}
		}

		// Payments are processed, and next batch will be to make renewal

    	return $error;
    }


    /**
     * Action executed by scheduler
     * Loop on invoice for customer with default payment mode Paypal and take payment. Unsuspend if it was suspended.
     * CAN BE A CRON TASK
     *
     * @return	int			0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
     */
    public function doTakePaymentPaypal()
    {
    	global $conf, $langs;

    	$savlog = $conf->global->SYSLOG_FILE;
    	$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_doTakePaymentPaypal.log';

    	$error = 0;
    	$this->output = '';
    	$this->error='';

    	dol_syslog(__METHOD__, LOG_DEBUG);

    	$this->db->begin();

    	// ...

    	//$this->output = count($invoiceprocessed).' validated invoice with a valid default payment mode processed'.(count($invoiceprocessed)>0 ? ' : '.join(',', $invoiceprocessed) : '');
    	$this->output = 'Not implemented yet';

    	$this->db->commit();

    	// Payments are processed, and next batch will be to make renewal

    	$conf->global->SYSLOG_FILE = $savlog;

    	return $error;
    }




    /**
     * Action executed by scheduler
     * CAN BE A CRON TASK
     * Loop on each contract. If it is a paid contract, and there is no unpaid invoice for contract, and end date < today + 2 days (so expired or soon expired),
     * we update qty of contract + qty of linked template invoice + the running contract service end date to end at next period.
     *
     * @param	int		$thirdparty_id			Thirdparty id
     * @return	int								0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
     */
    public function doRenewalContracts($thirdparty_id=0)
    {
    	global $conf, $langs, $user;

    	$savlog = $conf->global->SYSLOG_FILE;
    	$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_doRenewalContracts.log';

    	$now = dol_now();

    	$mode = 'paid';
    	$delayindaysshort= 2;	// So we let 2 chance to generate and validate invoice before
    	$enddatetoscan = dol_time_plus_duree($now, abs($delayindaysshort), 'd');		// $enddatetoscan = yesterday

    	$error = 0;
    	$this->output = '';
    	$this->error='';

    	$contractprocessed = array();
    	$contractignored = array();
    	$contracterror = array();

    	dol_syslog(__METHOD__, LOG_DEBUG);

    	$sql = 'SELECT c.rowid, c.ref_customer, cd.rowid as lid, cd.date_fin_validite';
    	$sql.= ' FROM '.MAIN_DB_PREFIX.'contrat as c, '.MAIN_DB_PREFIX.'contratdet as cd, '.MAIN_DB_PREFIX.'contrat_extrafields as ce,';
    	$sql.= ' '.MAIN_DB_PREFIX.'societe_extrafields as se';
    	$sql.= ' WHERE cd.fk_contrat = c.rowid AND ce.fk_object = c.rowid';
    	$sql.= " AND ce.deployment_status = 'done'";
    	//$sql.= " AND cd.date_fin_validite < '".$this->db->idate(dol_time_plus_duree($now, abs($delayindaysshort), 'd'))."'";
    	//$sql.= " AND cd.date_fin_validite > '".$this->db->idate(dol_time_plus_duree($now, abs($delayindayshard), 'd'))."'";
    	$sql.= " AND date_format(cd.date_fin_validite, '%Y-%m-%d') <= date_format('".$this->db->idate($enddatetoscan)."', '%Y-%m-%d')";
    	$sql.= " AND cd.statut = 4";
    	$sql.= " AND c.fk_soc = se.fk_object AND se.dolicloud = 'yesv2'";
    	if ($thirdparty_id > 0) $sql.=" AND c.fk_soc = ".$thirdparty_id;
    	//print $sql;

    	$resql = $this->db->query($sql);
    	if ($resql)
    	{
    		$num = $this->db->num_rows($resql);

    		include_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';

    		$i=0;
    		while ($i < $num)
    		{
    			$obj = $this->db->fetch_object($resql);
    			if ($obj)
    			{
    				if (! empty($contractprocessed[$obj->rowid]) || ! empty($contractignored[$obj->rowid]) || ! empty($contracterror[$obj->rowid])) continue;

    				// Test if this is a paid or not instance
    				$object = new Contrat($this->db);
    				$object->fetch($obj->rowid);		// fetch also lines
    				$object->fetch_thirdparty();

    				if ($object->id <= 0)
    				{
    					$error++;
    					$this->errors[] = 'Failed to load contract with id='.$obj->rowid;
    					continue;
    				}

    				dol_syslog("* Process contract id=".$object->id." ref=".$object->ref." ref_customer=".$object->ref_customer);

    				dol_syslog('Call sellyoursaasIsPaidInstance', LOG_DEBUG, 1);
    				$isAPayingContract = sellyoursaasIsPaidInstance($object);
    				dol_syslog('', 0, -1);
    				if ($mode == 'test' && $isAPayingContract)
    				{
    					$contractignored[$object->id]=$object->ref;
    					continue;											// Discard if this is a paid instance when we are in test mode
    				}
    				if ($mode == 'paid' && ! $isAPayingContract)
    				{
    					$contractignored[$object->id]=$object->ref;
    					continue;											// Discard if this is a test instance when we are in paid mode
    				}

    				// Update expiration date of instance
    				dol_syslog('Call sellyoursaasGetExpirationDate', LOG_DEBUG, 1);
    				$tmparray = sellyoursaasGetExpirationDate($object);
    				dol_syslog('', 0, -1);
    				$expirationdate = $tmparray['expirationdate'];
    				$duration_value = $tmparray['duration_value'];
    				$duration_unit = $tmparray['duration_unit'];
    				//var_dump($expirationdate.' '.$enddatetoscan);

    				// Test if there is pending invoice
    				$object->fetchObjectLinked();

    				if (is_array($object->linkedObjects['facture']) && count($object->linkedObjects['facture']) > 0)
    				{
    					usort($object->linkedObjects['facture'], "cmp");

    					//dol_sort_array($contract->linkedObjects['facture'], 'date');
    					$someinvoicenotpaid=0;
    					foreach($object->linkedObjects['facture'] as $idinvoice => $invoice)
    					{
    						if ($invoice->status == Facture::STATUS_DRAFT) continue;	// Draft invoice are not invoice not paid

    						if (empty($invoice->paye))
    						{
    							$someinvoicenotpaid++;
    						}
    					}
    					if ($someinvoicenotpaid)
    					{
    						$this->output .= 'Contract '.$object->ref.' is qualified for renewal but there is '.$someinvoicenotpaid.' invoice(s) unpayed so we cancel renewal'."\n";
    						$contractignored[$object->id]=$object->ref;
    						continue;
    					}
    				}

    				if ($expirationdate && $expirationdate < $enddatetoscan)
    				{
    					$newdate = $expirationdate;
    					$protecti=0;	//$protecti is to avoid infinite loop
    					while ($newdate < $enddatetoscan && $protecti < 1000)
    					{
    						$newdate = dol_time_plus_duree($newdate, $duration_value, $duration_unit);
    						$protecti++;
    					}

    					if ($protecti < 1000)	// If not, there is a pb
    					{
    						// We will update the end of date of contrat, so first we refresh contract data
    						dol_syslog("We will update the end of date of contract with newdate=".dol_print_date($newdate, 'dayhourrfc')." but first, we update qty of resources by a remote action refresh.");

    						$this->db->begin();

    						$errorforlocaltransaction = 0;

    						// First launch update of resources: This update status of install.lock+authorized key and update qty of contract lines + linked template invoice
    						$result = $this->sellyoursaasRemoteAction('refresh', $object);
    						if ($result <= 0)
    						{
    							$contracterror[$object->id]=$object->ref;

    							$error++;
    							$errorforlocaltransaction++;
    							$this->error=$this->error;
    							$this->errors=$this->errors;
    						}
							else
    						{
	    						$sqlupdate = 'UPDATE '.MAIN_DB_PREFIX."contratdet SET date_fin_validite = '".$this->db->idate($newdate)."'";
	    						$sqlupdate.= ' WHERE fk_contrat = '.$object->id;
	    						$resqlupdate = $this->db->query($sqlupdate);
	    						if ($resqlupdate)
	    						{
	    							$contractprocessed[$object->id]=$object->ref;

	    							$action = 'RENEW_CONTRACT';
	    							$now = dol_now();

	    							// Create an event
	    							$actioncomm = new ActionComm($this->db);
	    							$actioncomm->type_code   = 'AC_OTH_AUTO';		// Type of event ('AC_OTH', 'AC_OTH_AUTO', 'AC_XXX'...)
	    							$actioncomm->code        = 'AC_'.$action;
	    							$actioncomm->label       = 'Renewal of contrat '.$object->ref;
	    							$actioncomm->datep       = $now;
	    							$actioncomm->datef       = $now;
	    							$actioncomm->percentage  = -1;   // Not applicable
	    							$actioncomm->socid       = $contract->thirdparty->id;
	    							$actioncomm->authorid    = $user->id;   // User saving action
	    							$actioncomm->userownerid = $user->id;	// Owner of action
	    							$actioncomm->fk_element  = $object->id;
	    							$actioncomm->elementtype = 'contract';
	    							$ret=$actioncomm->create($user);       // User creating action
	    						}
	    						else
	    						{
	    							$contracterror[$object->id]=$object->ref;

	    							$error++;
	    							$errorforlocaltransaction++;
	    							$this->error = $this->db->lasterror();
	    						}
    						}

    						if (! $errorforlocaltransaction)
    						{
    							$this->db->commit();
    						}
    						else
    						{
    							$this->db->rollback();
    						}
    					}
    					else
    					{
    						$error++;
    						$this->error = "Bad value for newdate";
    						dol_syslog("Bad value for newdate", LOG_ERR);
    					}
    				}
    			}
    			$i++;
    		}
    	}
    	else
    	{
    		$error++;
    		$this->error = $this->db->lasterror();
    	}

    	$this->output .= count($contractprocessed).' paying contract(s) with end date before '.dol_print_date($enddatetoscan, 'day').' were renewed'.(count($contractprocessed)>0 ? ' : '.join(',', $contractprocessed) : '').' (search done on contracts of SellYourSaas customers only)';

    	$conf->global->SYSLOG_FILE = $savlog;

    	return ($error ? 1: 0);
    }




    /**
     * Action executed by scheduler
   	 * Suspend expired services of test instances if we are after planned end date (+ grace offset SELLYOURSAAS_NBDAYS_AFTER_EXPIRATION_BEFORE_TRIAL_SUSPEND)
     * CAN BE A CRON TASK
     *
     * @return	int			0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
     */
    public function doSuspendExpiredTestInstances()
    {
    	global $conf, $langs;

    	$savlog = $conf->global->SYSLOG_FILE;
    	$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_doSuspendExpiredTestInstances.log';

    	dol_syslog(__METHOD__, LOG_DEBUG);
    	$result = $this->doSuspendInstances('test');

    	$conf->global->SYSLOG_FILE = $savlog;

    	return $result;
    }

    /**
     * Action executed by scheduler
   	 * Suspend expired services of paid instances if we are after planned end date (+ grace offset in SELLYOURSAAS_NBDAYS_AFTER_EXPIRATION_BEFORE_PAID_SUSPEND)
     * CAN BE A CRON TASK
     *
     * @return	int			0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
     */
    public function doSuspendExpiredRealInstances()
    {
    	global $conf, $langs;

    	$savlog = $conf->global->SYSLOG_FILE;
    	$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_doSuspendExpiredRealInstances.log';

    	dol_syslog(__METHOD__, LOG_DEBUG);
    	$result = $this->doSuspendInstances('paid');

    	$conf->global->SYSLOG_FILE = $savlog;

    	return $result;
    }


   	/**
   	 * Called by batch only: doSuspendExpiredTestInstances or doSuspendExpiredRealInstances
   	 * It set the status of services to "offline" and send an email to wen the customer.
   	 * Note: An instance can also be suspended from backoffice by setting service to "offline". In such a case, no email is sent.
   	 *
   	 * @param	string	$mode		'test' or 'paid'
   	 * @return	int					0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
   	 */
   	private function doSuspendInstances($mode)
   	{
    	global $conf, $langs, $user;

    	if ($mode != 'test' && $mode != 'paid')
    	{
    		$this->error = 'Function doSuspendInstances called with bad value for parameter $mode';
    		return -1;
    	}

    	$langs->load("sellyoursaas");

    	$error = 0;
    	$erroremail = '';
    	$this->output = '';
    	$this->error='';

    	$gracedelay=9999999;
    	if ($mode == 'test') $gracedelay=$conf->global->SELLYOURSAAS_NBDAYS_AFTER_EXPIRATION_BEFORE_TRIAL_SUSPEND;
    	if ($mode == 'paid') $gracedelay=$conf->global->SELLYOURSAAS_NBDAYS_AFTER_EXPIRATION_BEFORE_PAID_SUSPEND;
    	if ($gracedelay < 1)
    	{
    		$this->error='BadValueForDelayBeforeSuspensionCheckSetup';
    		return -1;
    	}

    	dol_syslog(get_class($this)."::doSuspendInstances suspend expired instance in mode ".$mode." with grace delay of ".$gracedelay);

    	$now = dol_now();
    	$datetotest = dol_time_plus_duree($now, -1 * abs($gracedelay), 'd');

    	$this->db->begin();

    	$sql = 'SELECT c.rowid, c.ref_customer, cd.rowid as lid';
    	$sql.= ' FROM '.MAIN_DB_PREFIX.'contrat as c, '.MAIN_DB_PREFIX.'contratdet as cd, '.MAIN_DB_PREFIX.'contrat_extrafields as ce,';
    	$sql.= ' '.MAIN_DB_PREFIX.'societe_extrafields as se';
    	$sql.= ' WHERE cd.fk_contrat = c.rowid AND ce.fk_object = c.rowid';
    	$sql.= " AND ce.deployment_status = 'done'";
    	//$sql.= " AND cd.date_fin_validite < '".$this->db->idate(dol_time_plus_duree($now, 1, 'd'))."'";
    	$sql.= " AND cd.date_fin_validite < '".$this->db->idate($datetotest)."'";
    	$sql.= " AND cd.statut = 4";
    	$sql.= " AND se.fk_object = c.fk_soc AND se.dolicloud = 'yesv2'";

    	$resql = $this->db->query($sql);
    	if ($resql)
    	{
    		$num = $this->db->num_rows($resql);

    		$contractprocessed = array();

    		$i=0;
    		while ($i < $num)
    		{
				$obj = $this->db->fetch_object($resql);

				if ($obj)
				{
					if (! empty($contractprocessed[$obj->rowid])) continue;

					// Test if this is a paid or not instance
					$object = new Contrat($this->db);
					$object->fetch($obj->rowid);

					if ($object->id <= 0)
					{
						$error++;
						$this->errors[] = 'Failed to load contract with id='.$obj->rowid;
						continue;
					}

					dol_syslog("* Process contract id=".$object->id." ref=".$object->ref." ref_customer=".$object->ref_customer);

					dol_syslog('Call sellyoursaasIsPaidInstance', LOG_DEBUG, 1);
					$isAPayingContract = sellyoursaasIsPaidInstance($object);
					dol_syslog('', 0, -1);
					if ($mode == 'test' && $isAPayingContract)
					{
						dol_syslog("It is a paying contract, it will not be processed by this batch");
						continue;											// Discard if this is a paid instance when we are in test mode
					}
					if ($mode == 'paid' && ! $isAPayingContract)
					{
						dol_syslog("It is not a paying contract, it will not be processed by this batch");
						continue;											// Discard if this is a test instance when we are in paid mode
					}

					// Suspend instance
					dol_syslog('Call sellyoursaasGetExpirationDate', LOG_DEBUG, 1);
					$tmparray = sellyoursaasGetExpirationDate($object);
					dol_syslog('', 0, -1);
					$expirationdate = $tmparray['expirationdate'];

					if ($expirationdate && $expirationdate < $now)
					{
						//$object->array_options['options_deployment_status'] = 'suspended';
						$result = $object->closeAll($user, 0, 'Closed by batch doSuspendInstances the '.dol_print_date($now, 'dayhourrfc'));			// This may execute trigger that make remote actions to suspend instance
						if ($result < 0)
						{
							$error++;
							$this->error = $object->error;
							$this->errors += $object->errors;
						}
						else
						{
							$contractprocessed[$object->id]=$object->ref;

							// Send an email to warn customer of suspension
							if ($mode == 'test')
							{
								$labeltemplate = 'CustomerAccountSuspendedTrial';
							}
							if ($mode == 'paid')
							{
								$labeltemplate = 'CustomerAccountSuspended';
							}

							// Send deployment email
							include_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
							include_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
							$formmail=new FormMail($db);

							$arraydefaultmessage=$formmail->getEMailTemplate($db, 'thirdparty', $user, $langs, 0, 1, $labeltemplate);

							$substitutionarray=getCommonSubstitutionArray($langs, 0, null, $object);
							complete_substitutions_array($substitutionarray, $langs, $object);

							$subject = make_substitutions($arraydefaultmessage->topic, $substitutionarray, $langs);
							$msg     = make_substitutions($arraydefaultmessage->content, $substitutionarray, $langs);
							$from = $conf->global->SELLYOURSAAS_NOREPLY_EMAIL;
							$to = $object->thirdparty->email;

							$cmail = new CMailFile($subject, $to, $from, $msg, array(), array(), array(), '', '', 0, 1);
							$result = $cmail->sendfile();
							if (! $result)
							{
								$erroremail .= ($erroremail ? ' ' : '').$cmail->error;
								$this->errors[] = $cmail->error;
								if (is_array($cmail->errors) && count($cmail->errors) > 0) $this->errors += $cmail->errors;
							}
						}
					}
				}
    			$i++;
    		}
    	}
    	else
    	{
    		$error++;
    		$this->error = $this->db->lasterror();
    	}

   		if (! $error)
   		{
   			$this->output = count($contractprocessed).' '.$mode.' running contract(s) with end date before '.dol_print_date($datetotest, 'dayrfc').' suspended'.(count($contractprocessed)>0 ? ' : '.join(',', $contractprocessed) : '').' (search done on contracts of SellYourSaas customers only)';
   			if ($erroremail) $this->output.='. Got errors when sending some email : '.$erroremail;
   			$this->db->commit();
   		}
   		else
   		{
   			$this->output = count($contractprocessed).' '.$mode.' running contract(s) with end date before '.dol_print_date($datetotest, 'dayrfc').' to suspend'.(count($contractprocessed)>0 ? ' : '.join(',', $contractprocessed) : '').' (search done on contracts of SellYourSaas customers only)';
   			if ($erroremail) $this->output.='. Got errors when sending some email : '.$erroremail;
   			$this->db->rollback();
   		}

    	return ($error ? 1: 0);
    }


    /**
     * Action executed by scheduler
     * Undeployed test instances if we are after planned end date (+ grace offset in SELLYOURSAAS_NBDAYS_AFTER_EXPIRATION_BEFORE_TRIAL_UNDEPLOYMENT)
     * CAN BE A CRON TASK
     *
     * @return	int			0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
     */
    public function doUndeployOldSuspendedTestInstances()
    {
    	global $conf, $langs;

    	$savlog = $conf->global->SYSLOG_FILE;
    	$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_doUndeployOldSuspendedTestInstances.log';

    	dol_syslog(__METHOD__, LOG_DEBUG);
    	$result = $this->doUndeployOldSuspendedInstances('test');

    	$conf->global->SYSLOG_FILE = $savlog;

    	return $result;
    }

    /**
     * Action executed by scheduler
     * Undeployed paid instances if we are after planned end date (+ grace offset in SELLYOURSAAS_NBDAYS_AFTER_EXPIRATION_BEFORE_PAID_UNDEPLOYMENT)
     * CAN BE A CRON TASK
     *
     * @return	int			0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
     */
    public function doUndeployOldSuspendedRealInstances()
    {
    	global $conf, $langs;

    	$savlog = $conf->global->SYSLOG_FILE;
    	$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_doUndeployOldSuspendedRealInstances.log';

    	dol_syslog(__METHOD__, LOG_DEBUG);
    	$result = $this->doUndeployOldSuspendedInstances('paid');

    	$conf->global->SYSLOG_FILE = $savlog;

    	return $result;
    }

    /**
     * Action executed by scheduler
     * CAN BE A CRON TASK
     *
   	 * @param	string	$mode		'test' or 'paid'
     * @return	int					0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
     */
    public function doUndeployOldSuspendedInstances($mode)
    {
    	global $conf, $langs, $user;

    	if ($mode != 'test' && $mode != 'paid')
    	{
    		$this->error = 'Function doUndeployOldSuspendedInstances called with bad value for parameter '.$mode;
    		return -1;
    	}

    	$error = 0;
    	$this->output = '';
    	$this->error='';

    	$delayindays = 9999999;
    	if ($mode == 'test') $delayindays = $conf->global->SELLYOURSAAS_NBDAYS_AFTER_EXPIRATION_BEFORE_TRIAL_UNDEPLOYMENT;
    	if ($mode == 'paid') $delayindays = $conf->global->SELLYOURSAAS_NBDAYS_AFTER_EXPIRATION_BEFORE_PAID_UNDEPLOYMENT;
		if ($delayindays <= 1)
		{
			$this->error='BadValueForDelayBeforeUndeploymentCheckSetup';
			return -1;
		}

    	dol_syslog(__METHOD__." we undeploy instances mode=".$mode." that are expired since more than ".$delayindays." days", LOG_DEBUG);

    	$now = dol_now();
    	$datetotest = dol_time_plus_duree($now, -1 * abs($delayindays), 'd');

    	$this->db->begin();

    	$sql = 'SELECT c.rowid, c.ref_customer, cd.rowid as lid';
    	$sql.= ' FROM '.MAIN_DB_PREFIX.'contrat as c, '.MAIN_DB_PREFIX.'contratdet as cd, '.MAIN_DB_PREFIX.'contrat_extrafields as ce, ';
    	$sql.= ' '.MAIN_DB_PREFIX.'societe_extrafields as se';
    	$sql.= ' WHERE cd.fk_contrat = c.rowid AND ce.fk_object = c.rowid';
    	$sql.= " AND ce.deployment_status = 'done'";
    	$sql.= " AND cd.date_fin_validite < '".$this->db->idate($datetotest)."'";
    	$sql.= " AND cd.statut = 5";
    	$sql.= " AND se.fk_object = c.fk_soc AND se.dolicloud = 'yesv2'";

    	$resql = $this->db->query($sql);
    	if ($resql)
    	{
    		$num = $this->db->num_rows($resql);

    		$contractprocessed = array();

    		$i=0;
    		while ($i < $num)
    		{
    			$obj = $this->db->fetch_object($resql);
    			if ($obj)
    			{
    				if (! empty($contractprocessed[$obj->rowid])) continue;

    				// Test if this is a paid or not instance
    				$object = new Contrat($this->db);
    				$object->fetch($obj->rowid);

    				if ($object->id <= 0)
    				{
    					$error++;
    					$this->errors[] = 'Failed to load contract with id='.$obj->rowid;
    					continue;
    				}

    				$isAPayingContract = sellyoursaasIsPaidInstance($object);
    				if ($mode == 'test' && $isAPayingContract) continue;										// Discard if this is a paid instance when we are in test mode
    				if ($mode == 'paid' && ! $isAPayingContract) continue;										// Discard if this is a test instance when we are in paid mode

    				// Undeploy instance
    				$tmparray = sellyoursaasGetExpirationDate($object);
    				$expirationdate = $tmparray['expirationdate'];

    				if ($expirationdate && $expirationdate < ($now - (abs($delayindays)*24*3600)))
    				{
    					$result = $this->sellyoursaasRemoteAction('undeploy', $object);
    					if ($result <= 0)
    					{
    						$error++;
    						$this->error=$this->error;
    						$this->errors=$this->errors;
    					}
    					//$object->array_options['options_deployment_status'] = 'suspended';

    					$contractprocessed[$object->id]=$object->ref;	// To avoid to make action twice on same contract
    				}

    				// Finish deployall

    				$comment = 'Close after click on undeploy from contract card';

    				// Unactivate all lines
    				if (! $error)
    				{
    					dol_syslog("Unactivate all lines - doUndeployOldSuspendedInstances undeploy");

    					$result = $object->closeAll($user, 1, $comment);
    					if ($result <= 0)
    					{
    						$error++;
    						$this->error=$object->error;
    						$this->errors=$object->errors;
    					}
    				}

    				// End of undeployment is now OK / Complete
    				if (! $error)
    				{
    					$object->array_options['options_deployment_status'] = 'undeployed';
    					$object->array_options['options_undeployment_date'] = dol_now();
    					$object->array_options['options_undeployment_ip'] = $_SERVER['REMOTE_ADDR'];

    					$result = $object->update($user);
    					if ($result < 0)
    					{
    						// We ignore errors. This should not happen in real life.
    						//setEventMessages($contract->error, $contract->errors, 'errors');
    					}
    					else
    					{
    						//setEventMessages($langs->trans("InstanceWasUndeployed"), null, 'mesgs');
    						//setEventMessages($langs->trans("InstanceWasUndeployedToConfirm"), null, 'mesgs');
    					}
    				}
    			}
    			$i++;
    		}
    	}
    	else
    	{
    		$error++;
    		$this->error = $this->db->lasterror();
    	}

    	$this->output = count($contractprocessed).' contract(s), in mode '.$mode.', suspended with end date before '.dol_print_date($datetotest, 'dayrfc').' undeployed'.(count($contractprocessed)>0 ? ' : '.join(',', $contractprocessed) : '');

    	$this->db->commit();

    	return ($error ? 1: 0);
    }




    /**
     * Make a remote action on a contract (deploy/undeploy/suspend/unsuspend/...)
     *
     * @param	string					$remoteaction	Remote action ('suspend/unsuspend'=change apache virtual file, 'deploy/undeploy'=create/delete database, 'refresh'=update status of install.lock+authorized key + loop on each line and read remote data and update qty of metrics)
     * @param 	Contrat|ContratLigne	$object			Object contract or contract line
     * @param	string					$appusername	App login
     * @param	string					$email			Initial email
     * @param	string					$password		Initial password
     * @return	int										<0 if KO, >0 if OK
     */
    function sellyoursaasRemoteAction($remoteaction, $object, $appusername='admin', $email='', $password='')
    {
    	global $conf, $langs, $user;

    	$error = 0;

    	$now = dol_now();

    	if (get_class($object) == 'Contrat')
    	{
    		$listoflines = $object->lines;
    	}
    	else
    	{
    		$listoflines = array($object);
    	}

    	dol_syslog("* sellyoursaasRemoteAction START (remoteaction=".$remoteaction." email=".$email." password=".$password.")", LOG_DEBUG, 1);

    	include_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

		// Action 'refresh', 'deletelock', 'recreatelock' for contract, check install.lock file
    	if (empty($object->context['fromdolicloudcustomerv1']) && in_array($remoteaction, array('refresh','recreateauthorizedkeys','deletelock','recreatelock')) && get_class($object) == 'Contrat')
    	{
    		// SFTP refresh
    		if (function_exists("ssh2_connect"))
    		{
    			$server=$object->array_options['options_hostname_os'];

    			$connection = @ssh2_connect($server, 22);
    			if ($connection)
    			{
    				//print ">>".$object->array_options['options_username_os']." - ".$object->array_options['options_password_os']."<br>\n";exit;
    				if (! @ssh2_auth_password($connection, $object->array_options['options_username_os'], $object->array_options['options_password_os']))
    				{
    					dol_syslog("Could not authenticate with username ".$object->array_options['options_username_os'], LOG_WARNING);
    					$this->errors[] = "Could not authenticate with username ".$object->array_options['options_username_os']." and password ".preg_replace('/./', '*', $object->array_options['options_password_os']);
    					$error++;
    				}
    				else
    				{
    					if ($remoteaction == 'refresh')
    					{
	    					$sftp = ssh2_sftp($connection);
	    					if (! $sftp)
	    					{
	    						dol_syslog("Could not execute ssh2_sftp",LOG_ERR);
	    						$this->errors[]='Failed to connect to ssh2_sftp to '.$server;
	    						$error++;
	    					}

		    				if (! $error)
		    				{
		    					// Check if install.lock exists
		    					$dir = $object->array_options['options_database_db'];
		    					//$fileinstalllock="ssh2.sftp://".$sftp.$conf->global->DOLICLOUD_EXT_HOME.'/'.$object->username_web.'/'.$dir.'/documents/install.lock';
		    					$fileinstalllock="ssh2.sftp://".intval($sftp).$object->array_options['options_hostname_os'].'/'.$object->array_options['options_username_os'].'/'.$dir.'/documents/install.lock';
		    					$fileinstalllock2=$conf->global->DOLICLOUD_INSTANCES_PATH.'/'.$object->array_options['options_username_os'].'/'.$dir.'/documents/install.lock';
		    					$fstatlock=@ssh2_sftp_stat($sftp, $fileinstalllock2);
		    					$datelockfile=(empty($fstatlock['atime'])?'':$fstatlock['atime']);

		    					// Check if authorized_keys exists (created during os account creation, into skel dir)
		    					$fileauthorizedkeys="ssh2.sftp://".intval($sftp).$object->array_options['options_hostname_os'].'/'.$object->array_options['options_username_os'].'/'.$dir.'/documents/install.lock';
		    					$fileauthorizedkeys2=$conf->global->DOLICLOUD_INSTANCES_PATH.'/'.$object->array_options['options_username_os'].'/.ssh/authorized_keys';
		    					$fstatlock=@ssh2_sftp_stat($sftp, $fileauthorizedkeys2);
		    					$dateauthorizedkeysfile=(empty($fstatlock['atime'])?'':$fstatlock['atime']);
		    					//var_dump($datelockfile);
		    					//var_dump($fileauthorizedkeys2);

		    					$object->array_options['options_filelock'] = $datelockfile;
		    					$object->array_options['options_fileauthorizekey'] = $dateauthorizedkeysfile;
		    					$object->update($user);
		    				}
    					}

    					if ($remoteaction == 'recreateauthorizedkeys')
    					{
    						$sftp = ssh2_sftp($connection);
    						if (! $sftp)
    						{
    							dol_syslog("Could not execute ssh2_sftp",LOG_ERR);
    							$this->errors[]='Failed to connect to ssh2_sftp to '.$server;
    							$error++;
    						}

    						// Update ssl certificate
    						// Dir .ssh must have rwx------ permissions
    						// File authorized_keys must have rw------- permissions
    						$dircreated=0;
    						$result=ssh2_sftp_mkdir($sftp, $conf->global->DOLICLOUD_INSTANCES_PATH.'/'.$object->array_options['options_username_os'].'/.ssh');
    						if ($result) {
    							$dircreated=1;
    						}	// Created
    						else {
    							$dircreated=0;
    						}	// Creation fails or already exists

    						// Check if authorized_key exists
    						//$filecert="ssh2.sftp://".$sftp.$conf->global->DOLICLOUD_EXT_HOME.'/'.$object->username_web.'/.ssh/authorized_keys';
    						$filecert="ssh2.sftp://".intval($sftp).$conf->global->DOLICLOUD_INSTANCES_PATH.'/'.$object->array_options['options_username_os'].'/.ssh/authorized_keys';  // With PHP 5.6.27+
    						$fstat=@ssh2_sftp_stat($sftp, $conf->global->DOLICLOUD_INSTANCES_PATH.'/'.$object->array_options['options_username_os'].'/.ssh/authorized_keys');

    						// Create authorized_keys file
    						if (empty($fstat['atime']))		// Failed to connect or file does not exists
    						{
    							$stream = fopen($filecert, 'w');
    							if ($stream === false)
    							{
    								$error++;
    								$this->errors[] =$langs->transnoentitiesnoconv("ErrorConnectOkButFailedToCreateFile");
    							}
    							else
    							{
    								$publickeystodeploy = $conf->global->SELLYOURSAAS_PUBLIC_KEY;
    								// Add public keys
    								fwrite($stream,$publickeystodeploy);

    								fclose($stream);
    								$fstat=ssh2_sftp_stat($sftp, $conf->global->DOLICLOUD_INSTANCES_PATH.'/'.$object->array_options['options_username_os'].'/.ssh/authorized_keys');
    							}
    						}
    						else
    						{
    							$error++;
    							$this->errors[] = $langs->transnoentitiesnoconv("ErrorFileAlreadyExists");
    						}

    						$object->array_options['options_fileauthorizekey']=(empty($fstat['atime'])?'':$fstat['atime']);

    						if (! empty($fstat['atime'])) $result = $object->update($user);
    					}

    					if ($remoteaction == 'deletelock')
    					{
    						$sftp = ssh2_sftp($connection);
    						if (! $sftp)
    						{
    							dol_syslog("Could not execute ssh2_sftp",LOG_ERR);
    							$this->errors[]='Failed to connect to ssh2_sftp to '.$server;
    							$error++;
    						}

    						// Check if install.lock exists
    						$dir = $object->array_options['options_database_db'];
    						$filetodelete=$conf->global->DOLICLOUD_INSTANCES_PATH.'/'.$object->array_options['options_username_os'].'/'.$dir.'/documents/install.lock';
    						$result=ssh2_sftp_unlink($sftp, $filetodelete);

    						if (! $result)
    						{
    							$error++;
    							$this->errors[] = $langs->transnoentitiesnoconv("DeleteFails");
    						}
    						else
    						{
    							$object->array_options['options_filelock'] = '';
    						}
    						if ($result)
    						{
    							$result = $object->update($user, 1);
    						}
    					}

    					if ($remoteaction == 'recreatelock')
    					{
    						$sftp = ssh2_sftp($connection);
    						if (! $sftp)
    						{
    							dol_syslog("Could not execute ssh2_sftp",LOG_ERR);
    							$this->errors[]='Failed to connect to ssh2_sftp to '.$server;
    							$error++;
    						}

    						// Check if install.lock exists
    						$dir = $object->array_options['options_database_db'];
    						//$fileinstalllock="ssh2.sftp://".$sftp.$conf->global->DOLICLOUD_INSTANCES_PATH.'/'.$object->array_options['options_username_os'].'/'.$dir.'/documents/install.lock';
    						$fileinstalllock="ssh2.sftp://".intval($sftp).$conf->global->DOLICLOUD_INSTANCES_PATH.'/'.$object->array_options['options_username_os'].'/'.$dir.'/documents/install.lock';
    						$fstat=@ssh2_sftp_stat($sftp, $conf->global->DOLICLOUD_INSTANCES_PATH.'/'.$object->array_options['options_username_os'].'/'.$dir.'/documents/install.lock');
    						if (empty($fstat['atime']))
    						{
    							$stream = fopen($fileinstalllock, 'w');
    							//var_dump($stream);exit;
    							fwrite($stream,"// File to protect from install/upgrade.\n");
    							fclose($stream);
    							$fstat=ssh2_sftp_stat($sftp, $conf->global->DOLICLOUD_INSTANCES_PATH.'/'.$object->array_options['options_username_os'].'/'.$dir.'/documents/install.lock');
    						}
    						else
    						{
    							$error++;
    							$this->errors[]=$langs->transnoentitiesnoconv("ErrorFileAlreadyExists");
    						}

    						$object->array_options['options_filelock']=(empty($fstat['atime'])?'':$fstat['atime']);

    						if (! empty($fstat['atime']))
    						{
    							$result = $object->update($user, 1);
    						}
    					}
    				}
    			}
    			else {
    				$this->errors[]='Failed to connect to ssh2 to '.$server;
    				$error++;
    			}
    		}
    		else {
    			$this->errors[]='ssh2_connect not supported by this PHP';
    			$error++;
    		}
    	}

    	// Loop on each line of contract
    	foreach($listoflines as $tmpobject)
    	{
    		if (empty($tmpobject))
    		{
    			dol_syslog("List of lines contains empty ContratLine", LOG_WARNING);
    			continue;
    		}

    		$producttmp = new Product($this->db);
    		$producttmp->fetch($tmpobject->fk_product);

    		// remoteaction = 'deploy','deployall','suspend','unsuspend','undeploy'
    		if (empty($tmpobject->context['fromdolicloudcustomerv1']) &&
    			in_array($remoteaction, array('deploy','deployall','suspend','unsuspend','undeploy')) &&
    			($producttmp->array_options['options_app_or_option'] == 'app' || $producttmp->array_options['options_app_or_option'] == 'option'))
    		{
    			include_once DOL_DOCUMENT_ROOT.'/contrat/class/contrat.class.php';
    			dol_include_once('/sellyoursaas/class/packages.class.php');

    			$contract = new Contrat($this->db);
    			$contract->fetch($tmpobject->fk_contrat);

    			$tmp=explode('.', $contract->ref_customer, 2);
    			$sldAndSubdomain=$tmp[0];
    			$domainname=$tmp[1];
    			$serverdeployement = $this->getRemoveServerDeploymentIp($domainname);
    			if (empty($serverdeployement))	// Failed to get remote ip
    			{
    				dol_syslog($this->error, LOG_WARNING);
    				$error++;
    				break;
    			}

    			$targetdir = $conf->global->DOLICLOUD_INSTANCES_PATH;

    			$generatedunixlogin=$contract->array_options['options_username_os'];
    			$generatedunixpassword=$contract->array_options['options_password_os'];
    			$generateddbname=$contract->array_options['options_database_db'];
    			$generateddbport = ($contract->array_options['options_port_db']?$contract->array_options['options_port_db']:3306);
    			$generateddbusername=$contract->array_options['options_username_db'];
    			$generateddbpassword=$contract->array_options['options_password_db'];
    			$generateddbprefix=($contract->array_options['options_prefix_db']?$contract->array_options['options_prefix_db']:'llx_');

    			// Is it a product linked to a package ?
    			$tmppackage = new Packages($this->db);
    			if (! empty($producttmp->array_options['options_package']))
    			{
    				$tmppackage->fetch($producttmp->array_options['options_package']);
    			}

    			$savsalt = $conf->global->MAIN_SECURITY_SALT;
    			$savhashalgo = $conf->global->MAIN_SECURITY_HASH_ALGO;

    			$conf->global->MAIN_SECURITY_HASH_ALGO = empty($conf->global->SELLYOURSAAS_HASHALGOFORPASSWORD)?'':$conf->global->SELLYOURSAAS_HASHALGOFORPASSWORD;
    			dol_syslog("Using this MAIN_SECURITY_HASH_ALGO for __APPPASSWORDxxx__ variables : ".$conf->global->MAIN_SECURITY_HASH_ALGO);

    			$conf->global->MAIN_SECURITY_SALT = empty($conf->global->SELLYOURSAAS_SALTFORPASSWORDENCRYPTION)?'':$conf->global->SELLYOURSAAS_SALTFORPASSWORDENCRYPTION;
    			dol_syslog("Using this salt for __APPPASSWORDxxxSALTED__ variables : ".$conf->global->MAIN_SECURITY_SALT);
    			$password0salted = dol_hash($password);
    			$passwordmd5salted = dol_hash($password, 'md5');
    			$passwordsha256salted = dol_hash($password, 'sha256');
    			dol_syslog("passwordmd5salted=".$passwordmd5salted);

    			$conf->global->MAIN_SECURITY_SALT = '';
    			dol_syslog("Using empty salt for __APPPASSWORDxxx__ variables : ".$conf->global->MAIN_SECURITY_SALT);
    			$password0 = dol_hash($password);
    			$passwordmd5 = dol_hash($password, 'md5');
    			$passwordsha256 = dol_hash($password, 'sha256');
    			dol_syslog("passwordmd5=".$passwordmd5);

    			$conf->global->MAIN_SECURITY_SALT = $savsalt;
    			$conf->global->MAIN_SECURITY_HASH_ALGO = $savhashalgo;

    			// Replace __INSTANCEDIR__, __INSTALLHOURS__, __INSTALLMINUTES__, __OSUSERNAME__, __APPUNIQUEKEY__, __APPDOMAIN__, ...
    			$substitarray=array(
    			'__INSTANCEDIR__'=>$targetdir.'/'.$generatedunixlogin.'/'.$generateddbname,
    			'__INSTANCEDBPREFIX__'=>$generateddbprefix,
    			'__DOL_DATA_ROOT__'=>DOL_DATA_ROOT,
    			'__INSTALLHOURS__'=>dol_print_date($now, '%H'),
    			'__INSTALLMINUTES__'=>dol_print_date($now, '%M'),
    			'__OSHOSTNAME__'=>$generatedunixhostname,
    			'__OSUSERNAME__'=>$generatedunixlogin,
    			'__OSPASSWORD__'=>$generatedunixpassword,
    			'__DBHOSTNAME__'=>$generateddbhostname,
    			'__DBNAME__'=>$generateddbname,
    			'__DBPORT__'=>$generateddbport,
    			'__DBUSER__'=>$generateddbusername,
    			'__DBPASSWORD__'=>$generateddbpassword,
    			'__PACKAGEREF__'=> $tmppackage->ref,
    			'__PACKAGENAME__'=> $tmppackage->label,
    			'__APPUSERNAME__'=>$appusername,
    			'__APPEMAIL__'=>$email,
    			'__APPPASSWORD__'=>$password,
    			'__APPPASSWORD0__'=>$password0,
    			'__APPPASSWORDMD5__'=>$passwordmd5,
    			'__APPPASSWORDSHA256__'=>$passwordsha256,
    			'__APPPASSWORD0SALTED__'=>$password0salted,
    			'__APPPASSWORDMD5SALTED__'=>$passwordmd5salted,
    			'__APPPASSWORDSHA256SALTED__'=>$passwordsha256salted,
    			'__APPUNIQUEKEY__'=>$generateduniquekey,
    			'__APPDOMAIN__'=>$sldAndSubdomain.'.'.$domainname
    			);

    			$tmppackage->srcconffile1 = '/tmp/conf.php.'.$sldAndSubdomain.'.'.$domainname.'.tmp';
    			$tmppackage->srccronfile = '/tmp/cron.'.$sldAndSubdomain.'.'.$domainname.'.tmp';
    			$tmppackage->srccliafter = '/tmp/cliafter.'.$sldAndSubdomain.'.'.$domainname.'.tmp';

    			$conffile = make_substitutions($tmppackage->conffile1, $substitarray);
    			$cronfile = make_substitutions($tmppackage->crontoadd, $substitarray);
    			$cliafter = make_substitutions($tmppackage->cliafter, $substitarray);

    			$tmppackage->targetconffile1 = make_substitutions($tmppackage->targetconffile1, $substitarray);
    			$tmppackage->datafile1 = make_substitutions($tmppackage->datafile1, $substitarray);
    			$tmppackage->srcfile1 = make_substitutions($tmppackage->srcfile1, $substitarray);
    			$tmppackage->srcfile2 = make_substitutions($tmppackage->srcfile2, $substitarray);
    			$tmppackage->srcfile3 = make_substitutions($tmppackage->srcfile3, $substitarray);
    			$tmppackage->targetsrcfile1 = make_substitutions($tmppackage->targetsrcfile1, $substitarray);
    			$tmppackage->targetsrcfile2 = make_substitutions($tmppackage->targetsrcfile2, $substitarray);
    			$tmppackage->targetsrcfile3 = make_substitutions($tmppackage->targetsrcfile3, $substitarray);

    			dol_syslog("Create conf file ".$tmppackage->srcconffile1);
    			file_put_contents($tmppackage->srcconffile1, $conffile);

    			dol_syslog("Create cron file ".$tmppackage->srccronfile1);
    			file_put_contents($tmppackage->srccronfile, $cronfile);

    			dol_syslog("Create cli file ".$tmppackage->srccliafter);
    			file_put_contents($tmppackage->srccliafter, $cliafter);

    			// Remote action : unsuspend
    			$commandurl = $generatedunixlogin.'&'.$generatedunixpassword.'&'.$sldAndSubdomain.'&'.$domainname;
    			$commandurl.= '&'.$generateddbname.'&'.$generateddbport.'&'.$generateddbusername.'&'.$generateddbpassword;
    			$commandurl.= '&'.$tmppackage->srcconffile1.'&'.$tmppackage->targetconffile1.'&'.$tmppackage->datafile1;
    			$commandurl.= '&'.$tmppackage->srcfile1.'&'.$tmppackage->targetsrcfile1.'&'.$tmppackage->srcfile2.'&'.$tmppackage->targetsrcfile2.'&'.$tmppackage->srcfile3.'&'.$tmppackage->targetsrcfile3;
    			$commandurl.= '&'.$tmppackage->srccronfile.'&'.$tmppackage->srccliafter.'&'.$targetdir;
    			$commandurl.= '&'.$conf->global->SELLYOURSAAS_SUPERVISION_EMAIL;
    			$commandurl.= '&'.$serverdeployement;
    			$commandurl.= '&'.$conf->global->SELLYOURSAAS_ACCOUNT_URL;

    			$outputfile = $conf->sellyoursaas->dir_temp.'/action-'.$remoteaction.'-'.dol_getmypid().'.out';


    			$conf->global->MAIN_USE_RESPONSE_TIMEOUT = 60;

    			if (! $error)
    			{
	    			$urltoget='http://'.$serverdeployement.':8080/'.$remoteaction.'?'.urlencode($commandurl);
	    			include_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
	    			$retarray = getURLContent($urltoget);

	    			if ($retarray['curl_error_no'] != '' || $retarray['http_code'] != 200)
	    			{
	    				$error++;
	    				if ($retarray['curl_error_no'] != '') $this->errors[] = $retarray['curl_error_msg'];
	    				else $this->errors[] = $retarray['content'];
	    			}
    			}

    			if (! $error && in_array($remoteaction, array('deploy','deployall')))
    			{
			    	// Execute personalized SQL requests
			    	if (! $error)
			    	{
			    		$sqltoexecute = make_substitutions($tmppackage->sqlafter, $substitarray);

			    		dol_syslog("Try to connect to instance database to execute personalized requests substitarray=".join(',', $substitarray));

			    		//var_dump($generateddbhostname);	// fqn name dedicated to instance in dns
			    		//var_dump($serverdeployement);		// just ip of deployement server
			    		//$dbinstance = @getDoliDBInstance('mysqli', $generateddbhostname, $generateddbusername, $generateddbpassword, $generateddbname, $generateddbport);
			    		$dbinstance = @getDoliDBInstance('mysqli', $serverdeployement, $generateddbusername, $generateddbpassword, $generateddbname, $generateddbport);
			    		if (! $dbinstance || ! $dbinstance->connected)
			    		{
			    			$error++;
			    			$this->error = $dbinstance->error;
			    			$this->errors = $dbinstance->errors;

			    		}
			    		else
			    		{
			    			$arrayofsql=explode(';', $sqltoexecute);
			    			foreach($arrayofsql as $sqltoexecuteline)
			    			{
			    				$sqltoexecuteline = trim($sqltoexecuteline);
			    				if ($sqltoexecuteline)
			    				{
			    					dol_syslog("Execute sql=".$sqltoexecuteline);
			    					$resql = $dbinstance->query($sqltoexecuteline);
			    				}
			    			}
			    		}
			    	}
    			}
    		}

    		// remoteaction = refresh => update the qty for this line if it is a line that is a metric
    		if (empty($tmpobject->context['fromdolicloudcustomerv1']) &&
    			$remoteaction == 'refresh')
    		{
    			include_once DOL_DOCUMENT_ROOT.'/contrat/class/contrat.class.php';
    			dol_include_once('/sellyoursaas/class/packages.class.php');

    			$contract = new Contrat($this->db);
    			$contract->fetch($tmpobject->fk_contrat);

    			// Update resource count
    			if (! empty($producttmp->array_options['options_resource_formula']))
    			{
    				$targetdir = $conf->global->DOLICLOUD_INSTANCES_PATH;

    				$generatedunixlogin=$contract->array_options['options_username_os'];
    				$generatedunixpassword=$contract->array_options['options_password_os'];
    				$tmp=explode('.', $contract->ref_customer, 2);
    				$sldAndSubdomain=$tmp[0];
    				$domainname=$tmp[1];
    				$generateddbname=$contract->array_options['options_database_db'];
    				$generateddbport = ($contract->array_options['options_port_db']?$contract->array_options['options_port_db']:3306);
    				$generateddbusername=$contract->array_options['options_username_db'];
    				$generateddbpassword=$contract->array_options['options_password_db'];
    				$generateddbprefix=($contract->array_options['options_prefix_db']?$contract->array_options['options_prefix_db']:'llx_');

    				// Is it a product linked to a package ?
    				$tmppackage = new Packages($this->db);
    				if (! empty($producttmp->array_options['options_package']))
    				{
    					$tmppackage->fetch($producttmp->array_options['options_package']);
    				}

    				$savsalt = $conf->global->MAIN_SECURITY_SALT;
    				$savhashalgo = $conf->global->MAIN_SECURITY_HASH_ALGO;

    				$conf->global->MAIN_SECURITY_HASH_ALGO = empty($conf->global->SELLYOURSAAS_HASHALGOFORPASSWORD)?'':$conf->global->SELLYOURSAAS_HASHALGOFORPASSWORD;
    				dol_syslog("Using this MAIN_SECURITY_HASH_ALGO for __APPPASSWORDxxx__ variables : ".$conf->global->MAIN_SECURITY_HASH_ALGO);

    				$conf->global->MAIN_SECURITY_SALT = empty($conf->global->SELLYOURSAAS_SALTFORPASSWORDENCRYPTION)?'':$conf->global->SELLYOURSAAS_SALTFORPASSWORDENCRYPTION;
    				dol_syslog("Using this salt for __APPPASSWORDxxxSALTED__ variables : ".$conf->global->MAIN_SECURITY_SALT);
    				$password0salted = dol_hash($password);
    				$passwordmd5salted = dol_hash($password, 'md5');
    				$passwordsha256salted = dol_hash($password, 'sha256');
    				dol_syslog("passwordmd5salted=".$passwordmd5salted);

    				$conf->global->MAIN_SECURITY_SALT = '';
    				dol_syslog("Using empty salt for __APPPASSWORDxxx__ variables : ".$conf->global->MAIN_SECURITY_SALT);
    				$password0 = dol_hash($password);
    				$passwordmd5 = dol_hash($password, 'md5');
    				$passwordsha256 = dol_hash($password, 'sha256');
    				dol_syslog("passwordmd5=".$passwordmd5);

    				$conf->global->MAIN_SECURITY_SALT = $savsalt;
    				$conf->global->MAIN_SECURITY_HASH_ALGO = $savhashalgo;

    				// Replace __INSTANCEDIR__, __INSTALLHOURS__, __INSTALLMINUTES__, __OSUSERNAME__, __APPUNIQUEKEY__, __APPDOMAIN__, ...
    				$substitarray=array(
    				'__INSTANCEDIR__'=>$targetdir.'/'.$generatedunixlogin.'/'.$generateddbname,
    				'__INSTANCEDBPREFIX__'=>$generateddbprefix,
    				'__DOL_DATA_ROOT__'=>DOL_DATA_ROOT,
    				'__INSTALLHOURS__'=>dol_print_date($now, '%H'),
    				'__INSTALLMINUTES__'=>dol_print_date($now, '%M'),
    				'__OSHOSTNAME__'=>$generatedunixhostname,
    				'__OSUSERNAME__'=>$generatedunixlogin,
    				'__OSPASSWORD__'=>$generatedunixpassword,
    				'__DBHOSTNAME__'=>$generateddbhostname,
    				'__DBNAME__'=>$generateddbname,
    				'__DBPORT__'=>$generateddbport,
    				'__DBUSER__'=>$generateddbusername,
    				'__DBPASSWORD__'=>$generateddbpassword,
    				'__PACKAGEREF__'=> $tmppackage->ref,
    				'__PACKAGENAME__'=> $tmppackage->label,
    				'__APPUSERNAME__'=>$appusername,
    				'__APPEMAIL__'=>$email,
    				'__APPPASSWORD__'=>$password,
    				'__APPPASSWORD0__'=>$password0,
    				'__APPPASSWORDMD5__'=>$passwordmd5,
    				'__APPPASSWORDSHA256__'=>$passwordsha256,
    				'__APPPASSWORD0SALTED__'=>$password0salted,
    				'__APPPASSWORDMD5SALTED__'=>$passwordmd5salted,
    				'__APPPASSWORDSHA256SALTED__'=>$passwordsha256salted,
    				'__APPUNIQUEKEY__'=>$generateduniquekey,
    				'__APPDOMAIN__'=>$sldAndSubdomain.'.'.$domainname
    				);


					// Now execute the formula
    				$currentqty = $tmpobject->qty;

    				$tmparray=explode(':', $producttmp->array_options['options_resource_formula'], 2);
    				if ($tmparray[0] == 'SQL')
    				{
    					$sqlformula = make_substitutions($tmparray[1], $substitarray);

    					$serverdeployement = $this->getRemoveServerDeploymentIp($domainname);

    					dol_syslog("Try to connect to instance database to execute formula calculation");

    					//var_dump($generateddbhostname);	// fqn name dedicated to instance in dns
    					//var_dump($serverdeployement);		// just ip of deployement server
    					//$dbinstance = @getDoliDBInstance('mysqli', $generateddbhostname, $generateddbusername, $generateddbpassword, $generateddbname, $generateddbport);
    					$dbinstance = @getDoliDBInstance('mysqli', $serverdeployement, $generateddbusername, $generateddbpassword, $generateddbname, $generateddbport);
    					if (! $dbinstance || ! $dbinstance->connected)
    					{
    						$error++;
    						$this->error = $dbinstance->error;
    						$this->errors = $dbinstance->errors;
    					}
    					else
    					{
    						dol_syslog("Execute sql=".$sqlformula);
    						$resql = $dbinstance->query($sqlformula);
    						if ($resql)
    						{
    							$objsql = $dbinstance->fetch_object($resql);
    							if ($objsql)
    							{
    								$newqty = $objsql->nb;
    							}
    							else
    							{
    								$error++;
    								$this->error = 'SQL to get resource return nothing';
    								$this->errors[] = 'SQL to get resource return nothing';
    							}
    						}
    						else
    						{
    							$error++;
    							$this->error = $dbinstance->lasterror();
    							$this->errors[] = $dbinstance->lasterror();
    						}
    					}
    				}
    				else
    				{
    					$error++;
    					$this->error = 'Bad definition of formula to calculate resource for product '.$producttmp->ref;
    				}

    				if (! $error && $newqty != $currentqty)
    				//if (! $error)
    				{
    					// tmpobject is contract line
    					$tmpobject->qty = $newqty;
    					$result = $tmpobject->update($user);
    					if ($result <= 0)
    					{
    						$error++;
    						$this->error = 'Failed to update the count for product '.$producttmp->ref;
    					}
    					else
    					{
    						// Test if there is template invoice linkded
    						$contract->fetchObjectLinked();

    						if (is_array($contract->linkedObjects['facturerec']) && count($contract->linkedObjects['facturerec']) > 0)
    						{
    							//dol_sort_array($contract->linkedObjects['facture'], 'date');
    							$sometemplateinvoice=0;
    							$lasttemplateinvoice=null;
    							foreach($contract->linkedObjects['facturerec'] as $invoice)
    							{
    								//if ($invoice->suspended == FactureRec::STATUS_SUSPENDED) continue;	// Draft invoice are not invoice not paid
    								$sometemplateinvoice++;
    								$lasttemplateinvoice=$invoice;
    							}
    							if ($sometemplateinvoice > 1)
    							{
    								$error++;
    								$this->error = 'Contract '.$object->ref.' has too many template invoice ('.$someinvoicenotpaid.') so we dont know which one to update';
    							}
    							elseif (is_object($lasttemplateinvoice))
    							{
    								$sqlsearchline = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'facturedet_rec WHERE fk_facture = '.$lasttemplateinvoice->id.' AND fk_product = '.$tmpobject->fk_product;
    								$resqlsearchline = $this->db->query($sqlsearchline);
    								if ($resqlsearchline)
    								{
    									$num_search_line = $this->db->num_rows($resqlsearchline);
    									if ($num_search_line > 1)
    									{
    										$error++;
    										$this->error = 'Contract '.$object->ref.' has a template invoice with id ('.$lasttemplateinvoice->id.') that has several lines for product id '.$tmpobject->fk_product.' so we don t know wich line to update qty';
    									}
    									else
    									{
	    									$objsearchline = $this->db->fetch_object($resqlsearchline);
	    									if ($objsearchline)	// If empty, it means, template invoice has no line corresponding to contract line
	    									{
	    										// Update qty
	    										$invoicerecline = new FactureLigneRec($this->db);
	    										$invoicerecline->fetch($objsearchline->rowid);

	    										$tabprice = calcul_price_total($newqty, $invoicerecline->subprice, $invoicerecline->remise_percent, $invoicerecline->tva_tx, $invoicerecline->localtax1_tx, $invoicerecline->txlocaltax2, 0, 'HT', $invoicerecline->info_bits, $invoicerecline->product_type, $mysoc, array(), 100);

	    										$invoicerecline->qty = $newqty;

	    										$invoicerecline->total_ht  = $tabprice[0];
	    										$invoicerecline->total_tva = $tabprice[1];
	    										$invoicerecline->total_ttc = $tabprice[2];
	    										$invoicerecline->total_localtax1 = $tabprice[9];
	    										$invoicerecline->total_localtax2 = $tabprice[10];

	    										$result = $invoicerecline->update($user);

	    										$result = $lasttemplateinvoice->update_price();
	    									}
    									}
    								}
    								else
    								{
    									$error++;
    									$this->error = $this->db->lasterror();
    								}
    							}
    						}
    					}
    				}

    				if (! $error)
    				{
   						$contract->array_options['options_latestresupdate_date']=dol_now();
    					$result = $contract->update($user);
    					if ($result <= 0)
    					{
    						$error++;
    						$this->error = 'Failed to update field options_latestresupdate_date on contract '.$contract->ref;
    					}
    				}
    			}
    		}
    	}

    	if (! $error && get_class($object) == 'Contrat')
    	{
    		// Create an event
    		$actioncomm = new ActionComm($this->db);
    		$actioncomm->type_code   = 'AC_OTH_AUTO';		// Type of event ('AC_OTH', 'AC_OTH_AUTO', 'AC_XXX'...)
    		$actioncomm->code        = 'AC_'.$action;
    		$actioncomm->label       = 'Remote action '.$remoteaction.' on contract'.(preg_match('/PROV/', $object->ref) ? '' : ' '.$object->ref);
    		$actioncomm->datep       = $now;
    		$actioncomm->datef       = $now;
    		$actioncomm->percentage  = -1;   // Not applicable
    		$actioncomm->socid       = $object->socid;
    		$actioncomm->authorid    = $user->id;   // User saving action
    		$actioncomm->userownerid = $user->id;	// Owner of action
    		$actioncomm->fk_element  = $object->id;
    		$actioncomm->elementtype = 'contract';
    		$ret=$actioncomm->create($user);       // User creating action
    	}

    	dol_syslog("* sellyoursaasRemoteAction END (remoteaction=".$remoteaction." email=".$email." password=".$password." error=".$error.")", LOG_DEBUG, -1);

    	if ($error) return -1;
    	else return 1;
    }




    /**
     * Return IP of server to deploy to
     *
     * @param	string		$domainname		Domain name to select remote ip to deploy to
     * @return	string						'' if KO, ip if OK
     */
    function getRemoveServerDeploymentIp($domainname)
    {
    	global $conf;

    	$error = 0;

    	$REMOTEIPTODEPLOYTO='';
    	$tmparray=explode(',', $conf->global->SELLYOURSAAS_SUB_DOMAIN_NAMES);
    	$found=0;
    	foreach($tmparray as $key => $val)
    	{
    		if ($val == $domainname)
    		{
    			$found = $key+1;
    			break;
    		}
    	}
    	//print 'Found domain at position '.$found;
    	if (! $found)
    	{
    		$this->error="Failed to found position of server domain '".$domainname."' into SELLYOURSAAS_SUB_DOMAIN_NAMES=".$conf->global->SELLYOURSAAS_SUB_DOMAIN_NAMES;
    		$this->errors[]="Failed to found position of server domain '".$domainname."' into SELLYOURSAAS_SUB_DOMAIN_NAMES=".$conf->global->SELLYOURSAAS_SUB_DOMAIN_NAMES;
    		$error++;
    	}
    	else
    	{
    		$tmparray=explode(',', $conf->global->SELLYOURSAAS_SUB_DOMAIN_IP);
    		$REMOTEIPTODEPLOYTO=$tmparray[($found-1)];
	    	if (! $REMOTEIPTODEPLOYTO)
	    	{
	    		$this->error="Failed to found ip of server domain '".$domainname."' at position '".$found."' into SELLYOURSAAS_SUB_DOMAIN_IPS=".$conf->global->SELLYOURSAAS_SUB_DOMAIN_IP;
	    		$this->errors[]="Failed to found ip of server domain '".$domainname."' at position '".$found."' into SELLYOURSAAS_SUB_DOMAIN_IPS=".$conf->global->SELLYOURSAAS_SUB_DOMAIN_IP;
	    		$error++;
	    	}
    	}

    	if ($error) return '';
    	return $REMOTEIPTODEPLOYTO;
    }

}
