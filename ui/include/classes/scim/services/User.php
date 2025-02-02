<?php
/*
** Zabbix
** Copyright (C) 2001-2023 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


namespace SCIM\services;

use API as APIRPC;
use APIException;
use CApiInputValidator;
use CAuthenticationHelper;
use CProvisioning;
use DB;
use Exception;
use SCIM\ScimApiService;

class User extends ScimApiService {

	public const ACCESS_RULES = [
		'get' => ['min_user_type' => USER_TYPE_SUPER_ADMIN],
		'put' => ['min_user_type' => USER_TYPE_SUPER_ADMIN],
		'post' => ['min_user_type' => USER_TYPE_SUPER_ADMIN],
		'patch' => ['min_user_type' => USER_TYPE_SUPER_ADMIN],
		'delete' => ['min_user_type' => USER_TYPE_SUPER_ADMIN]
	];

	private const SCIM_USER_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:User';
	private const SCIM_LIST_RESPONSE_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';
	private const SCIM_PATCH_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';

	protected array $data = [
		'schemas' => [self::SCIM_USER_SCHEMA]
	];

	/**
	 * Returns information on specific user or all users if no specific information is requested.
	 * If user is not in database, returns only 'schemas' parameter.
	 *
	 * @param array  $options              Array with data from request.
	 * @param string $options['userName']  UserName parameter from GET request.
	 * @param string $options['id']        User id parameter from GET request URL.
	 *
	 * @return array                       Array with data necessary to create response.
	 *
	 * @throws Exception
	 */
	public function get(array $options = []): array {
		$userdirectoryid = CAuthenticationHelper::getSamlUserdirectoryidForScim();

		$this->validateGet($options);

		if (array_key_exists('userName', $options)) {
			$users = APIRPC::User()->get([
				'output' => ['userid', 'username', 'userdirectoryid'],
				'selectUsrgrps' => ['usrgrpid'],
				'filter' => ['username' => $options['userName']]
			]);

			$user_groups = $users ? array_column($users[0]['usrgrps'], 'usrgrpid') : [];
			$disabled_groupid = CAuthenticationHelper::get(CAuthenticationHelper::DISABLED_USER_GROUPID);

			if (!$users || (count($user_groups) == 1 && $user_groups[0] == $disabled_groupid)) {
				$this->data += [
					'totalResults' => 0,
					'Resources' => []
				];
			}
			elseif ($users[0]['userdirectoryid'] != $userdirectoryid) {
				self::exception(self::SCIM_ERROR_BAD_REQUEST,
					'User with username '.$options["userName"].' already exists.'
				);
			}
			else {
				$this->data = $this->prepareData($users[0]);
			}
		}
		elseif (array_key_exists('id', $options)) {
			$users = APIRPC::User()->get([
				'output' => ['userid', 'username', 'userdirectoryid'],
				'userids' => $options['id'],
				'filter' => ['userdirectoryid' => $userdirectoryid]
			]);

			if (!$users) {
				self::exception(self::SCIM_ERROR_NOT_FOUND, 'No permissions to referred object or it does not exist!');
			}

			$this->data = $this->prepareData($users[0]);
		}
		else {
			$userids = APIRPC::User()->get([
				'output' => ['userid'],
				'filter' => ['userdirectoryid' => $userdirectoryid]
			]);
			$total_users = count($userids);

			$this->data = [
				'schemas' => [self::SCIM_LIST_RESPONSE_SCHEMA],
				'totalResults' => $total_users,
				'startIndex' => max($options['startIndex'], 1),
				'itemsPerPage' => min($total_users, max($options['count'], 0)),
				'Resources' => []
			];

			if ($total_users != 0) {
				$userids = array_slice($userids, $this->data['startIndex'] - 1, $this->data['itemsPerPage']);
				$userids = array_column($userids, 'userid');

				$users = $userids
					? APIRPC::User()->get([
						'output' => ['userid', 'username', 'userdirectoryid'],
						'userids' => $userids
					])
					: [];

				foreach ($users as $user) {
					$user_data = $this->prepareData($user);
					unset($user_data['schemas']);
					$this->data['Resources'][] = $user_data;
				}
			}
		}

		return $this->data;
	}

	/**
	 * @param array $options
	 *
	 * @throws APIException if input is invalid.
	 */
	private function validateGet(array &$options): void {
		$api_input_rules = ['type' => API_OBJECT, 'fields' => [
			'id' =>				['type' => API_ID],
			'userName' =>		['type' => API_STRING_UTF8, 'flags' => API_NOT_EMPTY],
			'startIndex' =>		['type' => API_INT32, 'default' => 1],
			'count' =>			['type' => API_INT32, 'default' => 100]
		]];

		if (!CApiInputValidator::validate($api_input_rules, $options, '/', $error)) {
			self::exception(self::SCIM_ERROR_BAD_REQUEST, $error);
		}
	}

	/**
	 * Checks if requested user is in database. If user does not exist, creates new user, if user exists, updates this
	 * user.
	 *
	 * @param array  $options              Array with different attributes that might be set up in SAML settings.
	 * @param string $options['userName']  Users user name based on which user will be searched.
	 *
	 * @return array                       Returns SCIM data that is necessary for POST request response.
	 */
	public function post(array $options): array {
		$userdirectoryid = CAuthenticationHelper::getSamlUserdirectoryidForScim();

		$this->validatePost($options);

		$db_users = APIRPC::User()->get([
			'output' => ['userid', 'userdirectoryid'],
			'filter' => ['username' => $options['userName']]
		]);

		$provisioning = CProvisioning::forUserDirectoryId($userdirectoryid);

		$user_data['userdirectoryid'] = $userdirectoryid;
		$user_data += $provisioning->getUserAttributes($options);
		$user_data['medias'] = $provisioning->getUserMedias($options);

		if (!$db_users) {
			$user_data['username'] = $options['userName'];
			$user = APIRPC::User()->createProvisionedUser($user_data);
		}
		elseif ($db_users[0]['userdirectoryid'] == $userdirectoryid) {
			$user_data['userid'] = $db_users[0]['userid'];
			$user_data['usrgrps'] = [];
			$user = APIRPC::User()->updateProvisionedUser($user_data);
			$user['userid'] = $db_users[0]['userid'];
		}
		else {
			self::exception(self::SCIM_ERROR_BAD_REQUEST,
				'User with username '.$options['userName'].' already exists.'
			);
		}

		$this->setData($user['userid'], $userdirectoryid, $options);

		return $this->data;
	}

	/**
	 * @param array $options
	 *
	 * @throws APIException if input is invalid.
	 */
	private function validatePost(array $options) {
		$api_input_rules = ['type' => API_OBJECT, 'flags' => API_REQUIRED | API_ALLOW_UNEXPECTED, 'fields' => [
			'schemas' =>	['type' => API_STRINGS_UTF8, 'flags' => API_REQUIRED | API_NOT_EMPTY],
			'userName' =>	['type' => API_STRING_UTF8, 'flags' => API_REQUIRED | API_NOT_EMPTY]
		]];

		if (!CApiInputValidator::validate($api_input_rules, $options, '/', $error)) {
			self::exception(self::SCIM_ERROR_BAD_REQUEST, $error);
		}

		if (!in_array(self::SCIM_USER_SCHEMA, $options['schemas'], true)) {
			self::exception(self::SCIM_ERROR_BAD_REQUEST, 'Incorrect schema was sent in the request.');
		}
	}

	/**
	 * Updates user in the database with newly received information. If $options['active'] parameter is false, user
	 * is deleted from the database.
	 *
	 * @param array  $options
	 * @param string $options['id']
	 * @param string $options['userName']
	 * @param bool   $options['active']  True of false, but sent as string.
	 *
	 * @return array          Returns SCIM data that is necessary for PUT request response.
	 */
	public function put(array $options): array {
		// In order to comply with Azure SCIM without flag "aadOptscim062020", attribute active value is transformed to
		// boolean.
		if (array_key_exists('active', $options) && !is_bool($options['active'])) {
			$options['active'] = strtolower($options['active']) === 'true';
		}

		$this->validatePut($options, $db_user);
		$user_group_names = [];
		$provisioning = CProvisioning::forUserDirectoryId($db_user['userdirectoryid']);

		// Some IdPs have group attribute, but others don't.
		if (array_key_exists('groups', $options)) {
			$user_group_names = array_column($options['groups'], 'display');
		}
		else {
			$user_groupids = DB::select('user_scim_group', [
				'output' => ['scim_groupid'],
				'filter' => ['userid' => $options['id']]
			]);

			if ($user_groupids) {
				$user_group_names = DB::select('scim_group', [
					'output' => ['name'],
					'scim_groupids' => array_column($user_groupids, 'scim_groupid')
				]);
				$user_group_names = array_column($user_group_names, 'name');
			}
		}

		// In case some IdPs do not send attribute 'active'.
		$options += [
			'active' => true
		];

		$user_data = [
			'userid' => $db_user['userid'],
			'username' => $options['userName']
		];
		$user_data += $provisioning->getUserAttributes($options);
		$user_data += $provisioning->getUserGroupsAndRole($user_group_names);
		$user_data['medias'] = $provisioning->getUserMedias($options);

		if ($options['active'] == false) {
			$user_data['usrgrps'] = [];
		}

		APIRPC::User()->updateProvisionedUser($user_data);
		$this->setData($db_user['userid'], $db_user['userdirectoryid'], $options);

		return $this->data;
	}

	/**
	 * @param array $options
	 *
	 * @returns array                Returns user data from the database.
	 *          ['userid']
	 *          ['userdirectoryid']
	 *
	 * @throws APIException if input is invalid or user cannot be modified.
	 */
	private function validatePut(array &$options, array &$db_user = null): void {
		$api_input_rules = ['type' => API_OBJECT, 'flags' => API_REQUIRED | API_ALLOW_UNEXPECTED, 'fields' => [
			'schemas' =>	['type' => API_STRINGS_UTF8, 'flags' => API_REQUIRED | API_NOT_EMPTY],
			'id' =>			['type' => API_ID, 'flags' => API_REQUIRED],
			'userName' =>	['type' => API_STRING_UTF8, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'length' => DB::getFieldLength('users', 'username')],
			'active' =>		['type' => API_BOOLEAN, 'flags' => API_NOT_EMPTY],
			'groups' =>		['type' => API_OBJECTS, 'fields' => [
				'value' =>		['type' => API_ID, 'flags' => API_REQUIRED],
				'display' =>	['type' => API_STRING_UTF8, 'flags' => API_REQUIRED | API_NOT_EMPTY]
			]]
		]];

		if (!CApiInputValidator::validate($api_input_rules, $options, '/', $error)) {
			self::exception(self::SCIM_ERROR_BAD_REQUEST, $error);
		}

		if (!in_array(self::SCIM_USER_SCHEMA, $options['schemas'], true)) {
			self::exception(self::SCIM_ERROR_BAD_REQUEST, 'Incorrect schema was sent in the request.');
		}

		$userdirectoryid = CAuthenticationHelper::getSamlUserdirectoryidForScim();
		$db_user = APIRPC::User()->get([
			'output' => ['userid', 'userdirectoryid'],
			'userids' => $options['id'],
			'filter' => ['userdirectoryid' => $userdirectoryid]
		]);

		if (!$db_user) {
			self::exception(self::SCIM_ERROR_NOT_FOUND, 'No permissions to referred object or it does not exist!');
		}

		$db_user = $db_user[0];
	}

	/**
	 * Updates user in the database with newly received information.
	 *
	 * @param array  $options                                      Array with data from request.
	 * @param string $options['id']                                User id.
	 * @param array  $options['Operations']                        List of operations that need to be performed.
	 * @param string $options['Operations'][]['op']                Operation that needs to be performed -'add',
	 *                                                             'replace', 'remove'.
	 * @param string $options['Operations'][]['path']              On what operation should be performed, filters are
	 *                                                             not supported, supported 'path' is only 'userName',
	 *                                                             'active' and the one that matches custom user
	 *                                                             attributes .
	 * @param string $options['Operations'][]['value']             Value on which operation should be
	 *                                                             performed. If operation is 'remove' this can be
	 *                                                             omitted.
	 *
	 * @return array  Returns array with data necessary for SCIM response.
	 *
	 * @throws APIException
	 */
	public function patch(array $options): array {
		// In order to comply with Azure SCIM without flag "aadOptscim062020", attribute active value is transformed to
		// boolean.
		if (array_key_exists('Operations', $options)) {
			foreach ($options['Operations'] as &$operation) {
				if (array_key_exists('path', $operation) && $operation['path'] === 'active'
						&& !is_bool($operation['value'])
				) {
					$operation['value'] = strtolower($operation['value']) === 'true';
				}
			}
			unset($operation);
		}

		$this->validatePatch($options, $db_user);

		$user_idp_data = [];
		foreach ($options['Operations'] as $operation) {
			if ($operation['op'] === 'remove') {
				$user_idp_data[$operation['path']] = '';
			}
			else {
				$user_idp_data[$operation['path']] = $operation['value'];
			}
		}

		$provisioning = CProvisioning::forUserDirectoryId($db_user['userdirectoryid']);
		$new_user_data = $provisioning->getUserAttributes($user_idp_data);
		$new_user_data = array_merge($db_user, $new_user_data);

		// If user status 'active' is changed to false, user needs to be added to disabled group.
		if (array_key_exists('active', $user_idp_data) && strtolower($user_idp_data['active']) == false) {
			$new_user_data['usrgrps'] = [];
			$user_idp_data['active'] = false;
		}

		// If disabled user is activated again, need to return group mapping.
		if ($db_user['roleid'] == 0 && array_key_exists('active', $user_idp_data)
				&& strtolower($user_idp_data['active']) == true) {

			$group_names = DBfetchColumn(DBselect(
				'SELECT g.name'.
				' FROM user_scim_group ug,scim_group g'.
				' WHERE g.scim_groupid=ug.scim_groupid AND '.dbConditionId('ug.userid', [$options['id']])
			), 'name');

			$new_user_data = array_merge($new_user_data, $provisioning->getUserGroupsAndRole($group_names));
		}

		APIRPC::User()->updateProvisionedUser($new_user_data);

		$this->setData($db_user['userid'], $db_user['userdirectoryid'], $user_idp_data);

		return $this->data;
	}

	/**
	 * @param array $options
	 * @param array $db_user
	 *
	 * @throws APIException if input is invalid.
	 */
	private function validatePatch(array &$options, array &$db_user = null): void {
		$api_input_rules = ['type' => API_OBJECT, 'flags' => API_REQUIRED | API_ALLOW_UNEXPECTED, 'fields' => [
			'id' =>			['type' => API_ID, 'flags' => API_REQUIRED],
			'schemas' =>	['type' => API_STRINGS_UTF8, 'flags' => API_REQUIRED | API_NOT_EMPTY],
			'Operations' =>	['type' => API_OBJECTS, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'fields' => [
				'op' =>			['type' => API_STRING_UTF8, 'flags' => API_REQUIRED, 'in' => implode(',', ['add', 'remove', 'replace', 'Add', 'Remove', 'Replace'])],
				'path' =>		['type' => API_STRING_UTF8, 'flags' => API_REQUIRED],
				'value' =>      ['type' => API_MULTIPLE, 'rules' => [
					['if' => ['field' => 'path', 'in' => implode(',', ['active'])], 'type' => API_BOOLEAN, 'flags' => API_REQUIRED],
					['if' => ['field' => 'op', 'in' => implode(',', ['remove', 'Remove'])], 'type' => API_STRING_UTF8],
					['else' => true, 'type' => API_STRING_UTF8, 'flags' => API_REQUIRED]
				]]
			]]
		]];

		if (!CApiInputValidator::validate($api_input_rules, $options, '/', $error)) {
			self::exception(self::SCIM_ERROR_BAD_REQUEST, $error);
		}

		if (!in_array(self::SCIM_PATCH_SCHEMA, $options['schemas'], true)) {
			self::exception(self::SCIM_ERROR_BAD_REQUEST, 'Incorrect schema was sent in the request.');
		}

		$userdirectoryid = CAuthenticationHelper::getSamlUserdirectoryidForScim();
		$db_user = APIRPC::User()->get([
			'output' => ['userid', 'name', 'surname', 'userdirectoryid', 'roleid'],
			'userids' => $options['id'],
			'filter' => ['userdirectoryid' => $userdirectoryid]
		]);

		if (!$db_user) {
			self::exception(self::SCIM_ERROR_NOT_FOUND, 'No permissions to referred object or it does not exist!');
		}

		$db_user = $db_user[0];

		foreach ($options['Operations'] as &$operation) {
			$operation['op'] = strtolower($operation['op']);
		}
	}

	/**
	 * Deletes requested user based on userid.
	 *
	 * @param array  $options
	 * @param string $options['id']  Userid.
	 *
	 * @return array          Returns only schema parameter, the rest of the parameters are not included.
	 */
	public function delete(array $options): array {
		$this->validateDelete($options);

		$provisioning = CProvisioning::forUserDirectoryId($options['userdirectoryid']);
		$user_data = [
			'userid' => $options['id']
		];
		$user_data += $provisioning->getUserAttributes($options);
		$user_data['medias'] = $provisioning->getUserMedias($options);
		$user_data['usrgrps'] = [];

		DB::delete('user_scim_group', ['userid' => $user_data['userid']]);

		APIRPC::User()->updateProvisionedUser($user_data);

		return $this->data;
	}

	/**
	 * @param array $options
	 *
	 * @throws APIException if the input is invalid.
	 */
	private function validateDelete(array &$options) {
		$api_input_rules = ['type' => API_OBJECT, 'flags' => API_REQUIRED, 'fields' => [
			'id' =>	['type' => API_ID, 'flags' => API_REQUIRED]
		]];

		if (!CApiInputValidator::validate($api_input_rules, $options, '/', $error)) {
			self::exception(self::SCIM_ERROR_BAD_REQUEST, $error);
		}

		$userdirectoryid = CAuthenticationHelper::getSamlUserdirectoryidForScim();
		$options['userdirectoryid'] = $userdirectoryid;

		$db_users = APIRPC::User()->get([
			'output' => ['userid', 'userdirectoryid'],
			'userids' => $options['id'],
			'filter' => ['userdirectoryid' => $userdirectoryid]
		]);

		if (!$db_users) {
			self::exception(self::SCIM_ERROR_NOT_FOUND, 'No permissions to referred object or it does not exist!');
		}
	}

	/**
	 * Updates $this->data parameter with the data that is required by SCIM.
	 *
	 * @param string $userid
	 * @param string $userdirectoryid  SAML userdirectory ID.
	 * @param array  $options          Optional. User information sent in request from IdP.
	 *
	 * @return void
	 */
	private function setData(string $userid, string $userdirectoryid, array $options = []): void {
		$user = APIRPC::User()->get([
			'output' => ['userid', 'username', 'userdirectoryid'],
			'userids' => $userid,
			'filter' => ['userdirectoryid' => $userdirectoryid]
		]);

		$this->data += $this->prepareData($user[0], $options);
	}

	/**
	 * Returns user data that is necessary for SCIM response to IdP and that can be used to update $this->data.
	 *
	 * @param array  $user
	 * @param string $user['userid']
	 * @param string $user['username']
	 * @param array  $options                                     Optional. User information sent in request from IdP.
	 *
	 * @return array                                              Returns array with data formatted according to SCIM.
	 *                                                            Attributes might vary based on SAML settings.
	 *         ['id']
	 *         ['userName']
	 *         ['active']
	 *         ['name']
	 *         ['attribute']                                      Some other attributes set up in SAML settings.
	 */
	private function prepareData(array $user, array $options = []): array {
		$data = [
			'schemas'	=> [self::SCIM_USER_SCHEMA],
			'id' 		=> $user['userid'],
			'userName'	=> $user['username'],
			'name' => array_key_exists('name', $options) ? $options['name'] : ['givenName' => '', 'familyName' => '']
		];

		$data['active'] = array_key_exists('active', $options) ? $options['active'] : true;

		$provisioning = CProvisioning::forUserDirectoryId($user['userdirectoryid']);
		$user_attributes = $provisioning->getUserAttributes($options);
		$data += $user_attributes;

		$media_attributes = $provisioning->getUserIdpMediaAttributes();
		foreach ($media_attributes as $media_attribute) {
			if (array_key_exists($media_attribute, $options)) {
				$data[$media_attribute] = $options[$media_attribute];
			}
		}

		return $data;
	}
}
