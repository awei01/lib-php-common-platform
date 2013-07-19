<?php
/**
 * This file is part of the DreamFactory Services Platform(tm) (DSP)
 *
 * DreamFactory Services Platform(tm) <http://github.com/dreamfactorysoftware/dsp-core>
 * Copyright 2012-2013 DreamFactory Software, Inc. <support@dreamfactory.com>
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
namespace DreamFactory\Platform\Services;

use DreamFactory\Platform\Enums\PermissionMap;
use DreamFactory\Platform\Exceptions\RestException;
use Kisma\Core\Utility\Option;
use Kisma\Core\Utility\Curl;

/**
 * RemoteWebSvc
 * A service to handle remote web services accessed through the REST API.
 */
class RemoteWebSvc extends BasePlatformRestService
{
	//*************************************************************************
	//* Members
	//*************************************************************************

	/**
	 * @var string
	 */
	protected $_baseUrl;
	/**
	 * @var array
	 */
	protected $_credentials;
	/**
	 * @var array
	 */
	protected $_headers;
	/**
	 * @var array
	 */
	protected $_parameters;
	/**
	 * @var string
	 */
	protected $_query;
	/**
	 * @var string
	 */
	protected $_url;
	/**
	 * @var array
	 */
	protected $_curlOptions = array(
		CURLOPT_RETURNTRANSFER => false,
		CURLOPT_HEADER         => false,
	);

	//*************************************************************************
	//* Methods
	//*************************************************************************

	/**
	 * Create a new RemoteWebSvc
	 *
	 * @param array $config configuration array
	 *
	 * @throws \InvalidArgumentException
	 */
	public function __construct( $config )
	{
		parent::__construct( $config );

		$this->setAutoDispatch( false );

		// Validate url setup
		if ( empty( $this->_baseUrl ) )
		{
			throw new \InvalidArgumentException( 'Remote Web Service base url can not be empty.' );
		}
	}

	/**
	 * @param $action
	 *
	 * @return string
	 */
	protected function buildParameterString( $action )
	{
		$param_str = '';

		foreach ( $_REQUEST as $key => $value )
		{
			switch ( strtolower( $key ) )
			{
				case '_': // timestamp added by jquery
				case 'app_name': // app_name required by our api
				case 'method': // method option for our api
				case 'format':
					break;
				default:
					$param_str .= ( !empty( $param_str ) ) ? '&' : '';
					$param_str .= urlencode( $key );
					$param_str .= ( empty( $value ) ) ? '' : '=' . urlencode( $value );
					break;
			}
		}

		if ( !empty( $this->_parameters ) )
		{
			foreach ( $this->_parameters as $param )
			{
				$paramAction = Option::get( $param, 'action' );
				if ( !empty( $paramAction ) && ( 0 !== strcasecmp( 'all', $paramAction ) ) )
				{
					if ( 0 !== strcasecmp( $action, $paramAction ) )
					{
						continue;
					}
				}
				$key = Option::get( $param, 'name' );
				$value = Option::get( $param, 'value' );
				$param_str .= ( !empty( $param_str ) ) ? '&' : '';
				$param_str .= urlencode( $key );
				$param_str .= ( empty( $value ) ) ? '' : '=' . urlencode( $value );
			}
		}

		return $param_str;
	}

	/**
	 * @param string $action
	 * @param array  $options
	 *
	 * @return array
	 */
	protected function addHeaders( $action, $options = array() )
	{
		if ( !empty( $this->_headers ) )
		{
			foreach ( $this->_headers as $header )
			{
				$headerAction = Option::get( $header, 'action' );

				if ( !empty( $headerAction ) && ( 0 !== strcasecmp( 'all', $headerAction ) ) )
				{
					if ( 0 !== strcasecmp( $action, $headerAction ) )
					{
						continue;
					}
				}

				$key = Option::get( $header, 'name' );
				$value = Option::get( $header, 'value' );

				$options[CURLOPT_HTTPHEADER] = !isset( $options[CURLOPT_HTTPHEADER] ) ? array( $key . ': ' . $value ) : $options[CURLOPT_HTTPHEADER][] = $key . ': ' . $value;
			}
		}

		return $options;
	}

	protected function _preProcess()
	{
		parent::_preProcess();

		$this->_query = $this->buildParameterString( $this->_action );
		$this->_url = $this->_baseUrl . $this->_resourcePath . '?' . $this->_query;

		//	set additional headers
		$this->_curlOptions = $this->addHeaders( $this->_action, $this->_curlOptions );

		$this->checkPermission( PermissionMap::fromMethod( $this->_action ), $this->_apiName );
	}

	/**
	 * @throws \DreamFactory\Platform\Exceptions\RestException
	 * @return bool
	 */
	protected function _handleResource()
	{
		if ( false === ( $_results = Curl::request( $this->_action, $this->_url, array(), $this->_curlOptions ) ) )
		{
			$_error = Curl::getError();
			throw new RestException( Option::get( $_error, 'code', 500 ), Option::get( $_error, 'message' ) );
		}
	}

	/**
	 * @param string $baseUrl
	 *
	 * @return RemoteWebSvc
	 */
	public function setBaseUrl( $baseUrl )
	{
		$this->_baseUrl = $baseUrl;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getBaseUrl()
	{
		return $this->_baseUrl;
	}

	/**
	 * @param array $credentials
	 *
	 * @return RemoteWebSvc
	 */
	public function setCredentials( $credentials )
	{
		$this->_credentials = $credentials;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getCredentials()
	{
		return $this->_credentials;
	}

	/**
	 * @param array $headers
	 *
	 * @return RemoteWebSvc
	 */
	public function setHeaders( $headers )
	{
		$this->_headers = $headers;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getHeaders()
	{
		return $this->_headers;
	}

	/**
	 * @param array $parameters
	 *
	 * @return RemoteWebSvc
	 */
	public function setParameters( $parameters )
	{
		$this->_parameters = $parameters;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getParameters()
	{
		return $this->_parameters;
	}
}