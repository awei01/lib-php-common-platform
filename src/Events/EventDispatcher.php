<?php
/**
 * This file is part of the DreamFactory Services Platform(tm) SDK For PHP
 *
 * DreamFactory Services Platform(tm) <http://github.com/dreamfactorysoftware/dsp-core>
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
namespace DreamFactory\Platform\Events;

use DreamFactory\Platform\Components\ApiResponse;
use DreamFactory\Platform\Events\Enums\SwaggerEvents;
use DreamFactory\Platform\Events\Interfaces\EventObserverLike;
use DreamFactory\Platform\Events\Stores\EventStore;
use DreamFactory\Platform\Exceptions\BadRequestException;
use DreamFactory\Platform\Exceptions\NotImplementedException;
use DreamFactory\Platform\Resources\System\Config;
use DreamFactory\Platform\Resources\System\Script;
use DreamFactory\Platform\Services\BasePlatformRestService;
use DreamFactory\Platform\Services\SwaggerManager;
use DreamFactory\Platform\Utility\Platform;
use DreamFactory\Yii\Utility\Pii;
use Guzzle\Http\Client;
use Guzzle\Http\Exception\MultiTransferException;
use Jeremeamia\SuperClosure\SerializableClosure;
use Kisma\Core\Enums\GlobFlags;
use Kisma\Core\Utility\FileSystem;
use Kisma\Core\Utility\Inflector;
use Kisma\Core\Utility\Log;
use Kisma\Core\Utility\Option;
use Kisma\Core\Utility\Scalar;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * EventDispatcher
 */
class EventDispatcher implements EventDispatcherInterface
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * @type string
     */
    const DEFAULT_USER_AGENT = 'DreamFactory/SSE_1.0';

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var array The cached portions of this object
     */
    protected static $_cachedData = array( 'listeners', 'scripts', 'observers' );
    /**
     * @var EventObserverLike[]
     */
    protected static $_observers = array();
    /**
     * @var bool Will log dispatched events if true
     */
    protected static $_logEvents = false;
    /**
     * @var bool Will log all events if true
     */
    protected static $_logAllEvents = false;
    /**
     * @var bool Enable/disable REST events
     */
    protected static $_enableRestEvents = true;
    /**
     * @var bool Enable/disable platform events
     */
    protected static $_enablePlatformEvents = true;
    /**
     * @var bool Enable/disable event scripts
     */
    protected static $_enableEventScripts = true;
    /**
     * @var bool Enable/disable event observation
     */
    protected static $_enableEventObservers = true;
    /**
     * @var Client
     */
    protected static $_client = null;
    /**
     * @var \Kisma\Core\Components\Flexistore
     */
    protected static $_store = null;
    /**
     * @var BasePlatformRestService
     */
    protected $_service;
    /**
     * @var array
     */
    protected $_listeners = array();
    /**
     * @var array[]
     */
    protected $_scripts = array();
    /**
     * @var array
     */
    protected $_sorted = array();
    /**
     * @var string The path of the current request
     */
    protected $_pathInfo;

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * Load any stored events
     */
    public function __construct()
    {
        //  Logging settings
        static::$_logEvents = Pii::getParam( 'dsp.log_events', static::$_logEvents );
        static::$_logAllEvents = Pii::getParam( 'dsp.log_all_events', static::$_logAllEvents );

        //  Event enablements
        static::$_enableRestEvents = Pii::getParam( 'dsp.enable_rest_events', static::$_enableRestEvents );
        static::$_enablePlatformEvents = Pii::getParam( 'dsp.enable_rest_events', static::$_enablePlatformEvents );
        static::$_enableEventScripts = Pii::getParam( 'dsp.enable_event_scripts', static::$_enableEventScripts );
        static::$_enableEventObservers = Pii::getParam( 'dsp.enable_event_observers', static::$_enableEventObservers );

        try
        {
            $this->_initializeEvents();
            $this->_initializeEventObservation();
            $this->_initializeEventScripting();
        }
        catch ( \Exception $_ex )
        {
            Log::notice( 'Event system unavailable at this time.' );
        }
    }

    /**
     * Destruction
     */
    public function __destruct()
    {
        static::_saveToStore( $this );
    }

    /**
     * @throws \DreamFactory\Platform\Exceptions\InternalServerErrorException
     */
    protected function _initializeEvents()
    {
        static::_loadFromStore( $this );
    }

    /**
     * @return bool
     */
    protected function _initializeEventObservation()
    {
        //@todo decide what needs to happen here
    }

    /**
     * @return bool
     */
    protected function _initializeEventScripting()
    {
        //  Do nothing if not wanted
        if ( !static::$_enableEventScripts )
        {
            return false;
        }

        /**
         * @var int Make sure we check for new scripts at least once per minute
         */
        static $CACHE_TTL = 60;
        static $CACHE_KEY = 'platform.scripts_last_check';

        $_lastCheck = Platform::storeGet( $CACHE_KEY, $_timestamp = time(), false, $CACHE_TTL );

        if ( $_timestamp - $_lastCheck == 0 || empty( $this->_scripts ) )
        {
            $this->checkMappedScripts();
        }

        //  If the cache rebuilds, bust our cache and remap scripts
        $this->addListener(
            SwaggerEvents::CACHE_REBUILT,
            function ( $eventName, $event, $dispatcher )
            {
                /** @var EventDispatcher $dispatcher */
                $dispatcher->checkMappedScripts( true, true );
            }
        );

        return true;
    }

    /**
     * @param string                                                 $eventName
     * @param \Symfony\Component\EventDispatcher\Event|PlatformEvent $event
     *
     * @return \Symfony\Component\EventDispatcher\Event|PlatformEvent|void
     */
    public function dispatch( $eventName, Event $event = null )
    {
        $_event = $event ? : new PlatformEvent( $eventName );

        $this->_doDispatch( $_event, $eventName, $this );

        return $_event;
    }

    /**
     * @param PlatformEvent|PlatformServiceEvent $event
     * @param string                             $eventName
     *
     * @throws \DreamFactory\Platform\Exceptions\InternalServerErrorException
     * @throws \Exception
     *
     * @return bool|\DreamFactory\Platform\Events\PlatformEvent Returns the original $event
     * if successfully dispatched to all listeners. Returns false if nothing was dispatched
     * and true if propagation was stopped.
     */
    protected function _doDispatch( &$event, $eventName )
    {
        //  Do nothing if not wanted
        if ( !static::$_enableRestEvents && !static::$_enablePlatformEvents && !static::$_enableEventScripts && !static::$_enableEventObservers )
        {
            return false;
        }

        if ( static::$_logAllEvents )
        {
            Log::debug(
                'Triggered: "' . $eventName . '" by ' . $this->getPathInfo()
            );
        }

        //  Observers get the event first...
        if ( static::$_enableEventObservers && !empty( static::$_observers ) )
        {
            foreach ( static::$_observers as $_observer )
            {
                if ( !$_observer->handleEvent( $eventName, $event, $this ) )
                {
                    return true;
                }
            }
        }

        //  Run any scripts
        if ( !$this->_runEventScripts( $eventName, $event ) )
        {
            return true;
        }

        //  Notify the listeners
        if ( !( $_dispatched = $this->_notifyListeners( $eventName, $event ) ) )
        {
            return true;
        }

        return $_dispatched;
    }

    //-------------------------------------------------------------------------
    //	Listener Management
    //-------------------------------------------------------------------------

    /**
     * @param string $eventName
     * @param bool   $prettyPrintObjects
     *
     * @return array
     */
    public function getListeners( $eventName = null, $prettyPrintObjects = false )
    {
        if ( !empty( $eventName ) )
        {
            if ( !isset( $this->_sorted[ $eventName ] ) )
            {
                $this->_sortListeners( $eventName );
            }

            return $this->_sorted[ $eventName ];
        }

        foreach ( array_keys( $this->_listeners ) as $eventName )
        {
            if ( !isset( $this->_sorted[ $eventName ] ) )
            {
                $this->_sortListeners( $eventName );
            }
        }

        if ( $prettyPrintObjects )
        {
            $_result = $this->_sorted;

            foreach ( $_result as $_eventName => &$_listeners )
            {
                foreach ( $_listeners as &$_listener )
                {
                    if ( is_string( $_listener ) )
                    {
                        continue;
                    }

                    $_hash = spl_object_hash( $_listener );
                    $_name = gettype( $_listener );
                    $_listener = ( ( $_listener instanceof \Closure || $_listener instanceof SerializableClosure ) ? 'Closure' : $_name ) . 'id#' . $_hash;
                }
            }

            return $_result;
        }

        return $this->_sorted;
    }

    /**
     * Sorts the internal list of listeners for the given event by priority.
     *
     * @param string $eventName The name of the event.
     */
    protected function _sortListeners( $eventName )
    {
        $this->_sorted[ $eventName ] = array();

        if ( isset( $this->_listeners[ $eventName ] ) )
        {
            krsort( $this->_listeners[ $eventName ] );
            $this->_sorted[ $eventName ] = call_user_func_array( 'array_merge', $this->_listeners[ $eventName ] );
        }
    }

    /**
     * @param string   $eventName
     * @param callable $listener
     * @param int      $priority
     * @param bool     $fromCache True if this is a cached listener
     *
     * @return bool
     * @see EventDispatcherInterface::addListener
     */
    public function addListener( $eventName, $listener, $priority = 0, $fromCache = false )
    {
        if ( !isset( $this->_listeners[ $eventName ] ) || empty( $this->_listeners[ $eventName ] ) )
        {
            $this->_listeners[ $eventName ] = array();
        }

        if ( !isset( $this->_listeners[ $eventName ][ $priority ] ) )
        {
            $this->_listeners[ $eventName ][ $priority ] = array();
        }

        $this->_sanitizeListener( $listener );

        $_found = false;
        $_newListener = serialize( $listener );

        foreach ( $this->_listeners[ $eventName ][ $priority ] as $_liveListener )
        {
            if ( $_newListener === serialize( $_liveListener ) )
            {
                if ( static::$_logAllEvents && $fromCache )
                {
                    Log::debug( '  * Existing listener found and skipped for "' . spl_object_hash( $this ) . '::' . $eventName . '"' );
                }

                $_found = true;
                continue;
            }
        }

        if ( !$_found )
        {
            if ( static::$_logAllEvents )
            {
                Log::debug( '  * Added ' . ( $fromCache ? 'cached' : 'new' ) . ' listener for "' . spl_object_hash( $this ) . '::' . $eventName . '"' );
            }

            $this->_listeners[ $eventName ][ $priority ][] = $listener;
            unset( $this->_sorted[ $eventName ] );
        }

        return true;
    }

    /**
     * @see EventDispatcherInterface::removeListener
     */
    public function removeListener( $eventName, $listener )
    {
        if ( !isset( $this->_listeners[ $eventName ] ) )
        {
            return;
        }

        $this->_sanitizeListener( $listener );

        foreach ( $this->_listeners[ $eventName ] as $_priority => $_listeners )
        {
            if ( false !== ( $key = array_search( $listener, $_listeners, true ) ) )
            {
                unset( $this->_listeners[ $eventName ][ $_priority ][ $key ], $this->_sorted[ $eventName ] );
            }
        }
    }

    /**
     * @see EventDispatcherInterface::addSubscriber
     */
    public function addSubscriber( EventSubscriberInterface $subscriber )
    {
        foreach ( $subscriber->getSubscribedEvents() as $_eventName => $_params )
        {
            if ( is_string( $_params ) )
            {
                $this->addListener( $_eventName, array( $subscriber, $_params ) );
            }
            elseif ( is_string( $_params[0] ) )
            {
                $this->addListener( $_eventName, array( $subscriber, $_params[0] ), isset( $_params[1] ) ? $_params[1] : 0 );
            }
            else
            {
                foreach ( $_params as $listener )
                {
                    $this->addListener( $_eventName, array( $subscriber, $listener[0] ), isset( $listener[1] ) ? $listener[1] : 0 );
                }
            }
        }
    }

    /**
     * @see EventDispatcherInterface::removeSubscriber
     */
    public function removeSubscriber( EventSubscriberInterface $subscriber )
    {
        foreach ( $subscriber->getSubscribedEvents() as $_eventName => $_params )
        {
            if ( is_array( $_params ) && is_array( $_params[0] ) )
            {
                foreach ( $_params as $listener )
                {
                    $this->removeListener( $_eventName, array( $subscriber, $listener[0] ) );
                }
            }
            else
            {
                $this->removeListener( $_eventName, array( $subscriber, is_string( $_params ) ? $_params : $_params[0] ) );
            }
        }
    }

    /**
     * @return array
     */
    public function getAllListeners()
    {
        return $this->_listeners;
    }

    //-------------------------------------------------------------------------
    //	Utilities
    //-------------------------------------------------------------------------

    /**
     * @param $callable
     *
     * @return bool
     */
    protected function isPhpScript( $callable )
    {
        return is_callable( $callable ) || ( ( false === strpos( $callable, ' ' ) && false !== strpos( $callable, '::' ) ) );
    }

    /**
     * @param string                                   $className
     * @param string                                   $methodName
     * @param \Symfony\Component\EventDispatcher\Event $event
     * @param string                                   $eventName
     * @param EventDispatcher                          $dispatcher
     *
     * @return mixed
     */
    protected function _executeEventPhpScript( $className, $methodName, \Symfony\Component\EventDispatcher\Event $event, $eventName = null, $dispatcher = null )
    {
        try
        {
            return call_user_func(
                array( $className, $methodName ),
                $event,
                $eventName,
                $dispatcher
            );
        }
        catch ( \Exception $_ex )
        {
            throw new \LogicException( 'Error executing PHP event script: ' . $_ex->getMessage() );
        }
    }

    /**
     * @param EventDispatcher $dispatcher
     * @param bool            $flush If true, empties this dispatcher's cache
     *
     * @return bool
     */
    protected static function _saveToStore( EventDispatcher $dispatcher, $flush = false )
    {
        $_store = new EventStore( $dispatcher );

        if ( $flush )
        {
            return $_store->flushAll();
        }

        return $_store->saveAll();
    }

    /**
     * @param EventDispatcher $dispatcher
     *
     * @return bool
     */
    protected static function _loadFromStore( EventDispatcher $dispatcher )
    {
        $_store = new EventStore( $dispatcher );

        return $_store->loadAll();
    }

    /**
     * Creates a generic, consistent event for scripting and notifications
     *
     * The returned array is as follows:
     *
     *  array(
     *      //  This contains information about the event itself (READ-ONLY)
     *      'event' => array(
     *          'id'                => 'A unique ID assigned to this event',
     *          'name'              => 'event.name',
     *          'trigger'           => '{api_name}/{resource}',
     *          'stop_propagation'  => [true|false],
     *          'dispatcher'        => array(
     *              'id'            => 'A unique ID assigned to the dispatcher of this event',
     *          ),
     *          //  Information about the triggering request
     *          'request'           => array(
     *              'timestamp'     => 'timestamp of the initial request',
     *              'api_name'      =>'The api_name of the called service',
     *              'resource'      => 'The name of the resource requested',
     *              'path'          => '/full/path/that/triggered/event',
     *          ),
     *      ),
     *      //  This contains the static configuration of the entire platform (READ-ONLY)
     *      'platform' => array(
     *          'api'               => [wormhole to inline-REST API],
     *          'config'            => [standard DSP configuration update],
     *      ),
     *      //  This contains any additional information the event sender wanted to convey (READ-ONLY)
     *      'details' => array(),
     *      //  THE MEAT! This contains the ACTUAL data received from the client, or what's being sent back to the client (READ-WRITE).
     *      'payload' => array(
     *          //  See recap above for formats
     *      ),
     *  );
     *
     * Please note that the format of the payload differs slightly on multi-row result sets. In the v1.0 REST API, if a single row of data
     * is to be returned from a request, it is merged into the root of the resultant array. If there are multiple rows, they are placed into
     * n key called 'record'. To make matter worse, if you make a multi-row request via XML, and wrap your input payload in a
     * <records><record></record>...</records> type wrapper, the resultant array will be placed a level deeper ($payload['records']['record'] = $results).
     *
     * Therefore the data exposed by the event system has been "normalized" to provide a reliable and consistent manner in which to process said data.
     * There should be no need for wasting time trying to determine if your data is "maybe here, or maybe there, or maybe over there even" when received by
     * your event handlers. If your payload contains record data, you will always receive it in an array container. Even for single rows.
     *
     * IMPORTANT: Don't expect this for ALL results. For non-record-like resultant data and/or result sets (i.e. NoSQL, other stuff), the data
     * may be placed in the payload verbatim.
     *
     * IMPORTANTER: The representation of the data will be placed back into the original location/position in the $payload from which it was "normalized".
     * This means that any client-side handlers will have to deal with the bogus determinations. Just be aware.
     *
     * To recap, below is a side-by-side comparison of record data as shown returned to the caller, and sent to an event handler.
     *
     *  REST API v1.0                           Event Representation
     *  -------------                           --------------------
     *  Single row...                           Add a 'record' key and make it look like a multi-row
     *
     *      array(                              array(
     *          'id' => 1,                          'record' => array(
     *      )                                           0 => array( 'id' => 1, ),
     *                                              ),
     *                                          ),
     *
     * Multi-row...                             Stays the same...
     *
     *      array(                              array(
     *          'record' => array(                  'record' =>  array(
     *              0 => array( 'id' => 1 ),            0 => array( 'id' => 1 ),
     *              1 => array( 'id' => 2 ),            1 => array( 'id' => 2 ),
     *              2 => array( 'id' => 3 ),            2 => array( 'id' => 3 ),
     *          ),                                  ),
     *      )                                   )
     *
     * XML multi-row                            The 'records' key is unwrapped, like regular multi-row
     *
     *  array(                                  array(
     *    'records' => array(                     'record' =>  array(
     *      'record' => array(                        0 => array( 'id' => 1 ),
     *        0 => array( 'id' => 1 ),                1 => array( 'id' => 2 ),
     *        1 => array( 'id' => 2 ),                2 => array( 'id' => 3 ),
     *        2 => array( 'id' => 3 ),            ),
     *      ),                                  )
     *    ),
     *  )
     *
     * @param string        $eventName        The event name
     * @param PlatformEvent $event            The event
     * @param bool          $includeDspConfig If true, the current DSP config is added to payload
     * @param bool          $returnJson       If true, the event will be returned as a JSON string, otherwise an array.
     *
     * @param array         $additionalDetails
     *
     * @return array|string
     */
    protected function _sandboxEvent( $eventName, PlatformEvent $event, $includeDspConfig = true, $returnJson = false, $additionalDetails = array() )
    {
        static $_config = null;

        $_config = $_config ? : Config::getCurrentConfig();
        
        $_event = array(
            'event'    => array(
                'id'               => null,
                'name'             => $eventName,
                'trigger'          => $this->getPathInfo(),
                'stop_propagation' => $event->isPropagationStopped(),
                'dispatcher'       => array(
                    'id'   => spl_object_hash( $this ),
                    'type' => Inflector::neutralize( get_class( $this ) ),
                ),
            ),
            'request'  => array_merge(
                $event->toArray(),
                array(
                    'timestamp' => date( 'c', Option::server( 'REQUEST_TIME_FLOAT', Option::server( 'REQUEST_TIME', microtime( true ) ) ) ),
                    'path'      => $this->getPathInfo( true )
                )
            ),
            'platform' => array(
                'api'    => function ( $apiName, $resource, $resourceId, $parameters = array(), $payload = array() )
                {
                    throw new NotImplementedException( 'This feature is in development.' );
                },
                'config' => Config::getCurrentConfig(),
            ),
            'details'  => Option::clean( $additionalDetails ),
            'payload'  => $this->_sandboxEventData( $event ),
        );

        return $returnJson ? json_encode( $_event, JSON_UNESCAPED_SLASHES ) : $_event;
    }

    /**
     * Sandboxes the event data into a normalized fashion
     *
     * @param PlatformEvent $event
     *
     * @return array
     */
    protected function _sandboxEventData( PlatformEvent $event )
    {
        $_data = $event->getData();

        //  XML-wrapped
        if ( false !== ( $_records = Option::getDeep( $_data, 'records', 'record', false ) ) )
        {
            return array( 'record' => $_records );
        }

        //  Multi-row
        if ( false !== ( $_records = Option::get( $_data, 'record', false ) ) )
        {
            return array( 'record' => $_records );
        }

        //  Single row, or so we think...
        if ( is_array( $_data ) && !Pii::isEmpty( $_record = Option::get( $_data, 'record' ) ) && count( $_record ) >= 1 )
        {
            return array( 'record' => $_data );
        }

        //  Something completely different...
        return $_data;

    }

    /**
     * Verify that mapped scripts exist. Optionally check for new drop-ins
     *
     * @param bool $scanForNew If true, the $scriptPath will be scanned for new scripts
     * @param bool $flush
     */
    public function checkMappedScripts( $scanForNew = true, $flush = false )
    {
        if ( $flush )
        {
            return static::_saveToStore( $this, true );
        }

        $_found = array();
        $_basePath = Platform::getPrivatePath( Script::DEFAULT_SCRIPT_PATH );

        foreach ( SwaggerManager::getEventMap() as $_routes )
        {
            foreach ( $_routes as $_routeInfo )
            {
                foreach ( $_routeInfo as $_methodInfo )
                {
                    foreach ( Option::get( $_methodInfo, 'scripts', array() ) as $_script )
                    {
                        $_eventKey = str_ireplace( '.js', null, $_script );

                        //  Don't add bogus scripts
                        $_scriptFile = $_basePath . '/' . $_script;

                        if ( is_file( $_scriptFile ) && is_readable( $_scriptFile ) )
                        {
                            if ( !isset( $this->_scripts[ $_eventKey ] ) || !Scalar::contains( $_scriptFile, $this->_scripts[ $_eventKey ] ) )
                            {
                                $_found[] = str_replace( $_basePath, '.', $_scriptFile );
                                $this->_scripts[ $_eventKey ][] = $_scriptFile;
                            }
                        }
                    }
                }
            }
        }

        //  Check for new
        if ( $scanForNew && !empty( $_found ) )
        {
            $_scripts = FileSystem::glob( $_basePath . '/*.js', GlobFlags::GLOB_NODIR | GlobFlags::GLOB_NODOTS | GlobFlags::GLOB_RECURSE );

            if ( !empty( $_scripts ) )
            {
                foreach ( $_scripts as $_newScript )
                {
                    $_eventKey = str_ireplace( '.js', null, $_newScript );
                    $_scriptFile = $_basePath . '/' . $_newScript;

                    if ( !array_key_exists( $_eventKey, $this->_scripts ) || !Scalar::contains( $_scriptFile, $this->_scripts[ $_eventKey ] ) )
                    {
                        $this->_scripts[ $_eventKey ][] = $_scriptFile;
                    }
                }
            }
        }
    }

    /**
     * @param \Closure|SerializableClosure|string $listener
     *
     * @throws BadRequestException
     */
    protected function _sanitizeListener( &$listener )
    {
        if ( $listener instanceof SerializableClosure )
        {
            return;
        }

        //  All closures convert to SerializableClosure objects
        if ( $listener instanceof \Closure )
        {
            $listener = new SerializableClosure( $listener );

            return;
        }

        //  Strings must be a valid URL
        if ( !is_string( $listener ) )
        {
            throw new BadRequestException( 'Unrecognized listener type: ' . print_r( $listener, true ) );
        }

        //  Is this an URL listener?
        if ( filter_var( $listener, FILTER_VALIDATE_URL ) )
        {
            return;
        }

        //  Assume relative URL, add host and try again...
        $_test = Pii::request( false )->getSchemeAndHttpHost() . '/' . ltrim( $listener, ' /' );
        if ( !filter_var( $_test, FILTER_VALIDATE_URL ) )
        {
            throw new BadRequestException( 'Unrecognized listener: ' . $listener );
        }

        //  Set full url as listener
        $listener = $_test;
    }
    //-------------------------------------------------------------------------
    //	Properties
    //-------------------------------------------------------------------------

    /**
     * @return array
     */
    public function getScripts()
    {
        return $this->_scripts;
    }

    /**
     * @return array|EventObserverLike[]
     */
    public static function getObservers()
    {
        return static::$_observers;
    }

    /**
     * @return boolean
     */
    public static function getLogEvents()
    {
        return static::$_logEvents;
    }

    /**
     * @param boolean $logEvents
     */
    public static function setLogEvents( $logEvents )
    {
        static::$_logEvents = $logEvents;
    }

    /**
     * @return boolean
     */
    public static function getLogAllEvents()
    {
        return static::$_logAllEvents;
    }

    /**
     * @param boolean $logAllEvents
     */
    public static function setLogAllEvents( $logAllEvents )
    {
        static::$_logAllEvents = $logAllEvents;
    }

    /**
     * @param bool $unsullied If true, the actual request path is returned, otherwise it is stripped of "/rest"
     *
     * @return string The request path with or without "/rest"
     */
    public function getPathInfo( $unsullied = false )
    {
        $_path = null;

        //  If the dude wants the business or we haven't set the path yet...
        if ( !$unsullied || empty( $this->_pathInfo ) )
        {
            //  Get the path and clean it up
            $_path = Pii::request( true )->getPathInfo();
            $this->_pathInfo = preg_replace( '#^\/rest#', null, $_path, 1 );
        }

        return $unsullied ? ( $_path ? : Pii::request( true )->getPathInfo() ) : $this->_pathInfo;
    }

    /**
     * @see EventDispatcherInterface::hasListeners
     */
    public function hasListeners( $eventName = null )
    {
        return (bool)count( $this->getListeners( $eventName ) );
    }

    /**
     * @param string        $eventName The name of the event
     * @param PlatformEvent $event     The event
     *
     * @throws \DreamFactory\Platform\Exceptions\InternalServerErrorException
     * @return bool False if propagation has stopped, true otherwise.
     * @todo convert to EventObserver
     */
    protected function _runEventScripts( $eventName, &$event )
    {
        if ( !static::$_enableEventScripts )
        {
            return true;
        }

        //  Run scripts
        if ( null === ( $_scripts = Option::get( $this->_scripts, $eventName ) ) )
        {
            //  See if we have a platform event handler...
            if ( false === ( $_script = Script::existsForEvent( $eventName ) ) )
            {
                $_scripts = null;
            }
        }

        if ( empty( $_scripts ) )
        {
            return true;
        }

        $_event = $this->_sandboxEvent( $eventName, $event );

        foreach ( Option::clean( $_scripts ) as $_script )
        {
            $_result = Script::runScript( $_script, $eventName . '.js', $_event, $_output );

            if ( is_array( $_result ) )
            {
                $_event['data'] = $_result;
                $event->fromArray( $_event );
            }

            if ( !empty( $_output ) )
            {
                Log::debug( '  * Script "' . $eventName . '.js" output: ' . $_output );
            }

            if ( $event->isPropagationStopped() )
            {
                Log::info( '  * Propagation stopped by script.' );

                return false;
            }
        }

        return true;
    }

    /**
     * @param string               $eventName The name of the event
     * @param PlatformServiceEvent $event     The event
     *
     * @throws \Exception
     * @return bool False if propagation has stopped, true otherwise.
     * @todo convert to EventObserver
     */
    protected function _notifyListeners( $eventName, &$event )
    {
        $_dispatched = true;
        $_posts = array();

        foreach ( $this->getListeners( $eventName ) as $_listener )
        {
            //  Local code listener
            if ( !is_string( $_listener ) && is_callable( $_listener ) )
            {
                call_user_func( $_listener, $event, $eventName, $this );
            }
            //  External PHP script listener
            elseif ( $this->isPhpScript( $_listener ) )
            {
                $_className = substr( $_listener, 0, strpos( $_listener, '::' ) );
                $_methodName = substr( $_listener, strpos( $_listener, '::' ) + 2 );

                if ( !class_exists( $_className ) )
                {
                    Log::warning( 'Class ' . $_className . ' is not auto-loadable. Cannot call ' . $eventName . ' script' );
                    continue;
                }

                if ( !is_callable( $_listener ) )
                {
                    Log::warning( 'Method ' . $_listener . ' is not callable. Cannot call ' . $eventName . ' script' );
                    continue;
                }

                try
                {
                    $this->_executeEventPhpScript( $_className, $_methodName, $event, $eventName, $this );
                }
                catch ( \Exception $_ex )
                {
                    Log::error( 'Exception running script "' . $_listener . '" handling the event "' . $eventName . '"' );
                    throw $_ex;
                }
            }
            //  HTTP POST event
            elseif ( is_string( $_listener ) && (bool)@parse_url( $_listener ) )
            {
                if ( !static::$_client )
                {
                    static::$_client = static::$_client ? : new Client();
                    static::$_client->setUserAgent( static::DEFAULT_USER_AGENT );
                }

                /**
                 * If you're asking yourself "Great Shatner's Ghost! Why is he doing this every time through the loop!!?", well here is the answer:
                 * Because the $event object can be changed by different listeners during the processing loop, it needs to be regen'd each time.
                 * That's not to say it couldn't be done better in another sprint.
                 */
                $_payload = ApiResponse::create( $this->_sandboxEvent( $eventName, $event, false, true ) );

                $_posts[] = static::$_client->post(
                    $_listener,
                    array( 'content-type' => 'application/json' ),
                    json_encode( $_payload, JSON_UNESCAPED_SLASHES )
                );
            }
            //  No clue!
            else
            {
                $_dispatched = false;
            }

            //  Any thing to send, send them...
            if ( !empty( $_posts ) )
            {
                try
                {
                    //	Send the posts all at once
                    static::$_client->send( $_posts );
                }
                catch ( MultiTransferException $_exceptions )
                {
                    /** @var \Exception $_exception */
                    foreach ( $_exceptions as $_exception )
                    {
                        Log::error( '  * Action event exception: ' . $_exception->getMessage() );
                    }

                    foreach ( $_exceptions->getFailedRequests() as $_request )
                    {
                        Log::error( '  * Dispatch Failure: ' . $_request );
                    }

                    foreach ( $_exceptions->getSuccessfulRequests() as $_request )
                    {
                        Log::debug( '  * Dispatch success: ' . $_request );
                    }
                }
            }

            if ( $_dispatched && static::$_logEvents && !static::$_logAllEvents )
            {
                Log::debug(
                    ( $_dispatched ? 'Dispatcher' : 'Unhandled' ) .
                    ': event "' .
                    $eventName .
                    '" triggered by /' .
                    Option::get( $_GET, 'path', $event->getApiName() . '/' . $event->getResource() )
                );
            }

            if ( $event->isPropagationStopped() )
            {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string       $eventName
     * @param string|array $scriptPath One or more scripts to run on this event
     * @param bool         $fromCache  True if the cache is adding this handler (ignored by default)
     *
     * @return bool False if no scripts added, otherwise how many were added
     */
    public function addScript( $eventName, $scriptPath, $fromCache = false )
    {
        if ( !isset( $this->_scripts[ $eventName ] ) )
        {
            $this->_scripts[ $eventName ] = array();
        }

        if ( !is_array( $scriptPath ) )
        {
            $scriptPath = array( $scriptPath );
        }

        foreach ( $scriptPath as $_script )
        {
            if ( !Scalar::contains( $_script, $this->_scripts[ $eventName ] ) )
            {
                if ( !is_file( $_script ) || !is_readable( $_script ) )
                {
                    if ( static::$_logAllEvents )
                    {
                        Log::debug( 'Skipping cached non-existent script "' . $_script . '"' );
                        continue;
                    }
                }

                $this->_scripts[ $eventName ][] = $_script;
            }
        }

        return count( $scriptPath ) ? : false;
    }

    /**
     * @param EventObserverLike|EventObserverLike[] $observer
     * @param bool                                  $fromCache True if the cache is adding this handler Ignored by default
     *
     * @return bool False if no observers added, otherwise how many were added
     */
    public function addObserver( $observer, $fromCache = false )
    {
        if ( !isset( static::$_observers ) )
        {
            static::$_observers = array();
        }

        if ( empty( $observer ) )
        {
            return false;
        }

        if ( !is_array( $observer ) )
        {
            $observer = array( $observer );
        }

        /**
         * Observers can take many forms, but are 99.666% of the time going to be an object.
         * So we serialize the $observer before comparing with currently known observers.
         * This allows $observer to be a string, closure, object, whatever...
         */
        $_serializedObserver = serialize( $observer );
        $_additions = array();

        \array_walk(
            static::$_observers,
            function ( $item, $index, $serializedObserver ) use ( $_additions )
            {
                if ( $serializedObserver != serialize( $item ) )
                {
                    $_additions[] = $index;
                }
            },
            $_serializedObserver
        );

        if ( !empty( $_additions ) )
        {
            static::$_observers = array_merge( static::$_observers, $_additions );
        }

        return count( $_additions ) ? : false;
    }

}