<?php
/**
 * @link      http://github.com/zendframework/zend-json for the canonical source repository
 * @copyright Copyright (c) 2005-2016 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Zend\Json;

use SimpleXMLElement;
use SplQueue;
use Zend\Json\Exception\RecursionException;
use Zend\Json\Exception\RuntimeException;
use ZendXml\Security as XmlSecurity;

/**
 * Class for encoding to and decoding from JSON.
 */
class Json
{
    /**
     * How objects should be encoded: as arrays or as stdClass.
     *
     * TYPE_ARRAY is 1, which also conveniently evaluates to a boolean true
     * value, allowing it to be used with ext/json's functions.
     */
    const TYPE_ARRAY  = 1;
    const TYPE_OBJECT = 0;

     /**
      * Maximum allowed nesting depth when performing xml2json conversion.
      *
      * @var int
      */
    public static $maxRecursionDepthAllowed = 25;

    /**
     * Whether or not to use the built-in PHP functions.
     *
     * @var bool
     */
    public static $useBuiltinEncoderDecoder = false;

    /**
     * Decodes the given $encodedValue string from JSON.
     *
     * Uses json_decode() from ext/json if available.
     *
     * @param string $encodedValue Encoded in JSON format
     * @param int $objectDecodeType Optional; flag indicating how to decode
     *     objects. See {@link Decoder::decode()} for details.
     * @return mixed
     * @throws RuntimeException
     */
    public static function decode($encodedValue, $objectDecodeType = self::TYPE_OBJECT)
    {
        $encodedValue = (string) $encodedValue;
        if (function_exists('json_decode') && static::$useBuiltinEncoderDecoder !== true) {
            return self::decodeViaPhpBuiltIn($encodedValue, $objectDecodeType);
        }

        return Decoder::decode($encodedValue, $objectDecodeType);
    }

    /**
     * Encode the mixed $valueToEncode into the JSON format
     *
     * Encodes using ext/json's json_encode() if available.
     *
     * NOTE: Object should not contain cycles; the JSON format
     * does not allow object reference.
     *
     * NOTE: Only public variables will be encoded
     *
     * NOTE: Encoding native javascript expressions are possible using Zend\Json\Expr.
     *       You can enable this by setting $options['enableJsonExprFinder'] = true
     *
     * @see Zend\Json\Expr
     *
     * @param  mixed $valueToEncode
     * @param  bool $cycleCheck Optional; whether or not to check for object recursion; off by default
     * @param  array $options Additional options used during encoding
     * @return string JSON encoded object
     */
    public static function encode($valueToEncode, $cycleCheck = false, array $options = [])
    {
        if (is_object($valueToEncode)) {
            if (method_exists($valueToEncode, 'toJson')) {
                return $valueToEncode->toJson();
            }

            if (method_exists($valueToEncode, 'toArray')) {
                return static::encode($valueToEncode->toArray(), $cycleCheck, $options);
            }
        }

        // Pre-process and replace javascript expressions with placeholders
        $javascriptExpressions = new SplQueue();
        if (isset($options['enableJsonExprFinder'])
           && $options['enableJsonExprFinder'] == true
        ) {
            $valueToEncode = static::recursiveJsonExprFinder($valueToEncode, $javascriptExpressions);
        }

        // Encoding
        $prettyPrint = (isset($options['prettyPrint']) && ($options['prettyPrint'] === true));
        $encodedResult = self::encodeValue($valueToEncode, $cycleCheck, $options, $prettyPrint);

        // Post-process to revert back any Zend\Json\Expr instances.
        $encodedResult = self::injectJavascriptExpressions($encodedResult, $javascriptExpressions);

        return $encodedResult;
    }

    /**
     * Discover and replace javascript expressions with temporary placeholders.
     *
     * Check each value to determine if it is a Zend\Json\Expr; if so, replace the value with
     * a magic key and add the javascript expression to the queue.
     *
     * NOTE this method is recursive.
     *
     * NOTE: This method is used internally by the encode method.
     *
     * @see encode
     * @param mixed $value a string - object property to be encoded
     * @param SplQueue $javascriptExpressions
     * @param null|string|int $currentKey
     * @return mixed
     */
    protected static function recursiveJsonExprFinder(
        $value,
        SplQueue $javascriptExpressions,
        $currentKey = null
    ) {
        if ($value instanceof Expr) {
            // TODO: Optimize with ascii keys, if performance is bad
            $magicKey = "____" . $currentKey . "_" . (count($javascriptExpressions));

            $javascriptExpressions->enqueue([
                // If currentKey is integer, encodeUnicodeString call is not required.
                'magicKey' => (is_int($currentKey)) ? $magicKey : Encoder::encodeUnicodeString($magicKey),
                'value'    => $value,
            ]);

            return $magicKey;
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = static::recursiveJsonExprFinder($value[$k], $javascriptExpressions, $k);
            }
            return $value;
        }

        if (is_object($value)) {
            foreach ($value as $k => $v) {
                $value->$k = static::recursiveJsonExprFinder($value->$k, $javascriptExpressions, $k);
            }
            return $value;
        }

        return $value;
    }

    /**
     * Return the value of an XML attribute text or the text between the XML tags.
     *
     * In order to allow Zend\Json\Expr from XML, we check if the node matches
     * the pattern, and, if so, we return a new Zend\Json\Expr instead of a
     * text node.
     *
     * @param SimpleXMLElement $simpleXmlElementObject
     * @return Expr|string
     */
    protected static function getXmlValue($simpleXmlElementObject)
    {
        $pattern   = '/^[\s]*new Zend[_\\]Json[_\\]Expr[\s]*\([\s]*[\"\']{1}(.*)[\"\']{1}[\s]*\)[\s]*$/';
        $matchings = [];
        $match     = preg_match($pattern, $simpleXmlElementObject, $matchings);

        if ($match) {
            return new Expr($matchings[1]);
        }

        return (trim(strval($simpleXmlElementObject)));
    }

    /**
     * processXml - Contains the logic for fromJson()
     *
     * The logic in this function is a recursive one.
     *
     * The main caller of this function (fromXml) needs to provide only the
     * first two parameters (the SimpleXMLElement object and the flag for
     * indicating whether or not to ignore XML attributes).
     *
     * The third parameter will be used internally within this function during
     * the recursive calls.
     *
     * This function converts a SimpleXMLElement object into a PHP array by
     * calling a recursive function in this class; once all XML elements are
     * stored to a PHP array, it is returned to the caller.
     *
     * @param SimpleXMLElement $simpleXmlElementObject
     * @param bool $ignoreXmlAttributes
     * @param int $recursionDepth
     * @return array
     * @throws Exception\RecursionException if the XML tree is deeper than the
     *     allowed limit.
     */
    protected static function processXml($simpleXmlElementObject, $ignoreXmlAttributes, $recursionDepth = 0)
    {
        // Keep an eye on how deeply we are involved in recursion.
        if ($recursionDepth > static::$maxRecursionDepthAllowed) {
            // XML tree is too deep. Exit now by throwing an exception.
            throw new RecursionException(sprintf(
                'Function processXml exceeded the allowed recursion depth of %d',
                static::$maxRecursionDepthAllowed
            ));
        }

        $children   = $simpleXmlElementObject->children();
        $name       = $simpleXmlElementObject->getName();
        $value      = static::getXmlValue($simpleXmlElementObject);
        $attributes = (array) $simpleXmlElementObject->attributes();

        if (! count($children)) {
            if (! empty($attributes) && ! $ignoreXmlAttributes) {
                foreach ($attributes['@attributes'] as $k => $v) {
                    $attributes['@attributes'][$k] = static::getXmlValue($v);
                }

                if (! empty($value)) {
                    $attributes['@text'] = $value;
                }

                return [$name => $attributes];
            }

            return [$name => $value];
        }

        $childArray = [];
        foreach ($children as $child) {
            $childname = $child->getName();
            $element   = static::processXml($child, $ignoreXmlAttributes, $recursionDepth + 1);

            if (! array_key_exists($childname, $childArray)) {
                $childArray[$childname] = $element[$childname];
                continue;
            }

            if (empty($subChild[$childname])) {
                $childArray[$childname] = [$childArray[$childname]];
                $subChild[$childname]   = true;
            }

            $childArray[$childname][] = $element[$childname];
        }

        if (! empty($attributes) && ! $ignoreXmlAttributes) {
            foreach ($attributes['@attributes'] as $k => $v) {
                $attributes['@attributes'][$k] = static::getXmlValue($v);
            }
            $childArray['@attributes'] = $attributes['@attributes'];
        }

        if (! empty($value)) {
            $childArray['@text'] = $value;
        }

        return [$name => $childArray];
    }

    /**
     * Converts XML to JSON.
     *
     * Converts an XML formatted string into a JSON formatted string.
     *
     * The caller of this function needs to provide only the first parameter,
     * which is an XML formatted string.
     *
     * The second parameter, also optional, allows the user to select if the
     * XML attributes in the input XML string should be included or ignored
     * during the conversion.
     *
     * This function converts the XML formatted string into a PHP array via a
     * recursive function; it then converts that array to json via
     * Json::encode().
     *
     * NOTE: Encoding native javascript expressions via Zend\Json\Expr is not
     * possible.
     *
     * @deprecated by https://github.com/zendframework/zf2/pull/6778
     * @param string $xmlStringContents XML String to be converted.
     * @param  bool $ignoreXmlAttributes Include or exclude XML attributes in
     *     the conversion process.
     * @return string JSON formatted string on success.
     * @throws RuntimeException If the input not a XML formatted string.
     */
    public static function fromXml($xmlStringContents, $ignoreXmlAttributes = true)
    {
        // Load the XML formatted string into a Simple XML Element object.
        $simpleXmlElementObject = XmlSecurity::scan($xmlStringContents);

        // If it is not a valid XML content, throw an exception.
        if (! $simpleXmlElementObject) {
            throw new RuntimeException('Function fromXml was called with invalid XML');
        }

        // Call the recursive function to convert the XML into a PHP array.
        $resultArray = static::processXml($simpleXmlElementObject, $ignoreXmlAttributes);

        // Convert the PHP array to JSON using Json::encode.
        return static::encode($resultArray);
    }

    /**
     * Pretty-print JSON string
     *
     * Use 'indent' option to select indentation string; by default, four
     * spaces are used.
     *
     * @param string $json Original JSON string
     * @param array $options Encoding options
     * @return string
     */
    public static function prettyPrint($json, array $options = [])
    {
        $tokens       = preg_split('|([\{\}\]\[,])|', $json, -1, PREG_SPLIT_DELIM_CAPTURE);
        $result       = '';
        $indentLevel  = 0;
        $indentString = isset($options['indent']) ? $options['indent'] : '    ';
        $inLiteral    = false;

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            if (preg_match('/^("(?:.*)"):[ ]?(.*)$/', $token, $matches)) {
                $token = $matches[1] . ': ' . $matches[2];
            }

            $prefix = str_repeat($indentString, $indentLevel);
            if (! $inLiteral && ($token === '{' || $token === '[')) {
                $indentLevel++;
                if ($result != '' && $result[strlen($result) - 1] === "\n") {
                    $result .= $prefix;
                }
                $result .= $token . "\n";
                continue;
            }

            if (! $inLiteral && ($token == '}' || $token == ']')) {
                $indentLevel--;
                $prefix = str_repeat($indentString, $indentLevel);
                $result .= "\n" . $prefix . $token;
                continue;
            }

            if (! $inLiteral && $token === ',') {
                $result .= $token . "\n";
                continue;
            }

            $result .= ($inLiteral ? '' : $prefix) . $token;

            // Remove escaped backslash sequences causing false positives in next check
            $token = str_replace('\\', '', $token);

            // Count the number of unescaped double-quotes in token, subtract
            // the number of escaped double-quotes, and if the result is odd
            // then we are inside a string literal
            if ((substr_count($token, '"') - substr_count($token, '\\"')) % 2 !== 0) {
                $inLiteral = ! $inLiteral;
            }
        }

        return $result;
    }

    /**
     * Decode a value using the PHP built-in json_decode function.
     *
     * @param string $encodedValue
     * @param int $objectDecodeType
     * @return mixed
     * @throws RuntimeException
     */
    private static function decodeViaPhpBuiltIn($encodedValue, $objectDecodeType)
    {
        $decoded = json_decode($encodedValue, (bool) $objectDecodeType);

        switch (json_last_error()) {
            case JSON_ERROR_NONE:
                return $decoded;
            case JSON_ERROR_DEPTH:
                throw new RuntimeException('Decoding failed: Maximum stack depth exceeded');
            case JSON_ERROR_CTRL_CHAR:
                throw new RuntimeException('Decoding failed: Unexpected control character found');
            case JSON_ERROR_SYNTAX:
                throw new RuntimeException('Decoding failed: Syntax error');
            default:
                throw new RuntimeException('Decoding failed');
        }
    }

    /**
     * Encode a value to JSON.
     *
     * Intermediary step between injecting JavaScript expressions.
     *
     * Delegates to either the PHP built-in json_encode operation, or the
     * Encoder component, based on availability of the built-in and/or whether
     * or not the component encoder is requested.
     *
     * @param mixed $valueToEncode
     * @param bool $cycleCheck
     * @param array $options
     * @param bool $prettyPrint
     * @return string
     */
    private static function encodeValue($valueToEncode, $cycleCheck, array $options, $prettyPrint)
    {
        if (function_exists('json_encode') && static::$useBuiltinEncoderDecoder !== true) {
            return self::encodeViaPhpBuiltIn($valueToEncode, $prettyPrint);
        }

        return self::encodeViaEncoder($valueToEncode, $cycleCheck, $options, $prettyPrint);
    }

    /**
     * Encode a value to JSON using the PHP built-in json_encode function.
     *
     * Uses the encoding options:
     *
     * - JSON_HEX_TAG
     * - JSON_HEX_APOS
     * - JSON_HEX_QUOT
     * - JSON_HEX_AMP
     *
     * If $prettyPrint is boolean true, also uses JSON_PRETTY_PRINT.
     *
     * @param mixed $valueToEncode
     * @param bool $prettyPrint
     * @return string|false Boolean false return value if json_encode is not
     *     available, or the $useBuiltinEncoderDecoder flag is enabled.
     */
    private static function encodeViaPhpBuiltIn($valueToEncode, $prettyPrint = false)
    {
        if (! function_exists('json_encode') || static::$useBuiltinEncoderDecoder === true) {
            return false;
        }

        $encodeOptions = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

        if ($prettyPrint) {
            $encodeOptions |= JSON_PRETTY_PRINT;
        }

        return json_encode($valueToEncode, $encodeOptions);
    }

    /**
     * Encode a value to JSON using the Encoder class.
     *
     * Passes the value, cycle check flag, and options to Encoder::encode().
     *
     * Once the result is returned, determines if pretty printing is required,
     * and, if so, returns the result of that operation, otherwise returning
     * the encoded value.
     *
     * @param mixed $valueToEncode
     * @param bool $cycleCheck
     * @param array $options
     * @param bool $prettyPrint
     * @return string
     */
    private static function encodeViaEncoder($valueToEncode, $cycleCheck, array $options, $prettyPrint)
    {
        $encodedResult = Encoder::encode($valueToEncode, $cycleCheck, $options);

        if ($prettyPrint) {
            return self::prettyPrint($encodedResult, ['indent' => '    ']);
        }

        return $encodedResult;
    }

    /**
     * Inject javascript expressions into the encoded value.
     *
     * Loops through each, substituting the "magicKey" of each with its
     * associated value.
     *
     * @param string $encodedValue
     * @param SplQueue $javascriptExpressions
     * @return string
     */
    private static function injectJavascriptExpressions($encodedValue, SplQueue $javascriptExpressions)
    {
        foreach ($javascriptExpressions as $expression) {
            $encodedValue = str_replace(
                sprintf('"%s"', $expression['magicKey']),
                $expression['value'],
                (string) $encodedValue
            );
        }

        return $encodedValue;
    }
}
