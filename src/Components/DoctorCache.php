<?php
/**
 * This file is part of Kisma(tm).
 *
 * Kisma(tm) <https://github.com/kisma/kisma>
 * Copyright 2009-2014 Jerry Ablan <jerryablan@gmail.com>
 *
 * Kisma(tm) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Kisma(tm) is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Kisma(tm).  If not, see <http://www.gnu.org/licenses/>.
 */
namespace Kisma\Core\Components;

use Aws\Common\Exception\LogicException;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\MemcacheCache;
use Doctrine\Common\Cache\MemcachedCache;
use Doctrine\Common\Cache\PhpFileCache;
use Doctrine\Common\Cache\RedisCache;
use Doctrine\Common\Cache\XcacheCache;
use Kisma\Core\Enums\CacheTypes;
use Kisma\Core\Seed;
use Kisma\Core\Utility\Option;

/**
 * Dr. Cache, the cache doctor.
 *
 *   He'll fix what ails ya'!
 *   - Snake oil and tonics not provided.
 */
class DoctorCache extends Seed
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * @type string The namespace containing our store provider
     */
    const CHILD_NAMESPACE = 'Doctrine\\Common\\Cache\\';
    /**
     * @type int The number of seconds to keep cached items. Defaults to 300 (5 minutes). a time of "0" means keep until removed.
     */
    const DEFAULT_CACHE_TTL = 300;
    /**
     * @type string The suffix for the cache files
     */
    const DEFAULT_CACHE_EXTENSION = '.drc';

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var string The ID of this store. Used as a prefix for cache keys
     */
    protected $_storeId = null;
    /**
     * @var CacheProvider|RedisCache|XcacheCache|PhpFileCache|MemcacheCache|MemcachedCache The present, or wrapped object.
     */
    protected $_store = null;

    //*************************************************************************
    //* Methods
    //*************************************************************************

    /**
     * @param array|object|string $type
     * @param array               $config
     * @param bool                $autoInit If true, automatically initialize with defaults. If false, you are responsible for setting the necessary settings
     *
     * @throws \Aws\Common\Exception\LogicException
     * @throws \LogicException
     * @throws \InvalidArgumentException
     */
    public function __construct( $type = CacheTypes::ARRAY_CACHE, array $config = array(), $autoInit = true )
    {
        if ( !CacheTypes::contains( $type ) )
        {
            throw new \InvalidArgumentException( 'The $type "' . $type . '" is not valid.' );
        }

        //  Use any passed in 'class' or default to the type-generated class 
        $_class = Option::get( $config, 'class', static::CHILD_NAMESPACE . $type . 'Cache' );

        if ( !class_exists( $_class ) || null === ( $_mirror = new \ReflectionClass( $_class ) ) )
        {
            throw new LogicException( 'Associated driver for type "' . $type . '" not found. Looking for "' . $_class . '"' );
        }

        $this->_store = $_mirror->getConstructor() ? $_mirror->newInstanceArgs( $this->_getCacheTypeArguments( $type ) ) : $_mirror->newInstance();

        if ( null !== ( $_namespace = Option::get( $config, 'namespace' ) ) )
        {
            $this->_store->setNamespace( $_namespace );
        }

        if ( null !== ( $_extension = Option::get( $config, 'extension', self::DEFAULT_CACHE_EXTENSION ) ) )
        {
            $this->_store->setExtension( $_extension );
            
            if ( $autoInit )
            {
                $this->_initializeCache( $type );
            }
        }
    }

    /**
     * @param string $path
     * @param string $extension
     * @param string $namespace
     *
     * @throws \RuntimeException
     * @return \Kisma\Core\Components\DoctorCache
     */
    public static function createFileStore( $path = null, $extension = self::DEFAULT_CACHE_EXTENSION, $namespace = null )
    {
        $_path = $_basePath = null;

        if ( is_callable( $path ) )
        {
            $_basePath = dirname( $_path = call_user_func( $path ) );
        }
        elseif ( null === $path )
        {
            $_basePath = sys_get_temp_dir();
        }

        if ( !is_dir( $_basePath ) )
        {
            if ( false === @mkdir( $_basePath, 0777, true ) )
            {
                throw new \RuntimeException( 'File system error creating storage cache directory: ' . $_basePath );
            }
        }

        if ( null === $_path )
        {
            //  Get a unique temp directory
            do
            {
                $_path = '/fxs.' . uniqid();
            }
            while ( is_dir( $_basePath . $_path ) );
        }

        $_store = new DoctorCache( CacheTypes::PHP_FILE, $namespace, $extension, false );
        /** @noinspection PhpUndefinedMethodInspection */
        $_store->setDirectory( $path );

        return $_store;
    }

    /**
     * @param string $host
     * @param int    $port
     * @param float  $timeout
     * @param string $namespace
     *
     * @throws \LogicException
     * @throws \Aws\Common\Exception\LogicException
     * @return DoctorCache
     */
    public static function createRedisStore( $host = '127.0.0.1', $port = 6379, $timeout = 0.0, $namespace = null )
    {
        if ( !extension_loaded( 'redis' ) )
        {
            throw new LogicException( 'The PHP Redis extension is required to use this store type.' );
        }

        $_redis = new \Redis();

        if ( false === $_redis->pconnect( $host, $port, $timeout ) )
        {
            throw new \LogicException( 'No redis server answering at ' . $host . ':' . $port );
        }

        $_store = new static( CacheTypes::REDIS, $namespace, false );

        /** @noinspection PhpUndefinedMethodInspection */
        $_store->setRedis( $_redis );

        return $_store;
    }

    /**
     * @param string $type
     *
     * @return array|null
     */
    protected function _getCacheTypeArguments( $type )
    {
        switch ( $type )
        {
            case CacheTypes::FILE_SYSTEM:
            case CacheTypes::PHP_FILE:
                do
                {
                    $_directory = sys_get_temp_dir() . '/kfs.' . uniqid();
                }
                while ( is_dir( $_directory ) );

                return array( $_directory, static::DEFAULT_CACHE_EXTENSION );
        }

        return array();
    }

    /**
     * @param string $type
     *
     * @throws \LogicException
     */
    protected function _initializeCache( $type )
    {
        switch ( $type )
        {
            case CacheTypes::REDIS:
                $_redis = new \Redis();

                if ( false === $_redis->pconnect( '127.0.0.1' ) )
                {
                    throw new \LogicException( 'Cannot connect to redis server @ 127.0.0.1' );
                }

                $this->_store->setRedis( $_redis );
                break;
        }
    }

    /**
     * Fetches an entry from the cache.
     *
     * @param string $id           The id of the cache entry to fetch
     * @param mixed  $defaultValue The default value if $id not found
     * @param bool   $remove
     *
     * @return mixed The cached data or FALSE, if no cache entry exists for the given id.
     */
    public function get( $id, $defaultValue = null, $remove = false )
    {
        if ( false === ( $_data = $this->_store->fetch( $id ) ) )
        {
            if ( !$remove )
            {
                $this->_store->save( $id, $_data = $defaultValue );
            }
        }
        elseif ( $remove )
        {
            $this->_store->delete( $id );
        }

        return $_data;
    }

    /**
     * Puts data into the cache.
     *
     * $id can be specified as an array of key-value pairs: array( 'alpha' => 'xyz', 'beta' => 'qrs', 'gamma' => 'lmo', ... )
     *
     *
     * @param string|array $id       The cache id or array of key-value pairs
     * @param mixed        $data     The cache entry/data.
     * @param int          $lifeTime The cache lifetime. Sets a specific lifetime for this cache entry. Defaults to 0, or "never expire"
     *
     * @return boolean|boolean[] TRUE if the entry was successfully stored in the cache, FALSE otherwise.
     */
    public function set( $id, $data = null, $lifeTime = self::DEFAULT_CACHE_TTL )
    {
        if ( is_array( $id ) && null === $data )
        {
            $_result = array();

            foreach ( $id as $_key => $_value )
            {
                $_result[$_key] = $this->_store->save( $_key, $_value, $lifeTime );
            }

            return $_result;
        }

        return $this->_store->save( $id, $data, $lifeTime );
    }

    /**
     * Pass-thru for other cache methods to avoid extending CacheProvider
     *
     * @param string $name
     * @param array  $arguments
     *
     * @throws BadMethodException
     * @return mixed
     */
    public function __call( $name, $arguments )
    {
        if ( method_exists( $this->_store, $name ) )
        {
            return call_user_func_array( array( $this->_store, $name ), $arguments );
        }
    }

    /**
     * @return CacheProvider
     */
    public function getStore()
    {
        return $this->_store;
    }

    /**
     * @return string
     */
    public function getStoreId()
    {
        return $this->_storeId;
    }
}
