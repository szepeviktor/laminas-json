<?php

/**
 * @see       https://github.com/laminas/laminas-json for the canonical source repository
 * @copyright https://github.com/laminas/laminas-json/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-json/blob/master/LICENSE.md New BSD License
 */

namespace LaminasTest\Json\TestAsset;

use JsonSerializable;

/**
 * Implementation of the built-in JsonSerializable interface.
 */
class JsonSerializableBuiltinImpl implements JsonSerializable
{
    public function jsonSerialize()
    {
        return [__FUNCTION__];
    }
}
