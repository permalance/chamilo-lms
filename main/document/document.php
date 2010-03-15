<?php
/* For licensing terms, see /license.txt */

/**
 * Homepage script for the documents tool
 *
 * This script allows the user to manage files and directories on a remote http
 * server.
 * The user can : - navigate through files and directories.
 *				 - upload a file
 *				 - delete, copy a file or a directory
 *				 - edit properties & content (name, comments, html content)
 * The script is organised in four sections.
 *
 * 1) Execute the command called by the user
 *				Note: somme commands of this section are organised in two steps.
 *			    The script always begins with the second step,
 *			    so it allows to return more easily to the first step.
 *
 *				Note (March 2004) some editing functions (renaming, commenting)
 *				are moved to a separate page, edit_document.php. This is also
 *				where xml and other stuff should be added.
 * 2) Define the directory to display
 * 3) Read files and directories from the directory defined in part 2
 * 4) Display all of that on an HTML page
 *
 * @todo eliminate code duplication with document/document.php, scormdocument.php
 *
 * @package chamilo.document
 */

/*	INIT SECTION */

// Name of the language file that needs to be included
$language_file = array('document', 'slideshow');

require_once '../inc/global.inc.php';
$this_section = SECTION_COURSES;

require_once 'document.inc.php';
$lib_path = api_get_path(LIBRARY_PATH);

require_once $lib_path.'usermanager.lib.php';
require_once api_get_path(LIBRARY_PATH).'document.lib.php';
require_once api_get_path(LIBRARY_PATH).'fileUpload.lib.php';

api_protect_course_script(true);
$htmlHeadXtra[] = '<script src="'.api_get_path(WEB_LIBRARY_PATH).'javascript/jquery.js" type="text/javascript" language="javascript"></script>'; //jQuery
$htmlHeadXtra[] = '<script type="text/javascript" src="'.api_get_path(WEB_LIBRARY_PATH).'javascript/thickbox.js"></script>';
$htmlHeadXtra[] = '<link rel="stylesheet" href="'.api_get_path(WEB_LIBRARY_PATH).'javascript/thickbox.css" type="text/css" media="projection, screen">';
$htmlHeadXtra[] = '<script type="text/javascript">
$(document).ready( function() {
	for (i=0;i<$(".actions").length;i++) {
		if ($(".actions:eq("+i+")").html()=="<table border=\"0\"></table>" || $(".actions:eq("+i+")").html()=="" || $(".actions:eq("+i+")").html()==null) {
			$(".actions:eq("+i+")").hide();
		}
	}
 } );
 </script>';
//session
if (isset($_GET['id_session'])) {
	$_SESSION['id_session'] = Security::remove_XSS($_GET['id_session']);
}
//create directory certificates
$course_id=api_get_course_id();
DocumentManager::create_directory_certificate_in_course($course_id);

//show preview
if (isset($_GET['curdirpath']) && $_GET['curdirpath']=='/certificates' && isset($_GET['set_preview']) && $_GET['set_preview']==strval(intval($_GET['set_preview']))) {
	if (isset($_GET['set_preview'])) {
			//generate document HTML
			$course_id=api_get_course_id();
			$content_html=DocumentManager::replace_user_info_into_html($course_id);
			
			$new_content_html=$content_html;

			$path_image=api_get_path(WEB_COURSE_PATH).api_get_course_path().'/document/images/gallery';
			$new_content_html=str_replace('../images/gallery',$path_image,$new_content_html);
			
			$path_image_in_default_course=api_get_path(WEB_CODE_PATH).'default_course_document';
			$new_content_html=str_replace('/main/default_course_document',$path_image_in_default_course,$new_content_html);

			$path_image_in_dokeos_main=api_get_path(WEB_IMG_PATH);
			$new_content_html=str_replace('/main/img/',$path_image_in_dokeos_main,$new_content_html);
			echo '
			<style media="print" type="text/css">
				#imprimir {
				visibility:hidden;
				}				
			</style>';
			echo '<a href="javascript:window.print();" style="float:right; padding:4px;" id="imprimir"><img src="../img/printmgr.gif" alt="' . get_lang('Print') . '" /> ' . get_lang('Print') . '</a>';
			print_r($new_content_html);
			exit;
	}
}

// Is the document tool visible?
// Check whether the tool is actually visible
$table_course_tool = Database::get_course_table(TABLE_TOOL_LIST, $_course['dbName']);
$tool_sql = 'SELECT visibility FROM ' . $table_course_tool . ' WHERE name = "'. TOOL_DOCUMENT .'" LIMIT 1';
$tool_result = Database::query($tool_sql);
$tool_row = Database::fetch_array($tool_result);
$tool_visibility = $tool_row['visibility'];
if ($tool_visibility == '0' && $to_group_id == '0' && !($is_allowed_to_edit || $group_member_with_upload_rights)) {
	api_not_allowed(true);
}

$htmlHeadXtra[] =
"<script type=\"text/javascript\">
function confirmation (name)
{
	if (confirm(\" ". get_lang("AreYouSureToDelete") ." \"+ name + \" ?\"))
		{return true;}
	else
		{return false;}
}
</script>";

/*
	Variables
	- some need defining before inclusion of libraries
*/

// What's the current path?
// We will verify this a bit further down
if (isset($_GET['curdirpath']) && $_GET['curdirpath'] != '') {
	$curdirpath = Security::remove_XSS($_GET['curdirpath']);
} elseif (isset($_POST['curdirpath']) && $_POST['curdirpath'] != '') {
	$curdirpath = Security::remove_XSS($_POST['curdirpath']);
} else {
	$curdirpath = '/';
}
$curdirpathurl = urlencode($curdirpath);

$course_dir      = $_course['path'].'/document';
$sys_course_path = api_get_path(SYS_COURSE_PATH);
$base_work_dir   = $sys_course_path.$course_dir;
$http_www        = api_get_path(WEB_COURSE_PATH).$_course['path'].'/document';
$dbl_click_id    = 0; // Used for avoiding double-click

$is_allowed_to_edit = api_is_allowed_to_edit(null, true);
$group_member_with_upload_rights = false;

// If the group id is set, we show them group documents
if (isset($_SESSION['_gid']) && $_SESSION['_gid']!='') {
	// Needed for group related stuff
	require_once($lib_path. 'groupmanager.lib.php');
	// Get group info
	$group_properties = GroupManager::get_group_properties($_SESSION['_gid']);
	$noPHP_SELF=true;
	// Let's assume the user cannot upload files for the group
	$group_member_with_upload_rights = false;

	if ($group_properties['doc_state'] == 2) { // Documents are private
		if ($is_allowed_to_edit || GroupManager :: is_user_in_group($_user['user_id'], $_SESSION['_gid'])) { // Only courseadmin or group members (members + tutors) allowed
			$to_group_id = $_SESSION['_gid'];
			$req_gid = '&amp;gidReq='.$_SESSION['_gid'];
			$interbreadcrumb[] = array('url' => '../group/group.php', 'name' => get_lang('Groups'));
			$interbreadcrumb[] = array('url' => '../group/group_space.php?gidReq='.$_SESSION['_gid'], 'name' => get_lang('GroupSpace').' ('.$group_properties['name'].')');
			//they are allowed to upload
			$group_member_with_upload_rights = true;
		} else {
			$to_group_id = 0;
			$req_gid = '';
		}
	} elseif ($group_properties['doc_state'] == 1) {  // Documents are public
		$to_group_id = $_SESSION['_gid'];
		$req_gid = '&amp;gidReq='.$_SESSION['_gid'];
		$interbreadcrumb[] = array ('url' => '../group/group.php', 'name' => get_lang('Groups'));
		$interbreadcrumb[] = array ('url' => '../group/group_space.php?gidReq='.$_SESSION['_gid'], 'name' => get_lang('GroupSpace').' ('.$group_properties['name'].')');
		//allowed to upload?
		if ($is_allowed_to_edit || GroupManager::is_subscribed($_user['user_id'], $_SESSION['_gid'])) { // Only courseadmin or group members can upload
			$group_member_with_upload_rights = true;
		}
	} else { //documents not active for this group
		$to_group_id = 0;
		$req_gid = '';
	}
	$_SESSION['group_member_with_upload_rights'] = $group_member_with_upload_rights;
} else {
	$to_group_id = 0;
	$req_gid = '';
}

// For sessions we should check the parameters of visibility
if (api_get_session_id() != 0) {
	$group_member_with_upload_rights = $group_member_with_upload_rights && api_is_allowed_to_session_edit(false, true);
}

/* Libraries */

require_once $lib_path.'fileDisplay.lib.php';
require_once $lib_path.'document.lib.php';
require_once $lib_path.'tablesort.lib.php';
require_once $lib_path.'fileUpload.lib.php';

// Check the path
// If the path is not found (no document id), set the path to /
if (!DocumentManager::get_document_id($_course, $curdirpath)) {
	$curdirpath = '/';
	// Urlencoded version
	$curdirpathurl = '%2F';
}
// If they are looking at group documents they can't see the root
if ($to_group_id != 0 && $curdirpath == '/') {
	$curdirpath = $group_properties['directory'];
	$curdirpathurl = urlencode($group_properties['directory']);
}

// Check visibility of the current dir path. Don't show anything if not allowed
if (!(DocumentManager::is_visible($curdirpath, $_course)||$is_allowed_to_edit)) {
    api_not_allowed();
}

/*	Constants and variables */

$course_quota = DocumentManager::get_course_quota();
$current_session_id = api_get_session_id();

/*	Create the current user shared folder if no exist */

if (!file_exists($base_work_dir.'/shared_folder/sf_user_'.api_get_user_id())) {
	$usf_dir_title = api_get_person_name($_user['firstName'], $_user['lastName']);
	$usf_dir_name = '/shared_folder/sf_user_'.api_get_user_id();
	$to_group_id = 0;
	create_unexisting_directory($_course, $_user['user_id'], $to_group_id, $to_user_id, $base_work_dir, $usf_dir_name, $usf_dir_title);
}

/*	MAIN SECTION */

if (isset($_GET['action']) && $_GET['action'] == 'download') {
	$my_get_id = Security::remove_XSS($_GET['id']);

	// Check whether the document is in the database
	if (!DocumentManager::get_document_id($_course, $my_get_id)) {
		// File not found!
		header('HTTP/1.0 404 Not Found');
		$error404 = '<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">';
		$error404 .= '<html><head>';
		$error404 .= '<title>404 Not Found</title>';
		$error404 .= '</head><body>';
		$error404 .= '<h1>Not Found</h1>';
		$error404 .= '<p>The requested URL was not found on this server.</p>';
		$error404 .= '<hr>';
		$error404 .= '</body></html>';
		echo($error404);
		exit;
	}

	// Launch event
	event_download($my_get_id);

    // Check visibility of document and paths
    if (!($is_allowed_to_edit || $group_member_with_upload_rights) && !DocumentManager::is_visible($my_get_id, $_course)) {
        api_not_allowed();
    }

    $doc_url = $my_get_id;
	$full_file_name = $base_work_dir.$doc_url;
	DocumentManager::file_send_for_download($full_file_name, true);
	exit;
}

// Download of an completed folder
if (isset($_GET['action']) && $_GET['action'] == 'downloadfolder' && (api_get_setting('students_download_folders') == 'true' || api_is_allowed_to_edit() || api_is_platform_admin())) {
	require 'downloadfolder.inc.php';
}

// Slideshow inititalisation
$_SESSION['image_files_only'] = '';
$image_files_only = '';

/*	Header */

$interbreadcrumb[]= array ('url' => '', 'name' => get_lang('ToolDocument'));

// Interbreadcrumb for the current directory root path

$dir_array = explode('/', $curdirpath);
$array_len = count($dir_array);

if ($array_len > 1) {
	if (empty($_SESSION['_gid'])) {
		$url_dir = 'document.php?&curdirpath=/';
		$interbreadcrumb[] = array('url' => $url_dir, 'name' => get_lang('HomeDirectory'));
	}
}

$dir_acum = '';
for ($i = 0; $i < $array_len; $i++) {
	if ($dir_array[$i] =='shared_folder') {
		$dir_array[$i] = get_lang('SharedFolder');
	} elseif (strpos($dir_array[$i], 'sf_user_') !== false) {
		$userinfo = Database::get_user_info_from_id(substr($dir_array[$i], 8));
		$dir_array[$i] = api_get_person_name($userinfo['firstname'], $userinfo['lastname']);
	}

	$url_dir = 'document.php?&curdirpath='.$dir_acum.$dir_array[$i];
	$interbreadcrumb[] = array('url' => $url_dir, 'name' => $dir_array[$i]);
	$dir_acum.=$dir_array[$i].'/';
}

Display::display_header('','Doc');

// Lib for event log, stats & tracking & record of the access
event_access_tool(TOOL_DOCUMENT);

/*	DISPLAY */

if ($to_group_id != 0) { // Add group name after for group documents
	$add_group_to_title = ' ('.$group_properties['name'].')';
}

/* Introduction section (editable by course admins) */

if (!empty($_SESSION['_gid'])) {
	Display::display_introduction_section(TOOL_DOCUMENT.$_SESSION['_gid']);
} else {
	Display::display_introduction_section(TOOL_DOCUMENT);
}

if ($is_allowed_to_edit || $group_member_with_upload_rights) { // TEACHER ONLY

	/*	MOVE FILE OR DIRECTORY */

	$my_get_move = Security::remove_XSS($_GET['move']);
	if (isset($_GET['move']) && $_GET['move'] != '') {
		if (!$is_allowed_to_edit) {
			if (DocumentManager::check_readonly($_course, $_user['user_id'], $my_get_move)) {
				api_not_allowed();
			}
		}

		if (DocumentManager::get_document_id($_course, $my_get_move)) {
			$folders = DocumentManager::get_all_document_folders($_course, $to_group_id, $is_allowed_to_edit || $group_member_with_upload_rights);
			echo '<div class="row"><div class="form_header">'.get_lang('Move').'</div></div>';
			echo build_move_to_selector($folders, Security::remove_XSS($_GET['curdirpath']), $my_get_move, $group_properties['directory']);
		}
	}

	if (isset($_POST['move_to']) && isset($_POST['move_file'])) {
		if (!$is_allowed_to_edit) {
			if (DocumentManager::check_readonly($_course, $_user['user_id'], $my_get_move)) {
				api_not_allowed();
			}
		}

		require_once api_get_path(LIBRARY_PATH).'fileManage.lib.php';
		// This is needed for the update_db_info function
		//$dbTable = $_course['dbNameGlu']."document";
		$dbTable = Database::get_course_table(TABLE_DOCUMENT);

		// Security fix: make sure they can't move files that are not in the document table
		if (DocumentManager::get_document_id($_course, $_POST['move_file'])) {
			if (move($base_work_dir.$_POST['move_file'], $base_work_dir.$_POST['move_to'])) {
				update_db_info('update', $_POST['move_file'], $_POST['move_to'].'/'.basename($_POST['move_file']));
				// Set the current path
				$curdirpath = $_POST['move_to'];
				$curdirpathurl = urlencode($_POST['move_to']);
				Display::display_confirmation_message(get_lang('DirMv'));
			} else {
				Display::display_error_message(get_lang('Impossible'));
			}
		} else {
			Display::display_error_message(get_lang('Impossible'));
		}
	}

	/*	DELETE FILE OR DIRECTORY */

	if (isset($_GET['delete'])) {
		if (!$is_allowed_to_edit) {
			if (DocumentManager::check_readonly($_course, $_user['user_id'], $_GET['delete'], '', true)) {
				api_not_allowed();
			}
		}

		require_once api_get_path(LIBRARY_PATH).'fileManage.lib.php';

		if(DocumentManager::delete_document($_course,$_GET['delete'],$base_work_dir))
		{
			if ( isset($_GET['delete_certificate_id']) && $_GET['delete_certificate_id']==strval(intval($_GET['delete_certificate_id'])) ) {
				$course_id=api_get_course_id();
				$default_certificate_id=$_GET['delete_certificate_id'];
				DocumentManager::remove_attach_certificate ($course_id,$default_certificate_id);
			}
			Display::display_confirmation_message(get_lang('DocDeleted'));
		} else {
			Display::display_error_message(get_lang('DocDeleteError'));
		}
	}

	if (isset($_POST['action'])) {
		switch ($_POST['action']) {
			case 'delete':

				foreach ($_POST['path'] as $index => & $path) {
					if (!$is_allowed_to_edit) {
						if (DocumentManager::check_readonly($_course, $_user['user_id'], $path)) {
							Display::display_error_message(get_lang('CantDeleteReadonlyFiles'));
							break 2;
						}
					}
				}

				foreach ($_POST['path'] as $index => & $path) {
					if (in_array($path, array('/audio', '/flash', '/images', '/shared_folder', '/video', '/chat_files'))) {
						continue;
					} else {
					   $delete_document = DocumentManager::delete_document($_course, $path, $base_work_dir);
					}
				}
				if (!empty($delete_document)) {
				    Display::display_confirmation_message(get_lang('DocDeleted'));
				}
				break;
		}
	}

	/*	CREATE DIRECTORY */

	// Create directory with $_POST data
	if (isset($_POST['create_dir']) && $_POST['dirname'] != '') {
		// Needed for directory creation
		require_once api_get_path(LIBRARY_PATH).'fileUpload.lib.php';
		$post_dir_name = Security::remove_XSS($_POST['dirname']);

		if ($post_dir_name == '../' || $post_dir_name == '.' || $post_dir_name == '..') {
			Display::display_error_message(get_lang('CannotCreateDir'));
		} else {
			$added_slash = ($curdirpath == '/') ? '' : '/';
			$dir_name = $curdirpath.$added_slash.replace_dangerous_char($post_dir_name);
			$dir_name = disable_dangerous_file($dir_name);
			$dir_check = $base_work_dir.$dir_name;

			if (!is_dir($dir_check)) {
				$created_dir = create_unexisting_directory($_course, $_user['user_id'], $to_group_id, $to_user_id, $base_work_dir, $dir_name, $post_dir_name);

				if ($created_dir) {
					Display::display_confirmation_message('<span title="'.$created_dir.'">'.get_lang('DirCr').'</span>', false);
					// Uncomment if you want to enter the created dir
					//$curdirpath = $created_dir;
					//$curdirpathurl = urlencode($curdirpath);
				} else {
					Display::display_error_message(get_lang('CannotCreateDir'));
				}
			} else {
				Display::display_error_message(get_lang('CannotCreateDir'));
			}
		}
	}

	// Show them the form for the directory name
	if (isset($_GET['createdir'])) {
		// Show the form
		echo create_dir_form();
	}

	/*	VISIBILITY COMMANDS */

	if ((isset($_GET['set_invisible']) && !empty($_GET['set_invisible'])) || (isset($_GET['set_visible']) && !empty($_GET['set_visible'])) && $_GET['set_visible'] != '*' && $_GET['set_invisible'] != '*') {
		// Make visible or invisible?
		if (isset($_GET['set_visible'])) {
			$update_id = $_GET['set_visible'];
			$visibility_command = 'visible';
		} else {
			$update_id = $_GET['set_invisible'];
			$visibility_command = 'invisible';
		}

		if (!$is_allowed_to_edit) {
			if(DocumentManager::check_readonly($_course, $_user['user_id'], '', $update_id)) {
				api_not_allowed();
			}
		}

		// Update item_property to change visibility
		if (api_item_property_update($_course, TOOL_DOCUMENT, $update_id, $visibility_command, $_user['user_id'], null, null, null, null, $current_session_id)) {
			Display::display_confirmation_message(get_lang('ViMod'));
		} else {
			Display::display_error_message(get_lang('ViModProb'));
		}
	}

	/*	TEMPLATE ACTION */

	if (isset($_GET['add_as_template']) && !isset($_POST['create_template'])) {

		$document_id_for_template = intval($_GET['add_as_template']);

		// Create the form that asks for the directory name
		$template_text = '<form name="set_document_as_new_template" enctype="multipart/form-data" action="'.api_get_self().'?add_as_template='.$document_id_for_template.'" method="post">';
		$template_text .= '<input type="hidden" name="curdirpath" value="'.$curdirpath.'" />';
		$template_text .= '<table><tr><td>';
		$template_text .= get_lang('TemplateName').' : </td>';
		$template_text .= '<td><input type="text" name="template_title" /></td></tr>';
		//$template_text .= '<tr><td>'.get_lang('TemplateDescription').' : </td>';
		//$template_text .= '<td><textarea name="template_description"></textarea></td></tr>';
		$template_text .= '<tr><td>'.get_lang('TemplateImage').' : </td>';
		$template_text .= '<td><input type="file" name="template_image" id="template_image" /></td></tr>';
		$template_text .= '</table>';
		$template_text .= '<button type="submit" class="add" name="create_template">'.get_lang('CreateTemplate').'</button>';
		$template_text .= '</form>';
		// Show the form
		Display::display_normal_message($template_text, false);

	} elseif (isset($_GET['add_as_template']) && isset($_POST['create_template'])) {

		$document_id_for_template = intval(Database::escape_string($_GET['add_as_template']));

		$title = Security::remove_XSS($_POST['template_title']);
		//$description = Security::remove_XSS($_POST['template_description']);
		$course_code = api_get_course_id();
		$user_id = api_get_user_id();

		// Create the template_thumbnails folder in the upload folder (if needed)
		if (!is_dir(api_get_path(SYS_PATH).'courses/'.$_course['path'].'/upload/template_thumbnails/')) {
			@mkdir(api_get_path(SYS_PATH).'courses/'.$_course['path'].'/upload/template_thumbnails/', api_get_permissions_for_new_directories());
		}
		// Upload the file
		if (!empty($_FILES['template_image']['name'])) {

			require_once api_get_path(LIBRARY_PATH).'fileUpload.lib.php';
			$upload_ok = process_uploaded_file($_FILES['template_image']);

			if ($upload_ok) {
				// Try to add an extension to the file if it hasn't one
				$new_file_name = $_course['sysCode'].'-'.add_ext_on_mime(stripslashes($_FILES['template_image']['name']), $_FILES['template_image']['type']);

				// Upload dir
				$upload_dir = api_get_path(SYS_PATH).'courses/'.$_course['path'].'/upload/template_thumbnails/';

				// Resize image to max default and end upload
				require_once (api_get_path(LIBRARY_PATH).'image.lib.php');
				$temp = new image($_FILES['template_image']['tmp_name']);
				$picture_infos = @getimagesize($_FILES['template_image']['tmp_name']);

				$max_width_for_picture = 100;

				if ($picture_infos[0] > $max_width_for_picture) {
					$thumbwidth = $max_width_for_picture;
					if (empty($thumbwidth) || $thumbwidth == 0) {
					  $thumbwidth = $max_width_for_picture;
					}
					$new_height = round(($thumbwidth/$picture_infos[0])*$picture_infos[1]);

					$temp->resize($thumbwidth, $new_height, 0);
				}

				$type = $picture_infos[2];

				switch (!empty($type)) {
					case 2 : $temp->send_image('JPG', $upload_dir.$new_file_name);
							 break;
					case 3 : $temp->send_image('PNG', $upload_dir.$new_file_name);
							 break;
					case 1 : $temp->send_image('GIF', $upload_dir.$new_file_name);
							 break;
				}
			}
	   }

		DocumentManager::set_document_as_template($title, $description, $document_id_for_template, $course_code, $user_id, $new_file_name);
		Display::display_confirmation_message(get_lang('DocumentSetAsTemplate'));
	}

	if (isset($_GET['remove_as_template'])) {
		$document_id_for_template = intval($_GET['remove_as_template']);
		$course_code = api_get_course_id();
		$user_id = api_get_user_id();
		DocumentManager::unset_document_as_template($document_id_for_template, $course_code, $user_id);
		Display::display_confirmation_message(get_lang('DocumentUnsetAsTemplate'));
	}
} // END is allowed to edit

//attach certificate in the gradebook
if (isset($_GET['curdirpath']) && $_GET['curdirpath']=='/certificates' && isset($_GET['set_certificate']) && $_GET['set_certificate']==strval(intval($_GET['set_certificate']))) {
	if (isset($_GET['cidReq'])) {
		$course_id=Security::remove_XSS($_GET['cidReq']);//course id
		$document_id=Security::remove_XSS($_GET['set_certificate']);//document id
		DocumentManager::attach_gradebook_certificate ($course_id,$document_id) ;
		Display::display_normal_message(get_lang('IsDefaultCertificate'));	
	}	
}


/*	GET ALL DOCUMENT DATA FOR CURDIRPATH */

$docs_and_folders = DocumentManager::get_all_document_data($_course, $curdirpath, $to_group_id, null, $is_allowed_to_edit || $group_member_with_upload_rights);
$folders = DocumentManager::get_all_document_folders($_course, $to_group_id, $is_allowed_to_edit || $group_member_with_upload_rights);
if ($folders === false) {
	$folders = array();
}

echo '<div class="actions">';

	/* GO TO PARENT DIRECTORY */
	if ($curdirpath!= '/' && $curdirpath!=$group_properties['directory']&& !$is_certificate_mode)  {		
	?>
		 <a href="<?php echo api_get_self(); ?>?<?php echo api_get_cidreq();?>&curdirpath=<?php echo urlencode((dirname($curdirpath)=='\\')?'/':dirname($curdirpath)).$req_gid ?>">
				<img src="../img/folder_up.gif" border="0" title="<?php echo get_lang('Up'); ?>" alt="" />
				<?php echo get_lang('Up'); ?></a>&nbsp;
	<?php		
	}
	
	if ($is_certificate_mode & $curdirpath!= '/certificates')  {		
	?>
		 <a href="<?php echo api_get_self(); ?>?<?php echo api_get_cidreq();?>&curdirpath=<?php echo urlencode((dirname($curdirpath)=='\\')?'/':dirname($curdirpath)).$req_gid ?>">
				<img src="../img/folder_up.gif" border="0" title="<?php echo get_lang('Up'); ?>" alt="" />
				<?php echo get_lang('Up'); ?></a>&nbsp;
	<?php		
	}

if (isset($docs_and_folders) && is_array($docs_and_folders)) {
	//echo('<pre>');
	//print_r($docs_and_folders);
	//echo('</pre>');
	// Do we need the title field for the document name or not?
	// We get the setting here, so we only have to do it once
	$use_document_title = api_get_setting('use_document_title');
	// Create a sortable table with our data
	$sortable_data = array();

	while (list($key, $id) = each($docs_and_folders)) {
		$row = array();

		// If the item is invisible, wrap it in a span with class invisible
		$invisibility_span_open = ($id['visibility'] == 0) ? '<span class="invisible">' : '';
		$invisibility_span_close = ($id['visibility'] == 0) ? '</span>' : '';
		// Size (or total size of a directory)
		$size = $id['filetype'] == 'folder' ? get_total_folder_size($id['path'], $is_allowed_to_edit) : $id['size'];
		// Get the title or the basename depending on what we're using
		if ($use_document_title == 'true' && $id['title'] != '') {
			$document_name = $id['title'];
		} else {
			$document_name = basename($id['path']);
			//Juan Carlos Raña: Get firstname and lastname when folder is in shared_folder.
			//TODO: Check if is also necessary (above else)
			if (strstr($document_name, 'sf_user_')) {
				$userinfo = Database::get_user_info_from_id(substr($document_name, 8));
				$document_name = api_get_person_name($userinfo['firstname'], $userinfo['lastname']);
			} elseif (strpos($document_name, 'shared_folder') !== false) {
				$document_name = get_lang('SharedFolder');
			}
		}
		// Data for checkbox
		if (($is_allowed_to_edit || $group_member_with_upload_rights) && count($docs_and_folders) > 1) {
			$row[] = $id['path'];
		}

		// Show the owner of the file only in groups
		$user_link = '';

		if (isset($_SESSION['_gid']) && $_SESSION['_gid'] != '') {
			if (!empty($id['insert_user_id'])) {
				$user_info = UserManager::get_user_info_by_id($id['insert_user_id']);
				$user_name = api_get_person_name($user_info['firstname'], $user_info['lastname']);
				$user_link = '<div class="document_owner">'.get_lang('Owner').': '.display_user_link_document($id['insert_user_id'], $user_name).'</div>';
			}
		}

		// Icons (clickable)
		//$row[]= build_document_icon_tag($id['filetype'],$id['path']);
		$row[] = create_document_link($http_www, $document_name, $id['path'], $id['filetype'], $size, $id['visibility'], true);

		// Validacion when belongs to a session
		$session_img = api_get_session_image($id['session_id'], $_user['status']);

		// Document title with hyperlink
		$row[] = create_document_link($http_www, $document_name, $id['path'], $id['filetype'], $size, $id['visibility']).$session_img.'<br />'.$invisibility_span_open.nl2br(htmlspecialchars($id['comment'],ENT_QUOTES,$charset)).$invisibility_span_close.$user_link;

		// Comments => display comment under the document name
		//$row[] = $invisibility_span_open.nl2br(htmlspecialchars($id['comment'])).$invisibility_span_close;
		$display_size = format_file_size($size);
		$row[] = '<span style="display:none;">'.$size.'</span>'.$invisibility_span_open.$display_size.$invisibility_span_close;

		// Last edit date
		$last_edit_date = $id['lastedit_date'];
		$last_edit_date = api_get_local_time($last_edit_date, null, date_default_timezone_get());
		$display_date = date_to_str_ago($last_edit_date).'<br /><span class="dropbox_date">'.api_format_date($last_edit_date).'</span>';
		$row[] = $invisibility_span_open.$display_date.$invisibility_span_close.'<!--uts='.strtotime($last_edit_date).'-->';

		// Admins get an edit column
		if ($is_allowed_to_edit || $group_member_with_upload_rights) {
			$is_template = isset($id['is_template']) ? $id['is_template'] : false;
			// If readonly, check if it the owner of the file or if the user is an admin
			if ($id['insert_user_id'] == $_user['user_id'] || api_is_platform_admin()) {
				$edit_icons = build_edit_icons($curdirpath, $id['filetype'], $id['path'], $id['visibility'], $key, $is_template, 0);
			} else {
				$edit_icons = build_edit_icons($curdirpath, $id['filetype'], $id['path'], $id['visibility'], $key, $is_template, $id['readonly']);
			}
			$row[] = $edit_icons;
		}
		$row[] = $last_edit_date;
		$row[] = $size;
		$total_size = $total_size + $size;
		$sortable_data[] = $row;
	}
} else {
	$sortable_data = '';
	$table_footer = '<div style="text-align:center;"><strong>'.get_lang('NoDocsInFolder').'</strong></div>';
}

$column_show = array();

if ($is_allowed_to_edit || $group_member_with_upload_rights || is_my_shared_folder($_user['user_id'], $curdirpath)) {
	// @TODO:check enable more options for shared folders
	/* CREATE NEW DOCUMENT OR NEW DIRECTORY / GO TO UPLOAD / DOWNLOAD ZIPPED FOLDER */
?>
			<!-- create new document or directory -->
            <?php if (!$is_certificate_mode && !is_my_shared_folder($_user['user_id'],$curdirpath))
            {?>
                <a href="create_document.php?<?php echo api_get_cidreq();?>&dir=<?php echo $curdirpathurl.$req_gid; ?>"><img src="../img/filenew.gif" border="0" alt="" title="<?php echo get_lang('CreateDoc'); ?>" /></a>
                <a href="create_document.php?<?php echo api_get_cidreq();?>&dir=<?php echo $curdirpathurl.$req_gid; ?>"><?php echo get_lang("CreateDoc"); ?></a>&nbsp;&nbsp;
	        <?php }?>
	        
      		<!--create new certificate -->
			<?php if ($is_certificate_mode) { ?>	
			<a href="create_document.php?<?php echo api_get_cidreq();?>&dir=<?php echo $curdirpathurl.$req_gid; ?>&certificate=true&<?php echo 'selectcat=' . Security::remove_XSS($_GET['selectcat']); ?>"><img src="../img/filenew.gif" border="0" alt="" title="<?php echo get_lang('CreateCertificate'); ?>" /></a>
			<a href="create_document.php?<?php echo api_get_cidreq();?>&dir=<?php echo $curdirpathurl.$req_gid; ?>&certificate=true&<?php echo 'selectcat=' . Security::remove_XSS($_GET['selectcat']); ?>"><?php echo get_lang("CreateCertificate"); ?></a>&nbsp;&nbsp;	
			<?php }?>
			
			<!-- file upload link -->
		<?php
				$upload_name =  get_lang('UplUploadDocument');
				if ($is_certificate_mode) { 
				$upload_name =	 get_lang('UploadCertificate');
			}?>	

			<a href="upload.php?<?php echo api_get_cidreq();?>&curdirpath=<?php echo $curdirpathurl.$req_gid; ?>"><img src="../img/submit_file.gif" border="0" title="<?php echo get_lang('UplUploadDocument'); ?>" alt="" /></a>
			<a href="upload.php?<?php echo api_get_cidreq();?>&curdirpath=<?php echo $curdirpathurl.$req_gid; ?>"><?php echo get_lang('UplUploadDocument'); ?></a>&nbsp;
			<!-- create directory -->
            <?php if (!$is_certificate_mode && !is_my_shared_folder($_user['user_id'],$curdirpath))
            {?>
                <a href="<?php echo api_get_self(); ?>?<?php echo api_get_cidreq();?>&curdirpath=<?php echo $curdirpathurl.$req_gid; ?>&amp;createdir=1"><img src="../img/folder_new.gif" border="0" title="<?php echo get_lang('CreateDir'); ?>" alt ="" /></a>
                <a href="<?php echo api_get_self(); ?>?<?php echo api_get_cidreq();?>&curdirpath=<?php echo $curdirpathurl.$req_gid; ?>&amp;createdir=1"><?php echo get_lang("CreateDir"); ?></a>&nbsp;
                <a href="quota.php?<?php echo api_get_cidreq();?>"><?php Display::display_icon('statistics.gif', get_lang("ShowCourseQuotaUse")); ?><?php echo get_lang("ShowCourseQuotaUse"); ?></a>
		<?php
			}
	}
if (!is_null($docs_and_folders)) {
	global $total_size;
	if (!$is_certificate_mode && $total_size!=0  &&  (api_get_setting('students_download_folders') == 'true' || api_is_allowed_to_edit() || api_is_platform_admin())){ ?>
	<!-- download zipped folder -->
			<a href="<?php echo api_get_self(); ?>?<?php echo api_get_cidreq();?>&action=downloadfolder"><img src="../img/zip_save.gif" border="0" title="<?php echo get_lang("Save"); ?> (ZIP)" alt="" /></a>
			<a href="<?php echo api_get_self(); ?>?<?php echo api_get_cidreq();?>&action=downloadfolder"><?php echo get_lang("Save"); ?> (ZIP)</a>&nbsp;
<?php
	}
}

// Slideshow by Patrick Cool, May 2004
require 'document_slideshow.inc.php';
if ($image_present) {
	echo "<a href=\"slideshow.php?".api_get_cidreq()."&curdirpath=".$curdirpathurl."\"><img src=\"../img/images_gallery.gif\" border=\"0\" title=\"".get_lang('ViewSlideshow')."\"/>&nbsp;". get_lang('ViewSlideshow') . "</a>";
}
echo '</div>';
if (!$is_certificate_mode)
echo build_directory_selector($folders, $curdirpath, (isset($group_properties['directory']) ? $group_properties['directory'] : array()), true);

if (($is_allowed_to_edit || $group_member_with_upload_rights) && count($docs_and_folders) > 1) {
	$column_show[] = 1;
}

$column_show[] = 1;
$column_show[] = 1;
$column_show[] = 1;
$column_show[] = 1;

if ($is_allowed_to_edit || $group_member_with_upload_rights) {
	$column_show[] = 1;
}
$column_show[] = 0;

$column_order = array();

if (count($row) == 8) {
	$column_order[] = 1;
	$column_order[] = 2;
	$column_order[] = 7;
	$column_order[] = 6;
	$column_order[] = 5;
} elseif (count($row) == 6) {
	$column_order[] = 1;
	$column_order[] = 2;
	$column_order[] = 7;
	$column_order[] = 4;
}

$default_column = $is_allowed_to_edit ? 2 : 1;
$tablename = $is_allowed_to_edit ? 'teacher_table' : 'student_table';
$table = new SortableTableFromArrayConfig($sortable_data, $default_column, 20, $tablename, $column_show, $column_order, 'ASC');

$query_vars['curdirpath'] = $curdirpath;

if (isset($_SESSION['_gid'])) {
	$query_vars['gidReq'] = $_SESSION['_gid'];
}
$query_vars['cidReq'] = api_get_course_id();
$table->set_additional_parameters($query_vars);
$column = 0;

if (($is_allowed_to_edit || $group_member_with_upload_rights) && count($docs_and_folders) > 1) {
	$table->set_header($column++, '', false);
}
$table->set_header($column++, get_lang('Type'));
$table->set_header($column++, get_lang('Name'));

//$column_header[] = array(get_lang('Comment'), true); // Display comment under the document name
$table->set_header($column++, get_lang('Size'));
$table->set_header($column++, get_lang('Date'));
// Admins get an edit column
if ($is_allowed_to_edit || $group_member_with_upload_rights) {
	$table->set_header($column++, get_lang('Modify'), false);
}

// Actions on multiple selected documents
// TODO: Currently only delete action -> take only DELETE right into account
if (count($docs_and_folders) > 1) {
	if ($is_allowed_to_edit || $group_member_with_upload_rights) {
		$form_actions = array();
		$form_action['delete'] = get_lang('Delete');
		$table->set_form_actions($form_action, 'path');
	}
}

$table->display();
if (!empty($table_footer)) {
	echo $table_footer;
}

// Footer
Display::display_footer();
