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

use DreamFactory\Events\Interfaces\EventObserverLike;
use DreamFactory\Platform\Components\ApiResponse;
use DreamFactory\Platform\Components\EventStore;
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
use Kisma\Core\Utility\Log;
use Kisma\Core\Utility\Option;
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
     * @var EventObserverLike[]|array
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

        $this->addListener(
            'swagger.cache_rebuilt',
            function ( $eventName, $event, $dispatcher )
            {
                return call_user_func( array( $dispatcher, '_checkMappedScripts' ), $eventName, $event, $dispatcher );
            }
        );
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
        $this->_pathInfo = preg_replace( '#^\/rest#', null, Pii::request( true )->getPathInfo(), 1 );

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
            $this->_checkMappedScripts();
        }

        return true;
    }

    /**
     * @param string $eventName
     * @param Event  $event
     *
     * @return \Symfony\Component\EventDispatcher\Event|void
     */
    public function dispatch( $eventName, Event $event = null )
    {
        $_event = $event ? : new PlatformEvent( $eventName );

        $this->_doDispatch( $_event, $eventName, $this );

        return $_event;
    }

    /**
     * @param PlatformEvent|RestServiceEvent $event
     * @param string                         $eventName
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
                'Triggered: "' . $eventName . '" by ' . $this->_pathInfo
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
     *
     * @return array
     */
    public function getListeners( $eventName = null )
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
     * @see EventDispatcherInterface::addListener
     */
    public function addListener( $eventName, $listener, $priority = 0 )
    {
        if ( !isset( $this->_listeners[ $eventName ] ) )
        {
            $this->_listeners[ $eventName ] = array();
        }

        if ( $listener instanceof \Closure )
        {
            $listener = new SerializableClosure( $listener );
        }

        foreach ( $this->_listeners[ $eventName ] as $_priority => $_listeners )
        {
            if ( false !== ( $_key = array_search( $listener, $_listeners, true ) ) )
            {
                Log::debug( 'Replacing listener for event "' . $eventName . '" at ' . $_key );

                $this->_listeners[ $eventName ][ $_priority ][ $_key ] = $listener;
                unset( $this->_sorted[ $eventName ] );

                return;
            }
        }

        Log::debug( 'Adding listener for event "' . $eventName . '"' );

        $this->_listeners[ $eventName ][ $priority ][] = $listener;
        unset( $this->_sorted[ $eventName ] );
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

        if ( $listener instanceof \Closure )
        {
            $listener = new SerializableClosure( $listener );
        }

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
     * @param string          $className
     * @param string          $methodName
     * @param Event           $event
     * @param string          $eventName
     * @param EventDispatcher $dispatcher
     *
     * @return mixed
     */
    protected function _executeEventPhpScript( $className, $methodName, Event $event, $eventName = null, $dispatcher = null )
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
     *
     * @return bool
     */
    protected static function _saveToStore( EventDispatcher $dispatcher )
    {
        $_store = new EventStore( $dispatcher );

        return $_store->save();
    }

    /**
     * @param EventDispatcher $dispatcher
     *
     * @return bool
     */
    protected static function _loadFromStore( EventDispatcher $dispatcher )
    {
        $_store = new EventStore( $dispatcher );

        return $_store->load();
    }

    /**
     * @param string        $eventName
     * @param PlatformEvent $event
     *
     * @return array
     */
    protected function _createEventArray( $eventName, PlatformEvent $event )
    {
        //  Prepare the event we are passing around
        return array_merge(
            $event->toArray(),
            array(
                'event_name'       => $eventName,
                'dispatcher_id'    => spl_object_hash( $this ),
                'trigger'          => $this->_pathInfo,
                'stop_propagation' => $event->isPropagationStopped(),
            )
        );
    }

    /**
     * Verify that mapped scripts exist. Optionally check for new drop-ins
     *
     * @param bool $scanForNew If true, the $scriptPath will be scanned for new scripts
     */
    protected function _checkMappedScripts( $scanForNew = true )
    {
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
                            if ( !isset( $this->_scripts[ $_eventKey ] ) || !in_array( $_scriptFile, $this->_scripts[ $_eventKey ] ) )
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

                    if ( !array_key_exists( $_eventKey, $this->_scripts ) || !in_array( $_scriptFile, $this->_scripts[ $_eventKey ] ) )
                    {
                        $this->_scripts[ $_eventKey ][] = $_scriptFile;
                    }
                }
            }
        }
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
     * @return array|\DreamFactory\Events\Interfaces\EventObserverLike[]
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
        return self::$_logEvents;
    }

    /**
     * @param boolean $logEvents
     */
    public static function setLogEvents( $logEvents )
    {
        self::$_logEvents = $logEvents;
    }

    /**
     * @return boolean
     */
    public static function getLogAllEvents()
    {
        return self::$_logAllEvents;
    }

    /**
     * @param boolean $logAllEvents
     */
    public static function setLogAllEvents( $logAllEvents )
    {
        self::$_logAllEvents = $logAllEvents;
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

        $_event = $this->_createEventArray( $eventName, $event );

        foreach ( Option::clean( $_scripts ) as $_script )
        {
            $_eventData = Option::get( $_event, 'data', array() );
            $_result = Script::runScript( $_script, $eventName . '.js', $_eventData, $_output );

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
     * @param string           $eventName The name of the event
     * @param RestServiceEvent $event     The event
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

                $_payload = ApiResponse::create( $this->_createEventArray( $eventName, $event ) );

                $_posts[] = static::$_client->post(
                    $_listener,
                    array( 'content-type' => 'application/json' ),
                    json_encode( $_payload, JSON_UNESCAPED_SLASHES + JSON_PRETTY_PRINT )
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
     * @param string $eventName
     * @param string $scriptPath
     */
    public function addScript( $eventName, $scriptPath )
    {
        if ( !isset( $this->_scripts[ $eventName ] ) )
        {
            $this->_scripts[ $eventName ] = array();
        }

        if ( !in_array( $scriptPath, $this->_scripts ) )
        {
            $this->_scripts[ $eventName ][] = $scriptPath;
        }
    }

    /**
     * @param \DreamFactory\Events\Interfaces\EventObserverLike $observer
     */
    public function addObserver( EventObserverLike $observer )
    {
        if ( !isset( $this->_observers ) )
        {
            $this->_observers = array();
        }

        if ( !in_array( $observer, $this->_observers ) )
        {
            $this->_observers[] = $observer;
        }
    }

}