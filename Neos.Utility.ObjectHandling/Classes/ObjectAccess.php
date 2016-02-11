<?php
namespace Neos\Utility;

/*
 * This file is part of the Neos.Utility.ObjectHandling package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Utility\Exception\PropertyNotAccessibleException;
use Neos\Utility\TypeHandling;
use Doctrine\Common\Inflector\Inflector;

/**
 * Provides methods to call appropriate getter/setter on an object given the
 * property name. It does this following these rules:
 *
 * - if the target object is an instance of ArrayAccess, it gets/sets the property
 * - if public getter/setter method exists, call it.
 * - if public property exists, return/set the value of it.
 * - else, throw exception
 *
 * Some methods support arrays as well, most notably getProperty() and
 * getPropertyPath().
 *
 */
abstract class ObjectAccess
{
    /**
     * Internal RuntimeCache for getGettablePropertyNames()
     * @var array
     */
    protected static $gettablePropertyNamesCache = [];

    /**
     * Internal RuntimeCache for getPropertyInternal()
     * @var array
     */
    protected static $propertyGetterCache = [];

    const ACCESS_GET = 0;
    const ACCESS_SET = 1;
    const ACCESS_PUBLIC = 2;

    /**
     * Get a property of a given object or array.
     *
     * Tries to get the property the following ways:
     *
     * - if the target is an array, and has this property, we return it.
     * - if super cow powers should be used, fetch value through reflection
     * - if public getter method exists, call it.
     * - if the target object is an instance of ArrayAccess, it gets the property
     *   on it if it exists.
     * - if public property exists, return the value of it.
     * - else, throw exception
     *
     * @param mixed $subject Object or array to get the property from
     * @param string|integer $propertyName Name or index of the property to retrieve
     * @param boolean $forceDirectAccess Directly access property using reflection(!)
     * @return mixed Value of the property
     * @throws \InvalidArgumentException in case $subject was not an object or $propertyName was not a string
     * @throws PropertyNotAccessibleException if the property was not accessible
     */
    public static function getProperty($subject, $propertyName, bool $forceDirectAccess = false)
    {
        if (!is_object($subject) && !is_array($subject)) {
            throw new \InvalidArgumentException('$subject must be an object or array, ' . gettype($subject) . ' given.', 1237301367);
        }
        if (!is_string($propertyName) && !is_int($propertyName)) {
            throw new \InvalidArgumentException('Given property name/index is not of type string or integer.', 1231178303);
        }

        $propertyExists = false;
        $propertyValue = self::getPropertyInternal($subject, $propertyName, $forceDirectAccess, $propertyExists);
        if ($propertyExists === true) {
            return $propertyValue;
        }
        throw new PropertyNotAccessibleException('The property "' . $propertyName . '" on the subject was not accessible.', 1263391473);
    }

    /**
     * Gets a property of a given object or array.
     *
     * This is an internal method that does only limited type checking for performance reasons.
     *
     * If you can't make sure that $subject is either of type array or object and $propertyName
     * of type string you should use getProperty() instead.
     *
     * @param mixed $subject Object or array to get the property from
     * @param string $propertyName name of the property to retrieve
     * @param boolean $forceDirectAccess directly access property using reflection(!)
     * @param boolean $propertyExists (by reference) will be set to true if the specified property exists and is gettable
     * @return mixed Value of the property
     * @throws PropertyNotAccessibleException
     * @see getProperty()
     */
    protected static function getPropertyInternal($subject, string $propertyName, bool $forceDirectAccess, bool &$propertyExists)
    {
        if ($subject === null) {
            return null;
        }
        if (is_array($subject)) {
            $propertyExists = array_key_exists($propertyName, $subject);
            return $propertyExists ? $subject[$propertyName] : null;
        }
        if (!is_object($subject)) {
            return null;
        }

        $propertyExists = true;
        $className = TypeHandling::getTypeForValue($subject);

        if ($forceDirectAccess === true) {
            if (property_exists($className, $propertyName)) {
                $propertyReflection = new \ReflectionProperty($className, $propertyName);
                $propertyReflection->setAccessible(true);
                return $propertyReflection->getValue($subject);
            }
            if (property_exists($subject, $propertyName)) {
                return $subject->$propertyName;
            }
            throw new PropertyNotAccessibleException('The property "' . $propertyName . '" on the subject does not exist.', 1302855001);
        }

        if ($subject instanceof \stdClass) {
            if (array_key_exists($propertyName, get_object_vars($subject))) {
                return $subject->$propertyName;
            }
            $propertyExists = false;
            return null;
        }

        $cacheIdentifier = $className . '|' . $propertyName;
        self::initializePropertyGetterCache($cacheIdentifier, $subject, $propertyName);

        if (isset(self::$propertyGetterCache[$cacheIdentifier]['accessorMethod'])) {
            $method = self::$propertyGetterCache[$cacheIdentifier]['accessorMethod'];
            return $subject->$method();
        }
        if (isset(self::$propertyGetterCache[$cacheIdentifier]['publicProperty'])) {
            return $subject->$propertyName;
        }

        if (($subject instanceof \ArrayAccess) && !($subject instanceof \SplObjectStorage) && $subject->offsetExists($propertyName)) {
            return $subject->offsetGet($propertyName);
        }

        $propertyExists = false;
        return null;
    }

    /**
     * @param string $cacheIdentifier
     * @param mixed $subject
     * @param string $propertyName
     * @return void
     */
    protected static function initializePropertyGetterCache(string $cacheIdentifier, $subject, string $propertyName)
    {
        if (isset(self::$propertyGetterCache[$cacheIdentifier])) {
            return;
        }
        self::$propertyGetterCache[$cacheIdentifier] = [];
        $uppercasePropertyName = ucfirst($propertyName);
        $getterMethodNames = ['get' . $uppercasePropertyName, 'is' . $uppercasePropertyName, 'has' . $uppercasePropertyName];
        foreach ($getterMethodNames as $getterMethodName) {
            if (is_callable([$subject, $getterMethodName])) {
                self::$propertyGetterCache[$cacheIdentifier]['accessorMethod'] = $getterMethodName;
                return;
            }
        }
        if ($subject instanceof \ArrayAccess) {
            return;
        }
        if (array_key_exists($propertyName, get_object_vars($subject))) {
            self::$propertyGetterCache[$cacheIdentifier]['publicProperty'] = $propertyName;
        }
    }

    /**
     * Gets a property path from a given object or array.
     *
     * If propertyPath is "bla.blubb", then we first call getProperty($object, 'bla'),
     * and on the resulting object we call getProperty(..., 'blubb').
     *
     * For arrays the keys are checked likewise.
     *
     * @param mixed $subject An object or array
     * @param string $propertyPath
     * @return mixed Value of the property
     */
    public static function getPropertyPath($subject, string $propertyPath = null)
    {
        // TODO: This default value handling is only in place for b/c to have this method accept nulls.
        //       It can be removed with Flow 5.0 and other breaking typehint changes.
        if ($propertyPath === null) {
            return null;
        }
        $propertyPathSegments = explode('.', $propertyPath);
        foreach ($propertyPathSegments as $pathSegment) {
            $propertyExists = false;
            $propertyValue = self::getPropertyInternal($subject, $pathSegment, false, $propertyExists);
            if ($propertyExists !== true && (is_array($subject) || $subject instanceof \ArrayAccess) && isset($subject[$pathSegment])) {
                $subject = $subject[$pathSegment];
            } else {
                $subject = $propertyValue;
            }
        }
        return $subject;
    }

    /**
     * Set a property for a given object.
     *
     * Tries to set the property the following ways:
     *
     * - if target is an array, set value
     * - if super cow powers should be used, set value through reflection
     * - if public setter method exists, call it.
     * - if public property exists, set it directly.
     * - if the target object is an instance of ArrayAccess, it sets the property
     *   on it without checking if it existed.
     * - else, return false
     *
     * @param mixed $subject The target object or array
     * @param string|integer $propertyName Name or index of the property to set
     * @param mixed $propertyValue Value of the property
     * @param boolean $forceDirectAccess directly access property using reflection(!)
     * @return boolean true if the property could be set, false otherwise
     * @throws \InvalidArgumentException in case $object was not an object or $propertyName was not a string
     */
    public static function setProperty(&$subject, $propertyName, $propertyValue, bool $forceDirectAccess = false): bool
    {
        if (is_array($subject)) {
            $subject[$propertyName] = $propertyValue;
            return true;
        }

        if (!is_object($subject)) {
            throw new \InvalidArgumentException('subject must be an object or array, ' . gettype($subject) . ' given.', 1237301368);
        }
        if (!is_string($propertyName) && !is_int($propertyName)) {
            throw new \InvalidArgumentException('Given property name/index is not of type string or integer.', 1231178878);
        }

        if ($forceDirectAccess === true) {
            $className = TypeHandling::getTypeForValue($subject);
            if (property_exists($className, $propertyName)) {
                $propertyReflection = new \ReflectionProperty($className, $propertyName);
                $propertyReflection->setAccessible(true);
                $propertyReflection->setValue($subject, $propertyValue);
            } else {
                $subject->$propertyName = $propertyValue;
            }
        } elseif (self::isCollectionPropertyWithAddRemoveMethods($subject, $propertyName, $propertyValue, $addRemoveMethods)) {
            self::updateCollectionWithAddRemoveCalls($subject, $propertyName, $propertyValue, $addRemoveMethods['add'], $addRemoveMethods['remove']);
        } elseif (is_callable(array($subject, $setterMethodName = self::buildSetterMethodName($propertyName)))) {
            $subject->$setterMethodName($propertyValue);
        } elseif ($subject instanceof \ArrayAccess) {
            $subject[$propertyName] = $propertyValue;
        } elseif (array_key_exists($propertyName, get_object_vars($subject))) {
            $subject->$propertyName = $propertyValue;
        } else {
            return false;
        }
        return true;
    }

    /**
     * Check if the given $property is any type that can be assigned to a collection type property and $subject
     * has add* and remove* methods for that property.
     *
     * @param mixed $subject The subject to check the collection property on
     * @param string $propertyName The property name of the collection property
     * @param mixed $propertyValue The new value for the collection property
     * @param array $addRemoveMethods An array given by reference that will hold the inflected add* and remove* method names
     * @return boolean TRUE if $value is either NULL or any collection Type and $subject has both an add* and remove* method for this property
     */
    protected static function isCollectionPropertyWithAddRemoveMethods($subject, $propertyName, $propertyValue, &$addRemoveMethods)
    {
        $isCollectionType = true;
        if ($propertyValue !== null) {
            $propertyType = TypeHandling::getTypeForValue($propertyValue);
            $isCollectionType = TypeHandling::isCollectionType($propertyType);
        }
        if (!$isCollectionType) return false;

        $addRemoveMethods['add'] = self::buildAdderMethodName($propertyName);
        $addRemoveMethods['remove'] = self::buildRemoverMethodName($propertyName);
        return is_callable(array($subject, $addRemoveMethods['add'])) && is_callable(array($subject, $addRemoveMethods['remove']));
    }

    /**
     * @param mixed $subject The subject to update the collection property on
     * @param string $propertyName The property name of the collection property to update
     * @param mixed $propertyValue The new value to change the collection property to
     * @param string $addMethodName The name of the method to use for adding items to the subject's collection property
     * @param string $removeMethodName The name of the method to use for removing items from the subject's collection property
     * @return void
     */
    protected function updateCollectionWithAddRemoveCalls($subject, $propertyName, $propertyValue, $addMethodName, $removeMethodName)
    {
        $itemsToAdd = ($propertyValue instanceof \Traversable) ? iterator_to_array($propertyValue) : (array)$propertyValue;
        $itemsToRemove = array();
        $currentValue = self::getProperty($subject, $propertyName);
        $currentValue = ($currentValue instanceof \Traversable) ? iterator_to_array($currentValue) : (array)$currentValue;
        foreach ($currentValue as $currentItem) {
            foreach ($itemsToAdd as $key => $newItem) {
                if ($currentItem === $newItem) {
                    unset($itemsToAdd[$key]);
                    // Continue to next $currentItem
                    continue 2;
                }
            }
            $itemsToRemove[] = $currentItem;
        }

        foreach ($itemsToRemove as $item) {
            $subject->$removeMethodName($item);
        }
        foreach ($itemsToAdd as $item) {
            $subject->$addMethodName($item);
        }
    }

    /**
     * Returns an array of properties which can be get with the getProperty()
     * method.
     *
     * Includes the following properties:
     *
     * - which can be get through a public getter method.
     * - public properties which can be directly get.
     *
     * @param object $object Object to receive property names for
     * @return array Array of all gettable property names
     * @throws \InvalidArgumentException
     */
    public static function getGettablePropertyNames($object): array
    {
        if (!is_object($object)) {
            throw new \InvalidArgumentException('$object must be an object, ' . gettype($object) . ' given.', 1237301369);
        }
        if ($object instanceof \stdClass) {
            $declaredPropertyNames = array_keys(get_object_vars($object));
            $className = 'stdClass';
            unset(self::$gettablePropertyNamesCache[$className]);
        } else {
            $className = TypeHandling::getTypeForValue($object);
            $declaredPropertyNames = array_keys(get_class_vars($className));
        }

        if (!isset(self::$gettablePropertyNamesCache[$className])) {
            foreach (get_class_methods($object) as $methodName) {
                if (is_callable([$object, $methodName])) {
                    $methodNameLength = strlen($methodName);
                    if ($methodNameLength > 2 && substr($methodName, 0, 2) === 'is') {
                        $declaredPropertyNames[] = lcfirst(substr($methodName, 2));
                    } elseif ($methodNameLength > 3 && (($methodNamePrefix = substr($methodName, 0, 3)) === 'get' || $methodNamePrefix === 'has')) {
                        $declaredPropertyNames[] = lcfirst(substr($methodName, 3));
                    }
                }
            }

            $propertyNames = array_unique($declaredPropertyNames);
            sort($propertyNames);
            self::$gettablePropertyNamesCache[$className] = $propertyNames;
        }
        return self::$gettablePropertyNamesCache[$className];
    }

    /**
     * Returns an array of properties which can be set with the setProperty()
     * method.
     *
     * Includes the following properties:
     *
     * - which can be set through a public setter method.
     * - public properties which can be directly set.
     *
     * @param object $object Object to receive property names for
     * @return array Array of all settable property names
     * @throws \InvalidArgumentException
     */
    public static function getSettablePropertyNames($object): array
    {
        if (!is_object($object)) {
            throw new \InvalidArgumentException('$object must be an object, ' . gettype($object) . ' given.', 1264022994);
        }
        if ($object instanceof \stdClass) {
            $declaredPropertyNames = array_keys(get_object_vars($object));
        } else {
            $className = TypeHandling::getTypeForValue($object);
            $declaredPropertyNames = array_keys(get_class_vars($className));
        }

        foreach (get_class_methods($object) as $methodName) {
            if (substr($methodName, 0, 3) === 'set' && strlen($methodName) > 3 && is_callable([$object, $methodName])) {
                $declaredPropertyNames[] = lcfirst(substr($methodName, 3));
            }
        }

        $propertyNames = array_unique($declaredPropertyNames);
        sort($propertyNames);
        return $propertyNames;
    }

    /**
     * Tells if the value of the specified property can be set by this Object Accessor.
     *
     * @param object $object Object containing the property
     * @param string $propertyName Name of the property to check
     * @return boolean
     * @throws \InvalidArgumentException
     */
    public static function isPropertySettable($object, string $propertyName): bool
    {
        if (!is_object($object)) {
            throw new \InvalidArgumentException('$object must be an object, ' . gettype($object) . ' given.', 1259828920);
        }

        $className = TypeHandling::getTypeForValue($object);
        if (($object instanceof \stdClass && array_key_exists($propertyName, get_object_vars($object))) || array_key_exists($propertyName, get_class_vars($className))) {
            return true;
        }
        return is_callable([$object, self::buildSetterMethodName($propertyName)]);
    }

    /**
     * Tells if the value of the specified property can be retrieved by this Object Accessor.
     *
     * @param object $object Object containing the property
     * @param string $propertyName Name of the property to check
     * @return boolean
     * @throws \InvalidArgumentException
     */
    public static function isPropertyGettable($object, string $propertyName): bool
    {
        if (!is_object($object)) {
            throw new \InvalidArgumentException('$object must be an object, ' . gettype($object) . ' given.', 1259828921);
        }
        if (($object instanceof \ArrayAccess && $object->offsetExists($propertyName)) || ($object instanceof \stdClass && array_key_exists($propertyName, get_object_vars($object)))) {
            return true;
        }
        $uppercasePropertyName = ucfirst($propertyName);
        if (is_callable([$object, 'get' . $uppercasePropertyName]) || is_callable([$object, 'is' . $uppercasePropertyName]) || is_callable([$object, 'has' . $uppercasePropertyName])) {
            return true;
        }
        $className = TypeHandling::getTypeForValue($object);
        return array_key_exists($propertyName, get_class_vars($className));
    }

    /**
     * Get all properties (names and their current values) of the current
     * $object that are accessible through this class.
     *
     * @param object $object Object to get all properties from.
     * @return array Associative array of all properties.
     * @throws \InvalidArgumentException
     * @todo What to do with ArrayAccess
     */
    public static function getGettableProperties($object): array
    {
        if (!is_object($object)) {
            throw new \InvalidArgumentException('$object must be an object, ' . gettype($object) . ' given.', 1237301370);
        }
        $properties = [];
        foreach (self::getGettablePropertyNames($object) as $propertyName) {
            $propertyExists = false;
            $propertyValue = self::getPropertyInternal($object, $propertyName, false, $propertyExists);
            if ($propertyExists === true) {
                $properties[$propertyName] = $propertyValue;
            }
        }
        return $properties;
    }

    /**
     * Build the setter method name for a given property by capitalizing the
     * first letter of the property, and prepending it with "set".
     *
     * @param string $propertyName Name of the property
     * @return string Name of the setter method name
     */
    public static function buildSetterMethodName(string $propertyName): string
    {
        return 'set' . ucfirst($propertyName);
    }

    /**
     * Build the remover method name for a given property by singularizing the name
     * and capitalizing the first letter of the property, then prepending it with "remove".
     *
     * @param string $propertyName Name of the property
     * @return string Name of the remover method name
     */
    public static function buildRemoverMethodName($propertyName)
    {
        return 'remove' . ucfirst(Inflector::singularize($propertyName));
    }

    /**
     * Build the adder method name for a given property by singularizing the name
     * and capitalizing the first letter of the property, then prepending it with "add".
     *
     * @param string $propertyName Name of the property
     * @return string Name of the adder method name
     */
    public static function buildAdderMethodName($propertyName)
    {
        return 'add' . ucfirst(Inflector::singularize($propertyName));
    }

    /**
     * Instantiates the class named `$className` using the `$arguments` as constructor
     * arguments (in array order).
     *
     * @param string $className
     * @param array $arguments
     * @return object
     * @deprecated directly use "new $className(...$arguments)" instead
     */
    public static function instantiateClass($className, $arguments)
    {
        return new $className(...$arguments);
    }
}
