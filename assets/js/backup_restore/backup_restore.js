$(document).ready(() => {

  $("#backupUpload").click( event => {
    event.preventDefault();
    let file = $("#filetorestore")[0].files[0];
    var formData = new FormData();
    formData.append('filetorestore', file);
    $.ajax({
      url: `${ajaxurl}?module=backup&command=uploadrestore`,
      type: 'POST',
      data: formData,
      processData: false,  // tell jQuery not to process the data
      contentType: false  // tell jQuery not to set contentType
    })
    .then(data => {
      if(data.status == true){
        let url = `${window.location.href}&view=processrestore&id=${data.id}`;
        console.log(url);
        //window.location = url;
      }else{
        fpbxToast(data.message, 'error');
      }
    })
    .fail(err =>{
      fpbxToast("Unable to upload File");
      console.log(err);
      return false;
    });
    return false;
  });
	const inputElement = document.querySelector('input[type="file"]');
  const pond = FilePond.create( inputElement );
  //pond.registerPlugin('filepond-plugin-file-validate-type');
  pond.setOptions({
    server: ajaxurl+'?module=backup&command=uploadrestore',
    instantUpload: true,
    acceptedFileTypes: ['application/x-gzip'],
    labelFileTypeNotAllowed: _('File of invalid type'),
    abelIdle: _("Drag & Drop your files or <span class=\"filepond--label - action\"> Browse </span>"),
    labelFileWaitingForSize: _("Waiting for size"),
    labelFileSizeNotAvailable: _("Size not available"),
    labelFileLoading: _("Loading"),
    labelFileLoadError: _("Error during load"),
    labelFileProcessing: _("Uploading"),
    labelFileProcessingComplete: _("Upload complete"),
    labelFileProcessingAborted: _("Upload cancelled"),
    labelFileProcessingError: _("Error during upload"),
    labelTapToCancel: _("tap to cancel"),
    labelTapToRetry: _("tap to retry"),
    labelTapToUndo: _("tap to undo"),
    labelButtonRemoveItem: _("Remove"),
    labelButtonAbortItemLoad: _("Abort"),
    labelButtonRetryItemLoad: _("Retry"),
    labelButtonAbortItemProcessing: _("Cancel"),
    labelButtonUndoItemProcessing: _("Undo"),
    labelButtonRetryItemProcessing: _("Retry"),
    labelButtonProcessItem: 	_('Upload')
  });
});//end document ready

function localLinkFormatter(value, row, index) {
  var html = '<a href="?display=backup_restore&view=processrestore&type=local&id=' + row['id'] + '"><i class="fa fa-play"></i></a>';
  html += '<a href="/admin/api/backup/localdownload?id='+row['id']+'" class="localdownload" target="_blank"><i class="fa fa-download"></i></a>';
  html += '&nbsp;<a href="#" id="' + row['id'] + '" class="localDelete"><i class="fa fa-trash"></i></a>';
  return html;
}
$("table").on("post-body.bs.table", function () {
  $('.localDelete').on('click', e =>{
    e.preventDefault();
    fpbxConfirm(_("Are you sure you wish to delete this file? This cannot be undone"),
      _("Delete"),_("Cancel"),
      function(){
        var id = e.currentTarget.id;
        $.ajax({
          url: ajaxurl,
          method: "GET",
          data: {
            module: 'backup',
            command: 'deleteLocal',
            id: id
          }
        })
        .then(data => {
          console.log(data);
          if(data.status){
            $("#localrestorefiles").bootstrapTable('refresh',{silent:true});
          }
          fpbxToast(data.message);
        });
      }
    );
  });
});