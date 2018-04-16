<?php
/**
 * Copyright Sangoma Technologies, Inc 2015
 */
namespace FreePBX\modules;
use FreePBX\modules\Backup\Modules as Module;
use FreePBX\modules\Backup\Handlers as Handler;
use FreePBX\modules\Filestore\Modules as Filestore;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\LockHandler;
use Monolog\Handler\SwiftMailerHandler;
use Monolog\Handler\BufferHandler;
class Backup extends \FreePBX_Helpers implements \BMO {
	public function __construct($freepbx = null) {
		include __DIR__.'/vendor/autoload.php';
		if ($freepbx == null) {
				throw new Exception('Not given a FreePBX Object');
		}
		$this->FreePBX = $freepbx;
		$this->db = $freepbx->Database;
		$this->mf = \module_functions::create();
		$this->fs = new Filesystem;
		$this->backupFields = ['backup_name','backup_description','backup_items','backup_storage','backup_schedule','schedule_enabled','maintage','maintruns','backup_email','backup_emailtype','immortal','warmspare_type','warmspare_user','warmspare_remote','warmspareenables','publickey'];
		$this->templateFields = [];
		$this->serverName = $this->FreePBX->Config->get('FREEPBX_SYSTEM_IDENT');
		$this->sessionlog = [];
		$this->backupHandler = null;
		$this->restoreHandler = null;
		$this->logger = $this->FreePBX->Logger();
		$this->logger->createCustomLog('Backup', $path = '/var/log/asterisk/backup.log',true);
		$transport = \Swift_MailTransport::newInstance();
		$this->swiftmsg = \Swift_Message::newInstance();
		$this->swiftmsg->setContentType("text/html");
		$swift = \Swift_Mailer::newInstance($transport);
		$this->handler = new BufferHandler(new SwiftMailerHandler($swift,$this->swiftmsg,\Monolog\Logger::INFO),0,\Monolog\Logger::INFO); 
		$this->logger->customLog->pushHandler($this->handler);
	}
	//BMO STUFF
	public function install(){
	}

	public function uninstall(){
	}

	public function backup(){
	}

	public function restore($backup){
	}

	public function doConfigPageInit($page) {
		if($page == 'backup'){
			if(isset($_REQUEST['action']) && $_REQUEST['action'] == 'delete'){
				return $this->deleteBackup($_REQUEST['id']);
			}
			if(isset($_POST['backup_name'])){
				$this->importRequest();
				return $this->updateBackup();
			}
		}
	}

	/**
	 * Action bar in 13+
	 * @param [type] $request [description]
	 */
	public function getActionBar($request) {
		$buttons = array(
			'reset' => array(
				'name' => 'reset',
				'id' => 'reset',
				'value' => _('Reset'),
			),
			'submit' => array(
				'name' => 'submit',
				'id' => 'submit',
				'value' => _('Save'),
			),
			'run' => array(
				'name' => 'run',
				'id' => 'run_backup',
				'value' => _('Save and Run'),
			),
			'delete' => array(
				'name' => 'delete',
				'id' => 'delete',
				'value' => _('Delete'),
			),
		);
		switch ($request['display']) {
			case 'backup':
			break;
			case 'backup_restore':
			case 'backup_templates':
				unset($buttons['run']);
			break;
			default:
				$buttons = [];
			break;
		}
		if(!isset($request['id']) || empty($request['id'])){
			unset($buttons['delete']);
			unset($buttons['run']);
		}
		if(!isset($request['view']) || empty($request['view'])){
			$buttons = [];
		}
		return $buttons;
	}

	/**
	 * Ajax Request for BMO
	 * @param string $req     [description]
	 * @param [type] $setting [description]
	 */
	public function ajaxRequest($req, &$setting) {
		switch ($req) {
			case 'getJSON':
			case 'run':
			case 'getlog':
			case 'restoreFiles':
			case 'uploadrestore':
			case 'generateRSA':
				$return = true;
			break;
			case 'runstatus':
				$return = true;
				$setting['authenticate'] = false;
				$setting['allowremote'] = true;
			break;
			default:
				$return = false;
			break;
		}
		return $return;
	}

	/**
	 * Ajax Module for BMO
	 */
	public function ajaxHandler() {
		switch ($_REQUEST['command']) {
			case 'generateRSA':
				$ssh = new Filestore\Remote();
				$ret = $ssh->generateKey('/home/asterisk/.ssh');
			return ['status' => $ret];
			case 'uploadrestore':
			$id = $this->generateId();
			if(!isset($_FILES['filetorestore'])){
				return ['status' => false, 'error' => _("No file provided")];
			}
			if($_FILES['filetorestore']['error'] !== 0){
				return ['status' => false, 'err' => $_FILES['filetorestore']['error'], 'message' => _("File reached the server but could not be processed")];
			}
			if($_FILES['filetorestore']['type'] != 'application/x-gzip'){
				return ['status' => false, 'mime' => $_FILES['filetorestore']['type'], 'message' => _("The uploaded file type is incorrect and couldn't be processed")];
			}
			$spooldir = $this->FreePBX->Config->get("ASTSPOOLDIR");
			$path = sprintf('%s/backup/uploads/',$spooldir);
			//This will ignore if exists
			$this->fs->mkdir($path);
			$file = $path.basename($_FILES['filetorestore']['name']);
			if (!move_uploaded_file($_FILES['filetorestore']['tmp_name'],$file)){
				return ['status' => false, 'message' => _("Failed to copy the uploaded file")];
			}
			$this->setConfig('file', $file, $id);
			$backupphar = new \PharData($file);
			$meta = $backupphar->getMetadata();
			$this->setConfig('meta', $meta, $id);
			return ['status' => true, 'id' => $id, 'meta' => $meta];
			case 'restoreFiles':
				return [];
			case 'run':
				if(!isset($_GET['id'])){
					return ['status' => false, 'message' => _("No backup id provided")];
				}
				$buid = escapeshellarg($_GET['id']);
				$jobid = $this->generateId();
				$process = new Process('fwconsole backup --backup='.$buid.' --transaction='.$jobid);
				$process->disableOutput();
				try {
					$process->mustRun();
				} catch (\Exception $e) {
					return ['status' => false, 'message' => _("Couldn't run process.")];
				}
				$pid = $process->getPid();
				return ['status' => true, 'message' => _("Backup running"), 'process' => $pid, 'transaction' => $jobid, 'backupid' => $buid];
			case 'getLog':
				if(!isset($_GET['transaction'])){
					return[];
				}
				$ret = $this->getAll($_GET['transaction']);
				return $ret?$ret:[];
			case 'getJSON':
				switch ($_REQUEST['jdata']) {
					case 'backupGrid':
						return array_values($this->listBackups());
					break;
					case 'backupStorage':
						$storage_ids = [];
						if(isset($_GET['id']) && !empty($_GET['id'])){
							$storage_ids = $this->getStorageByID($_GET['id']);
						}
						try {
							$fstype = $this->getFSType();
							$items = $this->FreePBX->Filestore->listLocations($fstype);
							$return = [];
							foreach ($items['locations'] as $driver => $locations ) {
								$optgroup = [
									'label' => $driver,
									'children' => []
								];
								foreach ($locations as $location) {
									$select = in_array($driver.'_'.$location['id'], $storage_ids);
									$optgroup['children'][] = [
										'label' => $location['name'],
										'title' => $location['description'],
										'value' => $driver.'_'.$location['id'],
										'selected' => $select
									];
								}
								$return[] = $optgroup;
							}
							return $return;
						} catch (\Exception $e) {
							return $e;
						}
					break;
					case 'backupItems':
					$id = isset($_GET['id'])?$_GET['id']:'';
					return $this->HandlerById($id);
					break;
					default:
						return false;
					break;
				}
			break;
			default:
				return false;
			break;
		}
	}	
	public function ajaxCustomHandler() {
		if($_REQUEST['command'] == 'runstatus'){
			include __DIR__.'/views/run.php';
		}
	}

	//TODO: This whole thing
	public function getRightNav($request) {
		//We don't need an rnav if the view is not set
		if(isset($_GET['display']) && isset($_GET['view'])){
			switch ($_GET['display']) {
				case 'backup':
				case 'backup_templates':
				case 'backup_restore':
					return "Placeholder";
				break;
				default:
				break;
			}
		}
	}

	//Display stuff

	public function showPage($page){
		switch ($page) {
			case 'backup':
				if(isset($_GET['view']) && $_GET['view'] == 'run'){
					return load_view(__DIR__.'/views/backup/run.php',array('id' => $_REQUEST['id']));
				}

				if(isset($_GET['view']) && $_GET['view'] == 'newRSA'){
					return load_view(__DIR__.'/views/backup/rsa.php');
				}
				if(isset($_GET['view']) && $_GET['view'] == 'form'){
					$randcron = sprintf('59 23 * * %s',rand(0,6));
					$vars = ['id' => ''];
					$vars['backup_schedule'] = $randcron;
					if(isset($_GET['id']) && !empty($_GET['id'])){
						$vars = $this->getBackup($_GET['id']);
						$vars['backup_schedule'] = !empty($vars['backup_schedule'])?$vars['backup_schedule']:$randcron;
						$vars['id'] = $_GET['id'];
					}
					$warmsparedisable = $this->getConfig('warmsparedisable');
					$vars['warmspare'] = '';
					if(empty($warmsparedisable)){
						$warmsparedefaults = [
							'warmspare_type' => 'primary',
							'warmspare_user' => 'root',
							'warmspare_remote' => 'no',
							'warmspare_enable' => 'no',
						];
						$settings = $this->getConfig('warmsparesettings');
						$settings = $settings?$settings:[];
						foreach($warmsparedefaults as $key => $value){
							$value = isset($settings[$key])?$settings[$key]:$value;
							$vars[$key] = $value;
						}
						if($vars['warmspare_type'] == 'primary'){
							$file = '/home/asterisk/.ssh/id_rsa.pub';
							$vars['publickey'] = '';
							if(file_exists($file)){
								$data = file_get_contents($file);
								$vars['publickey'] = $data;
							}
						}
						$vars['warmspare'] = load_view(__DIR__.'/views/backup/warmspare.php',$vars);
					}
					return load_view(__DIR__.'/views/backup/form.php',$vars);
				}
				if(isset($_GET['view']) && $_GET['view'] == 'download'){
					return load_view(__DIR__.'/views/backup/download.php');
				}
				if(isset($_GET['view']) && $_GET['view'] == 'transfer'){
					return load_view(__DIR__.'/views/backup/transfer.php');
				}
				return load_view(__DIR__.'/views/backup/grid.php');
			break;
			case 'restore':
				$view = isset($_GET['view'])?$_GET['view']:'default';
				switch ($view) {
					case 'processrestore':
						if(!isset($_GET['id']) || empty($_GET['id'])){
							return load_view(__DIR__.'/views/restore/landing.php',['error' => _("No id was specified to process. Please try submitting your file again.")]);
						}
						$backupjson = [];
						$vars = $this->getAll($_GET['id']);
						$vars['missing'] = "yes";
						$vars['reset'] = "no";
						$vars['enabletrunks'] = "yes";
						foreach ($vars['meta']['modules'] as $module) {
							$mod = strtolower($module);
							$status = $this->FreePBX->Modules->checkStatus($mod);
							$backupjson[] = [
								'modulename' => $module,
								'installed' => $status
							];
						}
						$vars['jsondata'] = json_encode($backupjson);
						return load_view(__DIR__.'/views/restore/processRestore.php',$vars);
					break;
					default:
						return load_view(__DIR__.'/views/restore/landing.php');
					break;
				}
			break;
			default:
				return load_view(__DIR__.'/views/backup/grid.php');
		}
	}

	public function getBackupSettingsDisplay($module,$id = ''){
		$hooks = $this->FreePBX->Hooks->processHooks($module,$id);
		if(empty($hooks)){
			return false;
		}
		$ret = '<div class="hooksetting">';
		foreach ($hooks as $value) {
			$ret .= $value;
		}
		$ret .= '</div>';
		return $ret;
	}

	/** Oh... Migration, migration, let's learn about migration. It's nature's inspiration to move around the sea.
	* We have split the functionality up so things backup use to do may be done by another module. The other module(s)
	* May not yet be installed or may install after.  So we need to keep a kvstore with the various data and when installing
	* The other modules will checkin on install and process the data needed by them.
	**/

	//on install if the module/method is not yet a thing we will store it's data here
	public function migrateBackupJobs(){
		//['backup_name','backup_description','backup_items','backup_storage','backup_schedule','maintage','maintruns','backup_email','backup_emailtype','immortal'];
		$ids = array();
		try {
			$q = $this->db->query('SELECT * FROM backup');
			while ($item = $q->fetch(\PDO::FETCH_ASSOC)) {
				$id = $this->generateId();
				$insert = ['id' => $id];
				if($this->getConfig($item['id'],'migratedbackups')){
					continue;
				}
				$default = sprintf("Migrated Backup id: %s",$item['id']);
				$insert['backup_name'] = isset($item['name'])?$item['name']:$default;
				$insert['backup_description'] = isset($item['description'])?$item['description']:$default;
				$insert['backup_email'] = isset($item['email'])?$item['email']:$default;
				$immortal = (isset($item['immortal']) && !is_null($item['immortal']));
				$this->updateBackup($insert);
				$this->updateBackupSetting($id, 'immortal', $immortal);
				$this->updateBackupSetting($id, 'migratedid', $item['id']);
				$ids[] = ['oldid' => $item['id'], 'newid' => $id];
			}
		} catch (\Exception $e) {
			if($e->getCode() != '42S02'){
				throw $e;
			}
		}
		$stmt = $this->db->prepare('SELECT `key`,`value` FROM backup_details WHERE backup_id = :id');
		foreach ($ids as $item) {
			try {
				$stmt->execute([':id' => $item['oldid']]);
				$data = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
				if(isset($data['emailfailonly']) && !empty($data['emailfailonly'])){
					$this->updateBackupSetting($item['newid'], 'backup_emailtype', 'failure');
				}
				if(isset($data['delete_ammount']) && !empty($data['delete_ammount'])){
					$this->updateBackupSetting($item['newid'], 'maintruns', $data['delete_ammount']);
				}
			} catch (\Exception $e) {
				if($e->getCode() != '42S02'){
					throw $e;
				}
			}

			$this->setConfig($item['oldid'],$item['newid'],'migratedbackups');

		}
	}
	public function migrateStorage(){
		if($this->getMigrationFlag('filestore')){
			return true;
		}
	}
	public function setMigration($rawname,$data){
		$this->setConfig('data',$data,$rawname);
	}
	//Set a flag that says we have migrated
	public function setMigtatedFlag($rawname){
		$this->setConfig('migrated',true,$rawname);
	}
	//Check if we have migrated
	public function getMigrationFlag($rawname){
		return $this->getConfig('migrated',$rawname);
	}
	//Get any migration data the module has for us
	public function getMigration($rawname){
		return $this->getConfig('data',$rawname);
	}
	public function cleanMigrationData($rawname){
		$this->setConfig('data',false,$rawname);
	}
	//End migration stuff

	//Getters

	/**
	 * Get storage locations by backup ID
	 * @param  string $id backup id
	 * @return array  array of backup locations as DRIVER_ID
	 */
	public function getStorageById($id){
		$storage = $this->getConfig('backup_storage',$id);
		return is_array($storage)?$storage:[];
	}

	/**
	 * Gets the appropriate filesystem types to pass to filestore.
	 * @return mixed if hooks are present it will present an array, otherwise a string
	 */
	public function getFSType(){
		$types = $this->FreePBX->Hooks->processHooks();
		$ret=[];
		foreach ($types as $key => $value) {
			$value = is_array($value)?$value:[];
			$ret = array_merge($ret,$value);
		}
		return !empty($ret)?$ret:'backup';
	}

	/**
	 * List all backups
	 * @return array Array of backup items
	 */
	public function listBackups() {
		$return =  $this->getAll('backupList');
		return is_array($return)?$return:[];
	}

	/**
	 * Get all settings for a specific backup id
	 * @param  string $id backup id
	 * @return array  an array of backup settings
	 */
	public function getBackup($id){
		$data = $this->getAll($id);
		$return = [];
		foreach ($this->backupFields as $key) {
			$return[$key] = isset($data[$key])?$data[$key]:'';
		}
		return $return;
	}


	

	/**
	 * Get modules for a specific backup id returned in an array
	 * @param  string  $id              The backup id
	 * @param  boolean $selectedOnly    Only return the modules selected
	 * @param  boolean $includeSettings Include settings html for rendering in the UI
	 * @return array   list of module data
	 */
	public function HandlerById($id = '',$selectedOnly = false, $includeSettings = true){
		if(empty($this->backupHandler)){
			$this->backupHandler = new Handler\Backup($this->FreePBX);
		}
		$modules = $this->backupHandler->getModules();
		$selected = $this->getAll('modules_'.$id);
		$selected = is_array($selected)?array_keys($selected):[];
		if($selectedOnly){
			return $selected;
		}
		$ret = [];
		foreach ($modules as $module) {
			$item = [
				'modulename' => $module,
				'selected' => in_array($module, $selected),
			];
			if($includeSettings){
				$item['settingdisplay'] = $this->getBackupSettingsDisplay($module, $id);
			}
			$ret[] = $item;
		}
		return $ret;
	}


	//Setters
	public function scheduleJobs($id = 'all'){
		if($id !== 'all'){
			$enabled = $this->getBackupSetting($id, 'schedule_enabled');
			if($enabled === 'yes'){
				$schedule = $this->getBackupSetting($id, 'backup_schedule');
				$command = sprintf('/usr/sbin/fwconsole backup backup=%s > /dev/null 2>&1',$id);
				$this->FreePBX->Cron->removeAll($command);
				$this->FreePBX->Cron->add($schedule.' '.$command);
				return true;
			}
		}
		//Clean slate
		$allcrons = $this->FreePBX->Cron->getAll();
		$allcrons = is_array($allcrons)?$allcrons:[];
		foreach ($allcrons as $cmd) {
			if (strpos($cmd, 'fwconsole backup') !== false) {
				$this->FreePBX->Cron->remove($cmd);
			}
		}
		$backups = $this->listBackups();
		foreach ($backups as $key => $value) {
			$enabled = $this->getBackupSetting($key, 'schedule_enabled');
			if($enabled === 'yes'){
				$schedule = $this->getBackupSetting($key, 'backup_schedule');
				$command = sprintf('/usr/sbin/fwconsole backup backup=%s > /dev/null 2>&1',$key);
				$this->FreePBX->Cron->removeAll($command);
				$this->FreePBX->Cron->add($schedule.' '.$command);
			}
		}
		return true;
	}
	/**
	 * Update/Add a backup item. Note the only difference is weather we generate an ID
	 * @param  array $data an array of the items needed. typically just send the $_POST array
	 * @return string the backup id
	 */
	public function updateBackup(){
		$data = [];
		$data['id'] = $this->getReq('id',$this->generateID());

		foreach ($this->backupFields as $col) {
			//This will be set independently
			if($col == 'immortal'){
				continue;
			}
			//If this system is the primary system we get the key from the system.
			if($col == 'publickey'){
				if($this->getReq('warmspare_type','primary') === "primary"){
					continue;
				}
				$ssh = new Filestore\Remote();
				$ssh->addTrustedKey($this->getReq('publickey'));
			}
			$value = $this->getReqUnsafe($col,'');
			$this->updateBackupSetting($data['id'], $col, $value);
		}
		$description = $this->getReq('backup_description',sprintf(_('Backup %s'),$this->getReq('backup_name')));
		$this->setConfig($data['id'],array('id' => $data['id'], 'name' => $this->getReq('backup_name',''), 'description' => $description),'backupList');
		if($this->getReq('backup_items','unchanged') !== 'unchanged'){
			$backup_items = json_decode($this->$getReq('backup_items',[]),true);
			$this->setModulesById($data['id'], $backup_items);
		}
		if(isset($data['backup_items_settings']) && $data['backup_items_settings'] !== 'unchanged' ){
			$this->processBackupSettings($data['id'], json_decode($this->getReq('backup_items_settings'),true));
		}
		$this->scheduleJobs($id);
		return $id;
	}

	public function updateBackupSetting($id, $setting, $value=false){
		$this->setConfig($setting,$value,$id);
		if($setting == 'backup_schedule'){
			$this->scheduleJobs($id);
		}
	}
	public function getBackupSetting($id,$setting){
		return $this->getConfig($setting, $id);
	}
	/**
	 * delete backup by ID
	 * @param  string $id backup id
	 * @return bool	success/failure
	 */
	public function deleteBackup($id){
		$this->setConfig($id,false,'backupList');
		$this->delById($id);
		//This should return an empty array if successful.
		$this->scheduleJobs('all');
		return empty($this->getBackup($id));
	}

	/**
	 * Set the modules to backup for a specific id. This nukes prior data
	 * @param string $id      backup id
	 * @param array $modules associative array of modules [['modulename' => 'foo'], ['modulename' => 'bar']]
	 */
	public function setModulesById($id,$modules){
		$this->delById('modules_'.$id);
		foreach ($modules as $module) {
			if(!isset($module['modulename'])){
				continue;
			}
			$this->setConfig($module['modulename'],true,'modules_'.$id);
		}
		return $this->getAll('modules_'.$id);
	}
	

	//UTILITY
	
	public function processDependencies($deps = []){
		$ret = true;
		foreach($deps as $dep){

			if($this->FreePBX->Modules->getInfo(strtolower($dep),true)){
				continue;
			}
			try{
				$this->mf->install(strtolower($dep),true);
			}catch(\Exception $e){
				$ret = false;
				break;
			}
		}
		return $ret;
	}

	/**
	 * Wrapper for Ramsey UUID so we don't have to put the full namespace string everywhere
	 * @return string UUIDv4
	 */
	public function generateId(){
		return \Ramsey\Uuid\Uuid::uuid4()->toString();
	}

	public function log($transactionId = '', $message = ''){
		$entry = sprintf('[%s] - %s', $transactionId, $message);
		echo $entry.PHP_EOL;
		$this->sessionlog[$transactionId][] = $entry;
		$this->logger->logWrite('backup',$entry,true);
	}

	public function processNotifications($id, $transactionId, $errors){
		$backupInfo = $this->getBackup($id);
		if(!isset($backupInfo['backup_email']) || empty($backupInfo['backup_email'])){
			return false;
		}
		if(!isset($backupInfo['backup_emailtype']) || empty($backupInfo['backup_emailtype'])){
			return false;
		}
		$serverName = $this->FreePBX->Config->get('FREEPBX_SYSTEM_IDENT');
		$emailSubject = sprintf(_('Backup %s success for %s'),$backupInfo['backup_name'], $serverName);
		if(!empty($errors)){
			$emailSubject = sprintf(_('Backup %s failed for %s'),$backupInfo['backup_name'], $serverName);
		}

		if(isset($backupInfo['backup_emailtype']) && $backupInfo['backup_emailtype'] == 'success'){
			if(!empty($errors)){
				return false;
			}
		}
		
		$this->swiftmsg->setFrom('backup@chode.win');
		$this->swiftmsg->setSubject($emailSubject);
		$this->swiftmsg->setTo($backupInfo['backup_email']);
		$this->swiftmsg->attach(\Swift_Attachment::fromPath('/var/log/asterisk/backup.log')->setFilename('backup.log'));
		sleep(1);
		$this->handler->close();
	}
	public function getHooks($type = 'all'){
		if($type == 'backup' || $type == 'all'){
			$this->preBackup = new \SplQueue();
			$this->postBackup = new \SplQueue();
		}
		if($type == 'restore' || $type == 'all'){
			$this->preRestore = new \SplQueue();
			$this->postRestore = new \SplQueue();
		}
		foreach (new \DirectoryIterator('/home/asterisk/Backup') as $fileInfo) {
    		if($fileInfo->isFile() && $fileInfo->isReadable() && $fileInfo->isExecutable()){
				$fileobj = $fileInfo->openFile('r');
				while (!$fileobj->eof()) {
					$found = preg_match("/(pre|post):(backup|restore)/", $fileobj->fgets(), $out);
       				if($found === 1){
						$hooktype = $out[1].$out[2];
						$filename = $fileobj->getFilename();
						if($hooktype == prebackup && is_object($this->preBackup)){
							$this->preBackup->push($filename);
						}
						if($hooktype == postbackup && is_object($this->postBackup)){
							$this->postBackup->push($filename);
						}
						if($hooktype == prerestore && is_object($this->preRestore)){
							$this->preRestore->push($filename);
						}
						if($hooktype == postrestore && is_object($this->postRestore)){
							$this->postRestore->push($filename);
						}
						break;
					}
				}
			}
		}
	}
}