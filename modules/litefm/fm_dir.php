<?php
/*
 *
 * OGP - Open Game Panel
 * Copyright (C) Copyright (C) 2008 - 2013 The OGP Development Team
 *
 * http://www.opengamepanel.org/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 */

require_once(MODULES."/litefm/functions.php");
require_once(MODULES."/litefm/litefm.php");
function exec_ogp_module()
{
	$home_id = $_REQUEST['home_id'];

	if (empty($home_id))
	{
		print_failure( home_id_missing );
		return;
	}

	global $db, $view, $settings;
	
	$isAdmin = $db->isAdmin( $_SESSION['user_id'] );
	if($isAdmin) 
		$home_cfg = $db->getGameHome($home_id);
	else
		$home_cfg = $db->getUserGameHome($_SESSION['user_id'],$home_id);
	
	if ($home_cfg === FALSE)
	{
		print_failure( no_access_to_home );
		return;
	}

	litefm_check($home_id);
	
	$remote = new OGPRemoteLibrary($home_cfg['agent_ip'], $home_cfg['agent_port'], $home_cfg['encryption_key'] );
	
	$os_string = $remote->what_os();
	$os = preg_match("/Linux/i", $os_string) ? "linux" : "windows";
	
	// We must always add the home directory to the fm_cwd so that user
	// can not go out of the homedir.
	$path = clean_path($home_cfg['home_path']."/".@$_SESSION['fm_cwd_'.$home_id]);
	if (!$remote->rfile_exists($path))
	{
		while(!$remote->rfile_exists($path))
		{
			$path = dirname($path);
			$_SESSION['fm_cwd_'.$home_id] = dirname($_SESSION['fm_cwd_'.$home_id]);
			if($path == clean_path($home_cfg['home_path']))
			{
				print_failure(get_lang_f("dir_not_found",$path));
				break;
			}
		}
	}
	
	// Get File Operations Keys
	$fo_keys = get_file_operations_keys();
	// Get File Operations Settings
	$fo = get_fo_settings($settings,$fo_keys);
	
	$upload_folder_path = "modules/litefm/uploads/home_id_$home_id";
	// PHP post_max_size handling
	$PMS_bytes = return_bytes(ini_get('post_max_size'));
	if(isset($_SERVER['CONTENT_LENGTH']) AND $_SERVER['CONTENT_LENGTH'] > $PMS_bytes and $fo['upload'] == "1")
	{
		$error['post_max_size'] = "The uploaded file(s) size exceed the post_max_size directive in php.ini (".ini_get('post_max_size').").";
		echo json_encode( array( 'error' => $error ) );
	}
	// Get web to agent transfer progress
	elseif( isset( $_GET['pid'] ) and $_GET['pid'] != "" and $fo['upload'] == "1" )
	{
		$bytes = $_GET['size'];
		$totalsize = $bytes / 1024;
		$filename = $_GET['filename'];
		$kbytes = $remote->rsync_progress( clean_path( $path."/".$filename ) );
		list($totalsize,$mbytes,$pct) = explode(";",do_progress($kbytes,$totalsize));
		$totalmbytes = round($totalsize / 1024, 2);
		$pct = $pct > 100 ? 100 : $pct;
		$complete = false;
		if ( $remote->is_file_download_in_progress( $_GET['pid'] ) == 0 )
		{
			$dest_file_path = clean_path( $upload_folder_path . "/" . $filename . ".txt" );
			unlink($dest_file_path);
			$directory = dir($upload_folder_path);
			$directory_empty = TRUE;
			while ((FALSE !== ($item = $directory->read())) && ( ! isset($directory_not_empty)))
			{
				if ($item != '.' && $item != '..')
				{
					$directory_empty = FALSE;
				}
			}
			$directory->close();
			if( $directory_empty )
				rmdir( $upload_folder_path );
			$db->logger(upload_complete . ": " . clean_path( $path . "/" . $filename ));
			$complete = true;
		}
		echo json_encode(array('pct' => $pct,
							   'complete' => $complete));
	}
	// Upload File(s)
	elseif( isset( $_POST['upload'] ) and $fo['upload'] == "1" )
	{
		$error = FALSE;
		foreach ( $_FILES['files']['error'] as $i => $error_code )
		{
			if($error_code > 0)
			{
				$error['error_message'][$i] = codeToMessage($error_code,$_FILES['files']['name'][$i]);
			}
		}
		
		if( is_array($error) )
		{
			echo json_encode( array( 'error' => $error ) );
		}
		// Save uploaded file to the website and start file download from the agent
		else
		{
			if( ! file_exists( $upload_folder_path ) )
			{
				if( ! mkdir($upload_folder_path, 0777, true) )
				{
					echo json_encode(array('error' => get_lang_f('can_not_create_upload_folder_path', "\n(".$upload_folder_path.")" )));
					return;
				}
			}
			$count = 0;
			$s = ( isset($_SERVER['HTTPS']) and  get_true_boolean($_SERVER['HTTPS']) ) ? "s" : "";
			$p = (isset($_SERVER['SERVER_PORT']) and $_SERVER['SERVER_PORT'] != "80") ? ":".$_SERVER['SERVER_PORT'] : "";
			$url = 'http'.$s.'://'.$_SERVER['SERVER_NAME'].$p.$_SERVER['SCRIPT_NAME'];
			// loop all files
			foreach ( $_FILES['files']['name'] as $i => $name )
			{
				// if file not uploaded then skip it
				if ( !is_uploaded_file($_FILES['files']['tmp_name'][$i]) )
					continue;
				// now we can move uploaded files
				$bad_chars = preg_replace( "/([[:alnum:]_\.-]*)/", "", $_FILES['files']['name'][$i] );
				$bad_arr = str_split( $bad_chars );
				$filename = str_replace( $bad_arr, "", $_FILES['files']['name'][$i] );
				$dest_file_path = clean_path( $upload_folder_path . "/" . $filename . ".txt" );
				$file_url = str_replace( "home.php", $dest_file_path, $url );
				if( file_exists( $dest_file_path ) )
					unlink($dest_file_path);
				if( move_uploaded_file( $_FILES["files"]["tmp_name"][$i], $dest_file_path ) )
				{
					$remote_file_path = clean_path( $path . "/" . $filename );
					if( $remote->rfile_exists($remote_file_path) )
						$remote->exec('rm -f ' . $remote_file_path );
					$pid = $remote->start_file_download( $file_url, $path, $filename );
					$files[$count] = array('filename' => $filename,
										   'size' => $_FILES['files']['size'][$i],
										   'pid' => $pid);
					$count++;
				}
			}
			echo json_encode(array('count' => $count,
								   'files' => $files));
		}
	}
	// Create Folder
	elseif( isset( $_POST['create_folder'] ) and $fo['create_folder'] == "1" )
	{
		$folder_name = stripslashes($_POST['folder_name']);
		$folder_path = clean_path( $path . "/" . $folder_name );
		$remote->exec("mkdir '" . esc_squote($folder_path) . "'" );
		$db->logger( create_folder . ": " . $folder_path );
	}
	// Delete File(s)
	elseif( isset( $_POST['remove'] ) and $fo['remove'] == "1" )
	{
		if( isset($_SESSION['fm_files_'.$home_id]) and !empty($_SESSION['fm_files_'.$home_id]) )
		{
			$files = "";
			foreach($_POST['items'] as $item)
			{
				if(isset($_SESSION['fm_files_'.$home_id][$item]))
				{
					$item_path = clean_path( $path . "/" . $_SESSION['fm_files_'.$home_id][$item] );
					$files .= " '".esc_squote($item_path)."'";
				}
			}
			echo $files;
			if($files != "")
			{
				$remote->exec( "rm -Rf${files}" );
				$files = str_replace('" "','"<br>"',$files);
				$db->logger( remove . ": ${files}" );
			}
		}
	}
	// Rename File(s)/Folder(s)
	elseif( isset( $_POST['rename'] ) and $fo['rename'] == "1" )
	{
		if( isset($_SESSION['fm_files_'.$home_id]) and !empty($_SESSION['fm_files_'.$home_id]) )
		{
			foreach($_POST['items'] as $i => $item)
			{
				if(isset($_SESSION['fm_files_'.$home_id][$item]))
				{
					$item_path = clean_path( $path . "/" . $_SESSION['fm_files_'.$home_id][$item] );
					$new_item = stripslashes($_POST['values'][$i]);
					$new_item_path = clean_path( $path . "/" . $new_item );
					if ($item_path != $new_item_path)
					{
						$remote->exec( "mv '".esc_squote($item_path)."' '".esc_squote($new_item_path)."'" );
						$db->logger( rename . ": $item_path " . to . " $new_item_path" );
					}
				}
			}
		}
	}
	// Move Files/Folders
	elseif( isset( $_POST['move'] ) and $fo['move'] == "1" )
	{
		$selected_path = preg_replace("#[/\.\./]+#","/", stripslashes($_POST['selected_path']));
		$destination = clean_path($home_cfg['home_path']. "/" . $selected_path);
		if($path != $destination)
		{
			if($remote->rfile_exists($destination))
			{
				foreach($_POST['items'] as $item)
				{
					if(isset($_SESSION['fm_files_'.$home_id][$item]))
					{
						$item_path = clean_path( $path . "/" . $_SESSION['fm_files_'.$home_id][$item] );
						$destination = clean_path($destination . "/.");
						$remote->exec("mv '".esc_squote($item_path)."' '".esc_squote($destination)."'");
						$db->logger( move . ": $item_path " . to . " $destination" );
					}
				}
			}
		}
	}
	// Copy Files/Folders
	elseif( isset( $_POST['copy'] ) and $fo['copy'] == "1" )
	{
		$selected_path = preg_replace("#[/\.\./]+#","/", stripslashes($_POST['selected_path']));
		$destination = clean_path($home_cfg['home_path']. "/" . $selected_path);
		if($path != $destination)
		{
			if($remote->rfile_exists($destination))
			{
				foreach($_POST['items'] as $item)
				{
					if(isset($_SESSION['fm_files_'.$home_id][$item]))
					{
						$item_path = clean_path( $path . "/" . $_SESSION['fm_files_'.$home_id][$item] );
						$destination = clean_path($destination . "/.");
						$remote->exec("cp -Rf '".esc_squote($item_path)."' '".esc_squote($destination)."'");
						$db->logger( copy . ": $item_path " . to . " $destination" );
					}
				}
			}
		}
	}
	// Compress Files/Folders
	elseif( isset( $_POST['compress'] ) and $fo['compress'] == "1" )
	{
		$files_w_path = '';
		$items = '';
		foreach($_POST['items'] as $item)
		{
			if(isset($_SESSION['fm_files_'.$home_id][$item]))
			{
				$item_path = clean_path( $path . "/" . $_SESSION['fm_files_'.$home_id][$item] );
				$files_w_path .= $item_path.'<br>';
				$items .= $_SESSION['fm_files_'.$home_id][$item].'\n';
			}
		}
		if($items != '')
		{
			$remote->compress_files($items,$path,$_POST['archive_name'],$_POST['archive_type']);
			$db->logger( compress . " " . $_POST['archive_type'] . ":<br>$files_w_path" );
		}
	}
	// uncompress
	elseif( isset( $_POST['uncompress'] ) and $fo['uncompress'] == "1" )
	{
		$selected_path = preg_replace("#[/\.\./]+#","/", stripslashes($_POST['selected_path']));
		$destination = clean_path($home_cfg['home_path']. "/" . $selected_path);
		if($remote->rfile_exists($destination))
		{
			foreach($_POST['items'] as $item)
			{
				if(isset($_SESSION['fm_files_'.$home_id][$item]))
				{
					$file_location = clean_path( $path . "/" . $_SESSION['fm_files_'.$home_id][$item] );
					$remote->uncompress_file($file_location, $destination);
					$db->logger( uncompress . ": $file_location " . to . " $destination." );
				}
			}
		}
	}
	// Create file
	elseif( isset( $_POST['create_file'] ) and $fo['create_file'] == "1" )
	{
		$file_name = stripslashes($_POST['file_name']);
		$destination = clean_path( $path . "/" . $file_name);
		$remote->exec( "touch '" . esc_squote($destination) . "'" );
		$db->logger( create_file . ": $destination" );
	}
	// Send by email
	elseif( isset( $_POST['send_by_email'] ) and $fo['send_by_email'] == "1" )
	{
		$archive_name = $_POST['archive_name'];
		$archive_type = $_POST['archive_type'];
		$subject = $_POST['subject'];
		$message = $_POST['message'];
		$dest_email = $_POST['dest_email'];
		$items = '';
		foreach($_POST['items'] as $item)
		{
			if(isset($_SESSION['fm_files_'.$home_id][$item]))
			{
				$item_path = clean_path( $path . "/" . $_SESSION['fm_files_'.$home_id][$item] );
				$items .= $_SESSION['fm_files_'.$home_id][$item].'\n';
			}
		}
		if($items != '')
		{
			$retval = $remote->compress_files($items,$path,$archive_name,$archive_type);
			$archive = clean_path( "${path}/${archive_name}.${archive_type}" );
			if( $retval == 0 )
			{
				do{
					$size1 = $remote->exec( "du -sk '" . esc_squote($archive) . "'" );
					sleep( 2 );
					$size2 = $remote->exec( "du -sk '" . esc_squote($archive) . "'" );
				}while($size1 != $size2);
			}
			if( $retval != -1 and $remote->rfile_exists($archive) )
			{
				$mail_retval = $remote->exec( "(echo '" . esc_squote($message) . "' | mutt -a '" . esc_squote($archive) . "' -s '" . esc_squote($subject) . "' -- '" . esc_squote($dest_email) . "');echo \$?" );
				if($mail_retval == 0)
				{
					echo mail_sent_successfully;
					$db->logger( send_by_email . ": '$archive'<br>Subject: '$subject'<br>to: '$dest_email'" );
				}
				else
				{
					echo "The email could not be sent,\n".
						 "the package mutt or mutt-patched (a mail client)\n".
						 "must be installed, and postfix should be configured\n".
						 "in order to send large files.";
				}
			}
		}
	}
	// Secure File
	elseif( isset( $_POST['secure_file'] ) and $isAdmin )
	{
		if(isset($_SESSION['fm_files_'.$home_id][$_POST['item']]))
		{
			if($_POST['set_attr'] == '+i' or $_POST['set_attr'] == '-i')
			{
				$type = $_POST['set_attr'] == '+i' ? chattr_locked : chattr_unlocked;
				$action = "chattr".$_POST['set_attr'];
				$file_path = clean_path( $path . "/" . $_SESSION['fm_files_'.$home_id][$_POST['item']] );
				$remote->secure_path($action, $file_path);
				$db->logger( "$type: $file_path" );
			}
		}
	}
	else
	{
		?>
		<link rel="stylesheet" href="js/jquery/ui/themes/base/jquery.ui.all.css">
		<script type="text/javascript" src="js/jquery/jquery-1.11.0.min.js"></script>
		<script type="text/javascript" src="js/jquery/ui/jquery-ui-1.10.4.min.js"></script>
		<script type="text/javascript" src="js/jquery/plugins/jquery.form.min.js"></script>
		<script type="text/javascript" src="js/modules/litefm.js"></script>
		<?php
		echo "<h2>";
		echo empty($home_cfg['home_name']) ?  not_available  : $home_cfg['home_name'];
		echo "</h2>";
		$_SESSION['fm_files_'.$home_id] = array();
		$show_path = (isset($_SESSION['fm_cwd_'.$home_id])) ? clean_path($_SESSION['fm_cwd_'.$home_id]) : "/";
		if($isAdmin)
			$show_path = clean_path($home_cfg['home_path'].$show_path);
		echo "<table class='center'><tr><td><a href='?m=gamemanager&amp;p=game_monitor&amp;home_id=".$home_cfg['home_id']."'><< ". back ."</a></td></tr></table>";
		if ($remote->rfile_exists($path))
		{
			echo "<table class='center' style='width:100%;' ><tr>\n".
				 "<td colspan='3' ><h3>".
				 get_lang_f('currently_viewing',$show_path)."</h3></td>".
				 "</tr></table>\n";
			echo "<div class='file-operations' >\n";
			foreach($fo_keys as $key)
			{
				if($fo[$key] == "1")
					echo "<div class='operations-button' id='$key'><img src='" . check_theme_image("modules/litefm/action-images/$key.gif") . "' /><div>&nbsp;".get_lang($key)."</div></div>\n";
			}
			echo "</div>\n";
			$dirlist = $remote->remote_dirlistfm($path);
			
			if (!is_array($dirlist))
			{
				if(isset($_SESSION['fm_cwd_'.$home_id]))
				{
					unset($_SESSION['fm_cwd_'.$home_id]);
					$view->refresh("?m=litefm&amp;home_id=$home_id",0);
					return;
				}
				else
				{
					print_failure( failed_list );
					return;
				}
			}
			
			if ( empty($dirlist) )
			{
				
				echo "<table class='center' style='width:100%;' >\n".
					 show_back($home_id)."</table>";
				echo "<p>" . empty_directory . "</p>";
			}
			else
			{
				echo "<table class='center' style='width:100%;' >\n"
					 .show_back($home_id).
					 "<tr>\n<td style='width:10px;' >".
					 "<input type=checkbox name='switch' id='switch_check' />".
					 "</td>\n<td align=left>".
					  filename ."</td>\n";
				if( $os == "linux" )
					echo "<td>".  filesecure  ."</td>\n";
				echo "<td>". filesize ." [". bytes ."]</td>\n<td>".
					  owner ." ". group ."</td>\n</tr>\n";
				$i = 0;
				if(isset($dirlist['directorys']) and is_array($dirlist['directorys']))
				{
					$dirlist['directorys'] = array_orderby($dirlist['directorys'], 'filename', SORT_ASC);
					foreach($dirlist['directorys'] as $directory)
					{
						echo "<tr>\n".
							 "<td>".
							 "<input type=checkbox name='folder' data-item='$i' value=\"" . str_replace('"', "&quot;", $directory['filename']) . "\" class='item' />\n".
							 "</td>".
							 "<td align=left>".
							 "<img class=\"viewitem\" src=\"" . check_theme_image("images/folder.png") . "\" alt=\"Directory\" /> ".
							 "<a href=\"?m=litefm&amp;home_id=$home_id&amp;item=$i\">". 
							 $directory['filename'] . "</a></td>";
						if( $os == "linux" )
							echo "<td>-</td>";
						echo "<td>-</td> <td>" . $directory['user'] . " " . $directory['group']. "</td>\n".
							 "</tr>\n";
						$_SESSION['fm_files_'.$home_id][$i] = $directory['filename'];
						$i++;
					}
				}
				
				if(isset($dirlist['files']) and is_array($dirlist['files']))
				{
					$dirlist['files'] = array_orderby($dirlist['files'], 'filename', SORT_ASC);
					foreach($dirlist['files'] as $file)
					{
						if( $os == "linux" )
						{
							if($isAdmin){
								$secureFile = "<td><div data-item='$i' data-file_name=\"" . str_replace('"', "&quot;", $file['filename']) . "\" class='chattrButton ";
								if( preg_match( "/i/", $file['attr'] ) ){
									$secureFile .= "locked' data-set_attr='-i' ><i></i><span>". chattr_no;
								}else{
									$secureFile .= "unlocked' data-set_attr='+i' ><i></i><span>". chattr_yes;
								}
								$secureFile .= "</span></div></td>\n";
							}else{
								$secureFile = "<td><span class=";
								if( preg_match( "/i/", $file['attr'] ) ){
									$secureFile .= "'chattrLock'>". chattr_locked; 
								}else{
									$secureFile .= "'chattrUnlock'>". chattr_unlocked; 
								}
								$secureFile .= "</span></td>\n";
							}
						}
						else
							$secureFile = "";
							
						echo "<tr>\n".
							 "<td>".
							 "<input type=checkbox name='file' data-item='$i' value=\"" . str_replace('"', "&quot;", $file['filename']) . "\" class='item' />\n".
							 "</td>".
							 "<td align=left>";
						echo "<img class=\"viewitem\" src=\"" . check_theme_image("images/txt.png") . "\" alt=\"Text file\" /> ".
							 "<a href=\"?m=litefm&amp;home_id=$home_id&amp;item=$i&amp;p=read_file\">". button_edit ."</a>".
							 $file['filename'].
							 "</td>$secureFile<td>" . $file['size'] . "</td> <td>" . $file['user'] . " " . $file['group']. "</td>\n";
						echo "</tr>\n";
						$_SESSION['fm_files_'.$home_id][$i] = $file['filename'];
						$i++;
					}
				}
				
				if(isset($dirlist['binarys']) and is_array($dirlist['binarys']))
				{
					$dirlist['binarys'] = array_orderby($dirlist['binarys'], 'filename', SORT_ASC);
					foreach($dirlist['binarys'] as $binary)
					{
						if( $os == "linux" )
						{
							if($isAdmin){
								$secureFile = "<td><div data-item='$i' data-file_name=\"" . str_replace('"', "&quot;", $binary['filename']) . "\" class='chattrButton ";
								if( preg_match( "/i/", $binary['attr'] ) ){
									$secureFile .= "locked' data-set_attr='-i' ><i></i><span>". chattr_no;
								}else{
									$secureFile .= "unlocked' data-set_attr='+i' ><i></i><span>". chattr_yes;
								}
								$secureFile .= "</span></div></td>\n";
							}else{
								$secureFile = "<td><span class=";
								if( preg_match( "/i/", $binary['attr'] ) ){
									$secureFile .= "'chattrLock'>". chattr_locked; 
								}else{
									$secureFile .= "'chattrUnlock'>". chattr_unlocked; 
								}
								$secureFile .= "</span></td>\n";
							}
						}
						else
							$secureFile = "";
							
						echo "<tr>\n".
							 "<td>".
							 "<input type=checkbox name='binary' data-item='$i' value=\"" . str_replace('"', "&quot;", $binary['filename']) . "\" class='item' />\n".
							 "</td>".
							 "<td align=left>";
						echo "<img class=\"viewitem\" src=\"" . check_theme_image("images/exec.png") . "\" alt=\"Binary file\" /> ".
							 $binary['filename'].
							 "</td>$secureFile<td>" . $binary['size'] . "</td><td>" . $binary['user'] . " " . $binary['group']. "</td>\n";
						echo "</tr>\n";
						$_SESSION['fm_files_'.$home_id][$i] = $binary['filename'];
						$i++;
					}
				}
				echo "</table>\n";
			}
		}
		echo "<table class='center'><tr><td><a href='?m=gamemanager&amp;p=game_monitor&amp;home_id=".$home_cfg['home_id']."'><< ". back ."</a></td></tr></table>";
		// Dialog translation && info
		$user = $db->getUserById($_SESSION['user_id']);
		echo "<div id='dialog' ".
			 "data-folder=\"" . clean_path("/".str_replace('"', "&quot;", @$_SESSION['fm_cwd_'.$home_id])) . "\" " .
			 "data-select_at_least_one_item='" . select_at_least_one_item . "' " .
			 "data-ask_delete='" . delete_item . "' " .
			 "data-ask_rename='" . rename_item . "' " .
			 "data-ask_move='" . move_item . "' " .
			 "data-ask_copy='" . copy_item . "' " .
			 "data-ask_compress='" . compress_item . "' " .
			 "data-ask_uncompress='" . uncompress_item . "' " .
			 "data-archive_name='" . archive_name . "' " .
			 "data-archive_type='" . archive_type . "' " .
			 "data-file_name='" . file_name . "' " .
			 "data-folder_name='" . folder_name . "' " .
			 "data-compresses_files_separately='" . compresses_files_separately . "' " .
			 "data-to='" . to . "' " .
			 "data-yes='" . yes . "' " .
			 "data-no='" . no . "' " .
			 "data-max_file_uploads='" . ini_get('max_file_uploads') . "' " .
			 "data-upload_to_web='" . upload_to_web . "' " .
			 "data-transfer_to_server='" . transfer_to_server . "' " .
			 "data-upload='" . upload . "' " .
			 "data-ask_send_by_email='" . send_item_by_email . "' " .
			 "data-subject='" . subject . "' " .
			 "data-message='" . message . "' " .
			 "data-dest_email='" . dest_email . "' " .
			 "data-user_email='" . $user['users_email'] . "' ";
			 if($isAdmin)
				echo "data-ask_change_attr=\"" . get_lang_f( 'secure_item', clean_path( str_replace('"', "&quot;", $path) . "/%file_name%" ) ) . "\" ";
			 echo "></div>";
	}
}
?>