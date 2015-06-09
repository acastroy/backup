<?php

class Backup {

	/**
	 * Holds a list of paths to applications that we might need
	 * @param var
	 */
	public $apps;


	/**
	 * Holds settings for this backup
	 * @param var
	 */
	public $b;

	/**
	 * Holds a list of all servers
	 * @param var
	 */
	public $s;

	/**
	 * Holds a list of all templates
	 * @param var
	 */
	public $t;

	function __construct($b, $s, $t = '') {
		global $amp_conf, $db, $cdrdb;
		$this->b						= $b;
		$this->s						= $s;
		//$this->t						= $t;
		$this->amp_conf					= $amp_conf;

		$this->b['_ctime']				= time();
		$this->b['_file']				= date("Ymd-His-") . $this->b['_ctime'] . '-' . rand();
		$this->b['_dirname']			= trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $this->b['name']), '_');
		$this->db						= $db;
		$this->cdrdb					= $cdrdb;
		$this->amp_conf['CDRDBTYPE']	= $this->cdrdb->dsn['phptype'];
		$this->amp_conf['CDRDBHOST']	= $this->cdrdb->dsn['hostspec'];
		$this->amp_conf['CDRDBUSER']	= $this->cdrdb->dsn['username'];
		$this->amp_conf['CDRDBPASS']	= $this->cdrdb->dsn['password'];
		$this->amp_conf['CDRDBPORT']	= $this->cdrdb->dsn['port'];
		$this->amp_conf['CDRDBNAME']	= $this->cdrdb->dsn['database'];

		//defualt properties
		$this->b['prebu_hook']			= isset($b['prebu_hook'])	? $b['prebu_hook']	: '';
		$this->b['postbu_hook']			= isset($b['postbu_hook'])	? $b['postbu_hook']	: '';
		$this->b['prere_hook']			= isset($b['prere_hook'])	? $b['prere_hook']	: '';
		$this->b['postre_hook']			= isset($b['postre_hook'])	? $b['postre_hook']	: '';
		$this->b['email']				= isset($b['email'])		? $b['email']		: '';
		$this->b['error'] = false;

		ksort($this->b);
	}

	function __destruct() {
		//remove temp files and directories
		if (file_exists($this->b['_tmpfile'])) {
			unlink($this->b['_tmpfile']);
		}

		//remove file lock and release file handler
		if (isset($this->lock) && $this->lock) {
			flock($this->lock, LOCK_UN);
			fclose($this->lock);
			unlink($this->lock_file);
		}

		if (is_dir($this->b['_tmpdir'])) {
			$cmd = 'rm -rf ' . $this->b['_tmpdir'];
			exec($cmd);
		}

		/*
		 * cleanup stale backup files (older than one day)
		 * these files are those that were downloaded from a remote server
		 * usually, backups will be deleted after a restore
		 * but the user aborted the restore/decided not to go through with it
		 */
		$files = scandir($this->amp_conf['ASTSPOOLDIR'] . '/tmp/');
		foreach ($files as $file) {
			$f = explode('-', $file);
			if ($f[0] == 'backuptmp' && $f[2] < strtotime('yesterday')) {
				unlink($this->amp_conf['ASTSPOOLDIR'] . '/tmp/' . $file);
			}
		}

	}

	function init() {
		$this->b['_dirpath']	= $this->amp_conf['ASTSPOOLDIR'] . '/backup/' . $this->b['_dirname'];
		$this->b['_tmpdir']		= $this->amp_conf['ASTSPOOLDIR'] . '/tmp/backup-' . $this->b['id'];
		$this->b['_tmpfile']	= $this->amp_conf['ASTSPOOLDIR'] . '/tmp/' . $this->b['_file'] . '.tgz';
		$this->lock_file		= $this->b['_tmpdir'] . '/.lock';

		//create backup directory
		if (!(is_dir($this->b['_tmpdir']))) {
			mkdir($this->b['_tmpdir'], 0755, true);
		}
	}


	function acquire_lock() {
		//acquire file handler on lock file

		//TODO: use 'c+' once the project require php > 5.2.8
		if (file_exists($this->lock_file)) {
			//get pid that set the lock and ensure its still running
			$pid = file_get_contents($this->lock_file);

			exec(fpbx_which('ps') . ' h ' . $pid, $ret, $status);
			//exit code ($status) will be 0 if running, or 1 if pid not found
			if ($status === 0) {
				return false;
			} else {
				//if we dont see the prosses running, remove the lock
				unlink($this->lock_file);
			}
		}

		$this->lock	= fopen($this->lock_file, 'x+');


		if (flock($this->lock, LOCK_EX | LOCK_NB)) {
			fwrite($this->lock, getmypid());
			return true;
		} else {
			fclose($this->lock);
			unlink($this->lock_file);
			return false;
		}
	}

	function add_items() {
		foreach ($this->b['items'] as $i) {
			switch ($i['type']) {
				case 'file':
					//substitute variable if nesesary
					$i['path'] = backup__($i['path']);

					//make sure file exists
					if (!file_exists($i['path'])) {
						break;
					}

					//ensure directory structure
					$dest = $this->b['_tmpdir'] . realpath($i['path']);
					if (!is_dir(dirname($dest))) {
						mkdir(dirname($dest), 0755, true);
					}

					//copy file
					$cmd[] = fpbx_which('cp');
					$cmd[] = $i['path'];
					$cmd[] = $dest;

					exec(implode(' ', $cmd));
					unset($cmd);
					break;
				case 'dir':

					//subsitute variable if nesesary
					$i['path'] = backup__($i['path']);

					//ensure directory exists
					if (!is_dir($i['path'])) {
						break;
					}

					// Create destination directory structure
					$dest = $this->b['_tmpdir'].$i['path'];

					if (!is_dir($dest)) {
						mkdir($dest, 0755, true);
					}

					// Where are we copying from? Note we explicitly use realpath here,
					// as there could be any number of symlinks leading to the CONTENTS.
					// But we want to just back the contents up, and pretend those links
					// don't exist.
					$src = realpath($i['path']);

					// Build our list of excludes (if any)
					$excludes = "";
					if ($i['exclude']) {
						if (!is_array($i['exclude'])) {
							$xArr = explode("\n", $i['exclude']);
						} else {
							$xArr = $i['exclude'];
						}
						foreach ($xArr as $x) {
							$excludes .= " --exclude='$x'";
						}
					}

					// Use rsync to mirror $src to $dest.
					// Note we ensure we add the trailing slash to tell rsync to copy the
					// contents, not the targets (if the target is a link, for exmaple)
					$cmd = fpbx_which('rsync')."$excludes -av $src/ $dest/";
					exec($cmd);
					unset($cmd);
					break;
				case 'mysql':
					//build command
					$s = str_replace('server-', '', $i['path']);
					$sql_file = $this->b['_tmpdir'] . '/' . 'mysql-' . $s . '.sql';
					$cmd[] = fpbx_which('mysqldump');
					$cmd[] = '--host='		. backup__($this->s[$s]['host']);
					$cmd[] = '--port='		. backup__($this->s[$s]['port']);
					$cmd[] = '--user='		. backup__($this->s[$s]['user']);
					$cmd[] = '--password='	. backup__($this->s[$s]['password']);
					$cmd[] = backup__($this->s[$s]['dbname']);

					if ($i['exclude']) {
						foreach ($i['exclude'] as $x) {
							$cmd[] = '--ignore-table=' . backup__($this->s[$s]['dbname'])
									. '.' . backup__($x);
						}
					}
					$cmd[] = ' --opt --skip-comments --skip-extended-insert';

					// Need to grep out leading /* comments and SET commands as they create problems
					// restoring using the PEAR $db class
					//
					$cmd[] = ' | ';
					$cmd[] = fpbx_which('grep');
					$cmd[] = "-v '^\/\*\|^SET'";
					$cmd[] = ' > ' .  $sql_file;

					exec(implode(' ', $cmd), $file, $status);
					unset($cmd, $file);

					// remove file and log error information if it failed.
					//
					if ($status !== 0) {
						unlink($sql_file);
						$error_string = sprintf(
							_("Backup failed dumping SQL database [%s] to file [%s], "
							. "you have a corrupted backup from server [%s]."),
							backup__($this->s[$s]['dbname']), $sql_file, backup__($this->s[$s]['host'])
						);
						backup_log($error_string);
						freepbx_log(FPBX_LOG_FATAL, $error_string);
					}
					break;
				case 'astdb':
					$hard_exclude	= array('RG', 'BLKVM', 'FM', 'dundi');
					$exclude		= array_merge($i['exclude'], $hard_exclude);
					$astdb			= astdb_get($exclude);
					file_put_contents($this->b['_tmpdir'] . '/astdb', serialize($astdb));
					break;
			}
		}
	}

	function run_hooks($hook) {
		switch ($hook) {
			case 'pre-backup':
				if (isset($this->b['prebu_hook']) && $this->b['prebu_hook']) {
					exec($this->b['prebu_hook']);
				}
				mod_func_iterator('backup_pre_backup_hook', $this);
				break;
			case 'post-backup':
				if (isset($this->b['postbu_hook']) && $this->b['postbu_hook']) {
					exec($this->b['postbu_hook']);
				}
				mod_func_iterator('backup_post_backup_hook', $this);
				break;
		}
	}

	function create_backup_file($to_stdout = false) {
		$cmd[] = fpbx_which('tar');
		$cmd[] = 'zcf';
		$cmd[] = $to_stdout ? '-' : $this->b['_tmpfile'];
		$cmd[] = '-C ' . $this->b['_tmpdir'];
		// Always put the manifest file FIRST
		$cmd[] = './manifest .';
		//dbug('create_backup', implode(' ', $cmd));
		if ($to_stdout) {
			system(implode(' ', $cmd));
		} else {
			exec(implode(' ', $cmd));
		}

	}

	function store_backup() {
		foreach ($this->b['storage_servers'] as $s) {
			$s = $this->s[$s];
			switch ($s['type']) {
				case 'local':
					$path = backup__($s['path']) . '/' . $this->b['_dirname'];
					//ensure directory structure
					if (!is_dir($path)) {
						mkdir($path, 0755, true);
					}

					//would rather use the native copy() here, but by defualt
					//php doesnt support files > 2GB
					//see here for a posible solution:
					//http://ca3.php.net/manual/en/function.fopen.php#37791
					$cmd[] = fpbx_which('cp');
					$cmd[] = $this->b['_tmpfile'];
					$cmd[] = $path . '/' . $this->b['_file'] . '.tgz';

					exec(implode(' ', $cmd), $error, $status);
					unset($cmd, $error);
					if ($status !== 0) {
						$this->b['error'] = 'Error copying ' . $this->b['_tmpfile']
								. ' to ' . $path . '/' . $this->b['_file']
								. '.tgz: ' . $error;
						backup_log($this->b['error']);
					}
					//run maintenance on the directory
					$this->maintenance($s['type'], $s);
					break;
				case 'email':
					//dont run if the file is too big
					if (filesize($this->b['_tmpfile']) > $s['maxsize']) {
						continue;
					}

					//TODO: set agent to something informative, including fpbx & backup versions
					$email_options = array('useragent' => 'freepbx', 'protocol' => 'mail');
					$email = new CI_Email();
					$from = $this->amp_conf['AMPBACKUPEMAILFROM']
							? $this->amp_conf['AMPBACKUPEMAILFROM']
							: 'freepbx@freepbx.org';

					$msg[] = _('Name')			. ': ' . $this->b['name'];
					$msg[] = _('Created')		. ': ' . date('r', $this->b['_ctime']);
					$msg[] = _('Files')			. ': ' . $this->manifest['file_count'];
					$msg[] = _('Mysql Db\'s')	. ': ' . $this->manifest['mysql_count'];
					$msg[] = _('astDb\'s')		. ': ' . $this->manifest['astdb_count'];

					$email->from($from);
					$email->to(backup__($s['addr']));
					$email->subject(_('Backup') . ' ' . $this->b['name']);
					$email->message(implode("\n", $msg));
					$email->attach($this->b['_tmpfile']);
					$email->send();

					unset($msg);
					break;
				case 'ftp':
					//subsitute variables if nesesary
					$s['host'] = backup__($s['host']);
					$s['port'] = backup__($s['port']);
					$s['user'] = backup__($s['user']);
					$s['password'] = backup__($s['password']);
					$s['path'] = backup__($s['path']);
					$ftp = ftp_connect($s['host'], $s['port']);
					if (ftp_login($ftp, $s['user'], $s['password'])) {
						//chose pasive/active transfer mode
						ftp_pasv($ftp, ($s['transfer'] == 'passive'));

						//switch to directory. If we fail, build directory structure and try again
						if (!ftp_chdir($ftp, $s['path'] . '/' . $this->b['_dirname'])) {
							//ensure directory structure
							ftp_mkdir($ftp, $s['path']);
							ftp_mkdir($ftp, $s['path'] . '/' . $this->b['_dirname']);
							ftp_chdir($ftp, $s['path'] . '/' . $this->b['_dirname']);
						}

						//copy file
						ftp_put($ftp, $this->b['_file'] . '.tgz', $this->b['_tmpfile'], FTP_BINARY);

						//run maintenance on the directory
						$this->maintenance($s['type'], $s, $ftp);

						//release handel
						ftp_close($ftp);
					} else {
						$this->b['error'] = _("Error connecting to the FTP Server...");
						backup_log($this->b['error']);
					}
					break;
				case 'ssh':
					//subsitute variables if nesesary
					$s['path'] = backup__($s['path']);
					$s['user'] = backup__($s['user']);
					$s['host'] = backup__($s['host']);

					//ensure directory structure
					$cmd[] = fpbx_which('ssh');
					$cmd[] = '-o StrictHostKeyChecking=no -i';
					$cmd[] = $s['key'];
					$cmd[] = $s['user'] . '\@' . $s['host'];
					$cmd[] = '-p ' . $s['port'];
					$cmd[] = 'mkdir -p ' . $s['path']
							. '/' . $this->b['_dirname'];

					exec(implode(' ', $cmd));
					unset($cmd);

					//put file
					$cmd[] = fpbx_which('scp');
					$cmd[] = '-o StrictHostKeyChecking=no -i';
					$cmd[] = $s['key'];
					$cmd[] = '-P ' . $s['port'];
					$cmd[] = $this->b['_tmpfile'];
					$cmd[] = $s['user'] . '\@' . $s['host']
							. ':' . $s['path'] . '/' . $this->b['_dirname'];

					exec(implode(' ', $cmd));
					unset($cmd);

					//run maintenance on the directory
					$this->maintenance($s['type'], $s);
					break;
			}
		}
	}

	function build_manifest() {
		$ret = array(
			"manifest_version" => 10,
			"hostname" => php_uname("n"),
			"fpbx_db" => "",
			"mysql" => "",
			"astdb" => "",
			"fpbx_cdrdb" => "",
			"name" => $this->b['name'],
			"ctime" => $this->b['_ctime'],
			"pbx_framework_version" => get_framework_version(),
			"backup_version" => modules_getversion('backup'),
			"pbx_version" => getversion(),
			"hooks"	=> array(
				'pre_backup' => $this->b['prebu_hook'],
				'post_backup' => $this->b['postbu_hook'],
				'pre_restore' => $this->b['prere_hook'],
				'post_restore' => $this->b['postre_hook'],
			),
		);

		// Actually generate the file list
		$ret["file_list"] = $this->getDirContents($this->b['_tmpdir']);

		// Remove the mysql/astdb files, add them seperatly
		foreach($ret['file_list'] as $key => $file) {

			if (is_array($file)) {
				// It's a subdirectory. Ignore.
				continue;
			}

			// Is it the astdb? We don't report that as part of
			// the file manifest, so people can chose to restore
			// or not restore it individually.
			if ($file == 'astdb') {
				unset($ret['file_list'][$key]);
				$ret['astdb'] = 'astdb';
				continue;
			}

			// Is it a MySQL dump?
			if (strpos($file, 'mysql-') === 0) {
				//get server id
				$s = substr($file, 6);
				$s = substr($s, 0, -4);

				//get exclude
				foreach($this->b['items'] as $i) {
					if($i['type'] == 'mysql' && $i['path'] == 'server-' . $s) {
						$exclude = $i['exclude'];
						break;
					}
				}

				//build array on this server
				$ret['mysql'][$s] = array(
					'file'		=> $file,
					'host'		=> backup__($this->s[$s]['host']),
					'port'		=> backup__($this->s[$s]['port']),
					'name'		=> backup__($this->s[$s]['name']),
					'dbname'	=> backup__($this->s[$s]['dbname']),
					'exclude'	=> $exclude
				);

				//if this server is freepbx's primary server datastore, record that
				if ($ret['mysql'][$s]['dbname'] == $this->amp_conf['AMPDBNAME']) {

					//localhost and 127.0.0.1 are intergangeable, so test both scenarios
					if (in_array(strtolower($ret['mysql'][$s]['host']), array('localhost', '127.0.0.1'))
						&& in_array(strtolower($this->amp_conf['AMPDBHOST']), array('localhost', '127.0.0.1'))
						|| $ret['mysql'][$s]['host'] == $this->amp_conf['AMPDBHOST']
					) {
						$ret['fpbx_db'] = 'mysql-' . $s;
						unset($ret['file_list'][$key]);
					}

					//if this server is freepbx's primary cdr server datastore, record that
				} elseif($ret['mysql'][$s]['dbname'] == $this->amp_conf['CDRDBNAME']) {
					//localhost and 127.0.0.1 are intergangeable, so test both scenarios
					if (in_array(strtolower($ret['mysql'][$s]['host']), array('localhost', '127.0.0.1'))
						&& in_array(strtolower($this->amp_conf['CDRDBHOST']), array('localhost', '127.0.0.1'))
						|| $ret['mysql'][$s]['host'] == $this->amp_conf['CDRDBHOST']
					) {
						$ret['fpbx_cdrdb'] = 'mysql-' . $s;
						unset($ret['file_list'][$key]);
					}
				}
				continue;
			} 
			
			// Also exclude random .lock files left around.
			if ($file == '.lock') {
				unset($ret['file_list'][$key]);
				// Yes, I know, I'm the last thing in the loop. Consistancy!
				continue;
			}
		}

		$ret['file_count']	= count($ret['file_list'], COUNT_RECURSIVE);
		$ret['mysql_count']	= $ret['mysql'] ? count($ret['mysql']) : 0;
		$ret['astdb_count']	= $ret['astdb'] ? count($ret['astdb']) : 0;
		$ret['ftime']		= time();//finish time

		$this->b['manifest'] = $ret;
	}

	function save_manifest($location) {
		switch ($location) {
			case 'local':
				file_put_contents($this->b['_tmpdir'] . '/manifest', serialize($this->b['manifest']));
				break;
			case 'db':
				$manifest = $this->b['manifest'];
				unset($manifest['file_list']);
				//save manifest in db
				//dontsave the file list in the db - its way to big

				$sql = 'INSERT INTO backup_cache (id, manifest) VALUES (?, ?)';
				$q = $this->db->query($sql, array($this->b['_file'], serialize($manifest)));
				if ($this->db->IsError($q)){
					die_freepbx($q->getDebugInfo());
				}
				break;
		}
	}

	private function maintenance($type, $data, $handle = '') {
		if (!isset($this->b['delete_time'])  && !isset($this->b['delete_amount'])) {
			return true;
		}
		$delete = $dir = array();

		//get file list
		switch ($type) {
			case 'local':
				$dir = scandir(backup__($data['path']) . '/' . $this->b['_dirname']);
				break;
			case 'ftp':
				$dir = ftp_nlist($handle, '.');
				break;
			case 'ssh':
				$cmd[] = fpbx_which('ssh');
				$cmd[] = '-o StrictHostKeyChecking=no -i';
				$cmd[] = $data['key'];
				$cmd[] = $data['user'] . '\@' . $data['host'];
				$cmd[] = '-p ' . $data['port'];
				$cmd[] = 'ls -1 ' . $data['path'] . '/' . $this->b['_dirname'];
				exec(implode(' ', $cmd), $dir);
				unset($cmd);
				break;
		}

		//sanitize file list
		foreach ($dir as $file) {
			//dont include the current backup or special items
			if (in_array($file, array('.', '..', $this->b['_file']))) {
				continue;
			}
			$f = explode('-', $file);

			//remove file sufix
			$files[$f[2]] = $file;

		}


		//sort file list based on backup creation time
		ksort($files, SORT_NUMERIC);

		//create delete list based on creation time
		if (isset($this->b['delete_time']) && $this->b['delete_time']) {
			$cut_line = strtotime($this->b['delete_time'] . ' ' . $this->b['delete_time_type'] . ' ago');
			foreach ($files as $epoch => $file) {
				if ($epoch < $cut_line) {
					$delete[$epoch] = $file;
				}
			}
		}

		//create delete list based on quantity of files
		if (isset($this->b['delete_amount']) && $this->b['delete_amount']) {
			for ($i = 0; $i < $this->b['delete_amount']; $i++) {
				array_pop($files);
			}
			$delete = array_merge($files, $delete);
		}

		//now delete the actual files
		foreach($delete as $key => $file) {
			switch($type) {
				case 'local':
					unlink(backup__($data['path']) . '/' . $this->b['_dirname'] . '/' . $file);
					unset($delete[$key]);
					break;
				case 'ftp':
					ftp_delete($handle, $file);
					unset($delete[$key]);
					break;
				case 'ssh':
					$cmd[] = fpbx_which('ssh');
					$cmd[] = '-o StrictHostKeyChecking=no -i';
					$cmd[] = $data['key'];
					$cmd[] = $data['user'] . '\@' . $data['host'];
					$cmd[] = '-p ' . $data['port'];
					$cmd[] = 'rm ' . $data['path'] . '/' . '/' . $this->b['_dirname'] . '/' . $file;
					exec(implode(' ', $cmd));
					unset($delete[$key]);
					unset($cmd);
					break;
			}
		}

	}
	function emailCheck() {
		if(!empty($this->b['email'])) {
			$from = !empty($this->amp_conf['AMPBACKUPEMAILFROM']) ? $this->amp_conf['AMPBACKUPEMAILFROM'] : get_current_user() . '@' . gethostname();

			if(function_exists('sysadmin_get_storage_email')) {
				$emails = sysadmin_get_storage_email();
				if(!empty($emails['fromemail']) && filter_var($emails['fromemail'],FILTER_VALIDATE_EMAIL)) {
					$from = $emails['fromemail'];
				}
			}

			$subject = date("F j, Y, g:i a").'-'.$this->b['name'];
			backup_email_log($this->b['email'], $from, $subject);

		}
	}

	/**
	 * getDirContents - Return a hash of files and directories underneath $dir
	 *
	 * This also provides a 'followsymlinks' param, which treat the original symlink
	 * as a file. Any further symlinks will NOT be followed. 
	 *
	 * @param $dir string - Directory to iterate through
	 * @param $followsymlink bool - Continue if the first directory provided is a symlink
	 * @return array - Results.
	 */

	public function getDirContents($dir = false, $followsymlink = true) {

		$files = array();

		$d = new \DirectoryIterator($dir);
		foreach ($d as $dent) {
			$filename = $dent->__toString(); // Needed for php5.4 and lower

			if ($dent->isDot()) {
				continue;
			}

			if ($dent->isFile()) {
				$files[] = $filename;
				continue;
			}

			if ($dent->isLink()) {
				if ($followsymlink) {
					$files[$filename] = $this->getDirContents("$dir/$filename", false);
				} else {
					$files[] = $filename;
				}
				continue;
			}

			if ($dent->isDir()) {
				$files[$filename] = $this->getDirContents("$dir/$filename", false);
				continue;
			}
		}
		return $files;
	}
}
