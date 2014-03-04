<?php

namespace Ulrichsg\Getopt;

/**
 * Getopt.PHP allows for easy processing of command-line arguments.
 * It is a more powerful, object-oriented alternative to PHP's built-in getopt() function.
 *
 * @version 2.1.0
 * @license MIT
 * @link    http://ulrichsg.github.io/getopt-php
 */
class Getopt implements \Countable, \ArrayAccess, \IteratorAggregate
{
    const NO_ARGUMENT = 0;
    const REQUIRED_ARGUMENT = 1;
    const OPTIONAL_ARGUMENT = 2;

    /** @var OptionParser */
    private $optionParser;
    /** @var string */
    private $scriptName;
    /** @var Option[] */
    private $optionList = array();
    /** @var array */
    private $options = array();
    /** @var array */
    private $operands = array();

    /** @var boolean
     * "quirks mode" is referenced in issue #14, as the preferred  
     * method to allow undefined options to be accepted instead
     * of throwing an Exception (default behavior). To enable, 
     * Getopt->setQuirksMode(true) before calling Getopt->parse().
     * During run-time, all option strings will be accepted and added to
     * the list of acceptable options if not already there.
     * TODO: in the future it would be nice to be able to toggle different 
     * settings for quirks mode.
     * @link https://github.com/ulrichsg/getopt-php/issues/14
     */
    private $quirksMode = false;
    

    
    /**
     * Creates a new Getopt object.
     *
     * The argument $options can be either a string in the format accepted by the PHP library
     * function getopt() or an array.
     *
     * @param mixed $options Array of options, a String, or null (see documentation for details)
     * @param int $defaultType The default option type to use when omitted (optional)
     * @throws \InvalidArgumentException
     *
     * @link https://www.gnu.org/s/hello/manual/libc/Getopt.html GNU Getopt manual
     */
    public function __construct($options = null, $defaultType = Getopt::NO_ARGUMENT)
    {
        $this->optionParser = new OptionParser($defaultType);
        if ($options !== null) {
            $this->addOptions($options);
        }
    }

    /**
     * Extends the list of known options. Takes the same argument types as the constructor.
     *
     * @param mixed $options
     * @throws \InvalidArgumentException
     */
    public function addOptions($options)
    {
        if (is_string($options)) {
            $this->mergeOptions($this->optionParser->parseString($options));
        } elseif (is_array($options)) {
            $this->mergeOptions($this->optionParser->parseArray($options));
        } else {
            throw new \InvalidArgumentException("Getopt(): argument must be string or array");
        }
    }

    /**
     * Merges new options with the ones already in the Getopt optionList, making sure the resulting list is free of
     * conflicts.
     *
     * @param Option[] $options The list of new options
     * @throws \InvalidArgumentException
     */
    private function mergeOptions(array $options)
    {
        /** @var Option[] $mergedList */
        $mergedList = array_merge($this->optionList, $options);
        $duplicates = array();
        foreach ($mergedList as $option) {
            foreach ($mergedList as $otherOption) {
                if (($option === $otherOption) || in_array($otherOption, $duplicates)) {
                    continue;
                }
                if ($this->optionsConflict($option, $otherOption)) {
                  if ( !$this->getQuirksMode() ) {
                    throw new \InvalidArgumentException('Failed to add options due to conflict');
                  } else {
                    //quirks mode: ignore existing argument 
                    continue;
                  }
                }
                if (($option->short() === $otherOption->short()) && ($option->long() === $otherOption->long())) {
                    $duplicates[] = $option;
                }
            }
        }
        foreach ($mergedList as $index => $option) {
            if (in_array($option, $duplicates)) {
                unset($mergedList[$index]);
            }
        }
        $this->optionList = array_values($mergedList);
    }

    private function optionsConflict(Option $option1, Option $option2) {
        if ((is_null($option1->short()) && is_null($option2->short()))
                || (is_null($option1->long()) && is_null($option2->long()))) {
            return false;
        }
        return ((($option1->short() === $option2->short()) && ($option1->long() !== $option2->long()))
                || (($option1->short() !== $option2->short()) && ($option1->long() === $option2->long())));
    }

    /**
     * Evaluate the given arguments. These can be passed either as a string or as an array.
     * If nothing is passed, the running script's command line arguments are used.
     *
     * An {@link \UnexpectedValueException} or {@link \InvalidArgumentException} is thrown
     * when the arguments are not well-formed or do not conform to the options passed by the user.
     *
     * @param mixed $arguments optional ARGV array or space separated string
     */
    public function parse($arguments = null)
    {
        $this->options = array();
        if (!isset($arguments)) {
            global $argv;
            $arguments = $argv;
            $this->scriptName = array_shift($arguments); // $argv[0] is the script's name
        } elseif (is_string($arguments)) {
            $this->scriptName = $_SERVER['PHP_SELF'];
            $arguments = explode(' ', $arguments);
        }
       
        /* right now quirks mode is limited to allowing the options passed to be
         * dynamically set at runtime.  if the argument is a flag (prefix: '-|--'),
         * parse it and call addOptions with a new Getopt\Option.
         * This also passes the current state of quirks mode into the 
         * CommandLineParser class.
         * See Getopt->$quirksMode for a description of quirks mode.
        */ 
        if ($this->getQuirksMode())
          foreach($arguments as $arg) {
            if (substr($arg,0,1)=='-'){
              if (substr($arg,0,2)=='--') {
                //add a long option
                $arg=substr($arg,2,strlen($arg));
                
                $foundEq = (strpos($arg,'='))!==FALSE;
                list($name,$value) = explode('=', $foundEq?"$arg=1":"$arg=1");
                $o = new Option(null, $name, 
                    !$foundEq?Getopt::NO_ARGUMENT: Getopt::REQUIRED_ARGUMENT);
                if ( $foundEq )
                {
                  //the option was passed with a value, so
                  //set it as the default value. REQUIRED_ARGUMENT state
                  //is set during Getopt\Option creation.
                  
                  //force '1' instead of empty values ("--myarg=")
                  $o->setDefaultValue($value==""?1:$value);
                }
                $this->addOptions( array($o) );
              } else {
                //add a short option
                $arg=substr($arg,1,strlen($arg)); //strip off leading '-'
                $this->addOptions("$arg");
              }
            } //end flag testing
          } //end foreach arguments
        // -- end quirks mode argument processing --
        
        $parser = new CommandLineParser($this->optionList);
        $parser->setQuirksMode($this->getQuirksMode());
        $parser->parse($arguments);
        $this->options = $parser->getOptions();
        $this->operands = $parser->getOperands();
    }

    /**
     * Returns the value of the given option. Must be invoked after parse().
     *
     * The return value can be any of the following:
     * <ul>
     *   <li><b>null</b> if the option is not given and does not have a default value</li>
     *   <li><b>the default value</b> if it has been defined and the option is not given</li>
     *   <li><b>an integer</b> if the option is given without argument. The
     *       returned value is the number of occurrences of the option.</li>
     *   <li><b>a string</b> if the option is given with an argument. The returned value is that argument.</li>
     * </ul>
     *
     * @param string $name The (short or long) option name.
     * @return mixed
     */
    public function getOption($name)
    {
        return isset($this->options[$name]) ? $this->options[$name] : null;
    }

    /**
     * Returns the list of options. Must be invoked after parse() (otherwise it returns an empty array).
     *
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Returns the list of operands. Must be invoked after parse().
     *
     * @return array
     */
    public function getOperands()
    {
        return $this->operands;
    }

    /**
     * Returns the i-th operand (starting with 0), or null if it does not exist. Must be invoked after parse().
     *
     * @param int $i
     * @return string
     */
    public function getOperand($i)
    {
        return ($i < count($this->operands)) ? $this->operands[$i] : null;
    }

    /**
     * Returns an usage information text generated from the given options.
     * - Keep in mind that this is irrelevant when quirks mode is enabled,
     *  as it allows any options to be passed at runtime. - trick@github
     * @param int $padding Number of characters to pad output of options to
     * @return string
     */
    public function getHelpText($padding = 25)
    {
        $helpText = sprintf("Usage: %s [options] [operands]\n", $this->scriptName);
        $helpText .= "Options:\n";
        foreach ($this->optionList as $option) {
            $mode = '';
            switch ($option->mode()) {
                case self::NO_ARGUMENT:
                    $mode = '';
                    break;
                case self::REQUIRED_ARGUMENT:
                    $mode = "<arg>";
                    break;
                case self::OPTIONAL_ARGUMENT:
                    $mode = "[<arg>]";
                    break;
            }
            $short = ($option->short()) ? '-'.$option->short() : '';
            $long = ($option->long()) ? '--'.$option->long() : '';
            if ($short && $long) {
                $options = $short.', '.$long;
            } else {
                $options = $short ? : $long;
            }
            $padded = str_pad(sprintf("  %s %s", $options, $mode), $padding);
            $helpText .= sprintf("%s %s\n", $padded, $option->getDescription());
        }
        return $helpText;
    }

    /**
     * Returns the state of "quirks mode". see Getopt->$quirksMode for a full description.
     * @return boolean
     */
    function getQuirksMode()
    {
      return $this->quirksMode;
    }
    
    /**
     * Sets the state of quirks mode. see Getopt->$quirksMode for a full description.
     * @param boolean $value
     */
    function setQuirksMode($value)
    {
      return $this->quirksMode = $value;
    }

    /*
     * Interface support functions
     */

    public function count()
    {
        return count($this->options);
    }

    public function offsetExists($offset)
    {
        return isset($this->options[$offset]);
    }

    public function offsetGet($offset)
    {
        return $this->getOption($offset);
    }

    public function offsetSet($offset, $value)
    {
        throw new \LogicException('Getopt is read-only');
    }

    public function offsetUnset($offset)
    {
        throw new \LogicException('Getopt is read-only');
    }

    public function getIterator()
    {
        // For options that have both short and long names, $this->options has two entries.
        // We don't want this when iterating, so we have to filter the duplicates out.
        $filteredOptions = array();
        foreach ($this->options as $name => $value) {
            $keep = true;
            foreach ($this->optionList as $option) {
                if ($option->long() == $name && !is_null($option->short())) {
                    $keep = false;
                }
            }
            if ($keep) {
                $filteredOptions[$name] = $value;
            }
        }
        return new \ArrayIterator($filteredOptions);
    }
}