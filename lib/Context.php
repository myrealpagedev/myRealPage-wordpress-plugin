<?php

/**
 * Class Context - represents context of a request including MRP-specific variables from the embedded shortcode.
 *
 */
namespace MRPIDX;

class Context
{
    protected $methods;
    protected $values;

    public function __construct($values = array())
    {
        // set up default values
        $this->values = array(
                'context' => '',
                'listingDef' => '',
                'initAttr' => '',
                'permAttr' => '',
                'accountId' => '',
                'pageName' => '',
                'extension' => '',
                'detailsDef' => '',
                'detailsPhotosDef' => '',
                'detailsVideosDef' => '',
                'searchformDef' => '',
                'googleMapApiKey' => '',
                'debug' => false
            );

        // normalize keys
        $newValues = array();
        foreach ($values as $name => $value) {
            if (is_int($name)) {
                continue;
            }
            $newValues[lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $name))))] = $value;
        }

        $this->values = $newValues + $this->values;

        // create getters and setters for each value along with their defaults
        foreach ($this->values as $name => $value) {
            $this->addValue($name, $value);
        }
    }

    /**
     * Getter for cases where we don't need or want to use the dynamic code.
     */
    function get($name, $default = "")
    {
        return $this->getValue($name, $default);
    }

    function set($name, $value)
    {
        return $this->setValue($name, $value);
    }

    function has($name)
    {
        return $this->hasValue($name);
    }

    /**
     * Trick to get dynamic methods to work - see if we have a closure we can use, and if so, use it.
     *
     * Note that this method gets invoked when invoking inaccessible methods, so regular functions will work just fine.
     */
    function __call($method, $args)
    {
        if (is_callable($this->methods[$method])) {
            return call_user_func_array($this->methods[$method], $args);
        }
    }

    /**
     * Uses closures to create a dynamic getter and setter for the named value. For example, the name "foo" will
     * generate "getFoo()" and "setFoo($value)".
     *
     * @param string $name name of the field we're adding
     * @param mixed|null $default default value if none provided
     */
    public function addValue($name, $default = null)
    {
        $getter = function () use ($name, $default) {
            return $this->getValue($name, $default);
        };
        $setter = function ($_value) use ($name) {
            $this->setValue($name, $_value);
            return $this;
        };
        $exists = function ($_value) use ($name) {
            return $this->hasValue($name);
        };

        // re-jig the name so we get nice "getFooBar" and "setFooBar" method names
        $baseName = strtoupper(substr($name, 0, 1)) . substr($name, 1);
        $this->methods["get" . $baseName] = \Closure::bind($getter, $this, get_class());
        $this->methods["set" . $baseName] = \Closure::bind($setter, $this, get_class());
        $this->methods["has" . $baseName] = \Closure::bind($exists, $this, get_class());
    }
    
    public function getAllValues() {
	    
	    return $this->values;
    }

    private function getValue($name, $default = null)
    {
        return $this->hasValue($name) ? $this->values[$name] : $default;
    }

    private function hasValue($name)
    {
        return isset($this->values[$name]) && strlen(trim($this->values[$name]));
    }

    private function setValue($name, $value)
    {
        if ($this->has($name)) {
            $this->values[$name] = $value;
        }
        return $this;
    }
} 