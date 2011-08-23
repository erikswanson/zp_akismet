<?php
// Copyright (C) 2011 Erik Swanson
// 
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
// 
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
// 
// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software
// Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

/**
 * This plugin provides support for using the Akismet spam-filtering service.
 *
 * @author Erik Swanson <zp_akismet@erik.swanson.name>
 * @version 0.1.0
 * @package plugins
 */

/**
 * This implements the standard SpamFilter class for the Akismet spam filter.
 */
class SpamFilter {

	/**
	 * The SpamFilter class instantiation function.
	 *
	 * @return SpamFilter
	 */
	function SpamFilter() {
		setOptionDefault('Akismet_key', '');
	}

	/**
	 * The admin options interface
	 * called from admin Options tab
	 *	returns an array of the option names the theme supports
	 *	the array is indexed by the option name. The value for each option is an array:
	 *			'type' => 0 says for admin to use a standard textbox for the option
	 *			'type' => 1 says for admin to use a standard checkbox for the option
	 *			'type' => OPTION_TYPE_CUSTOM will cause admin to call handleOption to generate the HTML for the option
	 *			'desc' => text to be displayed for the option description.
	 *
	 * @return array
	 */
	function getOptionsSupported() {
		return array(
			gettext('Akismet API key') => array(
				'key' => 'Akismet_key',
				'type' => 0,
				'desc' => gettext('Proper operation requires an <a href="https://akismet.com/signup/">Akismet API key</a>.')
				)
			);
	}

	/**
	 * Handles custom formatting of options for Admin
	 *
	 * @param string $option the option name of the option to be processed
	 * @param mixed $currentValue the current value of the option (the "before" value)
	 */
	function handleOption($option, $currentValue) {
	}

	/**
	 * The function for processing a message to see if it might be SPAM
	 *		 returns:
	 *		   0 if the message is SPAM
	 *		   1 if the message might be SPAM (it will be marked for moderation)
	 *		   2 if the message is not SPAM
	 *
	 * @param string $author Author field from the posting
	 * @param string $email Email field from the posting
	 * @param string $website Website field from the posting
	 * @param string $body The text of the comment
	 * @param object $receiver The object on which the post was made
	 * @param string $ip the IP address of the comment poster
	 *
	 * @return int
	 */
	function filterMessage($author, $email, $website, $body, $receiver, $ip) {
		$request_url = self::getAkismetApiUrl('comment-check');
		$request_fields = array(
			'blog' => FULLWEBPATH,
			'user_ip' => $ip,
			'user_agent' => $_SERVER['HTTP_USER_AGENT'],
			'referrer' => $_SERVER['HTTP_REFERER'],
			'permalink' => self::getReceiverPermalink($receiver),
			'comment_type' => 'comment',
			'comment_author' => $author,
			'comment_author_email' => $email,
			'comment_author_url' => $website,
			'comment_content' => $body
			);
		
		$curl_handle= curl_init($request_url);
		curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($curl_handle, CURLOPT_POST, true);
		curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $request_fields);
		curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl_handle, CURLOPT_HEADER, true);
		$response = curl_exec($curl_handle);
		
		$retval = 1; // This default should never actually be returned.
		if (curl_errno($curl_handle) !== 0) {
			$curl_error_message = curl_error($curl_handle);
			self::emailAdmins($curl_error_message);
			// zp_error('The Akismet spam filter encountered an error: ' . $curl_error_message);
			$retval = 1;
		} elseif (($response_code = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE)) !== 200) {
			self::emailAdmins('An unexpected response was received from the Akismet service:\n' . $response);
			// zp_error('Akismet: curl response code=' . $response_code . ' body="' . $response . '"');
			$retval = 1;
		} else {
			$response_header_length = curl_getinfo($curl_handle, CURLINFO_HEADER_SIZE);
			$response_body = substr($response, $response_header_length);
			if ($response_body === 'true') {
				$retval = 0;
			} elseif ($response_body === 'false') {
				$retval = 2;
			} else {
				self::emailAdmins('An unexpected response was received from the Akismet service:\n' . $response);
				zp_error('Unexpected response from Akismet server: ' . htmlentities($response_body));
				$retval = 1;
			}
		}
		curl_close($curl_handle);
		return $retval;
	}
	
	/**
	 * Construct a permalink (URL) for the receiver of a comment.
	 * 
	 * @param object $receiver The object on which the post was made
	 * 
	 * @return string
	 */
	static private function getReceiverPermalink($receiver) {
		// FIXME: This does not support comments on anything except Images.
		if (isImageClass($receiver)) {
			return FULLWEBPATH . $receiver->getImageLink();
		} else {
			zp_error('The Akismet spam filter is enabled, but does not (yet) support comments on this type of object.');
		}
	}
	
	/**
	 * Construct the URL for an Akismet API call.
	 *
	 * @param string $method The API method to be called
	 *
	 * @return string
	 */
	static private function getAkismetApiUrl($method) {
		if ($method === 'verify-key') {
			return 'http://rest.akismet.com/1.1/verify-key';
		} else {
			$akismet_key = getOption('Akismet_key');
			if ((!is_string($akismet_key)) || empty($akismet_key)) {
				zp_error('The Akismet spam filter is enabled, but has not been configured with an API key.');
			} else {
				return 'http://' . $akismet_key . '.rest.akismet.com/1.1/' . $method;
			}
		}
	}
	
	/**
	 * Send an email to all administrators about an error.
	 *
	 * This is a messy copy of functionality from zp-core/lib-auth.php and will be removed at a later date.
	 *
	 * @param string $error_message The error message to report
	 */
	static private function emailAdmins($error_message) {
		// TODO: Factor out the email-all-admins code in zp-core/lib-auth.php and remove this copy.
		global $_zp_authority;
		$admins = $_zp_authority->getAdministrators();
		$mails = array();
		$user = NULL;
		foreach ($admins as $key=>$user) {
			if (!empty($user['email'])) {
				if (!($user['rights'] & ADMIN_RIGHTS)) {
					unset($admins[$key]);	// eliminate any peons from the list
				}
			} else {
				unset($admins[$key]);	// we want to ignore groups and users with no email address here!
			}
		}
		$mails = array();
		foreach ($admins as $user) {
			$name = $user['name'];
			if (empty($name)) {
				$name = $user['user'];
			}
			$mails[$name] = $user['email'];
		}
		$subject = gettext('[Zenphoto] Akismet Plugin Error');
		$body = gettext('The Akismet spam-filtering plugin was unable to classify a message because of the following error:')
			. ' \n' . $error_message . '\n';
		$mail_err_msg = zp_mail($subject, $body, $mails, array());
		if (!empty($mail_error_msg)) {
			debugLog('Error sending mail to all admins: ' . $mail_err_msg);
			zp_error('The Akismet plugin encountered an error and failed to alert the admins about the error.', false);
		}
	}
}
?>