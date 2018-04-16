<?php
$backupClass = FreePBX::Backup();
?>
<div class="container-fluid">
	<div class = "display full-border">
		<div class="row">
			<div class="col-sm-12">
				<div class="fpbx-container">
					<div class="display full-border">
						<ul class="nav nav-tabs">
							<li role="presentation" class='<?php echo ((isset($_GET['view']) && $_GET['view'] == 'form') || !isset($_GET['view']))?"active":""?>'><a href="?display=backup"><?php echo _("Backup")?></a></li>
							<li role="presentation"><a href="?display=backup_restore"><?php echo _("Restore")?></a></li>
							<li role="presentation" class='<?php echo (isset($_GET['view']) && $_GET['view'] == 'download')?"active":""?>'><a href="?display=backup&view=download"><?php echo _("Download")?></a></li>
							<!--Hide for alpha/beta... -->
							<li role="presentation" class='<?php echo (isset($_GET['view']) && $_GET['view'] == 'transfer')?"active":""?>'><a href="?display=backup&view=transfer"><?php echo _("System Transfer")?></a></li>
						</ul>
						<br/>
						<?php echo $backupClass->showPage('backup'); ?>
					</div>
				</div>
			</div>
		</div>
		<div class="modal fade" id="backuplog" tabindex="-1" role="dialog" aria-labelledby="backuplog" aria-hidden="true">
			<div class="modal-dialog" role="document">
				<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="backupLogTitle"><?php echo _("Backup Output")?></h5>
					<button type="button" class="close" data-dismiss="modal" aria-label="Close">
					<span aria-hidden="true">&times;</span>
					</button>
				</div>
				<div class="modal-body" style="overflow-y: auto;">
				<ul class="list-group" id="logdiv"></ul>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
				</div>
				</div>
			</div>
	</div>
</div>
