<?php
/**
 * Copyright 2013 DreamFactory Software, Inc. <support@dreamfactory.com>
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
namespace DreamFactory\Platform\Services\Portal;

use DreamFactory\Platform\Enums\PlatformServiceTypes;
use DreamFactory\Platform\Services\BaseSystemRestService;
use DreamFactory\Platform\Services\Portal\OAuth\Enums\OAuthTokenTypes;
use DreamFactory\Platform\Services\Portal\OAuth\Enums\OAuthGrantTypes;
use DreamFactory\Platform\Services\Portal\OAuth\Enums\OAuthTypes;
use DreamFactory\Platform\Services\Portal\OAuth\Exceptions\AuthenticationException;
use DreamFactory\Platform\Services\Portal\OAuth\GrantTypes as GrantTypes;
use DreamFactory\Platform\Services\Portal\OAuth\Interfaces\OAuthServiceLike;
use DreamFactory\Platform\Services\BasePlatformService;
use Kisma\Core\Exceptions\NotImplementedException;
use Kisma\Core\Interfaces\ConsumerLike;
use Kisma\Core\Interfaces\HttpMethod;
use Kisma\Core\Utility\Curl;
use Kisma\Core\Utility\Log;
use Kisma\Core\Utility\FilterInput;
use Kisma\Core\Utility\Inflector;
use Kisma\Core\Utility\Option;

/**
 * BasePortalClient
 * An portal client base that knows how to talk OAuth2
 *
 * Subclasses must implement _loadToken and _saveToken methods
 */
abstract class BasePortalClient extends BaseSystemRestService implements OAuthServiceLike, ConsumerLike, HttpMethod
{
	//**************************************************************************
	//* Constants
	//**************************************************************************

	/**
	 * @var int
	 */
	const ApplicationContent = 0;
	/**
	 * @var int
	 */
	const MultipartContent = 1;

	//**************************************************************************
	//* Members
	//**************************************************************************

	/**
	 * @var string The client id, or public key
	 */
	protected $_clientId = null;
	/**
	 * @var string The client secret, or private key
	 */
	protected $_clientSecret = null;
	/**
	 * @var string The client certificate file
	 */
	protected $_certificateFile = null;
	/**
	 * @var int The default OAuth authentication type
	 */
	protected $_authType = OAuthTypes::URI;
	/**
	 * @var string The default grant type is 'authorization_code'
	 */
	protected $_grantType = OAuthGrantTypes::AUTHORIZATION_CODE;
	/**
	 * @var string The application inbound redirect URI
	 */
	protected $_redirectUri = null;
	/**
	 * @var string The OAuth scope
	 */
	protected $_scope = null;
	/**
	 * @var string The OAuth access token parameter name for the requests
	 */
	protected $_accessTokenParamName = 'access_token';
	/**
	 * @var string The value to put in the "Authorization" header (i.e. Authorization: OAuth OAUTH-TOKEN). This can vary from service to service
	 */
	protected $_authHeaderName = 'OAuth';
	/**
	 * @var string The service authorization URL
	 */
	protected $_authorizeUrl = null;
	/**
	 * @var string The base URL for this service (i.e. https://oauth.server.com)
	 */
	protected $_serviceEndpoint = null;
	/**
	 * @var string The base URL for authenticated resource requests, if different from service URL (i.e. Github's are different)
	 */
	protected $_resourceEndpoint = null;
	/**
	 * @var string The endpoint for authorization
	 */
	protected $_authorizeEndpoint = '/oauth/authorize';
	/**
	 * @var string The endpoint for granting tokens
	 */
	protected $_tokenEndpoint = '/oauth/access_token';
	/**
	 * @var string The granted OAuth access token for the service
	 */
	protected $_accessToken = null;
	/**
	 * @var string The type of access token desired
	 */
	protected $_accessTokenType = OAuthTokenTypes::URI;
	/**
	 * @var string The access token secret key
	 */
	protected $_accessTokenSecret = null;
	/**
	 * @var string The hash algorithm used for signing requests
	 */
	protected $_hashAlgorithm = null;
	/**
	 * @var string The OAuth refresh token for the service
	 */
	protected $_refreshToken = null;
	/**
	 * @var string An optional user agent to send
	 */
	protected $_userAgent = null;
	/**
	 * @var string
	 */
	protected $_redirectProxyUrl = null;

	//**************************************************************************
	//* Methods
	//**************************************************************************

	/**
	 * @param array|\stdClass $options
	 *
	 * @throws \RuntimeException
	 * @throws \InvalidArgumentException
	 * @return \DreamFactory\Platform\Services\Portal\BasePortalClient
	 */
	public function __construct( $options = array() )
	{
		if ( !extension_loaded( 'curl' ) )
		{
			throw new \RuntimeException( 'The "php-curl" extension is required to use this class.' );
		}

		//	Auto-set API name to class name tagged...
		if ( empty( $this->_apiName ) && null === Option::get( $options, 'api_name' ) )
		{
			$this->_apiName = Inflector::neutralize( get_class( $this ) );
		}

		if ( empty( $this->_type ) && null === Option::get( $options, 'type' ) )
		{
			$this->_type = PlatformServiceTypes::LOCAL_PORTAL_SERVICE;
		}

		parent::__construct( $options );

		if ( !empty( $this->_certificateFile ) && ( !is_file( $this->_certificateFile ) || !is_readable( $this->_certificateFile ) ) )
		{
			throw new \InvalidArgumentException( 'The specified certificate file "' . $this->_certificateFile . '" was not found' );
		}

		//	Load any token we may have...
		$this->_loadToken();
	}

	/**
	 * Check if we are authorized or not...
	 *
	 * @param bool  $startFlow If true, and we are not authorized, checkAuthenticationProgress() is called.
	 * @param array $payload   Payload to pass along
	 *
	 * @return bool|string
	 */
	public function authorized( $startFlow = false, $payload = array() )
	{
		if ( empty( $this->_accessToken ) )
		{
			if ( false !== $startFlow )
			{
				return $this->checkAuthenticationProgress( true, $payload );
			}

			return false;
		}

		return true;
	}

	/**
	 * Loads a token from session
	 *
	 * @throws \Kisma\Core\Exceptions\NotImplementedException
	 * @return bool|mixed
	 */
	protected function _loadToken()
	{
		if ( null !== ( $_token = \Kisma::get( $this->getClientId() . '_access_token' ) ) )
		{
			$this->setAccessToken( $_token );

			return true;
		}

		return false;
	}

	/**
	 * Saves a token to session
	 *
	 * @throws \Kisma\Core\Exceptions\NotImplementedException
	 * @return bool
	 */
	protected function _saveToken()
	{
		\Kisma::set( $this->getClientId() . '_access_token', $this->getAccessToken() );
	}

	/**
	 * Validate that the required parameters are supplied for the type of grant selected
	 *
	 * @param string          $grantType
	 * @param array|\stdClass $payload
	 *
	 * @return array|\stdClass|void
	 * @throws \InvalidArgumentException
	 */
	protected function _validateGrantType( $grantType, $payload )
	{
		switch ( $grantType )
		{
			case OAuthGrantTypes::AUTHORIZATION_CODE:
				return GrantTypes\AuthorizationCode::validatePayload( $payload );

			case OAuthGrantTypes::PASSWORD:
				return GrantTypes\Password::validatePayload( $payload );

			case OAuthGrantTypes::CLIENT_CREDENTIALS:
				return GrantTypes\ClientCredentials::validatePayload( $payload );

			case OAuthGrantTypes::REFRESH_TOKEN:
				return GrantTypes\RefreshToken::validatePayload( $payload );

			default:
				throw new \InvalidArgumentException( 'Invalid grant type "' . $grantType . '" specified.' );
		}
	}

	/**
	 * Checks the progress of any in-flight OAuth requests
	 *
	 * @param bool  $redirect If TRUE, redirect to the authorization url instead of returning it.
	 * @param array $payload  Additional parameters to send through to authentication service
	 *
	 * @return string
	 */
	public function checkAuthenticationProgress( $redirect = true, $payload = array() )
	{
		$_code = FilterInput::get( INPUT_GET, 'code' );

		//	No code is present, request one
		if ( empty( $_code ) )
		{
			$_payload = array_merge(
				Option::clean( $payload ),
				array(
					 'redirect_uri' => $this->_redirectUri,
					 'client_id'    => $this->_clientId,
				)
			);

			$_redirectUrl = $this->_getAuthorizationUrl( $_payload );

			if ( true !== $redirect )
			{
				return $_redirectUrl;
			}

			if ( !empty( $this->_redirectProxyUrl ) )
			{
				$_redirectUrl = $this->_redirectProxyUrl . '?redirect=' . urlencode( $_redirectUrl );
			}

			header( 'Location: ' . $_redirectUrl );
			exit();
		}

		//	Got a code, now get a token
		$_token = $this->requestAccessToken(
			OAuthGrantTypes::AUTHORIZATION_CODE,
			array_merge(
				Option::clean( $payload ),
				array(
					 'code'         => $_code,
					 'redirect_uri' => $this->_redirectUri
				)
			)
		);

		$_info = null;

		if ( null !== ( $_result = Option::get( $_token, 'result' ) ) )
		{
			parse_str( $_token['result'], $_info );
		}

		if ( null !== ( $_error = Option::get( $_info, 'error' ) ) )
		{
			//	Error
			Log::error( 'Error returned from oauth token request: ' . print_r( $_info, true ) );

			return false;
		}

		$this->_accessToken = Option::get( $_info, 'access_token' );

		return true;
	}

	/**
	 * @param string $grantType
	 * @param array  $payload
	 *
	 * @return mixed
	 * @throws \InvalidArgumentException
	 */
	public function requestAccessToken( $grantType = OAuthGrantTypes::AUTHORIZATION_CODE, array $payload = array() )
	{
		$_payload = $this->_validateGrantType( $grantType, $payload );
		$_payload['grant_type'] = $grantType;

		$_headers = array();

		switch ( $this->_authType )
		{
			case OAuthTypes::URI:
			case OAuthTypes::FORM:
				$_payload['client_id'] = $this->_clientId;
				$_payload['client_secret'] = $this->_clientSecret;
				break;

			case OAuthTypes::BASIC:
				$_payload['client_id'] = $this->_clientId;
				$_headers[] = 'Authorization: Basic ' . base64_encode( $this->_clientId . ':' . $this->_clientSecret );
				break;

			default:
				throw new \InvalidArgumentException( 'The auth type "' . $this->_authType . '" is invalid.' );
		}

		return $this->_makeRequest( $this->getServiceEndpoint( $this->_tokenEndpoint ), $_payload, static::Post, $_headers, static::ApplicationContent );
	}

	/**
	 * Fetch a protected resource
	 *
	 * @param string $resource
	 * @param array  $payload
	 * @param string $method
	 * @param array  $headers
	 * @param int    $contentType
	 *
	 * @throws \InvalidArgumentException
	 * @return array
	 */
	public function fetch( $resource, $payload = array(), $method = self::Get, array $headers = array(), $contentType = self::MultipartContent )
	{
		//	Use the resource url if provided...
		$_url = $this->getResourceEndpoint( $resource );

		if ( $this->_accessToken )
		{
			switch ( $this->_accessTokenType )
			{
				case OAuthTokenTypes::URI:
					$payload[$this->_accessTokenParamName] = $this->_accessToken;
					break;

				case OAuthTokenTypes::BEARER:
					$headers[] = 'Authorization: Bearer ' . $this->_accessToken;
					break;

				case OAuthTokenTypes::OAUTH:
					$headers[] = 'Authorization: OAuth ' . $this->_accessToken;
					break;

				case OAuthTokenTypes::MAC:
					$headers[] = 'Authorization: MAC ' . $this->_signRequest( $_url, $payload, $method );
					break;

				default:
					throw new \InvalidArgumentException( 'Unknown access token type.' );
			}
		}

		return $this->_makeRequest( $_url, $payload, $method, $headers, $contentType );
	}

	/**
	 * Construct a link to authorize the application
	 *
	 * @param array $payload
	 *
	 * @return string
	 */
	protected function _getAuthorizationUrl( $payload = array() )
	{
		$_payload = array_merge(
			array(
				 'response_type' => 'code',
				 'client_id'     => $this->_clientId,
				 'redirect_uri'  => $this->_redirectUri,
				 'scope'         => is_array( $this->_scope ) ? implode( ',', $this->_scope ) : $this->_scope,
			),
			Option::clean( $payload )
		);

		return $this->_authorizeUrl = $this->getServiceEndpoint( $this->_authorizeEndpoint ) . '?' . http_build_query( $_payload, null, '&' );
	}

	/**
	 * Generate the MAC signature
	 *
	 * @param string $url         Called URL
	 * @param array  $payload     Parameters
	 * @param string $method      Http Method
	 *
	 * @throws \Kisma\Core\Exceptions\NotImplementedException
	 * @return string
	 */
	protected function _signRequest( $url, $payload, $method )
	{
		throw new NotImplementedException( 'This type of authorization is not not implemented.' );
	}

	/**
	 * Execute a request
	 *
	 * @param string $url
	 * @param mixed  $payload
	 * @param string $method
	 * @param array  $headers Array of HTTP headers to send in array( 'header: value', 'header: value', ... ) format
	 * @param int    $contentType
	 *
	 * @throws \DreamFactory\Platform\Services\Portal\OAuth\Exceptions\AuthenticationException
	 * @internal param array $_headers HTTP Headers
	 * @return array
	 */
	protected function _makeRequest( $url, $payload = array(), $method = self::Get, array $headers = null, $contentType = self::MultipartContent )
	{
		$headers = Option::clean( $headers );

		if ( !empty( $this->_userAgent ) )
		{
			$headers[] = 'User-Agent: ' . $this->_userAgent;
		}

		$_curlOptions = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_HTTPHEADER     => $headers,
		);

		if ( static::Get == $method && false === strpos( $url, '?' ) && !empty( $payload ) )
		{
			$url .= '?' . ( is_array( $payload ) ? http_build_query( $payload, null, '&' ) : $payload );
			$payload = array();
		}

		if ( !empty( $this->_certificateFile ) )
		{
			$_curlOptions[CURLOPT_SSL_VERIFYPEER] = true;
			$_curlOptions[CURLOPT_SSL_VERIFYHOST] = 2;
			$_curlOptions[CURLOPT_CAINFO] = $this->_certificateFile;
		}

		if ( false === ( $_result = Curl::request( $method, $url, $payload, $_curlOptions ) ) )
		{
			throw new AuthenticationException( Curl::getErrorAsString() );
		}

		return array(
			'result'       => $_result,
			'code'         => Curl::getLastHttpCode(),
			'content_type' => Curl::getInfo( 'content_type' ),
		);
	}

	/**
	 * Given a path, build a full url
	 *
	 * @param string|null $path
	 *
	 * @return string
	 */
	public function getServiceEndpoint( $path = null )
	{
		return rtrim( $this->_serviceEndpoint, '/ ' ) . '/' . ltrim( $path, '/ ' );
	}

	/**
	 * @param null $resourceEndpoint
	 *
	 * @return BasePortalClient
	 */
	public function setResourceEndpoint( $resourceEndpoint = null )
	{
		$this->_resourceEndpoint = $resourceEndpoint;

		return $this;
	}

	/**
	 * Given a path, build a full url to the resource.
	 * Falls back to the service endpoint if no resource endpoint has been set
	 *
	 * @param string|null $path
	 *
	 * @return string
	 */
	public function getResourceEndpoint( $path = null )
	{
		return rtrim( $this->_resourceEndpoint ? : $this->_serviceEndpoint, '/ ' ) . '/' . ltrim( $path, '/ ' );
	}

	/**
	 * @param string $accessToken
	 *
	 * @return BasePortalClient
	 */
	public function setAccessToken( $accessToken )
	{
		$this->_accessToken = $accessToken;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getAccessToken()
	{
		return $this->_accessToken;
	}

	/**
	 * @param string $accessTokenParamName
	 *
	 * @return BasePortalClient
	 */
	public function setAccessTokenParamName( $accessTokenParamName )
	{
		$this->_accessTokenParamName = $accessTokenParamName;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getAccessTokenParamName()
	{
		return $this->_accessTokenParamName;
	}

	/**
	 * @param string $accessTokenSecret
	 *
	 * @return BasePortalClient
	 */
	public function setAccessTokenSecret( $accessTokenSecret )
	{
		$this->_accessTokenSecret = $accessTokenSecret;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getAccessTokenSecret()
	{
		return $this->_accessTokenSecret;
	}

	/**
	 * Set the access token type
	 *
	 * @param int    $accessTokenType Access token type
	 * @param string $secret          The secret key used to encrypt the MAC header
	 * @param string $algorithm       Algorithm used to encrypt the signature
	 *
	 * @return $this
	 * @return BasePortalClient
	 */
	public function setAccessTokenType( $accessTokenType, $secret = null, $algorithm = null )
	{
		$this->_accessTokenType = $accessTokenType;
		$this->_accessTokenSecret = $secret;
		$this->_hashAlgorithm = $algorithm;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getAccessTokenType()
	{
		return $this->_accessTokenType;
	}

	/**
	 * @param int $authType
	 *
	 * @return BasePortalClient
	 */
	public function setAuthType( $authType )
	{
		$this->_authType = $authType;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getAuthType()
	{
		return $this->_authType;
	}

	/**
	 * @param string $authorizeEndpoint
	 *
	 * @return BasePortalClient
	 */
	public function setAuthorizeEndpoint( $authorizeEndpoint )
	{
		$this->_authorizeEndpoint = $authorizeEndpoint;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getAuthorizeEndpoint()
	{
		return $this->_authorizeEndpoint;
	}

	/**
	 * @param string $authorizeUrl
	 *
	 * @return BasePortalClient
	 */
	public function setAuthorizeUrl( $authorizeUrl )
	{
		$this->_authorizeUrl = $authorizeUrl;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getAuthorizeUrl()
	{
		return $this->_authorizeUrl;
	}

	/**
	 * @param string $certificateFile
	 *
	 * @return BasePortalClient
	 */
	public function setCertificateFile( $certificateFile )
	{
		$this->_certificateFile = $certificateFile;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getCertificateFile()
	{
		return $this->_certificateFile;
	}

	/**
	 * @param string $grantType
	 *
	 * @return BasePortalClient
	 */
	public function setGrantType( $grantType )
	{
		$this->_grantType = $grantType;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getGrantType()
	{
		return $this->_grantType;
	}

	/**
	 * @param string $hashAlgorithm
	 *
	 * @return BasePortalClient
	 */
	public function setHashAlgorithm( $hashAlgorithm )
	{
		$this->_hashAlgorithm = $hashAlgorithm;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getHashAlgorithm()
	{
		return $this->_hashAlgorithm;
	}

	/**
	 * @param string $clientSecret
	 *
	 * @return BasePortalClient
	 */
	public function setClientSecret( $clientSecret )
	{
		$this->_clientSecret = $clientSecret;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getClientSecret()
	{
		return $this->_clientSecret;
	}

	/**
	 * @param string $clientId
	 *
	 * @return BasePortalClient
	 */
	public function setClientId( $clientId )
	{
		$this->_clientId = $clientId;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getClientId()
	{
		return $this->_clientId;
	}

	/**
	 * @param string $redirectUri
	 *
	 * @return BasePortalClient
	 */
	public function setRedirectUri( $redirectUri )
	{
		$this->_redirectUri = $redirectUri;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getRedirectUri()
	{
		return $this->_redirectUri;
	}

	/**
	 * @param string $refreshToken
	 *
	 * @return BasePortalClient
	 */
	public function setRefreshToken( $refreshToken )
	{
		$this->_refreshToken = $refreshToken;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getRefreshToken()
	{
		return $this->_refreshToken;
	}

	/**
	 * @param string $scope
	 *
	 * @return BasePortalClient
	 */
	public function setScope( $scope )
	{
		$this->_scope = $scope;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getScope()
	{
		return $this->_scope;
	}

	/**
	 * @param string $tokenEndpoint
	 *
	 * @return BasePortalClient
	 */
	public function setTokenEndpoint( $tokenEndpoint )
	{
		$this->_tokenEndpoint = $tokenEndpoint;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getTokenEndpoint()
	{
		return $this->_tokenEndpoint;
	}

	/**
	 * @param string $authHeaderName
	 *
	 * @return BasePortalClient
	 */
	public function setAuthHeaderName( $authHeaderName )
	{
		$this->_authHeaderName = $authHeaderName;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getAuthHeaderName()
	{
		return $this->_authHeaderName;
	}

	/**
	 * @param mixed $userAgent
	 *
	 * @return BasePortalClient
	 */
	public function setUserAgent( $userAgent )
	{
		$this->_userAgent = $userAgent;

		return $this;
	}

	/**
	 * @return mixed
	 */
	public function getUserAgent()
	{
		return $this->_userAgent;
	}

	/**
	 * @param string $serviceEndpoint
	 *
	 * @return BasePortalClient
	 */
	public function setServiceEndpoint( $serviceEndpoint )
	{
		$this->_serviceEndpoint = $serviceEndpoint;

		return $this;
	}

	/**
	 * @param string $redirectProxyUrl
	 *
	 * @return BasePortalClient
	 */
	public function setRedirectProxyUrl( $redirectProxyUrl )
	{
		$this->_redirectProxyUrl = $redirectProxyUrl;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getRedirectProxyUrl()
	{
		return $this->_redirectProxyUrl;
	}
}
