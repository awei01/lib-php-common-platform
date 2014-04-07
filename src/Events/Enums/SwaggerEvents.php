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
namespace DreamFactory\Platform\Events\Enums;

/**
 * The base events raised by swagger operations
 */
class SwaggerEvents
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
<<<<<<< HEAD:src/Events/Enums/SwaggerEvents.php
     * @var string Triggered immediately after the swagger cache is cleared
     */
    const CACHE_CLEARED = 'swagger.cache_cleared';
    /**
     * @var string Triggered immediately after the swagger cache has been rebuilt
     */
    const CACHE_REBUILT = 'swagger.cache_rebuilt';
}
=======
     * @var string Triggered when an event stream is created
     */
    const STREAM_CREATED = 'event_stream.created';
    /**
     * @var string Triggered when an event stream is closing
     */
    const STREAM_CLOSING = 'event_stream.closing';
    /**
     * @var string Used by heartbeat service
     */
    const PING = 'event_stream.ping';
    /**
     * @var string Used by heartbeat service
     */
    const PONG = 'event_stream.pong';
}
>>>>>>> Composer update and eventstream junk:src/Events/Enums/StreamEvents.php
