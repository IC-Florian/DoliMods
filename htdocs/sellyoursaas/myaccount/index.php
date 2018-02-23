<?php

//if (! defined('NOREQUIREUSER'))  define('NOREQUIREUSER','1');
//if (! defined('NOREQUIREDB'))    define('NOREQUIREDB','1');
//if (! defined('NOREQUIRESOC'))   define('NOREQUIRESOC','1');
//if (! defined('NOREQUIRETRAN'))  define('NOREQUIRETRAN','1');
//if (! defined('NOCSRFCHECK'))    define('NOCSRFCHECK','1');			// Do not check anti CSRF attack test
if (! defined('NOIPCHECK'))      define('NOIPCHECK','1');				// Do not check IP defined into conf $dolibarr_main_restrict_ip
//if (! defined('NOSTYLECHECK'))   define('NOSTYLECHECK','1');			// Do not check style html tag into posted data
//if (! defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL','1');		// Do not check anti POST attack test
if (! defined('NOREQUIREMENU'))  define('NOREQUIREMENU','1');			// If there is no need to load and show top and left menu
//if (! defined('NOREQUIREHTML'))  define('NOREQUIREHTML','1');			// If we don't need to load the html.form.class.php
//if (! defined('NOREQUIREAJAX'))  define('NOREQUIREAJAX','1');
//if (! defined("NOLOGIN"))        define("NOLOGIN",'1');				    	// If this page is public (can be called outside logged session)
if (! defined("MAIN_LANG_DEFAULT")) define('MAIN_LANG_DEFAULT','auto');
if (! defined("MAIN_AUTHENTICATION_MODE")) define('MAIN_AUTHENTICATION_MODE','sellyoursaas');

// Load Dolibarr environment
include ('./mainmyaccount.inc.php');

// Load Dolibarr environment
$res=0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (! $res && ! empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res=@include($_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php");
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp=empty($_SERVER['SCRIPT_FILENAME'])?'':$_SERVER['SCRIPT_FILENAME'];$tmp2=realpath(__FILE__); $i=strlen($tmp)-1; $j=strlen($tmp2)-1;
while($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i]==$tmp2[$j]) { $i--; $j--; }
if (! $res && $i > 0 && file_exists(substr($tmp, 0, ($i+1))."/main.inc.php")) $res=@include(substr($tmp, 0, ($i+1))."/main.inc.php");
if (! $res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i+1)))."/main.inc.php")) $res=include(dirname(substr($tmp, 0, ($i+1)))."/main.inc.php");
// Try main.inc.php using relative path
if (! $res && file_exists("../../main.inc.php")) $res=@include("../../main.inc.php");
if (! $res && file_exists("../../../main.inc.php")) $res=@include("../../../main.inc.php");
if (! $res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/contrat/class/contrat.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/website/class/website.class.php';
dol_include_once('/sellyoursaas/class/packages.class.php');
dol_include_once('/sellyoursaas/lib/sellyoursaas.lib.php');

$conf->global->SYSLOG_FILE_ONEPERSESSION=1;

$welcomecid = GETPOST('welcomecid','alpha');
$mode = GETPOST('mode', 'alpha');
$action = GETPOST('action', 'alpha');
if (empty($mode) && empty($welcomecid)) $mode='dashboard';

$langs=new Translate('', $conf);
$langs->setDefaultLang(GETPOST('lang','aZ09')?GETPOST('lang','aZ09'):'auto');

$langs->loadLangs(array("main","companies","bills","sellyoursaas@sellyoursaas","other","errors"));

$mythirdpartyaccount = new Societe($db);

// Id of connected thirdparty
$socid = $_SESSION['dol_loginsellyoursaas'];
$result = $mythirdpartyaccount->fetch($socid);
if ($result <= 0)
{
	dol_print_error("Failed to load thirdparty for socid=".$socid);
	exit;
}



/*
 * Action
 */

if ($mode == 'logout')
{
	session_destroy();
	header("Location: /index.php");
	exit;
}

if ($action == 'updateurl')
{
	setEventMessages($langs->trans("FeatureNotYetAvailable").'.<br>'.$langs->trans("ContactUsByEmail", $conf->global->SELLYOURSAAS_MAIN_EMAIL), null, 'warnings');
}

if ($action == 'changeplan')
{
	setEventMessages($langs->trans("FeatureNotYetAvailable").'.<br>'.$langs->trans("ContactUsByEmail", $conf->global->SELLYOURSAAS_MAIN_EMAIL), null, 'warnings');
	$action = '';
}

if ($action == 'updatemythirdpartyaccount')
{
	$orgname = GETPOST('orgName','nohtml');
	$address = GETPOST('address','nohtml');
	$town = GETPOST('town','nohtml');
	$zip = GETPOST('zip','nohtml');
	$stateorcounty = GETPOST('stateorcounty','nohtml');
	$country_code = GETPOST('country_id','aZ09');
	$vatassuj = (GETPOST('vatassuj','alpha') == 'on' ? 1 : 0);
	$vatnumber = GETPOST('vatnumber','alpha');

	if (empty($orgname))
	{
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("NameOfCompany")), null, 'errors');
		header("Location: ".$_SERVER['PHP_SELF']."?mode=myaccount#updatemythirdpartyaccount");
		exit;
	}
	if (empty($country_code))
	{
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("Country")), null, 'errors');
		header("Location: ".$_SERVER['PHP_SELF']."?mode=myaccount#updatemythirdpartyaccount");
		exit;
	}

	$country_id = dol_getIdFromCode($db, $country_code, 'c_country', 'code', 'rowid');

	$db->begin();	// Start transaction

	$mythirdpartyaccount->name = $orgname;
	$mythirdpartyaccount->address = $address;
	$mythirdpartyaccount->town = $town;
	$mythirdpartyaccount->zip = $zip;
	if ($country_id > 0) $mythirdpartyaccount->country_id = $country_id;
	$mythirdpartyaccount->tva_assuj = $vatassuj;
	$mythirdpartyaccount->tva_intra = $vatnumber;

	$result = $mythirdpartyaccount->update($mythirdpartyaccount->id, $user);

	if ($result > 0)
	{
		$mythirdpartyaccount->country_code = $country_code;

		setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
		$db->commit();
	}
	else
	{
		$langs->load("errors");
		setEventMessages($langs->trans('ErrorFailedToSaveRecord'), null, 'errors');
		setEventMessages($mythirdpartyaccount->error, $mythirdpartyaccount->errors, 'errors');
		$db->rollback();
	}
}

if ($action == 'updatemythirdpartylogin')
{
	$email = GETPOST('email','nohtml');
	$firstname = GETPOST('firstName','nohtml');
	$lastname = GETPOST('lastName','nohtml');

	if (empty($email))
	{
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("Email")), null, 'errors');
		header("Location: ".$_SERVER['PHP_SELF']."?mode=myaccount#updatemythirdpartylogin");
		exit;
	}
	if (! isValidEmail($email))
	{
		setEventMessages($langs->trans("ErrorBadValueForEmail"), null, 'errors');
		header("Location: ".$_SERVER['PHP_SELF']."?mode=myaccount#updatemythirdpartylogin");
		exit;
	}

	$db->begin();	// Start transaction

	$mythirdpartyaccount->email = $email;
	$mythirdpartyaccount->array_options['options_firstname'] = $firstname;
	$mythirdpartyaccount->array_options['options_lastname'] = $lastname;

	$result = $mythirdpartyaccount->update($mythirdpartyaccount->id, $user);

	if ($result > 0)
	{
		setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
		$db->commit();
	}
	else
	{
		$langs->load("errors");
		setEventMessages($langs->trans('ErrorFailedToSaveRecord'), null, 'errors');
		setEventMessages($mythirdpartyaccount->error, $mythirdpartyaccount->errors, 'errors');
		$db->rollback();
	}
}

if ($action == 'updatepassword')
{
	$password = GETPOST('password','nohtml');
	$password2 = GETPOST('password2','nohtml');

	if (empty($password) || empty($password2))
	{
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("Password")), null, 'errors');
		header("Location: ".$_SERVER['PHP_SELF']."?mode=myaccount#updatepassword");
		exit;
	}
	if ($password != $password2)
	{
		setEventMessages($langs->trans("ErrorPasswordMismatch"), null, 'errors');
		header("Location: ".$_SERVER['PHP_SELF']."?mode=myaccount#updatepassword");
		exit;
	}

	$db->begin();	// Start transaction

	$mythirdpartyaccount->array_options['options_password'] = $password;

	$result = $mythirdpartyaccount->update($mythirdpartyaccount->id, $user);

	if ($result > 0)
	{
		setEventMessages($langs->trans("PasswordModified"), null, 'mesgs');
		$db->commit();
	}
	else
	{
		$langs->load("errors");
		setEventMessages($langs->trans('ErrorFailedToChangePassword'), null, 'errors');
		setEventMessages($mythirdpartyaccount->error, $mythirdpartyaccount->errors, 'errors');
		$db->rollback();
	}
}


if ($action == 'undeploy')
{
	$contract=new Contrat($db);
	$contract->fetch(GETPOST('contractid','int'));					// This load also lines

	$urlofinstancetodestroy = GETPOST('urlofinstancetodestroy','alpha');
	if (empty($urlofinstancetodestroy))
	{
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("NameOfInstanceToDestroy")), null, 'errors');
		$error++;
	}
	elseif ($urlofinstancetodestroy != $contract->ref_customer)
	{
		setEventMessages($langs->trans("ErrorNameOfInstanceDoesNotMatch", $urlofinstancetodestroy, $contract->ref_customer), null, 'errors');
		$error++;
	}
	else
	{
		$targetdir = $conf->global->DOLICLOUD_INSTANCES_PATH;

		$generatedunixlogin = $contract->array_options['options_username_os'];
		$generatedunixpassword = 'na';
		$tmparray = explode('.', $contract->ref_customer, 2);
		$sldAndSubdomain = $tmparray[0];
		$domainname = $tmparray[1];
		$generateddbname = $contract->array_options['options_database_db'];
		$generateddbport = ($contract->array_options['options_port_db']?$contract->array_options['options_port_db']:3306);
		$generateddbusername = $contract->array_options['options_username_db'];
		$generateddbpassword = $contract->array_options['options_password_db'];

		$tmppackage = new Packages($db);

		// Remote action : undeploy
		$commandurl = $generatedunixlogin.'&'.$generatedunixpassword.'&'.$sldAndSubdomain.'&'.$domainname;
		$commandurl.= '&'.$generateddbname.'&'.$generateddbport.'&'.$generateddbusername.'&'.$generateddbpassword;
		$commandurl.= '&'.$tmppackage->srcconffile1.'&'.$tmppackage->targetconffile1.'&'.$tmppackage->datafile1;
		$commandurl.= '&'.$tmppackage->srcfile1.'&'.$tmppackage->targetsrcfile1.'&'.$tmppackage->srcfile2.'&'.$tmppackage->targetsrcfile2.'&'.$tmppackage->srcfile3.'&'.$tmppackage->targetsrcfile3;
		$commandurl.= '&'.$tmppackage->srccronfile.'&'.$targetdir;

		$outputfile = $conf->sellyoursaas->dir_temp.'/action_deploy_undeploy-undeploy-'.dol_getmypid().'.out';

		$serverdeployement = getRemoveServerDeploymentIp();

		$urltoget='http://'.$serverdeployement.':8080/undeploy?'.urlencode($commandurl);
		include_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
		$retarray = getURLContent($urltoget);

		if ($retarray['curl_error_no'] != '')
		{
			$error++;
			$errormessages[] = $retarray['curl_error_msg'];
		}
	}
}


/*
 * View
 */

$form = new Form($db);

$listofcontractid = array();
require_once DOL_DOCUMENT_ROOT.'/contrat/class/contrat.class.php';
$documentstatic=new Contrat($db);
$documentstaticline=new ContratLigne($db);
$sql = 'SELECT c.rowid as rowid';
$sql.= ' FROM '.MAIN_DB_PREFIX.'contrat as c LEFT JOIN '.MAIN_DB_PREFIX.'contrat_extrafields as ce ON ce.fk_object = c.rowid, '.MAIN_DB_PREFIX.'contratdet as d, '.MAIN_DB_PREFIX.'societe as s';
$sql.= " WHERE c.fk_soc = s.rowid AND s.rowid = ".$socid;
$sql.= " AND d.fk_contrat = c.rowid";
$sql.= " AND c.entity = ".$conf->entity;
$sql.= " AND ce.deployment_status IN ('processing', 'done')";

$resql=$db->query($sql);
if ($resql)
{
	$num_rows = $db->num_rows($resql);
	$i = 0;
	while ($i < $num_rows)
	{
		$obj = $db->fetch_object($resql);
		if ($obj)
		{
			$contract=new Contrat($db);
			$contract->fetch($obj->rowid);					// This load also lines
			$listofcontractid[$obj->rowid]=$contract;
		}
		$i++;
	}
}
else
{
	setEventMessages($db->lasterror(), null, 'errors');
}
if ($welcomecid > 0)
{
	$contract=new Contrat($db);
	$contract->fetch($welcomecid);
	$listofcontractid[$welcomecid]=$contract;
}
//var_dump($listofcontractid);


$head='<link rel="icon" href="img/favicon.ico">
<!-- Bootstrap core CSS -->
<!--<link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0-alpha.6/css/bootstrap.css" rel="stylesheet">-->
<link href="dist/css/bootstrap.css" rel="stylesheet">
<link href="dist/css/myaccount.css" rel="stylesheet">';
$head.="
<script>
var select2arrayoflanguage = {
	matches: function (matches) { return matches + '" .dol_escape_js($langs->transnoentitiesnoconv("Select2ResultFoundUseArrows"))."'; },
	noResults: function () { return '". dol_escape_js($langs->transnoentitiesnoconv("Select2NotFound")). "'; },
	inputTooShort: function (input) {
		var n = input.minimum;
		/*console.log(input);
		console.log(input.minimum);*/
		if (n > 1) return '". dol_escape_js($langs->transnoentitiesnoconv("Select2Enter")). "' + n + '". dol_escape_js($langs->transnoentitiesnoconv("Select2MoreCharacters")) ."';
			else return '". dol_escape_js($langs->transnoentitiesnoconv("Select2Enter")) ."' + n + '". dol_escape_js($langs->transnoentitiesnoconv("Select2MoreCharacter")) . "';
		},
	loadMore: function (pageNumber) { return '".dol_escape_js($langs->transnoentitiesnoconv("Select2LoadingMoreResults"))."'; },
	searching: function () { return '". dol_escape_js($langs->transnoentitiesnoconv("Select2SearchInProgress"))."'; }
};
</script>
";


//$website = new Website($db);
//$website->fetch(0, 'sellyoursaas');


llxHeader($head, $langs->trans("MyAccount"));

$linklogo = DOL_URL_ROOT.'/viewimage.php?modulepart=mycompany&file='.urlencode('/thumbs/'.$conf->global->SELLYOURSAAS_LOGO_MINI);

print '
    <nav class="navbar navbar-toggleable-md navbar-inverse bg-inverse">

	  <!-- Search + Menu -->

	  <form class="navbar-toggle navbar-toggler-right form-inline my-md-0" action="'.$_SERVER["PHP_SELF"].'">
			<input type="hidden" name="mode" value="'.dol_escape_htmltag($mode).'">
			<!--
				          <input class="form-control mr-sm-2" style="max-width: 100px;" type="text" placeholder="'.$langs->trans("Search").'">
				          <button class="btn-transparent nav-link" type="submit"><i class="fa fa-search"></i></button>
			-->
	      <button class="inline-block navbar-toggler" type="button" data-toggle="collapse" data-target="#navbars" aria-controls="navbars" aria-expanded="false" aria-label="Toggle navigation">
	        <span class="navbar-toggler-icon"></span>
	      </button>
	  </form>

	  <!-- Logo -->
      <span class="navbar-brand"><img src="'.DOL_URL_ROOT.'/viewimage.php?modulepart=mycompany&file='.urlencode('/thumbs/'.$conf->global->SELLYOURSAAS_LOGO_MINI).'" height="34px"></span>

	  <!-- Menu -->
      <div class="collapse navbar-collapse" id="navbars">
        <ul class="navbar-nav mr-auto">
          <li class="nav-item'.($mode == 'dashboard'?' active':'').'">
            <a class="nav-link" href="'.$_SERVER["PHP_SELF"].'?mode=dashboard"><i class="fa fa-tachometer"></i> '.$langs->trans("Dashboard").'</a>
          </li>
          <li class="nav-item'.($mode == 'instances'?' active':'').'">
            <a class="nav-link" href="'.$_SERVER["PHP_SELF"].'?mode=instances"><i class="fa fa-server"></i> '.$langs->trans("MyInstances").'</a>
          </li>
          <li class="nav-item'.($mode == 'billing'?' active':'').'">
            <a class="nav-link" href="'.$_SERVER["PHP_SELF"].'?mode=billing"><i class="fa fa-usd"></i> '.$langs->trans("Billing").'</a>
          </li>

          <li class="nav-item'.($mode == 'support'?' active':'').' dropdown">
            <a class="nav-link dropdown-toggle" data-toggle="dropdown" href="#"><i class="fa fa-gear"></i> '.$langs->trans("Other").'</a>
            <ul class="dropdown-menu">
	            <li><a class="dropdown-item" href="'.$_SERVER["PHP_SELF"].'?mode=support">'.$langs->trans("Support").'</a></li>
                <li class="dropdown-divider"></li>
				';
				$langcode = 'en';
				if ($langs->getDefaultLang(1) == 'es') $langcode = 'es';
				if ($langs->getDefaultLang(1) == 'fr') $langcode = 'fr';
				print '
	            <li><a class="dropdown-item" href="https://www.dolicloud.com/'.$langcode.'/faq" target="_newfaq">'.$langs->trans("FAQs").'</a></li>
            </ul>
          </li>

          <li class="nav-item'.($mode == 'myaccount'?' active':'').' dropdown">
             <a class="nav-link dropdown-toggle" data-toggle="dropdown" href="#"><i class="fa fa-user"></i> '.$langs->trans("MyAccount").'</a>
             <ul class="dropdown-menu">
                 <li><a class="dropdown-item" href="'.$_SERVER["PHP_SELF"].'?mode=myaccount"><i class="fa fa-user"></i> '.$langs->trans("MyAccount").'</a></li>
                 <li class="dropdown-divider"></li>
                 <li><a class="dropdown-item" href="'.$_SERVER["PHP_SELF"].'?mode=logout"><i class="fa fa-sign-out"></i> '.$langs->trans("Logout").'</a></li>
             </ul>
           </li>

        </ul>


      </div>
    </nav>
';


print '
    <div class="container">
		<br>
';


//var_dump($_SESSION["dol_loginsellyoursaas"]);
//var_dump($user);


// Special case - when coming from a specific contract id $welcomid
if ($welcomecid > 0)
{
	$contract = $listofcontractid[$welcomecid];
	$contract->fetch_thirdparty();

	print '
      <div class="jumbotron">
        <div class="col-sm-8 mx-auto">


		<!-- BEGIN PAGE HEAD -->
		<div class="page-head">
		<!-- BEGIN PAGE TITLE -->
		<div class="page-title">
		<h1>'.$langs->trans("Welcome").'</h1>
		</div>
		<!-- END PAGE TITLE -->
		</div>
		<!-- END PAGE HEAD -->


		<!-- BEGIN PORTLET -->
		<div class="portletnoborder light">

		<div class="portlet-header">
		<div class="caption">
		<span class="caption-subject font-green-sharp bold uppercase">'.$langs->trans("InstallationComplete").'</span>
		</div>
		</div>';

	if (in_array($contract->thirdparty->country_code, array('aaa', 'bbb')))
	{
		print '
		<div class="portlet-body">
		<p>
		'.$langs->trans("YourCredentialToAccessYourInstanceHasBeenSentByEmail").'
		</p>

		</div>';
	}
	else
	{
	print '
		<div class="portlet-body">
		<p>
		'.$langs->trans("YouCanAccessYourInstance", $contract->array_options['options_plan']).'&nbsp:
		</p>
		<p class="well">
		'.$langs->trans("URL").' : <a href="http://'.$contract->ref_customer.'" target="_blank">'.$contract->ref_customer.'</a>';

		print '<br> '.$langs->trans("Username").' : '.($_SESSION['initialapplogin']?$_SESSION['initialapplogin']:'NA').'
		<br> '.$langs->trans("Password").' : '.($_SESSION['initialapppassword']?$_SESSION['initialapppassword']:'NA').'
		</p>
		<p>
		<a class="btn btn-primary" target="_blank" href="http://'.$contract->ref_customer.'">
		'.$langs->trans("TakeMeTo", $contract->array_options['options_plan']).'
		</a>
		</p>

		</div>';
	}

	print '
		</div> <!-- END PORTLET -->


        </div>
      </div>
	';
}


if (! empty($conf->global->SELLYOURSAAS_ANNOUNCE))	// Show warning
{

	print '
		<div class="note note-warning">
		<h4 class="block">'.$langs->trans($conf->global->SELLYOURSAAS_ANNOUNCE).'</h4>
		</div>
	';
}


if (empty($welcomecid))	// Show warning
{
	foreach ($listofcontractid as $contractid => $contract)
	{
		$isapaidinstance = sellyoursaasIsPaidInstance($contract);
		$expirationdate = sellyoursaasGetExpirationDate($contract);

		if (! $isapaidinstance && $contract->array_options['options_date_endfreeperiod'] > 0)
		{
			$dateendfreeperiod = $contract->array_options['options_date_endfreeperiod'];
			if (! is_numeric($dateendfreeperiod)) $dateendfreeperiod = dol_stringtotime($dateendfreeperiod);
			$delaybeforeendoftrial = ($dateendfreeperiod - dol_now());
			$delayindays = round($delaybeforeendoftrial / 3600 / 24);

			if ($delaybeforeendoftrial > 0)
			{

				$firstline = reset($contract->lines);
				print '
					<div class="note note-warning">
					<h4 class="block">'.$langs->trans("XDaysBeforeEndOfTrial", abs($delayindays), $contract->ref_customer).' !</h4>
					<p>
					<a href="register_paymentmode.php" class="btn btn-warning">'.$langs->trans("AddAPaymentMode").'</a>
					</p>
					</div>
				';
			}
			else
			{
				$firstline = reset($contract->lines);
				print '
					<div class="note note-warning">
					<h4 class="block">'.$langs->trans("XDaysAfterEndOfTrial", $contract->ref_customer, abs($delayindays)).' !</h4>
					<p>
					<a href="register_paymentmode.php" class="btn btn-warning">'.$langs->trans("AddAPaymentModeToRestoreInstance").'</a>
					</p>
					</div>
				';
			}
		}

		/*
		if ($isapaidinstance && $expirationdate > 0)
		{
			$delaybeforeexpiration = ($expirationdate - dol_now());
			$delayindays = round($delaybeforeexpiration / 3600 / 24);

			// TODO
			if ($delaybeforeexpiration > 0)
			{

			}
			else
			{

			}
		}*/


		// Test if there is a paiment error to ask to fix payment data
		// @TODO

	}
}



if ($mode == 'dashboard')
{
	$nbofinstances = count($listofcontractid);
	$nboftickets = $langs->trans("SoonAvailable");

	print '
	<div class="page-content-wrapper">
			<div class="page-content">


	     <!-- BEGIN PAGE HEADER-->
	<!-- BEGIN PAGE HEAD -->
	<div class="page-head">
	  <!-- BEGIN PAGE TITLE -->
	<div class="page-title">
	  <h1>'.$langs->trans("Dashboard").'</h1>
	</div>
	<!-- END PAGE TITLE -->


	</div>
	<!-- END PAGE HEAD -->
	<!-- END PAGE HEADER-->


	    <div class="row">
	      <div class="col-md-6">

	        <div class="portlet light" id="planSection">

	          <div class="portlet-title">
	            <div class="caption">
	              <span class="caption-subject font-green-sharp bold uppercase">'.$langs->trans("MyInstances").'</span>
	            </div>
	          </div>

	          <div class="portlet-body">

	            <div class="row">

	              <div class="col-md-9">
					'.$langs->trans("NbOfInstances").'
	              </div>
	              <div class="col-md-3 right">
	                <h2>'.$nbofinstances.'</h2>
	              </div>
	            </div> <!-- END ROW -->

				<div class="row">
				<div class="center col-md-12">
					<br>
					<a href="'.$_SERVER["PHP_SELF"].'?mode=instances" class="btn default btn-xs green-stripe">
	            	'.$langs->trans("SeeDetailsAndOptions").'
	                </a>
				</div></div>

	          </div> <!-- END PORTLET-BODY -->

	        </div> <!-- END PORTLET -->

	      </div> <!-- END COL -->


	      <div class="col-md-6">
	        <div class="portlet light" id="paymentMethodSection">

	          <div class="portlet-title">
	            <div class="caption">
	              <span class="caption-subject font-green-sharp bold uppercase">'.$langs->trans("MyAccount").'</span>
	            </div>
	          </div>

	          <div class="portlet-body">
				<div class="row">
				<div class="col-md-12">
	                ';
					if (empty($welcomecid))		// If we just created an instance, we don't show warnings yet.
					{
		                $missing = 0;
		                if (empty($mythirdpartyaccount->array_options['options_firstname'])) $missing++;
		                if (empty($mythirdpartyaccount->array_options['options_lastname'])) $missing++;
		                if (empty($mythirdpartyaccount->tva_intra)) $missing++;

		                if (! $missing)
		                {
							print $langs->trans("ProfileIsComplete");
		                }
		                else
		                {
		                	print $langs->trans("ProfileIsNotComplete", $missing, $_SERVER["PHP_SELF"].'?mode=myaccount');
		                	print ' '.img_warning();
		                }
					}
	                print '
	            </div>
				</div>

				<div class="row">
				<div class="center col-md-12">
					<br>
					<a href="'.$_SERVER["PHP_SELF"].'?mode=myaccount" class="btn default btn-xs green-stripe">
	            	'.$langs->trans("SeeOrEditProfile").'
	                </a>
				</div>
				</div>

	          </div> <!-- END PORTLET-BODY -->

	        </div> <!-- END PORTLET -->
	      </div><!-- END COL -->


	    </div> <!-- END ROW -->

	';

	print '
	    <div class="row">


	      <div class="col-md-6">
	        <div class="portlet light" id="paymentMethodSection">

	          <div class="portlet-title">
	            <div class="caption">
	              <span class="caption-subject font-green-sharp bold uppercase">'.$langs->trans("PaymentBalance").'</span>
	            </div>
	          </div>';

				//var_dump($contract->linkedObjects['facture']);
				//dol_sort_array($contract->linkedObjects['facture'], 'date');
				$nbinvoicenotpayed = 0;
				$amountdue = 0;
				foreach ($listofcontractid as $id => $contract)
				{
					$contract->fetchObjectLinked();
					if (is_array($contract->linkedObjects['facture']))
					{
						foreach($contract->linkedObjects['facture'] as $idinvoice => $invoice)
						{
							if ($invoice->statut != $invoice::STATUS_CLOSED)
							{
								$nbinvoicenotpayed++;
							}
							$alreadypayed = $invoice->getSommePaiement();
							$amount_credit_notes_included = $invoice->getSumCreditNotesUsed();
							$amountdue = $invoice->total_ttc - $alreadypayed - $amount_credit_notes_included;
						}
					}
				}
				print '
	          <div class="portlet-body">

				<div class="row">
				<div class="col-md-9">
	                '.$langs->trans("UnpaidInvoices").'
				</div>
				<div class="col-md-3 right"><h2>';
				if ($nbinvoicenotpayed > 0) print '<font style="color: orange">';
				print $nbinvoicenotpayed;
				if ($nbinvoicenotpayed) print '</font>';
				print '<h2></div>
	            </div>
				<div class="row">
				<div class="col-md-9">
	                '.$langs->trans("RemainderToPay").'
				</div>
				<div class="col-md-3 right"><h2>';
				if ($amountdue > 0) print '<font style="color: orange">';
				print price($amountdue, 1, $langs, 0, -1, -1, $conf->currency);
				if ($amountdue > 0) print '</font>';
				print '</h2></div>
	            </div>

				<div class="row">
				<div class="center col-md-12">
					<br>
					<a href="'.$_SERVER["PHP_SELF"].'?mode=billing" class="btn default btn-xs green-stripe">
	            	'.$langs->trans("SeeDetailsOfPayments").'
	                </a>
				</div>
				</div>

	          </div> <!-- END PORTLET-BODY -->

	        </div> <!-- END PORTLET -->
	      </div><!-- END COL -->


	      <div class="col-md-6">
	        <div class="portlet light" id="paymentMethodSection">

	          <div class="portlet-title">
	            <div class="caption">
	              <span class="caption-subject font-green-sharp bold uppercase">'.$langs->trans("SupportTickets").'</span>
	            </div>
	          </div>';

			$nboftickets = 0;
			$nbofopentickets = 0;

			print '
	          <div class="portlet-body">

	            <div class="row">
	              <div class="col-md-9">
					'.$langs->trans("NbOfTickets").'
	              </div>
	              <div class="col-md-3 right"><h2>
	                '.$nboftickets.'
	              </h2></div>
	            </div> <!-- END ROW -->

	            <div class="row">
	              <div class="col-md-9">
					'.$langs->trans("NbOfOpenTickets").'
	              </div>
	              <div class="col-md-3 right"><h2>';
					if ($nbofopentickets > 0) print '<font style="color: orange">';
					print $nbofopentickets;
					if ($nbofopentickets > 0) print '</font>';
	                print '</h2>
	              </div>
	            </div> <!-- END ROW -->

				<div class="row">
				<div class="center col-md-12">
					<br>
					<a href="'.$_SERVER["PHP_SELF"].'?mode=support" class="btn default btn-xs green-stripe">
	            	'.$langs->trans("SeeDetailsOfTickets").'
	                </a>
				</div></div>

	          </div> <!-- END PORTLET-BODY -->

	        </div> <!-- END PORTLET -->
	      </div><!-- END COL -->

	    </div> <!-- END ROW -->
	';

	print '

		</div>


	    </div>
		</div>
	';
}

if ($mode == 'instances')
{
	print '
	<div class="page-content-wrapper">
			<div class="page-content">


	     <!-- BEGIN PAGE HEADER-->
	<!-- BEGIN PAGE HEAD -->
	<div class="page-head">
	  <!-- BEGIN PAGE TITLE -->
	<div class="page-title">
	  <h1>'.$langs->trans("MyInstances").'</h1>
	</div>
	<!-- END PAGE TITLE -->


	</div>
	<!-- END PAGE HEAD -->
	<!-- END PAGE HEADER-->';

	foreach ($listofcontractid as $id => $contract)
	{
		$planref = $contract->array_options['options_plan'];
		$statuslabel = $contract->array_options['options_deployment_status'];
		$instancename = preg_replace('/\..*$/', '', $contract->ref_customer);

		$dbprefix = $contract->array_options['options_db_prefix'];
		if (empty($dbprefix)) $dbprefix = 'llx_';


		// Get info about PLAN of Contract
		$package = new Packages($db);
		$package->fetch(0, $planref);
		$planlabel = ($package->label?$package->label:$planref);
		$planid = 0;
		$freeperioddays = 0;
		$directaccess = 0;
		foreach($contract->lines as $keyline => $line)
		{
			if ($line->statut == 5)
			{
				$statuslabel = 'suspended';
			}

			$tmpproduct = new Product($db);
			if ($line->fk_product > 0)
			{
				$tmpproduct->fetch($line->fk_product);
				if ($tmpproduct->array_options['options_app_or_option'] == 'app')
				{
					$planlabel = $tmpproduct->label;		// Warning, label is in language of user
					$planid = $tmpproduct->id;
					$freeperioddays = $tmpproduct->array_options['options_freeperioddays'];
					$directaccess = $tmpproduct->array_options['options_directaccess'];
					break;
				}
			}
		}

		$color = "green";
		if ($statuslabel == 'processing') $color = 'orange';
		if ($statuslabel == 'suspended') $color = 'orange';


		print '
		    <div class="row" id="contractid'.$contract->id.'" data-contractref="'.$contract->ref.'">
		      <div class="col-md-12">

				<div class="portlet light">

			      <div class="portlet-title">
			        <div class="caption">';
					  print '<form class="inline-block centpercent" action="'.$_SERVER["PHP_SELF"].'" method="POST">';

			          // Instance name
			          print '<span class="caption-subject font-green-sharp bold uppercase">'.$instancename.'</span>
			          <span class="caption-helper"> - '.($package->label?$package->label:$planref).'</span>	<!-- This is package, not PLAN -->';

					  // Instance status
			          print '<span class="caption-helper floatright clearboth">'.$langs->trans("Status").' : <span class="bold uppercase" style="color:'.$color.'">';
			          if ($statuslabel == 'processing') print $langs->trans("DeploymentInProgress");
			          elseif ($statuslabel == 'done') print $langs->trans("Running");
			          elseif ($statuslabel == 'suspended') print $langs->trans("Suspended");
			          else print $statuslabel;
			          print '</span></span><br>';

					  print '<p style="padding-top: 8px;" class="clearboth">
			            <!-- <span class="caption-helper">'.$langs->trans("ID").' : '.$contract->ref.'</span><br> -->
			            <span class="caption-helper">';
							if ($contract->array_options['options_deployment_status'] == 'processing')
							{
								print $langs->trans("DateStart").' : <span class="bold">'.dol_print_date($contract->array_options['options_deployment_date_start'], 'dayhour').'</span>';
								if ((dol_now() - $contract->array_options['options_deployment_date_start']) > 120)	// More then 2 minutes ago
								{
									print ' - <a href="register_instance.php?reusecontractid='.$contract->id.'">'.$langs->trans("Restart").'</a>';
								}
							}
							elseif ($contract->array_options['options_deployment_status'] == 'done')
							{
								print $langs->trans("DeploymentDate").' : <span class="bold">'.dol_print_date($contract->array_options['options_deployment_date_end'], 'dayhour').'</span>';
							}
							else
							{
								print $langs->trans("DeploymentDate").' : <span class="bold">'.dol_print_date($contract->array_options['options_deployment_date_end'], 'dayhour').'</span>';
								print '<br>';
								print $langs->trans("UndeploymentDate").' : <span class="bold">'.dol_print_date($contract->array_options['options_undeployment_date'], 'dayhour').'</span>';
							}
						print '
						</span><br>';
						// URL
						print '<span class="caption-helper">';
						if ($conf->dol_optimize_smallscreen) print $langs->trans("URL");
						else print $langs->trans("YourURLToGoOnYourAppInstance");
						print ' : <a class="font-green-sharp linktoinstance" href="https://'.$contract->ref_customer.'" target="blankinstance">'.$contract->ref_customer.'</a>';
						print '</span><br>';

						// Calculate price on invoicing
						$contract->fetchObjectLinked();
						$foundtemplate=0;
						$pricetoshow = ''; $priceinvoicedht = 0;
						$freqlabel = array('d'=>$langs->trans('Day'), 'm'=>$langs->trans('Month'), 'y'=>$langs->trans('Year'));
						if (is_array($contract->linkedObjects['facturerec']))
						{
							foreach($contract->linkedObjects['facturerec'] as $idtemplateinvoice => $templateinvoice)
							{
								$foundtemplate++;
								if ($templateinvoice->suspended) print $langs->trans("InvoicingSuspended");
								else
								{
									if ($templateinvoice->unit_frequency == 'm' && $templateinvoice->frequency == 1)
									{
										$pricetoshow = price($templateinvoice->total_ht, 1, $langs, 0, -1, -1, $conf->currency).' '.$langs->trans("HT").' / '.$langs->trans("Month");
										$priceinvoicedht = $templateinvoice->total_ht;
									}
									elseif ($templateinvoice->unit_frequency == 'y' && $templateinvoice->frequency == 1)
									{
										$pricetoshow = price($templateinvoice->total_ht, 1, $langs, 0, -1, -1, $conf->currency).' '.$langs->trans("HT").' / '.$langs->trans("Year");
										$priceinvoicedht = $templateinvoice->total_ht;
									}
									else
									{
										$pricetoshow  = $templateinvoice->frequency.' '.$freqlabel[$templateinvoice->unit_frequency];
										$pricetoshow .= ', ';
										$pricetoshow .= price($templateinvoice->total_ht, 1, $langs, 0, -1, -1, $conf->currency).' '.$langs->trans("HT");
										$priceinvoicedht = $templateinvoice->total_ht;
									}
								}
							}
						}

						print '
			          </p>';
					print '</form>';
					print '</div>';
			     print '</div>


			      <div class="portlet-body" style="">

			        <div class="tabbable-custom nav-justified">
			          <ul class="nav nav-tabs nav-justified">
			            <li><a id="a_tab_resource_'.$contract->id.'" href="#tab_resource_'.$contract->id.'" data-toggle="tab"'.(! in_array($action, array('updateurlxxx')) ? ' class="active"' : '').'>'.$langs->trans("ResourcesAndOptions").'</a></li>
			            <li><a id="a_tab_domain_'.$contract->id.'" href="#tab_domain_'.$contract->id.'" data-toggle="tab"'.($action == 'updateurlxxx' ? ' class="active"' : '').'>'.$langs->trans("Domain").'</a></li>';
			     		if ($directaccess) print '<li><a id="a_tab_ssh_'.$contract->id.'" href="#tab_ssh_'.$contract->id.'" data-toggle="tab">'.$langs->trans("SSH").' / '.$langs->trans("SFTP").'</a></li>';
			     		if ($directaccess) print '<li><a id="a_tab_db_'.$contract->id.'" href="#tab_db_'.$contract->id.'" data-toggle="tab">'.$langs->trans("Database").'</a></li>';
			            print '<li><a id="a_tab_danger_'.$contract->id.'" href="#tab_danger_'.$contract->id.'" data-toggle="tab">'.$langs->trans("DangerZone").'</a></li>
			          </ul>

			          <div class="tab-content">

			            <div class="tab-pane active" id="tab_resource_'.$contract->id.'">
							<p class="opacitymedium" style="padding: 15px; margin-bottom: 5px;">'.$langs->trans("YourResourceAndOptionsDesc").' :</p>
				            <div style="padding-left: 12px; padding-bottom: 12px; padding-right: 12px">';
							foreach($contract->lines as $keyline => $line)
							{
								//var_dump($line);
								print '<div class="resource inline-block boxresource">';
			                  	print '<div class="">';

			                  	$resourceformula='';
			                  	$tmpproduct = new Product($db);
			                  	if ($line->fk_product > 0)
			                  	{
				                  	$tmpproduct->fetch($line->fk_product);

				                  	print $tmpproduct->show_photos($conf->product->dir_output, 1, 1, 1, 0, 0, 40, 40, 1, 1, 1);

				                  	//var_dump($tmpproduct->array_options);
				                  	/*if ($tmpproduct->array_options['options_app_or_option'] == 'app')
				                  	{
				                  		print '<span class="opacitymedium small">'.'&nbsp;'.'</span><br>';
				                  	}
				                  	if ($tmpproduct->array_options['options_app_or_option'] == 'system')
				                  	{
				                  		print '<span class="opacitymedium small">'.'&nbsp;'.'</span><br>';
				                  	}
				                  	if ($tmpproduct->array_options['options_app_or_option'] == 'option')
				                  	{
				                  		print '<span class="opacitymedium small">'.$langs->trans("Option").'</span><br>';
				                  	}*/

				                  	$labelprod = $tmpproduct->label;
				                  	$labelprodsing = '';
				                  	if (preg_match('/instance/i', $tmpproduct->label))
				                  	{
				                  		$labelprod = $langs->trans("Application");
				                  		$labelprodsing = $langs->trans("Application");
				                  	}
				                  	elseif (preg_match('/users/i', $tmpproduct->label))
				                  	{
				                  		$labelprod = $langs->trans("Users");
				                  		$labelprodsing = $langs->trans("User");
				                  	}
									// Label
				                  	print '<span class="opacitymedium small">'.$labelprod.'</span><br>';
				                  	// Qty
				                  	$resourceformula = $tmpproduct->array_options['options_resource_formula'];
				                  	if (preg_match('/SQL:/', $resourceformula))
				                  	{
				                  		$resourceformula = preg_match('/__d__/', $dbprefix, $resourceformula);
				                  	}
				                  	if (preg_match('/DISK:/', $resourceformula))
				                  	{
				                  		$resourceformula = $resourceformula;
				                  	}

									print '<span class="font-green-sharp counternumber">'.$line->qty.'</span>';
									print '<br>';
									if ($line->price)
									{
										print '<span class="opacitymedium small">'.price($line->price, 1, $langs, 0, -1, -1, $conf->currency);
										if ($line->qty > 1 && $labelprodsing) print ' / '.$labelprodsing;
										// TODO
										print ' / '.$langs->trans("Month");
										print '</span>';
									}
									else
									{
										print '<span class="opacitymedium small">'.price($line->price, 1, $langs, 0, -1, -1, $conf->currency);
										// TODO
										print ' / '.$langs->trans("Month");
										print '</span>';
									}
			                  	}
			                  	else	// If there is no product, this is users
			                  	{
			                  		print '<span class="opacitymedium small">';
			                  		print ($line->label ? $line->label : $line->libelle);
			                  		// TODO
			                  		print ' / '.$langs->trans("Month");
			                  		print '</span>';
			                  	}

			                  	print '</div>';
								print '</div>';
							}

							print '<br><br>';
							// Plan
							print '<span class="caption-helper">'.$langs->trans("YourSubscriptionPlan").' : ';
							if ($action == 'changeplan' && $planid > 0 && $id == GETPOST('id','int'))
							{
								print '<input type="hidden" name="mode" value="instances"/>';
								print '<input type="hidden" name="action" value="updateplan" />';
								print '<input type="hidden" name="contractid" value="'.$contract->id.'" />';

								// List of available plans
								$arrayofplans=array();
								$sqlproducts = 'SELECT p.rowid, p.ref, p.label FROM '.MAIN_DB_PREFIX.'product as p, '.MAIN_DB_PREFIX.'product_extrafields as pe';
								$sqlproducts.= ' WHERE p.tosell = 1 AND p.entity = '.$conf->entity;
								$sqlproducts.= " AND pe.fk_object = p.rowid AND pe.app_or_option = 'app'";
								$sqlproducts.= " AND (p.rowid = ".$planid." OR 1 = 1)";		// TODO Restict on compatible plans...
								$resqlproducts = $db->query($sqlproducts);
								if ($resqlproducts)
								{
									$num = $db->num_rows($resqlproducts);
									$i=0;
									while($i < $num)
									{
										$obj = $db->fetch_object($resqlproducts);
										if ($obj)
										{
											$arrayofplans[$obj->rowid]=$obj->label;
										}
										$i++;
									}
								}
								print $form->selectarray('planid', $arrayofplans, $planid, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
								print '<input type="submit" class="btn btn-warning default change-plan-link" name="changeplan" value="'.$langs->trans("ChangePlan").'">';
							}
							else
							{
								print '<span class="bold">'.$planlabel.'</span>';
								if ($priceinvoicedht == $contrat->total_ht)
								{
									print ' - <a href="'.$_SERVER["PHP_SELF"].'?mode=instances&action=changeplan&id='.$contract->id.'#contractid'.$contract->id.'">'.$langs->trans("ChangePlan").'</a>';
								}
							}
							print '</span>';
							print '<br>';
							// Billing
							print '<span class="caption-helper spanbilling">'.$langs->trans("Billing").' : ';
							if ($priceinvoicedht != $contrat->total_ht)
							{
								print $langs->trans("FlatOrDiscountedPrice").' = ';
							}
							print '<span class="bold">'.$pricetoshow.'</span>';
							if ($foundtemplate == 0)	// Same than ispaid
							{
								print ' <span style="color:'.$color.'">';
								if ($contract->array_options['options_date_endfreeperiod'] > 0) print $langs->trans("TrialUntil", dol_print_date($contract->array_options['options_date_endfreeperiod'], 'day'));
								else print $langs->trans("Trial");
								print '</span>';
								if ($contract->array_options['options_date_endfreeperiod'] < dol_now())
								{
									if ($statuslabel == 'suspended') print ' - <span style="color: orange">'.$langs->trans("Suspended").'</span>';
									else print ' - <span style="color: orange">'.$langs->trans("SuspendWillBeDoneSoon").'</span>';
								}
								if ($statuslabel == 'suspended') print ' - <a href="register_paymentmode.php">'.$langs->trans("AddAPaymentModeToRestoreInstance").'</a>';
								else print ' - <a href="register_paymentmode.php">'.$langs->trans("AddAPaymentMode").'</a>';
							}
							if ($foundtemplate > 1) print ' - <span class="bold">'.$langs->trans("WarningFoundMoreThanOneInvoicingTemplate").'</span>';
							print '</span>';

			            	print '
							  </div>
			              </div>

			            <div class="tab-pane" id="tab_domain_'.$contract->id.'">
			                <p class="opacitymedium" style="padding: 15px">'.$langs->trans("TheURLDomainOfYourInstance").' :</p>
							<form class="form-group" action="'.$_SERVER["PHP_SELF"].'" method="POST">
							<div class="col-md-9">
								<input type="text" class="urlofinstance" value="'.$contract->ref_customer.'">
								<input type="hidden" name="mode" value="instances"/>
								<input type="hidden" name="action" value="updateurl" />
								<input type="hidden" name="contractid" value="'.$contract->id.'" />
								<input type="hidden" name="tab" value="domain_'.$contract->id.'" />
								<input type="submit" class="btn btn-warning default change-domain-link" name="changedomain" value="'.$langs->trans("ChangeDomain").'">
							</div>
						  	</form>
			            </div>

			            <div class="tab-pane" id="tab_ssh_'.$contract->id.'">
			                <p class="opacitymedium" style="padding: 15px">'.$langs->trans("SSHFTPDesc").' :</p>
			                <form class="form-horizontal" role="form">
			                <div class="form-body">
			                  <div class="form-group">
			                    <label class="col-md-3 control-label">'.$langs->trans("Hostname").'</label>
			                    <div class="col-md-9">
			                      <input type="text" disabled="disabled" class="form-control input-medium" value="'.$contract->array_options['options_hostname_os'].'">
			                    </div>
			                  </div>
			                  <div class="form-group">
			                    <label class="col-md-3 control-label">'.$langs->trans("Port").'</label>
			                    <div class="col-md-9">
			                      <input type="text" disabled="disabled" class="form-control input-medium" value="'.($contract->array_options['options_port_os']?$contract->array_options['options_port_os']:22).'">
			                    </div>
			                  </div>
			                  <div class="form-group">
			                    <label class="col-md-3 control-label">'.$langs->trans("SFTP Username").'</label>
			                    <div class="col-md-9">
			                      <input type="text" disabled="disabled" class="form-control input-medium" value="'.$contract->array_options['options_username_os'].'">
			                    </div>
			                  </div>
			                  <div class="form-group">
			                    <label class="col-md-3 control-label">'.$langs->trans("Password").'</label>
			                    <div class="col-md-9">
			                      <input type="text" disabled="disabled" class="form-control input-medium" value="'.$contract->array_options['options_password_os'].'">
			                    </div>
			                  </div>
			                </div>
			                </form>
			              </div> <!-- END TAB PANE -->

			              <div class="tab-pane" id="tab_db_'.$contract->id.'">
			                <p class="opacitymedium" style="padding: 15px">'.$langs->trans("DBDesc").' :</p>
			                <form class="form-horizontal" role="form">
			                <div class="form-body">
			                  <div class="form-group">
			                    <label class="col-md-3 control-label">'.$langs->trans("Hostname").'</label>
			                    <div class="col-md-9">
			                      <input type="text" disabled="disabled" class="form-control input-medium" value="'.$contract->array_options['options_hostname_db'].'">
			                    </div>
			                  </div>
			                  <div class="form-group">
			                    <label class="col-md-3 control-label">'.$langs->trans("Port").'</label>
			                    <div class="col-md-9">
			                      <input type="text" disabled="disabled" class="form-control input-medium" value="'.$contract->array_options['options_port_db'].'">
			                    </div>
			                  </div>
			                  <div class="form-group">
			                    <label class="col-md-3 control-label">'.$langs->trans("DatabaseName").'</label>
			                    <div class="col-md-9">
			                      <input type="text" disabled="disabled" class="form-control input-medium" value="'.$contract->array_options['options_database_db'].'">
			                    </div>
			                  </div>
			                  <div class="form-group">
			                    <label class="col-md-3 control-label">'.$langs->trans("DatabaseLogin").'</label>
			                    <div class="col-md-9">
			                      <input type="text" disabled="disabled" class="form-control input-medium" value="'.$contract->array_options['options_username_db'].'">
			                    </div>
			                  </div>
			                  <div class="form-group">
			                    <label class="col-md-3 control-label">'.$langs->trans("Password").'</label>
			                    <div class="col-md-9">
			                      <input type="text" disabled="disabled" class="form-control input-medium" value="'.$contract->array_options['options_password_db'].'">
			                    </div>
			                  </div>
			                </div>
			                </form>
			              </div> <!-- END TAB PANE -->

			            <div class="tab-pane" id="tab_danger_'.$contract->id.'">
						<form class="form-group" action="'.$_SERVER["PHP_SELF"].'" method="POST">
			              <div class="">
			                <p class="opacitymedium" style="padding: 15px">
			                    '.$langs->trans("PleaseBeSure", $contract->ref_customer).'
			                </p>
							<p class="center" style="padding-bottom: 15px">
								<input type="text" class="center urlofinstancetodestroy" name="urlofinstancetodestroy" value="'.GETPOST('urlofinstancetodestroy','alpha').'" placeholder="">
							</p>
							<p class="center">
								<input type="hidden" name="mode" value="instances"/>
								<input type="hidden" name="action" value="undeploy" />
								<input type="hidden" name="contractid" value="'.$contract->id.'" />
								<input type="hidden" name="tab" value="danger_'.$contract->id.'" />
								<input type="submit" class="btn btn-danger" name="changedomain" value="'.$langs->trans("UndeployInstance").'">
							</p>
			              </div>
						</form>
			            </div> <!-- END TAB PANE -->

			          </div> <!-- END TAB CONTENT -->
			        </div> <!-- END TABABLE CUSTOM-->

			      </div><!-- END PORTLET-BODY -->


				</div> <!-- END PORTLET -->



		      </div> <!-- END COL -->


		    </div> <!-- END ROW -->
		';
	}

	print '
	    </div>
		</div>
	';

	if (GETPOST('tab','alpha'))
	{
		print '<script type="text/javascript" language="javascript">
		jQuery(document).ready(function() {
			console.log("Click on '.GETPOST('tab','alpha').'");
			jQuery("#a_tab_'.GETPOST('tab','alpha').'").click();
		});
		</script>';
	}
}




if ($mode == 'billing')
{
	print '
	<div class="page-content-wrapper">
			<div class="page-content">


	     <!-- BEGIN PAGE HEADER-->
	<!-- BEGIN PAGE HEAD -->
	<div class="page-head">
	  <!-- BEGIN PAGE TITLE -->
	<div class="page-title">
	  <h1>'.$langs->trans("Billing").' <small>'.$langs->trans("BillingDesc").'</small></h1>
	</div>
	<!-- END PAGE TITLE -->


	</div>
	<!-- END PAGE HEAD -->
	<!-- END PAGE HEADER-->


	    <div class="row">
	      <div class="col-md-9">

	        <div class="portlet light" id="planSection">

	          <div class="portlet-title">
	            <div class="caption">
	              <span class="caption-subject font-green-sharp bold uppercase">'.$langs->trans("MyInvoices").'</span>
	            </div>
	          </div>

';

		foreach ($listofcontractid as $id => $contract)
		{
			$planref = $contract->array_options['options_plan'];
			$statuslabel = $contract->array_options['options_deployment_status'];
			$instancename = preg_replace('/\..*$/', '', $contract->ref_customer);

			$package = new Packages($db);
			$package->fetch(0, $planref);

			$color = "green";
			if ($statuslabel == 'processing') $color = 'orange';
			if ($statuslabel == 'suspended') $color = 'orange';

			$dbprefix = $contract->array_options['options_db_prefix'];
			if (empty($dbprefix)) $dbprefix = 'llx_';

			print '
	        <div class="portlet-body">

	            <div class="row" style="border-bottom: 1px solid #ddd;">

	              <div class="col-md-6">
			          <span class="caption-subject font-green-sharp bold uppercase">'.$instancename.'</span>
			          <span class="caption-helper"> - '.($package->label?$package->label:$planref).'</span>	<!-- This is package, not PLAN -->
	              </div><!-- END COL -->
	              <div class="col-md-2 hideonsmartphone">
	                '.$langs->trans("Date").'
	              </div>
	              <div class="col-md-2 hideonsmartphone">
	                '.$langs->trans("Amount").'
	              </div>
	              <div class="col-md-2 hideonsmartphone">
	                '.$langs->trans("Status").'
	              </div>
	            </div> <!-- END ROW -->
			';

			$contract->fetchObjectLinked();
			$foundtemplate=0;
			$freqlabel = array('d'=>$langs->trans('Day'), 'm'=>$langs->trans('Month'), 'y'=>$langs->trans('Year'));
			if (is_array($contract->linkedObjects['facture']) && count($contract->linkedObjects['facture']) > 0)
			{
				function cmp($a, $b)
				{
					return strcmp($a->date, $b->date);
				}
				usort($contract->linkedObjects['facture'], "cmp");

				//var_dump($contract->linkedObjects['facture']);
				//dol_sort_array($contract->linkedObjects['facture'], 'date');
				foreach($contract->linkedObjects['facture'] as $idinvoice => $invoice)
				{
						print '
				            <div class="row" style="margin-top:20px">

				              <div class="col-md-6">
								';
								$url = $invoice->getLastMainDocLink($invoice->element, 0, 1);
								print '<a href="'.DOL_URL_ROOT.'/'.$url.'">'.$invoice->ref.' '.img_mime($invoice->ref.'.pdf', $langs->trans("File").': '.$invoice->ref.'.pdf').'</a>
				              </div>
				              <div class="col-md-2">
								'.dol_print_date($invoice->date, 'day').'
				              </div>
				              <div class="col-md-2">
								'.price(price2num($invoice->total_ttc), 1, $langs, 0, 0, 0, $conf->currency).'
				              </div>
				              <div class="col-md-2 nowrap">
								';
								$alreadypayed = $invoice->getSommePaiement();
								$amount_credit_notes_included = $invoice->getSumCreditNotesUsed();

								print $invoice->getLibStatut(2, $alreadypayed + $amount_credit_notes_included);
								print '
				              </div>

				            </div>
						';
				}
			}
			else
			{
				print '
				            <div class="row" style="margin-top:20px">

				              <div class="col-md-12">
							<span class="opacitymedium">'.$langs->trans("NoneF").'</span>
							  </div>
							</div>
					';
			}

			print '
	          </div> <!-- END PORTLET-BODY -->
			<br><br>
			';
		}

		print '

	        </div> <!-- END PORTLET -->



	      </div> <!-- END COL -->

	      <div class="col-md-3">
	        <div class="portlet light" id="paymentMethodSection">

	          <div class="portlet-title">
	            <div class="caption">
	              <i class="icon-credit-card font-green-sharp"></i>
	              <span class="caption-subject font-green-sharp bold uppercase">Payment Method</span>
	            </div>
	          </div>

	          <div class="portlet-body">
	            <p>

	                No payment method on file.
	                <br><br>
	                <a href="register_paymentmode.php" class="btn default btn-xs green-stripe">Add Payment Method</a>

	            </p>
	          </div> <!-- END PORTLET-BODY -->

	        </div> <!-- END PORTLET -->
	      </div><!-- END COL -->

	    </div> <!-- END ROW -->


	    </div>
		</div>
	';
}



if ($mode == 'myaccount')
{
	print '
	<div class="page-content-wrapper">
			<div class="page-content">


	     <!-- BEGIN PAGE HEADER-->
	<!-- BEGIN PAGE HEAD -->
	<div class="page-head">
	  <!-- BEGIN PAGE TITLE -->
	<div class="page-title">
	  <h1>'.$langs->trans("MyAccount").' <small>'.$langs->trans("YourPersonalInformation").'</small></h1>
	</div>
	<!-- END PAGE TITLE -->


	</div>
	<!-- END PAGE HEAD -->
	<!-- END PAGE HEADER-->


	    <div class="row">
	      <div class="col-md-6">

	        <div class="portlet light">
          <div class="portlet-title">
            <div class="caption-subject font-green-sharp bold uppercase">'.$langs->trans("Organization").'</div>
          </div>
          <div class="portlet-body">
            <form action="'.$_SERVER["PHP_SELF"].'" method="post">
				<input type="hidden" name="action" value="updatemythirdpartyaccount">
				<input type="hidden" name="mode" value="'.dol_escape_htmltag($mode).'">
              <div class="form-body">

                <div class="form-group">
                  <label>'.$langs->trans("NameOfCompany").'</label>
                  <input type="text" class="form-control" placeholder="'.$langs->trans("NameOfYourOrganization").'" value="'.$mythirdpartyaccount->name.'" name="orgName">
                </div>

                <div class="form-group">
                  <label>'.$langs->trans("AddressLine").'</label>
                  <input type="text" class="form-control" placeholder="'.$langs->trans("HouseNumberAndStreet").'" value="'.$mythirdpartyaccount->address.'" name="address">
                </div>
                <div class="form-group">
                  <label>'.$langs->trans("Town").'</label>
                  <input type="text" class="form-control" value="'.$mythirdpartyaccount->town.'" name="town">
                </div>
                <div class="form-group">
                  <label>'.$langs->trans("Zip").'</label>
                  <input type="text" class="form-control input-small" value="'.$mythirdpartyaccount->zip.'" name="zip">
                </div>
                <div class="form-group">
                  <label>'.$langs->trans("State").'</label>
                  <input type="text" class="form-control" placeholder="'.$langs->trans("StateOrCounty").'" name="stateorcounty" value="">
                </div>
                <div class="form-group">
                  <label>'.$langs->trans("Country").'</label><br>';
				$countryselected = $mythirdpartyaccount->country_code;
				print $form->select_country($countryselected, 'country_id', '', 0, 'minwidth300', 'code2', 0);
				print '
                </div>
                <div class="form-group">
                  <label>'.$langs->trans("VATIntra").'</label> ';
				if (! empty($mythirdpartyaccount->tva_assuj) && empty($mythirdpartyaccount->tva_intra))
					{
						print img_warning($langs->trans("WarningMandatorySetupNotComplete"), 'class="hideifnonassuj"');
					}
					print '
					<br>
                  <input type="checkbox" style="vertical-align: top" class="inline-block"'.($mythirdpartyaccount->tva_assuj?' checked="checked"':'').'" id="vatassuj" name="vatassuj"> '.$langs->trans("VATIsUsed").'
					<br>
                  <input type="text" class="input-small quatrevingtpercent hideifnonassuj" value="'.$mythirdpartyaccount->tva_intra.'" name="vatnumber" placeholder="">
                </div>
              </div>
              <!-- END FORM BODY -->

              <div>
                <input type="submit" name="submit" value="'.$langs->trans("Save").'" class="btn green-haze btn-circle">
              </div>

            </form>
            <!-- END FORM DIV -->
          </div> <!-- END PORTLET-BODY -->
        </div>

	    </div>
		';

		print '<script type="text/javascript" language="javascript">
		jQuery(document).ready(function() {
			jQuery("#vatassuj").click(function() {
				console.log("Click on vatassuj "+jQuery("#vatassuj").is(":checked");
				jQuery(".hideifnonassuj").hide();
				jQuery(".hideifnonassuj").show();
			});
		});
		</script>';

		print '

	      <div class="col-md-6">

			<div class="portlet light">
	          <div class="portlet-title">
	            <div class="caption-subject font-green-sharp bold uppercase">'.$langs->trans("YourAdminAccount").'</div>
	          </div>
	          <div class="portlet-body">
	            <form action="'.$_SERVER["PHP_SELF"].'" method="post">
				<input type="hidden" name="action" value="updatemythirdpartylogin">
				<input type="hidden" name="mode" value="'.dol_escape_htmltag($mode).'">
	              <div class="form-body">
	                <div class="form-group">
	                  <label>'.$langs->trans("Email").'</label>
	                  <input type="text" class="form-control" value="'.$mythirdpartyaccount->email.'" name="email">
	                </div>
	                <div class="row">
	                  <div class="col-md-6">
	                    <div class="form-group">
	                      <label>'.$langs->trans("Firstname").'</label> ';
							if (empty($mythirdpartyaccount->array_options['options_firstname'])) print img_warning($langs->trans("WarningMandatorySetupNotComplete"));
						print '
							<br>
	                      <input type="text" class="inline-block" value="'.$mythirdpartyaccount->array_options['options_firstname'].'" name="firstName">
	                    </div>
	                  </div>
	                  <div class="col-md-6">
	                    <div class="form-group">
	                      <label>'.$langs->trans("Lastname").'</label> ';
							if (empty($mythirdpartyaccount->array_options['options_lastname'])) print img_warning($langs->trans("WarningMandatorySetupNotComplete"));
						print '<br>
	                      <input type="text" class="inline-block" value="'.$mythirdpartyaccount->array_options['options_lastname'].'" name="lastName">
	                    </div>
	                  </div>
	                </div>
	              </div>
	              <div>
	                <input type="submit" name="submit" value="'.$langs->trans("Save").'" class="btn green-haze btn-circle">
	              </div>
	            </form>
	          </div>
	        </div>


			<div class="portlet light">
	          <div class="portlet-title">
	            <div class="caption-subject font-green-sharp bold uppercase">'.$langs->trans("Password").'</div>
	          </div>
	          <div class="portlet-body">
	            <form action="'.$_SERVER["PHP_SELF"].'" method="post" id="updatepassword">
				<input type="hidden" name="action" value="updatepassword">
				<input type="hidden" name="mode" value="'.dol_escape_htmltag($mode).'">
	              <div class="form-body">
	                <div class="form-group">
	                  <label>'.$langs->trans("Password").'</label>
	                  <input type="password" class="form-control" name="password">
	                </div>
	                <div class="form-group">
	                  <label>'.$langs->trans("RepeatPassword").'</label>
	                  <input type="password" class="form-control" name="password2">
	                </div>
	              </div>
	              <div>
	                <input type="submit" name="submit" value="'.$langs->trans("ChangePassword").'" class="btn green-haze btn-circle">
	              </div>
	            </form>
	          </div>
	        </div>
	      </div><!-- END COL -->

	    </div> <!-- END ROW -->


	    </div>
		</div>
	';
}



if ($mode == 'support')
{
	print '<center>';
	print $langs->trans("SoonAvailable").'...';
	print '</center>';
}


print '
	</div>






	<!-- Bootstrap core JavaScript
	================================================== -->
	<!-- Placed at the end of the document so the pages load faster -->
	<script src="dist/js/tether.min.js"></script>
	<script src="dist/js/popper.min.js"></script>
	<script src="dist/js/bootstrap.min.js"></script>
	<!--
	<script src="https://cdnjs.cloudflare.com/ajax/libs/tether/1.4.0/js/tether.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.13.0/umd/popper.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.0.0-beta.2/js/bootstrap.min.js"></script>
	-->

	</body>
</html>
';

llxFooter();

$db->close();
