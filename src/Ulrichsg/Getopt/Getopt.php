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
    /** @var Option[] */
    private $optionList = array();
    /** @var HelpTextFormatter */
    private $helpTextFormatter;
    /** @var Result */
    private $result = null;

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
        $this->helpTextFormatter = new DefaultHelpTextFormatter();
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
                if ($option->conflictsWith($otherOption)) {
                    throw new \InvalidArgumentException('Failed to add options due to conflict');
                }
                if ($option->equals($otherOption)) {
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
        $scriptName = $_SERVER['PHP_SELF'];
        if (!isset($arguments)) {
            global $argv;
            $arguments = $argv;
            $scriptName = array_shift($arguments); // $argv[0] is the script's name
        } elseif (is_string($arguments)) {
            $arguments = explode(' ', $arguments);
        }
        $this->helpTextFormatter->setScriptName($scriptName);

        $parser = new CommandLineParser($this->optionList);
        $this->result = $parser->parse($arguments);
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
        return isset($this->result) ? $this->result->getOption($name) : null;
    }

    /**
     * Returns the list of options. Must be invoked after parse() (otherwise it returns an empty array).
     *
     * @return array
     */
    public function getOptions()
    {
        return isset($this->result) ? $this->result->getOptions() : array();
    }

    /**
     * Returns the list of operands. Must be invoked after parse().
     *
     * @return array
     */
    public function getOperands()
    {
        return isset($this->result) ? $this->result->getOperands() : array();
    }

    /**
     * Returns the i-th operand (starting with 0), or null if it does not exist. Must be invoked after parse().
     *
     * @param int $i
     * @return string
     */
    public function getOperand($i)
    {
        return isset($this->result) ? $this->result->getOperand($i) : null;
    }

    /**
     * Returns the banner string
     *
     * @return string
     */
    public function getBanner()
    {
        return $this->helpTextFormatter->getBanner();
    }

    /**
     * Set the banner string
     *
     * @param string $banner    The banner string; will be passed to sprintf(), can include %s for current scripts name.
     *                          Be sure to include a trailing line feed.
     * @return Getopt
     */
    public function setBanner($banner)
    {
        $this->helpTextFormatter->setBanner($banner);
        return $this;
    }

    public function getHelpText($padding = 25)
    {
        return $this->helpTextFormatter->getHelpText($this->optionList, $padding);
    }


    /*
     * Interface support functions
     */

    public function count()
    {
        return isset($this->result) ? count($this->result->getOptions()) : 0;
    }

    public function offsetExists($offset)
    {
        $options = $this->getOptions();
        return isset($options[$offset]);
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
        foreach ($this->getOptions() as $name => $value) {
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
