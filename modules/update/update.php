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
 
 // todo, make checking and updating functions for updateing on the background.
 // todo, more specified updates in smaller packages

function exec_ogp_module()
{	
	if ($_SESSION['users_group'] != "admin") 
	{
		print_failure(get_lang('no_access'));
		return;
	}

	global $db,$settings;

	echo "<h2>".get_lang('update')."</h2>";

	$pversion = $settings['ogp_version'];
	
	echo '<a href="?m=update&p=blacklist" >'.get_lang('blacklist_files')."</a>&nbsp;".get_lang('blacklist_files_info')."</br></br>";
	echo get_lang('panel_version').": ".$pversion."</br></br>";
	
	//Get SVN rev.
	$serverlist_url = "http://sourceforge.net/projects/ogpextras/rss?path=/Alternative-Snapshot&limit=3";

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $serverlist_url);
	curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)');
	curl_setopt($ch, CURLOPT_HTTPHEADER,array("Accept-Language: es-es,en"));
	curl_setopt($ch, CURLOPT_TIMEOUT, 60);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	$date = new DateTime();
	$expires = gmdate('D, d-M-Y H:i:s \G\M\T', $date->getTimestamp() + 31536000000);
	curl_setopt($ch, CURLOPT_COOKIE, "FreedomCookie=true;path=/;expires=".$expires);
	
	//Save Page
	$result = curl_exec($ch);
	curl_close($ch);

	//Parse the result to get the last version.
	preg_match_all("(/Alternative-Snapshot/hldstart-code-(.*)\.zip)siU", $result, $matches);
	$last_svn_version = $matches[1][0];
	
	if( isset( $last_svn_version ) and $last_svn_version != "" )
	{
		echo get_lang('latest_version').": $last_svn_version</br></br>";
				
		//Check snapshot file exists.
		$ch = curl_init("http://master.dl.sourceforge.net/project/ogpextras/Alternative-Snapshot/hldstart-code-${last_svn_version}.zip");
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_exec($ch);
		$retcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		// Run the snapshot creation if it does not exists yet.
		if( $retcode == '404' )
		{
			print_failure("The snapshot of the current version has not been created yet.");
			print_failure("Try again in 5 minutes.");
		}
		else
		{
			if ( $last_svn_version != $pversion )
			{
				if(isset($_GET['view_changes']))
				{
					echo "<div style=\"text-align:left;width:80%;margin:0 auto;padding: 10px 5px;".
						 "border: 1px solid rgb(170, 170, 170);border-radius: 4px;background-color: rgb(189, 229, 248);\" >\n".
						 "<a href='http://sourceforge.net/p/hldstart/svn/commit_browser' target='_blank' ><b>[ All commits ]</b></a><br><br>\n";

					$serverlist_url = "http://sourceforge.net/p/hldstart/svn/feed";
					$ch = curl_init();
					curl_setopt($ch, CURLOPT_URL, $serverlist_url);
					curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)');
					curl_setopt($ch, CURLOPT_HTTPHEADER,array("Accept-Language: es-es,en"));
					curl_setopt($ch, CURLOPT_TIMEOUT, 10);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
					curl_setopt($ch, CURLOPT_COOKIE, "FreedomCookie=true;path=/;expires=".$expires);
					// Save Page
					$result = curl_exec($ch);
					curl_close($ch);
					
					$xml = simplexml_load_string($result);
					$json = json_encode($xml);
					$feed_info = json_decode($json,TRUE);
					
					$items = array_reverse($feed_info['channel']['item']);
					foreach($items as $item)
					{
						$link_arr = explode("/", $item['link']);
						$revision = $link_arr[6];
						if($pversion >= $revision)
							continue;
						$description = strip_tags(preg_replace("#<br />(.*)</a>#", "", $item['description']));
						echo "<b>Revision $revision changes</b>&nbsp;".
							 "<a href='$item[guid]' target='_blank' ><b>[ More details ]</b></a>:\n $description<br><br>";
						if($last_svn_version == $revision)
							break;
					}
					echo "</div>";
				}
				else
				{
					echo "<form action='' method='get'>\n".
						 "<input type='hidden' name='m' value='update' />\n".
						 "<input type='hidden' name='view_changes' value='true' />\n".
						 "<input type='submit' value='".get_lang('view_changes')." (Max 10)' /></form>\n";
				}
				
				echo "<br>\n";
				
				$url = "http://sourceforge.net/settings/mirror_choices?projectname=ogpextras".
					   "&filename=Alternative-Snapshot/hldstart-code-$last_svn_version.zip";
				
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $url);
				curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)');
				curl_setopt($ch, CURLOPT_HTTPHEADER,array("Accept-Language: es-es,en"));
				curl_setopt($ch, CURLOPT_TIMEOUT, 10);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_COOKIE, "FreedomCookie=true;path=/;expires=".$expires);
				// Save Page
				$res = curl_exec($ch);
				curl_close($ch);
				
				preg_match_all("(<li id=\"(.*)\">.*<label for=\".*\">(.*)</label> (.*)</li>)siU", $res, $matching);
				
				$sf_mirrors = array();
				$qty = count($matching[0]);
				for( $i = 0; $i < $qty; $i++ )
				{
					if ( $matching[1][$i] == 'autoselect' AND ( ini_get('open_basedir') or get_true_boolean(ini_get('safe_mode')) ) )
						continue;
					$sf_mirrors[$matching[1][$i]] = $matching[2][$i] . " " . $matching[3][$i];
				}
				echo "<form action='?m=update&amp;p=updating&amp;version=".$last_svn_version."' method='post'>\n".
					 get_lang('select_mirror').": ".create_drop_box_from_array($sf_mirrors, "mirror", 'autoselect', false).
					 "\n<br><br>\n<input type='submit' value='".get_lang('update_now')."' /></form><br><br>\n";
			}
			else
			{
				print_failure(get_lang('the_panel_is_up_to_date'));
			}
		}
	}
	else	
	{
		print_failure('sourceforge.net is down or not responding.');
	}
	echo "</div>\n";
}
?>
