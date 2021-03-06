<?php
/**
 * This file is part of the DreamFactory Services Platform(tm) (DSP)
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

use Kisma\Core\Events\SeedEvent;
use Kisma\Core\Utility\Option;

/**
 * A basic DSP event for the server-side DSP events
 *
 * This object is modeled after jQuery's event object for ease of client consumption.
 *
 * If an event handler calls an event's stopPropagation() method, no further
 * listeners will be called.
 *
 * PlatformEvent::preventDefault() and PlatformEvent::isDefaultPrevented()
 * are provided in stub form, and do nothing by default. You may implement the
 * response to a "preventDefault" in your services by overriding the methods.
 */
class PlatformEvent extends SeedEvent
{
    //**************************************************************************
    //* Members
    //**************************************************************************

    /**
     * @var bool Set to true to stop the default action from being performed
     */
    protected $_defaultPrevented = false;
    /**
     * @var bool Indicates that a listener in the chain has changed the data
     */
    protected $_dirty = false;

    //**************************************************************************
    //* Methods
    //**************************************************************************

    /**
     * @param array $data
     */
    public function __construct( $data = array() )
    {
        parent::__construct( $data );
    }

    /**
     * Tells the event manager to prevent the default action from being performed
     */
    public function preventDefault()
    {
        $this->_defaultPrevented = true;
    }

    /**
     * @return bool
     */
    public function isDefaultPrevented()
    {
        return $this->_defaultPrevented;
    }

    /**
     * @return boolean
     */
    public function isDirty()
    {
        return $this->_dirty;
    }

    /**
     * @param array|PlatformEvent $data
     *
     * @return $this
     */
    public function fromArray( $data = array() )
    {
        foreach ( $data as $_key => $_value )
        {
            //  Event ID cannot be changed
            if ( 'event_id' != $_key )
            {
                Option::set( $this, $_key, $_value );
            }
        }

        //  Special propagation stopper
        if ( Option::get( $data, 'stop_propagation', false ) )
        {
            $this->stopPropagation();
        }

        return $this;
    }
}