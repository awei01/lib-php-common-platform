<?php
/**
 * This file is part of the DreamFactory Services Platform(tm) (DSP)
 *
 * DreamFactory Services Platform(tm) <http://github.com/dreamfactorysoftware/dsp-core>
 * Copyright 2012-2013 DreamFactory Software, Inc. <developer-support@dreamfactory.com>
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

return array(
	'resourcePath' => '/{api_name}',
	'apis'         =>
	array(
		0 =>
		array(
			'path'        => '/{api_name}',
			'operations'  =>
			array(
				0 =>
				array(
					'httpMethod'    => 'GET',
					'summary'       => 'List resources available for database schema.',
					'nickname'      => 'getResources',
					'responseClass' => 'Resources',
					'notes'         => 'See listed operations for each resource available.',
				),
				1 =>
				array(
					'httpMethod'    => 'POST',
					'summary'       => 'Create one or more tables.',
					'nickname'      => 'createTables',
					'responseClass' => 'Resources',
					'notes'         => 'Post data should be a single table definition or an array of table definitions.',
				),
				2 =>
				array(
					'httpMethod'    => 'PUT',
					'summary'       => 'Update one or more tables.',
					'nickname'      => 'updateTables',
					'responseClass' => 'Resources',
					'notes'         => 'Post data should be a single table definition or an array of table definitions.',
				),
			),
			'description' => 'Operations available for SQL DB Schemas.',
		),
		1 =>
		array(
			'path'        => '/{api_name}/{table_name}',
			'operations'  =>
			array(
				0 =>
				array(
					'httpMethod'     => 'GET',
					'summary'        => 'Retrieve table definition for the given table.',
					'nickname'       => 'describeTable',
					'responseClass'  => 'TableSchema',
					'parameters'     =>
					array(
						0 =>
						array(
							'name'          => 'table_name',
							'description'   => 'Name of the table to perform operations on.',
							'allowMultiple' => false,
							'dataType'      => 'string',
							'paramType'     => 'path',
							'required'      => true,
						),
					),
					'errorResponses' =>
					array(
						0 =>
						array(
							'reason' => 'Bad Request - Request does not have a valid format, all required parameters, etc.',
							'code'   => 400,
						),
						1 =>
						array(
							'reason' => 'Unauthorized Access - No currently valid session available.',
							'code'   => 401,
						),
						2 =>
						array(
							'reason' => 'System Error - Specific reason is included in the error message.',
							'code'   => 500,
						),
					),
					'notes'          => 'This describes the table, its fields and relations to other tables.',
				),
				1 =>
				array(
					'httpMethod'     => 'POST',
					'summary'        => 'Create one or more fields in the given table.',
					'nickname'       => 'createFields',
					'responseClass'  => 'Success',
					'parameters'     =>
					array(
						0 =>
						array(
							'name'          => 'table_name',
							'description'   => 'Name of the table to perform operations on.',
							'allowMultiple' => false,
							'dataType'      => 'string',
							'paramType'     => 'path',
							'required'      => true,
						),
						1 =>
						array(
							'name'          => 'fields',
							'description'   => 'Array of field definitions.',
							'allowMultiple' => false,
							'dataType'      => 'Fields',
							'paramType'     => 'body',
							'required'      => true,
						),
					),
					'errorResponses' =>
					array(
						0 =>
						array(
							'reason' => 'Bad Request - Request does not have a valid format, all required parameters, etc.',
							'code'   => 400,
						),
						1 =>
						array(
							'reason' => 'Unauthorized Access - No currently valid session available.',
							'code'   => 401,
						),
						2 =>
						array(
							'reason' => 'System Error - Specific reason is included in the error message.',
							'code'   => 500,
						),
					),
					'notes'          => 'Post data should be an array of field properties for a single record or an array of fields.',
				),
				2 =>
				array(
					'httpMethod'     => 'PUT',
					'summary'        => 'Update one or more fields in the given table.',
					'nickname'       => 'updateFields',
					'responseClass'  => 'Success',
					'parameters'     =>
					array(
						0 =>
						array(
							'name'          => 'table_name',
							'description'   => 'Name of the table to perform operations on.',
							'allowMultiple' => false,
							'dataType'      => 'string',
							'paramType'     => 'path',
							'required'      => true,
						),
						1 =>
						array(
							'name'          => 'fields',
							'description'   => 'Array of field definitions.',
							'allowMultiple' => false,
							'dataType'      => 'Fields',
							'paramType'     => 'body',
							'required'      => true,
						),
					),
					'errorResponses' =>
					array(
						0 =>
						array(
							'reason' => 'Bad Request - Request does not have a valid format, all required parameters, etc.',
							'code'   => 400,
						),
						1 =>
						array(
							'reason' => 'Unauthorized Access - No currently valid session available.',
							'code'   => 401,
						),
						2 =>
						array(
							'reason' => 'System Error - Specific reason is included in the error message.',
							'code'   => 500,
						),
					),
					'notes'          => 'Post data should be an array of field properties for a single record or an array of fields.',
				),
				3 =>
				array(
					'httpMethod'     => 'DELETE',
					'summary'        => 'Delete (aka drop) the given table.',
					'nickname'       => 'deleteTable',
					'responseClass'  => 'Success',
					'parameters'     =>
					array(
						0 =>
						array(
							'name'          => 'table_name',
							'description'   => 'Name of the table to perform operations on.',
							'allowMultiple' => false,
							'dataType'      => 'string',
							'paramType'     => 'path',
							'required'      => true,
						),
					),
					'errorResponses' =>
					array(
						0 =>
						array(
							'reason' => 'Bad Request - Request does not have a valid format, all required parameters, etc.',
							'code'   => 400,
						),
						1 =>
						array(
							'reason' => 'Unauthorized Access - No currently valid session available.',
							'code'   => 401,
						),
						2 =>
						array(
							'reason' => 'System Error - Specific reason is included in the error message.',
							'code'   => 500,
						),
					),
					'notes'          => 'Careful, this drops the database table and all of its contents.',
				),
			),
			'description' => 'Operations for per table administration.',
		),
		2 =>
		array(
			'path'        => '/{api_name}/{table_name}/{field_name}',
			'operations'  =>
			array(
				0 =>
				array(
					'httpMethod'     => 'GET',
					'summary'        => 'Retrieve the definition of the given field for the given table.',
					'nickname'       => 'describeField',
					'responseClass'  => 'FieldSchema',
					'parameters'     =>
					array(
						0 =>
						array(
							'name'          => 'table_name',
							'description'   => 'Name of the table to perform operations on.',
							'allowMultiple' => false,
							'dataType'      => 'string',
							'paramType'     => 'path',
							'required'      => true,
						),
						1 =>
						array(
							'name'          => 'field_name',
							'description'   => 'Name of the field to perform operations on.',
							'allowMultiple' => false,
							'dataType'      => 'string',
							'paramType'     => 'path',
							'required'      => true,
						),
					),
					'errorResponses' =>
					array(
						0 =>
						array(
							'reason' => 'Bad Request - Request does not have a valid format, all required parameters, etc.',
							'code'   => 400,
						),
						1 =>
						array(
							'reason' => 'Unauthorized Access - No currently valid session available.',
							'code'   => 401,
						),
						2 =>
						array(
							'reason' => 'System Error - Specific reason is included in the error message.',
							'code'   => 500,
						),
					),
					'notes'          => 'This describes the field and its properties.',
				),
				1 =>
				array(
					'httpMethod'     => 'PUT',
					'summary'        => 'Update one record by identifier.',
					'nickname'       => 'updateField',
					'responseClass'  => 'Success',
					'parameters'     =>
					array(
						0 =>
						array(
							'name'          => 'table_name',
							'description'   => 'Name of the table to perform operations on.',
							'allowMultiple' => false,
							'dataType'      => 'string',
							'paramType'     => 'path',
							'required'      => true,
						),
						1 =>
						array(
							'name'          => 'field_name',
							'description'   => 'Name of the field to perform operations on.',
							'allowMultiple' => false,
							'dataType'      => 'string',
							'paramType'     => 'path',
							'required'      => true,
						),
						2 =>
						array(
							'name'          => 'field_props',
							'description'   => 'Array of field properties.',
							'allowMultiple' => false,
							'dataType'      => 'FieldSchema',
							'paramType'     => 'body',
							'required'      => true,
						),
					),
					'errorResponses' =>
					array(
						0 =>
						array(
							'reason' => 'Bad Request - Request does not have a valid format, all required parameters, etc.',
							'code'   => 400,
						),
						1 =>
						array(
							'reason' => 'Unauthorized Access - No currently valid session available.',
							'code'   => 401,
						),
						2 =>
						array(
							'reason' => 'System Error - Specific reason is included in the error message.',
							'code'   => 500,
						),
					),
					'notes'          => 'Post data should be an array of field properties for the given field.',
				),
				2 =>
				array(
					'httpMethod'     => 'DELETE',
					'summary'        => 'DELETE (aka DROP) the given field FROM the given TABLE.',
					'nickname'       => 'deleteField',
					'responseClass'  => 'Success',
					'parameters'     =>
					array(
						0 =>
						array(
							'name'          => 'table_name',
							'description'   => 'Name of the table to perform operations on.',
							'allowMultiple' => false,
							'dataType'      => 'string',
							'paramType'     => 'path',
							'required'      => true,
						),
						1 =>
						array(
							'name'          => 'field_name',
							'description'   => 'Name of the field to perform operations on.',
							'allowMultiple' => false,
							'dataType'      => 'string',
							'paramType'     => 'path',
							'required'      => true,
						),
					),
					'errorResponses' =>
					array(
						0 =>
						array(
							'reason' => 'Bad Request - Request does not have a valid format, all required parameters, etc.',
							'code'   => 400,
						),
						1 =>
						array(
							'reason' => 'Unauthorized Access - No currently valid session available.',
							'code'   => 401,
						),
						2 =>
						array(
							'reason' => 'System Error - Specific reason is included in the error message.',
							'code'   => 500,
						),
					),
					'notes'          => 'Careful, this drops the database table field/column and all of its contents.',
				),
			),
			'description' => 'Operations for single field administration.',
		),
	),
	'models'       =>
	array(
		'Resources'     =>
		array(
			'id'         => 'Resources',
			'properties' =>
			array(
				'resource' =>
				array(
					'type'  => 'Array',
					'items' =>
					array(
						'$ref' => 'Resource',
					),
				),
			),
		),
		'Resource'      =>
		array(
			'id'         => 'Resource',
			'properties' =>
			array(
				'name' =>
				array(
					'type' => 'string',
				),
			),
		),
		'TableSchema'   =>
		array(
			'id'         => 'TableSchema',
			'properties' =>
			array(
				'name'        =>
				array(
					'type'        => 'string',
					'description' => 'Identifier/Name for the table.',
				),
				'label'       =>
				array(
					'type'        => 'Array',
					'description' => 'Displayable singular name for the table.',
					'items'       =>
					array(
						'$ref' => 'EmailAddress',
					),
				),
				'plural'      =>
				array(
					'type'        => 'Array',
					'description' => 'Displayable plural name for the table.',
					'items'       =>
					array(
						'$ref' => 'EmailAddress',
					),
				),
				'primary_key' =>
				array(
					'type'        => 'string',
					'description' => 'Field(s), if any, that represent the primary key of each record.',
				),
				'name_field'  =>
				array(
					'type'        => 'string',
					'description' => 'Field(s), if any, that represent the name of each record.',
				),
				'field'       =>
				array(
					'type'        => 'Array',
					'description' => 'An array of available fields in each record.',
					'items'       =>
					array(
						'$ref' => 'FieldSchema',
					),
				),
				'related'     =>
				array(
					'type'        => 'Array',
					'description' => 'An array of available relationships to other tables.',
					'items'       =>
					array(
						'$ref' => 'RelatedSchema',
					),
				),
			),
		),
		'Success'       =>
		array(
			'id'         => 'Success',
			'properties' =>
			array(
				'success' =>
				array(
					'type' => 'boolean',
				),
			),
		),
		'Fields'        =>
		array(
			'id'         => 'Fields',
			'properties' =>
			array(
				'field' =>
				array(
					'type'        => 'Array',
					'description' => 'An array of field definitions.',
					'items'       =>
					array(
						'$ref' => 'FieldSchema',
					),
				),
			),
		),
		'EmailAddress'  =>
		array(
			'id'         => 'EmailAddress',
			'properties' =>
			array(
				'name'  =>
				array(
					'type'        => 'string',
					'description' => 'Optional name displayed along with the email address.',
				),
				'email' =>
				array(
					'type'        => 'string',
					'description' => 'Required email address.',
				),
			),
		),
		'FieldSchema'   =>
		array(
			'id'         => 'FieldSchema',
			'properties' =>
			array(
				'name'               =>
				array(
					'type'        => 'string',
					'description' => 'The API name of the field.',
				),
				'label'              =>
				array(
					'type'        => 'string',
					'description' => 'The displayable label for the field.',
				),
				'type'               =>
				array(
					'type'        => 'string',
					'description' => 'The DSP abstract data type for this field.',
				),
				'db_type'            =>
				array(
					'type'        => 'string',
					'description' => 'The native database type used for this field.',
				),
				'length'             =>
				array(
					'type'        => 'int',
					'description' => 'The maximum length allowed (in characters for string, displayed for numbers).',
				),
				'precision'          =>
				array(
					'type'        => 'int',
					'description' => 'Total number of places for numbers.',
				),
				'scale'              =>
				array(
					'type'        => 'int',
					'description' => 'Number of decimal places allowed for numbers.',
				),
				'default'            =>
				array(
					'type'        => 'string',
					'description' => 'Default value for this field.',
				),
				'required'           =>
				array(
					'type'        => 'boolean',
					'description' => 'Is a value required for record creation.',
				),
				'allow_null'         =>
				array(
					'type'        => 'boolean',
					'description' => 'Is null allowed as a value.',
				),
				'fixed_length'       =>
				array(
					'type'        => 'boolean',
					'description' => 'Is the length fixed (not variable).',
				),
				'supports_multibyte' =>
				array(
					'type'        => 'boolean',
					'description' => 'Does the data type support multibyte characters.',
				),
				'auto_increment'     =>
				array(
					'type'        => 'boolean',
					'description' => 'Does the integer field value increment upon new record creation.',
				),
				'is_primary_key'     =>
				array(
					'type'        => 'boolean',
					'description' => 'Is this field used as/part of the primary key.',
				),
				'is_foreign_key'     =>
				array(
					'type'        => 'boolean',
					'description' => 'Is this field used as a foreign key.',
				),
				'ref_table'          =>
				array(
					'type'        => 'string',
					'description' => 'For foreign keys, the referenced table name.',
				),
				'ref_fields'         =>
				array(
					'type'        => 'string',
					'description' => 'For foreign keys, the referenced table field name.',
				),
				'validation'         =>
				array(
					'type'        => 'Array',
					'description' => 'validations to be performed on this field.',
					'items'       =>
					array(
						'type' => 'string',
					),
				),
				'values'             =>
				array(
					'type'        => 'Array',
					'description' => 'Selectable string values for picklist validation.',
					'items'       =>
					array(
						'type' => 'string',
					),
				),
			),
		),
		'RelatedSchema' =>
		array(
			'id'         => 'RelatedSchema',
			'properties' =>
			array(
				'name'      =>
				array(
					'type'        => 'string',
					'description' => 'Name of the relationship.',
				),
				'type'      =>
				array(
					'type'        => 'string',
					'description' => 'Relationship type - belongs_to, has_many, many_many.',
				),
				'ref_table' =>
				array(
					'type'        => 'string',
					'description' => 'The table name that is referenced by the relationship.',
				),
				'ref_field' =>
				array(
					'type'        => 'string',
					'description' => 'The field name that is referenced by the relationship.',
				),
				'join'      =>
				array(
					'type'        => 'string',
					'description' => 'The intermediate joining table used for many_many relationships.',
				),
				'field'     =>
				array(
					'type'        => 'string',
					'description' => 'The current table field that is used in the relationship.',
				),
			),
		),
	),
);