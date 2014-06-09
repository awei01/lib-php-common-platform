<?php
/**
 * Copyright 2012-2014 DreamFactory Software, Inc. <support@dreamfactory.com>
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

use DreamFactory\Common\Utility\DataFormat;
use DreamFactory\Platform\Enums\DataFormats;
use DreamFactory\Platform\Enums\PlatformServiceTypes;
use DreamFactory\Platform\Exceptions\NotImplementedException;
use DreamFactory\Platform\Interfaces\PlatformServiceLike;
use DreamFactory\Platform\Resources\User\Session;
use DreamFactory\Platform\Utility\RestData;
use DreamFactory\Platform\Utility\ServiceHandler;
use DreamFactory\Yii\Utility\Pii;
use Kisma\Core\Interfaces\ConsumerLike;
use Kisma\Core\Seed;
use Kisma\Core\Utility\Inflector;
use Kisma\Core\Utility\Log;
use Kisma\Core\Utility\Option;

/**
 * BasePlatformService
 * The base class for all DSP services
 */
abstract class BasePlatformService extends Seed implements PlatformServiceLike, ConsumerLike
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * Default record wrapping tag for single or array of records
     */
    const RECORD_WRAPPER = 'record';

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
     * @var int Designated type ID of this service
     */
    protected $_typeId;
    /**
     * @var boolean Is this service activated for use?
     */
    protected $_isActive = false;
    /**
     * @var bool Indicates that this object is or is not to be treated as a resource
     */
    protected $_isResource = false;
    /**
     * @var string Native format of output of service, null for php, otherwise json, xml, etc.
     */
    protected $_nativeFormat = null;
    /**
     * @var mixed The local service client for proxying
     */
    protected $_proxyClient;
    /**
     * @var int current user ID
     */
    protected $_currentUserId;
    /**
     * @var bool Used to indicate whether or not this request has come from within, like a script
     */
    protected $_inlineRequest = false;
    /**
     * @var array The data that came in on the request
     */
    protected $_requestPayload = null;
    /**
     * @var array
     */
    protected $_requestData = null;
    /**
     * @var int The inner payload response format, used for table formatting, etc.
     */
    protected $_responseFormat = DataFormats::NATIVE;
    /**
     * @var string Default output format, either null (native), 'json' or 'xml'.
     * NOTE: Output format is different from RESPONSE format (inner payload format vs. envelope)
     */
    protected $_outputFormat = DataFormats::JSON;

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
        if ( null === Option::get( $settings, 'api_name', $this->_apiName ) )
        {
            if ( null !== ( $_name = Option::get( $settings, 'name', $this->_name ) ) )
            {
                $this->_apiName = Inflector::neutralize( $_name );
            }
        }

        if ( empty( $this->_apiName ) )
        {
            throw new \InvalidArgumentException( '"api_name" cannot be empty.' );
        }

        if ( null === $this->_typeId )
        {
            if ( false !== ( $_typeId = $this->_determineTypeId() ) )
            {
                $this->_typeId = $_typeId;

                //	Set type from ID
                if ( null === $this->_type )
                {
                    $this->_type = PlatformServiceTypes::nameOf( $this->_typeId );
                }
            }
        }

        if ( empty( $this->_type ) || null === $this->_typeId )
        {
            throw new \InvalidArgumentException( '"type" and/or "type_id" cannot be empty.' );
        }

        //	Set description from name...
        if ( empty( $this->_description ) )
        {
            $this->_description = $this->_name;
        }

        //	Get the current user ID if one...
        $this->_currentUserId = $this->_currentUserId ? : Session::getCurrentUserId();
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        //	Save myself!
        ServiceHandler::cacheService( $this->_apiName, $this );

        parent::__destruct();
    }

    /**
     * @param bool $wrapContent If true, inbound request data is wrapped in an array with static::RECORD_WRAPPER as key
     *
     * @return $this
     */
    protected function _detectResourceMembers( $wrapContent = false )
    {
        $_payload = RestData::getPostedData( true, true );

        // import from csv, etc doesn't include a wrapper, so wrap it
        if ( ( $wrapContent && !empty( $_payload ) ) || DataFormat::isArrayNumeric( $_payload ) )
        {
            $_payload = array( static::RECORD_WRAPPER => $_payload );
        }

        // MERGE URL parameters with posted data, posted data takes precedence
        $_payload = array_merge( $_REQUEST, $_payload );

        $this->_requestData = Option::clean( $_payload );

        return $this;
    }

    /**
     * Checks the payload for $key, defaulting to $alternateKey (if given) if empty
     *
     * @param array  $payload
     * @param string $key
     * @param string $alternateKey
     *
     * @return bool
     */
    protected function _checkPayloadParameter( &$payload, $key, $alternateKey = null )
    {
        $_value = Option::get(
            $payload,
            $key,
            $alternateKey ? Option::get( $payload, $alternateKey ) : null
        );

        if ( !empty( $_value ) )
        {
            $payload[ $key ] = $_value;

            return true;
        }

        return false;
    }

    /**
     * Given an old string-based TYPE, determine new integer identifier
     *
     * @param string $type
     *
     * @return bool|int
     */
    protected function _determineTypeId( $type = null )
    {
        $_type = str_replace( ' ', '_', trim( strtoupper( $type ? : $this->_type ) ) );

        if ( 'LOCAL_EMAIL_SERVICE' == $_type )
        {
            $_type = 'EMAIL_SERVICE';
        }

        try
        {
            //	Throws exception if type not defined...
            return PlatformServiceTypes::defines( $_type, true );
        }
        catch ( \InvalidArgumentException $_ex )
        {
            if ( empty( $_type ) )
            {
                Log::notice( ' * Empty "type", assuming this is a system resource( type_id == 0 )' );

                return PlatformServiceTypes::SYSTEM_SERVICE;
            }

            Log::error( ' * Unknown service type ID request for "' . $type . '" . ' );

            return false;
        }
    }

    /**
     * Determine the app_name/API key of this request
     *
     * @return mixed
     */
    protected function _detectAppName()
    {
        if ( !SystemManager::getCurrentAppName() )
        {
            $_request = Pii::requestObject();

            // 	Determine application if any
            $_appName = $_request->get(
                'app_name',
                //	No app_name, look for headers...
                Option::server(
                    'HTTP_X_DREAMFACTORY_APPLICATION_NAME',
                    Option::server( 'HTTP_X_APPLICATION_NAME' )
                ),
                FILTER_SANITIZE_STRING
            );

            //	Still empty?
            if ( empty( $_appName ) )
            {
                //	We give portal requests a break, as well as inbound OAuth redirects
                if ( false !== stripos( Option::server( 'REQUEST_URI' ), '/rest/portal', 0 ) )
                {
                    $_appName = 'portal';
                }
                elseif ( isset( $_REQUEST, $_REQUEST['code'], $_REQUEST['state'], $_REQUEST['oasys'] ) )
                {
                    $_appName = 'auth_redirect';
                }
                else
                {
                    RestResponse::sendErrors( new BadRequestException( 'No application name header or parameter value in request.' ) );

                    return false;
                }
            }

            // assign to global for system usage
            SystemManager::setCurrentAppName( $_appName );
        }

        return true;
    }

    /**
     * @param string $output_format
     */
    protected function _detectResponseMembers( $output_format = null )
    {
        //	Determine output format, inner and outer formatting if necessary
        $this->_outputFormat = RestResponse::detectResponseFormat( $output_format, $this->_responseFormat );

        //	Determine if output as file is enabled
        $_file = FilterInput::request( 'file', null, FILTER_SANITIZE_STRING );

        if ( !empty( $_file ) )
        {
            if ( DataFormat::boolval( $_file ) )
            {
                $_file = $this->getApiName();
                $_file .= '.' . $this->_outputFormat;
            }

            $this->_outputAsFile = $_file;
        }
    }

    /**
     * @param string $request
     * @param string $component
     *
     * @throws NotImplementedException
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
     * @param boolean $isActive
     *
     * @return BasePlatformService
     */
    public function setIsActive( $isActive = false )
    {
        $this->_isActive = $isActive;

        return $this;
    }

    /**
     * @return boolean
     */
    public function getIsActive()
    {
        return $this->_isActive;
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

    /**
     * @param int $typeId
     *
     * @return BasePlatformService
     */
    public function setTypeId( $typeId )
    {
        $this->_typeId = $typeId;

        return $this;
    }

    /**
     * @return int
     */
    public function getTypeId()
    {
        return $this->_typeId;
    }

    /**
     * @return int
     */
    public function getUserId()
    {
        return $this->_currentUserId;
    }

    /**
     * @return boolean
     */
    public function isResource()
    {
        return $this->_isResource;
    }

    /**
     * @param boolean $isResource
     *
     * @return BasePlatformService
     */
    public function setIsResource( $isResource )
    {
        $this->_isResource = $isResource;

        return $this;
    }

    /**
     * @return boolean
     */
    public function isInlineRequest()
    {
        return $this->_inlineRequest;
    }

    /**
     * @param boolean $inlineRequest
     *
     * @return BasePlatformService
     */
    public function setInlineRequest( $inlineRequest )
    {
        $this->_inlineRequest = $inlineRequest;

        return $this;
    }

    /**
     * @param array $requestData
     *
     * @return BasePlatformService
     */
    public function setRequestData( $requestData )
    {
        $this->_requestData = $requestData;

        return $this;
    }

    /**
     * @return array
     */
    public function getRequestData()
    {
        return $this->_requestData;
    }

    /**
     * @return string
     */
    public function getOutputFormat()
    {
        return $this->_outputFormat;
    }

    /**
     * @param string $outputFormat
     *
     * @return BasePlatformService
     */
    public function setOutputFormat( $outputFormat )
    {
        $this->_outputFormat = $outputFormat;

        return $this;
    }

    /**
     * @return int
     */
    public function getResponseFormat()
    {
        return $this->_responseFormat;
    }

    /**
     * @param int $responseFormat
     *
     * @return BasePlatformService
     */
    public function setResponseFormat( $responseFormat )
    {
        $this->_responseFormat = $responseFormat;

        return $this;
    }

    /**
     * @param array $requestPayload
     *
     * @return BasePlatformRestService
     */
    public function setRequestPayload( $requestPayload )
    {
        $this->_requestPayload = $requestPayload;

        return $this;
    }

    /**
     * @return array
     */
    public function getRequestPayload()
    {
        return $this->_requestPayload;
    }

}
