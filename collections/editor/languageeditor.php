<?php
include_once('../../config/symbini.php');
include_once($SERVER_ROOT.'/classes/OccurrenceEditorManager.php');
include_once($SERVER_ROOT.'/classes/ProfileManager.php');
header("Content-Type: text/html; charset=".$CHARSET);
header('Access-Control-Allow-Origin: http://www.catalogueoflife.org/col/webservice');
//header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
//header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past

$occId = array_key_exists('occid',$_REQUEST)?$_REQUEST['occid']:0;
$tabTarget = array_key_exists('tabtarget',$_REQUEST)?$_REQUEST['tabtarget']:0;
$collId = array_key_exists('collid',$_REQUEST)?$_REQUEST['collid']:0;
$goToMode = array_key_exists('gotomode',$_REQUEST)?$_REQUEST['gotomode']:0;
$occIndex = array_key_exists('occindex',$_REQUEST)&&$_REQUEST['occindex']!=""?$_REQUEST['occindex']:false;
$ouid = array_key_exists('ouid',$_REQUEST)?$_REQUEST['ouid']:0;
$crowdSourceMode = array_key_exists('csmode',$_REQUEST)?$_REQUEST['csmode']:0;
$action = array_key_exists('submitaction',$_REQUEST)?$_REQUEST['submitaction']:'';
if(!$action && array_key_exists('carryloc',$_REQUEST)){
	$goToMode = 2;
}

//Create Occurrence Manager
$occManager;
if(strpos($action,'Determination') || strpos($action,'Verification')){
	$occManager = new OccurrenceEditorDeterminations();
}
elseif(strpos($action,'Image')){
	$occManager = new OccurrenceEditorImages();
}
else{
	$occManager = new OccurrenceEditorManager();
}

if($crowdSourceMode){
	$occManager->setCrowdSourceMode(1);
}

$isEditor = 0;		//If not editor, edits will be submitted to omoccuredits table but not applied to omoccurrences
$displayQuery = 0;
$isGenObs = 0;
$collMap = Array();
$occArr = array();
$imgArr = array();
$specImgArr = array();
$fragArr = array();
$qryCnt = false;
$statusStr = '';
$navStr = '';

if($symbUid){
	//Set variables
	$occManager->setSymbUid($symbUid);
	$occManager->setOccId($occId);
	$occManager->setCollId($collId);
	$collMap = $occManager->getCollMap();
	if($occId && !$collId && !$crowdSourceMode) $collId = $collMap['collid'];

	if($collMap && $collMap['colltype']=='General Observations') $isGenObs = 1;

	//Bring in config variables
	if($isGenObs){
		if(file_exists('includes/config/occurVarGenObs'.$symbUid.'.php')){
			//Specific to particular collection
			include('includes/config/occurVarGenObs'.$symbUid.'.php');
		}
		elseif(file_exists('includes/config/occurVarGenObsDefault.php')){
			//Specific to Default values for portal
			include('includes/config/occurVarGenObsDefault.php');
		}
	}
	else{
		if($collId && file_exists('includes/config/occurVarColl'.$collId.'.php')){
			//Specific to particular collection
			include('includes/config/occurVarColl'.$collId.'.php');
		}
		elseif(file_exists('includes/config/occurVarDefault.php')){
			//Specific to Default values for portal
			include('includes/config/occurVarDefault.php');
		}
		if($crowdSourceMode && file_exists('includes/config/crowdSourceVar.php')){
			//Specific to Crowdsourcing
			include('includes/config/crowdSourceVar.php');
		}
	}
	if(isset($ACTIVATE_EXSICCATI) && $ACTIVATE_EXSICCATI) $occManager->setExsiccatiMode(true);

	if($isAdmin || ($collId && array_key_exists("CollAdmin",$userRights) && in_array($collId,$userRights["CollAdmin"]))){
		$isEditor = 1;
	}
	else{
		if($isGenObs){
			if(!$occId && array_key_exists("CollEditor",$userRights) && in_array($collId,$userRights["CollEditor"])){
				//Approved General Observation editors can add records
				$isEditor = 2;
			}
			elseif($action){
				//Lets assume that Edits where submitted and they remain on same specimen, user is still approved
				 $isEditor = 2;
			}
			elseif($occManager->getObserverUid() == $symbUid){
				//Users can edit their own records
				$isEditor = 2;
			}
		}
		elseif(array_key_exists("CollEditor",$userRights) && in_array($collId,$userRights["CollEditor"])){
			$isEditor = 2;
		}
		elseif(array_key_exists("CollTaxon",$userRights) && $occId){
			//Check to see if this user is authorized to edit this occurrence given their taxonomic editing authority
			//0 = not editor, 2 = full editor, 3 = taxon editor, but not for this specific occurrence
			$isEditor = $occManager->isTaxonomicEditor();
		}
	}
	if($action == "Save Edits"){
		$statusStr = $occManager->editOccurrence($_POST,($crowdSourceMode?1:$isEditor));
	}
	if($isEditor == 1 || $isEditor == 2 || $crowdSourceMode){
		if($action == 'Save OCR'){
			$statusStr = $occManager->insertTextFragment($_POST['imgid'],$_POST['rawtext'],$_POST['rawnotes'],$_POST['rawsource']);
			if(is_numeric($statusStr)){
				$newPrlid = $statusStr;
				$statusStr = '';
			}
		}
		elseif($action == 'Save OCR Edits'){
			$statusStr = $occManager->saveTextFragment($_POST['editprlid'],$_POST['rawtext'],$_POST['rawnotes'],$_POST['rawsource']);
		}
		elseif($action == 'Delete OCR'){
			$statusStr = $occManager->deleteTextFragment($_POST['delprlid']);
		}
	}
	if($isEditor){
		//Available to full editors and taxon editors
		if($action == "Add New Determination"){
			$statusStr = $occManager->addDetermination($_POST,$isEditor);
			$tabTarget = 1;
		}
		elseif($action == "Submit Determination Edits"){
			$statusStr = $occManager->editDetermination($_POST);
			$tabTarget = 1;
		}
		elseif($action == "Delete Determination"){
			$statusStr = $occManager->deleteDetermination($_POST['detid']);
			$tabTarget = 1;
		}
		//Only full editors can perform following actions
		if($isEditor == 1 || $isEditor == 2){
			if($action == 'Add Record'){
				if($occManager->addOccurrence($_POST)){
					$occManager->setQueryVariables();
					$qryCnt = $occManager->getQueryRecordCount();
					$qryCnt++;
					if($goToMode){
						//Go to new record
						$occIndex = $qryCnt;
					}
					else{
						//Stay on record and get $occId
						$occId = $occManager->getOccId();
					}
				}
				else{
					$statusStr = $occManager->getErrorStr();
				}
			}
			elseif($action == 'Delete Occurrence'){
				if($occManager->deleteOccurrence($occId)){
					$occId = 0;
					$occManager->setOccId(0);
				}
				else{
					$statusStr = $occManager->getErrorStr();
				}
			}
			elseif($action == 'Transfer Record'){
				$transferCollid = $_POST['transfercollid'];
				if($transferCollid){
					if($occManager->transferOccurrence($occId,$transferCollid)){
						if(!isset($_POST['remainoncoll']) || !$_POST['remainoncoll']){
							$occManager->setCollId($transferCollid);
							$collId = $transferCollid;
							$collMap = $occManager->getCollMap();
						}
					}
					else{
						$statusStr = $occManager->getErrorStr();
					}
				}
			}
			elseif($action == "Submit Image Edits"){
				$statusStr = $occManager->editImage($_POST);
				$tabTarget = 2;
			}
			elseif($action == "Submit New Image"){
				if($occManager->addImage($_POST)){
					$statusStr = 'Image added successfully';
					$tabTarget = 2;
				}
				if($occManager->getErrorStr()){
					$statusStr .= $occManager->getErrorStr();
				}
			}
			elseif($action == "Delete Image"){
				$removeImg = (array_key_exists("removeimg",$_POST)?$_POST["removeimg"]:0);
				if($occManager->deleteImage($_POST["imgid"], $removeImg)){
					$statusStr = 'Image deleted successfully';
					$tabTarget = 2;
				}
				else{
					$statusStr = $occManager->getErrorStr();
				}
			}
			elseif($action == "Remap Image"){
				if($occManager->remapImage($_POST["imgid"], $_POST["targetoccid"])){
					$statusStr = 'SUCCESS: Image remapped to record <a href="occurrenceeditor.php?occid='.$_POST["targetoccid"].'" target="_blank">'.$_POST["targetoccid"].'</a>';
				}
				else{
					$statusStr = 'ERROR linking image to new specimen: '.$occManager->getErrorStr();
				}
			}
			elseif($action == "Disassociate Image"){
				if($occManager->remapImage($_POST["imgid"])){
					$statusStr = 'SUCCESS disassociating image <a href="../../imagelib/imgdetails.php?imgid='.$_POST["imgid"].'" target="_blank">#'.$_POST["imgid"].'</a>';
				}
				else{
					$statusStr = 'ERROR disassociating image: '.$occManager->getErrorStr();
				}
				
			}
			elseif($action == "Apply Determination"){
				$makeCurrent = 0;
				if(array_key_exists('makecurrent',$_POST)) $makeCurrent = 1;
				$statusStr = $occManager->applyDetermination($_POST['detid'],$makeCurrent);
				$tabTarget = 1;
			}
			elseif($action == "Make Determination Current"){
				$statusStr = $occManager->makeDeterminationCurrent($_POST['detid']);
				$tabTarget = 1;
			}
			elseif($action == "Submit Verification Edits"){
				$statusStr = $occManager->editIdentificationRanking($_POST['confidenceranking'],$_POST['notes']);
				$tabTarget = 1;
			}
			elseif($action == 'Link to Checklist as Voucher'){
				$statusStr = $occManager->linkChecklistVoucher($_POST['clidvoucher'],$_POST['tidvoucher']);
			}
			elseif($action == 'deletevoucher'){
				$statusStr = $occManager->deleteChecklistVoucher($_REQUEST['delclid']);
			}
			elseif($action == 'editgeneticsubmit'){
				$statusStr = $occManager->editGeneticResource($_POST);
			}
			elseif($action == 'deletegeneticsubmit'){
				$statusStr = $occManager->deleteGeneticResource($_POST['genid']);
			}
			elseif($action == 'addgeneticsubmit'){
				$statusStr = $occManager->addGeneticResource($_POST);
			}
		}
	}

	if($goToMode){
		$occId = 0;
		//Adding new record, override query form and prime for current user's dataentry for the day
		$today = date('Y-m-d');
		$occManager->setQueryVariables(array('eb'=>$paramsArr['un'],'dm'=>$today));
		if(!$qryCnt){
			$occManager->setSqlWhere(0);
			$qryCnt = $occManager->getQueryRecordCount();
			$occIndex = $qryCnt;
		}
	}
	if($ouid){
		$occManager->setQueryVariables(array('ouid' => $ouid));
	}
	elseif($occIndex !== false){
		//Query Form has been activated
		$occManager->setQueryVariables();
		if($action == 'Delete Occurrence'){
			//Reset query form index to one less, unless it's already 1, then just reset
			$qryCnt = $occManager->getQueryRecordCount();		//Value won't be returned unless set in cookies in previous query
			if($qryCnt > 1){
				if(($occIndex + 1) >= $qryCnt) $occIndex = $qryCnt - 2;
				$qryCnt--;
				$occManager->setSqlWhere($occIndex);
			}
			else{
				setCookie('editorquery','',time()-3600,($CLIENT_ROOT?$CLIENT_ROOT:'/'));
				$occIndex = false;
			}
		}
		elseif($action == 'Save Edits'){
			//CA: Bookmark
			$occManager->setSqlWhere(0);
			//Get query count and then reset; don't use new count for this display
			$qryCnt = $occManager->getQueryRecordCount();
			$occManager->getQueryRecordCount(1);
		}
		else{
			$occManager->setSqlWhere($occIndex);
			$qryCnt = $occManager->getQueryRecordCount();
		}
	}
	elseif(isset($_COOKIE["editorquery"])){
		//Make sure query is null
		setCookie('editorquery','',time()-3600,($CLIENT_ROOT?$CLIENT_ROOT:'/'));
	}

	if(!$goToMode){
		$oArr = $occManager->getOccurMap();
		if($oArr){
			if(!$occId) $occId = $occManager->getOccId();
			$occArr = $oArr[$occId];
			if(!$collMap) $collMap = $occManager->getCollMap();
		}
	}
	elseif($goToMode == 2){
		$occArr = $occManager->carryOverValues($_REQUEST);
	}

	if($qryCnt !== false){
		if($qryCnt == 0){
			if(!$goToMode){
				$navStr .= '<div style="margin:20px;font-size:150%;font-weight:bold;">';
				$navStr .= 'Search returned 0 records</div>'."\n";
			}
		}
		else{
			$navStr = '<b>';
			if($occIndex > 0) $navStr .= '<a href="#" onclick="return submitQueryForm(0);" title="First Record">';
			$navStr .= '|&lt;';
			if($occIndex > 0) $navStr .= '</a>';
			$navStr .= '&nbsp;&nbsp;&nbsp;&nbsp;';
			if($occIndex > 0) $navStr .= '<a href="#" onclick="return submitQueryForm('.($occIndex-1).');" title="Previous Record">';
			$navStr .= '&lt;&lt;';
			if($occIndex > 0) $navStr .= '</a>';
			$recIndex = ($occIndex<$qryCnt?($occIndex + 1):'*');
			$navStr .= '&nbsp;&nbsp;| '.$recIndex.' of '.$qryCnt.' |&nbsp;&nbsp;';
			if($occIndex<$qryCnt-1) $navStr .= '<a href="#" onclick="return submitQueryForm('.($occIndex+1).');"  title="Next Record">';
			$navStr .= '&gt;&gt;';
			if($occIndex<$qryCnt-1) $navStr .= '</a>';
			$navStr .= '&nbsp;&nbsp;&nbsp;&nbsp;';
			if($occIndex<$qryCnt-1) $navStr .= '<a href="#" onclick="return submitQueryForm('.($qryCnt-1).');" title="Last Record">';
			$navStr .= '&gt;|';
			if($occIndex<$qryCnt-1) $navStr .= '</a> ';
			if(!$crowdSourceMode){
				$navStr .= '&nbsp;&nbsp;&nbsp;&nbsp;';
				$navStr .= '<a href="occurrenceeditor.php?gotomode=1&collid='.$collId.'" onclick="return verifyLeaveForm()" title="New Record">&gt;*</a>';
			}
			$navStr .= '</b>';
		}
	}

	//Images and other things needed for OCR
	$specImgArr = $occManager->getImageMap();
	if($specImgArr){
		$imgUrlPrefix = (isset($imageDomain)?$imageDomain:'');
		$imgCnt = 1;
		foreach($specImgArr as $imgId => $i2){
			$iUrl = $i2['url'];
			if($imgUrlPrefix && substr($iUrl,0,4) != 'http') $iUrl = $imgUrlPrefix.$iUrl;
			$imgArr[$imgCnt]['imgid'] = $imgId;
			$imgArr[$imgCnt]['web'] = $iUrl;
			if($i2['origurl']){
				$lgUrl = $i2['origurl'];
				if($imgUrlPrefix && substr($lgUrl,0,4) != 'http') $lgUrl = $imgUrlPrefix.$lgUrl;
				$imgArr[$imgCnt]['lg'] = $lgUrl;
			}
			$imgCnt++;
		}
		$fragArr = $occManager->getRawTextFragments();
	}

	$isLocked = false;
	if($occId) $isLocked = $occManager->getLock();
	
}
else{
	header('Location: ../../profile/index.php?refurl=../collections/editor/occurrenceeditor.php?'.$_SERVER['QUERY_STRING']);
}
?>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=<?php echo $CHARSET; ?>">
	<title><?php echo $DEFAULT_TITLE; ?> Language Editor</title>
	<link href="../../css/jquery-ui.css" type="text/css" rel="stylesheet" />
    <?php
    if($crowdSourceMode == 1){
		?>
		<link href="includes/config/occureditorcrowdsource.css?ver=20150402" type="text/css" rel="stylesheet" id="editorCssLink" />
		<?php
    }
    else{
		?>
		<link href="../../css/occureditor.css?ver=20150402" type="text/css" rel="stylesheet" id="editorCssLink" />
		<?php
		if(isset($CSSARR)){
			foreach($CSSARR as $cssVal){
				echo '<link href="includes/config/'.$cssVal.'?ver=150106" type="text/css" rel="stylesheet" id="editorCssLink" />';
			}
		}
		if(isset($JSARR)){
			foreach($JSARR as $jsVal){
				echo '<script src="includes/config/'.$jsVal.'?ver=150106" type="text/javascript"></script>';
			}
		}
	}
    ?>
	<script src="../../js/jquery.js?ver=140310" type="text/javascript"></script>
	<script src="../../js/jquery-ui.js?ver=140310" type="text/javascript"></script>
	<script src="../../js/jquery.imagetool-1.7.js?ver=140310" type="text/javascript"></script>
	<script type="text/javascript">
		var collId = "<?php echo $collId; ?>";
		var csMode = "<?php echo $crowdSourceMode; ?>";
		var tabTarget = <?php echo (is_numeric($tabTarget)?$tabTarget:'0'); ?>;
		var imgArr = [];
		var imgLgArr = [];
		<?php
		if($imgArr){
			foreach($imgArr as $iCnt => $iArr){
				echo 'imgArr['.$iCnt.'] = "'.$iArr['web'].'";'."\n";
				if(isset($iArr['lg'])) echo 'imgLgArr['.$iCnt.'] = "'.$iArr['lg'].'";'."\n";
			}
		}
		?>

		function requestImage(){
            $.ajax({
                type: "POST",
                url: 'rpc/makeactionrequest.php',
                data: { <?php echo " occid: '$occId' , "; ?> requesttype: 'Image' },
                success: function( response ) {
                   $('div#imagerequestresult').html(response);
                }
            });
        }


	</script>
	<script type="text/javascript" src="../../js/symb/collections.occureditormain.js?ver=150910"></script>
	<script type="text/javascript" src="../../js/symb/collections.occureditortools.js?ver=151120"></script>
	<script type="text/javascript" src="../../js/symb/collections.occureditorimgtools.js?ver=161012"></script>
	<script type="text/javascript" src="../../js/symb/collections.occureditorshare.js?ver=141212"></script>
</head>
<body>
	<!-- inner text -->
	<div id="innertext">
		<?php
		if($collMap){
			?>
			<div id="titleDiv">
				<?php
				echo $collMap['collectionname'].' ('.$collMap['institutioncode'].($collMap['collectioncode']?':'.$collMap['collectioncode']:'').')';
				if($isEditor == 1 || $isEditor == 2 || $crowdSourceMode){
					?>
					<div id="querySymbolDiv">
						<a href="#" title="Search / Filter" onclick="toggleQueryForm();"><img src="../../images/find.png" style="width:16px;" /></a>
					</div>
					<?php
				}
				?>
			</div>
			<?php
		}
		if($occId || $crowdSourceMode || ($isEditor && $collId)){
			if(!$occArr && !$goToMode) $displayQuery = 1;
			include 'includes/queryform.php';
			?>
			<div id="navDiv">
				<?php
				if($navStr){
					?>
					<div style="float:right;">
						<?php echo $navStr; ?>
					</div>
					<?php
				}
				if(isset($collections_editor_occurrenceeditorCrumbs)){
					if($collections_editor_occurrenceeditorCrumbs){
						?>
						<div class="navpath">
							<a href='../../index.php'>Home</a> &gt;&gt;
							<?php echo $collections_editor_occurrenceeditorCrumbs; ?>
							<b>Editor</b>
						</div>
						<?php
					}
				}
				else{
					?>
					<div class='navpath'>
						<a href="../../index.php" onclick="return verifyLeaveForm()">Home</a> &gt;&gt;
						<?php
						if($crowdSourceMode){
							?>
							<a href="../specprocessor/crowdsource/central.php">Crowd Sourcing Central</a> &gt;&gt;
							<?php
						}
						else{
							if($isGenObs){
								?>
								<a href="../../profile/viewprofile.php?tabindex=1" onclick="return verifyLeaveForm()">Personal Management</a> &gt;&gt;
								<?php
							}
							else{
								if($isEditor == 1 || $isEditor == 2){
									?>
									<a href="../misc/collprofiles.php?collid=<?php echo $collId; ?>&emode=1" onclick="return verifyLeaveForm()">Collection Management</a> &gt;&gt;
									<?php
								}
							}
						}
						if($occId) echo '<a href="../individual/index.php?occid='.$occId.'">Public Display</a> &gt;&gt;';
						?>
						<b><?php if($isEditor == 3) echo 'Taxonomic '; ?>Editor</b>
					</div>
					<?php
				}
				?>
			</div>
			<?php
			if($statusStr){
				?>
				<div id="statusdiv" style="margin:5px 0px 5px 15px;">
					<b>Action Status: </b>
					<span style="color:<?php echo (stripos($statusStr,'ERROR')!==false?'red':'green'); ?>;"><?php echo $statusStr; ?></span>
					<?php
					if($action == 'Delete Occurrence'){
						?>
						<br/>
						<a href="#" style="margin:5px;" onclick="window.opener.location.href = window.opener.location.href;window.close();">
							Return to Search Page
						</a>
						<?php
					}
					?>
				</div>
				<?php
			}
			if($occArr || $goToMode == 1 || $goToMode == 2){		//$action == 'gotonew'
				if($occId && $isLocked){
					?>
					<div style="margin:25px;border:2px double;padding:20px;width:90%;">
						<div style="color:red;font-weight:bold;font-size:110%;">
							Record Locked!
						</div>
						<div>
							This record is locked for editing by another user. Once the user is done with the record, the lock will be removed. Records are locked for a maximum of 15 minutes.
						</div>
						<div style="margin:20px;font-weight:bold;">
							<a href="../individual/index.php?occid=<?php echo $occId; ?>" target="_blank">Read-only Display</a>
						</div>
					</div>
					<?php
				}
				else{
					?>
					<table id="edittable" style="">
						<tr><td id="editortd" style="" valign="top">
							<div id="occedittabs" style="clear:both;">
								<ul>
									<li>
										<a href="#occdiv"  style="">
											<?php
											if($occId){
												echo 'Occurrence Data';
											}
											else{
												echo '<span style="color:red;">New Language Record</span>';
											}
											?>
										</a>
									</li>
									<?php
									if($occId && $isEditor){
										// Get symbiota user email as the annotator email (for fp)
										$pHandler = new ProfileManager();
										$pHandler->setUid($symbUid);
										$person = $pHandler->getPerson();
										$userEmail = ($person?$person->getEmail():'');

										$anchorVars = 'occid='.$occId.'&occindex='.$occIndex.'&csmode='.$crowdSourceMode.'&collid='.$collId;
										$detVars = 'identby='.urlencode($occArr['identifiedby']).'&dateident='.urlencode($occArr['dateidentified']).
											'&sciname='.urlencode($occArr['sciname']).'&em='.$isEditor.
											'&annotatorname='.urlencode($userDisplayName).'&annotatoremail='.urlencode($userEmail).
											(isset($collMap['collectioncode'])?'&collectioncode='.urlencode($collMap['collectioncode']):'').
											(isset($collMap['institutioncode'])?'&institutioncode='.urlencode($collMap['institutioncode']):'').
											'&catalognumber='.urlencode($occArr['catalognumber']);
										?>
										<li id="detTab">
											<a href="includes/determinationtab.php?<?php echo $anchorVars.'&'.$detVars; ?>"
												style="">Determination History</a>
										</li>
										<?php
										if (isset($fpEnabled) && $fpEnabled) { // FP Annotations tab
											echo '<li>';
											echo '<a href="includes/findannotations.php?'.$anchorVars.'&'.$detVars.'"';
											echo ' style=""> Annotations </a>';
											echo '</li>';
										}
										if($isEditor == 1 || $isEditor == 2){
											?>
											<li id="imgTab">
												<a href="includes/imagetab.php?<?php echo $anchorVars; ?>"
													style="">Images</a>
											</li>
											<li id="resourceTab">
												<a href="includes/resourcetab.php?<?php echo $anchorVars; ?>"
													style="">Linked Resources</a>
											</li>
											<li id="adminTab">
												<a href="includes/admintab.php?<?php echo $anchorVars; ?>"
													style="">Admin</a>
											</li>
											<?php
										}
									}
									?>
								</ul>
								<div id="occdiv">
									<form id="fullform" name="fullform" action="languageeditor.php" method="post" onsubmit="return verifyFullForm(this);">
										<!-- CA: Bookmark -->
										<fieldset>
											<legend><b>Ethnobiological Information</b></legend>
											<div style="padding:3px;">
                        <div class="clearfix">
                          <fieldset>
                            <legend><b>Language Input</b></legend>
                            <div id="familyLanguageConsultantIndigenousDiv">
                              <?php echo (defined('FAMILYLANGUAGECONSULTANTINDIGENOUSLABEL')?FAMILYLANGUAGECONSULTANTINDIGENOUSLABEL:'Language family'); ?>
                              <a href="#" onclick="return dwcDoc('familyLanguageConsultantIndigenous')"><img class="docimg" src="../../images/qmark.png" /></a><br/>
                              <input type="text" name="familylanguageconsultantindigenous" tabindex="127" maxlength="50" value="<?php echo array_key_exists('familylanguageconsultantindigenous',$occArr)?$occArr['familylanguageconsultantindigenous']:''; ?>" onchange="fieldChanged('familylanguageconsultantindigenous');" />
                            </div>
                            <div id="groupLanguageConsultantIndigenousDiv">
                              <?php echo (defined('GROUPLANGUAGECONSULTANTINDIGENOUSLABEL')?GROUPLANGUAGECONSULTANTINDIGENOUSLABEL:'Language group'); ?>
                              <a href="#" onclick="return dwcDoc('groupLanguageConsultantIndigenous')"><img class="docimg" src="../../images/qmark.png" /></a><br/>
                              <input type="text" name="grouplanguageconsultantindigenous" tabindex="128" maxlength="50" value="<?php echo array_key_exists('grouplanguageconsultantindigenous',$occArr)?$occArr['grouplanguageconsultantindigenous']:''; ?>" onchange="fieldChanged('grouplanguageconsultantindigenous');" />
                            </div>
                            <div id="subgroupLanguageCollaboratorIndigenousDiv">
                              <?php echo (defined('SUBGROUPLANGUAGECOLLABORATORINDIGENOUSLABEL')?SUBGROUPLANGUAGECOLLABORATORINDIGENOUSLABEL:'Subgroup'); ?>
                              <a href="#" onclick="return dwcDoc('subgroupLanguageCollaboratorIndigenous')"><img class="docimg" src="../../images/qmark.png" /></a><br/>
                              <input type="text" name="subgrouplanguagecollaboratorindigenous" tabindex="129" maxlength="50" value="<?php echo array_key_exists('subgrouplanguageconsultantindigenous',$occArr)?$occArr['subgrouplanguageconsultantindigenous']:''; ?>" onchange="fieldChanged('subgrouplanguagecollaboratorindigenous');" />
                            </div>
                            <div id="isoLanguageConsultantIndigenousDiv">
                              <?php echo (defined('ISOLANGUAGECONSULTANTINDIGENOUSLABEL')?ISOLANGUAGECONSULTANTINDIGENOUSLABEL:'Language ISO code'); ?>
                              <a href="#" onclick="return dwcDoc('isoLanguageConsultantIndigenous')"><img class="docimg" src="../../images/qmark.png" /></a><br/>
                              <input type="text" name="isolanguageconsultantindigenous" tabindex="129" maxlength="5" value="<?php echo array_key_exists('isolanguageconsultantindigenous',$occArr)?$occArr['isolanguageconsultantindigenous']:''; ?>" onchange="fieldChanged('isolanguageconsultantindigenous');" />
                            </div>
                          </fieldset>
                        </div>
											</div>
											<?php
											if($occId){
												?>
												<div id="pkDiv">
													<hr/>
													<div style="float:left;" title="Internal occurrence record Primary Key (occid)">
														<?php if($occId) echo 'Key: '.$occId; ?>
													</div>
													<div style="float:left;margin-left:50px;">
														<?php if(array_key_exists('datelastmodified',$occArr)) echo 'Modified: '.$occArr['datelastmodified']; ?>
													</div>
													<div style="float:left;margin-left:50px;">
														<?php
														if(array_key_exists('recordenteredby',$occArr)){
															echo 'Entered by: '.($occArr['recordenteredby']?$occArr['recordenteredby']:'not recorded');
														}
														if(isset($occArr['dateentered']) && $occArr['dateentered']) echo ' ['.$occArr['dateentered'].']';
														?>
													</div>
												</div>
												<?php
											}
											?>
										</fieldset>
										<?php
										if($navStr){
											//echo '<div style="float:right;margin-right:20px;">'.$navStr.'</div>'."\n";
										}
										?>
										<div style="padding:10px;">
											<input type="hidden" name="occid" value="<?php echo $occId; ?>" />
											<input type="hidden" name="collid" value="<?php echo $collId; ?>" />
											<input type="hidden" name="observeruid" value="<?php echo $symbUid; ?>" />
											<input type="hidden" name="csmode" value="<?php echo $crowdSourceMode; ?>" />
											<input type="hidden" name="linkdupe" value="" />
											<?php
											if($occId){
												if(($isEditor == 1 || $isEditor == 2) && !$crowdSourceMode){
													?>
													<div style="float:right;">
														<fieldset style="padding:15px;background-color:lightyellow;">
															<legend><b>Additional Options</b></legend>
															<input type="button" value="Go to New Occurrence Record" onclick="verifyGotoNew(this.form);" /><br/>
															<input type="hidden" name="gotomode" value="" />
															<input type="checkbox" name="carryloc" value="1" /> Carry over locality values
														</fieldset>
													</div>
													<?php
												}
												?>
												<div id="editButtonDiv">
													<input tabindex="149" type="submit" name="submitaction" value="Save Edits" style="width:150px;" onclick="return verifyFullFormEdits(this.form)" disabled />
													<br/>
													Status Auto-Set:
													<select name="autoprocessingstatus" onchange="autoProcessingStatusChanged(this)">
														<option value=''>Not Activated</option>
														<option value=''>-------------------</option>
														<?php
														foreach($processingStatusArr as $v){
															$keyOut = strtolower($v);
															//Don't display all options if editor is crowd sourced
															if($isEditor || ($keyOut != 'reviewed' && $keyOut != 'closed')){
																echo '<option value="'.$keyOut.'" '.($crowdSourceMode && $keyOut == "pending review"?'SELECTED':'').'>'.ucwords($v).'</option>';
															}
														}
														?>
													</select>
													<?php
													if($occIndex !== false){
														?>
														<input type="hidden" name="occindex" value="<?php echo $occIndex; ?>" />
														<?php
													}
													?>
													<input type="hidden" name="editedfields" value="" />
												</div>
												<?php
											}
											else{
												$userChecklists = $occManager->getUserChecklists();
												if($userChecklists){
													?>
													<fieldset>
														<legend><b>Checklist Voucher</b></legend>
														Link Occurrence to Checklist: 
														<select name="clidvoucher">
															<option value="">No Checklist Selected</option>
															<option value="">---------------------------------------------</option>
															<?php 
															foreach($userChecklists as $clid => $clName){
																echo '<option value="'.$clid.'">'.$clName.'</option>';
															}
															?>
														</select>
													</fieldset>
													<?php
												}
												?>
												<div id="addButtonDiv">
													<input type="hidden" name="recordenteredby" value="<?php echo $paramsArr['un']; ?>" />
													<input type="button" name="submitaddbutton" value="Add Record" onclick="this.disabled=true;this.form.submit();" style="width:150px;font-weight:bold;margin:10px;" />
													<input type="hidden" name="submitaction" value="Add Record" />
													<input type="hidden" name="qrycnt" value="<?php echo $qryCnt?$qryCnt:''; ?>" />
													<div style="margin-left:15px;font-weight:bold;">
														Follow-up Action:
													</div>
													<div style="margin-left:20px;">
														<input type="radio" name="gotomode" value="1" <?php echo ($goToMode==1?'CHECKED':''); ?> /> Go to New Record<br/>
														<input type="radio" name="gotomode" value="2" <?php echo ($goToMode==2?'CHECKED':''); ?> /> Go to New Record and Carryover Locality Information<br/>
														<input type="radio" name="gotomode" value="0" <?php echo (!$goToMode?'CHECKED':''); ?> /> Remain on Editing Page (add images, determinations, etc)
													</div>
												</div>
												
												<?php
											}
											?>
										</div>
										<div style="clear:both;">&nbsp;</div>
									</form>
								</div>
							</div>
						</td>
						<td id="imgtd" style="display:none;width:430px;" valign="top">
							<?php
							if($occId && ($fragArr || $specImgArr )){
								include_once('includes/imgprocessor.php');
							}
							?>
						</td></tr>
					</table>
					<?php
				}
			}
		}
		else{
			if($action == "Submit New Image"){
				echo '<div style="font-weight:bold;font-size:130%;">';
				echo 'ERROR: You may have tried to upload an image that was too large for the system. ';
				echo 'There is a 10MB limit set within the application, though there may be tighter restrictions set on the server (PHP configurations). ';
				echo 'Check with your server administrator to check on options for importing larger images. ';
				echo 'Use the back button to return to previous page and try to upload a smaller image </div>';
			}
			elseif(!$isEditor){
				echo '<h2>You are not authorized to add occurrence records</h2>';
			}
		}
		?>
	</div>
</body>
</html>
