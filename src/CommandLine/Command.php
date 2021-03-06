<?php
namespace Worklog\CommandLine;

use Carbon\Carbon;
use Worklog\Application;
use Worklog\Concerns\LogTrait;

/**
 * Command
 */
class Command
{
    // use LogTrait;


    public $command_name;

    protected $App;

    protected $db;

    protected $initialized = false;

    protected $internally_invoked;

    protected $pid;

    protected $output;

    private $required_data = [];

    private $data;

    private $Options;

    private $shortopts = '';

    private $longopts = [];

    protected static $_data;

    protected static $exception_strings = [
        'date_format' => 'Date must be a valid format, eg. YYYY-MM-DD',
        'time_format' => 'Start/stop times must be a time format: HH:MM',
        'no_input' => 'Command has no input'
    ];

    protected static $registry;

    protected static $aliases = [];

    const ERROR_UNREGISTERED_COMMAND = "Invalid command \"%s\"";

    public function __construct($command = [])
    {
        $this->App = App();
        $this->db = $this->App()->db();

        $command = (array) $command;

        if (isset($command[0])) {
        	$this->command_name = $command[0];
        }
    }

    public function init()
    {
        if (! $this->initialized()) {
            if ($config_file = $this->option('configuration')) {
                $this->config_file = $config_file;
            }
        }

        $this->initialized(true);
    }

    public function initialized($set = null) {
        if (!is_null($set)) {
            $this->initialized = (bool)$set;
        }

        return $this->initialized;
    }

    protected static function getSubcommandRegisteredException($subcommand, ...$append) {
        $message = sprintf("Error: subcommand %s is already registered", $subcommand);
        foreach ($append as $key => $value) {
            $message .= ', ' . $value;
        }

        return new \InvalidArgumentException($message);
    }

    protected static function getInvalidSubcommandException($subcommand, ...$append) {
        $message = sprintf("%s: invalid sub-command", $subcommand);
        foreach ($append as $key => $value) {
            $message .= ', ' . $value;
        }

        return new \InvalidArgumentException($message);
    }

    public function getSubcommand($subcommand = null) {
        if (is_null($subcommand)) {
            if (isset($this->subcommand))
                return $this->subcommand;
        } else {
            if ($this->validateSubcommand($subcommand)) {
                return $this->subcommands[$subcommand];
            }
        }
    }

    public function validateSubcommand($subcommand, $callable_or_method = null) {
        $valid = false;
        $valid_subcommands = $this->getValidSubcommands();

        if (is_null($callable_or_method) && in_array($subcommand, $valid_subcommands)) {
            $valid = true;
        } elseif (!is_null($callable_or_method)) {
            // callable is valid
            $valid = is_callable($callable_or_method);

            // invalidate $subcommand = '_something' to prevent "__something" from being invoked
            if (!$valid && substr($callable_or_method, 0, 1) !== '_') {
                // local _ prefixed method is valid
                $valid = method_exists($this, '_' . $callable_or_method);
            }
        }

        return $valid;
    }

    protected function authorizeSubcommand($subcommand) {
        $can = false;

        if (!is_null($subcommand) && $this->validateSubcommand($subcommand)) {
            $can = true;
        } else {
            throw new \InvalidArgumentException('Valid subcommands: ' . implode(', ', $this->getValidSubcommands()), 1);
        }

        return $can;
    }

    protected function getValidSubcommands() {
        $commands = [];

        if (isset($this->subcommands)) {
            $commands = array_keys($this->subcommands);
        }

        return $commands;
    }

    protected function runSubcommand($subcommand) {
        if (false !== $this->authorizeSubcommand($subcommand)) {
            $this->setSubcommand($subcommand);
            if (is_callable($this->subcommands[$subcommand])) {
                $function = $this->subcommands[$subcommand];

                return $function($this);
            } else {
                return call_user_func_array([$this, '_' . ltrim($subcommand, '_')], []);
            }
        }
    }

    protected function setSubcommand($subcommand) {
        if ($this->validateSubcommand($subcommand)) {
            $this->subcommand = $subcommand;
        } else {
            throw static::getInvalidSubcommandException($subcommand);
        }

        return $this;
    }

    protected function registerSubcommand($subcommand, $callable_or_method = null) {
        if (is_null($callable_or_method))
            $callable_or_method = $subcommand;

        if ($this->validateSubcommand($subcommand, $callable_or_method)) {
            if (!isset($this->subcommands)) {
                $this->subcommands = [];
            }
            if (!array_key_exists($subcommand, $this->subcommands)) {
                $this->subcommands[$subcommand] = $callable_or_method;
            } else {
                throw static::getSubcommandRegisteredException($subcommand);
            }
        } else {
            throw static::getInvalidSubcommandException(
                $subcommand,
                sprintf("%s is not callable nor a local method", $callable_or_method)
            );
        }
    }

    public function name()
    {
        $name = '';
        if (isset($this->command_name)) {
            $name = $this->command_name;
        }

        return $name;
    }

    /**
     * New up the specified class.
     * Based on Laravel's ServiceContainer
     * @param  mixed       $concrete   Name of the class to create
     * @param  array       $parameters constructor parameters
     * @param  Application $App        he Application instance
     * @return object
     */
    public function build($concrete, array $parameters = [])
    {
        if ($concrete instanceof Closure) {
            return $concrete($parameters);
        }
        $reflector = new \ReflectionClass($concrete);
        $constructor = $reflector->getConstructor();

        if (is_null($constructor)) {
            return new $concrete();
        }

        return $reflector->newInstanceArgs($parameters);
    }

    /**
     * Register a Command class to a string command
     * @param $commands
     * @param array $config The command configuration array
     * @internal param string $command The command line string name, eg. "list"
     */
    public static function bind($commands, $config = [])
    {
        if (! is_array($commands)) $commands = [ $commands ];
        if (! is_array($config)) $config = [ 'class' => $config ];
        if (! array_key_exists('class', $config)) {
            throw new \InvalidArgumentException('Command configuration array requires a "class" key');
        }
        $class = new \ReflectionClass($config['class']);
        $command = '';
        foreach ($class->getStaticProperties() as $propertyName => $value) {
            if (in_array($propertyName, [ 'arguments', 'description', 'options', 'usage', 'menu' ])) {
                $config[$propertyName] = $value;
            }
        }
        foreach ($commands as $key => $alias) {
            if ($key == 0) {
                $command = $alias;
                static::register_command($command, $config);
            } else {
                static::register_alias($command, $alias);
            }
        }
    }

    public static function call($Command, $decorate = null)
    {
    	if (is_string($Command) && isset(static::$registry[$Command])) {
    		$Command = Command::instance($Command);
    	}
        if (!($Command instanceof Command)) {
        	if (! is_callable($decorate)) {
        		$Command = new $Command($decorate);
        	} else {
        		$Command = new $Command();
        	}
        }

        $Command->resolve();

        if (is_callable($decorate)) {
            $Command = call_user_func($decorate, $Command);
        }
        return $Command->run();
    }

    public static function instance($command)
    {
        if (strlen($command) < 1) {
            throw new \InvalidArgumentException('No command specified');
        }
        $AbstractCommand = new static();
        $Command = $AbstractCommand->build(static::$registry[$command]['class']);
        $Command->command_name = $command;

        return $Command;
    }

    public function set_invocation_flag($value = true)
    {
        $this->internally_invoked = $value;
    }

    public function internally_invoked()
    {
        return (bool) $this->internally_invoked;
    }

    /**
     * Register a command configuration
     * @param  string $command The command line input "name"
     * @param  array  $config  The command configuration array
     * @return null
     */
    protected static function register_command($command, $config)
    {
        static::$registry[$command] = $config;
    }

    /**
     * Register a command alias
     * @param  string $command The command line input "name"
     * @param  string $alias   The command line input string representing $command
     * @return null
     */
    protected static function register_alias($command, $alias)
    {
        static::$aliases[$alias] = $command;
    }

    public function register_option(Option $Option)
    {
        if ($option_str = $Option->get_option_string()) {
            if ($Option->is_short()) {
                $this->shortopts($option_str);
            } else {
                $this->longopts($option_str);
            }
        }

        return $this;
    }

    public function shortopts($value = null)
    {
        if (! is_null($value)) {
            $this->shortopts .= $value;
        }

        return $this->shortopts;
    }

    public function longopts($value = null)
    {
        if (! is_null($value)) {
            $this->longopts[] = $value;
        }

        return $this->longopts;
    }

    public static function registry()
    {
        return static::$registry;
    }

    /**
     * @param array $args
     * @param string $must_be
     * @return mixed|null
     */
    public static function shift(&$args = [], $must_be = '')
    {
        $pulled = null;
        if (empty($must_be) || (isset($args[0]) && $args[0] == $must_be)) {
            $pulled = array_shift($args);
        }

        return $pulled;
    }

    public static function normalize_args($args = [])
    {
        if (! is_array($args)) {
            $args = [ $args ];
        }
        if (empty($args)) {
            $args = Options::argv();
        }

        return $args;
    }

    /**
     * Determine which command was run (from argv OR $_REQUEST)
     * @return [type] [description]
     */
    public function infer($args = [])
    {
        $command = '';
        $default = (defined('DEFAULT_COMMAND') ? DEFAULT_COMMAND : 'help');
        $args = static::normalize_args($args);
        $arg_count = count($args);

        if (isset(static::$registry)) {
            if ($arg_count > 0) {
                if ($matches = array_intersect($args, array_keys(static::$registry))) {
                    foreach ($matches as $key => $argument) {
                        if ($this->validate_command($argument)) {
                            $command = $args[$key] = $this->alias($argument);
                            break;
                        }
                    }
                }
                if (! $command) {
                    foreach ($args as $key => $argument) {
                        if ($this->validate_command($argument)) {
                            $command = $args[$key] = $this->alias($argument);
                            break;
                        }
                    }
                }
                if (! $command) {
                	throw new \InvalidArgumentException(sprintf("Invalid command \"%s\"", $args[0]));
                }
            }
        }

        if (empty($command)) {
            $command = $default;
        }

        return $command;
    }

    /**
     * Parse command arguments into Command data
     * @param array $args The $argv array
     * @param array $keys An array of keys to map values against
     */
    private function parse_arguments($args = [])
    {
        $keys = [];
        $command = $this->name();

        if (isset(static::$arguments)) {
            $keys = static::$arguments;
        } elseif (isset(static::$registry[$command]['arguments'])) {
            $keys = static::$registry[$command]['arguments'];
        }

        if (count($args) > 0) {
            // remove the command and any options/flags
            foreach ($args as $key => $arg) {
                if ($arg == $command || substr($arg, 0, 1) == '-' || strlen($arg) === 0) {
                    unset($args[$key]);
                }
            }
        }

        if (count($args) > 0) {
            $args = array_values($args);
            if (count($args) == count($keys)) {
                foreach (array_combine($keys, $args) as $key => $value) {
                    $this->addData($key, $value);
                }
            } else {
                $name = '';
                $arg_index = 0;
                foreach ($args as $key => $value) {
                    if (isset($keys[$key])) {
                        $name = $keys[$key];
                    }
                    if (is_int($name) || strlen($name) < 1) {
                        $name = $arg_index;
                    }
                    $this->addData($name, $value);
                    $arg_index++;
                }
            }
        }
    }

    /**
     * Parse default data into Command instance
     * @return null
     */
    public function parse_static_data()
    {
        $command = $this->name();
        if (isset(static::$registry[$command])) {
	        if (isset(static::$registry[$command]['data'])) {
	            foreach (static::$registry[$command]['data'] as $key => $value) {
	                $this->setData($key, $value);
	            }
	        }
	    }
	    if (isset(static::$data)) {
	    	foreach (static::$data as $key => $value) {
	    		$this->setData($key, $value);
	    	}
	    }
    }

    /**
     * Check if the command is configured
     * @param  string $command The command name
     * @return [type] [description]
     */
    public function validate_command($command)
    {
        $exists = false;
        if (isset(static::$registry)) {
            if (! $exists = array_key_exists($command, static::$registry)) {
                $exists = $this->alias($command, false);
            }
        }

        return $exists;
    }

    /**
     * Return the command the alias points to
     * @param  string $alias
     * @param  bool   $normalize Returns a string when set to true
     * @return string
     */
    public function alias($alias, $normalize = true)
    {
        $command = ($normalize ? $alias : false);
        if (isset(static::$registry)) {
            if (! array_key_exists($alias, static::$registry)) {
                $command = ($normalize ? '' : false);
                if (array_key_exists($alias, static::$aliases)) {
                    $command = ($normalize ? static::$aliases[$alias] : true);
                }
            } elseif (! $normalize) {
                $command = true;
            }
        }

        return $command;
    }

    /**
     * Get an option
     * @param  string  $name The flag/option name
     * @param  boolean $flag Option is a flag (boolean)
     * @return mixed
     */
    public function option($name, $flag = null)
    {
        $name = ltrim($name, '-');

        if ($Options = $this->Options()) {
            if ($Options->Option($name)) {
                if ($flag !== false && $Options->Option($name)->is_flag()) {
                    return $Options->exist($name)/* || (bool) $this->getData($name)*/;
                } else {
                    return $Options->Option($name)->value()/* || $this->getData($name)*/;
                }
            }
        }
    }

    protected function argument($name) {
        if ($Options = $this->Options()) {
            foreach ($Options->args() as $key => $value) {
                if ($key == $name) {
                    return $value;
                }
            }
        } else {
            debug_print_backtrace();
        }
    }

    protected function arguments($pull = '')
    {
        $args = [];

        if ($Options = $this->Options()) {
        	if ($args = $this->Options()->args()) {
                if (! empty($pull)) {
                    Command::shift($args, $pull);
                }
            }
        }

        return $args;
    }

    protected function flag($key)
    {
        $return = false;
        $flags = $this->flags();
        if (array_key_exists($key, $flags)) {
            $return = $flags[$key];
        }

        return $return;
    }

    protected function flags()
    {
        $flags = [];
        
        if ($Options = $this->Options()) {
        	$flags = $this->Options()->all();
        }

        return $flags;
    }

    public function setArgument($offset, $value)
    {
        if ($Options = $this->Options()) {
        	$Options->setArgument($offset, $value);
        }
    }

    public function getArgument($offset)
    {
        if ($Options = $this->Options()) {
        	return $Options->getArgument($offset);
        }
    }

    public function unsetArgument($offset)
    {
        if ($Options = $this->Options()) {
        	$Options->unsetArgument($offset);
        }
    }

    public function setOption($offset, $value)
    {
        if ($Options = $this->Options()) {
        	$Options->setOption($offset, $value);
        }
    }

    public function getOption($offset)
    {
        if ($Options = $this->Options()) {
        	return $Options->getOption($offset);
        }
    }

    public function unsetOption($offset)
    {
        if ($Options = $this->Options()) {
        	$Options->unsetOption($offset);
        }
    }

    /**
     * Get the process ID
     *
     * @return int
     */
    public function pid()
    {
        return $this->pid;
    }

    /**
     * Run this command
     */
    public function run()
    {
        if (method_exists($this, 'init') && $this->initialized === false) {
            $this->init();
        }
        if ($args = func_get_args()) {
            foreach (args as $name) {
                $this->expectData($name);
            }
        }

        return $this->getOutput();
    }

    public function getOutput()
    {
    	if (isset($this->output)) {
            return $this->output;
        }
    }



/*
        try {
            $this->Command = (new CommandLine\Command())->resolve();
            $this->Command->scan();

            return $this->Command->run();
        } catch (\Exception $e) {
            throw $e;
        }
*/



    /**
     * Parse command arguments and return a Command object
     * @param  array   $args The command and command arguments
     * @return Command
     */
    public function resolve($args = [])
    {
        $args = static::normalize_args($args);

        if ($command = $this->infer($args)) {
            $Command = static::instance($command);
            $Command->setOptions();
            $Command->parse_static_data();
            // // $Command->parse_arguments($args);
        } else {
            throw new \InvalidArgumentException(sprintf("Invalid command \"%s\"", $args[0]));
        }

        return $Command;
    }

    public function scan()
    {
        if (! $this->Options()->Command()) {
            $this->Options()->set_Command($this);
        }
        $this->Options()->scan();

        return $this;
    }

    /**
     * Return usage information in an array of strings (lines)
     * @param  boolean $long  [description]
     * @param  boolean $short [description]
     * @return array
     */
    public function usage($long = false, $short = false)
    {
        $lines = $options_lines = [];
        $options_string = '';

        if (isset($this->command_name)) {
            if (isset(static::$options)) {
                foreach (static::$options as $option => $option_config) {
                    if (empty($options_string)) $options_string .= '-';
                    $options_string .= $option;
                    $options_lines[$option] = sprintf("%19.19s %-5.5s %-78.78s\n",
                        '-'.$option, '', $option_config['description']
                    );
                }
                if (! empty($options_string)) $options_string = '['.$options_string.']';
            }

            if ($long) {

                if (! $short && isset(static::$description)) {
                    $lines[] = static::$description."\n";
                }

                if (isset(static::$usage)) {
                    $lines[] = sprintf("Usage: %-93.93s\n",
                        SCRIPT_NAME.' '.sprintf(static::$usage, $this->command_name)
                    );
                } else {
                    $usage_line = SCRIPT_NAME.' '.$this->command_name;
                    if (! empty($options_string)) {
                        $usage_line .= ' '.$options_string;
                    }
                    $lines[] = sprintf("Usage: %-93.93s\n", $usage_line);
                }

                if (! empty($options_lines)) {
                    $lines = array_merge($lines, $options_lines);
                }
            } else {
                $description = (isset(static::$description) ? static::$description : '');
                $lines[] = sprintf("%19.19s %-10.10s %-71.71s\n",
                    $this->command_name, $options_string, $description
                );
            }
        }

        return $lines;
    }

    public function App()
    {
        if (isset($this->App)) {
            return $this->App;
        }
        throw new \Exception('App object not set on the Command');
    }

    public function Options()
    {
        return $this->Options;
    }

    public function setOptions()
    {
        if (isset(static::$registry)) {
            $this->Options = new Options(static::$registry, $this);
        } else {
            $this->Options = new Options([], $this);
        }

        return $this->Options;
    }

    public function setData($name, $value)
    {
        if (! isset($this->data)) $this->data = [];
        $this->data[$name] = $value;

        return $this;
    }

    public function addData($name, $value)
    {
        if (! isset($this->data)) $this->data = [];
        if (! isset($this->data[$name]) || is_null($this->getData($name))) {
            $this->data[$name] = $value;
        } else {
            $data = $this->getData($name) ?: [];
            if (! is_array($data)) {
                $data = [ $data ];
            }
            $data[] = $value;
            $this->setData($name, $data);
        }

        return $this;
    }

    public function getData($name, $default = null)
    {
        if (isset($this->data) && array_key_exists($name, $this->data)) {
            return $this->data[$name];
        }

        return static::get_data($name, $default);
    }

    /**
     * Throws an exception if the key is not set on data
     * @param  mixed      $name
     * @param  string     $exception_message
     * @return $this
     * @throws \Exception
     */
    public function expectData($name = null, $exception_message = '')
    {
        if (is_string($name) && ! empty($name)) {
            if (false === $this->getData($name, false)) {
                throw new \Exception($exception_message ?: sprintf('Command expecting data at index "%s"', $name));
            }
        } else {
            if (is_array($name)) {
                foreach ($name as $key => $_name) {
                    $this->required_data[] = $_name;
                }
            }
            if (! empty($this->required_data)) {
                foreach ($this->required_data as $name) {
                    $this->expectData($name);
                }
            }
        }

        return $this;
    }

    public static function set_data($name, $value)
    {
        if (! isset(static::$_data)) static::$_data = [];
        static::$_data[$name] = $value;
    }

    public static function get_data($name, $default = null)
    {
        if (isset(static::$_data)) {
            if (array_key_exists($name, static::$_data)) {
                return static::$_data[$name];
            }
        }

        return $default;
    }

    protected function data()
    {
        if (isset($this->data)) {
            return $this->data;
        }
    }

    /**
     * Parse a date string representation
     * @param $response
     * @param $return_obj
     * @return string
     * @throws \Exception
     */
    protected static function parse_date_input($response, $return_obj = false)
    {
        $Date = false;

        if (false !== strpos($response, ' ')) {
            $parts = explode(' ', $response);
            if (! empty($parts[0])) {
                $response = $parts[0];
            } else {
                throw new \Exception(static::$exception_strings['date_format']);
            }
        }

        if (false !== strpos($response, '-')) {
            $parts = explode('-', $response);

            if (count($parts) !== 3) {
                throw new \Exception(static::$exception_strings['date_format']);
            }
            if (strlen($parts[0]) < 4 && intval($parts[0]) > 31) {
                throw new \Exception(static::$exception_strings['date_format']);
            }
            if (strlen($parts[1]) < 4 && intval($parts[1]) > 31) {
                throw new \Exception(static::$exception_strings['date_format']);
            }
            if (strlen($parts[0]) < 4 && strlen($parts[1]) < 4) {
                if (intval($parts[0]) > 12 && intval($parts[1]) > 12) {
                    throw new \Exception(static::$exception_strings['date_format']);
                }
            }
            $response = implode('/', $parts);
        } elseif (false !== strpos($response, '/')) {
            $parts = explode('/', $response);

            if (count($parts) !== 3) {
                throw new \Exception(static::$exception_strings['date_format']);
            }
            if (strlen($parts[1]) < 4 && intval($parts[1]) > 31) {
                throw new \Exception(static::$exception_strings['date_format']);
            }
            if (strlen($parts[0]) < 4 && intval($parts[0]) > 31) {
                throw new \Exception(static::$exception_strings['date_format']);
            }
            if (strlen($parts[0]) < 4 && strlen($parts[1]) < 4) {
                if (intval($parts[0]) > 12 && intval($parts[1]) > 12) {
                    throw new \Exception(static::$exception_strings['date_format']);
                }
            }
            $response = implode('/', $parts);
        } else {
            throw new \Exception(static::$exception_strings['date_format']);
        }

        try {
            $Date = Carbon::parse($response);
        } catch (\Exception $e) {
            throw new \Exception(static::$exception_strings['date_format']);
        }

        if ($Date instanceof \DateTime && ! $return_obj) {
            $Date = $Date->toDateString();
        }

        return $Date;
    }

    /*
     * Parse string time input into 24 hr format
     */
    protected static function parse_time_input($response)
    {
        $Now = Carbon::now();
        $hour = 0;
        $minute = 0;
        $ampm = null;

        $response = trim(strtolower($response));
        if ($response == 'noon') {
            $response = '12';
            $ampm = 'pm';
        } elseif ($response == 'midnight') {
            $response = '12';
            $ampm = 'am';
        }
        $response = preg_replace('/[^0-9apm:\s]/i', '', $response);

        if (substr($response, -1) == 'a' || substr($response, -1) == 'p') {
            $response .= 'm';
        }

        if (substr($response, -2) == 'am') {
            $ampm = 'am';
            $response = trim(substr($response, 0, -2));
        } elseif (substr($response, -2) == 'pm') {
            $ampm = 'pm';
            $response = trim(substr($response, 0, -2));
        }

        if (false === strpos($response, ':')) {
            if (! is_numeric($response)) {
                var_dump($response);
                throw new \Exception(static::$exception_strings['time_format']);
            }
            $response .= ':00';
        }

        $response_parts = explode(':', $response);
        if (! is_numeric($response_parts[0]) || (! empty($response_parts[1]) && ! is_numeric($response_parts[1]))) {
            throw new \Exception(static::$exception_strings['time_format']);
        }
        $hour = intval($response_parts[0]);
        $minute = intval($response_parts[1]);

        if ($hour > 23 || $hour > 12) {
            $ampm = 'pm';
            if ($hour > 23) $hour = 0;
            if ($hour > 12) $hour -= 12;
        }

        if (is_null($ampm)) {
            if ($hour < 12) { // 0-11
                $ampm = 'am';
                if ($Now->hour > 11 && $hour <= 7) {
                    $ampm = 'pm';
                }
            } else {
                $ampm = 'pm';
            }
            if (IS_CLI) {
                if ($hour == 12 && $minute < 30) {
                    $ampm_response = Input::ask(sprintf('Noon or midnight? [%s]: ', ($ampm == 'pm' ? 'noon' : 'midnight')));
                    if ($ampm_response && strtolower($ampm_response[0]) == 'n') {
                        $ampm = 'pm';
                    } elseif ($ampm_response && strtolower($ampm_response[0]) == 'm') {
                        $ampm = 'am';
                    }
                } else {
                    $ampm_response = Input::ask(sprintf('%02d:%02d AM or PM? [%s]: ', $hour, $minute, $ampm));
                    if ($ampm_response && strtolower($ampm_response[0]) == 'p') {
                        $ampm = 'pm';
                    } elseif ($ampm_response && strtolower($ampm_response[0]) == 'a') {
                        $ampm = 'am';
                    }
                }
            }
        }

        if (($hour < 12 && $ampm == 'pm') || ($hour == 12 && $ampm == 'am')) {
            $hour += 12;
        }
        if ($hour >= 24) $hour = 0;


        if (($ampm == 'am' && $hour > 12) || ($ampm == 'pm' && ($hour < 12 && $hour > 0))) {
            throw new \Exception(static::$exception_strings['time_format']);
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    protected static function get_twelve_hour_time($time_str)
    {
        $Date = Carbon::parse(date("Y-m-d").' '.$time_str);

        return $Date->format('g:i a');
    }
}
