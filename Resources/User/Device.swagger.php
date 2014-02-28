<?php
/**
 * This file is part of the DreamFactory Services Platform(tm) SDK For PHP
 *
 * DreamFactory Services Platform(tm) <http://github.com/dreamfactorysoftware/dsp-core>
 * Copyright 2012-2014 DreamFactory Software, Inc. <developer-support@dreamfactory.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

$_device = array();

$_device['apis'] = array(
	array(
		'path'        => '/{api_name}/device',
		'operations'  =>
		array(
			array(
				'method'           => 'GET',
				'summary'          => 'getDevices() - Retrieve the current user\'s device information.',
				'nickname'         => 'getDevices',
				'type'             => 'DevicesResponse',
				'responseMessages' =>
				array(
					array(
						'message' => 'Unauthorized Access - No currently valid session available.',
						'code'    => 401,
					),
					array(
						'message' => 'System Error - Specific reason is included in the error message.',
						'code'    => 500,
					),
				),
				'notes'            => 'A valid current session is required to use this API.',
			),
			array(
				'method'           => 'POST',
				'summary'          => 'setDevice() - Create a record of the current user\'s device information.',
				'nickname'         => 'setDevice',
				'type'             => 'Success',
				'parameters'       =>
				array(
					array(
						'name'          => 'body',
						'description'   => 'Data containing name-value pairs for the user device.',
						'allowMultiple' => false,
						'type'          => 'DeviceRequest',
						'paramType'     => 'body',
						'required'      => true,
					),
				),
				'responseMessages' =>
				array(
					array(
						'message' => 'Unauthorized Access - No currently valid session available.',
						'code'    => 401,
					),
					array(
						'message' => 'System Error - Specific reason is included in the error message.',
						'code'    => 500,
					),
				),
				'notes'            => 'Record the device information for this session. ' .
									  'This method is idempotent and will only create one entry per uuid.',
			),
		),
		'description' => 'Operations on a user\'s device information.',
	),
);

$_commonProperties = array(
	'id'       =>
		array(
			'type'        => 'integer',
			'format'      => 'int32',
			'description' => 'Identifier of this device.',
		),
	'uuid'     =>
		array(
			'type'        => 'string',
			'description' => 'Unique ID generated by the device.',
		),
	'platform' =>
		array(
			'type'        => 'string',
			'description' => 'Platform information of the device.',
		),
	'version'  =>
		array(
			'type'        => 'string',
			'description' => 'Version information of the device.',
		),
	'model'    =>
		array(
			'type'        => 'string',
			'description' => 'Model information of the device.',
		),
	'extra'    =>
		array(
			'type'        => 'string',
			'description' => 'Extra information from the device.',
		),
);

$_device['models'] = array(
	'DeviceRequest'   =>
		array(
			'id'         => 'DeviceRequest',
			'properties' => $_commonProperties,
		),
	'DeviceResponse'  =>
		array(
			'id'         => 'DeviceResponse',
			'properties' =>
				array_merge(
					$_commonProperties,
					array(
						 'created_date'       =>
							 array(
								 'type'        => 'string',
								 'description' => 'Date this device was created.',
							 ),
						 'last_modified_date' =>
							 array(
								 'type'        => 'string',
								 'description' => 'Date this device was last modified.',
							 ),
					)
				),
		),
	'DevicesRequest'  =>
		array(
			'id'         => 'DevicesRequest',
			'properties' =>
				array(
					'record' =>
						array(
							'type'        => 'Array',
							'description' => 'Array of system device records.',
							'items'       =>
								array(
									'$ref' => 'DeviceRequest',
								),
						),
					'ids'    =>
						array(
							'type'        => 'Array',
							'description' => 'Array of system record identifiers, used for batch GET, PUT, PATCH, and DELETE.',
							'items'       =>
								array(
									'type'   => 'integer',
									'format' => 'int32',
								),
						),
				),
		),
	'DevicesResponse' =>
		array(
			'id'         => 'DevicesResponse',
			'properties' =>
				array(
					'record' =>
						array(
							'type'        => 'Array',
							'description' => 'Array of system device records.',
							'items'       =>
								array(
									'$ref' => 'DeviceResponse',
								),
						),
				),
		),
);

return $_device;
