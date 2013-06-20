<?php
/**
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

use DreamFactory\Common\Interfaces\PlatformServiceLike;
use Kisma\Core\Exceptions\NotImplementedException;
use Kisma\Core\Interfaces\ConsumerLike;
use Kisma\Core\Seed;
use Kisma\Core\Utility\Option;
use DreamFactory\Common\Utility\DataFormat;

/**
 * BasePlatformService
 * The base class for all DSP services
 */
abstract class BasePlatformService extends Seed implements PlatformServiceLike, ConsumerLike
{
	//*************************************************************************
	//* Members
	//*************************************************************************

	/**
	 * @var string Name to be used in an API
	 */
	protected $_apiName;
	/**
	 * @var string Description of this service
	 */
	protected $_description;
	/**
	 * @var string Designated type of this service
	 */
	protected $_type;
	/**
	 * @var boolean Is this service activated for use?
	 */
	protected $_enabled = false;
	/**
	 * @var string Native format of output of service, null for php, otherwise json, xml, etc.
	 */
	protected $_nativeFormat = null;
	/**
	 * @var mixed The local service client for proxying
	 */
	protected $_proxyClient;

	//*************************************************************************
	//* Methods
	//*************************************************************************

	/**
	 * Create a new service
	 *
	 * @param array $settings configuration array
	 *
	 * @throws \InvalidArgumentException
	 * @throws \Exception
	 */
	public function __construct( $settings = array() )
	{
		parent::__construct( $settings );

		// Validate basic settings
		if ( empty( $this->_apiName ) )
		{
			throw new \InvalidArgumentException( 'Service name can not be empty.' );
		}

		if ( empty( $this->_type ) )
		{
			throw new \InvalidArgumentException( 'Service type can not be empty.' );
		}
	}

	/**
	 * @param string $request
	 * @param string $component
	 *
	 * @throws \Kisma\Core\Exceptions\NotImplementedException
	 */
	protected function _checkPermission( $request, $component )
	{
		throw new NotImplementedException();
	}

	/**
	 * @param string $apiName
	 *
	 * @return BasePlatformService
	 */
	public function setApiName( $apiName )
	{
		$this->_apiName = $apiName;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getApiName()
	{
		return $this->_apiName;
	}

	/**
	 * @param string $description
	 *
	 * @return BasePlatformService
	 */
	public function setDescription( $description )
	{
		$this->_description = $description;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getDescription()
	{
		return $this->_description;
	}

	/**
	 * @param boolean $enabled
	 *
	 * @return BasePlatformService
	 */
	public function setEnabled( $enabled = false )
	{
		$this->_enabled = $enabled;

		return $this;
	}

	/**
	 * @return boolean
	 */
	public function getEnabled()
	{
		return $this->_enabled;
	}

	/**
	 * @param string $nativeFormat
	 *
	 * @return BasePlatformService
	 */
	public function setNativeFormat( $nativeFormat )
	{
		$this->_nativeFormat = $nativeFormat;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getNativeFormat()
	{
		return $this->_nativeFormat;
	}

	/**
	 * @param string $type
	 *
	 * @return BasePlatformService
	 */
	public function setType( $type )
	{
		$this->_type = $type;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getType()
	{
		return $this->_type;
	}

	/**
	 * @param mixed $proxyClient
	 *
	 * @return BasePlatformService
	 */
	public function setProxyClient( $proxyClient )
	{
		$this->_proxyClient = $proxyClient;

		return $this;
	}

	/**
	 * @return mixed
	 */
	public function getProxyClient()
	{
		return $this->_proxyClient;
	}

}